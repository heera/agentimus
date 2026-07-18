<script>
/**
 * The writing assistant's drawer — right-hand panel on desktop (the underlying
 * screen stays readable beside it: composing is a side-by-side activity), full
 * sheet on phones. Summoned by the nav-bar quill.
 *
 * v1 flow, deliberately linear: brief → ONE structured generation → preview card
 * → explicit "Create draft" (or pending). Compose writes nothing; create can only
 * ever produce a draft/pending post — publishing stays a human editor action.
 *
 * The parent renders this with v-show, not v-if, so a composed preview SURVIVES
 * closing the drawer — Esc on a stray key never costs the owner a generation.
 * House dialog rules otherwise: Esc or Close only, the scrim doesn't close.
 *
 * A composed draft is a PAID artifact, so the held draft (+ its brief) is also
 * persisted to localStorage: reloads, browser restarts and closed tabs all
 * restore it exactly where it was. It leaves storage only through the same two
 * explicit acts that discard it on screen — creating the post, or drafting
 * again over it — and a stale stash expires after a week so a forgotten draft
 * from another month never ambushes anyone. Deliberately NOT auto-created as a
 * WP draft: "nothing is saved until you say so" stays literally true.
 */
const HELD_KEY = 'agentimus:assistant:held';
const HELD_TTL_MS = 7 * 24 * 3600 * 1000;

