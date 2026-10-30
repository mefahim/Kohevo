<?php
/**
 * FightxLife CONTENT BUILD — Class detail (p43) repurposed for Boxe
 * Adaptée, DRAFT ONLY. Source: Boxe Adaptée doc (= Cours Introduction
 * Boxe section, verbatim). Deleted with no French counterpart: fake
 * rating, fictional instructor line, price row, level row, fake share
 * links, yoga photos, Lorem paragraph. Added from the doc: "Pour qui ?"
 * block after the schedule list. Newsletter band remapped to the same
 * French demo form as Home (identical JS/CSS -> identical asset keys).
 * Heading highlights set to FightxLife pink #e00080. Layout and CSS
 * untouched (no new classes, no CSS append).
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

$pink = '#e00080';

function dBlk(string $type, array $props, array $children = [], array $classes = []): array
{
    $b = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => [], 'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings' => [], 'children' => $children];
    if ($classes !== []) {
        $b['classNames'] = $classes;
    }
    return $b;
}
function dTxt(string $content, array $classes = []): array
{
    return dBlk('core.text', ['content' => $content, 'size' => 'base'], [], $classes);
}
function dHdg(string $text, string $level): array
{
    return dBlk('core.heading', ['text' => $text, 'level' => $level], []);
}

/* ---------- French texts (Boxe Adaptée doc, verbatim) ---------- */
$boxePara = 'C’est une séance de Boxe encadrée par des coachs professionnels qui se déroule en salle pendant 90 minutes. Contrairement au cours de boxe traditionnelle, celle-ci reprend les techniques de la boxe afin de s\'adapter aux personnes atteintes et/ou ayant été atteintes d\'un cancer ou d\'autres maladies. Nos coachs vous font évoluer selon votre rythme, votre capacité et votre condition physique. Aucun coup n’est porté aux corps, ce qui veut dire qu’aucun combat n’est effectué. L’activité physique est essentiel et la boxe reste un atout majeur face à la dépression, la peur, les angoisses etc';
$boxeExcerpt = 'C’est une séance de Boxe encadrée par des coachs professionnels qui se déroule en salle pendant 90 minutes.';
$boxeBien = '<ul><li>renforcement musculaire</li><li>coordination</li><li>concentration</li><li>stimulation motrice, sensorielle et cognitive</li><li>Reprendre confiance en soi</li><li>Extérioriser sa colère</li><li>Etablir des relations au sein d’un groupe</li></ul>';
$boxeLieu = '<ul><li>PHOENIX A.D.A MARTIAL ARTS, Avenue de la Toison d’Or 39, 1050 Ixelles</li><li>Le lundi de 17h30 à 19h00</li><li>Le jeudi de 17h30 à 19h00</li></ul>';
$boxePourQui = 'Les cours sont accessible à toutes personnes motivées, même en cours de traitement';
$formJs = <<<'JS'
window.FightxLifeNewsletterForm = { mount: function (el) { el.innerHTML = '<form class="fxl-nl-form"><input class="fxl-nl-input" type="email" name="email" placeholder="Votre adresse email" required><button class="fxl-nl-btn" type="submit">S’inscrire</button><p class="fxl-nl-status" aria-live="polite"></p></form>'; var f = el.querySelector('form'); f.addEventListener('submit', function (e) { e.preventDefault(); if (!f.reportValidity()) { return; } f.querySelector('.fxl-nl-status').textContent = 'Merci — votre inscription est prête. Cet aperçu statique n’envoie aucune donnée à un serveur.'; f.reset(); }); } };
JS;
$formCss = '.fxl-nl-form{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.fxl-nl-input{flex:1;min-width:220px;padding:0 24px;height:60px;border-radius:60px;border:1px solid #e5e5e5;background:#fff}.fxl-nl-btn{height:60px;padding:0 32px;border-radius:60px;border:0;background:#e00080;color:#fff;font-weight:700;cursor:pointer}.fxl-nl-status{width:100%;color:#0c0905}';

/* ---------- load doc + manifest ---------- */
$doc = frLoadDoc($frApp, $frActor, 43);
[$byOrig, $bySlug] = frManifest();
$med = function (string $orig) use ($byOrig): int {
    if (!isset($byOrig[$orig])) {
        frFail("detail-boxe media missing: $orig");
    }
    return (int) $byOrig[$orig][0];
};
$idVideo = $med('Boxe adapté 4.jpg');
$idHelp = $med('Boxe adapté 1.jpg');

