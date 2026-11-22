/**
 * Words and rules for the Skills screen.
 */

/** Who wrote a skill, in the owner's words. */
export function skillByLabel(by) {
  return by === 'agent' ? 'Saved by an agent' : 'Written by you';
}

/** Skills an agent saved that wait off for the owner: shown first. */
export function waitingSkills(skills) {
  return (skills || []).filter((s) => s.by === 'agent' && !s.on);
}

/** The server's limits, so the fields stop where the server would refuse. */
export const skillLimits = { name: 80, when: 300, steps: 8000 };
