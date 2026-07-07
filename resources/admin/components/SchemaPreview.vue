<script>
/**
 * JSON-LD preview — a modal launched from the Readiness tab. Shows the exact
 * structured data Agentimus would put in a page's HTML head, for the whole site
 * or any page/post the owner picks. The graph is built server-side by the same
 * Schema::build_document() the front end uses (agentimus/v1/preview/*), so the
 * preview always matches what ships.
 *
 * It previews even when the live output is off — schema disabled, or an SEO
 * plugin owns it — and says so in a banner, because "what WOULD I emit?" is the
 * whole point. The per-post privacy guard still applies: a draft or password-
 * protected post shows only the site-level identity, with a note explaining why.
 *
 * Reuses the app's .ar-modal shell (the same one as the Verify-live dialog) and
 * ar-btn / ar-input chrome; only the picker, code block and banners are custom.
 */
export default {
  name: 'SchemaPreview',
  props: {
    api: { type: Object, required: true },
    open: { type: Boolean, default: false },
  },
  emits: ['close', 'flash'],
  data() {
    return {
      busy: false,        // a fetch is in flight
      error: '',
      // Which format the viewer shows for the selected target.
      format: 'jsonld',   // 'jsonld' | 'markdown'
      result: null,       // JSON-LD: { target, graph, json, active, reason, seoPlugin, postIncluded, postNote, livePublic }
      mdResult: null,     // Markdown: { target, markdown, mdUrl, active, reason, postIncluded, postNote, livePublic }
      // The pickable content, grouped by post type and paginated per group (the
      // "Site" entry is rendered separately, pinned). Each group is
      // { type, label, source, total, items, offset, hasMore, loadingMore }.
      groups: [],
      targetsLoading: false,
      search: '',
      // Which post-type groups are collapsed, keyed by type slug. Empty = all open.
      collapsedGroups: {},
      // The currently-selected target: { type:'site'|<post_type>, id:Number }.
      selected: { type: 'site', id: 0 },
      copied: false,
      // Small-screen master-detail: at ≤782px the picker and viewer become two
      // full-width panes; `mobileView` slides between them. Ignored on wide screens
      // where both panes show side by side.
      isNarrow: false,
      mobileView: 'list', // 'list' | 'detail'
    };
  },
  computed: {
    // The @type of every node in the graph, flattened (a node's @type may be a
    // string or an array) — shown as chips so the shape reads at a glance.
    nodeTypes() {
      const graph = (this.result && this.result.graph && this.result.graph['@graph']) || [];
      const out = [];
      graph.forEach((node) => {
        const t = node && node['@type'];
        (Array.isArray(t) ? t : [t]).forEach((one) => {
          if (one) out.push(String(one));
        });
      });
      return out;
    },
    // The response for the currently-selected format — the source for every shared
    // field (target, status, postNote) so the viewer chrome is format-agnostic.
    res() {
      return this.format === 'markdown' ? this.mdResult : this.result;
    },
    copyLabel() {
      return this.format === 'markdown' ? 'Copy Markdown' : 'Copy JSON';
    },
    // The banner above the viewer: is this output actually live, and if not, why.
    statusBanner() {
      if (!this.res) return null;
      const md = this.format === 'markdown';
      if (this.res.reason === 'disabled') {
        return {
          tone: 'warn',
          text: md
            ? 'Markdown delivery is turned off in Settings → Features. This is a preview of what would be served if you switch it on.'
            : 'JSON-LD output is turned off in Settings → Features. This is a preview of what would ship if you switch it on.',
        };
      }
      if (this.res.reason === 'seo_plugin') {
        return {
          tone: 'info',
          text: 'An SEO plugin currently owns your schema, so Agentimus stands down to avoid duplicates. This is what Agentimus would emit instead.',
        };
      }
      // The reassuring "served/live" banner only fits a target actually emitting
      // live: markdown needs a real .md (postIncluded), and a JSON-LD draft preview
      // is a would-be node that isn't live yet (livePublic false). In those cases the
      // postNote carries the explanation instead.
      if (md && !this.res.postIncluded) return null;
      if (!md && !this.res.livePublic) return null;
      // The site target's markdown is the index served at the home URL and /llms.txt,
      // not a single page's .md — so its "served" line names where it actually lives.
      const isSite = !!(this.res.target && this.res.target.type === 'site');
      return {
        tone: 'good',
        text: md
          ? (isSite
            ? 'This is served at your home URL and at /llms.txt right now.'
            : 'This is served at the page’s .md URL right now.')
          : 'This is live in your page’s HTML head right now.',
      };
    },
    // A public, fetchable URL exists only for the site or a published (non-gated)
    // post — the only cases the URL-based validators can actually read. A draft's
    // would-be node is previewable but its URL 404s, so livePublic (not postIncluded)
    // is the right gate here.
    hasPublicUrl() {
      return !!(this.res && this.res.livePublic);
    },
    // The URL-based validators reflect the LIVE page, so they're only meaningful
    // when Agentimus is actually emitting there. Otherwise we steer to copy-paste.
    canTestLiveUrl() {
      return !!(this.res && this.res.active && this.hasPublicUrl);
    },
    liveUrl() {
      return (this.res && this.res.target && this.res.target.url) || '';
    },
    googleUrl() {
      return `https://search.google.com/test/rich-results?url=${encodeURIComponent(this.liveUrl)}`;
    },
    schemaOrgUrl() {
      return `https://validator.schema.org/#url=${encodeURIComponent(this.liveUrl)}`;
    },
    isEmpty() {
      if (!this.res) return false;
      return this.format === 'markdown'
        ? !this.res.markdown
        : (!this.res.json || this.res.json === '');
    },
    // Whether any group holds any item — drives the "No matching content" hint.
    hasAnyTarget() {
      return this.groups.some((g) => g.items.length > 0);
    },
  },
  watch: {
    open(on) {
      if (!on) return;
      // Always open FRESH — a reopen must never inherit the last pick, format,
      // search or expanded groups. Reset the chrome, reload the list (groups
      // collapsed for a compact overview) and preview the site-wide identity as JSON-LD.
      this.format = 'jsonld';
      this.search = '';
      this.error = '';
      this.copied = false;
      this.mobileView = 'list';
      this.collapsedGroups = {};
      this.loadTargets({ collapseAll: true });
      this.select({ type: 'site', id: 0 });
      // Land focus on the dialog so Esc closes it and it reads as modal.
      this.$nextTick(() => {
        if (this.$refs.dialog) this.$refs.dialog.focus();
      });
    },
  },
  mounted() {
    this.setupMedia();
  },
  beforeUnmount() {
    this.teardownMedia();
  },
  methods: {
    // Track the narrow breakpoint reactively so the layout follows a window resize
    // across it, not just the width at open.
    setupMedia() {
      if (typeof window === 'undefined' || !window.matchMedia) return;
      this._mq = window.matchMedia('(max-width: 782px)');
      this.isNarrow = this._mq.matches;
      this._mqHandler = (e) => { this.isNarrow = e.matches; };
      if (this._mq.addEventListener) this._mq.addEventListener('change', this._mqHandler);
      else if (this._mq.addListener) this._mq.addListener(this._mqHandler); // older Safari
    },
    teardownMedia() {
      if (!this._mq) return;
      if (this._mq.removeEventListener) this._mq.removeEventListener('change', this._mqHandler);
      else if (this._mq.removeListener) this._mq.removeListener(this._mqHandler);
    },
    close() {
      this.$emit('close');
    },
    // Whether a list row is the one being previewed.
    isSelected(t) {
      return this.selected.type === t.type && this.selected.id === t.id;
    },
    isCollapsed(type) {
      return !!this.collapsedGroups[type];
    },
    toggleGroup(type) {
      this.collapsedGroups[type] = !this.collapsedGroups[type];
    },
    async select(target) {
      this.selected = { type: target.type, id: target.id };
      // New target → both format caches are stale.
      this.result = null;
      this.mdResult = null;
      await this.loadActive();
    },
    // Fetch the active format for the selected target, unless it's already loaded.
    async loadActive() {
      if (this.format === 'jsonld' && this.result) return;
      if (this.format === 'markdown' && this.mdResult) return;
      this.busy = true;
      this.error = '';
      try {
        const id = this.selected.id || 0;
        if (this.format === 'markdown') {
          this.mdResult = await this.api.getMarkdownPreview(id);
        } else {
          this.result = await this.api.getSchemaPreview(id);
        }
      } catch (e) {
        this.error = (e && e.message) || 'Could not build the preview.';
      } finally {
        this.busy = false;
      }
    },
    setFormat(fmt) {
      if (this.format === fmt) return;
      this.format = fmt;
      this.error = '';
      this.copied = false;
      this.loadActive();
    },
    // A user pick (the Site button or a list row). Fetches the graph and, on the
    // narrow slide layout, slides to the detail pane and moves focus to its Back
    // button so keyboard/SR users land in the right place. On wide screens both
    // panes are visible, so it just updates the viewer.
    pick(target) {
      this.select(target);
      if (this.isNarrow) {
        this.mobileView = 'detail';
        this.$nextTick(() => {
          if (this.$refs.back) this.$refs.back.focus();
        });
      }
    },
    backToList() {
      this.mobileView = 'list';
      // Move focus off the detail pane (it slides off-screen) back into the list.
      this.$nextTick(() => {
        if (this.$refs.picker) this.$refs.picker.focus();
      });
    },
    // Normalize a server group into the shape the picker renders, adding the
    // client-only `loadingMore` flag its "Load more" button toggles.
    mapGroup(g) {
      return {
        type: g.type,
        label: g.typeLabel || g.type,
        source: g.typeSource || '',
        total: g.total || 0,
        items: g.items || [],
        offset: g.offset || 0,
        hasMore: !!g.hasMore,
        loadingMore: false,
      };
    },
    async loadTargets({ collapseAll = false } = {}) {
      this.targetsLoading = true;
      try {
        const res = await this.api.getSchemaTargets(this.search);
        this.groups = ((res && res.groups) || []).map((g) => this.mapGroup(g));
        if (collapseAll) {
          // Fresh open: start every group collapsed (just labels + counts).
          const collapsed = {};
          this.groups.forEach((g) => { collapsed[g.type] = true; });
          this.collapsedGroups = collapsed;
        }
      } catch (e) {
        this.$emit('flash', 'error', (e && e.message) || 'Could not load the page list.');
      } finally {
        this.targetsLoading = false;
      }
    },
    // Append the next batch of one type's items (the "Load more" button). Offsets by
    // what's already loaded and de-dupes by id, so a concurrent edit can't double a row.
    async loadMore(group) {
      if (group.loadingMore || !group.hasMore) return;
      group.loadingMore = true;
      try {
        const res = await this.api.getSchemaTargets(this.search, group.type, group.items.length);
        const next = ((res && res.groups) || []).find((g) => g.type === group.type);
        if (next) {
          const seen = new Set(group.items.map((i) => i.id));
          (next.items || []).forEach((it) => { if (!seen.has(it.id)) group.items.push(it); });
          if (typeof next.total === 'number') group.total = next.total;
          group.hasMore = !!next.hasMore && group.items.length < group.total;
        } else {
          group.hasMore = false;
        }
      } catch (e) {
        this.$emit('flash', 'error', (e && e.message) || 'Could not load more items.');
      } finally {
        group.loadingMore = false;
      }
    },
    // "Load more" copy — names how many of this type remain unloaded.
    remaining(group) {
      return Math.max(0, group.total - group.items.length);
    },
    // Debounce the search so we don't query on every keystroke. A search reveals
    // matches, so expand the groups — collapsed defaults would otherwise hide them.
    onSearch() {
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => {
        this.collapsedGroups = {};
        this.loadTargets();
      }, 250);
    },
    // A friendly status badge for a non-published pick in the list.
    statusBadge(status) {
      return { draft: 'Draft', pending: 'Pending', private: 'Private', future: 'Scheduled' }[status] || '';
    },
    async copy() {
      const text = this.format === 'markdown'
        ? ((this.mdResult && this.mdResult.markdown) || '')
        : ((this.result && this.result.json) || '');
      if (!text) return;
      let ok = false;
      // navigator.clipboard needs a secure context (HTTPS or localhost); on plain
      // HTTP it's absent or throws, so fall back to the legacy execCommand path.
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
          ok = true;
        }
      } catch (e) { /* fall through */ }
      if (!ok) ok = this.legacyCopy(text);
      if (ok) {
        this.copied = true;
        clearTimeout(this._copyTimer);
        this._copyTimer = setTimeout(() => { this.copied = false; }, 2000);
      } else {
        this.$emit('flash', 'error', 'Could not copy to the clipboard — select the code and copy manually.');
      }
    },
    // Copy via a throwaway off-screen textarea — works on HTTP where the async
    // Clipboard API is unavailable.
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
</script>

