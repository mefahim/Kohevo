<?php
/**
 * PageForge dynamic tags + page library (Phase 11).
 *
 * PageForgeDynamic — the dynamic-tag registry. A tag is a namespaced
 * token usable in ANY widget's text settings (a heading's text, a
 * button's label, ...): {{site.name}}, {{page.title}}, {{date.today}},
 * {{kit.primary}}. The renderer resolves tags on every render — inside
 * loop fragments too — while plain {{field}} tokens keep their Phase 5
 * meaning (fragment-context fields). Site values come from the same
 * live site-settings sync as the Kit (no second copy); page values come
 * from the renderer's page metadata, which the store sets from the page
 * row at publish (and the preview API sets for the edited page).
 *
 * PageForgeLibrary — template + package helpers: recursive element
 * re-keying (inserted template content gets fresh ids so pf-el-*
 * classes never collide with the host document) and the page
 * export/import package format (pageforge-page v1), whose validation
 * is pure and shared by the store's import path.
 */

declare(strict_types=1);

require_once __DIR__ . '/PageForgeKit.php';
require_once __DIR__ . '/PageForgeDisplay.php';

final class PageForgeDynamic
{
    /** @return list<array{token: string, label: string}> */
    public static function tags(): array
    {
        return [
            ['token' => 'site.name', 'label' => 'Site name (Settings → General)'],
            ['token' => 'site.tagline', 'label' => 'Site tagline / brand sublabel'],
            ['token' => 'site.url', 'label' => 'Site URL'],
            ['token' => 'site.logo', 'label' => 'Site logo URL'],
            ['token' => 'page.title', 'label' => "This page's title"],
            ['token' => 'page.slug', 'label' => "This page's slug"],
            ['token' => 'page.url', 'label' => "This page's public URL"],
            ['token' => 'page.id', 'label' => "This page's ID"],
            ['token' => 'page.kind', 'label' => "This page's kind"],
            ['token' => 'date.today', 'label' => 'Today (Y-m-d)'],
            ['token' => 'date.year', 'label' => 'Current year'],
            ['token' => 'time.now', 'label' => 'Current time (H:i)'],
            ['token' => 'kit.primary', 'label' => 'Kit tokens (kit.* — primary, secondary, text, accent, background, link, button_background, button_text, font_heading, font_body)'],
        ];
    }

    private static function setting(string $key): ?string
    {
        if (!class_exists('Database') || !method_exists('Database', 'setting')) {
            return null;
        }
        try {
            $v = Database::setting($key);
        } catch (Throwable) {
            return null;
        }
        return $v === null ? null : (string) $v;
    }

    /**
     * Resolve one tag token; null when the token is not a known tag (the
     * renderer then applies its field/literal rules). Known tags with an
     * empty value resolve to ''.
     */
    public static function resolve(string $token, ?array $pageMeta): ?string
    {
        $siteUrl = defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '';
        switch ($token) {
            case 'site.name':
                return (string) (PageForgeKit::siteSync()['site_name'] ?? '');
            case 'site.tagline':
                return (string) (self::setting('brand_sublabel') ?? '');
            case 'site.url':
                return $siteUrl;
            case 'site.logo':
                $p = (string) (PageForgeKit::siteSync()['logo_path'] ?? '');
                if ($p === '') {
                    return '';
                }
                if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) {
                    return $p;
                }
                return $siteUrl . '/' . ltrim($p, '/');
            case 'page.title':
                return (string) ($pageMeta['title'] ?? '');
            case 'page.slug':
                return (string) ($pageMeta['slug'] ?? '');
            case 'page.kind':
                return (string) ($pageMeta['kind'] ?? '');
            case 'page.id':
                return isset($pageMeta['id']) ? (string) (int) $pageMeta['id'] : '';
            case 'page.url':
                $slug = (string) ($pageMeta['slug'] ?? '');
                return $slug === '' ? '' : $siteUrl . '/forge/' . $slug;
            case 'date.today':
                return date('Y-m-d');
            case 'date.year':
                return date('Y');
            case 'time.now':
                return date('H:i');
        }
        if (str_starts_with($token, 'kit.')) {
            return PageForgeKit::token(PageForgeKit::current(), substr($token, 4));
        }
        return null;
    }
}

final class PageForgeLibrary
{
    public const PACKAGE_FORMAT = 'pageforge-page';
    public const PACKAGE_VERSION = '1.0';
    public const KINDS = ['page', 'part', 'popup', 'global', 'loop-item', 'template'];
    public const PARTS = ['header', 'footer', 'single', 'archive', 'search', '404', 'section'];

    /** Recursively assign fresh element ids (insert collision guard). */
    public static function rekeyElements(array $elements): array
    {
        $out = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            if (isset($el['id'])) {
                $el['id'] = 'pf' . bin2hex(random_bytes(6));
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                $el['elements'] = self::rekeyElements($el['elements']);
            }
            $out[] = $el;
        }
        return $out;
    }

    /** Assemble a page export package (format pageforge-page v1). */
    public static function buildPagePackage(array $page, array $document, ?array $conditions = null, ?array $popup = null): array
    {
        return [
            'format' => self::PACKAGE_FORMAT,
            'version' => self::PACKAGE_VERSION,
            'exported_at' => gmdate('c'),
            'page' => [
                'title' => (string) ($page['title'] ?? ''),
                'slug' => (string) ($page['slug'] ?? ''),
                'kind' => (string) ($page['kind'] ?? 'page'),
                'part' => $page['part'] ?? null,
            ],
            'document' => PageForgeRenderer::normalizeDocument($document),
            'conditions' => $conditions === null ? null : PageForgeDisplayConditions::normalize($conditions),
            'popup' => $popup === null ? null : PageForgePopupConfig::normalize($popup),
        ];
    }

    /**
     * Validate + normalize an import package.
     *
     * @throws RuntimeException on any structural problem.
     */
    public static function validatePagePackage($data): array
    {
        if (!is_array($data) || ($data['format'] ?? '') !== self::PACKAGE_FORMAT) {
            throw new RuntimeException('pageforge: not a PageForge page package');
        }
        if (!str_starts_with((string) ($data['version'] ?? ''), '1.')) {
            throw new RuntimeException('pageforge: unsupported package version');
        }
        $page = is_array($data['page'] ?? null) ? $data['page'] : [];
        $title = trim((string) ($page['title'] ?? ''));
        if ($title === '' || !is_array($data['document'] ?? null)) {
            throw new RuntimeException('pageforge: package is missing its page or document');
        }
        $kind = (string) ($page['kind'] ?? 'page');
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'page';
        }
        $part = $page['part'] ?? null;
        if ($kind !== 'part') {
            $part = null;
        } elseif (!in_array($part, self::PARTS, true)) {
            $part = 'header';
        }
        return [
            'title' => $title,
            'slug' => (string) ($page['slug'] ?? ''),
            'kind' => $kind,
            'part' => $part,
            'document' => PageForgeRenderer::normalizeDocument($data['document']),
            'conditions' => isset($data['conditions']) && is_array($data['conditions'])
                ? PageForgeDisplayConditions::normalize($data['conditions']) : null,
            'popup' => isset($data['popup']) && is_array($data['popup'])
                ? PageForgePopupConfig::normalize($data['popup']) : null,
        ];
    }
}
