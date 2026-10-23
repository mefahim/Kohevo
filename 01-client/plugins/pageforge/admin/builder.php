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
            if (($_POST['action'] ?? '') === 'publish') {
                Auth::requirePerm('pageforge.publish');
                PageForgeStore::publish($pageId, $userId);
                $flash = ['type' => 'success', 'msg' => __('pageforge_published', 'Saved and published.')];
            } else {
                $flash = ['type' => 'success', 'msg' => __('pageforge_saved', 'Draft saved as a new revision.')];
            }
            $page = PageForgeStore::getPage($pageId);
        }
    }
}

$document = PageForgeStore::getDocument($pageId);
PageForgeStore::wireGlobalResolver();
$preview = PageForgeRenderer::compile($document, $pageId);
$widgets = array_keys(PageForgeWidgets::all());

// Canvas editor boot manifest (Phase 2): widget schemas without render
// callables, plus the shared style-control schemas and this page's state.
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
        'is_container' => !empty($def['is_container']),
        'controls' => $controls,
        'style_keys' => PageForgeCss::effectiveStyleKeys($def),
    ];
}
$pfStyleControls = [];
foreach (PageForgeCss::styleControls() as $key => $c) {
    $pfStyleControls[$key] = array_intersect_key($c, array_flip(['type', 'options', 'min', 'max', 'responsive']));
}
$pfBoot = [
    'pageId' => $pageId,
    'pageTitle' => (string) $page['title'],
    'document' => $document,
    'widgets' => $pfManifest,
    'styleControls' => $pfStyleControls,
    'csrf' => ['name' => '_csrf', 'value' => csrf_token()],
    'saveUrl' => 'builder.php?id=' . $pageId,
    'previewUrl' => 'api.php',
];
$pfEditorVer = (int) (@filemtime(dirname(__DIR__) . '/assets/editor/editor.js') ?: 0);

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