<template>
  <Teleport to="body">
    <transition name="ar-modal">
      <!-- No backdrop-click-to-close: the preview is a working surface, so a stray
           click outside the panel must not discard it. Close via the button or Esc. -->
      <div v-if="open" class="ar-modal">
        <div
          ref="dialog"
          class="ar-modal__panel agentimus-jsonld__panel"
          role="dialog"
          aria-modal="true"
          aria-labelledby="ar-jsonld-title"
          tabindex="-1"
          @keydown.esc="close"
        >
          <button type="button" class="agentimus-jsonld__close" @click="close" aria-label="Close preview" title="Close">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6 18 18M18 6 6 18" /></svg>
          </button>

          <div class="ar-modal__head">
            <h2 id="ar-jsonld-title" class="ar-modal__title">Agent preview</h2>
            <p class="ar-modal__lead">
              Exactly what an AI agent receives for a page — its JSON-LD structured data and its
              Markdown twin. Pick the whole site, or any page or post; it matches what ships.
            </p>
          </div>

          <div class="ar-modal__body">
            <div class="ar-modal__scroll">
              <div class="agentimus-jsonld" :class="{ 'show-detail': mobileView === 'detail' }">
                <!-- Picker: a search box on top, then the pinned Site entry and the page/post list. -->
                <aside ref="picker" class="agentimus-jsonld__picker" tabindex="-1">
                  <input
                    v-model="search"
                    type="search"
                    class="ar-input agentimus-jsonld__search"
                    placeholder="Search pages & posts…"
                    aria-label="Search pages and posts to preview"
                    @input="onSearch"
                  />

                  <button
                    type="button"
                    class="agentimus-jsonld__target agentimus-jsonld__target--site"
                    :class="{ 'is-selected': selected.type === 'site' }"
                    @click="pick({ type: 'site', id: 0 })"
                  >
                    <span class="agentimus-jsonld__target-label">Site-wide identity</span>
                    <span class="agentimus-jsonld__target-sub">WebSite · Person/Organization · Services</span>
                  </button>

                  <p v-if="targetsLoading" class="agentimus-jsonld__hint">Loading…</p>
                  <p v-else-if="!hasAnyTarget" class="agentimus-jsonld__hint">No matching content.</p>
                  <div v-else class="agentimus-jsonld__list">
                    <div v-for="g in groups" :key="g.type" class="agentimus-jsonld__group">
                      <button
                        type="button"
                        class="agentimus-jsonld__grouphead"
                        :aria-expanded="!isCollapsed(g.type)"
                        @click="toggleGroup(g.type)"
                      >
                        <span class="agentimus-jsonld__caret" :class="{ 'is-collapsed': isCollapsed(g.type) }" aria-hidden="true">
                          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
                        </span>
                        <span class="agentimus-jsonld__groupname">{{ g.label }}</span>
                        <span v-if="g.source" class="agentimus-jsonld__groupsource">{{ g.source }}</span>
                        <span class="agentimus-jsonld__groupcount">{{ g.total }}</span>
                      </button>
                      <ul v-show="!isCollapsed(g.type)" class="agentimus-jsonld__groupitems">
                        <li v-for="t in g.items" :key="t.id">
                          <button
                            type="button"
                            class="agentimus-jsonld__target"
                            :class="{ 'is-selected': isSelected(t) }"
                            @click="pick(t)"
                          >
                            <span class="agentimus-jsonld__target-label">{{ t.label }}</span>
                            <span v-if="statusBadge(t.status)" class="agentimus-jsonld__target-meta">
                              <span class="agentimus-jsonld__target-badge" :class="'is-' + t.status">{{ statusBadge(t.status) }}</span>
                            </span>
                          </button>
                        </li>
                        <li v-if="g.hasMore" class="agentimus-jsonld__morerow">
                          <button
                            type="button"
                            class="agentimus-jsonld__more"
                            :disabled="g.loadingMore"
                            @click="loadMore(g)"
                          >
                            {{ g.loadingMore ? 'Loading…' : `Load more (${remaining(g)} left)` }}
                          </button>
                        </li>
                      </ul>
                    </div>
                  </div>
                </aside>

                <!-- Viewer: format toggle, status, and the JSON-LD / Markdown body. -->
                <div ref="view" class="agentimus-jsonld__view">
                  <!-- Narrow only: return to the list (the two panes share the screen). -->
                  <button
                    v-if="isNarrow"
                    ref="back"
                    type="button"
                    class="agentimus-jsonld__back"
                    @click="backToList"
                  >
                    <span aria-hidden="true">←</span> Back to list
                  </button>

                  <!-- The two formats an agent receives for this target. -->
                  <div class="agentimus-jsonld__formats" role="tablist" aria-label="Preview format">
                    <button
                      type="button"
                      role="tab"
                      :aria-selected="format === 'jsonld'"
                      class="agentimus-jsonld__format"
                      :class="{ 'is-active': format === 'jsonld' }"
                      @click="setFormat('jsonld')"
                    >JSON-LD</button>
                    <button
                      type="button"
                      role="tab"
                      :aria-selected="format === 'markdown'"
                      class="agentimus-jsonld__format"
                      :class="{ 'is-active': format === 'markdown' }"
                      @click="setFormat('markdown')"
                    >Markdown</button>
                  </div>

                  <p v-if="error" class="agentimus-jsonld__status is-bad">{{ error }}</p>

                  <template v-else-if="res">
                    <p v-if="statusBanner" class="agentimus-jsonld__status" :class="`is-${statusBanner.tone}`">
                      {{ statusBanner.text }}
                    </p>

                    <div class="agentimus-jsonld__viewhead">
                      <div class="agentimus-jsonld__viewtitle">
                        <h3>{{ res.target.label }}</h3>
                        <p v-if="format === 'jsonld' && nodeTypes.length" class="agentimus-jsonld__types">
                          <span v-for="(t, i) in nodeTypes" :key="i" class="agentimus-jsonld__typechip">{{ t }}</span>
                        </p>
                      </div>
                      <!-- The "Open live" links sit top-right, where they're seen on load.
                           Copy moved into the code block's corner (below). -->
                      <div v-if="hasPublicUrl" class="agentimus-jsonld__actions">
                        <a :href="liveUrl" target="_blank" rel="noopener" class="agentimus-jsonld__tool">Open live page ↗</a>
                        <a
                          v-if="format === 'markdown' && mdResult && mdResult.mdUrl"
                          :href="mdResult.mdUrl"
                          target="_blank"
                          rel="noopener"
                          class="agentimus-jsonld__tool"
                        >Open live .md ↗</a>
                      </div>
                    </div>

                    <p v-if="res.postNote" class="agentimus-jsonld__note">{{ res.postNote }}</p>

                    <!-- JSON-LD body -->
                    <template v-if="format === 'jsonld'">
                      <p v-if="isEmpty && !res.postNote" class="agentimus-jsonld__empty">Nothing would be emitted for this target.</p>
                      <div v-else-if="!isEmpty" class="agentimus-jsonld__codewrap">
                        <button type="button" class="agentimus-jsonld__codecopy" :class="{ 'is-copied': copied }" @click="copy" :aria-label="copyLabel" :title="copyLabel">
                          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1" /></svg>
                          <span>{{ copied ? 'Copied' : 'Copy' }}</span>
                        </button>
                        <pre class="agentimus-jsonld__code"><code>{{ result.json }}</code></pre>
                      </div>
                    </template>
                    <!-- Markdown body -->
                    <template v-else>
                      <p v-if="isEmpty && !res.postNote" class="agentimus-jsonld__empty">No Markdown is served for this target.</p>
                      <div v-else-if="!isEmpty" class="agentimus-jsonld__codewrap">
                        <button type="button" class="agentimus-jsonld__codecopy" :class="{ 'is-copied': copied }" @click="copy" :aria-label="copyLabel" :title="copyLabel">
                          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1" /></svg>
                          <span>{{ copied ? 'Copied' : 'Copy' }}</span>
                        </button>
                        <pre class="agentimus-jsonld__code agentimus-jsonld__code--md"><code>{{ mdResult.markdown }}</code></pre>
                      </div>
                    </template>

                    <!-- Secondary: JSON-LD validators. The live-URL versions need a public,
                         live-emitting page; otherwise steer to copy-paste. -->
                    <div v-if="format === 'jsonld' && !isEmpty" class="agentimus-jsonld__tools">
                      <template v-if="canTestLiveUrl">
                        <a :href="googleUrl" target="_blank" rel="noopener" class="agentimus-jsonld__tool">
                          Google Rich Results test ↗
                        </a>
                        <a :href="schemaOrgUrl" target="_blank" rel="noopener" class="agentimus-jsonld__tool">
                          Schema.org validator ↗
                        </a>
                      </template>
                      <span v-else class="agentimus-jsonld__hint">
                        To validate, copy the JSON and paste it into the Schema.org validator’s "Code" tab.
                      </span>
                    </div>
                  </template>

                  <p v-else class="agentimus-jsonld__hint">Building preview…</p>
                </div>
              </div>
            </div>
          </div>

          <div class="ar-modal__actions">
            <button type="button" class="ar-btn ar-btn--ghost" @click="close">Close</button>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
