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

unit('pageforge phase2: repeater settings are sanitized per item schema', function (): void {
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [[
            'id' => 'il', 'elType' => 'widget', 'widgetType' => 'icon-list',
            'settings' => ['items' => [
                ['icon_name' => 'check', 'text' => 'One', 'link_url' => 'https://example.com'],
                ['icon_name' => 'not-an-icon', 'text' => 'Two', 'link_url' => 'javascript:alert(1)', 'extra' => 'dropped'],
                'junk',
            ]],
        ]],
    ]);
    $items = $doc['elements'][0]['settings']['items'];
    assert_eq(2, count($items), 'non-array repeater rows must be dropped');
    assert_eq('star', $items[1]['icon_name'], 'invalid icon must fall back to the control default');
    assert_eq('', $items[1]['link_url'], 'unsafe repeater URL must be emptied');
    assert_true(!isset($items[1]['extra']), 'undeclared repeater fields must be dropped');
});

unit('pageforge phase2: every registered widget renders without error on empty settings', function (): void {
    foreach (array_keys(PageForgeWidgets::all()) as $type) {
        if ($type === 'container') continue;
        $doc = PageForgeRenderer::normalizeDocument([
            'elements' => [['id' => 'w', 'elType' => 'widget', 'widgetType' => $type, 'settings' => []]],
        ]);
        $html = PageForgeRenderer::render($doc, 1);
        assert_true(is_string($html), $type . ' must render a string');
    }
});

unit('pageforge phase2: html widget strips active content but keeps markup', function (): void {
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [[
            'id' => 'h', 'elType' => 'widget', 'widgetType' => 'html',
            'settings' => ['content' => '<p onclick="x()">ok</p><script>alert(1)</script><iframe src="https://evil.example"></iframe>'],
        ]],
    ]);
    $html = PageForgeRenderer::render($doc, 1);
    assert_true(str_contains($html, '<p>ok</p>'), 'legitimate markup must survive');
    assert_true(!str_contains($html, 'script'), 'script must be stripped');
    assert_true(!str_contains($html, 'iframe'), 'iframe must be stripped');
    assert_true(!str_contains($html, 'onclick'), 'event handlers must be stripped');
});

unit('pageforge phase2: video widget only embeds canonical youtube/vimeo/file sources', function (): void {
    $mk = static fn (array $s): string => PageForgeRenderer::render(PageForgeRenderer::normalizeDocument([
        'elements' => [['id' => 'v', 'elType' => 'widget', 'widgetType' => 'video', 'settings' => $s]],
    ]), 1);
    assert_true(str_contains($mk(['source' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ']), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'));
    assert_true(str_contains($mk(['source' => 'vimeo', 'url' => 'https://vimeo.com/123456789']), 'player.vimeo.com/video/123456789'));
    assert_eq('', trim(strip_tags($mk(['source' => 'youtube', 'url' => 'https://example.com/watch?v=dQw4w9WgXcQ']))), 'foreign host must not embed');
    assert_true(str_contains($mk(['source' => 'file', 'url' => 'https://cdn.example.com/a.mp4']), '<video'));
    assert_true(!str_contains($mk(['source' => 'file', 'url' => 'https://cdn.example.com/a.exe']), '<video'), 'non-video file must not render');
});

unit('pageforge phase3: extended style controls compile through value maps', function (): void {
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [[
            'id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading',
            'settings' => ['text' => 'T', 'font_family' => 'serif', 'box_shadow' => 'md', 'opacity' => 0.5,
                'letter_spacing' => -1, 'text_transform' => 'uppercase', 'border_style' => 'solid', 'border_width' => 2, 'border_color' => '#FF0000'],
        ]],
    ]);
    $css = PageForgeCss::compile($doc['elements'], 5);
    assert_true(str_contains($css, 'font-family: Georgia, "Times New Roman", serif;'), 'font_family must compile through its value map');
    assert_true(str_contains($css, 'box-shadow: 0 4px 12px rgba(0,0,0,0.12);'), 'box_shadow preset must compile through its value map');
    assert_true(str_contains($css, 'opacity: 0.5;'), 'opacity must compile unitless');
    assert_true(str_contains($css, 'letter-spacing: -1px;'), 'negative letter-spacing must compile');
    assert_true(str_contains($css, 'text-transform: uppercase;'));
    assert_true(str_contains($css, 'border-width: 2px;') && str_contains($css, 'border-style: solid;') && str_contains($css, 'border-color: #ff0000;'), 'border trio must compile');
});

unit('pageforge phase3: tabs render beyond six panels', function (): void {
    $items = [];
    for ($i = 1; $i <= 8; $i++) $items[] = ['title' => 'T' . $i, 'content' => 'C' . $i];
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [['id' => 'tb', 'elType' => 'widget', 'widgetType' => 'tabs', 'settings' => ['items' => $items]]],
    ]);
    $html = PageForgeRenderer::render($doc, 1);
    assert_eq(8, substr_count($html, 'pf-tabs__panel--'), 'all 8 tab panels must render');
    assert_true(str_contains($html, 'pf-tabs__panel--7'), 'panel 8 (index 7) must exist for the 12-panel CSS');
});
