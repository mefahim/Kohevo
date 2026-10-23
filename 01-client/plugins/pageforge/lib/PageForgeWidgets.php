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

require_once __DIR__ . '/PageForgeIcons.php';

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

        self::$widgets = array_merge($self, self::phase2Widgets());
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
            case 'repeater':
                if (!is_array($value)) return [];
                $itemSchema = $control['item_schema'] ?? [];
                $max = (int) ($control['max_items'] ?? 30);
                $items = [];
                foreach (array_slice(array_values($value), 0, max(0, $max)) as $item) {
                    if (!is_array($item)) continue;
                    $clean = [];
                    foreach ($itemSchema as $field => $fieldControl) {
                        if (array_key_exists($field, $item)) {
                            $clean[$field] = self::sanitizeValue($fieldControl, $item[$field]);
                        }
                    }
                    $items[] = $clean;
                }
                return $items;
            case 'raw_html':
                // Trusted-editor HTML (html widget). Conservative strip: no
                // script/iframe/object/embed/form controls, no event
                // handlers, no javascript:/data: URLs in href/src.
                $html = (string) $value;
                $html = preg_replace('#<(script|iframe|object|embed|form|input|button|link|meta|style)[^>]*>.*?</\1>#is', '', $html) ?? '';
                $html = preg_replace('#<(script|iframe|object|embed|form|input|button|link|meta|style)[^>]*/?>#is', '', $html) ?? '';
                $html = preg_replace("/\\son\\w+\\s*=\\s*(\"[^\"]*\"|'[^']*'|[^\\s>]+)/i", '', $html) ?? '';
                $html = preg_replace("/(href|src)\\s*=\\s*(\"|')\\s*(javascript|data):[^\"']*\\2/i", '$1=$2#$2', $html) ?? '';
                return mb_substr($html, 0, 20000);
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

