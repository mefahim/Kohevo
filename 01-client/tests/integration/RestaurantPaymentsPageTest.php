<?php
/**
 * REST-002 Phase 2 — admin/payments.php page tests via child-process probes.
 *
 * Uses the repo's admin-page probe fixtures (real validated sessions in a
 * child process — the page calls exit() and Auth state must be fresh):
 *  - GET as super-admin renders the flagged queue for the CURRENT tenant
 *    only (tenant isolation), with expected figures and issue labels.
 *  - GET as a role WITHOUT restaurant.manage_orders is denied (403);
 *    the same page as a role WITH it renders (positive control).
 *  - POST with a bad CSRF token changes nothing.
 *  - POST validation: missing confirmation, missing reason, and a
 *    payment id from ANOTHER tenant are all refused with clear messages.
 *  - POST Accept in this environment: the page re-fetches Stripe actuals
 *    at action time; with no Stripe key configured the fetch fails and
 *    the page must refuse WITHOUT settling (posted data is never trusted).
 *  - POST Refund end-to-end via the healing path: a charge the ledger
 *    already shows fully refunded completes locally, the success message
 *    says no second refund was issued, and the order is NOT voided (D5).
 *  - Dashboard consistency: the widget count predicate
 *    (count of RestaurantAPI::flaggedPayments) equals a direct COUNT with
 *    the same predicate, per tenant — the widget calls exactly that.
 *
 * Not covered here (documented): the refund_unrecorded flash mapping —
 * that state needs a claim loss mid-completion, which cannot be forced
 * deterministically through the page; the backend state itself and its
 * audit are covered by design in Phase 1, and the mapping is a literal
 * match arm reviewed in the page source.
 */

require_once dirname(__DIR__) . '/unit/harness.php';
require_once dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePaymentAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/restaurant/RestaurantAPI.php';

RestaurantAPI::ensureSchema();

function unit_check(string $label, $cond): void {
    unit($label, function () use ($cond, $label) { assert_true($cond === true, $label); });
}
function unit_eq(string $label, $expected, $actual): void {
    unit($label, function () use ($expected, $actual, $label) { assert_eq($expected, $actual, $label); });
}

function pg_seed_flagged(int $tid): array {
    return with_tenant($tid, function () {
        $orderId = (int) Database::insert('restaurant_orders', [
            'tenant_id' => current_tenant_id(), 'order_no' => 'PG-' . strtoupper(bin2hex(random_bytes(4))),
            'type' => 'takeout', 'status' => 'open', 'guest_count' => 1,
            'subtotal_cents' => 8500, 'total_cents' => 8500, 'paid_cents' => 0, 'source' => 'pos',
        ]);
        Database::insert('restaurant_order_items', [
            'tenant_id' => current_tenant_id(), 'order_id' => $orderId, 'check_no' => 1, 'item_id' => null,
            'name_snapshot' => 'PG seeded item', 'qty' => 1, 'unit_price_cents' => 8500,
            'modifiers_total_cents' => 0, 'line_total_cents' => 8500, 'tax_rate' => 0, 'kitchen_status' => 'new',
        ]);
        $pi = 'pi_pg_' . bin2hex(random_bytes(6));
        $payId = (int) Database::insert('restaurant_payments', [
            'tenant_id' => current_tenant_id(), 'order_id' => $orderId, 'method' => 'terminal',
            'amount_cents' => 8000, 'tip_cents' => 500, 'status' => 'pending', 'currency' => 'USD',
            'stripe_pi_id' => $pi,
        ]);
        RestaurantAPI::settlePaymentWithActuals($payId, 9000, 'USD');
        return [$orderId, $payId, $pi];
    });
}

function pg_probe_get(string $page, int $roleId = 1): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg('') . ' ' . escapeshellarg((string)$roleId) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];
    return [(int)$m[1], substr($out, strlen($m[0]))];
}

function pg_probe_post(string $page, array $fields, int $roleId = 1, bool $badCsrf = false): array {
    $fixture = $badCsrf ? 'admin-page-post-badcsrf-probe.php' : 'admin-page-post-probe.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture) . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg((string)json_encode($fields)) . ' '
         . escapeshellarg((string)$roleId) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];
    return [(int)$m[1], substr($out, strlen($m[0]))];
}

function pg_role(int $tid, string $slug, array $perms): int {
    $roleId = (int) Database::insert('roles', [
        'tenant_id' => $tid, 'name' => 'PG ' . $slug, 'slug' => $slug . '-' . bin2hex(random_bytes(3)),
    ]);
    foreach ($perms as $perm) {
        Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => $perm, 'granted' => 1]);
    }
    return $roleId;
}

function pg_pay(int $id): array {
    return Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$id]);
}

$T  = (int)(getenv('REST_TEST_TENANT') ?: 1);
$T2 = $T + 1000;
$PAGE = 'plugins/restaurant/admin/payments.php';

[$oT, $pT, $piT]   = pg_seed_flagged($T);
[$oT2, $pT2, $piT2] = pg_seed_flagged($T2);

// ── GET: super-admin sees own tenant's queue only ────────────────────────
[$status, $body] = pg_probe_get($PAGE);
unit_eq('GET super-admin -> 200', 200, $status);
unit_check('GET renders the flagged payment', str_contains($body, $piT));
unit_check('GET shows the issue label', str_contains($body, 'Amount mismatch'));
unit_check('GET shows expected figures', str_contains($body, 'Expected (the check)'));
unit_check('GET tenant isolation: other tenant PI absent', !str_contains($body, $piT2));