export default {
  name: 'AssistantDrawer',
  props: {
    open: { type: Boolean, default: false },
    api: { type: Object, default: null },
    // { imageReady, canUpload } — drives the per-slot Generate/Library buttons.
    caps: { type: Object, default: () => ({ imageReady: false, canUpload: false }) },
  },
  emits: ['close', 'flash'],
  data() {
    return {
      step: 'idle', // idle | outline | composing | preview | creating | done
      prompt: '',
      draft: null,
      statusChoice: 'draft',
      post: null,
      error: '',
      // The outline gate: a cheap skeleton (title + sections) the owner shapes
      // BEFORE the expensive generation. Rerolling a skeleton costs pennies.
      outline: null, // { title, sections: [{ heading, note }] }
      outlining: false,
      usedOutline: false, // Whether the held draft was written under the outline contract.
      composeOrigin: 'idle', // Where composing started — errors and spinners return there.
      confirmReset: false, // "Start over" arms an inline confirm — it discards paid work.
      // Targeted revision of the held draft.
      refineText: '',
      refining: false,
      prevDraft: null, // One undo step: a revision overwrites a paid artifact.
      // Images: the FEATURED choice, per-slot busy flags, the attachment-id → url
      // display cache (slots carry only attachment_id — the server shape), and the
      // inline media-library picker's state.
      featuredImage: null,
      imgBusy: {},
      imageCache: {},
      lib: { open: null, q: '', results: [], busy: false, error: '' },
    };
  },
  watch: {
    open(now) {
      // The page behind the drawer is context, not content — freeze its scroll
      // while the drawer is up, or wheel events chain through the scrim and the
      // admin page wanders underneath. html carries `scrollbar-gutter: stable`,
      // so hiding the document scrollbar can't shift the layout sideways.
      document.documentElement.style.overflow = now ? 'hidden' : '';
      if (now) {
        this.$nextTick(() => {
          const el = 'idle' === this.step ? this.$refs.promptEl : this.$refs.panel;
          if (el) el.focus();
        });
      }
    },
    // Navigating anywhere disarms a half-clicked "Start over".
    step() {
      this.confirmReset = false;
    },
  },
  computed: {
    // One flag for "an AI call is in flight" — outline and compose alike.
    busy() {
      return 'composing' === this.step || this.outlining;
    },
    // The gate opens once at least one section has a real heading.
    outlineReady() {
      return !!(this.outline && this.outline.sections.some((s) => s.heading && s.heading.trim().length > 1));
    },
    // The outline screen — including while its "Write the article" call runs.
    onOutlineScreen() {
      return 'outline' === this.step || ('composing' === this.step && 'outline' === this.composeOrigin);
    },
    // Screens that carry the pinned foot: outline and preview alike — the body
    // sheds its bottom padding and the foot anchors/pins to the drawer's edge.
    onFootedScreen() {
      return this.onOutlineScreen || 'preview' === this.step || 'creating' === this.step;
    },
  },
  created() {
    this.restoreHeld();
  },
  mounted() {
    document.addEventListener('keydown', this.onKey);
  },
  beforeUnmount() {
    document.removeEventListener('keydown', this.onKey);
    document.documentElement.style.overflow = ''; // Never leave the page frozen.
  },
  methods: {
    // ---- Held-draft persistence (all best-effort: storage may be full/blocked) --
    persistHeld() {
      try {
        localStorage.setItem(HELD_KEY, JSON.stringify({
          v: 1,
          savedAt: Date.now(),
          prompt: this.prompt,
          draft: this.draft,
          outline: this.outline,
          usedOutline: this.usedOutline,
          featuredImage: this.featuredImage,
          imageCache: this.imageCache,
        }));
      } catch (e) { /* private mode / quota — the in-memory copy still stands */ }
    },
    clearHeld() {
      try {
        localStorage.removeItem(HELD_KEY);
      } catch (e) { /* nothing to do */ }
    },
    restoreHeld() {
      try {
        const raw = localStorage.getItem(HELD_KEY);
        if (!raw) return;
        const held = JSON.parse(raw);
        const fresh = held && 1 === held.v && Date.now() - (held.savedAt || 0) < HELD_TTL_MS;
        const draft = fresh && held.draft && 'string' === typeof held.draft.title && 'string' === typeof held.draft.content
          ? held.draft : null;
        const o = fresh && held.outline && 'string' === typeof held.outline.title && Array.isArray(held.outline.sections)
          ? held.outline : null;
        if (!draft && !o) {
          this.clearHeld();
          return;
        }
        this.prompt = 'string' === typeof held.prompt ? held.prompt : '';
        if (o) {
          this.outline = {
            title: o.title,
            sections: o.sections
              .filter((s) => s && 'string' === typeof s.heading)
              .map((s) => ({ heading: s.heading, note: 'string' === typeof s.note ? s.note : '' })),
          };
          this.usedOutline = !!held.usedOutline;
        }
        if (draft) {
          ['topics', 'tags', 'categories', 'images'].forEach((k) => { if (!Array.isArray(draft[k])) draft[k] = []; });
          this.draft = draft;
          this.featuredImage = held.featuredImage && held.featuredImage.id ? held.featuredImage : null;
          this.imageCache = held.imageCache && 'object' === typeof held.imageCache ? held.imageCache : {};
          this.step = 'preview'; // Reopen exactly where they left off.
        } else {
          this.step = 'outline'; // Mid-outline when the tab closed — pick it back up.
        }
      } catch (e) {
        this.clearHeld(); // A corrupt stash must never brick the drawer.
      }
    },
    onKey(e) {
      if ('Escape' === e.key && this.open) this.$emit('close');
    },
    // ---- Outline: the cheap skeleton before the expensive draft ----------------
    async makeOutline() {
      const brief = this.prompt.trim();
      if (brief.length < 8 || this.busy) return;
      this.outlining = true;
      this.error = '';
      try {
        const r = await this.api.assistantOutline(brief);
        this.outline = r.outline;
        this.usedOutline = false;
        this.step = 'outline';
        this.persistHeld();
        this.$nextTick(() => {
          if (this.$refs.outlineTitleEl) this.$refs.outlineTitleEl.focus();
        });
      } catch (e) {
        this.error = (e && e.message) || 'The outline didn’t come back — please try again.';
      } finally {
        this.outlining = false;
      }
    },
    addSection() {
      if (!this.outline) return;
      this.outline.sections.push({ heading: '', note: '' });
      this.persistHeld();
    },
    removeSection(i) {
      if (!this.outline) return;
      this.outline.sections.splice(i, 1);
      this.persistHeld();
    },
    // The outline as the server contract: trimmed, heading-less rows dropped.
    cleanOutline() {
      if (!this.outline) return null;
      const sections = this.outline.sections
        .map((s) => ({ heading: (s.heading || '').trim(), note: (s.note || '').trim() }))
        .filter((s) => s.heading);
      return sections.length ? { title: (this.outline.title || '').trim(), sections } : null;
    },
    // ---- Compose --------------------------------------------------------------
    async compose(useOutline) {
      const brief = this.prompt.trim();
      if (brief.length < 8 || this.busy) return;
      const outline = useOutline ? this.cleanOutline() : null;
      if (useOutline && !outline) return;
      this.composeOrigin = 'outline' === this.step ? 'outline' : 'idle';
      this.step = 'composing';
      this.error = '';
      try {
        const r = await this.api.assistantCompose(brief, outline);
        this.prevDraft = this.draft || null; // "Draft again" overwrites too — same one-step undo.
        this.draft = r.draft;
        this.usedOutline = !!outline;
        this.step = 'preview';
        this.persistHeld(); // A paid artifact — survives reloads until created or replaced.
      } catch (e) {
        this.error = (e && e.message) || 'The draft didn’t come back — please try again.';
        this.step = this.composeOrigin; // Fail back to wherever the click came from.
      }
    },
    // Back to the brief, keeping its text — "edit the brief", not "start over".
    // The composed draft is deliberately KEPT: the held-bar offers the way back.
    editBrief() {
      this.step = 'idle';
      this.error = '';
      this.$nextTick(() => {
        if (this.$refs.promptEl) this.$refs.promptEl.focus();
      });
    },
    restorePreview() {
      if (!this.draft) return;
      this.step = 'preview';
      this.error = '';
    },
    // ---- Refine: revise the held draft in place --------------------------------
    async refine() {
      const instruction = this.refineText.trim();
      if (instruction.length < 4 || this.refining || !this.draft) return;
      this.refining = true;
      this.error = '';
      try {
        const r = await this.api.assistantRefine(this.draft, instruction);
        this.prevDraft = this.draft; // Keep the version being replaced — one-step undo.
        this.draft = r.draft;
        this.refineText = '';
        this.persistHeld();
      } catch (e) {
        this.error = (e && e.message) || 'The revision didn’t come back — please try again.';
      } finally {
        this.refining = false;
      }
    },
    undoRefine() {
      if (!this.prevDraft) return;
      this.draft = this.prevDraft;
      this.prevDraft = null;
      this.error = '';
      this.persistHeld();
    },
    // ---- Images: one slot, one explicit act ------------------------------------
    slotUrl(slot) {
      const c = slot.attachment_id && this.imageCache[slot.attachment_id];
      return c ? c.url : '';
    },
    async genImage(key) {
      if (this.imgBusy[key] || !this.draft) return;
      const isFeatured = 'featured' === key;
      const alt = isFeatured ? `A featured image for the post “${this.draft.title}”` : this.draft.images[key].alt;
      this.imgBusy = { ...this.imgBusy, [key]: true };
      this.error = '';
      try {
        const r = await this.api.assistantGenerateImage(alt, this.draft.title);
        this.imageCache = { ...this.imageCache, [r.id]: { url: r.url } };
        if (isFeatured) {
          this.featuredImage = { id: r.id, url: r.url };
        } else {
          this.draft.images[key] = { ...this.draft.images[key], attachment_id: r.id };
        }
        this.persistHeld();
        this.$emit('flash', 'success', 'Image generated and saved to your media library.');
      } catch (e) {
        this.error = (e && e.message) || 'The image didn’t come back — please try again.';
      } finally {
        this.imgBusy = { ...this.imgBusy, [key]: false };
      }
    },
    clearImage(key) {
      if ('featured' === key) {
        this.featuredImage = null;
      } else if (this.draft && this.draft.images[key]) {
        const slot = { ...this.draft.images[key] };
        delete slot.attachment_id;
        this.draft.images[key] = slot;
      }
      this.persistHeld();
    },
    // ---- The inline media-library picker ---------------------------------------
    openLib(key) {
      this.lib = { open: key, q: '', results: [], busy: false, error: '' };
      this.searchLib();
    },
    closeLib() {
      this.lib.open = null;
    },
    async searchLib() {
      this.lib.busy = true;
      this.lib.error = '';
      try {
        this.lib.results = await this.api.searchMedia(this.lib.q);
      } catch (e) {
        this.lib.error = (e && e.message) || 'Couldn’t search the library.';
        this.lib.results = [];
      } finally {
        this.lib.busy = false;
      }
    },
    libThumb(item) {
      const s = item.media_details && item.media_details.sizes;
      return (s && s.thumbnail && s.thumbnail.source_url) || item.source_url;
    },
    pickLib(item) {
      const key = this.lib.open;
      this.imageCache = { ...this.imageCache, [item.id]: { url: this.libThumb(item) } };
      if ('featured' === key) {
        this.featuredImage = { id: item.id, url: this.libThumb(item) };
      } else if (this.draft && this.draft.images[key]) {
        this.draft.images[key] = { ...this.draft.images[key], attachment_id: item.id };
      }
      this.closeLib();
      this.persistHeld();
    },
    // ---- Create ---------------------------------------------------------------
    async create() {
      if (!this.draft || 'creating' === this.step) return;
      this.step = 'creating';
      this.error = '';
      try {
        const r = await this.api.assistantCreate({
          type: 'post',
          status: this.statusChoice,
          title: this.draft.title,
          content: this.draft.content,
          excerpt: this.draft.excerpt,
          description: this.draft.description,
          topics: this.draft.topics,
          tags: this.draft.tags,
          categories: this.draft.categories,
          featured_image: this.featuredImage ? this.featuredImage.id : 0,
          // Only FILLED slots ship; a skipped suggestion leaves no trace in the post.
          images: (this.draft.images || [])
            .filter((s) => s.attachment_id)
            .map((s) => ({ attachment_id: s.attachment_id, after_heading: s.after_heading, alt: s.alt })),
        });
        this.post = r.post;
        this.step = 'done';
        this.clearHeld(); // It's a real post now — the stash's job is done.
        this.$emit('flash', 'success', 'pending' === this.post.status ? 'Pending post created — waiting for review.' : 'Draft created — waiting in your drafts.');
      } catch (e) {
        this.error = (e && e.message) || 'Couldn’t create the draft — please try again.';
        this.step = 'preview'; // The composed draft is still good; only the write failed.
      }
    },
    // ---- After ----------------------------------------------------------------
    // "Start over" mid-flow: the same full reset the done screen's "Write
    // another" runs, behind the inline confirm — it discards a paid draft
    // and a shaped outline in one act, so one stray click must not do it.
    confirmStartOver() {
      this.confirmReset = false;
      this.writeAnother();
    },
    writeAnother() {
      this.step = 'idle';
      this.prompt = '';
      this.draft = null;
      this.post = null;
      this.error = '';
      this.refineText = '';
      this.prevDraft = null;
      this.outline = null;
      this.usedOutline = false;
      this.composeOrigin = 'idle';
      this.featuredImage = null;
      this.imgBusy = {};
      this.lib.open = null;
      this.clearHeld();
      this.$nextTick(() => {
        if (this.$refs.promptEl) this.$refs.promptEl.focus();
      });
    },
    statusLabel(status) {
      return 'pending' === status ? 'Pending review' : 'Draft';
    },
  },
};
</script>

