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
import { confirm } from '../confirm.js';

const HELD_KEY = 'agentimus:assistant:held';
const HELD_TTL_MS = 7 * 24 * 3600 * 1000;

export default {
  name: 'AssistantDrawer',
  props: {
    open: { type: Boolean, default: false },
    api: { type: Object, default: null },
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
      // Edit-existing: 'write' composes a new post, 'edit' revises a real one.
      // The picked post's identity rides here; its content becomes `draft` and
      // the whole preview/refine/undo machinery works on it unchanged.
      mode: 'write', // write | edit
      editing: null, // { id, status, statusLabel, title }
      pick: { q: '', results: [], busy: false, error: '' },
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
      return 'Write the article';
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
          mode: this.mode,
          editing: this.editing,
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
        if (o) {
          this.outline = {
            title: o.title,
            sections: o.sections
              .filter((s) => s && 'string' === typeof s.heading)
              .map((s) => ({ heading: s.heading, note: 'string' === typeof s.note ? s.note : '' })),
          };
          this.usedOutline = !!held.usedOutline;
        }
        // An interrupted edit session restores as one — but only with its post
        // identity AND its document both intact; anything less falls back to write.
        if (draft && 'edit' === held.mode && held.editing && held.editing.id && 'string' === typeof held.editing.title) {
          this.mode = 'edit';
          this.editing = {
            id: held.editing.id,
            status: held.editing.status || 'draft',
            statusLabel: held.editing.statusLabel || 'Draft',
            title: held.editing.title,
          };
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
      this.mode = 'write'; // Outlining starts a WRITE path.
      this.editing = null;
      try {
        const r = await this.api.assistantOutline(brief);
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
      this.mode = 'write'; // Composing writes a NEW post, whatever came before.
      this.editing = null;
      try {
        const r = await this.api.assistantCompose(brief, null);
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
      const editHeld = 'edit' === this.mode && this.editing;
      return confirm({
        title: 'Write a new draft?',
        message: editHeld
          ? `The new draft replaces the edit of “${this.editing.title}” you're holding — the post itself on your site is untouched.`
          : `The new draft replaces the one you're holding.`,
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
      this.mode = 'write';
      this.editing = null;
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
        fire('meta', () => this.api.assistantComposeMeta(brief, outline), (r) => r.meta),
        fire('intro', () => this.api.assistantComposeSection(brief, outline, 'intro'), (r) => r.html),
        fire('conclusion', () => this.api.assistantComposeSection(brief, outline, 'conclusion'), (r) => r.html),
      ].concat(outline.sections.map((s, i) =>
        fire('s' + i, () => this.api.assistantComposeSection(brief, outline, 'section', i), (r) => r.html)));

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
    // ---- Edit-existing: pick a post, work on it with the same machinery --------
    openPicker() {
      this.step = 'pick';
      this.error = '';
      this.pick = { q: '', results: [], busy: false, error: '' };
      this.searchPosts();
    },
    async searchPosts() {
      this.pick.busy = true;
      this.pick.error = '';
      try {
        const r = await this.api.assistantPosts(this.pick.q.trim());
        this.pick.results = r.posts || [];
      } catch (e) {
        this.pick.error = (e && e.message) || 'Couldn’t search your posts.';
        this.pick.results = [];
      } finally {
        this.pick.busy = false;
      }
    },
    async pickPost(row) {
      if (!row.compatible || this.pick.busy) return;
      this.pick.busy = true;
      this.pick.error = '';
      try {
        const r = await this.api.assistantPost(row.id);
        const doc = r.post;
        this.mode = 'edit';
        this.editing = { id: doc.id, status: doc.status, statusLabel: doc.statusLabel, title: doc.title };
        // The fetched document IS a draft — the preview/refine/undo machinery
        // takes it from here unchanged. Images ride the slots invisibly and
        // return to the post on update; the editor is where they're worked on.
        this.draft = {
          title: doc.title,
          excerpt: doc.excerpt,
          content: doc.content,
          description: doc.description,
          topics: doc.topics || [],
          tags: doc.tags || [],
          categories: doc.categories || [],
          images: (doc.images || []).map(({ url, ...slot }) => slot),
        };
        this.prevDraft = null;
        this.refineText = '';
        this.outline = null;
        this.usedOutline = false;
        this.staging = null; // An edit session writes nothing staged.
        this.step = 'preview';
        this.persistHeld();
      } catch (e) {
        this.pick.error = (e && e.message) || 'Couldn’t open that post.';
      } finally {
        this.pick.busy = false;
      }
    },
    // The ONLY write of the edit flow — and it never carries a status: the
    // assistant edits content, not visibility. WordPress keeps the previous
    // version in Revisions on top of the drawer's own one-step Undo.
    async update() {
      if (!this.draft || !this.editing || 'creating' === this.step) return;
      const published = 'publish' === this.editing.status;
      const isDraft = 'draft' === this.editing.status;
      const ok = await confirm({
        title: published ? 'Update the published post?' : (isDraft ? 'Update the draft?' : 'Update the post?'),
        message: published
          ? 'The changes go live on your site immediately. The previous version is kept in Revisions.'
          : `The post is updated and stays ${this.editing.statusLabel.toLowerCase()}. The previous version is kept in Revisions.`,
        confirmLabel: published ? 'Update — it goes live' : 'Update',
        within: '.ar-drawer__panel',
      });
      if (!ok) return;
      this.step = 'creating';
      this.error = '';
      try {
        const r = await this.api.assistantUpdate({
          id: this.editing.id,
          title: this.draft.title,
          content: this.draft.content,
          excerpt: this.draft.excerpt,
          description: this.draft.description,
          topics: this.draft.topics,
          tags: this.draft.tags,
          categories: this.draft.categories,
          // ALL slots travel: filled ones return as real figures, bare ones
          // as alt-only placeholders — nothing is silently dropped.
          images: (this.draft.images || [])
            .map((s) => ({ attachment_id: s.attachment_id, after_heading: s.after_heading, alt: s.alt })),
        });
        this.post = r.post;
        this.step = 'done';
        this.clearHeld();
        this.$emit('flash', 'success', 'Post updated — the previous version is in Revisions.');
      } catch (e) {
        this.error = (e && e.message) || 'Couldn’t update the post — please try again.';
        this.step = 'preview'; // The revised document is still good; only the write failed.
      }
    },
    // ---- Create ---------------------------------------------------------------
    async create() {
      if (!this.draft || 'creating' === this.step) return;
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
          type: 'post',
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
      this.mode = 'write';
      this.editing = null;
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
            <!-- When a screen has a way back, it sits with the close — the two
                 leave-this-screen acts share the corner. From the picker it
                 returns to the brief; from an edit it returns to the picker. -->
            <button
              v-if="'pick' === step || ('edit' === mode && ('preview' === step || 'creating' === step))"
              type="button"
              class="ar-linkbtn ar-drawer__back"
              :disabled="'creating' === step"
              @click="'pick' === step ? (step = 'idle') : openPicker()"
            >← Back</button>
            <button type="button" class="ar-drawer__close" aria-label="Close the assistant" @click="$emit('close')">
              <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" /></svg>
            </button>
          </div>

          <div class="ar-drawer__body" :class="{ 'ar-drawer__body--footed': onFootedScreen }">
            <!-- ============ Brief ============ -->
            <template v-if="'idle' === step || ('composing' === step && 'outline' !== composeOrigin)">
              <!-- Both doors visible before anyone types: write on the left,
                   the second door (revise something that exists) on the right. -->
              <div class="ar-assist__labelrow">
                <label class="ar-assist__label" for="ar-assist-prompt">What should it write?</label>
                <p class="ar-assist__editentry ar-assist__editentry--top">
                  <button type="button" class="ar-linkbtn" :disabled="busy" @click="openPicker">Edit an existing post</button>
                </p>
              </div>
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
                   cost the generation. Drafting again is what replaces it. A held
                   EDIT of a real post says WHICH post — "your drafted post" would
                   misdescribe it. -->
              <div v-if="draft && !busy" class="ar-assist__held">
                <span v-if="'edit' === mode && editing" class="ar-assist__heldtext">Still editing “{{ editing.title }}” — nothing was lost.</span>
                <span v-else class="ar-assist__heldtext">Your drafted post is still here — nothing was lost.</span>
                <button type="button" class="ar-linkbtn" @click="restorePreview">{{ 'edit' === mode && editing ? 'Back to the post' : 'Back to the preview' }}</button>
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

            </template>

            <!-- ============ Pick a post ============ -->
            <template v-else-if="'pick' === step">
              <!-- The way back lives in the drawer header, beside the close. -->
              <label class="ar-assist__label" for="ar-pick-q">Edit an existing post</label>
              <div class="ar-assist__librow">
                <input
                  id="ar-pick-q"
                  v-model="pick.q"
                  type="text"
                  class="ar-assist__refineinput"
                  placeholder="Search your posts"
                  aria-label="Search your posts"
                  @keydown.enter="searchPosts"
                />
                <button type="button" class="ar-btn ar-assist__refinebtn" :disabled="pick.busy" @click="searchPosts">
                  {{ pick.busy ? 'Searching…' : 'Search' }}
                </button>
              </div>

              <!-- The unsearched list explains itself; once a query is typed the
                   results are matches, so the line steps aside. -->
              <p v-if="!pick.q.trim()" class="ar-assist__hint">
                Your ten most recently edited posts — search finds any post on your site.
              </p>

              <p v-if="pick.error" class="ar-assist__error" role="alert">{{ pick.error }}</p>
              <div v-else-if="pick.results.length" class="ar-assist__postlist">
                <button
                  v-for="row in pick.results"
                  :key="row.id"
                  type="button"
                  class="ar-assist__postitem"
                  :class="{ 'is-blocked': !row.compatible }"
                  :disabled="!row.compatible || pick.busy"
                  @click="pickPost(row)"
                >
                  <span class="ar-assist__posttitle">{{ row.title }}</span>
                  <span class="ar-assist__postmeta">
                    <span class="ar-assist__chip">{{ row.statusLabel }}</span>
                    <span>{{ row.date }}</span>
                  </span>
                  <span v-if="!row.compatible" class="ar-assist__postreason">This post {{ row.reason }}.</span>
                </button>
              </div>
              <p v-else-if="!pick.busy" class="ar-assist__hint">Nothing matched — try another word.</p>

              <p class="ar-assist__hint">
                The assistant revises the post’s text and keeps its images. Its status never changes
                here — a draft stays a draft, a published post stays published, and WordPress keeps
                the previous version in Revisions.
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
                <span class="ar-assist__heldtext">{{ staging ? 'Your earlier draft is still here — finishing this article replaces it.' : 'Your drafted post is still here — “Write the article” replaces it.' }}</span>
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
              <!-- Editing a real post: say WHICH one, and that visibility is
                   untouchable here — the one rule of the edit flow. -->
              <!-- The way back lives in the drawer header's ← Back, beside the close. -->
              <div v-if="'edit' === mode && editing" class="ar-assist__held ar-assist__editingbar">
                <span class="ar-assist__heldtext">
                  Editing “{{ editing.title }}” — it stays {{ editing.statusLabel.toLowerCase() }};
                  the previous version is kept in Revisions.
                </span>
              </div>

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
                    <button type="button" class="ar-linkbtn ar-assist__resetlink" :disabled="'creating' === step" @click="confirmReset = true">Start over</button>
                    <span class="ar-assist__spacer"></span>
                    <button type="button" class="ar-linkbtn" :disabled="'creating' === step" @click="editBrief">Edit the brief</button>
                    <button v-if="outline" type="button" class="ar-linkbtn" :disabled="'creating' === step" @click="step = 'outline'">Edit the outline</button>
                    <button type="button" class="ar-linkbtn" :disabled="'creating' === step" @click="compose(usedOutline)">Try again</button>
                  </div>
                  <div class="ar-assist__footrow ar-assist__footrow--commit">
                    <button v-if="'edit' === mode" type="button" class="ar-linkbtn ar-assist__resetlink" :disabled="'creating' === step" @click="confirmReset = true">Start over</button>
                    <span class="ar-assist__spacer"></span>
                    <template v-if="'write' === mode">
                      <select v-model="statusChoice" class="ar-assist__status" :disabled="'creating' === step" aria-label="Save as">
                        <option value="draft">as a draft</option>
                        <option value="pending">as pending review</option>
                      </select>
                      <button type="button" class="ar-btn ar-assist__go" :disabled="'creating' === step" @click="create">
                        <span v-if="'creating' === step" class="ar-spinner ar-assist__spin" aria-hidden="true"></span>
                        {{ 'creating' === step ? 'Creating…' : 'Create draft' }}
                      </button>
                    </template>
                    <template v-else>
                      <!-- Editing a draft: "Update draft" says it all, no note
                           needed. Any other status keeps the generic button and
                           the "stays …" note — on a published post that note is
                           the warning that the change goes live on update. -->
                      <span v-if="editing && 'draft' !== editing.status" class="ar-assist__statusnote">stays {{ editing.statusLabel.toLowerCase() }}</span>
                      <button type="button" class="ar-btn ar-assist__go" :disabled="'creating' === step" @click="update">
                        <span v-if="'creating' === step" class="ar-spinner ar-assist__spin" aria-hidden="true"></span>
                        {{ 'creating' === step ? 'Updating…' : (editing && 'draft' === editing.status ? 'Update draft' : 'Update post') }}
                      </button>
                    </template>
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
                <h3 class="ar-assist__donetitle">{{ 'edit' === mode ? 'Post updated' : statusLabel(post.status) + ' created' }}</h3>
                <p v-if="'edit' === mode" class="ar-assist__donetext">
                  “{{ post.title }}” was updated — its status didn’t change, and the previous
                  version is saved under Revisions in the editor.
                </p>
                <p v-else class="ar-assist__donetext">
                  “{{ post.title }}” is waiting {{ 'pending' === post.status ? 'for review' : 'in your drafts' }} —
                  nothing is published.
                </p>
                <div class="ar-assist__doneactions">
                  <a class="ar-btn" :href="post.editUrl" target="_blank" rel="noopener noreferrer">Open in editor</a>
                  <button type="button" class="ar-btn ar-btn--ghost" @click="writeAnother">{{ 'edit' === mode ? 'Start another' : 'Write another' }}</button>
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
