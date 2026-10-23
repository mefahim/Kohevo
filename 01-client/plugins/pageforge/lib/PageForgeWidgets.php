<?php
/**
 * PageForge widget registry — Phase 1 foundation set.
 *
 * Each widget definition carries:
 *   - label / icon (editor display, Phase 2 canvas)
 *   - controls: the settings schema. Every control declared here is either a
 *     content control consumed by the widget's render callback, or a style
 *     control consumed by PageForgeCss. A control that does neither is a bug
 *     (the Phase 3 audit exists to prove none exist).
 *   - render: callable(array $settings, string $childrenHtml, array $element): string
 *
 * Control types in v1: text, textarea, url, number, select, color, dimensions,
 * media. Style controls are shared (PageForgeCss::styleControls()) and merged
 * into every widget; widget-local style controls may extend them.
 *
 * Original code, written for Kohevo. The element/settings model follows the
 * same public shape every modern builder uses; no third-party code is copied.
 */

declare(strict_types=1);

final class PageForgeWidgets
{
    /** @var array<string, array>|null */
    private static ?array $widgets = null;

    /** @return array<string, array> widgetType => definition */
    public static function all(): array
    {
        if (self::$widgets !== null) {
            return self::$widgets;
        }

        $text = ['type' => 'text'];
        $self = [];

        $self['container'] = [
            'label' => 'Container',
            'icon' => 'layout',
            'is_container' => true,
            'controls' => [
                'html_tag' => ['type' => 'select', 'options' => ['div', 'section', 'article', 'main', 'header', 'footer'], 'default' => 'div'],
            ],
            'style_controls' => ['flex_direction', 'justify_content', 'align_items', 'gap', 'width', 'min_height',
                'background_color', 'padding', 'margin', 'border_radius'],
            'render' => static function (array $s, string $children, array $el): string {
                $tag = (string) ($s['html_tag'] ?? 'div');
                if (!in_array($tag, ['div', 'section', 'article', 'main', 'header', 'footer'], true)) $tag = 'div';
                return '<' . $tag . ' class="pf-container ' . self::elClass($el) . '">' . $children . '</' . $tag . '>';
            },
        ];

        $self['heading'] = [
            'label' => 'Heading',
            'icon' => 'heading',
            'controls' => [
                'text' => ['type' => 'textarea', 'default' => 'Heading'],
                'html_tag' => ['type' => 'select', 'options' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'], 'default' => 'h2'],
                'link_url' => ['type' => 'url'],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                $tag = (string) ($s['html_tag'] ?? 'h2');
                if (!in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'], true)) $tag = 'h2';
                $inner = self::esc((string) ($s['text'] ?? ''));
                if (!empty($s['link_url'])) {
                    $inner = '<a href="' . self::escUrl((string) $s['link_url']) . '">' . $inner . '</a>';
                }
                return '<' . $tag . ' class="pf-heading ' . self::elClass($el) . '">' . $inner . '</' . $tag . '>';
            },
        ];

        $self['text'] = [
            'label' => 'Text',
            'icon' => 'text',
            'controls' => [
                // Rich text is stored as author HTML and passed through the
                // platform's HTML policy at save time (PageForgeStore);
                // the renderer escapes nothing here by design of that gate.
                'text' => ['type' => 'textarea', 'allow_html' => true, 'default' => ''],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                return '<div class="pf-text ' . self::elClass($el) . '">' . (string) ($s['text'] ?? '') . '</div>';
            },
        ];

        $self['button'] = [
            'label' => 'Button',
            'icon' => 'button',
            'controls' => [
                'text' => $text + ['default' => 'Click here'],
                'link_url' => ['type' => 'url'],
                'size' => ['type' => 'select', 'options' => ['sm', 'md', 'lg'], 'default' => 'md'],
            ],
            'style_controls' => ['background_color', 'color', 'border_radius', 'padding'],
            'render' => static function (array $s, string $children, array $el): string {
                $size = (string) ($s['size'] ?? 'md');
                if (!in_array($size, ['sm', 'md', 'lg'], true)) $size = 'md';
                $href = !empty($s['link_url']) ? self::escUrl((string) $s['link_url']) : '#';
                return '<a class="pf-button pf-button--' . $size . ' ' . self::elClass($el) . '" href="' . $href . '">'
                    . self::esc((string) ($s['text'] ?? '')) . '</a>';
            },
        ];

        $self['image'] = [
            'label' => 'Image',
            'icon' => 'image',
            'controls' => [
                'src' => ['type' => 'media'],
                'alt' => $text,
                'link_url' => ['type' => 'url'],
            ],
            'style_controls' => ['width', 'border_radius'],
            'render' => static function (array $s, string $children, array $el): string {
                if (empty($s['src'])) {
                    return '';
                }
                $img = '<img class="' . self::elClass($el) . '" src="' . self::escUrl((string) $s['src'])
                    . '" alt="' . self::esc((string) ($s['alt'] ?? '')) . '" loading="lazy">';
                if (!empty($s['link_url'])) {
                    return '<a href="' . self::escUrl((string) $s['link_url']) . '">' . $img . '</a>';
                }
                return $img;
            },
        ];

        $self['divider'] = [
            'label' => 'Divider',
            'icon' => 'divider',
            'controls' => [],
            'style_controls' => ['color', 'margin'],
            'render' => static fn (array $s, string $children, array $el): string =>
                '<hr class="pf-divider ' . self::elClass($el) . '">',
        ];

        $self['spacer'] = [
            'label' => 'Spacer',
            'icon' => 'spacer',
            'controls' => [
                'height' => ['type' => 'number', 'default' => 40, 'min' => 0, 'max' => 1000],
            ],
            'controls_are_style' => ['height' => 'height'], // content control rendered as inline style prop
            'render' => static fn (array $s, string $children, array $el): string =>
                '<div class="pf-spacer ' . self::elClass($el) . '" aria-hidden="true"></div>',
        ];

        self::$widgets = $self;
        return self::$widgets;
    }

    public static function get(string $widgetType): ?array
    {
        return self::all()[$widgetType] ?? null;
    }

    public static function exists(string $widgetType): bool
    {
        return isset(self::all()[$widgetType]);
    }

    /**
     * Sanitize a settings array against a widget's declared controls plus the
     * shared style controls. Unknown keys are dropped; values are coerced to
     * their control type. This is the single save-time gate for author input.
     */
    public static function sanitizeSettings(string $widgetType, array $settings): array
    {
        $def = self::get($widgetType);
        if ($def === null) {
            return [];
        }
        $schema = ($def['controls'] ?? []);
        foreach (PageForgeCss::styleControls() as $key => $c) {
            $schema[$key] = $c;
        }
        // Responsive variants (_tablet/_mobile) of size-like controls.
        $out = [];
        foreach ($settings as $key => $value) {
            $baseKey = preg_replace('/_(tablet|mobile)$/', '', (string) $key);
            $control = $schema[$baseKey] ?? null;
            if ($control === null) {
                continue;
            }
            $out[$key] = self::sanitizeValue($control, $value);
        }
        return $out;
    }

    private static function sanitizeValue(array $control, $value)
    {
        switch ($control['type']) {
            case 'number':
                $n = is_numeric($value) ? (float) $value : (float) ($control['default'] ?? 0);
                if (isset($control['min'])) $n = max((float) $control['min'], $n);
                if (isset($control['max'])) $n = min((float) $control['max'], $n);
                return $n == (int) $n ? (int) $n : $n;
            case 'select':
                return in_array($value, $control['options'] ?? [], true) ? $value : ($control['default'] ?? ($control['options'][0] ?? ''));
            case 'color':
                $v = trim((string) $value);
                return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) ? strtolower($v) : '';
            case 'url':
            case 'media':
                $v = trim((string) $value);
                if ($v === '') return '';
                return self::isSafeUrl($v) ? $v : '';
            case 'dimensions':
                if (!is_array($value)) return [];
                $out = [];
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $out[$side] = is_numeric($value[$side] ?? null) ? (float) $value[$side] : 0;
                }
                $out['unit'] = in_array($value['unit'] ?? 'px', ['px', '%', 'rem', 'em'], true) ? $value['unit'] : 'px';
                return $out;
            case 'textarea':
                return (string) $value; // HTML policy applied by the store for allow_html controls.
            default: // text
                return mb_substr(trim((string) $value), 0, 2000);
        }
    }

    public static function isSafeUrl(string $url): bool
    {
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    public static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    public static function escUrl(string $v): string
    {
        return self::isSafeUrl($v) ? self::esc($v) : '#';
    }

    public static function elClass(array $el): string
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($el['id'] ?? ''));
        return 'pf-el-' . $id;
    }
}
