<?php
/**
 * REST-001 — restaurant payment settlement hardening (single-tenant paths).
 *
 * Exercises the real settler, RestaurantAPI::settlePaymentWithActuals(), and
 * its entry points (markPaymentPaid for Terminal/embedded, handleStripeEvent
 * for webhooks) against seeded restaurant_payments rows with synthetic
 * Stripe actuals — no live Stripe anywhere. What is pinned here:
 *
 *  - Stripe's actual amount/currency must reconcile EXACTLY against the
 *    payment row (amount_cents + tip_cents; the row's currency) or the
 *    payment is parked with settlement_issue set, ONE
 *    restaurant.settlement_mismatch audit and ONE admin notification, and
 *    the order is NOT marked paid. Currency is checked before amount; an
 *    unreported amount or currency is unverifiable and never settles.
 *  - A successful settle produces exactly one stripepayment_charges row and
 *    exactly one restaurant.payment_paid audit, however many times the
 *    webhook / confirm / poll paths re-deliver (replays heal silently).
 *  - Healing: a payment already 'paid' whose charge row or order sync was
 *    lost converges again WITHOUT a second payment_paid audit.
 *  - payment_failed and cancel can never flip a settled payment back.
 *  - A charge row the generic stripe webhook already wrote for the same PI
 *    (source_plugin 'unknown') converges — settlement must not duplicate it.
 *
 * The test DB carries the OLD restaurant schema; rst_boot() upgrades it via
 * the plugin's own runMigrations() (the same step tests/fixtures/
 * restaurant-schema.php performs standalone). All seeded rows carry the
 * pi_rst_ PaymentIntent prefix and are swept before and after the file, so
 * re-runs and combined-suite runs start clean.
 */

declare(strict_types=1);

function rst_boot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $root = dirname(__DIR__, 2) . '/plugins/restaurant';
    require_once $root . '/Restaurant.php';
    $manifest = json_decode((string) file_get_contents($root . '/plugin.json'), true) ?: [];
    $plugin = new Restaurant('restaurant', $manifest, $root);
    $run = new ReflectionMethod(Restaurant::class, 'runMigrations');
    $run->setAccessible(true);
    $run->invoke($plugin, '0.0.0');
    if (class_exists('Notifications')) Notifications::ensureSchema();
    if (class_exists('StripePaymentAPI')) StripePaymentAPI::ensureChargesSchema();
}

/** Remove every row this file can create (by PI prefix + the ids behind it). */
function rst_sweep(): void
{
    $pays = Database::rows("SELECT id, order_id FROM restaurant_payments WHERE stripe_pi_id LIKE 'pi_rst_%'");
    $payIds = array_map(fn($r) => (int)$r['id'], $pays);
    $orderIds = array_values(array_unique(array_map(fn($r) => (int)$r['order_id'], $pays)));
    Database::query("DELETE FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE 'pi_rst_%'");
    if ($payIds) {
        $in = implode(',', $payIds);
        Database::query("DELETE FROM audit_log WHERE action LIKE 'restaurant.%' AND target IN ($in)");
    }
    if ($orderIds) {
        $in = implode(',', $orderIds);
        Database::query("DELETE FROM restaurant_order_item_modifiers WHERE order_item_id IN (SELECT id FROM restaurant_order_items WHERE order_id IN ($in))");
        Database::query("DELETE FROM restaurant_order_items WHERE order_id IN ($in)");
        Database::query("DELETE FROM restaurant_payments WHERE order_id IN ($in)");
        Database::query("DELETE FROM audit_log WHERE action LIKE 'restaurant.%' AND target IN ($in)");
        Database::query("DELETE FROM restaurant_orders WHERE id IN ($in)");
    }
    Database::query("DELETE FROM slate_notifications WHERE title = 'Restaurant payment needs review'");
}

/** Seed one open order (+ one line so recalc has a real subtotal) and one pending card payment. */
function rst_seed(int $amount = 10000, int $tip = 1500, string $method = 'terminal'): array
{
    $tid = current_tenant_id();
    $pi = 'pi_rst_' . bin2hex(random_bytes(8));
    $orderId = Database::insert('restaurant_orders', [
        'tenant_id' => $tid, 'order_no' => 'RST-' . strtoupper(bin2hex(random_bytes(4))),
        'type' => 'takeout', 'status' => 'open', 'guest_count' => 1,
        'subtotal_cents' => $amount, 'total_cents' => $amount + $tip, 'paid_cents' => 0,
        'source' => 'pos',
    ]);
    Database::insert('restaurant_order_items', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'check_no' => 1, 'item_id' => null,
        'name_snapshot' => 'RST seeded item', 'qty' => 1, 'unit_price_cents' => $amount,
        'modifiers_total_cents' => 0, 'line_total_cents' => $amount, 'tax_rate' => 0,
        'kitchen_status' => 'new',
    ]);
    $payId = Database::insert('restaurant_payments', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'method' => $method,
        'amount_cents' => $amount, 'tip_cents' => $tip, 'status' => 'pending',
        'stripe_pi_id' => $pi, 'currency' => 'USD',
    ]);
    return ['order_id' => $orderId, 'payment_id' => $payId, 'pi' => $pi,
            'amount' => $amount, 'tip' => $tip, 'expected' => $amount + $tip];
}

