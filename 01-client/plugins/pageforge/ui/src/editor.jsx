/**
 * PageForge canvas editor shell (Phase 2).
 *
 * Structural drag/drop editing over the same document tree the PHP engine
 * renders. The canvas is deliberately structural (labeled blocks); the
 * truthful render lives in the Preview pane, which round-trips the real
 * PageForgeRenderer through admin/api.php — what you see there is byte-for-
 * byte what publish serves. Saving posts the document JSON to builder.php,
 * the same contract the no-JS fallback form uses, so there is one write
 * path and one sanitizer gate.
 */
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

const boot = window.PAGEFORGE_BOOT;
const widgetByType = Object.fromEntries(boot.widgets.map((w) => [w.type, w]));

const uid = () => 'pf' + Math.random().toString(16).slice(2, 10);

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
        <span className="pfe-field__label">{label}</span>
        {items.map((item, i) => (
          <div className="pfe-repeater-item" key={i}>
            <div className="pfe-repeater-item__head">
              <span>Item {i + 1}</span>
              <span>
                <button type="button" onClick={() => { const n = items.slice(); n.splice(i, 1); onChange(n); }}>✕</button>
              </span>
            </div>
            {Object.entries(control.item_schema || {}).map(([f, c]) => (
              <Field key={f} label={f} control={c} value={item[f]} onChange={(v) => setItem(i, f, v)} />
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
      <label className="pfe-field">
        <span className="pfe-field__label">{label}</span>
        <span className="pfe-dims">
          {['top', 'right', 'bottom', 'left'].map((side) => (
            <input key={side} type="number" value={v[side] ?? 0} title={side} onChange={(e) => set(side, e.target.value)} />
          ))}
          <select value={v.unit || 'px'} onChange={(e) => onChange({ ...v, unit: e.target.value })}>
            {['px', '%', 'rem', 'em'].map((u) => <option key={u} value={u}>{u}</option>)}
          </select>
        </span>
      </label>
    );
  }
  if (type === 'select') {
    return (
      <label className="pfe-field">
        <span className="pfe-field__label">{label}</span>
        <select value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          {(control.options || []).map((o) => <option key={o} value={o}>{o}</option>)}
        </select>
      </label>
    );
  }
  if (type === 'color') {
    return (
      <label className="pfe-field">
        <span className="pfe-field__label">{label}</span>
        <span className="pfe-color">
          <input type="color" value={/^#[0-9a-f]{6}$/i.test(value || '') ? value : '#000000'} onChange={(e) => onChange(e.target.value)} />
          <input type="text" value={value ?? ''} placeholder="#rrggbb" onChange={(e) => onChange(e.target.value)} />
        </span>
      </label>
    );
  }
  if (type === 'number') {
    return (
      <label className="pfe-field">
        <span className="pfe-field__label">{label}</span>
        <input type="number" value={value ?? ''} min={control.min} max={control.max} onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} />
      </label>
    );
  }
  if (type === 'textarea' || type === 'raw_html') {
    return (
      <label className="pfe-field">
        <span className="pfe-field__label">{label}</span>
        <textarea rows={type === 'raw_html' ? 8 : 4} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
      </label>
    );
  }
  return (
    <label className="pfe-field">
      <span className="pfe-field__label">{label}</span>
      <input type="text" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
    </label>
  );
}

/* ---------------- Canvas ---------------- */

function CanvasNode({ el, selectedId, onSelect, onDropPayload, depth }) {
  const def = widgetByType[typeOf(el)] || { label: typeOf(el) };
  const selected = el.id === selectedId;
  const [over, setOver] = useState(false);
  return (
    <div
      className={'pfe-node' + (el.elType === 'container' ? ' pfe-node--container' : '') + (selected ? ' is-selected' : '') + (over ? ' is-dragover' : '')}
      onClick={(e) => { e.stopPropagation(); onSelect(el.id); }}
      onDragOver={(e) => { e.preventDefault(); e.stopPropagation(); setOver(true); }}
      onDragLeave={() => setOver(false)}
      onDrop={(e) => {
        e.preventDefault(); e.stopPropagation(); setOver(false);
        let payload = null;
        try { payload = JSON.parse(e.dataTransfer.getData('text/pageforge')); } catch { /* ignore */ }
        if (payload) onDropPayload(payload, el);
      }}
    >
      <div
        className="pfe-node__bar"
        draggable
        onDragStart={(e) => {
          e.stopPropagation();
          e.dataTransfer.setData('text/pageforge', JSON.stringify({ kind: 'node', id: el.id }));
          e.dataTransfer.effectAllowed = 'move';
        }}
      >
        <span className="pfe-node__label">{def.label}</span>
        <span className="pfe-node__id">{el.id}</span>
      </div>
      {(el.elements || []).map((child) => (
        <CanvasNode key={child.id} el={child} selectedId={selectedId} onSelect={onSelect} onDropPayload={onDropPayload} depth={depth + 1} />
      ))}
      {el.elType === 'container' && (el.elements || []).length === 0 && <div className="pfe-node__empty">Drop widgets here</div>}
    </div>
  );
}

/* ---------------- App ---------------- */

function App() {
  const [doc, setDoc] = useState(() => JSON.parse(JSON.stringify(boot.document)));
  const [selectedId, setSelectedId] = useState(null);
  const [device, setDevice] = useState('desktop');
  const [preview, setPreview] = useState({ html: '', css: '' });
  const [dirty, setDirty] = useState(false);
  const elements = doc.elements || [];
  const selected = selectedId ? findNode(elements, selectedId) : null;
  const selectedDef = selected ? widgetByType[typeOf(selected)] : null;

  const applyDoc = (nextElements) => { setDoc({ elements: nextElements }); setDirty(true); };

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
    }, 350);
    return () => clearTimeout(t);
  }, [doc]);

  const handleDropPayload = (payload, targetEl) => {
    const targetIsContainer = targetEl.elType === 'container';
    if (payload.kind === 'palette') {
      applyDoc(insertNode(elements, targetEl.id, newElement(payload.widgetType), targetIsContainer ? 'inside' : 'after'));
      return;
    }
    if (payload.kind === 'node') {
      if (payload.id === targetEl.id) return;
      const moving = findNode(elements, payload.id);
      if (!moving) return;
      if (findNode([moving], targetEl.id)) return; // cannot drop into own descendant
      const without = removeById(elements, payload.id);
      applyDoc(insertNode(without, targetEl.id, moving, targetIsContainer ? 'inside' : 'after'));
    }
  };

  const addFromPalette = (widgetType) => {
    const el = newElement(widgetType);
    if (selected && selected.elType === 'container') {
      applyDoc(insertNode(elements, selected.id, el, 'inside'));
    } else if (selected) {
      applyDoc(insertNode(elements, selected.id, el, 'after'));
    } else {
      applyDoc(insertNode(elements, null, el, 'root'));
    }
    setSelectedId(el.id);
  };

  const updateSelected = (key, value) => {
    if (!selected) return;
    const next = mapTree(elements, (el) => el.id === selected.id
      ? { ...el, settings: { ...(el.settings || {}), [key]: value } } : el);
    applyDoc(next);
  };

  const removeSelected = () => { if (selected) { applyDoc(removeById(elements, selected.id)); setSelectedId(null); } };
  const duplicateSelected = () => {
    if (!selected) return;
    const copy = cloneWithNewIds(selected);
    applyDoc(insertNode(elements, selected.id, copy, 'after'));
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

  const suffix = device === 'desktop' ? '' : '_' + device;

  return (
    <div className="pfe">
      <aside className="pfe-palette">
        <h3>Widgets</h3>
        {boot.widgets.map((w) => (
          <button
            key={w.type} type="button" className="pfe-palette__item"
            draggable
            onDragStart={(e) => e.dataTransfer.setData('text/pageforge', JSON.stringify({ kind: 'palette', widgetType: w.type }))}
            onClick={() => addFromPalette(w.type)}
          >{w.label}</button>
        ))}
      </aside>

      <main className="pfe-main">
        <div className="pfe-toolbar">
          <span className="pfe-toolbar__title">{boot.pageTitle}{dirty ? ' • unsaved changes' : ''}</span>
          <span className="pfe-toolbar__spacer" />
          <button type="button" onClick={() => submit('save')}>Save draft</button>
          <button type="button" className="pfe-publish" onClick={() => submit('publish')}>Save &amp; publish</button>
        </div>
        <div
          className="pfe-canvas"
          onClick={() => setSelectedId(null)}
          onDragOver={(e) => e.preventDefault()}
          onDrop={(e) => {
            e.preventDefault();
            try {
              const payload = JSON.parse(e.dataTransfer.getData('text/pageforge'));
              if (payload.kind === 'palette') addFromPalette(payload.widgetType);
            } catch { /* ignore */ }
          }}
        >
          {elements.map((el) => (
            <CanvasNode key={el.id} el={el} selectedId={selectedId} onSelect={setSelectedId} onDropPayload={handleDropPayload} depth={0} />
          ))}
          {elements.length === 0 && <div className="pfe-canvas__empty">Click or drag a widget from the left panel to start building.</div>}
        </div>
        <div className="pfe-preview">
          <h3>Preview — exactly what publish serves</h3>
          <style>{preview.css}</style>
          <div dangerouslySetInnerHTML={{ __html: preview.html }} />
        </div>
      </main>

      <aside className="pfe-inspector">
        <h3>Settings</h3>
        {!selected && <p className="pfe-inspector__hint">Select an element on the canvas.</p>}
        {selected && selectedDef && (
          <>
            <div className="pfe-inspector__actions">
              <button type="button" onClick={duplicateSelected}>Duplicate</button>
              <button type="button" onClick={removeSelected}>Delete</button>
            </div>
            {Object.entries(selectedDef.controls || {}).map(([key, c]) => (
              <Field key={key} label={key} control={c} value={(selected.settings || {})[key]} onChange={(v) => updateSelected(key, v)} />
            ))}
            <h4>Style</h4>
            <div className="pfe-device">
              {['desktop', 'tablet', 'mobile'].map((d) => (
                <button key={d} type="button" className={device === d ? 'is-active' : ''} onClick={() => setDevice(d)}>{d}</button>
              ))}
            </div>
            {(selectedDef.style_keys || []).map((key) => {
              const c = boot.styleControls[key];
              if (!c) return null;
              const effectiveKey = c.responsive ? key + suffix : key;
              return (
                <Field key={effectiveKey} label={effectiveKey} control={c}
                  value={(selected.settings || {})[effectiveKey]}
                  onChange={(v) => updateSelected(effectiveKey, v)} />
              );
            })}
          </>
        )}
      </aside>
    </div>
  );
}

createRoot(document.getElementById('pageforge-editor')).render(<App />);
