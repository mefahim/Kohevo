<?php
/**
 * PageForge display conditions + popup configuration (Phase 10).
 *
 * Two pure, storage-agnostic pieces:
 *
 * PageForgeDisplayConditions — the W3 conditions model shared by theme
 * parts and popups. A spec is {include: [rule], exclude: [rule]}; a rule
 * is {scope, value} with scope one of:
 *   entire — matches every context
 *   route  — the router's route name (page, notfound, product, ...)
 *   slug   — the current page's slug
 *   kind   — the current page's kind
 * A context matches when NO exclude rule matches AND (the include list
 * is empty OR at least one include rule matches). An empty spec therefore
 * always matches — the pre-Phase-10 unconditional behaviour.
 *
 * PageForgePopupConfig — the W4 popup settings/triggers/advanced-rules
 * model. normalize() clamps and enum-validates everything, and the
 * defaults reproduce the Phase 4 popup behaviour exactly (centered,
 * overlay on, close button on, no automatic triggers, no caps), so a
 * popup with no stored config behaves as it always has.
 */

declare(strict_types=1);

final class PageForgeDisplayConditions
{
    public const SCOPES = ['entire', 'route', 'slug', 'kind'];

    /** Sanitize a raw spec into {include: [rule], exclude: [rule]}. */
    public static function normalize($spec): array
    {
        $out = ['include' => [], 'exclude' => []];
        if (!is_array($spec)) {
            return $out;
        }
        foreach (['include', 'exclude'] as $side) {
            $rules = $spec[$side] ?? [];
            if (!is_array($rules)) {
                continue;
            }
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $scope = (string) ($rule['scope'] ?? '');
                if (!in_array($scope, self::SCOPES, true)) {
                    continue;
                }
                $value = trim((string) ($rule['value'] ?? ''));
                if ($scope !== 'entire' && $value === '') {
                    continue;
                }
                $out[$side][] = ['scope' => $scope, 'value' => mb_substr($value, 0, 191)];
                if (count($out[$side]) >= 20) {
                    break;
                }
            }
        }
        return $out;
    }

    /** Does the spec show in this context? Context: {route, slug, kind}. */
    public static function matches($spec, array $context): bool
    {
        $spec = self::normalize($spec);
        foreach ($spec['exclude'] as $rule) {
            if (self::ruleMatches($rule, $context)) {
                return false;
            }
        }
        if ($spec['include'] === []) {
            return true;
        }
        foreach ($spec['include'] as $rule) {
            if (self::ruleMatches($rule, $context)) {
                return true;
            }
        }
        return false;
    }

    private static function ruleMatches(array $rule, array $context): bool
    {
        return match ($rule['scope']) {
            'entire' => true,
            'route' => (string) ($context['route'] ?? '') === $rule['value'],
            'slug' => (string) ($context['slug'] ?? '') === $rule['value'],
            'kind' => (string) ($context['kind'] ?? '') === $rule['value'],
            default => false,
        };
    }

    /** Admin text form: "slug:home, route:page" → list of rules. */
    public static function parse(string $text): array
    {
        $rules = [];
        foreach (explode(',', $text) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            $pos = strpos($piece, ':');
            if ($pos === false) {
                $scope = $piece;
                $value = '';
            } else {
                $scope = trim(substr($piece, 0, $pos));
                $value = trim(substr($piece, $pos + 1));
            }
            $rules[] = ['scope' => $scope, 'value' => $value];
        }
        return self::normalize(['include' => $rules])['include'];
    }

    /** Rules → admin text form (inverse of parse, for valid rules). */
    public static function format(array $rules): string
    {
        $parts = [];
        foreach ($rules as $rule) {
            $parts[] = $rule['scope'] === 'entire' ? 'entire' : $rule['scope'] . ':' . $rule['value'];
        }
        return implode(', ', $parts);
    }
}

final class PageForgePopupConfig
{
    public const POSITIONS = ['center', 'bottom-right', 'bottom-left', 'top'];
    public const ANIMATIONS = ['none', 'fade', 'slide-up', 'zoom'];
    public const DEVICES = ['all', 'desktop', 'mobile'];

    public static function defaults(): array
    {
        return [
            'settings' => [
                'position' => 'center',
                'width' => 640,
                'animation' => 'fade',
                'overlay' => true,
                'close_button' => true,
                'prevent_scroll' => false,
                'avoid_multiple' => true,
            ],
            'triggers' => [
                'on_load' => ['enabled' => false, 'delay' => 0],
                'on_scroll' => ['enabled' => false, 'percent' => 50],
                'inactivity' => ['enabled' => false, 'seconds' => 30],
                'exit_intent' => ['enabled' => false],
            ],
            'rules' => [
                'max_shows' => 0,
                'max_per_day' => 0,
                'hide_after_views' => 0,
                'devices' => 'all',
            ],
        ];
    }

    /** Clamp + enum-validate a raw config; unknown keys are dropped. */
    public static function normalize($cfg): array
    {
        $d = self::defaults();
        if (!is_array($cfg)) {
            return $d;
        }
        $s = is_array($cfg['settings'] ?? null) ? $cfg['settings'] : [];
        $t = is_array($cfg['triggers'] ?? null) ? $cfg['triggers'] : [];
        $r = is_array($cfg['rules'] ?? null) ? $cfg['rules'] : [];
        $int = static fn ($v, $min, $max, $fallback) => is_numeric($v)
            ? max($min, min($max, (int) $v)) : $fallback;
        $trig = static fn ($key) => is_array($t[$key] ?? null) ? $t[$key] : [];

        $d['settings']['position'] = in_array($s['position'] ?? '', self::POSITIONS, true) ? $s['position'] : 'center';
        $d['settings']['width'] = $int($s['width'] ?? null, 200, 1200, 640);
        $d['settings']['animation'] = in_array($s['animation'] ?? '', self::ANIMATIONS, true) ? $s['animation'] : 'fade';
        foreach (['overlay', 'close_button', 'prevent_scroll', 'avoid_multiple'] as $flag) {
            if (array_key_exists($flag, $s)) {
                $d['settings'][$flag] = (bool) $s[$flag];
            }
        }
        $ol = $trig('on_load');
        $d['triggers']['on_load'] = ['enabled' => !empty($ol['enabled']), 'delay' => $int($ol['delay'] ?? null, 0, 120, 0)];
        $os = $trig('on_scroll');
        $d['triggers']['on_scroll'] = ['enabled' => !empty($os['enabled']), 'percent' => $int($os['percent'] ?? null, 5, 100, 50)];
        $ia = $trig('inactivity');
        $d['triggers']['inactivity'] = ['enabled' => !empty($ia['enabled']), 'seconds' => $int($ia['seconds'] ?? null, 5, 3600, 30)];
        $ei = $trig('exit_intent');
        $d['triggers']['exit_intent'] = ['enabled' => !empty($ei['enabled'])];
        $d['rules']['max_shows'] = $int($r['max_shows'] ?? null, 0, 100000, 0);
        $d['rules']['max_per_day'] = $int($r['max_per_day'] ?? null, 0, 1000, 0);
        $d['rules']['hide_after_views'] = $int($r['hide_after_views'] ?? null, 0, 1000000, 0);
        $d['rules']['devices'] = in_array($r['devices'] ?? '', self::DEVICES, true) ? $r['devices'] : 'all';
        return $d;
    }
}
