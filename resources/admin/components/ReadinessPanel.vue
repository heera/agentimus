<script>
export default {
  name: 'ReadinessPanel',
  props: {
    checks: { type: Array, default: () => [] },
    refreshing: { type: Boolean, default: false },
  },
  emits: ['refresh', 'navigate'],
  computed: {
    grouped() {
      const order = { fail: 0, warn: 1, pass: 2 };
      return [...this.checks].sort((a, b) => (order[a.status] ?? 3) - (order[b.status] ?? 3));
    },
  },
  methods: {
    tagLabel(status) {
      return { pass: 'PASS', warn: 'WARN', fail: 'FAIL' }[status] || status.toUpperCase();
    },
  },
};
</script>

<template>
  <section class="ar-card">
    <div class="ar-card__head">
      <h2 class="ar-card__title">Readiness report</h2>
      <button type="button" class="ar-btn" :disabled="refreshing" @click="$emit('refresh')">
        {{ refreshing ? 'Running…' : 'Re-run' }}
      </button>
    </div>

    <ul class="ar-checks">
      <li v-for="c in grouped" :key="c.id" class="ar-check" :class="`is-${c.status}`">
        <span class="ar-check__rule" aria-hidden="true"></span>
        <div class="ar-check__text">
          <strong>{{ c.label }}</strong>
          <small>{{ c.detail }}</small>
          <p v-if="c.fix" class="ar-check__fix">{{ c.fix }}</p>
          <a
            v-if="c.action && c.action.href"
            class="ar-check__action"
            :href="c.action.href"
            target="_blank"
            rel="noopener"
          >{{ c.action.label }} ↗</a>
          <button
            v-else-if="c.action"
            type="button"
            class="ar-check__action"
            @click="$emit('navigate', { tab: c.action.tab, anchor: c.action.anchor })"
          >{{ c.action.label }} →</button>
        </div>
        <span class="ar-check__tag" :class="`is-${c.status}`">{{ tagLabel(c.status) }}</span>
      </li>
    </ul>
  </section>
</template>