function rst_payment(int $id): array
{
    return Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$id]) ?: [];
}

function rst_order(int $id): array
{
    return Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$id]) ?: [];
}

function rst_audit_count(string $action, int $target): int
{
    return (int) Database::value(
        "SELECT COUNT(*) FROM audit_log WHERE action = ? AND target = ?", [$action, (string)$target]);
}

function rst_charge_count(string $pi): int
{
    return (int) Database::value(
        "SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_payment_intent_id = ?", [$pi]);
}

function rst_notif_count(): int
{
    return (int) Database::value(
        "SELECT COUNT(*) FROM slate_notifications WHERE title = 'Restaurant payment needs review'");
}

/** Synthetic succeeded/failed webhook event for a seeded payment. */
function rst_event(array $seed, string $type = 'payment_intent.succeeded', ?string $charge = null): array
{
    return ['type' => $type, 'data' => ['object' => [
        'id' => $seed['pi'],
        'metadata' => ['source' => 'restaurant'],
        'amount_received' => $seed['expected'],
        'currency' => 'usd',
        'latest_charge' => $charge ?? ('ch_rst_' . bin2hex(random_bytes(6))),
    ]]];
}

rst_boot();
rst_sweep();

// ── Reconciliation: mismatches never settle ─────────────────────────────

unit('REST-001 mismatch: wrong Stripe amount parks the payment (issue, 1 audit, 1 notification, order unpaid)', function (): void {
    $s = rst_seed();
    $notifBefore = rst_notif_count();
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], $s['expected'] + 500, 'usd');
    assert_eq('mismatch', $res['state'] ?? null);
    assert_eq('amount_mismatch', $res['issue'] ?? null);
    $pay = rst_payment($s['payment_id']);
    assert_eq('pending', $pay['status'], 'a mismatched payment must stay pending');
    assert_eq('amount_mismatch', $pay['settlement_issue']);
    assert_null($pay['settlement_claim_token'], 'the claim must be released after a mismatch');
    $order = rst_order($s['order_id']);
    assert_eq('open', $order['status'], 'the order must NOT be marked paid');
    assert_eq(0, (int)$order['paid_cents']);
    assert_eq(1, rst_audit_count('restaurant.settlement_mismatch', $s['payment_id']), 'exactly one mismatch audit');
    assert_eq(0, rst_audit_count('restaurant.payment_paid', $s['payment_id']), 'no payment_paid audit');
    assert_eq($notifBefore + 1, rst_notif_count(), 'exactly one admin notification');
    assert_eq(0, rst_charge_count($s['pi']), 'no charge row may be written for a mismatch');
});

unit('REST-001 mismatch: hosted-Checkout-shaped actuals (session amount_total) are verified the same way', function (): void {
    $s = rst_seed(8000, 0, 'other');
    // settleOnlineFromSession() feeds session amount_total + currency + the
    // PI's real latest_charge into the same entry point.
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], 7999, 'usd', 'ch_rst_hosted_bad');
    assert_eq('mismatch', $res['state'] ?? null);
    assert_eq('amount_mismatch', $res['issue'] ?? null);
    $pay = rst_payment($s['payment_id']);
    assert_eq('pending', $pay['status']);
    assert_null($pay['stripe_charge_id'], 'a mismatched settle must not stamp a charge id');
});

unit('REST-001 mismatch: an amount LOWER than expected also never settles (no partial-paid orders)', function (): void {
    $s = rst_seed();
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], $s['expected'] - 1, 'usd');
    assert_eq('mismatch', $res['state'] ?? null);
    assert_eq('amount_mismatch', $res['issue'] ?? null);
    assert_eq('pending', rst_payment($s['payment_id'])['status']);
    assert_eq('open', rst_order($s['order_id'])['status']);
});

