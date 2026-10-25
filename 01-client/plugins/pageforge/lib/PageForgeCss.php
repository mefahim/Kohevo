<?php
/**
 * PageForge CSS compiler — turns element settings into scoped CSS.
 *
 * Model (deliberately close to what every mature builder does):
 *   - Every element gets a stable class `pf-el-<id>`; a page render is wrapped
 *     in `.pf-page-<pageId>` so compiled CSS can never leak across pages.
 *   - Style controls are declared once here (shared by all widgets); a widget
 *     opts into a subset via its `style_controls` list. Widgets that declare
 *     no list get the default typography/spacing subset.
 *   - Responsive overrides use the `_tablet` / `_mobile` key suffixes and are
 *     emitted inside max-width media queries (1024px / 767px).
 *
 * Only values that passed PageForgeWidgets::sanitizeSettings() should reach
 * this compiler; the compiler still re-validates colors/dimensions defensively
 * because compiled CSS is a stored artifact (defense in depth).
 */

declare(strict_types=1);

require_once __DIR__ . '/PageForgeKit.php';

final class PageForgeCss
{
    public const BREAKPOINT_TABLET = 1024;
    public const BREAKPOINT_MOBILE = 767;

    /**
     * Phase 6 wiring: the store (publish) and the preview endpoint set the
     * current Kit + breakpoints before compiling. Unset = legacy
     * behaviour, byte-identical output (all pre-Phase-6 tests rely on it).
     */
    private static ?array $kit = null;
    private static int $bpTablet = self::BREAKPOINT_TABLET;
    private static int $bpMobile = self::BREAKPOINT_MOBILE;

    public static function setKit(?array $kit): void
    {
        self::$kit = $kit;
    }

    public static function setBreakpoints(int $tablet, int $mobile): void
    {
        $tablet = max(480, min(1600, $tablet));
        $mobile = max(320, min(1200, $mobile));
        self::$bpTablet = $tablet;
        self::$bpMobile = $mobile < $tablet ? $mobile : max(320, $tablet - 1);
    }

