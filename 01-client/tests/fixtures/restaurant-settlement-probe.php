<?php
/**
 * Settles ONE restaurant payment in its own OS process, so
 * RestaurantSettlementRaceTest can race genuinely concurrent settlers
 * against the same payment row — the REST-001 equivalent of
 * stripe-claim-probe.php.
 *
 * The settler under test is RestaurantAPI::settlePaymentWithActuals(): the
 * claim is a single conditional UPDATE on restaurant_payments
 * (settlement_claim_token / settlement_claimed_at, 120-second lease), so
 * exactly one concurrent process may reach the completion UPDATE, the
 * charge-ledger insert and the payment_paid audit. This probe does no
 * claiming of its own; it calls the real API exactly as the webhook, the
 * POS poll and the storefront return page do, and reports the state the
 * API returned.
 *
 * Usage:
 *   php restaurant-settlement-probe.php <paymentId> <actualCents|null> <currency> [--wait-flag <path>]
 *
 * --wait-flag makes the probe pause (after booting, before settling) until
 * the flag file exists, so the race test can launch several probes first
 * and release them all at the same instant.
 *
 * Prints exactly one line of JSON: {"payment_id":N,"state":"paid|..."}.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__, 2) . '/config.php';
require dirname(__DIR__) . '/guard.php';
slate_require_test_database();

require_once dirname(__DIR__, 2) . '/plugins/restaurant/Restaurant.php';

$args = array_slice($argv, 1);
$flag = null;
$pos  = [];
foreach ($args as $i => $a) {
    if ($a === '--wait-flag') { $flag = $args[$i + 1] ?? null; continue; }
    if ($i > 0 && ($args[$i - 1] ?? '') === '--wait-flag') continue;
    $pos[] = $a;
}

$paymentId    = (int)($pos[0] ?? 0);
$actualCents  = (($pos[1] ?? 'null') === 'null') ? null : (int)$pos[1];
$currency     = (string)($pos[2] ?? '');

if ($paymentId <= 0) {
    fwrite(STDERR, "usage: restaurant-settlement-probe.php <paymentId> <actualCents|null> <currency> [--wait-flag <path>]\n");
    exit(2);
}

if ($flag !== null) {
    $deadline = time() + 30;
    while (!file_exists($flag) && time() < $deadline) {
        usleep(10000);
    }
}

$res = RestaurantAPI::settlePaymentWithActuals($paymentId, $actualCents, $currency);
echo json_encode(['payment_id' => $paymentId, 'state' => (string)($res['state'] ?? 'error')]) . "\n";
