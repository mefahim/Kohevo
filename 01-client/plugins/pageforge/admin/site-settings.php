<?php
/**
 * PageForge — Site Settings (Phase 6: the Kit).
 *
 * Sync contract: the values shown as "synced from site" are read LIVE
 * from the platform's own site settings (Settings → Branding/General).
 * The form below stores PageForge OVERRIDES only — an empty field means
 * "follow the site", so changing the site branding later still flows
 * into PageForge on the next publish. Saving recompiles every published
 * page so a kit change reaches live pages immediately.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('pageforge.view');
ModuleGuard::require('pageforge');

require_once dirname(__DIR__) . '/lib/PageForgeStore.php';
require_once dirname(__DIR__) . '/lib/PageForgeSetup.php';
require_once __DIR__ . '/_ui.php';

$pageTitle = __('pageforge_site_settings', 'PageForge Site Settings');
$currentNav = 'pageforge-site-settings';
$flash = null;
$userId = (int) (Auth::user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['action'] ?? '') === 'export_kit') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="pageforge-kit.json"');
        echo json_encode(PageForgeKit::exportData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    } elseif (($_POST['action'] ?? '') === 'import_kit') {
        Auth::requirePerm('pageforge.edit');
        $raw = '';
        if (!empty($_FILES['kit_file']) && $_FILES['kit_file']['error'] === UPLOAD_ERR_OK) {
            $raw = (string) file_get_contents($_FILES['kit_file']['tmp_name']);
        } elseif (trim((string) ($_POST['kit_json'] ?? '')) !== '') {
            $raw = (string) $_POST['kit_json'];
        }
        $payload = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            $flash = ['type' => 'error', 'msg' => __('pageforge_import_bad', 'That file is not a valid PageForge kit package.')];
        } elseif (PageForgeKit::storedOverrides() !== [] && empty($_POST['confirm_overwrite'])) {
            $flash = ['type' => 'error', 'msg' => __('pageforge_import_confirm', 'This site already has kit overrides. Tick the confirmation box to overwrite the selected parts.')];
        } else {
            $partsIn = is_array($_POST['parts'] ?? null) ? $_POST['parts'] : [];
            $kitParts = [
                'colors' => !empty($partsIn['colors']),
                'fonts' => !empty($partsIn['fonts']),
                'button' => !empty($partsIn['theme']),
                'layout' => !empty($partsIn['theme']),
                'breakpoints' => !empty($partsIn['settings']),
                'custom_css' => !empty($partsIn['settings']),
            ];
            $applied = [];
            if ($kitParts['colors']) $applied[] = 'colors';
            if ($kitParts['fonts']) $applied[] = 'fonts';
            if ($kitParts['button']) $applied[] = 'theme style';
            if ($kitParts['breakpoints']) $applied[] = 'site settings';
            $merged = PageForgeKit::importMerge($payload, $kitParts, PageForgeKit::storedOverrides());
            PageForgeKit::saveOverrides($merged);
            if (!empty($partsIn['identity']) && isset($payload['identity']) && is_array($payload['identity'])) {
                $cur = PageForgeSetup::siteIdentity();
                PageForgeSetup::setSiteIdentity((string) ($payload['identity']['site_name'] ?? $cur['site_name']), $cur['tagline']);
                if (!empty($payload['identity']['logo_path'])) {
                    PageForgeSetup::setLogoPath((string) $payload['identity']['logo_path']);
                }
                $applied[] = 'site identity';
            }
            $count = PageForgeStore::recompileAllPublished($userId);
            $flash = ['type' => 'success', 'msg' => sprintf(
                __('pageforge_import_done', 'Kit imported (%s). %d published page(s) recompiled.'),
                $applied !== [] ? implode(', ', $applied) : 'no parts selected',
                $count
            )];
        }
    } elseif (($_POST['action'] ?? '') === 'save_kit') {
        Auth::requirePerm('pageforge.edit');
        PageForgeKit::saveOverrides([
            'colors'      => is_array($_POST['colors'] ?? null) ? $_POST['colors'] : [],
            'fonts'       => is_array($_POST['fonts'] ?? null) ? $_POST['fonts'] : [],
            'button'      => is_array($_POST['button'] ?? null) ? $_POST['button'] : [],
            'layout'      => is_array($_POST['layout'] ?? null) ? $_POST['layout'] : [],
            'breakpoints' => is_array($_POST['breakpoints'] ?? null) ? $_POST['breakpoints'] : [],
            'custom_css'  => (string) ($_POST['custom_css'] ?? ''),
        ]);
        $count = PageForgeStore::recompileAllPublished($userId);
        $flash = ['type' => 'success', 'msg' => sprintf(
            __('pageforge_kit_saved', 'Site settings saved. %d published page(s) recompiled.'),
            $count
        )];
    }
}

$site = PageForgeKit::siteSync();
$kit = PageForgeKit::current();
$ov = PageForgeKit::storedOverrides();
$stacks = PageForgeKit::fontStacks();

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ovVal = static function (string $section, string $key) use ($ov): string {
    return isset($ov[$section][$key]) ? (string) $ov[$section][$key] : '';
};
$colorField = static function (string $key, string $label) use ($e, $ovVal, $kit): void {
    $effective = (string) ($kit['colors'][$key] ?? '');
    ?>
    <tr>
      <td><?= $e($label) ?></td>
      <td><span class="pf-swatch" style="background:<?= $e($effective) ?>;"></span>
        <code><?= $e($effective) ?></code></td>
      <td><input type="text" name="colors[<?= $e($key) ?>]" value="<?= $e($ovVal('colors', $key)) ?>"
            placeholder="<?= $e($effective) ?>" size="10" maxlength="7"></td>
    </tr>
    <?php
};

require $root . '/admin/partials/header.php';
?>
<div class="pf-admin">
<?php pf_admin_head('settings', $pageTitle, 'Your kit — every page inherits these unless it overrides them'); ?>
<?php pf_flash($flash); ?>

<div class="pf-card">
<h2><?= $e(__('pageforge_synced_from_site', 'Synced from site settings')) ?></h2>
<p><?= $e(__('pageforge_synced_note', 'Read live from Settings → Branding/General. These are never copied — PageForge follows them until a field below overrides them.')) ?></p>
<table class="table">
  <tbody>
    <tr><td>Site name</td><td><?= $e($site['site_name'] !== '' ? $site['site_name'] : '—') ?></td></tr>
    <tr><td>Logo</td><td><?= $e($site['logo_path'] !== '' ? $site['logo_path'] : '—') ?></td></tr>
    <tr><td>Favicon</td><td><?= $e($site['favicon_path'] !== '' ? $site['favicon_path'] : '—') ?></td></tr>
    <tr><td>Brand accent</td><td><code><?= $e($site['accent']) ?></code></td></tr>
    <tr><td>Brand font</td><td><?= $e($site['font_key']) ?></td></tr>
    <tr><td>Button text mode</td><td><?= $e($site['button_text_mode']) ?></td></tr>
    <tr><td>Border radius</td><td><?= $e($site['radius_key']) ?></td></tr>
  </tbody>
</table>
</div>

<div class="pf-card">
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_kit">

  <h2><?= $e(__('pageforge_kit_colors', 'Global colors')) ?></h2>
  <p><?= $e(__('pageforge_kit_colors_note', 'Effective value on the left, override on the right (empty = follow site/default). Any widget color can also bind with a token such as {{kit.primary}}.')) ?></p>
  <table class="table">
    <thead><tr><th>Token</th><th>Effective</th><th>Override</th></tr></thead>
    <tbody>
      <?php $colorField('primary', 'Primary — {{kit.primary}}'); ?>
      <?php $colorField('secondary', 'Secondary — {{kit.secondary}}'); ?>
      <?php $colorField('text', 'Text — {{kit.text}}'); ?>
      <?php $colorField('accent', 'Accent — {{kit.accent}}'); ?>
      <?php $colorField('background', 'Background — {{kit.background}}'); ?>
      <?php $colorField('link', 'Link — {{kit.link}}'); ?>
    </tbody>
  </table>

  <h2><?= $e(__('pageforge_kit_fonts', 'Global fonts')) ?></h2>
  <table class="table">
    <tbody>
      <?php foreach (['heading' => 'Heading font', 'body' => 'Body font'] as $fk => $flabel): ?>
      <tr>
        <td><?= $e($flabel) ?></td>
        <td>
          <select name="fonts[<?= $e($fk) ?>]">
            <option value=""><?= $e('Follow site (' . $site['font_key'] . ')') ?></option>
            <?php foreach ($stacks as $key => $stack): ?>
              <option value="<?= $e($key) ?>" <?= $ovVal('fonts', $fk) === $key ? 'selected' : '' ?>><?= $e($key) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><code><?= $e($stacks[$kit['fonts'][$fk]] ?? '') ?></code></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2><?= $e(__('pageforge_kit_theme', 'Theme style — buttons & layout')) ?></h2>
  <table class="table">
    <tbody>
      <tr>
        <td>Button background</td>
        <td><code><?= $e($kit['button']['background']) ?></code></td>
        <td><input type="text" name="button[background]" value="<?= $e($ovVal('button', 'background')) ?>" placeholder="<?= $e($kit['button']['background']) ?>" size="10" maxlength="7"></td>
      </tr>
      <tr>
        <td>Button text</td>
        <td><code><?= $e($kit['button']['text']) ?></code></td>
        <td><input type="text" name="button[text]" value="<?= $e($ovVal('button', 'text')) ?>" placeholder="<?= $e($kit['button']['text']) ?>" size="10" maxlength="7"></td>
      </tr>
      <tr>
        <td>Button radius</td>
        <td><?= $e($kit['button']['radius']) ?></td>
        <td>
          <select name="button[radius]">
            <option value=""><?= $e('Follow site (' . $site['radius_key'] . ')') ?></option>
            <?php foreach (PageForgeKit::radiusMap() as $key => $px): ?>
              <option value="<?= $e($key) ?>" <?= $ovVal('button', 'radius') === $key ? 'selected' : '' ?>><?= $e($key . ' — ' . $px) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <td>Content width (px, 0 = full)</td>
        <td><?= (int) $kit['layout']['content_width'] ?></td>
        <td><input type="number" name="layout[content_width]" value="<?= $e($ovVal('layout', 'content_width')) ?>" placeholder="0" min="0" max="2000"></td>
      </tr>
      <tr>
        <td>Widget gap (px)</td>
        <td><?= (int) $kit['layout']['widget_gap'] ?></td>
        <td><input type="number" name="layout[widget_gap]" value="<?= $e($ovVal('layout', 'widget_gap')) ?>" placeholder="20" min="0" max="100"></td>
      </tr>
      <tr>
        <td>Tablet breakpoint (px)</td>
        <td><?= (int) $kit['breakpoints']['tablet'] ?></td>
        <td><input type="number" name="breakpoints[tablet]" value="<?= $e($ovVal('breakpoints', 'tablet')) ?>" placeholder="1024" min="480" max="1600"></td>
      </tr>
      <tr>
        <td>Mobile breakpoint (px)</td>
        <td><?= (int) $kit['breakpoints']['mobile'] ?></td>
        <td><input type="number" name="breakpoints[mobile]" value="<?= $e($ovVal('breakpoints', 'mobile')) ?>" placeholder="767" min="320" max="1200"></td>
      </tr>
    </tbody>
  </table>

  <h2><?= $e(__('pageforge_kit_custom_css', 'Site-wide custom CSS')) ?></h2>
  <p><?= $e(__('pageforge_kit_custom_css_note', 'Appended last to every compiled PageForge page. HTML is stripped for safety.')) ?></p>
  <textarea name="custom_css" rows="6" class="pf-wide"><?= $e($ov['custom_css'] ?? '') ?></textarea>

  <p><button type="submit" class="pf-primary"><?= $e(__('save', 'Save')) ?></button></p>
</form>
</div>

<div class="pf-card">
<h2><?= $e(__('pageforge_kit_transfer', 'Export / import kit')) ?></h2>
<p><?= $e(__('pageforge_kit_transfer_note', 'Export downloads one package file (site identity snapshot + kit overrides). Import applies only the parts you tick; unticked parts stay untouched.')) ?></p>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="export_kit">
  <p><button type="submit"><?= $e(__('pageforge_export', 'Export kit (.json)')) ?></button></p>
</form>
<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="import_kit">
  <p><input type="file" name="kit_file" accept=".json,application/json"></p>
  <p><textarea name="kit_json" rows="4" class="pf-wide" placeholder="<?= $e(__('pageforge_import_paste', '…or paste a kit package here')) ?>"></textarea></p>
  <p>
    <label><input type="checkbox" name="parts[identity]" value="1"> <?= $e('Site identity') ?></label>
    <label><input type="checkbox" name="parts[colors]" value="1" checked> <?= $e('Global colors') ?></label>
    <label><input type="checkbox" name="parts[fonts]" value="1" checked> <?= $e('Global fonts') ?></label>
    <label><input type="checkbox" name="parts[theme]" value="1" checked> <?= $e('Theme style') ?></label>
    <label><input type="checkbox" name="parts[settings]" value="1"> <?= $e('Site settings (breakpoints, custom CSS)') ?></label>
  </p>
  <p><label><input type="checkbox" name="confirm_overwrite" value="1"> <?= $e(__('pageforge_import_confirm_label', 'Overwrite the selected parts on this site')) ?></label></p>
  <p><button type="submit"><?= $e(__('pageforge_import', 'Import kit')) ?></button></p>
</form>
</div>
</div>
<?php require $root . '/admin/partials/footer.php'; ?>
