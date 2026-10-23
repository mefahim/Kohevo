<?php
/**
 * REST-002 Phase 1 — flagged-payment resolution core.
 *
 * Covers the resolution entry points in RestaurantAPI:
 *  - validation (actor, mandatory reason, fresh-actual sanity);
 *  - manager acceptance at the ACTUAL figures: settled_amount_cents /
 *    settled_currency written, original figures + the original mismatch
 *    audit preserved, a DISTINCT restaurant.settlement_manual_accept
 *    audit (never a second payment_paid), order paid at the actual;
 *  - state gates: unflagged / already-resolved payments refuse cleanly;
 *  - the lease claim blocks resolution while a settlement is in flight;
 *  - tenant isolation of the flagged list and of resolution effects;
 *  - refund failure against the REAL StripePaymentAPI::refundCharge()
 *    (no Stripe key in the test env -> deterministic failure): payment
 *    stays flagged, the pre-refund audit exists, a retry is safe and
 *    re-audited, nothing is marked refunded;
 *  - refund healing: a charge the ledger already shows fully refunded
 *    completes locally with NO Stripe call and NO refund_started audit —
 *    the recovery for "Stripe refunded, local completion failed";
 *  - refund with no charge and unverifiable actuals refuses precisely.
 *
 * Known limitations (documented, not hidden):
 *  - The refund SUCCESS call to Stripe cannot run without live keys
 *    (same transport seam as E8); the success-side local completion is
 *    proven through the healing path, which shares it.
 *  - The Auth-session branch of the permission guard cannot fire in the
 *    CLI harness (no session, userId 0); page-level enforcement is
 *    Phase 2. Actor/reason validation IS covered here.
 */

require_once dirname(__DIR__) . '/unit/harness.php';
require_once dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePaymentAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/restaurant/RestaurantAPI.php';

RestaurantAPI::ensureSchema();

// Local shims over the harness (each check registers as its own case).
function unit_check(string $label, $cond): void {
    unit($label, function () use ($cond, $label) { assert_true($cond === true, $label); });
}
function unit_eq(string $label, $expected, $actual): void {
    unit($label, function () use ($expected, $actual, $label) { assert_eq($expected, $actual, $label); });
}

/** Direct seed in the settlement suite's proven shape: explicit order totals. */
function rx_order(int $tid, int $total = 8500): int {
    return with_tenant($tid, function () use ($total) {
        $orderId = (int) Database::insert('restaurant_orders', [
            'tenant_id' => current_tenant_id(), 'order_no' => 'RX-' . strtoupper(bin2hex(random_bytes(4))),
            'type' => 'takeout', 'status' => 'open', 'guest_count' => 1,
            'subtotal_cents' => $total, 'total_cents' => $total, 'paid_cents' => 0, 'source' => 'pos',
        ]);
        Database::insert('restaurant_order_items', [
            'tenant_id' => current_tenant_id(), 'order_id' => $orderId, 'check_no' => 1, 'item_id' => null,
            'name_snapshot' => 'RX seeded item', 'qty' => 1, 'unit_price_cents' => $total,
            'modifiers_total_cents' => 0, 'line_total_cents' => $total, 'tax_rate' => 0, 'kitchen_status' => 'new',
        ]);
        return $orderId;
    });
}

/** A card payment (amount 8000 + tip 500 = expected 8500) flagged by a 9000 actual. */
function rx_flagged(int $tid): array {
    $pi = 'pi_rx_' . bin2hex(random_bytes(6));
    $orderId = rx_order($tid);
    $paymentId = with_tenant($tid, function () use ($orderId, $pi) {
        $id = (int) Database::insert('restaurant_payments', [
            'tenant_id' => current_tenant_id(), 'order_id' => $orderId,
            'method' => 'terminal', 'amount_cents' => 8000, 'tip_cents' => 500,
            'status' => 'pending', 'currency' => 'USD', 'stripe_pi_id' => $pi,
        ]);
        RestaurantAPI::settlePaymentWithActuals($id, 9000, 'USD'); // -> amount_mismatch flag
        return $id;
    });
    return [$orderId, $paymentId, $pi];
}

