/**
 * "What can agents do on this site?": one choice over the stored switches.
 *
 * The switches stay the source of truth, because the tools and their safety checks read
 * them. This only reads them as a level and writes a level back. Write sets both the write
 * switch and the content switch. Publishing is an extra, so it is no level. A site that could write before that second
 * switch existed reads as CUSTOM and is left alone: moving it up would give it powers nobody chose on upgrade.
 */

export const CUSTOM = 'custom';

export const LEVELS = [
  {
    key: 'off',
    anchor: 'ar-feat-level-off',
    name: 'Off',
    gives: 'The MCP server doesn’t run. Agents can’t connect.',
    limit: 'No agent can reach this site.',
  },
  {
    key: 'read',
    anchor: 'ar-feat-enable_mcp_server',
    name: 'Read',
    gives: 'Agents can see your site’s data and reports. They can’t change anything.',
    limit: 'Can’t change anything.',
  },
  {
    key: 'write',
    anchor: 'ar-feat-enable_agent_writes',
    name: 'Write',
    gives: 'Everything in Read, plus write and edit posts and pages, organise categories, tags and media, trash or restore a post, and apply Readiness fixes.',
    limit: 'Can’t publish or schedule. ⚠ Can edit a live post, or take it offline by moving it to draft or the trash.',
  },
];

/**
 * @param {object} s The settings.
 * @returns {string} A level key, or CUSTOM.
 */
export function levelOf(s) {
  if (!s.enable_mcp_server) return 'off';
  if (!s.enable_agent_writes) return 'read';
  return s.agent_writes_content ? 'write' : CUSTOM;
}

/**
 * What an older setup is, in words. Empty for a state that is a level.
 *
 * @param {object} s The settings.
 * @returns {string}
 */
export function customNote(s) {
  if (CUSTOM !== levelOf(s)) return '';
  const can = s.agent_writes_publish ? 'write and publish' : 'write';
  return `Agents can ${can}, without the newer tools for organising terms, media and the trash. Choose Write to add them.`;
}

/**
 * The line under the choice: what these settings can't do, or what risk they carry.
 *
 * @param {object} s The settings.
 * @returns {string}
 */
export function limitFor(s) {
  const level = levelOf(s);
  const known = LEVELS.find((l) => l.key === level);
  if (known && 'write' !== level) return known.limit;
  const offline = CUSTOM === level ? 'moving it to draft' : 'moving it to draft or the trash';
  return s.agent_writes_publish
    ? `Can publish and schedule, with nobody checking a post when it goes live. ⚠ Can edit a live post, or take it offline by ${offline}.`
    : `Can’t publish or schedule. ⚠ Can edit a live post, or take it offline by ${offline}.`;
}

/**
 * @param {object} s     The settings, changed in place.
 * @param {string} level A level key. Anything else changes nothing.
 */
export function applyLevel(s, level) {
  const rank = LEVELS.findIndex((l) => l.key === level);
  if (rank < 0) return;
  s.enable_mcp_server = rank >= 1;
  s.enable_agent_writes = rank >= 2;
  s.agent_writes_content = rank >= 2;
  // Publishing is never a side effect of Write. The server clears all three extras
  // below Write on save, so the screen says so now.
  if (rank < 2) {
    s.agent_writes_publish = false;
    s.agent_writes_settings = false;
    s.agent_writes_maintenance = false;
  }
}

/** The extras a level below Write would turn off, by name. */
function tickedExtras(s) {
  const names = [];
  if (s.agent_writes_publish) names.push('Publish without your review');
  if (s.agent_writes_settings) names.push('Change Agentimus settings');
  if (s.agent_writes_maintenance) names.push('Refresh data and purge the cache');
  return names;
}

function extrasClause(s) {
  const names = tickedExtras(s).map((n) => `“${n}”`);
  if (!names.length) return '';
  const list = names.length > 1 ? `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}` : names[0];
  const them = names.length > 1 ? 'them' : 'it';
  return ` ${list} will also turn off. If you choose a higher level later, you’ll need to tick ${them} again.`;
}

/** What ticking Publish asks first. The form saves on change. */
export function publishWarning() {
  return {
    title: 'Let Agents Publish?',
    message: 'Agents can publish and schedule posts without your review, starting now. A post can go live with nobody checking it.',
    confirmLabel: 'Allow Publishing',
    cancelLabel: 'Cancel',
    tone: 'danger',
  };
}

/**
 * Whether a move needs the owner's yes first. The form saves on change, so one stray
 * click would otherwise apply at once. Only the moves that are risky or lose something
 * ask: going to Off, and going to Read with extras ticked. Ticking Publish has its own.
 *
 * @param {object} s     The settings.
 * @param {string} level The level chosen.
 * @returns {?{title:string,message:string,confirmLabel:string,cancelLabel:string,tone:string}}
 */
export function levelWarning(s, level) {
  const base = { cancelLabel: 'Cancel', tone: 'danger' };
  if ('off' === level) {
    return {
      ...base,
      title: 'Turn Agents Off?',
      message: `The MCP server stops, and connected assistants lose access until you choose a level again.${extrasClause(s)}`,
      confirmLabel: 'Turn Off',
    };
  }
  if ('read' === level && tickedExtras(s).length) {
    return {
      ...base,
      title: 'Switch to Read?',
      message: `Agents will only be able to read.${extrasClause(s)}`,
      confirmLabel: 'Switch to Read',
    };
  }
  return null;
}

/**
 * Apply a level, asking first when the move needs it.
 *
 * @param {object}   s     The settings, changed only if the move goes ahead.
 * @param {string}   level The level chosen.
 * @param {function} ask   Takes a {@see levelWarning}, resolves true to go ahead.
 * @returns {Promise<boolean>} Whether anything changed.
 */
export async function chooseLevel(s, level, ask) {
  if (level === levelOf(s)) return false;
  const warning = levelWarning(s, level);
  if (warning && !(await ask(warning))) return false;
  applyLevel(s, level);
  return true;
}
