<?php
/**
 * REST-001 — settlement schema migration: parity, duplicate scan, and the
 * blocked-index cycle.
 *
 * The migration this pins is Restaurant::ensureSettlementSchema(): five
 * additive columns on restaurant_payments plus the unique key
 * uniq_tenant_pi (tenant_id, stripe_pi_id) — created ONLY when a duplicate
 * scan proves it can be. Legacy duplicates are never deleted or rewritten
 * to make the ALTER pass: the step records
 * restaurant.settlement_index = 'blocked', raises ONE admin notification,
 * and leaves every row byte-identical; a human resolves the duplicates and
 * the next pass finishes the job. (This probe-driven retry exists because
 * the version stamp cannot see it — schemaIsCurrent() only proves four
 * tables exist, so a naive migration would be stamped "done" and never
 * retried. That was the REST-001 review's blocker-grade finding.)
 *
 * This suite deliberately stages and RESTORES state on the main test DB:
 * the unique index is dropped and recreated several times, staged
 * duplicate rows live in a namespaced scratch tenant
 * (slate_test_tenant(918300)) and are removed in finally blocks, and every
 * case ends with index present + setting 'present' + no staged rows.
 *
 * Staging note: duplicate and empty-string PI pairs can only EXIST while
 * the unique index is absent (that is precisely the legacy state the
 * migration defends against), so the scan cases drop the index first and
 * restore it afterwards — mirroring an upgraded install's reality.
 */

declare(strict_types=1);

function rmt_boot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $root = dirname(__DIR__, 2) . '/plugins/restaurant';
    require_once $root . '/Restaurant.php';
    $manifest = json_decode((string) file_get_contents($root . '/plugin.json'), true) ?: [];
    $plugin = new Restaurant('restaurant', $manifest, $root);
    $GLOBALS['RMT_PLUGIN'] = $plugin;
    $run = new ReflectionMethod(Restaurant::class, 'runMigrations');
    $run->setAccessible(true);
    $run->invoke($plugin, '0.0.0');
    if (class_exists('Notifications')) Notifications::ensureSchema();
    if (class_exists('StripePaymentAPI')) StripePaymentAPI::ensureChargesSchema();
}

function rmt_plugin(): Restaurant
{
    return $GLOBALS['RMT_PLUGIN'];
}

function rmt_scratch(): int
{
    return slate_test_tenant(918300);
}

function rmt_ensure_settlement(): void
{
    $m = new ReflectionMethod(Restaurant::class, 'ensureSettlementSchema');
    $m->setAccessible(true);
    $m->invoke(rmt_plugin());
}

