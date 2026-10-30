<?php
/**
 * FightxLife CONTENT BUILD — partials (2026-10-28, on Fahim's word).
 * Header (p9): French nav (Accueil / Qui Sommes-Nous / Cours+dropdown /
 * Actions / Contact), French drawer, real contact, devise tagline.
 * Footer (p10): French footer-card (real address/phone/email, devise),
 * white-bg real logo prop, French link columns, Baliniz credit REMOVED,
 * footer pictures swapped to boxing photos.
 * TOUCHES ONLY: header island asset_key (JS) + footer island asset_key
 * (JS) + footer props logo + footer doc labels/links/pictures/credit.
 * css_key on both islands is PRESERVED (so a prior linkfix2 shell CSS
 * survives, and vice versa). DRAFT ONLY; publish-all runs separately.
 * Reads /tmp/fxl-logo-white.jpg, /tmp/fxl-footer-pic1.jpg,
 * /tmp/fxl-footer-pic2.jpg (docker cp'd from the host).
 */
declare(strict_types=1);

require '/var/www/html/config.php';
$GLOBALS['SLATE_TENANT_OVERRIDE'] = 1;

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Render\IslandAssets;
use Slate\Module\StudioBuilder\StudioPermissions;

$runtime = \Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory::build();
$app = $runtime->app;
$actor = StudioActor::authenticated(1, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);

function fail(string $msg): void
{
    fwrite(STDERR, "PARTIALS-FAIL: $msg\n");
    exit(1);
}

function uploadMedia(string $src, string $stem): array
{
    $bytes = file_get_contents($src);
    if ($bytes === false) {
        fail("cannot read $src");
    }
    $mediaDir = '/var/www/html/uploads/media/2026/10';
    if (!is_dir($mediaDir)) {
        mkdir($mediaDir, 0755, true);
    }
    $final = $stem . '.jpg';
    $i = 0;
    while (file_exists($mediaDir . '/' . $final)) {
        $i++;
        $final = $stem . '-' . $i . '.jpg';
    }
    $full = $mediaDir . '/' . $final;
    if (file_put_contents($full, $bytes) === false) {
        fail("cannot write $full");
    }
    chmod($full, 0644);
    @chown($full, 'www-data');
    @chgrp($full, 'www-data');
    $meta = ['mime' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'original_name' => basename($src)];
    $size = @getimagesize($full);
    if (is_array($size)) {
        $meta['width'] = $size[0];
        $meta['height'] = $size[1];
    }
    $id = \Slate\Services\Media\Media::register('/uploads/media/2026/10/' . $final, $meta);
    echo 'MEDIA-OK id=' . $id . ' url=/uploads/media/2026/10/' . $final . "\n";
    return [$id, '/uploads/media/2026/10/' . $final];
}

function storeAsset(string $content, string $ext): string
{
    $key = IslandAssets::store(1, $content, $ext);
    $p = '/var/www/html/plugins/studio-builder/assets/islands/' . $key . '.' . $ext;
    chmod($p, 0644);
    @chown($p, 'www-data');
    @chgrp($p, 'www-data');
    return $key;
}

// Path-based finder: returns the index path to a block matching $pred.
function findPath(array $blocks, callable $pred, array $prefix = []): ?array
{
    foreach ($blocks as $i => $b) {
        if ($pred($b)) {
            return array_merge($prefix, [$i]);
        }
        if (!empty($b['children']) && is_array($b['children'])) {
            $p = findPath($b['children'], $pred, array_merge($prefix, [$i, 'children']));
            if ($p !== null) {
                return $p;
            }
        }
    }
    return null;
}

function docBlockPath(array $doc, callable $pred): ?array
{
    foreach ($doc['sections'] as $si => $section) {
        if (!empty($section['blocks'])) {
            $p = findPath($section['blocks'], $pred, ['sections', $si, 'blocks']);
            if ($p !== null) {
                return $p;
            }
        }
    }
    return null;
}

function &navRef(array &$doc, array $path): array
{
    $ref = &$doc;
    foreach ($path as $k) {
        $ref = &$ref[$k];
    }
    return $ref;
}

function validateDraft(array $doc, $runtime, string $name): void
{
    $errs = DocumentValidator::validate($doc, $runtime->registry)->errors();
    echo strtoupper($name) . '-ERRORS: ' . count($errs) . "\n";
    foreach ($errs as $e) {
        echo '  ' . json_encode($e) . "\n";
    }
    if (count($errs) > 0) {
        fail("$name validation failed");
    }
}

