<?php
/**
 * FightxLife CONTENT BUILD — NEW course pages (Waterxfight + 100%
 * Féminin), DRAFT ONLY. Both docs mirror the Boxe detail template
 * (banner + detail + newsletter) with fresh block ids; the detail
 * JS/CSS island keys are copied at runtime from the Boxe detail page
 * (p43, language-independent), and the newsletter band reuses the same
 * French demo form as Home (identical JS/CSS -> identical asset keys).
 * Sources (client docs, verbatim): Waterxfight + Féminin docs. The
 * Waterxfight bienfaits lose only their prose semicolons (list
 * separators, not item text); the internal "Note stephanie" line is
 * EXCLUDED. No publish here. Guard file new-course-pages.json refuses
 * a second create (duplicate slugs) — delete it only after verifying
 * the pages do not exist.
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

$pink = '#e00080';
$guardFile = '/tmp/fr-new-course-pages.json';
if (file_exists($guardFile)) {
    echo "NEW-COURSES-ALREADY-CREATED (guard file exists, refusing duplicate create)\n";
    echo file_get_contents($guardFile) . "\n";
    exit(0);
}

function nBlk(string $type, array $props, array $children = [], array $classes = []): array
{
    $b = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => [], 'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings' => [], 'children' => $children];
    if ($classes !== []) {
        $b['classNames'] = $classes;
    }
    return $b;
}
function nCont(array $children, array $classes, string $dir = 'vertical'): array
{
    return nBlk('core.container', ['direction' => $dir, 'gap' => 'none'], $children, $classes);
}
function nTxt(string $content, array $classes = []): array
{
    return nBlk('core.text', ['content' => $content, 'size' => 'base'], [], $classes);
}
function nHdg(string $text, string $level, ?string $highlight, string $pink): array
{
    $props = ['text' => $text, 'level' => $level];
    if ($highlight !== null) {
        $props['highlight'] = $highlight;
        $props['highlight_color'] = $pink;
    }
    return nBlk('core.heading', $props, []);
}
function nImg(int $mediaId, string $alt): array
{
    return nBlk('core.image', ['media' => ['media_id' => $mediaId, 'alt' => $alt], 'rounded' => false]);
}
function nLnk(string $label, string $href, array $classes = [], string $target = '_self'): array
{
    return nBlk('core.link', ['link' => ['label' => $label, 'href' => $href, 'target' => $target], 'style' => 'plain'], [], $classes);
}
function nRich(string $html, array $classes = []): array
{
    return nBlk('core.rich_text', ['content' => $html], [], $classes);
}
function nIsland(string $assetKey, string $cssKey, string $entry, array $classes = []): array
{
    return nBlk('core.island', ['asset_key' => $assetKey, 'css_key' => $cssKey, 'entry' => $entry,
        'shell' => '<span></span>', 'props_json' => '{}', 'loading' => 'eager'], [], $classes);
}
function nSec(string $label, array $blocks): array
{
    return ['id' => CanonicalDocumentSchema::newSectionId(), 'label' => $label, 'global_ref' => null,
        'layout' => ['columns' => ['base' => 1], 'gap' => 'none', 'padding_y' => ['base' => 'none', 'md' => 'none'], 'width' => 'wide', 'background_token' => 'surface.primary'],
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']], 'blocks' => $blocks];
}

/* ---------- French texts (client docs, verbatim) ---------- */
$waterPara = 'Le Waterfight est une séance de boxe encadrée par des coachs professionnels qui se déroule en piscine pendant 45 minutes dont les mouvements sont inspirés des arts martiaux et des techniques de boxe. Il se pratique avec des gants de boxe spécifiques afin de mieux travailler avec la résistance de l’eau et tout cela en musique. Grâce à la résistance de l’eau, les coups sont ralentis, l’eau préserve des impacts et des secousses sur l’organisme et les articulations. Elle ne comporte aucun risque de blessure, car l’eau amortit les chocs.';
$waterExcerpt = 'Le Waterfight est une séance de boxe encadrée par des coachs professionnels qui se déroule en piscine pendant 45 minutes dont les mouvements sont inspirés des arts martiaux et des techniques de boxe.';
$waterMaillot = 'Il suffit de se munir d’un maillot et d’un bonnet de bain.';
$waterBien = '<ul><li>évacuer le stress et les tensions</li><li>développer et renforcer les capacités respiratoires</li><li>muscler l’intégralité du corps en douceur et en profondeur</li><li>Drainage lymphatique</li><li>tonifier le rythme cardio-vasculaire</li><li>renforcer les muscles</li><li>travailler l’équilibre</li></ul>';
$waterLieu = '<ul><li>Piscine Victor Boin, Rue de la Perche 38, 1060 Saint-Gilles</li><li>Le mardi à 18h30</li><li>Le vendredi à 18h30</li></ul>';
$waterPourQui = 'Un cours accessible à toute personne motivée quelle que soit votre condition physique.';
$femPara = 'Pour répondre à une demande plus que nécessaire, FightxLife a mis en place des cours de kick-boxing encadré par des coachs professionnelles femmes exclusivement réservés aux FEMMES. Rassembler les femmes entre elles permettra a Fightxlife de les sensibiliser chacune au cancer, de leur donner confiance en elles et de leur ouvrir la porte au monde du sport. Le kick-boxing est un sport de combat qui combine différentes techniques de poings et de pieds.';
$femExcerpt = 'Pour répondre à une demande plus que nécessaire, FightxLife a mis en place des cours de kick-boxing encadré par des coachs professionnelles femmes exclusivement réservés aux FEMMES.';
$femBien = '<ul><li>Améliore le cardio vasculaire, l’endurance et la respiration</li><li>Favorise le renforcement musculaire</li><li>Développe la rapidité, l’agilité et la coordination</li><li>Libère les tensions et le stress</li><li>Tonifie les muscles</li></ul>';
$femLieu = '<ul><li>Quai des charbonnages n°82, 1080 Molenbeek-Saint-Jean</li><li>Le mercredi de 18h00 à 19h30</li><li>Le vendredi de 18h00 à 19h30</li><li>Le dimanche de 12h00 à 13h30</li></ul>';
$femRejoins = 'Quelle que soit ta condition physique ou ton expérience, rejoins-nous!';
$formJs = <<<'JS'
window.FightxLifeNewsletterForm = { mount: function (el) { el.innerHTML = '<form class="fxl-nl-form"><input class="fxl-nl-input" type="email" name="email" placeholder="Votre adresse email" required><button class="fxl-nl-btn" type="submit">S’inscrire</button><p class="fxl-nl-status" aria-live="polite"></p></form>'; var f = el.querySelector('form'); f.addEventListener('submit', function (e) { e.preventDefault(); if (!f.reportValidity()) { return; } f.querySelector('.fxl-nl-status').textContent = 'Merci — votre inscription est prête. Cet aperçu statique n’envoie aucune donnée à un serveur.'; f.reset(); }); } };
JS;
$formCss = '.fxl-nl-form{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.fxl-nl-input{flex:1;min-width:220px;padding:0 24px;height:60px;border-radius:60px;border:1px solid #e5e5e5;background:#fff}.fxl-nl-btn{height:60px;padding:0 32px;border-radius:60px;border:0;background:#e00080;color:#fff;font-weight:700;cursor:pointer}.fxl-nl-status{width:100%;color:#0c0905}';

