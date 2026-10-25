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
    // Phase 10: a published '404' theme part whose display conditions
    // match the notfound context replaces the bare message.
    $notFound = PageForgeStore::getPublishedPartFor('404', ['route' => 'notfound', 'slug' => $slug, 'kind' => '']);
    if ($notFound === null) {
        echo 'Page not found.';
        return;
    }
    $nfScript = (str_contains($notFound['html'], 'data-pf-scroll-fade')
        || str_contains($notFound['html'], 'pf-carousel') || str_contains($notFound['html'], 'data-pf-lightbox'))
        ? '<script src="' . htmlspecialchars(SLATE_URL, ENT_QUOTES, 'UTF-8') . '/plugins/pageforge/assets/pageforge-frontend.js"></script>' : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars((string) ($notFound['page']['title'] ?? 'Page not found'), ENT_QUOTES, 'UTF-8') . '</title>'
        . '<link rel="stylesheet" href="' . htmlspecialchars(SLATE_URL, ENT_QUOTES, 'UTF-8') . '/plugins/pageforge/assets/pageforge.css">'
        . '<style>' . $notFound['css'] . '</style></head><body>' . $notFound['html'] . $nfScript . '</body></html>';
    return;
}

$page = $published['page'];
$kind = (string) ($page['kind'] ?? 'page');
if ($kind === 'loop-item') {
    // Loop templates are building blocks, never standalone pages: their
    // {{field}} placeholders only make sense inside a host page's loop.
    http_response_code(404);
    echo 'Page not found.';
    return;
}
$css = $published['css'];
$bodyHtml = $published['html'];
$popupScript = '';

