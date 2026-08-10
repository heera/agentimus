<script>
/**
 * The client manager — every standing decision about a client in one dialog:
 * blocked, trusted (always allowed), and ignored from the review queue, each
 * with its decision date (where recorded) and the recognition catalog's
 * identity for known crawlers. Opened from Settings → AI access; deliberately
 * NOT part of the review bell, which surfaces new clients rather than
 * managing old decisions.
 *
 * Actions are instant (each returns the refreshed payload, so the dialog
 * always re-renders from one server truth): Un-ignore forgets a dismissal,
 * Unblock / Un-trust take a token off the corresponding list. The parent is
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
      if (res.settings) this.$emit('changed', res.settings);
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
    identity(row) {
      return row.known ? `${row.known.name} · ${row.known.operator}` : '';
    },
    async unignore(row) {
      if (this.busy) return;
      this.busy = row.key;
      try {
        this.apply(await this.api.undismissClient(row.key));
      } catch (e) {
        this.error = (e && e.message) || 'Could not un-ignore.';
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
            Everything you've decided about visiting bots and agents — blocked, trusted, or ignored —
            and when. Undoing here applies immediately.
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
                <ul v-if="data.blocked.length" class="ar-cm__list">
                  <li v-for="row in data.blocked" :key="'b:' + row.token" class="ar-cm__row">
                    <span class="ar-cm__who">
                      <code class="ar-cm__token">{{ row.token }}</code>
                      <span v-if="identity(row)" class="ar-cm__known">{{ identity(row) }}</span>
                    </span>
                    <span class="ar-cm__meta">{{ row.at ? 'blocked ' + dateLabel(row.at) : '' }}</span>
                    <button type="button" class="ar-cm__undo" :disabled="busy === row.token" @click="removeToken(row, 'blocked_agents')">
                      {{ busy === row.token ? 'Removing…' : 'Unblock' }}
                    </button>
                  </li>
                </ul>
                <p v-else class="ar-cm__none">No blocked clients.</p>
              </div>

              <div v-else-if="view === 'allowed'" class="ar-cm__section">
                <ul v-if="data.allowed.length" class="ar-cm__list">
                  <li v-for="row in data.allowed" :key="'a:' + row.token" class="ar-cm__row">
                    <span class="ar-cm__who">
                      <code class="ar-cm__token">{{ row.token }}</code>
                      <span v-if="identity(row)" class="ar-cm__known">{{ identity(row) }}</span>
                    </span>
                    <span class="ar-cm__meta">{{ row.at ? 'allowed ' + dateLabel(row.at) : '' }}</span>
                    <button type="button" class="ar-cm__undo" :disabled="busy === row.token" @click="removeToken(row, 'allowed_agents')">
                      {{ busy === row.token ? 'Removing…' : 'Un-trust' }}
                    </button>
                  </li>
                </ul>
                <p v-else class="ar-cm__none">No trusted clients beyond the built-in search engines.</p>
              </div>

              <div v-else class="ar-cm__section">
                <ul v-if="data.ignored.length" class="ar-cm__list">
                  <li v-for="row in data.ignored" :key="'i:' + row.key" class="ar-cm__row">
                    <span class="ar-cm__who">
                      <strong v-if="row.label" class="ar-cm__name">{{ row.label }}</strong>
                      <em v-else class="ar-cm__name ar-cm__name--anon">Unnamed client</em>
                    </span>
                    <span class="ar-cm__meta">
                      {{ row.at ? 'ignored ' + dateLabel(row.at) : '' }}{{ row.hits ? ` · ${row.hits} hits then` : '' }}
                    </span>
                    <button type="button" class="ar-cm__undo" :disabled="busy === row.key" @click="unignore(row)">
                      {{ busy === row.key ? 'Removing…' : 'Un-ignore' }}
                    </button>
                  </li>
                </ul>
                <p v-else class="ar-cm__none">Nothing ignored.</p>
                <p v-if="data.ignored.length" class="ar-cm__note">
                  An ignored client also returns on its own if its traffic materially grows;
                  un-ignoring just brings it back to the review bell sooner (if it still visits).
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
