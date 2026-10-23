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
            elseif (in_array($kindSel, ['popup', 'global'], true)) { $kind = $kindSel; }
            $newId = PageForgeStore::createPage($title, '', $userId, $kind, $part);
            header('Location: builder.php?id=' . $newId);
            exit;
        }
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
<div class="page-head">
  <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
</div>

<?php if ($flash): ?>
  <div class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
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
    <option value="popup">Popup</option>
    <option value="global">Global widget</option>
  </select>
  <button type="submit"><?= htmlspecialchars(__('pageforge_create', 'Create page'), ENT_QUOTES, 'UTF-8') ?></button>
</form>

<p class="pf-kind-filter">
  <a href="index.php">All</a> ·
  <a href="index.php?kind=page">Pages</a> ·
  <a href="index.php?kind=part">Parts</a> ·
  <a href="index.php?kind=popup">Popups</a> ·
  <a href="index.php?kind=global">Globals</a>
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
      <td><?= htmlspecialchars((string) $p['status'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= htmlspecialchars((string) $p['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
      <td>
        <a href="builder.php?id=<?= (int) $p['id'] ?>"><?= htmlspecialchars(__('edit', 'Edit'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php if (($p['status'] ?? '') === 'published'): ?>
          · <a href="<?= htmlspecialchars(SLATE_URL, ENT_QUOTES, 'UTF-8') ?>/forge/<?= htmlspecialchars((string) $p['slug'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a>
        <?php endif; ?>
        <form method="post" style="display:inline; margin:0;">
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
<?php require $root . '/admin/partials/footer.php'; ?>
