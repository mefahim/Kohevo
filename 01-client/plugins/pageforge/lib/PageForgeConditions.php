<?php
/**
 * PageForge conditions — the one declarative visibility spec shared by
 * PHP and the editor (Phase 8, post-Phase-4 plan #5).
 *
 * A control may declare:
 *   'condition' => ['field' => 'source', 'value' => 'manual']
 *   'condition' => ['field' => 'source', 'in' => ['products', 'pages']]
 *   'condition' => ['field' => 'template_slug', 'not_empty' => true]
 *   'condition' => ['field' => 'source', 'not' => 'manual']
 * or a LIST of such maps (all must pass — AND semantics).
 *
 * The condition rides along in the control def, so it is serialized
 * into the editor manifest unchanged; this evaluator is the PHP side
 * of the same spec (used by tests, the audit, and any server-side
 * consumer). Visibility is an editor concern only — the sanitizer
 * keeps values of hidden controls, exactly like Elementor.
 */

declare(strict_types=1);

final class PageForgeConditions
{
    public static function visible(array $control, array $settings): bool
    {
        $cond = $control['condition'] ?? null;
        if (!is_array($cond) || $cond === []) {
            return true;
        }
        // A list of maps = AND of all of them.
        if (array_is_list($cond)) {
            foreach ($cond as $one) {
                if (is_array($one) && !self::passes($one, $settings)) {
                    return false;
                }
            }
            return true;
        }
        return self::passes($cond, $settings);
    }

    private static function passes(array $cond, array $settings): bool
    {
        $field = (string) ($cond['field'] ?? '');
        if ($field === '') {
            return true;
        }
        $actual = $settings[$field] ?? null;
        if (!empty($cond['not_empty'])) {
            return $actual !== null && $actual !== '' && $actual !== [];
        }
        if (isset($cond['in']) && is_array($cond['in'])) {
            foreach ($cond['in'] as $allowed) {
                if ((string) $actual === (string) $allowed) {
                    return true;
                }
            }
            return false;
        }
        if (array_key_exists('not', $cond)) {
            return (string) $actual !== (string) $cond['not'];
        }
        if (array_key_exists('value', $cond)) {
            return (string) $actual === (string) $cond['value'];
        }
        return true;
    }
}
