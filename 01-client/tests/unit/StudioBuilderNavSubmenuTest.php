<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — nav submenus (Batch D3):
 * the StudioMenus data model carries one level of `children`, and the
 * core.nav_menu renderer emits a nested list for both item shapes —
 * nested children (site menus) and `parent` indices (the block's own
 * links). The stylesheet carries the dropdown rules and the runtime a
 * touch toggle.
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

use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\NavMenuRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Tenancy\TenantContext;

function sbnav_scope(array $props): BlockRenderScope
{
    $tenants = new TenantContext();
    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site'));
    $block = ['type' => 'core.nav_menu', 'props' => $props];

    return new BlockRenderScope($block, $context, new CoreMediaResolver($tenants), new ResolvedTheme('default', []), new RenderCollector(), '', []);
}

unit('nav submenu: the menu store keeps one level of children and drops deeper nesting', function (): void {
    $menu = StudioMenus::validate('header', 'Header', [
        ['label' => 'Services', 'url' => '/services/', 'children' => [
            ['label' => 'Web', 'url' => '/services/web/'],
            ['label' => 'SEO', 'url' => '/services/seo/', 'children' => [['label' => 'Deep', 'url' => '/deep/']]],
        ]],
        ['label' => 'About', 'url' => '/about/'],
    ]);
    assert_eq(2, count($menu['items'][0]['children']), 'children are kept');
    assert_false(isset($menu['items'][0]['children'][1]['children']), 'a child may not carry children (one level only)');
    $roundTrip = StudioMenus::decode((string) json_encode(['menus' => [$menu]]));
    assert_eq('Web', $roundTrip['header']['items'][0]['children'][0]['label'], 'children survive a store round trip');
});

unit('nav submenu: invalid child data is refused, oversize submenus are refused', function (): void {
    assert_throws(\InvalidArgumentException::class, static function (): void {
        StudioMenus::validate('m', 'M', [['label' => 'A', 'url' => '/a/', 'children' => [['label' => 'B', 'url' => 'javascript:alert(1)']]]]);
    }, 'an unsafe child URL is refused');
    assert_throws(\InvalidArgumentException::class, static function (): void {
        $kids = array_fill(0, StudioMenus::MAX_CHILDREN + 1, ['label' => 'K', 'url' => '/k/']);
        StudioMenus::validate('m', 'M', [['label' => 'A', 'url' => '/a/', 'children' => $kids]]);
    }, 'a submenu over the cap is refused');
});

unit('nav submenu: renderer nests children from a bound-menu shape', function (): void {
    $html = (new NavMenuRenderer())->render(sbnav_scope(['items' => [
        ['label' => 'Services', 'url' => '/services/', 'children' => [
            ['label' => 'Web', 'url' => '/services/web/'],
            ['label' => 'SEO', 'url' => '/services/seo/', 'new_tab' => true],
        ]],
        ['label' => 'About', 'url' => '/about/'],
    ]]));
    assert_true(str_contains($html, '<li class="sb-nav__item sb-nav__item--has-sub">'), 'parent item carries the has-sub class');
    assert_true(str_contains($html, 'aria-haspopup="true" aria-expanded="false"'), 'parent link carries the aria markers');
    assert_true(str_contains($html, '<ul class="sb-nav__sub"><li class="sb-nav__subitem"><a class="sb-nav__link" href="/services/web/">Web</a></li>'), 'submenu markup is nested inside the parent item');
    assert_true(str_contains($html, 'target="_blank"'), 'child new_tab is honoured');
});

unit('nav submenu: the block\'s own links nest via the parent index', function (): void {
    $html = (new NavMenuRenderer())->render(sbnav_scope(['items' => [
        ['label' => 'Services', 'url' => '/services/'],
        ['label' => 'Web', 'url' => '/services/web/', 'parent' => 1],
        ['label' => 'About', 'url' => '/about/'],
        ['label' => 'Orphan', 'url' => '/orphan/', 'parent' => 99],
    ]]));
    assert_true(str_contains($html, 'sb-nav__item--has-sub'), 'parent index nests the item');
    assert_true(str_contains($html, '<li class="sb-nav__subitem"><a class="sb-nav__link" href="/services/web/">Web</a></li>'), 'the child renders in the submenu');
    assert_true(str_contains($html, '<li class="sb-nav__item"><a class="sb-nav__link" href="/orphan/">Orphan</a></li>'), 'an item pointing at a missing parent stays top level');
    $flat = (new NavMenuRenderer())->render(sbnav_scope(['items' => [['label' => 'Home', 'url' => '/']]]));
    assert_false(str_contains($flat, 'sb-nav__sub'), 'a flat menu renders no submenu markup (unchanged shape)');
});

unit('nav submenu: stylesheet and runtime carry the dropdown behaviour', function (): void {
    assert_true(str_contains(StudioStylesheet::css(), '.sb-nav__item--has-sub:hover>.sb-nav__sub'), 'submenu opens on hover/focus');
    assert_true(str_contains(StudioStylesheet::css(), '.sb-nav--vertical .sb-nav__sub'), 'vertical menus get the inline variant');
    assert_eq('20', StudioStylesheet::VERSION, 'stylesheet version bumped for the new rules');
    $runtime = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($runtime, 'initNavMenus') && str_contains($runtime, "'navMenus'"), 'runtime registers the touch toggle');
});
