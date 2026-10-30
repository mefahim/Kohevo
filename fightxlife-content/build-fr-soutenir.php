<?php
/**
 * FightxLife CONTENT BUILD — NEW Nous Soutenir page (/nous-soutenir), DRAFT ONLY.
 * Source: Nous Soutenir doc (verbatim intro + 4 CTA labels). No donation,
 * volunteer, event, or partner URL exists in any doc, so all 4 CTAs link
 * to the Contact page (interpretation stated to Fahim 2026-10-28, not
 * corrected). Page CSS = About page stylesheet (banner / section-head /
 * button styles, already pink-mapped by the rebrand); island JS is an
 * empty mount (no DOM touches, cannot throw). Guard file refuses a
 * second create (duplicate slugs).
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

$pink = '#e00080';
$guardFile = '/tmp/fr-new-soutenir.json';
if (file_exists($guardFile)) {
    echo "NEW-SOUTENIR-ALREADY-CREATED (guard file exists, refusing duplicate create)\n";
    echo file_get_contents($guardFile) . "\n";
    exit(0);
}

function xBlk(string $type, array $props, array $children = [], array $classes = []): array
{
    $b = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => [], 'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings' => [], 'children' => $children];
    if ($classes !== []) {
        $b['classNames'] = $classes;
    }
    return $b;
}
function xCont(array $children, array $classes, string $dir = 'vertical'): array
{
    return xBlk('core.container', ['direction' => $dir, 'gap' => 'none'], $children, $classes);
}
function xTxt(string $content, array $classes = []): array
{
    return xBlk('core.text', ['content' => $content, 'size' => 'base'], [], $classes);
}
function xHdg(string $text, string $level, ?string $highlight, string $pink): array
{
    $props = ['text' => $text, 'level' => $level];
    if ($highlight !== null) {
        $props['highlight'] = $highlight;
        $props['highlight_color'] = $pink;
    }
    return xBlk('core.heading', $props, []);
}
function xLnk(string $label, string $href): array
{
    return xBlk('core.link', ['link' => ['label' => $label, 'href' => $href, 'target' => '_self'], 'style' => 'plain'], []);
}
function xBtn(string $label, string $href): array
{
    return xBlk('core.link', ['link' => ['label' => $label, 'href' => $href, 'target' => '_self'], 'style' => 'plain'], [], ['button', 'button-primary']);
}
function xIsland(string $assetKey, string $cssKey, string $entry): array
{
    return xBlk('core.island', ['asset_key' => $assetKey, 'css_key' => $cssKey, 'entry' => $entry,
        'shell' => '<span></span>', 'props_json' => '{}', 'loading' => 'eager'], []);
}
function xSec(string $label, array $blocks): array
{
    return ['id' => CanonicalDocumentSchema::newSectionId(), 'label' => $label, 'global_ref' => null,
        'layout' => ['columns' => ['base' => 1], 'gap' => 'none', 'padding_y' => ['base' => 'none', 'md' => 'none'], 'width' => 'wide', 'background_token' => 'surface.primary'],
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']], 'blocks' => $blocks];
}

$intro1 = <<<'TXT'
Soutenez fightxlife dans sa lutte contre le cancer à travers nos actions en faisant un don, en organisant une action, en faisant un partenariat ou encore en devenant bénévole.
TXT;
$intro2 = <<<'TXT'
Quel que soit la façon avec laquelle vous nous aiderez, Fightxlife vous remercie.
TXT;

$aboutDoc = frLoadDoc($frApp, $frActor, 41);
$aboutCssKey = null;
$walkIsl = function ($bs) use (&$walkIsl, &$aboutCssKey) {
    foreach ($bs as $b) {
        if (($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistAboutIcons') {
            $aboutCssKey = $b['props']['css_key'] ?? null;
        }
        if (!empty($b['children'])) {
            $walkIsl($b['children']);
        }
    }
};
foreach ($aboutDoc['sections'] as $s) {
    $walkIsl($s['blocks'] ?? []);
}
if ($aboutCssKey === null) {
    frFail('about css key not found in p41');
}
$pageJs = <<<'JS'
window.FightxLifePageIcons = { mount: function (el) {} };
JS;
$pageJsKey = frStoreAsset($pageJs, 'js');
echo "PAGE-JS $pageJsKey\nABOUT-CSS $aboutCssKey\n";

$banner = xSec('page-banner', [
    xCont([xHdg('Nous Soutenir', 'h1', 'Soutenir', $pink),
        xCont([xLnk('Accueil', '/yogist-home'), xTxt('›'), xTxt('Nous Soutenir', ['crumb-current'])], ['breadcrumbs'], 'horizontal'),
    ], ['banner-inner', 'container']),
]);
$intro = xSec('soutenir-intro', [
    xCont([xCont([xHdg('Nous Soutenir', 'h2', 'Soutenir', $pink)], ['section-head', 'center']),
        xTxt($intro1), xTxt($intro2)], ['content-section', 'container']),
]);
$ctas = xSec('soutenir-ctas', [
    xCont([xBtn('Je fais un don', '/contact-us'),
        xBtn('Je deviens bénévole', '/contact-us'),
        xBtn('J’organise un événement', '/contact-us'),
        xBtn('Je souhaite être partenaire', '/contact-us')], ['center-action']),
]);
$icons = xSec('page-icons', [xIsland($pageJsKey, $aboutCssKey, 'FightxLifePageIcons')]);
$doc = ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default',
    'settings' => CanonicalDocumentSchema::defaultSettings(),
    'seo' => CanonicalDocumentSchema::defaultSeo('Nous Soutenir'),
    'sections' => [$banner, $intro, $ctas, $icons]];
if (isset($doc['seo']['title'])) {
    $doc['seo']['title'] = 'Nous Soutenir';
}
if (isset($doc['seo']['description'])) {
    $doc['seo']['description'] = $intro1;
}

frValidate($doc, $frRuntime, 'soutenir');
$c = $frApp->createPage($frActor, 'Nous Soutenir', 'nous-soutenir', 'page', 'standalone');
$pid = (int) ($c['page']['id'] ?? 0);
if ($pid <= 0) {
    frFail('createPage failed for nous-soutenir');
}
$status = $frApp->pageStatus($frActor, $pid);
$draft = $frApp->saveDraft($frActor, $pid, $doc, (int) $status['active_draft_revision_id'], 'manual');
$rev = (int) ($draft['revision']['id'] ?? 0);
file_put_contents($guardFile, json_encode(['title' => 'Nous Soutenir', 'slug' => 'nous-soutenir', 'page_id' => $pid, 'rev' => $rev], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "NEW-SOUTENIR page=$pid rev=$rev\nSOUTENIR-DONE\n";
