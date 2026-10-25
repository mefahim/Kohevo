<?php
/**
 * PageForge — admin page list + create.
 *
 * Entry-point gates (house pattern, in order): platform auth, permission,
 * commercial entitlement. All writes are CSRF-verified POSTs.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('pageforge.view');
ModuleGuard::require('pageforge');

require_once dirname(__DIR__) . '/lib/PageForgeStore.php';
require_once dirname(__DIR__) . '/lib/PageForgeSetup.php';
require_once __DIR__ . '/_ui.php';

$pageTitle = __('pageforge', 'PageForge');
$currentNav = 'pageforge';
$flash = null;
$userId = (int) (Auth::user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['action'] ?? '') === 'create') {
        Auth::requirePerm('pageforge.edit');
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $flash = ['type' => 'error', 'msg' => __('pageforge_title_required', 'A title is required.')];
        } else {
            $kindSel = (string) ($_POST['kind'] ?? 'page');
            $kind = 'page';
            $part = null;
            if ($kindSel === 'part-header') { $kind = 'part'; $part = 'header'; }
            elseif ($kindSel === 'part-footer') { $kind = 'part'; $part = 'footer'; }
            elseif (str_starts_with($kindSel, 'part-')) { $kind = 'part'; $part = substr($kindSel, 5); }
            elseif (in_array($kindSel, ['popup', 'global', 'loop-item', 'template'], true)) { $kind = $kindSel; }
            $newId = PageForgeStore::createPage($title, '', $userId, $kind, $part);
            header('Location: builder.php?id=' . $newId);
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'import') {
        // Phase 11 — page import from an export package.
        Auth::requirePerm('pageforge.edit');
        $pkg = json_decode((string) ($_POST['import_json'] ?? ''), true);
        try {
            $newId = PageForgeStore::importPage(is_array($pkg) ? $pkg : [], $userId);
            header('Location: builder.php?id=' . $newId);
            exit;
        } catch (RuntimeException $e) {
            $flash = ['type' => 'error', 'msg' => __('pageforge_import_failed', 'That file is not a valid PageForge page package.')];
        }
    } elseif (($_POST['action'] ?? '') === 'checklist_dismiss') {
        PageForgeSetup::dismissChecklist();
        $flash = ['type' => 'success', 'msg' => __('pageforge_checklist_dismissed', 'Checklist dismissed.')];
    } elseif (($_POST['action'] ?? '') === 'publish') {
        Auth::requirePerm('pageforge.publish');
        $pageId = (int) ($_POST['id'] ?? 0);
        if ($pageId > 0 && PageForgeStore::getPage($pageId) !== null) {
            PageForgeStore::publish($pageId, $userId);
            $flash = ['type' => 'success', 'msg' => __('pageforge_published', 'Page published.')];
        }
    }
}

$kindFilter = (string) ($_GET['kind'] ?? '');
$pages = PageForgeStore::listPages($kindFilter !== '' ? $kindFilter : null);

require $root . '/admin/partials/header.php';
?>
<div class="pf-admin">
<?php pf_admin_head('pages', $pageTitle, 'Pages, parts, popups & templates'); ?>
<?php pf_flash($flash); ?>

<?php if (!PageForgeSetup::isDone()): ?>
  <div class="pf-flash pf-flash--info">
    <?= htmlspecialchars(__('pageforge_setup_banner', 'PageForge is not set up yet.'), ENT_QUOTES, 'UTF-8') ?>
    <a href="setup.php"><strong><?= htmlspecialchars(__('pageforge_run_setup', 'Run the setup wizard'), ENT_QUOTES, 'UTF-8') ?></strong></a>
  </div>
<?php endif; ?>

<?php if (!PageForgeSetup::checklistDismissed() && !PageForgeSetup::checklistAllDone()): ?>
  <div class="pf-checklist">
    <h2><?= htmlspecialchars(__('pageforge_checklist', 'Getting started'), ENT_QUOTES, 'UTF-8') ?></h2>
    <ul>
      <?php foreach (PageForgeSetup::checklist() as $item): ?>
        <li><?= $item['done'] ? '✓' : '○' ?>
          <?php if ($item['done']): ?><s><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></s>
          <?php else: ?><a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="checklist_dismiss">
      <button type="submit"><?= htmlspecialchars(__('dismiss', 'Dismiss'), ENT_QUOTES, 'UTF-8') ?></button>
    </form>
  </div>
<?php endif; ?>

<form method="post" class="pf-create">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <input type="text" name="title" placeholder="<?= htmlspecialchars(__('pageforge_new_title', 'New page title'), ENT_QUOTES, 'UTF-8') ?>" required>
  <select name="kind">
    <option value="page">Page</option>
    <option value="part-header">Theme part — Header</option>
    <option value="part-footer">Theme part — Footer</option>
    <option value="part-single">Theme part — Single</option>
    <option value="part-archive">Theme part — Archive</option>
    <option value="part-search">Theme part — Search</option>
    <option value="part-404">Theme part — 404</option>
    <option value="part-section">Theme part — Section</option>
    <option value="popup">Popup</option>
    <option value="global">Global widget</option>
    <option value="loop-item">Loop item template</option>
    <option value="template">Template (library)</option>
  </select>
  <button type="submit" class="pf-primary"><?= htmlspecialchars(__('pageforge_create', 'Create page'), ENT_QUOTES, 'UTF-8') ?></button>
</form>

<details class="pf-panel">
<summary>Import a page (Phase 11)</summary>
<div class="pf-panel-body">
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="import">
  <label>Paste a PageForge page package (JSON)<br>
  <textarea name="import_json" rows="6" class="pf-wide" placeholder='{"format":"pageforge-page", ...}'></textarea></label>
  <button type="submit">Import page</button>
</form>
</div>
</details>

<p class="pf-kind-filter">
  <a href="index.php"<?= $kindFilter === '' ? ' class="on"' : '' ?>>All</a>
  <a href="index.php?kind=page"<?= $kindFilter === 'page' ? ' class="on"' : '' ?>>Pages</a>
  <a href="index.php?kind=part"<?= $kindFilter === 'part' ? ' class="on"' : '' ?>>Parts</a>
  <a href="index.php?kind=popup"<?= $kindFilter === 'popup' ? ' class="on"' : '' ?>>Popups</a>
  <a href="index.php?kind=global"<?= $kindFilter === 'global' ? ' class="on"' : '' ?>>Globals</a>
  <a href="index.php?kind=loop-item"<?= $kindFilter === 'loop-item' ? ' class="on"' : '' ?>>Loop items</a>
</p>

<table class="table">
  <thead>
    <tr><th>Title</th><th>Slug</th><th>Kind</th><th>Status</th><th>Updated</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($pages as $p): ?>
    <tr>
      <td><?= htmlspecialchars((string) $p['title'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><code>/forge/<?= htmlspecialchars((string) $p['slug'], ENT_QUOTES, 'UTF-8') ?></code></td>
      <td><?= htmlspecialchars((string) ($p['kind'] ?? 'page') . (!empty($p['part']) ? ' (' . (string) $p['part'] . ')' : ''), ENT_QUOTES, 'UTF-8') ?></td>
      <td><span class="pf-pill <?= ($p['status'] ?? '') === 'published' ? 'pf-pill--pub' : 'pf-pill--draft' ?>"><?= htmlspecialchars((string) $p['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
      <td><?= htmlspecialchars((string) $p['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
      <td>
        <a href="builder.php?id=<?= (int) $p['id'] ?>"><?= htmlspecialchars(__('edit', 'Edit'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php if (($p['status'] ?? '') === 'published'): ?>
          · <a href="<?= htmlspecialchars(SLATE_URL, ENT_QUOTES, 'UTF-8') ?>/forge/<?= htmlspecialchars((string) $p['slug'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a>
        <?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="publish">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button type="submit"><?= htmlspecialchars(__('publish', 'Publish'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if ($pages === []): ?>
    <tr><td colspan="6"><?= htmlspecialchars(__('pageforge_empty', 'No pages yet. Create your first page above.'), ENT_QUOTES, 'UTF-8') ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?php require $root . '/admin/partials/footer.php'; ?>
