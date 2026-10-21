import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isServerRenderedType, isStructuralChange, syncLiveDOM } from '../src/core/canvasLiveSync.mjs';

function fakeEl(tag = 'div', classes = [], text = '') {
  const classList = new Set(classes);
  const styleMap = {};
  const attrs = {};
  const kids = [];
  return {
    tagName: tag.toUpperCase(),
    textContent: text,
    innerHTML: text,
    children: kids,
    classList: {
      add: (...cls) => cls.forEach((c) => classList.add(c)),
      remove: (...cls) => cls.forEach((c) => classList.delete(c)),
      contains: (c) => classList.has(c),
    },
    style: styleMap,
    setAttribute: (k, v) => { attrs[k] = String(v); },
    getAttribute: (k) => attrs[k] || null,
    querySelector: (sel) => {
      if (sel.includes('heading') || sel.includes('h2') || sel.includes('h1')) {
        return kids.find((k) => k.tagName === 'H2' || k.tagName === 'H1') || null;
      }
      if (sel.includes('text') || sel.includes('p')) {
        return kids.find((k) => k.tagName === 'P') || null;
      }
      return null;
    },
    querySelectorAll: (sel) => kids,
    _classes: classList,
    _attrs: attrs,
  };
}

test('isStructuralChange detects section additions and deletions', () => {
  const doc1 = { sections: [{ id: 's1', blocks: [] }] };
  const doc2 = { sections: [{ id: 's1', blocks: [] }, { id: 's2', blocks: [] }] };
  assert.equal(isStructuralChange(doc1, doc2), true);
  assert.equal(isStructuralChange(doc1, doc1), false);
});

test('isStructuralChange returns false for prop and style edits', () => {
  const doc1 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'Old' }, style: {} }] }] };
  const doc2 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'New' }, style: { align: { base: 'center' } } }] }] };
  assert.equal(isStructuralChange(doc1, doc2), false);
});

test('syncLiveDOM updates block text and style immediately', () => {
  const headingEl = fakeEl('h2', ['sb-heading'], 'Old Title');
  const wrapperEl = fakeEl('div', ['sb-block']);
  wrapperEl.children.push(headingEl);

  const fakeDoc = {
    querySelector: (sel) => {
      if (sel.includes('b1')) return wrapperEl;
      return null;
    },
  };

  const doc1 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'Old Title' } }] }] };
  const doc2 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'New Title' }, style: { align: { base: 'center' } } }] }] };

  const count = syncLiveDOM(fakeDoc, doc1, doc2);
  assert.equal(count > 0, true);
  assert.equal(headingEl.textContent, 'New Title');
  assert.equal(wrapperEl.style.textAlign, 'center');
});

test('every other style edit repaints from the server, because its CSS is written there', () => {
  const doc = (block) => ({ sections: [sec([{ id: 'b', type: 'core.heading', props: { text: 'x' }, ...block }])] });
  const base = doc({ style: { typography: { font_size: '2rem' } } });
  for (const [what, next] of [
    ['typography', doc({ style: { typography: { font_size: '3rem' } } })],
    ['a new style group', doc({ style: { typography: { font_size: '2rem' }, effects: { opacity: 0.5 } } })],
    ['a style state', doc({ style: { typography: { font_size: '2rem' } }, style_states: { hover: { effects: { opacity: 0.5 } } } })],
    ['the wrapper tag', doc({ style: { typography: { font_size: '2rem' } }, tag: 'section' })],
    ['class names', doc({ style: { typography: { font_size: '2rem' } }, classNames: ['x'] })],
  ]) {
    assert.equal(isStructuralChange(base, next), true, what);
  }
  assert.equal(isStructuralChange(base, doc({ style: { typography: { font_size: '2rem' } } })), false, 'an equal copy is not a change');
  const withSecStyle = (style) => ({ sections: [{ id: 's1', style, blocks: [] }] });
  assert.equal(isStructuralChange(withSecStyle({ padding: '1rem' }), withSecStyle({ padding: '2rem' })), true, 'section style');
});

