<?php
/**
 * PageForge Phase 1 — engine unit tests (pure, no DB, no platform boot).
 *
 * The renderer/CSS compiler are the product's core contract: a document
 * tree must compile deterministically, author input must be sanitized at
 * the save gate, and every declared style control must reach the CSS.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/pageforge/lib/PageForgeRenderer.php';

unit('pageforge: normalization drops unknown widgets and undeclared settings', function (): void {
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [[
            'id' => 'c1', 'elType' => 'container',
            'settings' => ['gap' => 10, 'bogus' => 'x'],
            'elements' => [
                ['id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'Hi']],
                ['id' => 'w2', 'elType' => 'widget', 'widgetType' => 'does-not-exist', 'settings' => []],
            ],
        ]],
    ]);
    assert_eq(1, count($doc['elements']));
    assert_eq(1, count($doc['elements'][0]['elements']), 'unknown widget must be dropped');
    assert_true(!isset($doc['elements'][0]['settings']['bogus']), 'undeclared setting must be dropped');
    assert_eq(10, $doc['elements'][0]['settings']['gap']);
});

unit('pageforge: heading output is escaped and unsafe URLs are refused', function (): void {
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [[
            'id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading',
            'settings' => ['text' => '<script>alert(1)</script>', 'link_url' => 'javascript:alert(1)'],
        ]],
    ]);
    $html = PageForgeRenderer::render($doc, 3);
    assert_true(!str_contains($html, '<script>'), 'script tag must be escaped');
    assert_true(!str_contains($html, 'javascript:'), 'javascript: scheme must be refused');
});

unit('pageforge: style controls compile to scoped CSS with responsive variants', function (): void {
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [[
            'id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading',
            'settings' => ['text' => 'T', 'font_size' => 32, 'font_size_mobile' => 20, 'color' => '#ABCDEF'],
        ]],
    ]);
    $css = PageForgeCss::compile($doc['elements'], 9);
    assert_true(str_contains($css, '.pf-page-9 .pf-el-h'), 'rules are scoped to page and element');
    assert_true(str_contains($css, 'font-size: 32px;'), 'desktop size present');
    assert_true(str_contains($css, 'color: #abcdef;'), 'color normalized to lowercase hex');
    assert_true(str_contains($css, '@media (max-width: 767px)'), 'mobile media query present');
    assert_true(str_contains($css, 'font-size: 20px;'), 'mobile size present');
});

unit('pageforge: invalid colors and out-of-range numbers never reach CSS', function (): void {
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [[
            'id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading',
            'settings' => ['text' => 'T', 'color' => 'red; } body { display:none', 'font_size' => 99999],
        ]],
    ]);
    $css = PageForgeCss::compile($doc['elements'], 1);
    assert_true(!str_contains($css, 'display:none'), 'CSS injection through a color must be impossible');
    assert_true(!str_contains($css, '99999'), 'font size must be clamped by the sanitizer');
});