function rmt_index_exists(): bool
{
    return (int) Database::value(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_payments'
            AND INDEX_NAME = 'uniq_tenant_pi'") > 0;
}

function rmt_drop_index(): void
{
    if (rmt_index_exists()) {
        Database::query("ALTER TABLE `restaurant_payments` DROP INDEX `uniq_tenant_pi`");
    }
}

function rmt_setting(): ?string
{
    $v = Database::setting('restaurant.settlement_index');
    return $v === null ? null : (string)$v;
}

function rmt_blocked_notif_count(): int
{
    return (int) Database::value(
        "SELECT COUNT(*) FROM slate_notifications WHERE title = 'Restaurant payments need review'");
}

/** One scratch order per tenant, reused by every staged payment in it. */
function rmt_scratch_order(int $tid): int
{
    static $orders = [];
    if (isset($orders[$tid])) return $orders[$tid];
    $id = Database::insert('restaurant_orders', [
        'tenant_id' => $tid, 'order_no' => 'RMT-' . $tid . '-' . strtoupper(bin2hex(random_bytes(3))),
        'type' => 'takeout', 'status' => 'open', 'guest_count' => 1,
        'subtotal_cents' => 0, 'total_cents' => 0, 'paid_cents' => 0, 'source' => 'pos',
    ]);
    return $orders[$tid] = $id;
}

/** Stage one payment row in the scratch tenant. $pi may be null or ''. */
function rmt_stage(?string $pi): int
{
    $tid = rmt_scratch();
    return Database::insert('restaurant_payments', [
        'tenant_id' => $tid, 'order_id' => rmt_scratch_order($tid), 'method' => 'other',
        'amount_cents' => 1000, 'tip_cents' => 0, 'status' => 'pending',
        'stripe_pi_id' => $pi, 'currency' => 'USD',
    ]);
}

/** Remove everything staged in the scratch tenant + every pi_rmt_ row. */
function rmt_clean_staged(): void
{
    $tid = rmt_scratch();
    $pays = Database::rows(
        "SELECT id, order_id FROM restaurant_payments WHERE tenant_id = ? OR stripe_pi_id LIKE 'pi_rmt_%'", [$tid]);
    $payIds = array_map(fn($r) => (int)$r['id'], $pays);
    $orderIds = array_values(array_unique(array_map(fn($r) => (int)$r['order_id'], $pays)));
    Database::query("DELETE FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE 'pi_rmt_%'");
    if ($payIds) {
        $in = implode(',', $payIds);
        Database::query("DELETE FROM audit_log WHERE target IN ($in)");
        Database::query("DELETE FROM restaurant_payments WHERE id IN ($in)");
    }
    Database::query("DELETE FROM restaurant_payments WHERE tenant_id = ?", [$tid]);
    if ($orderIds) {
        $in = implode(',', $orderIds);
        Database::query("DELETE FROM restaurant_order_item_modifiers WHERE order_item_id IN (SELECT id FROM restaurant_order_items WHERE order_id IN ($in))");
        Database::query("DELETE FROM restaurant_order_items WHERE order_id IN ($in)");
        Database::query("DELETE FROM audit_log WHERE target IN ($in)");
        Database::query("DELETE FROM restaurant_orders WHERE id IN ($in)");
    }
}

/**
 * Run $fn with the unique index dropped (the only state in which legacy
 * duplicate/empty PI rows can exist), then ALWAYS clean the staging and
 * restore the index via the real migration step.
 */
function rmt_without_index(callable $fn): void
{
    rmt_drop_index();
    try {
        $fn();
    } finally {
        rmt_clean_staged();
        rmt_ensure_settlement();
    }
}

/** Seed a clean, settleable tenant-1 payment (for the blocked-window case). */
function rmt_seed_clean(): array
{
    $tid = current_tenant_id();
    $pi = 'pi_rmt_clean_' . bin2hex(random_bytes(6));
    $orderId = Database::insert('restaurant_orders', [
        'tenant_id' => $tid, 'order_no' => 'RMT-' . strtoupper(bin2hex(random_bytes(4))),
        'type' => 'takeout', 'status' => 'open', 'guest_count' => 1,
        'subtotal_cents' => 7000, 'total_cents' => 7000, 'paid_cents' => 0, 'source' => 'pos',
    ]);
    Database::insert('restaurant_order_items', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'check_no' => 1, 'item_id' => null,
        'name_snapshot' => 'RMT clean item', 'qty' => 1, 'unit_price_cents' => 7000,
        'modifiers_total_cents' => 0, 'line_total_cents' => 7000, 'tax_rate' => 0,
        'kitchen_status' => 'new',
    ]);
    $payId = Database::insert('restaurant_payments', [
        'tenant_id' => $tid, 'order_id' => $orderId, 'method' => 'terminal',
        'amount_cents' => 7000, 'tip_cents' => 0, 'status' => 'pending',
        'stripe_pi_id' => $pi, 'currency' => 'USD',
    ]);
    return ['order_id' => $orderId, 'payment_id' => $payId, 'pi' => $pi, 'expected' => 7000];
}

function rmt_row_hash(int $paymentId): string
{
    $row = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$paymentId]);
    return hash('sha256', json_encode($row));
}

