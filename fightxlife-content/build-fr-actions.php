<?php
/**
 * FightxLife CONTENT BUILD — NEW Actions page (/actions), DRAFT ONLY.
 * Source: Nos Projets doc (verbatim, 4 projects). SmileBox logo shown
 * as the section image; Visite / Gala / Crowdfunding each get their 3
 * photos in a gallery grid. The 3 Solidarité photos have NO matching
 * project text in the doc, so they are NOT placed (flagged in the final
 * report). The doc heading typo "eProjets" is not reproduced — the page
 * title is "Nos Actions" (nav + doc name). Page CSS comes from the About
 * page's stylesheet (content-section / section-head / gallery-grid);
 * the island JS is an empty mount (no DOM touches, cannot throw).
 * Guard file refuses a second create (duplicate slugs).
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

$pink = '#e00080';
$guardFile = '/tmp/fr-new-actions.json';
if (file_exists($guardFile)) {
    echo "NEW-ACTIONS-ALREADY-CREATED (guard file exists, refusing duplicate create)\n";
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
function xImg(int $mediaId, string $alt): array
{
    return xBlk('core.image', ['media' => ['media_id' => $mediaId, 'alt' => $alt], 'rounded' => false]);
}
function xLnk(string $label, string $href): array
{
    return xBlk('core.link', ['link' => ['label' => $label, 'href' => $href, 'target' => '_self'], 'style' => 'plain'], []);
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

/* ---------- Project texts (Nos Projets doc, verbatim) ---------- */
$sm1 = <<<'TXT'
La SmileBox un soutien contre la lutte contre le cancer.
TXT;
$sm2 = <<<'TXT'
La fondatrice Rezki Sara a sillonné les hôpitaux dès son jeune âge, par son expérience elle connaît cette sensation de se retrouver dans un lit d'hôpital face à cette épreuve difficile. Celle-ci impacte l’enfant et les parents qui doivent mettre leur vie professionnelle en pause.
TXT;
$sm3 = <<<'TXT'
La Belgique compte plus de 10% de ménages monoparentaux et ces familles se retrouvent donc à faire des choix entre des soins dits importants ou non-impératifs lors de la chimiothérapie ou autres.
TXT;
$sm4 = <<<'TXT'
Vivre des années au sein d’un hôpital rend plus difficile la socialisation et en devient un gouffre financier pour les parents. La plupart du temps, les familles des patients doivent faire face aux nouvelles et aux traitements de leurs proches qui sont abattues et dépassées par le coût des produits de soins que cela engendre.
TXT;
$sm5 = <<<'TXT'
Ainsi l’association a créé la SmileBox, une box offerte gratuitement à tous les enfants diagnostiqué d’un cancer. Le but étant d’alléger sur le plan financier les familles des enfants touchés par le cancer.
TXT;
$sm6 = <<<'TXT'
Avec notre partenaire LA ROCHE POSAY, nous offrons dans cette box les soins spécifiques et adaptés aux besoins de la peau. La chimiothérapie fragilise la peau en engendrant des effets indésirables sur la peau tels que la sécheresse cutanée, les rougeurs , les réactions allergiques… Il est donc primordial pour les enfants de prendre soin de leur peau fragilisée et sensibilisée à l’aide de soins adaptés .
TXT;
$sm7 = <<<'TXT'
La communication est importante durant cette période c’est la raison pour laquelle une peluche émotionnelle pédagogique sera également fournie et permettra aux enfants d’exprimer plus facilement leurs émotions. De cette façon, l’enfant sera encouragé, motivé mais surtout cela leur rendra un petit sourire pendant ces moments difficiles.
TXT;
$sm8 = <<<'TXT'
Notre sponsor Asiasport nous accompagne dans la lutte contre le cancer des enfants et adolescents en offrant un T-shirt digne d’un boxeur. Nous compléterons cette panoplie de super Fighter par une casquette SMILEBOX
TXT;
$sm9 = <<<'TXT'
Fightxlife se doit d’être active dans la société en contribuant à une meilleure qualité de vie auprès des personnes atteintes du cancer. Dans ce projet, l’association se veut être au côté des enfants et des parents pendant ces moments difficiles.
TXT;
$vi1 = <<<'TXT'
La plupart des enfants restent seules accompagnés de leur parents dans leur chambre et ne peuvent établir de liens avec les autres enfants de leurs âge ce qui souvent crée un isolement social c’est pourquoi, tout au long de l’année Fightxlife met en place des actions au sein des hôpitaux en rendant visite aux enfants des services oncologie et pédiatrie durant les fêtes (Saint-Nicolas, Noël, Carnaval, Pâques…) en leur offrant des activités, des cadeaux, etc pour leur apporter un rayon de soleil dans la grisaille de leur quotidien face à la maladie.
TXT;
$vi2 = <<<'TXT'
De ce fait, l’équipe crée un contact avec les parents et les enfants, et permet un moment d’échange, de partage et d’espoir. Fightxlife décharge les parents de certains frais en offrant également tout le nécessaire scolaire pour la rentrée de ces petits héros.
TXT;
$ga1 = <<<'TXT'
Fightxlife organise chaque année un Gala de charité, en invitant différentes célébrités pour nous soutenir dans la lutte contre le cancer.
TXT;
$ga2 = <<<'TXT'
La soirée de GALA est un moment de convivialité, de partage et d’échanges qui s’inscrit dans l’objectif de notre association : INFORMER ET SENSIBILISER LE PUBLIC FACE AU CANCER.
TXT;
$ga3 = <<<'TXT'
Le GALA de charité est un moment unique au cours duquel nos ambassadrices et ambassadeurs et toutes les personnes qui se sentent concernées par notre combat se retrouvent. Se voient réunis ceux qui ont choisi de donner de leur temps, de leur présence pour nous aider mais également nos collaborateurs,nos partenaires ainsi que nos sponsors que nous profitons de remercier comme il se doit en les mettant en avant.
TXT;
$ga4 = <<<'TXT'
Cette soirée est également un moment fort pour notre association car elle permet de partager des témoignages, de faire connaître notre association, à montrer toutes nos actions écoulés durant l’année et à recueillir des dons. Les fonds obtenus servent à financer nos actions, nos projets que nous avons programmées dans les semaines et mois à venir.
TXT;
$cf1 = <<<'TXT'
Annuellement, l’ASBL organise un crowdfunding pour promouvoir ses activités et partager ses messages pendant 36H lors de la journée mondiale du cancer. Lors de cet événement, l’association invite divers intervenants à témoigner de leur histoire mais aussi à partager les différentes associations contre le cancer. De ce fait, des artistes locaux sont généralement conviés et offrent un spectacle dans une ambiance chaleureuse. Le crowdfunding est également l’occasion pour l’ASBL de récolter des fonds en faisant appel aux dons. En cette période, l’association partage une quantité importante de contenu relative à l’événement et sensibilise les personnes face à la maladie.
TXT;
$smDesc = 'Ainsi l’association a créé la SmileBox, une box offerte gratuitement à tous les enfants diagnostiqué d’un cancer.';

