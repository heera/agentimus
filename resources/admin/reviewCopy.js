// Pure copy for the Review-queue impostor (spoofed verifiable-engine) Details panel — the
// sentence telling the owner how to act on a client caught faking a verifiable crawler.
// Extracted from ReviewMenu.vue as a pure function so its three IP states can be verified
// without mounting Vue.
//
// States:
//   1. an IP is stored for this client       → point straight at the address(es) to block
//   2. no IP, but IP-storage is ON            → this visit predates storage (NOT retroactive);
//                                               future flagged visits will show their IP here
//   3. no IP, and IP-storage is OFF           → turn it on for FUTURE visits (can't recover this)
// States 2 and 3 both fall back to "find it in your access log / CDN by the User-Agent" for
// THIS already-past visit, whose IP was never recorded. The old copy always emitted state 3's
// "turn it on…" line — misleading once the setting is already on (a past hit still shows no IP).

/**
 * @param {Object}   opts
 * @param {string}   opts.name       Bot the client claims to be, e.g. "Yandex" or "GPTBot (OpenAI)".
 * @param {number}  [opts.ipCount]   How many IPs are stored for this client (0 = none).
 * @param {boolean} [opts.storeIps]  Whether "Store IP addresses for flagged clients" is ON.
 * @param {string}  [opts.method]    Which check condemned it: 'rdns' | 'ranges' | 'both'.
 * @returns {string}
 */
export function impostorDetail({ name, ipCount = 0, storeIps = false, method = 'rdns' }) {
  const findIt = 'find it in your server’s access log or CDN/firewall dashboard by searching for the User-Agent above, then block it there';
  // How it was caught, in the operator's own terms: a failed reverse-DNS check, an
  // address outside the operator's published IP ranges, or (both methods registered)
  // the generic phrasing — the stored verdict doesn't say which check fired.
  const failed = 'ranges' === method
    ? `arrived from outside ${name}’s published IP ranges`
    : ('rdns' === method ? 'failed reverse-DNS verification when it visited' : 'failed identity verification when it visited');

  if (ipCount > 0) {
    return `It claims to be ${name}, but its address ${failed} — a forgery, not the real ${name}. Blocking the name would also block the genuine ${name}, so block the IP address${1 === ipCount ? '' : 'es'} above at your host or CDN/firewall.`;
  }

  const lead = `It claims to be ${name}, but its network address ${failed} — a forgery, not the real ${name}. Blocking the name would also block the genuine ${name}, so block the impostor’s IP instead.`;

  if (storeIps) {
    // Setting is ON yet no IP is stored → this visit happened before storage was enabled.
    return `${lead} No IP was recorded for this visit — it happened before “Store IP addresses for flagged clients” was on, and addresses aren’t captured retroactively. Any future flagged visit will show its IP here automatically; for this one, ${findIt}.`;
  }

  // Setting is OFF.
  return `${lead} To capture the IP of future flagged visits, turn on “Store IP addresses for flagged clients” in Settings — it records forward only, so it can’t recover this past visit. To find this one now, ${findIt}.`;
}

/**
 * Detail copy for a legacy-device spoof (a scanner hiding behind a long-dead handset UA).
 * Unlike an impostor, this one CAN be blocked safely by name/class (the "Block scanners"
 * button), so blocking the class stays the primary advice. But its IP is captured too when
 * storage is on, so when one is present we acknowledge it rather than leaving it unexplained.
 *
 * @param {Object}  [opts]
 * @param {number} [opts.ipCount] How many IPs are stored for this client (0 = none).
 * @returns {string}
 */
export function scannerDetail({ ipCount = 0 } = {}) {
  const base = 'It claims a long-dead device (Symbian, Java ME, old Windows CE…) that no real visitor runs — near-always a scanner hiding behind an innocuous name. Blocking the scanner class is safe.';
  if (ipCount > 0) {
    return `${base} Its address${1 === ipCount ? ' is' : 'es are'} shown above too, if you’d rather block ${1 === ipCount ? 'it' : 'them'} at your host or CDN.`;
  }
  return base;
}
