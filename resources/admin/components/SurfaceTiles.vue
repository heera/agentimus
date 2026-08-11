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
  },
  methods: { tileIcon },
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
             the definition more. Two stacked subs; the flex column takes it. -->
        <span class="ar-dash-tile__sub">sources describing your site</span>
        <span v-if="dashProvidersHeld > 0" class="ar-dash-tile__sub">{{ summary.providersPublic }} public · {{ dashProvidersHeld }} sign-in only</span>
      </span>
    </button>
    <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-capabilities' })">
      <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('capabilities')"></span>
      <span class="ar-dash-tile__body">
        <span class="ar-dash-tile__row">
          <strong class="ar-dash-tile__v">{{ summary.capabilities }}</strong>
          <span class="ar-dash-tile__k">Capabilities</span>
        </span>
        <span class="ar-dash-tile__sub">what AI assistants may read or do</span>
      </span>
    </button>
    <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-apis' })">
      <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('apis')"></span>
      <span class="ar-dash-tile__body">
        <span class="ar-dash-tile__row">
          <strong class="ar-dash-tile__v">{{ summary.apis }}</strong>
          <span class="ar-dash-tile__k">APIs</span>
        </span>
        <span class="ar-dash-tile__sub">addresses AI assistants can call for data</span>
      </span>
    </button>
    <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-tools' })">
      <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('tools')"></span>
      <span class="ar-dash-tile__body">
        <span class="ar-dash-tile__row">
          <strong class="ar-dash-tile__v">{{ summary.tools }}</strong>
          <span class="ar-dash-tile__k">Tools</span>
        </span>
        <span class="ar-dash-tile__sub">actions AI assistants can run</span>
        <span v-if="dashToolsHeld > 0" class="ar-dash-tile__sub">{{ summary.toolsPublic }} public · {{ dashToolsHeld }} sign-in only</span>
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
