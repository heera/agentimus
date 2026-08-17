<script>
import { relTimeShort } from '../js/wpDate.js';
import { authWords, isOpenToAnyone } from '../js/authWords.js';

/**
 * Which failed-address warnings the owner has read and closed, kept per row as
 * the VERDICT they dismissed. localStorage, like the assistant drawer's held
 * draft: this is one person's reading state on one browser, not a site setting —
 * it must never travel to another admin or into the database.
 *
 * ⛔ Every read and write is guarded: Safari in private mode throws on
 * localStorage, and a row that cannot remember a dismissal must still render.
 */
const DISMISSED_KEY = 'agentimus.discovery.dismissed';

function readDismissed() {
  try {
    return JSON.parse(window.localStorage.getItem(DISMISSED_KEY) || '{}') || {};
  } catch (e) {
    return {};
  }
}

function writeDismissed(id, signature) {
  try {
    const all = readDismissed();
    if (signature) all[id] = signature;
    else delete all[id];
    window.localStorage.setItem(DISMISSED_KEY, JSON.stringify(all));
  } catch (e) {
    /* No memory available — the row simply opens again next time. */
  }
}

// One row in "What Your Site Offers AI Assistants" — the whole of one
// registered thing, in one place.
//
// ⭐⭐ WHY IT HOLDS EVERYTHING NOW (his call, 2026-08-17). The screen used to
// describe the same thing twice: a row up top saying "25 things an AI assistant
// can do here — listed further down", and the twenty-five things further down.
// A row whose only content is a signpost to another part of the same page. So a
// row now carries its own addresses AND its own jobs, and the second list is
// gone. Nothing on this screen is described in two places.
//
// ⭐ THE THREE LAYERS, so one screen serves a shop owner and a developer alike:
// the summary strip is read by everyone; the closed row is a name, a plain line
// and a state anyone can act on; the open row is for whoever wants the addresses,
// the verdicts and the job names. Nobody is walked through detail they did not
// ask for, and nobody is denied it.
export default {
  name: 'ProviderRow',
  props: {
    r: { type: Object, required: true },
    // True when an earlier row already carries the full sign-in explanation —
    // this row defers to it instead of repeating the same paragraph verbatim.
    briefHeld: { type: Boolean, default: false },
    // True when this row is the owner's to switch off. The site's own content and
    // a bare data door are not: one is steered by Content types, the other is
    // already an opt-in.
    controllable: { type: Boolean, default: false },
    published: { type: Boolean, default: true },
    // The ways in to this row's jobs, named by the parent (only it can see the
    // detected MCP servers). Empty for a row that has no jobs.
    doors: { type: Array, default: () => [] },
    // ⭐ FALSE where every row in the group is the same kind. In "Things
    // Assistants Can Do" the badge read JOBS on all five rows — a label that
    // never varies inside its own section is furniture, and the section heading
    // already said it. In "…Can Read" it differs on every row (content, shop,
    // bookings, contacts, directory) and earns its place.
    // ⛔ Told by the parent rather than re-derived here: the parent is the one
    // that knows which list it is rendering, and a second rule for the same
    // question is a second rule to keep in step.
    showKind: { type: Boolean, default: true },
  },
  emits: ['toggle-publish'],
  data() {
    return { open: false };
  },
  created() {
    // ⭐ BAD NEWS TAKES THE SEAT — his standing law, applied here as the one
    // reason a row opens itself. An address that failed its check is the only
    // state on this screen that is both unexpected and actionable; "needs a
    // sign-in" is normal and by design, and opening every one of those would
    // unfold most of the page for nothing.
    //
    // ⚠️⚠️ BUT ONCE, NOT FOREVER. It re-opened on every single load, so closing
    // it achieved nothing and the warning became furniture — he asked "why do I
    // ALWAYS see this expanded", which is the question a notice gets when it has
    // stopped being news. Closing it now sticks. ⭐ AND IT UNSTICKS ITSELF WHEN
    // THE NEWS CHANGES: what is remembered is the VERDICT, not the row, so a
    // different failure — or a failure on an address that was fine — opens the
    // row again. Dismissing "this address is gone" is not dismissing whatever
    // happens next.
    this.open = this.state.key === 'held' && this.verdictSignature !== readDismissed()[this.r.id];
  },
  computed: {
    // The badge in the owner's words. The type is the spec's vocabulary (and on
    // a declared row, the vendor's own choice) — it stays exactly as written in
    // the served file. This is only what a shop owner is shown.
    // ⛔ Anything we have no plain word for prints as it came: inventing a
    // friendly label for a word we do not know is how a screen starts lying.
    typeLabel() {
      const plain = {
        agent: 'jobs',
        content: 'content',
        commerce: 'shop',
        crm: 'contacts',
        scheduling: 'bookings',
        search: 'search',
        media: 'media',
        directory: 'directory',
        forms: 'forms',
        // ⭐ The one kind in the vocabulary whose own word is jargon. A resource
        // of this kind IS the sign-in service — it is not a statement that
        // something needs one, which is what the state pill on the right says.
        // The other kinds we have no entry for (courses, messaging, analytics,
        // payments) are already plain English and print as they came.
        auth: 'sign-in',
      };
      return plain[this.r.type] || this.r.type;
    },
    // The engine names are the specs' own ("REST API", "Abilities API"). On a
    // screen read by shop owners they need saying in words, once, here.
    foundWhere() {
      if (this.r.engine === 'Abilities API') return 'in the list of jobs your plugins registered with WordPress';
      if (this.r.engine === 'REST API') return 'by reading your site’s own data doors';
      return 'by reading your site';
    },
    endpoints() {
      return this.r.endpoints || [];
    },
    // Addresses this site would not stand behind — each already carries its own
    // sentence from the background check.
    heldAddresses() {
      return this.endpoints.filter((e) => e.open && e.open.published === false);
    },
    jobs() {
      return (this.r.toolList || []).filter((t) => t.kind !== 'resource');
    },
    // ⭐ ONE STATE PER ROW, in the owner's words, and never a word that is only
    // true of some of it. Ordered by what outranks what: the owner's own switch
    // first, then a door that failed, then what the row needs from a visitor.
    // ⭐⭐ ONE WORD FOR ONE IDEA. The page said "held back", "not visible", "not
    // listed", "not published", "switched off" and "out of your public files" —
    // eight phrasings for a single state. A native reader enjoys the variety; a
    // reader whose English is a second language counts eight states and tries to
    // tell them apart. ⭐ THE RULE: if it is in the file, say who may read it; if
    // it is not, say "Not listed" and why. Two answers to one question, and the
    // words never vary.
    state() {
      if (!this.controllable && this.r.suppressed) {
        return { key: 'off', label: 'Not listed · you switched it off' };
      }
      if (this.controllable && !this.published) {
        return { key: 'off', label: 'Not listed · you switched it off' };
      }
      // ⚠️ THE CAUSE CAME OFF THE PILL. It read "Not published · address is gone",
      // and "address is gone" said about a row named *Demo Newsletter* is a
      // sentence about the wrong subject — the row is a thing, the address is one
      // detail inside it. ⭐ The pill states the row's STATE; the cause belongs to
      // the address, where it already sits with its evidence and its verdict.
      if (this.heldAddresses.length) {
        return { key: 'held', label: 'Not listed' };
      }
      if (this.r.notPublic) {
        return { key: 'signin', label: 'Not listed · needs a sign-in' };
      }
      if (this.endpoints.length) {
        const open = this.endpoints.every((e) => e.auth === 'none');
        return open
          ? { key: 'open', label: 'Anyone can read this' }
          : { key: 'signin', label: 'Needs a key' };
      }
      return { key: 'listed', label: 'Listed' };
    },
    // The one line under the name. A row with an address says what lives there,
    // in its own words; a row of jobs has no such sentence to borrow, so it says
    // how many there are and how many of them CHANGE something — which is the
    // one thing an owner wants to know before reading further.
    oneLine() {
      if (this.r.description && !this.isJobsOnly) return this.r.description;
      if (!this.r.tools) return '';
      const n = this.r.tools;
      const jobs = `${n} ${n === 1 ? 'job' : 'jobs'}`;
      if (this.r.changes > 0 && this.r.reads > 0) {
        return `${jobs} · ${this.r.reads} only read, ${this.r.changes} change something`;
      }
      if (this.r.changes > 0) return `${jobs} · ${this.r.changes} change something`;
      if (this.r.reads > 0) return `${jobs} · all of them only read`;
      return jobs;
    },
    // ⭐⭐ DIMMED MEANS "NOT DOING ITS JOB", NOT "not in your public files" — and
    // the difference is the whole point (his catch, 2026-08-17). Two states earn
    // it: an address that FAILED its check, and a group the owner SWITCHED OFF.
    // Both are things that could be working and are not.
    //
    // ⛔ SIGN-IN-ONLY IS NOT ONE OF THEM. It is the normal, correct state of a
    // job group — those jobs work perfectly, a signed-in assistant runs every one
    // — and dimming them said "inactive" about something entirely healthy. On
    // heera.it, where every group is sign-in-only, the whole section would have
    // gone grey with nothing wrong at all.
    //
    // ⚠️ So this deliberately does NOT match the "Held back" filter's set, and
    // that is correct: the filter answers "what is missing from my public file",
    // a fact worth listing; the dim answers "what is not working", a different
    // question. Each row still states its own case in words either way.
    // ⛔ And never keyed off the PILL — "Needs a key" arises two ways, and reading
    // the pill dimmed Acme Bookings, an address published honestly and sitting in
    // the address list four inches above.
    isQuiet() {
      return 'off' === this.state.key || 'held' === this.state.key;
    },
    // A row that is nothing but jobs: no address of its own, so a description
    // could only count the jobs — which oneLine() already does, better.
    isJobsOnly() {
      return !!this.r.tools && !this.endpoints.length;
    },
    // What a dismissal is ABOUT. ⛔ Not the row and not a timestamp: the daily
    // check restamps `checked_at` every night, so a signature carrying it would
    // re-open the row every morning with nothing new to say. What changes when
    // there is news is the state and the reason.
    verdictSignature() {
      const held = this.heldAddresses[0];
      return held ? `${this.state.key}:${(held.open || {}).why || ''}` : '';
    },
  },
  methods: {
    toggle() {
      this.open = !this.open;
      // Only a HELD row records anything: it is the only one that opens itself,
      // so it is the only one whose closing has to be remembered. Re-opening it
      // by hand clears the dismissal, so the next visit finds it open again —
      // the owner's last gesture is the one that counts.
      if ('held' === this.state.key) {
        writeDismissed(this.r.id, this.open ? '' : this.verdictSignature);
      }
    },
    // ⚠️ WHAT THE CHIP MAY SAY. `auth` is what the provider DECLARED; on an
    // address the daily check could not open, printing that declaration puts
    // "public" directly above the sentence "nothing answers here any more". The
    // chip must say what is true of the address now, and the word comes from the
    // check's own reason — a dead address and a locked one share a state, and
    // labelling the dead one "needs a sign-in" would be a fresh untrue sentence
    // on the row that exists to stop them.
    // ⭐ WHEN, not just what. A verdict with no age behind it reads as a
    // permanent fact about the address; it is the result of one check, at one
    // moment, and if cron has stopped this line is the first place it shows
    // ("checked 6d ago" under a daily check answers a question the owner would
    // otherwise never think to ask). Shown for a PASSING address too — evidence
    // is worth as much when the news is good.
    checkedAgo(e) {
      const at = e.open && e.open.checkedAt;
      return at ? relTimeShort(Number(at) * 1000) : '';
    },
    // ⭐ The measurement, not the opinion: a bare "we checked" invites "checked
    // how?". A status code answers it in four characters. Omitted when there was
    // none — a request that never completed has no status to quote.
    httpEvidence(e) {
      const code = e.open && e.open.code;
      return code ? `, and a request with no login got a ${code}` : ', in the background';
    },
    authLabel(e) {
      if (e.open && e.open.published === false) {
        const tags = {
          'refused-a-stranger': 'needs a sign-in',
          gone: 'not there any more',
          'no-route': 'not there',
        };
        return tags[e.open.why] || 'unproven';
      }
      return authWords(e.auth);
    },
    /** Green only for a door genuinely open to anyone. */
    authIsOpen(e) {
      return isOpenToAnyone(e.auth) && !(e.open && e.open.published === false);
    },
  },
};
</script>

