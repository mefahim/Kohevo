<?php
/**
 * Kohevo Studio — Portfolio Widget Extension.
 *
 * Registers custom StudioBuilder widget for the complete portfolio experience.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder;

use Slate\Kernel\Event\Hook;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Sdk\WidgetSdk;

final class StudioBuilderPortfolioWidgets
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        if (class_exists(Hook::class)) {
            Hook::addFilter('studio_register_widgets', [self::class, 'onRegisterWidgets']);
        }
    }

    public static function onRegisterWidgets(WidgetSdk $sdk): void
    {
        if (!$sdk->has('portfolio.rakib_showcase')) {
            $sdk->register([
                'type'        => 'portfolio.rakib_showcase',
                'version'     => 1,
                'label'       => 'Portfolio Showcase',
                'category'    => 'portfolio',
                'icon'        => 'layout',
                'schema'      => [
                    ['key' => 'title', 'type' => 'string', 'label' => 'Portfolio Title', 'required' => false, 'default' => ''],
                    ['key' => 'brand_text', 'type' => 'string', 'label' => 'Brand Header', 'required' => false, 'default' => 'Portfolio / 2026'],
                    ['key' => 'first_name', 'type' => 'string', 'label' => 'First Name', 'required' => false, 'default' => ''],
                    ['key' => 'last_name', 'type' => 'string', 'label' => 'Last Name', 'required' => false, 'default' => ''],
                    ['key' => 'role', 'type' => 'text', 'label' => 'Role / Headline', 'required' => false, 'default' => ''],
                    ['key' => 'vertical_text', 'type' => 'string', 'label' => 'Side Accent Text', 'required' => false, 'default' => 'Digital design • Development'],
                    ['key' => 'location', 'type' => 'text', 'label' => 'Location', 'required' => false, 'default' => ''],
                    ['key' => 'coordinates', 'type' => 'text', 'label' => 'Coordinates', 'required' => false, 'default' => ''],
                    ['key' => 'domain', 'type' => 'string', 'label' => 'Domain Name', 'required' => false, 'default' => ''],
                    ['key' => 'domain_url', 'type' => 'string', 'label' => 'Domain URL', 'required' => false, 'default' => ''],
                    ['key' => 'email', 'type' => 'string', 'label' => 'Contact Email', 'required' => false, 'default' => ''],
                ],
                'renderer'    => [self::class, 'renderShowcase'],
            ]);
        }
    }

    public static function renderShowcase(BlockRenderScope $scope): string
    {
        $bodyFile = __DIR__ . '/assets/portfolio_body.html';

        if (!file_exists($bodyFile)) {
            return '<div class="portfolio-error">Portfolio template asset missing.</div>';
        }

        $html = (string) file_get_contents($bodyFile);

        // Copy portrait photo src to #face for scriptless editor canvas
        if (preg_match('/<img class="a photo"[^>]*src="([^"]+)"/i', $html, $matches)) {
            $photoSrc = $matches[1];
            $html = str_replace('<img id="face"', '<img id="face" src="' . $photoSrc . '"', $html);
        }

        // Interpolate editable props. The template carries {{tokens}} for its identity
        // fields, so a markup edit in the asset can never silently break substitution
        // the way replacing whole rendered elements could. The schema's title is the
        // showcase's title line: when set it takes the brand header's place.
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $title = $scope->string('title', '');
        $html = strtr($html, [
            '{{brand_text}}'    => $esc($title !== '' ? $title : $scope->string('brand_text', 'Portfolio / 2026')),
            '{{first_name}}'    => $esc($scope->string('first_name', '')),
            '{{last_name}}'     => $esc($scope->string('last_name', '')),
            '{{role}}'          => nl2br($esc($scope->string('role', ''))),
            '{{vertical_text}}' => $esc($scope->string('vertical_text', 'Digital design • Development')),
            '{{location}}'      => nl2br($esc($scope->string('location', ''))),
            '{{coordinates}}'   => nl2br($esc($scope->string('coordinates', ''))),
            '{{domain}}'        => $esc($scope->string('domain', '')),
            '{{domain_url}}'    => $esc($scope->string('domain_url', '')),
            '{{email}}'         => $esc($scope->string('email', '')),
        ]);
        // A token the renderer does not know (asset edited ahead of the code) renders as nothing.
        $html = (string) preg_replace('/\{\{[a-z_]+\}\}/', '', $html);

        return self::stylesheetTag() . $html;
    }

    /**
     * `<link>` to the static portfolio stylesheet (self-hosted Outfit fonts
     * resolve relative to it). Needed because the builder canvas renders in
     * Editor mode, where tenant CSS is intentionally not emitted.
     */
    public static function stylesheetTag(): string
    {
        $file = __DIR__ . '/assets/portfolio/portfolio.css';
        if (!is_file($file)) {
            return '';
        }
        $url = '';
        try {
            if (\function_exists('plugin_url')) {
                $url = (string) \plugin_url('studio-builder', 'assets/portfolio/portfolio.css');
            }
        } catch (\Throwable $ignored) {
        }
        if ($url === '') {
            $base = defined('SLATE_URL') ? (string) SLATE_URL : '';
            $url = ($base !== '' ? rtrim($base, '/') : '') . '/plugins/studio-builder/assets/portfolio/portfolio.css';
        }
        $v = substr((string) @hash_file('sha256', $file), 0, 12);
        return '<link rel="stylesheet" href="' . htmlspecialchars($url . '?v=' . $v, ENT_QUOTES) . '">';
    }
}
