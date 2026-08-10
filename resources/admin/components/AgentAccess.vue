<script>
/**
 * Agent access — who authenticated to, and ACTED on, the machine surface this plugin creates.
 * The Activity log answers "who read my agent surface"; this answers "who acted on it".
 *
 * Design note — HONEST BY CONSTRUCTION, in the same spirit as the review queue and the request
 * log's footer. This screen has one failure mode that matters more than any bug: a quiet,
 * empty table reads as "all clear". It very often is not. So the screen NEVER renders a bare
 * empty state — it always states, in plain words:
 *
 *   1. What it can see here (the five coverage states — a site without the Abilities API has
 *      nothing to watch, and an old Abilities API plugin never announces an invocation at all).
 *   2. That it records no IP and no location, so it can name the key but never the person.
 *
 * It also records what was TURNED AWAY, not only what succeeded — refusals and probes for
 * abilities that don't exist. That is what lets the empty state say "nothing has run, and nothing
 * has been turned away either" instead of the confession it used to have to make. But it still
 * never labels intent: we cannot tell an attacker from a curious agent, and `hits` is the honest
 * signal — refused twice is someone looking, refused twelve thousand times is a hammer.
 *
 * And it never claims to protect anything. It is a camera, not a lock: nothing here blocks.
 */
import { formatStamp } from '../js/wpDate.js';
import { uaTip } from '../js/uaTip.js';
import RefreshCrank from './RefreshCrank.vue';
import CardSkeleton from './CardSkeleton.vue';

