<?php
/**
 * PageForge — setup wizard (W1, Phase 7).
 *
 * Six steps, one decision per screen, a primary CTA + Back + Skip on
 * every step, finish drops into the editor — the pattern recorded in
 * the SPEC wizard inventory. Every choice lands in storage the
 * platform consumes: purpose → starter pages; identity → core
 * site_name / brand_sublabel; logo → core brand_logo_path via the
 * core's own Uploads handling; branding → core button/radius keys;
 * kit → PageForgeKit overrides + full recompile.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('pageforge.view');
ModuleGuard::require('pageforge');

require_once dirname(__DIR__) . '/lib/PageForgeSetup.php';
require_once __DIR__ . '/_ui.php';

$pageTitle = __('pageforge_setup', 'PageForge Setup');
$currentNav = 'pageforge';
$flash = null;
$userId = (int) (Auth::user()['id'] ?? 0);
$step = max(1, min(6, (int) ($_GET['step'] ?? 1)));
$restart = isset($_GET['restart']);
$site = PageForgeKit::siteSync();
$identity = PageForgeSetup::siteIdentity();
$siteName = $identity['site_name'] !== '' ? $identity['site_name'] : $site['site_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        Auth::requirePerm('pageforge.edit');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'step1') {
            PageForgeSetup::setPurpose((string) ($_POST['purpose'] ?? 'business'));
            header('Location: setup.php?step=2' . ($restart ? '&restart=1' : ''));
            exit;
        } elseif ($action === 'step2') {
            PageForgeSetup::setSiteIdentity((string) ($_POST['site_name'] ?? ''), (string) ($_POST['tagline'] ?? ''));
            header('Location: setup.php?step=3' . ($restart ? '&restart=1' : ''));
            exit;
        } elseif ($action === 'step3') {
            $logoError = null;
            if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK && class_exists('Uploads')) {
                try {
                    $result = Uploads::handle('logo', 'branding', [
                        'allowed_mimes' => ['image/png', 'image/jpeg', 'image/webp'],
                        'max_bytes'     => 2 * 1024 * 1024,
                    ]);
                    if ($result['ok']) {
                        PageForgeSetup::setLogoPath((string) $result['path']);
                        // setLogoPath applies the media-library rule; the
                        // upload itself just landed in the branding dir,
                        // so fall back to the core write when needed.
                        if (PageForgeKit::siteSync()['logo_path'] !== (string) $result['path']) {
                            Database::setSetting('brand_logo_path', $result['path']);
                        }
                        if (class_exists('MediaLibrary')) {
                            $info = @getimagesize(SLATE_ROOT . $result['path']);
                            MediaLibrary::register($result['path'], [
                                'mime'          => $result['mime'] ?? '',
                                'size_bytes'    => $result['size'] ?? 0,
                                'width'         => is_array($info) ? ($info[0] ?? null) : null,
                                'height'        => is_array($info) ? ($info[1] ?? null) : null,
                                'original_name' => $result['original'] ?? '',
                            ]);
                        }
                    } else {
                        $logoError = $result['error'] ?? 'Upload failed.';
                    }
                } catch (Throwable $e) {
                    $logoError = 'Logo upload failed. ' . $e->getMessage();
                }
            } elseif (!empty($_POST['picked_logo_path'])) {
                if (!PageForgeSetup::setLogoPath((string) $_POST['picked_logo_path'])) {
                    $logoError = 'Picked logo path is not in the media library.';
                }
            }
            if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
                PageForgeSetup::setLogoPath('');
            }
            if ($logoError !== null) {
                $flash = ['type' => 'error', 'msg' => $logoError];
            } else {
                header('Location: setup.php?step=4' . ($restart ? '&restart=1' : ''));
                exit;
            }
        } elseif ($action === 'step4') {
            PageForgeSetup::setBranding((string) ($_POST['button_text_mode'] ?? 'auto'), (string) ($_POST['border_radius'] ?? 'medium'));
            header('Location: setup.php?step=5' . ($restart ? '&restart=1' : ''));
            exit;
        } elseif ($action === 'step5') {
            $ov = PageForgeKit::storedOverrides();
            $palette = PageForgeSetup::palettes()[(string) ($_POST['palette'] ?? 'site')] ?? ['colors' => []];
            if (($palette['colors'] ?? []) !== []) {
                $ov['colors'] = array_merge($ov['colors'] ?? [], $palette['colors']);
            }
            $pair = PageForgeSetup::fontPairs()[(string) ($_POST['font_pair'] ?? 'site')] ?? ['fonts' => []];
            if (($pair['fonts'] ?? []) !== []) {
                $ov['fonts'] = array_merge($ov['fonts'] ?? [], $pair['fonts']);
            }
            PageForgeKit::saveOverrides($ov);
            PageForgeStore::recompileAllPublished($userId);
            header('Location: setup.php?step=6' . ($restart ? '&restart=1' : ''));
            exit;
        } elseif ($action === 'finish') {
            $name = PageForgeSetup::siteIdentity()['site_name'];
            if (!empty($_POST['create_header'])) {
                PageForgeSetup::createHeaderPart($name, $userId);
            }
            $res = PageForgeSetup::createStarters(PageForgeSetup::purpose(), $name, $userId);
            PageForgeSetup::markDone();
            $target = $res['home_id'] !== null ? 'builder.php?id=' . $res['home_id'] : 'index.php';
            header('Location: ' . $target);
            exit;
        }
    }
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$stepUrl = static fn(int $n): string => 'setup.php?step=' . $n . ($restart ? '&restart=1' : '');

require $root . '/admin/partials/header.php';
?>
<div class="pf-admin">
<?php pf_admin_head('setup', $pageTitle, 'Four quick decisions — everything stays changeable later'); ?>
<?php pf_flash($flash); ?>

<?php if (PageForgeSetup::isDone() && !$restart): ?>
  <p><?= $e(__('pageforge_setup_done_msg', 'Setup is complete. PageForge follows your site settings; adjust the design system any time in Site Settings.')) ?></p>
  <p>
    <a href="index.php"><strong><?= $e(__('pageforge_open', 'Open PageForge')) ?></strong></a> ·
    <a href="site-settings.php"><?= $e(__('pageforge_site_settings', 'Site Settings')) ?></a> ·
    <a href="setup.php?restart=1&step=1"><?= $e(__('pageforge_rerun', 'Re-run the setup wizard')) ?></a>
  </p>
<?php else: ?>
  <?php pf_steps($step, 6); ?>

  <?php if ($step === 1): ?>
    <h2><?= $e(__('pageforge_step1', 'What kind of site is this?')) ?></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="step1">
      <?php foreach (PageForgeSetup::purposes() as $key => $p): ?>
        <p><label><input type="radio" name="purpose" value="<?= $e($key) ?>" <?= PageForgeSetup::purpose() === $key ? 'checked' : '' ?>>
          <?= $e($p['label']) ?> — <?= $e(__('pageforge_creates_pages', 'creates')) ?>: <?= $e(implode(', ', $p['pages'])) ?></label></p>
      <?php endforeach; ?>
      <p><button type="submit"><?= $e(__('continue', 'Continue')) ?></button>
        <a href="<?= $e($stepUrl(2)) ?>"><?= $e(__('skip', 'Skip')) ?></a></p>
    </form>

  <?php elseif ($step === 2): ?>
    <h2><?= $e(__('pageforge_step2', 'Site identity')) ?></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="step2">
      <p><label><?= $e(__('site_name', 'Site name')) ?><br>
        <input type="text" name="site_name" maxlength="120" value="<?= $e($identity['site_name']) ?>" class="pf-wide"></label></p>
      <p><label><?= $e(__('pageforge_tagline', 'Tagline')) ?><br>
        <input type="text" name="tagline" maxlength="120" value="<?= $e($identity['tagline']) ?>" class="pf-wide"></label></p>
      <p><button type="submit"><?= $e(__('continue', 'Continue')) ?></button>
        <a href="<?= $e($stepUrl(1)) ?>"><?= $e(__('back', 'Back')) ?></a> ·
        <a href="<?= $e($stepUrl(3)) ?>"><?= $e(__('skip', 'Skip')) ?></a></p>
    </form>

  <?php elseif ($step === 3): ?>
    <h2><?= $e(__('pageforge_step3', 'Logo')) ?></h2>
    <?php if ($site['logo_path'] !== ''): ?>
      <p><?= $e(__('pageforge_current_logo', 'Current logo:')) ?> <code><?= $e($site['logo_path']) ?></code></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="step3">
      <p><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></p>
      <p><label><?= $e(__('pageforge_or_picked', '…or a media-library path')) ?><br>
        <input type="text" name="picked_logo_path" value="" class="pf-wide"></label></p>
      <p><label><input type="checkbox" name="remove_logo" value="1"> <?= $e(__('pageforge_remove_logo', 'Remove the current logo')) ?></label></p>
      <p><button type="submit"><?= $e(__('continue', 'Continue')) ?></button>
        <a href="<?= $e($stepUrl(2)) ?>"><?= $e(__('back', 'Back')) ?></a> ·
        <a href="<?= $e($stepUrl(4)) ?>"><?= $e(__('skip', 'Skip')) ?></a></p>
    </form>

  <?php elseif ($step === 4): ?>
    <h2><?= $e(__('pageforge_step4', 'Branding')) ?></h2>
    <p><?= $e(__('pageforge_step4_note', 'These write to your site branding (Settings → Branding) — PageForge syncs from there.')) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="step4">
      <p><label><?= $e(__('pageforge_button_text', 'Button text on accent')) ?><br>
        <select name="button_text_mode">
          <?php foreach (['auto', 'light', 'dark'] as $m): ?>
            <option value="<?= $e($m) ?>" <?= $site['button_text_mode'] === $m ? 'selected' : '' ?>><?= $e($m) ?></option>
          <?php endforeach; ?>
        </select></label></p>
      <p><label><?= $e(__('pageforge_radius', 'Border radius')) ?><br>
        <select name="border_radius">
          <?php foreach (PageForgeKit::radiusMap() as $rk => $px): ?>
            <option value="<?= $e($rk) ?>" <?= $site['radius_key'] === $rk ? 'selected' : '' ?>><?= $e($rk . ' — ' . $px) ?></option>
          <?php endforeach; ?>
        </select></label></p>
      <p><button type="submit"><?= $e(__('continue', 'Continue')) ?></button>
        <a href="<?= $e($stepUrl(3)) ?>"><?= $e(__('back', 'Back')) ?></a> ·
        <a href="<?= $e($stepUrl(5)) ?>"><?= $e(__('skip', 'Skip')) ?></a></p>
    </form>

  <?php elseif ($step === 5): ?>
    <h2><?= $e(__('pageforge_step5', 'Design system (Kit)')) ?></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="step5">
      <p><strong><?= $e(__('pageforge_palette', 'Color palette')) ?></strong></p>
      <?php foreach (PageForgeSetup::palettes() as $key => $p): ?>
        <p><label><input type="radio" name="palette" value="<?= $e($key) ?>" <?= $key === 'site' ? 'checked' : '' ?>>
          <?= $e($p['label']) ?>
          <?php foreach (($p['colors'] ?? []) as $ck => $hex): ?>
            <?php if ($ck === 'primary' || $ck === 'secondary'): ?><span class="pf-swatch" style="background:<?= $e($hex) ?>;"></span><?php endif; ?>
          <?php endforeach; ?>
        </label></p>
      <?php endforeach; ?>
      <p><strong><?= $e(__('pageforge_font_pair', 'Font pairing')) ?></strong></p>
      <?php foreach (PageForgeSetup::fontPairs() as $key => $p): ?>
        <p><label><input type="radio" name="font_pair" value="<?= $e($key) ?>" <?= $key === 'site' ? 'checked' : '' ?>> <?= $e($p['label']) ?></label></p>
      <?php endforeach; ?>
      <p><button type="submit"><?= $e(__('continue', 'Continue')) ?></button>
        <a href="<?= $e($stepUrl(4)) ?>"><?= $e(__('back', 'Back')) ?></a> ·
        <a href="<?= $e($stepUrl(6)) ?>"><?= $e(__('skip', 'Skip')) ?></a></p>
    </form>

  <?php else: ?>
    <h2><?= $e(__('pageforge_step6', 'Finish')) ?></h2>
    <p><?= $e(sprintf(__('pageforge_finish_summary', 'Purpose: %s · Site: %s · Logo: %s'),
        PageForgeSetup::purposes()[PageForgeSetup::purpose()]['label'] ?? '',
        $siteName,
        $site['logo_path'] !== '' ? 'set' : 'not set')) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="finish">
      <p><label><input type="checkbox" name="create_header" value="1" checked> <?= $e(__('pageforge_create_header', 'Create and publish a header with the site name')) ?></label></p>
      <p><?= $e(__('pageforge_finish_note', 'Starter pages for your purpose are created as drafts; Home is published. Finishing opens Home in the builder.')) ?></p>
      <p><button type="submit"><?= $e(__('pageforge_finish', 'Finish setup')) ?></button>
        <a href="<?= $e($stepUrl(5)) ?>"><?= $e(__('back', 'Back')) ?></a></p>
    </form>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php require $root . '/admin/partials/footer.php'; ?>