test('the live patch no longer writes inline styles the schema does not have', () => {
  const el = fakeEl('div');
  const canvasDoc = { querySelector: () => el };
  const a = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: {}, style: {} }] }] };
  const b = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: {}, style: { typography: { fontSize: '9px' }, textColor: 'red' } }] }] };
  syncLiveDOM(canvasDoc, a, b);
  assert.deepEqual(el.style, {});
});

const sec = (blocks) => ({ id: 's1', blocks });
const blk = (id, type, props = {}, children = []) => ({ id, type, props, children });

test('isStructuralChange sees a block added or removed at any depth (nesting goes to 6)', () => {
  const deep = (extra) => ({ sections: [sec([blk('a', 'core.container', {}, [blk('b', 'core.container', {}, [blk('c', 'core.container', {}, [blk('d', 'core.container', {}, extra)])])])])] });
  assert.equal(isStructuralChange(deep([]), deep([blk('e', 'core.heading')])), true, 'a child added four levels down');
  assert.equal(isStructuralChange(deep([blk('e', 'core.heading')]), deep([blk('e', 'core.text')])), true, 'a type changed four levels down');
  assert.equal(isStructuralChange(deep([blk('e', 'core.heading')]), deep([blk('e', 'core.heading', { text: 'x' })])), false, 'a prop edit on a patched type is still live');
});

test('server-rendered elements repaint from the server on a prop edit', () => {
  for (const type of ['core.icon', 'core.list', 'core.quote', 'core.link']) {
    const a = { sections: [sec([blk('x', type, { name: 'star', text: 'One' })])] };
    const b = { sections: [sec([blk('x', type, { name: 'heart', text: 'Two' })])] };
    assert.equal(isStructuralChange(a, b), true, `${type}: a prop edit repaints`);
    assert.equal(isStructuralChange(a, { sections: [sec([blk('x', type, { name: 'star', text: 'One' })])] }), false, `${type}: identical props do not`);
  }
  const el = fakeEl('figure', [], 'untouched');
  const canvasDoc = { querySelector: () => el };
  const a = { sections: [sec([blk('x', 'core.table', { text: 'One' })])] };
  const b = { sections: [sec([blk('x', 'core.table', { text: 'Two' })])] };
  assert.equal(syncLiveDOM(canvasDoc, a, b), 0, 'a type without a bespoke patch is not patched');
  assert.equal(el.textContent, 'untouched');
});

// A minimal rich fake DOM for the bespoke server-type patches: class/tag descendant lookup.
function richEl(tag, classes = [], text = '', kids = []) {
  const classSet = new Set(classes);
  const attrs = {};
  const node = {
    tagName: tag.toUpperCase(),
    children: kids,
    classList: {
      contains: (c) => classSet.has(c),
      add: (...cs) => cs.forEach((c) => classSet.add(c)),
      remove: (...cs) => cs.forEach((c) => classSet.delete(c)),
    },
    style: {},
    setAttribute: (k, v) => { attrs[k] = String(v); },
    getAttribute: (k) => (k in attrs ? attrs[k] : null),
    removeAttribute: (k) => { delete attrs[k]; },
    querySelector: (sel) => richQuery(node, sel),
    querySelectorAll: (sel) => {
      const cls = sel.startsWith('.') ? sel.slice(1) : null;
      const out = [];
      const walk = (n) => (n.children || []).forEach((c) => { if (cls && c.classList && c.classList.contains(cls)) out.push(c); walk(c); });
      walk(node);
      return out;
    },
    _attrs: attrs,
  };
  let own = text;
  Object.defineProperty(node, 'textContent', {
    get: () => own + node.children.map((k) => k.textContent).join(''),
    set: (v) => { own = String(v); node.children = []; },
  });
  return node;
}