export default {
  name: 'AgentAccess',
  components: { RefreshCrank, CardSkeleton },
  mixins: [uaTip],
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show, so it stays mounted across tab switches. Fetch on first reveal.
    active: { type: Boolean, default: false },
    // The live unread count, owned by the parent (it rides the polled /activity payload that
    // also feeds the review bell). We take it as a prop purely as a CHANGE SIGNAL: if events
    // land while the owner is sitting on this screen, the nav badge learns about them and the
    // table must follow — otherwise the screen contradicts itself, showing a badge of 7 above
    // a table with one row in it. A self-contradicting screen is worse than a stale one.
    unread: { type: Number, default: 0 },
  },
  emits: ['flash', 'seen'],
  data() {
    return {
      events: [],
      unseen: 0,
      total: 0,
      // Cursor walk, mirroring the request log: the server hands back the id of the last row on
      // this page; we push it on a stack so "Newer" can reverse. Page numbers would be a lie on a
      // log that grows under you.
      hasMore: false,
      cursor: null,
      before: '',
      trail: [],
      perPage: 100,
      retention: 90,
      headline: { abilities: 0, registered: 0, lastAt: '' },
      maxRows: 5000,
      coverage: '',
      hasAbilities: false,
      thirdParty: false,
      passwords: true,
      wpVersion: '',
      // Ids of every row that has arrived UNREAD during this visit to the screen. The server marks
      // a row read as soon as it lands in front of the owner, so on the next refresh it comes back
      // as "seen" and would lose its NEW pill — even if they were looking away when it flashed up.
      // A row that silently de-highlights itself before anyone has actually looked at it is the one
      // thing this list must not do, so we keep our own record and let it outlive the server's.
      // Cleared on leaving the screen: come back later and the rows really are old news.
      highlighted: [],
      loading: false,
      loaded: false,
      error: '',
    };
  },
  computed: {
    // The five rungs, each with copy that says what we see AND what to do about it. Keyed by
    // the server's verdict so the wording can never drift from the decision behind it.
    coverageCopy() {
      const map = {
        full: {
          tone: 'ok',
          badge: 'Watching',
          title: 'Watching every ability on this site',
          body: "Whenever an ability runs, we'll record it — Agentimus's own and any other plugin's.",
          action: '',
        },
        own_only: {
          tone: 'warn',
          badge: 'Partly watching',
          title: "Watching Agentimus's abilities only",
          body: "Your Abilities API plugin is an older version that doesn't announce when an ability runs, so abilities from other plugins are invisible to us. We can still see our own, because we run those ourselves.",
          action: 'Update the Abilities API plugin to 0.4.0 or newer to see the rest.',
        },
        pending: {
          tone: 'info',
          badge: 'Not confirmed',
          title: "We'll know for sure once an ability runs",
          body: "Your site gets abilities from a plugin rather than from WordPress itself, and older versions of that plugin don't announce when an ability runs. We'll confirm which version you have the first time one does. Until then, treat this list as incomplete.",
          action: '',
        },
        installable: {
          tone: 'info',
          badge: 'Not available',
          title: 'This site has no abilities to watch',
          body: "Abilities are how an AI assistant runs something on your site. WordPress didn't include them until 6.9, and this site doesn't have them — so there's nothing here to record.",
          action: 'Update WordPress to 6.9 or newer, or install the Abilities API plugin.',
        },
        unsupported: {
          tone: 'info',
          badge: 'Not available',
          title: 'This site has no abilities to watch',
          body: `Abilities are how an AI assistant runs something on your site. They need WordPress 6.8 or newer — this site is on ${this.wpVersion || 'an older version'}.`,
          action: 'Update WordPress to turn this on.',
        },
      };
      return map[this.coverage] || null;
    },
    hasEvents() {
      return this.events.length > 0;
    },
    // Where in the walk we are, stated plainly — the same "1–100 of 315" the request log gives.
    pageFrom() {
      return this.events.length ? this.trail.length * this.perPage + 1 : 0;
    },
    pageTo() {
      return this.trail.length * this.perPage + this.events.length;
    },
  },
  watch: {
    active(on) {
      // Every arrival re-fetches page one. The nav badge polls live, so a kept-alive
      // table can be minutes older than the badge above it — the owner clicks a "1"
      // and lands on a list that doesn't contain it. Fresh load() also re-runs
      // markSeen() from the server's CURRENT unseen count, so the badge can never
      // stay lit over a caught-up table.
      if (on) {
        this.trail = [];
        this.load();
      }
      // Left the screen — that is when a row stops being news, and it must not take a page reload
      // to say so. The server already recorded these as read the moment they landed in front of
      // the owner; catch the LOCAL rows up to that, or `!e.seen` stays true in memory forever and
      // isHighlighted() keeps firing on it however many times the highlight list is cleared.
      if (!on) {
        this.highlighted = [];
        this.events = this.events.map((e) => ({ ...e, seen: true }));
      }
    },
    // Something arrived while this screen is open. Pull the new rows in so the table agrees
    // with the badge. load() then marks them read, which drops the count back to 0 — so this
    // cannot re-trigger itself. The new rows still arrive carrying their NEW pills, because the
    // server sent them as unread and we do not rewrite that locally.
    unread(count) {
      // Only auto-pull on the FIRST page. A live event arriving while the owner is reading page 3
      // must not yank them back to page 1 — new rows land at the top, so they are not on this page
      // anyway, and teleporting mid-read is worse than a slightly stale page. They will see them
      // when they walk back (or the nav badge tells them there is something to walk back to).
      if (count > 0 && this.active && this.loaded && !this.before && !this.trail.length) this.load();
    },
  },
  // The watcher only fires on a CHANGE, so a cold load straight to #agent-access — where
  // `active` is already true at mount — would render the chrome and never fetch. Same guard
  // the request log and AI-traffic panels use.
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    async load(before = '') {
      if (!this.api) return;
      this.loading = true;
      this.error = '';
      try {
        const data = await this.api.getAgentAccess(before);
        this.events = data.events || [];
        this.hasMore = !!data.hasMore;
        this.cursor = data.cursor || null;
        this.before = before;
        this.perPage = data.perPage || this.perPage;
        // Remember anything the server still considers unread BEFORE we mark it read below —
        // this is the only moment we can tell.
        (this.events || []).forEach((e) => {
          if (!e.seen && !this.highlighted.includes(e.id)) this.highlighted.push(e.id);
        });
        this.unseen = data.unseen || 0;
        this.total = data.total || 0;
        this.headline = data.headline || { abilities: 0, registered: 0, lastAt: '' };
        this.retention = data.retention || this.retention;
        this.maxRows = data.maxRows || this.maxRows;
        this.coverage = data.coverage || '';
        this.hasAbilities = !!data.hasAbilities;
        this.thirdParty = !!data.thirdParty;
        this.passwords = !!data.passwords;
        this.wpVersion = data.wpVersion || '';
        this.loaded = true;
        if (this.active && this.unseen > 0) this.markSeen();
      } catch (e) {
        this.error = e.message || 'Could not load agent access.';
      } finally {
        this.loading = false;
      }
    },
    // Walk one page older: remember where we were so "Newer" can come back.
    older() {
      if (!this.hasMore || !this.cursor) return;
      this.trail.push(this.before);
      this.load(this.cursor);
    },
    newer() {
      if (!this.trail.length) return;
      this.load(this.trail.pop());
    },
    async markSeen() {
      if (!this.api) return;
      try {
        await this.api.markAgentAccessSeen();
        this.unseen = 0;
        this.$emit('seen');
        // NOTE: the rows keep their `seen: false` for this viewing, on purpose. Clearing it
        // here would strip the highlight off the very row the badge just sent the owner to
        // look at — they'd arrive to find nothing marked. The server has recorded the read,
        // so the tint is gone on the next load; this pass is the one where it has to be there.
      } catch (e) {
        /* A failed read-receipt is not worth interrupting the owner for. */
      }
    },
    async clear() {
      if (!this.api) return;
      try {
        await this.api.clearAgentAccess();
        this.events = [];
        this.total = 0;
        this.hasMore = false;
        this.cursor = null;
        this.before = '';
        this.trail = [];
        this.unseen = 0;
        this.$emit('seen');
        this.$emit('flash', 'success', 'Agent Access log cleared.');
      } catch (e) {
        this.$emit('flash', 'error', e.message || 'Could not clear the log.');
      }
    },
    // One sentence per kind. The wording says what HAPPENED, not what it might mean — we have
    // no IP and no location, so we are in no position to judge intent, and pretending otherwise
    // would be the dishonesty this whole screen exists to avoid.
    // The event's KIND — the quiet first line. It repeats down the table, so it
    // reads as a label rather than as news.
    eventKind(e) {
      switch (e.kind) {
        case 'apppw_created':  return 'New application password';
        // "First used", not "used": the event fires ONCE, on a key's first
        // authentication (Module::on_password_used bails when last_used is set),
        // so plain "used" would imply a per-use log that deliberately doesn't exist.
        case 'apppw_used':     return 'Application password first used';
        case 'apppw_renamed':  return 'Application password renamed';
        case 'apppw_deleted':  return 'Application password revoked';
        case 'ability_used':   return 'Ability used';
        case 'ability_refused':return 'Ability refused';
        case 'ability_probed': return 'Probed for abilities that don’t exist';
        default:               return 'Event';
      }
    },
    // What the name in the subject line IS. "verify-tax" tells an admin nothing
    // unless they happen to remember creating it, so say where it came from and
    // where they can go act on it.
    subjectHint(e) {
      if (0 === String(e.kind).indexOf('apppw_')) {
        return 'The name given to this application password when it was created. You’ll find it under Users → Profile → Application passwords, where it can also be revoked.';
      }
      if (0 === String(e.kind).indexOf('ability_')) {
        return 'The name of the ability that was called — the machine-readable action an AI assistant asked your site to run.';
      }
      return '';
    },
    // A key for a password name, a spark for an ability: the row's family is
    // recognisable before a word is read.
    subjectIcon(e) {
      return 0 === String(e.kind).indexOf('apppw_') ? 'key' : 'ability';
    },
    // What it was about — the line that actually varies, so it carries the weight.
    eventSubject(e) {
      if (e.kind === 'ability_probed') return '';
      return e.subject || '(unnamed)';
    },
    // WHO, split in two: the person on one line, what they came in WITH on the
    // next. One sentence joined by a middot made the eye parse a punctuation
    // mark to find where the second fact started.
    whoPerson(e) {
      if (!e.userId) return '';
      const gone = !e.user;
      const user = gone ? `user #${e.userId} (deleted)` : e.user;
      // A password's own lifecycle row names the account it happened ON: the
      // stored user owns the key, and an admin can add or revoke one on someone
      // else's profile, so "by" would claim an actor we never recorded.
      if (e.kind && 0 === e.kind.indexOf('apppw_') && 'apppw_used' !== e.kind) {
        return gone ? `on user #${e.userId}’s account (deleted)` : `on ${e.user}’s account`;
      }
      // Key-borne events: the person did not necessarily act — their key did,
      // in their name. "acting as" says exactly that in a full phrase (a bare
      // "as admin" read like a fragment). "by" would put the human at the
      // keyboard, and "approved by" would invent an approval step app passwords
      // don't have. Session-borne events keep "by": there the person WAS there.
      if (e.cred || 'apppw_used' === e.kind) {
        return `acting as ${user}`;
      }
      return `by ${user}`;
    },
    // Only kinds that ACCUMULATE carry a count ("– once" / "– 4 times").
    // Password lifecycle rows are one-shot by construction — the recorder
    // bails when last_used is already set — so a count there could only
    // ever say "1", which is noise, not news.
    countedKind(e) {
      return !(e.kind && 0 === e.kind.indexOf('apppw_'));
    },
    // What the request came in with. Password lifecycle rows get none — the
    // subject line above already names the key.
    whoCred(e) {
      if (!e.userId || (e.kind && 0 === e.kind.indexOf('apppw_'))) return '';
      if (e.cred) {
        // "(revoked)", not "(since revoked)": every row here is a past event with
        // its own timestamp, so "revoked" can only mean "by now" — and it matches
        // how a deleted account is marked one line up.
        return e.credName ? `via ${e.credName}` : 'via an app password (revoked)';
      }
      return 'via a logged-in session';
    },
    // Shown on a created password and nowhere else. Deliberately unconditional: we have no
    // honest basis for deciding WHICH new password is suspicious (no IP, no location), so we
    // would rather mildly nag on every one than imply a certainty we do not have. This one
    // sentence is the reason the feature exists.
    isNewKey(e) {
      return e.kind === 'apppw_created';
    },
    // A refusal or a probe is qualitatively different from "your Zapier key was used": it is the
    // first thing here that might mean someone is actually trying it on. It gets a warn tone — but
    // never the word "attack", because we cannot know that and will not pretend to.
    // The advisory for this row, if any — the text that used to occupy a third line.
    note(e) {
      if (this.isNewKey(e)) {
        return 'Didn’t create this? Revoke it. An application password keeps working even after you change your password.';
      }
      if (this.revokeAdvice(e)) {
        return 'A key you issued tried to run something it isn’t allowed to. If you don’t recognise this, revoke that application password.';
      }
      if (e.kind === 'ability_probed') {
        return 'Nobody advertises these names, so someone is guessing them. One or two is noise; a large count is not.';
      }
      return '';
    },
    isRefusal(e) {
      return e.kind === 'ability_refused' || e.kind === 'ability_probed';
    },
    // THE payload of Phase 3, and the only unambiguous signal this feature will ever produce: a key
    // the OWNER issued asked for something it is not allowed to do. It is misconfigured or stolen,
    // and either way the action is the same. Anonymous refusals get no such line — we have no IP, so
    // there is nothing honest to tell them to do, and inventing advice would be the fake certainty
    // this screen refuses to trade in.
    revokeAdvice(e) {
      return e.kind === 'ability_refused' && !!e.cred;
    },
    // Unread per the server, OR unread at any point during this visit. The second half is what
    // stops a row losing its pill just because a later event arrived and triggered a refresh.
    isHighlighted(e) {
      return !e.seen || this.highlighted.includes(e.id);
    },
    // "3 minutes ago" beats a timestamp for the one question this line answers:
    // is anything happening? The exact moment is a hover away on every row below.
    lastActivity() {
      const t = Date.parse(this.headline.lastAt || '');
      if (!t) return 'never';
      const mins = Math.round((Date.now() - t) / 60000);
      if (mins < 1) return 'just now';
      if (mins < 60) return `${mins} minute${1 === mins ? '' : 's'} ago`;
      const hrs = Math.round(mins / 60);
      if (hrs < 24) return `${hrs} hour${1 === hrs ? '' : 's'} ago`;
      const days = Math.round(hrs / 24);
      return `${days} day${1 === days ? '' : 's'} ago`;
    },
    when(iso) {
      if (!iso) return '';
      const d = new Date(iso);
      return Number.isNaN(d.getTime()) ? iso : formatStamp(d);
    },
  },
};
</script>

