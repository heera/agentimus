<script>
/**
 * The site's agent SURFACE — providers, capabilities, APIs, tools.
 *
 * Its own component because of where it belongs: the dashboard's subtitle is
 * "what you expose, and who is reading it", and these are the first half of
 * that sentence, so they render ABOVE the audience card rather than inside the
 * activity panel that used to own them. Configuration, not analytics — stated
 * first because it is the premise, styled quieter because it is not the news.
 */
import { tileIcon } from '../js/groupIcons.js';

export default {
  name: 'SurfaceTiles',
  props: {
    // The boot payload's dashboard summary.
    summary: { type: Object, default: null },
  },
  emits: ['navigate'],
  computed: {
    // Public vs sign-in-only, so a tile can say which half is which. The
    // Discovery screen does the same reconciliation, and the two screens must
    // never show different numbers without saying why.
    dashProvidersHeld() {
      const s = this.summary || {};
      if (typeof s.providersPublic !== 'number') return 0;
      return Math.max(0, (s.providers || 0) - s.providersPublic);
    },
    dashToolsHeld() {
      const s = this.summary || {};
      if (typeof s.toolsPublic !== 'number') return 0;
      return Math.max(0, (s.tools || 0) - s.toolsPublic);
    },
    dashApisHeld() {
      const s = this.summary || {};
      if (typeof s.apisPublic !== 'number') return 0;
      return Math.max(0, (s.apis || 0) - s.apisPublic);
    },
  },
  methods: {
    tileIcon,
    // Every tile carries its third line (his alignment ruling): the split
    // when there is one, the plain "N public" when nothing is held back.
    // ⚠️⚠️ "SIGN-IN ONLY" WAS WRONG, and wrong in the same way the Discovery
    // screen's own stat row was (fixed 2026-08-17). A thing can be missing from
    // your public files for three different reasons — it needs a sign-in, the
    // owner switched it off, or its address failed the daily reachability check —
    // and only the first is a sign-in. "Held back" is the one phrase true of all
    // three, and it is the phrase the Discovery screen's filter uses, so a tile
    // and the screen it jumps to now name the same thing the same way.
    splitLine(publicCount, held) {
      if (typeof publicCount !== 'number') return '';
      // ⭐ "listed / not listed" — the one pair the Discovery screen these tiles
      // jump to now uses everywhere. A tile and its destination must not name one
      // state two ways.
      return held > 0 ? `${publicCount} listed · ${held} not listed` : `${publicCount} listed`;
    },
  },
};
</script>

<template>
  <div class="ar-surface">
  <!-- What the site EXPOSES, before who read it — the page's own subtitle
       says "what you expose, and who is reading it", and the tiles are the
       first half of that sentence. Quieter than a data card (they are
       configuration) but first, because they are the premise.

       Readiness isn't a tile here — it's the rail's AEO/GEO card, which owns
       the score and rungs. These describe the agent SURFACE instead. -->
  <div v-if="summary" class="ar-dash-sum">
    <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-providers' })">
      <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('providers')"></span>
      <span class="ar-dash-tile__body">
        <span class="ar-dash-tile__row">
          <strong class="ar-dash-tile__v">{{ summary.providers }}</strong>
          <span class="ar-dash-tile__k">Providers</span>
        </span>
        <!-- The teaching line stays even when the public/sign-in split shows:
             the split is a count, not a definition, and a first-timer needs
             the definition more. Every tile carries BOTH lines (his alignment
             ruling) — a row of tiles must land its baselines together. -->
        <span class="ar-dash-tile__sub">Sources describing your site</span>
        <span v-if="splitLine(summary.providersPublic, dashProvidersHeld)" class="ar-dash-tile__sub">{{ splitLine(summary.providersPublic, dashProvidersHeld) }}</span>
      </span>
    </button>
    <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-capabilities' })">
      <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('capabilities')"></span>
      <span class="ar-dash-tile__body">
        <span class="ar-dash-tile__row">
          <strong class="ar-dash-tile__v">{{ summary.capabilities }}</strong>
          <span class="ar-dash-tile__k">Capabilities</span>
        </span>
        <!-- ⚠️ THE COMMA IS LOAD-BEARING. The two lines are one sentence, and
             every OTHER tile's second sub-line is a count ("1 listed · 2 not
             listed") — a separate fact. Without the comma this one reads as a
             continuation that lost its punctuation: "may read or do declared in
             discovery.json". His catch, from his own dashboard. -->
        <span class="ar-dash-tile__sub">What AI assistants may read or do,</span>
        <!-- No auth attaches to a capability name, so no split exists to
             state — the third line is WHERE an agent reads them instead. -->
        <span class="ar-dash-tile__sub">declared in discovery.json</span>
      </span>
    </button>
    <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-apis' })">
      <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('apis')"></span>
      <span class="ar-dash-tile__body">
        <span class="ar-dash-tile__row">
          <strong class="ar-dash-tile__v">{{ summary.apis }}</strong>
          <!-- ⛔ THE ONLY LABEL ON THIS ROW WHOSE LOWERCASE LETTER MEANS
               SOMETHING. .ar-dash-tile__k uppercases every key, which is right
               for Providers, Capabilities and Tools — ordinary words that lose
               nothing. "APIs" is an acronym plus a plural marker, and uppercased
               it renders APIS, which stops reading as a plural and starts
               reading as a word. The `s` is held out of the transform rather
               than the transform dropped: the row's caps rhythm is the design,
               and this is how a caps-set acronym plural is normally handled. -->
          <span class="ar-dash-tile__k">API<span class="ar-dash-tile__pl">s</span></span>
        </span>
        <span class="ar-dash-tile__sub">Endpoints AI assistants can access</span>
        <span v-if="splitLine(summary.apisPublic, dashApisHeld)" class="ar-dash-tile__sub">{{ splitLine(summary.apisPublic, dashApisHeld) }}</span>
      </span>
    </button>
    <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-tools' })">
      <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('tools')"></span>
      <span class="ar-dash-tile__body">
        <span class="ar-dash-tile__row">
          <strong class="ar-dash-tile__v">{{ summary.tools }}</strong>
          <span class="ar-dash-tile__k">Tools</span>
        </span>
        <span class="ar-dash-tile__sub">Actions AI assistants can run</span>
        <span v-if="splitLine(summary.toolsPublic, dashToolsHeld)" class="ar-dash-tile__sub">{{ splitLine(summary.toolsPublic, dashToolsHeld) }}</span>
      </span>
    </button>
  </div>

  <!-- Quiet privacy framing so the dashboard reads as informational, not
       surveillance. The shield-check is the privacy-fact mark (.ar-privnote). -->
  <p class="ar-dash-note ar-privnote">
    Informational only — which AI assistants read your site, in aggregate. No IP addresses by default,
    nothing sent anywhere.
  </p>
  </div>
</template>
