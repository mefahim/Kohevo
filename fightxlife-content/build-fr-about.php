<?php
/**
 * FightxLife CONTENT BUILD — About page (p41) to French, DRAFT ONLY.
 * Sources (client docs, verbatim): Nos Valeurs (feature cards, titles
 * split from bodies at the doc's own " : "), Nos Missions (new section),
 * Equipe (9 members, roles as given), Advisory Board (intro + Sara /
 * Mirella quotes; Fathia / Karine / Samira names only, no photos exist
 * for Karine / Samira), Sponsors (11 logos, Drive "Sponsors" folder),
 * Partenaires (3 logos, Drive "Partenaires" folder), Comité d'Honneur
 * (4 photos, names only as given), Contact doc (map placeholder).
 * Deleted with no French counterpart: about-preview (Mission story
 * already lives on Home), why-section (fake stats), gallery yoga
 * photos, section Lorem intros, trainer social letters, Evelyn Woods
 * signature. Heading highlights set to FightxLife pink #e00080.
 * Layout and existing CSS untouched except appended .fxl- rules.
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

$pink = '#e00080';

function aBlk(string $type, array $props, array $children = [], array $classes = []): array
{
    $b = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => [], 'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings' => [], 'children' => $children];
    if ($classes !== []) {
        $b['classNames'] = $classes;
    }
    return $b;
}
function aCont(array $children, array $classes): array
{
    return aBlk('core.container', ['direction' => 'vertical', 'gap' => 'none'], $children, $classes);
}
function aTxt(string $content, array $classes = []): array
{
    return aBlk('core.text', ['content' => $content, 'size' => 'base'], [], $classes);
}
function aHdg(string $text, string $level, ?string $highlight, string $pink): array
{
    $props = ['text' => $text, 'level' => $level];
    if ($highlight !== null) {
        $props['highlight'] = $highlight;
        $props['highlight_color'] = $pink;
    }
    return aBlk('core.heading', $props, []);
}
function aImg(int $mediaId, string $alt): array
{
    return aBlk('core.image', ['media' => ['media_id' => $mediaId, 'alt' => $alt], 'rounded' => false]);
}
function aSec(string $label, array $blocks): array
{
    return ['id' => CanonicalDocumentSchema::newSectionId(), 'label' => $label, 'global_ref' => null,
        'layout' => ['columns' => ['base' => 1], 'gap' => 'none', 'padding_y' => ['base' => 'none', 'md' => 'none'], 'width' => 'wide', 'background_token' => 'surface.primary'],
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']], 'blocks' => $blocks];
}

/* ---------- French texts (client docs, verbatim) ---------- */
$mission1 = 'Le projet de Fightxlife est né d’une expérience de vie, un vécu, une histoire, celle de la fondatrice Rezki Sara touchée par le cancer à plusieurs moments de sa vie.';
$missPers = 'A travers une boxe adaptée, il s’agit de réconcilier le patient avec son mental et son corps. Lâcher les coups pour mieux se libérer du cancer. Il s’agit de ne plus subir la maladie, mais bien de se reconstruire à travers la boxe. Reprendre confiance en soi, l’estime de soi mais aussi se valoriser et développer le dépassement de soi . Ainsi en passant par ce sport, nous pouvons y retrouver tous ces bénéfices pour reprendre goût à la vie.';
$missPhys = 'Abattu et peu confiant, le patient n’exerce plus d’effort physique, ce qui engendre une prise de poids mais aussi un affaiblissement musculaire dû à une hospitalisation trop longue et aux traitements de chimiothérapie. Nous proposons donc par la boxe adaptée un renforcement musculaire et une stimulation de la mémoire par les gestes répétitifs.';
$missSoc = 'Se remettre dans la vie sociale et active pour les patients mais aussi créer des liens et des échanges. Établir des relations au sein du groupe et engendrer non seulement de la motivation mais aussi de l’entraide et du soutien. Ne plus être seul face à la maladie, ne plus être isolé.';
$valEgal = <<<'VAL'
Nous faisons en sorte que nos membres disposent des mêmes chances, des mêmes opportunités de développement social, indépendamment de leur origine sociale ou ethnique, de leur sexe, de leur âge, de leur moyens financiers, d’une maladie ou encore d’un éventuel handicap. Il s’agit de permettre à chacun de disposer des mêmes chances pour accéder au monde sportif et a une vie active.
VAL;
$valBien = <<<'VAL'
la santé mentale et physique n'a pas de prix .Dans notre combat, nous reconnaissons qu'un bon moral et une bonne humeur peuvent être des atouts primordiaux nous permettant d'avancer. Pour permettre cela, Figthxlife poursuit l'objectif de favoriser le développement personnel, physique et social de ses membres.
VAL;
$valSol = <<<'VAL'
elle est cette main tendue vers l'autre pour lui rappeler qu'il n'est pas seul dans ce qu'il traverse. Dans un esprit marqué par le respect, tenant compte de l'évolution de chacun selon son propre rythme, l'association Fightxlife souhaite renforcer les liens sociaux et offrir un lieu de rencontres sécurisant, loin de la solitude.
VAL;
$advIntro = <<<'ADV'
Notre advisory board, nous accompagne et nous soutient lors d’évènements importants. Chacun met son expérience et ses compétences au service de la réalisation de projets spécifiques. Les rencontres ont lieu plusieurs fois par an, afin d’établir les diverses stratégies en vue de la réalisation concrète de projet
ADV;
$advSara = <<<'ADV'
En tant que Fondatrice de Fightxlife, il est impératif de nous entourer de personnes de confiance qui soutiennent le projet en mettant à notre disposition leurs expériences et leurs connaissances. Je remercie notre Advisory de nous avoir rejoint dans la lutte contre le cancer.
ADV;
$advMir = <<<'ADV'
Un burn-out, suivi d'un cancer et je pensais qu'il serait difficile de se relever mais heureusement je me trompais. Ma rencontre avec Sara et de ce fait de Fightxlife, la découverte de la boxe, l'échange et la rencontre m'ont amené à m'investir dans ce merveilleux projet. Aujourd’hui, j’ai décidé de rejoindre l'advisory board et de mettre mes compétences au service de l’association.
ADV;