// ---------- media ----------
[$whiteId, $whiteUrl] = uploadMedia('/tmp/fxl-logo-white.jpg', 'fightxlife-logo-white');
[$pic1Id, $pic1Url] = uploadMedia('/tmp/fxl-footer-pic1.jpg', 'fightxlife-footer-boxing-1');
[$pic2Id, $pic2Url] = uploadMedia('/tmp/fxl-footer-pic2.jpg', 'fightxlife-footer-boxing-2');

// ---------- header JS (French) ----------
$headerJs = <<<'JS'
window.YogistShell = { mount: function (el, props) {
  var logo = (props && props.logo) || '';
  var links = [
    { label: 'Accueil', href: '/yogist-home', key: 'home' },
    { label: 'Qui Sommes-Nous', href: '/about-us', key: 'about' },
    { label: 'Cours', href: '/class', key: 'class', children: [
      { label: 'Boxe Adaptée', href: '/class-detail' }, { label: 'Waterxfight', href: '/cours-waterxfight' }, { label: '100% Féminin', href: '/cours-feminin' } ] },
    { label: 'Actions', href: '/actions', key: 'actions' },
    { label: 'Contact', href: '/contact-us', key: 'contact' }
  ];
  var path = window.location.pathname.replace(/\/$/, '') || '/';
  function isActive(item) {
    if (item.key === 'home') return path === '/' || path === '/yogist-home';
    if (item.key === 'about') return path === '/about-us';
    if (item.key === 'class') return path === '/class' || path === '/class-detail' || path === '/cours-waterxfight' || path === '/cours-feminin';
    if (item.key === 'actions') return path === '/actions';
    if (item.key === 'contact') return path === '/contact-us';
    return false;
  }
  var nav = '<nav class="main-nav" aria-label="Primary navigation">';
  links.forEach(function (item) {
    var active = isActive(item) ? ' active' : '';
    if (item.children) {
      var drop = item.children.map(function (c) {
        return '<a href="' + c.href + '">' + c.label + '</a>';
      }).join('');
      nav += '<div class="nav-item has-dropdown"><a class="nav-link' + active + '" href="' + item.href + '">' + item.label + ' <span class="chevron"></span></a><div class="nav-dropdown">' + drop + '</div></div>';
    } else {
      nav += '<a class="nav-link' + active + '" href="' + item.href + '">' + item.label + '</a>';
    }
  });
  nav += '</nav>';
  var drawerLinks = '<a href="/yogist-home">Accueil</a><a href="/about-us">Qui Sommes-Nous</a><a href="/class">Cours</a><a href="/actions">Actions</a><a href="/contact-us">Contact</a>';
  el.innerHTML = '<div class="header-inner container">'
    + '<a class="brand" href="/yogist-home" aria-label="Accueil FightxLife"><img src="' + logo + '" alt="FightxLife"></a>'
    + nav
    + '<button class="menu-toggle" type="button" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="site-drawer"><span></span><span></span></button>'
    + '</div>'
    + '<div class="drawer-scrim"></div>'
    + '<aside class="site-drawer" id="site-drawer" aria-label="Navigation" aria-hidden="true">'
    + '<button class="drawer-close" type="button" aria-label="Fermer le menu">×</button>'
    + '<a class="drawer-brand" href="/yogist-home"><img src="' + logo + '" alt="FightxLife"></a>'
    + '<p>Avance, encaisse, progresse et esquive</p><nav class="drawer-links">' + drawerLinks + '</nav>'
    + '<div class="drawer-contact"><span>Contact</span><a href="mailto:contact@fightxlife.com">contact@fightxlife.com</a><a href="tel:+32483127298">+32483127298</a></div>'
    + '</aside>';
  var btn = el.querySelector('.menu-toggle');
  var drawer = el.querySelector('.site-drawer');
  var scrim = el.querySelector('.drawer-scrim');
  function setOpen(open) {
    document.body.classList.toggle('menu-open', open);
    if (btn) { btn.setAttribute('aria-expanded', String(open)); btn.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu'); }
    if (drawer) drawer.setAttribute('aria-hidden', String(!open));
  }
  if (btn) btn.addEventListener('click', function () { setOpen(btn.getAttribute('aria-expanded') !== 'true'); });
  if (scrim) scrim.addEventListener('click', function () { setOpen(false); });
  var closeBtn = el.querySelector('.drawer-close');
  if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
  drawer.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { setOpen(false); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
} };
JS;

// ---------- footer JS (French, real contact) ----------
$footerJs = <<<'JS'
window.YogistFooterContact = { mount: function (el, props) {
  var logo = (props && props.logo) || '';
  var pin = '<svg class="icon-svg" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/></svg>';
  var mail = '<svg class="icon-svg" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>';
  var phone = '<svg class="icon-svg" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3H5a2 2 0 0 0-2 2c0 8.8 7.2 16 16 16a2 2 0 0 0 2-2v-2l-5-2-2 2c-3.1-1.2-5.8-3.9-7-7l2-2z"/></svg>';
  el.innerHTML = '<a href="/yogist-home" class="footer-logo"><img src="' + logo + '" alt="FightxLife"></a>'
    + '<p>Avance, encaisse, progresse et esquive</p>'
    + '<ul><li>' + pin + '<span>Rue Gabrielle Petit 32, 1080 Molenbeek-Saint-Jean</span></li>'
    + '<li>' + mail + '<a href="mailto:contact@fightxlife.com">contact@fightxlife.com</a></li>'
    + '<li>' + phone + '<a href="tel:+32483127298">+32483127298</a></li></ul>';
} };
JS;

// ---------- header doc ----------
$loaded = $app->loadEditorDocument($actor, 9);
$headerDoc = $loaded['document'];
$hPath = docBlockPath($headerDoc, fn($b) => ($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistShell');
if ($hPath === null) {
    fail('header YogistShell island not found');
}
$href = &navRef($headerDoc, $hPath);
$oldHJs = $href['props']['asset_key'] ?? '?';
$newHJs = storeAsset($headerJs, 'js');
$href['props']['asset_key'] = $newHJs;
echo "HEADER-JS $oldHJs => $newHJs (css_key preserved: " . ($href['props']['css_key'] ?? '?') . ")\n";
validateDraft($headerDoc, $runtime, 'header');
$status = $app->pageStatus($actor, 9);
$draft = $app->saveDraft($actor, 9, $headerDoc, (int) $status['active_draft_revision_id'], 'manual');
echo 'HEADER-DRAFT page=9 rev=' . (int) ($draft['revision']['id'] ?? 0) . "\n";

// ---------- footer doc ----------
$loaded = $app->loadEditorDocument($actor, 10);
$footerDoc = $loaded['document'];
$fPath = docBlockPath($footerDoc, fn($b) => ($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistFooterContact');
if ($fPath === null) {
    fail('footer YogistFooterContact island not found');
}
$fref = &navRef($footerDoc, $fPath);
$oldFJs = $fref['props']['asset_key'] ?? '?';
$newFJs = storeAsset($footerJs, 'js');
$fref['props']['asset_key'] = $newFJs;
$fref['props']['props_json'] = json_encode(['logo' => $whiteUrl]);
echo "FOOTER-JS $oldFJs => $newFJs logo=$whiteUrl (css_key preserved: " . ($fref['props']['css_key'] ?? '?') . ")\n";

// footer link columns + pictures + credit, found by class
$hasClass = fn(string $c) => fn($b) => in_array($c, $b['classNames'] ?? [], true);

// footer bottom FIRST: Baliniz credit REMOVED, copyright in French;
// the old Copyright block is reused (same id) as the Actions link below.
$botPath = docBlockPath($footerDoc, $hasClass('footer-bottom'));
if ($botPath === null) {
    fail('footer-bottom container not found');
}
$bot = &navRef($footerDoc, $botPath);
$inner = &$bot['children'][0]['children'];
$botSeen = array_map(fn($b) => $b['props']['content'] ?? '?', $inner);
if ($botSeen !== ['Yoga Class Template Kit by Baliniz', 'Copyright © 2022 All rights reserved.']) {
    fail('footer-bottom unexpected content: ' . implode(' | ', $botSeen));
}
$spare = $inner[1];
$inner[0]['props']['content'] = '© FightxLife. Tous droits réservés.';
$inner = [$inner[0]];

// column 1: Other Pages -> Autres Pages + Actions insert
$col1Path = docBlockPath($footerDoc, $hasClass('footer-links'));
if ($col1Path === null) {
    fail('footer-links container not found');
}
$col1 = &navRef($footerDoc, $col1Path);
$cols = $col1['children'] ?? [];
if (count($cols) !== 2) {
    fail('footer-links children count != 2');
}
$c1 = &$cols[0]['children'];
$relabeled = 0;
$newC1 = [];
foreach ($c1 as $blk) {
    if (($blk['type'] ?? '') === 'core.heading' && ($blk['props']['text'] ?? '') === 'Other Pages') {
        $blk['props']['text'] = 'Autres Pages';
        $relabeled++;
    } elseif (($blk['type'] ?? '') === 'core.link') {
        $lbl = $blk['props']['link']['label'] ?? '';
        $map = ['Home' => ['Accueil', '/yogist-home'], 'About Us' => ['Qui Sommes-Nous', '/about-us'], 'Services' => ['Cours', '/class'], 'Contact' => ['Contact', '/contact-us']];
        if (isset($map[$lbl])) {
            $blk['props']['link']['label'] = $map[$lbl][0];
            $blk['props']['link']['href'] = $map[$lbl][1];
            $relabeled++;
            $newC1[] = $blk;
            if ($lbl === 'Services') {
                $spare['type'] = 'core.link';
                $spare['props'] = ['link' => ['label' => 'Actions', 'href' => '/actions', 'target' => '_self'], 'style' => 'plain'];
                $newC1[] = $spare;
            }
            continue;
        }
    }
    $newC1[] = $blk;
}
if ($relabeled !== 5) {
    fail("col1 relabel count $relabeled != 5");
}
$cols[0]['children'] = $newC1;

// column 2: Quick Links -> Liens Rapides, legal links live
$c2 = &$cols[1]['children'];
$seen = [];
foreach ($c2 as $blk) {
    // NOTE (fixed 2026-10-28): core.text stores its text in props['content'],
    // NOT props['text'] (only core.heading uses 'text'). The old line read
    // props['text'] and showed every text block as empty, failing the check
    // even though the footer content was exactly as expected (PARTIALS-FAIL
    // line 106: three empty texts + FAQ link). Read content first.
    $seen[] = ($blk['type'] ?? '') . ':' . ($blk['props']['content'] ?? $blk['props']['text'] ?? $blk['props']['link']['label'] ?? '');
}
$expect = ['core.heading:Quick Links', 'core.text:Privacy Policy', 'core.text:Term Of Service', 'core.text:Disclaimer', 'core.link:FAQ'];
if ($seen !== $expect) {
    fail('col2 unexpected content: ' . implode(' | ', $seen));
}
// In-place transforms: same block ids, only type/props change.
$c2[0]['props']['text'] = 'Liens Rapides';
$c2[1]['type'] = 'core.link';
$c2[1]['props'] = ['link' => ['label' => 'Politique de Confidentialité', 'href' => '/politique-confidentialite', 'target' => '_self'], 'style' => 'plain'];
$c2[2]['type'] = 'core.link';
$c2[2]['props'] = ['link' => ['label' => 'Presse', 'href' => '/presse', 'target' => '_self'], 'style' => 'plain'];
$c2[3]['type'] = 'core.link';
$c2[3]['props'] = ['link' => ['label' => 'Nous Soutenir', 'href' => '/nous-soutenir', 'target' => '_self'], 'style' => 'plain'];
$c2[4]['type'] = 'core.text';
$c2[4]['props'] = ['content' => 'Mentions Légales', 'size' => 'base'];
$col1['children'] = $cols;

// footer pictures: yoga -> boxing
$picPath = docBlockPath($footerDoc, $hasClass('footer-pictures'));
if ($picPath === null) {
    fail('footer-pictures container not found');
}
$pics = &navRef($footerDoc, $picPath);
if (count($pics['children'] ?? []) !== 2) {
    fail('footer-pictures children count != 2');
}
$pics['children'][0]['props']['media']['media_id'] = $pic1Id;
$pics['children'][0]['props']['media']['alt'] = 'Boxeuses frappant un sac de frappe';
$pics['children'][1]['props']['media']['media_id'] = $pic2Id;
$pics['children'][1]['props']['media']['alt'] = 'Boxeur en entraînement sur un sac lourd';

// footer bottom already handled above (Baliniz removed, spare reused).

validateDraft($footerDoc, $runtime, 'footer');
$status = $app->pageStatus($actor, 10);
$draft = $app->saveDraft($actor, 10, $footerDoc, (int) $status['active_draft_revision_id'], 'manual');
echo 'FOOTER-DRAFT page=10 rev=' . (int) ($draft['revision']['id'] ?? 0) . "\n";
echo "PARTIALS-DONE\n";
