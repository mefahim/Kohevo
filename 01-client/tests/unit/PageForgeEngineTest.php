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

unit('pageforge phase4: form widget embeds the Kohevo Forms route', function (): void {
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [
            ['id' => 'f1', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => ['form_slug' => 'contact-us', 'form_height' => 520]],
            ['id' => 'f2', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => []],
        ],
    ]);
    $html = PageForgeRenderer::render($doc, 1);
    assert_true(str_contains($html, '/forms/contact-us?embed=1'), 'form must embed via the Forms plugin embed route');
    assert_true(str_contains($html, 'height:520px'), 'frame height must be honored');
    assert_true(str_contains($html, 'No form selected.'), 'empty form must render the empty state');
});

unit('pageforge phase4: global expansion at compile time, placeholder without resolver', function (): void {
    PageForgeRenderer::setGlobalResolver(null);
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [['id' => 'g', 'elType' => 'widget', 'widgetType' => 'global-widget', 'settings' => ['global_id' => 9]]],
    ]);
    $plain = PageForgeRenderer::compile($doc, 1);
    assert_true(str_contains($plain['html'], 'pf-global-missing'), 'without a resolver the global renders its placeholder');
    PageForgeRenderer::setGlobalResolver(static fn (int $id): ?array => $id === 9
        ? ['elements' => [['id' => 'in', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'FROM-GLOBAL', 'color' => '#00AA00']]]]
        : null);
    $expanded = PageForgeRenderer::compile($doc, 1);
    assert_true(str_contains($expanded['html'], 'FROM-GLOBAL'), 'resolver content must expand into the page HTML');
    assert_true(str_contains($expanded['css'], '#00aa00'), 'expanded element CSS must compile into the page CSS');
    PageForgeRenderer::setGlobalResolver(null);
});

unit('pageforge phase4: button popup trigger replaces the link', function (): void {
    $doc = PageForgeRenderer::normalizeDocument([
        'elements' => [['id' => 'b', 'elType' => 'widget', 'widgetType' => 'button',
            'settings' => ['text' => 'Open', 'link_url' => 'https://example.com', 'popup_id' => 5]]],
    ]);
    $html = PageForgeRenderer::render($doc, 1);
    assert_true(str_contains($html, 'data-pf-popup="5"'), 'popup button must carry the trigger attribute');
    assert_true(!str_contains($html, 'example.com'), 'popup button must not keep the plain link');
});

unit('pageforge phase5: loop binds {{field}} placeholders from item contexts', function (): void {
    PageForgeRenderer::setDocumentResolver(static function (string $slug, string $kind): ?array {
        if ($slug === 'card' && $kind === 'loop-item') {
            return ['elements' => [
                ['id' => 'th', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => '{{title}}']],
            ]];
        }
        return null;
    });
    try {
        $doc = PageForgeRenderer::normalizeDocument(['elements' => [[
            'id' => 'lp', 'elType' => 'widget', 'widgetType' => 'loop',
            'settings' => ['source' => 'manual', 'template_slug' => 'card', 'items' => [
                ['title' => 'Alpha', 'text' => '', 'image' => '', 'url' => ''],
                ['title' => 'Beta', 'text' => '', 'image' => '', 'url' => ''],
            ]],
        ]]]);
        $html = PageForgeRenderer::render($doc, 1);
        assert_true(str_contains($html, 'Alpha') && str_contains($html, 'Beta'), 'loop must render the template once per item');
        assert_true(!str_contains($html, '{{'), 'no placeholder may survive rendering');
    } finally {
        PageForgeRenderer::setDocumentResolver(null);
    }
});

unit('pageforge phase5: shop widgets exist and degrade without live shop data', function (): void {
    assert_true(PageForgeWidgets::exists('product-grid'), 'product-grid def must exist');
    assert_true(PageForgeWidgets::exists('product-view'), 'product-view def must exist');
    assert_true(PageForgeWidgets::exists('loop'), 'loop def must exist');
    assert_true(PageForgeWidgets::exists('mega-menu'), 'mega-menu def must exist');
    $doc = PageForgeRenderer::normalizeDocument(['elements' => [[
        'id' => 'pg', 'elType' => 'widget', 'widgetType' => 'product-grid',
        'settings' => ['category_slug' => 'shoes', 'limit' => 2],
    ]]]);
    $html = PageForgeRenderer::render($doc, 1);
    assert_true(str_contains($html, 'pf-products') && str_contains($html, 'shoes'), 'product-grid fallback must reflect its settings');
});