    /** Shared style control schema: key => control def + css mapping. */
    public static function styleControls(): array
    {
        return [
            'color'            => ['type' => 'color', 'css' => ['color']],
            'background_color' => ['type' => 'color', 'css' => ['background-color']],
            'font_size'        => ['type' => 'number', 'min' => 0, 'max' => 400, 'css' => ['font-size'], 'unit' => 'px', 'responsive' => true],
            'font_weight'      => ['type' => 'select', 'options' => ['300', '400', '500', '600', '700', '800'], 'css' => ['font-weight']],
            'line_height'      => ['type' => 'number', 'min' => 0, 'max' => 5, 'css' => ['line-height']],
            'text_align'       => ['type' => 'select', 'options' => ['left', 'center', 'right', 'justify'], 'css' => ['text-align'], 'responsive' => true],
            // Width+Align build: the element's own cross-axis alignment
            // inside its flex container (align-self, not text-align — the
            // img/figure itself moves, so a narrowed image can be centered).
            'image_align'      => ['type' => 'select', 'options' => ['left', 'center', 'right'], 'css' => ['align-self'], 'responsive' => true, 'value_map' => ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end']],
            'margin'           => ['type' => 'dimensions', 'css' => ['margin'], 'responsive' => true],
            'padding'          => ['type' => 'dimensions', 'css' => ['padding'], 'responsive' => true],
            'border_radius'    => ['type' => 'number', 'min' => 0, 'max' => 500, 'css' => ['border-radius'], 'unit' => 'px'],
            'width'            => ['type' => 'number', 'min' => 0, 'max' => 100, 'css' => ['width'], 'unit' => '%', 'responsive' => true],
            'min_height'       => ['type' => 'number', 'min' => 0, 'max' => 2000, 'css' => ['min-height'], 'unit' => 'px'],
            'gap'              => ['type' => 'number', 'min' => 0, 'max' => 200, 'css' => ['gap'], 'unit' => 'px', 'responsive' => true],
            'flex_direction'   => ['type' => 'select', 'options' => ['row', 'column', 'row-reverse', 'column-reverse'], 'css' => ['flex-direction'], 'responsive' => true],
            'justify_content'  => ['type' => 'select', 'options' => ['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly'], 'css' => ['justify-content']],
            'align_items'      => ['type' => 'select', 'options' => ['stretch', 'flex-start', 'center', 'flex-end', 'baseline'], 'css' => ['align-items']],
            'height'           => ['type' => 'number', 'min' => 0, 'max' => 2000, 'css' => ['height'], 'unit' => 'px', 'responsive' => true],
            'letter_spacing'   => ['type' => 'number', 'min' => -5, 'max' => 20, 'css' => ['letter-spacing'], 'unit' => 'px', 'responsive' => true],
            'text_transform'   => ['type' => 'select', 'options' => ['none', 'uppercase', 'lowercase', 'capitalize'], 'css' => ['text-transform']],
            'font_family'      => ['type' => 'select', 'options' => ['inherit', 'system', 'serif', 'mono'], 'css' => ['font-family'], 'value_map' => [
                'inherit' => 'inherit',
                'system' => '-apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif',
                'serif' => 'Georgia, "Times New Roman", serif',
                'mono' => 'ui-monospace, Menlo, Consolas, monospace',
            ]],
            'border_width'     => ['type' => 'number', 'min' => 0, 'max' => 50, 'css' => ['border-width'], 'unit' => 'px'],
            'border_style'     => ['type' => 'select', 'options' => ['none', 'solid', 'dashed', 'dotted', 'double'], 'css' => ['border-style']],
            'border_color'     => ['type' => 'color', 'css' => ['border-color']],
            'box_shadow'       => ['type' => 'select', 'options' => ['none', 'sm', 'md', 'lg', 'xl'], 'css' => ['box-shadow'], 'value_map' => [
                'none' => 'none',
                'sm' => '0 1px 2px rgba(0,0,0,0.08)',
                'md' => '0 4px 12px rgba(0,0,0,0.12)',
                'lg' => '0 10px 30px rgba(0,0,0,0.16)',
                'xl' => '0 20px 50px rgba(0,0,0,0.22)',
            ]],
            'opacity'          => ['type' => 'number', 'min' => 0, 'max' => 1, 'css' => ['opacity'], 'unit' => ''],
            // Phase 8 — container depth + states + motion.
            'flex_wrap'           => ['type' => 'select', 'options' => ['nowrap', 'wrap', 'wrap-reverse'], 'css' => ['flex-wrap']],
            'layout_mode'         => ['type' => 'select', 'options' => ['flex', 'grid'], 'css' => ['display'], 'value_map' => ['flex' => 'flex', 'grid' => 'grid']],
            'grid_columns'        => ['type' => 'select', 'options' => ['2', '3', '4', '5', '6'], 'css' => ['grid-template-columns'], 'value_map' => ['2' => 'repeat(2, minmax(0, 1fr))', '3' => 'repeat(3, minmax(0, 1fr))', '4' => 'repeat(4, minmax(0, 1fr))', '5' => 'repeat(5, minmax(0, 1fr))', '6' => 'repeat(6, minmax(0, 1fr))']],
            'background_gradient' => ['type' => 'gradient', 'css' => ['background-image']],
            'entrance_animation'  => ['type' => 'select', 'options' => ['none', 'fade', 'slide-up', 'slide-down', 'zoom'], 'css' => ['animation'], 'value_map' => ['none' => 'none', 'fade' => 'pf-fade .7s ease both', 'slide-up' => 'pf-slide-up .7s ease both', 'slide-down' => 'pf-slide-down .7s ease both', 'zoom' => 'pf-zoom .7s ease both']],
            'color_hover'            => ['type' => 'color', 'css' => ['color'], 'hover' => true],
            'background_color_hover' => ['type' => 'color', 'css' => ['background-color'], 'hover' => true],
            'border_color_hover'     => ['type' => 'color', 'css' => ['border-color'], 'hover' => true],
            'box_shadow_hover'       => ['type' => 'select', 'options' => ['none', 'sm', 'md', 'lg', 'xl'], 'css' => ['box-shadow'], 'hover' => true, 'value_map' => ['none' => 'none', 'sm' => '0 1px 2px rgba(0,0,0,0.08)', 'md' => '0 4px 12px rgba(0,0,0,0.12)', 'lg' => '0 10px 30px rgba(0,0,0,0.16)', 'xl' => '0 20px 50px rgba(0,0,0,0.22)']],
            // Batch A — shared Advanced (Elementor parity): layout freedom,
            // transform, sticky/scroll motion, overlay. Keys with css=[] carry
            // no direct declaration; walk() composes them (transform combine,
            // overlay background layer, sticky override) and elAttrs renders
            // the scroll-fade marker. All are universal via effectiveStyleKeys.
            'position'              => ['type' => 'select', 'options' => ['default', 'relative', 'absolute', 'fixed'], 'css' => ['position'], 'responsive' => true, 'value_map' => ['default' => 'static', 'relative' => 'relative', 'absolute' => 'absolute', 'fixed' => 'fixed']],
            'position_top'          => ['type' => 'number', 'min' => -2000, 'max' => 2000, 'css' => ['top'], 'unit' => 'px', 'responsive' => true],
            'position_right'        => ['type' => 'number', 'min' => -2000, 'max' => 2000, 'css' => ['right'], 'unit' => 'px', 'responsive' => true],
            'position_bottom'       => ['type' => 'number', 'min' => -2000, 'max' => 2000, 'css' => ['bottom'], 'unit' => 'px', 'responsive' => true],
            'position_left'         => ['type' => 'number', 'min' => -2000, 'max' => 2000, 'css' => ['left'], 'unit' => 'px', 'responsive' => true],
            'z_index'               => ['type' => 'number', 'min' => -9999, 'max' => 9999, 'css' => ['z-index'], 'unit' => ''],
            'order'                 => ['type' => 'number', 'min' => -100, 'max' => 100, 'css' => ['order'], 'unit' => ''],
            'align_self'            => ['type' => 'select', 'options' => ['auto', 'flex-start', 'center', 'flex-end', 'stretch'], 'css' => ['align-self'], 'responsive' => true],
            'transform_rotate'      => ['type' => 'number', 'min' => -360, 'max' => 360, 'css' => []],
            'transform_scale'       => ['type' => 'number', 'min' => 0, 'max' => 300, 'css' => []],
            'transform_translate_x' => ['type' => 'number', 'min' => -2000, 'max' => 2000, 'css' => []],
            'transform_translate_y' => ['type' => 'number', 'min' => -2000, 'max' => 2000, 'css' => []],
            'sticky'                => ['type' => 'select', 'options' => ['off', 'top', 'bottom'], 'css' => []],
            'sticky_offset'         => ['type' => 'number', 'min' => 0, 'max' => 2000, 'css' => []],
            'scroll_fade'           => ['type' => 'select', 'options' => ['off', 'on'], 'css' => []],
            'overlay_color'         => ['type' => 'color', 'css' => []],
            'overlay_opacity'       => ['type' => 'number', 'min' => 0, 'max' => 100, 'css' => []],
            // Background image (universal, like overlay): image itself is a
            // composed layer (url() + stack order), the rest map directly
            // except the custom variants, which batchAExtra composes.
            'background_image'        => ['type' => 'media', 'css' => []],
            'background_position'     => ['type' => 'select', 'options' => ['left top', 'left center', 'left bottom', 'center top', 'center center', 'center bottom', 'right top', 'right center', 'right bottom', 'custom'], 'default' => 'center center', 'css' => ['background-position']],
            'background_position_x'   => ['type' => 'text', 'css' => []],
            'background_position_y'   => ['type' => 'text', 'css' => []],
            'background_size'         => ['type' => 'select', 'options' => ['auto', 'cover', 'contain', 'custom'], 'css' => ['background-size']],
            'background_size_width'   => ['type' => 'text', 'css' => []],
            'background_size_height'  => ['type' => 'text', 'css' => []],
            'background_repeat'       => ['type' => 'select', 'options' => ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'], 'css' => ['background-repeat']],
            'background_attachment'   => ['type' => 'select', 'options' => ['scroll', 'fixed'], 'css' => ['background-attachment']],
            // Batch B: box-widget part controls. css => [] — they are NOT
            // root declarations; the widget render applies them to its
            // parts (title, description, image, icon) as inline styles.
            // The audit probes them through the render channel.
            'image_spacing'         => ['type' => 'number', 'min' => 0, 'max' => 200, 'css' => []],
            'icon_spacing'          => ['type' => 'number', 'min' => 0, 'max' => 200, 'css' => []],
            'content_spacing'       => ['type' => 'number', 'min' => 0, 'max' => 200, 'css' => []],
            'image_border_style'    => ['type' => 'select', 'options' => ['none', 'solid', 'dashed', 'dotted', 'double'], 'css' => []],
            'image_border_width'    => ['type' => 'number', 'min' => 0, 'max' => 20, 'css' => []],
            'image_border_color'    => ['type' => 'color', 'css' => []],
            'icon_color'            => ['type' => 'color', 'css' => []],
            'icon_bg_color'         => ['type' => 'color', 'css' => []],
            'icon_rotate'           => ['type' => 'number', 'min' => -360, 'max' => 360, 'css' => []],
            'icon_hover_animation'  => ['type' => 'select', 'options' => ['none', 'grow', 'pulse', 'float', 'spin'], 'css' => []],
            'title_color'           => ['type' => 'color', 'css' => []],
            'title_font_size'       => ['type' => 'number', 'min' => 0, 'max' => 400, 'css' => []],
            'title_font_weight'     => ['type' => 'select', 'options' => ['400', '500', '600', '700'], 'css' => []],
            'desc_color'            => ['type' => 'color', 'css' => []],
            'desc_font_size'        => ['type' => 'number', 'min' => 0, 'max' => 400, 'css' => []],
            'desc_font_weight'      => ['type' => 'select', 'options' => ['400', '500', '600', '700'], 'css' => []],
            // Batch C: icon-list part controls. css => [] — layout and
            // alignment ride on the render's inline styles; divider and
            // icon-color rules live on child selectors via listExtra().
            'list_align'            => ['type' => 'select', 'options' => ['left', 'center', 'right'], 'css' => []],
            'icon_valign'           => ['type' => 'select', 'options' => ['top', 'center', 'bottom'], 'css' => []],
            'divider_on'            => ['type' => 'select', 'options' => ['off', 'on'], 'css' => []],
            'divider_style'         => ['type' => 'select', 'options' => ['solid', 'dashed', 'dotted', 'double'], 'css' => []],
            'divider_weight'        => ['type' => 'number', 'min' => 0, 'max' => 20, 'css' => []],
            'divider_width'         => ['type' => 'number', 'min' => 10, 'max' => 100, 'css' => []],
            'divider_color'         => ['type' => 'color', 'css' => []],
            'list_icon_color'       => ['type' => 'color', 'css' => []],
            'list_icon_size'        => ['type' => 'number', 'min' => 0, 'max' => 200, 'css' => []],
            'list_transition'       => ['type' => 'number', 'min' => 0, 'max' => 2, 'css' => []],
            // Batch D: gallery/carousel caption + lightbox keys. css => []
            // — captions ride on the render's figcaption styles, lightbox
            // is a behavior marker, not a declaration.
            'lightbox'              => ['type' => 'select', 'options' => ['off', 'on'], 'css' => []],
            'caption_display'       => ['type' => 'select', 'options' => ['show', 'hide'], 'css' => []],
            'caption_align'         => ['type' => 'select', 'options' => ['left', 'center', 'right'], 'css' => []],
            'caption_color'         => ['type' => 'color', 'css' => []],
            'caption_font_size'     => ['type' => 'number', 'min' => 0, 'max' => 400, 'css' => []],
            'caption_font_weight'   => ['type' => 'select', 'options' => ['400', '500', '600', '700'], 'css' => []],
            // Batch F: read-more button + link-in-bio keys. css => [] —
            // the renders apply them as inline styles on their parts.
            'readmore_btn_color'    => ['type' => 'color', 'css' => []],
            'readmore_btn_size'     => ['type' => 'number', 'min' => 0, 'max' => 400, 'css' => []],
            'bio_avatar_size'       => ['type' => 'number', 'min' => 0, 'max' => 400, 'css' => []],
            'bio_avatar_shape'      => ['type' => 'select', 'options' => ['circle', 'square', 'rounded'], 'css' => []],
            'bio_link_bg'           => ['type' => 'color', 'css' => []],
            'bio_link_color'        => ['type' => 'color', 'css' => []],
            'bio_link_radius'       => ['type' => 'number', 'min' => 0, 'max' => 100, 'css' => []],
            'bio_link_spacing'      => ['type' => 'number', 'min' => 0, 'max' => 100, 'css' => []],
        ];
    }

    /** Style keys that apply to a widget definition (its own list, or the default subset). */
    public static function effectiveStyleKeys(array $def): array
    {
        $keys = $def['style_controls'] ?? self::defaultStyleKeys();
        foreach (($def['controls_are_style'] ?? []) as $cssKey) {
            if (!in_array($cssKey, $keys, true)) $keys[] = $cssKey;
        }
        // Phase 8: hover states, gradient and motion apply to every element.
        foreach (['entrance_animation', 'background_gradient', 'color_hover', 'background_color_hover', 'border_color_hover', 'box_shadow_hover'] as $extra) {
            if (!in_array($extra, $keys, true)) $keys[] = $extra;
        }
        // Batch A: the shared Advanced set is universal — every widget gets
        // layout freedom, transform, sticky/scroll motion and overlay, plus
        // the spacing/border/width keys explicit-list widgets were missing.
        foreach (['margin', 'padding', 'border_width', 'border_style', 'border_color', 'border_radius', 'width',
            'position', 'position_top', 'position_right', 'position_bottom', 'position_left', 'z_index', 'order', 'align_self',
            'transform_rotate', 'transform_scale', 'transform_translate_x', 'transform_translate_y',
            'sticky', 'sticky_offset', 'scroll_fade', 'overlay_color', 'overlay_opacity',
            'background_image', 'background_position', 'background_position_x', 'background_position_y',
            'background_size', 'background_size_width', 'background_size_height', 'background_repeat', 'background_attachment'] as $extra) {
            if (!in_array($extra, $keys, true)) $keys[] = $extra;
        }
        return $keys;
    }

    private static function defaultStyleKeys(): array
    {
        return ['color', 'background_color', 'font_size', 'font_weight', 'line_height', 'letter_spacing',
            'text_transform', 'font_family', 'text_align', 'margin', 'padding',
            'border_width', 'border_style', 'border_color', 'border_radius', 'box_shadow', 'opacity',
            'background_gradient', 'entrance_animation'];
    }

    /**
     * Compile a whole document tree to CSS scoped under .pf-page-<id>.
     *
     * @param array $elements top-level element list
     */
    public static function compile(array $elements, int $pageId): string
    {
        $desktop = [];
        $tablet = [];
        $mobile = [];
        self::walk($elements, $pageId, $desktop, $tablet, $mobile);

        $css = implode("\n", $desktop);
        if ($tablet !== []) {
            $css .= "\n@media (max-width: " . self::$bpTablet . "px) {\n" . implode("\n", $tablet) . "\n}";
        }
        if ($mobile !== []) {
            $css .= "\n@media (max-width: " . self::$bpMobile . "px) {\n" . implode("\n", $mobile) . "\n}";
        }
        if (self::$kit !== null) {
            // Theme style first (element rules win ties), site custom CSS last.
            $css = PageForgeKit::cssFor(self::$kit, $pageId) . "\n" . $css;
            $custom = trim((string) (self::$kit['custom_css'] ?? ''));
            if ($custom !== '') {
                $css .= "\n" . $custom;
            }
        }
        return $css;
    }

    private static function walk(array $elements, int $pageId, array &$desktop, array &$tablet, array &$mobile): void
    {
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $type = (string) ($el['widgetType'] ?? ($el['elType'] ?? ''));
            $def = PageForgeWidgets::get($type === 'container' ? 'container' : $type);
            $settings = is_array($el['settings'] ?? null) ? $el['settings'] : [];
            if ($def !== null) {
                $keys = self::effectiveStyleKeys($def);
                $selector = '.pf-page-' . $pageId . ' .' . PageForgeWidgets::elClass($el);
                $d = self::rulesFor($settings, $keys, '');
                // Batch A: composed declarations — transform combine, overlay
                // background layer, sticky override. Appended last so they win
                // ties inside the same declaration block (sticky beats the
                // position key and its offsets; overlay beats the gradient).
                $d .= self::batchAExtra($settings);
                if ($d !== '') $desktop[] = $selector . ' { ' . $d . ' }';
                $t = self::rulesFor($settings, $keys, '_tablet');
                if ($t !== '') $tablet[] = $selector . ' { ' . $t . ' }';
                $m = self::rulesFor($settings, $keys, '_mobile');
                if ($m !== '') $mobile[] = $selector . ' { ' . $m . ' }';
                // Phase 8: hover state ruleset (desktop only).
                $hd = self::rulesFor($settings, $keys, '', true);
                if ($hd !== '') $desktop[] = $selector . ':hover { ' . $hd . ' }';
                // Batch C: icon-list part rules (divider + dedicated icon
                // color) live on child selectors, so they are their own
                // rule blocks, not root declarations.
                if ($type === 'icon-list') {
                    foreach (self::listExtra($settings, $selector) as $rule) $desktop[] = $rule;
                }
                // Phase 8: per-element custom CSS, scoped — '&' is the
                // element selector; bare declarations are wrapped in it.
                // '<' is stripped at save time and again here, so custom
                // CSS can never break out of the inline <style> block.
                $cc = trim(str_replace('<', '', (string) ($settings['custom_css'] ?? '')));
                if ($cc !== '') {
                    $desktop[] = str_contains($cc, '&')
                        ? str_replace('&', $selector, $cc)
                        : $selector . ' { ' . $cc . ' }';
                }

                // Containers are flex parents by construction.
                if (($el['elType'] ?? '') === 'container') {
                    if (($settings['layout_mode'] ?? 'flex') !== 'grid') {
                        $desktop[] = $selector . ' { display: flex; }';
                    }
                    // Kit layout (S3): a container without its own gap
                    // inherits the site-wide widget gap.
                    if (self::$kit !== null && !isset($settings['gap'])) {
                        $desktop[] = $selector . ' { gap: ' . (int) (self::$kit['layout']['widget_gap'] ?? 20) . 'px; }';
                    }
                }
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                self::walk($el['elements'], $pageId, $desktop, $tablet, $mobile);
            }
        }
    }

    private static function rulesFor(array $settings, array $keys, string $suffix, bool $hoverOnly = false): string
    {
        $controls = self::styleControls();
        $rules = [];
        foreach ($keys as $key) {
            $control = $controls[$key] ?? null;
            if ($control === null) {
                continue;
            }
            if (!empty($control['hover']) !== $hoverOnly) {
                continue;
            }
            if ($suffix !== '' && empty($control['responsive'])) {
                continue;
            }
            $value = $settings[$key . $suffix] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            // Batch A: position offsets only apply to a positioned element.
            // Same-suffix position wins, falling back to the desktop value
            // (device inheritance). The audit probes offsets with a
            // position=absolute context, so a lone offset never silently
            // passes while doing nothing.
            if (in_array($key, ['position_top', 'position_right', 'position_bottom', 'position_left'], true)) {
                $pos = $settings['position' . $suffix] ?? ($suffix !== '' ? ($settings['position'] ?? 'default') : 'default');
                if (!in_array($pos, ['relative', 'absolute', 'fixed'], true)) {
                    continue;
                }
            }
            // Background image: the 'custom' variants are composed in
            // batchAExtra — emitting the literal word would be invalid CSS.
            if (($key === 'background_position' || $key === 'background_size') && $value === 'custom') {
                continue;
            }
            foreach ($control['css'] as $prop) {
                $cssVal = self::cssValue($control, $value);
                if ($cssVal !== null) {
                    $rules[] = $prop . ': ' . $cssVal . ';';
                }
            }
        }
        return implode(' ', $rules);
    }

    /**
     * Batch A composed declarations (desktop only — the composed keys are
     * not responsive in v1). Each piece is independent: transform combines
     * its parts into one declaration; overlay becomes the top background
     * layer (backgrounds always paint below content, so no pseudo-element
     * or z-index fight); sticky overrides the position key.
     */
    private static function batchAExtra(array $settings): string
    {
        $extra = '';
        $parts = [];
        if (isset($settings['transform_rotate']) && is_numeric($settings['transform_rotate'])) {
            $parts[] = 'rotate(' . self::fmtNum((float) $settings['transform_rotate']) . 'deg)';
        }
        if (isset($settings['transform_scale']) && is_numeric($settings['transform_scale'])) {
            $parts[] = 'scale(' . self::fmtNum((float) $settings['transform_scale'] / 100) . ')';
        }
        $tx = isset($settings['transform_translate_x']) && is_numeric($settings['transform_translate_x']) ? self::fmtNum((float) $settings['transform_translate_x']) . 'px' : null;
        $ty = isset($settings['transform_translate_y']) && is_numeric($settings['transform_translate_y']) ? self::fmtNum((float) $settings['transform_translate_y']) . 'px' : null;
        if ($tx !== null && $ty !== null) $parts[] = 'translate(' . $tx . ', ' . $ty . ')';
        elseif ($tx !== null) $parts[] = 'translateX(' . $tx . ')';
        elseif ($ty !== null) $parts[] = 'translateY(' . $ty . ')';
        if ($parts !== []) {
            $extra .= ' transform: ' . implode(' ', $parts) . ';';
        }
        $controls = self::styleControls();
        // Background-image layer stack, top to bottom: overlay, image,
        // gradient. Unset layers are skipped, so pages without an image
        // compile byte-identically to before this feature.
        $layers = [];
        $ovColor = isset($settings['overlay_color']) ? self::cssValue($controls['overlay_color'], $settings['overlay_color']) : null;
        if ($ovColor !== null) {
            $op = isset($settings['overlay_opacity']) && is_numeric($settings['overlay_opacity'])
                ? max(0, min(100, (float) $settings['overlay_opacity'])) : 100;
            $rgba = self::hexToRgba($ovColor, $op / 100);
            if ($rgba !== null) {
                $layers[] = 'linear-gradient(' . $rgba . ', ' . $rgba . ')';
            }
        }
        $img = isset($settings['background_image']) ? self::bgImageUrl((string) $settings['background_image']) : null;
        if ($img !== null) {
            $layers[] = $img;
        }
        $grad = isset($settings['background_gradient']) ? self::cssValue($controls['background_gradient'], $settings['background_gradient']) : null;
        if ($grad !== null) {
            $layers[] = $grad;
        }
        if ($layers !== []) {
            $extra .= ' background-image: ' . implode(', ', $layers) . ';';
        }
        // Custom background position/size: composed from their X/Y, W/H
        // parts (missing parts fall back to center/auto). Keyword position
        // and non-custom size are emitted by rulesFor directly.
        if (($settings['background_position'] ?? '') === 'custom') {
            $x = self::bgCoord($settings['background_position_x'] ?? null, ['left', 'center', 'right'], 'center');
            $y = self::bgCoord($settings['background_position_y'] ?? null, ['top', 'center', 'bottom'], 'center');
            $extra .= ' background-position: ' . $x . ' ' . $y . ';';
        }
        if (($settings['background_size'] ?? '') === 'custom') {
            $w = self::bgCoord($settings['background_size_width'] ?? null, ['auto'], 'auto');
            $h = self::bgCoord($settings['background_size_height'] ?? null, ['auto'], 'auto');
            $extra .= ' background-size: ' . $w . ' ' . $h . ';';
        }
        $sticky = (string) ($settings['sticky'] ?? 'off');
        if (in_array($sticky, ['top', 'bottom'], true)) {
            $off = isset($settings['sticky_offset']) && is_numeric($settings['sticky_offset']) ? max(0, (float) $settings['sticky_offset']) : 0;
            $extra .= ' position: sticky; ' . $sticky . ': ' . self::fmtNum($off) . 'px;';
        }
        return $extra;
    }

    /**
     * Batch C: icon-list part rules on child selectors (desktop only).
     * The dedicated icon color would otherwise pin the icon and swallow
     * the generic color_hover, so the hover value is re-applied on the
     * icon span. Divider lines are borders between items: a partial-width
     * ::before line in vertical layout, a full-height left border in
     * inline layout (where a width % is meaningless).
     */
    private static function listExtra(array $settings, string $selector): array
    {
        $rules = [];
        $controls = self::styleControls();
        $iconSel = $selector . ' .pf-icon-list__icon';
        $decls = [];
        $ic = isset($settings['list_icon_color']) ? self::cssValue($controls['list_icon_color'], $settings['list_icon_color']) : null;
        if ($ic !== null) $decls[] = 'color: ' . $ic . ';';
        $tr = isset($settings['list_transition']) && is_numeric($settings['list_transition'])
            ? max(0, min(2, (float) $settings['list_transition'])) : null;
        if ($tr !== null && $tr > 0) $decls[] = 'transition: color ' . self::fmtNum($tr) . 's;';
        if ($decls !== []) $rules[] = $iconSel . ' { ' . implode(' ', $decls) . ' }';
        if ($ic !== null) {
            $hov = isset($settings['color_hover']) ? self::cssValue($controls['color_hover'], $settings['color_hover']) : null;
            if ($hov !== null) $rules[] = $selector . ':hover .pf-icon-list__icon { color: ' . $hov . '; }';
        }
        if (($settings['divider_on'] ?? 'off') === 'on') {
            $style = in_array($settings['divider_style'] ?? '', ['solid', 'dashed', 'dotted', 'double'], true) ? $settings['divider_style'] : 'solid';
            $w = isset($settings['divider_weight']) && is_numeric($settings['divider_weight'])
                ? max(0, min(20, (float) $settings['divider_weight'])) : 1;
            $dc = isset($settings['divider_color']) ? self::cssValue($controls['divider_color'], $settings['divider_color']) : null;
            $dc = $dc ?? '#c9c9d4';
            $layout = in_array($settings['list_layout'] ?? '', ['vertical', 'inline'], true) ? $settings['list_layout'] : 'vertical';
            if ($layout === 'inline') {
                $gap = isset($settings['gap']) && is_numeric($settings['gap']) ? max(0, (float) $settings['gap']) : 8;
                $rules[] = $selector . ' li + li { border-left: ' . self::fmtNum($w) . 'px ' . $style . ' ' . $dc . '; padding-left: ' . self::fmtNum($gap) . 'px; }';
            } else {
                $width = isset($settings['divider_width']) && is_numeric($settings['divider_width'])
                    ? max(10, min(100, (float) $settings['divider_width'])) : 100;
                $rules[] = $selector . ' li + li { position: relative; }';
                $rules[] = $selector . ' li + li::before { content: ""; position: absolute; top: 0; left: 0; width: ' . self::fmtNum($width) . '%; border-top: ' . self::fmtNum($w) . 'px ' . $style . ' ' . $dc . '; }';
            }
        }
        return $rules;
    }

    /** Background-image URL: root-relative or http(s) only, safely quoted.
     *  Dynamic {{…}} tokens are skipped — CSS has no render-time substitution. */
    private static function bgImageUrl(string $v): ?string
    {
        $v = trim($v);
        if ($v === '' || str_contains($v, '{{') || str_contains($v, '}}')) {
            return null;
        }
        $ok = str_starts_with($v, '/') || preg_match('#^https?://[^\s"\'<>]+$#', $v);
        if (!$ok) {
            return null;
        }
        return 'url("' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $v) . '")';
    }

