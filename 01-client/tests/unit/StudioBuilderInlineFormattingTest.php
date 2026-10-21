<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — inline formatting
 * (Batch D4): the `inline_html` field type validates the inline subset,
 * and the plain blocks' renderers emit it through RichTextSanitizer —
 * bold/italic/links render, scripts and handlers never do.
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

use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

unit('inline formatting: the inline_html field type accepts the inline subset only', function (): void {
    $schema = FieldSchema::define([
        ['key' => 'text', 'type' => 'inline_html', 'label' => 'Text', 'required' => true, 'default' => '', 'max_length' => 300],
    ]);
    assert_true($schema->validate(['text' => 'Plain text'])->isValid(), 'plain text passes');
    assert_true($schema->validate(['text' => 'A <strong>bold</strong> and <em>italic</em> word'])->isValid(), 'strong/em pass');
    assert_true($schema->validate(['text' => 'See <a href="https://example.com" target="_blank">this</a>'])->isValid(), 'a safe link passes');
    assert_true($schema->validate(['text' => 'Line<br>break'])->isValid(), 'br passes');
    assert_false($schema->validate(['text' => '<p>Block tag</p>'])->isValid(), 'block tags are refused');
    assert_false($schema->validate(['text' => '<script>alert(1)</script>'])->isValid(), 'script is refused');
    assert_false($schema->validate(['text' => '<strong onclick="x()">b</strong>'])->isValid(), 'handlers are refused');
    assert_false($schema->validate(['text' => '<a href="javascript:alert(1)">x</a>'])->isValid(), 'unsafe link targets are refused');
    assert_eq('A <strong>b</strong>', $schema->normalize(['text' => 'A <strong>b</strong>'])['text'], 'normalizes as the string itself');
});

unit('inline formatting: render helpers sanitize markup and keep plain behaviour', function (): void {
    assert_eq('A <strong>bold</strong> word', Html::inlineText('A <strong>bold</strong> word'), 'inline markup renders');
    assert_eq('Fish &amp; Chips', Html::inlineText('Fish & Chips'), 'plain text is still escaped');
    assert_eq('alert(1)', Html::inlineText('<script>alert(1)</script>'), 'script tags never render — their text stays inert plain text, as in the rich text pipeline');
    assert_false(str_contains(Html::inlineText('<em onclick="x()">i</em>'), 'onclick'), 'handlers are stripped at render');
    assert_eq('one<br>' . "\n" . 'two', Html::inlineText("one\ntwo"), 'author line breaks still become <br>');
    // The highlight branch: markup wins over the highlight phrase.
    assert_eq('A <strong>bold</strong> word', Html::highlightedOrInline('A <strong>bold</strong> word', 'bold'), 'formatted text renders as-is (highlight skipped)');
    $plain = Html::highlightedOrInline('A bold word', 'bold');
    assert_true(str_contains($plain, 'sb-hl'), 'plain text still gets its highlight span');
});

unit('inline formatting: the converted props carry the inline_html type', function (): void {
    $registry = \Slate\Module\StudioBuilder\Registry\BlockRegistry::withAllCoreBlocks();
    $typeOf = static function (string $block, string $prop) use ($registry): ?string {
        $def = $registry->get($block);
        foreach ($def?->schema()->fields() ?? [] as $field) {
            if (($field['key'] ?? null) === $prop) {
                return $field['type'] ?? null;
            }
        }
        return null;
    };
    assert_eq('inline_html', $typeOf('core.heading', 'text'), 'heading text');
    assert_eq('inline_html', $typeOf('core.text', 'content'), 'text content');
    assert_eq('inline_html', $typeOf('core.hero', 'heading'), 'hero heading');
    assert_eq('inline_html', $typeOf('core.hero', 'subheading'), 'hero subheading');
    assert_eq('inline_html', $typeOf('core.quote', 'text'), 'quote text');
    assert_eq('inline_html', $typeOf('core.feature_list', 'title'), 'feature list title');
});