/* ---------- load doc + manifest ---------- */
$doc = frLoadDoc($frApp, $frActor, 41);
[$byOrig, $bySlug] = frManifest();
$med = function (string $orig) use ($byOrig): int {
    if (!isset($byOrig[$orig])) {
        frFail("about media missing: $orig");
    }
    return (int) $byOrig[$orig][0];
};
$idJoel = $med('Joel Jiberot.jpg');
$idNadC = $med('Nadine (comité).jpg');
$idMirC = $med('Mirella (comité).jpg');
$idBali = $med('Bali (comité).jpg');
$idSaraE = $med('Sara (équipe).jpg');
$idMirE = $med('Mirella (équipe).jpg');
$idNafE = $med('Nafissa (équipe).jpg');
$idCorE = $med('Cornelis (équipe).jpg');
$idRomE = $med('Romane (équipe).jpg');
$idIneE = $med('Ines (équipe).jpg');
$idSylE = $med('Sylvia (équipe).jpg');
$idElzE = $med('Elza (équipe).jpg');
$idImaE = $med('Imane (équipe).jpg');
$idSaraA = $med('Sara ( advisory).jpg');
$idMirA = $med('Mirella advisory.jpg');
$idFatA = $med('Fathia (advisory).jpg');
$sponsors = [
    ['loterie-nationale-logo.jpg', 'Loterie Nationale'],
    ['Igor-ni logo NB.jpg', 'Igor-ni'],
    ['Federation bxl LOGO.png', 'Fédération Bruxelles'],
    ['Larocheposay LOGO.png', 'La Roche-Posay'],
    ['ADEPS LOGO.png', 'ADEPS'],
    ['ASIA SPORT LOGO.png', 'Asia Sport'],
    ['LFKBMO LOGO.png', 'LFKBMO'],
    ['CERA LOGO.png', 'CERA'],
    ['Audacity coach LOGO.jpg', 'Audacity Coach'],
    ['Francophone bxl LOGO.png', 'Bruxelles Francophone'],
    ['fondation veronique.jpg', 'Fondation Véronique'],
];
$partenaires = [
    ['St Gilles Gillis LOGO.png', 'Saint-Gilles'],
    ['MOLENBEEK 1080 LOGO.png', 'Molenbeek 1080'],
    ['ADA LOGO.png', 'ADA'],
];
foreach (array_merge($sponsors, $partenaires) as [$f, $alt]) {
    $med($f);
}

