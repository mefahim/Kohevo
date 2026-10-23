# PageForge editor UI

React canvas shell for the PageForge builder. Developer build only — the
prebuilt bundle in `../assets/editor/` is what ships; customer installs
never run Node.

```bash
npm install
npm run build   # writes ../assets/editor/editor.js (esbuild, minified)
```

Boot contract: `admin/builder.php` sets `window.PAGEFORGE_BOOT` (page id,
document, widget schemas, style-control schemas, CSRF token, save/preview
URLs) and mounts `#pageforge-editor`. Preview round-trips `admin/api.php`;
saving posts the document JSON back to `builder.php` — the same contract
as the no-JS fallback form.
