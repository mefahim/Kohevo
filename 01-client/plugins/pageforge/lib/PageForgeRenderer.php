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
            $html .= ($def['render'])($el['settings'] ?? [], $children, $el);
        }
        return $html;
    }

    /** @return array{html: string, css: string} */
    public static function compile(array $document, int $pageId): array
    {
        return [
            'html' => self::render($document, $pageId),
            'css' => PageForgeCss::compile($document['elements'] ?? [], $pageId),
        ];
    }
}