/* ---------- load manifest + About CSS key ---------- */
[$byOrig, $bySlug] = frManifest();
$med = function (string $orig) use ($byOrig): int {
    if (!isset($byOrig[$orig])) {
        frFail("actions media missing: $orig");
    }
    return (int) $byOrig[$orig][0];
};
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

/* ---------- build doc ---------- */
$projectSec = function (string $title, ?string $hl, array $photos, array $paras) use ($pink): array {
    $kids = [xCont([xHdg($title, 'h2', $hl, $pink)], ['section-head', 'center'])];
    if ($photos !== []) {
        $kids[] = xCont($photos, ['gallery-grid']);
    }
    foreach ($paras as $p) {
        $kids[] = xTxt($p);
    }
    return xSec('project-' . $title, [xCont($kids, ['content-section', 'container'])]);
};
$banner = xSec('page-banner', [
    xCont([xHdg('Nos Actions', 'h1', 'Actions', $pink),
        xCont([xLnk('Accueil', '/yogist-home'), xTxt('›'), xTxt('Nos Actions', ['crumb-current'])], ['breadcrumbs'], 'horizontal'),
    ], ['banner-inner', 'container']),
]);
$sections = [$banner];
$sections[] = $projectSec('SmileBox', 'SmileBox',
    [xImg($med('Logo_smilebox-removebg-preview.png'), 'Logo SmileBox')],
    [$sm1, $sm2, $sm3, $sm4, $sm5, $sm6, $sm7, $sm8, $sm9]);
$sections[] = $projectSec('Visite hôpitaux', 'hôpitaux',
    [xImg($med('Visite hopitaux 1.jpg'), 'Visite aux enfants hospitalisés'),
     xImg($med('Visite hopitaux 2.jpg'), 'Visite aux enfants hospitalisés'),
     xImg($med('Visite hopitaux 3.jpg'), 'Visite aux enfants hospitalisés')],
    [$vi1, $vi2]);
$sections[] = $projectSec('Gala', 'Gala',
    [xImg($med('gala.jpg'), 'Soirée de Gala de charité'),
     xImg($med('gala sara.jpg'), 'Soirée de Gala de charité'),
     xImg($med('galaaa.jpeg'), 'Soirée de Gala de charité')],
    [$ga1, $ga2, $ga3, $ga4]);
$sections[] = $projectSec('Crowdfunding', 'Crowdfunding',
    [xImg($med('crowdfun.JPG'), 'Événement crowdfunding'),
     xImg($med('Crowdfunding 2022.jpg'), 'Événement crowdfunding'),
     xImg($med('crowd.jpg'), 'Événement crowdfunding')],
    [$cf1]);
$sections[] = xSec('page-icons', [xIsland($pageJsKey, $aboutCssKey, 'FightxLifePageIcons')]);
$doc = ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default',
    'settings' => CanonicalDocumentSchema::defaultSettings(),
    'seo' => CanonicalDocumentSchema::defaultSeo('Nos Actions'),
    'sections' => $sections];
if (isset($doc['seo']['title'])) {
    $doc['seo']['title'] = 'Nos Actions';
}
if (isset($doc['seo']['description'])) {
    $doc['seo']['description'] = $smDesc;
}

frValidate($doc, $frRuntime, 'actions');
$c = $frApp->createPage($frActor, 'Nos Actions', 'actions', 'page', 'standalone');
$pid = (int) ($c['page']['id'] ?? 0);
if ($pid <= 0) {
    frFail('createPage failed for actions');
}
$status = $frApp->pageStatus($frActor, $pid);
$draft = $frApp->saveDraft($frActor, $pid, $doc, (int) $status['active_draft_revision_id'], 'manual');
$rev = (int) ($draft['revision']['id'] ?? 0);
file_put_contents($guardFile, json_encode(['title' => 'Nos Actions', 'slug' => 'actions', 'page_id' => $pid, 'rev' => $rev], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "NEW-ACTIONS page=$pid rev=$rev\nACTIONS-DONE\n";
