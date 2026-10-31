/**
 * What to tell the owner after a Block click, from the `edge` part of the answer.
 * The row has already left the queue, so this is the only place to say it.
 *
 * @param {{ok: boolean, refusals: Array<{message: string}>, error: string}|undefined} edge
 * @returns {{type: string, text: string}|null} null when Cloudflare was not involved.
 */
export function edgeBlockNotice(edge) {
  if (!edge) return null;
  if (edge.ok) return { type: 'success', text: 'Blocked in Agentimus and at Cloudflare.' };
  const refusal = edge.refusals && edge.refusals[0];
  if (refusal) return { type: 'error', text: `Blocked in Agentimus only. Not sent to Cloudflare: ${refusal.message}` };
  if (edge.error) return { type: 'error', text: `Blocked in Agentimus only. Cloudflare said: ${edge.error}` };
  return { type: 'error', text: 'Blocked in Agentimus only. Cloudflare did not take it.' };
}

/**
 * One row's Cloudflare status, in words.
 *
 * @param {{status: string, reason?: string}} row
 * @returns {string}
 */
export function edgeRowLabel(row) {
  if (row.status === 'on') return 'Blocked at Cloudflare';
  if (row.status === 'can_add') return 'Blocked in Agentimus only';
  if (row.status === 'unseen') return 'Blocked in Agentimus only. No recent visits';
  return row.reason || 'Stays blocked in Agentimus only';
}

/**
 * The confirmation for blocking several at once: what goes up, and what stays behind.
 *
 * @param {Array<{token: string, status: string}>} rows
 * @returns {string}
 */
export function edgeAddAllMessage(rows) {
  const adding = rows.filter((r) => r.status === 'can_add').map((r) => r.token);
  const staying = rows.filter((r) => r.status === 'unseen' || r.status === 'refused').map((r) => r.token);
  const noun = adding.length === 1 ? 'client' : 'clients';
  let text = `Block ${adding.length} ${noun} at Cloudflare: ${adding.join(', ')}. Cloudflare will challenge them on every page.`;
  if (staying.length) text += ` ${staying.length} stay blocked in Agentimus only: ${staying.join(', ')}.`;
  return text;
}
