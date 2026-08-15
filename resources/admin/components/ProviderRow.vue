<script>
// One row in the Discovery Hub "Registered providers" inventory. Shared by both
// the Declared and Auto-discovered groups so the markup stays in one place.
export default {
  name: 'ProviderRow',
  props: {
    r: { type: Object, required: true },
    // True when an earlier row already carries the full sign-in explanation —
    // this row defers to it instead of repeating the same paragraph verbatim.
    briefHeld: { type: Boolean, default: false },
  },
  computed: {
    // The engine names are the specs' own ("REST API", "Abilities API"). On a
    // screen read by shop owners they need saying in words, once, here.
    foundWhere() {
      if (this.r.engine === 'Abilities API') return 'in the list of jobs your plugins registered with WordPress';
      if (this.r.engine === 'REST API') return 'by reading your site’s own data doors';
      return 'by reading your site';
    },
  },
};
</script>

<template>
  <li class="ar-wd-prov">
    <div class="ar-wd-prov__bar" aria-hidden="true"></div>
    <div class="ar-wd-prov__body">
      <div class="ar-wd-prov__head">
        <strong>{{ r.title }}</strong>
        <span class="ar-wd-type">{{ r.type }}</span>
        <!-- Only flag the agent card when the type isn't already "agent" — avoids a redundant double badge. -->
        <span v-if="r.hasAgent && r.type !== 'agent'" class="ar-wd-type ar-wd-type--agent">agent card</span>
        <span v-if="r.version" class="ar-wd-ver">v{{ r.version }}</span>
        <!-- A row listed with no caveat reads as "this is live". These two say otherwise, and they
             are different things: `suppressed` is the OWNER's own choice, `notPublic` is the
             provider stating that nobody anonymous could use it anyway. -->
        <span v-if="r.suppressed" class="ar-wd-held">Not published · you turned it off</span>
        <span v-else-if="r.notPublic" class="ar-wd-held">Not published · sign-in required</span>
      </div>
      <p v-if="r.description" class="ar-wd-prov__desc">{{ r.description }}</p>
      <!-- Say WHY, not just that. An owner seeing "not published" with no reason will assume
           something is broken. -->
      <p v-if="!r.suppressed && r.notPublic && !briefHeld" class="ar-wd-prov__held">
        Everything here needs someone signed in, so a stranger could never run it. Listing it
        publicly would hand anyone who asks a map of your site’s tools, and let nobody use them. An
        assistant that signs in still finds them.
      </p>
      <p v-else-if="!r.suppressed && r.notPublic" class="ar-wd-prov__held">
        Kept back for the same reason — signing in is needed here too.
      </p>
      <!-- Three sources, three sentences. "Found automatically" belongs only to
           what a scanner actually found: a plugin Agentimus recognises and
           describes was not discovered, it was written. -->
      <p class="ar-wd-prov__provider">
        <span v-if="r.described">Agentimus wrote this</span>
        <span v-else-if="r.auto">Agentimus found this · {{ foundWhere }}</span>
        <span v-else>This plugin told us · <code>{{ r.provider }}</code></span>
      </p>

      <div v-if="r.capabilities.length" class="ar-wd-caps">
        <span v-for="c in r.capabilities" :key="c" class="ar-wd-cap">{{ c }}</span>
      </div>

      <ul v-if="r.endpoints.length" class="ar-wd-eps">
        <li v-for="(e, i) in r.endpoints" :key="i">
          <span class="ar-wd-ep__type">{{ e.type }}</span>
          <code>{{ e.url }}</code>
          <span class="ar-wd-auth" :class="`is-${e.auth === 'none' ? 'open' : 'locked'}`">
            {{ e.auth === 'none' ? 'public' : e.auth }}
          </span>
        </li>
      </ul>
    </div>
  </li>
</template>
