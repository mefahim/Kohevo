<?php
/**
 * Restaurant settlement preflight — READ-ONLY duplicate scan (REST-001).
 *
 * Reports whether the (tenant_id, stripe_pi_id) unique index on
 * restaurant_payments can be created safely, listing every duplicate group
 * that would block it. This tool never writes: no row is deleted, merged or
 * rewritten to make the migration pass. A human decides what each duplicate
 * is (a bogus row may be marked 'failed' in the database by an operator,
 * with an audit trail — never by this script).
 *
 * Usage:  php bin/restaurant-settlement-preflight.php
 * Exit:   0 = clean, the index can be (or already is) created
 *         2 = blocked by duplicate stripe_pi_id values
 *         1 = error (table missing, database unreachable, …)
 *
 * NULL stripe_pi_id values never collide under a MySQL unique index and are
 * reported only as a count. Empty-string values DO collide ('' is a real
 * value to a unique index), so more than one '' per tenant blocks, exactly
 * like a duplicated real PaymentIntent id.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/plugins/restaurant/RestaurantAPI.php';

try {
    $exists = Database::value(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_payments'");
    if ((int) $exists === 0) {
        fwrite(STDERR, "restaurant_payments table not found — is the restaurant plugin installed?\n");
        exit(1);
    }

    $total = (int) Database::value("SELECT COUNT(*) FROM restaurant_payments");
    $nulls = (int) Database::value(
        "SELECT COUNT(*) FROM restaurant_payments WHERE stripe_pi_id IS NULL");

    $indexExists = (int) Database::value(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_payments'
            AND INDEX_NAME = 'uniq_tenant_pi'") > 0;
    $state = (string) (Database::setting('restaurant.settlement_index') ?? '');

    $scan = RestaurantAPI::settlementDuplicateScan();

    echo "Restaurant settlement preflight\n";
    echo "  payments total:            {$total}\n";
    echo "  stripe_pi_id NULL (safe):  {$nulls}\n";
    echo "  unique index present:      " . ($indexExists ? 'yes' : 'no') . "\n";
    echo "  recorded index state:      " . ($state !== '' ? $state : '(unset)') . "\n";

    foreach ($scan['groups'] as $g) {
        echo "  DUPLICATE tenant {$g['tenant_id']} pi {$g['stripe_pi_id']}: "
            . "{$g['count']} rows (ids: " . implode(', ', $g['ids']) . ")\n";
    }
    foreach ($scan['empty'] as $tenantId => $n) {
        echo "  EMPTY stripe_pi_id tenant {$tenantId}: {$n} row(s)"
            . ($n > 1 ? " — BLOCKS the unique index" : " — ok alone, would block if a second appears") . "\n";
    }

    if (!empty($scan['blocked'])) {
        echo "RESULT: BLOCKED — resolve the duplicates above manually; no data was changed.\n";
        exit(2);
    }
    echo "RESULT: CLEAN — the unique index can be created safely.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Preflight failed: ' . $e->getMessage() . "\n");
    exit(1);
}
