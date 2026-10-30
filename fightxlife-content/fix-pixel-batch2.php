<?php
/**
 * FightxLife PIXEL BATCH 2 — icon tint, contact h3, map radius.
 * All rules land in the GLOBAL shell CSS (header partial's css_key),
 * so one header draft + publish covers all pages. Shell CSS key is read
 * DYNAMICALLY from the YogistShell island. Marker-guarded (idempotent).
 *
 * 1. ICON-BG-FIX: .feature-icon + .contact-icon still carry the clone's
 *    pale lavender #f0e6ff — the rebrand color map missed it (it mapped
 *    #f6f0ff/#f5efff/#f4ebff/#f7f2ff but not #f0e6ff). FightxLife is pink,
 *    so the pale tint follows the brand: #ffe9f5 (the rebrand's pale-pink
 *    equivalent). Also drops the extra margin-top:3px on .contact-icon
 *    (clone correction has none) and sets its icon-svg to 27px (clone).
 * 2. CONTACT-H3-FIX: live card h3 is 21px/margin-bottom 12px, no border;
 *    clone screenshot-correction is 23px + padding-bottom 18px +
 *    margin-bottom 24px + 1px #dedede bottom border.
 * 3. MAP-RADIUS-FIX: live map iframe radius 14px; clone 13px.
 * DELIBERATELY NOT CHANGED: testimonial names stay pink (awaiting Fahim's
 * call); contact links stay pink (brand); no global body-color override.
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

if (strpos($css, 'PIXEL-BATCH2') !== false) {
    echo "PIXEL-BATCH2-ALL-APPLIED\n";
    exit(0);
}

$css .= "\n/* PIXEL-BATCH2: clone-measured corrections (see pixel-audit-2026-10-28.md). */\n"
    . ".sb-body .feature-icon.feature-icon,.sb-body .contact-icon.contact-icon{background:#ffe9f5}\n"
    . ".sb-body .contact-icon.contact-icon{margin-top:0}\n"
    . ".sb-body .contact-icon.contact-icon .icon-svg{width:27px;height:27px}\n"
    . ".sb-body .contact-info-card.contact-info-card h3{padding-bottom:18px;margin:0 0 24px;border-bottom:1px solid #dedede;font-size:23px}\n"
    . ".sb-body .map-embed-slot.map-embed-slot iframe{border-radius:13px}\n";
echo "APPLIED PIXEL-BATCH2\n";

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
echo "PIXEL-BATCH2-DONE\n";
