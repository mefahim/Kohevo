<?php
/**
 * FightxLife CONTENT BUILD — Class page (p42) to French, DRAFT ONLY.
 * Sources (client docs, verbatim): Cours Introduction (3 intro
 * paragraphs + course first sentences for card excerpts). Deleted with
 * no French counterpart: feature-row, testimonial-section (testimonials
 * live on Home), section Lorem intros, yoga gallery photos. Course
 * cards reuse the Home card structure and photos. Gallery shows 2
 * photos per course. Heading highlights set to FightxLife pink #e00080.
 * Layout and CSS untouched (no new classes, no CSS append).
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

$pink = '#e00080';

function cBlk(string $type, array $props, array $children = [], array $classes = []): array
{
    $b = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => [], 'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings' => [], 'children' => $children];
    if ($classes !== []) {
        $b['classNames'] = $classes;
    }
    return $b;
}
function cCont(array $children, array $classes): array
{
    return cBlk('core.container', ['direction' => 'vertical', 'gap' => 'none'], $children, $classes);
}
function cTxt(string $content, array $classes = []): array
{
    return cBlk('core.text', ['content' => $content, 'size' => 'base'], [], $classes);
}
function cHdg(string $text, string $level, ?string $highlight, string $pink): array
{
    $props = ['text' => $text, 'level' => $level];
    if ($highlight !== null) {
        $props['highlight'] = $highlight;
        $props['highlight_color'] = $pink;
    }
    return cBlk('core.heading', $props, []);
}
function cImg(int $mediaId, string $alt): array
{
    return cBlk('core.image', ['media' => ['media_id' => $mediaId, 'alt' => $alt], 'rounded' => false]);
}
function cLnk(string $label, string $href, array $classes = []): array
{
    return cBlk('core.link', ['link' => ['label' => $label, 'href' => $href, 'target' => '_self'], 'style' => 'plain'], [], $classes);
}

/* ---------- French texts (client docs, verbatim) ---------- */
$intro1 = 'FightxLife permet aux personnes atteintes d’un cancer ou toutes autres maladies de participer à un sport et cela de façon adaptée. Les cours de boxe permettent, à travers l’exercice physique et les valeurs associées à ce sport de renforcer le mental des participants, d’offrir un lieu sécurisé au sein duquel ils peuvent s’y défouler, de rencontrer d’autres personnes et renforcer le sentiment d’appartenance à un groupe. Pratiquer régulièrement la boxe constitue une activité physique plus ou moins intense et salvatrice pour la santé.';
$intro2 = 'Les cours de boxe sont un moteur essentiel pour chacun de nos membres.';
$intro3 = 'Notre collaboration étroite avec les médecins, psychologue et kinésithérapeute nous permet d’appuyer le fait que la pratique sportive est primordiale pour réduire le risque de rechute.';
$exBoxe = 'C’est une séance de Boxe encadrée par des coachs professionnels qui se déroule en salle pendant 90 minutes.';
$exWater = 'Le Waterfight est une séance de boxe encadrée par des coachs professionnels qui se déroule en piscine pendant 45 minutes dont les mouvements sont inspirés des arts martiaux et des techniques de boxe.';
$exFem = 'Pour répondre à une demande plus que nécessaire, FightxLife a mis en place des cours de kick-boxing encadré par des coachs professionnelles femmes exclusivement réservés aux FEMMES.';

/* ---------- load doc + manifest ---------- */
$doc = frLoadDoc($frApp, $frActor, 42);
[$byOrig, $bySlug] = frManifest();
$med = function (string $orig) use ($byOrig): int {
    if (!isset($byOrig[$orig])) {
        frFail("class media missing: $orig");
    }
    return (int) $byOrig[$orig][0];
};
$idBoxe1 = $med('Boxe adapté 1.jpg');
$idBoxe2 = $med('Boxe adapté 2.jpg');
$idBoxe3 = $med('Boxe adapté 3.jpg');
$idWater1 = $med('Waterfight piscine.JPG');
$idWater2 = $med('Waterfight 2.jpg');
$idWater3 = $med('Waterfight 3.jpg');
$idFem1 = $med('Feminin 1.jpg');
$idFem2 = $med('feminin.jpg');
$idFem3 = $med('femininn.jpg');

