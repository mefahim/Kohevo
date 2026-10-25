<?php
/**
 * PageForge Kit — the design-system object (Phase 6).
 *
 * Sync model (per Fahim's requirement): the Kit does NOT fork the site's
 * settings. Its defaults are derived LIVE from the platform's current site
 * settings (Settings → Branding / General): brand_accent_color becomes the
 * Kit primary/accent/link, brand_font_family becomes the heading/body
 * fonts, brand_button_text_mode decides the on-accent button text, and
 * brand_border_radius becomes the button radius. Change the site branding
 * and every PageForge page recompiled afterwards follows — no second
 * place to keep in sync.
 *
 * PageForge-specific choices (a different secondary color, a wider
 * content width, custom breakpoints, site-wide custom CSS) are stored as
 * OVERRIDES only: one JSON value under the settings key `pageforge_kit`.
 * An empty override field means "follow the site", never a copied value.
 *
 * Globals: any color control in any widget may hold a whole-value token
 * `{{kit.primary}}` (also secondary/text/accent/background/link/
 * button_background/button_text). PageForgeCss resolves tokens at
 * compile time against this Kit; an unknown or deleted token resolves
 * to nothing and the declaration is dropped (the control's unstyled
 * fallback), never a fatal and never a literal token in the CSS.
 *
 * All platform-settings access is defensive (method_exists + try/catch):
 * the engine also runs in CLI harnesses whose Database stub has no
 * settings API, where the documented static fallbacks apply.
 */

declare(strict_types=1);

final class PageForgeKit
{
    public const SETTING_KEY = 'pageforge_kit';

    /** PageForge's own accent — used only when the site has no brand accent. */
    public const FALLBACK_ACCENT = '#454299';

    /** Test/preview hook: wins over stored overrides when set. */
    private static ?array $runtimeOverrides = null;

    public static function setRuntimeOverrides(?array $overrides): void
    {
        self::$runtimeOverrides = $overrides;
    }

    /** Font stacks for the core `brand_font_family` allow-list (admin/settings.php). */
    public static function fontStacks(): array
    {
        return [
            'system'       => '-apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif',
            'inter'        => '"Inter", -apple-system, "Segoe UI", Roboto, sans-serif',
            'plus-jakarta' => '"Plus Jakarta Sans", -apple-system, "Segoe UI", Roboto, sans-serif',
            'outfit'       => '"Outfit", -apple-system, "Segoe UI", Roboto, sans-serif',
            'playfair'     => '"Playfair Display", Georgia, "Times New Roman", serif',
            'roboto'       => '"Roboto", -apple-system, "Segoe UI", Arial, sans-serif',
        ];
    }

