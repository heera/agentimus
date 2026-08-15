/**
 * Say a transport failure in the owner's words, and keep the machine's.
 *
 * A stored error is evidence: "cURL error 28: Failed to connect to
 * ssl.bing.com port 443 after 10001 ms: Timeout was reached." is exactly right
 * for a developer reading a log, and exactly wrong on a settings card, where
 * it reads as something the owner broke and cannot fix.
 *
 * So the raw sentence is never rewritten in storage — it is translated for
 * DISPLAY, and the original travels with it so it is one hover away. Nothing
 * here guesses: a shape we do not recognise is shown as it came, because an
 * invented explanation is worse than a technical one.
 *
 * Every plain sentence answers the same three questions in order: what
 * happened, whose side it is on, and whether anyone has to do anything.
 */

// Each rule is [ matcher, plain sentence ]. `who` is the service's own name so
// the sentence can say which side went quiet.
const RULES = [
  // Transport — the site's own server could not get out.
  [/curl error 28|timed? ?out|timeout was reached/i, (who) => `Your server couldn’t reach ${who} in time. That is usually a slow moment on one side or the other, and the next poll normally succeeds on its own.`],
  [/curl error 6|could ?n.t resolve host|name or service not known/i, (who) => `Your server couldn’t look up ${who}’s address — a DNS problem on your hosting, not with the connection itself.`],
  [/curl error 7|failed to connect|connection refused/i, (who) => `Your server couldn’t open a connection to ${who}. A firewall on your hosting is the usual reason.`],
  [/curl error (35|60)|ssl|certificate/i, (who) => `Your server couldn’t verify ${who}’s security certificate. That is a setting on your hosting, not something ${who} controls.`],
  // The far side answered, and said no.
  [/\b401\b|unauthorized|invalid[_ ]?grant|token.*(expired|invalid)/i, (who) => `${who} refused the key. Reconnect below to give it a fresh one.`],
  [/\b403\b|forbidden|permission/i, (who) => `${who} accepted the key but refused this request — the key is missing a permission.`],
  [/\b404\b|not found/i, (who) => `${who} says that property no longer exists. Check the id below.`],
  [/\b429\b|rate ?limit|too many requests/i, (who) => `${who} asked us to slow down. Agentimus backs off and tries again later.`],
  [/\b5\d\d\b|internal server error|service unavailable|bad gateway/i, (who) => `${who}’s own service returned an error. Nothing on your side to fix — the next poll will try again.`],
];

/**
 * @param {string} raw     The stored message, verbatim.
 * @param {string} service The service's name, for the sentence ("Bing").
 * @returns {{text: string, technical: string}} `technical` is '' when the
 *          plain text IS the raw one — there is then nothing extra to reveal.
 */
export function plainError(raw, service = 'the service') {
  const message = String(raw || '').trim();
  if (!message) return { text: '', technical: '' };

  for (const [pattern, say] of RULES) {
    if (pattern.test(message)) {
      return { text: say(service), technical: message };
    }
  }
  // Unrecognised: the owner gets the truth as it came. Never a guess.
  return { text: message, technical: '' };
}