/* Near full-screen — the picker + viewer sit side by side and JSON graphs get
   long, so give them the whole window (a thin frame from .ar-modal's padding).
   A viewport-locked height keeps the dialog from resizing as content loads in. */
.ar-modal__panel.agentimus-jsonld__panel {
  position: relative;   /* positioning context for the top-right ✕ */
  max-width: none;
  /* A touch smaller than the full viewport frame — pulled in ~20px on each axis so
     the dialog has a little breathing room instead of going edge-to-edge. */
  width: calc(100% - 20px);
  height: calc(100vh - 60px);
  max-height: calc(100vh - 60px);
}

/* Top-right ✕ close, over the head. The head gets extra right padding so its lead
   text never slips under the button. */
.agentimus-jsonld__panel .ar-modal__head { padding-right: 60px; }
.agentimus-jsonld__close {
  position: absolute;
  top: 16px;
  right: 16px;
  z-index: 3;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  padding: 0;
  background: transparent;
  border: 0;
  border-radius: 8px;
  cursor: pointer;
  color: var(--ar-ink-soft);
}
.agentimus-jsonld__close:hover { background: var(--ar-surface-2); color: var(--ar-ink); }
.agentimus-jsonld__close:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 2px; }

/* Split view: picker and viewer side by side. The list and the JSON each scroll
   in their own bounded box, so a long list never pushes the JSON down — and the
   layout can't collapse (no fragile fill-height chain). */
