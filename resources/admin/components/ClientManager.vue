<script>
/**
 * The client manager — every standing decision about a client in one dialog:
 * blocked, trusted (always allowed), and ignored from the review queue, each
 * with its decision date (where recorded) and the recognition catalog's
 * identity for known crawlers. Opened from Settings → AI Access; deliberately
 * NOT part of the review bell, which surfaces new clients rather than
 * managing old decisions.
 *
 * Actions are instant (each returns the refreshed payload, so the dialog
 * always re-renders from one server truth): Stop ignoring forgets a dismissal,
 * Unblock / Stop trusting take a token off the corresponding list. ("Untrust"
 * and "un-ignore" are not real words — the coined hyphens read as dashes in
 * the uppercase mono buttons, so the labels use plain verbs instead.) The
 * parent is
 * handed the settings-shaped lists after every mutation so the open Settings
 * form (and its saved snapshot) stay in step.
 *
 * Dialog conventions match the day reports: fixed size, Esc or Close only —
 * no backdrop-click close — and a centered spinner while loading.
 */
import { formatDate } from '../js/wpDate.js';

export default {
  name: 'ClientManager',
  props: {
    api: { type: Object, default: null },
  },
  emits: ['close', 'changed'],
  data() {
    return {
      loading: true,
      error: '',
      data: { blocked: [], allowed: [], ignored: [] },
      busy: '', // key/token of the row whose action is in flight
      // Whether the blocking master switch is on — the Blocked tab's note must
      // say honestly whether this list is enforced right now or waiting.
      blockOn: false,
      scrollMore: false,
      // One section at a time, switched by tabs — three stacked sections made
      // users scroll to find the one they came for (Heera, 2026-07-13).
      view: 'blocked',
    };
  },
  mounted() {
    document.addEventListener('keydown', this.onKeydown);
    this.load();
    this.$nextTick(() => {
      const el = this.$refs.dialog;
      if (el) el.focus();
    });
  },
  beforeUnmount() {
    document.removeEventListener('keydown', this.onKeydown);
  },
  methods: {
    onKeydown(e) {
      if ('Escape' === e.key) this.$emit('close');
    },
    async load() {
      this.loading = true;
      this.error = '';
      if (!this.api || !this.api.getClients) {
        this.loading = false;
        this.error = 'Unable to load in this context.';
        return;
      }
      try {
        this.apply(await this.api.getClients());
        // Land on a tab that has something to show (an owner opening this for
        // the ignored list shouldn't find an empty "Blocked" first).
        if (!this.data[this.view].length) {
          const first = ['blocked', 'allowed', 'ignored'].find((k) => this.data[k].length);
          if (first) this.view = first;
        }
      } catch (e) {
        this.error = (e && e.message) || 'Failed to load.';
      } finally {
        this.loading = false;
      }
    },
    show(view) {
      this.view = view;
      const el = this.$refs.scroll;
      if (el) el.scrollTop = 0;
      this.$nextTick(() => this.updateScrollHint());
    },
    apply(res) {
      this.data = {
        blocked: res.blocked || [],
        allowed: res.allowed || [],
        ignored: res.ignored || [],
      };
      if (res.settings) {
        this.blockOn = !!res.settings.block_agents;
        this.$emit('changed', res.settings);
      }
      this.$nextTick(() => this.updateScrollHint());
    },
    updateScrollHint() {
      const el = this.$refs.scroll;
      this.scrollMore = !!el && el.scrollHeight - el.scrollTop - el.clientHeight > 4;
    },
    scrollBody() {
      const el = this.$refs.scroll;
      if (!el) return;
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      el.scrollBy({ top: Math.round(el.clientHeight * 0.8), behavior: reduce ? 'auto' : 'smooth' });
    },
    dateLabel(at) {
      if (!at) return '';
      return formatDate(new Date(at * 1000));
    },
    // What the chip can't already say. The token chip shows the name, so
    // repeating it here ("ChatGPT-User  ChatGPT-User · OpenAI") was noise —
    // show the catalog name only when it differs from the token beyond case,
    // and always the operator (the part a token never carries).
    identity(row) {
      if (!row.known) return '';
      const name = String(row.known.name || '');
      const op = String(row.known.operator || '');
      const repeatsToken = name.toLowerCase() === String(row.token || '').toLowerCase();
      if (repeatsToken) return op;
      return op ? `${name} · ${op}` : name;
    },
    async unignore(row) {
      if (this.busy) return;
      this.busy = row.key;
      try {
        this.apply(await this.api.undismissClient(row.key));
      } catch (e) {
        this.error = (e && e.message) || 'Could not bring it back.';
      } finally {
        this.busy = '';
      }
    },
    async removeToken(row, list) {
      if (this.busy) return;
      this.busy = row.token;
      try {
        this.apply(await this.api.removeClientToken(row.token, list));
      } catch (e) {
        this.error = (e && e.message) || 'Could not remove.';
      } finally {
        this.busy = '';
      }
    },
  },
};
</script>

