<?php
/**
 * PageForge — plugin bootstrap.
 *
 * Registers:
 *   - Admin nav item (page list + builder), gated by the `pageforge`
 *     commercial entitlement and the pageforge.view permission.
 *   - Public route prefix `/forge` → public/router.php, which serves only
 *     published, compiled pages of the current tenant.
 *
 * The engine (document model, renderer, CSS compiler, store) is fully
 * self-contained in lib/ — this plugin does not depend on Kohevo Studio,
 * and Studio does not depend on it. Original code for Kohevo.
 */

require_once __DIR__ . '/lib/PageForgeStore.php';

class PageForge extends Plugin
{
    public const SLUG = 'pageforge';
    public const ENTITLEMENT = 'pageforge';

    public function boot(): void
    {
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('public_routes', [$this, 'addPublicRoutes']);
        Hook::addFilter('i18n_lang_paths', [$this, 'addLangPath']);
    }

    public function addAdminNav(array $items): array
    {
        if (!ModuleGuard::allows(self::ENTITLEMENT)) {
            return $items;
        }
        if (!Auth::can('pageforge.view') && !Auth::isSuperAdmin()) {
            return $items;
        }
        $items[] = [
            'slug' => 'pageforge',
            'label' => __('pageforge', 'PageForge'),
            'href' => $this->url('admin/index.php'),
            'icon' => 'layout',
        ];
        $items[] = [
            'slug' => 'pageforge-site-settings',
            'label' => __('pageforge_site_settings', 'PageForge Site Settings'),
            'href' => $this->url('admin/site-settings.php'),
            'icon' => 'sliders',
        ];
        return $items;
    }

    public function addPublicRoutes(array $routes): array
    {
        $routes['forge'] = [
            'handler' => $this->dir('public/router.php'),
            'methods' => ['GET'],
        ];
        return $routes;
    }

    public function addLangPath(array $paths): array
    {
        $paths[] = $this->dir('lang');
        return $paths;
    }
}
