<?php
/**
 * FightxLife CONTENT BUILD — Home page (p40) to French, DRAFT ONLY.
 * Sources (client docs, verbatim): Mission (about-preview), Nos Missions
 * (feature cards), Cours Introduction first sentences (class excerpts),
 * Témoignages (new testimonial section, all 9 quotes), Drive video
 * (new video section). Deleted with no French counterpart: hero Lorem,
 * why-section (fake stats), blog-section (archived articles), section
 * Lorem intros, trainer social letters, yoga list, Evelyn Woods
 * signature. UI chrome translated (nav-consistent French). Heading
 * highlights set to the FightxLife pink #e00080. Layout, classes and
 * existing CSS untouched except appended .fxl- rules to the home CSS.
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

$pink = '#e00080';

/* ---------- local block helpers (same shape as the Yogist builders) ---------- */
function hBlk(string $type, array $props, array $children = [], array $classes = []): array
{
    $b = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => [], 'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings' => [], 'children' => $children];
    if ($classes !== []) {
        $b['classNames'] = $classes;
    }
    return $b;
}
function hCont(array $children, array $classes): array
{
    return hBlk('core.container', ['direction' => 'vertical', 'gap' => 'none'], $children, $classes);
}
function hTxt(string $content, array $classes = []): array
{
    return hBlk('core.text', ['content' => $content, 'size' => 'base'], [], $classes);
}
function hHdg(string $text, string $level, ?string $highlight, string $pink): array
{
    $props = ['text' => $text, 'level' => $level];
    if ($highlight !== null) {
        $props['highlight'] = $highlight;
        $props['highlight_color'] = $pink;
    }
    return hBlk('core.heading', $props, []);
}
function hImg(int $mediaId, string $alt): array
{
    return hBlk('core.image', ['media' => ['media_id' => $mediaId, 'alt' => $alt], 'rounded' => false]);
}
function hIsland(string $assetKey, string $cssKey, string $entry, array $classes = []): array
{
    return hBlk('core.island', ['asset_key' => $assetKey, 'css_key' => $cssKey, 'entry' => $entry,
        'shell' => '<span></span>', 'props_json' => '{}', 'loading' => 'eager'], [], $classes);
}
function hSec(string $label, array $blocks): array
{
    return ['id' => CanonicalDocumentSchema::newSectionId(), 'label' => $label, 'global_ref' => null,
        'layout' => ['columns' => ['base' => 1], 'gap' => 'none', 'padding_y' => ['base' => 'none', 'md' => 'none'], 'width' => 'wide', 'background_token' => 'surface.primary'],
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']], 'blocks' => $blocks];
}

/* ---------- French texts (client docs, verbatim) ---------- */
$mission1 = 'Le projet de Fightxlife est né d’une expérience de vie, un vécu, une histoire, celle de la fondatrice Rezki Sara touchée par le cancer à plusieurs moments de sa vie.';
$mission2 = 'Elle comprend très vite qu’elle doit se battre pour vivre et décide de mettre les gants pour faire face à ce crabe pour se sentir vivante pour extérioriser la colère, cette boule au ventre et se sortir de l’isolement.';
$mission3 = 'C’est à sa 4 ème récidive qu’elle poursuit l’ambition d’offrir aux personnes atteintes du cancer et d’autres maladies une aide et un soutien. Pour ce faire, elle décide de fonder l’ASBL « Fightxlife ».';
$mission4 = 'Un tel nom est évocateur de l’esprit de l’association. En effet, en s’inspirant de sa propre histoire, Sara R. propose d’offrir aux personnes atteintes d’un cancer des cours de boxe adaptée et d’expérimenter ce qu’elle-même a pu connaître à travers la boxe : avancer malgré la maladie, encaisser les coups de la vie, progresser dans le combat et esquiver les coups dure.';
$mission5 = '“Avance,encaisse,progresse et esquive” sont les mots clés de la Boxe';
$missPers = 'A travers une boxe adaptée, il s’agit de réconcilier le patient avec son mental et son corps. Lâcher les coups pour mieux se libérer du cancer. Il s’agit de ne plus subir la maladie, mais bien de se reconstruire à travers la boxe. Reprendre confiance en soi, l’estime de soi mais aussi se valoriser et développer le dépassement de soi . Ainsi en passant par ce sport, nous pouvons y retrouver tous ces bénéfices pour reprendre goût à la vie.';
$missPhys = 'Abattu et peu confiant, le patient n’exerce plus d’effort physique, ce qui engendre une prise de poids mais aussi un affaiblissement musculaire dû à une hospitalisation trop longue et aux traitements de chimiothérapie. Nous proposons donc par la boxe adaptée un renforcement musculaire et une stimulation de la mémoire par les gestes répétitifs.';
$missSoc = 'Se remettre dans la vie sociale et active pour les patients mais aussi créer des liens et des échanges. Établir des relations au sein du groupe et engendrer non seulement de la motivation mais aussi de l’entraide et du soutien. Ne plus être seul face à la maladie, ne plus être isolé.';
$exBoxe = 'C’est une séance de Boxe encadrée par des coachs professionnels qui se déroule en salle pendant 90 minutes.';
$exWater = 'Le Waterfight est une séance de boxe encadrée par des coachs professionnels qui se déroule en piscine pendant 45 minutes dont les mouvements sont inspirés des arts martiaux et des techniques de boxe.';
$exFem = 'Pour répondre à une demande plus que nécessaire, FightxLife a mis en place des cours de kick-boxing encadré par des coachs professionnelles femmes exclusivement réservés aux FEMMES.';
$temTino = 'C’est par une amie que j’ai connu Fightxlife, rejoindre la team m’a permis de me sentir mieux dans mon corps et de rencontrer des personnes de grande qualité qui continuent le combat';
$temGer = 'Les cours de boxe avec FightXlife sont super pour une remise en forme physique. J’y ai rencontré des personnes charmantes et le groupe m’apporte autant un soutien psychologique que physique. Les prof sont hyper pro et on apprend bien toutes les techniques de boxe.';
$temEva = 'Une superbe aventure humaine! Une énorme révélation qui m’apprend à parler de mes émotions et à évacuer mes tensions';
$temMal = 'La boxe, une passion mais avant tout une vraie bouffée d’oxygène et un moyen de me sentir vivante malgré les moments difficiles. Même si parfois mon corps me lâche, c’est ce sport qui me tire vers le haut. Je résiste, je persévère, j’essaie, et surtout je combat la maladie.';
$temYnes = 'La boxe est et a tjs été pour moi le sport par excellence qui me permet d’extérioriser, de me renforcer et me sentir bien dans ma peau' . '<br><br>' . 'Aujourd’hui elle est clairement devenue une thérapie en soi, un besoin et Fightxlife me permet de lui donner une place au quotidien tout en respectant mon corps.';
$temNad = 'En tant que coach il n’a pas été difficile de guider Sara vers ses objectifs, tellement ce petit bout de femme est aussi motivée que déterminée.' . '<br><br>' . 'FightXlife aborde la maladie mais parle aussi de résistance, de courage, d’inclusion, d’humanité et de résilience.' . '<br><br>' . 'Fightxlife parle de Sara mais de beaucoup d’autres également. Ceux et celles qui ont choisi la force plutôt que l’acceptation.';
$temJay = 'Testé par curiosité, j’ai vite adopté le cours de waterxfigh. Sara et Dounia donnent le tempo avec sourire, énergie et professionnalisme. Pour moi, c’est une jolie bulle d’oxygène et de défoulement qui me permet de reprendre le sport en respectant mon rythme. Ça vide la tête et rempli l’esprit de bonnes vibes!';
$temRaph = <<<'RAPH'
Pourquoi je boxe avec Fightxlife !<br><br>C'est les hasards de la vie :<br><br>une simple affiche sur le mur du hall d'entrée de la piscine Victor Boin qui m'a rendu complètement addict à ces cours de boxe.<br><br>"Faites du sport!" ritournelle entendue dès l'annonce de mon cancer. Les traitements m'ont lessivé, le sport a attendu.<br><br>Puis, petit à petit, retour à la piscine, 3 fois par semaine, le matin, une heure de longueurs....Triste et monotone.<br><br>Et un jour d'août, cette affiche qui s'est imposée à mon regard.<br><br>Alors j'ai pris une séance d'essai. Pour voir.... Boxer dans l'eau: jamais entendu parler!<br><br>Depuis, je suis complétement droguée à ces deux séances par semaine! 45 minutes au cours desquelles je mets KO mes soucis, mes tensions et quelques gourmandises ;-)<br><br>Il y a bien sûr le sport  mais il y a aussi cette force, cette flamme d'énergie qu'est Sara.  C'est aussi ce petit groupe qui se retrouve avec bonne humeur et sourires.<br><br>Fightxlife: c'est la force de vivre avec ou après un cancer.<br><br>Au lieu de me lire, vous feriez mieux de nous rejoindre ;-)
RAPH;
$temMar = <<<'MARIA'
"J'avoue! J'ai toujours été une fervente ANTI-SPORT! Et là, après le premier cours de waterxfight, j'étais accro! Prête à revenir chaque jour! Grâce à ce cours, j'ai retrouvé ma joie de vivre et une énergie physique et mentale qui me faisaient défaut depuis plusieurs mois, suite au confinement et à des soucis de santé! Merci de tout coeur à Waterxfight
MARIA;

