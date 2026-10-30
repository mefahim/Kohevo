<?php
/**
 * FightxLife PIXEL BATCH 3 — advisory cards + hover zooms.
 * All rules land in the GLOBAL shell CSS (header partial's css_key),
 * so one header draft + publish covers all pages. Shell CSS key is read
 * DYNAMICALLY from the YogistShell island. Marker-guarded (idempotent).
 *
 * 1. Advisory cards mirror the testimonial card treatment fixed in batch 1
 *    (same white quote-card component family): grid gap 24px -> 22px,
 *    3-col restored on tablets 781-1050px, card padding 29/26/24 +
 *    shadow 0 8px 25px rgba(40,23,65,.055), quote text #888 14px/1.75.
 *    Names stay pink (same open question as testimonial names).
 * 2. Trainer-photo hover zoom (clone: scale 1.04) was missing even though
 *    the img already carries the .35s transition.
 * 3. Gallery img hover zoom (clone: scale 1.04, transition .3s) was missing
 *    entirely (no transition, no hover rule).
 * Class-photo hover (1.035) already matches the clone — untouched.
 * Publish separately via build-fr-publish-one.php 9.
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

$doc = frLoadDoc($frApp, $frActor, 9);

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

if (strpos($css, 'PIXEL-BATCH3B') !== false) {
    echo "PIXEL-BATCH3B-ALL-APPLIED\n";
    exit(0);
}

$css .= "\n/* PIXEL-BATCH3B: advisory cards mirror testimonial cards; hover zooms (see pixel-audit-2026-10-28.md). */\n"
    . ".sb-body .fxl-advisory-grid.fxl-advisory-grid{gap:22px}\n"
    . "@media(min-width:781px) and (max-width:1050px){.sb-body .fxl-advisory-grid.fxl-advisory-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}\n"
    . ".sb-body .fxl-advisory-card.fxl-advisory-card{padding:29px 26px 24px;box-shadow:0 8px 25px rgba(40,23,65,.055)}\n"
    . ".sb-body .fxl-advisory-text.fxl-advisory-text{color:#888;font-size:14px;line-height:1.75}\n"
    . ".sb-body .trainer-photo.trainer-photo:hover img{transform:scale(1.04)}\n"
    . ".sb-body .gallery-grid.gallery-grid img{transition:transform .3s}\n"
    . ".sb-body .gallery-grid.gallery-grid img:hover{transform:scale(1.04)}\n";
echo "APPLIED PIXEL-BATCH3B\n";

$newKey = frStoreAsset($css, 'css');
echo "SHELL-CSS-NEW $newKey\n";

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
echo "PIXEL-BATCH3B-DONE\n";