<template>
  <section class="ar-card ar-aa">
    <div class="ar-card__head ar-card__head--inline">
      <div class="ar-card__titlewrap">
        <h2 class="ar-card__title">Events</h2>
        <!-- The universal hand-crank, seated beside the title like every other
             data card (was a ghost "Refresh" in the actions — whose bare
             @click="load" quietly passed the CLICK EVENT as the paging cursor,
             so "refresh" asked the server for ?before=[object PointerEvent]).
             Reloads page one; ungated, so an empty list can still check for
             arrivals. -->
        <RefreshCrank
          :busy="loading"
          :aria-label="loading ? 'Reloading agent access events…' : 'Reload agent access events'"
          :title="loading ? 'Reloading…' : 'Reload agent access events'"
          @refresh="load()"
        />
      </div>
      <div class="ar-card__actions" v-if="hasEvents">
        <button type="button" class="ar-btn ar-btn--danger" :disabled="loading" @click="clear">
          Clear log
        </button>
      </div>
    </div>

    <p v-if="error" class="ar-aa__error">{{ error }}</p>

    <!-- First load in flight: show a skeleton, not a bare card. Same pattern as Endpoint
         Activity. Everything below waits on the fetch (the coverage box needs the server's
         verdict, the feed needs rows), so without this the screen renders only its title and
         its footer — and on a slow origin that blank sits long enough to read as broken. -->
    <CardSkeleton v-if="!loaded && !error" lead="Loading agent access…" />

    <!-- What we can see HERE. Rendered before the table, because on most of these rungs the
         table's emptiness is explained entirely by this box. -->
    <div v-if="coverageCopy" class="ar-aa__state" :class="`ar-aa__state--${coverageCopy.tone}`">
      <span class="ar-aa__badge">{{ coverageCopy.badge }}</span>
      <div class="ar-aa__statebody">
        <h3 class="ar-aa__statetitle">{{ coverageCopy.title }}</h3>
        <p>{{ coverageCopy.body }}</p>
        <p v-if="coverageCopy.action" class="ar-aa__action">{{ coverageCopy.action }}</p>
        <!-- What the watching has actually SEEN. The sentence above is true but
             abstract; without these an owner cannot tell a quiet site from a
             broken one, which is the exact confusion this screen exists to
             prevent. Honest at zero: it says nothing has run yet. -->
        <p v-if="loaded" class="ar-aa__facts">
          <template v-if="total > 0">
            <!-- Each fact is its own unit, with the separator drawn in CSS — so a
                 narrow screen can stack them instead of breaking a phrase in half
                 ("last activity" on one line, "3 days ago" on the next). -->
            <span class="ar-aa__fact"><strong>{{ total.toLocaleString() }}</strong> {{ total === 1 ? 'event' : 'events' }} recorded</span>
            <span class="ar-aa__fact">
              <strong>{{ headline.abilities }}</strong><template v-if="headline.registered"> of {{ headline.registered }}</template>
              {{ headline.abilities === 1 && !headline.registered ? 'ability' : 'abilities' }} used
            </span>
            <span class="ar-aa__fact">last activity <strong>{{ lastActivity() }}</strong></span>
          </template>
          <template v-else>Nothing has run yet — this list fills as agents act on your site.</template>
        </p>
      </div>
    </div>

    <!-- Orthogonal to the ladder above: core switches application passwords OFF entirely
         without https, so on a plain-HTTP site the credential half cannot fire at all. Saying
         nothing here would leave the owner reading an empty table as "no one has a key". -->
    <div v-if="!passwords" class="ar-aa__state ar-aa__state--warn">
      <span class="ar-aa__badge">Off</span>
      <div class="ar-aa__statebody">
        <h3 class="ar-aa__statetitle">Application passwords are switched off on this site</h3>
        <p>
          WordPress only allows them over a secure (https) connection, so nobody can create or
          use one here — there's nothing for us to record. If this site <em>is</em> on https and
          you still see this, check your address under Settings → General.
        </p>
      </div>
    </div>

    <!-- The wrapper scrolls the table sideways on narrow screens; the table itself
         never widens the page. -->
    <!-- A feed of event cards rather than a table: these rows are heterogeneous
         (an ability run, a key created, a probe turned away), and each one is
         read on its own rather than compared down a column. Every fact keeps a
         fixed position inside the card, so the eye still lands in the same place
         on every row. -->
    <table v-if="hasEvents" class="ar-aa__table">
      <thead class="ar-aa__thead">
        <tr>
          <th scope="col">Event</th>
          <th scope="col">Who</th>
          <th scope="col">Uses</th>
          <th scope="col">First seen</th>
          <th scope="col">Last seen</th>
        </tr>
      </thead>
      <tbody>
      <tr
        v-for="e in events"
        :key="e.id"
        class="ar-aa__card"
        :class="{ 'is-unseen': isHighlighted(e), 'is-refusal': isRefusal(e) }"
      >
        <td class="ar-aa__cell ar-aa__cell--ev">
        <div class="ar-aa__evwrap">
        <span class="ar-aa__mark" :class="`is-${subjectIcon(e)}`" aria-hidden="true">
          <svg
            v-if="subjectIcon(e) === 'key'"
            viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor"
            stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
          ><circle cx="5.5" cy="10.5" r="2.6" /><path d="M7.4 8.6 13 3" /><path d="M11.2 4.8l1.5 1.5" /></svg>
          <svg
            v-else
            viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor"
            stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
          ><path d="M8.6 1.8 3.4 9h3.4l-1.4 5.2L13 6.6H9.4z" /></svg>
        </span>

        <div class="ar-aa__ev">
          <!-- The count folds into the kind line on cards ("ABILITY USED – 2 TIMES");
               the wide table hides the span — it has a Uses column. -->
          <span class="ar-aa__kindline">{{ eventKind(e) }}<span v-if="countedKind(e)" class="ar-aa__kinduses"> – {{ e.hits > 1 ? `${e.hits} times` : 'once' }}</span></span>
          <span
            class="ar-aa__what"
            @mouseenter="showUaTip($event, subjectHint(e), '')"
            @mouseleave="hideUaTip"
          >{{ eventSubject(e) || eventKind(e) }}<span v-if="isHighlighted(e)" class="ar-aa__new">New</span></span>
        </div>
        </div>
        </td>

        <td class="ar-aa__cell ar-aa__cell--who" data-label="Who">
        <div class="ar-aa__whoblk">
          <span class="ar-aa__who">
            <svg class="ar-aa__lineicon" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="5.2" r="2.6" /><path d="M3.2 13.4a4.8 4.8 0 0 1 9.6 0" /></svg>
            {{ whoPerson(e) || 'not recorded' }}
            <button
              v-if="note(e)"
              type="button"
              class="ar-aa__noteicon"
              :class="{ 'is-warn': !!revokeAdvice(e) }"
              :aria-label="note(e)"
              @mouseenter="showUaTip($event, note(e), '')"
              @mouseleave="hideUaTip"
              @focus="showUaTip($event, note(e), '')"
              @blur="hideUaTip"
            >i</button>
          </span>
          <span v-if="whoCred(e)" class="ar-aa__cred">
            <svg v-if="e.cred" class="ar-aa__lineicon" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5.5" cy="10.5" r="2.6" /><path d="M7.4 8.6 13 3" /><path d="M11.2 4.8l1.5 1.5" /></svg>
            <svg v-else class="ar-aa__lineicon" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.2" y="3" width="11.6" height="8" rx="1.2" /><path d="M6 13.4h4" /></svg>
            {{ whoCred(e) }}
          </span>
        </div>
        </td>

        <td class="ar-aa__cell ar-aa__cell--uses" data-label="Uses">
        <div class="ar-aa__usesblk">
          <span class="ar-aa__uses">{{ e.hits > 1 ? `${e.hits} uses` : '1 use' }}</span>
        </div>
        </td>

        <td class="ar-aa__cell ar-aa__cell--seen">
        <div class="ar-aa__seenblk">
          <span class="ar-aa__seenlab">First seen</span>
          <span class="ar-aa__seenval">
            <svg class="ar-aa__lineicon" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.4" y="3.2" width="11.2" height="10.4" rx="1.4" /><path d="M2.4 6.4h11.2M5.6 1.8v2.4M10.4 1.8v2.4" /></svg>
            {{ when(e.firstSeen) }}
          </span>
        </div>
        </td>
        <td class="ar-aa__cell ar-aa__cell--seen">
        <div class="ar-aa__seenblk">
          <span class="ar-aa__seenlab">Last seen</span>
          <span class="ar-aa__seenval">
            <svg class="ar-aa__lineicon" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.4" y="3.2" width="11.2" height="10.4" rx="1.4" /><path d="M2.4 6.4h11.2M5.6 1.8v2.4M10.4 1.8v2.4" /></svg>
            {{ when(e.lastSeen) }}
          </span>
        </div>
        </td>
      </tr>
      </tbody>
    </table>

    <!-- Phase 3 EARNED this sentence. It used to have to confess that WordPress only tells us an
         ability ran after it has already ALLOWED it, so a refused probe left no trace — meaning a
         quiet screen could not honestly be read as "all clear". We now watch the refusals too, so
         the quiet screen finally means what owners always assumed it meant. -->
    <div v-else-if="loaded && hasAbilities" class="ar-aa__empty">
      <h3>Nothing yet.</h3>
      <p>
        Nothing has run — and nothing has been turned away either. Requests that were refused, and
        anyone poking at abilities that don't exist, would show up here too.
      </p>
    </div>

    <!-- What this VIEW is holding back, as opposed to what the feature can't see at all. A list
         that stops at 100 rows without saying so reads as "that's everything" — which on this
         screen, of all screens, would be the lie it exists to prevent. -->
    <div v-if="hasEvents" class="ar-aa__more">
      <p class="ar-aa__count">
        Showing <strong>{{ pageFrom }}–{{ pageTo }}</strong> of <strong>{{ total }}</strong>
        {{ total === 1 ? 'event' : 'events' }}. Kept for {{ retention }} days, up to
        {{ maxRows.toLocaleString() }} — whichever limit is reached first, so a flood of events
        can push older ones out early.
      </p>
      <!-- Newer / Older, the same cursor walk the request log uses. Deliberately NOT a "show
           more" that grows one page: this table can hold thousands of rows, and a button with a
           ceiling would leave the rest permanently unreachable — on a flood, exactly when the
           owner most needs to read them. -->
      <div v-if="hasMore || trail.length" class="ar-aa__pager">
        <button type="button" class="ar-btn ar-btn--ghost" :disabled="!trail.length || loading" @click="newer">
          ← Newer
        </button>
        <button type="button" class="ar-btn ar-btn--ghost" :disabled="!hasMore || loading" @click="older">
          Older →
        </button>
      </div>
    </div>

    <!-- Standing limits of the whole feature, always visible. Same principle as the request
         log's footer: a view that never states its own blind spots is read as complete. -->
    <p class="ar-aa__limits">
      <strong>What this can't tell you:</strong> we don't record IP addresses or locations, so we
      can name the key that was used but not the person or machine using it. We also only see
      machine logins — someone signing in with your normal password won't appear here.
    </p>

    <!-- The uaTip mixin positions THIS element; without it the marker's hover is a no-op. -->
    <Teleport to="body">
      <transition name="ar-tip">
        <div
          v-if="uaTip.show"
          ref="uaTipEl"
          class="ar-act-uatip"
          :class="{ 'is-below': uaTip.below }"
          :style="{ left: uaTip.x + 'px', top: uaTip.y + 'px' }"
          role="tooltip"
          aria-hidden="true"
        ><span class="ar-act-uatip__ua">{{ uaTip.text }}</span><span v-if="uaTip.hint" class="ar-act-uatip__hint">{{ uaTip.hint }}</span><span class="ar-act-uatip__caret" :style="{ left: uaTip.caret + 'px' }"></span></div>
      </transition>
    </Teleport>
  </section>
</template>
