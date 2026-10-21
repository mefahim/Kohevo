import test from 'node:test';
import assert from 'node:assert/strict';
import { toInlineHtml } from '../src/core/richtext.mjs';
import { docHasMarkupTextChange } from '../src/core/doc.mjs';

test('toInlineHtml keeps the inline subset and normalizes editor markup', () => {
  assert.equal(toInlineHtml('<strong>Bold</strong> and <em>italic</em>'), '<strong>Bold</strong> and <em>italic</em>');
  assert.equal(toInlineHtml('<b><strong>x</strong></b>'), '<strong>x</strong>', 'double-wrapped bold collapses');
  assert.equal(toInlineHtml('<span style="font-weight:bold">x</span>'), 'x', 'attribute-less spans unwrap');
  assert.equal(toInlineHtml('<p>First</p><p>Second</p>'), 'First Second', 'block boundaries become a space in single-line fields');
  assert.equal(toInlineHtml('<p>First</p><p>Second</p>', { multiline: true }), 'First\n\nSecond', 'paragraph boundaries become a blank line in multiline fields (matching innerText)');
  assert.equal(toInlineHtml('one<br>two', { multiline: true }), 'one\ntwo');
  assert.equal(toInlineHtml('one<br>two'), 'one<br>two');
});

test('toInlineHtml drops dangerous markup and unsafe links', () => {
  assert.equal(toInlineHtml('<script>alert(1)</script>Text'), 'Text');
  assert.equal(toInlineHtml('<strong onclick="x()">b</strong>'), '<strong>b</strong>');
  assert.equal(toInlineHtml('<a href="javascript:alert(1)">x</a>'), '<a>x</a>');
  assert.equal(toInlineHtml('<a href="https://example.com" target="_blank">x</a>'), '<a href="https://example.com" target="_blank" rel="noopener noreferrer">x</a>');
  assert.equal(toInlineHtml('<img src=x onerror=alert(1)>after'), 'after');
  assert.equal(toInlineHtml(''), '');
});

test('docHasMarkupTextChange gates the plain-text canvas patchers', () => {
  const doc = (text) => ({ sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text } }] }] });
  assert.equal(docHasMarkupTextChange(doc('Plain'), doc('Plain')), false, 'unchanged');
  assert.equal(docHasMarkupTextChange(doc('Plain'), doc('Other')), false, 'plain to plain patches locally');
  assert.equal(docHasMarkupTextChange(doc('Plain'), doc('A <strong>b</strong>')), true, 'gaining markup repaints');
  assert.equal(docHasMarkupTextChange(doc('A <strong>b</strong>'), doc('A b')), true, 'losing markup repaints');
  assert.equal(docHasMarkupTextChange(doc('x'), doc('x')), false);
  assert.equal(docHasMarkupTextChange(null, doc('x')), false);
});
