// Kohevo Studio builder — 0ms Real-Time Live Canvas DOM Synchronizer.
//
// In visual site builders, every keystroke in the property panel or style sidebar
// must reflect on the screen in 0 milliseconds without waiting for server round-trips
// or full iframe reloads.
//
// This module inspects working document changes and directly syncs:
//   1. Section surfaces, padding tokens, and colors.
//   2. Block text props (heading, title, subtitle, eyebrow, text, content, buttonText, label, etc.)
//   3. Complex block items (feature lists, cards, columns, portfolio items)
//   4. Block alignment (classes). The rest of a block's style is CSS the server writes (scoped, content-addressed
//      rules). `core/liveStyleSync.mjs` predicts it for the edits it can (and the server's render then confirms it);
//      a style it cannot predict repaints the canvas from the server instead.
//   5. Breakpoint visibility tokens and animation variables
//
// When changes are non-structural (only props or styles changed), iframe reloads
// are completely suppressed — guaranteeing instantaneous preview response.

import { docHasMarkupTextChange, nodeIds } from './doc.mjs';
import { styleChangeIsLive } from './liveStyleSync.mjs';

const BREAKPOINTS = ['base', 'sm', 'md', 'lg'];
const HIDE_PREFIX = 'sb-hide-';
const ALIGN_PREFIX = 'sb-align-';

