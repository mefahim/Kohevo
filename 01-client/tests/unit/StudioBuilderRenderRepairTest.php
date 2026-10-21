<?php
/**
 * Render-path repair: a stored prop that no longer matches its field pattern
 * (e.g. a stale highlight colour imported or written under an older schema)
 * must cost the prop, never the whole block. Structural damage still degrades
 * to the inert unavailable marker.
 */
declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\RenderDocumentPreparer;

function sbrr_block(string $type, array $props = []): array
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

function sbrr_doc(array $blocks): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Doc');
    $doc['sections'] = [[
        'blocks'     => $blocks,
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 's',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

function sbrr_prepare(array $blocks): array
{
    return RenderDocumentPreparer::prepare(sbrr_doc($blocks), ModuleBlockDefinitions::studioRegistry());
}

unit('render repair: invalid highlight_color costs the prop, not the heading block', function (): void {
    $out = sbrr_prepare([sbrr_block('core.heading', ['text' => 'Hello', 'level' => 'h2', 'highlight_color' => 'not a colour'])]);
    assert_true($out['degraded'], 'document should be marked degraded');
    $block = $out['document']['sections'][0]['blocks'][0];
    assert_true(($block['type'] ?? '') === 'core.heading', 'heading block must survive, got: ' . json_encode($block));
    assert_true(($block['props']['text'] ?? '') === 'Hello', 'heading text must be preserved');
    assert_true(($block['props']['highlight_color'] ?? null) === '', 'invalid colour must fall back to the field default');
});

unit('render repair: hero with invalid highlight_color survives', function (): void {
    $out = sbrr_prepare([sbrr_block('core.hero', ['heading' => 'Welcome here', 'highlight_color' => 'blue-ish??'])]);
    $block = $out['document']['sections'][0]['blocks'][0];
    assert_true(($block['type'] ?? '') === 'core.hero', 'hero block must survive, got: ' . json_encode($block));
    assert_true(($block['props']['heading'] ?? '') === 'Welcome here', 'hero heading must be preserved');
});

unit('render repair: pattern mismatch plus structural damage still degrades the block', function (): void {
    $bad = sbrr_block('core.heading', ['text' => 'Hello', 'highlight_color' => 'not a colour']);
    $bad['version'] = 999; // unsupported block version: not a repairable failure
    $out = sbrr_prepare([$bad]);
    $block = $out['document']['sections'][0]['blocks'][0];
    assert_true(isset($block[RenderDocumentPreparer::UNAVAILABLE_KEY]), 'structurally invalid block must stay unavailable');
});

unit('render repair: valid document is untouched and not degraded', function (): void {
    $out = sbrr_prepare([sbrr_block('core.heading', ['text' => 'Hello', 'level' => 'h2', 'highlight_color' => '#ff0000'])]);
    assert_false($out['degraded'], 'valid document must not be degraded');
    $block = $out['document']['sections'][0]['blocks'][0];
    assert_true(($block['props']['highlight_color'] ?? '') === '#ff0000', 'valid colour must be preserved');
});

unit('render repair: unknown block type still degrades to unavailable', function (): void {
    $out = sbrr_prepare([sbrr_block('core.nope_missing', ['text' => 'x'])]);
    $block = $out['document']['sections'][0]['blocks'][0];
    assert_true(isset($block[RenderDocumentPreparer::UNAVAILABLE_KEY]), 'unknown type must stay unavailable');
});
