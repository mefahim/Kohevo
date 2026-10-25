<?php
declare(strict_types=1);
/**
 * PageForge Phase 12 — final gate harness (CLI only, real MariaDB).
 *
 * Three legs, one scratch install:
 *  1. Fresh-tenant E2E — the FULL install.sql is applied to an empty
 *     database and a tenant that has never existed (id 7) runs the whole
 *     lifecycle: kinds, parts, popups, conditions, templates,
 *     export/import, revision restore, autosave — with tenant isolation
 *     against a second tenant (id 9).
 *  2. Reference builds — four representative Elementor-style pages built
 *     as original PageForge documents through the real Store, published,
 *     and probed for structural markers (nested containers, grid mode,
 *     responsive CSS, kit tokens, loop binding, popup triggers).
 *  3. Verdict — exit code 0 only when every check passed.
 *
 * The Database / current_tenant_id stubs mirror the exact static surface
 * PageForgeStore calls, backed by a real PDO connection (same pattern as
 * tools/store-e2e.php). Scratch DB: pageforge_gate (dropped at the end
 * on success, kept on failure for inspection).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$GLOBALS['__tid'] = 7;
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
$pass = 0;
$check = static function (bool $ok, string $label) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
};

$root = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS pageforge_gate');
$root->exec('CREATE DATABASE pageforge_gate CHARACTER SET utf8mb4');
Database::$pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=pageforge_gate', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Fresh install: apply the shipped install.sql verbatim (comments stripped).
$install = (string) file_get_contents(dirname(__DIR__) . '/install.sql');
$install = (string) preg_replace('/^--.*$/m', '', $install);
foreach (array_filter(array_map('trim', explode(';', $install))) as $stmt) {
    Database::$pdo->exec($stmt);
}
$tables = array_column(Database::rows('SHOW TABLES'), 'Tables_in_pageforge_gate');
sort($tables);
$check($tables === ['pageforge_compilations', 'pageforge_pages', 'pageforge_revisions'], 'fresh install: install.sql creates exactly the 3 plugin tables');
$kindCol = Database::rows("SHOW COLUMNS FROM pageforge_pages LIKE 'kind'");
$check($kindCol !== [] && str_contains((string) $kindCol[0]['Type'], "'template'"), 'fresh install: kind ENUM is current (incl. loop-item + template)');
$check((int) (Database::rows('SELECT COUNT(*) AS c FROM pageforge_pages')[0]['c'] ?? -1) === 0, 'fresh install: pages table starts empty for the new tenant');

require_once dirname(__DIR__) . '/lib/PageForgeStore.php';
PageForgeStore::wireGlobalResolver();
PageForgeStore::wireDocumentResolver();

// Element builders (short-hands keep the reference documents readable).
$W = static fn (string $id, string $type, array $settings = []): array => ['id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings];
$C = static fn (string $id, array $settings, array $children): array => ['id' => $id, 'elType' => 'container', 'settings' => $settings, 'elements' => $children];

// ============================ FRESH-TENANT E2E (tenant 7) ============================
$pagesCount = static fn (): int => (int) (Database::rows('SELECT COUNT(*) AS c FROM pageforge_pages WHERE tenant_id = ?', [$GLOBALS['__tid']])[0]['c'] ?? 0);

$fHeader = PageForgeStore::createPage('FT Header', '', 1, 'part', 'header');
PageForgeStore::saveDocument($fHeader, ['elements' => [$W('fh', 'heading', ['text' => 'FT-HEADER'])]], 1);
PageForgeStore::publish($fHeader, 1);
$check(PageForgeStore::getPublishedPart('header') !== null, 'fresh tenant: header part publishes and resolves');

$f404 = PageForgeStore::createPage('FT 404', '', 1, 'part', '404');
PageForgeStore::saveDocument($f404, ['elements' => [$W('f4', 'heading', ['text' => 'FT-NOT-FOUND'])]], 1);
PageForgeStore::publish($f404, 1);
$check(PageForgeStore::getPublishedPartFor('404', ['route' => 'notfound']) !== null, 'fresh tenant: 404 part resolves under the notfound context');

$fPopup = PageForgeStore::createPage('FT Popup', '', 1, 'popup');
PageForgeStore::saveDocument($fPopup, ['elements' => [$W('fp', 'text', ['text' => 'FT-POPUP'])]], 1);
PageForgeStore::setPopupConfig($fPopup, ['settings' => ['position' => 'bottom-right'], 'triggers' => ['on_load' => ['enabled' => true, 'delay' => 5]]]);
PageForgeStore::publish($fPopup, 1);
$fPops = PageForgeStore::listPublishedPopups(['route' => 'page']);
$check(count($fPops) === 1 && str_contains((string) ($fPops[0]['config']['settings']['position'] ?? ''), 'bottom-right'), 'fresh tenant: popup publishes with its config attached');

$fPage = PageForgeStore::createPage('FT Home', 'ft-home', 1, 'page');
PageForgeStore::saveDocument($fPage, ['elements' => [$W('fph', 'heading', ['text' => '{{page.title}} — {{site.name}}'])]], 1);
$fFirstRev = (int) (PageForgeStore::listRevisions($fPage, 1)[0]['id'] ?? 0);
PageForgeStore::publish($fPage, 1);
$fPub = PageForgeStore::getPublished('ft-home');
$check($fPub !== null && str_contains($fPub['html'], 'FT Home'), 'fresh tenant: dynamic {{page.title}} resolved at publish');
$check($fPub !== null && !str_contains($fPub['html'], '{{'), 'fresh tenant: no dynamic placeholder survives compilation');

// Template round-trip on the fresh tenant: save-as, insert into a new
// page, export the package, import it back as a copy.
$fTpl = PageForgeStore::saveAsTemplate($fPage, 'FT Template', 1);
$check((PageForgeStore::getPage($fTpl)['kind'] ?? '') === 'template', 'fresh tenant: saveAsTemplate stores a template-kind page');
$fPage2 = PageForgeStore::createPage('FT Second', 'ft-second', 1, 'page');
PageForgeStore::insertTemplate($fPage2, $fTpl, 1);
$fDoc2 = PageForgeStore::getDocument($fPage2);
$check(count($fDoc2['elements'] ?? []) === 1 && ($fDoc2['elements'][0]['id'] ?? '') !== 'fph', 'fresh tenant: template insert appends rekeyed elements');
$pkg = PageForgeStore::exportPage($fPage);
$check(($pkg['format'] ?? '') === 'pageforge-page', 'fresh tenant: export produces a pageforge-page package');
$impId = PageForgeStore::importPage($pkg, 1);
$impPage = PageForgeStore::getPage($impId);
$check($impPage !== null && ($impPage['slug'] ?? '') !== 'ft-home' && str_contains((string) ($impPage['title'] ?? ''), 'FT Home'), 'fresh tenant: import lands as a uniquified copy');

// Revision restore + autosave semantics on the fresh tenant.
PageForgeStore::saveDocument($fPage, ['elements' => [$W('fph', 'heading', ['text' => 'FT-CHANGED'])]], 1);
$revs = PageForgeStore::listRevisions($fPage, 30);
$manual = array_values(array_filter($revs, static fn ($r) => ($r['revision_kind'] ?? '') === 'manual'));
$check(count($manual) >= 2, 'fresh tenant: page carries multiple manual revisions');
PageForgeStore::restoreRevision($fPage, $fFirstRev, 1);
$fDocBack = PageForgeStore::getDocument($fPage);
$check(str_contains((string) ($fDocBack['elements'][0]['settings']['text'] ?? ''), '{{page.title}}'), 'fresh tenant: revision restore brings back the first manual revision (' . count($manual) . ' manual revisions listed)');
$threw = false;
try {
    PageForgeStore::restoreRevision($fPage, 999999, 1);
} catch (RuntimeException $e) {
    $threw = true;
}
$check($threw, 'fresh tenant: bogus revision restore throws');

// Tenant isolation: tenant 9 sees none of tenant 7's work.
$before = $pagesCount();
$GLOBALS['__tid'] = 9;
$check(PageForgeStore::getPublished('ft-home') === null && PageForgeStore::listPages() === [], 'isolation: tenant 9 sees no published pages or drafts of tenant 7');
$GLOBALS['__tid'] = 7;
$check($pagesCount() === $before && $before >= 7, 'isolation: tenant 7 data intact after the tenant-9 pass');

// ============================ REFERENCE BUILDS ============================
// Theme furniture every reference page assumes.
$rHeader = PageForgeStore::createPage('Ref Header', '', 1, 'part', 'header');
PageForgeStore::saveDocument($rHeader, ['elements' => [
    $C('hdr', ['html_tag' => 'header'], [
        $W('logo', 'heading', ['text' => 'RefBrand', 'html_tag' => 'p']),
        $W('nav', 'mega-menu', ['items' => [
            ['label' => 'Features', 'url' => 'https://example.com/features', 'panel_slug' => ''],
            ['label' => 'Pricing', 'url' => 'https://example.com/pricing', 'panel_slug' => ''],
        ]]),
    ]),
]], 1);
PageForgeStore::publish($rHeader, 1);

$rPopup = PageForgeStore::createPage('Ref Popup', '', 1, 'popup');
PageForgeStore::saveDocument($rPopup, ['elements' => [$W('rpt', 'text', ['text' => 'REF-POPUP-BODY'])]], 1);
PageForgeStore::publish($rPopup, 1);

// ---- Reference 1: SaaS landing (hero, logos strip, 3-col features, stats, testimonial, CTA) ----
$landing = PageForgeStore::createPage('Ref Landing', 'ref-landing', 1, 'page');
PageForgeStore::saveDocument($landing, ['elements' => [
    $C('hero', ['html_tag' => 'section'], [
        $W('kick', 'text', ['text' => 'REF-KICKER']),
        $W('h1', 'heading', ['text' => 'REF-HERO-TITLE', 'html_tag' => 'h1', 'font_size' => 44, 'font_size_tablet' => 32, 'font_size_mobile' => 26]),
        $W('sub', 'text', ['text' => 'REF-HERO-SUB']),
        $W('cta', 'button', ['text' => 'REF-CTA', 'link_url' => 'https://example.com/start', 'popup_id' => $rPopup]),
    ]),
    $C('logos', [], [
        $W('lg1', 'image', ['src' => 'https://example.com/logo1.png', 'alt' => 'Logo 1']),
        $W('lg2', 'image', ['src' => 'https://example.com/logo2.png', 'alt' => 'Logo 2']),
    ]),
    $C('feat', ['layout_mode' => 'grid', 'grid_columns' => 3], [
        $W('f1', 'icon-box', ['icon_name' => 'bolt', 'title' => 'REF-FEAT-1', 'description' => 'Fast by default.']),
        $W('f2', 'icon-box', ['icon_name' => 'shield', 'title' => 'REF-FEAT-2', 'description' => 'Safe by default.']),
        $W('f3', 'icon-box', ['icon_name' => 'chart', 'title' => 'REF-FEAT-3', 'description' => 'Clear by default.']),
    ]),
    $C('stats', [], [
        $W('s1', 'counter', ['end_number' => 1200, 'suffix' => '+', 'title' => 'Customers']),
        $W('s2', 'counter', ['end_number' => 99, 'suffix' => '%', 'title' => 'Uptime']),
    ]),
    $W('quote', 'testimonial', ['quote' => 'REF-QUOTE', 'author' => 'REF-AUTHOR', 'role' => 'CEO', 'rating' => 5]),
    $C('ctaband', [], [
        $W('ctah', 'heading', ['text' => 'REF-CTA-BAND']),
        $W('ctab', 'button', ['text' => 'REF-CTA-BUTTON', 'link_url' => 'https://example.com/go']),
    ]),
]], 1);
PageForgeStore::publish($landing, 1);
$L = PageForgeStore::getPublished('ref-landing');
$check($L !== null, 'ref 1 landing: published');
$check($L !== null && str_contains($L['html'], 'REF-HERO-TITLE') && str_contains($L['html'], 'REF-KICKER') && str_contains($L['html'], 'REF-HERO-SUB'), 'ref 1 landing: hero content renders');
$check($L !== null && str_contains($L['html'], 'REF-FEAT-1') && str_contains($L['html'], 'REF-FEAT-2') && str_contains($L['html'], 'REF-FEAT-3'), 'ref 1 landing: all three feature boxes render');
$check($L !== null && str_contains($L['css'], 'display:grid') || ($L !== null && str_contains($L['css'], 'display: grid')), 'ref 1 landing: feature container compiles to a CSS grid');
$check($L !== null && str_contains($L['html'], '1,200') && str_contains($L['html'], '99'), 'ref 1 landing: counters render their end numbers (locale-formatted)');
$check($L !== null && str_contains($L['html'], 'REF-QUOTE') && str_contains($L['html'], 'REF-AUTHOR'), 'ref 1 landing: testimonial renders');
$check($L !== null && str_contains($L['css'], '1024px') && str_contains($L['css'], '767px'), 'ref 1 landing: responsive breakpoints present in compiled CSS');
$check($L !== null && str_contains($L['html'], 'data-pf-popup="' . $rPopup . '"'), 'ref 1 landing: hero CTA carries the popup trigger');
$check($L !== null && str_contains($L['css'], '--pf-primary'), 'ref 1 landing: kit theme CSS emitted with the page');
$check($L !== null && !str_contains($L['html'], '{{'), 'ref 1 landing: no placeholder survives');

// ---- Reference 2: pricing + FAQ + contact (3 plan columns built from containers, accordion, form) ----
$pricing = PageForgeStore::createPage('Ref Pricing', 'ref-pricing', 1, 'page');
$plan = static fn (string $id, string $name, string $price): array => $C($id, [], [
    $W($id . 'n', 'heading', ['text' => $name, 'html_tag' => 'h3']),
    $W($id . 'p', 'heading', ['text' => $price, 'html_tag' => 'p']),
    $W($id . 'l', 'icon-list', ['items' => [
        ['icon_name' => 'check', 'text' => $name . ' feature A'],
        ['icon_name' => 'check', 'text' => $name . ' feature B'],
    ]]),
    $W($id . 'b', 'button', ['text' => 'Choose ' . $name, 'link_url' => 'https://example.com/buy']),
]);
PageForgeStore::saveDocument($pricing, ['elements' => [
    $W('ph', 'heading', ['text' => 'REF-PRICING-TITLE', 'html_tag' => 'h1']),
    $C('plans', ['layout_mode' => 'grid', 'grid_columns' => 3], [$plan('p1', 'REF-PLAN-BASIC', '$9'), $plan('p2', 'REF-PLAN-PRO', '$29'), $plan('p3', 'REF-PLAN-TEAM', '$79')]),
    $W('faq', 'accordion', ['items' => [
        ['title' => 'REF-FAQ-1', 'content' => 'Answer one.'],
        ['title' => 'REF-FAQ-2', 'content' => 'Answer two.'],
    ]]),
    $W('contact', 'form', ['form_slug' => 'contact', 'form_height' => 520]),
]], 1);
PageForgeStore::publish($pricing, 1);
$P = PageForgeStore::getPublished('ref-pricing');
$check($P !== null && str_contains($P['html'], 'REF-PLAN-BASIC') && str_contains($P['html'], '$29') && str_contains($P['html'], 'REF-PLAN-TEAM'), 'ref 2 pricing: all three plans render with prices');
$check($P !== null && substr_count($P['html'], 'feature A') === 3, 'ref 2 pricing: plan feature lists render per column');
$check($P !== null && str_contains($P['html'], 'REF-FAQ-1') && str_contains($P['html'], '<details'), 'ref 2 pricing: FAQ accordion renders as details elements');
$check($P !== null && str_contains($P['html'], '/forms/contact?embed=1'), 'ref 2 pricing: contact form embeds the Forms route');

// ---- Reference 3: content page (loop of posts, tabs, gallery) ----
$card = PageForgeStore::createPage('Ref Card', 'ref-card', 1, 'loop-item');
PageForgeStore::saveDocument($card, ['elements' => [
    $W('ct', 'heading', ['text' => '{{title}}']),
    $W('cx', 'text', ['text' => '{{text}}']),
]], 1);
PageForgeStore::publish($card, 1);
$blog = PageForgeStore::createPage('Ref Blog', 'ref-blog', 1, 'page');
PageForgeStore::saveDocument($blog, ['elements' => [
    $W('bh', 'heading', ['text' => 'REF-BLOG-TITLE', 'html_tag' => 'h1']),
    $W('posts', 'loop', ['source' => 'manual', 'template_slug' => 'ref-card', 'count' => 6, 'columns' => '3', 'items' => [
        ['title' => 'REF-POST-1', 'text' => 'First excerpt', 'image' => '', 'url' => ''],
        ['title' => 'REF-POST-2', 'text' => 'Second excerpt', 'image' => '', 'url' => ''],
        ['title' => 'REF-POST-3', 'text' => 'Third excerpt', 'image' => '', 'url' => ''],
    ]]),
    $W('topics', 'tabs', ['items' => [
        ['title' => 'REF-TAB-1', 'content' => 'Tab one body'],
        ['title' => 'REF-TAB-2', 'content' => 'Tab two body'],
    ]]),
    $W('shots', 'gallery', ['columns' => '3', 'items' => [
        ['src' => 'https://example.com/a.jpg', 'caption' => 'Shot A'],
        ['src' => 'https://example.com/b.jpg', 'caption' => 'Shot B'],
    ]]),
]], 1);
PageForgeStore::publish($blog, 1);
$B = PageForgeStore::getPublished('ref-blog');
$check($B !== null && str_contains($B['html'], 'REF-POST-1') && str_contains($B['html'], 'REF-POST-2') && str_contains($B['html'], 'REF-POST-3'), 'ref 3 blog: loop renders every post through the card template');
$check($B !== null && str_contains($B['html'], 'Second excerpt'), 'ref 3 blog: loop binds the item text field per item');
$check($B !== null && !str_contains($B['html'], '{{'), 'ref 3 blog: no binding placeholder survives');
$check($B !== null && str_contains($B['html'], 'REF-TAB-1') && str_contains($B['html'], 'Tab two body'), 'ref 3 blog: tabs render labels and panels');
$check($B !== null && substr_count($B['html'], 'example.com/') >= 2 && str_contains($B['html'], 'Shot A'), 'ref 3 blog: gallery renders its images');

// ---- Reference 4: shopfront (mega-menu header, product grid, product view) ----
$shop = PageForgeStore::createPage('Ref Shop', 'ref-shop', 1, 'page');
PageForgeStore::saveDocument($shop, ['elements' => [
    $W('sh', 'heading', ['text' => 'REF-SHOP-TITLE', 'html_tag' => 'h1']),
    $W('grid', 'product-grid', ['limit' => 4, 'columns' => '4', 'button_text' => 'REF-BUY']),
    $W('view', 'product-view', ['product_slug' => 'sample']),
]], 1);
PageForgeStore::publish($shop, 1);
$S = PageForgeStore::getPublished('ref-shop');
$check($S !== null && str_contains($S['html'], 'REF-SHOP-TITLE'), 'ref 4 shop: page renders');
$check($S !== null && str_contains($S['html'], 'REF-BUY'), 'ref 4 shop: product grid renders (live ShopAPI or the labelled sample)');
$check(PageForgeStore::getPublishedPart('header') !== null && str_contains(PageForgeStore::getPublishedPart('header')['html'] ?? '', 'RefBrand'), 'ref 4 shop: theme header part with mega-menu resolves alongside the shop page');

// Cross-cutting: every reference page carries per-element classes and no raw settings leak.
foreach (['ref-landing' => $L, 'ref-pricing' => $P, 'ref-blog' => $B, 'ref-shop' => $S] as $slug => $pub) {
    $check($pub !== null && str_contains($pub['html'], 'pf-el-'), 'cross: ' . $slug . ' elements carry pf-el-* classes');
}

echo PHP_EOL . 'PHASE 12 GATE: ' . $pass . ' passed, ' . $fail . ' failed' . PHP_EOL;
if ($fail === 0) {
    $root->exec('DROP DATABASE IF EXISTS pageforge_gate');
    echo 'GATE OK' . PHP_EOL;
    exit(0);
}
echo 'GATE FAILED — scratch DB pageforge_gate kept for inspection' . PHP_EOL;
exit(1);