// ── GET: permission gate ──────────────────────────────────────────────────
$staffRole   = pg_role($T, 'pg-staff', ['restaurant.view']);
$managerRole = pg_role($T, 'pg-manager', ['restaurant.view', 'restaurant.manage_orders']);
[$statusStaff, $bodyStaff] = pg_probe_get($PAGE, $staffRole);
unit_check('GET without manage_orders is denied (403)', $statusStaff === 403);
unit_check('GET denied body has no queue data', !str_contains($bodyStaff, $piT));
[$statusMgr, $bodyMgr] = pg_probe_get($PAGE, $managerRole);
unit_eq('GET with manage_orders -> 200', 200, $statusMgr);
unit_check('GET manager sees the queue', str_contains($bodyMgr, $piT));

// ── POST: bad CSRF ────────────────────────────────────────────────────────
[$s, $b] = pg_probe_post($PAGE, ['_action' => 'refund', 'payment_id' => $pT, 'reason' => 'csrf attack', 'confirm' => '1'], 1, true);
unit_check('POST bad CSRF -> security flash', str_contains($b, 'Security check failed'));
unit_check('POST bad CSRF changes nothing', pg_pay($pT)['settlement_issue'] === 'amount_mismatch' && pg_pay($pT)['status'] === 'pending');

// ── POST: validation (confirm, reason, cross-tenant id) ─────────────────
[$s, $b] = pg_probe_post($PAGE, ['_action' => 'accept', 'payment_id' => $pT, 'reason' => 'no confirm ticked']);
unit_check('POST without confirmation -> refused', str_contains($b, 'confirmation box'));
[$s, $b] = pg_probe_post($PAGE, ['_action' => 'accept', 'payment_id' => $pT, 'confirm' => '1', 'reason' => '']);
unit_check('POST without reason -> refused', str_contains($b, 'reason is required'));
[$s, $b] = pg_probe_post($PAGE, ['_action' => 'refund', 'payment_id' => $pT2, 'confirm' => '1', 'reason' => 'cross tenant attempt']);
unit_check('POST other tenant payment -> not in queue message', str_contains($b, 'not in this restaurant'));
unit_check('POST cross-tenant attempt changes nothing', pg_pay($pT2)['settlement_issue'] === 'amount_mismatch');

// ── POST: accept refuses when Stripe actuals cannot be fetched ────────────
// (No Stripe key in the test env: fetchStripeActuals returns null and the
// page must not settle on faith or on anything posted.)
[$s, $b] = pg_probe_post($PAGE, ['_action' => 'accept', 'payment_id' => $pT, 'confirm' => '1', 'reason' => 'trying to accept']);
unit_check('POST accept without reachable Stripe -> verification error', str_contains($b, 'Stripe could not be reached'));
unit_check('POST accept changed nothing', pg_pay($pT)['status'] === 'pending' && pg_pay($pT)['settlement_issue'] === 'amount_mismatch');

// ── POST: refund end-to-end via the healing path ──────────────────────────
with_tenant($T, function () use ($pT, $piT) {
    $pay = pg_pay($pT);
    $cid = StripePaymentAPI::recordCharge([
        'source_plugin' => 'restaurant', 'source_id' => (string)$pay['order_id'],
        'stripe_payment_intent_id' => $piT, 'stripe_session_id' => null,
        'amount_cents' => 9000, 'currency' => 'USD', 'status' => 'succeeded',
        'meta' => ['method' => 'terminal', 'order_id' => (int)$pay['order_id']],
    ]);
    Database::update('stripepayment_charges', ['refunded_cents' => 9000, 'status' => 'refunded'], 'id = ?', [$cid]);
});
[$s, $b] = pg_probe_post($PAGE, ['_action' => 'refund', 'payment_id' => $pT, 'confirm' => '1', 'reason' => 'Guest was charged the wrong amount']);
unit_check('POST refund (healed) -> honest success message', str_contains($b, 'already fully refunded at Stripe'));
unit_check('POST refund marked the payment refunded', pg_pay($pT)['status'] === 'refunded' && pg_pay($pT)['settlement_issue'] === null);
$ord = with_tenant($T, fn() => Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$oT]));
unit_check('POST refund did NOT void the order (D5)', $ord['status'] !== 'voided' && (int)$ord['paid_cents'] === 0);
unit_check('POST refund removed it from the queue copy', str_contains($b, 'No flagged payments') || !str_contains($b, $piT));

// ── POST: permission gate on actions ──────────────────────────────────────
[$oX, $pX, $piX] = pg_seed_flagged($T);
[$s, $b] = pg_probe_post($PAGE, ['_action' => 'refund', 'payment_id' => $pX, 'confirm' => '1', 'reason' => 'staff attempt'], $staffRole);
unit_check('POST as staff role is denied before any action', $s === 403 && pg_pay($pX)['settlement_issue'] === 'amount_mismatch');

// ── Widget/list count consistency (per tenant) ────────────────────────────
foreach ([$T, $T2] as $tidX) {
    $viaApi = count(RestaurantAPI::flaggedPayments($tidX));
    $viaSql = (int) Database::value(
        "SELECT COUNT(*) FROM restaurant_payments WHERE tenant_id = ? AND settlement_issue IS NOT NULL", [$tidX]);
    unit_eq("Widget count predicate == list size (tenant {$tidX})", $viaSql, $viaApi);
}

echo "RestaurantPaymentsPageTest done\n";