/* Spaces around <br><br> keep words separated even if a sanitizer ever strips the tags. */
$temYnes = str_replace('<br><br>', ' <br><br> ', $temYnes);
$temNad = str_replace('<br><br>', ' <br><br> ', $temNad);
$temRaph = str_replace('<br><br>', ' <br><br> ', $temRaph);

/* ---------- load doc + manifest ---------- */
$doc = frLoadDoc($frApp, $frActor, 40);
[$byOrig, $bySlug] = frManifest();
$med = function (string $orig) use ($byOrig): int {
    if (!isset($byOrig[$orig])) {
        frFail("home media missing: $orig");
    }
    return (int) $byOrig[$orig][0];
};
$idHero = $med('woman-boxer-hitting-punching-bag-gym-training.jpg');
$idSara = $med('Sara (équipe).jpg');
$idRomane = $med('Romane (équipe).jpg');
$idInes = $med('Ines (équipe).jpg');
$idBoxe = $med('Boxe adapté 1.jpg');
$idWater = $med('Waterfight piscine.JPG');
$idFem = $med('Feminin 1.jpg');
$idTino = $med('Tino (témoignage).jpg');
$idGer = $med('geraldine (témoignage).jpg');
$idEva = $med('Eva (témoignage).jpg');
$idMal = $med('Malika (témoignage).jpg');
$idYnes = $med('Ynes (témoignage).jpg');
$idNad = $med('Nadine (témoignage).jpg');