unit('REST-001 reconcile: currency mismatch wins even when the amount is also wrong', function (): void {
    $s = rst_seed();
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], $s['expected'] + 500, 'eur');
    assert_eq('mismatch', $res['state'] ?? null);
    assert_eq('currency_mismatch', $res['issue'] ?? null, 'currency is checked before amount');
    assert_eq('currency_mismatch', rst_payment($s['payment_id'])['settlement_issue']);
    assert_eq('pending', rst_payment($s['payment_id'])['status']);
});

unit('REST-001 reconcile: an empty actual currency is unverifiable and never settles', function (): void {
    $s = rst_seed();
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], $s['expected'], '');
    assert_eq('mismatch', $res['state'] ?? null);
    assert_eq('currency_unverifiable', $res['issue'] ?? null);
    assert_eq('pending', rst_payment($s['payment_id'])['status']);
    assert_eq(0, rst_charge_count($s['pi']));
});

unit('REST-001 reconcile: a missing actual amount is unverifiable and never settles', function (): void {
    $s = rst_seed();
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], null, 'usd');
    assert_eq('mismatch', $res['state'] ?? null);
    assert_eq('amount_unverifiable', $res['issue'] ?? null);
    assert_eq('pending', rst_payment($s['payment_id'])['status']);
});

unit('REST-001 reconcile: unit-level rules of reconcileStripeAmount (expected = amount + tip, row currency first)', function (): void {
    $row = ['amount_cents' => 10000, 'tip_cents' => 1500, 'currency' => 'USD'];
    $ok = RestaurantAPI::reconcileStripeAmount($row, 11500, 'usd');
    assert_true($ok['ok'], 'amount+tip in the row currency must reconcile');
    $bad = RestaurantAPI::reconcileStripeAmount($row, 11501, 'eur');
    assert_eq('currency_mismatch', $bad['issue'], 'currency is evaluated before amount');
});

// ── Happy paths ─────────────────────────────────────────────────────────

unit('REST-001 terminal happy path: markPaymentPaid settles, stamps the real charge, closes the order, one charge + one audit', function (): void {
    $s = rst_seed();
    RestaurantAPI::markPaymentPaid($s['payment_id'], [
        'amount_received' => $s['expected'], 'currency' => 'usd', 'latest_charge' => 'ch_rst_terminal1',
    ]);
    $pay = rst_payment($s['payment_id']);
    assert_eq('paid', $pay['status']);
    assert_eq('ch_rst_terminal1', $pay['stripe_charge_id'], 'stripe_charge_id must be the charge id, never the PI id');
    assert_null($pay['settlement_issue']);
    $order = rst_order($s['order_id']);
    assert_eq('paid', $order['status'], 'a fully paid order closes as paid');
    assert_true($order['closed_at'] !== null, 'closed_at is stamped');
    assert_eq($s['expected'], (int)$order['paid_cents']);
    assert_eq(1, rst_charge_count($s['pi']), 'exactly one central charge row');
    $charge = Database::row("SELECT * FROM stripepayment_charges WHERE stripe_payment_intent_id = ?", [$s['pi']]);
    assert_eq($s['expected'], (int)$charge['amount_cents'], 'the charge records amount + tip');
    assert_eq('restaurant', $charge['source_plugin']);
    assert_eq(1, rst_audit_count('restaurant.payment_paid', $s['payment_id']), 'exactly one payment_paid audit');
});

unit('REST-001 embedded happy path: a synthetic webhook event settles the payment', function (): void {
    $s = rst_seed(5000, 0, 'other');
    RestaurantAPI::handleStripeEvent(rst_event($s, 'payment_intent.succeeded', 'ch_rst_embedded1'));
    $pay = rst_payment($s['payment_id']);
    assert_eq('paid', $pay['status']);
    assert_eq('ch_rst_embedded1', $pay['stripe_charge_id']);
    assert_eq('paid', rst_order($s['order_id'])['status']);
    assert_eq(1, rst_charge_count($s['pi']));
    assert_eq(1, rst_audit_count('restaurant.payment_paid', $s['payment_id']));
});

unit('REST-001 hosted happy path: settlePaymentWithActuals stamps the session charge id (not the PI id)', function (): void {
    $s = rst_seed(8000, 0, 'other');
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], 8000, 'usd', 'ch_rst_hosted1');
    assert_eq('paid', $res['state'] ?? null);
    $pay = rst_payment($s['payment_id']);
    assert_eq('paid', $pay['status']);
    assert_eq('ch_rst_hosted1', $pay['stripe_charge_id']);
    assert_true($pay['stripe_charge_id'] !== $s['pi'], 'the PI id must never land in stripe_charge_id');
});

