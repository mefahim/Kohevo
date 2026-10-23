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

final class PageForgeCss
{
    public const BREAKPOINT_TABLET = 1024;
    public const BREAKPOINT_MOBILE = 767;

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
        ];
    }

    private static function defaultStyleKeys(): array
    {
        return ['color', 'background_color', 'font_size', 'font_weight', 'line_height', 'text_align',
            'margin', 'padding', 'border_radius'];
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
            $css .= "\n@media (max-width: " . self::BREAKPOINT_TABLET . "px) {\n" . implode("\n", $tablet) . "\n}";
        }
        if ($mobile !== []) {
            $css .= "\n@media (max-width: " . self::BREAKPOINT_MOBILE . "px) {\n" . implode("\n", $mobile) . "\n}";
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
                $keys = $def['style_controls'] ?? self::defaultStyleKeys();
                // controls_are_style maps content controls onto CSS props too (e.g. spacer height).
                foreach (($def['controls_are_style'] ?? []) as $controlKey => $cssKey) {
                    if (!in_array($cssKey, $keys, true)) {
                        $keys[] = $cssKey;
                    }
                }
                $selector = '.pf-page-' . $pageId . ' .' . PageForgeWidgets::elClass($el);
                $d = self::rulesFor($settings, $keys, '');
                if ($d !== '') $desktop[] = $selector . ' { ' . $d . ' }';
                $t = self::rulesFor($settings, $keys, '_tablet');
                if ($t !== '') $tablet[] = $selector . ' { ' . $t . ' }';
                $m = self::rulesFor($settings, $keys, '_mobile');
                if ($m !== '') $mobile[] = $selector . ' { ' . $m . ' }';

                // Containers are flex parents by construction.
                if (($el['elType'] ?? '') === 'container') {
                    $desktop[] = $selector . ' { display: flex; }';
                }
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                self::walk($el['elements'], $pageId, $desktop, $tablet, $mobile);
            }
        }
    }

    private static function rulesFor(array $settings, array $keys, string $suffix): string
    {
        $controls = self::styleControls();
        $rules = [];
        foreach ($keys as $key) {
            $control = $controls[$key] ?? null;
            if ($control === null) {
                continue;
            }
            if ($suffix !== '' && empty($control['responsive'])) {
                continue;
            }
            $value = $settings[$key . $suffix] ?? null;
            if ($value === null || $value === '' || $value === []) {
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

    private static function cssValue(array $control, $value): ?string
    {
        switch ($control['type']) {
            case 'color':
                $v = trim((string) $value);
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
                return in_array($value, $control['options'] ?? [], true) ? (string) $value : null;
            default:
                return null;
        }
    }
}
