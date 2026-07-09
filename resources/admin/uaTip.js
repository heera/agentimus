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
export const uaTip = {
  data() {
    return {
      // `hint` is the small second line ("Click to copy"). Empty for tooltips whose value
      // isn't copyable, so a plain hint bubble doesn't promise an interaction it lacks.
      uaTip: { show: false, text: '', hint: '', x: 0, y: 0, caret: 16, below: false },
    };
  },
  methods: {
    /**
     * Position the bubble in the fixed viewport off the hovered element's rect, so it
     * escapes any scroll clipping on the table around it.
     *
     * @param {MouseEvent} ev   The hover event.
     * @param {string}     text The full value to show.
     * @param {string}     hint Optional second line; '' for a non-copyable value.
     */
    showUaTip(ev, text, hint = 'Click to copy') {
      if (!text) return;
      const rect = ev.currentTarget.getBoundingClientRect();
      const below = rect.top < 96; // not enough room above → drop below.
      const anchor = rect.left + 16; // viewport x the caret points at — the element's top-left.
      this.uaTip = {
        show: true,
        text,
        hint,
        x: Math.max(rect.left, 12),
        y: below ? rect.bottom + 8 : rect.top - 8,
        caret: 16,
        below,
      };
      // Measure the rendered box so a right-edge cell doesn't overflow the viewport, and
      // keep the caret over the cell even after the box is clamped inward (a fixed caret
      // would drift off on narrow screens).
      this.$nextTick(() => {
        const el = this.$refs.uaTipEl;
        if (!el) return;
        const w = el.offsetWidth;
        const x = Math.max(12, Math.min(this.uaTip.x, window.innerWidth - w - 12));
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
    // Clipboard API where available, with the legacy textarea fallback for plain-HTTP
    // (non-secure) sites, where navigator.clipboard is undefined.
    async copyVal(text, label) {
      if (!text) return;
      let ok = false;
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
          ok = true;
        }
      } catch (e) { /* fall through to the legacy path */ }
      if (!ok) ok = this.legacyCopy(text);
      this.$emit('flash', ok ? 'success' : 'error', ok ? `${label} copied.` : 'Could not copy — select the text and copy manually.');
    },
    legacyCopy(text) {
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
      } catch (e) {
        return false;
      }
    },
  },
};
