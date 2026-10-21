<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Batch D5: the shop
 * blocks. `shop.products` (provider-bound product grid) and
 * `shop.cart_link`. The provider degrades to [] when the Shop module is
 * absent; the renderer links to the shop's own pages and never renders a
 * cart or checkout itself.
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

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Provider\ShopProductsProvider;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\ShopCartLinkRenderer;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\ShopProductsRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

final class _SbshopNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia { return null; }
}

function sbshop_scope(string $type, array $props, ?array $rows, bool $editor = false): BlockRenderScope
{
    $site = new SiteContext('https://acme.test', 'Acme', '', '', 'en');
    $ctx = $editor
        ? RenderContext::forEditor(101, $site, StudioActor::authenticated(3, [StudioPermissions::VIEW, StudioPermissions::EDIT]), static fn(string $m): bool => true)
        : RenderContext::forPublic(101, $site, static fn(string $m): bool => true);
    $block = ['id' => 'blk_' . str_repeat('a', 24), 'type' => $type, 'props' => $props];
    return new BlockRenderScope($block, $ctx, new _SbshopNoMedia(), new ResolvedTheme('default', ThemeResolver::DEFAULT_TOKENS), new RenderCollector(), '', ['items' => $rows]);
}

function sbshop_row(array $over = []): array
{
    return $over + [
        'id' => 7, 'name' => 'Ceramic Mug', 'slug' => 'ceramic-mug', 'short_desc' => 'Hand glazed',
        'price_cents' => 1250, 'price_label' => '$12.50', 'currency' => 'USD',
        'image_path' => 'uploads/mug.jpg', 'featured' => false, 'variant_count' => 0, 'in_stock' => true,
    ];
}

unit('shop provider: identity, entitlement and graceful absence', function (): void {
    $provider = new ShopProductsProvider();
    assert_eq('shop.products', $provider->key(), 'provider key');
    assert_eq('shop', $provider->requiredEntitlement(), 'shop entitlement');
    assert_eq(24, $provider->maxResults(), 'bounded result set');
    assert_eq([], $provider->execute(new TenantContext(), []), 'no ShopAPI in the test env -> no rows, no error');
    $keys = array_column($provider->parameterSchema()->fields(), 'key');
    assert_eq(['category', 'featured_only', 'sort', 'limit'], $keys, 'parameter schema');
    assert_true(StudioRuntimeFactory::dataProviders()->has('shop.products'), 'provider registered in the runtime factory');
});

unit('shop blocks: definitions carry the shop entitlement and binding', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $products = $registry->get('shop.products');
    assert_true($products !== null, 'shop.products registered');
    assert_eq('shop', $products->requiredEntitlement(), 'products entitlement');
    assert_eq(['items' => 'shop.products'], $products->bindingSlots(), 'products binding slot');
    $cart = $registry->get('shop.cart_link');
    assert_true($cart !== null && $cart->requiredEntitlement() === 'shop', 'shop.cart_link registered with entitlement');
});

unit('shop products renderer: grid cards link to the shop product pages', function (): void {
    $renderer = new ShopProductsRenderer();
    $html = $renderer->render(sbshop_scope('shop.products', ['heading' => 'Our shop', 'columns' => 4], [
        sbshop_row(),
        sbshop_row(['id' => 8, 'name' => 'Tee <b>Shirt</b>', 'slug' => 'tee', 'variant_count' => 2, 'image_path' => 'https://cdn.test/tee.png']),
        sbshop_row(['id' => 9, 'name' => 'Sold Out Vase', 'slug' => 'vase', 'in_stock' => false, 'image_path' => '']),
    ]));
    assert_true(str_contains($html, 'sb-catalog--shop') && str_contains($html, 'sb-catalog__list--cols-4'), 'grid with the chosen column count');
    assert_true(str_contains($html, 'href="https://acme.test/shop/product/ceramic-mug"'), 'card links to the product page');
    assert_true(str_contains($html, 'src="https://acme.test/uploads/mug.jpg"'), 'relative image path resolved against the site base');
    assert_true(str_contains($html, 'src="https://cdn.test/tee.png"'), 'absolute image URL kept as-is');
    assert_true(str_contains($html, '$12.50'), 'the shop-formatted price label is preferred');
    assert_true(str_contains($html, 'Choose options'), 'products with variants offer option picking');
    assert_true(str_contains($html, 'Out of stock') && str_contains($html, 'sb-catalog__badge'), 'out-of-stock products get a badge, not a dead button');
    assert_true(str_contains($html, 'Tee &lt;b&gt;Shirt&lt;/b&gt;'), 'product names are escaped');
    assert_true(str_contains($html, 'href="https://acme.test/shop"') && str_contains($html, 'View shop'), 'closing CTA links to the catalogue');
});

unit('shop products renderer: unavailable and empty states stay honest', function (): void {
    $renderer = new ShopProductsRenderer();
    assert_eq('', $renderer->render(sbshop_scope('shop.products', [], null)), 'public render with no binding is silent');
    assert_true(str_contains($renderer->render(sbshop_scope('shop.products', [], null, true)), 'sb-unavailable'), 'editor sees the unavailable notice');
    assert_true(str_contains($renderer->render(sbshop_scope('shop.products', [], [])), 'No products are available right now.'), 'empty catalogue message');
    $noPrices = $renderer->render(sbshop_scope('shop.products', ['show_prices' => false], [sbshop_row()]));
    assert_false(str_contains($noPrices, '$12.50'), 'show_prices=false hides prices');
});

unit('shop cart link renderer: button and link variants point at the cart', function (): void {
    $renderer = new ShopCartLinkRenderer();
    $button = $renderer->render(sbshop_scope('shop.cart_link', [], null));
    assert_true(str_contains($button, 'sb-button') && str_contains($button, 'href="https://acme.test/shop/cart"') && str_contains($button, '>Cart<'), 'default button variant with the default label');
    $link = $renderer->render(sbshop_scope('shop.cart_link', ['label' => 'My basket', 'variant' => 'link'], null));
    assert_true(str_contains($link, 'sb-link') && str_contains($link, '>My basket<'), 'link variant with a custom label');
    assert_false($renderer->isDynamic(), 'the cart link renders identically everywhere');
});
