<?php
/**
 * PageForge renderer — element tree → HTML.
 *
 * Pure and deterministic: the same document always compiles to the same
 * HTML/CSS pair, which is what makes the compilations cache safe.
 * Unknown widget types render as an HTML comment (never a fatal, never
 * silently dropped content in the editor preview the node is marked with
 * a data attribute so Phase 2's canvas can flag it).
 */

declare(strict_types=1);

require_once __DIR__ . '/PageForgeWidgets.php';
require_once __DIR__ . '/PageForgeCss.php';
require_once __DIR__ . '/PageForgeLibrary.php';

final class PageForgeRenderer
{
    /** Validate + normalize a decoded document into a canonical element list. */
    public static function normalizeDocument($document): array
    {
        if (is_string($document)) {
            $decoded = json_decode($document, true);
            $document = is_array($decoded) ? $decoded : [];
        }
        $elements = is_array($document['elements'] ?? null) ? $document['elements'] : [];
        return ['elements' => self::normalizeElements($elements)];
    }

    private static function normalizeElements(array $elements): array
    {
        $out = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $elType = (string) ($el['elType'] ?? '');
            if ($elType === 'container') {
                $type = 'container';
            } elseif ($elType === 'widget') {
                $type = (string) ($el['widgetType'] ?? '');
                if (!PageForgeWidgets::exists($type) || $type === 'container') {
                    continue; // unknown widget: dropped from the canonical document at save time
                }
            } else {
                continue;
            }
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($el['id'] ?? ''));
            if ($id === '') {
                $id = 'pf' . bin2hex(random_bytes(6));
            }
            $node = [
                'id' => $id,
                'elType' => $type === 'container' ? 'container' : 'widget',
                'settings' => PageForgeWidgets::sanitizeSettings($type, is_array($el['settings'] ?? null) ? $el['settings'] : []),
                'elements' => [],
            ];
            if ($type !== 'container') {
                $node['widgetType'] = $type;
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                $node['elements'] = self::normalizeElements($el['elements']);
            }
            $out[] = $node;
        }
        return $out;
    }

    /** Render a normalized document to its HTML fragment. */
    public static function render(array $document, int $pageId): string
    {
        $html = self::renderElements($document['elements'] ?? []);
        return '<div class="pf-page pf-page-' . $pageId . '">' . $html . '</div>';
    }

    private static function renderElements(array $elements): string
    {
        $html = '';
        foreach ($elements as $el) {
            $type = (string) ($el['widgetType'] ?? 'container');
            $def = PageForgeWidgets::get($type);
            $children = self::renderElements(is_array($el['elements'] ?? null) ? $el['elements'] : []);
            if ($def === null) {
                $html .= '<!-- pageforge: unknown widget ' . PageForgeWidgets::esc($type) . ' -->';
                continue;
            }
            $settings = is_array($el['settings'] ?? null) ? $el['settings'] : [];
            // Phase 11: settings are always bound — dynamic tags resolve
            // in every render; plain {{field}} tokens additionally resolve
            // from the active fragment context (loops) when one is set.
            $settings = self::bindSettings($settings, self::$context ?? []);
            $html .= ($def['render'])($settings, $children, $el);
        }
        return $html;
    }

    /** @var null|callable(int): ?array resolves a global page id to its document */
    private static $globalResolver = null;

    public static function setGlobalResolver(?callable $resolver): void
    {
        self::$globalResolver = $resolver;
    }

    /** @var null|callable(string, string): ?array resolves a slug+kind to a document */
    private static $documentResolver = null;

    /** @var null|array<string, mixed> active dynamic-binding context */
    private static ?array $context = null;

    /** @var null|array<string, mixed> host-page metadata for dynamic tags (Phase 11) */
    private static ?array $pageMeta = null;

    /** Set by the store at publish (and the preview API) so {{page.*}} tags resolve. */
    public static function setPageMeta(?array $meta): void
    {
        self::$pageMeta = $meta;
    }

    public static function setDocumentResolver(?callable $resolver): void
    {
        self::$documentResolver = $resolver;
    }

    /** Document for a slug+kind via the wired resolver; null when unavailable. */
    public static function resolveDocument(string $slug, string $kind): ?array
    {
        if (self::$documentResolver === null || $slug === '') {
            return null;
        }
        try {
            $doc = (self::$documentResolver)($slug, $kind);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($doc) ? $doc : null;
    }

    /**
     * Render a document fragment under a dynamic-binding context: string
     * settings containing {{field}} tokens are substituted from $context
     * before each widget renders (widgets keep escaping as usual). The
     * previous context is restored afterwards, so loops nest safely.
     */
    public static function renderFragment(array $document, ?array $context = null): string
    {
        $prev = self::$context;
        if ($context !== null) {
            self::$context = $context;
        }
        try {
            return self::renderElements(is_array($document['elements'] ?? null) ? $document['elements'] : []);
        } finally {
            self::$context = $prev;
        }
    }

    /** Substitute {{field}} tokens in every string value of a settings array. */
    public static function bindSettings(array $settings, array $context): array
    {
        $out = [];
        foreach ($settings as $key => $value) {
            $out[$key] = self::bindValue($value, $context);
        }
        return $out;
    }

    private static function bindValue($value, array $context)
    {
        if (is_string($value) && str_contains($value, '{{')) {
            return preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_]+(?:[.:][a-zA-Z0-9_%-]+)*)\s*\}\}/',
                static function (array $m) use ($context): string {
                    if (array_key_exists($m[1], $context)) {
                        $v = $context[$m[1]];
                        return is_scalar($v) ? (string) $v : '';
                    }
                    $dyn = PageForgeDynamic::resolve($m[1], self::$pageMeta);
                    if ($dyn !== null) {
                        return $dyn;
                    }
                    // Inside a fragment context an unknown field resolves
                    // to empty (Phase 5 rule); outside one, the literal
                    // token survives so plain content is never eaten.
                    return self::$context !== null ? '' : $m[0];
                },
                $value
            ) ?? $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::bindValue($v, $context);
            }
            return $out;
        }
        return $value;
    }

    /**
     * CSS-side expansion: loop templates and mega-menu panels live in other
     * documents, so their elements never appear in the host tree the CSS
     * compiler walks. Append them (depth-guarded) as synthetic containers so
     * their pf-el rules are generated once, scoped to the host page. The
     * HTML render is unaffected (it uses the original document).
     */
    private static function expandLinkedForCss(array $document): array
    {
        if (empty($document['elements']) || !is_array($document['elements']) || self::$documentResolver === null) {
            return $document;
        }
        [, $synthetic] = self::collectLinkedCss($document['elements'], 0);
        if ($synthetic !== []) {
            $document['elements'] = array_merge($document['elements'], $synthetic);
        }
        return $document;
    }

    /** @return array{0: array, 1: array} [elements unchanged, synthetic containers to append] */
    private static function collectLinkedCss(array $elements, int $depth): array
    {
        $synthetic = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                [, $childSynthetic] = self::collectLinkedCss($el['elements'], $depth);
                $synthetic = array_merge($synthetic, $childSynthetic);
            }
            if ($depth >= 2) {
                continue;
            }
            $type = (string) ($el['widgetType'] ?? '');
            $settings = is_array($el['settings'] ?? null) ? $el['settings'] : [];
            $docs = [];
            if ($type === 'loop') {
                $slug = trim((string) ($settings['template_slug'] ?? ''));
                if ($slug !== '') {
                    $doc = self::resolveDocument($slug, 'loop-item');
                    if ($doc !== null) {
                        $docs[] = $doc;
                    }
                }
            } elseif ($type === 'mega-menu') {
                foreach ((is_array($settings['items'] ?? null) ? $settings['items'] : []) as $item) {
                    $slug = is_array($item) ? trim((string) ($item['panel_slug'] ?? '')) : '';
                    if ($slug !== '') {
                        $doc = self::resolveDocument($slug, 'part');
                        if ($doc !== null) {
                            $docs[] = $doc;
                        }
                    }
                }
            }
            foreach ($docs as $i => $doc) {
                $inner = is_array($doc['elements'] ?? null) ? $doc['elements'] : [];
                [, $innerSynthetic] = self::collectLinkedCss($inner, $depth + 1);
                $synthetic[] = [
                    'id' => 'pf-linked-' . $depth . '-' . count($synthetic) . '-' . $i,
                    'elType' => 'container',
                    'widgetType' => 'container',
                    'settings' => [],
                    'elements' => $inner,
                ];
                $synthetic = array_merge($synthetic, $innerSynthetic);
            }
        }
        return [$elements, $synthetic];
    }

    /**
     * Replace global-widget nodes with the referenced global document's
     * elements, wrapped in a container carrying the referencing element's
     * identity — so both the HTML render and the CSS compiler see the
     * expanded tree. Depth-guarded against global cycles; an unresolvable
     * reference stays a global-widget node (its render is a placeholder).
     */
    public static function expandGlobals(array $document, int $depth = 0): array
    {
        if ($depth >= 3 || self::$globalResolver === null || empty($document['elements']) || !is_array($document['elements'])) {
            return $document;
        }
        $expand = function (array $elements) use (&$expand, $depth): array {
            $out = [];
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                if (($el['elType'] ?? '') === 'widget' && ($el['widgetType'] ?? '') === 'global-widget') {
                    $gid = (int) ($el['settings']['global_id'] ?? 0);
                    $gdoc = $gid > 0 ? (self::$globalResolver)($gid) : null;
                    if (is_array($gdoc) && !empty($gdoc['elements']) && is_array($gdoc['elements'])) {
                        $inner = self::expandGlobals($gdoc, $depth + 1);
                        $out[] = [
                            'id' => (string) ($el['id'] ?? ''),
                            'elType' => 'container',
                            'settings' => is_array($el['settings'] ?? null) ? $el['settings'] : [],
                            'elements' => $inner['elements'],
                            'widgetType' => 'container',
                        ];
                        continue;
                    }
                }
                if (!empty($el['elements']) && is_array($el['elements'])) {
                    $el['elements'] = $expand($el['elements']);
                }
                $out[] = $el;
            }
            return $out;
        };
        $document['elements'] = $expand($document['elements']);
        return $document;
    }

    /** @return array{html: string, css: string} */
    public static function compile(array $document, int $pageId): array
    {
        $document = self::expandGlobals($document);
        $cssDocument = self::expandLinkedForCss($document);
        return [
            'html' => self::render($document, $pageId),
            'css' => PageForgeCss::compile($cssDocument['elements'] ?? [], $pageId),
        ];
    }
}