/* ---------- load manifest + detail template island keys ---------- */
[$byOrig, $bySlug] = frManifest();
$med = function (string $orig) use ($byOrig): int {
    if (!isset($byOrig[$orig])) {
        frFail("new-courses media missing: $orig");
    }
    return (int) $byOrig[$orig][0];
};
$detailDoc = frLoadDoc($frApp, $frActor, 43);
$detailJsKey = null;
$detailCssKey = null;
$walkIsl = function ($bs) use (&$walkIsl, &$detailJsKey, &$detailCssKey) {
    foreach ($bs as $b) {
        if (($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistDetailIcons') {
            $detailJsKey = $b['props']['asset_key'] ?? null;
            $detailCssKey = $b['props']['css_key'] ?? null;
        }
        if (!empty($b['children'])) {
            $walkIsl($b['children']);
        }
    }
};
foreach ($detailDoc['sections'] as $s) {
    $walkIsl($s['blocks'] ?? []);
}
if ($detailJsKey === null || $detailCssKey === null) {
    frFail('detail template island keys not found in p43');
}
$formJsKey = frStoreAsset($formJs, 'js');
$formCssKey = frStoreAsset($formCss, 'css');
echo "FORM-JS $formJsKey\nFORM-CSS $formCssKey\nDETAIL-JS $detailJsKey\nDETAIL-CSS $detailCssKey\n";

/* ---------- doc builder ---------- */
$buildDoc = function (string $title, string $bannerHl, array $mainBlocks, array $infoRows, int $videoId, string $videoAlt, int $helpId, string $helpAlt, string $excerpt) use ($pink, $detailJsKey, $detailCssKey, $formJsKey, $formCssKey): array {
    $banner = nSec('page-banner', [
        nCont([nHdg($title, 'h1', $bannerHl, $pink),
            nCont([nLnk('Accueil', '/yogist-home'), nTxt('›'), nLnk('Cours', '/class'), nTxt('›'), nTxt($title, ['crumb-current'])], ['breadcrumbs'], 'horizontal'),
        ], ['banner-inner', 'container']),
    ]);
    $helpBody = nCont([nTxt('Une Question ?', ['help-eyebrow']), nHdg('Nous Contacter', 'h3', null, $pink),
        nLnk('+32483127298', 'tel:+32483127298'), nLnk('contact@fightxlife.com', 'mailto:contact@fightxlife.com')], ['help-body']);
    $infoRows = [];
    foreach ($infoRows as [$lab, $val]) {
        $infoRows[] = nTxt('<span>' . $lab . '</span><strong>' . $val . '</strong>', ['info-row']);
    }
    $detail = nSec('detail-section', [
        nCont([
            nCont(array_merge([
                nCont([nImg($videoId, $videoAlt), nCont([], ['detail-play-slot'])], ['detail-video']),
            ], $mainBlocks), ['detail-main']),
            nCont([
                nCont([nCont($infoRows, ['info-rows']), nLnk('Nous Contacter', '/contact-us', ['button', 'button-primary'])], ['class-info-card']),
                nCont([nImg($helpId, $helpAlt), $helpBody], ['help-card']),
            ], ['class-sidebar']),
        ], ['detail-layout', 'container']),
    ]);
    $newsletter = nSec('newsletter', [
        nCont([
            nCont([], ['newsletter-icon']),
            nCont([nHdg('Abonnez-vous à Notre Newsletter', 'h2', null, $pink)], ['newsletter-copy']),
            nIsland($formJsKey, $formCssKey, 'FightxLifeNewsletterForm', ['newsletter-form-slot']),
        ], ['newsletter', 'newsletter-inner', 'container']),
        nIsland($detailJsKey, $detailCssKey, 'YogistDetailIcons'),
    ]);
    $doc = ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default',
        'settings' => CanonicalDocumentSchema::defaultSettings(),
        'seo' => CanonicalDocumentSchema::defaultSeo($title),
        'sections' => [$banner, $detail, $newsletter]];
    if (isset($doc['seo']['title'])) {
        $doc['seo']['title'] = $title;
    }
    if (isset($doc['seo']['description'])) {
        $doc['seo']['description'] = $excerpt;
    }
    return $doc;
};

