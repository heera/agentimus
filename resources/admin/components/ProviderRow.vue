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
        Every tool here needs an authenticated WordPress user, so an anonymous agent could never
        run one. Advertising them in the public discovery documents would hand out a map of your
        tooling — the full descriptions and input/output schemas — without letting any agent
        actually use it. Agents that hold real credentials still find them the proper way.
      </p>
      <p v-else-if="!r.suppressed && r.notPublic" class="ar-wd-prov__held">
        Held back for the same reason as above — its tools need a signed-in user.
      </p>
      <p class="ar-wd-prov__provider">
        <span v-if="r.auto">Found automatically · via the {{ r.engine }}</span>
        <span v-else>Provided by <code>{{ r.provider }}</code></span>
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