function richQuery(root, sel) {
  const parts = sel.trim().split(/\s+/);
  const matches = (node, simple) => (simple.startsWith('.') ? node.classList.contains(simple.slice(1)) : node.tagName === simple.toUpperCase());
  const dfs = (node, part) => {
    for (const kid of node.children || []) {
      if (matches(kid, part)) return kid;
      const hit = dfs(kid, part);
      if (hit) return hit;
    }
    return null;
  };
  let cur = root;
  for (const part of parts) {
    cur = dfs(cur, part);
    if (!cur) return null;
  }
  return cur;
}

test('quote, link and list patch their text instantly, ahead of the server repaint', () => {
  // Quote: figure.sb-quote > blockquote.sb-quote__text > p, figcaption > author/role spans.
  const quoteP = richEl('p', [], 'Old quote');
  const author = richEl('span', ['sb-quote__author'], 'Ann');
  const role = richEl('span', ['sb-quote__role'], 'CEO');
  const quoteEl = richEl('div', ['sb-block'], '', [
    richEl('figure', ['sb-quote'], '', [
      richEl('blockquote', ['sb-quote__text'], '', [quoteP]),
      richEl('figcaption', ['sb-quote__caption'], '', [author, role]),
    ]),
  ]);
  const qa = { sections: [sec([blk('x', 'core.quote', { text: 'Old quote', author: 'Ann', role: 'CEO' })])] };
  const qb = { sections: [sec([blk('x', 'core.quote', { text: 'New quote', author: 'Bob', role: 'CTO' })])] };
  assert.equal(isStructuralChange(qa, qb), true, 'the server repaint still confirms a quote edit');
  assert.ok(syncLiveDOM({ querySelector: () => quoteEl }, qa, qb) >= 3, 'quote text, author and role patched');
  assert.equal(quoteP.textContent, 'New quote');
  assert.equal(author.textContent, 'Bob');
  assert.equal(role.textContent, 'CTO');

  // Link: p.sb-link-row > a.sb-link.
  const anchor = richEl('a', ['sb-link'], 'Old label');
  anchor.setAttribute('href', '/old');
  const linkEl = richEl('div', ['sb-block'], '', [richEl('p', ['sb-link-row'], '', [anchor])]);
  const la = { sections: [sec([blk('x', 'core.link', { link: { label: 'Old label', href: '/old' } })])] };
  const lb = { sections: [sec([blk('x', 'core.link', { link: { label: 'New label', href: '/new' } })])] };
  assert.ok(syncLiveDOM({ querySelector: () => linkEl }, la, lb) >= 2, 'link label and href patched');
  assert.equal(anchor.textContent, 'New label');
  assert.equal(anchor.getAttribute('href'), '/new');

  // List: ul.sb-list > li.sb-list__item rows; same row count patches, a changed count does not.
  const rows = [richEl('li', ['sb-list__item'], 'One'), richEl('li', ['sb-list__item'], 'Two')];
  const listEl = richEl('div', ['sb-block'], '', [richEl('ul', ['sb-list'], '', rows)]);
  const ia = { sections: [sec([blk('x', 'core.list', { items: [{ text: 'One' }, { text: 'Two' }] })])] };
  const ib = { sections: [sec([blk('x', 'core.list', { items: [{ text: 'First' }, { text: 'Second' }] })])] };
  assert.ok(syncLiveDOM({ querySelector: () => listEl }, ia, ib) >= 2, 'list rows patched');
  assert.equal(rows[0].textContent, 'First');
  assert.equal(rows[1].textContent, 'Second');
  const ic = { sections: [sec([blk('x', 'core.list', { items: [{ text: 'First' }, { text: 'Second' }, { text: 'Third' }] })])] };
  assert.equal(syncLiveDOM({ querySelector: () => listEl }, ib, ic), 0, 'a row added: left for the repaint');
});