/* ---------- section index + markers ---------- */
$secIdx = [];
foreach ($doc['sections'] as $si => $s) {
    $secIdx[$s['label'] ?? ''] = $si;
}
foreach (['page-banner', 'feature-row', 'about-preview', 'why-section', 'gallery-section', 'team-section', 'map-section'] as $lab) {
    if (!isset($secIdx[$lab])) {
        frFail("about section missing: $lab");
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
$checks = ['page-banner' => 'About Us', 'feature-row' => 'Flexible Time', 'about-preview' => 'We Are Giving',
    'why-section' => 'Why Choose Us', 'gallery-section' => 'Yoga Class Gallery', 'team-section' => 'Expert Yoga Trainer',
    'map-section' => 'FightxLife Yoga Studio']; // NOTE: the 2026-10-28 rebrand blanket-replaced 'Yogist' -> 'FightxLife' in all text props, so the batch3 map text 'Yogist Yoga Studio' is now 'FightxLife Yoga Studio' on live p41. The old marker failed the check (FR-PAGE-FAIL line 134).
foreach ($checks as $lab => $needle) {
    if (!$hasText($doc['sections'][$secIdx[$lab]]['blocks'], $needle)) {
        frFail("about section $lab marker not found: $needle");
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
    frFail('about banner-inner not found');
}
$banInner = &frNavRef($doc, array_merge(['sections', $secIdx['page-banner'], 'blocks'], $p));
foreach ($banInner['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Qui Sommes-Nous';
        $b['props']['highlight'] = 'Nous';
        $b['props']['highlight_color'] = $pink;
        $ok++;
    }
}
unset($b);
$p = $findCont($banBlocks, 'breadcrumbs');
if ($p === null) {
    frFail('about breadcrumbs not found');
}
$crumbs = &frNavRef($doc, array_merge(['sections', $secIdx['page-banner'], 'blocks'], $p));
$cb = 0;
foreach ($crumbs['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === 'Home') {
        $b['props']['link']['label'] = 'Accueil';
        $cb++;
    } elseif (($b['type'] ?? '') === 'core.text' && in_array('crumb-current', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Qui Sommes-Nous';
        $cb++;
    }
}
unset($b);
if ($cb !== 2) {
    frFail("about crumbs count=$cb");
}
$ok++;
unset($banInner, $crumbs, $banBlocks);

/* ---------- FEATURES -> VALEURS ---------- */
$featBlocks = &$doc['sections'][$secIdx['feature-row']]['blocks'];
$p = $findCont($featBlocks, 'feature-row');
if ($p === null) {
    frFail('about feature-row not found');
}
$featRow = &frNavRef($doc, array_merge(['sections', $secIdx['feature-row'], 'blocks'], $p));
if (count($featRow['children']) !== 3) {
    frFail('about feature cards count=' . count($featRow['children']));
}
$valeurs = [['L’égalité des chances', $valEgal], ['Le bien-être', $valBien], ['La solidarité', $valSol]];
foreach ($featRow['children'] as $ci => &$card) {
    [$vt, $vp] = $valeurs[$ci];
    $h = 0;
    $t = 0;
    foreach ($card['children'] as &$cb2) {
        if (($cb2['type'] ?? '') === 'core.heading') {
            $cb2['props']['text'] = $vt;
            $h++;
        } elseif (($cb2['type'] ?? '') === 'core.text') {
            $cb2['props']['content'] = $vp;
            $t++;
        }
    }
    unset($cb2);
    if ($h !== 1 || $t !== 1) {
        frFail("about valeur card $ci h=$h t=$t");
    }
    $ok++;
}
unset($card, $featRow, $featBlocks);

/* ---------- GALLERY -> COMITE D'HONNEUR ---------- */
$galBlocks = &$doc['sections'][$secIdx['gallery-section']]['blocks'];
$p = $findCont($galBlocks, 'section-head');
if ($p === null) {
    frFail('about gallery head not found');
}
$galHead = &frNavRef($doc, array_merge(['sections', $secIdx['gallery-section'], 'blocks'], $p));
foreach ($galHead['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Comité d’Honneur';
        $b['props']['highlight'] = 'Honneur';
        $b['props']['highlight_color'] = $pink;
        $ok++;
    }
}
unset($b);
$n = $directDel($galHead['children'], function ($c) {
    return ($c['type'] ?? '') === 'core.text';
});
if ($n !== 2) {
    frFail("about gallery head txt delete count=$n");
}
$ok++;
$p = $findCont($galBlocks, 'gallery-grid');
if ($p === null) {
    frFail('about gallery-grid not found');
}
$galGrid = &frNavRef($doc, array_merge(['sections', $secIdx['gallery-section'], 'blocks'], $p));
$comite = [
    [$idJoel, 'Joel Jiberot, membre du Comité d’Honneur', 'Joel Jiberot'],
    [$idNadC, 'Nadine, membre du Comité d’Honneur', 'Nadine'],
    [$idMirC, 'Mirella, membre du Comité d’Honneur', 'Mirella'],
    [$idBali, 'Bali, membre du Comité d’Honneur', 'Bali'],
];
$comItems = [];
foreach ($comite as [$mid, $alt, $name]) {
    $comItems[] = aCont([aImg($mid, $alt), aTxt($name, ['fxl-comite-name'])], ['fxl-comite-item']);
}
$galGrid['children'] = $comItems;
$ok++;
unset($galHead, $galGrid, $galBlocks);

/* ---------- TEAM -> EQUIPE (9) ---------- */
$teamBlocks = &$doc['sections'][$secIdx['team-section']]['blocks'];
$p = $findCont($teamBlocks, 'section-head');
if ($p === null) {
    frFail('about team head not found');
}
$teamHead = &frNavRef($doc, array_merge(['sections', $secIdx['team-section'], 'blocks'], $p));
foreach ($teamHead['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.text' && in_array('eyebrow', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Qui Sommes-Nous';
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Notre Équipe';
        $b['props']['highlight'] = 'Équipe';
        $b['props']['highlight_color'] = $pink;
        $ok++;
    }
}
unset($b);
$n = $directDel($teamHead['children'], function ($c) {
    return ($c['type'] ?? '') === 'core.text' && !in_array('eyebrow', $c['classNames'] ?? [], true);
});
if ($n !== 1) {
    frFail("about team secP delete count=$n");
}
$ok++;
$p = $findCont($teamBlocks, 'trainer-grid');
if ($p === null) {
    frFail('about trainer-grid not found');
}
$trainerGrid = &frNavRef($doc, array_merge(['sections', $secIdx['team-section'], 'blocks'], $p));
$equipe = [
    [$idSaraE, 'Sara Rezki, fondatrice', 'Sara Rezki', 'Fondatrice'],
    [$idMirE, 'Mirella, administratrice', 'Mirella', 'Administratrice'],
    [$idNafE, 'Nafisa, administratrice', 'Nafisa', 'Administratrice'],
    [$idCorE, 'Valérie Cornelis, attachée de presse', 'Valérie Cornelis', 'Attachée de presse'],
    [$idRomE, 'Romane Bellens, coach sportif', 'Romane Bellens', '3 fois championne européenne WCTS - Initiatrice coach sportif'],
    [$idIneE, 'Inès Miloudi, coach', 'Inès Miloudi', 'Coach Cancerfighter'],
    [$idSylE, 'Sylvia, coach Waterxfight', 'Sylvia', 'Coach Waterxfight'],
    [$idElzE, 'Elza, esthéticienne', 'Elza', 'Esthéticienne'],
    [$idImaE, 'Imane, esthéticienne du regard', 'Imane', 'Esthéticienne du regard'],
];
$eqCards = [];
foreach ($equipe as [$mid, $alt, $name, $role]) {
    $eqCards[] = aCont([aCont([aImg($mid, $alt)], ['trainer-photo']), aTxt($name, ['trainer-name']), aTxt($role, ['trainer-role'])], ['trainer-card']);
}
$trainerGrid['children'] = $eqCards;
$ok++;
unset($teamHead, $trainerGrid, $teamBlocks);

/* ---------- MAP PLACEHOLDER ---------- */
$mapBlocks = &$doc['sections'][$secIdx['map-section']]['blocks'];
$p = $findCont($mapBlocks, 'map-placeholder');
if ($p === null) {
    frFail('about map-placeholder not found');
}
$mapPh = &frNavRef($doc, array_merge(['sections', $secIdx['map-section'], 'blocks'], $p));
$mp = 0;
foreach ($mapPh['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.text' && ($b['props']['content'] ?? '') === 'FightxLife Yoga Studio') { // rebrand-renamed (see marker note above)
        $b['props']['content'] = 'ASBL Fightxlife';
        $mp++;
    } elseif (($b['type'] ?? '') === 'core.text' && strpos($b['props']['content'] ?? '', 'Kuta') !== false) {
        $b['props']['content'] = 'Rue Gabrielle Petit 32, 1080 Molenbeek-Saint-Jean';
        $mp++;
    } elseif (($b['type'] ?? '') === 'core.link') {
        $b['props']['link']['label'] = 'Ouvrir dans Google Maps ↗';
        $b['props']['link']['href'] = 'https://maps.google.com/?q=Rue+Gabrielle+Petit+32+1080+Molenbeek-Saint-Jean';
        $mp++;
    }
}
unset($b);
if ($mp !== 3) {
    frFail("about map placeholder count=$mp");
}
$ok++;
unset($mapPh, $mapBlocks);

/* ---------- NEW SECTIONS: missions / advisory / sponsors / partenaires ---------- */
$missionCards = [];
foreach ([['DÉVELOPPEMENT PERSONNEL', $missPers], ['DÉVELOPPEMENT PHYSIQUE', $missPhys], ['DÉVELOPPEMENT SOCIAL', $missSoc]] as [$mt, $mp2]) {
    $missionCards[] = aCont([aCont([], ['feature-icon']), aHdg($mt, 'h2', null, $pink), aTxt($mp2)], ['feature-card']);
}
$missionsSec = aSec('missions', [
    aCont([aCont([aHdg('Nos Missions', 'h2', 'Missions', $pink)], ['section-head', 'center']),
        aCont($missionCards, ['feature-row', 'container'])], ['content-section', 'container']),
]);
$advCards = [];
$advMembers = [
    [$idSaraA, 'Sara Rezki, membre de l’Advisory Board', 'Sara R.', $advSara],
    [$idMirA, 'Mirella, membre de l’Advisory Board', 'Mirella G.', $advMir],
    [$idFatA, 'Fathia, membre de l’Advisory Board', 'Fathia R.', null],
    [null, null, 'Karine V.', null],
    [null, null, 'Samira R.', null],
];
foreach ($advMembers as [$mid, $alt, $name, $quote]) {
    $kids = [];
    if ($mid !== null) {
        $kids[] = aCont([aImg($mid, $alt)], ['fxl-advisory-photo']);
    }
    $kids[] = aTxt($name, ['fxl-advisory-name']);
    if ($quote !== null) {
        $kids[] = aTxt($quote, ['fxl-advisory-text']);
    }
    $advCards[] = aCont($kids, ['fxl-advisory-card']);
}
$advisorySec = aSec('advisory', [
    aCont([aCont([aHdg('Advisory Board', 'h2', 'Board', $pink)], ['section-head', 'center']),
        aTxt($advIntro), aCont($advCards, ['fxl-advisory-grid'])], ['content-section', 'container']),
]);
$mkLogos = function (array $list) use ($med): array {
    $items = [];
    foreach ($list as [$f, $alt]) {
        $items[] = aCont([aImg($med($f), $alt)], ['fxl-logo-item']);
    }
    return $items;
};
$sponsorsSec = aSec('sponsors', [
    aCont([aCont([aHdg('Nos Sponsors', 'h2', 'Sponsors', $pink)], ['section-head', 'center']),
        aCont($mkLogos($sponsors), ['fxl-logo-grid'])], ['content-section', 'container']),
]);
$partenairesSec = aSec('partenaires', [
    aCont([aCont([aHdg('Nos Partenaires', 'h2', 'Partenaires', $pink)], ['section-head', 'center']),
        aCont($mkLogos($partenaires), ['fxl-logo-grid'])], ['content-section', 'container']),
]);
$ok += 4;

/* ---------- REORDER (drop about-preview + why-section) ---------- */
$doc['sections'] = [
    $doc['sections'][$secIdx['page-banner']],
    $doc['sections'][$secIdx['feature-row']],
    $missionsSec,
    $doc['sections'][$secIdx['gallery-section']],
    $doc['sections'][$secIdx['team-section']],
    $advisorySec,
    $sponsorsSec,
    $partenairesSec,
    $doc['sections'][$secIdx['map-section']],
];

/* ---------- ABOUT CSS: append .fxl- rules ---------- */
$aboutCssKey = null;
$aiIdx = null;
foreach ($doc['sections'] as $si => $s) {
    foreach ($s['blocks'] as $bi => $b) {
        if (($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistAboutIcons') {
            $aboutCssKey = $b['props']['css_key'] ?? null;
            $aiIdx = [$si, $bi];
        }
    }
}
if ($aboutCssKey === null || $aiIdx === null) {
    frFail('about icons island css_key not found');
}
$cssPath = '/var/www/html/plugins/studio-builder/assets/islands/' . $aboutCssKey . '.css';
$oldCss = @file_get_contents($cssPath);
if ($oldCss === false) {
    frFail("about css file missing: $aboutCssKey");
}
$aboutCss = '.fxl-advisory-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;align-items:start}.fxl-advisory-card{background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 16px rgba(0,0,0,.06)}.fxl-advisory-photo img{width:96px;height:96px;object-fit:cover;border-radius:50%}.fxl-advisory-name{font-weight:700;color:#e00080}.fxl-advisory-text{color:#0c0905}.fxl-comite-item{text-align:center}.fxl-comite-item img{aspect-ratio:1;object-fit:cover;border-radius:14px}.fxl-comite-name{font-weight:700;margin-top:12px}.fxl-logo-grid{display:flex;flex-wrap:wrap;gap:32px;align-items:center;justify-content:center}.fxl-logo-item img{max-height:80px;width:auto;object-fit:contain}@media(max-width:1050px){.fxl-advisory-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:780px){.fxl-advisory-grid{grid-template-columns:1fr}}';
$newCssKey = frStoreAsset($oldCss . "\n" . $aboutCss, 'css');
$iconsIsland = &frNavRef($doc, ['sections', $aiIdx[0], 'blocks', $aiIdx[1]]);
$iconsIsland['props']['css_key'] = $newCssKey;
echo "ABOUT-CSS-OLD $aboutCssKey\nABOUT-CSS-NEW $newCssKey\n";
$ok++;
unset($iconsIsland);

/* ---------- SEO ---------- */
if (isset($doc['seo']['title'])) {
    $doc['seo']['title'] = 'Qui Sommes-Nous';
    $ok++;
}
if (isset($doc['seo']['description'])) {
    $doc['seo']['description'] = $mission1;
    $ok++;
}

frValidate($doc, $frRuntime, 'about');
frSaveDraft($frApp, $frActor, 41, $doc);
echo "ABOUT-DONE edits=$ok\n";
