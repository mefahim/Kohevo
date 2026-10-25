/**
 * PageForge Phase 9 — Node tests for the editor history core (ui/src/history.js).
 * Pure logic, no DOM. Run: node tools/test-history.mjs
 */
import { createHistory } from '../ui/src/history.js';

let checks = 0;
const eq = (a, b, msg) => {
  checks += 1;
  if (JSON.stringify(a) !== JSON.stringify(b)) {
    throw new Error('FAIL ' + msg + ': got ' + JSON.stringify(a) + ' want ' + JSON.stringify(b));
  }
};
const ok = (cond, msg) => { checks += 1; if (!cond) throw new Error('FAIL ' + msg); };

const A = [{ id: 'a' }], B = [{ id: 'b' }], C = [{ id: 'c' }], D = [{ id: 'd' }];

// Init + push + undo/redo.
let h = createHistory(50, A, 'Open');
eq(h.current(), A, 'init current');
ok(!h.canUndo() && !h.canRedo(), 'init flags');
h.push('Add B', B);
h.push('Add C', C);
eq(h.current(), C, 'after pushes');
eq(h.undo(), B, 'undo to B');
eq(h.undo(), A, 'undo to A');
eq(h.undo(), null, 'undo at bottom is null');
eq(h.redo(), B, 'redo to B');

// Push after undo truncates the redo future.
h.push('Add D', D);
eq(h.current(), D, 'push after undo');
ok(!h.canRedo(), 'future truncated');
eq(h.entries().map((e) => e.label), ['Open', 'Add B', 'Add D'], 'entry labels');
eq(h.entries()[2].active, true, 'active flag on present');

// Coalescing replace: same label rewrites, different label does not.
ok(h.replace('Add D', C), 'replace same label coalesces');
eq(h.current(), C, 'replace rewrote present');
eq(h.entries().length, 3, 'replace did not grow the stack');
ok(!h.replace('Other', A), 'replace with new label refuses');
h.push('Other', A);
eq(h.entries().length, 4, 'push after refused replace grows');

// Jump: valid index moves; out-of-range is null and does not move.
eq(h.jump(0), A, 'jump to start returns initial snapshot');
eq(h.index(), 0, 'jump moved index');
eq(h.jump(99), null, 'jump out of range null');
eq(h.index(), 0, 'failed jump did not move');
eq(h.jump(-1), null, 'negative jump null');

// Cap: history is capped, oldest entries drop, present stays reachable.
h = createHistory(3, A, 'Open');
h.push('1', B); h.push('2', C); h.push('3', D); h.push('4', A);
eq(h.entries().length, 3, 'cap holds at limit');
eq(h.current(), A, 'cap keeps the newest present');
eq(h.undo(), D, 'cap undo one');
eq(h.undo(), C, 'cap undo two');
eq(h.undo(), null, 'cap undo exhausted');

console.log('HISTORY TESTS OK ' + checks + ' checks');
