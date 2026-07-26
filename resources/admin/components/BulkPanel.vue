<script>
/**
 * Fill The Gaps — the transparent scan-and-fix screen for the per-page AI
 * fields (description, topics, image alt text).
 *
 * The model, in the order the owner experiences it:
 *   1. SCAN IS AUTOMATIC AND FREE. Finding gaps is plain SQL against the site's
 *      own tables, so every page missing each piece is listed below the moment
 *      the screen opens — before any AI is involved, on sites with no AI at all.
 *   2. DRAFTS ARE MADE ONLY ON REQUEST, for the exact items picked — a row's
 *      Create Draft button, or several ticked rows at once. Each draft is one
 *      AI request to the owner's provider, and the screen says so.
 *   3. NOTHING GOES LIVE UNAPPROVED. A draft sits in its row until the owner
 *      clicks Apply (writes the real field) or Dismiss (deletes the draft).
 *      A value the author wrote themselves is never touched — the gap-only law,
 *      re-checked at apply time.
 */
export default {
  name: 'BulkPanel',
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show; fetch on first reveal, refresh quietly on return.
    active: { type: Boolean, default: false },
  },
  emits: ['flash', 'changed'],
  data() {
    return {
      overview: null, // { fields: {id: {enabled, missing, proposed}}, ai, batchSize, pageSize }
      loaded: false,
      error: '',
      running: '', // field id mid-draft ('' = idle)
      cancelled: false,
      progress: { done: 0, target: 0 },
      runErrors: [], // last run's per-item failures [{id,title,message}]
      vision: true, // false once the provider proves it can't read images
      lists: {}, // field id → { rows, total, pages, page, loading }
      checked: {}, // field id → { rowId: true } — ticks are for acting on several at once
      busy: '', // non-empty while an apply/dismiss is in flight
      scanning: false, // the header re-scan spinner
    };
  },
  computed: {
    fieldDefs() {
      return [
        {
          id: 'description',
          title: 'AI Descriptions',
          thing: 'a description of their own',
          what: 'One clear sentence saying what a page is about. It feeds the page’s structured data, its plain-text twin for assistants, and the description shown in results.',
        },
        {
          id: 'topics',
          title: 'Topics',
          thing: 'their own topic list',
          what: 'Short keywords naming what a page covers. They ride the page’s structured data and markdown so assistants can classify it correctly.',
        },
        {
          id: 'alt',
          title: 'Image Alt Text',
          thing: 'alt text',
          what: 'A sentence describing what an image shows, for anyone who can’t see it — screen readers and AI assistants alike. Covers the images your published pages actually use: their featured images and attachments.',
          note: 'This fills the media library’s alt field, so featured images, cards and future insertions are covered. Alt text already baked into an old page’s own markup isn’t touched — that’s a content edit only you should make.',
        },
      ];
    },
    ai() {
      return !!(this.overview && this.overview.ai);
    },
  },
  watch: {
    active(now) {
      if (now) this.load();
    },
    // Insurance against boot-order races: if the api lands after mount while this
    // screen is already showing, load then — a screen that stays silently empty
    // because of a race is the one thing this panel must not do.
    api(now) {
      if (now && this.active && !this.loaded) this.load();
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    field(id) {
      return (this.overview && this.overview.fields && this.overview.fields[id]) || null;
    },
    list(id) {
      return this.lists[id] || { rows: [], total: 0, pages: 0, page: 1, loading: false };
    },
    async load() {
      if (!this.api) return;
      try {
        this.overview = await this.api.getBulkOverview();
        this.loaded = true;
        this.error = '';
        // The scan list is the screen — every enabled field lists its gaps up
        // front (finding them is free; only DRAFTING costs anything).
        this.fieldDefs.forEach((f) => {
          const state = this.field(f.id);
          if (state && state.enabled && state.missing > 0) {
            this.loadItems(f.id, this.list(f.id).page || 1);
          }
        });
      } catch (e) {
        this.error = e.message || 'Couldn’t load.';
      }
    },
    async rescan() {
      // Re-runs the same automatic scan, for the owner who changed content in
      // another tab. It always ANSWERS: when nothing changed, a silent success
      // is indistinguishable from a dead button.
      this.scanning = true;
      await this.load();
      this.scanning = false;
      if (!this.error) {
        this.$emit('flash', 'success', 'Re-scanned — every count and list on this screen is current.');
      }
    },
    async loadItems(id, page) {
      const current = this.list(id);
      this.lists = { ...this.lists, [id]: { ...current, loading: true } };
      try {
        const res = await this.api.getBulkItems(id, page);
        this.lists = {
          ...this.lists,
          [id]: { rows: res.rows, total: res.total, pages: res.pages, page, loading: false },
        };
        this.checked = { ...this.checked, [id]: {} };
      } catch (e) {
        this.lists = { ...this.lists, [id]: { ...current, loading: false } };
        this.$emit('flash', 'error', e.message || 'Couldn’t load the list.');
      }
    },

    /* ------------------------------ ticks ------------------------------ */

    isChecked(id, rowId) {
      return !!(this.checked[id] && this.checked[id][rowId]);
    },
    toggleRow(id, rowId, on) {
      this.checked = { ...this.checked, [id]: { ...(this.checked[id] || {}), [rowId]: on } };
    },
    toggleAll(id, on) {
      const rows = this.list(id).rows;
      this.checked = { ...this.checked, [id]: Object.fromEntries(rows.map((r) => [r.id, on])) };
    },
    allChecked(id) {
      const rows = this.list(id).rows;
      return rows.length > 0 && rows.every((r) => this.isChecked(id, r.id));
    },
    tickedRows(id) {
      return this.list(id).rows.filter((r) => this.isChecked(id, r.id));
    },
    // The ticked rows each bulk button can actually act on: Create Draft wants
    // undrafted rows, Apply/Dismiss want drafted ones.
    tickedUndrafted(id) {
      return this.tickedRows(id).filter((r) => null === r.proposed).map((r) => r.id);
    },
    tickedDrafted(id) {
      return this.tickedRows(id).filter((r) => null !== r.proposed).map((r) => r.id);
    },

    /* ------------------------------ drafting ------------------------------ */

    async createDrafts(id, ids) {
      if (this.running || !this.overview || !ids.length) return;
      this.running = id;
      this.cancelled = false;
      this.runErrors = [];
      this.progress = { done: 0, target: ids.length };

      try {
        for (let i = 0; i < ids.length && !this.cancelled; i += this.overview.batchSize) {
          const res = await this.api.bulkGenerate(id, ids.slice(i, i + this.overview.batchSize));
          this.progress.done += res.generated.length;
          this.mergeRows(id, res.generated);
          if (res.errors.length) this.runErrors.push(...res.errors);
          if ('alt' === id && !res.vision) {
            this.vision = false;
            break;
          }
        }
      } catch (e) {
        this.$emit('flash', 'error', e.message || 'Drafting stopped — please try again.');
      }

      this.running = '';
      await this.refreshCounts();
      if (this.progress.done > 0) {
        const failedNote = this.runErrors.length
          ? ` ${this.runErrors.length} couldn’t be drafted — details in the section, drafting them again usually works.`
          : '';
        this.$emit('flash', 'success', `Drafted ${this.progress.done} — review each one, then Apply or Dismiss.${failedNote}`);
      } else if (this.runErrors.length) {
        this.$emit('flash', 'error', 'Nothing could be drafted this time — details in the section. Usually a momentary provider hiccup; try again in a minute.');
      }
    },
    cancel() {
      // Finishes the in-flight batch, then stops — a paid call is never abandoned midway.
      this.cancelled = true;
    },
    // Fold freshly drafted rows back into the visible list in place — the page
    // doesn't jump, the draft just appears in its row.
    mergeRows(id, generated) {
      if (!generated.length) return;
      const byId = Object.fromEntries(generated.map((r) => [r.id, r]));
      const current = this.list(id);
      this.lists = {
        ...this.lists,
        [id]: { ...current, rows: current.rows.map((r) => byId[r.id] || r) },
      };
    },

    /* ------------------------------ apply / dismiss ------------------------------ */

    async act(id, ids, action) {
      if (!ids.length) return;
      this.busy = `${id}:${action}`;
      try {
        const res = action === 'apply' ? await this.api.bulkApply(id, ids) : await this.api.bulkReject(id, ids);
        if (action === 'apply') {
          let msg = res.applied === 1 ? 'Applied — it’s live on the page now.' : `Applied ${res.applied}.`;
          if (res.skipped > 0) {
            msg += ` Skipped ${res.skipped} where you’d already filled the field yourself — your words win.`;
          }
          this.$emit('flash', 'success', msg);
          this.$emit('changed');
        }
        this.overview = { ...this.overview, fields: { ...this.overview.fields, ...res.counts } };
        // Applied rows leave the missing list; dismissed rows stay, back to undrafted.
        await this.loadItems(id, this.list(id).page);
      } catch (e) {
        this.$emit('flash', 'error', e.message || 'That didn’t save — please try again.');
      }
      this.busy = '';
    },
    async refreshCounts() {
      try {
        const res = await this.api.getBulkOverview();
        this.overview = res;
      } catch (e) {
        /* counts refresh is best-effort; the lists are already correct */
      }
    },
    proposedText(id, row) {
      return 'topics' === id && Array.isArray(row.proposed) ? row.proposed.join(', ') : row.proposed;
    },
  },
};
</script>

