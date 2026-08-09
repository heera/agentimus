<script>
/**
 * The writing assistant's drawer — right-hand panel on desktop (the underlying
 * screen stays readable beside it: composing is a side-by-side activity), full
 * sheet on phones. Summoned by the nav-bar spark.
 *
 * v1 flow, deliberately linear: brief → ONE structured generation → preview card
 * → explicit "Create draft" (or pending). Compose writes nothing; create can only
 * ever produce a draft/pending post — publishing stays a human editor action.
 *
 * The parent renders this with v-show, not v-if, so a composed preview SURVIVES
 * closing the drawer — Esc on a stray key never costs the owner a generation.
 * Esc, Close and the scrim all close (his call): unlike the confirm dialogs,
 * closing here can never lose anything — the brief and any held draft persist
 * to localStorage and reopen exactly where they were.
 *
 * A composed draft is a PAID artifact, so the held draft (+ its brief) is also
 * persisted to localStorage: reloads, browser restarts and closed tabs all
 * restore it exactly where it was. It leaves storage only through the same two
 * explicit acts that discard it on screen — creating the post, or drafting
 * again over it — and a stale stash expires after a week so a forgotten draft
 * from another month never ambushes anyone. Deliberately NOT auto-created as a
 * WP draft: "nothing is saved until you say so" stays literally true.
 */
import { confirm } from '../confirm.js';
import SelectMenu from './SelectMenu.vue';

const HELD_KEY = 'agentimus:assistant:held';
const HELD_TTL_MS = 7 * 24 * 3600 * 1000;

/**
 * Starting frames for the brief, one set per shape.
 *
 * Every seed is written as a fill-in-the-blanks sentence rather than a finished
 * instruction: the [brackets] are the point — they show the owner exactly which
 * decisions are theirs, and a brief still carrying them tells the model where it
 * must not guess. Nothing here is stored or sent as a mode; a chip only ever
 * types into the box on the owner's behalf.
 */
const ARTICLE_PRESETS = [
  { label: 'SEO article', seed: 'A practical article on [topic] for [audience] — what actually matters, the mistakes people make, and a short checklist to finish. Around 900 words.' },
  { label: 'Tutorial', seed: 'A step-by-step tutorial showing how to [task], for someone who [level of experience]. Numbered steps, what to expect after each one, and a short troubleshooting section at the end.' },
  { label: 'Comparison', seed: 'A fair comparison of [A] and [B] for [audience] — where each one wins, where each falls short, and a clear answer on who should pick which.' },
  { label: 'Product review', seed: 'An honest review of [product] after real use — what it does well, what it doesn’t, who it suits and who should skip it. No marketing voice.' },
];

const PAGE_PRESETS = [
  { label: 'About', seed: 'An About page for [who] — what they do, what they’re known for, and how to get in touch. Warm, specific, not boastful. Short.' },
  { label: 'Services', seed: 'A Services page covering [service one] and [service two] — what each includes, who it’s for, and what happens after someone gets in touch.' },
  { label: 'Contact', seed: 'A Contact page — the ways to reach [who], what to expect for a reply, and what to include in a first message.' },
  // The placeholder instruction is repeated here on purpose: this is the one
  // preset where an invented fact becomes a commitment the owner never made.
  { label: 'Terms / Policy', seed: 'A [Terms of service / Privacy policy] page covering [what it has to cover]. Leave a [placeholder] anywhere I haven’t given you a fact — do not invent a company name, jurisdiction, retention period or guarantee.' },
];