/* ---------- section index + markers ---------- */
$secIdx = [];
foreach ($doc['sections'] as $si => $s) {
    $secIdx[$s['label'] ?? ''] = $si;
}
foreach (['page-banner', 'detail-section', 'newsletter'] as $lab) {
    if (!isset($secIdx[$lab])) {
        frFail("detail-boxe section missing: $lab");
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
$checks = ['page-banner' => 'Vinyasa Yoga', 'detail-section' => 'What You\'ll Learn', 'newsletter' => 'Subscribe To Our Newsletter'];
foreach ($checks as $lab => $needle) {
    if (!$hasText($doc['sections'][$secIdx[$lab]]['blocks'], $needle)) {
        frFail("detail-boxe section $lab marker not found: $needle");
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
    frFail('detail banner-inner not found');
}
$banInner = &frNavRef($doc, array_merge(['sections', $secIdx['page-banner'], 'blocks'], $p));
foreach ($banInner['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Boxe Adaptée';
        $b['props']['highlight'] = 'Adaptée';
        $b['props']['highlight_color'] = $pink;
        $ok++;
    }
}
unset($b);
$p = $findCont($banBlocks, 'breadcrumbs');
if ($p === null) {
    frFail('detail breadcrumbs not found');
}
$crumbs = &frNavRef($doc, array_merge(['sections', $secIdx['page-banner'], 'blocks'], $p));
$cb = 0;
foreach ($crumbs['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === 'Home') {
        $b['props']['link']['label'] = 'Accueil';
        $cb++;
    } elseif (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === 'Class') {
        $b['props']['link']['label'] = 'Cours';
        $cb++;
    } elseif (($b['type'] ?? '') === 'core.text' && in_array('crumb-current', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Boxe Adaptée';
        $cb++;
    }
}
unset($b);
if ($cb !== 3) {
    frFail("detail crumbs count=$cb");
}
$ok++;
unset($banInner, $crumbs, $banBlocks);

/* ---------- DETAIL MAIN ---------- */
$detBlocks = &$doc['sections'][$secIdx['detail-section']]['blocks'];
$p = $findCont($detBlocks, 'detail-main');
if ($p === null) {
    frFail('detail-main not found');
}
$main = &frNavRef($doc, array_merge(['sections', $secIdx['detail-section'], 'blocks'], $p));
$p = $findCont($detBlocks, 'detail-video');
if ($p === null) {
    frFail('detail-video not found');
}
$video = &frNavRef($doc, array_merge(['sections', $secIdx['detail-section'], 'blocks'], $p));
$vi = 0;
foreach ($video['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.image') {
        $b['props']['media']['media_id'] = $idVideo;
        $b['props']['media']['alt'] = 'Cours de boxe adaptée en salle';
        $vi++;
    }
}
unset($b);
if ($vi !== 1) {
    frFail("detail video image count=$vi");
}
$ok++;
$reqRichIdx = null;
foreach ($main['children'] as $i => &$b) {
    if (($b['type'] ?? '') === 'core.heading' && ($b['props']['level'] ?? '') === 'h2') {
        $b['props']['text'] = 'Séance de Boxe Adaptée';
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.text') {
        $b['props']['content'] = $boxePara;
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.heading' && ($b['props']['text'] ?? '') === "What You'll Learn") {
        $b['props']['text'] = 'Bienfaits de la boxe adaptée';
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.heading' && ($b['props']['text'] ?? '') === 'Requirement') {
        $b['props']['text'] = 'Où et quand ?';
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.rich_text') {
        if ($reqRichIdx === null && ($main['children'][$i - 1]['props']['text'] ?? '') === 'Où et quand ?') {
            $b['props']['content'] = $boxeLieu;
            $reqRichIdx = $i;
            $ok++;
        } else {
            $b['props']['content'] = $boxeBien;
            $ok++;
        }
    }
}
unset($b);
if ($reqRichIdx === null) {
    frFail('detail requirement list not found');
}
array_splice($main['children'], $reqRichIdx + 1, 0, [dHdg('Pour qui ?', 'h3'), dTxt($boxePourQui)]);
$ok++;
unset($main, $video);

/* ---------- SIDEBAR: info card + help card ---------- */
$p = $findCont($detBlocks, 'class-info-card');
if ($p === null) {
    frFail('class-info-card not found');
}
$info = &frNavRef($doc, array_merge(['sections', $secIdx['detail-section'], 'blocks'], $p));
$n = $directDel($info['children'], function ($c) {
    return (($c['type'] ?? '') === 'core.text' && strpos($c['props']['content'] ?? '', 'Rated') !== false)
        || in_array('instructor-line', $c['classNames'] ?? [], true)
        || in_array('share-links', $c['classNames'] ?? [], true);
});
if ($n !== 3) {
    frFail("detail info-card delete count=$n");
}
$ok++;
$p = $findCont($detBlocks, 'info-rows');
if ($p === null) {
    frFail('info-rows not found');
}
$rows = &frNavRef($doc, array_merge(['sections', $secIdx['detail-section'], 'blocks'], $p));
foreach ($rows['children'] as &$b) {
    if (strpos($b['props']['content'] ?? '', 'Duration') !== false) {
        $b['props']['content'] = '<span>Durée</span><strong>90 minutes</strong>';
        $ok++;
    } elseif (strpos($b['props']['content'] ?? '', 'Schedule') !== false) {
        $b['props']['content'] = '<span>Horaires</span><strong>Lundi et jeudi, 17h30 – 19h00</strong>';
        $ok++;
    }
}
unset($b);
$n = $directDel($rows['children'], function ($c) {
    return strpos($c['props']['content'] ?? '', 'Level') !== false || strpos($c['props']['content'] ?? '', 'Price') !== false;
});
if ($n !== 2) {
    frFail("detail info-rows delete count=$n");
}
$ok++;
$jl = 0;
foreach ($info['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === 'Join Class') {
        $b['props']['link']['label'] = 'Nous Contacter';
        $jl++;
    }
}
unset($b);
if ($jl !== 1) {
    frFail("detail join link count=$jl");
}
$ok++;
unset($info, $rows);
$p = $findCont($detBlocks, 'help-card');
if ($p === null) {
    frFail('help-card not found');
}
$help = &frNavRef($doc, array_merge(['sections', $secIdx['detail-section'], 'blocks'], $p));
foreach ($help['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.image') {
        $b['props']['media']['media_id'] = $idHelp;
        $b['props']['media']['alt'] = 'Cours de boxe adaptée en salle';
        $ok++;
    }
}
unset($b);
$p = $findCont($detBlocks, 'help-body');
if ($p === null) {
    frFail('help-body not found');
}
$helpBody = &frNavRef($doc, array_merge(['sections', $secIdx['detail-section'], 'blocks'], $p));
$hb = 0;
foreach ($helpBody['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.text' && in_array('help-eyebrow', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Une Question ?';
        $hb++;
    } elseif (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Nous Contacter';
        $hb++;
    } elseif (($b['type'] ?? '') === 'core.link' && strpos($b['props']['link']['href'] ?? '', 'tel:') === 0) {
        $b['props']['link']['label'] = '+32483127298';
        $b['props']['link']['href'] = 'tel:+32483127298';
        $hb++;
    } elseif (($b['type'] ?? '') === 'core.link' && strpos($b['props']['link']['href'] ?? '', 'mailto:') === 0) {
        $b['props']['link']['label'] = 'contact@fightxlife.com';
        $b['props']['link']['href'] = 'mailto:contact@fightxlife.com';
        $hb++;
    }
}
unset($b);
if ($hb !== 4) {
    frFail("detail help-body count=$hb");
}
$ok++;
unset($help, $helpBody, $detBlocks);

/* ---------- NEWSLETTER (same French demo form as Home) ---------- */
$nlBlocks = &$doc['sections'][$secIdx['newsletter']]['blocks'];
$p = $findCont($nlBlocks, 'newsletter-inner');
if ($p === null) {
    frFail('detail newsletter-inner not found');
}
$nlInner = &frNavRef($doc, array_merge(['sections', $secIdx['newsletter'], 'blocks'], $p));
$p2 = $findCont($nlInner['children'], 'newsletter-copy');
if ($p2 === null) {
    frFail('detail newsletter-copy not found');
}
$nlCopy = &frNavRef($doc, array_merge(['sections', $secIdx['newsletter'], 'blocks'], $p, ['children'], $p2));
foreach ($nlCopy['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Abonnez-vous à Notre Newsletter';
        $ok++;
    }
}
unset($b);
$n = $directDel($nlCopy['children'], function ($c) {
    return ($c['type'] ?? '') === 'core.text';
});
if ($n !== 1) {
    frFail("detail newsletter para delete count=$n");
}
$ok++;
$formJsKey = frStoreAsset($formJs, 'js');
$formCssKey = frStoreAsset($formCss, 'css');
echo "FORM-JS $formJsKey\nFORM-CSS $formCssKey\n";
$fi = 0;
foreach ($nlInner['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.island' && in_array('newsletter-form-slot', $b['classNames'] ?? [], true)) {
        $b['props']['asset_key'] = $formJsKey;
        $b['props']['css_key'] = $formCssKey;
        $b['props']['entry'] = 'FightxLifeNewsletterForm';
        $fi++;
    }
}
unset($b);
if ($fi !== 1) {
    frFail("detail newsletter island count=$fi");
}
$ok++;
unset($nlCopy, $nlInner, $nlBlocks);

/* ---------- SEO ---------- */
if (isset($doc['seo']['title'])) {
    $doc['seo']['title'] = 'Boxe Adaptée';
    $ok++;
}
if (isset($doc['seo']['description'])) {
    $doc['seo']['description'] = $boxeExcerpt;
    $ok++;
}

frValidate($doc, $frRuntime, 'detail-boxe');
frSaveDraft($frApp, $frActor, 43, $doc);
echo "DETAIL-BOXE-DONE edits=$ok\n";
