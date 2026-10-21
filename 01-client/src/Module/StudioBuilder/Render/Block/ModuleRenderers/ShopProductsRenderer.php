<?php
/**
 * Kohevo Studio — `shop.products` renderer (Batch D5).
 *
 * Renders the rows the `shop.products` provider returned as a product
 * grid. Cards link to the Shop module's own product pages and the closing
 * CTA to the shop catalogue — Studio never re-implements the shop's cart
 * or checkout (add-to-cart needs the storefront's session CSRF token,
 * which Studio renders do not mint).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class ShopProductsRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.products';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rows = $scope->rows('items');
        if ($rows === null) {
            return CatalogFormat::unavailable($scope);
        }

        $heading    = $scope->string('heading');
        $showPrices = $scope->bool('show_prices', true);
        $showImages = $scope->bool('show_images', true);
        $columns    = (int) $scope->prop('columns', 3);
        if (!in_array($columns, [2, 3, 4], true)) {
            $columns = 3;
        }
        $ctaLabel = $scope->string('cta_label') !== '' ? $scope->string('cta_label') : Html::t('studio_view_shop', 'View shop');

        $items = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = CatalogFormat::str($row, 'name');
            $slug = CatalogFormat::str($row, 'slug');
            $url  = $slug !== '' ? $scope->site()->absoluteUrl('shop/product/' . $slug) : $scope->site()->absoluteUrl('shop');
            $desc = CatalogFormat::str($row, 'short_desc');
            $price = '';
            if ($showPrices) {
                $label = CatalogFormat::str($row, 'price_label');
                $price = $label !== '' ? $label : CatalogFormat::price($row['price_cents'] ?? null, $row['currency'] ?? null);
            }

            $image = '';
            $path = CatalogFormat::str($row, 'image_path');
            if ($showImages && $path !== '') {
                $src = preg_match('#^https?://#i', $path) === 1 ? $path : $scope->site()->absoluteUrl(ltrim($path, '/'));
                $image = '<img class="sb-catalog__image" src="' . Html::e($src) . '" alt="' . Html::e($name) . '" loading="lazy">';
            }

            if ((int) ($row['variant_count'] ?? 0) > 0) {
                $action = '<a class="sb-button sb-button--outline" href="' . Html::e($url) . '">' . Html::e(Html::t('studio_choose_options', 'Choose options')) . '</a>';
            } elseif (empty($row['in_stock'])) {
                $action = '<span class="sb-catalog__badge">' . Html::e(Html::t('studio_out_of_stock', 'Out of stock')) . '</span>';
            } else {
                $action = '<a class="sb-button sb-button--outline" href="' . Html::e($url) . '">' . Html::e(Html::t('studio_view_product', 'View product')) . '</a>';
            }

            $items .= '<li class="sb-catalog__item">'
                . $image
                . '<h3 class="sb-catalog__name"><a href="' . Html::e($url) . '">' . Html::e($name) . '</a></h3>'
                . ($desc !== '' ? '<p class="sb-catalog__description">' . Html::text($desc) . '</p>' : '')
                . ($price !== '' ? '<p class="sb-catalog__meta"><span class="sb-catalog__price">' . Html::e($price) . '</span></p>' : '')
                . '<p class="sb-button-row">' . $action . '</p>'
                . '</li>';
        }

        return '<div class="sb-catalog sb-catalog--shop">'
            . ($heading !== '' ? '<h2 class="sb-catalog__title">' . Html::e($heading) . '</h2>' : '')
            . ($items !== ''
                ? '<ul class="sb-catalog__list sb-catalog__list--cols-' . $columns . '">' . $items . '</ul>'
                    . '<p class="sb-button-row"><a class="sb-button sb-button--primary" href="' . Html::e($scope->site()->absoluteUrl('shop')) . '">' . Html::e($ctaLabel) . '</a></p>'
                : CatalogFormat::empty(Html::t('studio_no_products', 'No products are available right now.')))
            . '</div>';
    }
}
