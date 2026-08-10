/**
 * Keeps the hover bubbles quiet while the page scrolls.
 *
 * Scrolling slides rows UNDER a stationary pointer, and the browser fires
 * mouseenter on whatever lands there — so bubbles popped up that nobody hovered,
 * pinned (position:fixed) to spots their rows had already scrolled away from.
 * The guard tells a deliberate hover from a scroll-induced one by the signal
 * that separates them: a real hover moves the mouse, a scroll doesn't.
 *
 *  - Any scroll (the window or an inner feed — hence capture) hides every
 *    registered bubble and opens a "scrolling" state.
 *  - While it holds, mouseenter-driven shows are swallowed; the LAST one is
 *    remembered, and if the pointer is still inside that element when the mouse
 *    truly moves again, its bubble shows then — so a row the scroll happened to
 *    park the cursor on still explains itself once the reader settles.
 *  - Non-mouseenter shows (keyboard focus, a retry) always pass.
 */

const hiders = new Set();
let scrolling = false;
let pending = null; // The one swallowed { el, show } worth revisiting.

// NOTE: scroll deliberately leaves `pending` alone. A wheel gesture is MANY scroll
// events, and the swallowed mouseenter lands between them — clearing here would wipe
// the retry before the trailing events finish, so no bubble could ever reappear
// without leaving the row. Staleness is already handled where it matters: the retry
// only fires if the pointer is still inside the remembered element.
window.addEventListener(
  'scroll',
  () => {
    scrolling = true;
    hiders.forEach((hide) => hide());
  },
  { capture: true, passive: true }
);

window.addEventListener(
  'mousemove',
  (ev) => {
    if (!scrolling) return;
    scrolling = false;
    const p = pending;
    pending = null;
    if (p && p.el && p.el.contains(ev.target)) p.show(ev);
  },
  { capture: true, passive: true }
);

export const tipGuard = {
  /** Register a "hide your bubble" callback; returns its deregistration. */
  register(hide) {
    hiders.add(hide);
    return () => hiders.delete(hide);
  },
  /**
   * True when this mouseenter arrived mid-scroll and must not show a bubble.
   * Pass `el` and `show` to have the show retried (with the mousemove event)
   * if the pointer is still on `el` at the first real mouse move; omit them
   * for triggers that should simply stay closed, like a hover-opened menu.
   */
  suppress(ev, el, show) {
    if (!scrolling || !ev || 'mouseenter' !== ev.type) return false;
    pending = el && show ? { el, show } : null;
    return true;
  },
};
