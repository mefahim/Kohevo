<?php
/**
 * REST-001, E8 — concurrent webhook-vs-terminal-poll settlement race.
 *
 * The audit gap this closes: the settlement suites raced settler-vs-settler
 * (RestaurantSettlementRaceTest) and replayed the webhook sequentially, but
 * never ran the WEBHOOK path and the POLL path against the same payment at
 * the same time. In production that overlap is routine: Stripe delivers
 * payment_intent.succeeded while the POS is mid-poll on the same payment.
 *
 * Design — two OS processes released simultaneously via a flag file (the
 * mechanism RestaurantSettlementRaceTest established):
 *
 *   webhook probe    — the REAL RestaurantAPI::handleStripeEvent() with a
 *                      synthetic succeeded event. This entry point performs
 *                      no Stripe HTTP calls, so it runs end-to-end here.
 *   pollsettle probe — RestaurantAPI::settlePaymentWithActuals() fed with
 *                      post-capture PaymentIntent actuals. This is the
 *                      poll's own settlement entry: pollTerminalPayment()
 *                      acquires the same claim and completes through the
 *                      same machinery once its Stripe fetch/capture
 *                      returns. The poll's TRANSPORT half cannot run in a
 *                      test — getPaymentIntent()/capture() issue HTTPS
 *                      calls to api.stripe.com and API_BASE is a private
 *                      constant with no test seam, so running it would
 *                      mean calling the live Stripe API with test keys.
 *                      The race for financial side effects lives entirely
 *                      in the claim → reconcile → completion layer both
 *                      probes drive here; nothing about the claim or the
 *                      completion is mocked or bypassed.
 *
 * Assertions (parent process, after both probes exit): both probes exit 0;
 * the payment is paid; exactly ONE stripepayment_charges row; exactly ONE
 * restaurant.payment_paid audit; the order is paid with paid_cents equal
 * to amount + tip. A follow-up replay of BOTH attempts must then change
 * nothing at all.
 *
 * Seeded rows carry the pi_rwp_ prefix and are swept before/after.
 */

declare(strict_types=1);

function rwp_boot(): void
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

function rwp_sweep(): void
{
    $pays = Database::rows("SELECT id, order_id FROM restaurant_payments WHERE stripe_pi_id LIKE 'pi_rwp_%'");
    $payIds = array_map(fn($r) => (int)$r['id'], $pays);
    $orderIds = array_values(array_unique(array_map(fn($r) => (int)$r['order_id'], $pays)));
    Database::query("DELETE FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE 'pi_rwp_%'");
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

function rwp_seed(int $amount = 12000, int $tip = 2000): array
{
    $tid = current_tenant_id();
    $pi = 'pi_rwp_' . bin2hex(random_bytes(8));
    $orderId = Database::insert('restaurant_orders', [
        'tenant_id' => $tid, 'order_no' => 'RWP-' . strtoupper(bin2hex(random_bytes(4))),
        'type' => 'dine_in', 'status' => 'open', 'guest_count' => 2,
        'subtotal_cents' => $amount, 'total_cents' => $amount + $tip, 'paid_cents' => 0,
        'source' => 'pos',
    ]);
    Database::insert('restaurant_order_items', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'check_no' => 1, 'item_id' => null,
        'name_snapshot' => 'RWP seeded item', 'qty' => 1, 'unit_price_cents' => $amount,
        'modifiers_total_cents' => 0, 'line_total_cents' => $amount, 'tax_rate' => 0,
        'kitchen_status' => 'new',
    ]);
    $payId = Database::insert('restaurant_payments', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'method' => 'terminal',
        'amount_cents' => $amount, 'tip_cents' => $tip, 'status' => 'pending',
        'stripe_pi_id' => $pi, 'currency' => 'USD',
    ]);
    return ['order_id' => $orderId, 'payment_id' => $payId, 'pi' => $pi,
            'charge' => 'ch_rwp_' . bin2hex(random_bytes(6)),
            'amount' => $amount, 'tip' => $tip, 'expected' => $amount + $tip];
}

/** Launch one probe process; returns a handle for rwp_probe_wait(). */
function rwp_probe_async(string $mode, array $seed, ?string $flag): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/restaurant-webhook-poll-probe.php') . ' '
         . escapeshellarg($mode) . ' ' . escapeshellarg((string)$seed['payment_id']) . ' '
         . escapeshellarg($seed['pi']) . ' ' . escapeshellarg((string)$seed['expected']) . ' '
         . escapeshellarg('usd') . ' ' . escapeshellarg($seed['charge'])
         . ($flag !== null ? ' --wait-flag ' . escapeshellarg($flag) : '');
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    return ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'mode' => $mode];
}