/**
     * Phase 2 widget set (SPEC catalog): the basic widgets every page
     * builder ships. All rendering is server-side and JS-free on the public
     * page (tabs use the radio technique, accordion/toggle use <details>).
     *
     * @return array<string, array>
     */
    private static function phase2Widgets(): array
    {
        $text = ['type' => 'text'];
        $w = [];

        $iconControl = ['type' => 'select', 'options' => PageForgeIcons::names(), 'default' => 'star'];
        $imgItem = [
            'src' => ['type' => 'media'],
            'caption' => $text,
            'link_url' => ['type' => 'url'],
        ];
        $tabItem = [
            'title' => $text + ['default' => 'Tab'],
            'content' => ['type' => 'textarea', 'default' => ''],
        ];

        $w['icon'] = [
            'label' => 'Icon', 'icon' => 'star',
            'controls' => [
                'icon_name' => $iconControl,
                'link_url' => ['type' => 'url'],
            ],
            'style_controls' => ['color', 'font_size'],
            'render' => static function (array $s, string $children, array $el): string {
                $svg = PageForgeIcons::svg((string) ($s['icon_name'] ?? 'star'));
                if ($svg === '') return '';
                $html = '<span class="pf-icon ' . self::elClass($el) . '">' . $svg . '</span>';
                if (!empty($s['link_url'])) {
                    $html = '<a href="' . self::escUrl((string) $s['link_url']) . '">' . $html . '</a>';
                }
                return $html;
            },
        ];

        $w['image-box'] = [
            'label' => 'Image Box', 'icon' => 'image',
            'controls' => [
                'src' => ['type' => 'media'],
                'alt' => $text,
                'title' => $text + ['default' => 'Image box title'],
                'description' => ['type' => 'textarea', 'default' => ''],
                'link_url' => ['type' => 'url'],
            ],
            'style_controls' => ['text_align', 'border_radius'],
            'render' => static function (array $s, string $children, array $el): string {
                $inner = '';
                if (!empty($s['src'])) {
                    $inner .= '<img src="' . self::escUrl((string) $s['src']) . '" alt="' . self::esc((string) ($s['alt'] ?? '')) . '" loading="lazy">';
                }
                $inner .= '<h3>' . self::esc((string) ($s['title'] ?? '')) . '</h3>';
                if (!empty($s['description'])) {
                    $inner .= '<p>' . nl2br(self::esc((string) $s['description'])) . '</p>';
                }
                if (!empty($s['link_url'])) {
                    $inner = '<a href="' . self::escUrl((string) $s['link_url']) . '">' . $inner . '</a>';
                }
                return '<figure class="pf-image-box ' . self::elClass($el) . '">' . $inner . '</figure>';
            },
        ];

        $w['icon-box'] = [
            'label' => 'Icon Box', 'icon' => 'star',
            'controls' => [
                'icon_name' => $iconControl,
                'title' => $text + ['default' => 'Icon box title'],
                'description' => ['type' => 'textarea', 'default' => ''],
                'link_url' => ['type' => 'url'],
            ],
            'style_controls' => ['text_align', 'color'],
            'render' => static function (array $s, string $children, array $el): string {
                $inner = '<span class="pf-icon-box__icon">' . PageForgeIcons::svg((string) ($s['icon_name'] ?? 'star')) . '</span>'
                    . '<h3>' . self::esc((string) ($s['title'] ?? '')) . '</h3>';
                if (!empty($s['description'])) {
                    $inner .= '<p>' . nl2br(self::esc((string) $s['description'])) . '</p>';
                }
                if (!empty($s['link_url'])) {
                    $inner = '<a href="' . self::escUrl((string) $s['link_url']) . '">' . $inner . '</a>';
                }
                return '<div class="pf-icon-box ' . self::elClass($el) . '">' . $inner . '</div>';
            },
        ];

        $w['icon-list'] = [
            'label' => 'Icon List', 'icon' => 'list',
            'controls' => [
                'items' => ['type' => 'repeater', 'max_items' => 30, 'item_schema' => [
                    'icon_name' => $iconControl,
                    'text' => $text + ['default' => 'List item'],
                    'link_url' => ['type' => 'url'],
                ]],
            ],
            'style_controls' => ['color', 'font_size'],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                if ($items === []) return '';
                $html = '<ul class="pf-icon-list ' . self::elClass($el) . '">';
                foreach ($items as $item) {
                    $label = PageForgeIcons::svg((string) ($item['icon_name'] ?? 'check'))
                        . '<span>' . self::esc((string) ($item['text'] ?? '')) . '</span>';
                    if (!empty($item['link_url'])) {
                        $label = '<a href="' . self::escUrl((string) $item['link_url']) . '">' . $label . '</a>';
                    }
                    $html .= '<li>' . $label . '</li>';
                }
                return $html . '</ul>';
            },
        ];

        $w['video'] = [
            'label' => 'Video', 'icon' => 'play',
            'controls' => [
                'source' => ['type' => 'select', 'options' => ['youtube', 'vimeo', 'file'], 'default' => 'youtube'],
                'url' => ['type' => 'url'],
            ],
            'style_controls' => ['border_radius'],
            'render' => static function (array $s, string $children, array $el): string {
                $url = (string) ($s['url'] ?? '');
                if ($url === '') return '';
                $source = (string) ($s['source'] ?? 'youtube');
                if ($source === 'file') {
                    if (!self::isSafeUrl($url) || !preg_match('/\.(mp4|webm)(\?.*)?$/i', $url)) return '';
                    return '<div class="pf-video ' . self::elClass($el) . '"><video controls preload="metadata" src="' . self::escUrl($url) . '"></video></div>';
                }
                $embed = '';
                if ($source === 'youtube' && preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $url, $m)) {
                    $embed = 'https://www.youtube-nocookie.com/embed/' . $m[1];
                }
                if ($source === 'vimeo' && preg_match('~vimeo\.com/(\d{5,})~', $url, $m)) {
                    $embed = 'https://player.vimeo.com/video/' . $m[1];
                }
                if ($embed === '') return '';
                return '<div class="pf-video pf-video--embed ' . self::elClass($el) . '"><iframe src="' . self::esc($embed)
                    . '" loading="lazy" allow="accelerometer; autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Video"></iframe></div>';
            },
        ];

        $w['gallery'] = [
            'label' => 'Gallery', 'icon' => 'image',
            'controls' => [
                'items' => ['type' => 'repeater', 'max_items' => 30, 'item_schema' => $imgItem],
                'columns' => ['type' => 'number', 'default' => 3, 'min' => 1, 'max' => 6],
            ],
            'style_controls' => ['gap', 'border_radius'],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $items = array_values(array_filter($items, static fn ($i) => is_array($i) && !empty($i['src'])));
                if ($items === []) return '';
                $cols = max(1, min(6, (int) ($s['columns'] ?? 3)));
                $html = '<div class="pf-gallery ' . self::elClass($el) . '" style="--pf-gallery-cols:' . $cols . '">';
                foreach ($items as $item) {
                    $fig = '<img src="' . self::escUrl((string) $item['src']) . '" alt="' . self::esc((string) ($item['caption'] ?? '')) . '" loading="lazy">';
                    if (!empty($item['caption'])) {
                        $fig .= '<figcaption>' . self::esc((string) $item['caption']) . '</figcaption>';
                    }
                    if (!empty($item['link_url'])) {
                        $fig = '<a href="' . self::escUrl((string) $item['link_url']) . '">' . $fig . '</a>';
                    }
                    $html .= '<figure>' . $fig . '</figure>';
                }
                return $html . '</div>';
            },
        ];

        $w['image-carousel'] = [
            'label' => 'Image Carousel', 'icon' => 'image',
            'controls' => [
                'items' => ['type' => 'repeater', 'max_items' => 30, 'item_schema' => $imgItem],
            ],
            'style_controls' => ['border_radius'],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $items = array_values(array_filter($items, static fn ($i) => is_array($i) && !empty($i['src'])));
                if ($items === []) return '';
                $html = '<div class="pf-carousel ' . self::elClass($el) . '" role="region" aria-label="Image carousel">';
                foreach ($items as $item) {
                    $slide = '<img src="' . self::escUrl((string) $item['src']) . '" alt="' . self::esc((string) ($item['caption'] ?? '')) . '" loading="lazy">';
                    if (!empty($item['caption'])) {
                        $slide .= '<figcaption>' . self::esc((string) $item['caption']) . '</figcaption>';
                    }
                    $html .= '<figure class="pf-carousel__slide">' . $slide . '</figure>';
                }
                return $html . '</div>';
            },
        ];

        $w['tabs'] = [
            'label' => 'Tabs', 'icon' => 'tabs',
            'controls' => [
                'items' => ['type' => 'repeater', 'max_items' => 12, 'item_schema' => $tabItem],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                if ($items === []) return '';
                $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($el['id'] ?? 'tabs'));
                $labels = '';
                $panels = '';
                foreach ($items as $i => $item) {
                    $name = 'pf-tab-' . $uid;
                    $id = $name . '-' . $i;
                    $labels .= '<label for="' . $id . '">' . self::esc((string) ($item['title'] ?? '')) . '</label>';
                    $panels .= '<section class="pf-tabs__panel pf-tabs__panel--' . $i . '"><p>' . nl2br(self::esc((string) ($item['content'] ?? ''))) . '</p></section>';
                }
                $inputs = '';
                foreach ($items as $i => $item) {
                    $inputs .= '<input type="radio" name="pf-tab-' . $uid . '" id="pf-tab-' . $uid . '-' . $i . '"' . ($i === 0 ? ' checked' : '') . '>';
                }
                return '<div class="pf-tabs ' . self::elClass($el) . '">' . $inputs
                    . '<div class="pf-tabs__labels">' . $labels . '</div>' . $panels . '</div>';
            },
        ];

        $w['accordion'] = [
            'label' => 'Accordion', 'icon' => 'list',
            'controls' => [
                'items' => ['type' => 'repeater', 'max_items' => 30, 'item_schema' => $tabItem],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                if ($items === []) return '';
                $html = '<div class="pf-accordion ' . self::elClass($el) . '">';
                foreach ($items as $item) {
                    $html .= '<details><summary>' . self::esc((string) ($item['title'] ?? '')) . '</summary>'
                        . '<div class="pf-accordion__body"><p>' . nl2br(self::esc((string) ($item['content'] ?? ''))) . '</p></div></details>';
                }
                return $html . '</div>';
            },
        ];

        $w['toggle'] = [
            'label' => 'Toggle', 'icon' => 'list',
            'controls' => [
                'title' => $text + ['default' => 'Toggle title'],
                'content' => ['type' => 'textarea', 'default' => ''],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                return '<details class="pf-toggle ' . self::elClass($el) . '"><summary>' . self::esc((string) ($s['title'] ?? ''))
                    . '</summary><div class="pf-toggle__body"><p>' . nl2br(self::esc((string) ($s['content'] ?? ''))) . '</p></div></details>';
            },
        ];

        $w['counter'] = [
            'label' => 'Counter', 'icon' => 'number',
            'controls' => [
                'end_number' => ['type' => 'number', 'default' => 100, 'min' => 0, 'max' => 1000000000],
                'prefix' => $text,
                'suffix' => $text,
                'title' => $text,
            ],
            'style_controls' => ['font_size', 'color', 'text_align'],
            'render' => static function (array $s, string $children, array $el): string {
                $num = $s['end_number'] ?? 0;
                $num = $num == (int) $num ? number_format((int) $num) : number_format((float) $num, 2);
                $html = '<div class="pf-counter ' . self::elClass($el) . '"><span class="pf-counter__value">'
                    . self::esc((string) ($s['prefix'] ?? '')) . $num . self::esc((string) ($s['suffix'] ?? '')) . '</span>';
                if (!empty($s['title'])) {
                    $html .= '<span class="pf-counter__title">' . self::esc((string) $s['title']) . '</span>';
                }
                return $html . '</div>';
            },
        ];

        $w['progress'] = [
            'label' => 'Progress Bar', 'icon' => 'progress',
            'controls' => [
                'title' => $text,
                'percent' => ['type' => 'number', 'default' => 50, 'min' => 0, 'max' => 100],
                'show_percent' => ['type' => 'select', 'options' => ['yes', 'no'], 'default' => 'yes'],
            ],
            'style_controls' => ['color', 'background_color'],
            'render' => static function (array $s, string $children, array $el): string {
                $pct = max(0, min(100, (float) ($s['percent'] ?? 0)));
                $pctLabel = $pct == (int) $pct ? (string) (int) $pct : rtrim(rtrim(number_format($pct, 1), '0'), '.');
                $html = '<div class="pf-progress ' . self::elClass($el) . '" role="progressbar" aria-valuenow="' . $pctLabel . '" aria-valuemin="0" aria-valuemax="100">';
                if (!empty($s['title']) || ($s['show_percent'] ?? 'yes') === 'yes') {
                    $html .= '<div class="pf-progress__head"><span>' . self::esc((string) ($s['title'] ?? '')) . '</span>'
                        . (($s['show_percent'] ?? 'yes') === 'yes' ? '<span>' . $pctLabel . '%</span>' : '') . '</div>';
                }
                return $html . '<div class="pf-progress__track"><div class="pf-progress__bar" style="width:' . $pctLabel . '%"></div></div></div>';
            },
        ];

        $w['testimonial'] = [
            'label' => 'Testimonial', 'icon' => 'quote',
            'controls' => [
                'quote' => ['type' => 'textarea', 'default' => ''],
                'author' => $text,
                'role' => $text,
                'avatar' => ['type' => 'media'],
                'rating' => ['type' => 'select', 'options' => ['0', '1', '2', '3', '4', '5'], 'default' => '5'],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                $html = '<blockquote class="pf-testimonial ' . self::elClass($el) . '">';
                $rating = (int) ($s['rating'] ?? 0);
                if ($rating > 0) {
                    $html .= self::starsHtml((float) $rating);
                }
                $html .= '<p>' . nl2br(self::esc((string) ($s['quote'] ?? ''))) . '</p><footer>';
                if (!empty($s['avatar'])) {
                    $html .= '<img src="' . self::escUrl((string) $s['avatar']) . '" alt="" loading="lazy">';
                }
                $html .= '<span class="pf-testimonial__author">' . self::esc((string) ($s['author'] ?? '')) . '</span>';
                if (!empty($s['role'])) {
                    $html .= '<span class="pf-testimonial__role">' . self::esc((string) $s['role']) . '</span>';
                }
                return $html . '</footer></blockquote>';
            },
        ];

        $w['social-icons'] = [
            'label' => 'Social Icons', 'icon' => 'share',
            'controls' => [
                'items' => ['type' => 'repeater', 'max_items' => 12, 'item_schema' => [
                    'network' => ['type' => 'select', 'options' => ['facebook', 'x', 'instagram', 'linkedin', 'youtube', 'github', 'whatsapp', 'telegram'], 'default' => 'facebook'],
                    'url' => ['type' => 'url'],
                ]],
            ],
            'style_controls' => ['color', 'font_size'],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $items = array_values(array_filter($items, static fn ($i) => is_array($i) && !empty($i['url'])));
                if ($items === []) return '';
                $initials = ['facebook' => 'f', 'x' => 'X', 'instagram' => 'Ig', 'linkedin' => 'in', 'youtube' => 'Yt', 'github' => 'Gh', 'whatsapp' => 'Wa', 'telegram' => 'Tg'];
                $html = '<div class="pf-social ' . self::elClass($el) . '">';
                foreach ($items as $item) {
                    $net = (string) ($item['network'] ?? 'facebook');
                    $html .= '<a class="pf-social__btn pf-social__btn--' . self::esc($net) . '" href="' . self::escUrl((string) $item['url'])
                        . '" target="_blank" rel="noopener" aria-label="' . self::esc(ucfirst($net)) . '">'
                        . self::esc($initials[$net] ?? '?') . '</a>';
                }
                return $html . '</div>';
            },
        ];

        $w['alert'] = [
            'label' => 'Alert', 'icon' => 'warning',
            'controls' => [
                'alert_type' => ['type' => 'select', 'options' => ['info', 'success', 'warning', 'danger'], 'default' => 'info'],
                'title' => $text + ['default' => 'Alert title'],
                'description' => ['type' => 'textarea', 'default' => ''],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                $type = (string) ($s['alert_type'] ?? 'info');
                if (!in_array($type, ['info', 'success', 'warning', 'danger'], true)) $type = 'info';
                $icon = ['info' => 'info', 'success' => 'check', 'warning' => 'warning', 'danger' => 'warning'][$type];
                $html = '<div class="pf-alert pf-alert--' . $type . ' ' . self::elClass($el) . '" role="alert">'
                    . PageForgeIcons::svg($icon) . '<div><strong>' . self::esc((string) ($s['title'] ?? '')) . '</strong>';
                if (!empty($s['description'])) {
                    $html .= '<p>' . nl2br(self::esc((string) $s['description'])) . '</p>';
                }
                return $html . '</div></div>';
            },
        ];

        $w['html'] = [
            'label' => 'HTML', 'icon' => 'code',
            'controls' => [
                'content' => ['type' => 'raw_html', 'default' => ''],
            ],
            'render' => static fn (array $s, string $children, array $el): string =>
                '<div class="pf-html ' . self::elClass($el) . '">' . (string) ($s['content'] ?? '') . '</div>',
        ];

        $w['star-rating'] = [
            'label' => 'Star Rating', 'icon' => 'star',
            'controls' => [
                'rating' => ['type' => 'select', 'options' => ['0', '0.5', '1', '1.5', '2', '2.5', '3', '3.5', '4', '4.5', '5'], 'default' => '5'],
                'title' => $text,
            ],
            'style_controls' => ['color', 'font_size'],
            'render' => static function (array $s, string $children, array $el): string {
                $html = '<div class="pf-rating ' . self::elClass($el) . '">' . self::starsHtml((float) ($s['rating'] ?? 0));
                if (!empty($s['title'])) {
                    $html .= '<span class="pf-rating__title">' . self::esc((string) $s['title']) . '</span>';
                }
                return $html . '</div>';
            },
        ];

        $w['menu-anchor'] = [
            'label' => 'Menu Anchor', 'icon' => 'link',
            'controls' => [
                'anchor_id' => $text + ['default' => ''],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($s['anchor_id'] ?? ''));
                return $id === '' ? '' : '<span id="' . self::esc($id) . '" class="pf-anchor ' . self::elClass($el) . '"></span>';
            },
        ];

        $w['list'] = [
            'label' => 'List', 'icon' => 'list',
            'controls' => [
                'items' => ['type' => 'repeater', 'max_items' => 50, 'item_schema' => [
                    'text' => $text + ['default' => 'List item'],
                    'link_url' => ['type' => 'url'],
                ]],
                'list_type' => ['type' => 'select', 'options' => ['ul', 'ol'], 'default' => 'ul'],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                if ($items === []) return '';
                $tag = ($s['list_type'] ?? 'ul') === 'ol' ? 'ol' : 'ul';
                $html = '<' . $tag . ' class="pf-list ' . self::elClass($el) . '">';
                foreach ($items as $item) {
                    $label = self::esc((string) ($item['text'] ?? ''));
                    if (!empty($item['link_url'])) {
                        $label = '<a href="' . self::escUrl((string) $item['link_url']) . '">' . $label . '</a>';
                    }
                    $html .= '<li>' . $label . '</li>';
                }
                return $html . '</' . $tag . '>';
            },
        ];

        return $w;
    }

    /** Five-star display with a fractional fill (0–5, halves supported). */
    private static function starsHtml(float $rating): string
    {
        $rating = max(0.0, min(5.0, $rating));
        $pct = (string) ($rating * 20);
        $label = rtrim(rtrim(number_format($rating, 1), '0'), '.');
        return '<span class="pf-stars" role="img" aria-label="Rated ' . $label . ' out of 5">'
            . '<span class="pf-stars__bg">★★★★★</span>'
            . '<span class="pf-stars__fg" style="width:' . $pct . '%">★★★★★</span></span>';
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
