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

final class PageForgeStore
{
    private static function pdo(): PDO
    {
        return Database::get();
    }

    private static function tenantId(): int
    {
        return current_tenant_id();
    }

    public static function createPage(string $title, string $slug, int $userId): int
    {
        $pdo = self::pdo();
        $tid = self::tenantId();
        $slug = self::uniqueSlug($tid, $slug !== '' ? $slug : self::slugify($title));
        $stmt = $pdo->prepare(
            "INSERT INTO pageforge_pages (tenant_id, uuid, title, slug, status, created_by, updated_by)
             VALUES (?, ?, ?, ?, 'draft', ?, ?)"
        );
        $stmt->execute([$tid, self::uuid(), mb_substr($title, 0, 255), $slug, $userId, $userId]);
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
    public static function listPages(): array
    {
        return Database::rows(
            'SELECT id, title, slug, status, updated_at, published_at FROM pageforge_pages
             WHERE tenant_id = ? ORDER BY updated_at DESC',
            [self::tenantId()]
        );
    }

    /** Save a (raw, untrusted) document as a new draft revision. Returns revision id. */
    public static function saveDocument(int $pageId, $document, int $userId, string $kind = 'manual'): int
    {
        $page = self::getPage($pageId);
        if ($page === null) {
            throw new RuntimeException('pageforge: page not found for current tenant');
        }
        $normalized = PageForgeRenderer::normalizeDocument($document);
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

    /** Compile the draft and mark it published. Returns the compiled pair. */
    public static function publish(int $pageId, int $userId): array
    {
        $page = self::getPage($pageId);
        if ($page === null) {
            throw new RuntimeException('pageforge: page not found for current tenant');
        }
        $document = self::getDocument($pageId);
        $compiled = PageForgeRenderer::compile($document, $pageId);
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
        return $compiled;
    }

    /** Compiled output for the public runtime; null when not published. */
    public static function getPublished(string $slug): ?array
    {
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
