<?php
/**
 * FightxLife PIXEL BATCH 1 — chrome full-bleed + fonts + batch-3 overrides.
 * All three transforms land in the GLOBAL shell CSS (header partial's
 * css_key, loads on every page), so one header draft + publish covers all.
 * The shell CSS key is read DYNAMICALLY from the YogistShell island — never
 * hardcoded — so this script is safe to run before or after any earlier
 * shell-CSS fix. Every transform is marker-guarded (idempotent reruns).
 *
 * 1. CHROME-PADDING-FIX: platform `.sb-site-header,.sb-site-footer
 *    {padding:1rem}` insets both chrome bars 16px + 1px platform borders;
 *    Yogist reference chrome is full-bleed.
 * 2. FONT-FIX: platform vars are Inter body / DM Sans headings; clone is
 *    Karla 400/700 body (15px/1.65) + Syne 400/700 headings. @import MUST be
 *    the first bytes of the file, so it is PREPENDED, not appended.
 * 3. PIXEL-BATCH3: mobile button padding 22px -> 19px (clone 560px rule);
 *    mobile feature-card max-width 390px -> 360px; testimonial grid gap
 *    24px -> 22px; testimonial 3-col restored on tablets 781-1050px
 *    (clone stays 3-col until 780px); testimonial card padding/shadow +
 *    quote text #888 14px/1.75 + min-height 105px (0 on <=780px, per clone);
 *    inner banner sections bg #f6f6f6 (clone .page-banner bg); testimonial
 *    section bg #fff0f7 (pink-mapped clone #f7f2ff). Selectors are
 *    tripled-class (0,3,0)+ so they beat page CSS (0,1,0)/(0,1,1) despite
 *    the shell CSS loading BEFORE page CSS. :has() targets banner /
 *    testimonial sections without touching other sections.
 *    DELIBERATELY NOT CHANGED: testimonial names stay pink (possible
 *    intentional FightxLife branding — flagged for Fahim, not assumed);
 *    no global body-color override (about CSS already sets gray tones
 *    per block; only color-less blocks fall back to platform #111).
 * Publish separately via build-fr-publish-one.php 9.
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

$doc = frLoadDoc($frApp, $frActor, 9);

/* Find the YogistShell island and its current shell CSS key. */
$shellPath = frDocPath($doc, function ($b) {
    return ($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistShell';
});
if ($shellPath === null) {
    frFail('header YogistShell island not found');
}
$shellRef = &frNavRef($doc, $shellPath);
$oldKey = $shellRef['props']['css_key'] ?? '';
if ($oldKey === '') {
    frFail('YogistShell island has no css_key');
}
echo "SHELL-CSS-CURRENT $oldKey\n";

$cssPath = '/var/www/html/plugins/studio-builder/assets/islands/' . $oldKey . '.css';
$css = @file_get_contents($cssPath);
if ($css === false) {
    frFail("shell CSS file missing: $cssPath");
}

$changed = false;

if (strpos($css, 'CHROME-PADDING-FIX') === false) {
    $css .= "\n/* CHROME-PADDING-FIX: platform .sb-site-header,.sb-site-footer{padding:1rem} "
        . "insets BOTH chrome bars 16px from screen edges (plus 1px platform "
        . "borders); the Yogist reference chrome is full-bleed. */\n"
        . ".sb-site-header.sb-site-header,.sb-site-footer.sb-site-footer{padding:0!important;border-top:none!important;border-bottom:none!important}\n";
    echo "APPLIED CHROME-PADDING-FIX\n";
    $changed = true;
}

if (strpos($css, 'FONT-FIX') === false) {
    $import = "@import url('https://fonts.googleapis.com/css2?family=Karla:wght@400;700&family=Syne:wght@400;700&display=swap');\n"
        . "/* FONT-FIX: clone fonts are Karla body + Syne headings; platform ships Inter/DM Sans. */\n";
    $css = $import . $css;
    $css .= "\n/* FONT-FIX: override platform font vars + body line-height (clone 1.65). */\n"
        . ".sb-body.sb-body{--sb-font-body:Karla,Arial,sans-serif;--sb-font-heading:Syne,Arial,sans-serif;line-height:1.65}\n";
    echo "APPLIED FONT-FIX\n";
    $changed = true;
}

if (strpos($css, 'PIXEL-BATCH3') === false) {
    $css .= "\n/* PIXEL-BATCH3: clone-measured corrections (see pixel-audit-2026-10-28.md). */\n"
        . "@media(max-width:560px){.sb-body .button.button a{padding:0 19px}}\n"
        . "@media(max-width:560px){.sb-body .feature-card.feature-card{max-width:360px}}\n"
        . ".sb-body .fxl-temoignages-grid.fxl-temoignages-grid{gap:22px}\n"
        . "@media(min-width:781px) and (max-width:1050px){.sb-body .fxl-temoignages-grid.fxl-temoignages-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}\n"
        . ".sb-body .fxl-temoignage-card.fxl-temoignage-card{padding:29px 26px 24px;box-shadow:0 8px 25px rgba(40,23,65,.055)}\n"
        . ".sb-body .fxl-temoignage-text.fxl-temoignage-text{color:#888;font-size:14px;line-height:1.75;min-height:105px}\n"
        . "@media(max-width:780px){.sb-body .fxl-temoignage-text.fxl-temoignage-text{min-height:0}}\n"
        . ".sb-section:has(.banner-inner){background:#f6f6f6}\n"
        . ".sb-section:has(.fxl-temoignages-grid){background:#fff0f7}\n";
    echo "APPLIED PIXEL-BATCH3\n";
    $changed = true;
}

if (!$changed) {
    echo "PIXEL-BATCH1-ALL-APPLIED\n";
    exit(0);
}

$newKey = frStoreAsset($css, 'css');
echo "SHELL-CSS-NEW $newKey\n";

/* Remap every island whose css_key is the old shell CSS key. */
$paths = [];
$collect = function (array &$blocks, array $prefix) use (&$collect, &$paths, $oldKey) {
    foreach ($blocks as $i => &$b) {
        if (($b['type'] ?? '') === 'core.island' && ($b['props']['css_key'] ?? '') === $oldKey) {
            $paths[] = array_merge($prefix, [$i]);
        }
        if (!empty($b['children']) && is_array($b['children'])) {
            $collect($b['children'], array_merge($prefix, [$i, 'children']));
        }
    }
    unset($b);
};
foreach ($doc['sections'] as $si => &$section) {
    if (!empty($section['blocks'])) {
        $collect($section['blocks'], ['sections', $si, 'blocks']);
    }
}
unset($section);
if (count($paths) === 0) {
    frFail("no island with css_key=$oldKey to remap");
}
foreach ($paths as $p) {
    $ref = &$doc;
    foreach ($p as $k) {
        $ref = &$ref[$k];
    }
    $ref['props']['css_key'] = $newKey;
    unset($ref);
}
echo 'SHELL-CSS-REMAP count=' . count($paths) . "\n";
frValidate($doc, $frRuntime, 'header');
frSaveDraft($frApp, $frActor, 9, $doc);
echo "PIXEL-BATCH1-DONE\n";