test('card, table, countdown, divider, spacer and icon patch their class and text props instantly', () => {
  const doc = (type, props) => ({ sections: [sec([blk('x', type, props)])] });
  const run = (el, a, b) => syncLiveDOM({ querySelector: () => el }, a, b);

  // Card: variant and padding are classes on the inner .sb-card.
  const card = richEl('div', ['sb-card', 'sb-card--outlined', 'sb-card--pad-md']);
  const cardEl = richEl('div', ['sb-block'], '', [card]);
  run(cardEl, doc('core.card', { variant: 'outlined', padding: 'md' }), doc('core.card', { variant: 'filled', padding: 'lg' }));
  assert.ok(card.classList.contains('sb-card--filled') && !card.classList.contains('sb-card--outlined'), 'card variant swapped');
  assert.ok(card.classList.contains('sb-card--pad-lg') && !card.classList.contains('sb-card--pad-md'), 'card padding swapped');

  // Table: caption, header and body cells patch when the shape is unchanged.
  const ths = [richEl('th', [], 'Name'), richEl('th', [], 'Age')];
  const trs = [richEl('tr', [], '', [richEl('td', [], 'Ann'), richEl('td', [], '30')]), richEl('tr', [], '', [richEl('td', [], 'Bob'), richEl('td', [], '40')])];
  const caption = richEl('caption', ['sb-table__caption'], 'People');
  const tableEl = richEl('div', ['sb-block'], '', [
    richEl('div', ['sb-table-wrap'], '', [
      richEl('table', ['sb-table'], '', [caption, richEl('thead', [], '', [richEl('tr', [], '', ths)]), richEl('tbody', [], '', trs)]),
    ]),
  ]);
  const ta = doc('core.table', { caption: 'People', header: 'Name | Age', rows: [{ cells: 'Ann | 30' }, { cells: 'Bob | 40' }] });
  const tb = doc('core.table', { caption: 'Staff', header: 'Person | Years', rows: [{ cells: 'Ann | 31' }, { cells: 'Bob | 41' }] });
  run(tableEl, ta, tb);
  assert.equal(caption.textContent, 'Staff');
  assert.equal(ths[0].textContent, 'Person');
  assert.equal(trs[1].children[1].textContent, '41');
  const tc = doc('core.table', { caption: 'Staff', header: 'Person | Years', rows: [{ cells: 'Ann | 31' }] });
  const before = trs[0].children[0].textContent;
  assert.equal(run(tableEl, tb, tc), 0, 'a row removed: left for the repaint');
  assert.equal(trs[0].children[0].textContent, before);

  // Countdown: the label and the target (converted to UTC exactly as the server does).
  const time = richEl('time', [], '2030-01-01 00:00 UTC');
  time.setAttribute('datetime', '2030-01-01T00:00:00Z');
  const cdWrap = richEl('div', ['sb-countdown'], '', [
    richEl('p', ['sb-countdown__label'], 'Launch'),
    richEl('p', ['sb-countdown__date'], '', [time]),
  ]);
  cdWrap.setAttribute('data-sb-countdown', '2030-01-01T00:00:00Z');
  const cdEl = richEl('div', ['sb-block'], '', [cdWrap]);
  run(cdEl, doc('core.countdown', { label: 'Launch', target: '2030-01-01T00:00:00Z' }), doc('core.countdown', { label: 'Go live', target: '2030-06-01T05:30:00+05:30' }));
  assert.equal(cdWrap.querySelector('.sb-countdown__label').textContent, 'Go live');
  assert.equal(cdWrap.getAttribute('data-sb-countdown'), '2030-06-01T00:00:00Z', 'target converted to UTC');
  assert.equal(time.textContent, '2030-06-01 00:00 UTC');
  run(cdEl, doc('core.countdown', { label: 'Go live', target: '2030-06-01T05:30:00+05:30' }), doc('core.countdown', { label: 'Go live', target: '2030-02-31T00:00:00Z' }));
  assert.equal(cdWrap.getAttribute('data-sb-countdown'), '2030-06-01T00:00:00Z', 'a roll-over date is refused, like the server');

  // Divider: each dimension swaps only its own class set.
  const hr = richEl('hr', ['sb-divider', 'sb-divider--solid', 'sb-divider--thin', 'sb-divider--w-full', 'sb-divider--center']);
  const divEl = richEl('div', ['sb-block'], '', [hr]);
  run(divEl, doc('core.divider', { style: 'solid', weight: 'thin', width: 'full', align: 'center' }), doc('core.divider', { style: 'dashed', weight: 'thin', width: 'short', align: 'center' }));
  assert.ok(hr.classList.contains('sb-divider--dashed') && hr.classList.contains('sb-divider--w-short'), 'divider style and width swapped');
  assert.ok(hr.classList.contains('sb-divider--thin') && hr.classList.contains('sb-divider--center'), 'other dimensions untouched');

  // Spacer and icon sizes.
  const spacer = richEl('div', ['sb-spacer', 'sb-spacer--md']);
  run(richEl('div', ['sb-block'], '', [spacer]), doc('core.spacer', { size: 'md' }), doc('core.spacer', { size: 'xl' }));
  assert.ok(spacer.classList.contains('sb-spacer--xl') && !spacer.classList.contains('sb-spacer--md'), 'spacer size swapped');
  const icon = richEl('div', ['sb-icon', 'sb-icon--md', 'sb-icon--left']);
  run(richEl('div', ['sb-block'], '', [icon]), doc('core.icon', { size: 'md', align: 'left' }), doc('core.icon', { size: 'lg', align: 'center' }));
  assert.ok(icon.classList.contains('sb-icon--lg') && icon.classList.contains('sb-icon--center'), 'icon size and align swapped');
});