export default {
  name: 'AssistantDrawer',
  components: { SelectMenu },
  props: {
    open: { type: Boolean, default: false },
    api: { type: Object, default: null },
    // [{ slug, label, shape }] — the agent-visible types, from the boot payload.
    types: { type: Array, default: () => [] },
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
      // The staged pipeline: "Write the article" fires every PART in parallel —
      // intro, one call per outline section, closing, plus one dressing call —
      // and assembles them in outline order. Progress lives on the outline
      // screen; a failed part retries alone instead of rerolling the article.
      // { outline: <the clean outline the parts were written against>,
      //   parts: { intro|conclusion|meta|s0..sN: { status, result, error } } }
      staging: null,
      stagingActive: false, // Requests in flight (staging itself survives failures).
      composeOrigin: 'idle', // Where composing started — errors and spinners return there.
      confirmReset: false, // "Start over" arms an inline confirm — it discards paid work.
      // What this drawer is composing. There is no edit mode any more: a post
      // that already exists is revised in the block editor, and the picker below
      // only opens it there.
      writeType: '',
      pick: { q: '', results: [], busy: false, error: '', type: '' },
      // Targeted revision of the held draft.
      refineText: '',
      refining: false,
      prevDraft: null, // One undo step: a revision overwrites a paid artifact.
      // Images deliberately have NO drawer UI anymore: proposed slots ride the
      // draft invisibly and land in the editor as alt-filled placeholder
      // blocks — the editor is the image workshop (generate, pick or delete).
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
        // Settle the chooser on a real slug the first time the drawer opens (or
        // after a restored session left it empty). Never overwrite a choice
        // that's already been made — a held draft was written in ITS type, and
        // reopening the drawer must not quietly re-file it.
        if (!this.writeType) this.writeType = this.defaultType;
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
    // One flag for "an AI call is in flight" — outline, compose and the staged
    // parts alike.
    busy() {
      return 'composing' === this.step || this.outlining || this.stagingActive;
    },
    // The type every API call carries — the chooser's, or the default when the
    // drawer hasn't been opened yet.
    activeType() {
      return this.writeType || this.defaultType;
    },
    // Posts when posts are visible, otherwise the first type that is — a site
    // that has switched posts off still gets a working assistant. Mirrors
    // Assistant::read_type() on the server, which has the final say anyway.
    defaultType() {
      if (!this.types.length) return 'post';
      const post = this.types.find((t) => 'post' === t.slug);
      return post ? post.slug : this.types[0].slug;
    },
    // The shape the CHOOSER is showing — and the one every word on the brief
    // screen describes, because those words sit directly under the control that
    // sets it. Deliberately NOT activeType: while an edit is held, that points at
    // the post being revised, so a chooser reading "Posts" was getting a note
    // underneath it about standing pages, and a row of page presets to match.
    // Two different questions were sharing one answer.
    writeShape() {
      const slug = this.writeType || this.defaultType;
      const found = this.types.find((t) => t.slug === slug);
      return found ? found.shape : 'article';
    },
    isPageShape() {
      return 'page' === this.writeShape;
    },
    // The chooser only earns its space when there's a real choice to make.
    showTypeChooser() {
      return this.types.length > 1;
    },
    // The noun the brief screen uses, so "Describe the post" doesn't greet
    // someone who just chose Page.
    typeNoun() {
      // The chooser's type, for the same reason as writeShape: this noun names
      // what the brief will produce, not what a held edit happens to be.
      const found = this.types.find((t) => t.slug === (this.writeType || this.defaultType));
      // The SINGULAR, lowercased: this noun always appears inside a sentence
      // about one thing ("Describe the post"), never as a heading.
      return found ? (found.singular || found.label).toLowerCase() : 'post';
    },
    // Is there anything for "Start over" to clear? A typed-but-unsent brief
    // counts: it's work, even though nothing has been paid for yet.
    hasWorkToClear() {
      return !!(this.draft || this.outline || this.prompt.trim());
    },
    // Which frames to offer, decided by the same shape that decides the prompt.
    presets() {
      return this.isPageShape ? PAGE_PRESETS : ARTICLE_PRESETS;
    },
    // SelectMenu takes { value, label }; the boot list speaks slug/label.
    typeOptions() {
      return this.types.map((t) => ({ value: t.slug, label: t.label }));
    },
    // The picker's filter leads with the no-filter option, because the list's
    // own default is everything you last touched, whatever type it was.
    filterOptions() {
      return [{ value: '', label: 'Everything' }].concat(this.typeOptions);
    },
    // What the picker's filter is currently narrowed to, for the line under it.
    filterLabel() {
      const found = this.types.find((t) => t.slug === this.pick.type);
      return found ? found.label : '';
    },
    // Staged progress, counted over every part (sections + intro + closing +
    // the dressing call).
    stagedTotal() {
      return this.staging ? Object.keys(this.staging.parts).length : 0;
    },
    stagedDone() {
      return this.staging
        ? Object.values(this.staging.parts).filter((p) => 'done' === p.status).length
        : 0;
    },
    stagedFailed() {
      return !!this.staging
        && !this.stagingActive
        && Object.values(this.staging.parts).some((p) => 'error' === p.status);
    },
    // The outline foot's primary act, staged-aware: writing shows live progress,
    // a failure round offers the targeted retry, a restored half-written article
    // offers to continue — and with no staging it stays "Write the article".
    writeLabel() {
      if (this.stagingActive) return `Writing… ${this.stagedDone}/${this.stagedTotal}`;
      if (this.stagedFailed) return 'Retry the failed parts';
      if (this.staging) return 'Continue writing';
      return this.isPageShape ? 'Write the page' : 'Write the article';
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
    // One honest sentence about what the editor will hold: images kept from
    // an edited post, and placeholders arriving for the writer's suggestions.
    imagesNote() {
      const slots = (this.draft && this.draft.images) || [];
      const filled = slots.filter((s) => s.attachment_id).length;
      const empty = slots.length - filled;
      const parts = [];
      if (filled) parts.push(`${filled} ${1 === filled ? 'image travels' : 'images travel'} with the post`);
      if (empty) parts.push(`${empty} ${1 === empty ? 'placeholder arrives' : 'placeholders arrive'} with alt text ready — generate, fill or delete ${1 === empty ? 'it' : 'them'} in the editor`);
      return parts.join('; ') + '.';
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
          // The type the held draft was WRITTEN in. Without it a page composed
          // last night comes back after a reload and gets created as a post —
          // the shape it was written in would survive only in the prose.
          writeType: this.writeType,
          // Additive on purpose (still v:1): an older build reading this stash
          // simply ignores the key and restores the outline — degraded, never
          // broken. Finished parts are paid work; in-flight ones restart.
          staging: this.staging ? {
            outline: this.staging.outline,
            parts: Object.fromEntries(Object.entries(this.staging.parts).map(([k, p]) => [
              k,
              'done' === p.status ? { status: 'done', result: p.result } : { status: 'pending' },
            ])),
          } : null,
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
        // Restore the type the draft was written in, but only if it's still one
        // the owner allows — a type switched off since last night falls back to
        // the default rather than resurrecting itself from localStorage.
        if ('string' === typeof held.writeType && this.types.some((t) => t.slug === held.writeType)) {
          this.writeType = held.writeType;
        }
        if (o) {
          this.outline = {
            title: o.title,
            sections: o.sections
              .filter((s) => s && 'string' === typeof s.heading)
              .map((s) => ({ heading: s.heading, note: 'string' === typeof s.note ? s.note : '' })),
          };
          this.usedOutline = !!held.usedOutline;
        }
        // A stash from the build that could revise a real post in here is
        // dropped rather than restored: there is nowhere to save it back to any
        // more, and reopening it would offer work that cannot be committed. The
        // post on the site was never touched — only the unsaved revision goes.
        if ('edit' === held.mode) {
          this.clearHeld();
          return;
        }
        if (draft) {
          ['topics', 'tags', 'categories', 'images'].forEach((k) => { if (!Array.isArray(draft[k])) draft[k] = []; });
          this.draft = draft;
          this.step = 'preview'; // Reopen exactly where they left off.
        } else {
          this.step = 'outline'; // Mid-outline when the tab closed — pick it back up.
        }
        // A half-written staged article: finished parts restore as done (paid
        // work), everything else as pending — "Continue writing" fires only
        // what's missing. Held to shape the same way the rest of the stash is.
        const st = held.staging;
        if (o && st && st.outline && Array.isArray(st.outline.sections) && st.parts && 'object' === typeof st.parts) {
          const parts = {};
          ['intro', 'conclusion', 'meta'].concat(st.outline.sections.map((s, i) => 's' + i)).forEach((k) => {
            const p = st.parts[k];
            const doneShape = p && 'done' === p.status
              && ('meta' === k ? (p.result && 'string' === typeof p.result.title) : 'string' === typeof p.result);
            parts[k] = doneShape ? { status: 'done', result: p.result, error: '' } : { status: 'pending', result: null, error: '' };
          });
          this.staging = { outline: st.outline, parts };
          this.step = 'outline'; // Mid-write when the tab closed — the progress screen.
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
        const r = await this.api.assistantOutline(brief, this.activeType);
        this.outline = r.outline;
        this.usedOutline = false;
        this.staging = null; // A new outline is a new plan — no old part fits it.
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
      this.outlineEdited();
    },
    removeSection(i) {
      if (!this.outline) return;
      this.outline.sections.splice(i, 1);
      this.outlineEdited();
    },
    // Any edit to the plan retires the parts written against it: every part's
    // prompt carries the WHOLE outline (that's where consistency comes from),
    // so no cached part survives a change to any of it. The progress marks
    // disappear with the staging — the screen never shows a stale ✓.
    outlineEdited() {
      if (this.staging && !this.stagingActive) this.staging = null;
      this.persistHeld();
    },
    // The outline as the server contract: trimmed, heading-less rows dropped,
    // and capped at the server's own section ceiling so a staged part index
    // can never point past what the server keeps.
    cleanOutline() {
      if (!this.outline) return null;
      const sections = this.outline.sections
        .map((s) => ({ heading: (s.heading || '').trim(), note: (s.note || '').trim() }))
        .filter((s) => s.heading)
        .slice(0, 10);
      return sections.length ? { title: (this.outline.title || '').trim(), sections } : null;
    },
    // ---- Compose --------------------------------------------------------------
    // An outlined article writes STAGED (one call per part, in parallel); only
    // the outline-less quick draft still writes as one whole document.
    async compose(useOutline) {
      if (useOutline) return this.composeStaged();
      const brief = this.prompt.trim();
      if (brief.length < 8 || this.busy) return;
      if (!(await this.confirmReplaceHeld())) return;
      this.composeOrigin = 'outline' === this.step ? 'outline' : 'idle';
      this.step = 'composing';
      this.error = '';
      try {
        const r = await this.api.assistantCompose(brief, null, this.activeType);
        this.prevDraft = this.draft || null; // "Draft again" overwrites too — same one-step undo.
        this.draft = r.draft;
        this.usedOutline = false;
        this.staging = null; // A whole-document draft supersedes any half-staged one.
        this.step = 'preview';
        this.persistHeld(); // A paid artifact — survives reloads until created or replaced.
      } catch (e) {
        this.error = (e && e.message) || 'The draft didn’t come back — please try again.';
        this.step = this.composeOrigin; // Fail back to wherever the click came from.
      }
    },
    // Composing over held work replaces it — the consequence lives in the
    // confirm, not in a floating caption. A first draft asks nothing.
    async confirmReplaceHeld() {
      if (!this.draft) return true;
      return confirm({
        title: 'Write a new draft?',
        message: `The new draft replaces the one you're holding.`,
        confirmLabel: 'Write it',
        within: '.ar-drawer__panel',
      });
    },
    // ---- The staged pipeline: one part per call, all parts at once -------------
    async composeStaged() {
      const brief = this.prompt.trim();
      const outline = this.cleanOutline();
      if (brief.length < 8 || !outline || this.busy) return;

      // The parts were written against ONE exact outline; if what's on screen
      // no longer matches it (an edit slipped past, an old stash), start clean.
      if (this.staging && JSON.stringify(this.staging.outline) !== JSON.stringify(outline)) {
        this.staging = null;
      }

      if (!this.staging) {
        if (!(await this.confirmReplaceHeld())) return;
        const parts = {};
        ['intro', 'conclusion', 'meta'].concat(outline.sections.map((s, i) => 's' + i))
          .forEach((k) => { parts[k] = { status: 'pending', result: null, error: '' }; });
        this.staging = { outline, parts };
      }

      const st = this.staging;
      this.step = 'outline'; // Progress lives where the plan lives.
      this.error = '';
      this.stagingActive = true;

      // The browser IS the parallelism: every unfinished part flies now. Each
      // landing persists — a mid-write reload resumes instead of re-paying.
      const fire = (key, call, pick) => {
        const p = st.parts[key];
        if ('done' === p.status) return null;
        p.status = 'busy';
        p.error = '';
        return call()
          .then((r) => {
            p.result = pick(r);
            p.status = 'done';
            if (this.staging === st) this.persistHeld();
          })
          .catch((e) => {
            p.status = 'error';
            p.error = (e && e.message) || 'This part didn’t come back — retry it below.';
          });
      };
      const calls = [
        fire('meta', () => this.api.assistantComposeMeta(brief, outline, this.activeType), (r) => r.meta),
        fire('intro', () => this.api.assistantComposeSection(brief, outline, 'intro', -1, this.activeType), (r) => r.html),
        fire('conclusion', () => this.api.assistantComposeSection(brief, outline, 'conclusion', -1, this.activeType), (r) => r.html),
      ].concat(outline.sections.map((s, i) =>
        fire('s' + i, () => this.api.assistantComposeSection(brief, outline, 'section', i, this.activeType), (r) => r.html)));

      await Promise.all(calls.filter(Boolean));
      if (this.staging !== st) return; // Cleared mid-flight (Start over) — nothing to land.
      this.stagingActive = false;
      this.persistHeld();
      this.assembleStaged();
    },
    // Every part in hand → the same draft document a whole-document compose
    // returns, assembled in outline order; the preview machinery takes over
    // unchanged. With parts still missing this quietly stands by for a retry.
    assembleStaged() {
      const st = this.staging;
      if (!st || Object.values(st.parts).some((p) => 'done' !== p.status)) return;
      const meta = st.parts.meta.result || {};
      const content = [st.parts.intro.result]
        .concat(st.outline.sections.map((s, i) => st.parts['s' + i].result))
        .concat([st.parts.conclusion.result])
        .join('\n');
      this.prevDraft = this.draft || null; // Same one-step undo as "Draft again".
      this.draft = {
        title: meta.title || st.outline.title,
        excerpt: meta.excerpt || '',
        content,
        description: meta.description || '',
        topics: meta.topics || [],
        tags: meta.tags || [],
        categories: meta.categories || [],
        images: meta.images || [],
      };
      this.usedOutline = true;
      this.staging = null;
      this.step = 'preview';
      this.persistHeld();
    },
    // The outline screen's progress marks: '' (no staging) | pending | busy |
    // done | error, for section rows and the three synthetic rows alike.
    partState(key) {
      const p = this.staging && this.staging.parts[key];
      return p ? p.status : '';
    },
    partError(key) {
      const p = this.staging && this.staging.parts[key];
      return p && 'error' === p.status ? p.error : '';
    },
    // A display row's staged part key. The two lists can disagree: the staged
    // outline dropped heading-less rows and capped the list, so the mark for
    // display row i belongs to the CLEANED index — and a row that didn't make
    // the cut ('' here) simply wears no mark.
    stagedKeyFor(i) {
      if (!this.staging || !this.outline) return '';
      const rows = this.outline.sections;
      if (!rows[i] || !(rows[i].heading || '').trim()) return '';
      let k = -1;
      for (let j = 0; j <= i; j += 1) {
        if (rows[j] && (rows[j].heading || '').trim()) k += 1;
      }
      return k < this.staging.outline.sections.length ? 's' + k : '';
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
      // Each act states its consequence before it runs; the dialog centers
      // inside the drawer, where the act lives.
      const ok = await confirm({
        title: 'Revise this draft?',
        message: 'The revision replaces the current version — one-step Undo brings it back. Nothing is saved to your site yet.',
        confirmLabel: 'Revise',
        within: '.ar-drawer__panel',
      });
      if (!ok) return;
      this.refining = true;
      this.error = '';
      // The version being revised. If the held draft is gone or different when the
      // call returns (Start over, Try again — anything that moved it), the result
      // has nothing to land on and is dropped — compose's staging guard, for refine.
      const held = this.draft;
      try {
        const r = await this.api.assistantRefine(held, instruction, this.activeType);
        if (this.draft !== held) return;
        this.prevDraft = held; // Keep the version being replaced — one-step undo.
        this.draft = r.draft;
        this.refineText = '';
        this.persistHeld();
      } catch (e) {
        if (this.draft === held) this.error = (e && e.message) || 'The revision didn’t come back — please try again.';
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
    // ---- Edit-existing: pick a post, work on it with the same machinery --------
    // A chip fills an empty box, and APPENDS to one that isn't — typing is never
    // destroyed by a click that only meant "give me a starting point". Focus
    // lands back in the textarea with the caret at the end, because the seed is
    // a sentence to finish, not an answer.
    applyPreset(p) {
      const current = this.prompt.trim();
      this.prompt = current ? current + '\n\n' + p.seed : p.seed;
      this.$nextTick(() => {
        const el = this.$refs.promptEl;
        if (!el) return;
        el.focus();
        el.setSelectionRange(el.value.length, el.value.length);
        el.scrollTop = el.scrollHeight;
      });
    },
    openPicker() {
      this.step = 'pick';
      this.error = '';
      // The filter resets with the picker: it's a way to narrow THIS search, not
      // a preference that follows you around.
      this.pick = { q: '', results: [], busy: false, error: '', type: '' };
      this.searchPosts();
    },
    async searchPosts() {
      this.pick.busy = true;
      this.pick.error = '';
      try {
        const r = await this.api.assistantPosts(this.pick.q.trim(), this.pick.type);
        this.pick.results = r.posts || [];
      } catch (e) {
        this.pick.error = (e && e.message) || 'Couldn’t search your posts.';
        this.pick.results = [];
      } finally {
        this.pick.busy = false;
      }
    },
    // ---- Create ---------------------------------------------------------------
    async create() {
      if (!this.draft || 'creating' === this.step || this.refining) return;
      const pending = 'pending' === this.statusChoice;
      const ok = await confirm({
        title: pending ? 'Submit for review?' : 'Create the draft?',
        message: pending
          ? 'A new post is created as pending review — nothing goes live until an editor publishes it.'
          : 'A new draft post is created with this content — nothing goes live.',
        confirmLabel: pending ? 'Create as pending' : 'Create draft',
        within: '.ar-drawer__panel',
      });
      if (!ok) return;
      this.step = 'creating';
      this.error = '';
      try {
        const r = await this.api.assistantCreate({
          type: this.activeType,
          status: this.statusChoice,
          title: this.draft.title,
          content: this.draft.content,
          excerpt: this.draft.excerpt,
          description: this.draft.description,
          topics: this.draft.topics,
          tags: this.draft.tags,
          categories: this.draft.categories,
          // ALL slots travel: the writer's image suggestions land in the post
          // as alt-filled placeholder blocks, ready for the editor's
          // per-block Generate (or a native pick, or deletion).
          images: (this.draft.images || [])
            .map((s) => ({ attachment_id: s.attachment_id, after_heading: s.after_heading, alt: s.alt })),
        });
        // The draft is real — nothing left to do here. Straight to the editor,
        // where the images (and everything else) are worked on.
        this.clearHeld();
        window.location.assign(r.post.editUrl);
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
      this.staging = null; // In-flight parts land into the detached object and die with it.
      this.stagingActive = false;
      this.composeOrigin = 'idle';
      this.pick = { q: '', results: [], busy: false, error: '' };
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
        <!-- Scrim: dims AND closes — nothing can be lost here (state persists
             to localStorage), so the click-away is a safe exit, not a hazard. -->
        <div class="ar-drawer__scrim" aria-hidden="true" @click="$emit('close')"></div>

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
            <!-- The launcher's spark, repeated: this badge is the first thing you
                 see after pressing it, so the two have to be the same mark. Drawn
                 here rather than shared because the sizes differ (16 vs 15) and a
                 component for one path would cost more than the duplication —
                 but they change together. Class name kept from the quill era. -->
            <span class="ar-drawer__quill" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 3.2c1.1 5.3 2.5 6.7 7.8 7.8-5.3 1.1-6.7 2.5-7.8 7.8-1.1-5.3-2.5-6.7-7.8-7.8 5.3-1.1 6.7-2.5 7.8-7.8Z" />
              </svg>
            </span>
            <div class="ar-drawer__titles">
              <h2 id="ar-assist-title" class="ar-drawer__title">Writing assistant</h2>
              <p class="ar-drawer__sub">Describe the {{ typeNoun }} — nothing is saved until you say so.</p>
            </div>
            <!-- When a screen has a way back, it sits with the close — the two
                 leave-this-screen acts share the corner. From the picker it
                 returns to the brief; from an edit it returns to the picker. -->
            <button
              v-if="'pick' === step"
              type="button"
              class="ar-linkbtn ar-drawer__back"
              :disabled="refining || 'creating' === step"
              @click="step = 'idle'"
            >← Back</button>
            <button type="button" class="ar-drawer__close" aria-label="Close the assistant" @click="$emit('close')">
              <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" /></svg>
            </button>
          </div>

          <div class="ar-drawer__body" :class="{ 'ar-drawer__body--footed': onFootedScreen }">
            <!-- ============ Brief ============ -->
            <template v-if="'idle' === step || ('composing' === step && 'outline' !== composeOrigin)">
              <!-- Both doors visible before anyone types: the type field on the
                   left is the write door, the link on the right is the revise
                   one. The type is chosen BEFORE the brief because it decides the
                   shape of the whole generation — an article gets an outline,
                   image slots and taxonomies; a page gets none of them and is
                   told to stop when it's done. Choosing afterwards would mean
                   re-writing, not re-filing.

                   The label and the field hide together when the site has made
                   only one type agent-visible — a chooser with one option is
                   furniture — but the revise door always stays. -->
              <!-- Same attached-label control the picker's filter uses — one
                   bordered object rather than a caption floating over a field.
                   The revise door shares the row: the two are alternatives to
                   each other, so they belong on one line. With no chooser to
                   show, the spacer keeps the link right-aligned on its own. -->
              <div class="ar-assist__typerow2">
                <div v-if="showTypeChooser" class="ar-inputgroup ar-assist__typefield">
                  <span class="ar-inputgroup__addon">Content type</span>
                  <SelectMenu
                    v-model="writeType"
                    :options="typeOptions"
                    :disabled="busy"
                    aria-label="What kind of content to write"
                  >
                    <template #leading>
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z" /><path d="M14 3v5h5M9 13h6M9 17h4" /></svg>
                    </template>
                  </SelectMenu>
                </div>
                <span v-else class="ar-assist__spacer"></span>
                <!-- "Open", not "Edit": this door leads to the editor now, and a
                     link that says Edit but navigates elsewhere is a small lie
                     told on every visit. -->
                <button type="button" class="ar-linkbtn ar-assist__editdoor" :disabled="busy" @click="openPicker">Open existing</button>
              </div>

              <p v-if="showTypeChooser" class="ar-assist__typenote">{{ isPageShape
                ? 'Written as a standing page — no sections invented to fill it, no image slots, no categories.'
                : 'Written as an article — an opening that answers, then sections.' }}</p>

              <label class="ar-assist__section" for="ar-assist-prompt">Describe what you want</label>
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

              <!-- Starting frames, not modes. A chip drops a scaffold into the box
                   and gets out of the way: nothing is stored, nothing is sent, and
                   every word stays editable — the brief remains the only thing the
                   model is told. They're shaped by what's being written, because
                   "SEO article" is meaningless on a Terms page and "About page" is
                   meaningless on a blog post. -->
              <div class="ar-assist__presets">
                <button
                  v-for="p in presets"
                  :key="p.label"
                  type="button"
                  class="ar-assist__preset"
                  :disabled="busy"
                  @click="applyPreset(p)"
                >{{ p.label }}</button>
              </div>

              <p class="ar-assist__hint">
                Be as specific as you like — audience, tone, length, language. Your site’s Content
                Guidelines are applied automatically. “Outline first” lets you shape the sections
                before the full draft is written — rerolling an outline costs far less than a draft.
                <span v-if="isPageShape">A page that states terms or promises will leave a [placeholder] wherever your brief doesn’t give it a fact, rather than inventing one.</span>
              </p>
              <p v-if="error" class="ar-assist__error" role="alert">{{ error }}</p>

              <!-- A composed draft is a PAID artifact: while one is held, say so and
                   keep the way back one click away — editing the brief must never
                   cost the generation. Drafting again is what replaces it. A held
                   EDIT of a real post says WHICH post — "your drafted post" would
                   misdescribe it. -->
              <div v-if="draft && !busy" class="ar-assist__heldcard">
                <span class="ar-assist__heldicon" aria-hidden="true">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 109-9 9 9 0 00-6.4 2.7L3 8" /><path d="M3 3v5h5M12 7v5l3.5 2" /></svg>
                </span>
                <div class="ar-assist__heldbody">
                  <p class="ar-assist__heldlabel">Continuing previous draft</p>
                  <!-- The draft's own title, not a description of it: after a day
                       away, "your drafted post" identifies nothing. -->
                  <p class="ar-assist__heldtitle">{{ draft.title || 'Untitled' }}</p>
                  <button type="button" class="ar-linkbtn" @click="restorePreview">Back to the draft →</button>
                </div>
              </div>
              <!-- Same promise for a shaped outline when no draft holds the spotlight. -->
              <div v-else-if="outline && !busy" class="ar-assist__heldcard">
                <span class="ar-assist__heldicon" aria-hidden="true">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" /></svg>
                </span>
                <div class="ar-assist__heldbody">
                  <p class="ar-assist__heldlabel">Outline still here</p>
                  <p class="ar-assist__heldtitle">{{ outline.title || 'Untitled' }}</p>
                  <button type="button" class="ar-linkbtn" @click="step = 'outline'">Back to the outline →</button>
                </div>
              </div>

              <!-- The armed confirm REPLACES this row rather than joining it —
                   the same swap both pinned feet do. Sharing the row meant a
                   long question and four controls competing for one line, which
                   wrapped "Yes, clear it" into three. A question deserves the
                   width of a question. -->
              <div v-if="!confirmReset" class="ar-assist__actions">
                <!-- The screen's only way out, in the row where the other
                     decisions are. Appears once there is anything to clear — a
                     typed brief counts, not just paid work — behind the inline
                     confirm, because one stray click must not cost a generation. -->
                <button
                  v-if="hasWorkToClear"
                  type="button"
                  class="ar-linkbtn ar-assist__resetlink"
                  :disabled="busy"
                  @click="confirmReset = true"
                >Start over</button>
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
              <!-- Same words and classes as the outline and preview feet — one
                   confirm exists in this drawer, not three lookalikes. -->
              <div v-else class="ar-assist__actions">
                <span class="ar-assist__resettext">Clear everything here and start fresh? Nothing on your site is affected.</span>
                <span class="ar-assist__spacer"></span>
                <button type="button" class="ar-linkbtn ar-assist__resetdanger" @click="confirmStartOver">Yes, clear it</button>
                <button type="button" class="ar-linkbtn" @click="confirmReset = false">Keep it</button>
              </div>

            </template>

            <!-- ============ Pick a post ============ -->
            <template v-else-if="'pick' === step">
              <!-- The way back lives in the drawer header, beside the close. -->
              <label class="ar-assist__label" for="ar-pick-q">Open something you’ve written</label>
              <div class="ar-assist__librow">
                <input
                  id="ar-pick-q"
                  v-model="pick.q"
                  type="text"
                  class="ar-assist__refineinput"
                  placeholder="Search your content"
                  aria-label="Search your content"
                  @keydown.enter="searchPosts"
                />
                <button type="button" class="ar-btn ar-assist__refinebtn" :disabled="pick.busy" @click="searchPosts">
                  {{ pick.busy ? 'Searching…' : 'Search' }}
                </button>
              </div>

              <!-- The filter sits under the search, not beside it: it narrows the
                   list, it doesn't run it. "Everything" is the default because the
                   list is what you last touched, whatever type that was. Shown
                   only when there's more than one type to choose between. -->
              <!-- Label attached to the control rather than floating above it:
                   one bordered object that reads as a single sentence, and the
                   row stops competing with the search field above it. -->
              <div v-if="showTypeChooser" class="ar-inputgroup ar-assist__filtergroup">
                <span class="ar-inputgroup__addon">Filter by type</span>
                <!-- Not v-model: the search has to run AFTER the filter lands,
                     so the assignment and the fetch are one explicit handler
                     rather than two listeners racing on the same event. -->
                <SelectMenu
                  :model-value="pick.type"
                  :options="filterOptions"
                  :disabled="pick.busy"
                  aria-label="Filter the list by type"
                  @update:model-value="(v) => { pick.type = v; searchPosts(); }"
                />
              </div>

              <!-- The unsearched list explains itself; once a query is typed the
                   results are matches, so the line steps aside. -->
              <p v-if="!pick.q.trim() && !pick.type" class="ar-assist__hint">
                Your ten most recently edited posts and pages — search finds anything on your site.
              </p>
              <p v-else-if="!pick.q.trim()" class="ar-assist__hint">
                Your ten most recently edited, filtered to {{ filterLabel }} — search finds anything of that type.
              </p>

              <p v-if="pick.error" class="ar-assist__error" role="alert">{{ pick.error }}</p>
              <!-- Real links, not click handlers. An <a> gives cmd-click,
                   middle-click, "open in new window" and the browser's own
                   new-tab handling for free; window.open() gives a popup blocker
                   and none of them. Every row is reachable — the list stopped
                   refusing rows the moment it stopped rewriting them. -->
              <div v-else-if="pick.results.length" class="ar-assist__postlist">
                <a
                  v-for="row in pick.results"
                  :key="row.id"
                  :href="row.editUrl"
                  class="ar-assist__postitem"
                  target="_blank"
                  rel="noopener"
                >
                  <span class="ar-assist__posttitle">{{ row.title }}</span>
                  <span class="ar-assist__postmeta">
                    <span class="ar-assist__chip">{{ row.statusLabel }}</span>
                    <!-- A mixed list has to say which is which, or "Terms and
                         Conditions" sitting under a heading about posts is the
                         screen telling a small lie. Dropped once the list is
                         already filtered to one type — it says so above. -->
                    <span v-if="!pick.type && row.typeLabel" class="ar-assist__chip">{{ row.typeLabel }}</span>
                    <span>{{ row.date }}</span>
                    <span class="ar-assist__postgo" aria-hidden="true">Opens in the editor ↗</span>
                  </span>
                </a>
              </div>
              <p v-else-if="!pick.busy" class="ar-assist__hint">Nothing matched — try another word.</p>

              <p class="ar-assist__hint">
                Each of these opens in the block editor, in a new tab — the assistant’s
                <strong>Ask&nbsp;AI</strong> panel is there, on any block you select, so a change can
                be aimed at one paragraph instead of the whole page. Nothing here is opened, locked
                or altered by this list.
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
                @change="outlineEdited"
              />

              <label class="ar-assist__label">Sections</label>
              <!-- Staged write: the bookends appear as progress rows AROUND the
                   sections — the outline screen becomes the article filling in. -->
              <div v-if="staging" class="ar-assist__opart ar-assist__opart--lead">
                <span class="ar-assist__opartlabel">Introduction</span>
                <span class="ar-assist__omark" :class="'is-' + partState('intro')" aria-hidden="true"></span>
                <p v-if="partError('intro')" class="ar-assist__oparterror" role="alert">{{ partError('intro') }}</p>
              </div>
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
                      @change="outlineEdited"
                    />
                    <span v-if="stagedKeyFor(i)" class="ar-assist__omark" :class="'is-' + partState(stagedKeyFor(i))" aria-hidden="true"></span>
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
                    @change="outlineEdited"
                  ></textarea>
                  <p v-if="partError(stagedKeyFor(i))" class="ar-assist__oparterror ar-assist__oparterror--insec" role="alert">{{ partError(stagedKeyFor(i)) }}</p>
                </div>
              </div>
              <template v-if="staging">
                <div class="ar-assist__opart">
                  <span class="ar-assist__opartlabel">Closing</span>
                  <span class="ar-assist__omark" :class="'is-' + partState('conclusion')" aria-hidden="true"></span>
                  <p v-if="partError('conclusion')" class="ar-assist__oparterror" role="alert">{{ partError('conclusion') }}</p>
                </div>
                <div class="ar-assist__opart">
                  <span class="ar-assist__opartlabel">Title, description &amp; tags</span>
                  <span class="ar-assist__omark" :class="'is-' + partState('meta')" aria-hidden="true"></span>
                  <p v-if="partError('meta')" class="ar-assist__oparterror" role="alert">{{ partError('meta') }}</p>
                </div>
              </template>
              <button v-else type="button" class="ar-linkbtn" :disabled="busy" @click="addSection">+ Add a section</button>

              <p v-if="staging" class="ar-assist__hint">
                Every part is written at once — the introduction, each section, the closing and the
                title details — and assembled in this order. A part that fails retries alone.
                Editing the outline now starts the article over.
              </p>
              <p v-else class="ar-assist__hint">
                The article will follow this outline exactly — one section per row, in this order,
                these headings. Shape it first; a fresh outline costs far less than a full draft.
              </p>

              <div v-if="draft && !busy" class="ar-assist__held">
                <span class="ar-assist__heldtext">{{ staging ? 'Your earlier draft is still here — finishing this one replaces it.' : `Your drafted ${typeNoun} is still here — “${writeLabel}” replaces it.` }}</span>
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
                    <span v-if="stagingActive" class="ar-spinner ar-assist__spin" aria-hidden="true"></span>
                    {{ writeLabel }}
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

              <!-- Images have no drawer UI on purpose: the editor is the image
                   workshop. This line just says what will be waiting there. -->
              <div v-if="draft.images && draft.images.length" class="ar-assist__meta ar-assist__imagesnote">
                <div class="ar-assist__metarow">
                  <span class="ar-assist__metakey">Images</span>
                  <span class="ar-assist__metaval">{{ imagesNote }}</span>
                </div>
              </div>

              <!-- Pinned foot, same as the outline screen: the article scrolls,
                   the way out never does. The revise bar lives INSIDE it — it's
                   the screen's primary act, and in-flow above the sticky foot it
                   opened hidden beneath the fold on any long post. Then the two
                   deliberate rows — navigation links, then the commit act. -->
              <div class="ar-assist__foot">
                <p v-if="error" class="ar-assist__error ar-assist__footerror" role="alert">{{ error }}</p>
                <!-- Targeted revision: one instruction, one AI call, the draft revised in
                     place — with a one-step Undo, because a revision overwrites a paid
                     generation. "Try again" stays the full reroll. -->
                <div class="ar-assist__refine">
                  <input
                    v-model="refineText"
                    type="text"
                    class="ar-assist__refineinput"
                    :disabled="refining || 'creating' === step"
                    placeholder="Ask for a change — e.g. shorten the intro"
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
                <template v-if="!confirmReset">
                  <!-- Edit mode has no sibling links, so Start over joins the
                       commit row instead of sitting stranded on its own line. -->
                  <div v-if="'write' === mode" class="ar-assist__footrow">
                    <button type="button" class="ar-linkbtn ar-assist__resetlink" :disabled="refining || 'creating' === step" @click="confirmReset = true">Start over</button>
                    <span class="ar-assist__spacer"></span>
                    <button type="button" class="ar-linkbtn" :disabled="refining || 'creating' === step" @click="editBrief">Edit the brief</button>
                    <button v-if="outline" type="button" class="ar-linkbtn" :disabled="refining || 'creating' === step" @click="step = 'outline'">Edit the outline</button>
                    <button type="button" class="ar-linkbtn" :disabled="refining || 'creating' === step" @click="compose(usedOutline)">Try again</button>
                  </div>
                  <div class="ar-assist__footrow ar-assist__footrow--commit">
                    <span class="ar-assist__spacer"></span>
                    <select v-model="statusChoice" class="ar-assist__status" :disabled="refining || 'creating' === step" aria-label="Save as">
                      <option value="draft">as a draft</option>
                      <option value="pending">as pending review</option>
                    </select>
                    <button type="button" class="ar-btn ar-assist__go" :disabled="refining || 'creating' === step" @click="create">
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
                <h3 class="ar-assist__donetitle">{{ statusLabel(post.status) + ' created' }}</h3>
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


          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>
