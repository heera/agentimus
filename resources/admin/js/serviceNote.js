/**
 * The service card's honesty line, computed once for every service.
 *
 * Each connected card carries the same one-line truth in the same order of
 * hope: the freshest error first (it is the one the owner can still act on),
 * then the last delivery, then the queue's promise, then the honest "nothing
 * has happened yet". Webhook, Telegram and every later service speak it
 * through this function so no card can drift into its own dialect.
 */
import { formatStamp } from './wpDate.js';

const when = (unix) => formatStamp(new Date(unix * 1000));

/**
 * @param {object} service A GET /integrations service block:
 *   { enabled, queued, state: { lastDeliveredAt, lastError, lastErrorAt } }.
 * @returns {{ text: string, isError: boolean }} The card's note ('' when off).
 */
export function serviceNote(service) {
  if (!service || !service.enabled) return { text: '', isError: false };
  const s = service.state || {};
  if (s.lastError) {
    return {
      text: `Last error: ${s.lastError}${s.lastErrorAt ? ` — ${when(s.lastErrorAt)}` : ''}`,
      isError: true,
    };
  }
  if (s.lastDeliveredAt) {
    let text = `Last delivered ${when(s.lastDeliveredAt)}`;
    if (service.queued > 0) text += ` — ${service.queued} waiting`;
    return { text, isError: false };
  }
  if (service.queued > 0) {
    return {
      text: `${service.queued} ${service.queued === 1 ? 'event' : 'events'} waiting for the next delivery run`,
      isError: false,
    };
  }
  return { text: 'Connected — nothing has happened to send yet.', isError: false };
}
