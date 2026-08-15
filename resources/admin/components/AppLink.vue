<script>
/**
 * A link to another screen of THIS app — never an <a href>.
 *
 * An anchor was the obvious markup and the wrong one: the browser reads href
 * and advertises the address in its status bar, so an in-app view change
 * announces itself like a hyperlink to somewhere else (his call, 2026-08-15).
 * It is a control that swaps a view, so it is a button, and the router reads
 * the hash exactly as it did before.
 *
 * Everything about how it LOOKS lives in one class (.ar-go): accent, semibold,
 * no underline at rest — an underline at rest is what wp-admin's own links
 * wear, and this is not one of those. Hover brightens the ink and adds the
 * underline, which is the one cue that cannot paint over the words beside it.
 */
export default {
  name: 'AppLink',
  props: {
    // The screen's hash, e.g. '#log' — the same address the nav uses.
    to: { type: String, required: true },
  },
  methods: {
    go() {
      window.location.hash = this.to;
    },
  },
};
</script>

<template>
  <button type="button" class="ar-go" @click="go"><slot /></button>
</template>
