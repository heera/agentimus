<script>
/**
 * The Announcements screen — the ledger of scheduled public speech.
 *
 * A data screen, so the data screens' laws: re-read on every return (the
 * freshness rule), pagination in the footer so the furniture never grows with
 * the rows, and every failed row carries the network's own words plus the two
 * hands the owner has — Try again and Remove. Cancel lives only on a promise;
 * a sent row is history and history is not cancellable.
 *
 * Dates speak the site's own formats (wpDate), never the browser locale's.
 */
import CardSkeleton from '../CardSkeleton.vue';
import { formatStamp, relTimeShort } from '../../js/wpDate.js';

export default {
  name: 'AnnouncementsPanel',
  components: { CardSkeleton },
  props: {
    api: { type: Object, default: null },
    active: { type: Boolean, default: false },
  },
  emits: ['flash'],
  data() {
    return {
      loading: false,
      loaded: false,
      error: '',
      rows: [],
      total: 0,
      page: 1,
      perPage: 20,
      acting: 0, // The row id an action is in flight for — its buttons go quiet.
    };
  },
  computed: {
    pageStart() {
      return this.total === 0 ? 0 : (this.page - 1) * this.perPage + 1;
    },
    pageEnd() {
      return Math.min(this.total, this.page * this.perPage);
    },
    hasNewer() {
      return this.page > 1;
    },
    hasOlder() {
      return this.page * this.perPage < this.total;
    },
  },
  watch: {
    active(on) {
      if (on) this.load();
      else this.stopPoll();
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  beforeUnmount() {
    this.stopPoll();
  },
  methods: {
    async load(page = this.page) {
      if (!this.api) return;
      this.loading = true;
      this.error = '';
      try {
        this.apply(await this.api.getAnnouncements(page));
        this.loaded = true;
      } catch (e) {
        this.error = e.message || 'Could not load the announcements.';
      } finally {
        this.loading = false;
      }
    },
    apply(data) {
      this.rows = data.rows || [];
      this.total = data.total || 0;
      this.page = data.page || 1;
      this.perPage = data.perPage || 20;
      // A page emptied by the last action (the one failed row removed, say)
      // walks back a page rather than stranding the owner on a blank one.
      if (!this.rows.length && this.page > 1) this.load(this.page - 1);
      this.armPoll();
    },
    // The one screen where WATCHING is the point: while a due promise is on
    // screen ("goes out with the next tick"), re-read every 15s until it
    // resolves — and each read wakes wp-cron, so the watching helps the
    // sending. No due row, no poll: the freshness rule alone suffices.
    armPoll() {
      this.stopPoll();
      const due = this.rows.some((r) => r.status === 'queued' && r.scheduledAt * 1000 <= Date.now() + 60000);
      if (due && this.active) this._poll = setTimeout(() => this.load(), 15000);
    },
    stopPoll() {
      if (this._poll) {
        clearTimeout(this._poll);
        this._poll = null;
      }
    },
    async act(action, id) {
      if (!this.api || this.acting) return;
      this.acting = id;
      try {
        this.apply(await this.api.actAnnouncements({ action, id, page: this.page }));
        this.$emit(
          'flash',
          'success',
          { cancel: 'Cancelled — it will not be posted.', retry: 'Queued again — it goes out with the next tick.', remove: 'Removed from the record.' }[action]
        );
      } catch (e) {
        this.$emit('flash', 'error', e.message || 'That didn’t work — reload and try again.');
      } finally {
        this.acting = 0;
      }
    },
    stamp(seconds) {
      return seconds ? formatStamp(new Date(seconds * 1000)) : '';
    },
    // "in 25m / in 11h / in 3d" — the promise's distance, the counterpart of
    // relTimeShort's "ago" for rows that haven't happened yet.
    dueIn(seconds) {
      const m = Math.round((seconds * 1000 - Date.now()) / 60000);
      if (m < 1) return 'with the next tick';
      if (m < 60) return `in ${m}m`;
      const h = Math.round(m / 60);
      if (h < 24) return `in ${h}h`;
      return `in ${Math.round(h / 24)}d`;
    },
    ago(seconds) {
      return relTimeShort(seconds * 1000);
    },
    networkWord(id) {
      return { telegram: 'Telegram' }[id] || id;
    },
    // The first-timer button: the road starts where connections live.
    goSharing() {
      window.location.hash = '#integrations';
    },
  },
};
</script>

<template>
  <div class="ar-ann">
    <p v-if="error" class="ar-int__error" role="alert">{{ error }} — try again, and if it persists, reload the page.</p>
    <CardSkeleton v-if="!loaded && !error" lead="Loading announcements…" />

    <template v-if="loaded && !error">
      <!-- The first-timer card: what this record will hold, and where the
           road starts. The invitation grammar — mark, title, lead, one door. -->
      <div v-if="total === 0" class="ar-work__intro">
        <svg class="ar-work__intro-mark" viewBox="0 0 48 48" width="44" height="44" fill="none"
             stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M8 29V19l24-11v31Z" />
          <path d="M34 17a9 9 0 0 1 8 7" />
          <path d="M14 30l3 10" />
        </svg>

        <h3 class="ar-work__intro-title">Announce Your Posts on a Schedule</h3>
        <p class="ar-work__intro-lead">
          Connect a network under Integrations → Sharing, and every post’s Share tab grows a
          “Send it later” row. Hand a draft a time, and it posts itself — from your own server,
          with your own account. Everything sent, waiting, or stuck lands here, each with its reason.
        </p>

        <button type="button" class="ar-btn" @click="goSharing">Set up sharing</button>

        <p class="ar-work__intro-note">
          Nothing is ever posted without your hand — you write the draft, you pick the minute.
        </p>
      </div>

      <div v-else class="ar-ann__card">
        <table class="ar-ann__table">
          <thead>
            <tr>
              <th>When</th>
              <th>Network</th>
              <th>Post</th>
              <th class="ar-ann__statuscol">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td class="ar-ann__when">{{ stamp(row.scheduledAt) }}</td>
              <td><span class="ar-ann__net">{{ networkWord(row.network) }}</span></td>
              <td>
                <div class="ar-ann__post">{{ row.postTitle }}</div>
                <div class="ar-ann__body">{{ row.body.length > 120 ? row.body.slice(0, 120) + '…' : row.body }}</div>
              </td>
              <td>
                <template v-if="row.status === 'queued'">
                  <span class="ar-ann__st is-queued">Queued</span>
                  <div class="ar-ann__why">Goes out {{ dueIn(row.scheduledAt) }}.</div>
                  <div class="ar-ann__acts">
                    <button type="button" class="ar-btn ar-btn--ghost" :disabled="acting === row.id" @click="act('cancel', row.id)">Cancel</button>
                  </div>
                </template>
                <template v-else-if="row.status === 'sent'">
                  <span class="ar-ann__st is-sent">Sent</span>
                  <div class="ar-ann__why">{{ ago(row.sentAt) }} — {{ stamp(row.sentAt) }}</div>
                </template>
                <template v-else>
                  <span class="ar-ann__st is-failed">Didn’t go</span>
                  <div class="ar-ann__why">{{ row.error }}</div>
                  <div class="ar-ann__acts">
                    <button type="button" class="ar-btn" :disabled="acting === row.id" @click="act('retry', row.id)">Try again</button>
                    <button type="button" class="ar-btn ar-btn--ghost" :disabled="acting === row.id" @click="act('remove', row.id)">Remove</button>
                  </div>
                </template>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- The ledger's one caveat, in the worth-knowing gold (the shared
             .ar-aud__note grammar): why a Sent stamp can differ from the
             promise above it. -->
        <div class="ar-aud__note">
          <p class="ar-aud__note-t">
            <span class="ar-aud__note-i" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.8a6.6 6.6 0 0 0-3.9 11.9c.8.6 1.3 1.5 1.3 2.4h5.2c0-.9.5-1.8 1.3-2.4A6.6 6.6 0 0 0 12 2.8z" /><path d="M9.9 20h4.2" /></svg>
            </span>
            Worth knowing
          </p>
          <div class="ar-aud__note-cols">
            <span class="ar-aud__note-facts">
              <span class="ar-aud__note-item">A post that misses its minute — a sleeping site can — goes out on the site’s next visit, and Sent records when it really went, not when it was meant.</span>
            </span>
          </div>
        </div>

        <div class="ar-ann__foot">
          <span class="ar-ann__count">Showing {{ pageStart }}–{{ pageEnd }} of {{ total }}</span>
          <div class="ar-ann__pager">
            <button type="button" class="ar-btn ar-btn--ghost" :disabled="!hasNewer || loading" @click="load(page - 1)">Newer</button>
            <button type="button" class="ar-btn ar-btn--ghost" :disabled="!hasOlder || loading" @click="load(page + 1)">Older</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
