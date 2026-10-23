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
$installNoKind = str_replace(["    `kind` ENUM('page','part','popup','global') NOT NULL DEFAULT 'page',\n", "    `part` VARCHAR(20) NULL DEFAULT NULL,\n"], '', $install);
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

// Tenant isolation.
$GLOBALS['__tid'] = 2;
$check(PageForgeStore::getPublished('landing') === null, 'another tenant cannot read the page');
$check(PageForgeStore::getPublishedPart('header') === null, 'another tenant cannot read the part');
$check(PageForgeStore::listPublishedPopups() === [], 'another tenant sees no popups');
$GLOBALS['__tid'] = 1;

echo PHP_EOL . ($fail === 0 ? 'STORE E2E OK' : "STORE E2E FAILED ($fail)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
