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
require_once __DIR__ . '/PageForgeConditions.php';

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
            'style_controls' => ['flex_direction', 'justify_content', 'align_items', 'flex_wrap', 'layout_mode', 'grid_columns', 'gap', 'width', 'min_height',
                'background_color', 'padding', 'margin', 'border_radius', 'border_width', 'border_style',
                'border_color', 'box_shadow', 'opacity'],
            'render' => static function (array $s, string $children, array $el): string {
                $tag = (string) ($s['html_tag'] ?? 'div');
                if (!in_array($tag, ['div', 'section', 'article', 'main', 'header', 'footer'], true)) $tag = 'div';
                return '<' . $tag . self::elAttrs($el, $s) . ' class="pf-container ' . self::elClass($el) . '">' . $children . '</' . $tag . '>';
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
                return '<' . $tag . self::elAttrs($el, $s) . ' class="pf-heading ' . self::elClass($el) . '">' . $inner . '</' . $tag . '>';
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
                return '<div' . self::elAttrs($el, $s) . ' class="pf-text ' . self::elClass($el) . '">' . (string) ($s['text'] ?? '') . '</div>';
            },
        ];

        $self['button'] = [
            'label' => 'Button',
            'icon' => 'button',
            'controls' => [
                'text' => $text + ['default' => 'Click here'],
                'link_url' => ['type' => 'url'],
                'size' => ['type' => 'select', 'options' => ['sm', 'md', 'lg'], 'default' => 'md'],
                'popup_id' => ['type' => 'number', 'min' => 0, 'max' => 1000000000],
            ],
            'style_controls' => ['background_color', 'color', 'border_radius', 'padding'],
            'render' => static function (array $s, string $children, array $el): string {
                $size = (string) ($s['size'] ?? 'md');
                if (!in_array($size, ['sm', 'md', 'lg'], true)) $size = 'md';
                $popup = (int) ($s['popup_id'] ?? 0);
                if ($popup > 0) {
                    return '<a' . self::elAttrs($el, $s) . ' class="pf-button pf-button--' . $size . ' pf-popup-trigger ' . self::elClass($el)
                        . '" href="#" data-pf-popup="' . $popup . '">' . self::esc((string) ($s['text'] ?? '')) . '</a>';
                }
                $href = !empty($s['link_url']) ? self::escUrl((string) $s['link_url']) : '#';
                return '<a' . self::elAttrs($el, $s) . ' class="pf-button pf-button--' . $size . ' ' . self::elClass($el) . '" href="' . $href . '">'
                    . self::esc((string) ($s['text'] ?? '')) . '</a>';
            },
        ];

        $self['form'] = [
            'label' => 'Form (Kohevo Forms)',
            'icon' => 'form',
            'controls' => [
                'form_slug' => $text,
                'form_height' => ['type' => 'number', 'min' => 100, 'max' => 2000, 'default' => 480],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                $slug = preg_replace('/[^a-zA-Z0-9-_]/', '', (string) ($s['form_slug'] ?? ''));
                if ($slug === '') {
                    return '<div' . self::elAttrs($el, $s) . ' class="pf-form pf-form--empty ' . self::elClass($el) . '">No form selected.</div>';
                }
                $height = max(100, min(2000, (int) ($s['form_height'] ?? 480)));
                $base = defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '';
                $src = $base . '/forms/' . $slug . '?embed=1';
                return '<div' . self::elAttrs($el, $s) . ' class="pf-form ' . self::elClass($el) . '"><iframe class="pf-form-frame" src="'
                    . self::esc($src) . '" title="Form" loading="lazy" style="height:' . $height . 'px"></iframe></div>';
            },
        ];

        $self['global-widget'] = [
            'label' => 'Global widget',
            'icon' => 'widget',
            'controls' => [
                'global_id' => ['type' => 'number', 'min' => 0, 'max' => 1000000000],
            ],
            'style_controls' => [],
            // Real content is substituted at compile time by
            // PageForgeRenderer::expandGlobals(); this render is only the
            // unexpanded fallback (no resolver wired).
            'render' => static fn (array $s, string $children, array $el): string =>
                '<div' . self::elAttrs($el, $s) . ' class="pf-global-missing ' . self::elClass($el) . '" data-global-id="' . (int) ($s['global_id'] ?? 0) . '"></div>',
        ];

        $self['image'] = [
            'label' => 'Image',
            'icon' => 'image',
            'controls' => [
                'src' => ['type' => 'media'],
                'alt' => $text,
                'link_url' => ['type' => 'url'],
            ],
            'style_controls' => ['width', 'image_align', 'border_radius'],
            'render' => static function (array $s, string $children, array $el): string {
                if (empty($s['src'])) {
                    return '';
                }
                $img = '<img' . self::elAttrs($el, $s) . ' class="' . self::elClass($el) . '" src="' . self::escUrl((string) $s['src'])
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
            // Batch 0: was ['color', ...] — CSS `color` never tinted the
            // border-top line. border_color compiles to border-color and wins.
            'style_controls' => ['border_color', 'margin'],
            'render' => static fn (array $s, string $children, array $el): string =>
                '<hr' . self::elAttrs($el, $s) . ' class="pf-divider ' . self::elClass($el) . '">',
        ];

        $self['spacer'] = [
            'label' => 'Spacer',
            'icon' => 'spacer',
            'controls' => [
                'height' => ['type' => 'number', 'default' => 40, 'min' => 0, 'max' => 1000],
            ],
            'controls_are_style' => ['height' => 'height'], // content control rendered as inline style prop
            'render' => static fn (array $s, string $children, array $el): string =>
                '<div' . self::elAttrs($el, $s) . ' class="pf-spacer ' . self::elClass($el) . '" aria-hidden="true"></div>',
        ];

        $all = array_merge($self, self::phase2Widgets(), self::phase5Widgets(), self::batchFWidgets());
        // Phase 8: every element gains the shared Advanced controls —
        // reusable CSS classes and per-element custom CSS.
        // Batch A: + a stable CSS ID and data-* attributes (same loop).
        // Exception: menu-anchor already owns its id via anchor_id — a
        // second id control there would be inert, so it is not offered.
        foreach ($all as $pfType => &$defRef) {
            if (!isset($defRef['controls']['css_classes'])) {
                $defRef['controls']['css_classes'] = ['type' => 'text'];
            }
            if ($pfType !== 'menu-anchor' && !isset($defRef['controls']['css_id'])) {
                $defRef['controls']['css_id'] = ['type' => 'text'];
            }
            if (!isset($defRef['controls']['attributes'])) {
                $defRef['controls']['attributes'] = ['type' => 'attributes'];
            }
            if (!isset($defRef['controls']['custom_css'])) {
                $defRef['controls']['custom_css'] = ['type' => 'textarea'];
            }
        }
        unset($defRef);
        self::$widgets = $all;
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
        // Phase 8 shared Advanced controls (not CSS-mapped style keys).
        $schema['css_classes'] = ['type' => 'text'];
        $schema['custom_css'] = ['type' => 'textarea'];
        // Responsive variants (_tablet/_mobile) of size-like controls.
        $out = [];
        foreach ($settings as $key => $value) {
            $baseKey = preg_replace('/_(tablet|mobile)$/', '', (string) $key);
            $control = $schema[$baseKey] ?? null;
            if ($control === null) {
                continue;
            }
            if ($baseKey === 'custom_css') {
                // Custom CSS must never break out of the compiled
                // <style> block: strip '<' outright, cap the length.
                $value = mb_substr(str_replace('<', '', (string) $value), 0, 5000);
            }
            if ($baseKey === 'css_id') {
                // Batch A: a CSS ID is a single inert token — anything else
                // is stripped, never escaped into the attribute.
                $value = mb_substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value), 0, 100);
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
                // A whole-value {{kit.<token>}} reference is a Phase 6
                // global binding: preserved at save time, resolved to a
                // validated hex by the CSS compiler at publish time.
                if (preg_match('/^\{\{\s*kit[:.][a-z0-9_.-]+\s*\}\}$/i', $v)) return $v;
                return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) ? strtolower($v) : '';
            case 'url':
            case 'media':
                $v = trim((string) $value);
                if ($v === '') return '';
                // A whole-value {{field}} placeholder is a dynamic-binding
                // token (loop templates): substituted at render time, and
                // the bound value still passes the widget's own escUrl().
                if (preg_match('/^\{\{\s*[a-zA-Z0-9_]+\s*\}\}$/', $v)) return $v;
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
            case 'attributes':
                // Batch A: key/value pairs rendered as data-* attributes.
                // Only data-* keys survive (arbitrary attributes would let
                // an author inject event handlers); values are escaped at
                // render time and capped here.
                if (!is_array($value)) return [];
                $pairs = [];
                foreach (array_slice(array_values($value), 0, 20) as $pair) {
                    if (!is_array($pair)) continue;
                    $k = strtolower(trim((string) ($pair['key'] ?? '')));
                    if (!preg_match('/^data-[a-z0-9_-]+$/', $k)) continue;
                    $pairs[] = ['key' => $k, 'value' => mb_substr(trim((string) ($pair['value'] ?? '')), 0, 500)];
                }
                return $pairs;
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
                $html = '<span' . self::elAttrs($el, $s) . ' class="pf-icon ' . self::elClass($el) . '">' . $svg . '</span>';
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
                // Batch B: layout + title tag live in Content (structure).
                'image_position' => ['type' => 'select', 'options' => ['top', 'left', 'right'], 'default' => 'top'],
                'title_tag' => ['type' => 'select', 'options' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p'], 'default' => 'h3'],
            ],
            'style_controls' => ['width', 'image_align', 'text_align', 'border_radius',
                'image_spacing', 'content_spacing', 'image_border_style', 'image_border_width', 'image_border_color',
                'title_color', 'title_font_size', 'title_font_weight', 'desc_color', 'desc_font_size', 'desc_font_weight'],
            'render' => static function (array $s, string $children, array $el): string {
                [$layoutAttr, $wrap] = self::boxLayout($s, 'image_position', 'image_spacing');
                $imgHtml = '';
                if (!empty($s['src'])) {
                    $imgHtml = '<img src="' . self::escUrl((string) $s['src']) . '" alt="' . self::esc((string) ($s['alt'] ?? '')) . '" loading="lazy"' . self::boxStyle(self::mediaBorderDecls($s)) . '>';
                }
                $inner = $imgHtml . self::boxTextHtml($s, 'pf-image-box__text', $wrap);
                if (!empty($s['link_url'])) {
                    $inner = '<a href="' . self::escUrl((string) $s['link_url']) . '"' . $layoutAttr . '>' . $inner . '</a>';
                    $layoutAttr = '';
                }
                return '<figure' . self::elAttrs($el, $s) . $layoutAttr . ' class="pf-image-box ' . self::elClass($el) . '">' . $inner . '</figure>';
            },
        ];

        $w['icon-box'] = [
            'label' => 'Icon Box', 'icon' => 'star',
            'controls' => [
                'icon_name' => $iconControl,
                'title' => $text + ['default' => 'Icon box title'],
                'description' => ['type' => 'textarea', 'default' => ''],
                'link_url' => ['type' => 'url'],
                // Batch B: layout + view + title tag live in Content.
                'icon_position' => ['type' => 'select', 'options' => ['top', 'left', 'right'], 'default' => 'top'],
                'icon_view' => ['type' => 'select', 'options' => ['default', 'stacked', 'framed'], 'default' => 'default'],
                'title_tag' => ['type' => 'select', 'options' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p'], 'default' => 'h3'],
            ],
            'style_controls' => ['text_align', 'color', 'font_size',
                'icon_spacing', 'content_spacing', 'icon_color', 'icon_bg_color', 'icon_rotate', 'icon_hover_animation',
                'title_color', 'title_font_size', 'title_font_weight', 'desc_color', 'desc_font_size', 'desc_font_weight'],
            'render' => static function (array $s, string $children, array $el): string {
                [$layoutAttr, $wrap] = self::boxLayout($s, 'icon_position', 'icon_spacing');
                // Batch 0: .pf-icon-box__icon is fixed 40px in base CSS, so
                // a container font-size never reached it. Inline when set.
                $decls = [];
                if (isset($s['font_size']) && is_numeric($s['font_size'])) {
                    $decls['font-size'] = self::boxFmt(max(0, min(400, (float) $s['font_size']))) . 'px';
                }
                // Batch B: dedicated icon colors. Primary = glyph; secondary
                // = stacked background / framed border (falls back to
                // primary, then the kit accent, so stacked never renders
                // an invisible circle).
                $prim = self::boxColor($s['icon_color'] ?? null);
                if ($prim !== null) $decls['color'] = $prim;
                $view = in_array($s['icon_view'] ?? '', ['default', 'stacked', 'framed'], true) ? $s['icon_view'] : 'default';
                $sec = self::boxColor($s['icon_bg_color'] ?? null) ?? $prim ?? '#454299';
                if ($view === 'stacked') {
                    $decls['background-color'] = $sec;
                    $decls['padding'] = '0.4em';
                    $decls['border-radius'] = '50%';
                } elseif ($view === 'framed') {
                    $decls['border'] = '2px solid ' . $sec;
                    $decls['padding'] = '0.4em';
                    $decls['border-radius'] = '50%';
                }
                $anim = in_array($s['icon_hover_animation'] ?? '', ['none', 'grow', 'pulse', 'float', 'spin'], true) ? $s['icon_hover_animation'] : 'none';
                $iconCls = 'pf-icon-box__icon' . ($anim !== 'none' ? ' pf-icon-box__icon--hover-' . $anim : '');
                $iconHtml = '<span class="' . $iconCls . '"' . self::boxStyle($decls) . '>' . PageForgeIcons::svg((string) ($s['icon_name'] ?? 'star')) . '</span>';
                // Rotate rides on a wrapper so it never fights the hover
                // animation's transform on the icon itself.
                $rot = self::boxNum($s['icon_rotate'] ?? null);
                if ($rot !== null && $rot != 0) {
                    $iconHtml = '<span class="pf-icon-box__iconwrap" style="transform: rotate(' . self::boxFmt($rot) . 'deg);">' . $iconHtml . '</span>';
                }
                $inner = $iconHtml . self::boxTextHtml($s, 'pf-icon-box__text', $wrap);
                if (!empty($s['link_url'])) {
                    $inner = '<a href="' . self::escUrl((string) $s['link_url']) . '"' . $layoutAttr . '>' . $inner . '</a>';
                    $layoutAttr = '';
                }
                return '<div' . self::elAttrs($el, $s) . $layoutAttr . ' class="pf-icon-box ' . self::elClass($el) . '">' . $inner . '</div>';
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
                // Batch C: layout lives in Content (structure).
                'list_layout' => ['type' => 'select', 'options' => ['vertical', 'inline'], 'default' => 'vertical'],
            ],
            'style_controls' => ['color', 'font_size', 'gap',
                'list_align', 'icon_valign', 'divider_on', 'divider_style', 'divider_weight', 'divider_width', 'divider_color',
                'list_icon_color', 'list_icon_size', 'list_transition'],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                if ($items === []) return '';
                // Batch C: layout + alignment ride on the <ul>; icon
                // vertical alignment rides on each <li>. Defaults emit
                // nothing, so old lists compile byte-identically.
                $layout = in_array($s['list_layout'] ?? '', ['vertical', 'inline'], true) ? $s['list_layout'] : 'vertical';
                $align = in_array($s['list_align'] ?? '', ['left', 'center', 'right'], true) ? $s['list_align'] : 'left';
                $ulDecls = [];
                if ($layout === 'inline') {
                    $ulDecls['flex-direction'] = 'row';
                    $ulDecls['flex-wrap'] = 'wrap';
                    if ($align !== 'left') $ulDecls['justify-content'] = $align === 'center' ? 'center' : 'flex-end';
                } elseif ($align !== 'left') {
                    $ulDecls['align-items'] = $align === 'center' ? 'center' : 'flex-end';
                }
                $valign = in_array($s['icon_valign'] ?? '', ['top', 'center', 'bottom'], true) ? $s['icon_valign'] : 'center';
                $liStyle = $valign === 'center' ? '' : self::boxStyle(['align-items' => $valign === 'top' ? 'flex-start' : 'flex-end']);
                // The icon wrapper exists only when a new icon feature is
                // used — its class is what the compiled icon rules target.
                $size = self::boxNum($s['list_icon_size'] ?? null);
                $tr = isset($s['list_transition']) && is_numeric($s['list_transition']) && (float) $s['list_transition'] > 0;
                $wrapIcon = $size !== null || self::boxColor($s['list_icon_color'] ?? null) !== null || $tr;
                $sizeAttr = self::boxStyle(['font-size' => $size !== null ? self::boxFmt($size) . 'px' : null]);
                $html = '<ul' . self::elAttrs($el, $s) . self::boxStyle($ulDecls) . ' class="pf-icon-list ' . self::elClass($el) . '">';
                foreach ($items as $item) {
                    $icon = PageForgeIcons::svg((string) ($item['icon_name'] ?? 'check'));
                    if ($wrapIcon) $icon = '<span class="pf-icon-list__icon"' . $sizeAttr . '>' . $icon . '</span>';
                    $label = $icon . '<span>' . self::esc((string) ($item['text'] ?? '')) . '</span>';
                    if (!empty($item['link_url'])) {
                        $label = '<a href="' . self::escUrl((string) $item['link_url']) . '">' . $label . '</a>';
                    }
                    $html .= '<li' . $liStyle . '>' . $label . '</li>';
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
                    return '<div' . self::elAttrs($el, $s) . ' class="pf-video ' . self::elClass($el) . '"><video controls preload="metadata" src="' . self::escUrl($url) . '"></video></div>';
                }
                $embed = '';
                if ($source === 'youtube' && preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $url, $m)) {
                    $embed = 'https://www.youtube-nocookie.com/embed/' . $m[1];
                }
                if ($source === 'vimeo' && preg_match('~vimeo\.com/(\d{5,})~', $url, $m)) {
                    $embed = 'https://player.vimeo.com/video/' . $m[1];
                }
                if ($embed === '') return '';
                return '<div' . self::elAttrs($el, $s) . ' class="pf-video pf-video--embed ' . self::elClass($el) . '"><iframe src="' . self::esc($embed)
                    . '" loading="lazy" allow="accelerometer; autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Video"></iframe></div>';
            },
        ];

        $w['gallery'] = [
            'label' => 'Gallery', 'icon' => 'image',
            'controls' => [
                'items' => ['type' => 'repeater', 'max_items' => 30, 'item_schema' => $imgItem],
                'columns' => ['type' => 'number', 'default' => 3, 'min' => 1, 'max' => 6],
            ],
            'style_controls' => ['gap', 'border_radius',
                'image_border_style', 'image_border_width', 'image_border_color',
                'lightbox', 'caption_display', 'caption_align', 'caption_color', 'caption_font_size', 'caption_font_weight'],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $items = array_values(array_filter($items, static fn ($i) => is_array($i) && !empty($i['src'])));
                if ($items === []) return '';
                $cols = max(1, min(6, (int) ($s['columns'] ?? 3)));
                $lb = ($s['lightbox'] ?? 'off') === 'on';
                $bStyle = self::boxStyle(self::mediaBorderDecls($s));
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-gallery ' . self::elClass($el) . '" style="--pf-gallery-cols:' . $cols . '"'
                    . ($lb ? ' data-pf-lightbox="1"' : '') . '>';
                foreach ($items as $item) {
                    $fig = '<img src="' . self::escUrl((string) $item['src']) . '" alt="' . self::esc((string) ($item['caption'] ?? '')) . '" loading="lazy"' . $bStyle . '>';
                    $fig .= self::mediaCaption($s, (string) ($item['caption'] ?? ''));
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
                // Batch D: the slider config rides in a data attribute the
                // frontend script reads — no new markup per option.
                'slides_to_show' => ['type' => 'number', 'default' => 3, 'min' => 1, 'max' => 6],
                'slides_to_scroll' => ['type' => 'number', 'default' => 1, 'min' => 1, 'max' => 6],
                'navigation' => ['type' => 'select', 'options' => ['none', 'arrows', 'dots', 'both'], 'default' => 'both'],
                'infinite_loop' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'on'],
                'autoplay' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'off'],
                'autoplay_speed' => ['type' => 'number', 'default' => 3000, 'min' => 1000, 'max' => 10000],
                'pause_on_hover' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'on'],
                'pause_on_interaction' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'on'],
                'animation_speed' => ['type' => 'number', 'default' => 500, 'min' => 100, 'max' => 2000],
            ],
            'style_controls' => ['border_radius', 'gap',
                'image_border_style', 'image_border_width', 'image_border_color',
                'lightbox', 'caption_display', 'caption_align', 'caption_color', 'caption_font_size', 'caption_font_weight'],
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $items = array_values(array_filter($items, static fn ($i) => is_array($i) && !empty($i['src'])));
                if ($items === []) return '';
                $cfg = [
                    'show' => max(1, min(6, (int) ($s['slides_to_show'] ?? 3))),
                    'scroll' => max(1, min(6, (int) ($s['slides_to_scroll'] ?? 1))),
                    'nav' => in_array($s['navigation'] ?? '', ['none', 'arrows', 'dots', 'both'], true) ? $s['navigation'] : 'both',
                    'loop' => ($s['infinite_loop'] ?? 'on') === 'on',
                    'autoplay' => ($s['autoplay'] ?? 'off') === 'on',
                    'speed' => max(1000, min(10000, (int) ($s['autoplay_speed'] ?? 3000))),
                    'pauseHover' => ($s['pause_on_hover'] ?? 'on') === 'on',
                    'pauseInteract' => ($s['pause_on_interaction'] ?? 'on') === 'on',
                    'anim' => max(100, min(2000, (int) ($s['animation_speed'] ?? 500))),
                ];
                $lb = ($s['lightbox'] ?? 'off') === 'on';
                $bStyle = self::boxStyle(self::mediaBorderDecls($s));
                // The track gap is inline: the compiled `gap` rule lands on
                // the root, but the track is what needs it (v1: responsive
                // gap variants do not reach the track).
                $gap = isset($s['gap']) && is_numeric($s['gap']) ? max(0, (float) $s['gap']) : null;
                $trackStyle = $gap !== null ? ' style="gap:' . self::boxFmt($gap) . 'px;"' : '';
                $slides = '';
                foreach ($items as $item) {
                    $slide = '<img src="' . self::escUrl((string) $item['src']) . '" alt="' . self::esc((string) ($item['caption'] ?? '')) . '" loading="lazy"' . $bStyle . '>';
                    $slide .= self::mediaCaption($s, (string) ($item['caption'] ?? ''));
                    if (!empty($item['link_url'])) {
                        $slide = '<a href="' . self::escUrl((string) $item['link_url']) . '">' . $slide . '</a>';
                    }
                    $slides .= '<figure class="pf-carousel__slide">' . $slide . '</figure>';
                }
                return '<div' . self::elAttrs($el, $s) . ' class="pf-carousel ' . self::elClass($el) . '" role="region" aria-label="Image carousel"'
                    . ' data-pf-carousel="' . self::esc((string) json_encode($cfg)) . '"'
                    . ($lb ? ' data-pf-lightbox="1"' : '') . '>'
                    . '<div class="pf-carousel__viewport"><div class="pf-carousel__track"' . $trackStyle . '>' . $slides . '</div></div></div>';
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
                return '<div' . self::elAttrs($el, $s) . ' class="pf-tabs ' . self::elClass($el) . '">' . $inputs
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
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-accordion ' . self::elClass($el) . '">';
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
                return '<details' . self::elAttrs($el, $s) . ' class="pf-toggle ' . self::elClass($el) . '"><summary>' . self::esc((string) ($s['title'] ?? ''))
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
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-counter ' . self::elClass($el) . '"><span class="pf-counter__value">'
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
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-progress ' . self::elClass($el) . '" role="progressbar" aria-valuenow="' . $pctLabel . '" aria-valuemin="0" aria-valuemax="100">';
                if (!empty($s['title']) || ($s['show_percent'] ?? 'yes') === 'yes') {
                    $html .= '<div class="pf-progress__head"><span>' . self::esc((string) ($s['title'] ?? '')) . '</span>'
                        . (($s['show_percent'] ?? 'yes') === 'yes' ? '<span>' . $pctLabel . '%</span>' : '') . '</div>';
                }
                // Batch 0: track bg is fixed #e5e2da in base CSS; the control
                // only painted the wrapper. Inline the track bg when set.
                $trackStyle = self::isHexColor((string) ($s['background_color'] ?? ''))
                    ? ' style="background-color:' . trim((string) $s['background_color']) . '"' : '';
                return $html . '<div class="pf-progress__track"' . $trackStyle . '><div class="pf-progress__bar" style="width:' . $pctLabel . '%"></div></div></div>';
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
                $html = '<blockquote' . self::elAttrs($el, $s) . ' class="pf-testimonial ' . self::elClass($el) . '">';
                $rating = (int) ($s['rating'] ?? 0);
                if ($rating > 0) {
                    $html .= self::starsHtml((float) $rating, (string) ($s['color'] ?? ''));
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
                // Batch 0: buttons carry fixed #11130f bg in base CSS, so the
                // color control never tinted them. Inline bg when set.
                $btnStyle = self::isHexColor((string) ($s['color'] ?? ''))
                    ? ' style="background-color:' . trim((string) $s['color']) . '"' : '';
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-social ' . self::elClass($el) . '">';
                foreach ($items as $item) {
                    $net = (string) ($item['network'] ?? 'facebook');
                    $html .= '<a class="pf-social__btn pf-social__btn--' . self::esc($net) . '"' . $btnStyle . ' href="' . self::escUrl((string) $item['url'])
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
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-alert pf-alert--' . $type . ' ' . self::elClass($el) . '" role="alert">'
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
                '<div' . self::elAttrs($el, $s) . ' class="pf-html ' . self::elClass($el) . '">' . (string) ($s['content'] ?? '') . '</div>',
        ];

        $w['star-rating'] = [
            'label' => 'Star Rating', 'icon' => 'star',
            'controls' => [
                'rating' => ['type' => 'select', 'options' => ['0', '0.5', '1', '1.5', '2', '2.5', '3', '3.5', '4', '4.5', '5'], 'default' => '5'],
                'title' => $text,
            ],
            'style_controls' => ['color', 'font_size'],
            'render' => static function (array $s, string $children, array $el): string {
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-rating ' . self::elClass($el) . '">' . self::starsHtml((float) ($s['rating'] ?? 0), (string) ($s['color'] ?? ''));
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
                return $id === '' ? '' : '<span id="' . self::esc($id) . '"' . self::elAttrs($el, $s, true) . ' class="pf-anchor ' . self::elClass($el) . '"></span>';
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
                $html = '<' . $tag . self::elAttrs($el, $s) . ' class="pf-list ' . self::elClass($el) . '">';
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
    private static function starsHtml(float $rating, string $color = ''): string
    {
        $rating = max(0.0, min(5.0, $rating));
        $pct = (string) ($rating * 20);
        $label = rtrim(rtrim(number_format($rating, 1), '0'), '.');
        // Batch 0: filled layer was hardcoded #f5a623 in base CSS, so the
        // color control never tinted the stars. Inline when set; unset keeps gold.
        $fgStyle = 'width:' . $pct . '%;' . (self::isHexColor($color) ? ' color:' . trim($color) . ';' : '');
        return '<span class="pf-stars" role="img" aria-label="Rated ' . $label . ' out of 5">'
            . '<span class="pf-stars__bg">★★★★★</span>'
            . '<span class="pf-stars__fg" style="' . $fgStyle . '">★★★★★</span></span>';
    }

    public static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    public static function escUrl(string $v): string
    {
        return self::isSafeUrl($v) ? self::esc($v) : '#';
    }

    /** Strict hex check for author values echoed into inline styles. */
    public static function isHexColor(string $v): bool
    {
        return (bool) preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', trim($v));
    }

    /** Batch B: validated inline-style attribute from prop => value pairs.
     *  Only hex colors and finite numbers reach the attribute; anything
     *  else (incl. {{kit.*}} tokens, which only the CSS compiler resolves)
     *  is skipped. Returns '' or ' style="..."'. */
    private static function boxStyle(array $decls): string
    {
        $out = [];
        foreach ($decls as $prop => $v) {
            if ($v === null || $v === '') continue;
            $out[] = $prop . ':' . $v . ';';
        }
        return $out === [] ? '' : ' style="' . self::esc(implode('', $out)) . '"';
    }

    private static function boxColor($v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return self::isHexColor($v) ? strtolower($v) : null;
    }

    private static function boxNum($v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function boxFmt(float $n): string
    {
        return $n == (int) $n ? (string) (int) $n : (string) $n;
    }

    /** Batch B: validated heading tag for box titles (fixed <h3> before). */
    private static function boxTitleTag(array $s): string
    {
        $t = (string) ($s['title_tag'] ?? 'h3');
        return in_array($t, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p'], true) ? $t : 'h3';
    }

    /** Batch B: title/description part declarations shared by box widgets. */
    private static function boxTitleDecls(array $s): array
    {
        $size = self::boxNum($s['title_font_size'] ?? null);
        $weight = in_array($s['title_font_weight'] ?? '', ['400', '500', '600', '700'], true) ? $s['title_font_weight'] : null;
        return ['color' => self::boxColor($s['title_color'] ?? null),
            'font-size' => $size !== null ? self::boxFmt($size) . 'px' : null, 'font-weight' => $weight];
    }

    private static function boxDescDecls(array $s): array
    {
        $size = self::boxNum($s['desc_font_size'] ?? null);
        $weight = in_array($s['desc_font_weight'] ?? '', ['400', '500', '600', '700'], true) ? $s['desc_font_weight'] : null;
        return ['color' => self::boxColor($s['desc_color'] ?? null),
            'font-size' => $size !== null ? self::boxFmt($size) . 'px' : null, 'font-weight' => $weight];
    }

    /** Batch B: flex layout for a box root (or its <a> when linked).
     *  Returns [layoutAttr, wrapText]: row layouts need a text wrapper
     *  (title+desc would otherwise sit beside the media in the row), and
     *  so does a top layout with a media gap (otherwise the gap would
     *  also separate title from description). Untouched boxes — top,
     *  no gap — keep their exact old HTML. */
    private static function boxLayout(array $s, string $posKey, string $gapKey): array
    {
        $pos = in_array($s[$posKey] ?? '', ['top', 'left', 'right'], true) ? $s[$posKey] : 'top';
        $gap = self::boxNum($s[$gapKey] ?? null);
        if ($pos === 'left' || $pos === 'right') {
            $dir = $pos === 'right' ? 'row-reverse' : 'row';
            $style = 'display:flex;flex-direction:' . $dir . ';align-items:flex-start;'
                . ($gap !== null ? 'gap:' . self::boxFmt($gap) . 'px;' : '');
            return [' style="' . $style . '"', true];
        }
        if ($gap !== null) {
            return [' style="display:flex;flex-direction:column;gap:' . self::boxFmt($gap) . 'px;"', true];
        }
        return ['', false];
    }

    /** Batch D: figcaption HTML for gallery/carousel items. Hidden when
     *  the display toggle says so; aligned/colored/sized per the widget's
     *  caption style keys (Batch B per-part pattern). */
    private static function mediaCaption(array $s, string $caption): string
    {
        if (($s['caption_display'] ?? 'show') === 'hide' || $caption === '') return '';
        $decls = [];
        $al = in_array($s['caption_align'] ?? '', ['left', 'center', 'right'], true) ? $s['caption_align'] : null;
        if ($al !== null) $decls['text-align'] = $al;
        $c = self::boxColor($s['caption_color'] ?? null);
        if ($c !== null) $decls['color'] = $c;
        $size = self::boxNum($s['caption_font_size'] ?? null);
        if ($size !== null) $decls['font-size'] = self::boxFmt($size) . 'px';
        $w = in_array($s['caption_font_weight'] ?? '', ['400', '500', '600', '700'], true) ? $s['caption_font_weight'] : null;
        if ($w !== null) $decls['font-weight'] = $w;
        return '<figcaption' . self::boxStyle($decls) . '>' . self::esc($caption) . '</figcaption>';
    }

    /** Batch D: image border declarations shared by box/gallery/carousel imgs. */
    private static function mediaBorderDecls(array $s): array
    {
        $style = (string) ($s['image_border_style'] ?? '');
        if ($style === '' || $style === 'none' || !in_array($style, ['solid', 'dashed', 'dotted', 'double'], true)) return [];
        $decls = ['border-style' => $style];
        $bw = self::boxNum($s['image_border_width'] ?? null);
        $decls['border-width'] = self::boxFmt($bw ?? 1) . 'px';
        $bc = self::boxColor($s['image_border_color'] ?? null);
        if ($bc !== null) $decls['border-color'] = $bc;
        return $decls;
    }

    /** Batch B: title+desc HTML for a box widget, wrapped when the layout
     *  needs it; content spacing becomes the wrapper gap, else a margin
     *  on the description (the only element it separates then). */
    private static function boxTextHtml(array $s, string $wrapClass, bool $wrap): string
    {
        $tag = self::boxTitleTag($s);
        $titleHtml = '<' . $tag . self::boxStyle(self::boxTitleDecls($s)) . '>' . self::esc((string) ($s['title'] ?? '')) . '</' . $tag . '>';
        $descHtml = '';
        if (!empty($s['description'])) {
            $decls = self::boxDescDecls($s);
            if (!$wrap) {
                $cs = self::boxNum($s['content_spacing'] ?? null);
                if ($cs !== null) $decls['margin-top'] = self::boxFmt($cs) . 'px';
            }
            $descHtml = '<p' . self::boxStyle($decls) . '>' . nl2br(self::esc((string) $s['description'])) . '</p>';
        }
        if (!$wrap) {
            return $titleHtml . $descHtml;
        }
        $cs = self::boxNum($s['content_spacing'] ?? null);
        $wrapAttr = $cs !== null ? ' style="display:flex;flex-direction:column;gap:' . self::boxFmt($cs) . 'px;"' : '';
        return '<div class="' . $wrapClass . '"' . $wrapAttr . '>' . $titleHtml . $descHtml . '</div>';
    }

    public static function elClass(array $el): string
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($el['id'] ?? ''));
        $cls = 'pf-el-' . $id;
        // Phase 8 reusable classes: author-supplied class tokens ride
        // alongside pf-el-<id> on every element root. Tokens are
        // whitelisted to [a-zA-Z0-9_-] so the attribute stays inert.
        $extra = trim((string) ($el['settings']['css_classes'] ?? ''));
        if ($extra !== '') {
            $seen = [];
            foreach (preg_split('/\s+/', $extra) ?: [] as $tok) {
                $tok = (string) $tok;
                if (preg_match('/^[a-zA-Z0-9_-]+$/', $tok) && !isset($seen[$tok])) {
                    $seen[$tok] = true;
                    $cls .= ' ' . $tok;
                }
            }
        }
        return $cls;
    }

    /**
     * Batch A: root-element attributes beyond the class list — the author's
     * CSS ID, data-* attribute pairs, and the scroll-fade marker (consumed
     * by assets/pageforge-frontend.js). Returns '' or a leading-space
     * attribute string, so renders splice it before ` class="…"`. The
     * menu-anchor render passes $skipId because it already owns its id.
     */
    public static function elAttrs(array $el, array $s, bool $skipId = false): string
    {
        $out = '';
        if (!$skipId) {
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($s['css_id'] ?? ''));
            if ($id !== '') $out .= ' id="' . self::esc($id) . '"';
        }
        $attrs = $s['attributes'] ?? null;
        if (is_array($attrs)) {
            foreach (array_slice($attrs, 0, 20) as $pair) {
                if (!is_array($pair)) continue;
                $k = strtolower(trim((string) ($pair['key'] ?? '')));
                if (!preg_match('/^data-[a-z0-9_-]+$/', $k)) continue;
                $out .= ' ' . $k . '="' . self::esc(mb_substr(trim((string) ($pair['value'] ?? '')), 0, 500)) . '"';
            }
        }
        if (($s['scroll_fade'] ?? 'off') === 'on') $out .= ' data-pf-scroll-fade="1"';
        return $out;
    }

    // ── Phase 5 data helpers (Shop API / linked documents / contexts) ────

    /** Absolute URL on this Kohevo install for a path like '/shop'. */
    public static function siteUrl(string $path): string
    {
        $base = defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '';
        return $base . $path;
    }

    /** Money via the Shop API when available (its currency setting wins). */
    public static function money(float $value): string
    {
        if (class_exists('ShopAPI')) {
            try {
                return ShopAPI::formatMoney($value);
            } catch (\Throwable $e) {
                // fall through to the plain format
            }
        }
        return '$' . number_format($value, 2);
    }

    /** Published products via the Shop public API; [] when Shop is unavailable. */
    public static function fetchProducts(array $filters, int $limit): array
    {
        if (!class_exists('ShopAPI')) {
            return [];
        }
        try {
            return ShopAPI::publishedProducts($filters, max(1, min(60, $limit)), 0);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** One active product by slug via the Shop public API; null when unavailable. */
    public static function fetchProduct(string $slug): ?array
    {
        if (!class_exists('ShopAPI') || $slug === '') {
            return null;
        }
        try {
            return ShopAPI::productBySlug($slug);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Binding context for one shop product (loop fields + product cards). */
    public static function productContext(array $p): array
    {
        $price = (float) ($p['price'] ?? 0);
        $sale = (float) ($p['sale_price'] ?? 0);
        $effective = ($sale > 0 && $sale < $price) ? $sale : $price;
        if (class_exists('ShopAPI')) {
            try {
                $effective = ShopAPI::effectivePrice($p);
            } catch (\Throwable $e) {
                // keep the locally computed effective price
            }
        }
        $slug = (string) ($p['slug'] ?? '');
        return [
            'id' => (string) ($p['id'] ?? ''),
            'title' => (string) ($p['name'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            'slug' => $slug,
            'url' => $slug !== '' ? self::siteUrl('/shop/product/' . rawurlencode($slug)) : '#',
            'image' => (string) ($p['image_url'] ?? ''),
            'price' => self::money($effective),
            'old_price' => ($sale > 0 && $sale < $price) ? self::money($price) : '',
            'excerpt' => (string) ($p['short_desc'] ?? ''),
            'category' => (string) ($p['category'] ?? ''),
            'sku' => (string) ($p['sku'] ?? ''),
        ];
    }

    /** Referenced document (loop template / mega-menu panel) via the renderer resolver. */
    public static function linkedDocument(string $slug, string $kind): ?array
    {
        return PageForgeRenderer::resolveDocument($slug, $kind);
    }

    /** Item contexts for the loop widget, per its source setting. */
    public static function loopContexts(array $s): array
    {
        $source = (string) ($s['source'] ?? 'manual');
        $count = max(1, min(60, (int) ($s['count'] ?? 6)));
        if ($source === 'products') {
            $filters = ['sort' => 'newest'];
            $cat = trim((string) ($s['category_slug'] ?? ''));
            if ($cat !== '') {
                $filters['category_slug'] = $cat;
            }
            $out = [];
            foreach (self::fetchProducts($filters, $count) as $p) {
                $out[] = self::productContext($p);
            }
            return $out;
        }
        if ($source === 'pages') {
            $out = [];
            if (class_exists('PageForgeStore')) {
                try {
                    foreach (PageForgeStore::listPages('page') as $row) {
                        if (($row['status'] ?? '') !== 'published') {
                            continue;
                        }
                        $slug = (string) ($row['slug'] ?? '');
                        $out[] = [
                            'title' => (string) ($row['title'] ?? $slug),
                            'slug' => $slug,
                            'url' => $slug !== '' ? self::siteUrl('/forge/' . rawurlencode($slug)) : '#',
                            'image' => '', 'text' => '', 'excerpt' => '', 'price' => '', 'old_price' => '',
                        ];
                        if (count($out) >= $count) {
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    return [];
                }
            }
            return $out;
        }
        $out = [];
        foreach ((is_array($s['items'] ?? null) ? $s['items'] : []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'title' => (string) ($item['title'] ?? ''),
                'text' => (string) ($item['text'] ?? ''),
                'image' => (string) ($item['image'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'price' => '', 'old_price' => '', 'excerpt' => (string) ($item['text'] ?? ''),
            ];
        }
        return $out;
    }

    /** Shared product-card markup (product-grid + loop fallback). */
    public static function productCard(array $ctx, bool $showPrice, string $buttonText): string
    {
        $html = '<article class="pf-product-card">';
        if (($ctx['image'] ?? '') !== '') {
            $html .= '<img class="pf-product-card__img" src="' . self::escUrl((string) $ctx['image']) . '" alt="' . self::esc((string) ($ctx['title'] ?? '')) . '" loading="lazy">';
        }
        if (($ctx['old_price'] ?? '') !== '') {
            $html .= '<span class="pf-product-card__badge">Sale</span>';
        }
        $html .= '<div class="pf-product-card__body"><h3 class="pf-product-card__title"><a href="' . self::escUrl((string) ($ctx['url'] ?? '#')) . '">' . self::esc((string) ($ctx['title'] ?? '')) . '</a></h3>';
        if (($ctx['category'] ?? '') !== '') {
            $html .= '<p class="pf-product-card__cat">' . self::esc((string) $ctx['category']) . '</p>';
        }
        if (($ctx['excerpt'] ?? '') !== '') {
            $html .= '<p class="pf-product-card__excerpt">' . self::esc((string) $ctx['excerpt']) . '</p>';
        }
        if ($showPrice && ($ctx['price'] ?? '') !== '') {
            $html .= '<p class="pf-product-card__price">' . self::esc((string) $ctx['price']);
            if (($ctx['old_price'] ?? '') !== '') {
                $html .= ' <del>' . self::esc((string) $ctx['old_price']) . '</del>';
            }
            $html .= '</p>';
        }
        $html .= '<a class="pf-product-card__btn" href="' . self::escUrl((string) ($ctx['url'] ?? '#')) . '">' . self::esc($buttonText) . '</a>';
        return $html . '</div></article>';
    }

    /**
     * Phase 5 widget set (SPEC: Pro tier-2) — Shop widgets on the Kohevo
     * Shop public API, the loop builder with {{field}} dynamic binding, and
     * the mega-menu. Every widget degrades to deterministic placeholder
     * markup when its data source is unavailable, so no control is ever
     * decorative: the audit compiles without Shop/DB and must still see
     * every control's effect.
     *
     * @return array<string, array>
     */
    private static function phase5Widgets(): array
    {
        $text = ['type' => 'text'];
        $yesNo = ['type' => 'select', 'options' => ['yes', 'no']];
        $style = ['text_align', 'color', 'background_color', 'padding', 'border_radius', 'box_shadow'];
        $w = [];

        $w['product-grid'] = [
            'label' => 'Product Grid (Shop)', 'icon' => 'cart',
            'controls' => [
                'category_slug' => $text,
                'featured_only' => $yesNo + ['default' => 'no'],
                'sort' => ['type' => 'select', 'options' => ['newest', 'price-asc', 'price-desc', 'name', 'featured'], 'default' => 'newest'],
                'limit' => ['type' => 'number', 'min' => 1, 'max' => 60, 'default' => 12],
                'columns' => ['type' => 'select', 'options' => ['2', '3', '4'], 'default' => '3'],
                'show_price' => $yesNo + ['default' => 'yes'],
                'button_text' => $text + ['default' => 'View product'],
            ],
            'style_controls' => $style,
            'render' => static function (array $s, string $children, array $el): string {
                $colsRaw = (string) ($s['columns'] ?? '3');
                $cols = in_array($colsRaw, ['2', '3', '4'], true) ? $colsRaw : '3';
                $limit = max(1, min(60, (int) ($s['limit'] ?? 12)));
                $sort = (string) ($s['sort'] ?? 'newest');
                $featured = (string) ($s['featured_only'] ?? 'no');
                $category = trim((string) ($s['category_slug'] ?? ''));
                $showPrice = (string) ($s['show_price'] ?? 'yes') === 'yes';
                $buttonText = (string) ($s['button_text'] ?? 'View product');
                $filters = ['sort' => $sort];
                if ($category !== '') {
                    $filters['category_slug'] = $category;
                }
                if ($featured === 'yes') {
                    $filters['featured'] = 1;
                }
                $cards = '';
                $products = self::fetchProducts($filters, $limit);
                if ($products !== []) {
                    foreach ($products as $p) {
                        $cards .= self::productCard(self::productContext($p), $showPrice, $buttonText);
                    }
                } else {
                    $sample = [
                        'title' => 'Sample product', 'url' => '#', 'image' => '',
                        'price' => self::money(49.99), 'old_price' => '',
                        'excerpt' => 'Product preview — Shop products will appear here.',
                        'category' => $category,
                    ];
                    for ($i = 0; $i < $limit; $i++) {
                        $cards .= self::productCard($sample, $showPrice, $buttonText);
                    }
                }
                return '<div' . self::elAttrs($el, $s) . ' class="pf-products pf-grid ' . self::elClass($el) . '" style="--pf-cols:' . $cols
                    . '" data-limit="' . $limit . '" data-sort="' . self::esc($sort) . '" data-featured="' . self::esc($featured)
                    . '" data-category="' . self::esc($category) . '">'
                    . $cards . '</div>';
            },
        ];

        $w['product-view'] = [
            'label' => 'Product (Shop)', 'icon' => 'cart',
            'controls' => [
                'product_slug' => $text,
                'show_price' => $yesNo + ['default' => 'yes'],
                'show_desc' => $yesNo + ['default' => 'yes'],
                'button_text' => $text + ['default' => 'View product'],
            ],
            'style_controls' => $style,
            'render' => static function (array $s, string $children, array $el): string {
                $slug = trim((string) ($s['product_slug'] ?? ''));
                $showPrice = (string) ($s['show_price'] ?? 'yes') === 'yes';
                $showDesc = (string) ($s['show_desc'] ?? 'yes') === 'yes';
                $buttonText = (string) ($s['button_text'] ?? 'View product');
                $p = self::fetchProduct($slug);
                if ($p === null) {
                    $html = '<div' . self::elAttrs($el, $s) . ' class="pf-product-view pf-product-view--empty ' . self::elClass($el) . '">'
                        . '<h3 class="pf-product-view__title">Sample product</h3>';
                    if ($slug !== '') {
                        $html .= '<p class="pf-product-view__slug">' . self::esc($slug) . '</p>';
                    }
                    if ($showPrice) {
                        $html .= '<p class="pf-product-view__price">' . self::esc(self::money(49.99)) . '</p>';
                    }
                    if ($showDesc) {
                        $html .= '<p class="pf-product-view__desc">Product preview — the live product will appear here.</p>';
                    }
                    return $html . '<a class="pf-product-view__btn" href="#">' . self::esc($buttonText) . '</a></div>';
                }
                $ctx = self::productContext($p);
                $desc = (string) ($p['short_desc'] ?? '') !== '' ? (string) $p['short_desc'] : (string) ($p['description'] ?? '');
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-product-view ' . self::elClass($el) . '">';
                if ($ctx['image'] !== '') {
                    $html .= '<img class="pf-product-view__img" src="' . self::escUrl($ctx['image']) . '" alt="' . self::esc($ctx['title']) . '">';
                }
                $html .= '<div class="pf-product-view__body"><h2 class="pf-product-view__title">' . self::esc($ctx['title']) . '</h2>';
                if ($showPrice) {
                    $html .= '<p class="pf-product-view__price">' . self::esc($ctx['price']);
                    if ($ctx['old_price'] !== '') {
                        $html .= ' <del>' . self::esc($ctx['old_price']) . '</del>';
                    }
                    $html .= '</p>';
                }
                if ($showDesc && $desc !== '') {
                    $html .= '<p class="pf-product-view__desc">' . self::esc($desc) . '</p>';
                }
                return $html . '<a class="pf-product-view__btn" href="' . self::escUrl($ctx['url']) . '">' . self::esc($buttonText) . '</a></div></div>';
            },
        ];

        $w['loop'] = [
            'label' => 'Loop Builder', 'icon' => 'link',
            'controls' => [
                'source' => ['type' => 'select', 'options' => ['products', 'pages', 'manual'], 'default' => 'manual'],
                'category_slug' => $text + ['condition' => ['field' => 'source', 'value' => 'products']],
                'template_slug' => $text + ['condition' => ['field' => 'source', 'in' => ['products', 'pages']]],
                'count' => ['type' => 'number', 'min' => 1, 'max' => 60, 'default' => 6],
                'columns' => ['type' => 'select', 'options' => ['1', '2', '3', '4'], 'default' => '3'],
                'items' => ['type' => 'repeater', 'max_items' => 60, 'condition' => ['field' => 'source', 'value' => 'manual'], 'item_schema' => [
                    'title' => ['type' => 'text'],
                    'text' => ['type' => 'textarea'],
                    'image' => ['type' => 'media'],
                    'url' => ['type' => 'url'],
                ]],
            ],
            'style_controls' => $style,
            'render' => static function (array $s, string $children, array $el): string {
                $source = (string) ($s['source'] ?? 'manual');
                $count = max(1, min(60, (int) ($s['count'] ?? 6)));
                $colsRaw = (string) ($s['columns'] ?? '3');
                $cols = in_array($colsRaw, ['1', '2', '3', '4'], true) ? $colsRaw : '3';
                $templateSlug = trim((string) ($s['template_slug'] ?? ''));
                $category = trim((string) ($s['category_slug'] ?? ''));
                $contexts = self::loopContexts($s);
                if ($contexts === []) {
                    $sampleTitle = $source === 'products' ? 'Sample product' : ($source === 'pages' ? 'Sample page' : 'Sample item');
                    $contexts = [['title' => $sampleTitle, 'text' => '', 'image' => '', 'url' => '#', 'price' => '', 'old_price' => '', 'excerpt' => '']];
                }
                $contexts = array_slice($contexts, 0, $count);
                $template = $templateSlug !== '' ? self::linkedDocument($templateSlug, 'loop-item') : null;
                $itemsHtml = '';
                foreach ($contexts as $ctx) {
                    if ($template !== null) {
                        $inner = PageForgeRenderer::renderFragment($template, $ctx);
                    } else {
                        $card = $ctx;
                        $card['excerpt'] = (string) ($ctx['excerpt'] ?? '') !== '' ? (string) $ctx['excerpt'] : (string) ($ctx['text'] ?? '');
                        $inner = self::productCard($card, (string) ($ctx['price'] ?? '') !== '', 'View');
                    }
                    $itemsHtml .= '<div class="pf-loop__item">' . $inner . '</div>';
                }
                return '<div' . self::elAttrs($el, $s) . ' class="pf-loop pf-grid ' . self::elClass($el) . '" style="--pf-cols:' . $cols
                    . '" data-source="' . self::esc($source) . '" data-count="' . $count . '" data-template="' . self::esc($templateSlug)
                    . '" data-category="' . self::esc($category) . '">'
                    . $itemsHtml . '</div>';
            },
        ];

        $w['mega-menu'] = [
            'label' => 'Mega Menu', 'icon' => 'arrow-right',
            'controls' => [
                'items' => ['type' => 'repeater', 'max_items' => 12, 'item_schema' => [
                    'label' => ['type' => 'text'],
                    'url' => ['type' => 'url'],
                    'panel_slug' => ['type' => 'text'],
                ]],
            ],
            'style_controls' => $style,
            'render' => static function (array $s, string $children, array $el): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                if ($items === []) {
                    $items = [['label' => 'Menu item', 'url' => '#', 'panel_slug' => '']];
                }
                $lis = '';
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $label = (string) ($item['label'] ?? '');
                    $url = (string) ($item['url'] ?? '');
                    $panelSlug = trim((string) ($item['panel_slug'] ?? ''));
                    $lis .= '<li class="pf-megamenu__item' . ($panelSlug !== '' ? ' pf-megamenu__item--panel' : '') . '">'
                        . '<a class="pf-megamenu__link" href="' . self::escUrl($url !== '' ? $url : '#') . '">' . self::esc($label) . '</a>';
                    if ($panelSlug !== '') {
                        $doc = self::linkedDocument($panelSlug, 'part');
                        if ($doc !== null) {
                            $lis .= '<div class="pf-megamenu__panel">' . PageForgeRenderer::renderFragment($doc) . '</div>';
                        } else {
                            $lis .= '<div class="pf-megamenu__panel pf-megamenu__panel--empty" data-panel="' . self::esc($panelSlug) . '"></div>';
                        }
                    }
                    $lis .= '</li>';
                }
                return '<nav' . self::elAttrs($el, $s) . ' class="pf-megamenu ' . self::elClass($el) . '"><ul class="pf-megamenu__list">' . $lis . '</ul></nav>';
            },
        ];

        return $w;
    }

    /** Batch F: four wholly-new widgets (Elementor parity programme). */
    private static function batchFWidgets(): array
    {
        $text = ['type' => 'text'];
        $w = [];

        $w['soundcloud'] = [
            'label' => 'SoundCloud', 'icon' => 'play',
            'controls' => [
                'soundcloud_url' => ['type' => 'url'],
                'visual_player' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'off'],
                'autoplay' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'off'],
                'show_comments' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'off'],
                'show_username' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'off'],
                'show_related' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'off'],
                'buy_button' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'off'],
                'sharing' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'off'],
                'download' => ['type' => 'select', 'options' => ['off', 'on'], 'default' => 'off'],
                'sc_height' => ['type' => 'number', 'default' => 166, 'min' => 100, 'max' => 600],
            ],
            'render' => static function (array $s, string $children, array $el): string {
                $url = trim((string) ($s['soundcloud_url'] ?? ''));
                // Only soundcloud.com URLs can embed. Anything else gets a
                // visible placeholder (naming the URL when one was given)
                // instead of a blank hole — and the audit can tell the
                // placeholder states apart.
                if ($url === '') {
                    return '<div' . self::elAttrs($el, $s) . ' class="pf-soundcloud pf-soundcloud--empty ' . self::elClass($el) . '">SoundCloud track/playlist URL dao.</div>';
                }
                if (preg_match('#^https?://(www\.|on\.)?soundcloud\.com/#i', $url) !== 1) {
                    return '<div' . self::elAttrs($el, $s) . ' class="pf-soundcloud pf-soundcloud--empty ' . self::elClass($el) . '">Ei URL SoundCloud embed na: ' . self::esc($url) . '</div>';
                }
                $params = [
                    'url' => $url,
                    'visual' => ($s['visual_player'] ?? 'off') === 'on' ? 'true' : 'false',
                    'auto_play' => ($s['autoplay'] ?? 'off') === 'on' ? 'true' : 'false',
                    'show_comments' => ($s['show_comments'] ?? 'off') === 'on' ? 'true' : 'false',
                    'show_user' => ($s['show_username'] ?? 'off') === 'on' ? 'true' : 'false',
                    'hide_related' => ($s['show_related'] ?? 'off') === 'on' ? 'false' : 'true',
                    'buying' => ($s['buy_button'] ?? 'off') === 'on' ? 'true' : 'false',
                    'sharing' => ($s['sharing'] ?? 'off') === 'on' ? 'true' : 'false',
                    'download' => ($s['download'] ?? 'off') === 'on' ? 'true' : 'false',
                ];
                $h = max(100, min(600, (int) ($s['sc_height'] ?? 166)));
                $src = 'https://w.soundcloud.com/player/?' . http_build_query($params, '', '&');
                return '<div' . self::elAttrs($el, $s) . ' class="pf-soundcloud ' . self::elClass($el) . '">'
                    . '<iframe width="100%" height="' . $h . '" scrolling="no" frameborder="no" allow="autoplay" src="' . self::esc($src) . '"></iframe></div>';
            },
        ];

        $w['read-more'] = [
            'label' => 'Read More', 'icon' => 'text',
            'controls' => [
                'teaser_text' => ['type' => 'textarea', 'default' => ''],
                'full_text' => ['type' => 'textarea', 'default' => ''],
                'more_text' => ['type' => 'text', 'default' => 'Read More'],
                'less_text' => ['type' => 'text', 'default' => 'Show Less'],
            ],
            'style_controls' => ['readmore_btn_color', 'readmore_btn_size'],
            'render' => static function (array $s, string $children, array $el): string {
                $teaser = trim((string) ($s['teaser_text'] ?? ''));
                $full = trim((string) ($s['full_text'] ?? ''));
                if ($teaser === '' && $full === '') return '';
                $more = (string) ($s['more_text'] ?? '') !== '' ? (string) $s['more_text'] : 'Read More';
                $less = (string) ($s['less_text'] ?? '') !== '' ? (string) $s['less_text'] : 'Show Less';
                $decls = [];
                $c = self::boxColor($s['readmore_btn_color'] ?? null);
                if ($c !== null) $decls['color'] = $c;
                $size = self::boxNum($s['readmore_btn_size'] ?? null);
                if ($size !== null) $decls['font-size'] = self::boxFmt($size) . 'px';
                $btnStyle = self::boxStyle($decls);
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-readmore ' . self::elClass($el) . '">';
                if ($teaser !== '') $html .= '<p>' . nl2br(self::esc($teaser)) . '</p>';
                if ($full !== '') {
                    // Native <details> toggle: no JS, works everywhere, and
                    // the open/closed labels swap by CSS alone.
                    $html .= '<details class="pf-readmore__details"><summary' . $btnStyle . '>'
                        . '<span class="pf-readmore__more">' . self::esc($more) . '</span>'
                        . '<span class="pf-readmore__less">' . self::esc($less) . '</span></summary>'
                        . '<div class="pf-readmore__full"><p>' . nl2br(self::esc($full)) . '</p></div></details>';
                }
                return $html . '</div>';
            },
        ];

        $w['text-path'] = [
            'label' => 'Text Path', 'icon' => 'quote',
            'controls' => [
                'path_text' => ['type' => 'text', 'default' => ''],
                'path_shape' => ['type' => 'select', 'options' => ['wave', 'arch-up', 'arch-down', 'circle'], 'default' => 'wave'],
                'path_link' => ['type' => 'url'],
            ],
            'style_controls' => ['font_size', 'color', 'letter_spacing', 'text_align'],
            'render' => static function (array $s, string $children, array $el): string {
                $text = trim((string) ($s['path_text'] ?? ''));
                if ($text === '') return '';
                $shape = in_array($s['path_shape'] ?? '', ['wave', 'arch-up', 'arch-down', 'circle'], true) ? $s['path_shape'] : 'wave';
                $paths = [
                    'wave' => 'M0,55 Q50,10 100,55 T200,55 T300,55 T400,55',
                    'arch-up' => 'M0,100 Q200,-40 400,100',
                    'arch-down' => 'M0,20 Q200,160 400,20',
                    'circle' => 'M200,20 a80,80 0 1,1 -0.1,0',
                ];
                $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($el['id'] ?? 'tp'));
                // Alignment rides on the text anchor (the svg is a full-width
                // block, so root text-align alone would do nothing).
                $align = in_array($s['text_align'] ?? '', ['left', 'center', 'right'], true) ? $s['text_align'] : 'center';
                [$offset, $anchor] = $align === 'left' ? ['0%', 'start'] : ($align === 'right' ? ['100%', 'end'] : ['50%', 'middle']);
                $svg = '<svg class="pf-textpath__svg" viewBox="0 0 400 120" role="img" aria-label="' . self::esc($text) . '">'
                    . '<defs><path id="pf-tp-' . $uid . '" d="' . $paths[$shape] . '" fill="none"/></defs>'
                    . '<text><textPath href="#pf-tp-' . $uid . '" startOffset="' . $offset . '" text-anchor="' . $anchor . '">' . self::esc($text) . '</textPath></text></svg>';
                if (!empty($s['path_link'])) {
                    $svg = '<a href="' . self::escUrl((string) $s['path_link']) . '">' . $svg . '</a>';
                }
                return '<div' . self::elAttrs($el, $s) . ' class="pf-textpath ' . self::elClass($el) . '">' . $svg . '</div>';
            },
        ];

        $w['link-in-bio'] = [
            'label' => 'Link in Bio', 'icon' => 'link',
            'controls' => [
                'avatar' => ['type' => 'media'],
                'profile_name' => $text,
                'profile_bio' => ['type' => 'textarea', 'default' => ''],
                'social_items' => ['type' => 'repeater', 'max_items' => 12, 'item_schema' => [
                    'network' => ['type' => 'select', 'options' => ['facebook', 'x', 'instagram', 'linkedin', 'youtube', 'github', 'whatsapp', 'telegram'], 'default' => 'facebook'],
                    'url' => ['type' => 'url'],
                ]],
                'link_items' => ['type' => 'repeater', 'max_items' => 20, 'item_schema' => [
                    'label' => $text,
                    'url' => ['type' => 'url'],
                ]],
            ],
            'style_controls' => ['bio_avatar_size', 'bio_avatar_shape', 'text_align',
                'title_color', 'title_font_size', 'title_font_weight',
                'desc_color', 'desc_font_size', 'desc_font_weight',
                'bio_link_bg', 'bio_link_color', 'bio_link_radius', 'bio_link_spacing'],
            'render' => static function (array $s, string $children, array $el): string {
                $name = trim((string) ($s['profile_name'] ?? ''));
                $bio = trim((string) ($s['profile_bio'] ?? ''));
                $social = array_values(array_filter(is_array($s['social_items'] ?? null) ? $s['social_items'] : [], static fn ($i) => is_array($i) && !empty($i['url'])));
                $links = array_values(array_filter(is_array($s['link_items'] ?? null) ? $s['link_items'] : [], static fn ($i) => is_array($i) && !empty($i['url']) && trim((string) ($i['label'] ?? '')) !== ''));
                if ($name === '' && $bio === '' && empty($s['avatar']) && $social === [] && $links === []) return '';
                $align = in_array($s['text_align'] ?? '', ['left', 'center', 'right'], true) ? $s['text_align'] : 'center';
                $size = self::boxNum($s['bio_avatar_size'] ?? null) ?? 76;
                $shape = in_array($s['bio_avatar_shape'] ?? '', ['circle', 'square', 'rounded'], true) ? $s['bio_avatar_shape'] : 'circle';
                $radius = $shape === 'circle' ? '50%' : ($shape === 'rounded' ? '16px' : '0');
                $margin = $align === 'center' ? 'margin-left:auto;margin-right:auto;' : ($align === 'right' ? 'margin-left:auto;' : '');
                $html = '<div' . self::elAttrs($el, $s) . ' class="pf-bio ' . self::elClass($el) . '" style="text-align:' . $align . ';">';
                if (!empty($s['avatar'])) {
                    $html .= '<img class="pf-bio__avatar" src="' . self::escUrl((string) $s['avatar']) . '" alt="" loading="lazy" style="width:' . self::boxFmt($size) . 'px;height:' . self::boxFmt($size) . 'px;border-radius:' . $radius . ';' . $margin . '">';
                } elseif ($name !== '') {
                    $html .= '<div class="pf-bio__avatar pf-bio__avatar--initial" style="width:' . self::boxFmt($size) . 'px;height:' . self::boxFmt($size) . 'px;border-radius:' . $radius . ';font-size:' . self::boxFmt($size / 2) . 'px;' . $margin . '">' . self::esc(mb_substr($name, 0, 1)) . '</div>';
                }
                if ($name !== '') $html .= '<div class="pf-bio__name"' . self::boxStyle(self::boxTitleDecls($s)) . '>' . self::esc($name) . '</div>';
                if ($bio !== '') $html .= '<div class="pf-bio__bio"' . self::boxStyle(self::boxDescDecls($s)) . '><p>' . nl2br(self::esc($bio)) . '</p></div>';
                if ($social !== []) {
                    $initials = ['facebook' => 'f', 'x' => 'X', 'instagram' => 'Ig', 'linkedin' => 'in', 'youtube' => 'Yt', 'github' => 'Gh', 'whatsapp' => 'Wa', 'telegram' => 'Tg'];
                    $justify = $align === 'left' ? 'flex-start' : ($align === 'right' ? 'flex-end' : 'center');
                    $html .= '<div class="pf-bio__social" style="justify-content:' . $justify . ';">';
                    foreach ($social as $item) {
                        $html .= '<a href="' . self::escUrl((string) $item['url']) . '">' . self::esc($initials[(string) ($item['network'] ?? '')] ?? '?') . '</a>';
                    }
                    $html .= '</div>';
                }
                if ($links !== []) {
                    $lDecls = [];
                    $bg = self::boxColor($s['bio_link_bg'] ?? null);
                    if ($bg !== null) $lDecls['background-color'] = $bg;
                    $lc = self::boxColor($s['bio_link_color'] ?? null);
                    if ($lc !== null) $lDecls['color'] = $lc;
                    $lr = self::boxNum($s['bio_link_radius'] ?? null);
                    if ($lr !== null) $lDecls['border-radius'] = self::boxFmt($lr) . 'px';
                    $lStyle = self::boxStyle($lDecls);
                    $gap = self::boxNum($s['bio_link_spacing'] ?? null);
                    $html .= '<div class="pf-bio__links"' . ($gap !== null ? ' style="gap:' . self::boxFmt($gap) . 'px;"' : '') . '>';
                    foreach ($links as $item) {
                        $html .= '<a href="' . self::escUrl((string) $item['url']) . '"' . $lStyle . '>' . self::esc((string) $item['label']) . '</a>';
                    }
                    $html .= '</div>';
                }
                return $html . '</div>';
            },
        ];

        return $w;
    }
}
