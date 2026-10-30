<?php
/**
 * FightxLife CONTENT BUILD — NEW Politique de Confidentialité page
 * (/politique-confidentialite), DRAFT ONLY.
 * Source: the client's 2021 DRAFT Privacy Notice docx (66 paragraphs,
 * extracted verbatim). NOTHING is updated: the draft names
 * "FightingxLife ASBL", gives the address as "1080 Bruxelles", the phone
 * as "+32 (0) 483/12.72.98" and the site as "https://www.fightxlife.com/"
 * — all kept exactly as written, and all flagged in the final report
 * (the draft status itself is reported, not stamped onto the page).
 * Structure mirrors the doc: section headings become h2, subsection
 * labels become h3, bullet groups become rich-text lists (the "- "
 * markers are list markers, rendered by the list itself), label/value
 * paragraphs stay separate blocks. The parenthesised APD URL and the
 * site URL are rendered as links with the URL itself as the label.
 * Page CSS = About page stylesheet; island JS is an empty mount.
 * Guard file refuses a second create (duplicate slugs).
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

$pink = '#e00080';
$guardFile = '/tmp/fr-new-privacy.json';
if (file_exists($guardFile)) {
    echo "NEW-PRIVACY-ALREADY-CREATED (guard file exists, refusing duplicate create)\n";
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
function xRich(string $content): array
{
    return xBlk('core.rich_text', ['content' => $content]);
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
function xUl(array $items): string
{
    $lis = '';
    foreach ($items as $it) {
        $lis .= '<li>' . htmlspecialchars($it, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    return '<ul>' . $lis . '</ul>';
}

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
    xCont([xHdg('Politique de Confidentialité', 'h1', 'Confidentialité', $pink),
        xCont([xLnk('Accueil', '/yogist-home'), xTxt('›'), xTxt('Politique de Confidentialité', ['crumb-current'])], ['breadcrumbs'], 'horizontal'),
    ], ['banner-inner', 'container']),
]);

$coord = xSec('privacy-coord', [xCont([
    xCont([xHdg('Coordonnées du responsable du traitement', 'h2', null, $pink)], ['section-head', 'center']),
    xTxt('Nom'), xTxt('FightingxLife ASBL'),
    xTxt('Numéro d’entreprise'), xTxt('720.965.564'),
    xTxt('Représentée par'), xTxt('Madame F. Rezki, en sa qualité de présidente'),
    xTxt('Adresse'), xTxt('Rue Gabrielle Petit 32, 1080 Bruxelles'),
    xTxt('Numéro de tél.'), xTxt('+32 (0) 483/12.72.98'),
    xTxt('Site internet'), xExt('https://www.fightxlife.com/', 'https://www.fightxlife.com/'),
], ['content-section', 'container'])]);

$donnees = xSec('privacy-donnees', [xCont([
    xCont([xHdg('Données collectées', 'h2', null, $pink)], ['section-head', 'center']),
    xHdg('Données', 'h3', null, $pink),
    xRich(xUl(['Nom et prénom(s)', 'Numéro de téléphone', 'Adresse e-mail', 'Photos et vidéos prises par l’association dans le cadre des séances (si l’adhérent accepte de céder son droit à l’image)'])),
    xHdg('Finalités', 'h3', null, $pink),
    xRich(xUl(['Adhésion à l’association', 'Envoi de courriers électroniques liés aux activités de l’association', 'Illustration du site internet et de la page Facebook de l’association (photos/vidéos)'])),
    xHdg('Base juridique', 'h3', null, $pink),
    xRich(xUl(['Contrat', 'Intérêts légitimes', 'Consentement', 'Obligation légale'])),
    xHdg('Destinataires', 'h3', null, $pink),
    xRich(xUl(['Membres du personnel de l’association', 'Visiteurs du site internet et de la page Facebook de l’association (photos/vidéos)', 'Equipe de conception et de maintenance de notre site internet'])),
    xHdg('Durée de conservation', 'h3', null, $pink),
    xRich(xUl(['Les données sont conservées jusqu’à dissolution de l’association ou retrait du membre/de l’adhérent', 'Les photos et vidéos sont conservées jusqu’à dissolution de l’association ou mise à jour du site internet (droit à l’image)'])),
    xHdg('Transfert vers un pays tiers', 'h3', null, $pink),
    xTxt('Non'),
], ['content-section', 'container'])]);

$droits = xSec('privacy-droits', [xCont([
    xCont([xHdg('Vos droits', 'h2', null, $pink)], ['section-head', 'center']),
    xTxt('Conformément au Règlement européen n°2016/679/UE du 27 avril 2016 relatif à la protection des personnes physiques à l’égard du traitement de données à caractère personnel et à la libre circulation de ces données (ci-après « RGPD »), vous bénéficiez des droits suivants :'),
    xTxt('Art. 15 RGPD'), xTxt('Droit d’accès'),
    xTxt('Art. 16 RGPD'), xTxt('Droit de rectification'),
    xTxt('Art. 17 RGPD'), xTxt('Droit d’effacement de vos données'),
    xTxt('Art. 18 RGPD'), xTxt('Droit de limitation du traitement de vos données'),
    xTxt('Art. 20 RGPD'), xTxt('Droit à la portabilité de vos données'),
    xTxt('Art. 21 et 22 RGPD'), xTxt('Droit d’opposition'),
    xTxt('Art. 77 RGPD'), xTxt('Droit d’introduire une réclamation auprès de l’Autorité de protection des données'),
    xExt('https://www.autoriteprotectiondonnees.be', 'https://www.autoriteprotectiondonnees.be'),
], ['content-section', 'container'])]);

$cookies = xSec('privacy-cookies', [xCont([
    xCont([xHdg('Cookies', 'h2', null, $pink)], ['section-head', 'center']),
    xTxt('À ce stade, notre site internet n’utilise pas de cookies. Il est toutefois possible qu’il en utilise un jour, en vue de faciliter son utilisation et d’en améliorer la navigabilité.'),
    xTxt('Nous vous le ferons savoir par l’intermédiaire de cette Privacy Notice, le cas échéant.'),
], ['content-section', 'container'])]);

$securite = xSec('privacy-securite', [xCont([
    xCont([xHdg('Sécurité afférente aux données', 'h2', null, $pink)], ['section-head', 'center']),
    xTxt('Vos données personnelles sont confidentielles et seront traitées comme telles.'),
    xTxt('Nous prenons toutes les mesures raisonnables pour protéger vos données. Celles-ci incluent la mise en place de procédures visant à limiter les accès non autorisés à vos données ainsi que la divulgation de celles-ci. Nous assurons également que nous ne partageons aucune informations des données avec aucun de nos tiers, sponsor ou autres'),
], ['content-section', 'container'])]);

$decisions = xSec('privacy-decisions', [xCont([
    xCont([xHdg('Décisions automatisées et profilage', 'h2', null, $pink)], ['section-head', 'center']),
    xTxt('Aucune décision automatisée ne sera prise et aucun profil vous concernant ne sera créé pendant le traitement de vos données tel que décrit dans la présente Privacy Notice.'),
], ['content-section', 'container'])]);

$modifs = xSec('privacy-modifs', [xCont([
    xCont([xHdg('Modifications apportées à la Privacy Notice', 'h2', null, $pink)], ['section-head', 'center']),
    xTxt('Nous nous réservons le droit de modifier à tout moment la présente Privacy Notice, notamment en cas de modification de la réglementation.'),
    xTxt('La version en vigueur de cette Privacy Notice sera toujours disponible sur notre site internet.'),
], ['content-section', 'container'])]);

$icons = xSec('page-icons', [xIsland($pageJsKey, $aboutCssKey, 'FightxLifePageIcons')]);
$doc = ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default',
    'settings' => CanonicalDocumentSchema::defaultSettings(),
    'seo' => CanonicalDocumentSchema::defaultSeo('Politique de Confidentialité'),
    'sections' => [$banner, $coord, $donnees, $droits, $cookies, $securite, $decisions, $modifs, $icons]];
if (isset($doc['seo']['title'])) {
    $doc['seo']['title'] = 'Politique de Confidentialité';
}
// No description overwrite: no page-level description exists in the
// draft, and inventing one would break the no-extra-content rule.

frValidate($doc, $frRuntime, 'privacy');
$c = $frApp->createPage($frActor, 'Politique de Confidentialité', 'politique-confidentialite', 'page', 'standalone');
$pid = (int) ($c['page']['id'] ?? 0);
if ($pid <= 0) {
    frFail('createPage failed for politique-confidentialite');
}
$status = $frApp->pageStatus($frActor, $pid);
$draft = $frApp->saveDraft($frActor, $pid, $doc, (int) $status['active_draft_revision_id'], 'manual');
$rev = (int) ($draft['revision']['id'] ?? 0);
file_put_contents($guardFile, json_encode(['title' => 'Politique de Confidentialité', 'slug' => 'politique-confidentialite', 'page_id' => $pid, 'rev' => $rev], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "NEW-PRIVACY page=$pid rev=$rev\nPRIVACY-DONE\n";