<template>
  <div class="ar-fg">
    <section class="ar-card">
      <div class="ar-card__titlewrap">
        <h2 class="ar-card__title">Fill The Gaps</h2>
        <button
          type="button"
          class="ar-readiness__refresh"
          :class="{ 'is-busy': scanning }"
          :disabled="scanning"
          aria-label="Re-scan the site for gaps"
          title="Re-scan the site for gaps"
          @click="rescan"
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>
        </button>
      </div>
      <p class="ar-fg__lead">
        Some pages are missing the small pieces that help AI assistants read, classify and cite
        them. Each section below covers one of those pieces — and works the same way:
      </p>
      <ol class="ar-fg__steps">
        <li>
          <strong>Scan.</strong> Automatic and free — every page missing each piece is already
          listed below. Finding gaps reads your own database; no AI is involved.
        </li>
        <li>
          <strong>Create drafts.</strong> Only for the items you pick — a row’s own button, or
          tick several and create them together. Each draft is one AI request to the provider
          you connected.
        </li>
        <li>
          <strong>Apply or Dismiss.</strong> Apply saves a draft to its page; Dismiss deletes
          it. Nothing is saved without your click, and anything you wrote yourself is never
          touched.
        </li>
      </ol>
      <p v-if="error" class="ar-fg__error">{{ error }}</p>
      <p v-if="!loaded && !error" class="ar-fg__muted ar-fg__scanning">Scanning the site for gaps…</p>
      <div v-if="loaded && !ai" class="ar-fg__state ar-fg__state--warn">
        <strong>No AI provider is connected.</strong>
        The gap lists below are real, but drafting needs a provider — add one under
        Settings&nbsp;→&nbsp;AI, then come back.
      </div>
    </section>

    <section v-for="f in fieldDefs" :key="f.id" class="ar-card ar-fg__field">
      <div class="ar-card__head ar-card__head--inline">
        <div class="ar-card__titlewrap">
          <h2 class="ar-card__title">{{ f.title }}</h2>
        </div>
      </div>
      <p class="ar-fg__what">{{ f.what }}</p>
      <p v-if="f.note" class="ar-fg__note">{{ f.note }}</p>

      <p v-if="!field(f.id)" class="ar-fg__muted">{{ error ? 'Couldn’t scan — reload to try again.' : 'Scanning…' }}</p>
      <template v-if="field(f.id)">
        <p v-if="!field(f.id).enabled" class="ar-fg__muted">Turned off in Settings.</p>
        <template v-else>
          <p class="ar-fg__count">
            <template v-if="field(f.id).missing > 0">
              <strong>{{ field(f.id).missing }}</strong>
              {{ field(f.id).missing === 1 ? ('alt' === f.id ? 'image is' : 'page is') : ('alt' === f.id ? 'images are' : 'pages are') }}
              missing {{ f.thing }}.
              <template v-if="field(f.id).proposed > 0">
                {{ field(f.id).proposed }} of them already {{ field(f.id).proposed === 1 ? 'has' : 'have' }} a draft ready to review.
              </template>
            </template>
            <template v-else>
              Nothing missing — {{ 'alt' === f.id ? 'every image your pages use has alt text' : `every page has ${f.thing}` }}. ✓
            </template>
          </p>

          <div v-if="'alt' === f.id && !vision" class="ar-fg__state ar-fg__state--warn">
            Your AI provider can’t read images, so alt text can’t be drafted — connect a
            vision-capable model under Settings&nbsp;→&nbsp;AI.
          </div>

          <div v-if="field(f.id).missing > 0" class="ar-fg__listwrap">
            <div class="ar-fg__bulkbar">
              <label class="ar-fg__check">
                <input
                  type="checkbox"
                  :checked="allChecked(f.id)"
                  @change="toggleAll(f.id, $event.target.checked)"
                />
                <span class="ar-fg__checklabel">All on this page</span>
              </label>

              <template v-if="running !== f.id">
                <div class="ar-fg__bulkactions">
                  <span v-if="tickedUndrafted(f.id).length" class="ar-fg__estimate">
                    = {{ tickedUndrafted(f.id).length }} AI request{{ tickedUndrafted(f.id).length === 1 ? '' : 's' }}
                  </span>
                  <button
                    v-if="ai && !('alt' === f.id && !vision)"
                    type="button"
                    class="ar-btn ar-btn--sm"
                    :disabled="!!running || busy !== '' || tickedUndrafted(f.id).length === 0"
                    @click="createDrafts(f.id, tickedUndrafted(f.id))"
                  >
                    Create {{ tickedUndrafted(f.id).length || '' }} draft{{ tickedUndrafted(f.id).length === 1 ? '' : 's' }}
                  </button>
                  <button
                    type="button"
                    class="ar-btn ar-btn--sm"
                    :disabled="busy !== '' || tickedDrafted(f.id).length === 0"
                    @click="act(f.id, tickedDrafted(f.id), 'apply')"
                  >
                    Apply {{ tickedDrafted(f.id).length || '' }}
                  </button>
                  <button
                    type="button"
                    class="ar-btn ar-btn--ghost ar-btn--sm"
                    :disabled="busy !== '' || tickedDrafted(f.id).length === 0"
                    @click="act(f.id, tickedDrafted(f.id), 'reject')"
                  >
                    Dismiss
                  </button>
                </div>
              </template>
              <template v-else>
                <div class="ar-fg__bulkactions">
                  <span class="ar-fg__progress" role="status">
                    Drafting… {{ progress.done }} of {{ progress.target }}
                  </span>
                  <button type="button" class="ar-btn ar-btn--ghost ar-btn--sm" @click="cancel">
                    {{ cancelled ? 'Stopping…' : 'Stop after this one' }}
                  </button>
                </div>
              </template>
            </div>

            <div v-if="running !== f.id && runErrors.length" class="ar-fg__state ar-fg__state--warn">
              <strong>
                {{ runErrors.length === 1 ? 'One item couldn’t be drafted' : `${runErrors.length} items couldn’t be drafted` }}
              </strong>
              — usually a momentary provider hiccup; drafting them again usually works.
              <ul class="ar-fg__errors">
                <li v-for="e in runErrors" :key="e.id">
                  <strong>{{ e.title || `#${e.id}` }}</strong> — {{ e.message }}
                </li>
              </ul>
            </div>

            <div v-if="list(f.id).loading && !list(f.id).rows.length" class="ar-fg__muted">Loading…</div>
            <ul v-else class="ar-fg__rows">
              <li v-for="row in list(f.id).rows" :key="row.id" class="ar-fg__row">
                <label class="ar-fg__check">
                  <input
                    type="checkbox"
                    :checked="isChecked(f.id, row.id)"
                    @change="toggleRow(f.id, row.id, $event.target.checked)"
                  />
                </label>
                <img
                  v-if="'alt' === f.id && row.thumb"
                  class="ar-fg__thumb"
                  :src="row.thumb"
                  alt=""
                />
                <div class="ar-fg__rowbody">
                  <a class="ar-fg__rowtitle" :href="row.editLink">{{ row.title || `#${row.id}` }}</a>
                  <p v-if="row.proposed !== null" class="ar-fg__proposed">{{ proposedText(f.id, row) }}</p>
                  <p v-else class="ar-fg__proposed ar-fg__proposed--none">No draft yet.</p>
                </div>
                <div class="ar-fg__rowactions">
                  <template v-if="row.proposed !== null">
                    <button
                      type="button"
                      class="ar-btn ar-btn--sm"
                      :disabled="busy !== '' || !!running"
                      @click="act(f.id, [row.id], 'apply')"
                    >
                      Apply
                    </button>
                    <button
                      type="button"
                      class="ar-btn ar-btn--ghost ar-btn--sm"
                      :disabled="busy !== '' || !!running"
                      @click="act(f.id, [row.id], 'reject')"
                    >
                      Dismiss
                    </button>
                  </template>
                  <button
                    v-else-if="ai && !('alt' === f.id && !vision)"
                    type="button"
                    class="ar-btn ar-btn--ghost ar-btn--sm ar-fg__createbtn"
                    :disabled="busy !== '' || !!running"
                    @click="createDrafts(f.id, [row.id])"
                  >
                    Create Draft
                  </button>
                </div>
              </li>
            </ul>

            <div v-if="list(f.id).pages > 1" class="ar-fg__pager">
              <button
                type="button"
                class="ar-btn ar-btn--ghost ar-btn--sm"
                :disabled="list(f.id).page <= 1 || list(f.id).loading"
                @click="loadItems(f.id, list(f.id).page - 1)"
              >
                Newer
              </button>
              <span class="ar-fg__muted">Page {{ list(f.id).page }} of {{ list(f.id).pages }}</span>
              <button
                type="button"
                class="ar-btn ar-btn--ghost ar-btn--sm"
                :disabled="list(f.id).page >= list(f.id).pages || list(f.id).loading"
                @click="loadItems(f.id, list(f.id).page + 1)"
              >
                Older
              </button>
            </div>
          </div>
        </template>
      </template>
    </section>
  </div>
