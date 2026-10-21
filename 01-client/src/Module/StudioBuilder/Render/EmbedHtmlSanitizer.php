<?php
/**
 * Kohevo Studio (studio-builder) — Output-side Embed HTML Sanitizer.
 *
 * The render-side policy for the `core.html` block (Elementor's HTML widget,
 * with a house security policy). Authors write HTML for embeds and custom
 * markup; this sanitizer is what may actually reach a page:
 *
 * - Structural/content tags pass with a small per-tag attribute allowlist.
 *   `style`, every `on*`, `srcdoc` and non-allowlisted attributes never do.
 * - Raw-text dangers (script/style/form/svg/…) are removed WITH their
 *   contents before tokenizing.
 * - `<iframe>` survives only when its src is https on an allowlisted embed
 *   host + path (video/maps/audio players) and always gains a sandbox.
 * - `<img>`/`<video>`/`<source>` URLs go through FieldSchema::isSafeUrl
 *   (raster `data:image/…` also allowed for img).
 * - Output is well-formed, like RichTextSanitizer's: stray closers dropped,
 *   unclosed elements closed.
 * - In the editor ($forEditor) iframes become a labelled placeholder card —
 *   the script-less canvas never receives a live frame (embed-block house
 *   style).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class EmbedHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav',
        'figure', 'figcaption', 'picture',
        'p', 'br', 'hr', 'strong', 'em', 'b', 'i', 'u', 's', 'small', 'sub', 'sup', 'mark',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'code', 'pre',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'span',
        'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'iframe', 'video', 'source',
    ];

    private const VOID_TAGS = ['br', 'hr', 'img', 'source'];

    /** Embed iframes: host => allowed path prefixes. */
    private const IFRAME_HOSTS = [
        'www.youtube.com'          => ['/embed/'],
        'www.youtube-nocookie.com' => ['/embed/'],
        'youtube-nocookie.com'     => ['/embed/'],
        'player.vimeo.com'         => ['/video/'],
        'www.openstreetmap.org'    => ['/export/embed.html'],
        'www.google.com'           => ['/maps'],
        'maps.google.com'          => ['/'],
        'open.spotify.com'         => ['/embed/'],
        'w.soundcloud.com'         => ['/player/'],
        'player.twitch.tv'         => ['/'],
    ];

    private const IFRAME_ALLOW_TOKENS = ['accelerometer', 'autoplay', 'clipboard-write', 'encrypted-media', 'gyroscope', 'picture-in-picture', 'web-share', 'fullscreen'];

    public static function sanitize(string $html, bool $forEditor = false): string
    {
        // Remove comments and raw-text danger elements WITH their contents.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? '';
        $html = preg_replace('/<\s*(script|style|textarea|title|noscript|template|object|form|svg|math)\b[^>]*>.*?<\/\s*\1\s*>/is', '', $html) ?? '';

        $parts = preg_split('/(<[^<>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return Html::e($html);
        }

        $out   = '';
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] !== '<') {
                $out .= Html::e(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                continue;
            }
            if (preg_match('/^<\s*[!?]/', $part) === 1) {
                continue;
            }
            if (preg_match('/^<\s*(\/?)\s*([a-zA-Z0-9]+)([^>]*)>$/', $part, $m) !== 1) {
                $out .= Html::e($part);
                continue;
            }
            $closing = $m[1] === '/';
            $tag     = strtolower($m[2]);
            $attrs   = self::parseAttrs(trim(rtrim(trim($m[3]), '/')));

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                continue;
            }
            if ($closing) {
                $pos = array_search($tag, $stack, true);
                if ($pos === false) {
                    continue;
                }
                while (count($stack) > $pos) {
                    $out .= '</' . array_pop($stack) . '>';
                }
                continue;
            }

            if ($tag === 'iframe') {
                $src = self::iframeSrc($attrs['src'] ?? null);
                if ($src === null) {
                    continue; // dropped entirely; its closer becomes a stray and is dropped too
                }
                if ($forEditor) {
                    $host = (string) parse_url($src, PHP_URL_HOST);
                    $out .= '<div class="sb-embed-frame__placeholder" data-sb-embed-host="' . Html::e($host) . '">Embedded content: ' . Html::e($host) . '</div>';
                    continue;
                }
                $out .= '<iframe' . self::genericAttrs($attrs) . self::iframeAttrs($attrs, $src) . '></iframe>';
                continue;
            }

            $out .= '<' . $tag . self::genericAttrs($attrs) . self::tagAttrs($tag, $attrs) . '>';
            if (!in_array($tag, self::VOID_TAGS, true)) {
                $stack[] = $tag;
            }
        }
        while ($stack !== []) {
            $out .= '</' . array_pop($stack) . '>';
        }

        return $out;
    }

    /** @return array<string, string|null> attribute name => value (null = bare) */
    private static function parseAttrs(string $attrString): array
    {
        $out = [];
        if ($attrString === '') {
            return $out;
        }
        if (preg_match_all('/([a-zA-Z0-9_-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $attrString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $out[strtolower($m[1])] = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? null));
            }
        }

        return $out;
    }

    /** Attributes allowed on every tag: class/id/title/lang/dir with tight charsets. */
    private static function genericAttrs(array $attrs): string
    {
        $out = '';
        if (isset($attrs['class']) && is_string($attrs['class']) && preg_match('/^[a-zA-Z0-9 _-]+$/', $attrs['class']) === 1) {
            $out .= ' class="' . Html::e($attrs['class']) . '"';
        }
        if (isset($attrs['id']) && is_string($attrs['id']) && preg_match('/^[a-zA-Z0-9_-]+$/', $attrs['id']) === 1) {
            $out .= ' id="' . Html::e($attrs['id']) . '"';
        }
        if (isset($attrs['title']) && is_string($attrs['title'])) {
            $out .= ' title="' . Html::e($attrs['title']) . '"';
        }
        if (isset($attrs['lang']) && is_string($attrs['lang']) && preg_match('/^[a-zA-Z-]+$/', $attrs['lang']) === 1) {
            $out .= ' lang="' . Html::e($attrs['lang']) . '"';
        }
        if (isset($attrs['dir']) && in_array($attrs['dir'], ['ltr', 'rtl', 'auto'], true)) {
            $out .= ' dir="' . $attrs['dir'] . '"';
        }

        return $out;
    }

    private static function tagAttrs(string $tag, array $attrs): string
    {
        $out = '';
        switch ($tag) {
            case 'a':
                if (isset($attrs['href']) && is_string($attrs['href']) && FieldSchema::isSafeUrl($attrs['href'])) {
                    $out .= ' href="' . Html::e($attrs['href']) . '"';
                }
                if (isset($attrs['target']) && in_array($attrs['target'], ['_self', '_blank'], true)) {
                    $out .= ' target="' . $attrs['target'] . '"';
                    if ($attrs['target'] === '_blank') {
                        $out .= ' rel="noopener noreferrer"';
                    }
                }
                break;
            case 'img':
                if (isset($attrs['src']) && is_string($attrs['src']) && (FieldSchema::isSafeUrl($attrs['src']) || preg_match('#^data:image/(png|jpeg|gif|webp);base64,[a-zA-Z0-9+/=]+$#', $attrs['src']) === 1)) {
                    $out .= ' src="' . Html::e($attrs['src']) . '"';
                }
                if (isset($attrs['alt']) && is_string($attrs['alt'])) {
                    $out .= ' alt="' . Html::e($attrs['alt']) . '"';
                }
                $out .= self::dimensionAttrs($attrs);
                if (isset($attrs['loading']) && in_array($attrs['loading'], ['lazy', 'eager'], true)) {
                    $out .= ' loading="' . $attrs['loading'] . '"';
                }
                break;
            case 'video':
                if (isset($attrs['src']) && is_string($attrs['src']) && FieldSchema::isSafeUrl($attrs['src'])) {
                    $out .= ' src="' . Html::e($attrs['src']) . '"';
                }
                if (isset($attrs['poster']) && is_string($attrs['poster']) && FieldSchema::isSafeUrl($attrs['poster'])) {
                    $out .= ' poster="' . Html::e($attrs['poster']) . '"';
                }
                foreach (['controls', 'autoplay', 'muted', 'loop', 'playsinline'] as $bool) {
                    if (array_key_exists($bool, $attrs)) {
                        $out .= ' ' . $bool;
                    }
                }
                $out .= self::dimensionAttrs($attrs);
                break;
            case 'source':
                if (isset($attrs['src']) && is_string($attrs['src']) && FieldSchema::isSafeUrl($attrs['src'])) {
                    $out .= ' src="' . Html::e($attrs['src']) . '"';
                }
                if (isset($attrs['type']) && is_string($attrs['type']) && preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', $attrs['type']) === 1) {
                    $out .= ' type="' . Html::e($attrs['type']) . '"';
                }
                break;
            case 'td':
            case 'th':
                foreach (['colspan', 'rowspan'] as $span) {
                    if (isset($attrs[$span]) && is_string($attrs[$span]) && ctype_digit($attrs[$span]) && (int) $attrs[$span] >= 1 && (int) $attrs[$span] <= 100) {
                        $out .= ' ' . $span . '="' . $attrs[$span] . '"';
                    }
                }
                break;
        }

        return $out;
    }

    private static function dimensionAttrs(array $attrs): string
    {
        $out = '';
        foreach (['width', 'height'] as $dim) {
            if (isset($attrs[$dim]) && is_string($attrs[$dim]) && ctype_digit($attrs[$dim])) {
                $out .= ' ' . $dim . '="' . $attrs[$dim] . '"';
            }
        }

        return $out;
    }

    /** The iframe src when it is an allowlisted embed URL, else null. */
    private static function iframeSrc(mixed $src): ?string
    {
        if (!is_string($src) || $src === '') {
            return null;
        }
        $parts = parse_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '/');
        foreach (self::IFRAME_HOSTS[$host] ?? [] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $src;
            }
        }

        return null;
    }

    private static function iframeAttrs(array $attrs, string $src): string
    {
        $out = ' src="' . Html::e($src) . '"';
        $out .= self::dimensionAttrs($attrs);
        if (isset($attrs['allow']) && is_string($attrs['allow'])) {
            $tokens = array_values(array_intersect(self::IFRAME_ALLOW_TOKENS, array_map('trim', explode(';', strtolower($attrs['allow'])))));
            if ($tokens !== []) {
                $out .= ' allow="' . implode('; ', $tokens) . '"';
            }
        }
        if (array_key_exists('allowfullscreen', $attrs)) {
            $out .= ' allowfullscreen';
        }
        if (isset($attrs['loading']) && in_array($attrs['loading'], ['lazy', 'eager'], true)) {
            $out .= ' loading="' . $attrs['loading'] . '"';
        }
        if (isset($attrs['referrerpolicy']) && in_array($attrs['referrerpolicy'], ['no-referrer', 'origin', 'strict-origin-when-cross-origin'], true)) {
            $out .= ' referrerpolicy="' . $attrs['referrerpolicy'] . '"';
        }
        $out .= ' sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"';

        return $out;
    }
}
