# PageForge editor parity: working notes

The target is the workflow and density of a modern page-builder editor
(one left panel that swaps between the element list, an element's
controls and the page panel; a thin top bar; a floating structure
window; a real compiled canvas), drawn with Kohevo's own icons, type
and accent. Nothing is copied: no assets, wording or code. This file
is PageForge's equivalent of Studio's EDITOR-PARITY.md — the token
table and measurements below are sampled from the shipped
`assets/editor/editor.css`, not aspirational.

## Reference palette (as shipped)

| Role | Value | Where |
| --- | --- | --- |
| Top bar / darkest chrome | `#101014` | `.pfe-topbar` |
| Panel surface | `#17171d` | `.pfe-panel` |
| Elevated / button surface | `#1f1f27` | `.pfe-btn` |
| Canvas surround | `#26262b` | `.pfe` |
| Input / icon-button surface | `#1b1b22` | `.pfe-devices`, `.pfe-iconbtn` |
| Border | `#26262e` / `#2c2c36` | panel + control borders |
| Body text | `#e8e8ee` | `.pfe` |
| Muted / label text | `#9a9aa7` | device buttons, panel tabs |
| Accent (Kohevo violet) | `#454299` | brand mark, primary buttons, active states |
| Selection violet | `#6a63f0` | canvas selection (per editor.css header) |
| Draft badge | bg `#3a3413`, text `#f2cd4b` | `.pfe-badge.is-draft` |
| Published badge | bg `#12351f`, text `#4ade80` | `.pfe-badge.is-pub` |

## Measurements (as shipped)

| Part | PageForge now |
| --- | --- |
| Left panel width | 302px (`.pfe-panel`) |
| Top bar height | 54px (`.pfe-topbar`) |
| Panel tab height | 42px (`.pfe-ptabs button`) |
| Button height | 36px (`.pfe-btn`) |
| Icon button | 34×34px (`.pfe-iconbtn`) |
| History drawer | 300px, fixed below the top bar (`.pfe-history`) |
| Responsive breakpoints | tablet ≤1024px, mobile ≤767px (compiled CSS) |

## Surface parity checklist

1. Editor shell (top bar, Elements ⇄ Inspector ⇄ Page panel,
   floating Structure, iframe canvas fed by the real compiled
   preview): **Done** — editor redesign + Phase 9–11 port, live.
2. History/autosave/context menu/Finder/inline editing in the
   shell: **Done** — the port.
3. Display conditions + popup settings + templates + dynamic tags
   reachable inside the shell (Page panel): **Done** — the port.
4. Admin-side shared UI layer (sub-nav + admin stylesheet),
   Studio-style: **Planned** — see docs/ORGANIZATION.md, Tier 1.
5. Classic `?classic=1` surface: kept as the no-JS fallback;
   visual alignment with the admin layer: **Planned** — Tier 2.

## Rules that keep parity honest

- Every control must affect the render; the control audit
  (`tools/audit-controls.php`, 1440 probes) is the gate, not opinion.
- Published pages serve compilations only; the editor canvas is fed
  by the same compiled output the public sees.
- Tokens change here first, then in CSS — never the other way round.
