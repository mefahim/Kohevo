<?php
/**
 * REST-001 — settlement tenant isolation.
 *
 * The stripe webhook endpoint runs pinned to TENANT_ID (tenant 1 here),
 * which settlement must NOT trust: the payment row's tenant_id is the
 * source of truth, and the whole settlement (charge row, order sync, audit)
 * runs inside with_tenant(payment.tenant_id). Pinned here:
 *
 *  - a webhook event settles a TENANT-2 payment while the ambient tenant
 *    is 1, and every record lands under tenant 2;
 *  - the same stripe_pi_id string on a tenant-1 AND a tenant-2 payment is
 *    ambiguous pre-index legacy data: the webhook settles NEITHER rather
 *    than guessing (the uniq_tenant_pi index permits the pair — it is
 *    unique per tenant, not global);
 *  - RestaurantAPI::recordCashPayment() verifies order ownership in the
 *    function itself (REST-001): called from tenant 1 against a tenant-2
 *    order it returns 0 and inserts nothing — with a same-tenant positive
 *    control proving the path itself works.
 *
 * The synthetic tenant is slate_test_tenant(918200), namespaced per
 * checkout like the other suites. All seeded rows carry the pi_rti_
 * prefix (or the synthetic tenant id) and are swept before/after —
 * including the duplicate-PI pair, so the migration suite's global
 * duplicate scan is never polluted when files run together.
 */

declare(strict_types=1);

function rti_boot(): void
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

function rti_t2(): int
{
    return slate_test_tenant(918200);
}

function rti_ensure_tenant(int $tid): void
{
    if (!Database::row("SELECT id FROM tenants WHERE id = ?", [$tid])) {
        Database::insert('tenants', [
            'id' => $tid, 'name' => 'RTI Synthetic Tenant', 'slug' => 'rti-' . $tid, 'status' => 'active',
        ]);
    }
}

function rti_sweep(): void
{
    $t2 = rti_t2();
    $pays = Database::rows(
        "SELECT id, order_id FROM restaurant_payments WHERE stripe_pi_id LIKE 'pi_rti_%' OR tenant_id = ?", [$t2]);
    $payIds = array_map(fn($r) => (int)$r['id'], $pays);
    $orderIds = array_values(array_unique(array_map(fn($r) => (int)$r['order_id'], $pays)));
    // Cash-control orders carry no card payment PI; find them via the tenant + order_no marker.
    foreach (Database::rows("SELECT id FROM restaurant_orders WHERE tenant_id = ? OR order_no LIKE 'RTI-%'", [$t2]) as $o) {
        $orderIds[] = (int)$o['id'];
    }
    $orderIds = array_values(array_unique($orderIds));
    Database::query("DELETE FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE 'pi_rti_%'");
    if ($payIds) {
        $in = implode(',', $payIds);
        Database::query("DELETE FROM audit_log WHERE target IN ($in)");
    }
    if ($orderIds) {
        $in = implode(',', $orderIds);
        Database::query("DELETE FROM restaurant_order_item_modifiers WHERE order_item_id IN (SELECT id FROM restaurant_order_items WHERE order_id IN ($in))");
        Database::query("DELETE FROM restaurant_order_items WHERE order_id IN ($in)");
        Database::query("DELETE FROM restaurant_payments WHERE order_id IN ($in)");
        Database::query("DELETE FROM audit_log WHERE target IN ($in)");
        Database::query("DELETE FROM restaurant_orders WHERE id IN ($in)");
    }
}

/** Seed order + pending card payment in an explicit tenant (no context switch). */
function rti_seed(int $tid, ?string $pi = null, int $amount = 10000, int $tip = 1500): array
{
    $pi = $pi ?? ('pi_rti_' . bin2hex(random_bytes(8)));
    $orderId = Database::insert('restaurant_orders', [
        'tenant_id' => $tid, 'order_no' => 'RTI-' . strtoupper(bin2hex(random_bytes(4))),
        'type' => 'takeout', 'status' => 'open', 'guest_count' => 1,
        'subtotal_cents' => $amount, 'total_cents' => $amount + $tip, 'paid_cents' => 0,
        'source' => 'pos',
    ]);
    Database::insert('restaurant_order_items', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'check_no' => 1, 'item_id' => null,
        'name_snapshot' => 'RTI seeded item', 'qty' => 1, 'unit_price_cents' => $amount,
        'modifiers_total_cents' => 0, 'line_total_cents' => $amount, 'tax_rate' => 0,
        'kitchen_status' => 'new',
    ]);
    $payId = Database::insert('restaurant_payments', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'method' => 'terminal',
        'amount_cents' => $amount, 'tip_cents' => $tip, 'status' => 'pending',
        'stripe_pi_id' => $pi, 'currency' => 'USD',
    ]);
    return ['order_id' => $orderId, 'payment_id' => $payId, 'pi' => $pi,
            'amount' => $amount, 'tip' => $tip, 'expected' => $amount + $tip, 'tenant_id' => $tid];
}

function rti_event(array $seed): array
{
    return ['type' => 'payment_intent.succeeded', 'data' => ['object' => [
        'id' => $seed['pi'],
        'metadata' => ['source' => 'restaurant'],
        'amount_received' => $seed['expected'],
        'currency' => 'usd',
        'latest_charge' => 'ch_rti_' . bin2hex(random_bytes(6)),
    ]]];
}

