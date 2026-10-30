<?php
/**
 * FightxLife CONTENT BUILD — NEW Presse page (/presse), DRAFT ONLY.
 * Source: Presse doc (verbatim labels + descriptions, URLs EXACTLY as
 * given). 18 links. Two notes: (1) the doc's "Icônes RS" line has no
 * URL and is not a press item, so it is not rendered (flagged in the
 * final report); (2) one link in the doc has no label (a bare Google
 * redirect to a YouTube video) — shown with the label "YouTube" taken
 * from its destination (flagged). The Hespress URL contains Arabic
 * characters and is passed verbatim; if validation rejects it, the
 * percent-encoded form is the fallback. All press links open in a new
 * tab (external). Page CSS = About page stylesheet; island JS is an
 * empty mount. Guard file refuses a second create (duplicate slugs).
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

$pink = '#e00080';
$guardFile = '/tmp/fr-new-presse.json';
if (file_exists($guardFile)) {
    echo "NEW-PRESSE-ALREADY-CREATED (guard file exists, refusing duplicate create)\n";
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
function xExt(string $label, string $href): array
{
    return xBlk('core.link', ['link' => ['label' => $label, 'href' => $href, 'target' => '_blank', 'rel' => 'noopener'], 'style' => 'plain'], []);
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

/* [label, href, description-or-null] — descriptions verbatim from the doc */
$items = [
    ['La Manchette', 'https://www.lamanchette.be/main/fightxlife-asbl-lance-bientot-son-action-journee-mondiale-contre-le-cancer-rendez-vous-le-4-et-5-fevrier/', 'Un journal qui explique qui est Fightxlife et du Crowdfunding 2021, le 31/01/21'],
    ['Soundcloud : Interview de Sara sur Fem Positive (BXFM 104.3 Bruxelles)', 'https://soundcloud.com/bxfmradio/positive-fem-sara-rezki-fightxlife', '(22/10/21)'],
    ['DH les sports', 'https://www.dhnet.be/lifestyle/people/sara-de-paduwa-on-a-tous-envie-de-mettre-le-cancer-k-o-60182ff97b50a652f773aa30', 'Article sur le fait que Sara de Paduwa est la Marraine de Fightxlife et description Fxl- le 01/02/21'],
    ['Rtbf.be', 'https://www.rtbf.be/article/une-jeune-bruxelloise-se-lance-un-defi-pour-lutter-contre-le-cancer-10688290', 'L’histoire de Sara et l’histoire de Juliette Berguet qui parle aussi de Sara : - le 15/10/21'],
    ['Rtbf.be — Juliette Berguet', 'https://www.rtbf.be/article/juliette-en-remission-dun-cancer-du-sein-aide-les-personnes-frappees-par-la-maladie-10860956', null],
    ['Jeanmotte Show', 'https://podcast.ausha.co/jeanmotte-show/emission-du-lundi-01-02-2021', 'le 01/02/21'],
    ['Jehanne Bergé', 'https://jehanneberge.com/2021/10/04/in-sara-rezki-we-trust-la-boxe-ma-sauve-la-vie/', 'Description – le 4 octobre 2021'],
    ['RTL info', 'https://www.rtl.be/info/vous/temoignages/-cogner-son-adversaire-tant-qu-on-peut-comment-la-boxe-l-a-sauvee-du-cancer-1277126.aspx', 'Berengere Debos (histoire de Fightxlife) – le 4/02/21'],
    ['Tendances première on Apple Podcasts : Ann Vandenplas parle de Sara et de Fightxlife', 'https://www.rtbf.be/auvio/detail_tendances-premiere-les-tendanceurs?id=2859925', null],
    ['John Late Show (28/01/22)', 'https://www.ln24.be/2022-01-28/john-late-show-avec-pierre-yves-jeholet-et-kody', null],
    ['BX1', 'https://bx1.be/communes/bruxelles-ville/de-la-boxe-pour-combattre-le-cancer/', null],
    ['Petite étincelles avec Sara Rezki', 'https://youtu.be/W0N25moJ8us', null],
    ['Rtbf.be', 'https://www.rtbf.be/article/de-la-boxe-adaptee-pour-les-malades-du-cancer-venir-sur-le-ring-c-est-aller-vers-la-vie-10437246', null],
    ['Nomad', 'https://fb.watch/aVgWbMtylg/', null],
    ['Arabel il va y avoir du sport', 'https://youtu.be/IGSdV_Y3s8g', null],
    ['Estelle Baldé', 'https://www.google.com/url?sa=t&rct=j&q=&esrc=s&source=video&cd=&ved=2ahUKEwjrr-6htcr3AhVR6RoKHZcnCC84PBC3AnoECAgQAg&url=https%3A%2F%2Fm.facebook.com%2Festellebalde%2Fvideos%2Fsara-est-esperanza-interview%2F252699939717847%2F&usg=AOvVaw0NiIQZAi97qLA8OEls-KrK', null],
    ['YouTube', 'https://www.google.com/url?sa=t&rct=j&q=&esrc=s&source=video&cd=&cad=rja&uact=8&ved=2ahUKEwiNxProtsr3AhVJyhoKHdMID0I4WhC3AnoECAQQAg&url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3DPHx3K_nNnms&usg=AOvVaw3zHhKUz9acpPN_VmmYrrc7', null],
    ['Hespress', 'https://www.hespress.com/سارة-رزقي-ابنة-طنجة-تهزم-السرطان-4-مرات-587839.html', null],
];

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
    xCont([xHdg('Presse', 'h1', 'Presse', $pink),
        xCont([xLnk('Accueil', '/yogist-home'), xTxt('›'), xTxt('Presse', ['crumb-current'])], ['breadcrumbs'], 'horizontal'),
    ], ['banner-inner', 'container']),
]);
$listKids = [xCont([xHdg('Presse', 'h2', 'Presse', $pink)], ['section-head', 'center'])];
foreach ($items as [$label, $href, $desc]) {
    $itemKids = [xExt($label, $href)];
    if ($desc !== null) {
        $itemKids[] = xTxt($desc);
    }
    $listKids[] = xCont($itemKids, ['fxl-presse-item']);
}
$list = xSec('presse-list', [xCont($listKids, ['content-section', 'container'])]);
$icons = xSec('page-icons', [xIsland($pageJsKey, $aboutCssKey, 'FightxLifePageIcons')]);
$doc = ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default',
    'settings' => CanonicalDocumentSchema::defaultSettings(),
    'seo' => CanonicalDocumentSchema::defaultSeo('Presse'),
    'sections' => [$banner, $list, $icons]];
if (isset($doc['seo']['title'])) {
    $doc['seo']['title'] = 'Presse';
}
// No description overwrite: no page-level description exists in the
// Presse doc, and inventing one would break the no-extra-content rule.

frValidate($doc, $frRuntime, 'presse');
$c = $frApp->createPage($frActor, 'Presse', 'presse', 'page', 'standalone');
$pid = (int) ($c['page']['id'] ?? 0);
if ($pid <= 0) {
    frFail('createPage failed for presse');
}
$status = $frApp->pageStatus($frActor, $pid);
$draft = $frApp->saveDraft($frActor, $pid, $doc, (int) $status['active_draft_revision_id'], 'manual');
$rev = (int) ($draft['revision']['id'] ?? 0);
file_put_contents($guardFile, json_encode(['title' => 'Presse', 'slug' => 'presse', 'page_id' => $pid, 'rev' => $rev], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "NEW-PRESSE page=$pid rev=$rev\nPRESSE-DONE\n";
