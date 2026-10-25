<?php
declare(strict_types=1);
/**
 * PageForge engine smoke test — CLI only, no platform boot, no database.
 *
 * Exercises the pure engine (normalize → sanitize → render → CSS compile)
 * on a sample document that mirrors the Phase 1 gate: a container holding
 * a heading, a text block and a button, with desktop + mobile styles.
 * Exits non-zero on the first failed expectation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__) . '/lib/PageForgeRenderer.php';

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if (!$ok) $fail++;
};

$raw = [
    'elements' => [
        [
            'id' => 'hero', 'elType' => 'container',
            'settings' => ['flex_direction' => 'column', 'gap' => 12, 'padding' => ['top' => 24, 'right' => 16, 'bottom' => 24, 'left' => 16, 'unit' => 'px'], 'background_color' => '#F5F3EE', 'evil_key' => 'dropped'],
            'elements' => [
                ['id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading',
                 'settings' => ['text' => 'Build <b>bold</b> pages', 'html_tag' => 'h1', 'font_size' => 40, 'font_size_mobile' => 28, 'color' => '#11130F', 'text_align' => 'center']],
                ['id' => 'cta', 'elType' => 'widget', 'widgetType' => 'button',
                 'settings' => ['text' => 'Start', 'link_url' => 'https://example.com/start', 'background_color' => '#454299']],
                ['id' => 'bad', 'elType' => 'widget', 'widgetType' => 'nope-not-real', 'settings' => []],
                ['id' => 'xss', 'elType' => 'widget', 'widgetType' => 'heading',
                 'settings' => ['text' => '<script>alert(1)</script>', 'link_url' => 'javascript:alert(1)']],
            ],
        ],
    ],
];

$doc = PageForgeRenderer::normalizeDocument($raw);
$check(count($doc['elements']) === 1, 'one top-level container survives normalization');
$check(count($doc['elements'][0]['elements']) === 3, 'unknown widget dropped at save-time normalization');
$check(!isset($doc['elements'][0]['settings']['evil_key']), 'undeclared setting key dropped by sanitizer');

$compiled = PageForgeRenderer::compile($doc, 7);
$html = $compiled['html'];
$css = $compiled['css'];

$check(str_contains($html, 'pf-page-7'), 'page wrapper class present');
$check(str_contains($html, '<h1 class="pf-heading pf-el-h1">Build &lt;b&gt;bold&lt;/b&gt; pages</h1>'), 'heading text is escaped, tag honored');
$check(str_contains($html, 'href="https://example.com/start"'), 'button link rendered');
$check(!str_contains($html, '<script>'), 'script payload never reaches output');
$check(!str_contains($html, 'javascript:'), 'javascript: URL refused by sanitizer');

$check(str_contains($css, '.pf-page-7 .pf-el-hero { display: flex; }'), 'container is a flex parent');
$check(str_contains($css, 'background-color: #f5f3ee;'), 'container background compiled (lowercased hex)');
$check(str_contains($css, 'gap: 12px;'), 'container gap compiled with unit');
$check(str_contains($css, 'font-size: 40px;'), 'desktop font size compiled');
$check(str_contains($css, '@media (max-width: 767px)'), 'mobile media query emitted');
$check(str_contains($css, 'font-size: 28px;'), 'mobile font size compiled inside the responsive pass');


// ---- Phase 2 widgets ----
$p2 = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'root', 'elType' => 'container', 'settings' => [],
    'elements' => [
        ['id' => 'il', 'elType' => 'widget', 'widgetType' => 'icon-list', 'settings' => ['items' => [
            ['icon_name' => 'check', 'text' => 'Fast', 'link_url' => ''],
            ['icon_name' => 'bogus-icon', 'text' => 'Safe', 'link_url' => 'javascript:alert(1)'],
            'garbage-row',
        ]]],
        ['id' => 'vid', 'elType' => 'widget', 'widgetType' => 'video', 'settings' => ['source' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']],
        ['id' => 'vidbad', 'elType' => 'widget', 'widgetType' => 'video', 'settings' => ['source' => 'youtube', 'url' => 'https://evil.example/watch?v=xxxxxxxxxx']],
        ['id' => 'tabs', 'elType' => 'widget', 'widgetType' => 'tabs', 'settings' => ['items' => [
            ['title' => 'One', 'content' => 'First'], ['title' => 'Two', 'content' => 'Second'],
        ]]],
        ['id' => 'acc', 'elType' => 'widget', 'widgetType' => 'accordion', 'settings' => ['items' => [['title' => 'Q?', 'content' => 'A.']]]],
        ['id' => 'prog', 'elType' => 'widget', 'widgetType' => 'progress', 'settings' => ['title' => 'Skill', 'percent' => 140]],
        ['id' => 'cnt', 'elType' => 'widget', 'widgetType' => 'counter', 'settings' => ['end_number' => 12500, 'suffix' => '+', 'title' => 'Users']],
        ['id' => 'raw', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => ['content' => '<p onclick="x()">Hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>']],
        ['id' => 'soc', 'elType' => 'widget', 'widgetType' => 'social-icons', 'settings' => ['items' => [
            ['network' => 'facebook', 'url' => 'https://facebook.com/page'], ['network' => 'x', 'url' => 'notaurl'],
        ]]],
        ['id' => 'rate', 'elType' => 'widget', 'widgetType' => 'star-rating', 'settings' => ['rating' => '4.5']],
    ],
]]]);
$p2c = PageForgeRenderer::compile($p2, 8);
$h = $p2c['html'];
$check(str_contains($h, 'pf-icon-list'), 'phase2: icon-list renders');
$check(substr_count($h, '<li>') >= 2 && !str_contains($h, 'garbage-row'), 'phase2: repeater drops non-array rows');
$check(!str_contains($h, 'javascript:'), 'phase2: repeater unsafe URL dropped by sanitizer');
$check(str_contains($h, 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'phase2: youtube URL becomes nocookie embed');
$check(!str_contains($h, 'evil.example'), 'phase2: non-youtube URL refused for youtube source');
$check(str_contains($h, 'type="radio"') && str_contains($h, 'pf-tabs__panel--1'), 'phase2: tabs render radio technique + panels');
$check(str_contains($h, '<details><summary>Q?</summary>'), 'phase2: accordion uses details/summary');
$check(str_contains($h, 'aria-valuenow="100"') && str_contains($h, 'width:100%'), 'phase2: progress clamps percent to 100');
$check(str_contains($h, '12,500+'), 'phase2: counter formats thousands + suffix');
$check(!str_contains($h, '<script>') && !str_contains($h, 'onclick'), 'phase2: raw html widget strips script + handlers');
$check(str_contains($h, '<p>Hi</p>'), 'phase2: raw html keeps legitimate markup');
$check(str_contains($h, 'pf-social__btn--facebook') && !str_contains($h, 'pf-social__btn--x'), 'phase2: social item with unsafe URL dropped');
$check(str_contains($h, 'width:90%') && str_contains($h, 'Rated 4.5 out of 5'), 'phase2: star rating fractional fill + aria label');

// ---- Phase 5 widgets ----
PageForgeRenderer::setDocumentResolver(static function (string $slug, string $kind): ?array {
    if ($slug === 'card' && $kind === 'loop-item') {
        return ['elements' => [
            ['id' => 'tplh', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => '{{title}}', 'font_size' => 22]],
            ['id' => 'tpli', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => ['src' => '{{image}}', 'alt' => '{{title}}']],
        ]];
    }
    if ($slug === 'mega-panel' && $kind === 'part') {
        return ['elements' => [
            ['id' => 'ph', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'PANEL-HEAD']],
        ]];
    }
    return null;
});
$p5 = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'root5', 'elType' => 'container', 'settings' => [],
    'elements' => [
        ['id' => 'lp', 'elType' => 'widget', 'widgetType' => 'loop', 'settings' => [
            'source' => 'manual', 'template_slug' => 'card', 'count' => 6, 'columns' => '2',
            'items' => [
                ['title' => 'Alpha', 'text' => '', 'image' => 'https://seed.invalid/alpha.png', 'url' => ''],
                ['title' => 'Beta', 'text' => '', 'image' => '', 'url' => ''],
            ],
        ]],
        ['id' => 'pg', 'elType' => 'widget', 'widgetType' => 'product-grid', 'settings' => ['category_slug' => 'shoes', 'limit' => 3]],
        ['id' => 'pv', 'elType' => 'widget', 'widgetType' => 'product-view', 'settings' => ['product_slug' => 'seed-product']],
        ['id' => 'mm', 'elType' => 'widget', 'widgetType' => 'mega-menu', 'settings' => ['items' => [
            ['label' => 'Shop', 'url' => 'https://example.com/shop', 'panel_slug' => 'mega-panel'],
        ]]],
    ],
]]]);
$p5c = PageForgeRenderer::compile($p5, 9);
$h5 = $p5c['html'];
$check(str_contains($h5, 'pf-loop') && str_contains($h5, 'Alpha') && str_contains($h5, 'Beta'), 'phase5: loop renders the template once per manual item');
$check(!str_contains($h5, '{{'), 'phase5: no binding placeholder survives rendering');
$check(str_contains($h5, 'alpha.png'), 'phase5: {{image}} placeholder binds through the sanitizer into the image src');
$check(str_contains($p5c['css'], 'font-size: 22px'), 'phase5: loop template CSS is compiled into the host page CSS');
$check(str_contains($h5, 'pf-products') && str_contains($h5, 'data-limit="3"') && str_contains($h5, 'shoes'), 'phase5: product-grid fallback reflects category + limit');
$check(str_contains($h5, 'pf-product-view') && str_contains($h5, 'seed-product'), 'phase5: product-view fallback reflects the slug');
$check(str_contains($h5, 'pf-megamenu') && str_contains($h5, 'PANEL-HEAD'), 'phase5: mega-menu renders the linked part as its panel');
PageForgeRenderer::setDocumentResolver(null);

// ---- Phase 6: kit defaults sync-fallback, validation, token resolution,
//      theme CSS, editable breakpoints, container gap inheritance ----
require_once dirname(__DIR__) . '/lib/PageForgeKit.php';
PageForgeKit::setRuntimeOverrides(null);
$kitDefault = PageForgeKit::current();
$check($kitDefault['colors']['primary'] === PageForgeKit::FALLBACK_ACCENT, 'phase6: kit primary falls back to the PageForge accent when no site branding exists');
$check($kitDefault['fonts']['body'] === 'system', 'phase6: kit body font follows the site font default (system)');
$valid6 = PageForgeKit::validateOverrides([
    'colors' => ['primary' => 'red', 'secondary' => '#ABCDEF'],
    'fonts' => ['heading' => 'inter', 'body' => 'bogus'],
    'layout' => ['content_width' => 5000],
    'custom_css' => '.x{color:red}</style><script>alert(1)</script>',
]);
$check(!isset($valid6['colors']['primary']) && ($valid6['colors']['secondary'] ?? '') === '#abcdef', 'phase6: override validation drops bad colors and normalizes hex');
$check(($valid6['fonts']['heading'] ?? '') === 'inter' && !isset($valid6['fonts']['body']), 'phase6: override validation enforces the core font allow-list');
$check(($valid6['layout']['content_width'] ?? 0) === 2000, 'phase6: override validation clamps content width');
$check(!str_contains((string) ($valid6['custom_css'] ?? ''), '<'), 'phase6: custom CSS cannot break out of the style block');

PageForgeKit::setRuntimeOverrides([
    'colors' => ['primary' => '#123abc'],
    'button' => ['background' => '#123abc', 'text' => '#ffffff', 'radius' => 'pill'],
    'layout' => ['widget_gap' => 33],
    'breakpoints' => ['tablet' => 900, 'mobile' => 600],
]);
$kit6 = PageForgeKit::current();
$check($kit6['breakpoints']['mobile'] < $kit6['breakpoints']['tablet'], 'phase6: kit keeps mobile breakpoint below tablet');
PageForgeCss::setKit($kit6);
PageForgeCss::setBreakpoints($kit6['breakpoints']['tablet'], $kit6['breakpoints']['mobile']);
$doc6 = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'root6', 'elType' => 'container', 'settings' => [],
    'elements' => [
        ['id' => 'kh', 'elType' => 'widget', 'widgetType' => 'heading',
         'settings' => ['text' => 'Kit', 'color' => '{{kit.primary}}', 'font_size' => 30, 'font_size_tablet' => 20]],
    ],
]]]);
$css6 = PageForgeRenderer::compile($doc6, 11)['css'];
$check(str_contains($css6, '--pf-primary: #123abc'), 'phase6: compiled CSS carries the kit variables');
$check(str_contains($css6, 'color: #123abc'), 'phase6: {{kit.primary}} token resolves to the kit hex in element CSS');
$check(str_contains($css6, '@media (max-width: 900px)'), 'phase6: editable tablet breakpoint drives the media query');
$check(str_contains($css6, 'gap: 33px'), 'phase6: container without its own gap inherits the kit widget gap');
$check(str_contains($css6, '.pf-button { background: #123abc; color: #ffffff; border-radius: 999px;'), 'phase6: theme style emits the kit button theme');
$doc6b = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'kh2', 'elType' => 'widget', 'widgetType' => 'heading',
    'settings' => ['text' => 'Nope', 'color' => '{{kit.nope}}'],
]]]);
$css6b = PageForgeRenderer::compile($doc6b, 12)['css'];
$check(!str_contains($css6b, '{{kit.nope}}') && !str_contains($css6b, 'color: ;'), 'phase6: unknown kit token drops the declaration, never leaks raw');
PageForgeKit::setRuntimeOverrides(null);
PageForgeCss::setKit(null);
PageForgeCss::setBreakpoints(PageForgeCss::BREAKPOINT_TABLET, PageForgeCss::BREAKPOINT_MOBILE);

// ---- Phase 7: setup-wizard data + kit import merge (pure helpers) ----
require_once dirname(__DIR__) . '/lib/PageForgeSetup.php';
$docs7 = PageForgeSetup::starterDocuments('shop', 'My Shop');
$check(array_column($docs7, 'slug') === ['home', 'shop', 'contact'], 'phase7: shop purpose maps to home+shop+contact starters');
$shopDoc = $docs7[1]['document'] ?? [];
$check(str_contains(json_encode($shopDoc), 'product-grid'), 'phase7: shop starter carries a real product-grid widget');
$check(str_contains(json_encode($docs7[0]['document']), 'My Shop'), 'phase7: home starter carries the site name');
$check(array_column(PageForgeSetup::starterDocuments('nope', 'X'), 'slug') === ['home', 'about', 'contact'], 'phase7: unknown purpose falls back to business starters');
$palettesOk = true;
foreach (PageForgeSetup::palettes() as $pal) {
    $v = PageForgeKit::validateOverrides(['colors' => $pal['colors']]);
    if (count($v['colors'] ?? []) !== count($pal['colors'])) $palettesOk = false;
}
$check($palettesOk, 'phase7: every palette preset passes kit color validation');
$pairsOk = true;
foreach (PageForgeSetup::fontPairs() as $pair) {
    foreach (($pair['fonts'] ?? []) as $fk) {
        if (!isset(PageForgeKit::fontStacks()[$fk])) $pairsOk = false;
    }
}
$check($pairsOk, 'phase7: every font pairing uses the core font allow-list');
$cur7 = ['colors' => ['primary' => '#111111'], 'fonts' => ['heading' => 'inter']];
$payload7 = ['kit' => ['colors' => ['primary' => '#222222'], 'fonts' => ['heading' => 'roboto']]];
$mColors = PageForgeKit::importMerge($payload7, ['colors' => true], $cur7);
$check(($mColors['colors']['primary'] ?? '') === '#222222' && ($mColors['fonts']['heading'] ?? '') === 'inter', 'phase7: import applies colors only when only colors are selected');
$mFonts = PageForgeKit::importMerge($payload7, ['fonts' => true], $cur7);
$check(($mFonts['colors']['primary'] ?? '') === '#111111' && ($mFonts['fonts']['heading'] ?? '') === 'roboto', 'phase7: import leaves unselected parts untouched');
$exp7 = PageForgeKit::exportData();
$check(($exp7['kind'] ?? '') === 'kit' && array_key_exists('kit', $exp7) && isset($exp7['identity']), 'phase7: export package has the kit + identity shape');

// ---- Phase 8: style depth (hover, classes, conditions, container, custom CSS, motion) ----
$doc8 = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'hov', 'elType' => 'widget', 'widgetType' => 'heading',
    'settings' => ['text' => 'Hover me', 'color_hover' => '#123456', 'background_color_hover' => '#abcdef'],
    'elements' => [],
]]]);
$out8 = PageForgeRenderer::compile($doc8, 7);
$check(str_contains($out8['css'], ':hover { ') && str_contains($out8['css'], 'color: #123456;'), 'phase8: hover state emits a :hover ruleset');
$check(str_contains($out8['css'], 'background-color: #abcdef;'), 'phase8: hover background resolves in the hover ruleset');
$doc8b = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'grid1', 'elType' => 'container',
    'settings' => ['layout_mode' => 'grid', 'grid_columns' => '3', 'flex_wrap' => 'wrap'],
    'elements' => [['id' => 'kid', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'k'], 'elements' => []]],
]]]);
$out8b = PageForgeRenderer::compile($doc8b, 7);
$check(str_contains($out8b['css'], 'display: grid;') && str_contains($out8b['css'], 'grid-template-columns: repeat(3, minmax(0, 1fr));'), 'phase8: container grid mode + columns compile');
$check(!str_contains($out8b['css'], '.pf-el-grid1 { display: flex; }'), 'phase8: grid container does not also get the flex display rule');
$check(str_contains($out8b['css'], 'flex-wrap: wrap;'), 'phase8: flex_wrap compiles');
$doc8c = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'grad', 'elType' => 'container',
    'settings' => ['background_gradient' => 'linear-gradient(90deg, #ff0000, #0000ff)'],
    'elements' => [],
]]]);
$out8c = PageForgeRenderer::compile($doc8c, 7);
$check(str_contains($out8c['css'], 'background-image: linear-gradient(90deg, #ff0000, #0000ff);'), 'phase8: valid gradient compiles to background-image');
$doc8d = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'grad2', 'elType' => 'container',
    'settings' => ['background_gradient' => 'url(https://evil.example/x); color: red'],
    'elements' => [],
]]]);
$out8d = PageForgeRenderer::compile($doc8d, 7);
$check(!str_contains($out8d['css'], 'evil.example'), 'phase8: invalid gradient is dropped, never leaks');
$doc8e = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'anim', 'elType' => 'widget', 'widgetType' => 'heading',
    'settings' => ['text' => 'A', 'entrance_animation' => 'fade'],
    'elements' => [],
]]]);
$out8e = PageForgeRenderer::compile($doc8e, 7);
$check(str_contains($out8e['css'], 'animation: pf-fade .7s ease both;'), 'phase8: entrance animation compiles');
$check(str_contains((string) file_get_contents(dirname(__DIR__) . '/assets/pageforge.css'), '@keyframes pf-fade'), 'phase8: entrance keyframes ship in pageforge.css');
$doc8f = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'cc1', 'elType' => 'widget', 'widgetType' => 'heading',
    'settings' => ['text' => 'C', 'custom_css' => '& { outline: 3px solid #0a0b0c; }'],
    'elements' => [],
]]]);
$out8f = PageForgeRenderer::compile($doc8f, 7);
$check(str_contains($out8f['css'], '.pf-page-7 .pf-el-cc1 { outline: 3px solid #0a0b0c; }'), 'phase8: custom CSS & scopes to the element selector');
$doc8g = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'cc2', 'elType' => 'widget', 'widgetType' => 'heading',
    'settings' => ['text' => 'C', 'custom_css' => 'letter-spacing: 2px;'],
    'elements' => [],
]]]);
$out8g = PageForgeRenderer::compile($doc8g, 7);
$check(str_contains($out8g['css'], '.pf-el-cc2 { letter-spacing: 2px; }'), 'phase8: bare custom CSS declarations wrap in the element selector');
$doc8h = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'cls', 'elType' => 'widget', 'widgetType' => 'heading',
    'settings' => ['text' => 'C', 'css_classes' => 'my-hero  my-hero bad"onclick=x'],
    'elements' => [],
]]]);
$out8h = PageForgeRenderer::compile($doc8h, 7);
$check(str_contains($out8h['html'], 'pf-el-cls my-hero') && !str_contains($out8h['html'], 'onclick'), 'phase8: css_classes render sanitized + deduped on the element root');
$norm8 = PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'sv', 'elType' => 'widget', 'widgetType' => 'heading',
    'settings' => ['text' => 'S', 'color_hover' => '#111111', 'css_classes' => 'keepme', 'custom_css' => 'a < b { }'],
    'elements' => [],
]]]);
$sv = $norm8['elements'][0]['settings'] ?? [];
$check(($sv['color_hover'] ?? '') === '#111111' && ($sv['css_classes'] ?? '') === 'keepme', 'phase8: hover + classes survive sanitizing');
$check(!str_contains((string) ($sv['custom_css'] ?? ''), '<'), 'phase8: custom CSS has < stripped at save time');
$loopDef = PageForgeWidgets::get('loop');
$check(PageForgeConditions::visible($loopDef['controls']['template_slug'], ['source' => 'products']) === true
    && PageForgeConditions::visible($loopDef['controls']['template_slug'], ['source' => 'manual']) === false,
    'phase8: condition spec — template_slug visible for products/pages only');
$check(PageForgeConditions::visible($loopDef['controls']['items'], ['source' => 'manual']) === true
    && PageForgeConditions::visible($loopDef['controls']['items'], ['source' => 'pages']) === false,
    'phase8: condition spec — items visible for manual source only');
$check(PageForgeConditions::visible(['condition' => [['field' => 'a', 'value' => '1'], ['field' => 'b', 'not_empty' => true]]], ['a' => '1', 'b' => 'x']) === true
    && PageForgeConditions::visible(['condition' => [['field' => 'a', 'value' => '1'], ['field' => 'b', 'not_empty' => true]]], ['a' => '1', 'b' => '']) === false,
    'phase8: condition lists AND together');

// ---- Phase 9: editor depth (history core, autosave endpoint, revision restore) ----
$check(method_exists(PageForgeStore::class, 'listRevisions') && method_exists(PageForgeStore::class, 'restoreRevision'), 'phase9: store exposes revision listing + restore');
$check(str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/api.php'), "'autosave'"), 'phase9: api.php carries the autosave action');
$check(is_file(dirname(__DIR__) . '/ui/src/history.js'), 'phase9: DOM-free history core ships in ui/src');
$check(str_contains((string) file_get_contents(dirname(__DIR__) . '/assets/editor/editor.js'), 'Autosaved'), 'phase9: built editor bundle contains the autosave UI');
$check(str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/builder.php'), "'restore'"), 'phase9: builder handles revision restore posts');

// ---- Phase 10: theme + popup completion (display conditions, 404 part, popup triggers) ----
$check(class_exists('PageForgeDisplayConditions') && class_exists('PageForgePopupConfig'), 'phase10: display conditions + popup config libs load');
$check(method_exists(PageForgeStore::class, 'getPublishedPartFor') && method_exists(PageForgeStore::class, 'setConditions') && method_exists(PageForgeStore::class, 'setPopupConfig'), 'phase10: store exposes condition-aware selection + config setters');
$pfRouter10 = (string) file_get_contents(dirname(__DIR__) . '/public/router.php');
$check(str_contains($pfRouter10, "getPublishedPartFor('404'") && str_contains($pfRouter10, "getPublishedPartFor('header', \$ctx)"), 'phase10: router selects the 404 part + condition-aware header');
$check(str_contains($pfRouter10, 'data-config') && str_contains($pfRouter10, 'exit_intent') && str_contains($pfRouter10, 'pf10_views'), 'phase10: popup embeds carry config; trigger engine + caps ship in the router');
$check(PageForgeDisplayConditions::matches(['exclude' => [['scope' => 'slug', 'value' => 'x']]], ['route' => 'page', 'slug' => 'y', 'kind' => 'page']), 'phase10: matcher keeps non-excluded pages visible');

// ---- Phase 11: dynamic tags + library (W5 template save/insert, page export/import) ----
$check(class_exists('PageForgeDynamic') && class_exists('PageForgeLibrary'), 'phase11: dynamic tags + library libs load');
// Editor port: the redesign shell + bundle carry the Phase 9-11 feature set.
$pfShellPort = (string) @file_get_contents(dirname(__DIR__) . '/admin/builder.php');
$pfBundlePort = (string) @file_get_contents(dirname(__DIR__) . '/assets/editor/editor.js');
$pfSrcPort = (string) @file_get_contents(dirname(__DIR__) . '/ui/src/editor.jsx');
$check(str_contains($pfShellPort, "'revisions' =>") && str_contains($pfShellPort, "'conditions' =>") && str_contains($pfShellPort, "'dynamicTags' =>"), 'port: builder boot carries revisions, conditions and dynamic tags');
$check(str_contains($pfSrcPort, "from './history.js'") && str_contains($pfSrcPort, 'createHistory'), 'port: editor source builds on the Phase 9 history core');
$check(str_contains($pfBundlePort, 'Autosaved'), 'port: bundle carries autosave');
$check(count(PageForgeDynamic::tags()) >= 12, 'phase11: tag registry lists the documented tags');
$check(method_exists(PageForgeStore::class, 'saveAsTemplate') && method_exists(PageForgeStore::class, 'insertTemplate') && method_exists(PageForgeStore::class, 'exportPage') && method_exists(PageForgeStore::class, 'importPage'), 'phase11: store exposes template + export/import methods');
PageForgeRenderer::setPageMeta(['id' => 7, 'title' => 'SMOKE-TITLE', 'slug' => 'smoke-page', 'kind' => 'page']);
$pf11 = PageForgeRenderer::compile(PageForgeRenderer::normalizeDocument(['elements' => [[
    'id' => 'dt1', 'elType' => 'widget', 'widgetType' => 'heading',
    'settings' => ['text' => '{{page.title}} - {{date.year}} - keep {{unknown_field}}'], 'elements' => [],
]]]), 7);
PageForgeRenderer::setPageMeta(null);
$check(str_contains($pf11['html'], 'SMOKE-TITLE') && str_contains($pf11['html'], (string) date('Y')), 'phase11: page + date tags resolve at compile');
$check(str_contains($pf11['html'], '{{unknown_field}}'), 'phase11: unknown plain tokens stay literal outside a loop context');
$rk = PageForgeLibrary::rekeyElements([['id' => 'a', 'elType' => 'container', 'settings' => [], 'elements' => [['id' => 'b', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => []]]]]);
$check($rk[0]['id'] !== 'a' && $rk[0]['elements'][0]['id'] !== 'b' && $rk[0]['id'] !== $rk[0]['elements'][0]['id'], 'phase11: template rekey assigns fresh unique ids');

echo $fail === 0 ? PHP_EOL . 'SMOKE OK' . PHP_EOL : PHP_EOL . 'SMOKE FAILED (' . $fail . ')' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