.agentimus-jsonld {
  display: grid;
  grid-template-columns: minmax(260px, 360px) 1fr;
  gap: 20px;
  align-items: start;
  min-width: 0;
}

/* Picker ------------------------------------------------------------------ */
.agentimus-jsonld__picker {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}
.agentimus-jsonld__picker:focus { outline: none; }   /* programmatic focus on Back */
.agentimus-jsonld__search { margin: 4px 0; }
.agentimus-jsonld__list {
  max-height: 60vh;
  overflow-y: auto;
  border: 1px solid var(--ar-line);
  border-radius: var(--ar-radius);
}
.agentimus-jsonld__group + .agentimus-jsonld__group { border-top: 1px solid var(--ar-line); }

/* Collapsible per-post-type group header. Sticks to the top of the scroll area
   so the current group stays labelled while you scroll a long list. */
.agentimus-jsonld__grouphead {
  position: sticky;
  top: 0;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 7px;
  width: 100%;
  padding: 7px 10px;
  background: var(--ar-surface-2);
  border: 0;
  cursor: pointer;
  font: inherit;
  text-align: left;
  color: var(--ar-ink);
}
.agentimus-jsonld__grouphead:hover { background: var(--ar-surface); }
.agentimus-jsonld__caret {
  display: inline-flex;
  color: var(--ar-ink-soft);
  transition: transform 0.15s ease;
}
.agentimus-jsonld__caret.is-collapsed { transform: rotate(-90deg); }
@media (prefers-reduced-motion: reduce) {
  .agentimus-jsonld__caret { transition: none; }
}
.agentimus-jsonld__groupname {
  flex: 0 1 auto;
  min-width: 0;
  font-weight: 600;
  font-size: 12px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
/* Vendor of the post type (e.g. FluentCart), so a generic "Products" isn't ambiguous. */
.agentimus-jsonld__groupsource {
  flex: 0 1 auto;
  min-width: 0;
  font-size: 11px;
  font-weight: 500;
  color: var(--ar-ink-soft);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.agentimus-jsonld__groupsource::before {
  content: '·';
  margin-right: 6px;
}
.agentimus-jsonld__groupcount {
  flex: 0 0 auto;
  margin-left: auto;
  font-size: 11px;
  color: var(--ar-ink-soft);
  background: var(--ar-surface);
  border: 1px solid var(--ar-line);
  border-radius: 999px;
  padding: 0 7px;
}
.agentimus-jsonld__groupitems {
  list-style: none;
  margin: 0;
  padding: 0;
}
.agentimus-jsonld__groupitems li + li { border-top: 1px solid var(--ar-line); }

/* "Load more" — a quiet full-width action that reads as a continuation of the
   list, not a primary button. Sits below the last row of a paginated group. */
.agentimus-jsonld__more {
  display: block;
  width: 100%;
  padding: 8px 10px;
  background: transparent;
  border: 0;
  cursor: pointer;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--ar-accent);
  text-align: center;
}
.agentimus-jsonld__more:hover:not(:disabled) { background: var(--ar-surface-2); text-decoration: underline; }
.agentimus-jsonld__more:disabled { color: var(--ar-ink-soft); cursor: default; }

.agentimus-jsonld__target {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 2px;
  width: 100%;
  text-align: left;
  padding: 8px 10px;
  background: transparent;
  border: 0;
  cursor: pointer;
  color: var(--ar-ink);
  font: inherit;
}
.agentimus-jsonld__target:hover { background: var(--ar-surface-2); }
.agentimus-jsonld__target.is-selected {
  background: var(--ar-surface-2);
  box-shadow: inset 3px 0 0 var(--ar-accent);
}
.agentimus-jsonld__target--site {
  border: 1px solid var(--ar-line);
  border-radius: var(--ar-radius);
}
.agentimus-jsonld__target-label {
  font-weight: 400;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.agentimus-jsonld__target-sub,
.agentimus-jsonld__target-meta {
  font-size: 11px;
  color: var(--ar-ink-soft);
  display: flex;
  gap: 6px;
  align-items: center;
}
.agentimus-jsonld__target-badge {
  text-transform: uppercase;
  letter-spacing: .15em;
  font-size: 8px;
  font-weight: 500;
  color: var(--ar-ink-soft);
}
/* Draft stays distinct from Pending/Private/Scheduled — a quiet amber, not gray. */
.agentimus-jsonld__target-badge.is-draft {
  color: var(--ar-warn);
}

/* Viewer ------------------------------------------------------------------ */
.agentimus-jsonld__view { min-width: 0; }

/* Back bar — narrow slide layout only (hidden by v-if on wide screens). Sticks to
   the top of the scrolling detail pane so it's reachable however far you scroll. */
.agentimus-jsonld__back {
  position: sticky;
  top: 0;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: 5px;
  width: 100%;
  margin: 0 0 12px;
  padding: 6px 0 8px;
  background: var(--ar-surface);
  border: 0;
  border-bottom: 1px solid var(--ar-line);
  cursor: pointer;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  text-align: left;
  color: var(--ar-accent);
}
.agentimus-jsonld__back:hover { text-decoration: underline; }
.agentimus-jsonld__back span { font-size: 16px; line-height: 1; }

/* JSON-LD ⇆ Markdown segmented toggle. */
.agentimus-jsonld__formats {
  display: inline-flex;
  gap: 2px;
  margin: 0 0 14px;
  padding: 2px;
  background: var(--ar-surface-2);
  border: 1px solid var(--ar-line);
  border-radius: 999px;
}
.agentimus-jsonld__format {
  border: 0;
  background: transparent;
  padding: 4px 16px;
  border-radius: 999px;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--ar-ink-soft);
  cursor: pointer;
}
.agentimus-jsonld__format:hover { color: var(--ar-ink); }
.agentimus-jsonld__format.is-active {
  background: var(--ar-surface);
  color: var(--ar-ink);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

.agentimus-jsonld__status {
  margin: 0 0 12px;
  padding: 9px 12px;
  border-radius: var(--ar-radius);
  border-left: 3px solid var(--ar-line-strong);
  background: var(--ar-surface-2);
  font-size: 13px;
}
.agentimus-jsonld__status.is-good { border-left-color: var(--ar-good); }
.agentimus-jsonld__status.is-warn { border-left-color: var(--ar-warn); }
.agentimus-jsonld__status.is-info { border-left-color: var(--ar-accent); }
.agentimus-jsonld__status.is-bad {
  border-left-color: var(--ar-bad);
  color: var(--ar-bad);
}

.agentimus-jsonld__viewhead {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}
.agentimus-jsonld__viewtitle { min-width: 0; }
.agentimus-jsonld__viewtitle h3 {
  margin: 0 0 6px;
  font-size: 15px;
}
/* The "Open live" links cluster, top-right of the header (Copy moved to the code
   block's corner). Wraps under the title on narrow screens. */
.agentimus-jsonld__actions {
  flex: 0 0 auto;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 14px;
}

.agentimus-jsonld__types {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin: 0;
}
.agentimus-jsonld__typechip {
  font-family: var(--ar-mono);
  font-size: 11px;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--ar-surface-2);
  border: 1px solid var(--ar-line);
  color: var(--ar-ink-soft);
}

.agentimus-jsonld__note {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--ar-ink-soft);
  font-style: italic;
}
.agentimus-jsonld__empty {
  padding: 24px;
  text-align: center;
  color: var(--ar-ink-soft);
  border: 1px dashed var(--ar-line);
  border-radius: var(--ar-radius);
}

