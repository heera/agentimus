<script>
// One row in the Discovery Hub "Registered providers" inventory. Shared by both
// the Declared and Auto-discovered groups so the markup stays in one place.
export default {
  name: 'ProviderRow',
  props: {
    r: { type: Object, required: true },
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
      </div>
      <p v-if="r.description" class="ar-wd-prov__desc">{{ r.description }}</p>
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
