/**
 * The styled hover bubble used by the activity tables — a custom tooltip rather than a
 * native title="…", because a native one can't be themed, is slow to appear, and truncates
 * a long User-Agent in some browsers.
 *
 * Shared by ActivityPanel and RequestLog. The bubble's markup lives in each component's
 * template (a <Teleport to="body"> with ref="uaTipEl") so the `$refs` measuring below keeps
 * working; only the state and behaviour are shared here.
 *
 * The consumer must declare `flash` in its `emits`, since copying reports its result there.
 */
import { tipGuard } from './tipGuard.js';
import { copyText } from './clipboard.js';

export const uaTip = {
  data() {
    return {
      // `hint` is the small second line ("Click to copy"). Empty for tooltips whose value
      // isn't copyable, so a plain hint bubble doesn't promise an interaction it lacks.
      uaTip: { show: false, text: '', hint: '', x: 0, y: 0, caret: 16, below: false },
    };
  },
  mounted() {
    // Any scroll hides the bubble — position:fixed, it would otherwise hang where
    // its row used to be (and scroll-induced mouseenters must not raise a new one).
    this._uaTipUnguard = tipGuard.register(() => this.hideUaTip());
  },
  beforeUnmount() {
    if (this._uaTipUnguard) this._uaTipUnguard();
  },
  methods: {
    /**
     * Position the bubble in the fixed viewport off the hovered element's rect, so it
     * escapes any scroll clipping on the table around it.
     *
     * @param {MouseEvent} ev    The hover event.
     * @param {string}     text  The full value to show.
     * @param {string}     hint  Optional second line; '' for a non-copyable value.
     * @param {string}     align 'left' (default) grows the bubble rightward from the
     *                           element; 'right' pins its right edge to the element's,
     *                           growing LEFTWARD — for right-edge elements whose bubble
     *                           would otherwise spill over whatever sits beside them;
     *                           'cursor' centers it on the pointer — for FULL-WIDTH
     *                           rows, where the element's left edge can be half a
     *                           screen away from where the mouse actually is.
     */
    showUaTip(ev, text, hint = 'Click to copy', align = 'left') {
      if (!text) return;
      const el = ev.currentTarget;
      // A scroll-induced mouseenter isn't a hover — hold the bubble until the mouse
      // truly moves (the guard re-runs this if the pointer settles on the element).
      if (tipGuard.suppress(ev, el, (mv) => this.showUaTip({ currentTarget: el, clientX: mv.clientX, type: 'retry' }, text, hint, align))) return;
      const rect = el.getBoundingClientRect();
      // The card the hovered element lives in: a cursor-anchored bubble clamps
      // inside it, so it never drifts over whatever sits beside the card.
      const host = el.closest ? el.closest('.ar-card') : null;
      const hostRect = host ? host.getBoundingClientRect() : null;
      const below = rect.top < 96; // not enough room above → drop below.
      const right = 'right' === align;
      const cursor = 'cursor' === align;
      // Viewport x the caret points at: near the element's leading edge for a
      // left-aligned bubble, its trailing edge for a right-aligned one, or the
      // pointer itself for a cursor-anchored one.
      const anchor = right ? rect.right - 16 : cursor ? ev.clientX : rect.left + 16;
      this.uaTip = {
        show: true,
        text,
        hint,
        x: Math.max(cursor ? ev.clientX - 24 : rect.left, 12),
        y: below ? rect.bottom + 8 : rect.top - 8,
        caret: 16,
        below,
      };
      // Measure the rendered box so it never overflows the viewport (and, for 'right',
      // actually pin the right edge — width is only known once rendered), keeping the
      // caret over the element even after the box is clamped inward.
      this.$nextTick(() => {
        const el = this.$refs.uaTipEl;
        if (!el) return;
        const w = el.offsetWidth;
        let min = 12;
        let max = window.innerWidth - w - 12;
        if (cursor && hostRect) {
          min = Math.max(min, hostRect.left + 8);
          max = Math.min(max, hostRect.right - w - 8);
          if (max < min) max = min; // card narrower than the bubble — hug its left edge.
        }
        const x = Math.max(min, Math.min(right ? rect.right - w : this.uaTip.x, max));
        this.uaTip.x = x;
        this.uaTip.caret = Math.max(12, Math.min(anchor - x, w - 16));
      });
    },
    hideUaTip() {
      this.uaTip.show = false;
    },
    // Click a truncated value (the User-Agent) to copy the WHOLE string — the cell only
    // shows an ellipsis, so this is the way to grab the full text.
    async copyUa(text) {
      return this.copyVal(text, 'User-Agent');
    },
    // Copy the value (see clipboard.js for the secure-context/plain-HTTP handling)
    // and report the outcome through `flash` — hence the consumer's emits: ['flash'].
    async copyVal(text, label) {
      if (!text) return;
      const ok = await copyText(text);
      this.$emit('flash', ok ? 'success' : 'error', ok ? `${label} copied.` : 'Could not copy — select the text and copy manually.');
    },
  },
};
