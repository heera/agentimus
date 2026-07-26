<script>
/**
 * Fill the gaps — site-wide backfill of the per-page AI fields (description,
 * topics, image alt text), as a draft → review → approve pipeline.
 *
 * Three promises this screen keeps, in order of importance:
 *   1. NOTHING GOES LIVE UNAPPROVED. Drafts are parked as proposals; the owner
 *      reads each one and clicks Use (or Dismiss). "Use all" exists, but it is
 *      the owner's click, not the machine's.
 *   2. THE GAP-ONLY LAW. Only empty fields are filled. A value the author wrote
 *      — even one written while a proposal sat here waiting — always wins.
 *   3. HONEST COST. Every item is one AI request against the owner's provider,
 *      and the screen says so before the first request fires. Runs are capped
 *      and chunked ("draft the next 25, then come back"), never open-ended.
 *
 * The client is the loop: it fires small batches (the server clamps each one)
 * until the run cap, an empty pick, or Cancel. Items that failed this run ride
 * an exclude list so the next batch can't pick — and pay for — them again.
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
      overview: null, // { fields: {id: {enabled, missing, proposed}}, ai, runCap, batchSize }
      loaded: false,
      error: '',
      running: '', // field id mid-run ('' = idle)
      cancelled: false,
      progress: { done: 0, target: 0 },
      runErrors: [], // last run's per-item failures [{id,title,message}]
      vision: true, // false once the provider proves it can't read images
      lists: {}, // field id → { rows, total, pages, page, loading }
      busy: '', // '<field>:<id>' | '<field>:all' while an apply/dismiss is in flight
    };
  },
  computed: {
    fieldDefs() {
      return [
        {
          id: 'description',
          title: 'AI descriptions',
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
          title: 'Image alt text',
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
      if (now) this.load(!this.loaded);
    },
  },
  mounted() {
    if (this.active) this.load(true);
  },
  methods: {
    field(id) {
      return (this.overview && this.overview.fields && this.overview.fields[id]) || null;
    },
    list(id) {
      return this.lists[id] || { rows: [], total: 0, pages: 0, page: 1, loading: false };
    },
    async load(first) {
      if (!this.api) return;
      try {
        this.overview = await this.api.getBulkOverview();
        this.loaded = true;
        this.error = '';
        // Any field with proposals waiting gets its review list fetched up front —
        // the whole point of coming back to this screen is what's waiting here.
        this.fieldDefs.forEach((f) => {
          const state = this.field(f.id);
          if (state && state.proposed > 0) this.loadList(f.id, 1);
          else if (!first) this.lists[f.id] = undefined;
        });
      } catch (e) {
        this.error = e.message || 'Couldn’t load.';
      }
    },
    async loadList(id, page) {
      const current = this.list(id);
      this.lists = { ...this.lists, [id]: { ...current, loading: true } };
      try {
        const res = await this.api.getBulkProposals(id, page);
        this.lists = {
          ...this.lists,
          [id]: { rows: res.rows, total: res.total, pages: res.pages, page, loading: false },
        };
      } catch (e) {
        this.lists = { ...this.lists, [id]: { ...current, loading: false } };
        this.$emit('flash', 'error', e.message || 'Couldn’t load the review list.');
      }
    },
    runTarget(id) {
      const state = this.field(id);
      if (!state || !this.overview) return 0;
      return Math.min(this.overview.runCap, state.missing);
    },
    async run(id) {
      if (this.running || !this.overview) return;
      const target = this.runTarget(id);
      if (!target) return;

      this.running = id;
      this.cancelled = false;
      this.runErrors = [];
      this.progress = { done: 0, target };
      const failed = [];

      try {
        while (!this.cancelled && this.progress.done < target) {
          const left = target - this.progress.done;
          const res = await this.api.bulkGenerate(id, Math.min(this.overview.batchSize, left), failed);
          this.progress.done += res.generated.length;
          if (res.errors.length) {
            this.runErrors.push(...res.errors);
            res.errors.forEach((e) => failed.push(e.id));
          }
          if ('alt' === id && !res.vision) {
            this.vision = false;
            break;
          }
          // An empty pick means there is nothing left this run can reach.
          if (!res.generated.length && !res.errors.length) break;
        }
      } catch (e) {
        this.$emit('flash', 'error', e.message || 'Drafting stopped — please try again.');
      }

      this.running = '';
      await this.load(false);
      const state = this.field(id);
      if (this.progress.done > 0) {
        const more = state && state.missing > 0 ? ` ${state.missing} still missing — run it again when you’re ready.` : '';
        this.$emit('flash', 'success', `Drafted ${this.progress.done} — every draft is below, waiting for your OK.${more}`);
      } else if (!this.runErrors.length && !this.cancelled) {
        this.$emit('flash', 'success', 'Nothing to draft right now.');
      }
    },
    cancel() {
      // Finishes the in-flight batch, then stops — a paid call is never abandoned midway.
      this.cancelled = true;
    },
    async act(id, ids, action) {
      const key = `${id}:${ids.length === 1 ? ids[0] : 'all'}`;
      this.busy = key;
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
        await this.loadList(id, 1);
      } catch (e) {
        this.$emit('flash', 'error', e.message || 'That didn’t save — please try again.');
      }
      this.busy = '';
    },
    proposedText(id, row) {
      return 'topics' === id && Array.isArray(row.proposed) ? row.proposed.join(', ') : row.proposed;
    },
    minutesEstimate(id) {
      // Plain words, deliberately vague: provider speed varies ~20×. The honest
      // constant is the request count, so that leads.
      const n = this.runTarget(id);
      return n === 1
        ? 'That’s one AI request.'
        : `That’s ${n} AI requests — usually a few minutes. Keep this tab open while it runs.`;
    },
  },
};
</script>

<template>
  <div class="ar-fg">
    <section class="ar-card">
      <h2 class="ar-card__title">Fill the gaps</h2>
      <p class="ar-fg__lead">
        Some pages are missing the small pieces that help AI assistants read, classify and cite
        them. This screen finds every gap on the site, asks your connected AI to draft what’s
        missing, and lines the drafts up here for your OK. Nothing goes live until you approve
        it — and a value you wrote yourself is never touched.
      </p>
      <p v-if="error" class="ar-fg__error">{{ error }}</p>
      <div v-if="loaded && !ai" class="ar-fg__state ar-fg__state--warn">
        <strong>No AI provider is connected.</strong>
        The gap counts below are real, but drafting needs a provider — add one under
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

      <template v-if="field(f.id)">
        <p v-if="!field(f.id).enabled" class="ar-fg__muted">Turned off in Settings.</p>
        <template v-else>
          <p class="ar-fg__count">
            <template v-if="field(f.id).missing > 0">
              <strong>{{ field(f.id).missing }}</strong>
              {{ field(f.id).missing === 1 ? ('alt' === f.id ? 'image is' : 'page is') : ('alt' === f.id ? 'images are' : 'pages are') }}
              missing {{ f.thing }}.
            </template>
            <template v-else-if="field(f.id).proposed === 0">
              Nothing missing — {{ 'alt' === f.id ? 'every image your pages use has alt text' : `every page has ${f.thing}` }}. ✓
            </template>
          </p>

          <div v-if="'alt' === f.id && !vision" class="ar-fg__state ar-fg__state--warn">
            Your AI provider can’t read images, so alt text can’t be drafted — connect a
            vision-capable model under Settings&nbsp;→&nbsp;AI.
          </div>

          <div v-else-if="ai && field(f.id).missing > 0" class="ar-fg__runrow">
            <template v-if="running !== f.id">
              <button
                type="button"
                class="button button-primary"
                :disabled="!!running"
                @click="run(f.id)"
              >
                Draft the next {{ runTarget(f.id) }} with AI
              </button>
              <span class="ar-fg__estimate">{{ minutesEstimate(f.id) }}</span>
            </template>
            <template v-else>
              <span class="ar-fg__progress" role="status">
                Drafting… {{ progress.done }} of {{ progress.target }}
              </span>
              <button type="button" class="button" @click="cancel">
                {{ cancelled ? 'Stopping…' : 'Stop after this one' }}
              </button>
            </template>
          </div>

          <ul v-if="running !== f.id && runErrors.length && lists[f.id]" class="ar-fg__errors">
            <li v-for="e in runErrors" :key="e.id">
              <strong>{{ e.title || `#${e.id}` }}</strong> — {{ e.message }}
            </li>
          </ul>

          <div v-if="field(f.id).proposed > 0" class="ar-fg__review">
            <div class="ar-fg__reviewhead">
              <h3 class="ar-fg__reviewtitle">
                {{ field(f.id).proposed }} draft{{ field(f.id).proposed === 1 ? '' : 's' }} waiting for your OK
              </h3>
              <div class="ar-fg__reviewactions">
                <button
                  type="button"
                  class="button"
                  :disabled="busy !== ''"
                  @click="act(f.id, [], 'apply')"
                >
                  Use all
                </button>
                <button
                  type="button"
                  class="button ar-fg__ghost"
                  :disabled="busy !== ''"
                  @click="act(f.id, [], 'reject')"
                >
                  Dismiss all
                </button>
              </div>
            </div>

            <div v-if="list(f.id).loading && !list(f.id).rows.length" class="ar-fg__muted">Loading…</div>
            <ul v-else class="ar-fg__rows">
              <li v-for="row in list(f.id).rows" :key="row.id" class="ar-fg__row">
                <img
                  v-if="'alt' === f.id && row.thumb"
                  class="ar-fg__thumb"
                  :src="row.thumb"
                  alt=""
                />
                <div class="ar-fg__rowbody">
                  <a class="ar-fg__rowtitle" :href="row.editLink">{{ row.title || `#${row.id}` }}</a>
                  <p class="ar-fg__proposed">{{ proposedText(f.id, row) }}</p>
                </div>
                <div class="ar-fg__rowactions">
                  <button
                    type="button"
                    class="button button-small"
                    :disabled="busy !== ''"
                    @click="act(f.id, [row.id], 'apply')"
                  >
                    Use
                  </button>
                  <button
                    type="button"
                    class="button button-small ar-fg__ghost"
                    :disabled="busy !== ''"
                    @click="act(f.id, [row.id], 'reject')"
                  >
                    Dismiss
                  </button>
                </div>
              </li>
            </ul>

            <div v-if="list(f.id).pages > 1" class="ar-fg__pager">
              <button
                type="button"
                class="button button-small"
                :disabled="list(f.id).page <= 1 || list(f.id).loading"
                @click="loadList(f.id, list(f.id).page - 1)"
              >
                Newer
              </button>
              <span class="ar-fg__muted">Page {{ list(f.id).page }} of {{ list(f.id).pages }}</span>
              <button
                type="button"
                class="button button-small"
                :disabled="list(f.id).page >= list(f.id).pages || list(f.id).loading"
                @click="loadList(f.id, list(f.id).page + 1)"
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
.ar-fg__lead { margin: 0; color: var(--ar-ink-soft, #50575e); max-width: 66ch; }
.ar-fg__error { color: #d63638; }
.ar-fg__state { margin-top: 12px; padding: 10px 12px; border-radius: 6px; font-size: 13px; line-height: 1.55; }
.ar-fg__state--warn { background: #fcf9e8; border: 1px solid #f0e6bb; color: #6b5d1f; }
.ar-fg__what { margin: 0 0 4px; color: var(--ar-ink-soft, #50575e); max-width: 66ch; }
.ar-fg__note { margin: 0 0 4px; font-size: 12px; color: var(--ar-ink-soft, #646970); max-width: 66ch; }
.ar-fg__count { margin: 10px 0 0; }
.ar-fg__muted { color: var(--ar-ink-soft, #646970); font-size: 13px; }
.ar-fg__runrow { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 10px; }
.ar-fg__estimate { font-size: 12px; color: var(--ar-ink-soft, #646970); }
.ar-fg__progress { font-weight: 600; }
.ar-fg__errors { margin: 10px 0 0; padding: 0 0 0 1.2em; font-size: 12px; color: var(--ar-ink-soft, #646970); }
.ar-fg__review { margin-top: 16px; border-top: 1px solid var(--ar-line, #e2e4e7); padding-top: 12px; }
.ar-fg__reviewhead { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
.ar-fg__reviewtitle { margin: 0; font-size: 13.5px; }
.ar-fg__reviewactions { display: flex; gap: 6px; }
.ar-fg__rows { list-style: none; margin: 10px 0 0; padding: 0; }
.ar-fg__row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--ar-line, #f0f0f1); }
.ar-fg__row:last-child { border-bottom: 0; }
.ar-fg__thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 4px; flex: none; }
.ar-fg__rowbody { min-width: 0; flex: 1; }
.ar-fg__rowtitle { font-weight: 600; text-decoration: none; }
.ar-fg__proposed { margin: 2px 0 0; color: var(--ar-ink-soft, #50575e); font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
.ar-fg__rowactions { display: flex; gap: 6px; flex: none; }
.ar-fg__pager { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
@media (max-width: 640px) {
  .ar-fg__row { flex-wrap: wrap; }
  .ar-fg__rowactions { width: 100%; justify-content: flex-end; }
}
</style>