/** Collect a probe: returns ['exit' => int, 'json' => array, 'stderr' => string]. */
function rwp_probe_wait(array $handle): array
{
    $out = stream_get_contents($handle['stdout']);
    $err = stream_get_contents($handle['stderr']);
    fclose($handle['stdout']);
    fclose($handle['stderr']);
    $exit = proc_close($handle['proc']);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$out)), fn($l) => $l !== ''));
    $last = end($lines);
    $json = ($last !== false && str_starts_with($last, '{')) ? (json_decode($last, true) ?: []) : [];
    return ['exit' => $exit, 'json' => $json, 'stderr' => trim((string)$err), 'mode' => $handle['mode']];
}

function rwp_charges(string $pi): int
{
    return (int) Database::value(
        "SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_payment_intent_id = ?", [$pi]);
}

function rwp_paid_audits(int $paymentId): int
{
    return (int) Database::value(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'restaurant.payment_paid' AND target = ?",
        [(string)$paymentId]);
}

rwp_boot();
rwp_sweep();

unit('REST-001 E8: webhook and poll settlement attempts released simultaneously settle exactly once', function (): void {
    $s = rwp_seed();
    $flag = sys_get_temp_dir() . '/rwp_flag_' . bin2hex(random_bytes(6));
    if (file_exists($flag)) unlink($flag);
    try {
        $handles = [
            rwp_probe_async('webhook', $s, $flag),
            rwp_probe_async('pollsettle', $s, $flag),
        ];
        // Both probes boot, warm their schemas, and park on the flag; the
        // release makes their settlement calls overlap for real.
        usleep(1500000);
        file_put_contents($flag, 'go');
        $results = array_map('rwp_probe_wait', $handles);
    } finally {
        if (file_exists($flag)) unlink($flag);
    }

    foreach ($results as $r) {
        assert_eq(0, $r['exit'], $r['mode'] . ' probe must exit cleanly; stderr: ' . $r['stderr']);
        assert_true($r['json'] !== [], $r['mode'] . ' probe must report a JSON outcome');
    }
    $poll = array_values(array_filter($results, fn($r) => $r['mode'] === 'pollsettle'))[0];
    assert_true(
        in_array($poll['json']['state'] ?? '', ['paid', 'claimed', 'already_paid', 'lost'], true),
        'the poll-side attempt ends in a legitimate settler state, got: ' . json_encode($poll['json']));

    $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$s['payment_id']]);
    assert_eq('paid', $pay['status'], 'the raced payment settles');
    assert_eq($s['charge'], $pay['stripe_charge_id'], 'the winning charge id is stamped');
    assert_null($pay['settlement_claim_token'], 'no claim is left behind');
    assert_eq(1, rwp_charges($s['pi']), 'exactly one converged charge-ledger row');
    assert_eq(1, rwp_paid_audits($s['payment_id']), 'exactly one payment_paid audit');
    $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$s['order_id']]);
    assert_eq('paid', $order['status'], 'the order is paid');
    assert_eq($s['expected'], (int)$order['paid_cents'], 'paid_cents equals amount + tip exactly');

    // Replay BOTH attempts (the loser retries, Stripe redelivers): nothing
    // may change — no second charge, no second audit, no double-counted order.
    $replayHook = rwp_probe_wait(rwp_probe_async('webhook', $s, null));
    $replayPoll = rwp_probe_wait(rwp_probe_async('pollsettle', $s, null));
    assert_eq(0, $replayHook['exit'], 'webhook replay probe exits cleanly');
    assert_eq(0, $replayPoll['exit'], 'poll replay probe exits cleanly');
    assert_eq('already_paid', $replayPoll['json']['state'] ?? null, 'the replayed poll attempt heals instead of re-settling');
    assert_eq(1, rwp_charges($s['pi']), 'replays must not duplicate the charge row');
    assert_eq(1, rwp_paid_audits($s['payment_id']), 'replays must not duplicate the audit');
    assert_eq($s['expected'], (int) Database::value(
        "SELECT paid_cents FROM restaurant_orders WHERE id = ?", [$s['order_id']]),
        'paid_cents is a re-sum, never an accumulation');
});

unit('REST-001 E8 cleanup: webhook/poll race suite leaves no seeded rows behind', function (): void {
    rwp_sweep();
    assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM restaurant_payments WHERE stripe_pi_id LIKE 'pi_rwp_%'"));
    assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE 'pi_rwp_%'"));
});