<template>
  <Teleport to="body">
    <transition name="ar-drawer">
      <div v-if="open" class="ar-drawer" aria-hidden="false">
        <!-- Scrim: dims, deliberately does NOT close (house rule: Esc or Close only). -->
        <div class="ar-drawer__scrim" aria-hidden="true"></div>

        <div
          ref="panel"
          class="ar-drawer__panel"
          role="dialog"
          aria-modal="true"
          aria-labelledby="ar-assist-title"
          tabindex="-1"
          @keydown.esc="$emit('close')"
        >
          <div class="ar-drawer__head">
            <span class="ar-drawer__quill" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.5 3.5c-4-.5-8.3 1-11.2 3.9-2 2-3.3 4.6-3.8 7.2L3 17.1l3.9 3.9 2.5-2.5c2.6-.5 5.2-1.8 7.2-3.8 2.9-2.9 4.4-7.2 3.9-11.2z" />
                <path d="M14.5 9.5L4.5 19.5" />
              </svg>
            </span>
            <div class="ar-drawer__titles">
              <h2 id="ar-assist-title" class="ar-drawer__title">Writing assistant</h2>
              <p class="ar-drawer__sub">Describe the post — nothing is saved until you say so.</p>
            </div>
            <button type="button" class="ar-drawer__close" aria-label="Close the assistant" @click="$emit('close')">
              <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" /></svg>
            </button>
          </div>

          <div class="ar-drawer__body" :class="{ 'ar-drawer__body--footed': onFootedScreen }">
            <!-- ============ Brief ============ -->
            <template v-if="'idle' === step || ('composing' === step && 'outline' !== composeOrigin)">
              <label class="ar-assist__label" for="ar-assist-prompt">What should it write?</label>
              <textarea
                id="ar-assist-prompt"
                ref="promptEl"
                v-model="prompt"
                class="ar-assist__prompt"
                rows="6"
                :disabled="busy"
                placeholder="e.g. A practical post on choosing a WordPress backup plugin — what actually matters, common mistakes, and a short checklist. Friendly, ~800 words."
                @keydown.meta.enter="compose(false)"
                @keydown.ctrl.enter="compose(false)"
              ></textarea>
              <p class="ar-assist__hint">
                Be as specific as you like — audience, angle, length, language. Your site’s Content
                Guidelines are applied automatically. “Outline first” lets you shape the sections
                before the full draft is written — rerolling an outline costs far less than a draft.
              </p>
              <p v-if="error" class="ar-assist__error" role="alert">{{ error }}</p>

              <!-- A composed draft is a PAID artifact: while one is held, say so and
                   keep the way back one click away — editing the brief must never
                   cost the generation. Drafting again is what replaces it. -->
              <div v-if="draft && !busy" class="ar-assist__held">
                <span class="ar-assist__heldtext">Your drafted post is still here — nothing was lost.</span>
                <button type="button" class="ar-linkbtn" @click="restorePreview">Back to the preview</button>
              </div>
              <!-- Same promise for a shaped outline when no draft holds the spotlight. -->
              <div v-else-if="outline && !busy" class="ar-assist__held">
                <span class="ar-assist__heldtext">Your outline is still here — nothing was lost.</span>
                <button type="button" class="ar-linkbtn" @click="step = 'outline'">Back to the outline</button>
              </div>

              <div class="ar-assist__actions">
                <span class="ar-assist__spacer"></span>
                <button type="button" class="ar-btn ar-btn--ghost" :disabled="prompt.trim().length < 8 || busy" @click="compose(false)">
                  <span v-if="'composing' === step" class="ar-spinner ar-assist__spin" aria-hidden="true"></span>
                  {{ 'composing' === step ? 'Drafting…' : (draft ? 'Draft again' : 'Draft it now') }}
                </button>
                <button type="button" class="ar-btn ar-assist__go" :disabled="prompt.trim().length < 8 || busy" @click="makeOutline">
                  <span v-if="outlining" class="ar-spinner ar-assist__spin" aria-hidden="true"></span>
                  {{ outlining ? 'Outlining…' : 'Outline first' }}
                </button>
              </div>
              <p v-if="draft && !busy" class="ar-assist__hint ar-assist__replacenote">
                “Draft again” writes a new draft from the brief above and replaces the held one.
              </p>
            </template>

            <!-- ============ Outline ============ -->
            <!-- The skeleton, before any real writing: reword, remove or add
                 sections — the article will follow this shape exactly. -->
            <template v-else-if="onOutlineScreen">
              <label class="ar-assist__label" for="ar-outline-title">Working title</label>
              <input
                id="ar-outline-title"
                ref="outlineTitleEl"
                v-model="outline.title"
                type="text"
                class="ar-assist__refineinput ar-assist__otitle"
                :disabled="busy"
                aria-label="Working title"
                @change="persistHeld"
              />

              <label class="ar-assist__label">Sections</label>
              <div class="ar-assist__osections">
                <div v-for="(s, i) in outline.sections" :key="'os' + i" class="ar-assist__osection">
                  <div class="ar-assist__osecrow">
                    <span class="ar-assist__onum" aria-hidden="true">{{ i + 1 }}</span>
                    <input
                      v-model="s.heading"
                      type="text"
                      class="ar-assist__refineinput"
                      placeholder="Section heading"
                      :aria-label="'Heading of section ' + (i + 1)"
                      :disabled="busy"
                      @change="persistHeld"
                    />
                    <button type="button" class="ar-assist__oremove" :aria-label="'Remove section ' + (i + 1)" :disabled="busy" @click="removeSection(i)">
                      <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" /></svg>
                    </button>
                  </div>
                  <textarea
                    v-model="s.note"
                    rows="2"
                    class="ar-assist__refineinput ar-assist__onote"
                    placeholder="What this section covers"
                    :aria-label="'What section ' + (i + 1) + ' covers'"
                    :disabled="busy"
                    @change="persistHeld"
                  ></textarea>
                </div>
              </div>
              <button type="button" class="ar-linkbtn" :disabled="busy" @click="addSection">+ Add a section</button>

              <p class="ar-assist__hint">
                The article will follow this outline exactly — one section per row, in this order,
                these headings. Shape it first; a fresh outline costs far less than a full draft.
              </p>

              <div v-if="draft && !busy" class="ar-assist__held">
                <span class="ar-assist__heldtext">Your drafted post is still here — “Write the article” replaces it.</span>
                <button type="button" class="ar-linkbtn" @click="restorePreview">Back to the preview</button>
              </div>

              <!-- Pinned foot: the section list scrolls, the way out never does. -->
              <div class="ar-assist__foot">
                <p v-if="error" class="ar-assist__error ar-assist__footerror" role="alert">{{ error }}</p>
                <div v-if="!confirmReset" class="ar-assist__footrow">
                  <button type="button" class="ar-linkbtn ar-assist__resetlink" :disabled="busy" @click="confirmReset = true">Start over</button>
                  <span class="ar-assist__spacer"></span>
                  <button type="button" class="ar-linkbtn" :disabled="busy" @click="editBrief">Edit the brief</button>
                  <button type="button" class="ar-linkbtn" :disabled="busy" @click="makeOutline">{{ outlining ? 'Outlining…' : 'New outline' }}</button>
                  <button type="button" class="ar-btn ar-assist__go" :disabled="!outlineReady || busy" @click="compose(true)">
                    <span v-if="'composing' === step" class="ar-spinner ar-assist__spin" aria-hidden="true"></span>
                    {{ 'composing' === step ? 'Writing…' : 'Write the article' }}
                  </button>
                </div>
                <div v-else class="ar-assist__footrow">
                  <span class="ar-assist__resettext">Clear everything here and start fresh? Nothing on your site is affected.</span>
                  <span class="ar-assist__spacer"></span>
                  <button type="button" class="ar-linkbtn ar-assist__resetdanger" @click="confirmStartOver">Yes, clear it</button>
                  <button type="button" class="ar-linkbtn" @click="confirmReset = false">Keep it</button>
                </div>
              </div>
            </template>

            <!-- ============ Preview ============ -->
            <template v-else-if="'preview' === step || 'creating' === step">
              <div class="ar-assist__preview">
                <h3 class="ar-assist__ptitle">{{ draft.title }}</h3>
                <p v-if="draft.excerpt" class="ar-assist__pexcerpt">{{ draft.excerpt }}</p>
                <!-- Server-sanitised (wp_kses_post) before it ever reaches the client. -->
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div class="ar-assist__pbody" v-html="draft.content"></div>
              </div>

              <div class="ar-assist__meta">
                <div v-if="draft.description" class="ar-assist__metarow">
                  <span class="ar-assist__metakey">AI description</span>
                  <span class="ar-assist__metaval">{{ draft.description }}</span>
                </div>
                <div v-if="draft.categories.length" class="ar-assist__metarow">
                  <span class="ar-assist__metakey">Categories</span>
                  <span class="ar-assist__chips"><span v-for="c in draft.categories" :key="'c' + c" class="ar-assist__chip">{{ c }}</span></span>
                </div>
                <div v-if="draft.tags.length" class="ar-assist__metarow">
                  <span class="ar-assist__metakey">Tags</span>
                  <span class="ar-assist__chips"><span v-for="t in draft.tags" :key="'t' + t" class="ar-assist__chip">{{ t }}</span></span>
                </div>
                <div v-if="draft.topics.length" class="ar-assist__metarow">
                  <span class="ar-assist__metakey">Topics for AI</span>
                  <span class="ar-assist__chips"><span v-for="t in draft.topics" :key="'p' + t" class="ar-assist__chip">{{ t }}</span></span>
                </div>
              </div>

              <!-- Images: the writer proposed WHERE a picture helps and WHAT it shows
                   (free); each actual image is one explicit act per slot — Generate
                   (feature-detected) or pick from the owner's own library. Skipped
                   slots leave no trace in the post. -->
              <div v-if="caps.canUpload && ((draft.images && draft.images.length) || caps.imageReady)" class="ar-assist__images">
                <div class="ar-assist__metarow">
                  <span class="ar-assist__metakey">Featured image</span>
                  <span class="ar-assist__imgslot">
                    <template v-if="featuredImage">
                      <img class="ar-assist__thumb" :src="featuredImage.url" alt="" />
                      <button type="button" class="ar-linkbtn" @click="clearImage('featured')">Remove</button>
                    </template>
                    <template v-else>
                      <button v-if="caps.imageReady" type="button" class="ar-linkbtn" :disabled="!!imgBusy.featured" @click="genImage('featured')">
                        {{ imgBusy.featured ? 'Generating…' : 'Generate' }}
                      </button>
                      <button type="button" class="ar-linkbtn" @click="openLib('featured')">Library</button>
                    </template>
                  </span>
                </div>

                <div v-for="(slot, i) in draft.images" :key="'slot' + i" class="ar-assist__metarow ar-assist__metarow--slot">
                  <span class="ar-assist__metakey">Image {{ i + 1 }}</span>
                  <span class="ar-assist__imgslot ar-assist__imgslot--stack">
                    <span class="ar-assist__imgalt">{{ slot.alt }}</span>
                    <span class="ar-assist__imganchor">{{ slot.after_heading ? 'after “' + slot.after_heading + '”' : 'after the introduction' }}</span>
                    <span class="ar-assist__imgacts">
                      <template v-if="slot.attachment_id">
                        <img v-if="slotUrl(slot)" class="ar-assist__thumb" :src="slotUrl(slot)" alt="" />
                        <button type="button" class="ar-linkbtn" @click="clearImage(i)">Remove</button>
                      </template>
                      <template v-else>
                        <button v-if="caps.imageReady" type="button" class="ar-linkbtn" :disabled="!!imgBusy[i]" @click="genImage(i)">
                          {{ imgBusy[i] ? 'Generating…' : 'Generate' }}
                        </button>
                        <button type="button" class="ar-linkbtn" @click="openLib(i)">Library</button>
                        <span class="ar-assist__imgnote">or skip it</span>
                      </template>
                    </span>
                  </span>
                </div>

                <!-- Inline library picker for the open slot. -->
                <div v-if="null !== lib.open" class="ar-assist__lib">
                  <div class="ar-assist__librow">
                    <input v-model="lib.q" type="text" class="ar-assist__refineinput" placeholder="Search your media library" aria-label="Search your media library" @keydown.enter="searchLib" />
                    <button type="button" class="ar-btn ar-assist__refinebtn" :disabled="lib.busy" @click="searchLib">{{ lib.busy ? 'Searching…' : 'Search' }}</button>
                    <button type="button" class="ar-linkbtn" @click="closeLib">Close</button>
                  </div>
                  <p v-if="lib.error" class="ar-assist__error">{{ lib.error }}</p>
                  <div v-else-if="lib.results.length" class="ar-assist__libgrid">
                    <button v-for="item in lib.results" :key="item.id" type="button" class="ar-assist__libitem" :title="item.alt_text || ''" @click="pickLib(item)">
                      <img :src="libThumb(item)" alt="" />
                    </button>
                  </div>
                  <p v-else-if="!lib.busy" class="ar-assist__imgnote">Nothing matched — try another word.</p>
                </div>
              </div>

              <!-- Targeted revision: one instruction, one AI call, the draft revised in
                   place — with a one-step Undo, because a revision overwrites a paid
                   generation. "Try again" stays the full reroll. -->
              <div class="ar-assist__refine">
                <input
                  v-model="refineText"
                  type="text"
                  class="ar-assist__refineinput"
                  :disabled="refining || 'creating' === step"
                  placeholder="Ask for a change — e.g. add a section on caching, shorten the intro"
                  aria-label="Ask for a change to this draft"
                  @keydown.enter="refine"
                />
                <button type="button" class="ar-btn ar-assist__refinebtn" :disabled="refineText.trim().length < 4 || refining || 'creating' === step" @click="refine">
                  <span v-if="refining" class="ar-spinner ar-assist__spin" aria-hidden="true"></span>
                  {{ refining ? 'Revising…' : 'Revise' }}
                </button>
              </div>
              <p v-if="prevDraft && !refining" class="ar-assist__undoline">
                Replaced the previous version.
                <button type="button" class="ar-linkbtn" @click="undoRefine">Undo — bring it back</button>
              </p>

              <!-- Pinned foot, same as the outline screen: the article scrolls,
                   the way out never does. Two deliberate rows — navigation
                   links, then the commit act — so nothing wraps unpredictably. -->
              <div class="ar-assist__foot">
                <p v-if="error" class="ar-assist__error ar-assist__footerror" role="alert">{{ error }}</p>
                <template v-if="!confirmReset">
                  <div class="ar-assist__footrow">
                    <button type="button" class="ar-linkbtn ar-assist__resetlink" :disabled="'creating' === step" @click="confirmReset = true">Start over</button>
                    <span class="ar-assist__spacer"></span>
                    <button type="button" class="ar-linkbtn" :disabled="'creating' === step" @click="editBrief">Edit the brief</button>
                    <button v-if="outline" type="button" class="ar-linkbtn" :disabled="'creating' === step" @click="step = 'outline'">Edit the outline</button>
                    <button type="button" class="ar-linkbtn" :disabled="'creating' === step" @click="compose(usedOutline)">Try again</button>
                  </div>
                  <div class="ar-assist__footrow ar-assist__footrow--commit">
                    <span class="ar-assist__spacer"></span>
                    <select v-model="statusChoice" class="ar-assist__status" :disabled="'creating' === step" aria-label="Save as">
                      <option value="draft">as a draft</option>
                      <option value="pending">as pending review</option>
                    </select>
                    <button type="button" class="ar-btn ar-assist__go" :disabled="'creating' === step" @click="create">
                      <span v-if="'creating' === step" class="ar-spinner ar-assist__spin" aria-hidden="true"></span>
                      {{ 'creating' === step ? 'Creating…' : 'Create draft' }}
                    </button>
                  </div>
                </template>
                <div v-else class="ar-assist__footrow">
                  <span class="ar-assist__resettext">Clear everything here and start fresh? Nothing on your site is affected.</span>
                  <span class="ar-assist__spacer"></span>
                  <button type="button" class="ar-linkbtn ar-assist__resetdanger" @click="confirmStartOver">Yes, clear it</button>
                  <button type="button" class="ar-linkbtn" @click="confirmReset = false">Keep it</button>
                </div>
              </div>
            </template>

            <!-- ============ Done ============ -->
            <template v-else-if="'done' === step">
              <div class="ar-assist__done">
                <span class="ar-assist__doneicon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><path d="M8.2 12.4l2.6 2.6 5-5.4" /></svg>
                </span>
                <h3 class="ar-assist__donetitle">{{ statusLabel(post.status) }} created</h3>
                <p class="ar-assist__donetext">
                  “{{ post.title }}” is waiting {{ 'pending' === post.status ? 'for review' : 'in your drafts' }} —
                  nothing is published.
                </p>
                <div class="ar-assist__doneactions">
                  <a class="ar-btn" :href="post.editUrl" target="_blank" rel="noopener noreferrer">Open in editor</a>
                  <button type="button" class="ar-btn ar-btn--ghost" @click="writeAnother">Write another</button>
                </div>
                <p class="ar-assist__nudge">
                  Tip: in the editor, run the <strong>AI Readability</strong> check (the Agentimus box)
                  before publishing — it grades exactly what answer engines look for.
                </p>
              </div>
            </template>

            <!-- ============ Start over ============ -->
            <!-- The brief screen's copy of the way out (the outline and preview
                 screens carry theirs inside their pinned foot): clear the brief,
                 outline and held draft in one act, behind the inline confirm —
                 it discards paid work. Never shown mid-call or after creation. -->
            <div
              v-if="'idle' === step && !busy && (draft || outline || prompt.trim())"
              class="ar-assist__reset"
            >
              <template v-if="!confirmReset">
                <button type="button" class="ar-linkbtn" @click="confirmReset = true">Start over</button>
              </template>
              <template v-else>
                <span class="ar-assist__resettext">Clear everything here and start fresh? Nothing on your site is affected.</span>
                <button type="button" class="ar-linkbtn ar-assist__resetdanger" @click="confirmStartOver">Yes, clear it</button>
                <button type="button" class="ar-linkbtn" @click="confirmReset = false">Keep it</button>
              </template>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>
