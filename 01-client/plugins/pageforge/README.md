# PageForge

Visual page builder plugin for Kohevo — an original-code builder inspired by
the Elementor feature set (document tree, containers, widgets, responsive
style controls, revisions, compiled output). Not affiliated with Elementor;
no Elementor code or assets are used.

## Layout
- `PageForge.php` — plugin bootstrap (admin nav, `/forge` public route).
- `lib/PageForgeWidgets.php` — widget registry + settings sanitizer (save gate).
- `lib/PageForgeRenderer.php` — document normalization + deterministic HTML render.
- `lib/PageForgeCss.php` — settings → scoped CSS compiler (desktop/tablet/mobile).
- `lib/PageForgeStore.php` — tenant-scoped pages/revisions/compilations store.
- `admin/` — page list and the Phase 1 foundation builder (JSON editor +
  server preview; the drag-and-drop canvas arrives in Phase 2).
- `public/router.php` — serves published compilations at `/forge/<slug>`.
- `tools/smoke.php` — CLI smoke test for the pure engine (no DB needed):
  `php tools/smoke.php`

## Licensing
Commercial module, entitlement `pageforge` (see plugin.json). Admin entry
points call `ModuleGuard::require('pageforge')`; the public router calls
`ModuleGuard::requirePublic('pageforge')`.
