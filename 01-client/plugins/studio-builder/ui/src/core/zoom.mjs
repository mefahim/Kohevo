// Canvas zoom — framework-free.
//
// The canvas scales the whole device frame with a CSS transform. Zoom used
// to cap at 100% ("larger zoom needs scrolling frames"); since Batch E3 the
// stage scrolls in both axes and keeps the frame's full height at scale > 1,
// so steps above 100% work. `fit` follows the stage width.

export const ZOOM_STEPS = Object.freeze([25, 50, 75, 100, 125, 150, 200]);

/** The percent a zoom mode renders at: `fit` uses the computed fit scale. */
export function zoomPercent(mode, fitScale) {
  if (mode === 'fit') return Math.round(fitScale * 100);
  return ZOOM_STEPS.includes(mode) ? mode : 100;
}

/**
 * The step after zooming in (+1) or out (-1) from the CURRENT percent — even when
 * that percent came from `fit` and sits between two steps. Stays at the ends.
 */
export function stepZoom(percent, direction) {
  if (direction > 0) return ZOOM_STEPS.find((s) => s > percent) ?? ZOOM_STEPS[ZOOM_STEPS.length - 1];
  const below = ZOOM_STEPS.filter((s) => s < percent);
  return below.length ? below[below.length - 1] : ZOOM_STEPS[0];
}

export const canZoomIn = (percent) => percent < ZOOM_STEPS[ZOOM_STEPS.length - 1];
export const canZoomOut = (percent) => percent > ZOOM_STEPS[0];
