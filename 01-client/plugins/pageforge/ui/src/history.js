/**
 * PageForge editor history core (Phase 9).
 *
 * The command bus's memory: every editor mutation is dispatched with a
 * human label and routed through here, which keeps a capped stack of
 * document snapshots. Undo/redo/jump are pure index moves over that stack;
 * the editor re-renders from whichever snapshot is current. Framework-free
 * and DOM-free so it can be unit-tested in Node (tools/test-history.mjs)
 * and reused verbatim by any future editor shell.
 */

export function createHistory(limit = 50, initialElements = [], initialLabel = 'Open editor') {
  const cap = Math.max(2, limit);
  let stack = [{ label: initialLabel, elements: initialElements }];
  let idx = 0;

  const api = {
    /** Append a new present, discarding any redo future. */
    push(label, elements) {
      stack = stack.slice(0, idx + 1);
      stack.push({ label: String(label || 'Edit'), elements });
      while (stack.length > cap) stack.shift();
      idx = stack.length - 1;
      return stack[idx].elements;
    },
    /**
     * Coalesce rapid-fire edits (typing in one field): rewrite the current
     * entry in place when the label matches the present entry's label.
     * Returns true when it coalesced.
     */
    replace(label, elements) {
      if (stack[idx] && stack[idx].label === label) {
        stack[idx] = { label, elements };
        return true;
      }
      return false;
    },
    undo() {
      if (idx <= 0) return null;
      idx -= 1;
      return stack[idx].elements;
    },
    redo() {
      if (idx >= stack.length - 1) return null;
      idx += 1;
      return stack[idx].elements;
    },
    jump(i) {
      if (!Number.isInteger(i) || i < 0 || i >= stack.length) return null;
      idx = i;
      return stack[idx].elements;
    },
    current() { return stack[idx] ? stack[idx].elements : null; },
    canUndo() { return idx > 0; },
    canRedo() { return idx < stack.length - 1; },
    index() { return idx; },
    entries() { return stack.map((e, i) => ({ label: e.label, active: i === idx })); },
  };
  return api;
}
