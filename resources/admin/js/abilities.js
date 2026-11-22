/**
 * Words for the Abilities screen.
 */

/** A plugin's declared level, in the owner's words. */
export function abilityLevelLabel(level) {
  return { read: 'Read', write: 'Write', delete: 'Delete' }[level] || 'Write';
}

/** "2 of 5 on", or "All 3 off". */
export function pluginOnLine(plugin) {
  const total = (plugin && plugin.abilities ? plugin.abilities.length : 0);
  const on = (plugin && plugin.abilities ? plugin.abilities.filter((a) => a.on).length : 0);
  if (on === 0) return total === 1 ? 'Off' : `All ${total} off`;
  if (on === total) return total === 1 ? 'On' : `All ${total} on`;
  return `${on} of ${total} on`;
}

/** The toast after one switch: "Get Site Information is on for agents." */
export function switchedOneLine(label, on) {
  return on ? `${label} is on for agents.` : `${label} is off for agents.`;
}

/** The toast after Turn all: "All 3 core abilities are on for agents." */
export function switchedAllLine(title, count, on) {
  const what = count === 1 ? `The ${title} ability is` : `All ${count} ${title} abilities are`;
  return `${what} ${on ? 'on' : 'off'} for agents.`;
}