if ($kind === 'page') {
    $ctx = ['route' => 'page', 'slug' => $slug, 'kind' => 'page'];
    $header = PageForgeStore::getPublishedPartFor('header', $ctx);
    $footer = PageForgeStore::getPublishedPartFor('footer', $ctx);
    if ($header !== null) {
        $css .= "\n" . $header['css'];
        $bodyHtml = $header['html'] . $bodyHtml;
    }
    if ($footer !== null) {
        $css .= "\n" . $footer['css'];
        $bodyHtml .= $footer['html'];
    }
    $popups = PageForgeStore::listPublishedPopups($ctx);
    foreach ($popups as $popup) {
        $css .= "\n" . $popup['css'];
        $pid = (int) $popup['page']['id'];
        $cfg = $popup['config'] ?? PageForgePopupConfig::defaults();
        $cfgJson = htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
        $closeBtn = !empty($cfg['settings']['close_button'])
            ? '<button type="button" class="pf-popup__close" data-pf-close aria-label="Close">&times;</button>' : '';
        $bodyHtml .= '<div class="pf-popup pf-pos-' . $cfg['settings']['position'] . ' pf-anim-' . $cfg['settings']['animation']
            . '" id="pf-popup-' . $pid . '" hidden role="dialog" aria-modal="true" data-config="' . $cfgJson . '">'
            . '<div class="pf-popup__box" style="max-width: ' . (int) $cfg['settings']['width'] . 'px">' . $closeBtn
            . $popup['html'] . '</div></div>';
    }
    if ($popups !== []) {
        $popupScript = <<<'JS'
<script>
(function () {
    var views = 0;
    try { views = (parseInt(localStorage.getItem('pf10_views') || '0', 10) || 0) + 1; localStorage.setItem('pf10_views', String(views)); } catch (e) {}
    function today() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
    function stats(id) { try { return JSON.parse(localStorage.getItem('pf10_p' + id) || '{}'); } catch (e) { return {}; } }
    function saveStats(id, s) { try { localStorage.setItem('pf10_p' + id, JSON.stringify(s)); } catch (e) {} }
    function isMobile() { return window.matchMedia('(max-width: 767px)').matches; }
    function canShow(p, cfg) {
        var r = cfg.rules || {};
        if (r.devices === 'mobile' && !isMobile()) return false;
        if (r.devices === 'desktop' && isMobile()) return false;
        if (r.hide_after_views > 0 && views > r.hide_after_views) return false;
        var s = stats(p.id.replace('pf-popup-', ''));
        if (r.max_shows > 0 && (s.total || 0) >= r.max_shows) return false;
        if (r.max_per_day > 0 && s.day === today() && (s.dayCount || 0) >= r.max_per_day) return false;
        return true;
    }
    function open(p) {
        var cfg = p._cfg || {};
        if (cfg.settings && cfg.settings.avoid_multiple) {
            document.querySelectorAll('.pf-popup:not([hidden])').forEach(function (o) { if (o !== p) close(o); });
        }
        p.hidden = false;
        if (cfg.settings && cfg.settings.prevent_scroll) { document.body.style.overflow = 'hidden'; }
        var id = p.id.replace('pf-popup-', '');
        var s = stats(id);
        s.total = (s.total || 0) + 1;
        if (s.day !== today()) { s.day = today(); s.dayCount = 0; }
        s.dayCount = (s.dayCount || 0) + 1;
        saveStats(id, s);
    }
    function close(p) {
        p.hidden = true;
        var cfg = p._cfg || {};
        if (cfg.settings && cfg.settings.prevent_scroll) { document.body.style.overflow = ''; }
    }
    document.querySelectorAll('.pf-popup').forEach(function (p) {
        var cfg = {};
        try { cfg = JSON.parse(p.getAttribute('data-config') || '{}'); } catch (e) {}
        p._cfg = cfg;
        var trg = cfg.triggers || {};
        var fired = false;
        function fire() { if (fired) return; fired = true; if (canShow(p, cfg)) open(p); }
        if (trg.on_load && trg.on_load.enabled) { setTimeout(fire, (trg.on_load.delay || 0) * 1000); }
        if (trg.on_scroll && trg.on_scroll.enabled) {
            var pct = trg.on_scroll.percent || 50;
            var onScroll = function () {
                var h = document.documentElement;
                var max = h.scrollHeight - h.clientHeight;
                var now = max > 0 ? (h.scrollTop / max) * 100 : 100;
                if (now >= pct) { fire(); window.removeEventListener('scroll', onScroll); }
            };
            window.addEventListener('scroll', onScroll, { passive: true });
        }
        if (trg.inactivity && trg.inactivity.enabled) {
            var sec = trg.inactivity.seconds || 30;
            var timer = null;
            var reset = function () { if (fired) return; clearTimeout(timer); timer = setTimeout(fire, sec * 1000); };
            ['mousemove', 'keydown', 'scroll', 'click', 'touchstart'].forEach(function (ev) { window.addEventListener(ev, reset, { passive: true }); });
            reset();
        }
        if (trg.exit_intent && trg.exit_intent.enabled) {
            document.addEventListener('mouseout', function (e) { if (!e.relatedTarget && e.clientY < 8) fire(); });
        }
    });
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-pf-popup]');
        if (t) {
            e.preventDefault();
            var p = document.getElementById('pf-popup-' + t.getAttribute('data-pf-popup'));
            if (p && canShow(p, p._cfg || {})) open(p);
            return;
        }
        if (e.target.closest('[data-pf-close]')) {
            var o = e.target.closest('.pf-popup');
            if (o) close(o);
            return;
        }
        if (e.target.classList && e.target.classList.contains('pf-popup')) {
            var cfg = e.target._cfg || {};
            if (!cfg.settings || cfg.settings.overlay !== false) close(e.target);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.pf-popup:not([hidden])').forEach(function (p) { close(p); });
        }
    });
})();
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
<?php
// Batch A: the frontend script loads only when the served page actually
// uses a scroll-fade element (parts + popups are already in $bodyHtml).
// Batch D: carousels and lightboxes need the same script (slider + viewer).
if (str_contains($bodyHtml, 'data-pf-scroll-fade')
    || str_contains($bodyHtml, 'pf-carousel') || str_contains($bodyHtml, 'data-pf-lightbox')) {
    echo '<script src="' . htmlspecialchars(SLATE_URL, ENT_QUOTES, 'UTF-8') . '/plugins/pageforge/assets/pageforge-frontend.js"></script>';
}
?>
</body>
</html>