rmt_boot();
rmt_clean_staged();
rmt_ensure_settlement();

// ── (a) Parity on the migrated test DB ──────────────────────────────────

unit('REST-001 migration (a): parity — all five settlement columns and uniq_tenant_pi (tenant_id, stripe_pi_id) exist', function (): void {
    $cols = array_map('strval', array_column(Database::rows(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_payments'
            AND COLUMN_NAME IN ('stripe_session_id','currency','settlement_claim_token',
                                'settlement_claimed_at','settlement_issue')"),
        'COLUMN_NAME'));
    sort($cols);
    assert_eq(['currency', 'settlement_claim_token', 'settlement_claimed_at', 'settlement_issue', 'stripe_session_id'],
        $cols, 'all five settlement columns must be present');

    $idx = Database::rows(
        "SELECT COLUMN_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_payments'
            AND INDEX_NAME = 'uniq_tenant_pi' ORDER BY SEQ_IN_INDEX");
    assert_eq(['tenant_id', 'stripe_pi_id'], array_map('strval', array_column($idx, 'COLUMN_NAME')),
        'the unique index covers (tenant_id, stripe_pi_id) in that order');
    assert_eq(0, (int)$idx[0]['NON_UNIQUE'], 'the index is UNIQUE');
    assert_true(rmt_plugin()->settlementSchemaReady(), 'the plugin probe agrees the schema is ready');
    assert_eq('present', rmt_setting());
});

// ── (b) Duplicate-scan unit cases ───────────────────────────────────────

unit('REST-001 scan (b1): two rows sharing one PI in a tenant block, with the group listed', function (): void {
    rmt_without_index(function (): void {
        $pi = 'pi_rmt_dup_' . bin2hex(random_bytes(6));
        $a = rmt_stage($pi);
        $b = rmt_stage($pi);
        $scan = RestaurantAPI::settlementDuplicateScan();
        assert_true($scan['blocked'], 'a duplicated (tenant, PI) pair must block');
        $group = null;
        foreach ($scan['groups'] as $g) {
            if ($g['tenant_id'] === rmt_scratch() && $g['stripe_pi_id'] === $pi) $group = $g;
        }
        assert_true($group !== null, 'the offending group is reported with tenant + PI');
        assert_eq(2, $group['count']);
        $ids = $group['ids'];
        sort($ids);
        $want = [$a, $b];
        sort($want);
        assert_eq($want, $ids, 'the group lists both row ids for the human resolver');
    });
    assert_true(rmt_index_exists(), 'index restored after the staged scan case');
});

unit('REST-001 scan (b2): many NULL stripe_pi_id rows never block (cash rows are exempt)', function (): void {
    rmt_without_index(function (): void {
        rmt_stage(null);
        rmt_stage(null);
        rmt_stage(null);
        $scan = RestaurantAPI::settlementDuplicateScan();
        assert_false($scan['blocked'], 'NULLs cannot collide under a MySQL unique index');
        assert_eq([], $scan['groups'], 'NULL rows form no duplicate group');
    });
    assert_true(rmt_index_exists(), 'index restored after the staged scan case');
});

unit('REST-001 scan (b3): a single empty-string stripe_pi_id does not block but is counted', function (): void {
    rmt_without_index(function (): void {
        rmt_stage('');
        $scan = RestaurantAPI::settlementDuplicateScan();
        assert_false($scan['blocked'], 'one empty string is a warning, not a block');
        assert_eq(1, $scan['empty'][rmt_scratch()] ?? 0, 'the empty row is reported per tenant');
    });
    assert_true(rmt_index_exists(), 'index restored after the staged scan case');
});

unit('REST-001 scan (b4): two empty-string stripe_pi_id rows in one tenant DO block (empty string is a real value)', function (): void {
    rmt_without_index(function (): void {
        rmt_stage('');
        rmt_stage('');
        $scan = RestaurantAPI::settlementDuplicateScan();
        assert_true($scan['blocked'], "two '' values collide exactly like a duplicated PI");
        assert_eq(2, $scan['empty'][rmt_scratch()] ?? 0);
    });
    assert_true(rmt_index_exists(), 'index restored after the staged scan case');
});

// ── (c) Blocked migration cycle ─────────────────────────────────────────

unit('REST-001 migration (c): duplicates block the index without touching a byte; human resolution unblocks it', function (): void {
    $pi = 'pi_rmt_block_' . bin2hex(random_bytes(6));
    $first = 0; $second = 0;
    rmt_drop_index();
    try {
        $first = rmt_stage($pi);
        $second = rmt_stage($pi);
        $hashBefore = [rmt_row_hash($first), rmt_row_hash($second)];
        $notifBefore = rmt_blocked_notif_count();

        rmt_ensure_settlement();

        assert_eq('blocked', rmt_setting(), "the blocked state is persisted in the restaurant.settlement_index setting");
        assert_false(rmt_index_exists(), 'the unique index must NOT be created over duplicates');
        assert_eq($hashBefore, [rmt_row_hash($first), rmt_row_hash($second)],
            'both financial rows are byte-identical after the blocked pass — nothing deleted, nothing rewritten');
        assert_eq($notifBefore + 1, rmt_blocked_notif_count(),
            'exactly one admin notification announces the blocked state');

        // A second pass while still blocked stays silent (state-gated alert).
        rmt_ensure_settlement();
        assert_eq($notifBefore + 1, rmt_blocked_notif_count(), 'no repeat notification while the state is unchanged');
        assert_eq('blocked', rmt_setting());

        // Human resolution, simulated: an operator retires ONE of the pair
        // (here: removes the staged row). The migration itself never does this.
        Database::query("DELETE FROM restaurant_payments WHERE id = ?", [$second]);
        rmt_ensure_settlement();

        assert_true(rmt_index_exists(), 'once duplicates are resolved the next pass creates the index');
        assert_eq('present', rmt_setting());
        assert_eq($hashBefore[0], rmt_row_hash($first), 'the surviving row is still byte-identical');
    } finally {
        rmt_clean_staged();
        rmt_ensure_settlement();
    }
    assert_true(rmt_index_exists(), 'final state restored: index present');
    assert_eq('present', rmt_setting(), "final state restored: setting 'present'");
});

// ── (d) Settlement keeps working while the index is blocked ─────────────

unit('REST-001 migration (d): the settler works normally during a blocked window (the claim needs no index)', function (): void {
    $pi = 'pi_rmt_window_' . bin2hex(random_bytes(6));
    rmt_drop_index();
    try {
        rmt_stage($pi);
        rmt_stage($pi);
        rmt_ensure_settlement();
        assert_eq('blocked', rmt_setting(), 'setup: the window is blocked');
        assert_false(rmt_index_exists(), 'setup: no unique index');

        $clean = rmt_seed_clean();
        $res = RestaurantAPI::settlePaymentWithActuals($clean['payment_id'], $clean['expected'], 'usd');
        assert_eq('paid', $res['state'] ?? null, 'an unrelated clean payment settles fine while blocked');
        $pay = Database::row("SELECT status FROM restaurant_payments WHERE id = ?", [$clean['payment_id']]);
        assert_eq('paid', $pay['status']);
    } finally {
        rmt_clean_staged();
        rmt_ensure_settlement();
    }
    assert_true(rmt_index_exists(), 'final state restored: index present');
    assert_eq('present', rmt_setting(), "final state restored: setting 'present'");
    assert_eq(0, (int) Database::value(
        "SELECT COUNT(*) FROM restaurant_payments WHERE tenant_id = ?", [rmt_scratch()]),
        'final state restored: no staged rows left');
    assert_eq(0, (int) Database::value(
        "SELECT COUNT(*) FROM restaurant_payments WHERE stripe_pi_id LIKE 'pi_rmt_%'"),
        'final state restored: no rmt rows left anywhere');
});
