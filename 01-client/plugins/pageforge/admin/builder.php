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
    'form' => 'kohevo', 'global-widget' => 'kohevo',
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
// Post-save notices arrive via the PRG redirect (see the POST handler).
if ($flash === null && isset($_GET['pf_notice'])) {
    $flash = match ((string) $_GET['pf_notice']) {
        'saved' => ['type' => 'success', 'msg' => __('pageforge_saved', 'Draft saved as a new revision.')],
        'published' => ['type' => 'success', 'msg' => __('pageforge_published', 'Saved and published.')],
        default => null,
    };
}
$pfBoot = [
    'pageId' => $pageId,
    'pageTitle' => (string) $page['title'],
    'slug' => (string) $page['slug'],
    'status' => (string) $page['status'],
    'kind' => (string) ($page['kind'] ?? 'page'),
    'notice' => $flash,
    'document' => $document,
    'widgets' => $pfManifest,
    'styleControls' => $pfStyleControls,
    'csrf' => ['name' => '_csrf', 'value' => csrf_token()],
    'saveUrl' => 'builder.php?id=' . $pageId,
    'previewUrl' => 'api.php',
    'pagesUrl' => 'index.php',
    'publicUrl' => rtrim((string) SLATE_URL, '/') . '/forge/' . (string) $page['slug'],
    'pageCssUrl' => plugin_url('pageforge', 'assets/pageforge.css'),
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
<title><?= htmlspecialchars((string) $page['title'], ENT_QUOTES, 'UTF-8') ?> · PageForge Editor</title>
<link rel="stylesheet" href="<?= htmlspecialchars(plugin_url('pageforge', 'assets/editor/editor.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $pfEditorCssVer ?>">
</head>
<body style="margin:0">
<div id="pageforge-editor"></div>
<script>window.PAGEFORGE_BOOT = <?= json_encode($pfBoot, JSON_UNESCAPED_SLASHES) ?>;</script>
<script type="module" src="<?= htmlspecialchars(plugin_url('pageforge', 'assets/editor/editor.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $pfEditorVer ?>"></script>
</body>
</html>
<?php
    exit;
}

require $root . '/admin/partials/header.php';
?>
<div class="page-head">
  <h1><?= htmlspecialchars((string) $page['title'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p><code>/forge/<?= htmlspecialchars((string) $page['slug'], ENT_QUOTES, 'UTF-8') ?></code> · <?= htmlspecialchars((string) $page['status'], ENT_QUOTES, 'UTF-8') ?></p>
</div>

<?php if ($flash): ?>
  <div class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

<link rel="stylesheet" href="<?= htmlspecialchars(plugin_url('pageforge', 'assets/pageforge.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(plugin_url('pageforge', 'assets/editor/editor.css'), ENT_QUOTES, 'UTF-8') ?>">
<div id="pageforge-editor"></div>
<script>window.PAGEFORGE_BOOT = <?= json_encode($pfBoot, JSON_UNESCAPED_SLASHES) ?>;</script>
<script type="module" src="<?= htmlspecialchars(plugin_url('pageforge', 'assets/editor/editor.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $pfEditorVer ?>"></script>

<details>
<summary>Fallback editor (document JSON) — widgets in this build: <?= htmlspecialchars(implode(', ', $widgets), ENT_QUOTES, 'UTF-8') ?></summary>

<form method="post">
  <?= csrf_field() ?>
  <textarea name="document" rows="22" style="width:100%; font-family:monospace;"><?= htmlspecialchars(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></textarea>
  <div>
    <button type="submit" name="action" value="save">Save draft</button>
    <button type="submit" name="action" value="publish">Save &amp; publish</button>
    <a href="index.php">Back to pages</a>
  </div>
</form>

<h2>Preview (server-rendered, exactly what publish serves)</h2>
<style><?= $preview['css'] ?></style>
<div style="border:1px solid #ddd; padding:16px; background:#fff;">
<?= $preview['html'] ?>
</div>
</details>
<?php require $root . '/admin/partials/footer.php'; ?>