/* ---------- section index + markers ---------- */
$secIdx = [];
foreach ($doc['sections'] as $si => $s) {
    $secIdx[$s['label'] ?? ''] = $si;
}
foreach (['page-banner', 'feature-row', 'class-section', 'testimonial-section', 'gallery-section'] as $lab) {
    if (!isset($secIdx[$lab])) {
        frFail("class section missing: $lab");
    }
}
$hasText = function (array $blocks, string $needle): bool {
    $found = false;
    $walk = function ($bs) use (&$walk, $needle, &$found) {
        foreach ($bs as $b) {
            foreach (['text', 'content'] as $f) {
                if (isset($b['props'][$f]) && strpos((string) $b['props'][$f], $needle) !== false) {
                    $found = true;
                }
            }
            if (isset($b['props']['link']['label']) && strpos($b['props']['link']['label'], $needle) !== false) {
                $found = true;
            }
            if (!empty($b['children'])) {
                $walk($b['children']);
            }
        }
    };
    $walk($blocks);
    return $found;
};
$checks = ['page-banner' => 'Yoga Class', 'class-section' => 'Our Best Classes',
    'testimonial-section' => 'What Client Says', 'gallery-section' => 'Yoga Class Gallery'];
foreach ($checks as $lab => $needle) {
    if (!$hasText($doc['sections'][$secIdx[$lab]]['blocks'], $needle)) {
        frFail("class section $lab marker not found: $needle");
    }
}
$ok = 0;
$findCont = function (array $blocks, string $cls): ?array {
    return frFindPath($blocks, function ($b) use ($cls) {
        return ($b['type'] ?? '') === 'core.container' && in_array($cls, $b['classNames'] ?? [], true);
    });
};
$directDel = function (array &$children, callable $pred): int {
    $n = 0;
    $kept = [];
    foreach ($children as $c) {
        if ($pred($c)) {
            $n++;
            continue;
        }
        $kept[] = $c;
    }
    $children = $kept;
    return $n;
};

