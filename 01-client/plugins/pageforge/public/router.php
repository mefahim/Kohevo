<?php
/**
 * PageForge public router — serves published pages under /forge/<slug>.
 *
 * Only compiled output is served (never a live render of a draft), and the
 * compilation is keyed to the current tenant, so a slug that exists for
 * another tenant is a 404 here, never a leak.
 */

ModuleGuard::requirePublic('pageforge');

require_once dirname(__DIR__) . '/lib/PageForgeStore.php';

$root = realpath(__DIR__ . '/../../..');

$path = trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''), '/');
$segments = explode('/', $path);
$slug = preg_replace('/[^a-zA-Z0-9-_]/', '', (string) ($segments[1] ?? ''));

$published = $slug !== '' ? PageForgeStore::getPublished($slug) : null;

if ($published === null) {
    http_response_code(404);
    echo 'Page not found.';
    return;
}

$page = $published['page'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars((string) $page['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars(SLATE_URL, ENT_QUOTES, 'UTF-8') ?>/plugins/pageforge/assets/pageforge.css">
<style><?= $published['css'] ?></style>
</head>
<body>
<?= $published['html'] ?>
</body>
</html>