/* The code box + its floating Copy button. The wrapper is the positioning context
   and does NOT scroll (only the <pre> inside does), so the button stays pinned to
   the top-right corner instead of scrolling away with the content. */
.agentimus-jsonld__codewrap { position: relative; }
.agentimus-jsonld__codecopy {
  position: absolute;
  top: 8px;
  right: 10px;
  z-index: 2;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 9px;
  background: var(--ar-surface);
  border: 1px solid var(--ar-line);
  border-radius: 6px;
  cursor: pointer;
  font: inherit;
  font-size: 11px;
  font-weight: 600;
  color: var(--ar-ink-soft);
  opacity: 0.9;
}
.agentimus-jsonld__codecopy:hover { color: var(--ar-ink); opacity: 1; border-color: var(--ar-line-strong); }
.agentimus-jsonld__codecopy:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 2px; }
.agentimus-jsonld__codecopy.is-copied { color: var(--ar-good); border-color: var(--ar-good); opacity: 1; }

.agentimus-jsonld__code {
  margin: 0;
  padding: 14px 16px;
  max-height: 62vh;   /* scrolls within its own box; narrow view removes this cap */
  overflow: auto;
  background: var(--ar-surface-2);
  border: 1px solid var(--ar-line);
  border-radius: var(--ar-radius);
  font-family: var(--ar-mono);
  font-size: 12.5px;
  line-height: 1.55;
  color: var(--ar-ink);
  white-space: pre;
  tab-size: 2;
}
/* Markdown is prose — wrap long lines instead of scrolling far right. */
.agentimus-jsonld__code--md { white-space: pre-wrap; overflow-wrap: anywhere; }

