<?php
/**
 * Kohevo Studio — `shop.cart_link` renderer (Batch D5).
 *
 * A link to the Shop module's own cart page. The cart itself (items,
 * totals, checkout) stays in the shop storefront; this block is just the
 * door. The label is a plain string prop — no session state is read, so
 * the block renders identically everywhere (not dynamic).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class ShopCartLinkRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.cart_link';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $label = $scope->string('label') !== '' ? $scope->string('label') : Html::t('studio_cart', 'Cart');
        $href  = $scope->site()->absoluteUrl('shop/cart');

        return $scope->string('variant', 'button') === 'link'
            ? '<a class="sb-link" href="' . Html::e($href) . '">' . Html::e($label) . '</a>'
            : '<a class="sb-button sb-button--primary" href="' . Html::e($href) . '">' . Html::e($label) . '</a>';
    }
}