    /** A background coordinate: number+unit, a keyword, or the fallback. */
    private static function bgCoord($v, array $keywords, string $fallback): string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return $fallback;
        }
        if (in_array(strtolower($s), $keywords, true)) {
            return strtolower($s);
        }
        if (preg_match('/^-?\d+(\.\d+)?(px|%|em|rem|vw|vh)?$/', $s)) {
            return $s;
        }
        return $fallback;
    }

    private static function fmtNum(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** #rgb / #rrggbb / #rrggbbaa + opacity 0-1 -> rgba() string, or null. */
    private static function hexToRgba(string $hex, float $opacity): ?string
    {
        $h = ltrim(strtolower($hex), '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (strlen($h) === 8) {
            $opacity *= hexdec(substr($h, 6, 2)) / 255;
            $h = substr($h, 0, 6);
        }
        if (strlen($h) !== 6 || !ctype_xdigit($h)) {
            return null;
        }
        return 'rgba(' . hexdec(substr($h, 0, 2)) . ', ' . hexdec(substr($h, 2, 2)) . ', ' . hexdec(substr($h, 4, 2)) . ', ' . self::fmtNum(max(0, min(1, $opacity))) . ')';
    }

    private static function cssValue(array $control, $value): ?string
    {
        switch ($control['type']) {
            case 'color':
                $v = trim((string) $value);
                // Phase 6 globals: a whole-value {{kit.<token>}} reference
                // resolves against the wired kit; an unknown token drops
                // the declaration (deleted-token rule), never leaks raw.
                if (preg_match('/^\{\{\s*kit[:.]([a-z0-9_.-]+)\s*\}\}$/i', $v, $mm)) {
                    $resolved = PageForgeKit::token(self::$kit, strtolower($mm[1]));
                    return $resolved !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $resolved) ? strtolower($resolved) : null;
                }
                return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) ? strtolower($v) : null;
            case 'number':
                if (!is_numeric($value)) return null;
                $n = (float) $value;
                $unit = (string) ($control['unit'] ?? '');
                // line-height is unitless.
                if (($control['css'][0] ?? '') === 'line-height') $unit = '';
                return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') . $unit;
            case 'dimensions':
                if (!is_array($value)) return null;
                $unit = in_array($value['unit'] ?? 'px', ['px', '%', 'rem', 'em'], true) ? $value['unit'] : 'px';
                $parts = [];
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $parts[] = (is_numeric($value[$side] ?? null) ? (float) $value[$side] : 0) . $unit;
                }
                return implode(' ', $parts);
            case 'select':
                if (!in_array($value, $control['options'] ?? [], true)) return null;
                return (string) (($control['value_map'] ?? [])[$value] ?? $value);
            case 'gradient':
                // Strict whitelist shape: a single linear/radial gradient
                // with color/percent/position tokens only — no url(),
                // no semicolons, nothing that can escape the declaration.
                $v = trim((string) $value);
                return preg_match('/^(linear|radial)-gradient\([a-zA-Z0-9#%.,()\- ]+\)$/', $v) ? $v : null;
            default:
                return null;
        }
    }
}