$waterMain = [
    nHdg('Séance de Waterxfight', 'h2', null, $pink),
    nTxt($waterPara),
    nTxt($waterMaillot),
    nHdg('Bienfaits de Waterxfight', 'h3', null, $pink),
    nRich($waterBien, ['check-list']),
    nHdg('Où et quand ?', 'h3', null, $pink),
    nRich($waterLieu, ['check-list']),
    nHdg('Pour qui ?', 'h3', null, $pink),
    nTxt($waterPourQui),
];
$femMain = [
    nHdg('Boxe 100% Féminin', 'h2', null, $pink),
    nTxt($femPara),
    nHdg('Bienfait de la boxe', 'h3', null, $pink),
    nRich($femBien, ['check-list']),
    nHdg('Où et quand ?', 'h3', null, $pink),
    nRich($femLieu, ['check-list']),
    nTxt($femRejoins),
];
$waterDoc = $buildDoc('Waterxfight', 'Waterxfight', $waterMain,
    [['Durée', '45 minutes'], ['Horaires', 'Mardi et vendredi, 18h30']],
    $med('Waterfight piscine.JPG'), 'Cours de Waterxfight en piscine',
    $med('Waterfight 2.jpg'), 'Cours de Waterxfight en piscine', $waterExcerpt);
$femDoc = $buildDoc('100% Féminin', 'Féminin', $femMain,
    [['Horaires', 'Mercredi et vendredi, 18h00 – 19h30 ; Dimanche, 12h00 – 13h30']],
    $med('Feminin 1.jpg'), 'Cours de kick-boxing 100% féminin',
    $med('feminin.jpg'), 'Cours de kick-boxing 100% féminin', $femExcerpt);

/* ---------- validate BOTH before creating either ---------- */
frValidate($waterDoc, $frRuntime, 'cours-waterxfight');
frValidate($femDoc, $frRuntime, 'cours-feminin');

/* ---------- create + draft ---------- */
$created = [];
foreach ([['Waterxfight', 'cours-waterxfight', $waterDoc], ['100% Féminin', 'cours-feminin', $femDoc]] as [$t, $slug, $d]) {
    $c = $frApp->createPage($frActor, $t, $slug, 'page', 'standalone');
    $pid = (int) ($c['page']['id'] ?? 0);
    if ($pid <= 0) {
        frFail("createPage failed for $slug");
    }
    $status = $frApp->pageStatus($frActor, $pid);
    $draft = $frApp->saveDraft($frActor, $pid, $d, (int) $status['active_draft_revision_id'], 'manual');
    $rev = (int) ($draft['revision']['id'] ?? 0);
    echo "NEW-COURSE $slug page=$pid rev=$rev\n";
    $created[] = ['title' => $t, 'slug' => $slug, 'page_id' => $pid, 'rev' => $rev];
}
file_put_contents($guardFile, json_encode($created, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "NEW-COURSES-DONE\n";
