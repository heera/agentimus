/**
 * One place that turns an activity label into words a site owner reads.
 *
 * ⚠️⚠️ WHY THIS EXISTS. The Activity card and the Request Log both render the
 * `endpoint` column of the activity table STRAIGHT OUT OF THE DATABASE — the
 * key whatever served the request happened to pass to Recorder::record(). Most
 * of those keys are real filenames an owner already recognises (`llms.txt`,
 * `discovery.json`), so nobody noticed the rest were internal: `rest:discovery`
 * put a namespace prefix we invented on screen, and `markdown` is not a file at
 * all — it is every `.md` twin on the site rolled into one row.
 *
 * ⛔ A KEY IS NOT A LABEL. Only the keys below get renamed; a real filename is
 * left exactly as it is, because the rail, Discovery and the owner's own robots
 * file all call it that, and inventing a prettier name here would be a second
 * vocabulary for one thing.
 *
 * ⛔ AND AN UNKNOWN KEY IS RETURNED UNCHANGED, never guessed at or hidden. A
 * provider that starts recording its own surface should show up as itself and
 * be added here on purpose — not silently vanish or get a made-up name.
 */

/**
 * The keys that are NOT filenames. Everything absent from this map is one.
 *
 * ⚠️ `markdown` is the odd one: it is a GROUP, not an address, so its label has
 * to say so or the row reads as a single file that got fetched 500 times.
 */
const NAMES = {
  markdown: 'Page markdown (.md)',
  'rest:discovery': 'Discovery (REST API)',
  'api-catalog': 'API catalog',
  'agent-skills': 'Agent skill',
  'agent-skills/SKILL.md': 'Agent skill (SKILL.md)',
  'agent-skills/index.json': 'Agent skill index',
  'oauth-authorization-server': 'OAuth server info',
  'oauth-protected-resource': 'OAuth resource info',
  'oauth-protected-resource-mcp': 'OAuth resource info (MCP)',
  changes: 'Change feed',
  sitemap: 'Sitemap',
};

/**
 * @param {string} key The activity label as recorded.
 * @returns {string} What to put on screen.
 */
export function surfaceName(key) {
  const k = String(key || '');
  return Object.prototype.hasOwnProperty.call(NAMES, k) ? NAMES[k] : k;
}

/**
 * Whether a key is one we renamed — the caller can then show the raw key as a
 * tooltip, so a developer can still find the row in the database.
 *
 * @param {string} key The activity label as recorded.
 * @returns {boolean}
 */
export function isRenamed(key) {
  return Object.prototype.hasOwnProperty.call(NAMES, String(key || ''));
}