.agentimus-jsonld__tools {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 14px;
  margin-top: 12px;
}
.agentimus-jsonld__tool {
  font-size: 13px;
  color: var(--ar-accent);
  text-decoration: none;
}
.agentimus-jsonld__tool:hover { text-decoration: underline; }
.agentimus-jsonld__hint {
  font-size: 12px;
  color: var(--ar-ink-soft);
  margin: 0;
}

/* Narrow screens can't fit two panes: stack them in one fixed-height, clipped box
   and slide between them (`show-detail`). Absolute positioning makes the slide
   independent of content height (can't collapse) and leak-proof — each pane is
   exactly 100% wide and slides fully out of the box, so nothing peeks at the edges. */
@media (max-width: 782px) {
  /* Narrow already has a fixed-height slide box, so let the panel size to it
     (head + 66vh box + actions) instead of forcing the desktop fixed height. */
  .ar-modal__panel.agentimus-jsonld__panel { height: auto; }
  .agentimus-jsonld {
    display: block;
    position: relative;
    overflow: hidden;
    height: 66vh;
    grid-template-columns: none;
    gap: 0;
  }
  .agentimus-jsonld__picker,
  .agentimus-jsonld__view {
    position: absolute;
    inset: 0;
    overflow-y: auto;
    transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
  }
  .agentimus-jsonld__picker { transform: translateX(0); }
  .agentimus-jsonld.show-detail .agentimus-jsonld__picker { transform: translateX(-100%); }
  .agentimus-jsonld__view { transform: translateX(100%); }
  .agentimus-jsonld.show-detail .agentimus-jsonld__view { transform: translateX(0); }
  /* Fill the box; the list and JSON scroll with their pane, not in a nested cap. */
  .agentimus-jsonld__list { max-height: none; flex: 1 1 auto; min-height: 0; }
  .agentimus-jsonld__code { max-height: none; }
}
@media (max-width: 782px) and (prefers-reduced-motion: reduce) {
  .agentimus-jsonld__picker,
  .agentimus-jsonld__view { transition: none; }
}
</style>