/* ---------- BANNER ---------- */
$banBlocks = &$doc['sections'][$secIdx['page-banner']]['blocks'];
$p = $findCont($banBlocks, 'banner-inner');
if ($p === null) {
    frFail('class banner-inner not found');
}
$banInner = &frNavRef($doc, array_merge(['sections', $secIdx['page-banner'], 'blocks'], $p));
foreach ($banInner['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Nos Cours';
        $b['props']['highlight'] = 'Cours';
        $b['props']['highlight_color'] = $pink;
        $ok++;
    }
}
unset($b);
$p = $findCont($banBlocks, 'breadcrumbs');
if ($p === null) {
    frFail('class breadcrumbs not found');
}
$crumbs = &frNavRef($doc, array_merge(['sections', $secIdx['page-banner'], 'blocks'], $p));
$cb = 0;
foreach ($crumbs['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === 'Home') {
        $b['props']['link']['label'] = 'Accueil';
        $cb++;
    } elseif (($b['type'] ?? '') === 'core.text' && in_array('crumb-current', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Nos Cours';
        $cb++;
    }
}
unset($b);
if ($cb !== 2) {
    frFail("class crumbs count=$cb");
}
$ok++;
unset($banInner, $crumbs, $banBlocks);

/* ---------- CLASS SECTION: head + intro + 3 course cards ---------- */
$clsBlocks = &$doc['sections'][$secIdx['class-section']]['blocks'];
$p = $findCont($clsBlocks, 'section-head');
if ($p === null) {
    frFail('class section-head not found');
}
$clsHead = &frNavRef($doc, array_merge(['sections', $secIdx['class-section'], 'blocks'], $p));
foreach ($clsHead['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.text' && in_array('eyebrow', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Cours';
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Nos Cours';
        $b['props']['highlight'] = 'Cours';
        $b['props']['highlight_color'] = $pink;
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.text') {
        $b['props']['content'] = $intro1;
        $ok++;
    }
}
unset($b);
$p = $findCont($clsBlocks, 'content-section');
if ($p === null) {
    frFail('class content-section not found');
}
$contentSec = &frNavRef($doc, array_merge(['sections', $secIdx['class-section'], 'blocks'], $p));
$headPos = null;
foreach ($contentSec['children'] as $i => $c) {
    if (in_array('section-head', $c['classNames'] ?? [], true)) {
        $headPos = $i;
    }
}
if ($headPos === null) {
    frFail('class head position not found');
}
array_splice($contentSec['children'], $headPos + 1, 0, [cTxt($intro2), cTxt($intro3)]);
$ok++;
$p = $findCont($clsBlocks, 'class-grid');
if ($p === null) {
    frFail('class class-grid not found');
}
$classGrid = &frNavRef($doc, array_merge(['sections', $secIdx['class-section'], 'blocks'], $p));
$courses = [
    [$idBoxe1, 'Cours de boxe adaptée en salle', 'Boxe Adaptée', '/class-detail', $exBoxe, '01'],
    [$idWater1, 'Cours de Waterxfight en piscine', 'Waterxfight', '/cours-waterxfight', $exWater, '02'],
    [$idFem1, 'Cours de kick-boxing 100% féminin', '100% Féminin', '/cours-feminin', $exFem, '03'],
];
$cards = [];
foreach ($courses as [$mid, $alt, $title, $href, $ex, $num]) {
    $cards[] = cCont([
        cCont([cImg($mid, $alt), cTxt($num, ['class-number'])], ['class-photo']),
        cCont([
            cCont([], ['mini-icon']),
            cLnk($title, $href, ['class-title']),
            cTxt($ex, ['class-excerpt']),
            cLnk('Lire la Suite →', $href, ['text-link']),
        ], ['class-card-body']),
    ], ['class-card']);
}
$classGrid['children'] = $cards;
$ok++;
unset($clsHead, $contentSec, $classGrid, $clsBlocks);

/* ---------- GALLERY: 2 photos per course ---------- */
$galBlocks = &$doc['sections'][$secIdx['gallery-section']]['blocks'];
$p = $findCont($galBlocks, 'section-head');
if ($p === null) {
    frFail('class gallery head not found');
}
$galHead = &frNavRef($doc, array_merge(['sections', $secIdx['gallery-section'], 'blocks'], $p));
foreach ($galHead['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Nos Cours en Images';
        $b['props']['highlight'] = 'Images';
        $b['props']['highlight_color'] = $pink;
        $ok++;
    }
}
unset($b);
$n = $directDel($galHead['children'], function ($c) {
    return ($c['type'] ?? '') === 'core.text';
});
if ($n !== 2) {
    frFail("class gallery head txt delete count=$n");
}
$ok++;
$p = $findCont($galBlocks, 'gallery-grid');
if ($p === null) {
    frFail('class gallery-grid not found');
}
$galGrid = &frNavRef($doc, array_merge(['sections', $secIdx['gallery-section'], 'blocks'], $p));
$galGrid['children'] = [
    cImg($idBoxe2, 'Cours de boxe adaptée en salle'),
    cImg($idBoxe3, 'Cours de boxe adaptée en salle'),
    cImg($idWater2, 'Cours de Waterxfight en piscine'),
    cImg($idWater3, 'Cours de Waterxfight en piscine'),
    cImg($idFem2, 'Cours de kick-boxing 100% féminin'),
    cImg($idFem3, 'Cours de kick-boxing 100% féminin'),
];
$ok++;
unset($galHead, $galGrid, $galBlocks);

/* ---------- REORDER (drop feature-row + testimonial-section) ---------- */
$doc['sections'] = [
    $doc['sections'][$secIdx['page-banner']],
    $doc['sections'][$secIdx['class-section']],
    $doc['sections'][$secIdx['gallery-section']],
];

/* ---------- SEO ---------- */
if (isset($doc['seo']['title'])) {
    $doc['seo']['title'] = 'Nos Cours';
    $ok++;
}
if (isset($doc['seo']['description'])) {
    $doc['seo']['description'] = $intro1;
    $ok++;
}

frValidate($doc, $frRuntime, 'class');
frSaveDraft($frApp, $frActor, 42, $doc);
echo "CLASS-DONE edits=$ok\n";