unit('pageforge phase6: kit override validation is strict and clamped', function (): void {
    $v = PageForgeKit::validateOverrides([
        'colors' => ['primary' => 'red', 'secondary' => '#ABCDEF'],
        'fonts' => ['heading' => 'inter', 'body' => 'bogus'],
        'button' => ['radius' => 'pill', 'background' => '#123ABC'],
        'layout' => ['content_width' => 5000, 'widget_gap' => 250],
        'breakpoints' => ['tablet' => 900, 'mobile' => 600],
        'custom_css' => '.x{}</style><script>1</script>',
    ]);
    assert_true(!isset($v['colors']['primary']), 'invalid color must be dropped');
    assert_eq('#abcdef', $v['colors']['secondary'] ?? null);
    assert_eq('inter', $v['fonts']['heading'] ?? null);
    assert_true(!isset($v['fonts']['body']), 'font outside the core allow-list must be dropped');
    assert_eq('pill', $v['button']['radius'] ?? null);
    assert_eq(2000, $v['layout']['content_width'] ?? null);
    assert_eq(100, $v['layout']['widget_gap'] ?? null);
    assert_true(!str_contains((string) ($v['custom_css'] ?? ''), '<'), 'custom CSS must not contain a style breakout');
});

unit('pageforge phase6: kit tokens resolve in compiled CSS with kit breakpoints', function (): void {
    $kit = PageForgeKit::defaults();
    $kit['colors']['primary'] = '#123abc';
    $kit['button']['background'] = '#123abc';
    assert_eq('#123abc', PageForgeKit::token($kit, 'primary'));
    assert_true(PageForgeKit::token($kit, 'nope') === null, 'unknown token must resolve to null');
    PageForgeCss::setKit($kit);
    PageForgeCss::setBreakpoints(900, 600);
    try {
        $doc = PageForgeRenderer::normalizeDocument(['elements' => [[
            'id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading',
            'settings' => ['text' => 'Kit', 'color' => '{{kit.primary}}', 'font_size' => 30, 'font_size_tablet' => 20],
        ]]]);
        $css = PageForgeRenderer::compile($doc, 5)['css'];
        assert_true(str_contains($css, '--pf-primary: #123abc'), 'kit variables must be compiled in');
        assert_true(str_contains($css, 'color: #123abc'), 'kit token must resolve to the hex');
        assert_true(str_contains($css, '@media (max-width: 900px)'), 'kit tablet breakpoint must drive the media query');
    } finally {
        PageForgeCss::setKit(null);
        PageForgeCss::setBreakpoints(PageForgeCss::BREAKPOINT_TABLET, PageForgeCss::BREAKPOINT_MOBILE);
    }
});

unit('pageforge phase7: kit import merge respects part selection', function (): void {
    $current = ['colors' => ['primary' => '#111111'], 'fonts' => ['heading' => 'inter'], 'custom_css' => '.a{}'];
    $payload = ['kit' => ['colors' => ['primary' => '#222222'], 'fonts' => ['heading' => 'roboto'], 'custom_css' => '.b{}']];
    $onlyColors = PageForgeKit::importMerge($payload, ['colors' => true], $current);
    assert_eq('#222222', $onlyColors['colors']['primary'] ?? null);
    assert_eq('inter', $onlyColors['fonts']['heading'] ?? null);
    assert_eq('.a{}', $onlyColors['custom_css'] ?? null);
    $onlyFonts = PageForgeKit::importMerge($payload, ['fonts' => true, 'custom_css' => true], $current);
    assert_eq('#111111', $onlyFonts['colors']['primary'] ?? null);
    assert_eq('roboto', $onlyFonts['fonts']['heading'] ?? null);
    assert_eq('.b{}', $onlyFonts['custom_css'] ?? null);
});

unit('pageforge phase7: setup data is valid (purposes, starters, palettes)', function (): void {
    require_once dirname(__DIR__, 2) . '/plugins/pageforge/lib/PageForgeSetup.php';
    assert_eq(['home', 'shop', 'contact'], array_column(PageForgeSetup::starterDocuments('shop', 'S'), 'slug'));
    assert_eq(['home', 'about', 'contact'], array_column(PageForgeSetup::starterDocuments('bogus', 'S'), 'slug'));
    foreach (PageForgeSetup::palettes() as $pal) {
        $v = PageForgeKit::validateOverrides(['colors' => $pal['colors']]);
        assert_eq(count($pal['colors']), count($v['colors'] ?? []), 'palette colors must validate');
    }
    foreach (PageForgeSetup::fontPairs() as $pair) {
        foreach (($pair['fonts'] ?? []) as $fk) {
            assert_true(isset(PageForgeKit::fontStacks()[$fk]), 'font pairing must use the core allow-list');
        }
    }
});