/* ---------- section index (by label, verified by a content marker) ---------- */
$secIdx = [];
foreach ($doc['sections'] as $si => $s) {
    $secIdx[$s['label'] ?? ''] = $si;
}
foreach (['home-hero', 'feature-row', 'about-preview', 'why-section', 'class-section', 'team-section', 'blog-section', 'newsletter'] as $lab) {
    if (!isset($secIdx[$lab])) {
        frFail("home section missing: $lab");
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
$checks = ['home-hero' => 'Beatifull', 'feature-row' => 'Flexible Time', 'about-preview' => 'We Are Giving',
    'why-section' => 'Why Choose Us', 'class-section' => 'Our Best Classes', 'team-section' => 'Expert Yoga Trainer',
    'blog-section' => 'Blog', 'newsletter' => 'Subscribe To Our Newsletter'];
foreach ($checks as $lab => $needle) {
    if (!$hasText($doc['sections'][$secIdx[$lab]]['blocks'], $needle)) {
        frFail("home section $lab marker not found: $needle");
    }
}
$ok = 0;
$findCont = function (array $blocks, string $cls): ?array {
    $p = frFindPath($blocks, function ($b) use ($cls) {
        return ($b['type'] ?? '') === 'core.container' && in_array($cls, $b['classNames'] ?? [], true);
    });
    return $p;
};

/* ---------- helpers for direct-children edits ---------- */
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
$secBlocks = function (string $label) use (&$doc, $secIdx): array {
    return $doc['sections'][$secIdx[$label]]['blocks'];
};

/* ---------- HERO ---------- */
$heroBlocks = &$doc['sections'][$secIdx['home-hero']]['blocks'];
$p = $findCont($heroBlocks, 'hero-copy');
if ($p === null) {
    frFail('home hero-copy not found');
}
$heroCopy = &frNavRef($doc, array_merge(['sections', $secIdx['home-hero'], 'blocks'], $p));
foreach ($heroCopy['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.heading' && ($b['props']['level'] ?? '') === 'h1') {
        if (strpos($b['props']['text'] ?? '', 'Beatifull') === false) {
            frFail('home hero h1 unexpected');
        }
        $b['props']['text'] = 'Avance, Encaisse,<br>Progresse <span>et Esquive</span>';
        $ok++;
    }
}
unset($b);
$n = $directDel($heroCopy['children'], function ($c) {
    return ($c['type'] ?? '') === 'core.text';
});
if ($n !== 1) {
    frFail("home hero paragraph delete count=$n");
}
$ok++;
$p = $findCont($heroBlocks, 'hero-actions');
if ($p === null) {
    frFail('home hero-actions not found');
}
$heroActions = &frNavRef($doc, array_merge(['sections', $secIdx['home-hero'], 'blocks'], $p));
$heroBtns = 0;
foreach ($heroActions['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.link') {
        if (($b['props']['link']['label'] ?? '') === 'About Us') {
            $b['props']['link']['label'] = 'Qui Sommes-Nous';
            $heroBtns++;
        } elseif (($b['props']['link']['label'] ?? '') === 'Learn More') {
            $b['props']['link']['label'] = 'En Savoir Plus';
            $heroBtns++;
        }
    }
}
unset($b);
if ($heroBtns !== 2) {
    frFail("home hero buttons count=$heroBtns");
}
$ok++;
$p = $findCont($heroBlocks, 'hero-visual');
if ($p === null) {
    frFail('home hero-visual not found');
}
$heroVisual = &frNavRef($doc, array_merge(['sections', $secIdx['home-hero'], 'blocks'], $p));
$heroImg = 0;
foreach ($heroVisual['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.image') {
        $b['props']['media']['media_id'] = $idHero;
        $b['props']['media']['alt'] = 'Boxeuse frappant un sac de frappe en salle de sport';
        $heroImg++;
    }
}
unset($b);
if ($heroImg !== 1) {
    frFail("home hero image count=$heroImg");
}
$ok++;
unset($heroCopy, $heroActions, $heroVisual, $heroBlocks);

/* ---------- FEATURES -> 3 MISSIONS ---------- */
$featBlocks = &$doc['sections'][$secIdx['feature-row']]['blocks'];
$p = $findCont($featBlocks, 'feature-row');
if ($p === null) {
    frFail('home feature-row container not found');
}
$featRow = &frNavRef($doc, array_merge(['sections', $secIdx['feature-row'], 'blocks'], $p));
if (count($featRow['children']) !== 3) {
    frFail('home feature cards count=' . count($featRow['children']));
}
$missions = [['DÉVELOPPEMENT PERSONNEL', $missPers], ['DÉVELOPPEMENT PHYSIQUE', $missPhys], ['DÉVELOPPEMENT SOCIAL', $missSoc]];
foreach ($featRow['children'] as $ci => &$card) {
    if (!in_array('feature-card', $card['classNames'] ?? [], true)) {
        frFail("home feature card $ci class mismatch");
    }
    [$mt, $mp] = $missions[$ci];
    $h = 0;
    $t = 0;
    foreach ($card['children'] as &$cb) {
        if (($cb['type'] ?? '') === 'core.heading') {
            $cb['props']['text'] = $mt;
            $h++;
        } elseif (($cb['type'] ?? '') === 'core.text') {
            $cb['props']['content'] = $mp;
            $t++;
        }
    }
    unset($cb);
    if ($h !== 1 || $t !== 1) {
        frFail("home feature card $ci h=$h t=$t");
    }
    $ok++;
}
unset($card, $featRow, $featBlocks);

/* ---------- ABOUT-PREVIEW -> MISSION ---------- */
$aboutBlocks = &$doc['sections'][$secIdx['about-preview']]['blocks'];
$p = $findCont($aboutBlocks, 'about-image');
if ($p === null) {
    frFail('home about-image not found');
}
$aboutImg = &frNavRef($doc, array_merge(['sections', $secIdx['about-preview'], 'blocks'], $p));
$ai = 0;
foreach ($aboutImg['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.image') {
        $b['props']['media']['media_id'] = $idSara;
        $b['props']['media']['alt'] = 'Sara Rezki, fondatrice de Fightxlife';
        $ai++;
    }
}
unset($b);
if ($ai !== 1) {
    frFail("home about image count=$ai");
}
$ok++;
$p = $findCont($aboutBlocks, 'about-copy');
if ($p === null) {
    frFail('home about-copy not found');
}
$aboutCopy = &frNavRef($doc, array_merge(['sections', $secIdx['about-preview'], 'blocks'], $p));
$plainIdx = null;
foreach ($aboutCopy['children'] as $i => &$b) {
    if (($b['type'] ?? '') === 'core.text' && in_array('eyebrow', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Notre Mission';
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Qui est Fightxlife?';
        $b['props']['highlight'] = 'Fightxlife';
        $b['props']['highlight_color'] = $pink;
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.text' && empty($b['classNames'])) {
        if ($plainIdx !== null) {
            frFail('home about plain txt duplicate');
        }
        $plainIdx = $i;
        $b['props']['content'] = $mission1;
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === 'About Us') {
        $b['props']['link']['label'] = 'Qui Sommes-Nous';
        $ok++;
    }
}
unset($b);
if ($plainIdx === null) {
    frFail('home about plain txt not found');
}
array_splice($aboutCopy['children'], $plainIdx + 1, 0, [hTxt($mission2), hTxt($mission3), hTxt($mission4), hTxt($mission5)]);
$ok++;
$n = $directDel($aboutCopy['children'], function ($c) {
    return ($c['type'] ?? '') === 'core.rich_text';
});
if ($n !== 1) {
    frFail("home about list delete count=$n");
}
$ok++;
$p = $findCont($aboutBlocks, 'signature');
if ($p === null) {
    frFail('home signature not found');
}
$sig = &frNavRef($doc, array_merge(['sections', $secIdx['about-preview'], 'blocks'], $p));
$sn = 0;
foreach ($sig['children'] as &$b) {
    if (in_array('signature-name', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Rezki Sara';
        $sn++;
    } elseif (in_array('signature-role', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Fondatrice';
        $sn++;
    }
}
unset($b);
if ($sn !== 2) {
    frFail("home signature count=$sn");
}
$ok++;
unset($aboutCopy, $sig, $aboutImg, $aboutBlocks);

/* ---------- WHY -> VIDEO SECTION ---------- */
$videoJs = <<<'JS'
window.FightxLifeVideo = { mount: function (el) { el.innerHTML = '<div class="fxl-video-wrap"><iframe title="Vidéo Fightxlife" src="https://drive.google.com/file/d/1BukItDeHsiJOtjKqF7ppGA-D216x2Swb/preview" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>'; } };
JS;
$videoCss = '.fxl-video-inner{padding:48px 0}.fxl-video-wrap{position:relative;padding-top:56.25%;border-radius:14px;overflow:hidden;background:#000}.fxl-video-wrap iframe{position:absolute;inset:0;width:100%;height:100%;border:0}';
$videoJsKey = frStoreAsset($videoJs, 'js');
$videoCssKey = frStoreAsset($videoCss, 'css');
echo "VIDEO-JS $videoJsKey\nVIDEO-CSS $videoCssKey\n";
$videoSec = hSec('video-section', [
    hCont([hCont([hHdg('Vidéo', 'h2', 'Vidéo', $pink)], ['section-head', 'center']),
        hIsland($videoJsKey, $videoCssKey, 'FightxLifeVideo')], ['fxl-video-inner', 'container']),
]);
$doc['sections'][$secIdx['why-section']] = $videoSec;
$ok++;

/* ---------- CLASS -> 3 COURSES ---------- */
$classBlocks = &$doc['sections'][$secIdx['class-section']]['blocks'];
$p = $findCont($classBlocks, 'section-head');
if ($p === null) {
    frFail('home class head not found');
}
$classHead = &frNavRef($doc, array_merge(['sections', $secIdx['class-section'], 'blocks'], $p));
foreach ($classHead['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.text' && in_array('eyebrow', $b['classNames'] ?? [], true)) {
        $b['props']['content'] = 'Cours';
        $ok++;
    } elseif (($b['type'] ?? '') === 'core.heading') {
        $b['props']['text'] = 'Nos Cours';
        $b['props']['highlight'] = 'Cours';
        $b['props']['highlight_color'] = $pink;
        $ok++;
    }
}
unset($b);
$n = $directDel($classHead['children'], function ($c) {
    return ($c['type'] ?? '') === 'core.text' && !in_array('eyebrow', $c['classNames'] ?? [], true);
});
if ($n !== 1) {
    frFail("home class secP delete count=$n");
}
$ok++;
$p = $findCont($classBlocks, 'class-grid');
if ($p === null) {
    frFail('home class-grid not found');
}
$classGrid = &frNavRef($doc, array_merge(['sections', $secIdx['class-section'], 'blocks'], $p));
if (count($classGrid['children']) !== 3) {
    frFail('home class cards count=' . count($classGrid['children']));
}
$courses = [
    [$idBoxe, 'Cours de boxe adaptée en salle', 'Boxe Adaptée', '/class-detail', $exBoxe],
    [$idWater, 'Cours de Waterxfight en piscine', 'Waterxfight', '/cours-waterxfight', $exWater],
    [$idFem, 'Cours de kick-boxing 100% féminin', '100% Féminin', '/cours-feminin', $exFem],
];
foreach ($classGrid['children'] as $ci => &$card) {
    [$mid, $alt, $title, $href, $ex] = $courses[$ci];
    $pi = 0;
    $ti = 0;
    $ei = 0;
    $ri = 0;
    $walkCard = function (&$bs) use (&$walkCard, &$pi, &$ti, &$ei, &$ri, $mid, $alt, $title, $href, $ex) {
        foreach ($bs as &$b) {
            if (($b['type'] ?? '') === 'core.image') {
                $b['props']['media']['media_id'] = $mid;
                $b['props']['media']['alt'] = $alt;
                $pi++;
            } elseif (($b['type'] ?? '') === 'core.link' && in_array('class-title', $b['classNames'] ?? [], true)) {
                $b['props']['link']['label'] = $title;
                $b['props']['link']['href'] = $href;
                $ti++;
            } elseif (($b['type'] ?? '') === 'core.text' && in_array('class-excerpt', $b['classNames'] ?? [], true)) {
                $b['props']['content'] = $ex;
                $ei++;
            } elseif (($b['type'] ?? '') === 'core.link' && in_array('text-link', $b['classNames'] ?? [], true)) {
                $b['props']['link']['label'] = 'Lire la Suite →';
                $b['props']['link']['href'] = $href;
                $ri++;
            }
            if (!empty($b['children'])) {
                $walkCard($b['children']);
            }
        }
        unset($b);
    };
    $walkCard($card['children']);
    if ($pi !== 1 || $ti !== 1 || $ei !== 1 || $ri !== 1) {
        frFail("home class card $ci pi=$pi ti=$ti ei=$ei ri=$ri");
    }
    $ok++;
}
unset($card);
$p = $findCont($classBlocks, 'center-action');
if ($p === null) {
    frFail('home center-action not found');
}
$centerAction = &frNavRef($doc, array_merge(['sections', $secIdx['class-section'], 'blocks'], $p));
$sa = 0;
foreach ($centerAction['children'] as &$b) {
    if (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === 'See More') {
        $b['props']['link']['label'] = 'Voir Plus';
        $sa++;
    }
}
unset($b);
if ($sa !== 1) {
    frFail("home see-more count=$sa");
}
$ok++;
unset($classHead, $classGrid, $centerAction, $classBlocks);

/* ---------- TEAM -> EQUIPE PREVIEW ---------- */
$teamBlocks = &$doc['sections'][$secIdx['team-section']]['blocks'];
$p = $findCont($teamBlocks, 'section-head');
if ($p === null) {
    frFail('home team head not found');
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
    frFail("home team secP delete count=$n");
}
$ok++;
$p = $findCont($teamBlocks, 'trainer-grid');
if ($p === null) {
    frFail('home trainer-grid not found');
}
$trainerGrid = &frNavRef($doc, array_merge(['sections', $secIdx['team-section'], 'blocks'], $p));
if (count($trainerGrid['children']) !== 3) {
    frFail('home trainer cards count=' . count($trainerGrid['children']));
}
$team = [
    [$idSara, 'Sara Rezki, fondatrice', 'Sara Rezki', 'Fondatrice'],
    [$idRomane, 'Romane Bellens, coach', 'Romane Bellens', 'Coach'],
    [$idInes, 'Inès Miloudi, coach', 'Inès Miloudi', 'Coach'],
];
foreach ($trainerGrid['children'] as $ci => &$card) {
    [$mid, $alt, $name, $role] = $team[$ci];
    $n = $directDel($card['children'], function ($c) {
        return in_array('trainer-social', $c['classNames'] ?? [], true);
    });
    if ($n !== 1) {
        frFail("home team card $ci social delete count=$n");
    }
    $pi = 0;
    $ni = 0;
    $ri = 0;
    foreach ($card['children'] as &$cb) {
        if (($cb['type'] ?? '') === 'core.container' && in_array('trainer-photo', $cb['classNames'] ?? [], true)) {
            foreach ($cb['children'] as &$im) {
                if (($im['type'] ?? '') === 'core.image') {
                    $im['props']['media']['media_id'] = $mid;
                    $im['props']['media']['alt'] = $alt;
                    $pi++;
                }
            }
            unset($im);
        } elseif (in_array('trainer-name', $cb['classNames'] ?? [], true)) {
            $cb['type'] = 'core.text';
            $cb['props'] = ['content' => $name, 'size' => 'base'];
            $ni++;
        } elseif (in_array('trainer-role', $cb['classNames'] ?? [], true)) {
            $cb['props']['content'] = $role;
            $ri++;
        }
    }
    unset($cb);
    if ($pi !== 1 || $ni !== 1 || $ri !== 1) {
        frFail("home team card $ci pi=$pi ni=$ni ri=$ri");
    }
    $ok++;
}
unset($card, $teamHead, $trainerGrid, $teamBlocks);

/* ---------- BLOG -> TESTIMONIALS ---------- */
$temoignages = [
    [$temTino, 'Tino', $idTino, 'Portrait de Tino', null],
    [$temGer, 'Geraldine', $idGer, 'Portrait de Geraldine', null],
    [$temEva, 'Eva', $idEva, 'Portrait d’Eva', null],
    [$temMal, 'Malika', $idMal, 'Portrait de Malika', null],
    [$temYnes, 'Ynes', $idYnes, 'Portrait d’Ynes', null],
    [$temNad, 'Nadine Minampala', $idNad, 'Portrait de Nadine Minampala', 'Consultante en entrepreneuriat'],
    [$temJay, 'Jayne', null, null, null],
    [$temRaph, 'Raph', null, null, null],
    [$temMar, 'Maria', null, null, null],
];
$temCards = [];
foreach ($temoignages as [$quote, $name, $pid, $palt, $prole]) {
    $kids = [];
    if ($pid !== null) {
        $kids[] = hCont([hImg($pid, $palt)], ['fxl-temoignage-photo']);
    }
    $kids[] = hTxt($quote, ['fxl-temoignage-text']);
    $kids[] = hTxt($name, ['fxl-temoignage-name']);
    if ($prole !== null) {
        $kids[] = hTxt($prole, ['fxl-temoignage-role']);
    }
    $temCards[] = hCont($kids, ['fxl-temoignage-card']);
}
$temSec = hSec('temoignages', [
    hCont([hCont([hHdg('Témoignages', 'h2', 'Témoignages', $pink)], ['section-head', 'center']),
        hCont($temCards, ['fxl-temoignages-grid'])], ['content-section', 'container']),
]);
$doc['sections'][$secIdx['blog-section']] = $temSec;
$ok++;

/* ---------- NEWSLETTER ---------- */
$nlBlocks = &$doc['sections'][$secIdx['newsletter']]['blocks'];
$p = $findCont($nlBlocks, 'newsletter-inner');
if ($p === null) {
    frFail('home newsletter-inner not found');
}
$nlInner = &frNavRef($doc, array_merge(['sections', $secIdx['newsletter'], 'blocks'], $p));
$p2 = $findCont($nlInner['children'], 'newsletter-copy');
if ($p2 === null) {
    frFail('home newsletter-copy not found');
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
    frFail("home newsletter para delete count=$n");
}
$ok++;
$formJs = <<<'JS'
window.FightxLifeNewsletterForm = { mount: function (el) { el.innerHTML = '<form class="fxl-nl-form"><input class="fxl-nl-input" type="email" name="email" placeholder="Votre adresse email" required><button class="fxl-nl-btn" type="submit">S’inscrire</button><p class="fxl-nl-status" aria-live="polite"></p></form>'; var f = el.querySelector('form'); f.addEventListener('submit', function (e) { e.preventDefault(); if (!f.reportValidity()) { return; } f.querySelector('.fxl-nl-status').textContent = 'Merci — votre inscription est prête. Cet aperçu statique n’envoie aucune donnée à un serveur.'; f.reset(); }); } };
JS;
$formCss = '.fxl-nl-form{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.fxl-nl-input{flex:1;min-width:220px;padding:0 24px;height:60px;border-radius:60px;border:1px solid #e5e5e5;background:#fff}.fxl-nl-btn{height:60px;padding:0 32px;border-radius:60px;border:0;background:#e00080;color:#fff;font-weight:700;cursor:pointer}.fxl-nl-status{width:100%;color:#0c0905}';
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
    frFail("home newsletter island count=$fi");
}
$ok++;
if (isset($doc['sections'][$secIdx['newsletter']]['style']['background']['color'])) {
    $doc['sections'][$secIdx['newsletter']]['style']['background']['color'] = '#ffe9f5';
    $ok++;
}
unset($nlCopy, $nlInner);

/* ---------- HOME CSS: append testimonial rules ---------- */
$homeCssKey = null;
$hiIdx = null;
foreach ($doc['sections'][$secIdx['newsletter']]['blocks'] as $bi => $b) {
    if (($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistHomeIcons') {
        $homeCssKey = $b['props']['css_key'] ?? null;
        $hiIdx = $bi;
    }
}
if ($homeCssKey === null || $hiIdx === null) {
    frFail('home icons island css_key not found');
}
$iconsIsland = &frNavRef($doc, ['sections', $secIdx['newsletter'], 'blocks', $hiIdx]);
$cssPath = '/var/www/html/plugins/studio-builder/assets/islands/' . $homeCssKey . '.css';
$oldCss = @file_get_contents($cssPath);
if ($oldCss === false) {
    frFail("home css file missing: $homeCssKey");
}
$temCss = '.fxl-temoignages-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;align-items:start}.fxl-temoignage-card{background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 16px rgba(0,0,0,.06)}.fxl-temoignage-photo img{width:72px;height:72px;object-fit:cover;border-radius:50%}.fxl-temoignage-text{color:#0c0905}.fxl-temoignage-name{font-weight:700;color:#e00080}.fxl-temoignage-role{color:#a1a1a1}@media(max-width:1050px){.fxl-temoignages-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:780px){.fxl-temoignages-grid{grid-template-columns:1fr}}';
$newCssKey = frStoreAsset($oldCss . "\n" . $temCss, 'css');
$iconsIsland['props']['css_key'] = $newCssKey;
echo "HOME-CSS-OLD $homeCssKey\nHOME-CSS-NEW $newCssKey\n";
$ok++;
unset($iconsIsland, $nlBlocks);

/* ---------- SEO ---------- */
if (isset($doc['seo']['title'])) {
    $doc['seo']['title'] = 'Fightxlife';
    $ok++;
}
if (isset($doc['seo']['description'])) {
    $doc['seo']['description'] = $mission1;
    $ok++;
}

frValidate($doc, $frRuntime, 'home');
frSaveDraft($frApp, $frActor, 40, $doc);
echo "HOME-DONE edits=$ok\n";
