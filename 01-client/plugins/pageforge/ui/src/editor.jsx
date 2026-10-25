/**
 * PageForge editor — full-screen visual builder (editor-UX overhaul).
 *
 * The approved design: an Elementor-style immersive editor in Kohevo's own
 * dark system — top bar (page, device switch, save/publish), left Elements
 * panel with a searchable icon grid that becomes the element Inspector
 * (Content / Style tabs), a floating Structure tree, and the page itself on
 * canvas inside a device-width iframe.
 *
 * The canvas is an iframe on purpose: the compiled CSS uses real media
 * queries, so tablet/mobile values only preview truthfully at a true frame
 * width, and compiled styles can never leak into the editor chrome. The
 * iframe document is never reloaded after boot — the bridge swaps the
 * .pf-page content and compiled <style> in place, so editing does not
 * flicker or lose scroll. Every rendered element carries a `pf-el-<id>`
 * class from the engine, which is how canvas clicks map back to tree nodes.
 *
 * Saving still posts the document JSON to builder.php — one write path,
 * one sanitizer gate (PageForgeStore), exactly as before.
 */
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { createHistory } from './history.js';

const boot = window.PAGEFORGE_BOOT;
const widgetByType = Object.fromEntries(boot.widgets.map((w) => [w.type, w]));
const CATEGORIES = [
  ['layout', 'Layout'], ['content', 'Content'], ['media', 'Media'],
  ['interactive', 'Interactive'], ['kohevo', 'Kohevo'],
];
const DEVICES = { desktop: 1280, tablet: 768, mobile: 390 };

const uid = () => 'pf' + Math.random().toString(16).slice(2, 10);
const humanize = (key) => String(key).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/** Widgets whose primary text can be edited inline on the canvas (Phase 9). */
const PRIMARY_TEXT = { heading: 'text', text: 'text', button: 'text' };

/* ---------------- Icons (stroke set, keyed by the defs' icon names) ---------------- */

const PATHS = {
  layout: <><rect x="3.5" y="3.5" width="17" height="17" rx="2" strokeDasharray="4 3" /><path d="M3.5 9.5h17M9.5 9.5v11" /></>,
  heading: <path d="M5 5v14M19 5v14M5 12h14" />,
  text: <path d="M4 6h16M4 10h16M4 14h9M4 18h13" />,
  button: <><rect x="3" y="7" width="18" height="10" rx="5" /><path d="M14.5 10.3l3 1.7-3 1.7z" /></>,
  list: <><path d="M9 6h11M9 12h11M9 18h11" /><path d="M4.5 6h.01M4.5 12h.01M4.5 18h.01" strokeWidth="2.6" /></>,
  image: <><rect x="3" y="5" width="18" height="14" rx="2" /><circle cx="9" cy="10" r="1.6" /><path d="M4 18l5.5-5 3.5 3.5L17 12l3 3.5" /></>,
  play: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M10.5 9.5l4.5 2.5-4.5 2.5z" /></>,
  star: <path d="M12 3.5l2.5 5.3 5.8.7-4.3 4 1.1 5.7L12 16.4l-5.1 2.8L8 13.5 3.7 9.5l5.8-.7z" />,
  quote: <path d="M5 16c-1 0-2-1-2-2.5C3 9 6 5.5 10 4.5l.6 1.7C8 7.3 6.8 9 6.6 10.5c.3-.2.8-.4 1.4-.4 1.4 0 2.5 1.1 2.5 2.6S8.4 15.5 7 15.5c-.7 0-1.4.2-2 .5Zm11 0c-1 0-2-1-2-2.5 0-4.5 3-8 7-9l.6 1.7c-2.6 1.1-3.8 2.8-4 4.3.3-.2.8-.4 1.4-.4 1.4 0 2.5 1.1 2.5 2.6s-1.1 2.8-2.5 2.8c-.7 0-1.4.2-2 .5Z" />,
  progress: <><rect x="3" y="9" width="18" height="6" rx="3" /><path d="M6 12h7" strokeWidth="2.6" /></>,
  number: <path d="M8 5L6 19M18 5l-2 14M4 9h17M3 15h17" />,
  share: <><circle cx="6" cy="12" r="2.6" /><circle cx="17.5" cy="5.5" r="2.6" /><circle cx="17.5" cy="18.5" r="2.6" /><path d="M8.3 10.8l6.9-4M8.3 13.2l6.9 4" /></>,
  warning: <><path d="M12 4L2.8 20h18.4z" /><path d="M12 10v4.5M12 17.2h.01" strokeWidth="2.2" /></>,
  tabs: <><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M3 9.5h18M8.5 9.5V20" /></>,
  form: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M7 10h10M7 14h6" /></>,
  code: <path d="M8 6l-5 6 5 6M16 6l5 6-5 6" />,
  link: <><path d="M9.5 14.5l5-5" /><path d="M11 6.5l1.8-1.8a3.6 3.6 0 0 1 5 5L16 11.5M13 17.5l-1.8 1.8a3.6 3.6 0 0 1-5-5L8 12.5" /></>,
  divider: <path d="M4 12h16" />,
  spacer: <path d="M12 4v16M8 8l4-4 4 4M8 16l4 4 4-4" />,
  widget: <><rect x="3.5" y="3.5" width="8" height="8" rx="1.6" /><rect x="12.5" y="3.5" width="8" height="8" rx="1.6" /><rect x="3.5" y="12.5" width="8" height="8" rx="1.6" /><rect x="12.5" y="12.5" width="8" height="8" rx="1.6" /></>,
  back: <path d="M15 6l-6 6 6 6" />,
  eye: <><path d="M3 12s3.5-6.5 9-6.5S21 12 21 12s-3.5 6.5-9 6.5S3 12 3 12Z" /><circle cx="12" cy="12" r="3" /></>,
  search: <><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></>,
  x: <path d="M6 6l12 12M18 6L6 18" />,
  copy: <><rect x="8.5" y="8.5" width="11.5" height="11.5" rx="2" /><path d="M16 8.5v-2A2.5 2.5 0 0 0 13.5 4h-7A2.5 2.5 0 0 0 4 6.5v7A2.5 2.5 0 0 0 6.5 16h2" /></>,
  trash: <path d="M4 7h16M9.5 7V5A1.5 1.5 0 0 1 11 3.5h2A1.5 1.5 0 0 1 14.5 5v2M6.5 7l1 13h9l1-13" />,
  monitor: <><rect x="3" y="4" width="18" height="12" rx="2" /><path d="M9 20h6M12 16v4" /></>,
  tablet: <><rect x="5" y="3" width="14" height="18" rx="2" /><path d="M11 17.5h2" /></>,
  phone: <><rect x="8" y="2.5" width="8" height="19" rx="2" /><path d="M11 18.5h2" /></>,
  layers: <path d="M12 3l9.5 5L12 13 2.5 8zM3.5 12.5L12 17l8.5-4.5M3.5 16.5L12 21l8.5-4.5" />,
  check: <path d="M4.5 12.5l5 5 10-11" />,
  publish: <path d="M5 12h13M13 6l6 6-6 6" />,
  grip: <><circle cx="9" cy="6" r="1.1" fill="currentColor" stroke="none" /><circle cx="15" cy="6" r="1.1" fill="currentColor" stroke="none" /><circle cx="9" cy="12" r="1.1" fill="currentColor" stroke="none" /><circle cx="15" cy="12" r="1.1" fill="currentColor" stroke="none" /><circle cx="9" cy="18" r="1.1" fill="currentColor" stroke="none" /><circle cx="15" cy="18" r="1.1" fill="currentColor" stroke="none" /></>,
  undo: <path d="M8.5 6.5L4 11l4.5 4.5M4 11h9a7 7 0 0 1 0 14h-3" transform="translate(0 -3)" />,
  redo: <path d="M15.5 6.5L20 11l-4.5 4.5M20 11h-9a7 7 0 0 0 0 14h3" transform="translate(0 -3)" />,
  history: <><path d="M4.5 12a7.5 7.5 0 1 1 2.2 5.3M4.5 12H2.8M4.5 12l-1-2.8" /><path d="M12 8.5V12l2.5 1.8" /></>,
  page: <><path d="M6 3.5h8L19 8.5v12H6z" /><path d="M13.5 3.5v5.5H19" /></>,
  download: <path d="M12 4v11M7.5 11l4.5 4.5L16.5 11M5 20h14" />,
  arrR: <path d="M4 12h15M13 6l6 6-6 6" />,
  arrL: <path d="M20 12H5M11 6l-6 6 6 6" />,
  arrD: <path d="M12 4v15M6 13l6 6 6-6" />,
  arrU: <path d="M12 20V5M6 11l6-6 6 6" />,
  jStart: <path d="M4 7h5M4 12h5M4 17h5" />,
  jCenter: <path d="M9.5 7h5M9.5 12h5M9.5 17h5" />,
  jEnd: <path d="M15 7h5M15 12h5M15 17h5" />,
  jBetween: <path d="M4 7h5M15 7h5M4 17h5M15 17h5" />,
  jAround: <path d="M6 7h4M14 7h4M6 17h4M14 17h4" />,
  jEvenly: <path d="M5 7h4.5M14.5 7h4.5M5 17h4.5M14.5 17h4.5" />,
  aStretch: <path d="M7 4v16M12 4v16M17 4v16" />,
  aStart: <path d="M7 4v8M12 4v8M17 4v8" />,
  aCenter: <path d="M7 8v8M12 8v8M17 8v8" />,
  aEnd: <path d="M7 12v8M12 12v8M17 12v8" />,
  aBase: <path d="M7 6v6M12 4v8M17 7v5M4 14h16" />,
  tLeft: <path d="M5 6h14M5 10h9M5 14h14M5 18h9" />,
  tCenter: <path d="M5 6h14M7.5 10h9M5 14h14M7.5 18h9" />,
  tRight: <path d="M5 6h14M10 10h9M5 14h14M10 18h9" />,
  tJustify: <path d="M5 6h14M5 10h14M5 14h14M5 18h14" />,
};

function Ic({ n, cls }) {
  return (
    <svg className={'pfe-ic ' + (cls || '')} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {PATHS[n] || PATHS.widget}
    </svg>
  );
}

/* ---------------- Style tab presentation (Style-UX overhaul) ----------------
 * Presentation only: section grouping, search, Normal/Hover tabs, icon and
 * segmented pickers, linked dimensions, swatches, gradient stops and shadow
 * cards all write the SAME setting keys and value shapes the engine already
 * consumes. No control name, option list, or stored format changes here. */