</template>

<style>
.ar-fg__lead { margin: 0; color: var(--ar-ink-soft, #50575e); }
.ar-fg__steps { margin: 10px 0 0; padding-left: 1.3em; color: var(--ar-ink-soft, #50575e); }
.ar-fg__steps li { margin: 4px 0; line-height: 1.55; }
.ar-fg__steps strong { color: var(--ar-ink, #1d2327); }
.ar-fg__error { color: #d63638; }
.ar-fg__state { margin-top: 12px; padding: 10px 12px; border-radius: 6px; font-size: 13px; line-height: 1.55; }
.ar-fg__state--warn { background: #fcf9e8; border: 1px solid #f0e6bb; color: #6b5d1f; }
.ar-fg__what { margin: 0 0 4px; color: var(--ar-ink-soft, #50575e); }
.ar-fg__note { margin: 0 0 4px; font-size: 12px; color: var(--ar-ink-soft, #646970); }
.ar-fg__count { margin: 10px 0 0; }
.ar-fg__muted { color: var(--ar-ink-soft, #646970); font-size: 13px; }
.ar-fg__estimate { font-size: 12px; color: var(--ar-ink-soft, #646970); }
.ar-fg__progress { font-weight: 600; }
.ar-fg__errors { margin: 6px 0 0; padding: 0 0 0 1.2em; font-size: 12px; }
.ar-fg__listwrap { margin-top: 14px; border-top: 1px solid var(--ar-line, #e2e4e7); padding-top: 12px; }
.ar-fg__bulkbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 12px; }
.ar-fg__bulkactions { display: flex; align-items: center; gap: 6px; }
/* Row-sized take on the house button — same voice, quieter volume. */
.ar-fg .ar-btn--sm { padding: 6px 14px; font-size: 11px; }
.ar-fg__rows { list-style: none; margin: 10px 0 0; padding: 0; }
.ar-fg__row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--ar-line, #f0f0f1); }
.ar-fg__row:last-child { border-bottom: 0; }
.ar-fg__check { display: inline-flex; align-items: center; gap: 8px; flex: none; }
.ar-fg__check input[type='checkbox'] { margin: 0; }
.ar-fg__checklabel { font-size: 12px; color: var(--ar-ink-soft, #646970); }
.ar-fg__thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 4px; flex: none; }
.ar-fg__rowbody { min-width: 0; flex: 1; }
.ar-fg__rowtitle { font-weight: 600; text-decoration: none; }
.ar-fg__proposed { margin: 2px 0 0; color: var(--ar-ink-soft, #50575e); font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
.ar-fg__proposed--none { font-style: italic; color: var(--ar-ink-soft, #8c8f94); }
/* Every row's action area shares the same two fixed columns, so Apply and
   Dismiss line up edge-to-edge down the list; a lone Create Draft spans both. */
.ar-fg__rowactions { display: grid; grid-template-columns: 118px 96px; gap: 6px; flex: none; margin-left: auto; }
.ar-fg__rowactions .ar-btn { width: 100%; padding-left: 0; padding-right: 0; text-align: center; }
.ar-fg__createbtn { grid-column: 1 / -1; }
.ar-fg__pager { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
@media (max-width: 640px) {
  .ar-fg__row { flex-wrap: wrap; }
  .ar-fg__rowactions { width: 100%; margin-left: 0; }
}
</style>
