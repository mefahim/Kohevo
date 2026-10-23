<?php
/**
 * PageForge public router — serves published pages under /forge/<slug>.
 *
 * Only compiled output is served (never a live render of a draft), and the
 * compilation is keyed to the current tenant, so a slug that exists for
 * another tenant is a 404 here, never a leak.
 *
 * Regular pages are wrapped in the tenant's published theme parts
 * (header/footer) and carry every published popup of the tenant (hidden
 * overlays opened by button triggers). Parts, popups and globals viewed
 * directly render standalone.
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
$kind = (string) ($page['kind'] ?? 'page');
$css = $published['css'];
$bodyHtml = $published['html'];
$popupScript = '';

if ($kind === 'page') {
    $header = PageForgeStore::getPublishedPart('header');
    $footer = PageForgeStore::getPublishedPart('footer');
    if ($header !== null) {
        $css .= "\n" . $header['css'];
        $bodyHtml = $header['html'] . $bodyHtml;
    }
    if ($footer !== null) {
        $css .= "\n" . $footer['css'];
        $bodyHtml .= $footer['html'];
    }
    $popups = PageForgeStore::listPublishedPopups();
    foreach ($popups as $popup) {
        $css .= "\n" . $popup['css'];
        $pid = (int) $popup['page']['id'];
        $bodyHtml .= '<div class="pf-popup" id="pf-popup-' . $pid . '" hidden role="dialog" aria-modal="true">'
            . '<div class="pf-popup__box"><button type="button" class="pf-popup__close" data-pf-close aria-label="Close">&times;</button>'
            . $popup['html'] . '</div></div>';
    }
    if ($popups !== []) {
        $popupScript = <<<'JS'
<script>
document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-pf-popup]');
    if (t) {
        e.preventDefault();
        var p = document.getElementById('pf-popup-' + t.getAttribute('data-pf-popup'));
        if (p) { p.hidden = false; }
        return;
    }
    if (e.target.closest('[data-pf-close]') || e.target.classList.contains('pf-popup')) {
        var open = e.target.closest('.pf-popup');
        if (open) { open.hidden = true; }
    }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.pf-popup:not([hidden])').forEach(function (p) { p.hidden = true; });
    }
});
</script>
JS;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars((string) $page['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars(SLATE_URL, ENT_QUOTES, 'UTF-8') ?>/plugins/pageforge/assets/pageforge.css">
<style><?= $css ?></style>
</head>
<body>
<?= $bodyHtml ?>
<?= $popupScript ?>
</body>
</html>
