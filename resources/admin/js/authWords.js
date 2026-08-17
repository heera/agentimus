/**
 * One place that turns a discovery document's auth scheme into words a shop
 * owner reads.
 *
 * ⚠️⚠️ WHY THIS EXISTS. The Discovery screen printed the scheme raw, so an
 * address registered with `apikey` showed a chip reading `apikey` — beside a row
 * whose own state pill, four pixels away, said "Needs a key" in plain English.
 * Two labels for one fact, one of them jargon, on the same screen. The document's
 * vocabulary (none · apikey · basic · oauth2 · oidc · custom) is written for
 * machines; nothing obliges us to hand it to a person unchanged.
 *
 * ⛔ AND THE ONE RULE THAT KEEPS THIS HONEST: a scheme we have no plain word for
 * prints AS IT CAME. Inventing a friendly label for a word we do not know is how
 * a screen starts lying — the same rule the provider rows follow for a resource
 * type nobody has a word for.
 */

/**
 * The five schemes the spec defines, in the owner's words.
 *
 * `basic`, `oauth2` and `oidc` all end in the same place for a reader: somebody
 * has to be signed in. The difference between them matters to whoever writes the
 * client, and that person is reading the document, not this chip.
 */
const WORDS = {
  none: 'public',
  apikey: 'needs a key',
  basic: 'needs a sign-in',
  oauth2: 'needs a sign-in',
  oidc: 'needs a sign-in',
  // Its own scheme, on the vendor's terms. We can still say the true half: not
  // open to a stranger.
  custom: 'needs a sign-in',
};

/**
 * @param {string} scheme The auth scheme as the document states it.
 * @returns {string} Plain words, or the scheme itself when we have none.
 */
export function authWords(scheme) {
  const key = String(scheme || 'none').toLowerCase();
  return WORDS[key] || key;
}

/**
 * Whether this scheme means "anyone may read it" — the one that earns the open
 * styling. Asked of the scheme, never of the words, so a translation can never
 * change which chip is green.
 *
 * @param {string} scheme The auth scheme.
 * @returns {boolean}
 */
export function isOpenToAnyone(scheme) {
  return 'none' === String(scheme || 'none').toLowerCase();
}
