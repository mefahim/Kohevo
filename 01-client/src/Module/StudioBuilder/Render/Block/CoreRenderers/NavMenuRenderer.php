<?php
/**
 * Kohevo Studio — `core.nav_menu` renderer: a list of links in a `<nav>`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Render\Html;

final class NavMenuRenderer implements BlockRendererInterface
{
    public const LAYOUTS = ['horizontal', 'vertical'];
    public const ALIGNS  = ['left', 'center', 'right'];
    public const STYLES  = ['plain', 'underline', 'pill'];

    public function type(): string
    {
        return 'core.nav_menu';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $layout = self::pick($scope->string('layout', 'horizontal'), self::LAYOUTS, 'horizontal');
        $align  = self::pick($scope->string('align', 'left'), self::ALIGNS, 'left');
        $style  = self::pick($scope->string('style', 'plain'), self::STYLES, 'plain');
        $name   = trim($scope->string('aria_label', 'Menu'));

        // A chosen site menu wins; a menu that was since deleted falls back to this block's own links, so a header never goes blank.
        $links = (array) $scope->prop('items', []);
        $menuKey = trim($scope->string('menu', ''));
        $menu = $menuKey !== '' ? StudioMenus::find($scope->context()->tenantId, $menuKey) : null;
        if ($menu !== null) {
            $links = $menu['items'];
        }

        $items = self::renderItems(self::tree($links), true);
        if ($items === '') {
            return '';
        }

        return '<nav' . Html::classAttr(['sb-nav', 'sb-nav--' . $layout, 'sb-nav--' . $align, 'sb-nav--' . $style])
            . ($name !== '' ? ' aria-label="' . Html::e($name) . '"' : '') . '>'
            . '<ul class="sb-nav__list">' . $items . '</ul></nav>';
    }

    /**
     * Normalize the two item shapes into a two-level tree: site-menu items
     * nest via `children`; the block's own links nest via a 1-based `parent`
     * index into the same list. Anything deeper collapses to the top level.
     *
     * @return list<array{item: array, children: list<array{item: array, children: list<never>}>}>
     */
    private static function tree(array $links): array
    {
        $nodes = [];
        foreach ($links as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $children = [];
            foreach ((array) ($item['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $children[] = ['item' => $child, 'children' => []];
                }
            }
            $nodes[$i] = ['item' => $item, 'children' => $children];
        }

        $roots = [];
        foreach ($nodes as $i => $node) {
            $parent = (int) ($node['item']['parent'] ?? 0) - 1;
            if ($node['children'] !== [] || $parent < 0 || $parent === $i || !isset($nodes[$parent])) {
                $roots[$i] = $node;
            }
        }
        foreach ($nodes as $i => $node) {
            if (isset($roots[$i])) {
                continue;
            }
            $parent = (int) ($node['item']['parent'] ?? 0) - 1;
            if (isset($roots[$parent])) {
                $roots[$parent]['children'][] = $node;
            } else {
                $roots[$i] = $node;
            }
        }

        return array_values($roots);
    }

    private static function renderItems(array $nodes, bool $topLevel): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $item = $node['item'];
            $anchor = Html::link(['href' => $item['url'] ?? null, 'label' => $item['label'] ?? '', 'target' => !empty($item['new_tab']) ? '_blank' : '_self'], 'sb-nav__link');
            if ($anchor === '') {
                continue;
            }
            $kids = $topLevel && $node['children'] !== [] ? self::renderItems($node['children'], false) : '';
            if ($kids === '') {
                $html .= '<li class="' . ($topLevel ? 'sb-nav__item' : 'sb-nav__subitem') . '">' . $anchor . '</li>';
                continue;
            }
            $anchor = (string) preg_replace('/^<a /', '<a aria-haspopup="true" aria-expanded="false" ', $anchor, 1);
            $html .= '<li class="sb-nav__item sb-nav__item--has-sub">' . $anchor . '<ul class="sb-nav__sub">' . $kids . '</ul></li>';
        }

        return $html;
    }

    /** @param list<string> $allowed */
    private static function pick(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
