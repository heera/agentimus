/**
 * `v-tip="text"` — the app-wide hover/focus tooltip, replacing native `title="…"`
 * (which can't be themed, is slow to appear, and reads inconsistently across
 * browsers). One shared bubble, appended to <body>, reusing the same
 * `.ar-act-uatip` look the activity tables already use — the `--info` variant,
 * because these are plain prose, not the mono code values the UA bubbles show.
 *
 * The rich, copyable, carefully-measured User-Agent bubbles keep the uaTip mixin;
 * this is the plain-text replacement for every other `title="…"`. An empty or
 * null value shows nothing (same as an empty native title).
 */

let bubble = null;
let uaSpan = null;
let caret = null;
let activeEl = null; // the element the shown bubble currently belongs to

function ensureBubble() {
  if (bubble) return;
  bubble = document.createElement('div');
  bubble.className = 'ar-act-uatip ar-act-uatip--info';
  bubble.style.display = 'none';
  uaSpan = document.createElement('span');
  uaSpan.className = 'ar-act-uatip__ua';
  caret = document.createElement('span');
  caret.className = 'ar-act-uatip__caret';
  bubble.appendChild(uaSpan);
  bubble.appendChild(caret);
  document.body.appendChild(bubble);
}

function hide() {
  activeEl = null;
  if (bubble) bubble.style.display = 'none';
}

// Position off the element's rect in the fixed viewport, the same geometry the
// uaTip mixin uses: anchored ABOVE by default (caret points down), dropped BELOW
// when there isn't room, and clamped so a right-edge bubble never leaves the page.
function show(el, text) {
  if (!text) return;
  ensureBubble();
  activeEl = el;
  uaSpan.textContent = text;
  bubble.style.display = '';
  const rect = el.getBoundingClientRect();
  const below = rect.top < 96;
  bubble.classList.toggle('is-below', below);
  bubble.style.left = '0px';
  bubble.style.top = (below ? rect.bottom + 8 : rect.top - 8) + 'px';
  const w = bubble.offsetWidth;
  const anchor = rect.left + 16; // viewport x the caret points at
  const max = window.innerWidth - w - 12;
  const x = Math.max(12, Math.min(rect.left, max));
  bubble.style.left = x + 'px';
  caret.style.left = Math.max(12, Math.min(anchor - x, w - 16)) + 'px';
}

// Any scroll hides it — a position:fixed bubble would otherwise hang where its
// element used to be.
window.addEventListener('scroll', hide, { capture: true, passive: true });

const handlers = new WeakMap();

export const tip = {
  mounted(el, binding) {
    el.__tipVal = binding.value;
    const enter = () => show(el, el.__tipVal == null ? '' : String(el.__tipVal));
    const leave = () => { if (activeEl === el) hide(); };
    el.addEventListener('mouseenter', enter);
    el.addEventListener('mouseleave', leave);
    el.addEventListener('focus', enter);
    el.addEventListener('blur', leave);
    handlers.set(el, { enter, leave });
  },
  updated(el, binding) {
    el.__tipVal = binding.value;
    // Refresh the text live if this element's bubble is the one showing.
    if (activeEl === el) show(el, el.__tipVal == null ? '' : String(el.__tipVal));
  },
  beforeUnmount(el) {
    const h = handlers.get(el);
    if (h) {
      el.removeEventListener('mouseenter', h.enter);
      el.removeEventListener('mouseleave', h.leave);
      el.removeEventListener('focus', h.enter);
      el.removeEventListener('blur', h.leave);
      handlers.delete(el);
    }
    if (activeEl === el) hide();
  },
};