<template>
  <transition name="ar-modal" appear>
    <div class="ar-modal">
      <div
        ref="dialog"
        class="ar-modal__panel ar-modal__panel--day"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ar-cm-title"
        tabindex="-1"
        @keydown.esc="$emit('close')"
      >
        <div class="ar-modal__head">
          <h2 id="ar-cm-title" class="ar-modal__title">Client Decisions</h2>
          <p class="ar-modal__lead">
            Everything you've decided about visiting crawlers and AI assistants — and when you
            decided it. Undoing here applies immediately.
          </p>
          <div class="ar-rev-tabs" role="tablist" aria-label="Decision type">
            <button type="button" class="ar-rev-tab" :class="{ 'is-active': view === 'blocked' }" role="tab" :aria-selected="view === 'blocked'" @click="show('blocked')">
              Blocked <span class="ar-rev-tab__n">{{ data.blocked.length }}</span>
            </button>
            <button type="button" class="ar-rev-tab" :class="{ 'is-active': view === 'allowed' }" role="tab" :aria-selected="view === 'allowed'" @click="show('allowed')">
              Allowed <span class="ar-rev-tab__n">{{ data.allowed.length }}</span>
            </button>
            <button type="button" class="ar-rev-tab" :class="{ 'is-active': view === 'ignored' }" role="tab" :aria-selected="view === 'ignored'" @click="show('ignored')">
              Ignored <span class="ar-rev-tab__n">{{ data.ignored.length }}</span>
            </button>
          </div>
        </div>

        <div class="ar-modal__body">
          <div ref="scroll" class="ar-modal__scroll" :class="{ 'is-loading': loading }" @scroll="updateScrollHint">
            <template v-if="!loading">
              <p v-if="error" class="ar-act-log__state is-error">{{ error }}</p>

              <div v-if="view === 'blocked'" class="ar-cm__section">
                <!-- The scope notice leads the list — a footnote under 11 rows was
                     below the fold, and what a block reaches (and whether it is
                     enforced RIGHT NOW) must be read before the rows. -->
                <p v-if="data.blocked.length" class="ar-cm__notice" :class="{ 'is-off': !blockOn }">
                  <template v-if="blockOn">These clients are refused at this plugin’s machine files (llms.txt, markdown pages, discovery).</template>
                  <template v-else><strong>Blocking is turned off in Settings right now</strong>, so this list waits — nothing is refused until you turn it on.</template>
                  A block here does not cover your normal pages — that needs your host or CDN. It is
                  also separate from robots.txt, which is only a request to bots.
                </p>
                <ul v-if="data.blocked.length" class="ar-cm__list">
                  <li v-for="row in data.blocked" :key="'b:' + row.token" class="ar-cm__row">
                    <span class="ar-cm__who">
                      <code class="ar-cm__token">{{ row.token }}</code>
                      <span v-if="identity(row)" class="ar-cm__known">{{ identity(row) }}</span>
                    </span>
                    <!-- The tab already says "Blocked" — the verb would repeat it on every row. -->
                    <span class="ar-cm__meta">{{ row.at ? dateLabel(row.at) : '' }}</span>
                    <button type="button" class="ar-cm__undo" :disabled="busy === row.token" @click="removeToken(row, 'blocked_agents')">
                      {{ busy === row.token ? 'Removing…' : 'Unblock' }}
                    </button>
                  </li>
                </ul>
                <p v-else class="ar-cm__none">No blocked clients yet — when you Block one from the review bell or the Request Log, it lands here with its date.</p>
              </div>

              <div v-else-if="view === 'allowed'" class="ar-cm__section">
                <!-- "Trusted" grants nothing — it must not read like a robots.txt
                     permission or an invitation. Leads the list, like Blocked's. -->
                <p v-if="data.allowed.length" class="ar-cm__notice">
                  Trusted only means: this site never blocks these clients and never asks you about
                  them again. It sends nothing to the bot and changes nothing in robots.txt.
                </p>
                <ul v-if="data.allowed.length" class="ar-cm__list">
                  <li v-for="row in data.allowed" :key="'a:' + row.token" class="ar-cm__row">
                    <span class="ar-cm__who">
                      <code class="ar-cm__token">{{ row.token }}</code>
                      <span v-if="identity(row)" class="ar-cm__known">{{ identity(row) }}</span>
                      <!-- Trusted here, told to stay away in robots.txt — maybe
                           deliberate, but the two surfaces may only disagree
                           out loud. -->
                      <span v-if="row.robots" class="ar-cm__robots">robots.txt asks it to stay away<svg class="ar-rev-vwhy" v-tip="'Your robots.txt tells this bot to stay away from the whole site (the AI-training block list in Settings). Trusting it here does not remove that line — an honest bot obeys robots.txt and will stay away anyway.'" tabindex="0" role="img" aria-label="Why" viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="8" cy="8" r="6.4" /><path d="M8 7.4v3.2" /><path d="M8 5.1v.1" /></svg></span>
                    </span>
                    <span class="ar-cm__meta">{{ row.at ? dateLabel(row.at) : '' }}</span>
                    <button type="button" class="ar-cm__undo ar-cm__undo--danger" :disabled="busy === row.token" @click="removeToken(row, 'allowed_agents')">
                      {{ busy === row.token ? 'Removing…' : 'Stop trusting' }}
                    </button>
                  </li>
                </ul>
                <p v-else class="ar-cm__none">No trusted clients beyond the built-in search engines — when you Allow one from the review bell, it lands here.</p>
              </div>

              <div v-else class="ar-cm__section">
                <ul v-if="data.ignored.length" class="ar-cm__list">
                  <li v-for="row in data.ignored" :key="'i:' + row.key" class="ar-cm__row">
                    <span class="ar-cm__who">
                      <strong v-if="row.label" class="ar-cm__name">{{ row.label }}</strong>
                      <em v-else class="ar-cm__name ar-cm__name--anon">Unnamed client</em>
                    </span>
                    <span class="ar-cm__meta">
                      {{ row.at ? dateLabel(row.at) : '' }}{{ row.hits ? ` · ${row.hits} hits then` : '' }}
                    </span>
                    <button type="button" class="ar-cm__undo" :disabled="busy === row.key" @click="unignore(row)">
                      {{ busy === row.key ? 'Removing…' : 'Stop ignoring' }}
                    </button>
                  </li>
                </ul>
                <p v-else class="ar-cm__none">Nothing ignored yet — clients you dismiss from the review bell wait here, in case you change your mind.</p>
                <p v-if="data.ignored.length" class="ar-cm__note">
                  An ignored client comes back on its own if its traffic grows a lot.
                  Un-ignoring only brings it back to the review bell sooner, if it still visits.
                </p>
              </div>
            </template>
          </div>
          <div v-if="loading" class="ar-day-loadover" role="status">
            <span class="ar-spinner" aria-hidden="true"></span>
            <span class="ar-day-loadover__label">Loading…</span>
          </div>
          <div class="ar-modal__fade" :class="{ 'is-visible': scrollMore }">
            <button type="button" class="ar-modal__fade-btn" :disabled="!scrollMore" aria-label="Scroll down for more" @click="scrollBody">
              <svg viewBox="0 0 16 16" class="ar-modal__chev" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
            </button>
          </div>
        </div>

        <div class="ar-modal__actions">
          <button type="button" class="ar-btn ar-btn--ghost" @click="$emit('close')">Close</button>
        </div>
      </div>
    </div>
  </transition>
</template>
