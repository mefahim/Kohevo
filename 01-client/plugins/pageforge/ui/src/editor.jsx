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

const boot = window.PAGEFORGE_BOOT;
const widgetByType = Object.fromEntries(boot.widgets.map((w) => [w.type, w]));
const CATEGORIES = [
  ['layout', 'Layout'], ['content', 'Content'], ['media', 'Media'],
  ['interactive', 'Interactive'], ['kohevo', 'Kohevo'],
];
const DEVICES = { desktop: 1280, tablet: 768, mobile: 390 };

const uid = () => 'pf' + Math.random().toString(16).slice(2, 10);
const humanize = (key) => String(key).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

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
};

function Ic({ n, cls }) {
  return (
    <svg className={'pfe-ic ' + (cls || '')} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {PATHS[n] || PATHS.widget}
    </svg>
  );
}

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

function typeOf(el) {
  return el.elType === 'container' ? 'container' : el.widgetType;
}

function labelOf(el) {
  const def = widgetByType[typeOf(el)];
  return def ? def.label : typeOf(el);
}

/* ---------------- Inspector field editors ---------------- */

function Field({ label, control, value, onChange }) {
  const type = control.type;
  if (type === 'repeater') {
    const items = Array.isArray(value) ? value : [];
    const setItem = (i, field, v) => {
      const next = items.slice();
      next[i] = { ...next[i], [field]: v };
      onChange(next);
    };
    const addItem = () => {
      const item = {};
      for (const [f, c] of Object.entries(control.item_schema || {})) {
        item[f] = c.default !== undefined ? c.default : (c.type === 'select' ? (c.options || [])[0] ?? '' : '');
      }
      onChange([...items, item]);
    };
    return (
      <div className="pfe-field pfe-field--repeater">
        <span className="pfe-label">{label}</span>
        {items.map((item, i) => (
          <div className="pfe-repeater-item" key={i}>
            <div className="pfe-repeater-item__head">
              <span>Item {i + 1}</span>
              <button type="button" title="Remove item" onClick={() => { const n = items.slice(); n.splice(i, 1); onChange(n); }}><Ic n="x" cls="sm" /></button>
            </div>
            {Object.entries(control.item_schema || {}).map(([f, c]) => (
              <Field key={f} label={humanize(f)} control={c} value={item[f]} onChange={(v) => setItem(i, f, v)} />
            ))}
          </div>
        ))}
        <button type="button" className="pfe-add-item" onClick={addItem}>+ Add item</button>
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
  if (type === 'number') {
    const hasRange = control.min !== undefined && control.max !== undefined;
    return (
      <div className="pfe-field">
        <span className="pfe-label">{label}</span>
        {hasRange ? (
          <span className="pfe-numrow">
            <input type="range" min={control.min} max={control.max} value={value === '' || value === undefined ? control.min : value}
              onChange={(e) => onChange(Number(e.target.value))} />
            <input type="number" className="pfe-num" value={value ?? ''} min={control.min} max={control.max}
              onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} />
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
`;

const BRIDGE_JS = `
(function () {
  var editing = true, selectedId = null, hoverId = null;
  var chip = document.getElementById('pf-chip');
  var chipLabel = document.getElementById('pf-chip-label');
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
  }
  function positionChip() {
    var el = elById(selectedId);
    if (!el || !editing) { chip.style.display = 'none'; return; }
    var r = el.getBoundingClientRect();
    chip.style.display = 'flex';
    chip.style.left = Math.max(0, r.left + window.scrollX - 2) + 'px';
    chip.style.top = Math.max(0, r.top + window.scrollY - 29) + 'px';
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
      requestAnimationFrame(function () { applySel(selectedId); sendHeight(); });
      setTimeout(sendHeight, 350);
    }
    if (d.type === 'select') { chipLabel.textContent = d.name || 'Element'; applySel(d.id || null); }
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
  });
  document.addEventListener('click', function (e) {
    if (!editing) return;
    if (e.target.closest && e.target.closest('#pf-chip')) return;
    var hit = closestEl(e.target);
    e.preventDefault(); e.stopPropagation();
    post({ type: 'select', id: hit ? hit.id : null });
  }, true);
  document.addEventListener('mouseover', function (e) {
    if (!editing) return;
    var hit = closestEl(e.target);
    var id = hit ? hit.id : null;
    if (id === hoverId) return;
    clearHover();
    if (id && id !== selectedId) { var n = elById(id); if (n) { n.classList.add('pfe-hover'); hoverId = id; } }
  }, true);
  chip.addEventListener('dragstart', function (e) {
    if (!selectedId) return;
    e.dataTransfer.setData('text/pageforge', JSON.stringify({ kind: 'node', id: selectedId }));
    e.dataTransfer.effectAllowed = 'move';
  });
  document.addEventListener('dragover', function (e) { if (editing) e.preventDefault(); });
  document.addEventListener('drop', function (e) {
    if (!editing) return;
    e.preventDefault(); e.stopPropagation();
    var payload = null;
    try { payload = JSON.parse(e.dataTransfer.getData('text/pageforge')); } catch (err) { /* ignore */ }
    if (!payload) return;
    var hit = closestEl(e.target);
    post({ type: 'drop', payload: payload, targetId: hit ? hit.id : null, targetIsRoot: hit ? e.target === hit.el : false });
  });
  chip.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('button[data-act]') : null;
    if (btn && selectedId) post({ type: 'action', action: btn.getAttribute('data-act'), id: selectedId });
  });
  window.addEventListener('scroll', positionChip, { passive: true });
  window.addEventListener('resize', positionChip);
  post({ type: 'ready' });
})();
`;

const CHIP_HTML = `<div id="pf-chip" draggable="true"><span id="pf-chip-label">Element</span><span class="pf-chip-acts">`
  + `<button type="button" data-act="duplicate" title="Duplicate"><svg viewBox="0 0 24 24"><rect x="8.5" y="8.5" width="11.5" height="11.5" rx="2"/><path d="M16 8.5v-2A2.5 2.5 0 0 0 13.5 4h-7A2.5 2.5 0 0 0 4 6.5v7A2.5 2.5 0 0 0 6.5 16h2"/></svg></button>`
  + `<button type="button" data-act="delete" title="Delete"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button></span></div>`;

function frameSrcdoc() {
  return '<!doctype html><html><head><meta charset="utf-8">'
    + '<link rel="stylesheet" href="' + boot.pageCssUrl + '">'
    + '<style>' + BRIDGE_CSS + '</style><style id="pf-css"></style></head>'
    + '<body class="pf-editing"><div id="pf-root"></div>' + CHIP_HTML
    + '<script>' + BRIDGE_JS + '<\/script></body></html>';
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
  const [tab, setTab] = useState('content'); // inspector tab
  const [query, setQuery] = useState('');
  const [structureOpen, setStructureOpen] = useState(true);
  const [previewMode, setPreviewMode] = useState(false);
  const [zoom, setZoom] = useState(1);
  const [frameH, setFrameH] = useState(640);
  const [frameReady, setFrameReady] = useState(false);
  const [toast, setToast] = useState(boot.notice || null);
  const frameRef = useRef(null);
  const canvasRef = useRef(null);
  const zoomManual = useRef(false);
  const elements = doc.elements || [];
  const selected = selectedId ? findNode(elements, selectedId) : null;
  const selectedDef = selected ? widgetByType[typeOf(selected)] : null;
  const frameW = DEVICES[device];

  const applyDoc = (nextElements) => { setDoc({ elements: nextElements }); setDirty(true); };

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

  useEffect(() => { if (frameReady) postToFrame({ type: 'content', html: preview.html, css: preview.css }); }, [preview, frameReady]);
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
      }
      if (d.type === 'drop') handleDropPayload(d.payload, d.targetId, d.targetIsRoot);
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

  const handleDropPayload = (payload, targetId, targetIsRoot) => {
    if (!payload) return;
    if (targetId === null) {
      if (payload.kind === 'palette') applyDoc(insertNode(elements, null, newElement(payload.widgetType), 'root'));
      return;
    }
    const target = findNode(elements, targetId);
    if (!target) return;
    const pos = target.elType === 'container' && targetIsRoot ? 'inside' : 'after';
    if (payload.kind === 'palette') {
      const el = newElement(payload.widgetType);
      applyDoc(insertNode(elements, targetId, el, target.elType === 'container' ? 'inside' : pos));
      setSelectedId(el.id);
      return;
    }
    if (payload.kind === 'node') moveNode(payload.id, targetId, target.elType === 'container' && targetIsRoot ? 'inside' : pos);
  };

  const moveNode = (id, targetId, pos) => {
    if (id === targetId) return;
    const moving = findNode(elements, id);
    if (!moving) return;
    if (targetId && findNode([moving], targetId)) return; // cannot drop into own descendant
    const without = removeById(elements, id);
    applyDoc(targetId === null ? [...without, moving] : insertNode(without, targetId, moving, pos));
  };

  const addFromPalette = (widgetType) => {
    const el = newElement(widgetType);
    if (selected && selected.elType === 'container') applyDoc(insertNode(elements, selected.id, el, 'inside'));
    else if (selected) applyDoc(insertNode(elements, selected.id, el, 'after'));
    else applyDoc(insertNode(elements, null, el, 'root'));
    setSelectedId(el.id);
  };

  const updateSelected = (key, value) => {
    if (!selected) return;
    applyDoc(mapTree(elements, (el) => el.id === selected.id
      ? { ...el, settings: { ...(el.settings || {}), [key]: value } } : el));
  };

  const removeByIdAction = (id) => { applyDoc(removeById(elements, id)); if (selectedId === id) setSelectedId(null); };
  const duplicateById = (id) => {
    const node = findNode(elements, id);
    if (!node) return;
    const copy = cloneWithNewIds(node);
    applyDoc(insertNode(elements, id, copy, 'after'));
    setSelectedId(copy.id);
  };

  const submit = (action) => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = boot.saveUrl;
    const add = (name, value) => {
      const input = document.createElement('input');
      input.type = 'hidden'; input.name = name; input.value = value;
      form.appendChild(input);
    };
    add(boot.csrf.name, boot.csrf.value);
    add('document', JSON.stringify(doc));
    add('action', action);
    document.body.appendChild(form);
    form.submit();
  };

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
                  <button type="button" className="is-on">Elements</button>
                  <button type="button" className={structureOpen ? 'is-on' : ''} onClick={() => setStructureOpen(!structureOpen)}>Structure</button>
                </div>
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
                  {tab === 'content' && (
                    Object.entries(selectedDef.controls || {}).length === 0
                      ? <p className="pfe-hint">This element has no content settings.</p>
                      : Object.entries(selectedDef.controls || {}).map(([key, c]) => (
                        <Field key={key} label={humanize(key)} control={c} value={(selected.settings || {})[key]} onChange={(v) => updateSelected(key, v)} />
                      ))
                  )}
                  {tab === 'style' && (
                    <>
                      {deviceNote && <div className="pfe-devnote"><Ic n={device === 'tablet' ? 'tablet' : 'phone'} cls="sm" />{deviceNote}</div>}
                      {(selectedDef.style_keys || []).length === 0 && <p className="pfe-hint">This element has no style settings.</p>}
                      {(selectedDef.style_keys || []).map((key) => {
                        const c = boot.styleControls[key];
                        if (!c) return null;
                        const effectiveKey = c.responsive ? key + suffix : key;
                        return (
                          <Field key={effectiveKey} label={humanize(key)} control={c}
                            value={(selected.settings || {})[effectiveKey]}
                            onChange={(v) => updateSelected(effectiveKey, v)} />
                        );
                      })}
                    </>
                  )}
                </div>
              </>
            )}
          </aside>
        )}

        {/* ── Canvas ─────────────────────────── */}
        <div className={'pfe-canvas' + (previewMode ? ' is-clean' : '')} ref={canvasRef}
          onClick={(e) => { if (e.target === e.currentTarget) setSelectedId(null); }}>
          <div className="pfe-framewrap" style={{ width: frameW * zoom }}>
            {!previewMode && (
              <div className="pfe-framebar">
                <span className="pfe-dot" style={{ background: '#ff5f57' }} /><span className="pfe-dot" style={{ background: '#febc2e' }} /><span className="pfe-dot" style={{ background: '#28c840' }} />
                <span className="pfe-frameurl">Editing draft · {boot.publicUrl}</span>
              </div>
            )}
            <div className={'pfe-frameviewport' + (previewMode ? ' is-fullradius' : '')} style={{ width: frameW * zoom, height: frameH * zoom }}>
              <iframe
                ref={frameRef}
                className="pfe-frame"
                title="PageForge canvas"
                srcDoc={frameSrcdoc()}
                style={{ width: frameW, height: frameH, transform: 'scale(' + zoom + ')' }}
              />
              {!previewMode && elements.length === 0 && (
                <div className="pfe-dropzone">Drop widgets here, or click a widget in the panel</div>
              )}
            </div>
          </div>
          {!previewMode && (
            <div className="pfe-zoombar">
              <button type="button" className="pfe-btn" onClick={() => { zoomManual.current = true; setZoom(Math.max(0.3, Math.round((zoom - 0.1) * 10) / 10)); }}>−</button>
              <button type="button" className="pfe-btn" onClick={() => { zoomManual.current = false; setZoom(Math.max(0.3, Math.min(1, Math.floor(((canvasRef.current ? canvasRef.current.clientWidth : 1200) - 44) / frameW * 100) / 100))); }}>{Math.round(zoom * 100)}%</button>
              <button type="button" className="pfe-btn" onClick={() => { zoomManual.current = true; setZoom(Math.min(1.5, Math.round((zoom + 0.1) * 10) / 10)); }}>+</button>
            </div>
          )}
          {previewMode && <div className="pfe-previewcap">This is the compiled page exactly as publish serves it.</div>}
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

      {toast && (
        <div className={'pfe-toast is-' + (toast.type || 'success')}>
          <Ic n="check" cls="sm" />{toast.msg}
        </div>
      )}
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
