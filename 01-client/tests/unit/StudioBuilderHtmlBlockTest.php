<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the `core.html` block
 * (Batch D1): the `html` field type's write validation and the
 * EmbedHtmlSanitizer render policy. Scripts and event handlers never
 * survive; iframes pass only from allowlisted embed hosts, sandboxed;
 * editor mode swaps iframes for a placeholder card.
 *
 * Autoloader only, no database.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require __DIR__ . '/harness.php';
}

use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\EmbedHtmlSanitizer;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

unit('html block sanitizer: dangerous content never survives', function (): void {
    assert_eq('', EmbedHtmlSanitizer::sanitize('<script>alert(1)</script>'), 'script element and its contents are removed');
    assert_false(str_contains(EmbedHtmlSanitizer::sanitize('<p onclick="x()">hi</p>'), 'onclick'), 'event handler attributes are dropped');
    assert_eq('<p>hi</p>', EmbedHtmlSanitizer::sanitize('<p style="color:red">hi</p>'), 'style attributes are dropped');
    assert_eq('<a href="https://example.com">x</a>', EmbedHtmlSanitizer::sanitize('<a href="https://example.com">x</a>'), 'safe anchor passes');
    assert_false(str_contains(EmbedHtmlSanitizer::sanitize('<a href="javascript:alert(1)">x</a>'), 'javascript:'), 'javascript: href is dropped');
    assert_eq('<div class="wrap wide"><strong>Bold</strong></div>', EmbedHtmlSanitizer::sanitize('<div class="wrap wide"><strong>Bold</strong></div>'), 'structural markup and classes pass');
    assert_eq('<div><p>unclosed</p></div>', EmbedHtmlSanitizer::sanitize('<div><p>unclosed'), 'output is well-formed (unclosed tags closed)');
    assert_eq('', EmbedHtmlSanitizer::sanitize('<form action="/x"><input name="a"></form>'), 'form and contents are removed');
});

unit('html block sanitizer: iframes only from allowlisted embed hosts', function (): void {
    $yt = EmbedHtmlSanitizer::sanitize('<iframe src="https://www.youtube-nocookie.com/embed/abc123" width="560" height="315" allowfullscreen></iframe>');
    assert_true(str_contains($yt, '<iframe') && str_contains($yt, 'sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"'), 'allowlisted youtube embed passes, sandboxed');
    assert_eq('', EmbedHtmlSanitizer::sanitize('<iframe src="https://evil.example.com/embed/x"></iframe>'), 'non-allowlisted iframe host is dropped');
    assert_eq('', EmbedHtmlSanitizer::sanitize('<iframe src="http://www.youtube.com/embed/x"></iframe>'), 'http (non-https) iframe is dropped');
    assert_eq('', EmbedHtmlSanitizer::sanitize('<iframe src="https://www.youtube.com/watch?v=x"></iframe>'), 'youtube watch URL (not /embed/) is dropped');
    $editor = EmbedHtmlSanitizer::sanitize('<iframe src="https://player.vimeo.com/video/42"></iframe>', true);
    assert_true(str_contains($editor, 'sb-embed-frame__placeholder') && str_contains($editor, 'player.vimeo.com') && !str_contains($editor, '<iframe'), 'editor mode swaps the iframe for a placeholder card');
});

unit('html block sanitizer: image URL policy', function (): void {
    assert_true(str_contains(EmbedHtmlSanitizer::sanitize('<img src="data:image/png;base64,iVBORw0KGgo=" alt="x">'), 'data:image/png'), 'raster data URI image passes');
    assert_false(str_contains(EmbedHtmlSanitizer::sanitize('<img src="data:image/svg+xml;base64,PHN2Zz4=">'), 'data:image'), 'SVG data URI is dropped (can carry script)');
});

unit('html field type: write validation', function (): void {
    $schema = FieldSchema::define([
        ['key' => 'html', 'type' => 'html', 'label' => 'HTML', 'required' => false, 'default' => '', 'max_length' => 20000],
    ]);
    assert_true($schema->validate(['html' => '<div><iframe src="https://www.youtube.com/embed/x"></iframe></div>'])->isValid(), 'html field accepts embed markup');
    $bad = $schema->validate(['html' => '<script>alert(1)</script>']);
    assert_false($bad->isValid(), 'html field rejects script on write');
    assert_true(str_contains((string) json_encode($bad->errors()), 'unsafe_html'), 'rejection carries the unsafe_html code');
    assert_false($schema->validate(['html' => '<p onclick="x()">hi</p>'])->isValid(), 'html field rejects event handlers on write');
    assert_eq('<p>x</p>', $schema->normalize(['html' => '<p>x</p>'])['html'], 'html field normalizes as string');
});

unit('html block: registered with a renderer', function (): void {
    $def = BlockRegistry::withAllCoreBlocks()->get('core.html');
    assert_true($def !== null && $def->category() === 'advanced', 'core.html is registered in the advanced category');
    assert_true(BlockRendererRegistry::withCoreRenderers()->has('core.html'), 'core.html has a renderer');
});
