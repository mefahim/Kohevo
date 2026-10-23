<?php
/**
 * Restaurant — flagged payments (REST-002).
 *
 * The operator surface for payments REST-001 parked with a settlement
 * issue (Stripe's amount/currency did not reconcile). Two resolutions,
 * both delegated ENTIRELY to RestaurantAPI — this page holds no payment
 * or refund business logic:
 *
 *   Accept & settle — settle at the figures Stripe reports NOW. The
 *     actuals are fetched fresh on POST (RestaurantAPI::fetchStripeActuals);
 *     no amount is ever read from the form, so a stale page or a tampered
 *     post cannot invent figures.
 *   Refund — full remaining refund through the charges ledger.
 *
 * Both actions require restaurant.manage_orders (page gate + the API
 * re-checks a live session), an explicit confirmation checkbox, and a
 * mandatory reason — all enforced again server-side in RestaurantAPI.
 * The original payment figures and the original mismatch audit are never
 * modified here; a refund never voids the order (staff void/re-ring at
 * the POS — decision D5).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.manage_orders');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = 'Flagged payments';
$currentNav = 'restaurant-payments';
$tid = current_tenant_id();

$flash = null;

$issueLabels = [
    'amount_mismatch'      => 'Amount mismatch',
    'currency_mismatch'    => 'Currency mismatch',
    'amount_unverifiable'  => 'Amount could not be verified',
    'currency_unverifiable' => 'Currency could not be verified',
];

// ── POST: one resolution action ──────────────────────────────────────────
// Handled before any output; the result is rendered as a flash below.
// Every state the API can return maps to an honest message — in
// particular 'refund_unrecorded' is shown as UNRESOLVED, never as success.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please reload the page and try again.'];
    } else {
        $action    = (string)($_POST['_action'] ?? '');
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $reason    = trim((string)($_POST['reason'] ?? ''));
        $confirmed = (string)($_POST['confirm'] ?? '') === '1';

        // Tenant-scoped load: a payment from another restaurant is simply
        // "not found" here — the API would run it in its own tenant, but
        // this page only ever acts on the current restaurant's queue.
        $payment = $paymentId > 0 ? Database::row(
            "SELECT * FROM restaurant_payments WHERE id = ? AND tenant_id = ?",
            [$paymentId, $tid]) : null;

        if (!in_array($action, ['accept', 'refund'], true) || !$payment) {
            $flash = ['type' => 'error', 'msg' => 'That payment is not in this restaurant\u2019s flagged queue.'];
        } elseif (!$confirmed) {
            $flash = ['type' => 'error', 'msg' => 'Please tick the confirmation box — these actions move money and cannot be undone from here.'];
        } elseif (mb_strlen($reason) < 3) {
            $flash = ['type' => 'error', 'msg' => 'A reason is required (at least 3 characters) — it is written to the audit trail.'];
        } else {
            // Fresh actuals at action time — never the figures rendered
            // into the page, never anything from $_POST.
            $actuals = RestaurantAPI::fetchStripeActuals($payment);
            if ($action === 'accept') {
                if ($actuals === null || $actuals['amount_cents'] === null) {
                    $flash = ['type' => 'error', 'msg' => 'Stripe could not be reached to verify the captured amount just now. Nothing was changed — try again in a moment.'];
                } else {
                    $res = RestaurantAPI::acceptFlaggedPayment(
                        $paymentId, $actuals['amount_cents'], (string)$actuals['currency'],
                        $actuals['charge_id'], (int)Auth::userId(), $reason);
                    $flash = match ($res['state'] ?? 'error') {
                        'paid'        => ['type' => 'success', 'msg' => 'Payment accepted and settled at '
                            . RestaurantAPI::money((int)$actuals['amount_cents']) . ' ' . e((string)$actuals['currency'])
                            . ' — the order is now marked paid at that figure. The original figures and the mismatch record are preserved in the audit trail.'],
                        'claimed'     => ['type' => 'error', 'msg' => 'Another settlement or resolution is in progress for this payment. Wait a few seconds and reload before trying again.'],
                        'not_flagged' => ['type' => 'error', 'msg' => 'This payment is no longer flagged — it may already have been resolved. The list below is current.'],
                        'forbidden'   => ['type' => 'error', 'msg' => 'Your account does not have the restaurant.manage_orders permission.'],
                        default       => ['type' => 'error', 'msg' => 'Acceptance failed: ' . e((string)($res['error'] ?? 'unknown error')) . ' Nothing was settled.'],
                    };
                }
            } else { // refund
                $res = RestaurantAPI::refundFlaggedPayment(
                    $paymentId, $actuals['amount_cents'] ?? null, (string)($actuals['currency'] ?? ''),
                    (int)Auth::userId(), $reason);
                $flash = match ($res['state'] ?? 'error') {
                    'refunded' => ['type' => 'success', 'msg' => !empty($res['healed'])
                        ? 'This charge was already fully refunded at Stripe — the payment record has now been updated to match. No second refund was issued. The order stays unpaid; void or re-ring it at the POS if needed.'
                        : 'Refund issued at Stripe and the payment is marked refunded. The order stays unpaid; void or re-ring it at the POS if needed.'],
                    'refund_unrecorded' => ['type' => 'error', 'msg' => 'UNRESOLVED — reconciliation required. Stripe reports the refund as issued'
                        . (!empty($res['refund_id']) ? ' (refund ' . e((string)$res['refund_id']) . ')' : '')
                        . ', but the local record could not be completed. Do NOT refund again from the Stripe dashboard. The payment stays in this queue; check the charge in Stripe first, then retry here — the retry detects the existing refund and only updates the record.'],
                    'refund_failed' => ['type' => 'error', 'msg' => 'Stripe refused or could not complete the refund: '
                        . e((string)($res['error'] ?? 'unknown error')) . ' The payment is unchanged and stays flagged — it is safe to try again.'],
                    'no_charge'   => ['type' => 'error', 'msg' => 'No charge is recorded for this payment and the captured amount could not be verified, so there is nothing safe to refund from here. Refund from the Stripe dashboard instead, and leave this payment flagged until then.'],
                    'claimed'     => ['type' => 'error', 'msg' => 'Another settlement or resolution is in progress for this payment. Wait a few seconds and reload before trying again.'],
                    'not_flagged' => ['type' => 'error', 'msg' => 'This payment is no longer flagged — it may already have been resolved. The list below is current.'],
                    'forbidden'   => ['type' => 'error', 'msg' => 'Your account does not have the restaurant.manage_orders permission.'],
                    default       => ['type' => 'error', 'msg' => 'Refund failed: ' . e((string)($res['error'] ?? 'unknown error')) . ' The payment is unchanged and stays flagged.'],
                };
            }
        }
    }
}

// ── The flagged queue for THIS restaurant ────────────────────────────────
$flagged = RestaurantAPI::flaggedPayments($tid);
// Fresh actuals per row for the comparison display. A failure shows
// "unavailable" — display only; actions re-fetch on POST regardless.
foreach ($flagged as &$row) {
    $row['_actuals'] = RestaurantAPI::fetchStripeActuals($row);
}
unset($row);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Flagged payments'],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= $flash['msg'] ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Flagged payments</h2>
    </div>
    <p style="padding:0 1rem">
        These card payments were captured by Stripe but did <strong>not</strong> settle automatically,
        because the amount or currency Stripe reported did not match the check.
        The order was not marked paid. Resolve each one below: <strong>accept</strong> the Stripe
        figures (only when the difference is understood) or <strong>refund</strong> the full amount.
        Both actions are recorded in the audit trail with your name and reason; the original
        figures and the original mismatch record are always preserved.
    </p>

    <?php if (!$flagged): ?>
        <p style="padding:0 1rem 1rem">No flagged payments. Every captured payment has settled cleanly.</p>
    <?php endif; ?>

    <?php foreach ($flagged as $p):
        $expectedCents = (int)$p['amount_cents'] + (int)$p['tip_cents'];
        $expectedCur   = strtoupper(trim((string)($p['currency'] ?? ''))) ?: RestaurantAPI::currency();
        $actuals       = $p['_actuals'];
        $orderHref     = plugin_url('restaurant', 'admin/order.php') . '?id=' . (int)$p['order_id'];
    ?>
    <section aria-label="Flagged payment <?= (int)$p['id'] ?>" style="border-top:1px solid #e5e7eb;padding:1rem">
        <h3 style="margin-top:0">
            Order <a href="<?= e($orderHref) ?>">#<?= e((string)($p['order_no'] ?? $p['order_id'])) ?></a>
            · Payment #<?= (int)$p['id'] ?>
            · <span class="badge"><?= e($issueLabels[$p['settlement_issue']] ?? (string)$p['settlement_issue']) ?></span>
        </h3>
        <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.5rem 1.5rem">
            <div>
                <dt><strong>Expected (the check)</strong></dt>
                <dd><?= e(RestaurantAPI::money($expectedCents)) ?> <?= e($expectedCur) ?>
                    <br><small>Amount <?= e(RestaurantAPI::money((int)$p['amount_cents'])) ?> + tip <?= e(RestaurantAPI::money((int)$p['tip_cents'])) ?></small>
                </dd>
            </div>
            <div>
                <dt><strong>Actual (Stripe, fetched just now)</strong></dt>
                <dd>
                    <?php if ($actuals && $actuals['amount_cents'] !== null): ?>
                        <?= e(RestaurantAPI::money((int)$actuals['amount_cents'])) ?> <?= e((string)$actuals['currency']) ?>
                    <?php else: ?>
                        Unavailable — Stripe could not be reached. Actions re-check live before doing anything.
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt><strong>References</strong></dt>
                <dd>
                    <small>
                        PaymentIntent: <?= e((string)($p['stripe_pi_id'] ?? '—')) ?><br>
                        Order status: <?= e((string)($p['order_status'] ?? '—')) ?> · Payment status: flagged (pending)
                    </small>
                </dd>
            </div>
        </dl>

        <form method="post" style="margin-top:.5rem">
            <?= csrf_field() ?>
            <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
            <div class="field">
                <label class="field-label" for="reason-<?= (int)$p['id'] ?>">Reason (required, recorded in the audit trail)</label>
                <input type="text" id="reason-<?= (int)$p['id'] ?>" name="reason" required minlength="3" maxlength="500"
                       placeholder="e.g. Guest added items at the counter; difference verified">
            </div>
            <div class="field">
                <label>
                    <input type="checkbox" name="confirm" value="1" required>
                    I confirm this action. Accepting settles the payment at the Stripe figures shown above;
                    refunding returns the full captured amount to the guest. The order is not voided automatically.
                </label>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                <button type="submit" name="_action" value="accept" class="btn btn-primary">Accept &amp; settle at Stripe figures</button>
                <button type="submit" name="_action" value="refund" class="btn btn-danger">Refund full amount</button>
            </div>
        </form>
    </section>
    <?php endforeach; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
