/**
 * The one word on an edge conflict's badge — shared by the Request Log's pins
 * and the Settings card, so the two can never name one card differently.
 *
 * ⛔ THE KIND IS IN THE ID, NOT THE LEVEL. The badge used to read the level
 * alone (warn → "Conflict", anything else → "Not enforced"), written when the
 * only info card was the training notice. Then the spoof check learned to
 * stand a blocking warning down to info, and on heera.it (2026-09-23) the card
 * "Cloudflare is blocking impostors using OpenAI's name" wore "Not enforced"
 * — while the edge WAS enforcing, correctly, against the impostors. The
 * findings row hit the same trap first and was fixed the same way.
 *
 * @param {{ id?: string, level?: string }} c A conflict from the edge summary.
 * @returns {string}
 */
export function edgeBadge(c) {
  if (c.level === 'warn') {
    return 'Conflict';
  }
  // A stood-down blocking warning keeps its `edge-blocks-<operator>` id.
  if (String(c.id || '').startsWith('edge-blocks-')) {
    return 'Impostors';
  }
  return 'Not enforced';
}