unit('pageforge phase8: conditions evaluator (value/in/not_empty/not/list)', function (): void {
    require_once dirname(__DIR__, 2) . '/plugins/pageforge/lib/PageForgeConditions.php';
    assert_true(PageForgeConditions::visible([], ['a' => '1']), 'no condition = visible');
    assert_true(PageForgeConditions::visible(['condition' => ['field' => 's', 'value' => 'manual']], ['s' => 'manual']));
    assert_true(!PageForgeConditions::visible(['condition' => ['field' => 's', 'value' => 'manual']], ['s' => 'pages']));
    assert_true(PageForgeConditions::visible(['condition' => ['field' => 's', 'in' => ['products', 'pages']]], ['s' => 'pages']));
    assert_true(!PageForgeConditions::visible(['condition' => ['field' => 's', 'in' => ['products', 'pages']]], ['s' => 'manual']));
    assert_true(PageForgeConditions::visible(['condition' => ['field' => 't', 'not_empty' => true]], ['t' => 'x']));
    assert_true(!PageForgeConditions::visible(['condition' => ['field' => 't', 'not_empty' => true]], ['t' => '']));
    assert_true(PageForgeConditions::visible(['condition' => ['field' => 's', 'not' => 'manual']], ['s' => 'pages']));
    assert_true(!PageForgeConditions::visible(['condition' => [['field' => 'a', 'value' => '1'], ['field' => 'b', 'value' => '2']]], ['a' => '1', 'b' => '3']), 'list conditions AND');
});

unit('pageforge phase8: hover ruleset, gradient validation, custom css scoping', function (): void {
    $doc = PageForgeRenderer::normalizeDocument(['elements' => [[
        'id' => 'x1', 'elType' => 'widget', 'widgetType' => 'heading',
        'settings' => ['text' => 'X', 'color_hover' => '#445566', 'custom_css' => '&:focus { color: #010203; }'],
        'elements' => [],
    ]]]);
    $out = PageForgeRenderer::compile($doc, 3);
    assert_true(str_contains($out['css'], ':hover { ') && str_contains($out['css'], 'color: #445566;'), 'hover ruleset compiled');
    assert_true(str_contains($out['css'], '.pf-el-x1:focus { color: #010203; }'), 'custom css & replaced by the element selector');
    $bad = PageForgeRenderer::normalizeDocument(['elements' => [[
        'id' => 'x2', 'elType' => 'container',
        'settings' => ['background_gradient' => 'linear-gradient(90deg, #fff, #000); } body { display:none'],
        'elements' => [],
    ]]]);
    $outBad = PageForgeRenderer::compile($bad, 3);
    assert_true(!str_contains($outBad['css'], 'display:none'), 'malicious gradient dropped by strict validation');
});

unit('pageforge phase10: display conditions matcher (include/exclude/scopes)', function (): void {
    require_once dirname(__DIR__, 2) . '/plugins/pageforge/lib/PageForgeDisplay.php';
    $ctx = ['route' => 'page', 'slug' => 'landing', 'kind' => 'page'];
    assert_true(PageForgeDisplayConditions::matches([], $ctx), 'empty spec always matches (legacy behaviour)');
    assert_true(PageForgeDisplayConditions::matches(['include' => [['scope' => 'entire', 'value' => '']]], $ctx), 'entire include matches');
    assert_true(PageForgeDisplayConditions::matches(['include' => [['scope' => 'slug', 'value' => 'landing']]], $ctx), 'slug include hit');
    assert_true(!PageForgeDisplayConditions::matches(['include' => [['scope' => 'slug', 'value' => 'other']]], $ctx), 'slug include miss');
    assert_true(PageForgeDisplayConditions::matches(['include' => [['scope' => 'route', 'value' => 'page']]], $ctx), 'route include hit');
    assert_true(!PageForgeDisplayConditions::matches(['include' => [['scope' => 'entire', 'value' => '']], 'exclude' => [['scope' => 'slug', 'value' => 'landing']]], $ctx), 'exclude wins over include');
    assert_true(PageForgeDisplayConditions::matches(['exclude' => [['scope' => 'slug', 'value' => 'other']]], $ctx), 'non-matching exclude keeps it visible');
    $parsed = PageForgeDisplayConditions::parse('slug:home, route:page, entire, bogus:x');
    assert_true(count($parsed) === 3, 'parse drops invalid scopes');
    assert_true(PageForgeDisplayConditions::format($parsed) === 'slug:home, route:page, entire', 'format round-trips parse');
    $nf = ['route' => 'notfound', 'slug' => 'missing', 'kind' => ''];
    assert_true(PageForgeDisplayConditions::matches(['include' => [['scope' => 'route', 'value' => 'notfound']]], $nf), 'notfound route matches the 404 context');
});

