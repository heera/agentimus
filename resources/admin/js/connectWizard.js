/**
 * The connect wizard's rules, kept out of the .vue so a test can hold them:
 * which apps it offers, each app's steps, and when a wait has ended.
 *
 * ⛔ Only a connection approved AFTER the wizard opened ends the wait. The
 * snapshot taken at open holds every grant as it was then, so an old
 * connection (or one approved again with the same time) can never read as
 * the one the owner just made. The shared token is not watched: any
 * assistant already holding it calls the site, so a call proves nothing
 * about the app being set up now.
 */

// Ten minutes of asking every three seconds, then "Check again".
export const POLL_MS = 3000;
export const WAIT_MS = 10 * 60 * 1000;

export const APPS = [
  { key: 'claude', label: 'Claude', kind: 'web · desktop · mobile' },
  { key: 'claude-code', label: 'Claude Code', kind: 'terminal' },
  { key: 'chatgpt', label: 'ChatGPT', kind: 'web · desktop' },
  { key: 'cursor', label: 'Cursor', kind: 'editor' },
  { key: 'vscode', label: 'VS Code', kind: 'editor' },
  { key: 'other', label: 'Something else', kind: 'any MCP client' },
];

/**
 * One app's setup steps. `url` is this site's MCP address; `token` is the
 * shared token's plaintext when this page just created it ('' otherwise).
 */
export function recipeFor(key, url, token) {
  const map = {
    claude: {
      title: 'Claude — web, desktop, mobile',
      steps: [
        'Settings → Connectors → Add custom connector.',
        'Paste the address above. Claude opens a page on this site asking for your approval.',
        'Approve, and Claude appears in Connected assistants.',
      ],
    },
    'claude-code': {
      title: 'Claude Code — terminal',
      steps: ['Run this command.', 'Start Claude Code — it sends you here to approve.'],
      copy: `claude mcp add --transport http agentimus ${url}`,
    },
    cursor: {
      title: 'Cursor — editor',
      steps: [
        'Settings → MCP → add a server with the address above, no key.',
        'Ask Cursor something about your site; it shows an Authenticate button.',
        'Approve here, and Cursor is connected.',
      ],
      note: 'Needs a recent Cursor — old versions ignore MCP install links and show nothing.',
    },
    vscode: {
      title: 'VS Code — editor',
      steps: [
        'Command Palette → MCP: Add Server → HTTP → paste the address above.',
        'Confirm, then approve here when it asks.',
      ],
    },
    chatgpt: {
      title: 'ChatGPT — web, desktop & Codex',
      steps: [
        'In ChatGPT: Settings → Security → turn on “Developer mode”. It unlocks custom connectors (ChatGPT pauses its memory feature while it is on).',
        'Plugins → the “+” button → paste the address above, keep Authentication on “OAuth”, and Create.',
        '“Sign in with Agentimus” opens a page on this site — approve, and ChatGPT appears in Connected assistants.',
        'Codex can’t ask for approval: create a token under “Shared token” on the MCP card and send it as this header.',
      ],
      copy: token ? `Authorization: Bearer ${token}` : 'Authorization: Bearer <create a token below>',
      note: 'Site behind Cloudflare? Free-plan “Bot Fight Mode” blocks ChatGPT’s calls with a 403 — turn it off for this to work. Verified July 2026.',
    },
    other: {
      title: 'Any MCP client',
      steps: [
        'Give it the address above, over HTTP transport.',
        'If it can ask for approval, it sends you here. If it cannot, create one under “Shared token” on the MCP card and send it as a Bearer header.',
      ],
      copy: token ? `Authorization: Bearer ${token}` : '',
    },
  };
  return map[key] || map.claude;
}

const seenKey = (g) => `${g.clientId}:${g.approved}`;

/** What was connected when the wizard opened. */
export function snapshot(grants, openedAt) {
  return { seen: new Set((grants || []).map(seenKey)), openedAt };
}

/**
 * The connections approved since the snapshot, newest approval first.
 */
function freshSince(snap, grants) {
  return (grants || [])
    .filter((g) => g && g.clientId && !snap.seen.has(seenKey(g)))
    .sort((a, b) => (b.approved || 0) - (a.approved || 0));
}

// Approved is not connected: the app still has to come back with its code.
// `signedIn` is 0 until it does; null means a server that does not record it,
// where the approval is all there is to go on.
const signedIn = (g) => g.signedIn === null || g.signedIn === undefined || g.signedIn > 0;

/**
 * The connection approved since the snapshot whose app has signed in, or
 * null. When two arrive in one wait, the most recently approved wins: it is
 * the one the owner just clicked.
 */
export function newConnection(snap, grants) {
  return freshSince(snap, grants).find(signedIn) || null;
}

/**
 * A connection approved since the snapshot whose app has not signed in yet,
 * or null: the wizard says it is waiting for the app, not for the owner.
 */
export function approvedOnly(snap, grants) {
  return freshSince(snap, grants).find((g) => !signedIn(g)) || null;
}

/** Whether a wait that began at `startedAt` has run its ten minutes. */
export function waitOver(startedAt, now) {
  return now - startedAt >= WAIT_MS;
}
