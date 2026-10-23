// PageForge editor build — bundles the React canvas shell into
// ../assets/editor/editor.js (prebuilt output ships; installs never run Node).
import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));

await build({
  entryPoints: [path.join(here, 'src/editor.jsx')],
  bundle: true,
  format: 'esm',
  platform: 'browser',
  target: ['es2020'],
  jsx: 'automatic',
  minify: true,
  outfile: path.join(here, '../assets/editor/editor.js'),
  logLevel: 'info',
});
