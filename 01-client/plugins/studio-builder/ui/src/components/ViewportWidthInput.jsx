// Custom canvas width (Batch E3) — a small px input shared by the TopBar
// (desktop) and the ResponsiveViewSheet (mobile). Typing edits a local
// draft; Enter or blur commits the clamped width and switches the canvas
// to the custom viewport, Escape abandons the draft.

import React, { memo, useEffect, useState } from 'react';
import { t } from '../core/messages.mjs';
import { CUSTOM_VIEWPORT_MAX, CUSTOM_VIEWPORT_MIN, clampViewportWidth } from '../core/viewport.mjs';

export const ViewportWidthInput = memo(function ViewportWidthInput({ width, active = false, onCommit, testId = 'viewport-width' }) {
  const [draft, setDraft] = useState(String(width));
  useEffect(() => { setDraft(String(width)); }, [width]);

  const commit = () => {
    const next = clampViewportWidth(draft);
    setDraft(String(next));
    if (next !== width || !active) onCommit(next);
  };

  return (
    <span className={`sbx-viewport-width${active ? ' is-active' : ''}`}>
      <input
        data-testid={testId}
        type="number"
        inputMode="numeric"
        min={CUSTOM_VIEWPORT_MIN}
        max={CUSTOM_VIEWPORT_MAX}
        step={1}
        value={draft}
        aria-label={t('custom_width')}
        title={t('custom_width')}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === 'Enter') { e.preventDefault(); commit(); e.currentTarget.blur(); }
          if (e.key === 'Escape') { setDraft(String(width)); e.currentTarget.blur(); }
        }}
      />
      <span aria-hidden="true">px</span>
    </span>
  );
});
