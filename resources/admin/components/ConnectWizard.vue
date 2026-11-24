<script>
/**
 * Connect an assistant: pick your app → add it → wait for your approval →
 * done. Opened from the Connected assistants card. It only shows steps and
 * reads the connection list; approving still happens on the consent page the
 * app opens, and Change access is the card's own dialog.
 *
 * The wait asks /oauth/grants every 3 seconds for 10 minutes, then offers
 * Check again. Only a connection approved after the wizard opened ends it
 * (js/connectWizard.js). It keeps asking while this tab is hidden: the owner
 * is in the app, or on the approval page, exactly then.
 */
import { copyText } from '../js/clipboard.js';
import { bindDocEsc } from '../js/docEsc.js';
import { connectionAccessLabel } from '../js/connectionAccess.js';
import { APPS, POLL_MS, recipeFor, snapshot, newConnection, approvedOnly, waitOver } from '../js/connectWizard.js';

export default {
  name: 'ConnectWizard',
  props: {
    api: { type: Object, default: null },
    endpoint: { type: String, default: '' },
    token: { type: String, default: '' },
    // The grants as the card holds them when the wizard opens: the snapshot.
    grants: { type: Array, default: () => [] },
    accessChoiceCount: { type: Number, default: 0 },
  },
  emits: ['close', 'connected', 'change-access'],
  data() {
    return {
      step: 1,
      pick: '',
      copied: '',
      // 'waiting' | 'timeout' | 'done'
      waitState: 'waiting',
      checkError: false,
      found: null,
      // Approved, but the app has not come back with its code yet.
      approved: null,
    };
  },
  computed: {
    apps() {
      return APPS;
    },
    stepMeta() {
      return [
        { label: 'Your app' },
        { label: 'Add it' },
        { label: 'Approve' },
        { label: 'Done' },
      ];
    },
    pickedApp() {
      return APPS.find((a) => a.key === this.pick) || null;
    },
    recipe() {
      return recipeFor(this.pick, this.endpoint, this.token);
    },
    foundAccess() {
      const g = this.found;
      return g ? connectionAccessLabel(g.access, g.scope, this.accessChoiceCount) : '';
    },
  },
  created() {
    this._snap = snapshot(this.grants, Date.now());
  },
  mounted() {
    this._unEsc = bindDocEsc(() => this.close());
    window.addEventListener('focus', this.checkNow);
    this.$nextTick(() => this.$refs.panel && this.$refs.panel.focus());
  },
  beforeUnmount() {
    this.stopWait();
    if (this._unEsc) this._unEsc();
    window.removeEventListener('focus', this.checkNow);
    clearTimeout(this._copyTimer);
  },
  methods: {
    close() {
      this.$emit('close');
    },
    choose(key) {
      this.pick = key;
      this.step = 2;
    },
    back() {
      this.stopWait();
      if (this.step > 1) this.step -= 1;
    },
    async copy(what, text) {
      if (!text || !(await copyText(text))) return;
      this.copied = what;
      clearTimeout(this._copyTimer);
      this._copyTimer = setTimeout(() => { this.copied = ''; }, 2000);
    },
    startWait() {
      this.step = 3;
      this.waitState = 'waiting';
      this.checkError = false;
      this._waitStart = Date.now();
      this.stopWait();
      this.tick();
    },
    stopWait() {
      clearTimeout(this._pollTimer);
      this._pollTimer = null;
    },
    // A window coming back into focus is the likeliest moment the owner just
    // approved: ask at once instead of waiting out the three seconds.
    checkNow() {
      if (this.step === 3 && this.waitState === 'waiting') {
        this.stopWait();
        this.tick();
      }
    },
    async tick() {
      if (this.step !== 3 || this.waitState !== 'waiting') return;
      try {
        const res = await this.api.getOauthGrants();
        this.checkError = false;
        const grants = (res && res.grants) || [];
        this.approved = approvedOnly(this._snap, grants);
        const g = newConnection(this._snap, grants);
        if (g) {
          this.found = g;
          this.waitState = 'done';
          this.step = 4;
          this.$emit('connected', res);
          return;
        }
      } catch (e) {
        // One failed ask is not the end of the wait; say so and keep asking.
        this.checkError = true;
      }
      if (this.step !== 3) return;
      if (waitOver(this._waitStart, Date.now())) {
        this.waitState = 'timeout';
        return;
      }
      this.stopWait();
      this._pollTimer = setTimeout(this.tick, POLL_MS);
    },
    changeAccess() {
      this.$emit('change-access', this.found);
    },
  },
};
</script>

