<?php
declare(strict_types=1);
/**
 * PageForge store E2E — CLI only, real MariaDB, stub platform surface.
 *
 * Verifies the Phase 4 store behavior end to end: the pre-Phase-4 schema
 * migration (kind/part columns), page kinds, published theme parts,
 * published popups, global-widget expansion at publish, and dependent
 * recompilation when a global is re-published. Uses a scratch database
 * (pageforge_e2e) that it drops and recreates.
 *
 * The Database / current_tenant_id stubs mirror the exact static surface
 * PageForgeStore calls (get/rows/query), backed by a real PDO connection.
 * Exit code 0 = all checks passed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$GLOBALS['__tid'] = 1;
function current_tenant_id(): int
{
    return (int) $GLOBALS['__tid'];
}

final class Database
{
    public static ?PDO $pdo = null;

    public static function get(): PDO
    {
        return self::$pdo;
    }

    public static function rows(string $sql, array $params = []): array
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function query(string $sql, array $params = []): int
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if (!$ok) $fail++;
};

$root = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS pageforge_e2e');
$root->exec('CREATE DATABASE pageforge_e2e CHARACTER SET utf8mb4');
Database::$pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=pageforge_e2e', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Pre-Phase-4 schema: pages table WITHOUT kind/part (migration test bed),
// revisions + compilations from the current install.sql.
$install = file_get_contents(dirname(__DIR__) . '/install.sql');
$installNoKind = str_replace(["    `kind` ENUM('page','part','popup','global','loop-item','template') NOT NULL DEFAULT 'page',\n", "    `part` VARCHAR(20) NULL DEFAULT NULL,\n"], '', $install);
$installNoKind = preg_replace('/^--.*$/m', '', $installNoKind);
foreach (array_filter(array_map('trim', explode(';', $installNoKind))) as $stmt) {
    Database::$pdo->exec($stmt);
}
$cols = Database::rows("SHOW COLUMNS FROM pageforge_pages LIKE 'kind'");
$check($cols === [], 'scratch schema starts without the kind column (pre-Phase-4)');

require_once dirname(__DIR__) . '/lib/PageForgeStore.php';

// First store write must run the migration.
$headerId = PageForgeStore::createPage('Site Header', '', 1, 'part', 'header');
$cols = Database::rows("SHOW COLUMNS FROM pageforge_pages LIKE 'kind'");
$check($cols !== [], 'ensureSchema migrated pageforge_pages (kind column exists)');
$header = PageForgeStore::getPage($headerId);
$check(($header['kind'] ?? '') === 'part' && ($header['part'] ?? '') === 'header', 'header part stored with kind/part');

PageForgeStore::saveDocument($headerId, ['elements' => [
    ['id' => 'hh', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'HEADER-TEXT']],
]], 1);
PageForgeStore::publish($headerId, 1);
$part = PageForgeStore::getPublishedPart('header');
$check($part !== null && str_contains($part['html'], 'HEADER-TEXT'), 'published header part retrievable');
$check(PageForgeStore::getPublishedPart('footer') === null, 'unpublished footer part is null');

$popupId = PageForgeStore::createPage('Promo Popup', '', 1, 'popup');
PageForgeStore::saveDocument($popupId, ['elements' => [
    ['id' => 'pt', 'elType' => 'widget', 'widgetType' => 'text', 'settings' => ['text' => 'POPUP-TEXT']],
]], 1);
PageForgeStore::publish($popupId, 1);
$popups = PageForgeStore::listPublishedPopups();
$check(count($popups) === 1 && str_contains($popups[0]['html'], 'POPUP-TEXT'), 'published popup listed with compiled html');

$globalId = PageForgeStore::createPage('Shared CTA', '', 1, 'global');
PageForgeStore::saveDocument($globalId, ['elements' => [
    ['id' => 'gh', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'GLOBAL-V1', 'color' => '#123456']],
]], 1);
PageForgeStore::publish($globalId, 1);

$pageId = PageForgeStore::createPage('Landing', '', 1, 'page');
PageForgeStore::saveDocument($pageId, ['elements' => [
    ['id' => 'gw', 'elType' => 'widget', 'widgetType' => 'global-widget', 'settings' => ['global_id' => $globalId]],
    ['id' => 'pb', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => ['text' => 'Open', 'popup_id' => $popupId]],
    ['id' => 'fm', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => ['form_slug' => 'contact', 'form_height' => 520]],
]], 1);
PageForgeStore::publish($pageId, 1);
$pub = PageForgeStore::getPublished('landing');
$check($pub !== null, 'landing page published');
$check($pub !== null && str_contains($pub['html'], 'GLOBAL-V1'), 'global expanded into the page at publish');
$check($pub !== null && str_contains($pub['css'], '#123456'), 'global element CSS compiled into the page CSS');
$check($pub !== null && str_contains($pub['html'], 'data-pf-popup="' . $popupId . '"'), 'button carries the popup trigger');
$check($pub !== null && str_contains($pub['html'], '/forms/contact?embed=1'), 'form widget embeds the Forms route');

// Edit the global and re-publish ONLY the global: dependents must follow.
PageForgeStore::saveDocument($globalId, ['elements' => [
    ['id' => 'gh', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'GLOBAL-V2', 'color' => '#123456']],
]], 1);
PageForgeStore::publish($globalId, 1);
$pub2 = PageForgeStore::getPublished('landing');
$check($pub2 !== null && str_contains($pub2['html'], 'GLOBAL-V2') && !str_contains($pub2['html'], 'GLOBAL-V1'), 'dependent page recompiled when its global was re-published');

// ---- Phase 5: loop-item templates, dynamic binding, mega-menu panels ----
$tplId = PageForgeStore::createPage('Card template', 'card', 1, 'loop-item');
$check((PageForgeStore::getPage($tplId)['kind'] ?? '') === 'loop-item', 'loop-item kind is preserved on create');
PageForgeStore::saveDocument($tplId, ['elements' => [
    ['id' => 'th', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => '{{title}}', 'color' => '#654321']],
]], 1);
PageForgeStore::publish($tplId, 1);

$panelId = PageForgeStore::createPage('Mega panel', 'mega-panel', 1, 'part', 'footer');
PageForgeStore::saveDocument($panelId, ['elements' => [
    ['id' => 'ph', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'PANEL-TEXT']],
]], 1);
PageForgeStore::publish($panelId, 1);

$hostId = PageForgeStore::createPage('Shopfront', 'shopfront', 1, 'page');
PageForgeStore::saveDocument($hostId, ['elements' => [
    ['id' => 'lp', 'elType' => 'widget', 'widgetType' => 'loop', 'settings' => [
        'source' => 'manual', 'template_slug' => 'card', 'count' => 6, 'columns' => '3',
        'items' => [
            ['title' => 'First item', 'text' => '', 'image' => '', 'url' => ''],
            ['title' => 'Second item', 'text' => '', 'image' => '', 'url' => ''],
        ],
    ]],
    ['id' => 'mm', 'elType' => 'widget', 'widgetType' => 'mega-menu', 'settings' => [
        'items' => [['label' => 'Shop', 'url' => 'https://example.com/shop', 'panel_slug' => 'mega-panel']],
    ]],
]], 1);
PageForgeStore::publish($hostId, 1);
$pub5 = PageForgeStore::getPublished('shopfront');
$check($pub5 !== null && str_contains($pub5['html'], 'First item') && str_contains($pub5['html'], 'Second item'), 'loop renders the template once per item with bound titles');
$check($pub5 !== null && !str_contains($pub5['html'], '{{'), 'no binding placeholder survives compilation');
$check($pub5 !== null && str_contains($pub5['css'], '#654321'), 'loop template CSS compiled into the host page CSS');
$check($pub5 !== null && str_contains($pub5['html'], 'PANEL-TEXT'), 'mega-menu panel part rendered into the host page');

// Edit + re-publish ONLY the template: the host compilation must follow.
PageForgeStore::saveDocument($tplId, ['elements' => [
    ['id' => 'th', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'ITEM: {{title}}', 'color' => '#654321']],
]], 1);
PageForgeStore::publish($tplId, 1);
$pub5b = PageForgeStore::getPublished('shopfront');
$check($pub5b !== null && str_contains($pub5b['html'], 'ITEM: First item'), 'host recompiled when its loop template was re-published');

// Phase 6: document version stamp + kit-driven compilation + recompile-all.
$kitPageId = PageForgeStore::createPage('Kit Page', 'kit-page', 1, 'page');
PageForgeStore::saveDocument($kitPageId, ['elements' => [
    ['id' => 'kh', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'Kit page', 'color' => '{{kit.primary}}', 'font_size' => 30, 'font_size_tablet' => 20]],
]], 1);
$kitDoc = PageForgeStore::getDocument($kitPageId);
$check(($kitDoc['version'] ?? '') === PageForgeStore::DOCUMENT_VERSION, 'saved documents carry the document version stamp');
PageForgeKit::setRuntimeOverrides([
    'colors' => ['primary' => '#123abc'],
    'button' => ['background' => '#123abc'],
    'breakpoints' => ['tablet' => 900, 'mobile' => 600],
]);
PageForgeStore::publish($kitPageId, 1);
$pub6 = PageForgeStore::getPublished('kit-page');
$check($pub6 !== null && str_contains($pub6['css'], '--pf-primary: #123abc'), 'published CSS carries the kit variables');
$check($pub6 !== null && str_contains($pub6['css'], 'color: #123abc'), 'published CSS resolves the {{kit.primary}} token');
$check($pub6 !== null && str_contains($pub6['css'], '@media (max-width: 900px)'), 'published CSS uses the kit tablet breakpoint');
PageForgeKit::setRuntimeOverrides(null);
$recount = PageForgeStore::recompileAllPublished(1);
$check($recount >= 1, 'recompileAllPublished recompiles the published set');
$pub6b = PageForgeStore::getPublished('kit-page');
$check($pub6b !== null && str_contains($pub6b['css'], '--pf-primary: ' . PageForgeKit::FALLBACK_ACCENT), 'after clearing overrides, recompiled pages follow the fallback/synced kit');

// Phase 7: checklist reflects real store data; the wizard finish step works end to end.
require_once dirname(__DIR__) . '/lib/PageForgeSetup.php';
$list7 = [];
foreach (PageForgeSetup::checklist() as $item) { $list7[$item['key']] = $item['done']; }
$check(($list7['header'] ?? false) === true, 'checklist: header item done (a header part exists)');
$check(($list7['homepage'] ?? true) === false, 'checklist: homepage item not done before starters run');
$res7 = PageForgeSetup::createStarters('shop', 'Test Co', 1);
$check($res7['created'] === 3 && $res7['home_id'] !== null, 'wizard finish creates the purpose starter pages');
$check(PageForgeStore::getPublished('home') !== null, 'wizard finish publishes the homepage');
$check(PageForgeStore::getPageBySlug('shop') !== null, 'wizard finish creates the shop starter');
$res7b = PageForgeSetup::createStarters('shop', 'Test Co', 1);
$check($res7b['created'] === 0, 'wizard finish is idempotent (no duplicate starters)');
$check(PageForgeSetup::createHeaderPart('Test Co', 1) === null, 'header creation is idempotent (existing part kept)');
$list7b = [];
foreach (PageForgeSetup::checklist() as $item) { $list7b[$item['key']] = $item['done']; }
$check(($list7b['homepage'] ?? false) === true, 'checklist: homepage item done after starters run');

// Phase 8: style depth survives the real store (sanitize -> publish -> compiled output).
$pf8 = PageForgeStore::createPage('PF8 Styles', 'pf8-styles', 1);
PageForgeStore::saveDocument($pf8, ['elements' => [[
    'id' => 'wrap8', 'elType' => 'container',
    'settings' => ['layout_mode' => 'grid', 'grid_columns' => '2', 'background_gradient' => 'linear-gradient(180deg, #111111, #222222)'],
    'elements' => [[
        'id' => 'h8', 'elType' => 'widget', 'widgetType' => 'heading',
        'settings' => ['text' => 'Styled', 'color_hover' => '#334455', 'css_classes' => 'hero-title',
            'entrance_animation' => 'slide-up', 'custom_css' => '& { text-shadow: 1px 1px 2px #000000; }'],
        'elements' => [],
    ]],
]]], 1);
PageForgeStore::publish($pf8, 1);
$pub8 = PageForgeStore::getPublished('pf8-styles');
$check($pub8 !== null && str_contains($pub8['css'], 'display: grid;'), 'phase8 page: grid container in published CSS');
$check($pub8 !== null && str_contains($pub8['css'], 'background-image: linear-gradient(180deg, #111111, #222222);'), 'phase8 page: gradient in published CSS');
$check($pub8 !== null && str_contains($pub8['css'], ':hover { ') && str_contains($pub8['css'], 'color: #334455;'), 'phase8 page: hover ruleset in published CSS');
$check($pub8 !== null && str_contains($pub8['css'], 'animation: pf-slide-up .7s ease both;'), 'phase8 page: entrance animation in published CSS');
$check($pub8 !== null && str_contains($pub8['css'], 'text-shadow: 1px 1px 2px #000000;'), 'phase8 page: scoped custom CSS in published CSS');
$check($pub8 !== null && str_contains($pub8['html'], 'hero-title'), 'phase8 page: reusable class on the rendered element');

// Phase 9: autosave revisions, revision listing, and restore (append-only history).
$pf9Doc = static fn (string $text) => ['elements' => [[
    'id' => 'wrap9', 'elType' => 'container', 'settings' => [],
    'elements' => [[
        'id' => 'h9', 'elType' => 'widget', 'widgetType' => 'heading',
        'settings' => ['text' => $text], 'elements' => [],
    ]],
]]];
$pf9Text = static fn (array $doc) => $doc['elements'][0]['elements'][0]['settings']['text'] ?? '';
$pf9 = PageForgeStore::createPage('PF9 Revisions', 'pf9-revisions', 1);
$revOne = PageForgeStore::saveDocument($pf9, $pf9Doc('PF9-ONE'), 1, 'manual');
PageForgeStore::publish($pf9, 1);
$revAuto = PageForgeStore::saveDocument($pf9, $pf9Doc('PF9-TWO'), 1, 'autosave');
$revs9 = PageForgeStore::listRevisions($pf9);
$check($revs9 !== [] && (int) $revs9[0]['id'] === $revAuto && $revs9[0]['revision_kind'] === 'autosave', 'phase9: revision list is newest-first with the autosave on top');
$check($pf9Text(PageForgeStore::getDocument($pf9)) === 'PF9-TWO', 'phase9: autosave moved the draft');
$pub9 = PageForgeStore::getPublished('pf9-revisions');
$check($pub9 !== null && str_contains($pub9['html'], 'PF9-ONE') && !str_contains($pub9['html'], 'PF9-TWO'), 'phase9: autosave never touches the published compilation');
$countBeforeRestore = count($revs9);
$revBack = PageForgeStore::restoreRevision($pf9, $revOne, 1);
$revs9b = PageForgeStore::listRevisions($pf9);
$check($pf9Text(PageForgeStore::getDocument($pf9)) === 'PF9-ONE', 'phase9: restore rolls the draft back');
$check((int) $revs9b[0]['id'] === $revBack && $revs9b[0]['revision_kind'] === 'rollback', 'phase9: restore is recorded as a rollback revision');
$check(count($revs9b) === $countBeforeRestore + 1 && in_array($revAuto, array_map(static fn ($r) => (int) $r['id'], $revs9b), true), 'phase9: history is appended, never rewritten (autosave revision still listed)');
$threw9 = false;
try { PageForgeStore::restoreRevision($pf9, 999999, 1); } catch (RuntimeException $e) { $threw9 = true; }
$check($threw9, 'phase9: restoring a missing revision throws instead of wiping the draft');

// Phase 10: display conditions (W3), 404 part, popup config + conditioned embedding (W4).
$pf10Doc = static fn (string $text) => ['elements' => [[
    'id' => 'wrap10', 'elType' => 'container', 'settings' => [],
    'elements' => [[
        'id' => 'h10', 'elType' => 'widget', 'widgetType' => 'heading',
        'settings' => ['text' => $text], 'elements' => [],
    ]],
]]];
$ctxLanding = ['route' => 'page', 'slug' => 'landing', 'kind' => 'page'];
$ctxOther = ['route' => 'page', 'slug' => 'other', 'kind' => 'page'];
$hdrA = PageForgeStore::createPage('PF10 Header A', 'pf10-hdr-a', 1, 'part', 'header');
PageForgeStore::saveDocument($hdrA, $pf10Doc('PF10-HDR-A'), 1);
PageForgeStore::publish($hdrA, 1);
$hdrB = PageForgeStore::createPage('PF10 Header B', 'pf10-hdr-b', 1, 'part', 'header');
PageForgeStore::saveDocument($hdrB, $pf10Doc('PF10-HDR-B'), 1);
PageForgeStore::setConditions($hdrB, ['include' => [['scope' => 'slug', 'value' => 'landing']]]);
PageForgeStore::publish($hdrB, 1);
$condB = PageForgeStore::pageConditions(PageForgeStore::getPage($hdrB));
$check(($condB['include'][0]['scope'] ?? '') === 'slug' && ($condB['include'][0]['value'] ?? '') === 'landing', 'phase10: conditions round-trip through the store');
$pickLanding = PageForgeStore::getPublishedPartFor('header', $ctxLanding);
$check($pickLanding !== null && str_contains($pickLanding['html'], 'PF10-HDR-B'), 'phase10: slug-conditioned header wins on its page');
$pickOther = PageForgeStore::getPublishedPartFor('header', $ctxOther);
$check($pickOther !== null && str_contains($pickOther['html'], 'PF10-HDR-A'), 'phase10: unconditional header serves the other pages');
PageForgeStore::setConditions($hdrA, ['exclude' => [['scope' => 'slug', 'value' => 'other']]]);
$pickOther2 = PageForgeStore::getPublishedPartFor('header', $ctxOther);
$check($pickOther2 === null || !str_contains($pickOther2['html'], 'PF10-HDR-A'), 'phase10: excluded header is never selected for that slug');
$nf10 = PageForgeStore::createPage('PF10 NotFound', 'pf10-nf', 1, 'part', '404');
PageForgeStore::saveDocument($nf10, $pf10Doc('PF10-NOTFOUND'), 1);
PageForgeStore::publish($nf10, 1);
$pick404 = PageForgeStore::getPublishedPartFor('404', ['route' => 'notfound', 'slug' => 'nope', 'kind' => '']);
$check($pick404 !== null && str_contains($pick404['html'], 'PF10-NOTFOUND'), 'phase10: 404 part is selected for the notfound context');
$single10 = PageForgeStore::createPage('PF10 Single', 'pf10-single', 1, 'part', 'single');
$check((PageForgeStore::getPage($single10)['part'] ?? '') === 'single', 'phase10: new part types (single) are storable');
$pop10 = PageForgeStore::createPage('PF10 Popup', 'pf10-popup', 1, 'popup');
PageForgeStore::saveDocument($pop10, $pf10Doc('PF10-POPUP'), 1);
$cfg10 = PageForgeStore::setPopupConfig($pop10, ['settings' => ['width' => 99999, 'position' => 'sideways'], 'rules' => ['max_shows' => 3]]);
$check($cfg10['settings']['width'] === 1200 && $cfg10['settings']['position'] === 'center' && $cfg10['rules']['max_shows'] === 3, 'phase10: popup config is normalized on save (clamps + enums)');
PageForgeStore::publish($pop10, 1);
$findPop = static function (array $list, string $slug) {
    foreach ($list as $pop) { if (($pop['page']['slug'] ?? '') === $slug) { return $pop; } }
    return null;
};
$popOnLanding = $findPop(PageForgeStore::listPublishedPopups($ctxLanding), 'pf10-popup');
$check($popOnLanding !== null && ($popOnLanding['config']['settings']['width'] ?? 0) === 1200, 'phase10: popup is embedded with its normalized config');
PageForgeStore::setConditions($pop10, ['exclude' => [['scope' => 'slug', 'value' => 'landing']]]);
$check($findPop(PageForgeStore::listPublishedPopups($ctxLanding), 'pf10-popup') === null, 'phase10: condition-excluded popup is not embedded on that page');
$check($findPop(PageForgeStore::listPublishedPopups($ctxOther), 'pf10-popup') !== null, 'phase10: the same popup still embeds on other pages');

// Phase 11: dynamic tags at publish, template save/insert (W5), page export/import.
$src11 = PageForgeStore::createPage('PF11 Source', 'pf11-source', 1);
PageForgeStore::saveDocument($src11, ['elements' => [[
    'id' => 's11wrap', 'elType' => 'container', 'settings' => [],
    'elements' => [[
        'id' => 's11h', 'elType' => 'widget', 'widgetType' => 'heading',
        'settings' => ['text' => 'PF11-SRC {{page.title}} {{date.year}}'], 'elements' => [],
    ]],
]]], 1);
$pub11 = PageForgeStore::publish($src11, 1);
$check(str_contains($pub11['html'], 'PF11-SRC PF11 Source ' . date('Y')), 'phase11: dynamic tags resolve in the published compilation');
PageForgeStore::setConditions($src11, ['include' => [['scope' => 'slug', 'value' => 'landing']]]);
$tpl11 = PageForgeStore::saveAsTemplate($src11, 'PF11 Template', 1);
$check((PageForgeStore::getPage($tpl11)['kind'] ?? '') === 'template', 'phase11: save-as-template creates a template page');
$tgt11 = PageForgeStore::createPage('PF11 Target', 'pf11-target', 1);
PageForgeStore::saveDocument($tgt11, ['elements' => []], 1);
PageForgeStore::insertTemplate($tgt11, $tpl11, 1);
$tgtDoc = PageForgeStore::getDocument($tgt11);
$tplDoc = PageForgeStore::getDocument($tpl11);
$check(str_contains((string) json_encode($tgtDoc), 'PF11-SRC'), 'phase11: inserted template content lands in the target draft');
$idsOf = static function (array $els) use (&$idsOf): array {
    $ids = [];
    foreach ($els as $el) {
        if (isset($el['id'])) { $ids[] = (string) $el['id']; }
        if (!empty($el['elements']) && is_array($el['elements'])) { $ids = array_merge($ids, $idsOf($el['elements'])); }
    }
    return $ids;
};
$check(array_intersect($idsOf($tgtDoc['elements']), $idsOf($tplDoc['elements'])) === [], 'phase11: inserted elements carry fresh ids (no collisions)');
$pkg11 = PageForgeStore::exportPage($src11);
$check(($pkg11['format'] ?? '') === 'pageforge-page', 'phase11: export produces a pageforge-page package');
$imp11 = PageForgeStore::importPage(json_decode((string) json_encode($pkg11), true), 1);
$impPage = PageForgeStore::getPage($imp11);
$check($impPage !== null && $impPage['slug'] !== 'pf11-source', 'phase11: import creates a new page with a unique slug');
$check(json_encode(PageForgeRenderer::normalizeDocument(PageForgeStore::getDocument($imp11))) === json_encode(PageForgeRenderer::normalizeDocument(PageForgeStore::getDocument($src11))), 'phase11: imported document matches the source document');
$check((PageForgeStore::pageConditions($impPage)['include'][0]['value'] ?? '') === 'landing', 'phase11: display conditions travel with the package');
$impPop = PageForgeStore::importPage(PageForgeStore::exportPage($pop10), 1);
$check((PageForgeStore::popupConfig(PageForgeStore::getPage($impPop))['settings']['width'] ?? 0) === 1200, 'phase11: popup config travels with the package');

// Tenant isolation.
$GLOBALS['__tid'] = 2;
$check(PageForgeStore::getPublished('landing') === null, 'another tenant cannot read the page');
$check(PageForgeStore::getPublishedPart('header') === null, 'another tenant cannot read the part');
$check(PageForgeStore::listPublishedPopups() === [], 'another tenant sees no popups');
$GLOBALS['__tid'] = 1;

echo PHP_EOL . ($fail === 0 ? 'STORE E2E OK' : "STORE E2E FAILED ($fail)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
