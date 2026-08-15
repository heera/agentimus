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
import BrandMark from '../BrandMark.vue';
import { formatStamp } from '../../js/wpDate.js';
import { confirm } from '../../js/confirm.js';
import { bindDocEsc } from '../../js/docEsc.js';

export default {
  name: 'AnnouncementsPanel',
  components: { CardSkeleton, BrandMark },
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
      preview: null, // The row whose draft is open in the preview dialog.
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
    // Esc closes the preview, like every other dialog in the app.
    preview(open) {
      if (this._unEsc) this._unEsc();
      this._unEsc = open ? bindDocEsc(() => { this.preview = null; }) : null;
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  beforeUnmount() {
    this.stopPoll();
    if (this._unEsc) this._unEsc();
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
    // The two hands that cannot be taken back ask first — cancelling drops the
    // promise from the record, removing drops the failure from it, and neither
    // is undoable from this screen. Try again is not asked: it only re-queues,
    // and Cancel is right there beside it.
    async askCancel(id) {
      const ok = await confirm({
        title: 'Cancel this announcement?',
        message:
          'It will not be posted, and the row leaves this record — a promise that never happened is not history. You can schedule it again from the Share tab.',
        confirmLabel: 'Cancel it',
        cancelLabel: 'Keep it',
        tone: 'danger',
      });
      if (ok) this.act('cancel', id);
    },
    async askRemove(id) {
      const ok = await confirm({
        title: 'Remove this from the record?',
        message: 'The failed announcement leaves this record for good. Nothing was posted, and removing it does not post it.',
        confirmLabel: 'Remove',
        cancelLabel: 'Keep it',
        tone: 'danger',
      });
      if (ok) this.act('remove', id);
    },
    async act(action, id) {
      if (!this.api || this.acting) return;
      // A row about to change is not a row to keep previewing.
      this.preview = null;
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
    // "in 25m / in 11h / in 3d" — the promise's distance, for the rows that
    // haven't happened yet. The finished rows carry a real stamp instead.
    dueIn(seconds) {
      const m = Math.round((seconds * 1000 - Date.now()) / 60000);
      if (m < 1) return 'with the next tick';
      if (m < 60) return `in ${m}m`;
      const h = Math.round(m / 60);
      if (h < 24) return `in ${h}h`;
      return `in ${Math.round(h / 24)}d`;
    },
    // The badge carries the whole name — no logo beside it to say half of it.
    networkWord(id) {
      return { telegram: 'Telegram', x: 'X (Twitter)', linkedin: 'LinkedIn' }[id] || id;
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
              <!-- "When" left the reader to guess which moment it meant. The
                   column holds the minute the owner aimed at — not when the
                   row was made, and not when it actually went (that is
                   Status's second line) — so it says so. -->
              <th class="ar-ann__whencol">Scheduled For</th>
              <!-- Not "Network": that is our word for the plumbing, not the
                   owner's word for where their post lands. -->
              <th class="ar-ann__netcol">Posted To</th>
              <!-- Not "Post": what gets announced is whatever type the site
                   enabled — a page, a product, any CPT. "Content" is the word
                   the wizard and the settings screen already use for the set. -->
              <th>Content</th>
              <th class="ar-ann__statuscol">Status</th>
              <!-- The hands get their own column, so they line up down the
                   edge instead of pushing every status line to a different
                   length. The header names the column for a screen reader;
                   on screen the buttons speak for themselves. -->
              <th class="ar-ann__actcol"><span class="screen-reader-text">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td class="ar-ann__when">{{ stamp(row.scheduledAt) }}</td>
              <!-- One badge, one width, whatever the network: a column of
                   equal badges reads as a column; a logo tile per row made it
                   a stack of objects. -->
              <td class="ar-ann__netcell">
                <span class="ar-ann__net">{{ networkWord(row.network) }}</span>
              </td>
              <!-- The title alone. The draft itself is one click away rather
                   than half-spilled under every row: an excerpt that stops
                   mid-word tells the owner less than the word "preview". -->
              <td>
                <button type="button" class="ar-ann__post" @click="preview = row">
                  <svg class="ar-ann__eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.2 12S6 5.4 12 5.4 21.8 12 21.8 12 18 18.6 12 18.6 2.2 12 2.2 12Z" /><circle cx="12" cy="12" r="3.1" /></svg>
                  <span>{{ row.postTitle }}</span>
                  <span class="screen-reader-text">— preview this announcement</span>
                </button>
              </td>
              <!-- Two lines: the state's word, then what it means underneath.
                   The hands live in the column beside it, so no state's
                   sentence has to make room for a button. -->
              <td class="ar-ann__statuscell">
                <div class="ar-ann__state">
                  <template v-if="row.status === 'queued'">
                    <span class="ar-ann__st is-queued">Queued</span>
                    <span class="ar-ann__why">Goes out {{ dueIn(row.scheduledAt) }}</span>
                  </template>
                  <template v-else-if="row.status === 'sent'">
                    <span class="ar-ann__st is-sent">Sent at</span>
                    <span class="ar-ann__why">{{ stamp(row.sentAt) }}</span>
                  </template>
                  <!-- The reason lives under an info mark: it is the network's
                       sentence, not ours, and it must not set the height of
                       every row around it. A row that failed before we
                       recorded the minute says so by having none. -->
                  <template v-else>
                    <span class="ar-ann__st is-failed">{{ row.failedAt ? 'Failed at' : 'Failed' }}</span>
                    <span class="ar-ann__why">
                      <span v-if="row.failedAt">{{ stamp(row.failedAt) }}</span>
                      <span v-if="row.error" class="ar-ann__tipwrap">
                        <button type="button" class="ar-ann__info" :aria-label="'Why it didn’t go: ' + row.error">
                          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.4" /><path d="M12 11.2v5.4" /><path d="M12 7.7h.01" /></svg>
                        </button>
                        <span class="ar-ann__tip" aria-hidden="true">{{ row.error }}</span>
                      </span>
                    </span>
                  </template>
                </div>
              </td>
              <td class="ar-ann__actcell">
                <div class="ar-ann__acts">
                  <button
                    v-if="row.status === 'queued'"
                    type="button" class="ar-ann__act is-stop"
                    :disabled="acting === row.id"
                    @click="askCancel(row.id)"
                  >Cancel</button>
                  <template v-if="row.status === 'failed'">
                    <button
                      type="button" class="ar-ann__act is-retry"
                      :disabled="acting === row.id"
                      @click="act('retry', row.id)"
                    >Try again</button>
                    <button
                      type="button" class="ar-ann__act is-drop"
                      :disabled="acting === row.id"
                      @click="askRemove(row.id)"
                    >Remove</button>
                  </template>
                </div>
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
              <span class="ar-aud__note-item">An announcement that misses its minute — a sleeping site can — goes out on the site’s next visit, and Sent records when it really went, not when it was meant.</span>
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

    <!-- The draft itself, whole: what was queued is what goes out, so the
         preview shows the body VERBATIM — no re-render, no trimming. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="preview" class="ar-modal" @click.self="preview = null">
          <div class="ar-modal__panel ar-annprev" role="dialog" aria-modal="true" aria-labelledby="ar-annprev-t">
            <div class="ar-modal__head">
              <h2 id="ar-annprev-t" class="ar-modal__title">{{ preview.postTitle }}</h2>
              <p class="ar-modal__lead ar-annprev__meta">
                <span class="ar-int__mark is-brand ar-annprev__mark" aria-hidden="true"><BrandMark :brand="preview.network" /></span>
                <span>{{ networkWord(preview.network) }}</span>
                <span class="ar-annprev__sep" aria-hidden="true">·</span>
                <span>{{ stamp(preview.scheduledAt) }}</span>
                <span class="ar-ann__st" :class="'is-' + (preview.status === 'failed' ? 'failed' : preview.status)">
                  {{ { queued: 'Queued', sent: 'Sent' }[preview.status] || 'Didn’t go' }}
                </span>
              </p>
            </div>
            <div class="ar-modal__scroll">
              <p class="ar-annprev__kicker">The message, exactly as it will be posted</p>
              <div class="ar-annprev__body">{{ preview.body }}</div>

              <!-- The image is NOT part of the message: the network builds it
                   from the linked page's own card. Same resolver the editor's
                   Share tab previews, so the two can never disagree — and
                   when the page has no card, the row says so rather than
                   showing a stand-in. -->
              <template v-if="preview.postId">
                <p class="ar-annprev__kicker">The card the link will show</p>
                <figure v-if="preview.image" class="ar-annprev__card">
                  <img :src="preview.image" :alt="preview.imageAlt" />
                  <figcaption v-if="preview.postUrl" class="ar-annprev__cardfoot">{{ preview.postUrl }}</figcaption>
                </figure>
                <p v-else class="ar-annprev__nocard">
                  This page has no card image — no featured image, and no site-wide default set.
                  The networks will show the link on its own.
                </p>
              </template>

              <p v-if="preview.status === 'failed' && preview.error" class="ar-annprev__err">{{ preview.error }}</p>
            </div>
            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" @click="preview = null">Close</button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>
  </div>
</template>
