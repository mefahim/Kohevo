<?php
/**
 * REST-001 — settlement claim races.
 *
 * The claim is a lease (settlement_claim_token + settlement_claimed_at,
 * 120 seconds) taken by ONE conditional UPDATE in
 * RestaurantAPI::settlePaymentWithActuals(); the completion UPDATE is gated
 * on still owning the token. This suite pins the three consequences:
 *
 *  (a) a live claim excludes every other settler — deterministically, by
 *      writing a fresh claim directly and watching the real settler refuse;
 *  (b) an EXPIRED claim (owner died) is taken over by the next natural
 *      attempt — no manual database surgery, no cron;
 *  (c) the dead owner's late completion (a T3-shaped UPDATE carrying the
 *      OLD token) matches zero rows and changes nothing;
 *  (d) four genuinely concurrent OS processes (tests/fixtures/
 *      restaurant-settlement-probe.php, released simultaneously via a flag
 *      file) settling the same payment produce exactly one charge row,
 *      exactly one restaurant.payment_paid audit and a paid order.
 *
 * Seeded rows carry the pi_rsr_ prefix and are swept before/after, so
 * re-runs and combined-suite runs start clean.
 */

declare(strict_types=1);

function rsr_boot(): void
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
    // Land the charges table's unique keys BEFORE any concurrency, so the
    // race below tests the settler's claim — not a first-call schema ALTER
    // happening inside four processes at once.
    if (class_exists('StripePaymentAPI')) StripePaymentAPI::ensureChargesSchema();
}

function rsr_sweep(): void
{
    $pays = Database::rows("SELECT id, order_id FROM restaurant_payments WHERE stripe_pi_id LIKE 'pi_rsr_%'");
    $payIds = array_map(fn($r) => (int)$r['id'], $pays);
    $orderIds = array_values(array_unique(array_map(fn($r) => (int)$r['order_id'], $pays)));
    Database::query("DELETE FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE 'pi_rsr_%'");
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
}

function rsr_seed(int $amount = 10000, int $tip = 1500): array
{
    $tid = current_tenant_id();
    $pi = 'pi_rsr_' . bin2hex(random_bytes(8));
    $orderId = Database::insert('restaurant_orders', [
        'tenant_id' => $tid, 'order_no' => 'RSR-' . strtoupper(bin2hex(random_bytes(4))),
        'type' => 'takeout', 'status' => 'open', 'guest_count' => 1,
        'subtotal_cents' => $amount, 'total_cents' => $amount + $tip, 'paid_cents' => 0,
        'source' => 'pos',
    ]);
    Database::insert('restaurant_order_items', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'check_no' => 1, 'item_id' => null,
        'name_snapshot' => 'RSR seeded item', 'qty' => 1, 'unit_price_cents' => $amount,
        'modifiers_total_cents' => 0, 'line_total_cents' => $amount, 'tax_rate' => 0,
        'kitchen_status' => 'new',
    ]);
    $payId = Database::insert('restaurant_payments', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'method' => 'terminal',
        'amount_cents' => $amount, 'tip_cents' => $tip, 'status' => 'pending',
        'stripe_pi_id' => $pi, 'currency' => 'USD',
    ]);
    return ['order_id' => $orderId, 'payment_id' => $payId, 'pi' => $pi,
            'amount' => $amount, 'tip' => $tip, 'expected' => $amount + $tip];
}

function rsr_paid_audits(int $paymentId): int
{
    return (int) Database::value(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'restaurant.payment_paid' AND target = ?",
        [(string)$paymentId]);
}

function rsr_charges(string $pi): int
{
    return (int) Database::value(
        "SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_payment_intent_id = ?", [$pi]);
}

/** A 32-char claim token in the same shape the settler generates (CHAR(32) column). */
function rsr_token(string $label): string
{
    return str_pad($label, 32, '0');
}

function rsr_probe_async(int $paymentId, int $actualCents, string $currency, ?string $flag): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/restaurant-settlement-probe.php') . ' '
         . escapeshellarg((string)$paymentId) . ' ' . escapeshellarg((string)$actualCents) . ' '
         . escapeshellarg($currency)
         . ($flag !== null ? ' --wait-flag ' . escapeshellarg($flag) : '');
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    return ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

function rsr_probe_wait(array $handle): string
{
    $out = stream_get_contents($handle['stdout']);
    $err = stream_get_contents($handle['stderr']);
    fclose($handle['stdout']);
    fclose($handle['stderr']);
    proc_close($handle['proc']);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$out)), fn($l) => $l !== ''));
    $last = end($lines);
    if ($last === false || !str_starts_with($last, '{')) {
        throw new \Exception('probe produced no JSON state line; stdout=' . trim((string)$out) . ' stderr=' . trim((string)$err));
    }
    $decoded = json_decode($last, true);
    return (string)($decoded['state'] ?? 'error');
}

rsr_boot();
rsr_sweep();