rti_boot();
rti_ensure_tenant(rti_t2());
rti_sweep();

unit('REST-001 tenant: a webhook settles a tenant-2 payment while the ambient tenant is 1 — every record lands in tenant 2', function (): void {
    assert_eq(1, current_tenant_id(), 'precondition: the webhook context is pinned to tenant 1');
    $s = rti_seed(rti_t2());
    RestaurantAPI::handleStripeEvent(rti_event($s));

    $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$s['payment_id']]);
    assert_eq('paid', $pay['status'], 'the tenant-2 payment settles');
    $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$s['order_id']]);
    assert_eq('paid', $order['status'], 'the tenant-2 order is synced + paid');
    assert_eq($s['expected'], (int)$order['paid_cents']);

    $charge = Database::row(
        "SELECT * FROM stripepayment_charges WHERE stripe_payment_intent_id = ?", [$s['pi']]);
    assert_true($charge !== null, 'a charge row exists');
    assert_eq(rti_t2(), (int)$charge['tenant_id'], 'the charge row belongs to the PAYMENT tenant, not the ambient one');

    $audit = Database::row(
        "SELECT * FROM audit_log WHERE action = 'restaurant.payment_paid' AND target = ?", [(string)$s['payment_id']]);
    assert_true($audit !== null, 'a payment_paid audit exists');
    assert_eq(rti_t2(), (int)$audit['tenant_id'], 'the audit is recorded under the payment tenant');
    assert_eq(1, current_tenant_id(), 'the ambient tenant is restored afterwards');
});

unit('REST-001 tenant: the same PI string on a tenant-1 and a tenant-2 payment settles NEITHER', function (): void {
    $pi = 'pi_rti_dup_' . bin2hex(random_bytes(6));
    $a = rti_seed(1, $pi);
    $b = rti_seed(rti_t2(), $pi);
    try {
        RestaurantAPI::handleStripeEvent(rti_event($a));
        $pa = Database::row("SELECT status FROM restaurant_payments WHERE id = ?", [$a['payment_id']]);
        $pb = Database::row("SELECT status FROM restaurant_payments WHERE id = ?", [$b['payment_id']]);
        assert_eq('pending', $pa['status'], 'ambiguous PI: the tenant-1 payment must not settle on a guess');
        assert_eq('pending', $pb['status'], 'ambiguous PI: the tenant-2 payment must not settle on a guess');
        assert_eq(0, (int) Database::value(
            "SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_payment_intent_id = ?", [$pi]),
            'no charge row for an ambiguous PI');
        assert_eq(0, (int) Database::value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'restaurant.payment_paid' AND target IN (?, ?)",
            [(string)$a['payment_id'], (string)$b['payment_id']]),
            'no payment_paid audit for either payment');
    } finally {
        // The duplicate pair must not survive this file: the migration
        // suite's global duplicate scan would (correctly) report it.
        Database::query("DELETE FROM restaurant_payments WHERE stripe_pi_id = ?", [$pi]);
        foreach ([$a, $b] as $s) {
            Database::query("DELETE FROM restaurant_order_items WHERE order_id = ?", [$s['order_id']]);
            Database::query("DELETE FROM restaurant_orders WHERE id = ?", [$s['order_id']]);
        }
    }
});

unit('REST-001 tenant: recordCashPayment from tenant 1 against a tenant-2 order returns 0 and inserts nothing', function (): void {
    $s = rti_seed(rti_t2());
    assert_eq(1, current_tenant_id(), 'precondition: ambient tenant is 1');
    $pid = RestaurantAPI::recordCashPayment($s['order_id'], 5000, 0);
    assert_eq(0, $pid, 'a foreign-tenant order is refused in the API itself, not only by callers');
    assert_eq(0, (int) Database::value(
        "SELECT COUNT(*) FROM restaurant_payments WHERE order_id = ? AND method = 'cash'", [$s['order_id']]),
        'no cash payment row was inserted');
});

unit('REST-001 tenant: recordCashPayment positive control — a same-tenant order tenders normally', function (): void {
    $s = rti_seed(1);
    $pid = RestaurantAPI::recordCashPayment($s['order_id'], $s['expected'], 0);
    assert_true($pid > 0, 'the same-tenant cash path still works');
    $row = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$pid]);
    assert_eq('cash', $row['method']);
    assert_eq('paid', $row['status']);
    assert_eq(1, (int)$row['tenant_id']);
});

unit('REST-001 tenant cleanup: isolation suite leaves no seeded rows behind', function (): void {
    rti_sweep();
    assert_eq(0, (int) Database::value(
        "SELECT COUNT(*) FROM restaurant_payments WHERE stripe_pi_id LIKE 'pi_rti_%' OR tenant_id = ?", [rti_t2()]));
    assert_eq(0, (int) Database::value(
        "SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE 'pi_rti_%'"));
    assert_eq(0, (int) Database::value(
        "SELECT COUNT(*) FROM restaurant_payments p JOIN restaurant_payments q
          ON p.tenant_id <> q.tenant_id AND p.stripe_pi_id = q.stripe_pi_id
         WHERE p.stripe_pi_id LIKE 'pi_rti_%'"),
        'no cross-tenant duplicate PI survives this file');
    Database::query("DELETE FROM tenants WHERE id = ?", [rti_t2()]);
});
