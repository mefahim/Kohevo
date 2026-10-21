import test from 'node:test';
import assert from 'node:assert/strict';
import { applyLocal } from '../src/core/operations.mjs';
import * as ops from '../src/core/operations.mjs';

const doc = () => ({
  sections: [{ id: 'sec_1', blocks: [{ id: 'blk_1', type: 'core.heading', props: {}, children: [] }] }],
});

test('updateBlockCustomCss sets, replaces and clears the block key', () => {
  let d = applyLocal(doc(), ops.updateBlockCustomCss('blk_1', '.a{color:red}'));
  assert.equal(d.sections[0].blocks[0].custom_css, '.a{color:red}');
  d = applyLocal(d, ops.updateBlockCustomCss('blk_1', '&{border:0}'));
  assert.equal(d.sections[0].blocks[0].custom_css, '&{border:0}');
  d = applyLocal(d, ops.updateBlockCustomCss('blk_1', null));
  assert.equal('custom_css' in d.sections[0].blocks[0], false);
  d = applyLocal(doc(), ops.updateBlockCustomCss('blk_1', '   '));
  assert.equal('custom_css' in d.sections[0].blocks[0], false, 'blank clears');
});

test('updateBlockCustomCss refuses oversize and non-string values', () => {
  assert.throws(() => applyLocal(doc(), ops.updateBlockCustomCss('blk_1', 'a'.repeat(8193))));
  assert.throws(() => applyLocal(doc(), ops.updateBlockCustomCss('blk_1', 42)));
});
