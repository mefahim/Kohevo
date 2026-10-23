<?php
/**
 * REST-001, E22 — fresh-install vs upgraded-install schema parity, proven
 * against real databases rather than SQL text.
 *
 * The migration suite (RestaurantMigrationTest) proves the UPGRADED path:
 * the shared test DB started life from the OLD install.sql and was migrated
 * by Restaurant::runMigrations(). This suite proves the FRESH path the same
 * way FreshInstallMigrationTest works: it creates a throwaway database,
 * loads the core schema (db/schema.sql) + the CURRENT restaurant
 * install.sql, swaps the app's Database connection onto it (reflection,
 * restored afterwards), runs the plugin's normal migration/bootstrap path,
 * and then compares the RESULTING schema — read from INFORMATION_SCHEMA —
 * against the upgraded test DB's schema.
 *
 * The comparison contract for every restaurant_* table: columns as a set
 * keyed by name (COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT — ordinal
 * position is deliberately excluded: an upgraded table gains columns by
 * ALTER in migration order while a fresh table is created in install.sql
 * order, and position carries no behaviour), plus indexes keyed by name
 * (NON_UNIQUE and the index columns IN ORDER — order inside a composite
 * index is behaviour).
 *
 * The throwaway database is dropped in a finally block. The shared test DB
 * is only READ by this suite.
 */

declare(strict_types=1);

function rfresh_pdo_for(string $dbName): \PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    $root = new \PDO($dsn, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
    $root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
    return new \PDO($dsn . ';dbname=' . $dbName, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
}

function rfresh_drop(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `$dbName`");
}

function rfresh_apply_sql(\PDO $pdo, string $file): void
{
    $sql = (string) file_get_contents($file);
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') $pdo->exec($stmt);
    }
}

/** Run $fn with the app's Database connection swapped onto $pdo (restored after). */
function rfresh_with_pdo(\PDO $pdo, callable $fn): mixed
{
    $property = new \ReflectionProperty(\Slate\Data\Database::class, 'pdo');
    $property->setAccessible(true);
    $previous = $property->getValue();
    $property->setValue(null, $pdo);
    try {
        return $fn();
    } finally {
        $property->setValue(null, $previous);
    }
}

/**
 * The restaurant schema contract of the database $pdo points at:
 * [table => ['columns' => [name => type|nullable|default], 'indexes' =>
 * [name => [non_unique, col1, col2, ...]]]], tables and keys sorted.
 */
function rfresh_contract(\PDO $pdo): array
{
    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'restaurant\\_%' ORDER BY TABLE_NAME"
    )->fetchAll(\PDO::FETCH_COLUMN);

    $contract = [];
    foreach ($tables as $table) {
        $cols = [];
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        foreach ($stmt->fetchAll() as $c) {
            $cols[$c['COLUMN_NAME']] = $c['COLUMN_TYPE'] . '|' . $c['IS_NULLABLE'] . '|' . var_export($c['COLUMN_DEFAULT'], true);
        }
        ksort($cols);

        $idx = [];
        $stmt = $pdo->prepare(
            "SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX");
        $stmt->execute([$table]);
        foreach ($stmt->fetchAll() as $s) {
            $idx[$s['INDEX_NAME']]['non_unique'] = (int)$s['NON_UNIQUE'];
            $idx[$s['INDEX_NAME']]['columns'][] = $s['COLUMN_NAME'];
        }
        ksort($idx);

        $contract[$table] = ['columns' => $cols, 'indexes' => $idx];
    }
    return $contract;
}

unit('REST-001 E22: fresh install (core schema + current install.sql + bootstrap) reaches the same schema as the upgraded install', function (): void {
    $dbName = 'slate_rfresh_' . slate_test_ns();
    $pdo = rfresh_pdo_for($dbName);
    try {
        // Fresh-install base: the core schema a new install starts from,
        // then the CURRENT restaurant install.sql, then the plugin's normal
        // migration/bootstrap path against this very database.
        rfresh_apply_sql($pdo, SLATE_ROOT . '/db/schema.sql');
        rfresh_apply_sql($pdo, dirname(__DIR__, 2) . '/plugins/restaurant/install.sql');

        $root = dirname(__DIR__, 2) . '/plugins/restaurant';
        require_once $root . '/Restaurant.php';
        $manifest = json_decode((string) file_get_contents($root . '/plugin.json'), true) ?: [];
        $plugin = new Restaurant('restaurant', $manifest, $root);

        $ready = rfresh_with_pdo($pdo, function () use ($plugin) {
            $run = new ReflectionMethod(Restaurant::class, 'runMigrations');
            $run->setAccessible(true);
            $run->invoke($plugin, '0.0.0');
            return $plugin->settlementSchemaReady();
        });
        assert_true($ready, 'settlementSchemaReady() must be true on a fresh install after bootstrap');

        $scan = rfresh_with_pdo($pdo, fn() => RestaurantAPI::settlementDuplicateScan());
        assert_false($scan['blocked'], 'a fresh install has no duplicates to block the index');

        $fresh = rfresh_contract($pdo);
    } finally {
        rfresh_drop($dbName);
    }

    // Upgraded-install contract: this suite's own test DB (old install.sql
    // + migrations — see RestaurantMigrationTest). Read via the app
    // connection, now restored.
    $upgraded = rfresh_with_pdo(Database::get(), fn() => rfresh_contract(Database::get()));

    assert_eq(array_keys($upgraded), array_keys($fresh), 'fresh and upgraded installs have the same restaurant tables');
    foreach ($upgraded as $table => $shape) {
        assert_eq($shape['columns'], $fresh[$table]['columns'],
            "$table: fresh and upgraded column contracts must match (name, type, nullability, default)");
        assert_eq($shape['indexes'], $fresh[$table]['indexes'],
            "$table: fresh and upgraded index contracts must match (name, uniqueness, column order)");
    }

    $payIdx = $fresh['restaurant_payments']['indexes']['uniq_tenant_pi'] ?? null;
    assert_true($payIdx !== null, 'the fresh install carries uniq_tenant_pi');
    assert_eq(0, $payIdx['non_unique'], 'uniq_tenant_pi is UNIQUE on a fresh install');
    assert_eq(['tenant_id', 'stripe_pi_id'], $payIdx['columns'], 'uniq_tenant_pi covers (tenant_id, stripe_pi_id) in order');
    foreach (['stripe_session_id', 'currency', 'settlement_claim_token', 'settlement_claimed_at', 'settlement_issue'] as $col) {
        assert_true(isset($fresh['restaurant_payments']['columns'][$col]), "fresh install has restaurant_payments.$col");
    }
});
