<?php
/**
 * PageForge icon set — small original stroke/fill glyphs drawn for this
 * plugin (generic symbols: star, check, heart, bolt, …). Rendered inline as
 * SVG with currentColor so the shared `color` style control tints them.
 *
 * Brand/social glyphs are intentionally NOT drawn here: the social-icons
 * widget renders styled initial letters instead of reproducing brand marks.
 */

declare(strict_types=1);

final class PageForgeIcons
{
    /** @return array<string, array{path:string, fill?:bool}> */
    public static function all(): array
    {
        return [
            'star' => ['path' => 'M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z'],
            'check' => ['path' => 'M4 12.5l5.5 5.5L20 6.5'],
            'arrow-right' => ['path' => 'M3 12h17M14 6l6 6-6 6'],
            'heart' => ['path' => 'M12 20.3C7.2 16.4 3.5 13.2 3.5 9.5c0-2.8 2-4.7 4.4-4.7 1.7 0 3.3.9 4.1 2.3.8-1.4 2.4-2.3 4.1-2.3 2.4 0 4.4 1.9 4.4 4.7 0 3.7-3.7 6.9-8.5 10.8z'],
            'bolt' => ['path' => 'M13 2.5L4.8 13.5h6L9.6 21.5l8.6-11.5h-6.2z'],
            'shield' => ['path' => 'M12 3l7 2.4v5.4c0 4.6-2.9 7.9-7 9.7-4.1-1.8-7-5.1-7-9.7V5.4zM9.2 11.6l2 2 3.8-3.9'],
            'phone' => ['path' => 'M5.2 3.5h3.3l1.4 4-1.9 1.6a12.5 12.5 0 0 0 6 6l1.6-1.9 4 1.4v3.3a2 2 0 0 1-2.1 2A16.8 16.8 0 0 1 3.2 5.6a2 2 0 0 1 2-2.1z'],
            'mail' => ['path' => 'M3.5 5.5h17v13h-17zM3.8 6.2l8.2 6.3 8.2-6.3'],
            'pin' => ['path' => 'M12 21s-6.3-5.2-6.3-10.1A6.3 6.3 0 0 1 12 4.2a6.3 6.3 0 0 1 6.3 6.7C18.3 15.8 12 21 12 21zM12 12.6a2.1 2.1 0 1 0 0-4.2 2.1 2.1 0 0 0 0 4.2z'],
            'cart' => ['path' => 'M3 4h2.1l2.3 10.9a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.3L20 8H6M9.4 20.4a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8zm7.4 0a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8z'],
            'quote' => ['path' => 'M6.8 6.5h3.7v4.2c0 2.8-1.4 4.7-3.8 5.8l-.8-1.4c1.1-.7 1.7-1.5 1.8-2.6H5.4A1.4 1.4 0 0 1 4 9.1V7.9a1.4 1.4 0 0 1 1.4-1.4h1.4zm9.5 0H20v4.2c0 2.8-1.4 4.7-3.8 5.8l-.8-1.4c1.1-.7 1.7-1.5 1.8-2.6h-2.3a1.4 1.4 0 0 1-1.4-1.4V7.9a1.4 1.4 0 0 1 1.4-1.4h1.4z', 'fill' => true],
            'play' => ['path' => 'M8 5.2v13.6L19 12z', 'fill' => true],
            'info' => ['path' => 'M12 20.5a8.5 8.5 0 1 0 0-17 8.5 8.5 0 0 0 0 17zM12 11v5M12 7.8v.2'],
            'warning' => ['path' => 'M12 4L22 20H2zM12 10v4.2M12 17.2v.2'],
            'link' => ['path' => 'M10.5 13.5a4 4 0 0 0 5.7 0l3-3a4 4 0 1 0-5.7-5.7l-1.4 1.4M13.5 10.5a4 4 0 0 0-5.7 0l-3 3a4 4 0 1 0 5.7 5.7l1.4-1.4'],
        ];
    }

    public static function names(): array
    {
        return array_keys(self::all());
    }

    /** Inline SVG for an icon name; '' for unknown names. */
    public static function svg(string $name, string $class = ''): string
    {
        $icon = self::all()[$name] ?? null;
        if ($icon === null) {
            return '';
        }
        $fill = !empty($icon['fill']);
        $attrs = $fill
            ? 'fill="currentColor" stroke="none"'
            : 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
        return '<svg class="' . PageForgeWidgets::esc($class) . '" viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" focusable="false" ' . $attrs
            . '><path d="' . PageForgeWidgets::esc($icon['path']) . '"/></svg>';
    }
}