function escapeHtml(str) {
  if (typeof str !== 'string') return '';
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function cssEscape(id) {
  return String(id).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`);
}

function isPlainObject(v) {
  return Boolean(v) && typeof v === 'object' && !Array.isArray(v);
}

/**
 * Checks whether changes between prevDoc and nextDoc require a full structural
 * iframe reload (e.g. sections or blocks added, removed, reordered, or types changed).
 *
 * If this returns false, the document structure is identical and `syncLiveDOM`
 * has already updated all visuals in 0ms — no iframe reload is needed!
 *
 * `tokens` (`{ has(ref) }`) lets a style change the client can predict count as non-structural; without it every
 * style change but alignment is structural, as before.
 */
export function isStructuralChange(prevDoc, nextDoc, tokens = null) {
  if (!prevDoc || !nextDoc) return true;
  const prevSections = Array.isArray(prevDoc.sections) ? prevDoc.sections : [];
  const nextSections = Array.isArray(nextDoc.sections) ? nextDoc.sections : [];
  if (prevSections.length !== nextSections.length) return true;

  for (let s = 0; s < nextSections.length; s += 1) {
    const prevSec = prevSections[s];
    const nextSec = nextSections[s];
    if (!prevSec || !nextSec || prevSec.id !== nextSec.id) return true;
    if (differs(prevSec.animation, nextSec.animation) || differs(prevSec.interactions, nextSec.interactions)) return true; // section motion: classes written by the server
    if (differs(prevSec.style, nextSec.style) && !liveStyle({ kind: 'section', node: prevSec }, { kind: 'section', node: nextSec }, tokens)) return true; // section background/padding: scoped CSS from the server
    if (blocksChanged(prevSec.blocks, nextSec.blocks, tokens)) return true;
  }
  return false;
}

const asHighlight = (block) => isPlainObject(block.props) && typeof block.props.highlight === 'string' && block.props.highlight.trim() !== '';

function differs(a, b) {
  return a !== b && JSON.stringify(a === undefined ? null : a) !== JSON.stringify(b === undefined ? null : b);
}

/** A block's style minus `align`, the one style key the live patch applies as classes (everything else is server CSS). */
function styleWithoutAlign(style) {
  if (!isPlainObject(style)) return null;
  const { align, ...rest } = style; // eslint-disable-line no-unused-vars
  return Object.keys(rest).length ? rest : null;
}

/** Block fields whose effect is CSS, a wrapper tag or attributes written only by the server's renderer. */
const SERVER_PAINTED_FIELDS = ['style_states', 'tag', 'classNames', 'attributes'];

/**
 * Blocks whose markup is built only on the server (no heuristic live patch exists for them): any
 * property edit repaints the canvas from the server instead of being patched in place.
 */
export const SERVER_RENDERED_TYPES = new Set(['core.icon', 'core.list', 'core.quote', 'core.link', 'core.card', 'core.table', 'core.countdown', 'core.divider', 'core.spacer', 'core.nav_menu', 'core.map']);

/**
 * Whether a block's markup is built only by the server, so any property edit repaints it from the server: the core types listed
 * above, and every block a module or a custom widget brings (their renderers are PHP and nothing in the editor can predict them).
 * Only the `core.*` blocks with a live patch and the `layout.*` containers are painted ahead.
 */
export const isServerRenderedType = (type) => {
  const t = String(type || '');
  return SERVER_RENDERED_TYPES.has(t) || !(t.startsWith('core.') || t.startsWith('layout.'));
};

/**
 * Types whose heading can carry a highlighted word: the server wraps the first occurrence
 * in a span.sb-hl, which a plain textContent write would erase. While BOTH states carry a
 * highlight, patchHighlightedHeading rewrites the text around the span instead, and the
 * generic title write is kept away from the element. A highlight that appears or
 * disappears falls through to the generic write on purpose: plain text is exactly what
 * the server renders for those states. The repaint still confirms everything.
 */
const HIGHLIGHT_TYPES = new Set(['core.heading', 'core.hero']);

/**
 * Server-rendered types whose visible text and class-level props can still be patched in
 * place safely (see patchServerRenderedBlock): the canvas shows the edit instantly, and
 * the repaint after the save — which isStructuralChange keeps requiring for these types —
 * confirms the exact server markup. A type joins this set only when every element its
 * patch can touch is located by an exact class and written with textContent,
 * setAttribute or a class swap from a known set.
 */
const LIVE_PATCH_TYPES = new Set(['core.quote', 'core.link', 'core.list', 'core.card', 'core.table', 'core.countdown', 'core.divider', 'core.spacer', 'core.icon', 'core.nav_menu', 'core.map']);

/**
 * Module and theme types that reach the generic patchers (they are server-rendered by
 * the prefix rule, not by the set above) but carry a few author-text props the generic
 * patchers cannot know — a catalog's call-to-action label, a payment link's validity
 * note, a search box's placeholder. patchModuleBlock covers exactly those props; the
 * generic flow still runs afterwards for everything else (alignment, visibility, the
 * heading the generic patcher already finds).
 */
const LIVE_MODULE_PATCH_TYPES = new Set(['booking.services', 'membership.plans', 'forms.form_card', 'payment.link', 'theme.search_box']);

/** The per-device style overrides (`responsive.tablet|mobile.style`): @media rules written only by the server. */
function deviceStyles(block) {
  const r = isPlainObject(block.responsive) ? block.responsive : {};
  return { tablet: isPlainObject(r.tablet) ? r.tablet.style : undefined, mobile: isPlainObject(r.mobile) ? r.mobile.style : undefined };
}

/** Compare two block lists to any depth: ids, types, child counts and the props of server-rendered types. */
function liveStyle(prevEntry, nextEntry, tokens) {
  return !!tokens && styleChangeIsLive(prevEntry, nextEntry, tokens);
}

function blocksChanged(prev, next, tokens = null) {
  const prevBlocks = Array.isArray(prev) ? prev : [];
  const nextBlocks = Array.isArray(next) ? next : [];
  if (prevBlocks.length !== nextBlocks.length) return true;
  for (let i = 0; i < nextBlocks.length; i += 1) {
    const pb = prevBlocks[i];
    const nb = nextBlocks[i];
    if (!pb || !nb || pb.id !== nb.id || pb.type !== nb.type) return true;
    const styled = differs(styleWithoutAlign(pb.style), styleWithoutAlign(nb.style)) || differs(deviceStyles(pb), deviceStyles(nb));
    if (styled && !liveStyle({ kind: 'block', node: pb }, { kind: 'block', node: nb }, tokens)) return true;
    if (SERVER_PAINTED_FIELDS.some((f) => differs(pb[f], nb[f]))) return true;
    if (HIGHLIGHT_TYPES.has(nb.type) && (asHighlight(pb) || asHighlight(nb)) && differs(pb.props, nb.props)) return true;
    if (isServerRenderedType(nb.type) && pb.props !== nb.props && JSON.stringify(pb.props) !== JSON.stringify(nb.props)) return true;
    if (blocksChanged(pb.children, nb.children, tokens)) return true;
  }
  return false;
}

/**
 * Indexes all blocks in a document tree by id.
 */
function indexAllBlocks(doc) {
  const map = new Map();
  const sections = Array.isArray(doc && doc.sections) ? doc.sections : [];
  sections.forEach((s) => {
    const walk = (blocks) => {
      (Array.isArray(blocks) ? blocks : []).forEach((b) => {
        if (b && typeof b.id === 'string') map.set(b.id, b);
        if (b && Array.isArray(b.children)) walk(b.children);
      });
    };
    walk(s && s.blocks);
  });
  return map;
}

/** The class a renderer emits for an enum prop: the value when allowed, its default otherwise (mirrors the PHP pick()). */
function enumClass(prefix, allowed, value, fallback) {
  return prefix + (allowed.includes(value) ? value : fallback);
}

/** Swap one class of a known set on an element. Returns 1 when the element changed. */
function swapClassFromSet(target, classNames, wanted) {
  if (!target) return 0;
  let n = 0;
  for (const c of classNames) {
    if (c !== wanted && target.classList.contains(c)) {
      target.classList.remove(c);
      n = 1;
    }
  }
  if (!target.classList.contains(wanted)) {
    target.classList.add(wanted);
    n = 1;
  }
  return n;
}

const COUNTDOWN_TARGET_RE = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])T([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/;

/**
 * The UTC instant a countdown target names, exactly as CountdownRenderer computes it —
 * or null when the target is not a real moment (the pattern, or a roll-over date like
 * Feb 31 that PHP refuses and renders as nothing: the repaint owns those).
 */
function countdownTargetUtc(target) {
  if (typeof target !== 'string' || !COUNTDOWN_TARGET_RE.test(target)) return null;
  const d = new Date(target);
  if (Number.isNaN(d.getTime())) return null;
  let offMin = 0;
  if (!target.endsWith('Z')) {
    const sign = target.slice(-6, -5) === '+' ? 1 : -1;
    offMin = sign * (Number(target.slice(-5, -3)) * 60 + Number(target.slice(-2)));
  }
  const local = new Date(d.getTime() + offMin * 60000);
  const ymd = `${local.getUTCFullYear()}-${String(local.getUTCMonth() + 1).padStart(2, '0')}-${String(local.getUTCDate()).padStart(2, '0')}`;
  if (ymd !== target.slice(0, 10)) return null;
  const iso = d.toISOString().replace(/\.\d{3}Z$/, 'Z');
  return { iso, line: `${iso.slice(0, 10)} ${iso.slice(11, 16)} UTC` };
}

/**
 * Instant patch for the server-rendered types in LIVE_PATCH_TYPES, whose visible text
 * sits in elements an exact class names and whose class-level props come from small
 * known sets (the Quote/Link/List/Card/Table/Countdown/Divider/Spacer/Icon renderers
 * in the PHP render layer). Conservative by design: it only writes when the element the
 * server rendered is already there — an element that would have to be created or
 * removed (a first author line, an added table row) is left for the repaint after the
 * save, which isStructuralChange still requires for these types, so the canvas never
 * drifts from the server markup for long.
 */
function patchServerRenderedBlock(el, type, prevProps, nextProps) {
  let n = 0;
  const setText = (target, value) => {
    if (target && typeof value === 'string' && target.textContent !== value) {
      target.textContent = value;
      n += 1;
    }
  };
  const setAttr = (target, name, value) => {
    if (target && target.getAttribute(name) !== value) {
      target.setAttribute(name, value);
      n += 1;
    }
  };

  if (type === 'core.quote') {
    if (nextProps.text !== prevProps.text) setText(el.querySelector('.sb-quote__text p'), nextProps.text);
    if (nextProps.author !== prevProps.author) setText(el.querySelector('.sb-quote__author'), nextProps.author);
    if (nextProps.role !== prevProps.role) setText(el.querySelector('.sb-quote__role'), nextProps.role);
    return n;
  }

  if (type === 'core.link') {
    const prev = isPlainObject(prevProps.link) ? prevProps.link : {};
    const next = isPlainObject(nextProps.link) ? nextProps.link : {};
    const a = el.querySelector('a');
    if (a) {
      if (next.label !== prev.label) setText(a, next.label);
      if (typeof next.href === 'string' && next.href !== prev.href) setAttr(a, 'href', next.href);
    }
    return n;
  }

  if (type === 'core.list') {
    if (!Array.isArray(nextProps.items)) return 0;
    const visible = nextProps.items
      .map((item) => (isPlainObject(item) && typeof item.text === 'string' ? item.text.trim() : ''))
      .filter((text) => text !== '');
    const list = el.querySelector('.sb-list');
    const rows = list ? Array.from(list.children || []).filter((c) => c.classList && c.classList.contains('sb-list__item')) : [];
    if (rows.length !== visible.length) return 0; // rows added or removed: the repaint owns it
    visible.forEach((text, i) => setText(rows[i], text));
    return n;
  }

  if (type === 'core.card') {
    const card = el.querySelector('.sb-card');
    if (nextProps.variant !== prevProps.variant) {
      n += swapClassFromSet(card, ['sb-card--outlined', 'sb-card--filled', 'sb-card--shadow'], enumClass('sb-card--', ['outlined', 'filled', 'shadow'], nextProps.variant, 'outlined'));
    }
    if (nextProps.padding !== prevProps.padding) {
      n += swapClassFromSet(card, ['sb-card--pad-sm', 'sb-card--pad-md', 'sb-card--pad-lg'], enumClass('sb-card--pad-', ['sm', 'md', 'lg'], nextProps.padding, 'md'));
    }
    return n;
  }

  if (type === 'core.table') {
    const parseCells = (line) => String(typeof line === 'string' ? line : '').split('|').map((c) => c.trim()).slice(0, 8);
    if (nextProps.caption !== prevProps.caption) {
      setText(el.querySelector('.sb-table__caption'), typeof nextProps.caption === 'string' ? nextProps.caption.trim() : '');
    }
    const rawHeader = parseCells(nextProps.header);
    const header = rawHeader.length === 1 && rawHeader[0] === '' ? [] : rawHeader;
    const rows = (Array.isArray(nextProps.rows) ? nextProps.rows : [])
      .map((row) => (isPlainObject(row) && typeof row.cells === 'string' ? row.cells : ''))
      .filter((line) => line.trim() !== '')
      .map(parseCells);
    const columns = header.length > 0 ? header.length : rows.reduce((m, r) => Math.max(m, r.length), 0);
    if (columns === 0) return n;
    if (header.length > 0) {
      const head = el.querySelector('thead');
      const tr = head ? (head.children || [])[0] : null;
      const ths = tr ? Array.from(tr.children || []) : [];
      if (ths.length === header.length) header.forEach((cell, i) => setText(ths[i], cell));
    }
    const tbody = el.querySelector('tbody');
    const trs = tbody ? Array.from(tbody.children || []) : [];
    if (trs.length === rows.length) {
      rows.forEach((cells, r) => {
        const tds = Array.from((trs[r] && trs[r].children) || []);
        if (tds.length !== columns) return;
        for (let i = 0; i < columns; i += 1) setText(tds[i], cells[i] ?? '');
      });
    }
    return n;
  }

  if (type === 'core.countdown') {
    if (nextProps.label !== prevProps.label) {
      setText(el.querySelector('.sb-countdown__label'), typeof nextProps.label === 'string' ? nextProps.label.trim() : '');
    }
    if (nextProps.done_text !== prevProps.done_text) {
      setText(el.querySelector('.sb-countdown__done'), typeof nextProps.done_text === 'string' ? nextProps.done_text.trim() : '');
    }
    if (nextProps.target !== prevProps.target) {
      const parsed = countdownTargetUtc(nextProps.target);
      const wrap = el.querySelector('.sb-countdown');
      if (parsed && wrap) {
        setAttr(wrap, 'data-sb-countdown', parsed.iso);
        const time = el.querySelector('.sb-countdown__date time');
        if (time) {
          setAttr(time, 'datetime', parsed.iso);
          setText(time, parsed.line);
        }
      }
    }
    return n;
  }

  if (type === 'core.divider') {
    const hr = el.querySelector('.sb-divider');
    if (nextProps.style !== prevProps.style) {
      n += swapClassFromSet(hr, ['sb-divider--solid', 'sb-divider--dashed', 'sb-divider--dotted'], enumClass('sb-divider--', ['solid', 'dashed', 'dotted'], nextProps.style, 'solid'));
    }
    if (nextProps.weight !== prevProps.weight) {
      n += swapClassFromSet(hr, ['sb-divider--thin', 'sb-divider--medium', 'sb-divider--thick'], enumClass('sb-divider--', ['thin', 'medium', 'thick'], nextProps.weight, 'thin'));
    }
    if (nextProps.width !== prevProps.width) {
      n += swapClassFromSet(hr, ['sb-divider--w-full', 'sb-divider--w-wide', 'sb-divider--w-narrow', 'sb-divider--w-short'], enumClass('sb-divider--w-', ['full', 'wide', 'narrow', 'short'], nextProps.width, 'full'));
    }
    if (nextProps.align !== prevProps.align) {
      n += swapClassFromSet(hr, ['sb-divider--left', 'sb-divider--center', 'sb-divider--right'], enumClass('sb-divider--', ['left', 'center', 'right'], nextProps.align, 'center'));
    }
    return n;
  }

  if (type === 'core.spacer') {
    if (nextProps.size !== prevProps.size) {
      n += swapClassFromSet(el.querySelector('.sb-spacer'), ['sb-spacer--xs', 'sb-spacer--sm', 'sb-spacer--md', 'sb-spacer--lg', 'sb-spacer--xl', 'sb-spacer--2xl'], enumClass('sb-spacer--', ['xs', 'sm', 'md', 'lg', 'xl', '2xl'], nextProps.size, 'md'));
    }
    return n;
  }

  if (type === 'core.icon') {
    const icon = el.querySelector('.sb-icon');
    if (nextProps.size !== prevProps.size) {
      n += swapClassFromSet(icon, ['sb-icon--sm', 'sb-icon--md', 'sb-icon--lg', 'sb-icon--xl'], enumClass('sb-icon--', ['sm', 'md', 'lg', 'xl'], nextProps.size, 'md'));
    }
    if (nextProps.align !== prevProps.align) {
      n += swapClassFromSet(icon, ['sb-icon--left', 'sb-icon--center', 'sb-icon--right'], enumClass('sb-icon--', ['left', 'center', 'right'], nextProps.align, 'left'));
    }
    return n;
  }

  if (type === 'core.nav_menu') {
    const nav = el.querySelector('.sb-nav');
    if (nextProps.layout !== prevProps.layout) {
      n += swapClassFromSet(nav, ['sb-nav--horizontal', 'sb-nav--vertical'], enumClass('sb-nav--', ['horizontal', 'vertical'], nextProps.layout, 'horizontal'));
    }
    if (nextProps.align !== prevProps.align) {
      n += swapClassFromSet(nav, ['sb-nav--left', 'sb-nav--center', 'sb-nav--right'], enumClass('sb-nav--', ['left', 'center', 'right'], nextProps.align, 'left'));
    }
    if (nextProps.style !== prevProps.style) {
      n += swapClassFromSet(nav, ['sb-nav--plain', 'sb-nav--underline', 'sb-nav--pill'], enumClass('sb-nav--', ['plain', 'underline', 'pill'], nextProps.style, 'plain'));
    }
    if (nextProps.aria_label !== prevProps.aria_label && nav) {
      const name = typeof nextProps.aria_label === 'string' ? nextProps.aria_label.trim() : '';
      if (name === '') {
        if (nav.getAttribute('aria-label') !== null) { nav.removeAttribute('aria-label'); n += 1; }
      } else {
        setAttr(nav, 'aria-label', name);
      }
    }
    // The block's own links render only while no site menu is bound; a bound menu's
    // items come from the menu store, so props.items edits must not touch them.
    const menuBound = typeof nextProps.menu === 'string' && nextProps.menu.trim() !== '';
    if (!menuBound && Array.isArray(nextProps.items)) {
      const links = nextProps.items.filter((item) => isPlainObject(item) && typeof item.label === 'string' && item.label.trim() !== '' && typeof item.url === 'string' && item.url.trim() !== '');
      const rows = Array.from(el.querySelectorAll('.sb-nav__link') || []);
      if (rows.length === links.length) {
        links.forEach((item, i) => {
          setText(rows[i], item.label);
          setAttr(rows[i], 'href', item.url);
        });
      }
    }
    return n;
  }

  if (type === 'core.map') {
    if (nextProps.height !== prevProps.height) {
      n += swapClassFromSet(el.querySelector('.sb-map'), ['sb-map--sm', 'sb-map--md', 'sb-map--lg'], enumClass('sb-map--', ['sm', 'md', 'lg'], nextProps.height, 'md'));
    }
    const frame = el.querySelector('.sb-map iframe');
    if (frame && (nextProps.latitude !== prevProps.latitude || nextProps.longitude !== prevProps.longitude || nextProps.zoom !== prevProps.zoom)) {
      const src = mapEmbedUrl(nextProps);
      if (src) setAttr(frame, 'src', src);
    }
    if (frame && nextProps.title !== prevProps.title) {
      const title = typeof nextProps.title === 'string' ? nextProps.title.trim() : '';
      setAttr(frame, 'title', title !== '' ? title : 'Map');
    }
    return n;
  }

  return 0;
}

/**
 * The OpenStreetMap embed URL MapRenderer builds for a point: the visible box is about
 * two tiles wide at the chosen zoom, centred on the point, every number at 5 decimals.
 * Null when the point is not a valid coordinate (the block renders nothing then, and
 * the repaint owns it).
 */
function mapEmbedUrl(props) {
  const isNum = (v) => (typeof v === 'number' && Number.isFinite(v)) || (typeof v === 'string' && v.trim() !== '' && Number.isFinite(Number(v)));
  if (!isNum(props.latitude) || !isNum(props.longitude)) return null;
  const lat = Number(props.latitude);
  const lon = Number(props.longitude);
  if (lat < -85 || lat > 85 || lon < -180 || lon > 180) return null;
  let zoom = Math.trunc(Number(props.zoom ?? 13));
  if (!Number.isFinite(zoom)) zoom = 13;
  zoom = Math.max(1, Math.min(19, zoom));
  const lonSpan = (360 / 2 ** zoom) * 2;
  const latSpan = lonSpan * 0.55;
  const n = (v) => v.toFixed(5);
  const bbox = `${n(Math.max(-180, lon - lonSpan / 2))},${n(Math.max(-85, lat - latSpan / 2))},${n(Math.min(180, lon + lonSpan / 2))},${n(Math.min(85, lat + latSpan / 2))}`;
  return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${n(lat)},${n(lon)}`;
}

const STRIPE_LINK_RE = /^(?:https:\/\/(?:buy|checkout|donate|billing)\.stripe\.com\/[A-Za-z0-9_\-/.?=&%#+:~]{1,500})?$/;

/**
 * The few author-text props of module/theme blocks the generic patchers cannot know.
 * Everything data-driven (the rows themselves, prices, the bound entries) stays with the
 * server repaint: this only writes labels and notes whose exact element and text the
 * PHP renderers fix. Empty-value defaults mirror the renderers' English fallbacks.
 */
function patchModuleBlock(el, type, prevProps, nextProps) {
  let n = 0;
  const setText = (target, value) => {
    if (target && typeof value === 'string' && target.textContent !== value) {
      target.textContent = value;
      n += 1;
    }
  };
  const textOr = (v, fallback) => (typeof v === 'string' && v !== '' ? v : fallback);

  if (type === 'booking.services' || type === 'membership.plans') {
    if (nextProps.cta_label !== prevProps.cta_label) {
      setText(el.querySelector('.sb-button-row a'), textOr(nextProps.cta_label, type === 'booking.services' ? 'Book now' : 'Join now'));
    }
    return n;
  }

  if (type === 'forms.form_card') {
    if (nextProps.button_label !== prevProps.button_label) {
      setText(el.querySelector('.sb-button-row a'), textOr(nextProps.button_label, 'Open form'));
    }
    return n;
  }

  if (type === 'payment.link') {
    if (nextProps.url !== prevProps.url) {
      const ok = typeof nextProps.url === 'string' && nextProps.url !== '' && STRIPE_LINK_RE.test(nextProps.url);
      setText(el.querySelector('.sb-embed-card__note'), ok ? 'Visitors who click this go to your Stripe page.' : 'Paste a Stripe Payment Link (https://buy.stripe.com/...) in the Inspector.');
    }
    return n;
  }

  if (type === 'theme.search_box') {
    const input = el.querySelector('.sb-search-input');
    if (input && nextProps.placeholder !== prevProps.placeholder) {
      const value = textOr(nextProps.placeholder, 'Search articles and resources...');
      if (input.getAttribute('placeholder') !== value) { input.setAttribute('placeholder', value); n += 1; }
    }
    if (nextProps.button_text !== prevProps.button_text) {
      setText(el.querySelector('.sb-search-button'), textOr(nextProps.button_text, 'Search'));
    }
    return n;
  }

  return 0;
}

const HL_COLOR_RE = /^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|(rgb|rgba|hsl|hsla)\(\s*[0-9.%\s,\/]{1,60}\))$/;
const HL_KNOWN_CLASSES = ['sb-hl', 'sb-hl--italic', 'sb-hl--underline', 'sb-hl--w-normal', 'sb-hl--w-medium', 'sb-hl--w-bold'];

/**
 * Span surgery for a heading/hero whose highlight is set in both states, mirroring
 * Html::highlighted(): the FIRST occurrence of the (trimmed) word sits in span.sb-hl,
 * flanked by plain text nodes. The text around the span is rewritten in place — no
 * element is created or removed — and the span's look (italic/underline/weight classes,
 * a custom colour as its style, a theme colour as one extra class) is updated with it.
 * The theme-token class is the one class JS cannot name, so it is handled by
 * elimination: any class that is not a known highlight class is the token's. When the
 * word no longer occurs in the text, the server renders the text plainly, and so does
 * this patch. Anything it cannot express is left for the repaint, which
 * isStructuralChange still requires for highlighted blocks.
 */
function patchHighlightedHeading(el, type, prevProps, nextProps) {
  const host = el.querySelector('.sb-hero__heading, .sb-heading, h1, h2, h3, h4, h5, h6');
  if (!host) return 0;
  const span = (Array.from(host.children || []).find((c) => c.classList && c.classList.contains('sb-hl'))) || host.querySelector('.sb-hl');
  if (!span) return 0;
  let n = 0;

  const textProp = type === 'core.hero' ? 'heading' : 'text';
  const nextText = nextProps[textProp];
  const needle = typeof nextProps.highlight === 'string' ? nextProps.highlight.trim() : '';
  const prevNeedle = typeof prevProps.highlight === 'string' ? prevProps.highlight.trim() : '';
  if (typeof nextText === 'string' && (nextText !== prevProps[textProp] || needle !== prevNeedle)) {
    const at = needle === '' ? -1 : nextText.indexOf(needle);
    if (at < 0) {
      if (host.textContent !== nextText) { host.textContent = nextText; n += 1; }
      return n;
    }
    const before = nextText.slice(0, at);
    const after = nextText.slice(at + needle.length);
    const beforeNode = span.previousSibling && span.previousSibling.nodeType === 3 ? span.previousSibling : null;
    const afterNode = span.nextSibling && span.nextSibling.nodeType === 3 ? span.nextSibling : null;
    if ((before !== '' && !beforeNode) || (after !== '' && !afterNode)) return n;
    if (beforeNode && beforeNode.textContent !== before) { beforeNode.textContent = before; n += 1; }
    if (afterNode && afterNode.textContent !== after) { afterNode.textContent = after; n += 1; }
    if (span.textContent !== needle) { span.textContent = needle; n += 1; }
  }

  const setCls = (cls, on) => {
    const has = span.classList.contains(cls);
    if (on && !has) { span.classList.add(cls); n += 1; } else if (!on && has) { span.classList.remove(cls); n += 1; }
  };
  if (nextProps.highlight_italic !== prevProps.highlight_italic) setCls('sb-hl--italic', !!nextProps.highlight_italic);
  if (nextProps.highlight_underline !== prevProps.highlight_underline) setCls('sb-hl--underline', !!nextProps.highlight_underline);
  if (nextProps.highlight_weight !== prevProps.highlight_weight) {
    const w = ['normal', 'medium', 'bold'].includes(nextProps.highlight_weight) ? `sb-hl--w-${nextProps.highlight_weight}` : null;
    ['sb-hl--w-normal', 'sb-hl--w-medium', 'sb-hl--w-bold'].forEach((c) => { if (c !== w && span.classList.contains(c)) { span.classList.remove(c); n += 1; } });
    if (w && !span.classList.contains(w)) { span.classList.add(w); n += 1; }
  }
  if (nextProps.highlight_color !== prevProps.highlight_color || nextProps.highlight_color_token !== prevProps.highlight_color_token) {
    const dropTokenClass = () => {
      Array.from(span.classList).forEach((c) => { if (!HL_KNOWN_CLASSES.includes(c)) { span.classList.remove(c); n += 1; } });
    };
    const custom = typeof nextProps.highlight_color === 'string' ? nextProps.highlight_color : '';
    if (custom !== '' && HL_COLOR_RE.test(custom)) {
      const style = `color:${custom}`;
      if (span.getAttribute('style') !== style) { span.setAttribute('style', style); n += 1; }
      dropTokenClass();
    } else {
      if (span.getAttribute('style')) { span.removeAttribute('style'); n += 1; }
      if (nextProps.highlight_color_token !== prevProps.highlight_color_token) dropTokenClass();
    }
  }
  return n;
}

/**
 * Synchronize live working document changes directly into the canvas iframe DOM in 0ms.
 *
 * @param {Document} canvasDoc
 * @param {Object} prevDoc
 * @param {Object} nextDoc
 * @param {string} [activeBreakpoint='base']
 * @returns {number} count of updated nodes
 */
export function syncLiveDOM(canvasDoc, prevDoc, nextDoc, activeBreakpoint = 'base') {
  if (!canvasDoc || !canvasDoc.querySelector || !nextDoc) return 0;
  // Formatted (inline_html) values are painted by the server repaint (Batch D).
  if (docHasMarkupTextChange(prevDoc, nextDoc)) return 0;

  let updateCount = 0;
  const hlGuarded = new Set();

  try {
    // 1. Sync Sections
    const prevSections = new Map(
      (Array.isArray(prevDoc && prevDoc.sections) ? prevDoc.sections : []).map((s) => [s.id, s])
    );
    const nextSections = Array.isArray(nextDoc.sections) ? nextDoc.sections : [];

    nextSections.forEach((sec) => {
      if (!sec || !sec.id) return;
      const prevSec = prevSections.get(sec.id);
      if (prevSec === sec) return; // Unchanged reference

      const secEl = canvasDoc.querySelector(`[data-sb-node="${cssEscape(sec.id)}"]`);
      if (!secEl) return;

      // Surface token update
      if (sec.surface_token !== (prevSec && prevSec.surface_token)) {
        Array.from(secEl.classList).forEach((cls) => {
          if (cls.startsWith('sb-bg--surface-') || cls.startsWith('sb-bg--accent-')) {
            secEl.classList.remove(cls);
          }
        });
        if (sec.surface_token) {
          secEl.classList.add(`sb-bg--${sec.surface_token}`);
        }
        updateCount += 1;
      }

      // Section padding update
      const padTop = sec.padding_top || 'lg';
      const padBottom = sec.padding_bottom || 'lg';
      const prevPadTop = prevSec ? (prevSec.padding_top || 'lg') : null;
      const prevPadBottom = prevSec ? (prevSec.padding_bottom || 'lg') : null;

      if (padTop !== prevPadTop || padBottom !== prevPadBottom) {
        Array.from(secEl.classList).forEach((cls) => {
          if (/^sb-(py|pt|pb)-/.test(cls)) {
            secEl.classList.remove(cls);
          }
        });
        if (padTop === padBottom) {
          secEl.classList.add(`sb-py-${padTop}`);
        } else {
          secEl.classList.add(`sb-pt-${padTop}`, `sb-pb-${padBottom}`);
        }
        updateCount += 1;
      }
    });

    // 2. Sync Blocks
    const prevBlocks = indexAllBlocks(prevDoc);
    const nextBlocks = indexAllBlocks(nextDoc);

    for (const [id, nextBlock] of nextBlocks) {
      const prevBlock = prevBlocks.get(id);
      if (prevBlock === nextBlock) continue;

      const el = canvasDoc.querySelector(`[data-sb-node="${cssEscape(id)}"]`);
      if (!el) continue;

      const nextProps = isPlainObject(nextBlock.props) ? nextBlock.props : {};
      const prevProps = prevBlock && isPlainObject(prevBlock.props) ? prevBlock.props : {};

      if (SERVER_RENDERED_TYPES.has(nextBlock.type)) {
        // Repainted from the server after the save (see isStructuralChange); a few types
        // patch their visible text ahead of that repaint so the edit shows instantly.
        if (LIVE_PATCH_TYPES.has(nextBlock.type)) {
          updateCount += patchServerRenderedBlock(el, nextBlock.type, prevProps, nextProps);
        }
        continue;
      }
      const hlNext = HIGHLIGHT_TYPES.has(nextBlock.type) && asHighlight(nextBlock);
      const hlPrev = HIGHLIGHT_TYPES.has(nextBlock.type) && !!prevBlock && asHighlight(prevBlock);
      if (hlNext && hlPrev) {
        hlGuarded.add(el);
        updateCount += patchHighlightedHeading(el, nextBlock.type, prevProps, nextProps);
      }

      if (LIVE_MODULE_PATCH_TYPES.has(nextBlock.type)) {
        updateCount += patchModuleBlock(el, nextBlock.type, prevProps, nextProps);
      }

      const nextStyle = isPlainObject(nextBlock.style) ? nextBlock.style : {};
      const prevStyle = prevBlock && isPlainObject(prevBlock.style) ? prevBlock.style : {};

      // ── TEXT & CONTENT PROPS ──
      // Headings & Titles (checks heading, title, or text for heading block types)
      const isHeadingBlock = nextBlock.type === 'core.heading' || (typeof nextBlock.type === 'string' && nextBlock.type.includes('heading'));
      const titleVal = nextProps.heading !== undefined ? nextProps.heading
        : (nextProps.title !== undefined ? nextProps.title : (isHeadingBlock ? nextProps.text : undefined));

      if (titleVal !== undefined) {
        const titleEl = el.querySelector(
          '.sb-hero__heading, .sb-feature-list__title, .sb-heading, h1, h2, h3, h4, h5, h6, [class*="heading"], [class*="title"]'
        ) || (/^h[1-6]$/i.test(el.tagName) ? el : null);
        if (titleEl && !hlGuarded.has(el) && titleEl.textContent !== titleVal) {
          titleEl.textContent = titleVal;
          updateCount += 1;
        }
      }

      // Eyebrow / Badges
      const eyebrowVal = nextProps.eyebrow !== undefined ? nextProps.eyebrow : nextProps.badge;
      if (eyebrowVal !== undefined && eyebrowVal !== (prevProps.eyebrow || prevProps.badge)) {
        const eyeEl = el.querySelector('.sb-hero__eyebrow, [class*="eyebrow"], [class*="badge"]');
        if (eyeEl) {
          eyeEl.textContent = eyebrowVal;
          updateCount += 1;
        }
      }

      // Subheading / Subtitle / Lead
      const subVal = nextProps.subheading !== undefined ? nextProps.subheading : nextProps.subtitle;
      if (subVal !== undefined && subVal !== (prevProps.subheading || prevProps.subtitle)) {
        const subEl = el.querySelector('.sb-hero__subheading, [class*="subheading"], [class*="subtitle"], .sb-lead');
        if (subEl) {
          subEl.textContent = subVal;
          updateCount += 1;
        }
      }

      // Body text / paragraph / content
      const textVal = nextProps.text !== undefined ? nextProps.text : (nextProps.content !== undefined ? nextProps.content : nextProps.body);
      const prevTextVal = prevProps.text !== undefined ? prevProps.text : (prevProps.content !== undefined ? prevProps.content : prevProps.body);
      if (textVal !== undefined && textVal !== prevTextVal && !(hlGuarded.has(el) && nextBlock.type === 'core.heading')) {
        const textEl = el.querySelector('.sb-text, .sb-feature__body, p, [class*="description"], [class*="content"]')
          || (/^h[1-6]|p|span|a|button$/i.test(el.tagName) ? el : null);
        if (textEl) {
          textEl.textContent = textVal;
          updateCount += 1;
        } else if (!el.children || el.children.length === 0) {
          el.textContent = textVal;
          updateCount += 1;
        }
      }

      // Button text / Label
      const btnVal = nextProps.buttonText !== undefined ? nextProps.buttonText : nextProps.label;
      const prevBtnVal = prevProps.buttonText !== undefined ? prevProps.buttonText : prevProps.label;
      if (btnVal !== undefined && btnVal !== prevBtnVal) {
        const btnEl = el.querySelector('.sb-button, .sb-btn, a, button, [class*="button"]');
        if (btnEl) {
          btnEl.textContent = btnVal;
          updateCount += 1;
        }
      }

      // Links / URLs
      if (nextProps.url !== undefined && nextProps.url !== prevProps.url) {
        const linkEl = el.querySelector('a') || (el.tagName.toLowerCase() === 'a' ? el : null);
        if (linkEl) {
          linkEl.setAttribute('href', nextProps.url);
          updateCount += 1;
        }
      }

      // Primary CTA
      if (nextProps.primary_cta && typeof nextProps.primary_cta === 'object') {
        const ctaEl = el.querySelector('.sb-button-row a, .sb-button, a');
        if (ctaEl) {
          if (nextProps.primary_cta.label) ctaEl.textContent = nextProps.primary_cta.label;
          if (nextProps.primary_cta.url) ctaEl.setAttribute('href', nextProps.primary_cta.url);
          updateCount += 1;
        }
      }

      // List / Card / Feature items (core.feature_list, portfolios, grids)
      if (Array.isArray(nextProps.items)) {
        const cardEls = el.querySelectorAll('.sb-feature, .sb-feature-list__items > li, .sb-card, [class*="feature"], [class*="card"], li');
        nextProps.items.forEach((item, idx) => {
          const cardEl = cardEls[idx];
          if (!cardEl || !item || typeof item !== 'object') return;
          if (item.heading !== undefined || item.title !== undefined) {
            const h = cardEl.querySelector('.sb-feature__heading, h2, h3, h4, h5, [class*="heading"], [class*="title"]');
            if (h) h.textContent = item.heading || item.title || '';
          }
          if (item.body !== undefined || item.description !== undefined || item.text !== undefined) {
            const b = cardEl.querySelector('.sb-feature__body, p, [class*="body"], [class*="description"], [class*="text"]');
            if (b) b.textContent = item.body || item.description || item.text || '';
          }
          if (item.url !== undefined) {
            const a = cardEl.querySelector('.sb-feature__link, a');
            if (a) a.setAttribute('href', item.url);
          }
        });
        updateCount += 1;
      }

      // Grid columns
      if (nextProps.columns !== undefined && nextProps.columns !== prevProps.columns) {
        const listEl = el.querySelector('.sb-feature-list__items, [class*="items"]');
        if (listEl) {
          Array.from(listEl.classList).forEach((c) => {
            if (c.startsWith('sb-md-cols-') || c.startsWith('sb-cols-')) listEl.classList.remove(c);
          });
          listEl.classList.add('sb-cols-1', `sb-md-cols-${nextProps.columns}`);
          updateCount += 1;
        }
      }

      // Image / Media
      const srcVal = typeof nextProps.media === 'string'
        ? nextProps.media
        : (nextProps.media && nextProps.media.url ? nextProps.media.url : (nextProps.src || nextProps.image));
      if (srcVal) {
        const imgEl = el.querySelector('img') || (el.tagName.toLowerCase() === 'img' ? el : null);
        if (imgEl && imgEl.src !== srcVal) {
          imgEl.src = srcVal;
          updateCount += 1;
        }
      }

      // Rakib Portfolio custom showcase widget
      if (nextBlock.type === 'portfolio.rakib_showcase') {
        if (nextProps.brand_text) {
          const pf = el.querySelector('.e-pf');
          if (pf) pf.textContent = nextProps.brand_text;
        }
        if (nextProps.first_name || nextProps.last_name) {
          const name = el.querySelector('.e-name');
          if (name) name.innerHTML = `${escapeHtml(nextProps.first_name || '')}<br>${escapeHtml(nextProps.last_name || '')}`;
        }
        if (nextProps.role) {
          const role = el.querySelector('.e-role');
          if (role) role.innerHTML = escapeHtml(nextProps.role).replace(/\n/g, '<br>');
        }
        if (nextProps.location) {
          const loc = el.querySelector('.e-loc');
          if (loc) loc.innerHTML = escapeHtml(nextProps.location).replace(/\n/g, '<br>');
        }
        if (nextProps.coordinates) {
          const coord = el.querySelector('.e-coord');
          if (coord) coord.innerHTML = escapeHtml(nextProps.coordinates).replace(/\n/g, '<br>');
        }
        if (nextProps.domain) {
          const dom = el.querySelector('.e-dom');
          if (dom) dom.textContent = nextProps.domain;
        }
        if (nextProps.email) {
          const mail = el.querySelector('.e-mail');
          if (mail) mail.textContent = nextProps.email;
        }
        updateCount += 1;
      }

      // ── STYLES ──
      // Alignment
      const align = nextStyle.align;
      if (align && typeof align === 'object') {
        Array.from(el.classList).forEach((c) => {
          if (c.startsWith(ALIGN_PREFIX) || c.startsWith('sb-heading--align-') || c.startsWith('sb-text--align-')) {
            el.classList.remove(c);
          }
        });
        Object.entries(align).forEach(([bp, val]) => {
          if (val) {
            el.classList.add(`${ALIGN_PREFIX}${bp}-${val}`);
            el.classList.add(`sb-heading--align-${val}`);
            el.classList.add(`sb-text--align-${val}`);
          }
        });
        const activeVal = align[activeBreakpoint] || align.base || 'left';
        el.style.textAlign = activeVal;
        updateCount += 1;
      }

      // Visibility devices
      if (nextBlock.visibility && Array.isArray(nextBlock.visibility.devices)) {
        BREAKPOINTS.forEach((bp) => {
          const cls = `${HIDE_PREFIX}${bp}`;
          if (!nextBlock.visibility.devices.includes(bp)) {
            el.classList.add(cls);
          } else {
            el.classList.remove(cls);
          }
        });
      }
    }
  } catch (err) {
    // Fail-safe: a sync error must never disrupt the editor
    if (typeof console !== 'undefined' && console.warn) {
      console.warn('syncLiveDOM failed:', err);
    }
  }

  return updateCount;
}

/**
 * Node ids the canvas shows that the document does not have. The live patch and the server's repaint can
 * disagree (a change the patch cannot express, a patch that threw, a reload that never landed); a node the
 * editor cannot find is the one sure sign, because the server only tags nodes that are in the document.
 */
export function ghostNodeIds(domIds, doc) {
  const known = nodeIds(doc);
  return domIds.filter((id) => id && !known.has(id));
}