    /** Button radius presets, keyed like the core `brand_border_radius` setting. */
    public static function radiusMap(): array
    {
        return ['none' => '0', 'small' => '6px', 'medium' => '10px', 'large' => '16px', 'pill' => '999px'];
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

    private static function hex(?string $v, string $fallback): string
    {
        $v = trim((string) $v);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $fallback;
    }

    /**
     * The live sync source: values read from the platform's current site
     * settings on every call. Exposed for the Site Settings screen, which
     * shows these as the read-only "synced from site" block.
     */
    public static function siteSync(): array
    {
        $fontKey = (string) (self::setting('brand_font_family') ?? 'system');
        if (!isset(self::fontStacks()[$fontKey])) {
            $fontKey = 'system';
        }
        $buttonMode = (string) (self::setting('brand_button_text_mode') ?? 'auto');
        if (!in_array($buttonMode, ['auto', 'light', 'dark'], true)) {
            $buttonMode = 'auto';
        }
        $radiusKey = (string) (self::setting('brand_border_radius') ?? 'medium');
        if (!isset(self::radiusMap()[$radiusKey])) {
            $radiusKey = 'medium';
        }
        return [
            'site_name'        => (string) (self::setting('site_name') ?? ''),
            'logo_path'        => (string) (self::setting('brand_logo_path') ?? ''),
            'favicon_path'     => (string) (self::setting('brand_favicon_path') ?? ''),
            'accent'           => self::hex(self::setting('brand_accent_color'), self::FALLBACK_ACCENT),
            'font_key'         => $fontKey,
            'button_text_mode' => $buttonMode,
            'radius_key'       => $radiusKey,
        ];
    }

    /** On-accent text color, mirroring the platform shell's contrast logic. */
    public static function onAccent(string $hex, string $mode): string
    {
        if ($mode === 'light') {
            return '#ffffff';
        }
        if ($mode === 'dark') {
            return '#15181e';
        }
        $h = ltrim($hex, '#');
        if (strlen($h) !== 6 || !ctype_xdigit($h)) {
            return '#ffffff';
        }
        $lum = (0.299 * hexdec(substr($h, 0, 2)) + 0.587 * hexdec(substr($h, 2, 2)) + 0.114 * hexdec(substr($h, 4, 2))) / 255;
        return $lum > 0.6 ? '#15181e' : '#ffffff';
    }

    /** Kit defaults derived from the current site settings. */
    public static function defaults(): array
    {
        $site = self::siteSync();
        $primary = $site['accent'];
        return [
            'colors' => [
                'primary'    => $primary,
                'secondary'  => '#6c6a9f',
                'text'       => '#1c1e24',
                'accent'     => $primary,
                'background' => '',
                'link'       => $primary,
            ],
            'fonts' => [
                'heading' => $site['font_key'],
                'body'    => $site['font_key'],
            ],
            'button' => [
                'background' => $primary,
                'text'       => self::onAccent($primary, $site['button_text_mode']),
                'radius'     => $site['radius_key'],
            ],
            // content_width 0 = no constraint (the page shell decides).
            'layout'      => ['content_width' => 0, 'widget_gap' => 20],
            'breakpoints' => ['tablet' => 1024, 'mobile' => 767],
            'custom_css'  => '',
        ];
    }

    /** Stored PageForge overrides (settings key), validated; [] when none. */
    public static function storedOverrides(): array
    {
        $raw = self::setting(self::SETTING_KEY);
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? self::validateOverrides($decoded) : [];
    }

    /**
     * The effective Kit: site-derived defaults, with stored overrides,
     * with runtime overrides (tests/previews) on top.
     */
    public static function current(): array
    {
        $kit = self::defaults();
        foreach ([self::storedOverrides(), self::$runtimeOverrides ?? []] as $overrides) {
            if ($overrides === []) {
                continue;
            }
            $valid = self::validateOverrides($overrides);
            foreach ($valid as $section => $values) {
                if ($section === 'custom_css') {
                    $kit['custom_css'] = $values;
                } elseif (is_array($values)) {
                    $kit[$section] = array_merge($kit[$section] ?? [], $values);
                }
            }
        }
        // Invariant: mobile breakpoint stays below tablet.
        $tablet = (int) $kit['breakpoints']['tablet'];
        $mobile = (int) $kit['breakpoints']['mobile'];
        if ($mobile >= $tablet) {
            $kit['breakpoints']['mobile'] = max(320, $tablet - 1);
        }
        return $kit;
    }

    /**
     * Validate raw override input. Pure (no I/O) so it is unit-testable.
     * Returns only the keys that may be stored; an absent key means
     * "follow the site / the default", which is what keeps the Kit in
     * sync instead of freezing a copy of today's branding.
     */
    public static function validateOverrides(array $in): array
    {
        $out = [];
        $hexKeys = ['primary', 'secondary', 'text', 'accent', 'background', 'link'];
        if (isset($in['colors']) && is_array($in['colors'])) {
            foreach ($hexKeys as $k) {
                $v = $in['colors'][$k] ?? null;
                if (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v))) {
                    $out['colors'][$k] = strtolower(trim($v));
                }
            }
        }
        if (isset($in['fonts']) && is_array($in['fonts'])) {
            foreach (['heading', 'body'] as $k) {
                $v = $in['fonts'][$k] ?? null;
                if (is_string($v) && isset(self::fontStacks()[$v])) {
                    $out['fonts'][$k] = $v;
                }
            }
        }
        if (isset($in['button']) && is_array($in['button'])) {
            foreach (['background', 'text'] as $k) {
                $v = $in['button'][$k] ?? null;
                if (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v))) {
                    $out['button'][$k] = strtolower(trim($v));
                }
            }
            $r = $in['button']['radius'] ?? null;
            if (is_string($r) && isset(self::radiusMap()[$r])) {
                $out['button']['radius'] = $r;
            }
        }
        if (isset($in['layout']) && is_array($in['layout'])) {
            if (isset($in['layout']['content_width']) && is_numeric($in['layout']['content_width'])) {
                $w = (int) $in['layout']['content_width'];
                $out['layout']['content_width'] = $w === 0 ? 0 : max(600, min(2000, $w));
            }
            if (isset($in['layout']['widget_gap']) && is_numeric($in['layout']['widget_gap'])) {
                $out['layout']['widget_gap'] = max(0, min(100, (int) $in['layout']['widget_gap']));
            }
        }
        if (isset($in['breakpoints']) && is_array($in['breakpoints'])) {
            if (isset($in['breakpoints']['tablet']) && is_numeric($in['breakpoints']['tablet'])) {
                $out['breakpoints']['tablet'] = max(480, min(1600, (int) $in['breakpoints']['tablet']));
            }
            if (isset($in['breakpoints']['mobile']) && is_numeric($in['breakpoints']['mobile'])) {
                $out['breakpoints']['mobile'] = max(320, min(1200, (int) $in['breakpoints']['mobile']));
            }
        }
        if (isset($in['custom_css']) && is_string($in['custom_css'])) {
            // Compiled CSS is inlined in a <style> block by the public
            // router; stripping '<' makes a </style> breakout impossible.
            $out['custom_css'] = substr(str_replace('<', '', $in['custom_css']), 0, 20000);
        }
        return $out;
    }

    /**
     * Persist overrides as the full form state (an absent key clears the
     * override and returns that value to following the site). Returns the
     * validated array actually stored.
     */
    public static function saveOverrides(array $in): array
    {
        $valid = self::validateOverrides($in);
        if (class_exists('Database') && method_exists('Database', 'setSetting')) {
            try {
                Database::setSetting(self::SETTING_KEY, json_encode($valid, JSON_UNESCAPED_SLASHES));
            } catch (Throwable) {
                // Storage unavailable (CLI harness): overrides are lost,
                // but the save path itself must never fatal.
            }
        }
        return $valid;
    }

    /** Flat token map used by the CSS compiler for {{kit.*}} references. */
    public static function tokens(array $kit): array
    {
        $stacks = self::fontStacks();
        return [
            'primary'           => $kit['colors']['primary'] ?? '',
            'secondary'         => $kit['colors']['secondary'] ?? '',
            'text'              => $kit['colors']['text'] ?? '',
            'accent'            => $kit['colors']['accent'] ?? '',
            'background'        => $kit['colors']['background'] ?? '',
            'link'              => $kit['colors']['link'] ?? '',
            'button_background' => $kit['button']['background'] ?? '',
            'button_text'       => $kit['button']['text'] ?? '',
            'font_heading'      => $stacks[$kit['fonts']['heading'] ?? 'system'] ?? $stacks['system'],
            'font_body'         => $stacks[$kit['fonts']['body'] ?? 'system'] ?? $stacks['system'],
        ];
    }

    /** Resolve one token against a kit; null when unknown (deleted-token rule). */
    public static function token(?array $kit, string $key): ?string
    {
        if ($kit === null) {
            return null;
        }
        $v = self::tokens($kit)[$key] ?? null;
        return ($v === null || $v === '') ? null : $v;
    }

    /**
     * W2 export package: the stored overrides plus a read-only snapshot
     * of the synced site identity (applied on import only when the
     * identity part is selected and the values validate).
     */
    public static function exportData(): array
    {
        $site = self::siteSync();
        return [
            'app'         => 'pageforge',
            'kind'        => 'kit',
            'version'     => 1,
            'exported_at' => date('c'),
            'identity'    => [
                'site_name' => $site['site_name'],
                'logo_path' => $site['logo_path'],
            ],
            'kit'         => self::storedOverrides(),
        ];
    }

    /**
     * W2 import merge (pure): start from the current stored overrides,
     * replace only the sections named in $parts with the validated
     * sections from the package. Unselected parts are untouched — the
     * SPEC rule for kit import. $parts keys: colors, fonts, button,
     * layout, breakpoints, custom_css (identity is handled by the
     * caller, which writes through to the core site settings).
     */
    public static function importMerge(array $payload, array $parts, array $current): array
    {
        $incoming = isset($payload['kit']) && is_array($payload['kit']) ? $payload['kit'] : $payload;
        $valid = self::validateOverrides($incoming);
        $merged = $current;
        foreach (['colors', 'fonts', 'button', 'layout', 'breakpoints'] as $section) {
            if (!empty($parts[$section])) {
                if (isset($valid[$section])) {
                    $merged[$section] = $valid[$section];
                } else {
                    unset($merged[$section]);
                }
            }
        }
        if (!empty($parts['custom_css'])) {
            if (isset($valid['custom_css'])) {
                $merged['custom_css'] = $valid['custom_css'];
            } else {
                unset($merged['custom_css']);
            }
        }
        return $merged;
    }

    /**
     * Theme-style CSS for one compiled page (S2): CSS variables, base
     * typography on the page wrapper, heading font, link color, and the
     * button theme for .pf-button. Emitted FIRST in the compiled CSS so
     * element-level rules (equal or higher specificity, later in the
     * file) always win over these site-wide fallbacks.
     */
    public static function cssFor(array $kit, int $pageId): string
    {
        $sel = '.pf-page-' . $pageId;
        $stacks = self::fontStacks();
        $body = $stacks[$kit['fonts']['body'] ?? 'system'] ?? $stacks['system'];
        $heading = $stacks[$kit['fonts']['heading'] ?? 'system'] ?? $stacks['system'];
        $radius = self::radiusMap()[$kit['button']['radius'] ?? 'medium'] ?? '10px';

        $base = '--pf-primary: ' . $kit['colors']['primary'] . '; --pf-secondary: ' . $kit['colors']['secondary']
            . '; --pf-accent: ' . $kit['colors']['accent'] . '; color: ' . $kit['colors']['text']
            . '; font-family: ' . $body . ';';
        if (($kit['colors']['background'] ?? '') !== '') {
            $base .= ' background: ' . $kit['colors']['background'] . ';';
        }
        if ((int) ($kit['layout']['content_width'] ?? 0) > 0) {
            $base .= ' max-width: ' . (int) $kit['layout']['content_width'] . 'px; margin-left: auto; margin-right: auto;';
        }
        $lines = [
            $sel . ' { ' . $base . ' }',
            $sel . ' h1, ' . $sel . ' h2, ' . $sel . ' h3, ' . $sel . ' h4, ' . $sel . ' h5, ' . $sel . ' h6 { font-family: ' . $heading . '; }',
            $sel . ' a { color: ' . $kit['colors']['link'] . '; }',
            $sel . ' .pf-button { background: ' . $kit['button']['background'] . '; color: ' . $kit['button']['text'] . '; border-radius: ' . $radius . '; }',
        ];
        return implode("\n", $lines);
    }
}
