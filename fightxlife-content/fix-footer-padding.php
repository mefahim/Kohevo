<?php
/**
 * FightxLife PIXEL FIX 1 — header + footer full-bleed (v2: header added).
 * Root cause (proven 2026-10-28 from live HTML/CSS): the platform ships
 * `.sb-site-header,.sb-site-footer{padding:1rem}` on the <header> and
 * <footer> elements themselves, so BOTH chrome bars (black .site-header
 * + pink .footer-main + dark .footer-bottom) sit 16px in from the screen
 * edges, plus a 1px platform border above/below. The Yogist reference
 * chrome is full-bleed (clone styles.css: header/footer are direct
 * children of <body>, no wrapper padding); the black header itself is
 * the 2026-10-28 rebrand decision. Page sections are NOT affected: page
 * CSS already overrides .sb-section__inner padding to 0, and .container
 * gutters (32px desktop / 22px mobile) are the intended Yogist design.
 * Fix: append one override to the global shell CSS (header partial's
 * css_key, loads on every page), remap the header island's css_key,
 * save a header DRAFT. Publish separately via build-fr-publish-one.php 9.
 * v2 supersedes v1 (footer-only) before any run — header had the same bug.
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

$OLD_CSS_KEY = 'isl_aeb04270435da17664564aac6346aa89';
$MARKER = 'CHROME-PADDING-FIX';

$cssPath = '/var/www/html/plugins/studio-builder/assets/islands/' . $OLD_CSS_KEY . '.css';
$css = @file_get_contents($cssPath);
if ($css === false) {
    frFail("shell CSS file missing: $cssPath");
}
if (strpos($css, $MARKER) !== false) {
    echo "SHELL-CSS-ALREADY-APPLIED key=$OLD_CSS_KEY\n";
    echo "CHROME-PADDING-DONE\n";
    exit(0);
}

$fix = "\n/* $MARKER: platform .sb-site-header,.sb-site-footer{padding:1rem} "
    . "insets BOTH chrome bars 16px from screen edges (plus 1px platform "
    . "borders); the Yogist reference chrome is full-bleed. Kill the "
    . "wrapper padding and the platform borders. */\n"
    . ".sb-site-header.sb-site-header,.sb-site-footer.sb-site-footer{padding:0!important;border-top:none!important;border-bottom:none!important}\n";
$newKey = frStoreAsset($css . $fix, 'css');
echo "SHELL-CSS-OLD $OLD_CSS_KEY\n";
echo "SHELL-CSS-NEW $newKey\n";

/* Remap every header island whose css_key is the old shell CSS. */
$doc = frLoadDoc($frApp, $frActor, 9);
$paths = [];
$collect = function (array &$blocks, array $prefix) use (&$collect, &$paths, $OLD_CSS_KEY) {
    foreach ($blocks as $i => &$b) {
        if (($b['type'] ?? '') === 'core.island' && ($b['props']['css_key'] ?? '') === $OLD_CSS_KEY) {
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
    frFail("no header island with css_key=$OLD_CSS_KEY");
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
echo "CHROME-PADDING-DONE\n";
