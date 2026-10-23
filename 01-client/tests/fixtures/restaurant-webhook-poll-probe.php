<?php
/**
 * Probe for RestaurantWebhookPollRaceTest (REST-001, E8) — one settlement
 * attempt per process, in one of the two shapes that race in production:
 *
 *   webhook    — the REAL RestaurantAPI::handleStripeEvent() entry point
 *                with a synthetic payment_intent.succeeded event (the
 *                webhook path performs no Stripe HTTP calls of its own).
 *   pollsettle — the poll path's production settlement entry,
 *                RestaurantAPI::settlePaymentWithActuals(), fed with the
 *                actuals pollTerminalPayment() would have obtained from a
 *                captured PaymentIntent (amount_received, currency,
 *                latest_charge). pollTerminalPayment() itself cannot run
 *                here: its transport half (getPaymentIntent / capture)
 *                performs HTTPS calls to api.stripe.com and
 *                StripePaymentAPI::API_BASE is a private constant with no
 *                test seam, so exercising it would mean calling the live
 *                Stripe API. The claim → reconcile → completion machinery
 *                this probe drives is exactly what the poll invokes after
 *                capture — and the claim is where the race is decided.
 *
 * Usage:
 *   php restaurant-webhook-poll-probe.php <mode> <paymentId> <piId>
 *       <expectedCents> <currency> <chargeId> [--wait-flag <path>]
 *
 * Echoes one JSON line describing the outcome; exit code 0 on a clean run
 * (a 'claimed'/'already_paid' outcome is a clean run — losing the race is
 * a legitimate result, not a probe failure).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('SLATE_TESTING', true);

$args = $argv;
array_shift($args);
$flag = null;
$widx = array_search('--wait-flag', $args, true);
if ($widx !== false) {
    $flag = (string)($args[$widx + 1] ?? '');
    array_splice($args, $widx, 2);
}
[$mode, $paymentId, $piId, $expected, $currency, $chargeId] = array_pad($args, 6, '');
$paymentId = (int)$paymentId;
$expected  = (int)$expected;

require dirname(__DIR__, 2) . '/config.php';
require dirname(__DIR__) . '/guard.php';
slate_require_test_database();

require_once dirname(__DIR__, 2) . '/plugins/restaurant/RestaurantAPI.php';

// Schema warm-up BEFORE the flag wait, so the release puts both probes
// directly at the settlement call (the race suite does the same).
if (class_exists('StripePaymentAPI')) StripePaymentAPI::ensureChargesSchema();
if (class_exists('Notifications')) Notifications::ensureSchema();

if ($flag !== null && $flag !== '') {
    $deadline = microtime(true) + 30.0;
    while (!file_exists($flag)) {
        if (microtime(true) > $deadline) {
            echo json_encode(['mode' => $mode, 'error' => 'flag timeout']) . "\n";
            exit(2);
        }
        usleep(5000);
    }
}

if ($mode === 'webhook') {
    RestaurantAPI::handleStripeEvent([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id'              => $piId,
            'metadata'        => ['source' => 'restaurant'],
            'amount_received' => $expected,
            'currency'        => $currency,
            'latest_charge'   => $chargeId,
        ]],
    ]);
    $row = Database::row("SELECT status FROM restaurant_payments WHERE id = ?", [$paymentId]);
    echo json_encode(['mode' => 'webhook', 'observed_status' => $row['status'] ?? null]) . "\n";
    exit(0);
}

if ($mode === 'pollsettle') {
    $res = RestaurantAPI::settlePaymentWithActuals($paymentId, $expected, $currency, $chargeId);
    echo json_encode(['mode' => 'pollsettle', 'state' => $res['state'] ?? 'error']) . "\n";
    exit(0);
}

echo json_encode(['mode' => $mode, 'error' => 'unknown mode']) . "\n";
exit(2);
