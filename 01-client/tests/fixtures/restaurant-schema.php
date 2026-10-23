<?php
/**
 * Bring the test database's restaurant schema up to the shape a real install has.
 *
 * WHY THIS EXISTS
 *
 * There are three different "restaurant schemas", and only the third one is
 * what the running code actually writes to:
 *
 *   1. plugins/restaurant/install.sql as originally shipped — restaurant_
 *      payments has neither stripe_session_id nor any of the REST-001
 *      settlement columns, and its stripe_pi_id key is non-unique.
 *   2. install.sql + RestaurantAPI::ensureSchema() — the same file replayed
 *      defensively at runtime; CREATE TABLE IF NOT EXISTS cannot add columns
 *      to a table that already exists, so this changes nothing on an
 *      upgraded install.
 *   3. install.sql + Restaurant::runMigrations() — the real thing. The
 *      missing columns (stripe_session_id, currency, settlement_claim_token,
 *      settlement_claimed_at, settlement_issue) and the unique key
 *      uniq_tenant_pi (tenant_id, stripe_pi_id) exist ONLY as ensureColumn() /
 *      ensureSettlementSchema() calls in Restaurant.php, so no amount of
 *      loading SQL produces them. A fresh activation runs install.sql and
 *      then this migration pass, which is why production works and a
 *      SQL-only test database does not.
 *
 * The test database was provisioned with the OLD (pre-REST-001) schema, so
 * any test that calls RestaurantAPI::settlePaymentWithActuals() would die on
 * "Unknown column 'settlement_claim_token'" without this step. This script
 * closes that gap by running the plugin's own migration, so the column list
 * lives in exactly one place and cannot drift from the code that writes to
 * it.
 *
 * WHY REFLECTION, AND WHY NOT boot()
 *
 * runMigrations() is private, and its only caller is Restaurant::boot().
 * Calling boot() would work but would also register restaurant's filters and
 * actions for the rest of the process — admin_nav_items,
 * admin_dashboard_widgets, public_routes, stripe_webhook_event — which
 * changes what every other suite in the run sees. runMigrations() itself
 * touches no hooks, so invoking it directly provisions the schema and
 * changes nothing else. (The settlement-schema retry that boot() performs
 * when settlementSchemaReady() is false is part of runMigrations() here,
 * because runMigrations() itself calls ensureSettlementSchema().)
 *
 * Every statement is idempotent — CREATE TABLE IF NOT EXISTS, and
 * ensureColumn/ensureIndex both probe INFORMATION_SCHEMA first — so
 * re-running is a no-op. One deliberate exception to "no-op": if legacy
 * duplicate stripe_pi_id values block the unique index, the migration
 * records restaurant.settlement_index = 'blocked' and raises one admin
 * notification instead of touching the rows; this script then exits
 * non-zero below, because the schema genuinely is not in its final shape.
 *
 * Run after loading plugins/restaurant/install.sql. Exits non-zero if the
 * schema is still incomplete afterwards, so CI fails here with a clear
 * message rather than inside an unrelated test.
 */

declare(strict_types=1);

require __DIR__ . '/../../config.php';
require __DIR__ . '/../guard.php';
slate_require_test_database();

require_once dirname(__DIR__, 2) . '/plugins/restaurant/Restaurant.php';

$root     = dirname(__DIR__, 2) . '/plugins/restaurant';
$manifest = json_decode((string) file_get_contents($root . '/plugin.json'), true) ?: [];
$plugin   = new Restaurant('restaurant', $manifest, $root);

// '0.0.0' replays every version gate from the beginning, which is what a fresh
// activation does and what makes the result complete rather than partial.
$run = new ReflectionMethod(Restaurant::class, 'runMigrations');
$run->setAccessible(true);
$run->invoke($plugin, '0.0.0');

// Verify the REST-001 settlement shape explicitly (rather than via
// schemaIsCurrent(), which only proves four tables exist and cannot see
// any of this — that blindness is exactly what REST-001 had to work around).
$expectedColumns = ['stripe_session_id', 'currency', 'settlement_claim_token',
                    'settlement_claimed_at', 'settlement_issue'];
$present = array_map('strval', array_column(Database::rows(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_payments'
        AND COLUMN_NAME IN ('stripe_session_id','currency','settlement_claim_token',
                            'settlement_claimed_at','settlement_issue')"),
    'COLUMN_NAME'));
$missing = array_values(array_diff($expectedColumns, $present));
if ($missing !== []) {
    fwrite(STDERR, 'restaurant schema is still incomplete after runMigrations(): missing columns '
        . implode(', ', $missing) . " on restaurant_payments\n");
    exit(1);
}

$indexCols = array_map('strval', array_column(Database::rows(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_payments'
        AND INDEX_NAME = 'uniq_tenant_pi' ORDER BY SEQ_IN_INDEX"),
    'COLUMN_NAME'));
if ($indexCols !== ['tenant_id', 'stripe_pi_id']) {
    fwrite(STDERR, "restaurant schema is still incomplete after runMigrations(): unique index "
        . "uniq_tenant_pi (tenant_id, stripe_pi_id) is missing — legacy duplicate stripe_pi_id "
        . "values may be blocking it (see bin/restaurant-settlement-preflight.php)\n");
    exit(1);
}

// The plugin's own probe must agree with the explicit checks above.
if (!$plugin->settlementSchemaReady()) {
    fwrite(STDERR, "restaurant schema probe (settlementSchemaReady) disagrees: reports not ready\n");
    exit(1);
}

echo "restaurant schema migrated to " . ($manifest['version'] ?? '?')
    . " (settlement columns + uniq_tenant_pi verified)\n";