test('nav menu and map patch instantly; a bound menu keeps its items from the menu store', () => {
  const doc = (type, props) => ({ sections: [sec([blk('x', type, props)])] });
  const run = (el, a, b) => syncLiveDOM({ querySelector: () => el }, a, b);

  // Nav: layout class, aria label, and the block's own links while no menu is bound.
  const a1 = richEl('a', ['sb-nav__link'], 'Home');
  a1.setAttribute('href', '/');
  const a2 = richEl('a', ['sb-nav__link'], 'About');
  a2.setAttribute('href', '/about');
  const nav = richEl('nav', ['sb-nav', 'sb-nav--horizontal', 'sb-nav--left', 'sb-nav--plain'], '', [
    richEl('ul', ['sb-nav__list'], '', [richEl('li', ['sb-nav__item'], '', [a1]), richEl('li', ['sb-nav__item'], '', [a2])]),
  ]);
  nav.setAttribute('aria-label', 'Menu');
  const navEl = richEl('div', ['sb-block'], '', [nav]);
  const na = doc('core.nav_menu', { layout: 'horizontal', align: 'left', style: 'plain', aria_label: 'Menu', items: [{ label: 'Home', url: '/' }, { label: 'About', url: '/about' }] });
  const nb = doc('core.nav_menu', { layout: 'vertical', align: 'left', style: 'plain', aria_label: 'Main', items: [{ label: 'Start', url: '/start' }, { label: 'About', url: '/about' }] });
  run(navEl, na, nb);
  assert.ok(nav.classList.contains('sb-nav--vertical'), 'nav layout swapped');
  assert.equal(nav.getAttribute('aria-label'), 'Main');
  assert.equal(a1.textContent, 'Start');
  assert.equal(a1.getAttribute('href'), '/start');

  // With a site menu bound, props.items edits must NOT touch the rendered links.
  const nc = doc('core.nav_menu', { layout: 'vertical', align: 'left', style: 'plain', aria_label: 'Main', menu: 'main', items: [{ label: 'Hacked', url: '/x' }, { label: 'About', url: '/about' }] });
  run(navEl, nb, nc);
  assert.equal(a1.textContent, 'Start', 'bound menu items untouched');
  const nd = doc('core.nav_menu', { layout: 'vertical', align: 'left', style: 'plain', aria_label: ' ', menu: 'main', items: nb.sections[0].blocks[0].props.items });
  run(navEl, nc, nd);
  assert.equal(nav.getAttribute('aria-label'), null, 'a blank label drops the attribute, like the server');

  // Map: the iframe src is rebuilt with the renderer's own embed math.
  const frame = richEl('iframe', [], '');
  frame.setAttribute('src', 'https://www.openstreetmap.org/export/embed.html?bbox=old');
  frame.setAttribute('title', 'Map');
  const mapDiv = richEl('div', ['sb-map', 'sb-map--md'], '', [frame]);
  const mapEl = richEl('div', ['sb-block'], '', [mapDiv]);
  const ma = doc('core.map', { latitude: 23.8103, longitude: 90.4125, zoom: 12, height: 'md', title: 'Map' });
  const mb = doc('core.map', { latitude: 23.8103, longitude: 90.4125, zoom: 13, height: 'lg', title: 'Office' });
  run(mapEl, ma, mb);
  assert.equal(frame.getAttribute('src'), 'https://www.openstreetmap.org/export/embed.html?bbox=90.36855,23.78613,90.45645,23.83447&layer=mapnik&marker=23.81030,90.41250');
  assert.equal(frame.getAttribute('title'), 'Office');
  assert.ok(mapDiv.classList.contains('sb-map--lg'), 'map height swapped');
  const mc = doc('core.map', { latitude: 999, longitude: 90.4125, zoom: 13, height: 'lg', title: 'Office' });
  run(mapEl, mb, mc);
  assert.ok(frame.getAttribute('src').includes('bbox=90.36855'), 'an out-of-range point is left for the repaint');
});

