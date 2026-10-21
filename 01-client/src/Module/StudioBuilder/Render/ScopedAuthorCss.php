<?php
/**
 * Kohevo Studio (studio-builder) — Scoped author CSS.
 *
 * Scopes a block's author-written `custom_css` to that block instance by
 * prefixing every selector with the block's scope class. Authoring
 * convention (mirrors Elementor's `selector` idea, with nesting syntax):
 *
 *   `.title { color: red }`        → `.sb-u-blk_x .title { color: red }`
 *   `& { border: 1px solid }`      → `.sb-u-blk_x { border: 1px solid }`
 *   `&:hover { ... }` / `:hover`   → `.sb-u-blk_x:hover { ... }`
 *
 * Grouping at-rules (@media/@supports/@layer) are recursed into; rules whose
 * contents are not element selectors (@keyframes, @font-face, @page) pass
 * through untouched. The input is expected to have passed
 * StudioCodePolicy::sanitizeCustomCss first; this class only restructures.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

final class ScopedAuthorCss
{
    public static function scope(string $css, string $scopeClass): string
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? '';

        return self::scopeRules($css, '.' . $scopeClass);
    }

    private static function scopeRules(string $css, string $scope): string
    {
        $out = '';
        $len = strlen($css);
        $i   = 0;
        while ($i < $len) {
            // Read a prelude up to '{' or ';', string-aware.
            $start = $i;
            $inStr = null;
            while ($i < $len) {
                $ch = $css[$i];
                if ($inStr !== null) {
                    if ($ch === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($ch === $inStr) {
                        $inStr = null;
                    }
                    $i++;
                    continue;
                }
                if ($ch === '"' || $ch === "'") {
                    $inStr = $ch;
                    $i++;
                    continue;
                }
                if ($ch === '{' || $ch === ';') {
                    break;
                }
                $i++;
            }
            $prelude = trim(substr($css, $start, $i - $start));
            if ($i >= $len) {
                if ($prelude !== '') {
                    $out .= $prelude;
                }
                break;
            }
            if ($css[$i] === ';') {
                // Statement at-rule (e.g. @charset): pass through.
                if ($prelude !== '') {
                    $out .= $prelude . ';';
                }
                $i++;
                continue;
            }
            // '{' — capture the balanced body.
            $i++;
            $bodyStart = $i;
            $depth     = 1;
            $inStr     = null;
            while ($i < $len && $depth > 0) {
                $ch = $css[$i];
                if ($inStr !== null) {
                    if ($ch === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($ch === $inStr) {
                        $inStr = null;
                    }
                    $i++;
                    continue;
                }
                if ($ch === '"' || $ch === "'") {
                    $inStr = $ch;
                } elseif ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                }
                $i++;
            }
            $body = $depth === 0
                ? substr($css, $bodyStart, $i - $bodyStart - 1)
                : substr($css, $bodyStart);
            if ($prelude === '') {
                continue;
            }
            if (preg_match('/^@(media|supports|layer)\b/i', $prelude) === 1) {
                $out .= $prelude . '{' . self::scopeRules($body, $scope) . '}';
            } elseif (str_starts_with($prelude, '@')) {
                // @keyframes/@font-face/@page: contents are not selectors.
                $out .= $prelude . '{' . $body . '}';
            } else {
                $scoped = [];
                foreach (self::splitSelectors($prelude) as $selector) {
                    $selector = trim($selector);
                    if ($selector === '') {
                        continue;
                    }
                    if (str_contains($selector, '&')) {
                        $scoped[] = str_replace('&', $scope, $selector);
                    } elseif ($selector[0] === ':' || $selector[0] === '[') {
                        $scoped[] = $scope . $selector;
                    } else {
                        $scoped[] = $scope . ' ' . $selector;
                    }
                }
                if ($scoped !== []) {
                    $out .= implode(',', $scoped) . '{' . $body . '}';
                }
            }
        }

        return $out;
    }

    /**
     * Split a selector list on top-level commas (paren/bracket/string aware,
     * so `:not(.a, .b)` stays one selector).
     *
     * @return list<string>
     */
    private static function splitSelectors(string $list): array
    {
        $parts   = [];
        $current = '';
        $depth   = 0;
        $inStr   = null;
        $len     = strlen($list);
        for ($i = 0; $i < $len; $i++) {
            $ch = $list[$i];
            if ($inStr !== null) {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $current .= $list[++$i];
                } elseif ($ch === $inStr) {
                    $inStr = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $inStr = $ch;
            } elseif ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;

        return $parts;
    }
}
