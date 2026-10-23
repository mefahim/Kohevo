<?php
/**
 * Restaurant — core API.
 *
 * Static methods so the admin pages, the POS, and other plugins can share
 * one source of truth for menu reads, the order/check engine, money
 * formatting, and the Stripe Terminal bridge. All money is integer cents.
 *
 *   RestaurantAPI::ensureSchema()
 *   RestaurantAPI::money($cents)            // "$12.50"
 *   RestaurantAPI::slugify($name, $table, $excludeId)
 *   RestaurantAPI::recalcOrder($orderId)    // recompute line + order totals
 *   RestaurantAPI::getOrder($orderId)       // order + items + modifiers + payments
 *   RestaurantAPI::handleStripeEvent($event)
 *
 * Tax model: per-line tax_rate (%) snapshotted from the menu item; tax is
 * computed on each line's net total. Service charge is an order-level
 * percentage of the discounted subtotal. Tip is added on tender.
 */

class RestaurantAPI {

    private static bool $schemaChecked = false;

    // ── Schema ────────────────────────────────────────────────

    /**
     * Lazily create the restaurant_* tables. Defensive against installs
     * where install.sql didn't run or partially failed. Idempotent.
     */
    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            $sqlFile = __DIR__ . '/install.sql';
            if (!is_file($sqlFile)) return;
            $sql = (string) file_get_contents($sqlFile);
            $sql = preg_replace('/^--.*$/m', '', $sql);
            $pdo = Database::get();
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt === '') continue;
                try { $pdo->exec($stmt); } catch (\Throwable $e) {
                    slate_log('Restaurant ensureSchema stmt failed: ' . $e->getMessage(), 'warning');
                }
            }
        } catch (\Throwable $e) {
            slate_log('Restaurant ensureSchema failed: ' . $e->getMessage(), 'error');
        }
    }

    // ── Settings ──────────────────────────────────────────────

    public static function setting(string $key, $default = null) {
        $val = Database::setting('restaurant.' . $key);
        return $val !== null ? $val : $default;
    }

    /**
     * The business / receipt / Terminal-Location settings block, as an array.
     * Keys mirror what admin/settings.php persists; used by the Card-readers
     * page to register a Stripe Terminal Location.
     */
    public static function settings(): array {
        return [
            'biz_name'     => (string) self::setting('biz_name', ''),
            'addr_line1'   => (string) self::setting('addr_line1', ''),
            'addr_line2'   => (string) self::setting('addr_line2', ''),
            'addr_city'    => (string) self::setting('addr_city', ''),
            'addr_state'   => (string) self::setting('addr_state', ''),
            'addr_postal'  => (string) self::setting('addr_postal', ''),
            'addr_country' => (string) self::setting('addr_country', 'US'),
        ];
    }

    public static function currency(): string {
        return strtoupper((string) self::setting('currency', 'USD'));
    }

    /** Format integer cents as a localized money string. */
    public static function money(int $cents): string {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$',
                    'AUD' => 'A$', 'BDT' => '৳', 'INR' => '₹', 'JPY' => '¥'];
        $cur = self::currency();
        $sym = $symbols[$cur] ?? ($cur . ' ');
        $neg = $cents < 0;
        $amt = number_format(abs($cents) / 100, 2);
        return ($neg ? '-' : '') . $sym . $amt;
    }

    public static function defaultTaxRate(): float {
        return (float) self::setting('default_tax_rate', '0');
    }

    public static function serviceChargePct(): float {
        return (float) self::setting('service_charge_pct', '0');
    }

    public static function serviceChargeAuto(): bool {
        return (string) self::setting('service_charge_auto', '0') === '1';
    }

    /** Tip preset percentages, e.g. [15, 18, 20]. */
    public static function tipPresets(): array {
        $raw = (string) self::setting('tip_presets', '15,18,20');
        $out = [];
        foreach (explode(',', $raw) as $p) {
            $n = (int) trim($p);
            if ($n > 0) $out[$n] = true;
        }
        return array_keys($out);
    }

    // ── Helpers ───────────────────────────────────────────────

    /** Unique slug within (tenant_id, slug) for the given table. */
    public static function slugify(string $name, string $table, ?int $excludeId = null): string {
        $tid  = current_tenant_id();
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'item';
        $base = substr($base, 0, 60);
        $slug = $base; $i = 2;
        while (true) {
            $clash = Database::row(
                "SELECT id FROM `{$table}` WHERE tenant_id = ? AND slug = ?" . ($excludeId ? " AND id <> ?" : ""),
                $excludeId ? [$tid, $slug, $excludeId] : [$tid, $slug]
            );
            if (!$clash) return $slug;
            $slug = $base . '-' . $i++;
            if ($i > 999) return $base . '-' . bin2hex(random_bytes(3));
        }
    }

    /** Sequential-ish, human order number unique per tenant (e.g. "A4F2-013"). */
    public static function generateOrderNo(int $tid): string {
        $count = (int) Database::value(
            "SELECT COUNT(*) FROM restaurant_orders WHERE tenant_id = ? AND DATE(opened_at) = CURDATE()",
            [$tid]
        );
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $no = date('md') . '-' . str_pad((string)($count + 1 + $attempt), 3, '0', STR_PAD_LEFT);
            $exists = Database::row(
                "SELECT id FROM restaurant_orders WHERE tenant_id = ? AND order_no = ?", [$tid, $no]);
            if (!$exists) return $no;
        }
        return date('md') . '-' . bin2hex(random_bytes(2));
    }

    // ── Order engine ──────────────────────────────────────────

    /**
     * Recompute one line item's modifier total + line total from its stored
     * modifier rows, then roll up the whole order's subtotal/tax/service/total.
     * Void lines and voided orders contribute nothing.
     */
    public static function recalcOrder(int $orderId): void {
        $tid   = current_tenant_id();
        $order = Database::row(
            "SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;

        $items = Database::rows(
            "SELECT * FROM restaurant_order_items WHERE order_id = ? AND tenant_id = ?",
            [$orderId, $tid]);

        $subtotal = 0;
        $taxTotal = 0;
        foreach ($items as $it) {
            $modTotal = (int) Database::value(
                "SELECT COALESCE(SUM(price_delta_cents),0) FROM restaurant_order_item_modifiers
                  WHERE order_item_id = ?", [(int)$it['id']]);
            $qty       = max(1, (int)$it['qty']);
            $lineTotal = ((int)$it['unit_price_cents'] + $modTotal) * $qty;

            Database::update('restaurant_order_items', [
                'modifiers_total_cents' => $modTotal,
                'line_total_cents'      => $lineTotal,
            ], 'id = ?', [(int)$it['id']]);

            if ($it['kitchen_status'] === 'void') continue;
            $subtotal += $lineTotal;
            $taxTotal += (int) round($lineTotal * ((float)$it['tax_rate'] / 100));
        }

        // Service charge is a percentage of the discounted subtotal. The
        // percent is snapshotted onto the order at open time so later recalcs
        // stay stable even if the global setting changes mid-service.
        $discount = min((int)$order['discount_cents'], $subtotal);
        $svcBase  = max(0, $subtotal - $discount);
        $pct      = (float)($order['service_charge_pct'] ?? 0);
        $service  = $pct > 0 ? (int) round($svcBase * $pct / 100) : 0;
        $tip      = (int)$order['tip_cents'];
        $total    = $subtotal - $discount + $service + $taxTotal + $tip;

        Database::update('restaurant_orders', [
            'subtotal_cents'       => $subtotal,
            'discount_cents'       => $discount,
            'service_charge_cents' => $service,
            'tax_cents'            => $taxTotal,
            'total_cents'          => max(0, $total),
        ], 'id = ? AND tenant_id = ?', [$orderId, $tid]);
    }

    /** Full order with items (+ their modifiers) and payments. Null if missing. */
    public static function getOrder(int $orderId): ?array {
        $tid   = current_tenant_id();
        $order = Database::row(
            "SELECT o.*, t.label AS table_label, c.name AS customer_name, c.phone AS customer_phone
               FROM restaurant_orders o
               LEFT JOIN restaurant_tables t    ON t.id = o.table_id
               LEFT JOIN restaurant_customers c ON c.id = o.customer_id
              WHERE o.id = ? AND o.tenant_id = ?", [$orderId, $tid]);
        if (!$order) return null;

        $items = Database::rows(
            "SELECT * FROM restaurant_order_items
              WHERE order_id = ? AND tenant_id = ? ORDER BY id", [$orderId, $tid]);
        foreach ($items as &$it) {
            $it['modifiers'] = Database::rows(
                "SELECT * FROM restaurant_order_item_modifiers WHERE order_item_id = ? ORDER BY id",
                [(int)$it['id']]);
        }
        unset($it);
        $order['items'] = $items;
        $order['payments'] = Database::rows(
            "SELECT * FROM restaurant_payments WHERE order_id = ? AND tenant_id = ? ORDER BY id",
            [$orderId, $tid]);
        return $order;
    }

    public static function getOpenOrders(): array {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT o.*, t.label AS table_label
               FROM restaurant_orders o
               LEFT JOIN restaurant_tables t ON t.id = o.table_id
              WHERE o.tenant_id = ? AND o.status IN ('open','sent')
           ORDER BY o.opened_at", [$tid]);
    }

    /** Active menu for the POS: categories with their available items. */
    public static function getPosMenu(): array {
        $tid = current_tenant_id();
        $cats = Database::rows(
            "SELECT * FROM restaurant_menu_categories
              WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
        $items = Database::rows(
            "SELECT * FROM restaurant_items
              WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
        return ['categories' => $cats, 'items' => $items];
    }

    /** Modifier groups attached to an item, each with its options. */
    public static function getItemModifierGroups(int $itemId): array {
        $tid    = current_tenant_id();
        $groups = Database::rows(
            "SELECT g.* FROM restaurant_modifier_groups g
               JOIN restaurant_item_modifier_groups l ON l.group_id = g.id
              WHERE l.item_id = ? AND g.tenant_id = ? ORDER BY l.sort_order, g.name",
            [$itemId, $tid]);
        foreach ($groups as &$g) {
            $g['modifiers'] = Database::rows(
                "SELECT * FROM restaurant_modifiers
                  WHERE group_id = ? AND is_active = 1 ORDER BY sort_order, name", [(int)$g['id']]);
        }
        unset($g);
        return $groups;
    }

    // ── Stripe Terminal bridge ────────────────────────────────

    public static function terminalAvailable(): bool {
        return class_exists('StripeTerminalAPI')
            && class_exists('StripePaymentAPI')
            && StripePaymentAPI::isConfigured();
    }

    /** Registered, active card readers for this tenant (for the POS picker). */
    public static function readers(): array {
        return Database::rows(
            "SELECT * FROM restaurant_readers WHERE tenant_id = ? AND is_active = 1 ORDER BY label",
            [current_tenant_id()]);
    }

    // ── Settlement (REST-001) ────────────────────────────────
    //
    // One settler for every Stripe path (Terminal poll, webhook, hosted
    // Checkout return, Payment Element confirm):
    //
    //  * Stripe's ACTUAL amount/currency must reconcile exactly against the
    //    payment row (amount_cents + tip_cents; the currency stamped on the
    //    row at creation) before anything is marked paid. A mismatch parks
    //    the payment with settlement_issue set, one audit row and one admin
    //    notification — the money's resolution is a human decision
    //    (REST-002), never an automatic settle.
    //  * A lease-style claim (token + timestamp columns; the status ENUM is
    //    deliberately untouched) makes concurrent settlers mutually
    //    exclusive. Every financial side effect is gated by a conditional
    //    UPDATE, is idempotent, or fires exactly on a state transition —
    //    never by a PHP check-then-act. A claim whose owner died expires
    //    after the lease, so the next natural attempt finishes the job with
    //    no manual database surgery and no cron dependency.
    //  * The payment row's tenant_id is the source of truth: the whole
    //    settlement runs inside with_tenant(payment.tenant_id), so the
    //    charge row, order sync, audit and notification land in the
    //    payment's tenant even when the caller (a webhook) runs pinned to a
    //    different tenant.
    private const SETTLEMENT_LEASE_SECONDS = 120;

    /**
     * Duplicate stripe_pi_id scan, shared by the boot migration, the schema
     * probe and bin/restaurant-settlement-preflight.php. NULLs never collide
     * under a MySQL unique index; empty strings DO, so more than one '' per
     * tenant blocks exactly like a duplicated real PaymentIntent id.
     * Read-only — this scan never modifies a financial row.
     */
    public static function settlementDuplicateScan(): array {
        $groups = [];
        foreach (Database::rows(
            "SELECT tenant_id, stripe_pi_id, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
               FROM restaurant_payments
              WHERE stripe_pi_id IS NOT NULL AND stripe_pi_id <> ''
              GROUP BY tenant_id, stripe_pi_id HAVING n > 1") as $r) {
            $groups[] = [
                'tenant_id'    => (int)$r['tenant_id'],
                'stripe_pi_id' => (string)$r['stripe_pi_id'],
                'count'        => (int)$r['n'],
                'ids'          => array_map('intval', explode(',', (string)$r['ids'])),
            ];
        }
        $empty = [];
        foreach (Database::rows(
            "SELECT tenant_id, COUNT(*) AS n FROM restaurant_payments
              WHERE stripe_pi_id = '' GROUP BY tenant_id") as $r) {
            $empty[(int)$r['tenant_id']] = (int)$r['n'];
        }
        $blocked = $groups !== [];
        foreach ($empty as $n) { if ($n > 1) { $blocked = true; break; } }
        return ['blocked' => $blocked, 'groups' => $groups, 'empty' => $empty];
    }

    /**
     * Authoritative reconciliation: expected is ALWAYS the payment row
     * (amount_cents + tip_cents — the PaymentIntent was created for exactly
     * that combined figure, so the tip needs no separate handling), never
     * the order total, which a split tender would corrupt. Currency is
     * checked first; an amount or currency Stripe did not report is
     * unverifiable and never settles (unlike Shop's reconcile, which skips
     * an empty currency — a restaurant payment must not settle blind).
     */
    public static function reconcileStripeAmount(array $payment, ?int $actualCents, string $actualCurrency): array {
        $expectedCents = (int)$payment['amount_cents'] + (int)$payment['tip_cents'];
        $expectedCur   = strtoupper(trim((string)($payment['currency'] ?? '')));
        if ($expectedCur === '') $expectedCur = self::currency();
        $actualCur = strtoupper(trim($actualCurrency));
        $base = ['expected_cents' => $expectedCents, 'expected_currency' => $expectedCur,
                 'actual_cents' => $actualCents, 'actual_currency' => $actualCur];
        if ($actualCur === '')               return $base + ['ok' => false, 'issue' => 'currency_unverifiable'];
        if ($actualCur !== $expectedCur)     return $base + ['ok' => false, 'issue' => 'currency_mismatch'];
        if ($actualCents === null)           return $base + ['ok' => false, 'issue' => 'amount_unverifiable'];
        if ($actualCents !== $expectedCents) return $base + ['ok' => false, 'issue' => 'amount_mismatch'];
        return $base + ['ok' => true, 'issue' => null];
    }

    /** Guarded transaction idiom (mirrors InstallationService/ShopAPI). */
    private static function withTransaction(callable $fn) {
        $pdo = Database::get();
        $started = false;
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }
        try {
            $result = $fn();
            if ($started) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Take the settlement lease on a pending payment. Exactly one concurrent
     * caller wins (conditional UPDATE + rowCount); a claim older than the
     * lease is treated as abandoned and may be taken over.
     */
    private static function acquireSettlementClaim(array $payment): ?string {
        if (($payment['status'] ?? '') !== 'pending') return null;
        $token = bin2hex(random_bytes(16));
        $lease = self::SETTLEMENT_LEASE_SECONDS;
        $n = Database::query(
            "UPDATE restaurant_payments
                SET settlement_claim_token = ?, settlement_claimed_at = NOW()
              WHERE id = ? AND status = 'pending'
                AND (settlement_claim_token IS NULL
                     OR settlement_claimed_at IS NULL
                     OR settlement_claimed_at < DATE_SUB(NOW(), INTERVAL {$lease} SECOND))",
            [$token, (int)$payment['id']])->rowCount();
        return $n === 1 ? $token : null;
    }

    /** Ownership-checked claim release; the lease expiry is the backstop. */
    private static function releaseSettlementClaim(int $paymentId, string $token): void {
        try {
            Database::update('restaurant_payments',
                ['settlement_claim_token' => null, 'settlement_claimed_at' => null],
                'id = ? AND settlement_claim_token = ?', [$paymentId, $token]);
        } catch (\Throwable $e) { /* a stuck claim expires on its own */ }
    }

    /** Central-charge payload for a payment row — one shape for settle + heal. */
    private static function chargePayload(array $payment, int $amountCents, string $currency): array {
        return [
            'source_plugin'            => 'restaurant',
            'source_id'                => (string)$payment['order_id'],
            'stripe_payment_intent_id' => (string)$payment['stripe_pi_id'],
            'stripe_session_id'        => !empty($payment['stripe_session_id']) ? (string)$payment['stripe_session_id'] : null,
            'amount_cents'             => $amountCents,
            'currency'                 => $currency,
            'status'                   => 'succeeded',
            'meta'                     => ['method' => ($payment['method'] ?? '') === 'terminal' ? 'terminal' : 'online',
                                           'order_id' => (int)$payment['order_id']],
        ];
    }

    /**
     * Healing for an already-paid payment (webhook replay, a settler that
     * died after the paid UPDATE but before finishing, a retry after a
     * partial failure): converge the charge ledger and the order WITHOUT a
     * second payment_paid audit. Idempotent — recordCharge converges on the
     * charges table's unique keys and syncOrderPayment re-sums.
     * Caller must already run in the payment's tenant.
     */
    private static function healPaidPayment(array $payment): void {
        if (class_exists('StripePaymentAPI') && !empty($payment['stripe_pi_id'])) {
            $cur = strtoupper(trim((string)($payment['currency'] ?? '')));
            StripePaymentAPI::recordCharge(self::chargePayload(
                $payment,
                (int)$payment['amount_cents'] + (int)$payment['tip_cents'],
                $cur !== '' ? $cur : self::currency()));
        }
        self::syncOrderPayment((int)$payment['order_id']);
    }

    /** Park a payment on a reconciliation mismatch — transition-gated side effects. */
    private static function markSettlementMismatch(array $payment, string $token, array $rec): void {
        // The alert + audit fire ONLY on the NULL → issue transition, so a
        // redelivered event (or a POS retry) against an already-flagged
        // payment stays silent; a DIFFERENT issue later still re-alerts,
        // because the predicate compares against the recorded value.
        $n = Database::update('restaurant_payments', [
            'settlement_issue'       => $rec['issue'],
            'settlement_claim_token' => null,
            'settlement_claimed_at'  => null,
        ], 'id = ? AND settlement_claim_token = ? AND (settlement_issue IS NULL OR settlement_issue <> ?)',
           [(int)$payment['id'], $token, $rec['issue']]);
        if ($n !== 1) {
            // Already flagged with this issue (or the claim moved on): just
            // make sure our claim is released, without a second alert.
            self::releaseSettlementClaim((int)$payment['id'], $token);
            return;
        }
        slate_log('Restaurant settlement ' . $rec['issue'] . ' on payment ' . (int)$payment['id']
            . ': expected ' . $rec['expected_cents'] . ' ' . $rec['expected_currency']
            . ', Stripe reported ' . var_export($rec['actual_cents'], true)
            . ' ' . $rec['actual_currency'], 'error');
        AuditLog::record('restaurant.settlement_mismatch', (string)$payment['id'], [
            'issue'             => $rec['issue'],
            'expected_cents'    => $rec['expected_cents'],
            'expected_currency' => $rec['expected_currency'],
            'actual_cents'      => $rec['actual_cents'],
            'actual_currency'   => $rec['actual_currency'],
            'order_id'          => (int)$payment['order_id'],
        ]);
        if (class_exists('Notifications')) {
            Notifications::add('Restaurant payment needs review', [
                'body' => 'A card payment did not settle: Stripe reported a different amount or '
                        . 'currency than the check total. The order was NOT marked paid.',
                'icon' => 'alert-triangle',
            ]);
        }
    }

    /**
     * The settlement itself, run by the claim owner. Order of operations is
     * load-bearing: reconcile BEFORE any side effect; recordCharge OUTSIDE
     * the local transaction (its lazy schema step can run DDL, which would
     * implicitly commit); the paid UPDATE + order sync inside ONE guarded
     * transaction, gated on still owning the claim; the audit AFTER commit,
     * exactly once, because only the claim owner can reach it.
     */
    private static function completeClaimedSettlement(array $payment, string $token, ?int $actualCents, string $actualCurrency, ?string $chargeId): array {
        return with_tenant((int)$payment['tenant_id'], function () use ($payment, $token, $actualCents, $actualCurrency, $chargeId) {
            $paymentId = (int)$payment['id'];
            $fresh = Database::row(
                "SELECT * FROM restaurant_payments WHERE id = ? AND tenant_id = ?",
                [$paymentId, (int)$payment['tenant_id']]);
            if (!$fresh) return ['state' => 'lost'];
            if ($fresh['status'] === 'paid') { self::healPaidPayment($fresh); return ['state' => 'already_paid']; }
            if ($fresh['status'] !== 'pending') return ['state' => 'not_pending'];

            $rec = self::reconcileStripeAmount($fresh, $actualCents, $actualCurrency);
            if (!$rec['ok']) {
                self::markSettlementMismatch($fresh, $token, $rec);
                return ['state' => 'mismatch', 'issue' => $rec['issue']];
            }

            $chargeRowId = null;
            if (class_exists('StripePaymentAPI') && !empty($fresh['stripe_pi_id'])) {
                $chargeRowId = StripePaymentAPI::recordCharge(
                    self::chargePayload($fresh, $rec['expected_cents'], $rec['expected_currency']));
            }

            $charge = $chargeId ?: ($fresh['stripe_charge_id'] ?: null);
            $completed = self::withTransaction(function () use ($fresh, $token, $charge) {
                $n = Database::update('restaurant_payments', [
                    'status'                 => 'paid',
                    'stripe_charge_id'       => $charge,
                    'settlement_claim_token' => null,
                    'settlement_claimed_at'  => null,
                ], 'id = ? AND status = ? AND settlement_claim_token = ?',
                   [(int)$fresh['id'], 'pending', $token]);
                if ($n !== 1) return false; // claim lost mid-flight — nothing else may run
                self::syncOrderPayment((int)$fresh['order_id']);
                return true;
            });
            if (!$completed) return ['state' => 'lost'];

            AuditLog::record('restaurant.payment_paid', (string)$paymentId, [
                'order_id' => (int)$fresh['order_id'],
                'amount'   => (int)$fresh['amount_cents'],
                'charge'   => $chargeRowId,
            ]);
            return ['state' => 'paid', 'charge_row' => $chargeRowId];
        });
    }

    /**
     * Settle a payment from caller-supplied Stripe actuals (the amount and
     * currency the caller's flow authoritatively observed). Returns a state
     * array: paid | already_paid | mismatch (+issue) | claimed (another
     * settler is working — no side effects taken) | not_pending | not_found
     * | error. Never throws.
     */
    public static function settlePaymentWithActuals(int $paymentId, ?int $actualCents, string $actualCurrency, ?string $chargeId = null): array {
        $payment = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$paymentId]);
        if (!$payment) return ['state' => 'not_found'];
        if ($payment['status'] === 'paid') {
            with_tenant((int)$payment['tenant_id'], function () use ($payment) {
                self::healPaidPayment($payment);
            });
            return ['state' => 'already_paid'];
        }
        if ($payment['status'] !== 'pending') return ['state' => 'not_pending'];

        $token = self::acquireSettlementClaim($payment);
        if ($token === null) return ['state' => 'claimed'];
        try {
            return self::completeClaimedSettlement($payment, $token, $actualCents, $actualCurrency, $chargeId);
        } catch (\Throwable $e) {
            self::releaseSettlementClaim($paymentId, $token);
            slate_log('Restaurant settlement failed for payment ' . $paymentId . ': ' . $e->getMessage(), 'error');
            return ['state' => 'error', 'error' => $e->getMessage()];
        }
    }

    // ── REST-002 — flagged-payment resolution ──────────────────────────
    // REST-001 detects and parks mismatches; these entry points are the
    // human resolution path. Invariants:
    //  * The payment row's tenant_id rules — everything runs inside
    //    with_tenant(payment.tenant_id), exactly like the settler.
    //  * Resolution takes the SAME lease claim as settlement, and every
    //    completion is token-checked, so a resolution can never interleave
    //    with an in-flight settlement, a webhook, or a second resolution.
    //  * Original figures (amount_cents / tip_cents / currency) and the
    //    original restaurant.settlement_mismatch audit row are NEVER
    //    rewritten. Accepted actuals live in settled_amount_cents /
    //    settled_currency (D3) and in the resolution audit payload.
    //  * A resolution is only reported successful after the authoritative
    //    states (Stripe for refunds, the committed local transaction for
    //    both paths) support it.

    /** Flagged payments for one tenant, newest first, with order basics. */
    public static function flaggedPayments(int $tenantId): array {
        return Database::rows(
            "SELECT p.*, o.order_no AS order_no, o.status AS order_status, o.total_cents AS order_total_cents
               FROM restaurant_payments p
               LEFT JOIN restaurant_orders o
                 ON o.id = p.order_id AND o.tenant_id = p.tenant_id
              WHERE p.tenant_id = ? AND p.settlement_issue IS NOT NULL
              ORDER BY p.id DESC", [$tenantId]);
    }

    /**
     * Fresh Stripe actuals for a payment — fetched at ACTION time, never
     * the figures stored when the mismatch was flagged (they may be stale:
     * a partial capture, a later adjustment). Returns
     * ['amount_cents' => ?int, 'currency' => string, 'charge_id' => ?string]
     * or null when Stripe cannot be reached / the PI is unknown.
     */
    public static function fetchStripeActuals(array $payment): ?array {
        $pi = (string)($payment['stripe_pi_id'] ?? '');
        if ($pi === '' || !class_exists('StripePaymentAPI')) return null;
        try {
            $intent = StripePaymentAPI::getPaymentIntent($pi);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$intent) return null;
        $amount = $intent['amount_received'] ?? ($intent['amount'] ?? null);
        return [
            'amount_cents' => $amount !== null ? (int)$amount : null,
            'currency'     => strtoupper(trim((string)($intent['currency'] ?? ''))),
            'charge_id'    => !empty($intent['latest_charge']) ? (string)$intent['latest_charge'] : null,
        ];
    }

    /**
     * Shared resolution validation. Returns an error state array, or null
     * when the action may proceed. The permission rule (D2) is enforced by
     * the calling admin page (Auth::requirePerm('restaurant.manage_orders'))
     * AND re-checked here whenever a live Auth session exists, so no
     * caller can skip it by invoking the API directly over HTTP. CLI/test
     * contexts have no Auth session (userId 0) and pass — they still must
     * supply a real actor id and reason, which land in the audit payload.
     */
    private static function resolutionGuard(int $actorId, string $reason): ?array {
        if ($actorId <= 0) return ['state' => 'invalid', 'error' => 'A resolution must name the acting user.'];
        if (mb_strlen(trim($reason)) < 3) return ['state' => 'invalid', 'error' => 'A reason of at least 3 characters is required.'];
        if (class_exists('Auth') && (int)\Auth::userId() > 0
            && !\Auth::can('restaurant.manage_orders') && !\Auth::isSuperAdmin()) {
            return ['state' => 'forbidden', 'error' => 'restaurant.manage_orders permission required.'];
        }
        return null;
    }

    /** Expected figures for audit payloads — the same basis the settler reconciles on. */
    private static function expectedFigures(array $payment): array {
        $cur = strtoupper(trim((string)($payment['currency'] ?? '')));
        return [
            'expected_cents'    => (int)$payment['amount_cents'] + (int)$payment['tip_cents'],
            'expected_currency' => $cur !== '' ? $cur : self::currency(),
        ];
    }

    /** The charges-ledger row for a payment, resolved INSIDE its tenant. */
    private static function resolveChargeRow(array $payment): ?array {
        if (!class_exists('StripePaymentAPI')) return null;
        \StripePaymentAPI::ensureChargesSchema();
        $tid = (int)$payment['tenant_id'];
        $pi  = (string)($payment['stripe_pi_id'] ?? '');
        if ($pi !== '') {
            $row = Database::row(
                "SELECT * FROM stripepayment_charges WHERE tenant_id = ? AND stripe_payment_intent_id = ?",
                [$tid, $pi]);
            if ($row) return $row;
        }
        $sess = (string)($payment['stripe_session_id'] ?? '');
        if ($sess !== '') {
            return Database::row(
                "SELECT * FROM stripepayment_charges WHERE tenant_id = ? AND stripe_session_id = ?",
                [$tid, $sess]);
        }
        return null;
    }

    /**
     * Token-checked resolution completion shared by both paths: flip the
     * payment to its resolved status, clear the flag + claim, and re-sync
     * the order — in ONE guarded transaction, only if this caller still
     * owns the claim. Returns false when the claim was lost (the caller
     * must then report honestly rather than claim success).
     */
    private static function completeFlaggedResolution(array $payment, string $token, string $newStatus, array $extra = []): bool {
        return self::withTransaction(function () use ($payment, $token, $newStatus, $extra) {
            $fields = $extra + [
                'status'                 => $newStatus,
                'settlement_issue'       => null,
                'settlement_claim_token' => null,
                'settlement_claimed_at'  => null,
            ];
            $n = Database::update('restaurant_payments', $fields,
                'id = ? AND status = ? AND settlement_claim_token = ?',
                [(int)$payment['id'], 'pending', $token]);
            if ($n !== 1) return false;
            self::syncOrderPayment((int)$payment['order_id']);
            return true;
        });
    }

    /**
     * Manager acceptance of a flagged payment at the Stripe-reported
     * figures ($actualCents/$actualCurrency MUST be freshly fetched at
     * action time — see fetchStripeActuals). The payment settles at the
     * ACTUAL amount: the charge ledger records the actual, the order's
     * paid_cents counts the actual (via settled_amount_cents), and the
     * distinct restaurant.settlement_manual_accept audit preserves the
     * expected-vs-actual story with the authorising actor and reason.
     * Never throws; returns a state array like the settler's.
     */
    public static function acceptFlaggedPayment(int $paymentId, ?int $actualCents, string $actualCurrency, ?string $chargeId, int $actorId, string $reason): array {
        if ($err = self::resolutionGuard($actorId, $reason)) return $err;
        $actualCurrency = strtoupper(trim($actualCurrency));
        if ($actualCents === null || $actualCents <= 0) {
            return ['state' => 'invalid', 'error' => 'Stripe actual amount is missing or not positive — re-check Stripe before accepting.'];
        }
        if (strlen($actualCurrency) !== 3) {
            return ['state' => 'invalid', 'error' => 'Stripe actual currency is missing — re-check Stripe before accepting.'];
        }
        $payment = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$paymentId]);
        if (!$payment) return ['state' => 'not_found'];
        if ($payment['status'] !== 'pending' || empty($payment['settlement_issue'])) {
            return ['state' => 'not_flagged'];
        }
        return with_tenant((int)$payment['tenant_id'], function () use ($payment, $actualCents, $actualCurrency, $chargeId, $actorId, $reason) {
            $fresh = Database::row("SELECT * FROM restaurant_payments WHERE id = ? AND tenant_id = ?",
                [(int)$payment['id'], (int)$payment['tenant_id']]);
            if (!$fresh || $fresh['status'] !== 'pending' || empty($fresh['settlement_issue'])) {
                return ['state' => 'not_flagged'];
            }
            $token = self::acquireSettlementClaim($fresh);
            if ($token === null) return ['state' => 'claimed'];
            try {
                $chargeRowId = null;
                if (class_exists('StripePaymentAPI') && !empty($fresh['stripe_pi_id'])) {
                    $chargeRowId = StripePaymentAPI::recordCharge(
                        self::chargePayload($fresh, $actualCents, $actualCurrency));
                }
                $done = self::completeFlaggedResolution($fresh, $token, 'paid', [
                    'stripe_charge_id'     => $chargeId ?: ($fresh['stripe_charge_id'] ?: null),
                    'settled_amount_cents' => $actualCents,
                    'settled_currency'     => $actualCurrency,
                ]);
                if (!$done) return ['state' => 'lost'];
                $exp = self::expectedFigures($fresh);
                AuditLog::record('restaurant.settlement_manual_accept', (string)$fresh['id'], [
                    'order_id'          => (int)$fresh['order_id'],
                    'issue'             => (string)$fresh['settlement_issue'],
                    'expected_cents'    => $exp['expected_cents'],
                    'expected_currency' => $exp['expected_currency'],
                    'actual_cents'      => $actualCents,
                    'actual_currency'   => $actualCurrency,
                    'actor_id'          => $actorId,
                    'reason'            => trim($reason),
                    'charge'            => $chargeRowId,
                ]);
                return ['state' => 'paid', 'charge_row' => $chargeRowId];
            } catch (\Throwable $e) {
                self::releaseSettlementClaim((int)$fresh['id'], $token);
                slate_log('Restaurant manual accept failed for payment ' . (int)$fresh['id'] . ': ' . $e->getMessage(), 'error');
                return ['state' => 'error', 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Full-remaining refund of a flagged payment through the charges
     * ledger (D4). Safety order:
     *  1. Claim the payment (lease) so nothing else can act on it.
     *  2. Inspect the ledger BEFORE any Stripe call: a charge already
     *     fully refunded (an earlier attempt whose local completion
     *     failed, or a dashboard refund) completes locally with NO new
     *     Stripe call — a retry can never double-refund.
     *  3. If no ledger row exists, create it from the freshly fetched
     *     actuals (the mismatch path never recorded one); without
     *     verifiable actuals the refund refuses — Stripe-dashboard
     *     fallback is the documented operator path.
     *  4. Audit restaurant.settlement_refund_started BEFORE the Stripe
     *     request, so a crash leaves a discoverable trail.
     *  5. StripePaymentAPI::refundCharge() performs the refund and the
     *     ledger update; only then does the local completion run,
     *     token-checked. If that completion loses the claim, the state
     *     'refund_unrecorded' + a dedicated audit say exactly what
     *     happened — success is never claimed early, and step 2 makes
     *     the retry converge.
     * The order is left unpaid (D5) — staff void or re-ring at the POS.
     */
    public static function refundFlaggedPayment(int $paymentId, ?int $actualCents, string $actualCurrency, int $actorId, string $reason): array {
        if ($err = self::resolutionGuard($actorId, $reason)) return $err;
        $payment = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$paymentId]);
        if (!$payment) return ['state' => 'not_found'];
        if ($payment['status'] !== 'pending' || empty($payment['settlement_issue'])) {
            return ['state' => 'not_flagged'];
        }
        if (!class_exists('StripePaymentAPI')) {
            return ['state' => 'error', 'error' => 'The stripe-payment plugin is not available.'];
        }
        return with_tenant((int)$payment['tenant_id'], function () use ($payment, $actualCents, $actualCurrency, $actorId, $reason) {
            $fresh = Database::row("SELECT * FROM restaurant_payments WHERE id = ? AND tenant_id = ?",
                [(int)$payment['id'], (int)$payment['tenant_id']]);
            if (!$fresh || $fresh['status'] !== 'pending' || empty($fresh['settlement_issue'])) {
                return ['state' => 'not_flagged'];
            }
            $token = self::acquireSettlementClaim($fresh);
            if ($token === null) return ['state' => 'claimed'];
            try {
                $chargeRow = self::resolveChargeRow($fresh);
                $healed = false;
                if ($chargeRow && (int)$chargeRow['amount_cents'] > 0
                    && (int)$chargeRow['refunded_cents'] >= (int)$chargeRow['amount_cents']) {
                    $healed = true; // money already fully refunded — converge locally, no Stripe call
                }
                $refundId = null; $refundedCents = $healed ? (int)$chargeRow['refunded_cents'] : 0;
                if (!$healed) {
                    if (!$chargeRow) {
                        $actualCurrency = strtoupper(trim($actualCurrency));
                        if ($actualCents === null || $actualCents <= 0 || strlen($actualCurrency) !== 3) {
                            self::releaseSettlementClaim((int)$fresh['id'], $token);
                            return ['state' => 'no_charge',
                                    'error' => 'No charge is recorded for this payment and the captured amount could not be verified — refund from the Stripe dashboard instead.'];
                        }
                        $newId = StripePaymentAPI::recordCharge(
                            self::chargePayload($fresh, $actualCents, $actualCurrency));
                        if (!$newId) {
                            self::releaseSettlementClaim((int)$fresh['id'], $token);
                            return ['state' => 'error', 'error' => 'Could not record the charge for refund.'];
                        }
                        $chargeRow = self::resolveChargeRow($fresh);
                        if (!$chargeRow) {
                            self::releaseSettlementClaim((int)$fresh['id'], $token);
                            return ['state' => 'error', 'error' => 'Charge record could not be reloaded for refund.'];
                        }
                    }
                    $exp = self::expectedFigures($fresh);
                    AuditLog::record('restaurant.settlement_refund_started', (string)$fresh['id'], [
                        'order_id'          => (int)$fresh['order_id'],
                        'issue'             => (string)$fresh['settlement_issue'],
                        'expected_cents'    => $exp['expected_cents'],
                        'expected_currency' => $exp['expected_currency'],
                        'charge_row'        => (int)$chargeRow['id'],
                        'actor_id'          => $actorId,
                        'reason'            => trim($reason),
                    ]);
                    $res = StripePaymentAPI::refundCharge((int)$chargeRow['id']);
                    if (empty($res['ok'])) {
                        self::releaseSettlementClaim((int)$fresh['id'], $token);
                        return ['state' => 'refund_failed', 'error' => (string)($res['error'] ?? 'Stripe refund failed.')];
                    }
                    $refundId = (string)($res['refund_id'] ?? '');
                    $refundedCents = (int)($res['refunded_cents'] ?? 0);
                }
                $done = self::completeFlaggedResolution($fresh, $token, 'refunded');
                if (!$done) {
                    if (!$healed) {
                        AuditLog::record('restaurant.settlement_refund_unrecorded', (string)$fresh['id'], [
                            'order_id' => (int)$fresh['order_id'],
                            'refund_id' => $refundId,
                            'refunded_cents' => $refundedCents,
                            'actor_id' => $actorId,
                            'reason' => trim($reason),
                        ]);
                        slate_log('Restaurant refund succeeded at Stripe but local completion lost the claim for payment '
                            . (int)$fresh['id'] . ' (refund ' . $refundId . '); a retry will converge.', 'error');
                    }
                    return ['state' => 'refund_unrecorded', 'refund_id' => $refundId];
                }
                $exp = self::expectedFigures($fresh);
                AuditLog::record('restaurant.settlement_refunded', (string)$fresh['id'], [
                    'order_id'          => (int)$fresh['order_id'],
                    'issue'             => (string)$fresh['settlement_issue'],
                    'expected_cents'    => $exp['expected_cents'],
                    'expected_currency' => $exp['expected_currency'],
                    'refund_id'         => $refundId,
                    'refunded_cents'    => $refundedCents,
                    'healed'            => $healed,
                    'actor_id'          => $actorId,
                    'reason'            => trim($reason),
                ]);
                return ['state' => 'refunded', 'refund_id' => $refundId, 'healed' => $healed];
            } catch (\Throwable $e) {
                self::releaseSettlementClaim((int)$payment['id'], $token);
                slate_log('Restaurant refund resolution failed for payment ' . (int)$payment['id'] . ': ' . $e->getMessage(), 'error');
                return ['state' => 'error', 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Settle a restaurant payment when its Stripe PaymentIntent succeeds.
     * Fired from the stripe_webhook_event action. The endpoint runs pinned
     * to TENANT_ID, which is NOT trusted here: the payment row found by the
     * PaymentIntent id is the source of truth, and the settlement runs in
     * that row's tenant. A PI matching more than one payment settles
     * nothing (only possible before the unique index lands).
     *
     * Known limitation (accepted for REST-001, G4): the plugin's webhook
     * listener swallows exceptions, so Stripe sees HTTP 200 and will not
     * retry a failed settlement. Recovery rides the healing paths — the POS
     * poll, the storefront return page, or a redelivery all converge.
     */
    public static function handleStripeEvent(array $event): void {
        if (class_exists('ModuleGuard') && !ModuleGuard::allows('restaurant')) return;
        $type = (string)($event['type'] ?? '');
        if (!in_array($type, ['payment_intent.succeeded', 'payment_intent.payment_failed'], true)) return;

        $pi = $event['data']['object'] ?? [];
        $piId = (string)($pi['id'] ?? '');
        $meta = $pi['metadata'] ?? [];
        if ($piId === '' || ($meta['source'] ?? '') !== 'restaurant') return;

        $payments = Database::rows(
            "SELECT * FROM restaurant_payments WHERE stripe_pi_id = ?", [$piId]);
        if (count($payments) > 1) {
            slate_log('Restaurant: stripe event for ambiguous stripe_pi_id ' . $piId
                . ' (' . count($payments) . ' payments) — settling nothing.', 'error');
            return;
        }
        if (!$payments) return;
        $payment = $payments[0];

        if ($type === 'payment_intent.payment_failed') {
            // Only a still-pending payment may fail — this event must never
            // flip a settled payment back.
            Database::update('restaurant_payments', ['status' => 'failed'],
                'id = ? AND status = ?', [(int)$payment['id'], 'pending']);
            return;
        }

        self::markPaymentPaid((int)$payment['id'], $pi);
    }

    /**
     * Settle a payment from its PaymentIntent object (webhook delivery, POS
     * poll after capture, Payment Element confirm). The authoritative amount
     * is amount_received — what Stripe actually captured — and the currency
     * is the PI's own. Kept as the stable entry point the webhook and poll
     * have always called; the work happens in settlePaymentWithActuals().
     */
    public static function markPaymentPaid(int $paymentId, array $pi = []): void {
        $actualCents = null;
        if (isset($pi['amount_received']))    $actualCents = (int)$pi['amount_received'];
        elseif (isset($pi['amount']))         $actualCents = (int)$pi['amount'];
        $chargeId = !empty($pi['latest_charge']) ? (string)$pi['latest_charge'] : null;
        self::settlePaymentWithActuals($paymentId, $actualCents, (string)($pi['currency'] ?? ''), $chargeId);
    }

    /**
     * Re-sum paid payments onto the order and close it when fully paid. Tips
     * collected at tender are folded into the order's tip, the totals are
     * recomputed (so total includes tip), and the order closes once the paid
     * amount covers that total.
     */
    public static function syncOrderPayment(int $orderId): void {
        $tid   = current_tenant_id();
        $order = Database::row(
            "SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;

        // Fold tender-time tips into the order tip, then recompute totals so
        // total_cents includes the tip before we compare against paid.
        $tips = (int) Database::value(
            "SELECT COALESCE(SUM(tip_cents),0) FROM restaurant_payments
              WHERE order_id = ? AND tenant_id = ? AND status = 'paid'", [$orderId, $tid]);
        Database::update('restaurant_orders', ['tip_cents' => $tips], 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);

        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        // REST-002: a manually accepted payment (settled_amount_cents set)
        // counts at the figure the manager actually accepted — that is the
        // money received — never at the rung figure it overrode. Automatic
        // settlements leave the column NULL and count exactly as before.
        $paid  = (int) Database::value(
            "SELECT COALESCE(SUM(COALESCE(settled_amount_cents, amount_cents + tip_cents)),0) FROM restaurant_payments
              WHERE order_id = ? AND tenant_id = ? AND status = 'paid'", [$orderId, $tid]);

        $fields = ['paid_cents' => $paid];
        if ((int)$order['total_cents'] > 0 && $paid >= (int)$order['total_cents']
            && !in_array($order['status'], ['voided', 'closed'], true)) {
            $fields['status']    = 'paid';
            $fields['closed_at'] = slate_db_now();
        }
        Database::update('restaurant_orders', $fields, 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        if (($fields['status'] ?? '') === 'paid') self::freeTableIfSettled((array)$order);
    }

    // ── Order lifecycle ───────────────────────────────────────

    /**
     * Open a new order/check. $args: type, table_id, customer_id, server_id,
     * guest_count, notes, source. The service-charge percent is snapshotted
     * from settings at open time so later recalcs stay stable. Returns the id.
     */
    public static function openOrder(array $args = []): int {
        self::ensureSchema();
        $tid  = current_tenant_id();
        $type = in_array(($args['type'] ?? 'dine_in'), ['dine_in', 'takeout', 'delivery'], true)
            ? ($args['type'] ?? 'dine_in') : 'dine_in';
        $source = in_array(($args['source'] ?? 'pos'), ['pos', 'online'], true)
            ? ($args['source'] ?? 'pos') : 'pos';
        $svcPct = self::serviceChargeAuto() ? self::serviceChargePct() : 0;

        $id = Database::insert('restaurant_orders', [
            'tenant_id'          => $tid,
            'order_no'           => self::generateOrderNo($tid),
            'type'               => $type,
            'table_id'           => !empty($args['table_id']) ? (int)$args['table_id'] : null,
            'customer_id'        => !empty($args['customer_id']) ? (int)$args['customer_id'] : null,
            'server_id'          => !empty($args['server_id']) ? (int)$args['server_id'] : (Auth::userId() ?: null),
            'status'             => 'open',
            'guest_count'        => max(1, (int)($args['guest_count'] ?? 1)),
            'service_charge_pct' => $svcPct,
            'source'             => $source,
            'notes'              => isset($args['notes']) ? mb_substr((string)$args['notes'], 0, 255) : null,
        ]);

        if ($type === 'dine_in' && !empty($args['table_id'])) {
            Database::update('restaurant_tables', ['status' => 'seated'],
                'id = ? AND tenant_id = ?', [(int)$args['table_id'], $tid]);
        }
        AuditLog::record('restaurant.order_opened', (string)$id, ['type' => $type]);
        return $id;
    }

    /** Update editable order meta (guest count, notes, customer). */
    public static function updateOrderMeta(int $orderId, array $fields): void {
        $tid = current_tenant_id();
        $set = [];
        if (array_key_exists('guest_count', $fields)) $set['guest_count'] = max(1, (int)$fields['guest_count']);
        if (array_key_exists('notes', $fields))       $set['notes']       = mb_substr((string)$fields['notes'], 0, 255);
        if (array_key_exists('customer_id', $fields))  $set['customer_id'] = $fields['customer_id'] ? (int)$fields['customer_id'] : null;
        if ($set) Database::update('restaurant_orders', $set, 'id = ? AND tenant_id = ?', [$orderId, $tid]);
    }

    /**
     * Add a line to an order. $line: item_id, qty, seat, check_no, notes,
     * modifiers => [modifier_id, …] (validated against the item's groups).
     * Returns the new line id. Throws on a bad item or modifier rule.
     */
    public static function addLine(int $orderId, array $line): int {
        $tid  = current_tenant_id();
        $itemId = (int)($line['item_id'] ?? 0);
        $item = $itemId > 0
            ? Database::row("SELECT * FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$itemId, $tid])
            : null;
        if (!$item) throw new \RuntimeException('Unknown menu item.');
        if (!empty($item['is_86'])) throw new \RuntimeException($item['name'] . " is 86'd right now.");

        $qty       = max(1, (int)($line['qty'] ?? 1));
        $chosenIds = array_values(array_unique(array_map('intval', (array)($line['modifiers'] ?? []))));

        // Validate chosen modifiers against the item's groups + collect snapshots.
        $groups    = self::getItemModifierGroups($itemId);
        $validMods = [];
        foreach ($groups as $g) {
            $picked = [];
            foreach ($g['modifiers'] as $m) {
                if (in_array((int)$m['id'], $chosenIds, true)) { $picked[] = $m; $validMods[] = $m; }
            }
            $min = (int)$g['min_select']; $max = (int)$g['max_select'];
            if (!empty($g['is_required']) && count($picked) < max(1, $min)) {
                throw new \RuntimeException('Choose at least ' . max(1, $min) . ' for: ' . $g['name']);
            }
            if ($max > 0 && count($picked) > $max) {
                throw new \RuntimeException('Choose at most ' . $max . ' for: ' . $g['name']);
            }
        }

        $lineId = Database::insert('restaurant_order_items', [
            'tenant_id'        => $tid,
            'order_id'         => $orderId,
            'check_no'         => max(1, (int)($line['check_no'] ?? 1)),
            'item_id'          => (int)$item['id'],
            'name_snapshot'    => mb_substr((string)$item['name'], 0, 160),
            'qty'              => $qty,
            'unit_price_cents' => (int)$item['price_cents'],
            'tax_rate'         => (float)$item['tax_rate'],
            'seat'             => !empty($line['seat']) ? (int)$line['seat'] : null,
            'notes'            => isset($line['notes']) ? mb_substr((string)$line['notes'], 0, 255) : null,
            'kitchen_status'   => 'new',
        ]);
        foreach ($validMods as $m) {
            Database::insert('restaurant_order_item_modifiers', [
                'tenant_id'         => $tid,
                'order_item_id'     => $lineId,
                'modifier_id'       => (int)$m['id'],
                'name_snapshot'     => mb_substr((string)$m['name'], 0, 160),
                'price_delta_cents' => (int)$m['price_delta_cents'],
            ]);
        }
        self::recalcOrder($orderId);
        return $lineId;
    }

    public static function setLineQty(int $lineId, int $qty): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) return;
        Database::update('restaurant_order_items', ['qty' => max(1, $qty)], 'id = ?', [$lineId]);
        self::recalcOrder((int)$line['order_id']);
    }

    /** Remove an un-fired line outright; void it instead once it's been sent. */
    public static function removeLine(int $lineId): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) return;
        if ($line['kitchen_status'] === 'new') {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lineId]);
            Database::delete('restaurant_order_items', 'id = ? AND tenant_id = ?', [$lineId, $tid]);
            self::recalcOrder((int)$line['order_id']);
        } else {
            self::voidLine($lineId);
        }
    }

    public static function voidLine(int $lineId): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) return;
        Database::update('restaurant_order_items', ['kitchen_status' => 'void'], 'id = ?', [$lineId]);
        AuditLog::record('restaurant.line_voided', (string)$lineId, ['order_id' => (int)$line['order_id']]);
        self::recalcOrder((int)$line['order_id']);
    }

    /** Move a line onto a different check number (split-check support). */
    public static function moveLineToCheck(int $lineId, int $checkNo): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) return;
        Database::update('restaurant_order_items', ['check_no' => max(1, $checkNo)], 'id = ?', [$lineId]);
    }

    /** Fire all 'new' lines to the kitchen. */
    public static function sendToKitchen(int $orderId): void {
        $tid = current_tenant_id();
        Database::query(
            "UPDATE restaurant_order_items SET kitchen_status = 'sent'
              WHERE order_id = ? AND tenant_id = ? AND kitchen_status = 'new'", [$orderId, $tid]);
        Database::update('restaurant_orders', ['status' => 'sent'],
            "id = ? AND tenant_id = ? AND status = 'open'", [$orderId, $tid]);
        AuditLog::record('restaurant.order_sent', (string)$orderId);
    }

    public static function setDiscount(int $orderId, int $discountCents): void {
        $tid = current_tenant_id();
        Database::update('restaurant_orders', ['discount_cents' => max(0, $discountCents)],
            'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);
        AuditLog::record('restaurant.discount_set', (string)$orderId, ['cents' => max(0, $discountCents)]);
    }

    public static function setTip(int $orderId, int $tipCents): void {
        $tid = current_tenant_id();
        Database::update('restaurant_orders', ['tip_cents' => max(0, $tipCents)],
            'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);
    }

    // ── Tender ────────────────────────────────────────────────

    /** Tender cash. Closes the order if the paid total now covers it. */
    public static function recordCashPayment(int $orderId, int $amountCents, int $tipCents = 0): int {
        $tid = current_tenant_id();
        // Verify ownership HERE, not only in callers: a payment must never be
        // attachable to another tenant's order by a direct API call (REST-001).
        $owns = Database::value(
            "SELECT id FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$owns) return 0;
        $pid = Database::insert('restaurant_payments', [
            'tenant_id'    => $tid,
            'order_id'     => $orderId,
            'method'       => 'cash',
            'amount_cents' => max(0, $amountCents),
            'tip_cents'    => max(0, $tipCents),
            'status'       => 'paid',
        ]);
        AuditLog::record('restaurant.cash_payment', (string)$orderId, ['cents' => $amountCents, 'tip' => $tipCents]);
        self::syncOrderPayment($orderId);
        return $pid;
    }

    /**
     * Start a card-present charge on a registered reader (server-driven).
     * Creates a pending payment + a manual-capture card_present PaymentIntent,
     * then pushes it to the reader. Returns ['ok','payment_id','pi_id','simulated']
     * or ['ok'=>false,'error'=>…]. Poll with pollTerminalPayment().
     */
    public static function beginTerminalPayment(int $orderId, int $readerRowId, int $amountCents, int $tipCents = 0): array {
        if (!self::terminalAvailable()) {
            return ['ok' => false, 'error' => 'Stripe Terminal is not available. Configure the Stripe plugin first.'];
        }
        $tid    = current_tenant_id();
        $reader = Database::row("SELECT * FROM restaurant_readers WHERE id = ? AND tenant_id = ?", [$readerRowId, $tid]);
        if (!$reader) return ['ok' => false, 'error' => 'Reader not found.'];

        $charge = max(0, $amountCents) + max(0, $tipCents);
        if ($charge <= 0) return ['ok' => false, 'error' => 'Nothing to charge.'];

        $orderNo = (string) Database::value(
            "SELECT order_no FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        try {
            $pi = StripeTerminalAPI::createCardPresentIntent($charge, [
                'currency' => strtolower(self::currency()),
                'metadata' => ['source' => 'restaurant', 'order_id' => (string)$orderId, 'order_no' => $orderNo],
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $pid = Database::insert('restaurant_payments', [
            'tenant_id'    => $tid,
            'order_id'     => $orderId,
            'method'       => 'terminal',
            'amount_cents' => max(0, $amountCents),
            'tip_cents'    => max(0, $tipCents),
            'status'       => 'pending',
            'stripe_pi_id' => (string)$pi['id'],
            'currency'     => self::currency(),
            'reader_id'    => $readerRowId,
        ]);

        $proc = StripeTerminalAPI::processOnReader((string)$reader['stripe_reader_id'], (string)$pi['id']);
        if (!$proc['ok']) {
            Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$pid]);
            return ['ok' => false, 'error' => $proc['error'], 'payment_id' => $pid];
        }
        AuditLog::record('restaurant.terminal_begin', (string)$orderId, ['pi' => $pi['id']]);
        return [
            'ok'         => true,
            'payment_id' => $pid,
            'pi_id'      => (string)$pi['id'],
            'simulated'  => str_contains((string)$reader['stripe_reader_id'], 'simulated'),
        ];
    }

    /**
     * Poll a pending terminal payment. Captures the PI once the reader has
     * collected a card, then settles through the REST-001 settler. Returns a
     * status array: ['ok'=>bool, 'state'=>'pending'|'paid'|'failed'|'mismatch',
     * 'error'?]. The settlement claim is taken BEFORE any Stripe call: the
     * capture must never run twice for one payment, and a webhook may be
     * settling this same payment at the same moment.
     */
    public static function pollTerminalPayment(int $paymentId): array {
        if (!self::terminalAvailable()) return ['ok' => false, 'state' => 'failed', 'error' => 'Terminal unavailable.'];
        $tid = current_tenant_id();
        $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ? AND tenant_id = ?", [$paymentId, $tid]);
        if (!$pay) return ['ok' => false, 'state' => 'failed', 'error' => 'Payment not found.'];
        if ($pay['status'] === 'paid')   return ['ok' => true,  'state' => 'paid'];
        if ($pay['status'] === 'failed') return ['ok' => false, 'state' => 'failed', 'error' => 'Payment failed or was cancelled.'];

        $token = self::acquireSettlementClaim($pay);
        if ($token === null) return ['ok' => true, 'state' => 'pending', 'settling' => true];

        try {
            $piId = (string)$pay['stripe_pi_id'];
            $pi   = StripePaymentAPI::getPaymentIntent($piId);
            if (!$pi) {
                self::releaseSettlementClaim($paymentId, $token);
                return ['ok' => true, 'state' => 'pending'];
            }

            $status = (string)($pi['status'] ?? '');
            if ($status === 'requires_capture') {
                $cap = StripeTerminalAPI::capture($piId);
                if (!$cap['ok']) {
                    self::releaseSettlementClaim($paymentId, $token);
                    return ['ok' => false, 'state' => 'failed', 'error' => $cap['error']];
                }
                $pi = $cap['payment_intent'];
                $status = (string)($pi['status'] ?? '');
            }

            if ($status === 'succeeded') {
                $actual = isset($pi['amount_received']) ? (int)$pi['amount_received']
                        : (isset($pi['amount']) ? (int)$pi['amount'] : null);
                $res = self::completeClaimedSettlement($pay, $token, $actual,
                    (string)($pi['currency'] ?? ''),
                    !empty($pi['latest_charge']) ? (string)$pi['latest_charge'] : null);
                if ($res['state'] === 'paid' || $res['state'] === 'already_paid') {
                    return ['ok' => true, 'state' => 'paid'];
                }
                if ($res['state'] === 'mismatch') {
                    return ['ok' => false, 'state' => 'mismatch',
                            'error' => 'The amount Stripe settled does not match this check. '
                                     . 'It has been flagged for review; the check was not marked paid.'];
                }
                // Claim lost mid-flight — the winning settler owns the outcome.
                self::releaseSettlementClaim($paymentId, $token);
                return ['ok' => true, 'state' => 'pending'];
            }
            if ($status === 'canceled') {
                Database::update('restaurant_payments',
                    ['status' => 'failed', 'settlement_claim_token' => null, 'settlement_claimed_at' => null],
                    'id = ? AND status = ?', [$paymentId, 'pending']);
                return ['ok' => false, 'state' => 'failed', 'error' => 'Cancelled at the reader.'];
            }
            if ($status === 'requires_payment_method' && !empty($pi['last_payment_error'])) {
                Database::update('restaurant_payments',
                    ['status' => 'failed', 'settlement_claim_token' => null, 'settlement_claimed_at' => null],
                    'id = ? AND status = ?', [$paymentId, 'pending']);
                return ['ok' => false, 'state' => 'failed',
                        'error' => (string)($pi['last_payment_error']['message'] ?? 'Card declined.')];
            }
            self::releaseSettlementClaim($paymentId, $token);
            return ['ok' => true, 'state' => 'pending', 'stripe_status' => $status];
        } catch (\Throwable $e) {
            self::releaseSettlementClaim($paymentId, $token);
            slate_log('Restaurant terminal poll failed for payment ' . $paymentId . ': ' . $e->getMessage(), 'error');
            return ['ok' => false, 'state' => 'failed', 'error' => 'Payment status check failed.'];
        }
    }

    /** Abort an in-flight terminal payment (cancel the reader + the PI). */
    public static function cancelTerminalPayment(int $paymentId): array {
        $tid = current_tenant_id();
        $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ? AND tenant_id = ?", [$paymentId, $tid]);
        if (!$pay) return ['ok' => false, 'error' => 'Payment not found.'];
        if (self::terminalAvailable()) {
            if (!empty($pay['reader_id'])) {
                $reader = Database::row(
                    "SELECT * FROM restaurant_readers WHERE id = ? AND tenant_id = ?", [(int)$pay['reader_id'], $tid]);
                if ($reader) StripeTerminalAPI::cancelReaderAction((string)$reader['stripe_reader_id']);
            }
            if (!empty($pay['stripe_pi_id'])) StripeTerminalAPI::cancelIntent((string)$pay['stripe_pi_id']);
        }
        // Only a still-pending payment may be failed by a cancel — a payment
        // that settled in the meantime must never be flipped back (REST-001).
        Database::update('restaurant_payments', ['status' => 'failed'],
            'id = ? AND status = ?', [$paymentId, 'pending']);
        return ['ok' => true];
    }

    // ── Close / void ──────────────────────────────────────────

    public static function closeOrder(int $orderId): void {
        $tid   = current_tenant_id();
        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;
        Database::update('restaurant_orders',
            ['status' => 'closed', 'closed_at' => slate_db_now()],
            'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::freeTableIfSettled($order);
        AuditLog::record('restaurant.order_closed', (string)$orderId);
    }

    public static function voidOrder(int $orderId, string $reason = ''): void {
        $tid   = current_tenant_id();
        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;
        $note = trim((($order['notes'] ?? '') !== '' ? $order['notes'] . ' · ' : '') . 'VOID: ' . $reason);
        Database::update('restaurant_orders',
            ['status' => 'voided', 'closed_at' => slate_db_now(), 'notes' => mb_substr($note, 0, 255)],
            'id = ? AND tenant_id = ?', [$orderId, $tid]);
        Database::query("UPDATE restaurant_order_items SET kitchen_status = 'void'
                          WHERE order_id = ? AND tenant_id = ?", [$orderId, $tid]);
        self::freeTableIfSettled($order);
        AuditLog::record('restaurant.order_voided', (string)$orderId, ['reason' => $reason]);
    }

    /** Mark a table dirty once no open/sent order remains on it. */
    private static function freeTableIfSettled(array $order): void {
        if (empty($order['table_id'])) return;
        $tid = current_tenant_id();
        $stillOpen = (int) Database::value(
            "SELECT COUNT(*) FROM restaurant_orders
              WHERE tenant_id = ? AND table_id = ? AND id <> ? AND status IN ('open','sent')",
            [$tid, (int)$order['table_id'], (int)$order['id']]);
        if ($stillOpen === 0) {
            Database::update('restaurant_tables', ['status' => 'dirty'],
                'id = ? AND tenant_id = ?', [(int)$order['table_id'], $tid]);
        }
    }

    // ── Customers (light POS lookup) ──────────────────────────

    public static function findOrCreateCustomer(string $name, string $phone = '', string $email = ''): ?int {
        $name = trim($name); $phone = trim($phone); $email = trim($email);
        if ($name === '' && $phone === '' && $email === '') return null;
        $tid = current_tenant_id();
        if ($phone !== '') {
            $row = Database::row("SELECT id FROM restaurant_customers WHERE tenant_id = ? AND phone = ?", [$tid, $phone]);
            if ($row) return (int)$row['id'];
        }
        return Database::insert('restaurant_customers', [
            'tenant_id' => $tid,
            'name'      => mb_substr($name !== '' ? $name : 'Guest', 0, 160),
            'phone'     => $phone !== '' ? mb_substr($phone, 0, 40) : null,
            'email'     => $email !== '' ? mb_substr($email, 0, 200) : null,
        ]);
    }

    // ── Online ordering (Phase 3 — public storefront) ─────────

    /** Is the public online-ordering storefront switched on in settings? */
    public static function onlineOrderingEnabled(): bool {
        return (string) self::setting('online_enabled', '0') === '1';
    }

    /** Can the storefront take card payment online (vs pay-at-pickup only)? */
    public static function onlinePayEnabled(): bool {
        return self::onlineOrderingEnabled()
            && (string) self::setting('online_pay', '1') === '1'
            && class_exists('StripePaymentAPI') && StripePaymentAPI::isConfigured();
    }

    /** Fetch a single online order by its unguessable public token. */
    public static function getOrderByToken(string $token): ?array {
        $token = trim($token);
        if ($token === '') return null;
        $row = Database::row(
            "SELECT id FROM restaurant_orders WHERE public_token = ? AND tenant_id = ?",
            [$token, current_tenant_id()]);
        return $row ? self::getOrder((int)$row['id']) : null;
    }

    /**
     * Create an order from a public storefront cart. $cart is a list of
     * [item_id, qty, modifiers => [ids], notes]. $contact = name/phone/email.
     * $meta = type ('takeout'|'delivery'), requested_time, notes. Validates
     * every line through addLine(). Returns ['order_id','token'] or throws.
     */
    public static function createOnlineOrder(array $cart, array $contact, array $meta = []): array {
        if (empty($cart)) throw new \RuntimeException('Your cart is empty.');
        $tid  = current_tenant_id();
        $type = in_array(($meta['type'] ?? 'takeout'), ['takeout', 'delivery'], true) ? $meta['type'] : 'takeout';

        $custId = self::findOrCreateCustomer(
            (string)($contact['name'] ?? ''), (string)($contact['phone'] ?? ''), (string)($contact['email'] ?? ''));

        $orderId = self::openOrder([
            'type'        => $type,
            'customer_id' => $custId,
            'guest_count' => 1,
            'source'      => 'online',
            'notes'       => (string)($meta['notes'] ?? ''),
        ]);

        try {
            foreach ($cart as $line) {
                self::addLine($orderId, [
                    'item_id'   => (int)($line['item_id'] ?? 0),
                    'qty'       => (int)($line['qty'] ?? 1),
                    'modifiers' => array_map('intval', (array)($line['modifiers'] ?? [])),
                    'notes'     => (string)($line['notes'] ?? ''),
                ]);
            }
        } catch (\Throwable $e) {
            // Roll back the half-built order so a bad line never leaves a ghost.
            self::voidOrder($orderId, 'online build failed');
            throw $e;
        }

        $token = bin2hex(random_bytes(16));
        Database::update('restaurant_orders', [
            'public_token'       => $token,
            'fulfillment_status' => 'new',
            'requested_time'     => mb_substr((string)($meta['requested_time'] ?? ''), 0, 40) ?: null,
        ], 'id = ? AND tenant_id = ?', [$orderId, $tid]);

        AuditLog::record('restaurant.online_order_created', (string)$orderId, ['type' => $type]);

        if (class_exists('Notifications')) {
            $order = self::getOrder($orderId);
            Notifications::add('New ' . $type . ' order · ' . ($order['order_no'] ?? ('#' . $orderId)), [
                'body' => ($order['customer_name'] ?? $contact['name'] ?? 'A customer') . ' — '
                        . self::money((int)($order['total_cents'] ?? 0)),
                'url'  => function_exists('plugin_url') ? plugin_url('restaurant', 'admin/order.php') . '?id=' . $orderId : '',
                'icon' => 'shopping-bag',
            ]);
        }

        return ['order_id' => $orderId, 'token' => $token];
    }

    /**
     * Start a hosted Stripe Checkout for an online order. Creates a pending
     * payment, builds line items from the order, and returns the redirect URL.
     */
    public static function beginOnlinePayment(int $orderId, string $successUrl, string $cancelUrl): array {
        if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
            return ['ok' => false, 'error' => 'Online card payment is not available.'];
        }
        $order = self::getOrder($orderId);
        if (!$order) return ['ok' => false, 'error' => 'Order not found.'];

        $lineItems = [];
        foreach ($order['items'] as $it) {
            if ($it['kitchen_status'] === 'void') continue;
            $modNames = array_map(fn($m) => $m['name_snapshot'], $it['modifiers'] ?? []);
            $perUnit  = (int)$it['unit_price_cents'] + (int)$it['modifiers_total_cents'];
            $lineItems[] = [
                'name'         => (string)$it['name_snapshot'],
                'description'  => $modNames ? implode(', ', $modNames) : '',
                'amount_cents' => $perUnit,
                'quantity'     => max(1, (int)$it['qty']),
            ];
        }
        if ((int)$order['service_charge_cents'] > 0)
            $lineItems[] = ['name' => 'Service charge', 'amount_cents' => (int)$order['service_charge_cents'], 'quantity' => 1];
        if ((int)$order['tax_cents'] > 0)
            $lineItems[] = ['name' => 'Tax', 'amount_cents' => (int)$order['tax_cents'], 'quantity' => 1];
        if (!$lineItems) return ['ok' => false, 'error' => 'Nothing to charge.'];

        $tid = current_tenant_id();
        $pid = Database::insert('restaurant_payments', [
            'tenant_id'    => $tid,
            'order_id'     => $orderId,
            'method'       => 'other',
            'amount_cents' => (int)$order['total_cents'],
            'tip_cents'    => 0,
            'status'       => 'pending',
            'currency'     => self::currency(),
        ]);

        try {
            $sess = StripePaymentAPI::createCheckout($lineItems, [
                'currency'       => strtolower(self::currency()),
                'success_url'    => $successUrl,
                'cancel_url'     => $cancelUrl,
                'customer_email' => $order['customer_email'] ?? null,
                'metadata'       => ['source' => 'restaurant', 'source_plugin' => 'restaurant',
                                     'order_id' => (string)$orderId, 'payment_id' => (string)$pid],
            ]);
        } catch (\Throwable $e) {
            Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$pid]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        Database::update('restaurant_payments', ['stripe_session_id' => $sess['session_id']], 'id = ?', [$pid]);
        return ['ok' => true, 'url' => $sess['url'], 'payment_id' => $pid];
    }

    /**
     * Settle an online order from its Stripe Checkout session on the return
     * page. Idempotent — safe to call repeatedly. Returns the fresh order.
     */
    public static function settleOnlineFromSession(string $sessionId): ?array {
        $sessionId = trim($sessionId);
        if ($sessionId === '') return null;
        $tid = current_tenant_id();
        $pay = Database::row(
            "SELECT * FROM restaurant_payments WHERE stripe_session_id = ? AND tenant_id = ?", [$sessionId, $tid]);
        if (!$pay) return null;

        if ($pay['status'] !== 'paid' && class_exists('StripePaymentAPI')) {
            $sess = StripePaymentAPI::getSession($sessionId);
            if ($sess && (string)($sess['payment_status'] ?? '') === 'paid') {
                $piId = !empty($sess['payment_intent']) ? (string)$sess['payment_intent'] : '';
                if ($piId !== '' && empty($pay['stripe_pi_id'])) {
                    Database::update('restaurant_payments',
                        ['stripe_pi_id' => $piId], 'id = ?', [(int)$pay['id']]);
                    $pay['stripe_pi_id'] = $piId;
                }
                // The hosted session is authoritative for amount + currency
                // (amount_total / currency). The charge id lives on the
                // PaymentIntent's latest_charge — fetch it, because storing
                // the PI id in stripe_charge_id (the old behaviour) corrupts
                // refund/reconciliation lookups. A failed fetch only costs the
                // cosmetic charge id, never the amount verification.
                $chargeId = null;
                if ($piId !== '') {
                    try {
                        $piObj = StripePaymentAPI::getPaymentIntent($piId);
                        if ($piObj && !empty($piObj['latest_charge'])) {
                            $chargeId = (string)$piObj['latest_charge'];
                        }
                    } catch (\Throwable $e) { /* settle proceeds on session actuals */ }
                }
                self::settlePaymentWithActuals((int)$pay['id'],
                    isset($sess['amount_total']) ? (int)$sess['amount_total'] : null,
                    (string)($sess['currency'] ?? ''), $chargeId);
            }
        }
        return self::getOrder((int)$pay['order_id']);
    }

    /**
     * Begin an on-page (Payment Element) card payment for an online order.
     * Creates a pending payment row + a PaymentIntent for the order total and
     * returns the client secret + publishable key for Stripe.js. The PI carries
     * `metadata.source = restaurant`, so handleStripeEvent() settles it from the
     * webhook regardless of what the browser does after confirming.
     */
    public static function beginOnlinePaymentIntent(int $orderId): array {
        if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
            return ['ok' => false, 'error' => 'Online card payment is not available.'];
        }
        $order = self::getOrder($orderId);
        if (!$order) return ['ok' => false, 'error' => 'Order not found.'];
        $amount = (int)$order['total_cents'];
        if ($amount <= 0) return ['ok' => false, 'error' => 'Nothing to charge.'];

        $tid = current_tenant_id();
        $pid = Database::insert('restaurant_payments', [
            'tenant_id'    => $tid,
            'order_id'     => $orderId,
            'method'       => 'other',
            'amount_cents' => $amount,
            'tip_cents'    => 0,
            'status'       => 'pending',
            'currency'     => self::currency(),
        ]);

        try {
            $pi = StripePaymentAPI::createPaymentIntent($amount, [
                'currency'             => strtolower(self::currency()),
                'receipt_email'        => $order['customer_email'] ?? null,
                'payment_method_types' => ['card'],
                'metadata'             => ['source' => 'restaurant', 'source_plugin' => 'restaurant',
                                           'order_id' => (string)$orderId, 'payment_id' => (string)$pid],
            ]);
        } catch (\Throwable $e) {
            Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$pid]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        Database::update('restaurant_payments', ['stripe_pi_id' => (string)$pi['id']], 'id = ?', [$pid]);
        return [
            'ok'             => true,
            'payment_id'     => $pid,
            'pi_id'          => (string)$pi['id'],
            'client_secret'  => (string)$pi['client_secret'],
            'publishable_key'=> StripePaymentAPI::publishableKey(),
        ];
    }

    /**
     * Settle an online order from its PaymentIntent on the return page (the
     * embedded Payment Element flow). Idempotent; the webhook is the backstop.
     */
    public static function settleOnlineFromPaymentIntent(string $piId): ?array {
        $piId = trim($piId);
        if ($piId === '') return null;
        $tid = current_tenant_id();
        $pay = Database::row(
            "SELECT * FROM restaurant_payments WHERE stripe_pi_id = ? AND tenant_id = ?", [$piId, $tid]);
        if (!$pay) return null;

        if ($pay['status'] !== 'paid' && class_exists('StripePaymentAPI')) {
            $pi = StripePaymentAPI::getPaymentIntent($piId);
            if ($pi && (string)($pi['status'] ?? '') === 'succeeded') {
                self::markPaymentPaid((int)$pay['id'], $pi);
            }
        }
        return self::getOrder((int)$pay['order_id']);
    }

    /** Online orders not yet fulfilled, newest first — the kitchen/expo queue. */
    public static function getOnlineOrders(int $limit = 100): array {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT o.*, c.name AS customer_name, c.phone AS customer_phone
               FROM restaurant_orders o
               LEFT JOIN restaurant_customers c ON c.id = o.customer_id
              WHERE o.tenant_id = ? AND o.source = 'online'
              ORDER BY o.id DESC LIMIT ?", [$tid, max(1, $limit)]);
    }

    /** Advance an online order's fulfilment state (kitchen/expo workflow). */
    public static function setFulfillment(int $orderId, string $status): void {
        $allowed = ['new', 'accepted', 'preparing', 'ready', 'completed', 'cancelled'];
        if (!in_array($status, $allowed, true)) return;
        $tid = current_tenant_id();
        Database::update('restaurant_orders', ['fulfillment_status' => $status],
            'id = ? AND tenant_id = ? AND source = ?', [$orderId, $tid, 'online']);
        AuditLog::record('restaurant.online_fulfillment', (string)$orderId, ['status' => $status]);
    }
}