// ── Replay, healing, alert-once ─────────────────────────────────────────

unit('REST-001 webhook replay x3: still one charge, one payment_paid audit, order paid once', function (): void {
    $s = rst_seed();
    $event = rst_event($s, 'payment_intent.succeeded', 'ch_rst_replay1');
    RestaurantAPI::handleStripeEvent($event);
    RestaurantAPI::handleStripeEvent($event);
    RestaurantAPI::handleStripeEvent($event);
    assert_eq('paid', rst_payment($s['payment_id'])['status']);
    assert_eq(1, rst_charge_count($s['pi']), 'replays must not duplicate the charge row');
    assert_eq(1, rst_audit_count('restaurant.payment_paid', $s['payment_id']), 'replays must not duplicate the audit');
    assert_eq($s['expected'], (int)rst_order($s['order_id'])['paid_cents'], 'paid_cents is a re-sum, not an accumulation');
});

unit('REST-001 healing: an already-paid payment with a lost charge row + unsynced order converges without a second audit', function (): void {
    $s = rst_seed();
    RestaurantAPI::markPaymentPaid($s['payment_id'], [
        'amount_received' => $s['expected'], 'currency' => 'usd', 'latest_charge' => 'ch_rst_heal1',
    ]);
    assert_eq(1, rst_audit_count('restaurant.payment_paid', $s['payment_id']), 'setup: settled once');
    // Simulate the partial failure: charge row lost, order sync lost.
    Database::query("DELETE FROM stripepayment_charges WHERE stripe_payment_intent_id = ?", [$s['pi']]);
    Database::update('restaurant_orders',
        ['status' => 'open', 'paid_cents' => 0, 'closed_at' => null, 'tip_cents' => 0],
        'id = ?', [$s['order_id']]);
    RestaurantAPI::markPaymentPaid($s['payment_id'], [
        'amount_received' => $s['expected'], 'currency' => 'usd', 'latest_charge' => 'ch_rst_heal1',
    ]);
    assert_eq(1, rst_charge_count($s['pi']), 'healing recreates the charge row');
    $order = rst_order($s['order_id']);
    assert_eq('paid', $order['status'], 'healing re-syncs the order');
    assert_eq($s['expected'], (int)$order['paid_cents']);
    assert_eq(1, rst_audit_count('restaurant.payment_paid', $s['payment_id']),
        'healing must NOT write a second payment_paid audit');
});

unit('REST-001 mismatch alert-once: three identical mismatch attempts produce one audit + one notification total', function (): void {
    $s = rst_seed();
    $notifBefore = rst_notif_count();
    for ($i = 0; $i < 3; $i++) {
        $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], $s['expected'] + 500, 'usd');
        assert_eq('mismatch', $res['state'] ?? null, "attempt $i still reports the mismatch");
    }
    assert_eq(1, rst_audit_count('restaurant.settlement_mismatch', $s['payment_id']));
    assert_eq($notifBefore + 1, rst_notif_count(), 'the alert fires only on the NULL -> issue transition');
    assert_eq('pending', rst_payment($s['payment_id'])['status']);
});

// ── Failed / cancel can never un-pay ────────────────────────────────────

unit('REST-001 payment_failed on an already-paid payment leaves it paid', function (): void {
    $s = rst_seed();
    RestaurantAPI::markPaymentPaid($s['payment_id'], [
        'amount_received' => $s['expected'], 'currency' => 'usd', 'latest_charge' => 'ch_rst_failpaid',
    ]);
    assert_eq('paid', rst_payment($s['payment_id'])['status'], 'setup: paid');
    RestaurantAPI::handleStripeEvent(rst_event($s, 'payment_intent.payment_failed'));
    assert_eq('paid', rst_payment($s['payment_id'])['status'], 'a late failure event must never flip a settled payment');
});

unit('REST-001 payment_failed on a pending payment marks it failed', function (): void {
    $s = rst_seed();
    RestaurantAPI::handleStripeEvent(rst_event($s, 'payment_intent.payment_failed'));
    assert_eq('failed', rst_payment($s['payment_id'])['status']);
    assert_eq(0, rst_charge_count($s['pi']));
});