unit('pageforge phase10: popup config normalizer (defaults, clamps, enums)', function (): void {
    require_once dirname(__DIR__, 2) . '/plugins/pageforge/lib/PageForgeDisplay.php';
    $d = PageForgePopupConfig::normalize(null);
    assert_true($d['settings']['position'] === 'center' && $d['settings']['width'] === 640, 'defaults = Phase 4 popup behaviour');
    assert_true($d['settings']['overlay'] === true && $d['settings']['close_button'] === true, 'default overlay + close button on');
    assert_true($d['triggers']['on_load']['enabled'] === false && $d['rules']['max_shows'] === 0, 'no automatic triggers or caps by default');
    $c = PageForgePopupConfig::normalize([
        'settings' => ['position' => 'sideways', 'width' => 99999, 'animation' => 'spin', 'overlay' => false],
        'triggers' => ['on_load' => ['enabled' => true, 'delay' => 999], 'on_scroll' => ['enabled' => true, 'percent' => 1]],
        'rules' => ['max_shows' => 3, 'devices' => 'watch'],
    ]);
    assert_true($c['settings']['position'] === 'center', 'bad position falls back to center');
    assert_true($c['settings']['width'] === 1200, 'width clamped to 1200');
    assert_true($c['settings']['animation'] === 'fade', 'bad animation falls back to fade');
    assert_true($c['settings']['overlay'] === false, 'explicit overlay=false is kept');
    assert_true($c['triggers']['on_load']['delay'] === 120, 'delay clamped to 120');
    assert_true($c['triggers']['on_scroll']['percent'] === 5, 'scroll percent clamped to >= 5');
    assert_true($c['rules']['max_shows'] === 3 && $c['rules']['devices'] === 'all', 'rules kept / bad device falls back to all');
});

unit('pageforge phase11: dynamic tags resolve (page meta, kit, date; context wins; literals preserved)', function (): void {
    require_once dirname(__DIR__, 2) . '/plugins/pageforge/lib/PageForgeLibrary.php';
    PageForgeRenderer::setPageMeta(['id' => 3, 'title' => 'META-TITLE', 'slug' => 'meta-page', 'kind' => 'page']);
    $doc = PageForgeRenderer::normalizeDocument(['elements' => [[
        'id' => 'd1', 'elType' => 'widget', 'widgetType' => 'heading',
        'settings' => ['text' => '{{page.title}} / {{kit.primary}} / {{date.year}} / {{unknown_field}}'],
        'elements' => [],
    ]]]);
    $out = PageForgeRenderer::compile($doc, 3);
    PageForgeRenderer::setPageMeta(null);
    assert_true(str_contains($out['html'], 'META-TITLE'), 'page.title resolves from page meta');
    assert_true(str_contains($out['html'], '#454299'), 'kit.primary resolves to the kit fallback accent');
    assert_true(str_contains($out['html'], (string) date('Y')), 'date.year resolves');
    assert_true(str_contains($out['html'], '{{unknown_field}}'), 'unknown plain token stays literal outside a fragment context');
    $frag = PageForgeRenderer::renderFragment($doc, ['page.title' => 'CTX-WINS']);
    assert_true(str_contains($frag, 'CTX-WINS'), 'fragment context wins over the dynamic tag');
    $frag2 = PageForgeRenderer::renderFragment($doc, ['other' => 'x']);
    assert_true(!str_contains($frag2, '{{unknown_field}}'), 'unknown field resolves to empty inside a fragment context (Phase 5 rule)');
});

unit('pageforge phase11: library rekey + package validate/build round-trip', function (): void {
    require_once dirname(__DIR__, 2) . '/plugins/pageforge/lib/PageForgeLibrary.php';
    $els = [['id' => 'a', 'elType' => 'container', 'settings' => [], 'elements' => [
        ['id' => 'b', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => []],
    ]]];
    $rk = PageForgeLibrary::rekeyElements($els);
    assert_true($rk[0]['id'] !== 'a' && $rk[0]['elements'][0]['id'] !== 'b', 'rekey replaces every id');
    assert_true($rk[0]['id'] !== $rk[0]['elements'][0]['id'], 'rekeyed ids are unique');
    $doc = ['elements' => [['id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'PKG-TEXT'], 'elements' => []]]];
    $pkg = PageForgeLibrary::buildPagePackage(
        ['title' => 'T', 'slug' => 't', 'kind' => 'popup', 'part' => null],
        $doc,
        ['include' => [['scope' => 'slug', 'value' => 'landing']]],
        ['settings' => ['width' => 500]]
    );
    assert_true($pkg['format'] === 'pageforge-page' && $pkg['popup']['settings']['width'] === 500, 'package builds with format + normalized popup config');
    $v = PageForgeLibrary::validatePagePackage($pkg);
    assert_true($v['title'] === 'T' && $v['kind'] === 'popup', 'package validates back');
    assert_true(($v['conditions']['include'][0]['value'] ?? '') === 'landing', 'conditions survive the round-trip');
    assert_true(str_contains(json_encode($v['document']), 'PKG-TEXT'), 'document survives the round-trip');
    $threw = false;
    try {
        PageForgeLibrary::validatePagePackage(['format' => 'nope']);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'a foreign package is rejected');
});