<template>
  <!-- ⚠️ `is-expanded`, NOT `is-open`: the state key for a public address is also
       "open", so a single `is-open` class made every readable row render as an
       unfolded one — the caret turned on five rows that were shut. Two different
       ideas may never share a class name. -->
  <li class="ar-wd-prov" :class="{ 'is-ours': r.own, 'is-expanded': open, 'is-quiet': isQuiet, [`is-${state.key}`]: true }">
    <div class="ar-wd-prov__bar" aria-hidden="true"></div>
    <div class="ar-wd-prov__body">
      <!-- ⚠️ The switch sits BESIDE the toggle, never inside it: a control nested
           in a button is invalid, and clicking to publish must never be a click
           that also unfolds the row. -->
      <div class="ar-wd-prov__head">
        <button
          type="button"
          class="ar-wd-prov__toggle"
          :aria-expanded="String(open)"
          @click="toggle"
        >
          <span class="ar-wd-prov__name">
            <span class="ar-wd-prov__caret" aria-hidden="true"></span>
            <strong>{{ r.title }}</strong>
            <!-- ⛔ THE "Agentimus" CHIP IS GONE (his call, 2026-08-17), and for two
                 reasons that agree. ① The row is titled "Jobs from Agentimus" — a
                 chip beside it saying "Agentimus" is the same word twice on one
                 line. ② We cannot badge any OTHER vendor honestly (their abilities
                 carry no attribution, and `core/` turned out to be shared between
                 WordPress and the AI plugin), so badging ourselves alone was
                 special pleading. ⭐ `r.own` still orders the list; it just no
                 longer decorates it. -->
            <span v-if="showKind" class="ar-wd-type">{{ typeLabel }}</span>
            <span v-if="r.version" class="ar-wd-ver">v{{ r.version }}</span>
          </span>
          <span v-if="oneLine" class="ar-wd-prov__desc">{{ oneLine }}</span>
        </button>
        <span class="ar-wd-state" :class="`is-${state.key}`">{{ state.label }}</span>
        <!-- In place, on the row it governs: the switch sits where the thing it
             controls is read, so nobody has to hold two screens in their head. -->
        <!-- ⭐ EVERY ROW GETS THE SWITCH, and a row the owner cannot change gets it
             DISABLED (his call, 2026-08-17). An empty cell read as "off"; a green
             "On" in its place was a second vocabulary for the same column. One
             control, one shape, and being unable to move it is itself the message.
             ⚠️ What it shows is the OWNER'S SETTING — "you have not switched this
             off" — which is why a disabled one can sit beside "Sign-in only — not
             listed": the switch is the choice, the pill is the outcome, and for
             these rows the outcome was never the owner's to change. -->
        <label class="ar-wd-switch" :class="{ 'is-fixed': !controllable }" @click.stop>
          <input
            type="checkbox"
            :checked="published"
            :disabled="!controllable"
            @change="$emit('toggle-publish', r.id)"
          />
          <span class="ar-wd-switch__track" aria-hidden="true"></span>
          <span class="screen-reader-text">
            <!-- ⭐ "List", not "announce": a screen-reader user hears this label
                 while every pill on the page says "listed". One word for one idea
                 applies to the text nobody sees as much as to the text they do. -->
            {{ controllable ? `List ${r.title} in your public file` : `Listing ${r.title} is not switched from here` }}
          </span>
        </label>
      </div>

      <div v-show="open" class="ar-wd-prov__detail">
        <!-- Say WHY, not just that. An owner seeing "not published" with no reason will assume
             something is broken. -->
        <!-- ⚠️ ITS FIRST SENTENCE IS GONE. It read "Everything here needs someone
             signed in, so a stranger could never run it" — which the section lead
             directly above now says almost word for word ("every job needs a
             sign-in first… so a stranger can never run one"). What only this
             paragraph can say is WHY that means we keep the list out of the
             public file, so that is all it says now. ⛔ When a lead is added above
             a paragraph, the paragraph has to be re-read, not left. -->
        <!-- ⭐ Plain words, short sentences: "would hand anyone who asks a map of
             your site's tools" is an idiom stacked on a metaphor, and 28 words in
             one breath. Two sentences, no idiom, same meaning. -->
        <p v-if="!r.suppressed && r.notPublic && !briefHeld" class="ar-wd-prov__held">
          Anyone could read this list, but nobody could use it without signing in. So we do not put
          it in your public file.
        </p>
        <p v-else-if="!r.suppressed && r.notPublic" class="ar-wd-prov__held">
          Not listed for the same reason: signing in is needed here too.
        </p>
        <!-- No switch here, and the reason said out loud rather than left as a
             gap: this row is steered from another screen. -->
        <!-- "brings its categories along with it" is a separable phrasal verb —
             among the hardest structures in English for a second-language reader,
             and avoidable at no cost. -->
        <p v-if="r.siteContent" class="ar-wd-prov__held">
          Chosen in Settings → Content types, not here. When you tick a kind of content, its
          categories and tags are included too.
        </p>

        <ul v-if="endpoints.length" class="ar-wd-eps">
          <li v-for="(e, i) in endpoints" :key="i" :class="{ 'is-held': e.open && e.open.published === false }">
            <span class="ar-wd-ep__type">{{ e.type }}</span>
            <code>{{ e.url }}</code>
            <span class="ar-wd-auth" :class="`is-${authIsOpen(e) ? 'open' : 'locked'}`">{{ authLabel(e) }}</span>
            <!-- The background check's own sentence about this address, whether it
                 passed or not — a verdict is worth as much when it is good — and
                 when it last looked, so the sentence is dated rather than eternal. -->
            <span v-if="e.open && e.open.label" class="ar-wd-ep__verdict">
              {{ e.open.label }}
              <span v-if="checkedAgo(e)" class="ar-wd-ep__when">Checked {{ checkedAgo(e) }}{{ httpEvidence(e) }}.</span>
              <!-- What it means for the owner, and what happens next. Only on a
                   verdict that went against the address: an address that passed
                   needs no reassurance. -->
              <span v-if="e.open.means" class="ar-wd-ep__means">{{ e.open.means }}</span>
            </span>
          </li>
        </ul>

        <ul v-if="jobs.length" class="ar-wd-jobs">
          <!-- ⭐ WHAT IT DOES FIRST, WHAT IT IS CALLED SECOND. The machine name led
               before, in mono at full strength, so twenty-three rows of
               `agentimus/read-…` were the loudest thing in the card and the
               sentence a person actually reads sat under it in grey. An owner
               reads the title; a developer reads the id; putting the title first
               costs the developer nothing, because a mono line under a sentence is
               still the only mono line there. -->
          <li v-for="t in jobs" :key="t.name" :class="{ 'is-write': !t.reads }">
            <span v-if="t.title" class="ar-wd-jobs__title">{{ t.title }}</span>
            <span class="ar-wd-jobs__foot">
              <code>{{ t.name }}</code>
              <!-- ⛔ Only the exception is a chip. Every job needs a sign-in, so a
                   "sign-in" badge on every card would be furniture; what varies, and
                   what an owner actually weighs, is whether it CHANGES anything.
                   One word: the group's own line above already says "5 change
                   something", so the chip does not have to teach the phrase. -->
              <span v-if="!t.reads" class="ar-wd-jobs__changes">changes</span>
            </span>
          </li>
        </ul>

        <div v-if="r.capabilities.length" class="ar-wd-caps">
          <span v-for="c in r.capabilities" :key="c" class="ar-wd-cap">{{ c }}</span>
        </div>

        <p class="ar-wd-prov__provider">
          <span v-if="r.described">Agentimus wrote this</span>
          <span v-else-if="r.auto">Agentimus found this · {{ foundWhere }}</span>
          <span v-else>This plugin told us · <code>{{ r.provider }}</code></span>
          <span v-if="doors.length" class="ar-wd-prov__doors">· reached through {{ doors.join(' · ') }}</span>
        </p>
      </div>
    </div>
  </li>
</template>
