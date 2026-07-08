<script>
/**
 * "Check an IP" — a small, self-contained tool (Settings → AI Access) that reverse-DNS
 * identifies any address: paste an IP, and it says which verifiable search engine it really
 * belongs to (forward-confirmed), or that it doesn't. Engine-agnostic and read-only — it
 * touches no stored data and no review row, so it's the global counterpart to a review row's
 * per-client "Re-check". HONEST by construction: it reports only what reverse-DNS proves
 * (verified / forged / not-a-known-engine / no-record), never a score.
 *
 * Because the admin supplies the address, it needs no `verify_bots` setting and is unaffected
 * by any CDN/proxy in front of the site — there's no visitor IP to derive (the CDN only ever
 * matters for the automatic per-visit path, which must resolve the real client IP itself).
 */
export default {
  name: 'IpChecker',
  props: {
    api: { type: Object, required: true },
  },
  data() {
    return { ip: '', busy: false, result: null, error: '' };
  },
  computed: {
    // The result as a view-model: tone + headline + one plain sentence. Null until a check runs.
    view() {
      const r = this.result;
      if (!r) return null;
      const engine = this.engineName(r.engine);
      if (1 === r.verdict) {
        return { tone: 'ok', head: `Verified ${engine}`, detail: `${r.ip} reverse-resolves to ${r.host} and forward-confirms — a genuine ${engine} address.` };
      }
      if (2 === r.verdict) {
        return { tone: 'danger', head: 'Forged — impostor', detail: `${r.ip} points to ${r.host} (in ${engine}'s domain) but doesn't forward-confirm — a faked reverse-DNS record, not the real ${engine}.` };
      }
      if (r.slow) {
        return { tone: 'muted', head: 'Couldn’t determine', detail: 'The resolver was too slow to answer — try again in a moment.' };
      }
      if (r.host) {
        return { tone: 'muted', head: 'Not a known search engine', detail: `${r.ip} reverse-resolves to ${r.host}, which isn’t one of the verifiable engines (Googlebot, Bingbot, DuckDuckBot, Applebot, Yandex).` };
      }
      return { tone: 'muted', head: 'No reverse-DNS record', detail: `${r.ip} has no PTR record — a genuine search-engine crawler always does.` };
    },
  },
  watch: {
    // The shown result belongs to the last-checked address; once the box is edited or
    // cleared it no longer matches, so drop it and let the user run a fresh check.
    ip() {
      this.result = null;
      this.error = '';
    },
  },
  methods: {
    async check() {
      const ip = (this.ip || '').trim();
      if (!ip || this.busy) return;
      this.busy = true;
      this.result = null;
      this.error = '';
      try {
        this.result = await this.api.checkIp(ip);
      } catch (e) {
        this.error = (e && e.message) || 'The check failed — try again.';
      } finally {
        this.busy = false;
      }
    },
    engineName(token) {
      return {
        googlebot: 'Googlebot', bingbot: 'Bingbot', duckduckbot: 'DuckDuckBot',
        applebot: 'Applebot', yandex: 'Yandex',
      }[token] || (token || 'a search engine');
    },
  },
};
</script>

<template>
  <div class="ar-ipcheck">
    <div class="ar-ipcheck__head">Check an IP address</div>
    <p class="ar-ipcheck__intro">
      Paste any IP to see which search engine it really belongs to — a live reverse-DNS lookup,
      independent of the review queue. Needs no setting turned on, and works the same with or
      without a CDN — you give it the address directly, so there’s no proxy to see through.
    </p>
    <form class="ar-ipcheck__row" @submit.prevent="check">
      <input
        v-model.trim="ip"
        type="text"
        class="ar-ipcheck__input"
        placeholder="e.g. 66.249.66.1"
        aria-label="IP address to check"
        autocomplete="off"
        spellcheck="false"
        :disabled="busy"
      />
      <button type="submit" class="ar-ipcheck__btn" :disabled="busy || !ip">
        {{ busy ? 'Checking…' : 'Check' }}
      </button>
    </form>
    <p v-if="error" class="ar-ipcheck__result is-danger" role="status">{{ error }}</p>
    <div v-else-if="view" class="ar-ipcheck__result" :class="'is-' + view.tone" role="status">
      <strong>{{ view.head }}</strong>
      <span>{{ view.detail }}</span>
    </div>
  </div>
</template>
