# PageForge — plugin map

How this plugin is organized, and where everything lives. PageForge
is a self-contained Kohevo plugin (slug/entitlement `pageforge`) with
its own engine; it does not depend on WordPress or on Studio.

## Layout

| Path | Job |
| --- | --- |
| `PageForge.php` | Bootstrap: routes (`/forge/<slug>`), admin nav, hooks. (Deployed on the VPS as `Pageforge.php` — the PluginLoader's slugToClass casing; repo filename fix is a known open hotfix.) |
| `plugin.json` | Manifest: entitlement, permissions, nav. |
| `install.sql` / `uninstall.sql` | The 3 tables: `pageforge_pages`, `pageforge_revisions`, `pageforge_compilations`. Schema self-heals at runtime via `PageForgeStore::ensureSchema()`. |
| `lib/` | The engine, one class per file: Widgets (registry + sanitizer), Renderer, Css, Store, Kit, Display (conditions + popup config), Library (dynamic tags + page packages), Conditions, Setup, Icons. |
| `admin/` | `index.php` page list + checklist + import; `builder.php` the editor shell (full-screen React app + classic fallback panels); `setup.php` the W1 wizard; `site-settings.php` Kit/site settings + kit export/import; `api.php` preview/autosave/revision endpoints. |
| `public/router.php` | Public serving: published compilations only, theme-part injection, popup embedding. |
| `assets/pageforge.css` | Public compiled-page companion styles (keyframes, popup positions). |
| `assets/editor/` | The built editor bundle (`editor.js`) + `editor.css` design system. Built from `ui/src/` — never edit the bundle by hand. |
| `ui/` | Editor sources: `src/editor.jsx`, `src/history.js` (framework-free history core), `build.mjs` (esbuild). |
| `tools/` | Verification harnesses: `smoke.php`, `audit-controls.php` (1440 control probes), `store-e2e.php`, `reference-builds.php` (Phase 12 final gate: fresh-tenant E2E + 4 reference builds), `test-history.mjs`. |
| `docs/` | This map + `EDITOR-PARITY.md` (tokens, measurements, parity checklist). Phase reports live in the goal workspace, not here. |

## Surfaces

1. **Editor** (builder.php, no `?classic`) — the product: full-screen
   dark builder. Tokens in `docs/EDITOR-PARITY.md`.
2. **Classic builder** (builder.php?classic=1) — admin-embedded
   surface + no-JS fallback forms; shares every store handler with
   the shell.
3. **Admin pages** — index / setup / site-settings on the platform
   admin chrome.
4. **Public** — `/forge/<slug>` compilations; JS-free by design
   except the popup trigger engine.

## Organization tiers (Studio alignment, 2026-10-24 audit)

- **Tier 0 — docs (done):** this file + EDITOR-PARITY.md.
- **Tier 1 — shared admin layer (built 2026-10-24):** `admin/_ui.php`
  partial (sub-nav + flash + wizard steps) and `assets/admin.css`,
  absorbing the admin pages' hand-rolled scaffolds and inline
  styles. Presentation only; field/handler contracts unchanged.
- **Tier 2 — classic surface facelift (built 2026-10-24):** the
  classic surface's fallback panel under the admin.css `pf-panel`
  component (+ `pf-doc`, `pf-preview`). Presentation only.

Out of scope for all tiers: engine behaviour, storage, routes,
permissions, compiled output, the editor's approved visuals.
