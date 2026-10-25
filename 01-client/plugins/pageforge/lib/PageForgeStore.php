<?php
/**
 * PageForge store — tenant-scoped persistence for pages, revisions and
 * compilations. All queries bind tenant_id from the current request context;
 * no method accepts a tenant id from the caller, so cross-tenant access
 * cannot be expressed through this API.
 *
 * Save flow: normalizeDocument() (sanitize) → new revision → page's draft
 * pointer moves. Publish flow: compile(draft document) → compilations row →
 * published pointer moves. Public runtime only ever reads compilations.
 */

declare(strict_types=1);

require_once __DIR__ . '/PageForgeRenderer.php';
require_once __DIR__ . '/PageForgeKit.php';
require_once __DIR__ . '/PageForgeDisplay.php';
require_once __DIR__ . '/PageForgeLibrary.php';

final class PageForgeStore
{
    /** Document format version stamped on every save (Phase 6). */
    public const DOCUMENT_VERSION = '1.0';

    private static function pdo(): PDO
    {
        return Database::get();
    }

    private static function tenantId(): int
    {
        return current_tenant_id();
    }

    private static bool $schemaReady = false;

    private static int $slugRecompileDepth = 0;

    /** Phase 4 columns (kind/part) for installs created before they existed. */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        self::$schemaReady = true;
        $cols = Database::rows("SHOW COLUMNS FROM pageforge_pages LIKE 'kind'");
        if ($cols === []) {
            Database::query("ALTER TABLE pageforge_pages ADD COLUMN kind ENUM('page','part','popup','global','loop-item','template') NOT NULL DEFAULT 'page'");
        } elseif (!str_contains(strtolower((string) ($cols[0]['Type'] ?? '')), 'loop-item')
            || !str_contains(strtolower((string) ($cols[0]['Type'] ?? '')), 'template')) {
            // Phase 5 widened for loop-item; Phase 11 adds 'template'.
            Database::query("ALTER TABLE pageforge_pages MODIFY COLUMN kind ENUM('page','part','popup','global','loop-item','template') NOT NULL DEFAULT 'page'");
        }
        // Phase 4's migration added kind+part in one statement; ensure part
        // independently so a partially-migrated table (kind without part)
        // still heals instead of fataling on the first INSERT.
        if (Database::rows("SHOW COLUMNS FROM pageforge_pages LIKE 'part'") === []) {
            Database::query("ALTER TABLE pageforge_pages ADD COLUMN part VARCHAR(20) NULL DEFAULT NULL");
        }
        // Phase 10: display conditions (W3) + popup configuration (W4)
        // live on the page row — normalizeDocument() reduces a document
        // to its element tree, so page-level metadata cannot ride it.
        if (Database::rows("SHOW COLUMNS FROM pageforge_pages LIKE 'conditions_json'") === []) {
            Database::query("ALTER TABLE pageforge_pages ADD COLUMN conditions_json TEXT NULL DEFAULT NULL");
        }
        if (Database::rows("SHOW COLUMNS FROM pageforge_pages LIKE 'popup_json'") === []) {
            Database::query("ALTER TABLE pageforge_pages ADD COLUMN popup_json TEXT NULL DEFAULT NULL");
        }
    }

    public static function createPage(string $title, string $slug, int $userId, string $kind = 'page', ?string $part = null): int
    {
        self::ensureSchema();
        if (!in_array($kind, ['page', 'part', 'popup', 'global', 'loop-item', 'template'], true)) {
            $kind = 'page';
        }
        if ($kind !== 'part') {
            $part = null;
        } elseif (!in_array($part, ['header', 'footer', 'single', 'archive', 'search', '404', 'section'], true)) {
            $part = 'header';
        }
        $pdo = self::pdo();
        $tid = self::tenantId();
        $slug = self::uniqueSlug($tid, $slug !== '' ? $slug : self::slugify($title));
        $stmt = $pdo->prepare(
            "INSERT INTO pageforge_pages (tenant_id, uuid, title, slug, status, kind, part, created_by, updated_by)
             VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?)"
        );
        $stmt->execute([$tid, self::uuid(), mb_substr($title, 0, 255), $slug, $kind, $part, $userId, $userId]);
        $pageId = (int) $pdo->lastInsertId();
        self::saveDocument($pageId, ['elements' => []], $userId, 'manual');
        return $pageId;
    }

    public static function getPage(int $pageId): ?array
    {
        $rows = Database::rows(
            'SELECT * FROM pageforge_pages WHERE tenant_id = ? AND id = ?',
            [self::tenantId(), $pageId]
        );
        return $rows[0] ?? null;
    }

    public static function getPageBySlug(string $slug): ?array
    {
        $rows = Database::rows(
            'SELECT * FROM pageforge_pages WHERE tenant_id = ? AND slug = ?',
            [self::tenantId(), $slug]
        );
        return $rows[0] ?? null;
    }

    /** @return list<array> */
    public static function listPages(?string $kind = null): array
    {
        self::ensureSchema();
        $sql = 'SELECT id, title, slug, status, kind, part, updated_at, published_at FROM pageforge_pages
             WHERE tenant_id = ?';
        $params = [self::tenantId()];
        if ($kind !== null && in_array($kind, ['page', 'part', 'popup', 'global', 'loop-item', 'template'], true)) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }
        $sql .= ' ORDER BY updated_at DESC';
        return Database::rows($sql, $params);
    }

    /** Save a (raw, untrusted) document as a new draft revision. Returns revision id. */
    public static function saveDocument(int $pageId, $document, int $userId, string $kind = 'manual'): int
    {
        $page = self::getPage($pageId);
        if ($page === null) {
            throw new RuntimeException('pageforge: page not found for current tenant');
        }
        $normalized = PageForgeRenderer::normalizeDocument($document);
        $normalized['version'] = self::DOCUMENT_VERSION;
        $pdo = self::pdo();
        $tid = self::tenantId();
        $next = (int) (Database::rows(
            'SELECT COALESCE(MAX(revision_number), 0) AS n FROM pageforge_revisions WHERE page_id = ?',
            [$pageId]
        )[0]['n'] ?? 0) + 1;

        $stmt = $pdo->prepare(
            'INSERT INTO pageforge_revisions (tenant_id, page_id, revision_number, revision_kind, document_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tid,
            $pageId,
            $next,
            in_array($kind, ['autosave', 'manual', 'publish', 'rollback', 'import'], true) ? $kind : 'manual',
            json_encode($normalized, JSON_UNESCAPED_SLASHES),
            $userId,
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        Database::query(
            'UPDATE pageforge_pages SET active_draft_revision_id = ?, updated_by = ? WHERE tenant_id = ? AND id = ?',
            [$revisionId, $userId, $tid, $pageId]
        );
        return $revisionId;
    }

    /** Decoded document of a revision, or the page's current draft when null. */
    public static function getDocument(int $pageId, ?int $revisionId = null): array
    {
        $page = self::getPage($pageId);
        if ($page === null) {
            return ['elements' => []];
        }
        $revId = $revisionId ?? (int) ($page['active_draft_revision_id'] ?? 0);
        if ($revId <= 0) {
            return ['elements' => []];
        }
        $rows = Database::rows(
            'SELECT document_json FROM pageforge_revisions WHERE tenant_id = ? AND page_id = ? AND id = ?',
            [self::tenantId(), $pageId, $revId]
        );
        if ($rows === []) {
            return ['elements' => []];
        }
        $decoded = json_decode((string) $rows[0]['document_json'], true);
        return is_array($decoded) ? $decoded : ['elements' => []];
    }

    /**
     * Phase 9 — revision history for one page, newest first (metadata only;
     * documents are fetched one at a time through getDocument()).
     */
    public static function listRevisions(int $pageId, int $limit = 50): array
    {
        if (self::getPage($pageId) === null) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        return Database::rows(
            'SELECT id, revision_number, revision_kind, created_by, created_at
             FROM pageforge_revisions
             WHERE tenant_id = ? AND page_id = ?
             ORDER BY revision_number DESC
             LIMIT ' . $limit,
            [self::tenantId(), $pageId]
        );
    }

    /**
     * Phase 9 — make a past revision the current draft. History is never
     * rewritten: the restored document is appended as a NEW revision of kind
     * 'rollback' (via saveDocument), so the pre-restore state stays
     * recoverable. Returns the new revision id.
     */
    public static function restoreRevision(int $pageId, int $revisionId, int $userId): int
    {
        $rows = Database::rows(
            'SELECT id FROM pageforge_revisions WHERE tenant_id = ? AND page_id = ? AND id = ?',
            [self::tenantId(), $pageId, $revisionId]
        );
        if ($rows === []) {
            throw new RuntimeException('pageforge: revision not found for this page');
        }
        $document = self::getDocument($pageId, $revisionId);
        return self::saveDocument($pageId, $document, $userId, 'rollback');
    }

    /** Wire the renderer's global-widget resolver to this tenant's globals. */
    public static function wireGlobalResolver(): void
    {
        PageForgeRenderer::setGlobalResolver(static function (int $globalId): ?array {
            $g = self::getPage($globalId);
            if ($g === null || ($g['kind'] ?? 'page') !== 'global') {
                return null;
            }
            return self::getDocument($globalId);
        });
    }

    /** Wire the renderer's slug+kind document resolver (loop templates, mega-menu panels). */
    public static function wireDocumentResolver(): void
    {
        PageForgeRenderer::setDocumentResolver(static function (string $slug, string $kind): ?array {
            if (!in_array($kind, ['loop-item', 'part'], true)) {
                return null;
            }
            $page = self::getPageBySlug($slug);
            if ($page === null || (string) ($page['kind'] ?? '') !== $kind) {
                return null;
            }
            return self::getDocument((int) $page['id']);
        });
    }

    /** Wire the Phase 6 kit (design system) into the CSS compiler. */
    public static function wireKit(): void
    {
        $kit = PageForgeKit::current();
        PageForgeCss::setKit($kit);
        PageForgeCss::setBreakpoints(
            (int) ($kit['breakpoints']['tablet'] ?? PageForgeCss::BREAKPOINT_TABLET),
            (int) ($kit['breakpoints']['mobile'] ?? PageForgeCss::BREAKPOINT_MOBILE)
        );
    }

    /** Recompile every published page (a kit save reaches live pages at once). */
    public static function recompileAllPublished(int $userId): int
    {
        self::ensureSchema();
        $rows = Database::rows(
            "SELECT id FROM pageforge_pages WHERE tenant_id = ? AND status = 'published'",
            [self::tenantId()]
        );
        foreach ($rows as $row) {
            self::publish((int) $row['id'], $userId);
        }
        return count($rows);
    }

    /** Compile the draft and mark it published. Returns the compiled pair. */
    public static function publish(int $pageId, int $userId): array
    {
        self::ensureSchema();
        $page = self::getPage($pageId);
        if ($page === null) {
            throw new RuntimeException('pageforge: page not found for current tenant');
        }
        self::wireGlobalResolver();
        self::wireDocumentResolver();
        self::wireKit();
        $document = self::getDocument($pageId);
        // Phase 11: publish resolves dynamic tags against this page.
        PageForgeRenderer::setPageMeta(['id' => $pageId, 'title' => (string) ($page['title'] ?? ''), 'slug' => (string) ($page['slug'] ?? ''), 'kind' => (string) ($page['kind'] ?? 'page')]);
        try {
            $compiled = PageForgeRenderer::compile($document, $pageId);
        } finally {
            PageForgeRenderer::setPageMeta(null);
        }
        $revId = (int) ($page['active_draft_revision_id'] ?? 0);
        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO pageforge_compilations (tenant_id, page_id, revision_id, html, css)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE revision_id = VALUES(revision_id), html = VALUES(html), css = VALUES(css), compiled_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([self::tenantId(), $pageId, $revId, $compiled['html'], $compiled['css']]);
        Database::query(
            "UPDATE pageforge_pages SET status = 'published', published_revision_id = ?, published_at = NOW(), updated_by = ?
             WHERE tenant_id = ? AND id = ?",
            [$revId, $userId, self::tenantId(), $pageId]
        );
        if (($page['kind'] ?? 'page') === 'global') {
            self::recompileDependents($pageId, $userId);
        }
        $kind = (string) ($page['kind'] ?? 'page');
        if ($kind === 'loop-item' || $kind === 'part') {
            self::recompileSlugDependents($pageId, (string) $page['slug'], $userId);
        }
        return $compiled;
    }

    /**
     * Recompile every published page whose draft references this global, so
     * a global edit reaches live pages on the global's own publish — the
     * referencing pages do not need a manual re-publish.
     */
    private static function recompileDependents(int $globalId, int $userId): void
    {
        $needle = '"global_id":' . $globalId;
        $rows = Database::rows(
            "SELECT p.id FROM pageforge_pages p
             JOIN pageforge_revisions r ON r.id = p.active_draft_revision_id
             WHERE p.tenant_id = ? AND p.id <> ? AND p.status = 'published' AND r.document_json LIKE ?",
            [self::tenantId(), $globalId, '%' . $needle . '%']
        );
        foreach ($rows as $row) {
            self::publish((int) $row['id'], $userId);
        }
    }

    /**
     * Recompile published pages that reference this slug as a loop template
     * or mega-menu panel — the Phase 5 analogue of global dependents, keyed
     * by slug because templates/panels are referenced by slug. Depth-guarded
     * against reference cycles (a part whose panel is another part, ...).
     */
    private static function recompileSlugDependents(int $selfId, string $slug, int $userId): void
    {
        if ($slug === '' || self::$slugRecompileDepth >= 3) {
            return;
        }
        $rows = Database::rows(
            "SELECT p.id FROM pageforge_pages p
             JOIN pageforge_revisions r ON r.id = p.active_draft_revision_id
             WHERE p.tenant_id = ? AND p.id <> ? AND p.status = 'published'
               AND (r.document_json LIKE ? OR r.document_json LIKE ?)",
            [self::tenantId(), $selfId, '%"template_slug":"' . $slug . '"%', '%"panel_slug":"' . $slug . '"%']
        );
        if ($rows === []) {
            return;
        }
        self::$slugRecompileDepth++;
        try {
            foreach ($rows as $row) {
                self::publish((int) $row['id'], $userId);
            }
        } finally {
            self::$slugRecompileDepth--;
        }
    }

    /** Normalized display conditions (W3) stored on a page row. */
    public static function pageConditions(array $page): array
    {
        $raw = $page['conditions_json'] ?? null;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return PageForgeDisplayConditions::normalize($decoded);
    }

    /** Persist display conditions for a part/popup page; returns the normalized spec. */
    public static function setConditions(int $pageId, $spec): array
    {
        if (self::getPage($pageId) === null) {
            throw new RuntimeException('pageforge: page not found for current tenant');
        }
        $normalized = PageForgeDisplayConditions::normalize($spec);
        Database::query(
            'UPDATE pageforge_pages SET conditions_json = ? WHERE tenant_id = ? AND id = ?',
            [json_encode($normalized, JSON_UNESCAPED_SLASHES), self::tenantId(), $pageId]
        );
        return $normalized;
    }

    /** Normalized popup configuration (W4) stored on a page row. */
    public static function popupConfig(array $page): array
    {
        $raw = $page['popup_json'] ?? null;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return PageForgePopupConfig::normalize($decoded);
    }

    /** Persist popup configuration; returns the normalized config. */
    public static function setPopupConfig(int $pageId, $config): array
    {
        if (self::getPage($pageId) === null) {
            throw new RuntimeException('pageforge: page not found for current tenant');
        }
        $normalized = PageForgePopupConfig::normalize($config);
        Database::query(
            'UPDATE pageforge_pages SET popup_json = ? WHERE tenant_id = ? AND id = ?',
            [json_encode($normalized, JSON_UNESCAPED_SLASHES), self::tenantId(), $pageId]
        );
        return $normalized;
    }

    /** Phase 11 — save this page's current draft as a reusable template. */
    public static function saveAsTemplate(int $pageId, string $title, int $userId): int
    {
        $page = self::getPage($pageId);
        if ($page === null) {
            throw new RuntimeException('pageforge: page not found for current tenant');
        }
        $templateId = self::createPage($title !== '' ? $title : (string) $page['title'] . ' (Template)', '', $userId, 'template');
        self::saveDocument($templateId, self::getDocument($pageId), $userId);
        return $templateId;
    }

    /** Phase 11 — append a template's elements to a page's draft (fresh element ids). */
    public static function insertTemplate(int $targetId, int $templateId, int $userId): array
    {
        $target = self::getPage($targetId);
        $template = self::getPage($templateId);
        if ($target === null || $template === null || ($template['kind'] ?? '') !== 'template') {
            throw new RuntimeException('pageforge: template or target not found for current tenant');
        }
        $doc = self::getDocument($targetId);
        $tpl = self::getDocument($templateId);
        $out = ['elements' => array_merge(
            is_array($doc['elements'] ?? null) ? $doc['elements'] : [],
            PageForgeLibrary::rekeyElements(is_array($tpl['elements'] ?? null) ? $tpl['elements'] : [])
        )];
        self::saveDocument($targetId, $out, $userId);
        return $out;
    }

    /** Phase 11 — the full export package for one page. */
    public static function exportPage(int $pageId): array
    {
        $page = self::getPage($pageId);
        if ($page === null) {
            throw new RuntimeException('pageforge: page not found for current tenant');
        }
        return PageForgeLibrary::buildPagePackage(
            $page,
            self::getDocument($pageId),
            self::pageConditions($page),
            ($page['kind'] ?? '') === 'popup' ? self::popupConfig($page) : null
        );
    }

    /** Phase 11 — create a new page from an export package; returns its id. */
    public static function importPage(array $package, int $userId): int
    {
        $parts = PageForgeLibrary::validatePagePackage($package);
        $newId = self::createPage($parts['title'], $parts['slug'], $userId, $parts['kind'], $parts['part']);
        self::saveDocument($newId, $parts['document'], $userId);
        if ($parts['conditions'] !== null) {
            self::setConditions($newId, $parts['conditions']);
        }
        if ($parts['popup'] !== null) {
            self::setPopupConfig($newId, $parts['popup']);
        }
        return $newId;
    }

    /**
     * Published theme part compilation — the newest published part whose
     * display conditions match the context (Phase 10). A null context
     * skips condition filtering entirely (the pre-Phase-10 behaviour).
     */
    public static function getPublishedPartFor(string $part, ?array $context): ?array
    {
        self::ensureSchema();
        $rows = Database::rows(
            "SELECT id FROM pageforge_pages WHERE tenant_id = ? AND kind = 'part' AND part = ? AND status = 'published'
             ORDER BY published_at DESC, id DESC",
            [self::tenantId(), $part]
        );
        foreach ($rows as $row) {
            $page = self::getPage((int) $row['id']);
            if ($page === null) {
                continue;
            }
            if ($context !== null && !PageForgeDisplayConditions::matches(self::pageConditions($page), $context)) {
                continue;
            }
            $comp = Database::rows(
                'SELECT html, css FROM pageforge_compilations WHERE tenant_id = ? AND page_id = ?',
                [self::tenantId(), (int) $row['id']]
            );
            if ($comp === []) {
                continue;
            }
            return ['page' => $page, 'html' => (string) $comp[0]['html'], 'css' => (string) $comp[0]['css']];
        }
        return null;
    }

    /** Published theme part compilation, if one exists (no context filter). */
    public static function getPublishedPart(string $part): ?array
    {
        return self::getPublishedPartFor($part, null);
    }

    /** All published popups of the tenant (rendered once, opened by trigger). */
    public static function listPublishedPopups(?array $context = null): array
    {
        self::ensureSchema();
        $out = [];
        foreach (self::listPages('popup') as $p) {
            if (($p['status'] ?? '') !== 'published') {
                continue;
            }
            $full = self::getPage((int) $p['id']);
            if ($full === null) {
                continue;
            }
            // Phase 10: a popup whose display conditions exclude this
            // context is not embedded on the page at all.
            if ($context !== null && !PageForgeDisplayConditions::matches(self::pageConditions($full), $context)) {
                continue;
            }
            $comp = Database::rows(
                'SELECT html, css FROM pageforge_compilations WHERE tenant_id = ? AND page_id = ?',
                [self::tenantId(), (int) $p['id']]
            );
            if ($comp !== []) {
                $out[] = [
                    'page' => $full,
                    'html' => (string) $comp[0]['html'],
                    'css' => (string) $comp[0]['css'],
                    'config' => self::popupConfig($full),
                    'conditions' => self::pageConditions($full),
                ];
            }
        }
        return $out;
    }

    /** Compiled output for the public runtime; null when not published. */
    public static function getPublished(string $slug): ?array
    {
        self::ensureSchema();
        $page = self::getPageBySlug($slug);
        if ($page === null || ($page['status'] ?? '') !== 'published') {
            return null;
        }
        $rows = Database::rows(
            'SELECT html, css FROM pageforge_compilations WHERE tenant_id = ? AND page_id = ?',
            [self::tenantId(), (int) $page['id']]
        );
        if ($rows === []) {
            return null;
        }
        return ['page' => $page, 'html' => (string) $rows[0]['html'], 'css' => (string) $rows[0]['css']];
    }

    private static function slugify(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? '', '-'));
        return $slug !== '' ? mb_substr($slug, 0, 180) : 'page';
    }

    private static function uniqueSlug(int $tenantId, string $slug): string
    {
        $base = mb_substr($slug, 0, 180);
        $candidate = $base;
        $i = 2;
        while (Database::rows(
            'SELECT id FROM pageforge_pages WHERE tenant_id = ? AND slug = ?',
            [$tenantId, $candidate]
        ) !== []) {
            $candidate = $base . '-' . $i;
            $i++;
        }
        return $candidate;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