function rx_pay(int $id): array {
    return Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$id]);
}
function rx_audit_count(string $action, int $tid, string $target): int {
    return (int) Database::value(
        "SELECT COUNT(*) FROM audit_log WHERE action = ? AND tenant_id = ? AND target = ?",
        [$action, $tid, $target]);
}
function rx_audit_meta(string $action, int $tid, string $target): ?array {
    $json = Database::value(
        "SELECT meta_json FROM audit_log WHERE action = ? AND tenant_id = ? AND target = ? ORDER BY id DESC",
        [$action, $tid, $target]);
    return $json ? json_decode((string)$json, true) : null;
}

$T = (int)(getenv('REST_TEST_TENANT') ?: 1);
$T2 = $T + 1000;

// ── X1: validation refuses before touching anything ─────────────────────
[$o1, $p1, $pi1] = rx_flagged($T);
unit_check('X1 flagged setup: issue recorded', rx_pay($p1)['settlement_issue'] === 'amount_mismatch');
unit_eq('X1 actor 0 -> invalid', 'invalid',
    RestaurantAPI::acceptFlaggedPayment($p1, 9000, 'USD', null, 0, 'valid reason')['state']);
unit_eq('X1 blank reason -> invalid', 'invalid',
    RestaurantAPI::acceptFlaggedPayment($p1, 9000, 'USD', null, 42, '  ')['state']);
unit_eq('X1 null actual -> invalid', 'invalid',
    RestaurantAPI::acceptFlaggedPayment($p1, null, 'USD', null, 42, 'valid reason')['state']);
unit_eq('X1 short currency -> invalid', 'invalid',
    RestaurantAPI::acceptFlaggedPayment($p1, 9000, 'US', null, 42, 'valid reason')['state']);
unit_eq('X1 refund actor 0 -> invalid', 'invalid',
    RestaurantAPI::refundFlaggedPayment($p1, 9000, 'USD', 0, 'valid reason')['state']);
unit_check('X1 payment untouched by invalid calls',
    rx_pay($p1)['status'] === 'pending' && rx_pay($p1)['settlement_issue'] === 'amount_mismatch');

// ── X2: manager acceptance at actual figures ────────────────────────────
$res = RestaurantAPI::acceptFlaggedPayment($p1, 9000, 'USD', 'ch_rx_1', 42, 'Guest added items at the counter');
unit_eq('X2 accept -> paid', 'paid', $res['state']);
$row = rx_pay($p1);
unit_check('X2 settled figures recorded (D3)',
    (int)$row['settled_amount_cents'] === 9000 && $row['settled_currency'] === 'USD');
unit_check('X2 original figures preserved',
    (int)$row['amount_cents'] === 8000 && (int)$row['tip_cents'] === 500 && $row['currency'] === 'USD');
unit_check('X2 flag + claim cleared', $row['settlement_issue'] === null && $row['settlement_claim_token'] === null);
$ord = with_tenant($T, fn() => Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$o1]));
unit_check('X2 order paid at the ACTUAL figure', $ord['status'] === 'paid' && (int)$ord['paid_cents'] === 9000);
unit_eq('X2 mismatch audit preserved', 1, rx_audit_count('restaurant.settlement_mismatch', $T, (string)$p1));
unit_eq('X2 NO automatic payment_paid audit', 0, rx_audit_count('restaurant.payment_paid', $T, (string)$p1));
unit_eq('X2 manual_accept audit written', 1, rx_audit_count('restaurant.settlement_manual_accept', $T, (string)$p1));
$meta = rx_audit_meta('restaurant.settlement_manual_accept', $T, (string)$p1);
unit_check('X2 audit payload: expected vs actual + actor + reason',
    (int)$meta['expected_cents'] === 8500 && (int)$meta['actual_cents'] === 9000
    && (int)$meta['actor_id'] === 42 && $meta['reason'] === 'Guest added items at the counter'
    && $meta['issue'] === 'amount_mismatch');
$charge = Database::row("SELECT * FROM stripepayment_charges WHERE tenant_id = ? AND stripe_payment_intent_id = ?", [$T, $pi1]);
unit_check('X2 charge ledger holds the ACTUAL amount', $charge && (int)$charge['amount_cents'] === 9000);

// ── X3: state gates ──────────────────────────────────────────────────────
unit_eq('X3 re-accept resolved -> not_flagged', 'not_flagged',
    RestaurantAPI::acceptFlaggedPayment($p1, 9000, 'USD', null, 42, 'second attempt')['state']);