unit('REST-001 race (a): a live claim excludes another settler — no side effects at all', function (): void {
    $s = rsr_seed();
    Database::query(
        "UPDATE restaurant_payments SET settlement_claim_token = ?, settlement_claimed_at = NOW() WHERE id = ?",
        [rsr_token('probe-live-claim'), $s['payment_id']]);
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], $s['expected'], 'usd');
    assert_eq('claimed', $res['state'] ?? null, 'a fresh claim must refuse a second settler');
    $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$s['payment_id']]);
    assert_eq('pending', $pay['status'], 'the payment stays pending');
    assert_eq(rsr_token('probe-live-claim'), $pay['settlement_claim_token'], 'the live claim is untouched');
    assert_eq(0, rsr_charges($s['pi']), 'no charge row');
    assert_eq(0, rsr_paid_audits($s['payment_id']), 'no payment_paid audit');
});

unit('REST-001 race (b): an expired claim (300s old) is taken over and the settlement completes', function (): void {
    $s = rsr_seed();
    Database::query(
        "UPDATE restaurant_payments SET settlement_claim_token = ?, settlement_claimed_at = DATE_SUB(NOW(), INTERVAL 300 SECOND) WHERE id = ?",
        [rsr_token('probe-dead-claim'), $s['payment_id']]);
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], $s['expected'], 'usd');
    assert_eq('paid', $res['state'] ?? null, 'a claim older than the 120s lease is abandoned and may be taken over');
    $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$s['payment_id']]);
    assert_eq('paid', $pay['status']);
    assert_null($pay['settlement_claim_token'], 'the completed settlement clears the claim');
    assert_eq(1, rsr_charges($s['pi']));
    assert_eq(1, rsr_paid_audits($s['payment_id']));
});

unit('REST-001 race (c): a zombie settler holding the OLD token cannot complete after takeover', function (): void {
    $s = rsr_seed();
    $oldToken = rsr_token('probe-zombie-claim');
    Database::query(
        "UPDATE restaurant_payments SET settlement_claim_token = ?, settlement_claimed_at = DATE_SUB(NOW(), INTERVAL 300 SECOND) WHERE id = ?",
        [$oldToken, $s['payment_id']]);
    $res = RestaurantAPI::settlePaymentWithActuals($s['payment_id'], $s['expected'], 'usd');
    assert_eq('paid', $res['state'] ?? null, 'setup: the lease takeover settles the payment');

    // The dead settler wakes up and attempts its completion UPDATE with the
    // token it used to own — the exact predicate shape of the settler's T3.
    $n = Database::update('restaurant_payments', [
        'status' => 'paid', 'settlement_claim_token' => null, 'settlement_claimed_at' => null,
    ], 'id = ? AND status = ? AND settlement_claim_token = ?', [$s['payment_id'], 'pending', $oldToken]);
    assert_eq(0, $n, 'the zombie completion must match zero rows');

    $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$s['payment_id']]);
    assert_eq('paid', $pay['status'], 'the legitimate settlement is unchanged');
    assert_eq(1, rsr_paid_audits($s['payment_id']), 'still exactly one payment_paid audit');
    assert_eq(1, rsr_charges($s['pi']), 'still exactly one charge row');
});

unit('REST-001 race (d): four simultaneous probe processes settle one payment exactly once', function (): void {
    $s = rsr_seed();
    $flag = sys_get_temp_dir() . '/rsr_flag_' . bin2hex(random_bytes(6));
    if (file_exists($flag)) unlink($flag);
    $handles = [];
    try {
        for ($i = 0; $i < 4; $i++) {
            $handles[] = rsr_probe_async($s['payment_id'], $s['expected'], 'usd', $flag);
        }
        // Give every probe time to boot and park on the flag, then release
        // them all at the same instant.
        usleep(1500000);
        file_put_contents($flag, 'go');
        $states = array_map('rsr_probe_wait', $handles);
    } finally {
        if (file_exists($flag)) unlink($flag);
    }
    $paidCount = count(array_filter($states, fn($st) => $st === 'paid'));
    assert_true($paidCount <= 1, 'at most one process may report paid, got: ' . implode(',', $states));
    foreach ($states as $st) {
        assert_true(in_array($st, ['paid', 'claimed', 'already_paid', 'lost'], true),
            'losing processes must end claimed/already_paid/lost — never error; got: ' . implode(',', $states));
    }
    $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$s['payment_id']]);
    assert_eq('paid', $pay['status']);
    assert_eq(1, rsr_charges($s['pi']), 'exactly one charge row after the race');
    assert_eq(1, rsr_paid_audits($s['payment_id']), 'exactly one payment_paid audit after the race');
    $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$s['order_id']]);
    assert_eq('paid', $order['status'], 'the order is paid exactly once');
    assert_eq($s['expected'], (int)$order['paid_cents']);
});

unit('REST-001 race cleanup: race suite leaves no seeded rows behind', function (): void {
    rsr_sweep();
    assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM restaurant_payments WHERE stripe_pi_id LIKE 'pi_rsr_%'"));
    assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE 'pi_rsr_%'"));
});
