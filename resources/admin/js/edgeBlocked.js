/**
 * The words under a crawler's Blocked count on the At Cloudflare card. Kept out
 * of the component so they can be tested without mounting it.
 */

// Blocked requests that were not probes. Not "the crawler itself": Cloudflare
// counts by the name a request gives, so one of these can still be an impostor.
export function otherBlocked(c) {
  return Math.max(0, (Number(c.blocked) || 0) - (Number(c.probes) || 0));
}

// A crawler you let in with blocks a probe does not explain. A crawler you block
// being blocked is your rule working.
export function blockedBad(c) {
  return !c.blockedByYou && otherBlocked(c) > 0;
}

export function otherCount(n) {
  return `${Number(n).toLocaleString()} ${Number(n) === 1 ? 'other' : 'others'}`;
}

export function blockedParts(c) {
  const p = Number(c.probes) || 0;
  const other = blockedBad(c) ? otherCount(otherBlocked(c)) : '';
  let probes = '';
  if (p) probes = p >= (Number(c.blocked) || 0) ? 'all probes' : `${p.toLocaleString()} probes`;
  else if (other) probes = 'no probes';
  return { probes, other };
}