test('module blocks patch the author-text props the generic patchers cannot know', () => {
  const doc = (type, props) => ({ sections: [sec([blk('x', type, props)])] });
  const run = (el, a, b) => syncLiveDOM({ querySelector: () => el }, a, b);

  // Catalog CTA labels (booking + membership share the markup).
  const cta = richEl('a', ['sb-button', 'sb-button--primary'], 'Book now');
  const catEl = richEl('div', ['sb-block'], '', [richEl('div', ['sb-catalog'], '', [richEl('p', ['sb-button-row'], '', [cta])])]);
  run(catEl, doc('booking.services', { cta_label: '' }), doc('booking.services', { cta_label: 'Reserve' }));
  assert.equal(cta.textContent, 'Reserve');
  run(catEl, doc('booking.services', { cta_label: 'Reserve' }), doc('booking.services', { cta_label: '' }));
  assert.equal(cta.textContent, 'Book now', 'an empty label falls back to the renderer default');

  // Form card button label.
  const open = richEl('a', ['sb-button', 'sb-button--primary'], 'Open form');
  const formEl = richEl('div', ['sb-block'], '', [richEl('div', ['sb-form-card'], '', [richEl('p', ['sb-button-row'], '', [open])])]);
  run(formEl, doc('forms.form_card', { button_label: '' }), doc('forms.form_card', { button_label: 'Apply now' }));
  assert.equal(open.textContent, 'Apply now');

  // Payment link: the editor note follows the URL's validity.
  const note = richEl('p', ['sb-embed-card__note'], 'Paste a Stripe Payment Link (https://buy.stripe.com/...) in the Inspector.');
  const payEl = richEl('div', ['sb-block'], '', [richEl('div', ['sb-pay-card'], '', [note])]);
  run(payEl, doc('payment.link', { label: 'Pay', url: '' }), doc('payment.link', { label: 'Pay', url: 'https://buy.stripe.com/abc123' }));
  assert.equal(note.textContent, 'Visitors who click this go to your Stripe page.');
  run(payEl, doc('payment.link', { label: 'Pay', url: 'https://buy.stripe.com/abc123' }), doc('payment.link', { label: 'Pay', url: 'https://evil.example.com/x' }));
  assert.ok(note.textContent.startsWith('Paste a Stripe'), 'a non-Stripe URL flips the note back');

  // Search box: placeholder attribute and button text.
  const input = richEl('input', ['sb-search-input'], '');
  input.setAttribute('placeholder', 'Search articles and resources...');
  const btn = richEl('button', ['sb-search-button'], 'Search');
  const searchEl = richEl('div', ['sb-block'], '', [richEl('form', ['sb-search-form'], '', [input, btn])]);
  run(searchEl, doc('theme.search_box', {}), doc('theme.search_box', { placeholder: 'Find a course', button_text: 'Go' }));
  assert.equal(input.getAttribute('placeholder'), 'Find a course');
  assert.equal(btn.textContent, 'Go');
});

