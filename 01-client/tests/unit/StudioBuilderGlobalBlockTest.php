<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — block-level global
 * components (Batch D2): a `core.global` block references a Global
 * Component by uuid; the resolver collects block refs alongside section
 * refs, the validator gates them like section refs, and the renderer
 * splices the component's blocks into the block's own wrapper.
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

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Component\GlobalComponentResolver;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderDocumentPreparer;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Tenancy\TenantContext;

const SBGL_REF = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

final class _SbglNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia { return null; }
}

function sbgl_block(string $type, array $props = []): array
{
    return [
        'bindings'   => [],
        'children'   => [],
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'props'      => $props,
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'type'       => $type,
        'version'    => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ];
}

function sbgl_doc(array $blocks, string $type = 'page'): array
{
    $doc = CanonicalDocumentSchema::emptyDocument($type, 'default', 'Doc');
    $doc['sections'] = [[
        'blocks'     => $blocks,
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];

    return $doc;
}

function sbgl_render(array $doc, array $components, bool $public = true): string
{
    $registry = ModuleBlockDefinitions::studioRegistry();
    $renderer = new DocumentRenderer($registry, BlockRendererRegistry::withStudioRenderers(), new _SbglNoMedia(), new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext()));
    $prepared = RenderDocumentPreparer::prepare($doc, $registry)['document'];
    $preparedComponents = [];
    foreach ($components as $ref => $componentDoc) {
        $preparedComponents[$ref] = RenderDocumentPreparer::prepare($componentDoc, $registry)['document']['sections'];
    }
    $site = new SiteContext('https://acme.test', 'Acme', '', '', 'en');
    $ctx = $public
        ? RenderContext::forPublic(101, $site, static fn(string $m): bool => true)
        : RenderContext::forEditor(101, $site, \Slate\Module\StudioBuilder\Application\StudioActor::authenticated(3, [\Slate\Module\StudioBuilder\StudioPermissions::VIEW, \Slate\Module\StudioBuilder\StudioPermissions::EDIT]), static fn(string $m): bool => true);

    return $renderer->renderSections($prepared['sections'], $ctx, new ResolvedTheme('default', ThemeResolver::DEFAULT_TOKENS), new RenderCollector(), true, $preparedComponents);
}

unit('global block: the resolver collects block-level refs, including nested ones', function (): void {
    $doc = sbgl_doc([
        sbgl_block('core.global', ['ref' => SBGL_REF]),
        sbgl_block('layout.columns', [], ),
    ]);
    $doc['sections'][0]['blocks'][1]['children'] = [sbgl_block('core.global', ['ref' => 'cccccccc-dddd-4eee-8fff-000000000000'])];
    $refs = GlobalComponentResolver::referencesIn($doc);
    assert_true(in_array(SBGL_REF, $refs, true), 'top-level block ref collected');
    assert_true(in_array('cccccccc-dddd-4eee-8fff-000000000000', $refs, true), 'nested block ref collected');
});

unit('global block: the validator gates the reference like a section reference', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $exists = ['partial_exists' => static fn(string $ref): bool => $ref === SBGL_REF];
    $codes = static fn(array $doc, array $options = []): array => array_column(DocumentValidator::validate($doc, $registry, $options)->errors(), 'code');

    assert_eq([], $codes(sbgl_doc([sbgl_block('core.global', ['ref' => SBGL_REF])]), $exists), 'an existing component uuid validates');
    assert_true(in_array('invalid_global_ref', $codes(sbgl_doc([sbgl_block('core.global', ['ref' => 'not-a-uuid'])])), true), 'a malformed ref is refused');
    assert_true(in_array('cross_tenant_or_missing_partial', $codes(sbgl_doc([sbgl_block('core.global', ['ref' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff'])]), $exists), true), 'a missing component is refused');
    assert_true(in_array('global_ref_not_allowed', $codes(sbgl_doc([sbgl_block('core.global', ['ref' => SBGL_REF])], 'section_preset'), $exists), true), 'a component may not contain a global block (one level deep)');
});

unit('global block: the renderer splices the component blocks into the block wrapper', function (): void {
    $componentDoc = sbgl_doc([sbgl_block('core.heading', ['text' => 'Shared heading', 'level' => 'h2'])], 'section_preset');
    $html = sbgl_render(sbgl_doc([sbgl_block('core.global', ['ref' => SBGL_REF])]), [SBGL_REF => $componentDoc]);
    assert_true(str_contains($html, 'sb-block--core-global'), 'the block keeps its own wrapper');
    assert_true(str_contains($html, 'Shared heading'), 'the component content renders inside it');
    assert_false(str_contains($html, 'sb-section--global'), 'no section wrapper is carried over');
});

unit('global block: a missing component is inert in public and labelled in the editor', function (): void {
    $public = sbgl_render(sbgl_doc([sbgl_block('core.global', ['ref' => SBGL_REF])]), []);
    assert_false(str_contains($public, 'component_unavailable'), 'public output stays silent');
    $editor = sbgl_render(sbgl_doc([sbgl_block('core.global', ['ref' => SBGL_REF])]), [], false);
    assert_true(str_contains($editor, 'component_unavailable'), 'the editor shows the unavailable notice');
});