unit_eq('X3 refund resolved -> not_flagged', 'not_flagged',
    RestaurantAPI::refundFlaggedPayment($p1, 9000, 'USD', 42, 'second attempt')['state']);
unit_eq('X3 still exactly one manual_accept audit', 1,
    rx_audit_count('restaurant.settlement_manual_accept', $T, (string)$p1));
$plainOrder = rx_order($T);
$plainPay = with_tenant($T, function () use ($plainOrder) {
    return (int) Database::insert('restaurant_payments', [
        'tenant_id' => current_tenant_id(), 'order_id' => $plainOrder, 'method' => 'terminal',
        'amount_cents' => 8500, 'tip_cents' => 0, 'status' => 'pending', 'currency' => 'USD',
        'stripe_pi_id' => 'pi_rx_plain_' . bin2hex(random_bytes(4)),
    ]);
});
unit_eq('X3 unflagged payment -> not_flagged', 'not_flagged',
    RestaurantAPI::acceptFlaggedPayment($plainPay, 8500, 'USD', null, 42, 'not flagged')['state']);

// ── X4: acceptance of a currency_mismatch at the actual currency ────────
[$o4, $p4] = rx_flagged($T);
Database::update('restaurant_payments', ['settlement_issue' => 'currency_mismatch'], 'id = ?', [$p4]);
$res = RestaurantAPI::acceptFlaggedPayment($p4, 9000, 'EUR', null, 7, 'Charged in EUR at the reader');
unit_eq('X4 currency override accepted', 'paid', $res['state']);
unit_check('X4 settled currency is the actual', rx_pay($p4)['settled_currency'] === 'EUR');

// ── X5: tenant isolation ─────────────────────────────────────────────────
[$o5, $p5] = rx_flagged($T2);
$listT  = array_column(RestaurantAPI::flaggedPayments($T), 'id');
$listT2 = array_column(RestaurantAPI::flaggedPayments($T2), 'id');
unit_check('X5 flagged list is tenant-scoped',
    !in_array($p5, array_map('intval', $listT), true) && in_array($p5, array_map('intval', $listT2), true));
$res = with_tenant($T, fn() => RestaurantAPI::acceptFlaggedPayment($p5, 9000, 'USD', null, 9, 'Cross-tenant resolution test'));
unit_eq('X5 resolution runs in the payment tenant', 'paid', $res['state']);
unit_eq('X5 audit landed in the payment tenant', 1,
    rx_audit_count('restaurant.settlement_manual_accept', $T2, (string)$p5));
unit_eq('X5 no audit leaked into tenant 1', 0,
    rx_audit_count('restaurant.settlement_manual_accept', $T, (string)$p5));

// ── X6: a live settlement claim blocks resolution ────────────────────────
[$o6, $p6, $pi6] = rx_flagged($T);
Database::update('restaurant_payments',
    ['settlement_claim_token' => 'rx_live_claim', 'settlement_claimed_at' => date('Y-m-d H:i:s')],
    'id = ?', [$p6]);
unit_eq('X6 accept blocked by live claim', 'claimed',
    RestaurantAPI::acceptFlaggedPayment($p6, 9000, 'USD', null, 42, 'while settling')['state']);
unit_eq('X6 refund blocked by live claim', 'claimed',
    RestaurantAPI::refundFlaggedPayment($p6, 9000, 'USD', 42, 'while settling')['state']);
unit_check('X6 payment unchanged', rx_pay($p6)['status'] === 'pending' && rx_pay($p6)['settlement_issue'] === 'amount_mismatch');
Database::update('restaurant_payments',
    ['settlement_claim_token' => null, 'settlement_claimed_at' => null], 'id = ?', [$p6]);

// ── X7: refund failure (real refundCharge, no Stripe key) + safe retry ──
$res = RestaurantAPI::refundFlaggedPayment($p6, 9000, 'USD', 42, 'Guest disputes the charge');
unit_eq('X7 refund without Stripe key -> refund_failed', 'refund_failed', $res['state']);
unit_check('X7 payment stays flagged + pending',
    rx_pay($p6)['status'] === 'pending' && rx_pay($p6)['settlement_issue'] === 'amount_mismatch');