test('a highlighted heading patches around its span instead of erasing it', () => {
  const doc = (props) => ({ sections: [sec([blk('x', 'core.heading', props)])] });
  const textNode = (t) => ({ nodeType: 3, _t: t, get textContent() { return this._t; }, set textContent(v) { this._t = String(v); } });
  const build = (beforeText, word, afterText) => {
    const span = richEl('span', ['sb-hl'], word);
    const before = textNode(beforeText);
    const after = textNode(afterText);
    span.previousSibling = before;
    span.nextSibling = after;
    const host = richEl('h2', ['sb-heading'], '', [span]);
    const el = richEl('div', ['sb-block'], '', [host]);
    el.querySelector = (sel) => (sel.includes('.sb-heading') ? host : richQuery(el, sel));
    return { span, before, after, host, el };
  };
  const base = { text: 'Our Section Heading', highlight: 'Section', highlight_color: '' };

  // Typing around the word rewrites the flanking text nodes; the span survives.
  const a = build('Our ', 'Section', ' Heading');
  syncLiveDOM({ querySelector: () => a.el }, doc(base), doc({ ...base, text: 'Your Section Heading' }));
  assert.equal(a.before.textContent, 'Your ');
  assert.equal(a.span.textContent, 'Section', 'the highlight span was not flattened');
  assert.ok(a.host.children.includes(a.span), 'the span is still in the heading');

  // Moving the word redistributes the text; changing the word rewrites the span.
  const b = build('Our ', 'Section', ' Heading');
  syncLiveDOM({ querySelector: () => b.el }, doc(base), doc({ ...base, text: 'My Heading Section' }));
  assert.equal(b.before.textContent, 'My Heading ');
  assert.equal(b.after.textContent, '');
  const c = build('Our ', 'Section', ' Heading');
  syncLiveDOM({ querySelector: () => c.el }, doc(base), doc({ ...base, highlight: 'Heading' }));
  assert.equal(c.span.textContent, 'Heading');
  assert.equal(c.before.textContent, 'Our Section ');

  // The word gone from the text: the heading goes plain, exactly as the server renders.
  const d = build('Our ', 'Section', ' Heading');
  syncLiveDOM({ querySelector: () => d.el }, doc(base), doc({ ...base, text: 'Plain words only' }));
  assert.equal(d.host.textContent, 'Plain words only');

  // The look follows: italic class and a custom colour as the span's style.
  const e = build('Our ', 'Section', ' Heading');
  syncLiveDOM({ querySelector: () => e.el }, doc(base), doc({ ...base, highlight_italic: true, highlight_color: '#00ff00' }));
  assert.ok(e.span.classList.contains('sb-hl--italic'), 'italic class applied');
  assert.equal(e.span.getAttribute('style'), 'color:#00ff00', 'custom colour applied');
});

