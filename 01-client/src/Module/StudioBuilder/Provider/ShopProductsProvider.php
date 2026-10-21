<?php
/**
 * Kohevo Studio (studio-builder) — `shop.products` Data Provider (Batch D5).
 *
 * Thin adapter over the Shop module's own `\ShopAPI::publishedProducts()`
 * (already tenant-scoped via `current_tenant_id()`), following the booking
 * provider pattern. Shop prices are DECIMAL amounts in a tenant-level
 * currency (not per-product cents), so each row carries both a normalized
 * `price_cents` + `currency` pair (for CatalogFormat) and the shop's own
 * formatted `price_label` (symbol style, e.g. ৳1,234.00), which the
 * renderer prefers. `image_url` stays a raw stored path here — resolving
 * it against the site base is the renderer's job (it owns the SiteContext).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Tenancy\TenantContext;

final class ShopProductsProvider implements PublicDataProviderInterface
{
    public function key(): string
    {
        return 'shop.products';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'category', 'type' => 'string', 'label' => 'Category slug', 'required' => false, 'default' => '', 'max_length' => 80],
            ['key' => 'featured_only', 'type' => 'boolean', 'label' => 'Featured products only', 'required' => false, 'default' => false],
            ['key' => 'sort', 'type' => 'enum', 'label' => 'Sort order', 'required' => false, 'allowed_values' => ['newest', 'price-asc', 'price-desc', 'name', 'featured'], 'default' => 'newest'],
            ['key' => 'limit', 'type' => 'number', 'label' => 'Products to show', 'required' => false, 'default' => 12, 'min' => 1, 'max' => 24, 'integer_only' => true],
        ]);
    }

    public function requiredEntitlement(): ?string
    {
        return 'shop';
    }

    public function requiredPermission(): string
    {
        return StudioPermissions::VIEW;
    }

    public function maxResults(): int
    {
        return 24;
    }

    public function execute(TenantContext $tenants, array $params): array
    {
        if (!class_exists('ShopAPI')) {
            return [];
        }

        $filters = [];
        $category = trim((string) ($params['category'] ?? ''));
        if ($category !== '') {
            $filters['category_slug'] = $category;
        }
        if (!empty($params['featured_only'])) {
            $filters['featured'] = true;
        }
        $sort = (string) ($params['sort'] ?? 'newest');
        if (in_array($sort, ['newest', 'price-asc', 'price-desc', 'name', 'featured'], true)) {
            $filters['sort'] = $sort;
        }
        $limit = max(1, min(24, (int) ($params['limit'] ?? 12)));

        $currency = 'USD';
        if (class_exists('Database')) {
            $setting = \Database::setting('shop.currency');
            if (is_string($setting) && $setting !== '') {
                $currency = $setting;
            }
        }

        $out = [];
        foreach (\ShopAPI::publishedProducts($filters, $limit, 0) as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $effective = \ShopAPI::effectivePrice($row);
            $out[] = [
                'id'            => (int) $row['id'],
                'name'          => (string) ($row['name'] ?? ''),
                'slug'          => (string) ($row['slug'] ?? ''),
                'short_desc'    => (string) ($row['short_desc'] ?? ''),
                'price_cents'   => (int) round($effective * 100),
                'price_label'   => \ShopAPI::formatMoney($effective, $currency),
                'currency'      => $currency,
                'image_path'    => (string) ($row['image_url'] ?? ''),
                'featured'      => !empty($row['featured']),
                'variant_count' => (int) ($row['variant_count'] ?? 0),
                'in_stock'      => empty($row['manage_stock']) || (int) ($row['stock_qty'] ?? 0) > 0,
            ];
        }
        return $out;
    }
}
