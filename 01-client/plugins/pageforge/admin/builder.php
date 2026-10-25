<?php
/**
 * PageForge — foundation builder (Phase 1).
 *
 * This is the engine-proving builder: it edits the document tree as JSON,
 * saves revisions through PageForgeStore (which sanitizes every setting
 * against the widget registry), publishes compiled output, and shows the
 * server-rendered result live below the editor. The drag-and-drop canvas
 * editor replaces this surface in Phase 2; the save/publish/preview
 * contract it uses is the contract defined here.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('pageforge.edit');
ModuleGuard::require('pageforge');

require_once dirname(__DIR__) . '/lib/PageForgeStore.php';
require_once __DIR__ . '/_ui.php';

$pageTitle = __('pageforge_builder', 'PageForge Builder');
$currentNav = 'pageforge';
$flash = null;
$userId = (int) (Auth::user()['id'] ?? 0);
$pageId = (int) ($_GET['id'] ?? 0);
$page = $pageId > 0 ? PageForgeStore::getPage($pageId) : null;

if ($page === null) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['action'] ?? '') === 'restore') {
        // Phase 9 — revision restore: the store appends the restored
        // document as a new 'rollback' revision; nothing is rewritten.
        try {
            PageForgeStore::restoreRevision($pageId, (int) ($_POST['revision_id'] ?? 0), $userId);
            $flash = ['type' => 'success', 'msg' => __('pageforge_restored', 'Revision restored as the current draft.')];
        } catch (RuntimeException $e) {
            $flash = ['type' => 'error', 'msg' => __('pageforge_restore_failed', 'That revision could not be restored.')];
        }
        $page = PageForgeStore::getPage($pageId);
        if (!isset($_GET['classic'])) {
            header('Location: builder.php?id=' . $pageId . '&pf_notice=restored');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'save_conditions') {
        // Phase 10 — W3 display conditions for theme parts and popups.
        PageForgeStore::setConditions($pageId, [
            'include' => PageForgeDisplayConditions::parse((string) ($_POST['cond_include'] ?? '')),
            'exclude' => PageForgeDisplayConditions::parse((string) ($_POST['cond_exclude'] ?? '')),
        ]);
        $flash = ['type' => 'success', 'msg' => __('pageforge_conditions_saved', 'Display conditions saved.')];
        $page = PageForgeStore::getPage($pageId);
        if (!isset($_GET['classic'])) {
            header('Location: builder.php?id=' . $pageId . '&pf_notice=saved');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'save_popup') {
        // Phase 10 — W4 popup settings, triggers and advanced rules.
        PageForgeStore::setPopupConfig($pageId, [
            'settings' => [
                'position' => (string) ($_POST['pop_position'] ?? 'center'),
                'width' => (int) ($_POST['pop_width'] ?? 640),
                'animation' => (string) ($_POST['pop_animation'] ?? 'fade'),
                'overlay' => isset($_POST['pop_overlay']),
                'close_button' => isset($_POST['pop_close_button']),
                'prevent_scroll' => isset($_POST['pop_prevent_scroll']),
                'avoid_multiple' => isset($_POST['pop_avoid_multiple']),
            ],
            'triggers' => [
                'on_load' => ['enabled' => isset($_POST['pop_t_load']), 'delay' => (int) ($_POST['pop_t_load_delay'] ?? 0)],
                'on_scroll' => ['enabled' => isset($_POST['pop_t_scroll']), 'percent' => (int) ($_POST['pop_t_scroll_pct'] ?? 50)],
                'inactivity' => ['enabled' => isset($_POST['pop_t_idle']), 'seconds' => (int) ($_POST['pop_t_idle_sec'] ?? 30)],
                'exit_intent' => ['enabled' => isset($_POST['pop_t_exit'])],
            ],
            'rules' => [
                'max_shows' => (int) ($_POST['pop_max_shows'] ?? 0),
                'max_per_day' => (int) ($_POST['pop_max_day'] ?? 0),
                'hide_after_views' => (int) ($_POST['pop_hide_views'] ?? 0),
                'devices' => (string) ($_POST['pop_devices'] ?? 'all'),
            ],
        ]);
        $flash = ['type' => 'success', 'msg' => __('pageforge_popup_saved', 'Popup settings saved.')];
        $page = PageForgeStore::getPage($pageId);
        if (!isset($_GET['classic'])) {
            header('Location: builder.php?id=' . $pageId . '&pf_notice=saved');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'save_template') {
        // Phase 11 — W5: save this page's draft as a reusable template.
        $tplId = PageForgeStore::saveAsTemplate($pageId, trim((string) ($_POST['template_title'] ?? '')), $userId);
        $flash = ['type' => 'success', 'msg' => __('pageforge_template_saved', 'Template saved (ID ') . $tplId . ').'];
        $page = PageForgeStore::getPage($pageId);
        if (!isset($_GET['classic'])) {
            header('Location: builder.php?id=' . $pageId . '&pf_notice=saved');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'insert_template') {
        // Phase 11 — W5: append a saved template's elements to this draft.
        try {
            PageForgeStore::insertTemplate($pageId, (int) ($_POST['insert_template_id'] ?? 0), $userId);
            $flash = ['type' => 'success', 'msg' => __('pageforge_template_inserted', 'Template inserted into this page.')];
        } catch (RuntimeException $e) {
            $flash = ['type' => 'error', 'msg' => __('pageforge_template_failed', 'That template could not be inserted.')];
        }
        $page = PageForgeStore::getPage($pageId);
        if (!isset($_GET['classic'])) {
            header('Location: builder.php?id=' . $pageId . '&pf_notice=saved');
            exit;
        }
    } else {
        $raw = (string) ($_POST['document'] ?? '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $flash = ['type' => 'error', 'msg' => __('pageforge_bad_json', 'The document is not valid JSON; nothing was saved.')];
        } else {
            PageForgeStore::saveDocument($pageId, $decoded, $userId, 'manual');
            $pfAction = (string) ($_POST['action'] ?? '');
            if ($pfAction === 'publish') {
                Auth::requirePerm('pageforge.publish');
                PageForgeStore::publish($pageId, $userId);
                $flash = ['type' => 'success', 'msg' => __('pageforge_published', 'Saved and published.')];
            } else {
                $flash = ['type' => 'success', 'msg' => __('pageforge_saved', 'Draft saved as a new revision.')];
            }
            $page = PageForgeStore::getPage($pageId);
            // The full-screen editor posts here too: answer with a PRG redirect
            // carrying a notice flag, so a refresh never re-submits the save.
            if (!isset($_GET['classic'])) {
                header('Location: builder.php?id=' . $pageId . '&pf_notice=' . ($pfAction === 'publish' ? 'published' : 'saved'));
                exit;
            }
        }
    }
}

$document = PageForgeStore::getDocument($pageId);
PageForgeStore::wireGlobalResolver();
$preview = PageForgeRenderer::compile($document, $pageId);
$widgets = array_keys(PageForgeWidgets::all());

// Canvas editor boot manifest (Phase 2): widget schemas without render
// callables, plus the shared style-control schemas and this page's state.
// Editor-UX overhaul: each widget also carries a palette category so the
// Elements panel can group the grid the way the approved design shows.
$pfCategories = [
    'container' => 'layout', 'divider' => 'layout', 'spacer' => 'layout', 'menu-anchor' => 'layout',
    'heading' => 'content', 'text' => 'content', 'button' => 'content', 'list' => 'content',
    'icon' => 'content', 'icon-box' => 'content', 'icon-list' => 'content', 'counter' => 'content',
    'progress' => 'content', 'testimonial' => 'content', 'social-icons' => 'content',
    'alert' => 'content', 'star-rating' => 'content',
    'image' => 'media', 'image-box' => 'media', 'video' => 'media', 'gallery' => 'media',
    'image-carousel' => 'media',
    'tabs' => 'interactive', 'accordion' => 'interactive', 'toggle' => 'interactive', 'html' => 'interactive',
    'form' => 'kohevo', 'global-widget' => 'kohevo', 'shop-grid' => 'kohevo', 'loop' => 'kohevo',
    'mega-menu' => 'kohevo',
];
$pfManifest = [];
foreach (PageForgeWidgets::all() as $type => $def) {
    $controls = [];
    foreach (($def['controls'] ?? []) as $key => $c) {
        $controls[$key] = array_intersect_key($c, array_flip(['type', 'options', 'default', 'min', 'max', 'item_schema', 'allow_html']));
        if (isset($controls[$key]['item_schema'])) {
            foreach ($controls[$key]['item_schema'] as $f => $fc) {
                $controls[$key]['item_schema'][$f] = array_intersect_key($fc, array_flip(['type', 'options', 'default', 'min', 'max']));
            }
        }
    }
    $pfManifest[] = [
        'type' => $type,
        'label' => $def['label'] ?? $type,
        'icon' => $def['icon'] ?? '',
        'category' => $pfCategories[$type] ?? 'content',
        'is_container' => !empty($def['is_container']),
        'controls' => $controls,
        'style_keys' => PageForgeCss::effectiveStyleKeys($def),
    ];
}
$pfStyleControls = [];
foreach (PageForgeCss::styleControls() as $key => $c) {
    $pfStyleControls[$key] = array_intersect_key($c, array_flip(['type', 'options', 'min', 'max', 'responsive']));
}
// Content-modern: the editor's visual icon picker + repeater previews
// render the real glyphs, so boot carries the icon SVG map (same source
// the renderer uses — no duplicated paths in JS).
$pfIcons = [];
foreach (PageForgeIcons::names() as $pfIconName) {
    $pfIcons[$pfIconName] = PageForgeIcons::svg($pfIconName);
}
// Post-save notices arrive via the PRG redirect (see the POST handler).
if ($flash === null && isset($_GET['pf_notice'])) {
    $flash = match ((string) $_GET['pf_notice']) {
        'saved' => ['type' => 'success', 'msg' => __('pageforge_saved', 'Draft saved as a new revision.')],
        'published' => ['type' => 'success', 'msg' => __('pageforge_published', 'Saved and published.')],
        'restored' => ['type' => 'success', 'msg' => __('pageforge_restored', 'Revision restored as the current draft.')],
        default => null,
    };
}
// Editor port: the redesign shell serves the full Phase 9-11 feature set —
// saved revisions for the History panel, autosave cadence, and the Phase
// 10/11 page-level data the Page panel edits (conditions, popup config,
// dynamic-tag reference, template list).
$pfRevisions = [];
foreach (PageForgeStore::listRevisions($pageId, 30) as $rev) {
    $pfRevisions[] = [
        'id' => (int) $rev['id'],
        'number' => (int) $rev['revision_number'],
        'kind' => (string) $rev['revision_kind'],
        'created_at' => (string) $rev['created_at'],
    ];
}
$pfPageKind = (string) ($page['kind'] ?? 'page');
$pfCond = PageForgeStore::pageConditions($page);
$pfTemplates = [];
foreach (PageForgeStore::listPages('template') as $pfTpl) {
    if ((int) $pfTpl['id'] !== $pageId) {
        $pfTemplates[] = ['id' => (int) $pfTpl['id'], 'title' => (string) $pfTpl['title']];
    }
}
$pfBoot = [
    'pageId' => $pageId,
    'pageTitle' => (string) $page['title'],
    'slug' => (string) $page['slug'],
    'status' => (string) $page['status'],
    'kind' => $pfPageKind,
    'part' => (string) ($page['part'] ?? ''),
    'revisions' => $pfRevisions,
    'autosaveSeconds' => 30,
    'conditions' => in_array($pfPageKind, ['part', 'popup'], true) ? [
        'include' => PageForgeDisplayConditions::format($pfCond['include']),
        'exclude' => PageForgeDisplayConditions::format($pfCond['exclude']),
    ] : null,
    'popup' => $pfPageKind === 'popup' ? PageForgeStore::popupConfig($page) : null,
    'popupOptions' => [
        'positions' => PageForgePopupConfig::POSITIONS,
        'animations' => PageForgePopupConfig::ANIMATIONS,
        'devices' => PageForgePopupConfig::DEVICES,
    ],
    'dynamicTags' => PageForgeDynamic::tags(),
    'templates' => $pfTemplates,
    'exportUrl' => 'builder.php?id=' . $pageId . '&export=1',
    'notice' => $flash,
    'document' => $document,
    'widgets' => $pfManifest,
    'styleControls' => $pfStyleControls,
    'icons' => $pfIcons,
    'csrf' => ['name' => '_csrf', 'value' => csrf_token()],
    'saveUrl' => 'builder.php?id=' . $pageId,
    'previewUrl' => 'api.php',
    'pagesUrl' => 'index.php',
    'publicUrl' => rtrim((string) SLATE_URL, '/') . '/forge/' . (string) $page['slug'],
    'pageCssUrl' => plugin_url('pageforge', 'assets/pageforge.css'),
    'pageJsUrl' => plugin_url('pageforge', 'assets/pageforge-frontend.js'),
];
$pfEditorVer = (int) (@filemtime(dirname(__DIR__) . '/assets/editor/editor.js') ?: 0);
$pfEditorCssVer = (int) (@filemtime(dirname(__DIR__) . '/assets/editor/editor.css') ?: 0);

// Editor-UX overhaul: the default surface is a full-screen standalone editor
// (the approved design). The Phase 2 admin-embedded page remains available
// as ?classic=1, including the no-JS JSON fallback form below.
if (!isset($_GET['classic'])) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars((string) $page['title'], ENT_QUOTES, 'UTF-8') ?> · PageForge Editor</title>
<link rel="stylesheet" href="<?= htmlspecialchars(plugin_url('media-library', 'assets/css/picker.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(plugin_url('pageforge', 'assets/editor/editor.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $pfEditorCssVer ?>">
</head>
<body style="margin:0">
<div id="pageforge-editor"></div>
<script>window.PAGEFORGE_BOOT = <?= json_encode($pfBoot, JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= htmlspecialchars(plugin_url('media-library', 'assets/js/picker.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script type="module" src="<?= htmlspecialchars(plugin_url('pageforge', 'assets/editor/editor.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $pfEditorVer ?>"></script>
</body>
</html>
<?php
    exit;
}

require $root . '/admin/partials/header.php';
?>
<div class="pf-admin">
<?php pf_admin_head('builder', (string) $page['title'], '/forge/' . (string) $page['slug'] . ' · ' . (string) $page['status'], 'index.php', '← Pages'); ?>
<?php pf_flash($flash); ?>

<link rel="stylesheet" href="<?= htmlspecialchars(plugin_url('pageforge', 'assets/pageforge.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(plugin_url('media-library', 'assets/css/picker.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(plugin_url('pageforge', 'assets/editor/editor.css'), ENT_QUOTES, 'UTF-8') ?>">
<div id="pageforge-editor"></div>
<script>window.PAGEFORGE_BOOT = <?= json_encode($pfBoot, JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= htmlspecialchars(plugin_url('media-library', 'assets/js/picker.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script type="module" src="<?= htmlspecialchars(plugin_url('pageforge', 'assets/editor/editor.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $pfEditorVer ?>"></script>

<details class="pf-panel">
<summary>Fallback editor (document JSON) — widgets in this build: <?= htmlspecialchars(implode(', ', $widgets), ENT_QUOTES, 'UTF-8') ?></summary>

<div class="pf-panel-body">
<form method="post">
  <?= csrf_field() ?>
  <textarea name="document" rows="22" class="pf-doc"><?= htmlspecialchars(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></textarea>
  <div>
    <button type="submit" name="action" value="save">Save draft</button>
    <button type="submit" name="action" value="publish">Save &amp; publish</button>
    <a href="index.php">Back to pages</a>
  </div>
</form>

<h2>Preview (server-rendered, exactly what publish serves)</h2>
<style><?= $preview['css'] ?></style>
<div class="pf-preview">
<?= $preview['html'] ?>
</div>
</div>
</details>
</div>
<?php require $root . '/admin/partials/footer.php'; ?>
