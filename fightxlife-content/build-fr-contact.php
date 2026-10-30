<?php
/**
 * FightxLife CONTENT BUILD — Contact page (p47) to French, DRAFT ONLY.
 * Source of truth: the client's Contact doc (adresse siège social, n°
 * d'entreprise, Rezki Sara, ASBL Fightxlife, tél, email) — every line
 * used exactly once. UI chrome is translated (nav-consistent French):
 * banner, section head, card headings, form labels. The demo Lorem
 * paragraph and the unused 3rd hours line are REMOVED (no French text
 * exists for them — nothing invented). Map island retargeted from Kuta
 * to the siège social. Layout, classes and CSS assets untouched.
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

$formJs = <<<'JS'
window.YogistContactForm = { mount: function (el) {
  el.innerHTML = '<form class="contact-form form-card" data-demo-form><div class="field-grid"><label>Votre nom<input type="text" name="name" placeholder="Votre nom" required></label><label>Votre email<input type="email" name="email" placeholder="Votre email" required></label></div><label>Sujet<input type="text" name="subject" placeholder="Sujet" required></label><label>Votre message<textarea name="message" rows="6" placeholder="Votre message" required></textarea></label><button class="button button-primary" type="submit">Envoyer</button><p class="form-status" aria-live="polite"></p></form>';
  var f = el.querySelector('form');
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!f.reportValidity()) { return; }
    f.querySelector('.form-status').textContent = 'Merci — votre formulaire est prêt. Cet aperçu statique n’envoie aucune donnée à un serveur.';
    f.reset();
  });
} };
JS;

$mapJs = <<<'JS'
window.YogistContactIcons = { mount: function (el) {
  var ic = document.querySelectorAll('.contact-icon');
  var sv = ['<svg class="icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3H5a2 2 0 0 0-2 2c0 8.8 7.2 16 16 16a2 2 0 0 0 2-2v-2l-5-2-2 2c-3.1-1.2-5.8-3.9-7-7l2-2z"/></svg>', '<svg class="icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3.3 2"/></svg>'];
  for (var i = 0; i < ic.length && i < sv.length; i++) { ic[i].innerHTML = sv[i]; }
  el.innerHTML = '<iframe title="Carte — Siège social Fightxlife à Molenbeek-Saint-Jean" loading="lazy" src="https://www.google.com/maps?q=Rue%20Gabrielle%20Petit%2032%2C%201080%20Molenbeek-Saint-Jean&output=embed" referrerpolicy="no-referrer-when-downgrade"></iframe>';
} };
JS;

$doc = frLoadDoc($frApp, $frActor, 47);
$sections = $doc['sections'] ?? [];
if (count($sections) < 3) {
    frFail('contact doc has fewer than 3 sections');
}
// Banner = first section (verified by its h1). Contact section = the one
// holding 'Get in Touch'. Map section = the one holding the map island.
$bannerIdx = 0;
$contactIdx = null;
$mapIdx = null;
foreach ($sections as $si => $s) {
    $found = false;
    $walk = function ($blocks) use (&$walk, &$found) {
        foreach ($blocks as $b) {
            if (($b['props']['text'] ?? '') === 'Get in Touch') {
                $found = true;
            }
            if (!empty($b['children'])) {
                $walk($b['children']);
            }
        }
    };
    $walk($s['blocks'] ?? []);
    if ($found) {
        $contactIdx = $si;
    }
    foreach ($s['blocks'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistContactIcons') {
            $mapIdx = $si;
        }
    }
}
if ($contactIdx === null || $mapIdx === null) {
    frFail("contact/map section not found (contact=$contactIdx map=$mapIdx)");
}
$counts = [];
$hit = function (string $k) use (&$counts) {
    $counts[$k] = ($counts[$k] ?? 0) + 1;
};

// ---------- banner ----------
frWalk($sections[$bannerIdx]['blocks'], function (&$b) use ($hit) {
    if (($b['type'] ?? '') === 'core.heading' && ($b['props']['level'] ?? '') === 'h1' && ($b['props']['text'] ?? '') === 'Contact Us') {
        $b['props']['text'] = 'Contact';
        $b['props']['highlight'] = 'Contact';
        $hit('banner_h1');
    } elseif (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === 'Home') {
        $b['props']['link']['label'] = 'Accueil';
        $hit('banner_home');
    } elseif ((($b['props']['text'] ?? '') === 'Contact Us' || ($b['props']['content'] ?? '') === 'Contact Us')) {
        if (isset($b['props']['text'])) {
            $b['props']['text'] = 'Contact';
        } else {
            $b['props']['content'] = 'Contact';
        }
        $hit('banner_crumb');
    }
});

// ---------- contact section: texts ----------
frWalk($sections[$contactIdx]['blocks'], function (&$b) use ($hit) {
    $t = $b['props']['text'] ?? $b['props']['content'] ?? null;
    if (($b['type'] ?? '') === 'core.heading' && $t === 'Get in Touch') {
        $b['props']['text'] = 'Nous Contacter';
        $b['props']['highlight'] = 'Contacter';
        $hit('head');
    } elseif ($t === 'Contact Us') {
        if (isset($b['props']['text'])) {
            $b['props']['text'] = 'Contact';
        } else {
            $b['props']['content'] = 'Contact';
        }
        $hit('eyebrow');
    } elseif (($b['type'] ?? '') === 'core.heading' && $t === 'Get Consultation') {
        $b['props']['text'] = 'Contact';
        $hit('card1_h');
    } elseif (($b['type'] ?? '') === 'core.heading' && $t === 'Open Hours') {
        $b['props']['text'] = 'ASBL Fightxlife';
        $hit('card2_h');
    } elseif (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === '(+62)81 422 7509') {
        $b['props']['link']['label'] = '+32483127298';
        $b['props']['link']['href'] = 'tel:+32483127298';
        $hit('phone');
    } elseif (($b['type'] ?? '') === 'core.link' && ($b['props']['link']['label'] ?? '') === 'support@domain.com') {
        $b['props']['link']['label'] = 'contact@fightxlife.com';
        $b['props']['link']['href'] = 'mailto:contact@fightxlife.com';
        $hit('email');
    } elseif ($t === 'Jl. Sunset Road No.815') {
        if (isset($b['props']['text'])) {
            $b['props']['text'] = 'Adresse siège social : Rue Gabrielle Petit 32, 1080 Molenbeek-Saint Jean';
        } else {
            $b['props']['content'] = 'Adresse siège social : Rue Gabrielle Petit 32, 1080 Molenbeek-Saint Jean';
        }
        $hit('street');
    } elseif ($t === 'Mon - Fri: 8 AM - 8 PM') {
        if (isset($b['props']['text'])) {
            $b['props']['text'] = 'Numéro d’entreprise : 720.965564';
        } else {
            $b['props']['content'] = 'Numéro d’entreprise : 720.965564';
        }
        $hit('ent');
    } elseif ($t === 'Saturday: 9 AM - 4 PM') {
        if (isset($b['props']['text'])) {
            $b['props']['text'] = 'Rezki Sara';
        } else {
            $b['props']['content'] = 'Rezki Sara';
        }
        $hit('name');
    }
});
$expect = ['banner_h1' => 1, 'banner_home' => 1, 'banner_crumb' => 1, 'head' => 1, 'eyebrow' => 1, 'card1_h' => 1, 'card2_h' => 1, 'phone' => 1, 'email' => 1, 'street' => 1, 'ent' => 1, 'name' => 1];
foreach ($expect as $k => $v) {
    if (($counts[$k] ?? 0) !== $v) {
        frFail("contact replacement count $k = " . ($counts[$k] ?? 0) . " != $v");
    }
}
// Deletions: demo Lorem paragraph + unused 3rd hours line.
$lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.';
$del = frDeleteBlocks($sections[$contactIdx]['blocks'], function ($b) use ($lorem) {
    $t = $b['props']['text'] ?? $b['props']['content'] ?? null;
    return $t === $lorem || $t === 'Sunday: 9 AM - 6 PM';
});
if ($del !== 2) {
    frFail("contact deletions = $del != 2");
}

$doc['sections'] = $sections;

// ---------- form island (French labels) ----------
$formPath = frDocPath($doc, fn($b) => ($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistContactForm');
if ($formPath === null) {
    frFail('contact form island not found');
}
$fref = &frNavRef($doc, $formPath);
$oldFJs = $fref['props']['asset_key'] ?? '?';
$newFJs = frStoreAsset($formJs, 'js');
$fref['props']['asset_key'] = $newFJs;
echo "FORM-JS $oldFJs => $newFJs (css_key preserved)\n";

// ---------- map island (siège social) ----------
$mapPath = frDocPath($doc, fn($b) => ($b['type'] ?? '') === 'core.island' && ($b['props']['entry'] ?? '') === 'YogistContactIcons');
if ($mapPath === null) {
    frFail('contact map island not found');
}
$mref = &frNavRef($doc, $mapPath);
$oldMJs = $mref['props']['asset_key'] ?? '?';
$newMJs = frStoreAsset($mapJs, 'js');
$mref['props']['asset_key'] = $newMJs;
echo "MAP-JS $oldMJs => $newMJs (css_key preserved)\n";

frValidate($doc, $frRuntime, 'contact');
frSaveDraft($frApp, $frActor, 47, $doc);
echo "CONTACT-DONE\n";