unit('REST-001 cancelTerminalPayment on a paid payment leaves it paid', function (): void {
    $s = rst_seed();
    RestaurantAPI::markPaymentPaid($s['payment_id'], [
        'amount_received' => $s['expected'], 'currency' => 'usd', 'latest_charge' => 'ch_rst_cancel1',
    ]);
    assert_eq('paid', rst_payment($s['payment_id'])['status'], 'setup: paid');
    // Stripe is unconfigured in tests, so terminalAvailable() is false and no
    // reader/PI cancel is attempted — the final conditional UPDATE still runs.
    $res = RestaurantAPI::cancelTerminalPayment($s['payment_id']);
    assert_true($res['ok'] ?? false);
    assert_eq('paid', rst_payment($s['payment_id'])['status'], 'cancel may only fail a still-pending payment');
});

// ── Charge-ledger convergence ───────────────────────────────────────────

unit('REST-001 convergence: a pre-existing generic (unknown) charge row for the PI is reused, not duplicated', function (): void {
    $s = rst_seed();
    $preId = StripePaymentAPI::recordCharge([
        'source_plugin' => 'unknown',
        'stripe_payment_intent_id' => $s['pi'],
        'amount_cents' => $s['expected'],
        'currency' => 'USD',
        'status' => 'succeeded',
    ]);
    assert_true($preId !== null && $preId > 0, 'setup: the generic webhook row exists');
    assert_eq(1, rst_charge_count($s['pi']), 'setup: one charge row');
    RestaurantAPI::markPaymentPaid($s['payment_id'], [
        'amount_received' => $s['expected'], 'currency' => 'usd', 'latest_charge' => 'ch_rst_conv1',
    ]);
    assert_eq('paid', rst_payment($s['payment_id'])['status']);
    assert_eq(1, rst_charge_count($s['pi']), 'settlement converges on the existing row — total stays 1');
});

// ── Totals regression (the settler's neighbour: recalc must not drift) ──

unit('REST-001 totals regression: openOrder + addLine total equals the line subtotal at 0% tax / no service charge', function (): void {
    $tid = current_tenant_id();
    $prevSvc = Database::setting('restaurant.service_charge_auto');
    $prevTax = Database::setting('restaurant.default_tax_rate');
    Database::setSetting('restaurant.service_charge_auto', '0');
    Database::setSetting('restaurant.default_tax_rate', '0');
    $catId = 0; $itemId = 0; $orderId = 0;
    try {
        $slug = 'rst-cat-' . bin2hex(random_bytes(4));
        $catId = Database::insert('restaurant_menu_categories', [
            'tenant_id' => $tid, 'name' => 'RST Category', 'slug' => $slug, 'is_active' => 1,
        ]);
        $itemId = Database::insert('restaurant_items', [
            'tenant_id' => $tid, 'category_id' => $catId, 'name' => 'RST Burger',
            'slug' => 'rst-item-' . bin2hex(random_bytes(4)), 'price_cents' => 1250,
            'tax_rate' => 0, 'is_active' => 1, 'is_86' => 0,
        ]);
        $orderId = RestaurantAPI::openOrder(['type' => 'takeout']);
        RestaurantAPI::addLine($orderId, ['item_id' => $itemId, 'qty' => 2]);
        $order = rst_order($orderId);
        assert_eq(2500, (int)$order['subtotal_cents'], 'subtotal = 2 x 1250');
        assert_eq(0, (int)$order['tax_cents']);
        assert_eq($order['subtotal_cents'], $order['total_cents'], 'total == subtotal with tax/service at 0');
    } finally {
        if ($orderId > 0) {
            Database::query("DELETE FROM restaurant_order_item_modifiers WHERE order_item_id IN (SELECT id FROM restaurant_order_items WHERE order_id = ?)", [$orderId]);
            Database::query("DELETE FROM restaurant_order_items WHERE order_id = ?", [$orderId]);
            Database::query("DELETE FROM audit_log WHERE action LIKE 'restaurant.%' AND target = ?", [(string)$orderId]);
            Database::query("DELETE FROM restaurant_orders WHERE id = ?", [$orderId]);
        }
        if ($itemId > 0) Database::query("DELETE FROM restaurant_items WHERE id = ?", [$itemId]);
        if ($catId > 0) Database::query("DELETE FROM restaurant_menu_categories WHERE id = ?", [$catId]);
        if ($prevSvc === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = 'restaurant.service_charge_auto'", [$tid]);
        else Database::setSetting('restaurant.service_charge_auto', $prevSvc);
        if ($prevTax === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = 'restaurant.default_tax_rate'", [$tid]);
        else Database::setSetting('restaurant.default_tax_rate', $prevTax);
    }
});

unit('REST-001 cleanup: settlement suite leaves no seeded rows behind', function (): void {
    rst_sweep();
    assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM restaurant_payments WHERE stripe_pi_id LIKE 'pi_rst_%'"));
    assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE 'pi_rst_%'"));
});