const STYLE_SECTIONS = [
  ['Layout', ['layout_mode', 'flex_direction', 'flex_wrap', 'justify_content', 'align_items', 'image_align', 'grid_columns', 'gap', 'position', 'position_top', 'position_right', 'position_bottom', 'position_left', 'z_index', 'order', 'align_self']],
  ['Sizing', ['width', 'min_height', 'height']],
  ['Spacing', ['margin', 'padding', 'image_spacing', 'icon_spacing', 'content_spacing']],
  ['Typography', ['font_family', 'font_size', 'font_weight', 'line_height', 'letter_spacing', 'text_transform', 'text_align', 'color']],
  ['Background', ['background_color', 'background_gradient', 'background_image', 'background_position', 'background_position_x', 'background_position_y', 'background_size', 'background_size_width', 'background_size_height', 'background_repeat', 'background_attachment', 'overlay_color', 'overlay_opacity']],
  ['Border', ['border_width', 'border_style', 'border_color', 'border_radius', 'image_border_style', 'image_border_width', 'image_border_color']],
  ['Icon', ['icon_color', 'icon_bg_color', 'icon_rotate', 'icon_hover_animation', 'list_icon_color', 'list_icon_size', 'list_transition']],
  ['Title', ['title_color', 'title_font_size', 'title_font_weight']],
  ['Description', ['desc_color', 'desc_font_size', 'desc_font_weight']],
  ['List', ['list_align', 'icon_valign']],
  ['Divider', ['divider_on', 'divider_style', 'divider_weight', 'divider_width', 'divider_color']],
  ['Lightbox', ['lightbox']],
  ['Caption', ['caption_display', 'caption_align', 'caption_color', 'caption_font_size', 'caption_font_weight']],
  ['Avatar', ['bio_avatar_size', 'bio_avatar_shape']],
  ['Link Buttons', ['bio_link_bg', 'bio_link_color', 'bio_link_radius', 'bio_link_spacing']],
  ['Button', ['readmore_btn_color', 'readmore_btn_size']],
  ['Effects', ['box_shadow', 'opacity', 'entrance_animation', 'transform_rotate', 'transform_scale', 'transform_translate_x', 'transform_translate_y', 'sticky', 'sticky_offset', 'scroll_fade']],
];
const STYLE_SECTION_OF = Object.fromEntries(STYLE_SECTIONS.flatMap(([s, keys]) => keys.map((k) => [k, s])));
// Hover keys live in their base key's section (the Hover tab swaps values, not layout).
for (const [s, keys] of STYLE_SECTIONS) for (const k of keys) STYLE_SECTION_OF[k + '_hover'] = s;
const STYLE_UNITS = { font_size: 'px', letter_spacing: 'px', border_radius: 'px', border_width: 'px', gap: 'px', min_height: 'px', height: 'px', width: '%', position_top: 'px', position_right: 'px', position_bottom: 'px', position_left: 'px', z_index: '', order: '', transform_rotate: '°', transform_scale: '%', transform_translate_x: 'px', transform_translate_y: 'px', sticky_offset: 'px', overlay_opacity: '%', image_spacing: 'px', icon_spacing: 'px', content_spacing: 'px', image_border_width: 'px', title_font_size: 'px', desc_font_size: 'px', icon_rotate: '°', divider_weight: 'px', divider_width: '%', list_icon_size: 'px', list_transition: 's', autoplay_speed: 'ms', animation_speed: 'ms', caption_font_size: 'px', sc_height: 'px', readmore_btn_size: 'px', bio_avatar_size: 'px', bio_link_radius: 'px', bio_link_spacing: 'px' };
const ICON_PICKERS = {
  flex_direction: [['row', 'arrR'], ['column', 'arrD'], ['row-reverse', 'arrL'], ['column-reverse', 'arrU']],
  justify_content: [['flex-start', 'jStart'], ['center', 'jCenter'], ['flex-end', 'jEnd'], ['space-between', 'jBetween'], ['space-around', 'jAround'], ['space-evenly', 'jEvenly']],
  align_items: [['stretch', 'aStretch'], ['flex-start', 'aStart'], ['center', 'aCenter'], ['flex-end', 'aEnd'], ['baseline', 'aBase']],
  text_align: [['left', 'tLeft'], ['center', 'tCenter'], ['right', 'tRight'], ['justify', 'tJustify']],
  image_align: [['left', 'tLeft'], ['center', 'tCenter'], ['right', 'tRight']],
};
const SEGMENTED_KEYS = ['layout_mode', 'flex_wrap', 'border_style', 'text_transform', 'font_weight', 'entrance_animation', 'position', 'sticky', 'align_self', 'scroll_fade', 'background_size', 'background_attachment', 'image_border_style', 'title_font_weight', 'desc_font_weight', 'list_align', 'icon_valign', 'divider_on', 'divider_style', 'navigation', 'infinite_loop', 'autoplay', 'pause_on_hover', 'pause_on_interaction', 'lightbox', 'caption_display', 'caption_align', 'caption_font_weight', 'visual_player', 'show_comments', 'show_username', 'show_related', 'buy_button', 'sharing', 'download', 'path_shape', 'bio_avatar_shape'];
const SEGMENTED_LABELS = {
  layout_mode: [['flex', 'Flex'], ['grid', 'Grid']],
  flex_wrap: [['nowrap', 'No wrap'], ['wrap', 'Wrap'], ['wrap-reverse', 'Reverse']],
  border_style: [['none', 'None'], ['solid', 'Solid'], ['dashed', 'Dashed'], ['dotted', 'Dotted'], ['double', 'Double']],
  image_border_style: [['none', 'None'], ['solid', 'Solid'], ['dashed', 'Dashed'], ['dotted', 'Dotted'], ['double', 'Double']],
  list_align: [['left', 'Left'], ['center', 'Center'], ['right', 'Right']],
  icon_valign: [['top', 'Top'], ['center', 'Center'], ['bottom', 'Bottom']],
  divider_on: [['off', 'Off'], ['on', 'On']],
  divider_style: [['solid', 'Solid'], ['dashed', 'Dashed'], ['dotted', 'Dotted'], ['double', 'Double']],
  navigation: [['none', 'None'], ['arrows', 'Arrows'], ['dots', 'Dots'], ['both', 'Both']],
  infinite_loop: [['off', 'Off'], ['on', 'On']],
  autoplay: [['off', 'Off'], ['on', 'On']],
  pause_on_hover: [['off', 'Off'], ['on', 'On']],
  pause_on_interaction: [['off', 'Off'], ['on', 'On']],
  lightbox: [['off', 'Off'], ['on', 'On']],
  caption_display: [['show', 'Show'], ['hide', 'Hide']],
  caption_align: [['left', 'Left'], ['center', 'Center'], ['right', 'Right']],
  visual_player: [['off', 'Off'], ['on', 'On']],
  show_comments: [['off', 'Off'], ['on', 'On']],
  show_username: [['off', 'Off'], ['on', 'On']],
  show_related: [['off', 'Off'], ['on', 'On']],
  buy_button: [['off', 'Off'], ['on', 'On']],
  sharing: [['off', 'Off'], ['on', 'On']],
  download: [['off', 'Off'], ['on', 'On']],
  path_shape: [['wave', 'Wave'], ['arch-up', 'Arch up'], ['arch-down', 'Arch down'], ['circle', 'Circle']],
  bio_avatar_shape: [['circle', 'Circle'], ['square', 'Square'], ['rounded', 'Rounded']],
  text_transform: [['none', 'None'], ['uppercase', 'Upper'], ['lowercase', 'Lower'], ['capitalize', 'Capitalize']],
  entrance_animation: [['none', 'None'], ['fade', 'Fade'], ['slide-up', 'Slide up'], ['slide-down', 'Slide down'], ['zoom', 'Zoom']],
  position: [['default', 'Default'], ['relative', 'Relative'], ['absolute', 'Absolute'], ['fixed', 'Fixed']],
  sticky: [['off', 'Off'], ['top', 'Top'], ['bottom', 'Bottom']],
  align_self: [['auto', 'Auto'], ['flex-start', 'Start'], ['center', 'Center'], ['flex-end', 'End'], ['stretch', 'Stretch']],
  scroll_fade: [['off', 'Off'], ['on', 'On']],
  background_size: [['auto', 'Auto'], ['cover', 'Cover'], ['contain', 'Contain'], ['custom', 'Custom']],
  background_attachment: [['scroll', 'Scroll'], ['fixed', 'Fixed']],
};
const SHADOW_CARDS = [['none', 'None', 'none'], ['sm', 'Small', '0 1px 2px rgba(0,0,0,0.08)'], ['md', 'Medium', '0 4px 12px rgba(0,0,0,0.12)'], ['lg', 'Large', '0 10px 30px rgba(0,0,0,0.16)'], ['xl', 'X-Large', '0 20px 50px rgba(0,0,0,0.22)']];
const SWATCHES = ['#454299', '#6a63f0', '#11130f', '#e8e8ee', '#ff5f57', '#28c840', '#febc2e', '#17171d'];

/* ---------------- Document tree helpers (unchanged semantics) ---------------- */

function defaultSettings(def) {
  const out = {};
  for (const [key, c] of Object.entries(def.controls || {})) {
    if (c.default !== undefined) out[key] = c.default;
    else if (c.type === 'repeater') out[key] = [];
    else if (c.type === 'number') out[key] = 0;
    else if (c.type === 'select') out[key] = (c.options || [])[0] ?? '';
    else if (c.type === 'dimensions') out[key] = { top: 0, right: 0, bottom: 0, left: 0, unit: 'px' };
    else out[key] = '';
  }
  return out;
}

function newElement(widgetType) {
  const def = widgetByType[widgetType];
  const el = { id: uid(), elType: def.is_container ? 'container' : 'widget', settings: defaultSettings(def), elements: [] };
  if (!def.is_container) el.widgetType = widgetType;
  return el;
}

function cloneWithNewIds(node) {
  const copy = JSON.parse(JSON.stringify(node));
  const walk = (n) => { n.id = uid(); (n.elements || []).forEach(walk); };
  walk(copy);
  return copy;
}

function findPath(elements, id, acc = []) {
  for (const el of elements) {
    const path = [...acc, el];
    if (el.id === id) return path;
    const hit = findPath(el.elements || [], id, path);
    if (hit) return hit;
  }
  return null;
}

function findNode(elements, id) {
  for (const el of elements) {
    if (el.id === id) return el;
    const hit = findNode(el.elements || [], id);
    if (hit) return hit;
  }
  return null;
}

function mapTree(elements, fn) {
  return elements.map((el) => fn({ ...el, elements: mapTree(el.elements || [], fn) }));
}

function removeById(elements, id) {
  return elements.filter((el) => el.id !== id).map((el) => ({ ...el, elements: removeById(el.elements || [], id) }));
}

/** Insert node relative to targetId: 'inside' | 'before' | 'after'; null target = root end. */
function insertNode(elements, targetId, node, position) {
  if (targetId === null) return [...elements, node];
  const out = [];
  for (const el of elements) {
    if (el.id === targetId) {
      if (position === 'before') out.push(node, el);
      else if (position === 'after') out.push(el, node);
      else out.push({ ...el, elements: [...(el.elements || []), node] });
    } else {
      out.push({ ...el, elements: insertNode(el.elements || [], targetId, node, position) });
    }
  }
  return out;
}

/** Swap an element with its previous/next sibling, wherever it lives. */
function moveInTree(elements, id, dir) {
  const idx = elements.findIndex((el) => el.id === id);
  if (idx >= 0) {
    const swap = idx + dir;
    if (swap < 0 || swap >= elements.length) return elements;
    const out = elements.slice();
    [out[idx], out[swap]] = [out[swap], out[idx]];
    return out;
  }
  return elements.map((el) => ({ ...el, elements: moveInTree(el.elements || [], id, dir) }));
}

/** Flat searchable view of the tree for the Finder. */
function flattenTree(elements, depth = 0, out = []) {
  for (const el of elements) {
    const type = typeOf(el);
    const def = widgetByType[type] || { label: type };
    const text = Object.values(el.settings || {}).filter((v) => typeof v === 'string').join(' ').slice(0, 90);
    out.push({ id: el.id, label: def.label || type, type, depth, text });
    flattenTree(el.elements || [], depth + 1, out);
  }
  return out;
}

function typeOf(el) {
  return el.elType === 'container' ? 'container' : el.widgetType;
}

function labelOf(el) {
  const def = widgetByType[typeOf(el)];
  return def ? def.label : typeOf(el);
}

/* ---------------- Inspector field editors ---------------- */

