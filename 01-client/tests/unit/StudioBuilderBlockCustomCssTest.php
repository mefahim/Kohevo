<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — per-element custom CSS.
 *
 * Autoloader only, no database. A block's `custom_css` is author CSS scoped
 * to that block instance: validated as a bounded string, sanitized on
 * normalize through StudioCodePolicy, and rendered with every selector
 * prefixed by the block's scope class.
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
    require_once __DIR__ . '/harness.php';
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\ScopedAuthorCss;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Tenancy\TenantContext;

function sbcss_block(?string $css): array
{
    $block = [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1,
        'props' => ['text' => 'Hello', 'level' => 'h2'], 'style' => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
    ];
    if ($css !== null) {
        $block['custom_css'] = $css;
    }
    return $block;
}

function sbcss_doc(?string $css): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default');
    $doc = DocumentOperationApplier::applyOne(
        $doc,
        new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Main']]),
        BlockRegistry::withAllCoreBlocks()
    );
    $doc['sections'][0]['blocks'][] = sbcss_block($css);
    return $doc;
}

/** @return list<string> */
function sbcss_codes(?string $css): array
{
    try {
        DocumentNormalizer::validateAndNormalize(sbcss_doc($css), BlockRegistry::withAllCoreBlocks());
    } catch (StudioValidationException $e) {
        return array_column($e->errors(), 'code');
    }
    return [];
}

unit('block custom css: bounded string validates, oversize and non-string refused', function (): void {
    assert_eq([], sbcss_codes('.a{color:red}'));
    assert_eq([], sbcss_codes(null), 'absent key is fine');
    assert_true(in_array('custom_css_too_large', sbcss_codes(str_repeat('a', CanonicalDocumentSchema::CUSTOM_CSS_MAX_BYTES + 1)), true), 'over the ceiling refused');
});

unit('block custom css: normalizer sanitizes and drops empties', function (): void {
    $reg = BlockRegistry::withAllCoreBlocks();
    $out = DocumentNormalizer::validateAndNormalize(sbcss_doc('.a{color:red} @import url(evil);'), $reg);
    $css = $out['sections'][0]['blocks'][0]['custom_css'] ?? '';
    assert_true(str_contains($css, '.a{color:red}'), 'clean rule kept');
    assert_true(!str_contains($css, '@import'), '@import stripped on store');
    $empty = DocumentNormalizer::validateAndNormalize(sbcss_doc('   '), $reg);
    assert_true(!array_key_exists('custom_css', $empty['sections'][0]['blocks'][0]), 'blank css drops the key');
});

unit('block custom css: scoped transform prefixes selectors to the block', function (): void {
    $scope = 'sb-u-blk_0123456789abcdef';
    assert_eq('.' . $scope . ' .title{color:red}', ScopedAuthorCss::scope('.title{color:red}', $scope));
    assert_eq('.' . $scope . '{border:0}', ScopedAuthorCss::scope('&{border:0}', $scope));
    assert_eq('.' . $scope . ':hover{color:blue}', ScopedAuthorCss::scope('&:hover{color:blue}', $scope));
    assert_eq('.' . $scope . ':hover{color:blue}', ScopedAuthorCss::scope(':hover{color:blue}', $scope));
    assert_eq('.' . $scope . ' .a,.' . $scope . ' .b{x:1}', ScopedAuthorCss::scope('.a,.b{x:1}', $scope));
    assert_eq('.' . $scope . ':not(.a, .b){x:1}', ScopedAuthorCss::scope(':not(.a, .b){x:1}', $scope));
    assert_eq('.' . $scope . ' .a :not(.b){x:1}', ScopedAuthorCss::scope('.a :not(.b){x:1}', $scope));
});

unit('block custom css: scoped transform recurses media and leaves keyframes alone', function (): void {
    $scope = 'sb-u-blk_0123456789abcdef';
    assert_eq('@media (max-width:600px){.' . $scope . ' .a{x:1}}', ScopedAuthorCss::scope('@media (max-width:600px){.a{x:1}}', $scope));
    assert_eq('@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}', ScopedAuthorCss::scope('@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}', $scope));
});

unit('block custom css: renderer adds the scope class and emits the scoped rule', function (): void {
    $tenants  = new TenantContext();
    $renderer = new DocumentRenderer(
        BlockRegistry::withAllCoreBlocks(),
        BlockRendererRegistry::withCoreRenderers(),
        new CoreMediaResolver($tenants),
        new ProviderBindingResolver(new DataProviderRegistry(), $tenants)
    );
    $block     = sbcss_block('.sb-heading{letter-spacing:1px}');
    $collector = new RenderCollector();
    $context   = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test'));
    $html      = $renderer->renderBlock($block, $context, new ResolvedTheme('default', []), $collector, false);
    assert_true(str_contains($html, 'sb-u-' . $block['id']), 'wrapper carries the scope class');
    assert_true(str_contains($collector->css(), '.sb-u-' . $block['id'] . ' .sb-heading{letter-spacing:1px}'), 'collector carries the scoped rule, got: ' . $collector->css());
});

unit('block custom css: update op stores and clears the key', function (): void {
    $reg = BlockRegistry::withAllCoreBlocks();
    $doc = sbcss_doc(null);
    $id  = $doc['sections'][0]['blocks'][0]['id'];
    $doc = DocumentOperationApplier::applyOne($doc, DocumentOperation::updateBlockCustomCss($id, '.a{color:red}'), $reg);
    assert_eq('.a{color:red}', $doc['sections'][0]['blocks'][0]['custom_css'] ?? null);
    DocumentNormalizer::validateAndNormalize($doc, $reg);
    $doc = DocumentOperationApplier::applyOne($doc, DocumentOperation::updateBlockCustomCss($id, null), $reg);
    assert_true(!array_key_exists('custom_css', $doc['sections'][0]['blocks'][0]), 'null clears the key');
});