test('a heading with a highlighted word is never flattened by a text write (the repaint still confirms it)', () => {
  const doc = (props) => ({ sections: [sec([blk('h', 'core.heading', props)])] });
  assert.equal(isStructuralChange(doc({ text: 'Get started' }), doc({ text: 'Get started now' })), false, 'plain heading: live patch');
  assert.equal(isStructuralChange(doc({ text: 'Get started', highlight: 'started' }), doc({ text: 'Get started now', highlight: 'started' })), true, 'text edit under a highlight');
  assert.equal(isStructuralChange(doc({ text: 'Get started' }), doc({ text: 'Get started', highlight: 'started' })), true, 'highlight set');
  const el = fakeEl('h2', ['sb-heading'], 'Get <span>started</span>');
  const canvasDoc = { querySelector: () => el };
  syncLiveDOM(canvasDoc, doc({ text: 'Get started', highlight: 'started' }), doc({ text: 'Get going', highlight: 'started' }));
  assert.equal(el.textContent, 'Get <span>started</span>', 'no plain text write touched it: span surgery or the repaint owns it');
});

test('a section animation or interaction change repaints from the server (the classes are written there)', () => {
  const secDoc = (extra) => ({ sections: [{ id: 's1', blocks: [], ...extra }] });
  assert.equal(isStructuralChange(secDoc({}), secDoc({ animation: { type: 'reveal_up' } })), true);
  assert.equal(isStructuralChange(secDoc({ interactions: { trigger: 'hover' } }), secDoc({ interactions: { trigger: 'viewport-enter' } })), true);
  assert.equal(isStructuralChange(secDoc({ animation: { type: 'fade_in' } }), secDoc({ animation: { type: 'fade_in' } })), false, 'an equal copy is not a change');
});

test('ghostNodeIds: nodes the canvas shows that the document does not have', async () => {
  const { ghostNodeIds } = await import('../src/core/canvasLiveSync.mjs');
  const doc = { sections: [{ id: 'sec_1', blocks: [{ id: 'blk_1', children: [{ id: 'blk_2' }] }] }] };
  assert.deepEqual(ghostNodeIds(['sec_1', 'blk_1', 'blk_2'], doc), []);
  assert.deepEqual(ghostNodeIds(['sec_1', 'sec_gone', 'blk_2', 'blk_gone'], doc), ['sec_gone', 'blk_gone']);
  assert.deepEqual(ghostNodeIds([null, '', 'sec_1'], doc), []);
  assert.deepEqual(ghostNodeIds(['sec_1'], { sections: [] }), ['sec_1']);
});

test('a per-device style override repaints from the server, while hide and align stay live', () => {
  const doc = (responsive) => ({ sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: {}, style: {}, responsive }] }] });
  assert.equal(isStructuralChange(doc({}), doc({ tablet: { style: { padding: { top: '2rem' } } } })), true);
  assert.equal(isStructuralChange(doc({ mobile: { style: { padding: { top: '1rem' } } } }), doc({})), true);
  assert.equal(isStructuralChange(doc({}), doc({ tablet: { hide: true } })), false);
});

test('a property edit on a module or custom block repaints from the server; core text blocks keep their live patch', () => {
  const doc = (type, props) => ({ sections: [{ id: 'sec_1', style: {}, blocks: [{ id: 'blk_1', type, props, style: {}, children: [] }] }] });
  for (const type of ['payment.link', 'payment.button', 'membership.plans', 'booking.embed', 'acme.widget']) {
    assert.equal(isStructuralChange(doc(type, { label: 'a' }), doc(type, { label: 'b' })), true, `${type} is server-rendered`);
  }
  assert.equal(isStructuralChange(doc('core.heading', { text: 'a' }), doc('core.heading', { text: 'b' })), false, 'a heading is patched in place');
  assert.equal(isStructuralChange(doc('layout.container', { padding: 'sm' }), doc('layout.container', { padding: 'md' })), false, 'a layout container is patched in place');
  assert.equal(isServerRenderedType('core.quote'), true);
  assert.equal(isServerRenderedType('core.text'), false);
});