unit_check('X7 claim released after failure', rx_pay($p6)['settlement_claim_token'] === null);
unit_eq('X7 pre-refund audit written', 1, rx_audit_count('restaurant.settlement_refund_started', $T, (string)$p6));
unit_eq('X7 no refunded audit on failure', 0, rx_audit_count('restaurant.settlement_refunded', $T, (string)$p6));
$chargeRow = Database::row("SELECT * FROM stripepayment_charges WHERE tenant_id = ? AND stripe_payment_intent_id = ?", [$T, $pi6]);
unit_check('X7 ledger row created from actuals, not refunded',
    $chargeRow && (int)$chargeRow['amount_cents'] === 9000 && (int)$chargeRow['refunded_cents'] === 0);
$res = RestaurantAPI::refundFlaggedPayment($p6, 9000, 'USD', 42, 'Retry the refund');
unit_eq('X7 retry also fails safe', 'refund_failed', $res['state']);
unit_eq('X7 retry re-audited (started x2)', 2, rx_audit_count('restaurant.settlement_refund_started', $T, (string)$p6));
unit_check('X7 still flagged after retries', rx_pay($p6)['settlement_issue'] === 'amount_mismatch');

// ── X8: no charge + unverifiable actuals -> precise refusal ─────────────
[$o8, $p8] = rx_flagged($T);
$res = RestaurantAPI::refundFlaggedPayment($p8, null, '', 42, 'Try refund without figures');
unit_eq('X8 no charge + no actuals -> no_charge', 'no_charge', $res['state']);
unit_check('X8 still flagged', rx_pay($p8)['settlement_issue'] === 'amount_mismatch');
unit_eq('X8 no refund_started audit', 0, rx_audit_count('restaurant.settlement_refund_started', $T, (string)$p8));

// ── X9: refund healing — ledger already fully refunded ───────────────────
[$o9, $p9, $pi9] = rx_flagged($T);
with_tenant($T, function () use ($p9, $pi9) {
    $pay = rx_pay($p9);
    $cid = StripePaymentAPI::recordCharge([
        'source_plugin' => 'restaurant', 'source_id' => (string)$pay['order_id'],
        'stripe_payment_intent_id' => $pi9, 'stripe_session_id' => null,
        'amount_cents' => 9000, 'currency' => 'USD', 'status' => 'succeeded',
        'meta' => ['method' => 'online', 'order_id' => (int)$pay['order_id']],
    ]);
    // Simulate: the money was refunded at Stripe, local completion died.
    Database::update('stripepayment_charges',
        ['refunded_cents' => 9000, 'status' => 'refunded'], 'id = ?', [$cid]);
});
$res = RestaurantAPI::refundFlaggedPayment($p9, null, '', 42, 'Converge after earlier refund');
unit_eq('X9 healed refund completes', 'refunded', $res['state']);
unit_check('X9 healed flag set', ($res['healed'] ?? false) === true);
unit_check('X9 payment refunded + flag cleared',
    rx_pay($p9)['status'] === 'refunded' && rx_pay($p9)['settlement_issue'] === null);
unit_check('X9 original figures preserved',
    (int)rx_pay($p9)['amount_cents'] === 8000 && (int)rx_pay($p9)['tip_cents'] === 500);
unit_eq('X9 mismatch audit preserved', 1, rx_audit_count('restaurant.settlement_mismatch', $T, (string)$p9));
unit_eq('X9 refunded audit written', 1, rx_audit_count('restaurant.settlement_refunded', $T, (string)$p9));
unit_eq('X9 NO refund_started audit (no Stripe call)', 0,
    rx_audit_count('restaurant.settlement_refund_started', $T, (string)$p9));
$meta = rx_audit_meta('restaurant.settlement_refunded', $T, (string)$p9);
unit_check('X9 audit marks healing + actor + reason',
    ($meta['healed'] ?? null) === true && (int)$meta['actor_id'] === 42);
$ord = with_tenant($T, fn() => Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$o9]));
unit_check('X9 order back to unpaid, NOT auto-voided (D5)',
    (int)$ord['paid_cents'] === 0 && $ord['status'] !== 'paid' && $ord['status'] !== 'voided');
$chargeRow = Database::row("SELECT * FROM stripepayment_charges WHERE tenant_id = ? AND stripe_payment_intent_id = ?", [$T, $pi9]);
unit_check('X9 no double refund (ledger untouched)', (int)$chargeRow['refunded_cents'] === 9000);

echo "RestaurantSettlementResolutionTest done\n";
