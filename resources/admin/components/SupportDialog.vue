<script>
/**
 * Report an Issue — the handoff to GitHub.
 *
 * ⛔ THE OTHER DOOR HAS NO DIALOG. Get Help is a plain link to the WordPress.org
 * forum, because a dialog there would have bought one thing — a copy button for
 * the setup block, since WordPress.org cannot be pre-filled by anyone — at the
 * cost of a form standing in front of the fastest path in the app. The forum's
 * own new-topic page already asks for "your server environment"; what it cannot
 * do is tell somebody their PHP version, so that button lives on About, where
 * the question is answered and where a reply can point.
 *
 * ⛔ NEITHER SUBMITS ANYTHING. The plugin composes a URL; the person posts under
 * their own account. A token shipped here would sit in every install and post as
 * the maintainer; a relay would strip the reporter's identity off the issue and
 * with it any way to ask a follow-up question. See {@see \Agentimus\Support}.
 *
 * ⚠️ The facts panel is STATED, NOT TYPED — no field border, no caret. It is
 * something the plugin knows about this site, not a draft to fill in. It also
 * cannot be enforced past this screen: every field on a prefilled GitHub form is
 * editable by whoever submits it.
 */
import { bindDocEsc } from '../js/docEsc.js';

export default {
  name: 'SupportDialog',
  props: {
    // { repo, forum, kinds:[{id,label,template}], facts, factsLean, version }
    data: { type: Object, required: true },
  },
  emits: ['close'],
  data() {
    return {
      kind: 'bug',
      // ⚠️ Opposite defaults on purpose. A forum thread is public and stays that
      // way; an issue can be edited afterwards, and the maintainer looking at the
      // site is most of what makes a bug report actionable.
      withSite: true,
    };
  },
  computed: {
    facts() {
      return this.withSite ? this.data.facts : this.data.factsLean;
    },
    kinds() {
      return Array.isArray(this.data.kinds) ? this.data.kinds : [];
    },
    // ⚠️⚠️ PICKED, NEVER BUILT. This computed used to assemble the URL itself,
    // which meant the format lived here AND in Agentimus\Support — and they
    // drifted the moment one was fixed: the `body` fallback landed in PHP, the
    // PHP tests went green, and this button kept emitting the old link. The
    // server composes every URL now and the screen chooses one.
    href() {
      const urls = (this.data.urls || {})[this.kind] || {};
      return (this.withSite ? urls.site : urls.lean) || this.data.repo + '/issues/new';
    },
  },
  mounted() {
    this._unEsc = bindDocEsc(() => this.$emit('close'));
    this.$nextTick(() => {
      // ⚠️ A ref inside v-for is an array in Vue 3; this one is not in a loop,
      // but the unwrap costs nothing and the next edit might move it.
      const el = Array.isArray(this.$refs.first) ? this.$refs.first[0] : this.$refs.first;
      if (el && el.focus) el.focus();
    });
  },
  beforeUnmount() {
    if (this._unEsc) this._unEsc();
  },
  methods: {
    go() {
      window.open(this.href, '_blank', 'noopener');
      this.$emit('close');
    },
  },
};
</script>

<template>
  <Teleport to="body">
    <div class="ar-modal" @click.self="$emit('close')">
      <div
        class="ar-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ar-support-title"
        @keydown.esc="$emit('close')"
      >
        <div class="ar-modal__head">
          <h2 id="ar-support-title" class="ar-modal__title">Report an Issue on GitHub</h2>
          <p class="ar-modal__lead">
            Opens the right form with your setup already attached. You write it and post it
            there, so the reply comes back to you.
          </p>
        </div>

        <div class="ar-modal__body">
          <div class="ar-modal__scroll ar-support">
            <!-- The kind picks which template opens, and the template is where
                 the label comes from. That is its only job — it is not a survey. -->
            <div class="ar-field">
              <label for="ar-support-kind">What kind of issue?</label>
              <select id="ar-support-kind" ref="first" v-model="kind" class="ar-input">
                <option v-for="k in kinds" :key="k.id" :value="k.id">{{ k.label }}</option>
              </select>
            </div>

            <div class="ar-field">
              <label>What the plugin will attach</label>
              <pre class="ar-facts">{{ facts }}</pre>
              <label class="ar-check">
                <input type="checkbox" :checked="withSite" @change="withSite = !withSite" />
                <span>Include my site address</span>
              </label>
            </div>

            <p class="ar-warnline">
              You’ll need a free GitHub account — it’s what lets a reply reach you. You can
              drag a screenshot straight into the form.
              <strong>Just want to ask something? Get Help goes to the forum instead.</strong>
            </p>
          </div>
        </div>

        <div class="ar-modal__actions">
          <button type="button" class="ar-btn ar-btn--ghost" @click="$emit('close')">Cancel</button>
          <button type="button" class="ar-btn ar-newtab" @click="go">Open the form on GitHub</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