<template>
  <Teleport to="body">
    <div class="ar-modal" @click.self="close">
      <div
        ref="panel"
        class="ar-modal__panel ar-cwiz"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ar-cwiz-title"
        tabindex="-1"
      >
        <div class="ar-modal__head">
          <ol class="ar-stepper ar-cwiz__stepper" :aria-label="`Step ${step} of 4`">
            <li
              v-for="(s, i) in stepMeta"
              :key="s.label"
              :class="{ 'is-done': step > i + 1, 'is-now': step === i + 1 }"
              :aria-current="step === i + 1 ? 'step' : null"
            >
              <span class="ar-stepper__dot" aria-hidden="true">
                <svg v-if="step > i + 1" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7" /></svg>
                <template v-else>{{ i + 1 }}</template>
              </span>
              <span class="ar-stepper__label">{{ s.label }}</span>
            </li>
          </ol>
          <h2 v-if="step === 1" id="ar-cwiz-title" class="ar-modal__title">Connect an Assistant</h2>
          <h2 v-else-if="step === 2" id="ar-cwiz-title" class="ar-modal__title">Add This Site to {{ pickedApp && pickedApp.label }}</h2>
          <h2 v-else-if="step === 3" id="ar-cwiz-title" class="ar-modal__title">
            {{ waitState === 'timeout' ? 'Nothing Yet' : `Waiting for ${pickedApp && pickedApp.label}` }}
          </h2>
          <h2 v-else id="ar-cwiz-title" class="ar-modal__title ar-cwiz__done-title">{{ found && found.name }} Is Connected</h2>
        </div>

        <div class="ar-modal__body">
          <div class="ar-modal__scroll">
            <!-- 1 · Your app -->
            <div v-if="step === 1" class="ar-cwiz__step">
              <p class="ar-modal__lead">Which app do you want to use with this site?</p>
              <div class="ar-mcp-picker" role="group" aria-label="Your app">
                <button
                  v-for="a in apps"
                  :key="a.key"
                  type="button"
                  class="ar-mcp-pick"
                  @click="choose(a.key)"
                >{{ a.label }}<small>{{ a.kind }}</small></button>
              </div>
            </div>

            <!-- 2 · Add it -->
            <div v-else-if="step === 2" class="ar-cwiz__step">
              <p class="ar-mcp-eyebrow">Your server address</p>
              <div class="ar-mcp-addr__row">
                <code class="ar-mcp-addr__url">{{ endpoint }}</code>
                <button type="button" class="ar-btn ar-btn--ghost ar-btn--small" @click="copy('url', endpoint)">
                  {{ copied === 'url' ? 'Copied' : 'Copy' }}
                </button>
              </div>
              <div class="ar-mcp-recipe">
                <p class="ar-mcp-eyebrow">{{ recipe.title }}</p>
                <ol class="ar-mcp-recipe__steps">
                  <li v-for="(s, i) in recipe.steps" :key="i">{{ s }}</li>
                </ol>
                <div v-if="recipe.copy" class="ar-mcp-addr__row">
                  <code class="ar-mcp-addr__url">{{ recipe.copy }}</code>
                  <button type="button" class="ar-btn ar-btn--ghost ar-btn--small" @click="copy('recipe', recipe.copy)">
                    {{ copied === 'recipe' ? 'Copied' : 'Copy' }}
                  </button>
                </div>
                <p v-if="recipe.note" class="ar-field__hint">{{ recipe.note }}</p>
              </div>
            </div>

            <!-- 3 · Approve: waiting for the approval to land -->
            <div v-else-if="step === 3" class="ar-cwiz__step">
              <template v-if="waitState === 'waiting'">
                <p v-if="approved" class="ar-cwiz__wait" role="status">
                  <span class="ar-cwiz__spin" aria-hidden="true"></span>
                  Approved. Waiting for {{ approved.name }} to sign in…
                </p>
                <p v-else class="ar-cwiz__wait" role="status">
                  <span class="ar-cwiz__spin" aria-hidden="true"></span>
                  Approve {{ pickedApp && pickedApp.label }} on the page it opens. This screen moves on by itself.
                </p>
                <p class="ar-field__hint">
                  No approval page? Go back and check the steps, or close this: an app you approve later still appears in Connected assistants.
                </p>
                <p v-if="checkError" class="ar-field__hint" role="alert">Your site didn’t answer the last check. Still trying.</p>
              </template>
              <template v-else>
                <p v-if="approved" class="ar-modal__lead">
                  You approved {{ approved.name }}, but it hasn’t signed in. If its page showed an error, start the connection again from the app.
                </p>
                <p v-else class="ar-modal__lead">
                  No new connection in the last 10 minutes. If you approved it, check again. If no approval page opened, go back to the steps.
                </p>
              </template>
            </div>

            <!-- 4 · Done -->
            <div v-else class="ar-cwiz__step ar-cwiz__done">
              <div class="ar-wiz__done-check" aria-hidden="true">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7" /></svg>
              </div>
              <p class="ar-modal__lead">
                It can now use this site’s tools as your user, within what your settings allow.
              </p>
              <p class="ar-cwiz__access">
                Access: <strong>{{ foundAccess }}</strong>
                <button type="button" class="ar-linkbtn ar-linkbtn--act" @click="changeAccess">Change access</button>
              </p>
              <div class="ar-cwiz__try">
                <p class="ar-mcp-eyebrow">Try asking it</p>
                <p class="ar-cwiz__ask">“How ready is my site for AI, and what should I fix first?”</p>
              </div>
            </div>
          </div>
        </div>

        <div class="ar-modal__actions">
          <template v-if="step === 1">
            <button type="button" class="ar-btn ar-btn--ghost" @click="close">Cancel</button>
          </template>
          <template v-else-if="step === 2">
            <button type="button" class="ar-btn ar-btn--ghost" @click="back">Back</button>
            <button type="button" class="ar-btn" @click="startWait">I’ve added it</button>
          </template>
          <template v-else-if="step === 3">
            <button type="button" class="ar-btn ar-btn--ghost" @click="back">Back</button>
            <button v-if="waitState === 'timeout'" type="button" class="ar-btn" @click="startWait">Check again</button>
            <button v-else type="button" class="ar-btn ar-btn--ghost" @click="close">Close</button>
          </template>
          <template v-else>
            <button type="button" class="ar-btn" @click="close">Close</button>
          </template>
        </div>
      </div>
    </div>
  </Teleport>
</template>