function Field({ label, control, value, onChange, skey, fname, onMediaPick }) {
  const type = control.type;
  const [dimsLinked, setDimsLinked] = useState(null); // dimensions link toggle (Style tab only)
  const [pickerMissing, setPickerMissing] = useState(false);
  const [iconOpen, setIconOpen] = useState(false); // visual icon picker popover (Content modern)
  // Repeater card state, hoisted here (not in the branch below) so hook
  // order stays stable for every Field instance: collapse map parallel to
  // items (undefined = collapsed), drag source ref, drop-target highlight.
  const [collapsed, setCollapsed] = useState([]);
  const dragIdx = useRef(null);
  const [dropIdx, setDropIdx] = useState(null);
  if (type === 'repeater') {
    const items = Array.isArray(value) ? value : [];
    const schema = control.item_schema || {};
    // Collapse default: headers only until opened. Remapped on move/delete
    // so it follows the item, and a fresh item starts expanded.
    const isCollapsed = (i) => collapsed[i] !== false;
    const setItem = (i, field, v) => {
      const next = items.slice();
      next[i] = { ...next[i], [field]: v };
      onChange(next);
    };
    const addItem = () => {
      const item = {};
      for (const [f, c] of Object.entries(schema)) {
        item[f] = c.default !== undefined ? c.default : (c.type === 'select' ? (c.options || [])[0] ?? '' : '');
      }
      onChange([...items, item]);
      setCollapsed((c) => [...c, false]);
    };
    const removeItem = (i) => {
      const next = items.slice();
      next.splice(i, 1);
      onChange(next);
      setCollapsed((c) => { const n = c.slice(); n.splice(i, 1); return n; });
    };
    const duplicateItem = (i) => {
      const next = items.slice();
      next.splice(i + 1, 0, { ...items[i] });
      onChange(next);
      setCollapsed((c) => { const n = c.slice(); n.splice(i + 1, 0, false); return n; });
    };
    const moveItem = (from, to) => {
      if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) return;
      const next = items.slice();
      const [it] = next.splice(from, 1);
      next.splice(to, 0, it);
      onChange(next);
      setCollapsed((c) => { const n = c.slice(); const [b] = n.splice(from, 1); n.splice(to, 0, b); return n; });
    };
    // Header preview: glyph (when the item has an icon) + the first
    // meaningful text field; path-like values show their basename.
    const previewOf = (item) => {
      const icons = boot.icons || {};
      const glyph = item.icon_name && icons[item.icon_name] ? icons[item.icon_name] : null;
      let text = '';
      for (const [f] of Object.entries(schema)) {
        if (f === 'icon_name' || f === 'link_url' || f === 'url') continue;
        const v = item[f];
        if (typeof v === 'string' && v.trim() !== '') {
          text = v.trim();
          if (/^(https?:\/\/|\/)/.test(text)) text = text.split('?')[0].split('#')[0].split('/').pop() || text;
          break;
        }
      }
      const sub = [item.icon_name || null, schema.link_url ? (item.link_url ? 'linked' : 'no link') : null].filter(Boolean).join(' · ');
      return { glyph, text, sub };
    };
    return (
      <div className="pfe-field pfe-field--repeater">
        <span className="pfe-label pfe-label--row">{label}<span className="pfe-count">{items.length} item{items.length === 1 ? '' : 's'}</span></span>
        {items.map((item, i) => {
          const prev = previewOf(item);
          return (
            <div key={i}
              className={'pfe-repeater-item' + (isCollapsed(i) ? ' is-collapsed' : '') + (dropIdx === i ? ' is-drop' : '')}
              onDragOver={(e) => { e.preventDefault(); setDropIdx(i); }}
              onDragLeave={() => setDropIdx((d) => (d === i ? null : d))}
              onDrop={(e) => {
                e.preventDefault();
                const raw = Number(e.dataTransfer.getData('text/plain'));
                moveItem(Number.isFinite(raw) ? raw : dragIdx.current, i);
                dragIdx.current = null;
                setDropIdx(null);
              }}>
              <div className="pfe-repeater-item__head" onClick={() => setCollapsed((c) => { const n = c.slice(); n[i] = !isCollapsed(i); return n; })}>
                <span className="pfe-repeater-drag" title="Drag to reorder" draggable
                  onClick={(e) => e.stopPropagation()}
                  onDragStart={(e) => { dragIdx.current = i; e.dataTransfer.setData('text/plain', String(i)); e.dataTransfer.effectAllowed = 'move'; }}>⋮⋮</span>
                <span className={'pfe-repeater-chev' + (isCollapsed(i) ? '' : ' is-open')}>›</span>
                {prev.glyph ? <span className="pfe-repeater-glyph" dangerouslySetInnerHTML={{ __html: prev.glyph }} /> : null}
                <span className="pfe-repeater-prev"><b>{prev.text || `Item ${i + 1}`}</b>{prev.sub ? <small>{prev.sub}</small> : null}</span>
                <button type="button" title="Duplicate item" onClick={(e) => { e.stopPropagation(); duplicateItem(i); }}><Ic n="copy" cls="sm" /></button>
                <button type="button" title="Remove item" onClick={(e) => { e.stopPropagation(); removeItem(i); }}><Ic n="x" cls="sm" /></button>
              </div>
              {!isCollapsed(i) && (
                <div className="pfe-repeater-item__body">
                  {Object.entries(schema).map(([f, c]) => (
                    <Field key={f} fname={f} label={humanize(f)} control={c} value={item[f]} onChange={(v) => setItem(i, f, v)}
                      onMediaPick={c.type === 'media' ? (path, itemRec) => {
                        const patch = { [f]: path };
                        if (f === 'src' && !item.caption && itemRec.description) patch.caption = itemRec.description;
                        const next = items.slice();
                        next[i] = { ...next[i], ...patch };
                        onChange(next);
                      } : undefined} />
                  ))}
                </div>
              )}
            </div>
          );
        })}
        <button type="button" className="pfe-add-item" onClick={addItem}>+ Add item</button>
      </div>
    );
  }
  if (type === 'media') {
    // Media picker (Kohevo Content -> Media): thumbnail preview + URL kept
    // + Library/Upload buttons. The platform picker hosts upload inside its
    // own modal, so both buttons open it; SlateMedia filters to images.
    const isImage = typeof value === 'string' && /\.(jpe?g|png|gif|webp|svg|avif)(\?|#|$)/i.test(value);
    const baseName = typeof value === 'string' && value !== '' ? value.split('?')[0].split('#')[0].split('/').pop() : '';
    const openPicker = () => {
      const w = typeof window !== 'undefined' ? window : {};
      if (w.SlateMedia && w.SlateMedia.open) {
        w.SlateMedia.open({ type: 'image', onPick: (item) => {
          if (onMediaPick) onMediaPick(item.path, item); else onChange(item.path);
        } });
      } else if (w.MediaPicker && w.MediaPicker.open) {
        w.MediaPicker.open({ mode: 'single', onPick: (path, item) => {
          if (onMediaPick) onMediaPick(path, item); else onChange(path);
        } });
      } else {
        setPickerMissing(true);
      }
    };
    return (
      <div className="pfe-field">
        <span className="pfe-label">{label}</span>
        {value ? (
          isImage ? (
            <div className="pfe-media-prev"><img src={value} alt="" onError={(e) => { e.currentTarget.style.display = 'none'; }} /><span>{baseName}</span></div>
          ) : (
            <div className="pfe-media-file">{baseName}</div>
          )
        ) : (
          <div className="pfe-media-empty">No image selected</div>
        )}
        <input type="text" value={value ?? ''} onChange={(e) => onChange(e.target.value)} placeholder="https:// or /uploads/…" />
        <div className="pfe-media-btns">
          <button type="button" className="pfe-btn pfe-btn--primary" onClick={openPicker}>Library</button>
          <button type="button" className="pfe-btn" onClick={openPicker}>Upload</button>
          {value ? <button type="button" className="pfe-btn pfe-btn--ghost" onClick={() => onChange('')}>Remove</button> : null}
        </div>
        {pickerMissing ? <p className="pfe-hint">Media library unavailable on this page — paste a URL instead.</p> : null}
      </div>
    );
  }
  if (type === 'dimensions' && skey) {
    const v = value && typeof value === 'object' ? value : { top: 0, right: 0, bottom: 0, left: 0, unit: 'px' };
    const allEqual = v.top === v.right && v.right === v.bottom && v.bottom === v.left;
    const linked = dimsLinked === null ? allEqual : dimsLinked;
    const setSide = (side, n) => {
      const num = n === '' ? 0 : Number(n);
      onChange(linked ? { ...v, top: num, right: num, bottom: num, left: num } : { ...v, [side]: num });
    };
    const toggleLink = () => {
      if (linked) { setDimsLinked(false); return; }
      setDimsLinked(true);
      onChange({ ...v, right: v.top, bottom: v.top, left: v.top });
    };
    const sideInput = (side) => (
      <span className="pfe-dim" key={side}>
        <input type="number" value={v[side] ?? 0} onChange={(e) => setSide(side, e.target.value)} />
        <small>{side}</small>
      </span>
    );
    return (
      <div className="pfe-field">
        <span className="pfe-label pfe-label--row">{label}
          <button type="button" className={'pfe-linkbtn' + (linked ? ' is-on' : '')} title={linked ? 'Unlink sides' : 'Link all sides'} onClick={toggleLink}><Ic n="link" cls="sm" /></button>
        </span>
        <div className="pfe-boxmodel">
          <div className="pfe-bm-row pfe-bm-row--top">{sideInput('top')}</div>
          <div className="pfe-bm-row pfe-bm-row--mid">{sideInput('left')}<span className="pfe-bm-core" title="Element">▦</span>{sideInput('right')}</div>
          <div className="pfe-bm-row pfe-bm-row--bot">{sideInput('bottom')}</div>
        </div>
        <span className="pfe-unitpills">
          {['px', '%', 'rem', 'em'].map((u) => (
            <button key={u} type="button" className={(v.unit || 'px') === u ? 'is-on' : ''} onClick={() => onChange({ ...v, unit: u })}>{u}</button>
          ))}
        </span>
      </div>
    );
  }
  if (type === 'dimensions') {
    const v = value && typeof value === 'object' ? value : { top: 0, right: 0, bottom: 0, left: 0, unit: 'px' };
    const set = (side, n) => onChange({ ...v, [side]: n === '' ? 0 : Number(n) });
    return (
      <div className="pfe-field">
        <span className="pfe-label">{label}</span>
        <span className="pfe-dims">
          {['top', 'right', 'bottom', 'left'].map((side) => (
            <span className="pfe-dim" key={side}>
              <input type="number" value={v[side] ?? 0} onChange={(e) => set(side, e.target.value)} />
              <small>{side}</small>
            </span>
          ))}
          <select className="pfe-dim-unit" value={v.unit || 'px'} onChange={(e) => onChange({ ...v, unit: e.target.value })}>
            {['px', '%', 'rem', 'em'].map((u) => <option key={u} value={u}>{u}</option>)}
          </select>
        </span>
      </div>
    );
  }
  if (type === 'select' && skey && ICON_PICKERS[skey]) {
    const pairs = ICON_PICKERS[skey].filter(([v]) => (control.options || []).includes(v));
    return (
      <div className="pfe-field">
        <span className="pfe-label pfe-label--row">{label}<span className="pfe-valbadge">{value === '' || value === undefined ? 'Not set' : value}</span></span>
        <span className="pfe-iconpick">
          {pairs.map(([v, icon]) => (
            <button key={v} type="button" title={v} className={value === v ? 'is-on' : ''} onClick={() => onChange(v)}><Ic n={icon} cls="sm" /></button>
          ))}
        </span>
      </div>
    );
  }
  if (type === 'select' && skey && SEGMENTED_KEYS.includes(skey)) {
    const pairs = (SEGMENTED_LABELS[skey] || (control.options || []).map((o) => [o, o])).filter(([v]) => (control.options || []).includes(v));
    const cur = pairs.find(([v]) => v === value);
    return (
      <div className="pfe-field">
        <span className="pfe-label pfe-label--row">{label}<span className="pfe-valbadge">{cur ? cur[1] : 'Not set'}</span></span>
        <span className="pfe-seg">
          {pairs.map(([v, lbl]) => (
            <button key={v} type="button" className={value === v ? 'is-on' : ''} onClick={() => onChange(v)}>{lbl}</button>
          ))}
        </span>
      </div>
    );
  }
  if (type === 'select' && (skey === 'box_shadow' || skey === 'box_shadow_hover')) {
    const cards = SHADOW_CARDS.filter(([v]) => (control.options || []).includes(v));
    return (
      <div className="pfe-field">
        <span className="pfe-label pfe-label--row">{label}<span className="pfe-valbadge">{value === '' || value === undefined ? 'Not set' : value}</span></span>
        <span className="pfe-shadowcards">
          {cards.map(([v, lbl, prev]) => (
            <button key={v} type="button" className={value === v ? 'is-on' : ''} onClick={() => onChange(v)}>
              <span className="pfe-shadowprev" style={{ boxShadow: prev }} />
              <small>{lbl}</small>
            </button>
          ))}
        </span>
      </div>
    );
  }
  // Batch A: attributes fields are key/value rows rendered as data-*
  // attributes. No hooks here (all Field hooks are hoisted above), so
  // branch order stays safe. Server-side sanitize drops non-data-* keys.
  if (type === 'attributes') {
    const pairs = Array.isArray(value) ? value : [];
    const setPair = (i, field, v) => {
      const next = pairs.slice();
      next[i] = { ...next[i], [field]: v };
      onChange(next);
    };
    const removePair = (i) => {
      const next = pairs.slice();
      next.splice(i, 1);
      onChange(next);
    };
    return (
      <div className="pfe-field">
        <span className="pfe-label">{label}</span>
        {pairs.map((p, i) => (
          <span className="pfe-attr-row" key={i}>
            <input type="text" value={p.key ?? ''} placeholder="data-*" onChange={(e) => setPair(i, 'key', e.target.value)} />
            <input type="text" value={p.value ?? ''} placeholder="value" onChange={(e) => setPair(i, 'value', e.target.value)} />
            <button type="button" className="pfe-attr-rm" title="Remove attribute" onClick={() => removePair(i)}>×</button>
          </span>
        ))}
        <button type="button" className="pfe-add-item" onClick={() => onChange([...pairs, { key: '', value: '' }])}>+ Add attribute</button>
        <p className="pfe-hint">Keys must start with data- — anything else is dropped on save.</p>
      </div>
    );
  }
  // Content modern: icon_name fields get a visual glyph picker instead of
  // a name dropdown. Falls back to the plain select when boot carries no
  // icon map (stale boot / cached page) — never a dead field.
  if (type === 'select' && fname === 'icon_name' && boot.icons) {
    const names = (control.options || []).filter((n) => boot.icons[n]);
    const cur = value && boot.icons[value] ? boot.icons[value] : null;
    return (
      <div className="pfe-field">
        <span className="pfe-label">{label}</span>
        <div className="pfe-iconfield">
          <button type="button" className="pfe-iconbtn" title="Choose icon" onClick={() => setIconOpen((o) => !o)}>
            {cur ? <span dangerouslySetInnerHTML={{ __html: cur }} /> : null}
            <small>{value || '—'}</small>
          </button>
          <input type="text" value={value ?? ''} readOnly onClick={() => setIconOpen(true)} placeholder="Choose an icon…" />
        </div>
        {iconOpen && (
          <div className="pfe-iconpop">
            <div className="pfe-iconpop__h"><b>Choose icon</b><span>{names.length} glyphs · click to apply</span></div>
            <div className="pfe-iconpop__grid">
              {names.map((n) => (
                <button key={n} type="button" title={n} className={value === n ? 'is-on' : ''} onClick={() => { onChange(n); setIconOpen(false); }}>
                  <span dangerouslySetInnerHTML={{ __html: boot.icons[n] }} />
                  <small>{n}</small>
                </button>
              ))}
            </div>
          </div>
        )}
      </div>
    );
  }
  // Background image: the position select renders as a 9-point grid
  // (approved mockup) plus a Custom button that reveals the X/Y fields
  // via the Style tab visibility filter — no cross-key writes here.
  if (type === 'select' && skey === 'background_position') {
    const points = [['left top', '↖'], ['center top', '↑'], ['right top', '↗'], ['left center', '←'], ['center center', '●'], ['right center', '→'], ['left bottom', '↙'], ['center bottom', '↓'], ['right bottom', '↘']];
    return (
      <div className="pfe-field">
        <span className="pfe-label pfe-label--row">{label}<span className="pfe-valbadge">{value === '' || value === undefined ? 'Not set' : value}</span></span>
        <span className="pfe-posgrid">
          {points.map(([v, arrow]) => (
            <button key={v} type="button" title={v} className={value === v ? 'is-on' : ''} onClick={() => onChange(v)}>{arrow}</button>
          ))}
        </span>
        <button type="button" className={'pfe-poscustom' + (value === 'custom' ? ' is-on' : '')} onClick={() => onChange('custom')}>Custom position…</button>
      </div>
    );
  }
  if (type === 'select') {
    return (
      <label className="pfe-field">
        <span className="pfe-label">{label}</span>
        <select value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          {(control.options || []).map((o) => <option key={o} value={o}>{o}</option>)}
        </select>
      </label>
    );
  }
  if (type === 'color' && skey) {
    const valid = /^#[0-9a-f]{6}$/i.test(value || '');
    const sw = [...SWATCHES];
    if (valid && !sw.some((s) => s.toLowerCase() === value.toLowerCase())) sw.push(value);
    return (
      <div className="pfe-field">
        <span className="pfe-label pfe-label--row">{label}
          {value ? <button type="button" className="pfe-clearbtn" title="Clear color" onClick={() => onChange('')}>×</button> : null}
        </span>
        <span className="pfe-color">
          <input type="color" className={valid ? '' : 'pfe-color--unset'} value={valid ? value : '#000000'} onChange={(e) => onChange(e.target.value)} />
          <input type="text" value={value ?? ''} placeholder="#rrggbb" onChange={(e) => onChange(e.target.value)} />
        </span>
        <span className="pfe-swatches">
          <button type="button" className={'pfe-swatch-none' + (!value ? ' is-on' : '')} title="No color" onClick={() => onChange('')}>∅</button>
          {sw.map((s) => (
            <button key={s} type="button" className={'pfe-swatch' + (valid && s.toLowerCase() === value.toLowerCase() ? ' is-on' : '')} style={{ background: s }} title={s} onClick={() => onChange(s)} />
          ))}
        </span>
      </div>
    );
  }
  if (type === 'color') {
    return (
      <div className="pfe-field">
        <span className="pfe-label">{label}</span>
        <span className="pfe-color">
          <input type="color" value={/^#[0-9a-f]{6}$/i.test(value || '') ? value : '#000000'} onChange={(e) => onChange(e.target.value)} />
          <input type="text" value={value ?? ''} placeholder="#rrggbb" onChange={(e) => onChange(e.target.value)} />
        </span>
      </div>
    );
  }
  if (type === 'gradient') {
    const raw = typeof value === 'string' ? value : '';
    const m = raw.match(/^linear-gradient\((\d+)deg,\s*(#[0-9a-fA-F]{6}),\s*(#[0-9a-fA-F]{6})\)$/);
    if (raw !== '' && !m) {
      return (
        <label className="pfe-field">
          <span className="pfe-label">{label}</span>
          <input type="text" value={raw} onChange={(e) => onChange(e.target.value)} />
          <span className="pfe-hint">Custom gradient — kept as text. A two-stop linear gradient edits visually.</span>
        </label>
      );
    }
    const angle = m ? Number(m[1]) : 90;
    const c1 = m ? m[2] : '#454299';
    const c2 = m ? m[3] : '#6a63f0';
    const set = (a, x, y) => onChange('linear-gradient(' + a + 'deg, ' + x + ', ' + y + ')');
    const stopText = (cur, commit) => (
      <input type="text" key={cur} defaultValue={cur} spellCheck={false}
        onBlur={(e) => { const v = e.target.value.trim(); if (/^#[0-9a-fA-F]{6}$/.test(v)) commit(v); else e.target.value = cur; }} />
    );
    return (
      <div className="pfe-field">
        <span className="pfe-label">{label}</span>
        <div className="pfe-gradbar" style={{ background: 'linear-gradient(' + angle + 'deg, ' + c1 + ', ' + c2 + ')' }} />
        <span className="pfe-gradstops">
          <span className="pfe-gradstop"><input type="color" value={c1} onChange={(e) => set(angle, e.target.value, c2)} />{stopText(c1, (v) => set(angle, v, c2))}</span>
          <span className="pfe-gradstop"><input type="color" value={c2} onChange={(e) => set(angle, c1, e.target.value)} />{stopText(c2, (v) => set(angle, c1, v))}</span>
        </span>
        <span className="pfe-numrow">
          <input type="range" min={0} max={360} value={angle} onChange={(e) => set(Number(e.target.value), c1, c2)} />
          <span className="pfe-numbadge">{angle}°</span>
        </span>
      </div>
    );
  }
  if (type === 'number') {
    const hasRange = control.min !== undefined && control.max !== undefined;
    // Slider steps that fit the range (opacity 0-1 was step-1 before: only 0/1).
    const span = hasRange ? control.max - control.min : 0;
    const step = span <= 1 ? 0.01 : span <= 10 ? 0.1 : 1;
    const unit = skey ? (STYLE_UNITS[skey] || '') : '';
    return (
      <div className="pfe-field">
        <span className="pfe-label">{label}</span>
        {hasRange ? (
          <span className="pfe-numrow">
            <input type="range" min={control.min} max={control.max} step={step} value={value === '' || value === undefined ? control.min : value}
              onChange={(e) => onChange(Number(e.target.value))} />
            <input type="number" className="pfe-num" value={value ?? ''} min={control.min} max={control.max}
              onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} />
            <span className="pfe-numbadge">{value === '' || value === undefined ? 'Not set' : (unit ? value + ' ' + unit : value)}</span>
          </span>
        ) : (
          <input type="number" value={value ?? ''} min={control.min} max={control.max} onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} />
        )}
      </div>
    );
  }
  if (type === 'textarea' || type === 'raw_html') {
    return (
      <label className="pfe-field">
        <span className="pfe-label">{label}</span>
        <textarea rows={type === 'raw_html' ? 8 : 4} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
      </label>
    );
  }
  return (
    <label className="pfe-field">
      <span className="pfe-label">{label}</span>
      <input type="text" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
    </label>
  );
}

/* ---------------- Canvas bridge (runs inside the canvas iframe) ----------------
 * Injected once via srcdoc. The document is never reloaded: the parent swaps
 * compiled HTML/CSS in place through 'content' messages, so scroll and the
 * selection chip survive edits. The bridge owns hover/selection outlines and
 * the floating element chip; it reports clicks, drops and chip actions back.
 */
const BRIDGE_CSS = `
  html, body { margin: 0; padding: 0; background: #fff; }
  body { font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #191922; }
  img { max-width: 100%; }
  body.pf-editing [class*="pf-el-"] { cursor: pointer; }
  .pfe-sel { outline: 2px solid #6a63f0 !important; outline-offset: 2px; }
  .pfe-hover { outline: 1.5px dashed rgba(106,99,240,.65) !important; outline-offset: 2px; }
  #pf-chip { position: absolute; display: none; z-index: 99999; background: #6a63f0; color: #fff;
    font: 600 11px/1.4 -apple-system, "Segoe UI", Roboto, sans-serif; padding: 3px 6px 3px 8px;
    border-radius: 6px 6px 6px 0; align-items: center; gap: 7px; box-shadow: 0 4px 14px rgba(20,16,80,.35); cursor: grab; }
  #pf-chip .pf-chip-acts { display: flex; gap: 4px; border-left: 1px solid rgba(255,255,255,.35); padding-left: 7px; }
  #pf-chip button { background: transparent; border: 0; color: #fff; cursor: pointer; padding: 2px; display: grid; place-items: center; }
  #pf-chip svg { width: 13px; height: 13px; stroke: #fff; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
  #pf-chip.is-flipped { border-radius: 6px; }
  #pf-hovertag { position: absolute; display: none; z-index: 99998; background: #17171d; color: #c9c5f5;
    font: 600 11px/1.4 -apple-system, "Segoe UI", Roboto, sans-serif; padding: 2px 8px; border-radius: 6px;
    border: 1px solid #6a63f0; pointer-events: none; white-space: nowrap; }
  #pf-insertline { position: absolute; display: none; z-index: 99997; height: 3px; background: #6a63f0;
    border-radius: 3px; box-shadow: 0 2px 10px rgba(106,99,240,.5); pointer-events: none; }
  #pf-insertline::after { content: '+ Drop here'; position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%);
    background: #6a63f0; color: #fff; font: 600 11px/1.4 -apple-system, "Segoe UI", Roboto, sans-serif;
    padding: 3px 10px; border-radius: 20px; white-space: nowrap; }
  .pfe-drop-target { outline: 2px solid rgba(106,99,240,.7) !important; outline-offset: 2px; background: rgba(106,99,240,.08) !important; }
  .pf-empty-box { border: 2px dashed #c9c9f2; border-radius: 10px; padding: 10px; text-align: center; }
  .pf-empty-add { display: inline-grid; place-items: center; width: 38px; height: 38px; margin: 14px auto; border-radius: 50%;
    background: #454299; color: #fff; border: 0; font-size: 20px; line-height: 1; cursor: pointer; }
  body:not(.pf-editing) .pf-empty-box { display: none; }
  body.pf-guides::after { content: ''; position: fixed; left: 50%; top: 0; bottom: 0; width: 1px;
    background: rgba(106,99,240,.35); pointer-events: none; z-index: 99996; }
  body.pf-outlines [class*="pf-el-"] { outline: 1px dashed rgba(106,99,240,.4) !important; outline-offset: 1px; }
`;

const BRIDGE_JS = `
(function () {
  var editing = true, selectedId = null, hoverId = null;
  var chip = document.getElementById('pf-chip');
  var chipLabel = document.getElementById('pf-chip-label');
  var hovertag = document.getElementById('pf-hovertag');
  var hovertagLabel = document.getElementById('pf-hovertag-label');
  var insertline = document.getElementById('pf-insertline');
  var containerIds = {}, dragPos = null, dragTargetEl = null;
  function post(msg) { msg.ns = 'pageforge'; parent.postMessage(msg, '*'); }
  function idOf(el) {
    if (!el || typeof el.className !== 'string') return null;
    var m = el.className.match(/pf-el-([A-Za-z0-9_-]+)/);
    return m ? m[1] : null;
  }
  function closestEl(t) {
    while (t && t !== document.body) { var id = idOf(t); if (id) return { el: t, id: id }; t = t.parentElement; }
    return null;
  }
  function elById(id) { return id ? document.querySelector('.pf-el-' + CSS.escape(id)) : null; }
  function clearHover() {
    if (hoverId) { var o = elById(hoverId); if (o) o.classList.remove('pfe-hover'); hoverId = null; }
    hovertag.style.display = 'none';
  }
  function positionChip() {
    var el = elById(selectedId);
    if (!el || !editing) { chip.style.display = 'none'; return; }
    var r = el.getBoundingClientRect();
    chip.style.display = 'flex';
    chip.style.left = Math.max(0, r.left + window.scrollX - 2) + 'px';
    // Canvas-UX: flip inside when there is no room above (page-top elements
    // used to get the chip clamped ONTO their content).
    var above = r.top + window.scrollY - 29;
    if (above < window.scrollY) { chip.style.top = (r.top + window.scrollY + 2) + 'px'; chip.classList.add('is-flipped'); }
    else { chip.style.top = above + 'px'; chip.classList.remove('is-flipped'); }
  }
  function positionHovertag() {
    var el = elById(hoverId);
    if (!el || !editing || !hoverId) { hovertag.style.display = 'none'; return; }
    var r = el.getBoundingClientRect();
    hovertag.style.display = 'block';
    hovertag.style.left = Math.max(0, r.left + window.scrollX - 2) + 'px';
    var above = r.top + window.scrollY - 26;
    hovertag.style.top = (above < window.scrollY ? r.top + window.scrollY + 2 : above) + 'px';
  }
  function clearDropInd() {
    insertline.style.display = 'none';
    if (dragTargetEl) { dragTargetEl.classList.remove('pfe-drop-target'); dragTargetEl = null; }
  }
  var emptyIds = new Set();
  function applyEmptyAffordances() {
    emptyIds.forEach(function (id) {
      var el = elById(id);
      if (!el) return;
      el.classList.add('pf-empty-box');
      if (!el.querySelector('.pf-empty-add')) {
        var btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'pf-empty-add'; btn.textContent = '+';
        btn.title = 'Add element inside'; btn.setAttribute('data-empty-for', id);
        el.appendChild(btn);
      }
    });
  }
  function applySel(id) {
    if (selectedId) { var o = elById(selectedId); if (o) o.classList.remove('pfe-sel'); }
    selectedId = id;
    if (id) { var n = elById(id); if (n) n.classList.add('pfe-sel'); }
    positionChip();
  }
  function sendHeight() { post({ type: 'height', h: Math.max(document.body ? document.body.scrollHeight : 0, 480) }); }
  window.addEventListener('message', function (e) {
    var d = e.data || {};
    if (d.ns !== 'pageforge') return;
    if (d.type === 'content') {
      document.getElementById('pf-css').textContent = d.css || '';
      document.getElementById('pf-root').innerHTML = d.html || '';
      containerIds = {}; (d.containers || []).forEach(function (id) { containerIds[id] = true; });
      emptyIds = new Set(d.emptyContainers || []);
      requestAnimationFrame(function () { applySel(selectedId); applyEmptyAffordances(); sendHeight(); });
      setTimeout(function () { applyEmptyAffordances(); sendHeight(); }, 350);
    }
    if (d.type === 'canvasopts') {
      document.body.classList.toggle('pf-guides', !!d.guides);
      document.body.classList.toggle('pf-outlines', !!d.outlines);
    }
    if (d.type === 'hovername') {
      if (d.id && d.id === hoverId) { hovertagLabel.textContent = d.name || 'Element'; positionHovertag(); }
      else hovertag.style.display = 'none';
    }
    if (d.type === 'select') { chipLabel.textContent = d.name || 'Element'; clearHover(); applySel(d.id || null); }
    if (d.type === 'editing') {
      editing = !!d.on;
      document.body.classList.toggle('pf-editing', editing);
      if (!editing) {
        clearHover();
        chip.style.display = 'none';
        var cur = elById(selectedId);
        if (cur) cur.classList.remove('pfe-sel');
      } else { applySel(selectedId); }
    }
    if (d.type === 'scrollto') { var el = elById(d.id); if (el) el.scrollIntoView({ block: 'center' }); }
    if (d.type === 'inlineedit') {
      var el2 = elById(d.id);
      if (el2) {
        el2.setAttribute('data-pf-inline-orig', el2.textContent || '');
        el2.setAttribute('data-pf-inline', d.id);
        el2.contentEditable = 'true';
        el2.focus();
        var range = document.createRange(); range.selectNodeContents(el2);
        var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
      }
    }
  });
  document.addEventListener('contextmenu', function (e) {
    if (!editing) return;
    var hit = closestEl(e.target);
    if (!hit) return;
    e.preventDefault(); e.stopPropagation();
    post({ type: 'contextmenu', id: hit.id, x: e.clientX, y: e.clientY });
  }, true);
  document.addEventListener('dblclick', function (e) {
    if (!editing) return;
    var hit = closestEl(e.target);
    if (!hit) return;
    e.preventDefault(); e.stopPropagation();
    post({ type: 'inlinestart', id: hit.id });
  }, true);
  document.addEventListener('click', function (e) {
    if (!editing) return;
    if (e.target.closest && e.target.closest('#pf-chip')) return;
    var addBtn = e.target.closest ? e.target.closest('.pf-empty-add') : null;
    if (addBtn) { e.preventDefault(); e.stopPropagation(); post({ type: 'empty-add', id: addBtn.getAttribute('data-empty-for') }); return; }
    if (e.target.closest && e.target.closest('[data-pf-inline]')) return;
    var hit = closestEl(e.target);
    e.preventDefault(); e.stopPropagation();
    post({ type: 'select', id: hit ? hit.id : null });
  }, true);
  document.addEventListener('keydown', function (e) {
    var ed = e.target.closest ? e.target.closest('[data-pf-inline]') : null;
    if (!ed) return;
    e.stopPropagation();
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ed.blur(); }
    if (e.key === 'Escape') {
      ed.textContent = ed.getAttribute('data-pf-inline-orig') || '';
      ed.removeAttribute('contenteditable'); ed.removeAttribute('data-pf-inline'); ed.removeAttribute('data-pf-inline-orig');
      post({ type: 'inlinecancel' });
      ed.blur();
    }
  }, true);
  document.addEventListener('focusout', function (e) {
    var ed = e.target.closest ? e.target.closest('[data-pf-inline]') : null;
    if (!ed) return;
    var id = ed.getAttribute('data-pf-inline');
    var value = ed.textContent || '';
    ed.removeAttribute('contenteditable'); ed.removeAttribute('data-pf-inline'); ed.removeAttribute('data-pf-inline-orig');
    post({ type: 'inlinecommit', id: id, value: value });
  }, true);
  document.addEventListener('mouseout', function (e) {
    if (!e.relatedTarget) clearHover();
  }, true);
  document.addEventListener('mouseover', function (e) {
    if (!editing) return;
    var hit = closestEl(e.target);
    var id = hit ? hit.id : null;
    if (id === hoverId) return;
    clearHover();
    hovertag.style.display = 'none';
    if (id && id !== selectedId) { var n = elById(id); if (n) { n.classList.add('pfe-hover'); hoverId = id; post({ type: 'hover', id: id }); } }
    else post({ type: 'hover', id: null });
  }, true);
  chip.addEventListener('dragstart', function (e) {
    if (!selectedId) return;
    e.dataTransfer.setData('text/pageforge', JSON.stringify({ kind: 'node', id: selectedId }));
    e.dataTransfer.effectAllowed = 'move';
  });
  document.addEventListener('dragover', function (e) {
    if (!editing) return;
    e.preventDefault();
    clearDropInd();
    dragPos = null;
    var hit = closestEl(e.target);
    if (!hit) {
      var root = document.getElementById('pf-root');
      if (root) {
        var rr = root.getBoundingClientRect();
        insertline.style.display = 'block';
        insertline.style.left = (rr.left + window.scrollX) + 'px';
        insertline.style.width = rr.width + 'px';
        insertline.style.top = (rr.bottom + window.scrollY - 1) + 'px';
        dragPos = { targetId: null, pos: 'root' };
      }
      return;
    }
    var r = hit.el.getBoundingClientRect();
    if (containerIds[hit.id] && e.target === hit.el) {
      hit.el.classList.add('pfe-drop-target'); dragTargetEl = hit.el;
      dragPos = { targetId: hit.id, pos: 'inside' };
      return;
    }
    var before = e.clientY < r.top + r.height / 2;
    insertline.style.display = 'block';
    insertline.style.left = (r.left + window.scrollX) + 'px';
    insertline.style.width = r.width + 'px';
    insertline.style.top = (before ? r.top + window.scrollY - 1 : r.bottom + window.scrollY - 1) + 'px';
    dragPos = { targetId: hit.id, pos: before ? 'before' : 'after' };
  });
  document.addEventListener('drop', function (e) {
    if (!editing) return;
    e.preventDefault(); e.stopPropagation();
    clearDropInd();
    var payload = null;
    try { payload = JSON.parse(e.dataTransfer.getData('text/pageforge')); } catch (err) { /* ignore */ }
    if (!payload) return;
    var hit = closestEl(e.target);
    var pos = (dragPos && dragPos.targetId === (hit ? hit.id : null)) ? dragPos.pos : null;
    if (dragPos && dragPos.targetId === null && !hit) pos = 'root';
    post({ type: 'drop', payload: payload, targetId: hit ? hit.id : null, targetIsRoot: hit ? e.target === hit.el : false, pos: pos });
    dragPos = null;
  });
  chip.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('button[data-act]') : null;
    if (btn && selectedId) post({ type: 'action', action: btn.getAttribute('data-act'), id: selectedId });
  });
  window.addEventListener('scroll', function () { positionChip(); positionHovertag(); }, { passive: true });
  window.addEventListener('resize', positionChip);
  post({ type: 'ready' });
})();
`;

const CHIP_HTML = `<div id="pf-chip" draggable="true"><span id="pf-chip-label">Element</span><span class="pf-chip-acts">`
  + `<button type="button" data-act="duplicate" title="Duplicate"><svg viewBox="0 0 24 24"><rect x="8.5" y="8.5" width="11.5" height="11.5" rx="2"/><path d="M16 8.5v-2A2.5 2.5 0 0 0 13.5 4h-7A2.5 2.5 0 0 0 4 6.5v7A2.5 2.5 0 0 0 6.5 16h2"/></svg></button>`
  + `<button type="button" data-act="move-up" title="Move up"><svg viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>`
  + `<button type="button" data-act="move-down" title="Move down"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12l7 7 7-7"/></svg></button>`
  + `<button type="button" data-act="style" title="Style settings"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></button>`
  + `<button type="button" data-act="delete" title="Delete"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button></span></div>`;

function frameSrcdoc() {
  return '<!doctype html><html><head><meta charset="utf-8">'
    + '<link rel="stylesheet" href="' + boot.pageCssUrl + '">'
    + '<style>' + BRIDGE_CSS + '</style><style id="pf-css"></style></head>'
    + '<body class="pf-editing"><div id="pf-root"></div>' + CHIP_HTML
    + '<div id="pf-hovertag"><span id="pf-hovertag-label"></span></div><div id="pf-insertline"></div>'
    + '<script>' + BRIDGE_JS + '<\/script>'
    // Batch A: the frontend script is inert without [data-pf-scroll-fade]
    // elements, so the preview always loads it (content swaps in live);
    // public pages load it only when a fade element exists (router check).
    + (boot.pageJsUrl ? '<script src="' + boot.pageJsUrl + '"><\/script>' : '')
    + '</body></html>';
}

/* ---------------- Structure tree ---------------- */

function StructureRows({ elements, depth, selectedId, onSelect, onDropRow }) {
  const [over, setOver] = useState(null); // {id, pos}
  return elements.map((el) => {
    const def = widgetByType[typeOf(el)] || {};
    const isContainer = el.elType === 'container';
    return (
      <React.Fragment key={el.id}>
        <div
          className={'pfe-trow' + (el.id === selectedId ? ' is-on' : '') + (over && over.id === el.id ? ' is-over-' + over.pos : '')}
          style={{ marginLeft: depth * 15 }}
          draggable
          onDragStart={(e) => { e.stopPropagation(); e.dataTransfer.setData('text/pageforge', JSON.stringify({ kind: 'node', id: el.id })); e.dataTransfer.effectAllowed = 'move'; }}
          onDragOver={(e) => {
            e.preventDefault(); e.stopPropagation();
            const r = e.currentTarget.getBoundingClientRect();
            const y = (e.clientY - r.top) / r.height;
            const pos = isContainer ? (y < 0.3 ? 'before' : y > 0.7 ? 'after' : 'inside') : (y < 0.5 ? 'before' : 'after');
            setOver({ id: el.id, pos });
          }}
          onDragLeave={() => setOver(null)}
          onDrop={(e) => {
            e.preventDefault(); e.stopPropagation();
            let payload = null;
            try { payload = JSON.parse(e.dataTransfer.getData('text/pageforge')); } catch { /* ignore */ }
            setOver(null);
            if (payload) onDropRow(payload, el.id, over ? over.pos : 'after');
          }}
          onClick={() => onSelect(el.id, true)}
        >
          <Ic n={def.icon || 'widget'} cls="sm" />
          <span className="pfe-trow__label">{def.label || typeOf(el)}{isContainer ? ' — container' : ''}</span>
          <Ic n="grip" cls="sm pfe-trow__grip" />
        </div>
        {(el.elements || []).length > 0 && (
          <StructureRows elements={el.elements} depth={depth + 1} selectedId={selectedId} onSelect={onSelect} onDropRow={onDropRow} />
        )}
      </React.Fragment>
    );
  });
}

/* ---------------- App ---------------- */

function App() {
  const [doc, setDoc] = useState(() => JSON.parse(JSON.stringify(boot.document)));
  const [selectedId, setSelectedId] = useState(null);
  const [device, setDevice] = useState('desktop');
  const [preview, setPreview] = useState({ html: '', css: '' });
  const [dirty, setDirty] = useState(false);
  const [tab, setTab] = useState('content'); // inspector tab: content | style | page
  const [contentAdvOpen, setContentAdvOpen] = useState(false); // Content-modern Advanced disclosure
  const [styleQuery, setStyleQuery] = useState('');
  const [styleState, setStyleState] = useState('normal'); // normal | hover
  const [closedSections, setClosedSections] = useState({});
  const [query, setQuery] = useState('');
  const [panelTab, setPanelTab] = useState('elements'); // elements | page
  const [structureOpen, setStructureOpen] = useState(true);
  const [previewMode, setPreviewMode] = useState(false);
  const [zoom, setZoom] = useState(1);
  const [frameH, setFrameH] = useState(640);
  const [canvasOpts, setCanvasOpts] = useState({ grid: true, guides: false, outlines: false });
  const fitZoom = () => { zoomManual.current = false; setZoom(Math.max(0.3, Math.min(1, Math.floor(((canvasRef.current ? canvasRef.current.clientWidth : 1200) - 44) / frameW * 100) / 100))); };
  const [frameReady, setFrameReady] = useState(false);
  const [toast, setToast] = useState(boot.notice || null);
  const [ctxMenu, setCtxMenu] = useState(null);
  const [finderOpen, setFinderOpen] = useState(false);
  const [finderQuery, setFinderQuery] = useState('');
  const [showHistory, setShowHistory] = useState(false);
  const [autosaveMsg, setAutosaveMsg] = useState('');
  const [, setHistTick] = useState(0);
  useEffect(() => { setStyleQuery(''); }, [selectedId]);
  const frameRef = useRef(null);
  const canvasRef = useRef(null);
  const zoomManual = useRef(false);
  const elements = doc.elements || [];
  const selected = selectedId ? findNode(elements, selectedId) : null;
  const selectedDef = selected ? widgetByType[typeOf(selected)] : null;
  const frameW = DEVICES[device];

  /* Phase 9 history core: every mutation flows through dispatch with a
   * label, so undo/redo and the History panel share one source of truth. */
  const historyRef = useRef(null);
  if (historyRef.current === null) {
    historyRef.current = createHistory(50, JSON.parse(JSON.stringify(boot.document.elements || [])), 'Open editor');
  }
  const hist = historyRef.current;
  const docRef = useRef(doc);
  const dirtyRef = useRef(false);
  useEffect(() => { docRef.current = doc; }, [doc]);
  useEffect(() => { dirtyRef.current = dirty; }, [dirty]);

  const dispatch = (nextElements, label, coalesce = false) => {
    if (!(coalesce && hist.replace(label, nextElements))) {
      hist.push(label, nextElements);
    }
    setHistTick((t) => t + 1);
    setDoc({ elements: nextElements });
    setDirty(true);
  };
  const applyDoc = (nextElements, label = 'Edit element', coalesce = false) => dispatch(nextElements, label, coalesce);

  const undo = () => { const els = hist.undo(); if (els) { setHistTick((t) => t + 1); setDoc({ elements: els }); setDirty(true); } };
  const redo = () => { const els = hist.redo(); if (els) { setHistTick((t) => t + 1); setDoc({ elements: els }); setDirty(true); } };
  const jumpTo = (i) => { const els = hist.jump(i); if (els) { setHistTick((t) => t + 1); setDoc({ elements: els }); setDirty(true); } };

  /* Phase 9 autosave: persist the working draft as an 'autosave' revision
   * through api.php — the store's one write path; never publishes. */
  useEffect(() => {
    const seconds = Math.max(5, Number(boot.autosaveSeconds) || 30);
    const t = setInterval(async () => {
      if (!dirtyRef.current) return;
      try {
        const body = new URLSearchParams();
        body.set(boot.csrf.name, boot.csrf.value);
        body.set('action', 'autosave');
        body.set('page_id', String(boot.pageId));
        body.set('document', JSON.stringify(docRef.current));
        const res = await fetch(boot.previewUrl, { method: 'POST', body });
        if (res.ok) {
          setDirty(false);
          setAutosaveMsg('Autosaved ' + new Date().toLocaleTimeString());
        }
      } catch { /* autosave is best-effort; manual save remains */ }
    }, seconds * 1000);
    return () => clearInterval(t);
  }, []);

  /* compiled preview via the real engine (unchanged contract) */
  useEffect(() => {
    const t = setTimeout(async () => {
      try {
        const body = new URLSearchParams();
        body.set(boot.csrf.name, boot.csrf.value);
        body.set('action', 'preview');
        body.set('page_id', String(boot.pageId));
        body.set('document', JSON.stringify(doc));
        const res = await fetch(boot.previewUrl, { method: 'POST', body });
        if (res.ok) setPreview(await res.json());
      } catch { /* preview is best-effort; the saved render is the contract */ }
    }, 300);
    return () => clearTimeout(t);
  }, [doc]);

  const postToFrame = (msg) => {
    const w = frameRef.current && frameRef.current.contentWindow;
    if (w) w.postMessage({ ns: 'pageforge', ...msg }, '*');
  };

  const canvasLists = () => {
    const containers = [], emptyContainers = [];
    const walk = (els) => els.forEach((el) => {
      if (el.elType === 'container') { containers.push(el.id); if (!(el.elements || []).length) emptyContainers.push(el.id); }
      walk(el.elements || []);
    });
    walk(elements);
    return { containers, emptyContainers };
  };
  useEffect(() => { if (frameReady) postToFrame({ type: 'content', html: preview.html, css: preview.css, ...canvasLists() }); }, [preview, frameReady]);
  useEffect(() => { if (frameReady) postToFrame({ type: 'canvasopts', ...canvasOpts }); }, [canvasOpts, frameReady]);
  useEffect(() => {
    if (frameReady) postToFrame({ type: 'select', id: selectedId, name: selected ? labelOf(selected) : 'Element' });
  }, [selectedId, frameReady, preview]);
  useEffect(() => { if (frameReady) postToFrame({ type: 'editing', on: !previewMode }); }, [previewMode, frameReady]);

  useEffect(() => {
    const onMsg = (e) => {
      if (!frameRef.current || e.source !== frameRef.current.contentWindow) return;
      const d = e.data || {};
      if (d.ns !== 'pageforge') return;
      if (d.type === 'ready') setFrameReady(true);
      if (d.type === 'height') setFrameH(Math.max(480, Math.min(4000, d.h || 640)));
      if (d.type === 'select') setSelectedId(d.id);
      if (d.type === 'action') {
        if (d.action === 'duplicate') duplicateById(d.id);
        if (d.action === 'delete') removeByIdAction(d.id);
        if (d.action === 'move-up') applyDoc(moveInTree(elements, d.id, -1), 'Move element up');
        if (d.action === 'move-down') applyDoc(moveInTree(elements, d.id, 1), 'Move element down');
        if (d.action === 'style') setTab('style');
      }
      if (d.type === 'hover') {
        const n = d.id ? findNode(elements, d.id) : null;
        postToFrame({ type: 'hovername', id: d.id || null, name: n ? labelOf(n) : null });
      }
      if (d.type === 'empty-add') {
        const el = newElement('text');
        applyDoc(insertNode(elements, d.id, el, 'inside'), 'Add Text');
        setSelectedId(el.id);
      }
      if (d.type === 'drop') handleDropPayload(d.payload, d.targetId, d.targetIsRoot, d.pos);
      if (d.type === 'contextmenu') openCtxMenu(d);
      if (d.type === 'inlinestart') startInlineEdit(d.id);
      if (d.type === 'inlinecommit') commitInline(d.id, d.value);
    };
    window.addEventListener('message', onMsg);
    return () => window.removeEventListener('message', onMsg);
  });

  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(() => setToast(null), 4200);
    return () => clearTimeout(t);
  }, [toast]);

  useEffect(() => {
    if (!dirty) return;
    const h = (e) => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  const handleDropPayload = (payload, targetId, targetIsRoot, dropPos) => {
    if (!payload) return;
    if (targetId === null) {
      if (payload.kind === 'palette') {
        const el = newElement(payload.widgetType);
        const def = widgetByType[payload.widgetType];
        applyDoc(insertNode(elements, null, el, 'root'), 'Add ' + (def ? def.label : payload.widgetType));
      }
      return;
    }
    const target = findNode(elements, targetId);
    if (!target) return;
    // Canvas-UX: the bridge reports the exact drop position (before/after/
    // inside from the insertion line); fall back to the old rule when absent.
    const pos = dropPos === 'inside' && target.elType === 'container' ? 'inside'
      : (dropPos === 'before' || dropPos === 'after' ? dropPos
      : (target.elType === 'container' && targetIsRoot ? 'inside' : 'after'));
    if (payload.kind === 'palette') {
      const el = newElement(payload.widgetType);
      const def = widgetByType[payload.widgetType];
      applyDoc(insertNode(elements, targetId, el, pos), 'Add ' + (def ? def.label : payload.widgetType));
      setSelectedId(el.id);
      return;
    }
    if (payload.kind === 'node') moveNode(payload.id, targetId, pos);
  };

  const moveNode = (id, targetId, pos) => {
    if (id === targetId) return;
    const moving = findNode(elements, id);
    if (!moving) return;
    if (targetId && findNode([moving], targetId)) return; // cannot drop into own descendant
    const without = removeById(elements, id);
    applyDoc(targetId === null ? [...without, moving] : insertNode(without, targetId, moving, pos), 'Move element');
  };

  const addFromPalette = (widgetType) => {
    const el = newElement(widgetType);
    const def = widgetByType[widgetType];
    const label = 'Add ' + (def ? def.label : widgetType);
    if (selected && selected.elType === 'container') applyDoc(insertNode(elements, selected.id, el, 'inside'), label);
    else if (selected) applyDoc(insertNode(elements, selected.id, el, 'after'), label);
    else applyDoc(insertNode(elements, null, el, 'root'), label);
    setSelectedId(el.id);
  };

  const updateSelected = (key, value) => {
    if (!selected) return;
    applyDoc(mapTree(elements, (el) => el.id === selected.id
      ? { ...el, settings: { ...(el.settings || {}), [key]: value } } : el), 'Change ' + key, true);
  };

  // Media picker pick: set the picked path, and fill empty sibling
  // alt/title/description fields from the library record — one history step.
  const applyMediaPick = (key, path, itemRec) => {
    if (!selected) return;
    const def = widgetByType[typeOf(selected)] || {};
    const defControls = def.controls || {};
    const cur = selected.settings || {};
    const patch = { [key]: path };
    if (defControls.alt && !cur.alt && itemRec.alt_text) patch.alt = itemRec.alt_text;
    if (defControls.title && !cur.title && itemRec.title) patch.title = itemRec.title;
    if (defControls.description && !cur.description && itemRec.description) patch.description = itemRec.description;
    applyDoc(mapTree(elements, (el) => el.id === selected.id
      ? { ...el, settings: { ...(el.settings || {}), ...patch } } : el), 'Change ' + key, true);
  };

  // Style-UX: reset a whole section — deletes its keys (unset = engine default).
  const resetSelectedKeys = (keys) => {
    if (!selected) return;
    applyDoc(mapTree(elements, (el) => {
      if (el.id !== selected.id) return el;
      const next = { ...(el.settings || {}) };
      keys.forEach((k) => { delete next[k]; });
      return { ...el, settings: next };
    }), 'Reset style');
  };

  const removeByIdAction = (id) => { applyDoc(removeById(elements, id), 'Delete element'); if (selectedId === id) setSelectedId(null); };
  const duplicateById = (id) => {
    const node = findNode(elements, id);
    if (!node) return;
    const copy = cloneWithNewIds(node);
    applyDoc(insertNode(elements, id, copy, 'after'), 'Duplicate element');
    setSelectedId(copy.id);
  };
  const moveNodeStep = (id, dir) => { applyDoc(moveInTree(elements, id, dir), dir < 0 ? 'Move up' : 'Move down'); };

  /* Inline editing (Phase 9): the bridge makes the rendered element
   * contentEditable for PRIMARY_TEXT widgets; the commit lands here. */
  const startInlineEdit = (id) => {
    const node = findNode(elements, id);
    if (!node) return;
    const key = PRIMARY_TEXT[typeOf(node)];
    if (!key) return;
    setSelectedId(id);
    postToFrame({ type: 'inlineedit', id });
  };
  const commitInline = (id, value) => {
    const node = findNode(elements, id);
    if (!node) return;
    const key = PRIMARY_TEXT[typeOf(node)];
    if (!key || ((node.settings || {})[key] ?? '') === value) return;
    applyDoc(mapTree(elements, (el) => el.id === id
      ? { ...el, settings: { ...(el.settings || {}), [key]: value } } : el), 'Edit text', true);
  };

  /* Context menu (Phase 9): coordinates arrive in iframe space; the frame
   * element's rect converts them to editor space for the fixed-position menu. */
  const openCtxMenu = (d) => {
    setSelectedId(d.id);
    const fr = frameRef.current;
    const r = fr ? fr.getBoundingClientRect() : { left: 0, top: 0 };
    setCtxMenu({ id: d.id, x: r.left + (d.x || 0) * zoom, y: r.top + (d.y || 0) * zoom });
  };
  useEffect(() => {
    if (!ctxMenu) return undefined;
    const close = () => setCtxMenu(null);
    window.addEventListener('click', close);
    return () => window.removeEventListener('click', close);
  }, [ctxMenu]);

  const postForm = (fields) => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = boot.saveUrl;
    for (const [name, value] of Object.entries(fields)) {
      const input = document.createElement('input');
      input.type = 'hidden'; input.name = name; input.value = value;
      form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
  };

  const submit = (action) => {
    postForm({ [boot.csrf.name]: boot.csrf.value, document: JSON.stringify(doc), action });
  };

  const submitRestore = (rev) => {
    if (!window.confirm('Restore revision #' + rev.number + ' (' + rev.kind + ')? The current draft is kept in history as a new rollback revision.')) return;
    postForm({ [boot.csrf.name]: boot.csrf.value, action: 'restore', revision_id: String(rev.id) });
  };

  /* Keyboard shortcuts (Phase 9). Ctrl+K opens the Finder even while
   * typing; the rest stay out of form fields. */
  const finderResults = useMemo(() => {
    const flat = flattenTree(elements);
    const q = finderQuery.trim().toLowerCase();
    if (!q) return flat;
    return flat.filter((f) => (f.label + ' ' + f.type + ' ' + f.id + ' ' + f.text).toLowerCase().includes(q));
  }, [elements, finderQuery]);

  const jumpToElement = (id) => {
    setSelectedId(id);
    setFinderOpen(false);
    postToFrame({ type: 'scrollto', id });
  };

  useEffect(() => {
    const onKey = (e) => {
      const t = e.target;
      const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setFinderOpen((v) => !v); return; }
      if (typing || previewMode) return;
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) redo(); else undo(); return; }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); return; }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); submit('save'); return; }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') { e.preventDefault(); if (selectedId) duplicateById(selectedId); return; }
      if ((e.key === 'Delete' || e.key === 'Backspace') && selectedId) { e.preventDefault(); removeByIdAction(selectedId); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  });

  const selectFromStructure = (id, scroll) => {
    setSelectedId(id);
    if (scroll) postToFrame({ type: 'scrollto', id });
  };

  /* Fit the frame to the canvas until the user zooms manually (per device). */
  useEffect(() => { zoomManual.current = false; }, [device]);
  useEffect(() => {
    const fit = () => {
      if (zoomManual.current || !canvasRef.current) return;
      const w = canvasRef.current.clientWidth - 44;
      setZoom(Math.max(0.3, Math.min(1, Math.floor((w / DEVICES[device]) * 100) / 100)));
    };
    fit();
    window.addEventListener('resize', fit);
    return () => window.removeEventListener('resize', fit);
  }, [device, previewMode]);

  const suffix = device === 'desktop' ? '' : '_' + device;
  const deviceNote = device === 'tablet' ? 'Tablet — values apply at ≤1024 px only' : device === 'mobile' ? 'Mobile — values apply at ≤767 px only' : null;
  const searching = query.trim().length > 0;
  const visibleWidgets = useMemo(() => boot.widgets.filter((w) =>
    !searching || w.label.toLowerCase().includes(query.trim().toLowerCase()) || w.type.includes(query.trim().toLowerCase())), [query, searching]);

  return (
    <div className="pfe">
      {/* ── Top bar ─────────────────────────── */}
      <header className="pfe-topbar">
        <div className="pfe-brand"><span className="pfe-brand__mark">P</span><span className="pfe-brand__name">PageForge <em>· Kohevo</em></span></div>
        {!previewMode && <a className="pfe-iconbtn pfe-iconbtn--plain" href={boot.pagesUrl} title="Back to pages"><Ic n="back" cls="sm" /></a>}
        {previewMode && <button type="button" className="pfe-btn" onClick={() => setPreviewMode(false)}><Ic n="back" cls="sm" /> Back to editor</button>}
        <div className="pfe-pagetitle">
          {boot.pageTitle}
          <span className={'pfe-badge ' + (boot.status === 'published' ? 'is-pub' : 'is-draft')}>{boot.status === 'published' ? 'Published' : 'Draft'}</span>
          {dirty && <span className="pfe-dirty" title="Unsaved changes" />}
          {autosaveMsg && <span className="pfe-autosave">{autosaveMsg}</span>}
        </div>
        <div className="pfe-topcenter">
          <div className="pfe-devices">
            {Object.keys(DEVICES).map((d) => (
              <button key={d} type="button" className={device === d ? 'is-on' : ''} title={d}
                onClick={() => setDevice(d)}><Ic n={d === 'desktop' ? 'monitor' : d === 'tablet' ? 'tablet' : 'phone'} cls="sm" /></button>
            ))}
          </div>
          <span className="pfe-widthlbl">{frameW} px{device !== 'desktop' ? ' · ' + device : ''}</span>
        </div>
        <div className="pfe-topright">
          {previewMode ? (
            <>
              <span className="pfe-previewnote"><span className="pfe-previewnote__dot" />Exact compiled output</span>
              <span className="pfe-vr" />
              <button type="button" className="pfe-btn pfe-btn--primary" onClick={() => submit('publish')}><Ic n="publish" cls="sm" />Publish</button>
            </>
          ) : (
            <>
              <button type="button" className="pfe-iconbtn" title="Undo (Ctrl+Z)" disabled={!hist.canUndo()} onClick={undo}><Ic n="undo" cls="sm" /></button>
              <button type="button" className="pfe-iconbtn" title="Redo (Ctrl+Shift+Z)" disabled={!hist.canRedo()} onClick={redo}><Ic n="redo" cls="sm" /></button>
              <button type="button" className={'pfe-iconbtn' + (showHistory ? ' is-on' : '')} title="History & revisions" onClick={() => setShowHistory(!showHistory)}><Ic n="history" cls="sm" /></button>
              <button type="button" className="pfe-iconbtn" title="Find element (Ctrl+K)" onClick={() => setFinderOpen(true)}><Ic n="search" cls="sm" /></button>
              <span className="pfe-vr" />
              <button type="button" className={'pfe-iconbtn' + (structureOpen ? ' is-on' : '')} title="Structure panel" onClick={() => setStructureOpen(!structureOpen)}><Ic n="layers" cls="sm" /></button>
              <button type="button" className="pfe-iconbtn" title="Preview" onClick={() => { setPreviewMode(true); }}><Ic n="eye" cls="sm" /></button>
              <button type="button" className="pfe-btn pfe-btn--ghost" onClick={() => submit('save')}>Save draft</button>
              <button type="button" className="pfe-btn pfe-btn--primary" onClick={() => submit('publish')}><Ic n="publish" cls="sm" />Publish</button>
            </>
          )}
        </div>
      </header>

      <div className="pfe-main">
        {/* ── Left panel: Elements or Inspector ── */}
        {!previewMode && (
          <aside className="pfe-panel">
            {!selected || !selectedDef ? (
              <>
                <div className="pfe-ptabs">
                  <button type="button" className={panelTab === 'elements' ? 'is-on' : ''} onClick={() => setPanelTab('elements')}>Elements</button>
                  <button type="button" className={panelTab === 'page' ? 'is-on' : ''} onClick={() => setPanelTab('page')}>Page</button>
                </div>
                {panelTab === 'page' ? (
                  <PagePanel
                    postForm={postForm}
                    onInsertTemplate={async (tpl) => {
                      /* Insert applies to the saved draft server-side: persist
                       * the current canvas first (fetch, no navigation), then
                       * post the insert, which reloads the builder. */
                      try {
                        const body = new URLSearchParams();
                        body.set(boot.csrf.name, boot.csrf.value);
                        body.set('document', JSON.stringify(doc));
                        body.set('action', 'save');
                        await fetch(boot.saveUrl, { method: 'POST', body });
                      } catch { /* fall through: insert uses the last saved draft */ }
                      postForm({ [boot.csrf.name]: boot.csrf.value, action: 'insert_template', insert_template_id: String(tpl.id) });
                    }}
                  />
                ) : (
                <>
                <div className="pfe-pbody">
                  <div className="pfe-search">
                    <Ic n="search" cls="sm" />
                    <input type="text" placeholder="Search widgets..." value={query} onChange={(e) => setQuery(e.target.value)} />
                    {searching && <button type="button" className="pfe-search__x" onClick={() => setQuery('')}><Ic n="x" cls="sm" /></button>}
                  </div>
                  {searching ? (
                    <div className="pfe-cat">
                      <div className="pfe-grid">
                        {visibleWidgets.map((w) => <PaletteCard key={w.type} w={w} onAdd={addFromPalette} />)}
                        {visibleWidgets.length === 0 && <p className="pfe-hint">No widgets match.</p>}
                      </div>
                    </div>
                  ) : CATEGORIES.map(([key, label]) => {
                    const items = boot.widgets.filter((w) => (w.category || 'content') === key);
                    if (items.length === 0) return null;
                    return (
                      <div className="pfe-cat" key={key}>
                        <h4>{label}<span>{items.length}</span></h4>
                        <div className="pfe-grid">{items.map((w) => <PaletteCard key={w.type} w={w} onAdd={addFromPalette} />)}</div>
                      </div>
                    );
                  })}
                </div>
                </>
                )}
              </>
            ) : (
              <>
                <div className="pfe-insp-head">
                  <button type="button" className="pfe-iconbtn pfe-iconbtn--plain" title="Back to elements" onClick={() => setSelectedId(null)}><Ic n="back" cls="sm" /></button>
                  <span className="pfe-insp-thumb"><Ic n={selectedDef.icon || 'widget'} cls="sm" /></span>
                  <span className="pfe-insp-t"><b>{selectedDef.label}</b><small>{humanize(selectedDef.category || 'content')} element · {selected.id}</small></span>
                  <button type="button" className="pfe-iconbtn pfe-iconbtn--plain" title="Duplicate" onClick={() => duplicateById(selected.id)}><Ic n="copy" cls="sm" /></button>
                  <button type="button" className="pfe-iconbtn pfe-iconbtn--plain" title="Delete" onClick={() => removeByIdAction(selected.id)}><Ic n="trash" cls="sm" /></button>
                </div>
                <div className="pfe-ptabs">
                  <button type="button" className={tab === 'content' ? 'is-on' : ''} onClick={() => setTab('content')}>Content</button>
                  <button type="button" className={tab === 'style' ? 'is-on' : ''} onClick={() => setTab('style')}>Style</button>
                </div>
                <div className="pfe-pbody">
                  {tab === 'content' && (() => {
                    const entries = Object.entries(selectedDef.controls || {});
                    const advKeys = ['css_classes', 'css_id', 'attributes', 'custom_css'];
                    const main = entries.filter(([k]) => !advKeys.includes(k)).filter(([k]) => {
                      // Batch D: autoplay parts exist only when autoplay is on.
                      if ((k === 'autoplay_speed' || k === 'pause_on_hover' || k === 'pause_on_interaction') && (selected.settings || {}).autoplay !== 'on') return false;
                      return true;
                    });
                    const adv = entries.filter(([k]) => advKeys.includes(k));
                    if (entries.length === 0) return <p className="pfe-hint">This element has no content settings.</p>;
                    return (<>
                      {main.map(([key, c]) => (
                        <Field key={key} fname={key} skey={key} label={humanize(key)} control={c} value={(selected.settings || {})[key]} onChange={(v) => updateSelected(key, v)}
                          onMediaPick={c.type === 'media' ? (path, itemRec) => applyMediaPick(key, path, itemRec) : undefined} />
                      ))}
                      {adv.length > 0 && (
                        <div className="pfe-adv">
                          <button type="button" className="pfe-adv__h" onClick={() => setContentAdvOpen((o) => !o)}>
                            <span>Advanced</span><small>CSS classes · CSS ID · Attributes · Custom CSS</small><span className="pfe-adv__chev">{contentAdvOpen ? '⌄' : '›'}</span>
                          </button>
                          {contentAdvOpen && adv.map(([key, c]) => (
                            <Field key={key} fname={key} skey={key} label={humanize(key)} control={c} value={(selected.settings || {})[key]} onChange={(v) => updateSelected(key, v)} />
                          ))}
                        </div>
                      )}
                    </>);
                  })()}
                  {tab === 'style' && (
                    <>
                      {deviceNote && <div className="pfe-devnote"><Ic n={device === 'tablet' ? 'tablet' : 'phone'} cls="sm" />{deviceNote}</div>}
                      <div className="pfe-style-search">
                        <Ic n="search" cls="sm" />
                        <input type="text" placeholder="Search style…" value={styleQuery} onChange={(e) => setStyleQuery(e.target.value)} />
                        {styleQuery && <button type="button" className="pfe-style-search__x" title="Clear search" onClick={() => setStyleQuery('')}><Ic n="x" cls="sm" /></button>}
                      </div>
                      <div className="pfe-state-tabs">
                        <button type="button" className={styleState === 'normal' ? 'is-on' : ''} onClick={() => setStyleState('normal')}>Normal</button>
                        <button type="button" className={styleState === 'hover' ? 'is-on' : ''} onClick={() => setStyleState('hover')}>Hover</button>
                      </div>
                      {styleState === 'hover' && <p className="pfe-hint">Hover values apply on :hover (desktop only).</p>}
                      {(() => {
                        const layoutModeVal = (selected.settings || {}).layout_mode;
                        const q = styleQuery.trim().toLowerCase();
                        const visible = (selectedDef.style_keys || []).filter((key) => {
                          const c = boot.styleControls[key];
                          if (!c) return false;
                          // Grid-only controls stay hidden in flex mode, where they do nothing.
                          if (key === 'grid_columns' && layoutModeVal !== 'grid') return false;
                          // Batch A: position offsets only exist on a positioned
                          // element (same-device position wins, else desktop).
                          if (key.startsWith('position_') && key !== 'position') {
                            const posVal = (selected.settings || {})['position' + suffix] ?? (selected.settings || {}).position;
                            if (!['relative', 'absolute', 'fixed'].includes(posVal)) return false;
                          }
                          // Background image: custom coordinates/sizes only
                          // exist when their parent select is 'custom'
                          // (desktop-only keys in v1, so no suffix here).
                          if ((key === 'background_position_x' || key === 'background_position_y') && (selected.settings || {}).background_position !== 'custom') return false;
                          if ((key === 'background_size_width' || key === 'background_size_height') && (selected.settings || {}).background_size !== 'custom') return false;
                          // Batch C: divider parts need the divider on; the
                          // width % only exists on a vertical list (an
                          // inline separator is full-height by nature).
                          if ((key === 'divider_style' || key === 'divider_weight' || key === 'divider_width' || key === 'divider_color') && (selected.settings || {}).divider_on !== 'on') return false;
                          if (key === 'divider_width' && (selected.settings || {}).list_layout === 'inline') return false;
                          const isHover = key.endsWith('_hover');
                          if (styleState === 'hover' ? !isHover : isHover) return false;
                          if (q) {
                            const lbl = humanize(isHover ? key.replace(/_hover$/, '') : key).toLowerCase();
                            if (lbl.indexOf(q) === -1 && key.indexOf(q) === -1) return false;
                          }
                          return true;
                        });
                        if (visible.length === 0) return <p className="pfe-hint">No style settings match.</p>;
                        const order = [...STYLE_SECTIONS.map(([s]) => s), 'General'];
                        const groups = [];
                        const bySection = {};
                        visible.forEach((key) => {
                          const s = STYLE_SECTION_OF[key] || 'General';
                          if (!bySection[s]) { bySection[s] = []; groups.push(s); }
                          bySection[s].push(key);
                        });
                        groups.sort((a, b) => order.indexOf(a) - order.indexOf(b));
                        return groups.map((section) => {
                          const keys = bySection[section];
                          const closed = !!closedSections[section] && !q;
                          const effKeys = keys.map((k) => boot.styleControls[k].responsive ? k + suffix : k);
                          return (
                            <div className="pfe-sec" key={section}>
                              <div className="pfe-sec-h" onClick={() => setClosedSections((prev) => ({ ...prev, [section]: !prev[section] }))}>
                                <span className="pfe-sec-chev">{closed ? '›' : '⌄'}</span>
                                <b>{section}</b>
                                <span className="pfe-sec-cnt">{keys.length}</span>
                                <span className="pfe-sec-sp" />
                                <button type="button" className="pfe-sec-reset" title={'Reset ' + section}
                                  onClick={(e) => { e.stopPropagation(); resetSelectedKeys(effKeys); }}>Reset</button>
                              </div>
                              {!closed && keys.map((key) => {
                                const c = boot.styleControls[key];
                                const effectiveKey = c.responsive ? key + suffix : key;
                                const isHover = key.endsWith('_hover');
                                return (
                                  <Field key={effectiveKey} skey={key} label={humanize(isHover ? key.replace(/_hover$/, '') : key)} control={c}
                                    value={(selected.settings || {})[effectiveKey]}
                                    onChange={(v) => updateSelected(effectiveKey, v)} />
                                );
                              })}
                              {!closed && section === 'Layout' && layoutModeVal !== 'grid' && styleState === 'normal' && (
                                <p className="pfe-hint">Grid settings appear when Layout mode is Grid.</p>
                              )}
                            </div>
                          );
                        });
                      })()}
                    </>
                  )}
                </div>
              </>
            )}
          </aside>
        )}

        {/* ── Canvas ─────────────────────────── */}
        <div className={'pfe-canvas' + (previewMode ? ' is-clean' : '') + (canvasOpts.grid ? '' : ' no-grid')} ref={canvasRef}
          onClick={(e) => { if (e.target === e.currentTarget) setSelectedId(null); }}>
          {!previewMode && (
            <div className="pfe-crumbbar" style={{ width: frameW * zoom }}>
              <div className="pfe-crumbs">
                <button type="button" onClick={() => setSelectedId(null)}>🏠 {boot.pageTitle}</button>
                {(selectedId ? (findPath(elements, selectedId) || []) : []).map((c) => (
                  <span key={c.id} className="pfe-crumbset"><i>/</i><button type="button" className={c.id === selectedId ? 'is-on' : ''} onClick={() => setSelectedId(c.id)}>{c.elType === 'container' ? '▦ ' : ''}{labelOf(c)}</button></span>
                ))}
              </div>
              <span className="pfe-crumbmeta">{frameW} px{device !== 'desktop' ? ' · ' + device : ''}</span>
            </div>
          )}
          <div className="pfe-framewrap" style={{ width: frameW * zoom }}>
            <div className={'pfe-frameviewport is-fullradius' + (device !== 'desktop' && !previewMode ? ' is-bezel' : '')} style={{ width: frameW * zoom, height: frameH * zoom }}>
              <iframe
                ref={frameRef}
                className="pfe-frame"
                title="PageForge canvas"
                srcDoc={frameSrcdoc()}
                style={{ width: frameW, height: frameH, transform: 'scale(' + zoom + ')' }}
              />
              {!previewMode && elements.length === 0 && (
                <div className="pfe-dropzone pfe-welcome">
                  <div className="pfe-welcome__star">✦</div>
                  <b>Start building</b>
                  <p>Pick a block below, or drag one from the Elements panel.</p>
                  <div className="pfe-welcome__quick">
                    {['container', 'heading', 'text', 'button', 'image'].filter((wt) => widgetByType[wt]).map((wt) => (
                      <button key={wt} type="button" onClick={() => addFromPalette(wt)}>{wt === 'container' ? '▦ Container' : widgetByType[wt].label}</button>
                    ))}
                  </div>
                </div>
              )}
            </div>
            {previewMode && <div className="pfe-prevbadge">👁 Preview — exactly as published</div>}
          </div>
          {!previewMode && (
            <div className="pfe-zoombar">
              <button type="button" className="pfe-btn" onClick={() => { zoomManual.current = true; setZoom(Math.max(0.3, Math.round((zoom - 0.1) * 10) / 10)); }}>−</button>
              <span className="pfe-zoomval">{Math.round(zoom * 100)}%</span>
              <button type="button" className="pfe-btn is-fit" onClick={fitZoom}>Fit</button>
              <button type="button" className="pfe-btn" onClick={() => { zoomManual.current = true; setZoom(Math.min(1.5, Math.round((zoom + 0.1) * 10) / 10)); }}>+</button>
              <span className="pfe-sep" />
              <button type="button" className={'pfe-btn pfe-tgl' + (canvasOpts.grid ? ' is-on' : '')} onClick={() => setCanvasOpts((o) => ({ ...o, grid: !o.grid }))}>▦ Grid</button>
              <button type="button" className={'pfe-btn pfe-tgl' + (canvasOpts.guides ? ' is-on' : '')} onClick={() => setCanvasOpts((o) => ({ ...o, guides: !o.guides }))}>⤢ Guides</button>
              <button type="button" className={'pfe-btn pfe-tgl' + (canvasOpts.outlines ? ' is-on' : '')} onClick={() => setCanvasOpts((o) => ({ ...o, outlines: !o.outlines }))}>⬒ Outlines</button>
            </div>
          )}
        </div>

        {/* ── Floating Structure panel ─────────── */}
        {!previewMode && structureOpen && (
          <div className="pfe-structure">
            <div className="pfe-structure__head"><b>Structure</b>
              <button type="button" className="pfe-iconbtn pfe-iconbtn--plain" onClick={() => setStructureOpen(false)}><Ic n="x" cls="sm" /></button>
            </div>
            <div className="pfe-tree"
              onDragOver={(e) => e.preventDefault()}
              onDrop={(e) => {
                e.preventDefault();
                try {
                  const payload = JSON.parse(e.dataTransfer.getData('text/pageforge'));
                  if (payload.kind === 'palette') addFromPalette(payload.widgetType);
                  if (payload.kind === 'node') moveNode(payload.id, null, 'root');
                } catch { /* ignore */ }
              }}
            >
              <StructureRows elements={elements} depth={0} selectedId={selectedId} onSelect={selectFromStructure}
                onDropRow={(payload, targetId, pos) => {
                  if (payload.kind === 'palette') {
                    const el = newElement(payload.widgetType);
                    applyDoc(insertNode(elements, targetId, el, pos));
                    setSelectedId(el.id);
                  } else if (payload.kind === 'node') moveNode(payload.id, targetId, pos);
                }} />
              {elements.length === 0 && <p className="pfe-hint">No elements yet.</p>}
            </div>
            <div className="pfe-structure__note">Click a row to select it on the canvas. Drag rows to reorder; drop onto a container's middle to nest inside it.</div>
          </div>
        )}
      </div>

      {/* ── History & revisions drawer (Phase 9) ── */}
      {!previewMode && showHistory && (
        <div className="pfe-history" style={{ top: 70, right: structureOpen ? 300 : 16 }}>
          <div className="pfe-history__head"><b>History</b>
            <button type="button" className="pfe-iconbtn pfe-iconbtn--plain" onClick={() => setShowHistory(false)}><Ic n="x" cls="sm" /></button>
          </div>
          <div className="pfe-history__col">
            <h4>This session</h4>
            {hist.entries().map((entry, i) => (
              <button key={i} type="button" className={'pfe-history__item' + (entry.active ? ' is-active' : '')} onClick={() => jumpTo(i)}>{entry.label}</button>
            ))}
          </div>
          <div className="pfe-history__col">
            <h4>Saved revisions</h4>
            {(boot.revisions || []).map((rev) => (
              <div key={rev.id} className="pfe-history__rev">
                <span>#{rev.number} · {rev.kind} · {rev.created_at}</span>
                <button type="button" onClick={() => submitRestore(rev)}>Restore</button>
              </div>
            ))}
            {(boot.revisions || []).length === 0 && <p className="pfe-hint">No saved revisions yet.</p>}
          </div>
        </div>
      )}

      {/* ── Context menu (Phase 9) ── */}
      {ctxMenu && (
        <div className="pfe-ctxmenu" style={{ left: ctxMenu.x, top: ctxMenu.y }} onClick={(e) => e.stopPropagation()}>
          <button type="button" onClick={() => { duplicateById(ctxMenu.id); setCtxMenu(null); }}>Duplicate</button>
          <button type="button" onClick={() => { moveNodeStep(ctxMenu.id, -1); setCtxMenu(null); }}>Move up</button>
          <button type="button" onClick={() => { moveNodeStep(ctxMenu.id, 1); setCtxMenu(null); }}>Move down</button>
          <button type="button" className="is-danger" onClick={() => { removeByIdAction(ctxMenu.id); setCtxMenu(null); }}>Delete</button>
        </div>
      )}

      {/* ── Finder (Phase 9, Ctrl+K) ── */}
      {finderOpen && (
        <div className="pfe-finder" role="dialog" aria-label="Find element">
          <input
            autoFocus
            value={finderQuery}
            onChange={(e) => setFinderQuery(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Escape') setFinderOpen(false); if (e.key === 'Enter' && finderResults[0]) jumpToElement(finderResults[0].id); }}
            placeholder="Find element by name, type or text…"
          />
          <div className="pfe-finder__results">
            {finderResults.map((f) => (
              <button key={f.id} type="button" onClick={() => jumpToElement(f.id)} style={{ paddingLeft: 10 + f.depth * 14 }}>
                <strong>{f.label}</strong>{f.text ? <span> — {f.text}</span> : null}
              </button>
            ))}
            {finderResults.length === 0 && <p className="pfe-hint">No elements match.</p>}
          </div>
          <button type="button" className="pfe-finder__close" onClick={() => setFinderOpen(false)}>Close (Esc)</button>
        </div>
      )}

      {toast && (
        <div className={'pfe-toast is-' + (toast.type || 'success')}>
          <Ic n="check" cls="sm" />{toast.msg}
        </div>
      )}
    </div>
  );
}

/* ---------------- Page panel (Phases 10 + 11) ----------------
 * Page-level settings that used to live as PHP forms under the classic
 * builder: display conditions (parts/popups), popup settings/triggers/
 * rules, the dynamic-tag reference, and templates + export. Every save
 * posts to builder.php, which routes it through PageForgeStore exactly
 * like the classic forms did — one write path, one sanitizer gate.
 */
function PagePanel({ postForm, onInsertTemplate }) {
  const csrf = { [boot.csrf.name]: boot.csrf.value };
  const [condInc, setCondInc] = useState(boot.conditions ? boot.conditions.include : '');
  const [condExc, setCondExc] = useState(boot.conditions ? boot.conditions.exclude : '');
  const pop = boot.popup;
  const [popState, setPopState] = useState(pop ? JSON.parse(JSON.stringify(pop)) : null);
  const [tplTitle, setTplTitle] = useState('');
  const setPop = (path, value) => {
    setPopState((prev) => {
      const next = JSON.parse(JSON.stringify(prev));
      let node = next;
      for (let i = 0; i < path.length - 1; i++) node = node[path[i]];
      node[path[path.length - 1]] = value;
      return next;
    });
  };
  const chk = (path) => ({
    checked: path.reduce((n, k) => (n ? n[k] : undefined), popState) === true,
    onChange: (e) => setPop(path, e.target.checked),
  });
  const num = (path) => ({
    value: path.reduce((n, k) => (n ? n[k] : undefined), popState) ?? 0,
    onChange: (e) => setPop(path, e.target.value === '' ? 0 : Number(e.target.value)),
  });
  const sel = (path) => ({
    value: path.reduce((n, k) => (n ? n[k] : undefined), popState) ?? '',
    onChange: (e) => setPop(path, e.target.value),
  });
  const savePopup = () => {
    if (!popState) return;
    const s = popState.settings || {};
    const t = popState.triggers || {};
    const r = popState.rules || {};
    const on = (b) => (b ? '1' : undefined);
    postForm({
      ...csrf,
      action: 'save_popup',
      pop_position: s.position || 'center',
      pop_width: String(s.width ?? 640),
      pop_animation: s.animation || 'fade',
      pop_overlay: on(s.overlay),
      pop_close_button: on(s.close_button),
      pop_prevent_scroll: on(s.prevent_scroll),
      pop_avoid_multiple: on(s.avoid_multiple),
      pop_t_load: on((t.on_load || {}).enabled),
      pop_t_load_delay: String((t.on_load || {}).delay ?? 0),
      pop_t_scroll: on((t.on_scroll || {}).enabled),
      pop_t_scroll_pct: String((t.on_scroll || {}).percent ?? 50),
      pop_t_idle: on((t.inactivity || {}).enabled),
      pop_t_idle_sec: String((t.inactivity || {}).seconds ?? 30),
      pop_t_exit: on((t.exit_intent || {}).enabled),
      pop_max_shows: String(r.max_shows ?? 0),
      pop_max_day: String(r.max_per_day ?? 0),
      pop_hide_views: String(r.hide_after_views ?? 0),
      pop_devices: r.devices || 'all',
    });
  };

  return (
    <div className="pfe-pbody pfe-pagepanel">
      <div className="pfe-pp-sec">
        <h4>{boot.pageTitle}</h4>
        <p className="pfe-hint"><code>/forge/{boot.slug}</code> · {boot.status} · {boot.kind}{boot.part ? ' · ' + boot.part : ''}</p>
        <a className="pfe-btn pfe-btn--ghost pfe-pp-export" href={boot.exportUrl}><Ic n="download" cls="sm" /> Download page JSON</a>
        <p className="pfe-hint">Import this package from the PageForge page list.</p>
      </div>

      {boot.conditions && (
        <div className="pfe-pp-sec">
          <h4>Display conditions</h4>
          <label className="pfe-field"><span className="pfe-label">Include rules</span>
            <input type="text" value={condInc} onChange={(e) => setCondInc(e.target.value)} placeholder="entire" /></label>
          <label className="pfe-field"><span className="pfe-label">Exclude rules</span>
            <input type="text" value={condExc} onChange={(e) => setCondExc(e.target.value)} /></label>
          <p className="pfe-hint">Comma-separated <code>scope:value</code> pairs. Scopes: <code>entire</code> · <code>route:page</code> · <code>route:notfound</code> · <code>slug:&lt;page-slug&gt;</code> · <code>kind:page</code>. Empty include = everywhere; any exclude match hides it there.</p>
          <button type="button" className="pfe-btn pfe-btn--primary" onClick={() => postForm({ ...csrf, action: 'save_conditions', cond_include: condInc, cond_exclude: condExc })}>Save conditions</button>
        </div>
      )}

      {popState && (
        <div className="pfe-pp-sec">
          <h4>Popup settings</h4>
          <label className="pfe-field"><span className="pfe-label">Position</span>
            <select {...sel(['settings', 'position'])}>{(boot.popupOptions.positions || []).map((o) => <option key={o} value={o}>{o}</option>)}</select></label>
          <label className="pfe-field"><span className="pfe-label">Width (px)</span>
            <input type="number" min="200" max="1200" {...num(['settings', 'width'])} /></label>
          <label className="pfe-field"><span className="pfe-label">Entrance animation</span>
            <select {...sel(['settings', 'animation'])}>{(boot.popupOptions.animations || []).map((o) => <option key={o} value={o}>{o}</option>)}</select></label>
          <label className="pfe-check"><input type="checkbox" {...chk(['settings', 'overlay'])} /> Overlay click closes</label>
          <label className="pfe-check"><input type="checkbox" {...chk(['settings', 'close_button'])} /> Close button</label>
          <label className="pfe-check"><input type="checkbox" {...chk(['settings', 'prevent_scroll'])} /> Prevent page scroll while open</label>
          <label className="pfe-check"><input type="checkbox" {...chk(['settings', 'avoid_multiple'])} /> Avoid multiple popups open at once</label>
          <h4>Triggers</h4>
          <label className="pfe-check"><input type="checkbox" {...chk(['triggers', 'on_load', 'enabled'])} /> On load, after (s)</label>
          <label className="pfe-field"><input type="number" min="0" max="120" {...num(['triggers', 'on_load', 'delay'])} /></label>
          <label className="pfe-check"><input type="checkbox" {...chk(['triggers', 'on_scroll', 'enabled'])} /> On scroll (%)</label>
          <label className="pfe-field"><input type="number" min="5" max="100" {...num(['triggers', 'on_scroll', 'percent'])} /></label>
          <label className="pfe-check"><input type="checkbox" {...chk(['triggers', 'inactivity', 'enabled'])} /> After inactivity of (s)</label>
          <label className="pfe-field"><input type="number" min="5" max="3600" {...num(['triggers', 'inactivity', 'seconds'])} /></label>
          <label className="pfe-check"><input type="checkbox" {...chk(['triggers', 'exit_intent', 'enabled'])} /> On exit intent</label>
          <p className="pfe-hint">On click stays available through any button whose Popup ID targets this popup.</p>
          <h4>Advanced rules</h4>
          <label className="pfe-field"><span className="pfe-label">Max shows total (0 = unlimited)</span>
            <input type="number" min="0" {...num(['rules', 'max_shows'])} /></label>
          <label className="pfe-field"><span className="pfe-label">Max shows per day</span>
            <input type="number" min="0" {...num(['rules', 'max_per_day'])} /></label>
          <label className="pfe-field"><span className="pfe-label">Hide after page views</span>
            <input type="number" min="0" {...num(['rules', 'hide_after_views'])} /></label>
          <label className="pfe-field"><span className="pfe-label">Devices</span>
            <select {...sel(['rules', 'devices'])}>{(boot.popupOptions.devices || []).map((o) => <option key={o} value={o}>{o}</option>)}</select></label>
          <button type="button" className="pfe-btn pfe-btn--primary" onClick={savePopup}>Save popup settings</button>
        </div>
      )}

      <div className="pfe-pp-sec">
        <h4>Templates</h4>
        <label className="pfe-field"><span className="pfe-label">Save this page as a template</span>
          <input type="text" value={tplTitle} onChange={(e) => setTplTitle(e.target.value)} placeholder="Template name (defaults to the page title)" /></label>
        <button type="button" className="pfe-btn pfe-btn--ghost" onClick={() => postForm({ ...csrf, action: 'save_template', template_title: tplTitle })}>Save as template</button>
        {(boot.templates || []).length > 0 && (
          <>
            <h4>Insert a template</h4>
            {(boot.templates || []).map((tpl) => (
              <div key={tpl.id} className="pfe-pp-tpl">
                <span>{tpl.title}</span>
                <button type="button" className="pfe-btn" onClick={() => onInsertTemplate(tpl)}>Insert</button>
              </div>
            ))}
            <p className="pfe-hint">Insert appends the template's elements to this page's draft (your current canvas is saved first).</p>
          </>
        )}
      </div>

      <div className="pfe-pp-sec">
        <h4>Dynamic tags</h4>
        <p className="pfe-hint">Use these tokens in any widget's text settings. Loop fields like <code>{'{{title}}'}</code> keep working inside loop templates.</p>
        <ul className="pfe-pp-tags">
          {(boot.dynamicTags || []).map((t) => (
            <li key={t.token}><code>{'{{' + t.token + '}}'}</code> — {t.label}</li>
          ))}
        </ul>
      </div>
    </div>
  );
}

function PaletteCard({ w, onAdd }) {
  return (
    <button
      type="button" className="pfe-wcard"
      draggable
      onDragStart={(e) => e.dataTransfer.setData('text/pageforge', JSON.stringify({ kind: 'palette', widgetType: w.type }))}
      onClick={() => onAdd(w.type)}
      title={'Add ' + w.label}
    >
      <Ic n={w.icon || 'widget'} />
      <span>{w.label}{(w.category || '') === 'kohevo' && <span className="pfe-pro">PRO</span>}</span>
    </button>
  );
}

createRoot(document.getElementById('pageforge-editor')).render(<App />);
