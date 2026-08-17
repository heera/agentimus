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

// How long a due event may sit before waiting stops being normal and starts
// being news. A fresh event is due 30 seconds after it happens, and a failing
// one serves a backoff of at most 15 minutes — but a row serving its backoff is
// excluded server-side, so anything still waiting after this really is waiting
// on a cron run that is not coming.
const STALL_AFTER = 15 * 60;

// Plain, coarse duration — "about 3 hours", never "2h 58m". The exact figure
// would imply a precision the diagnosis does not have.
function roughly(seconds) {
  const mins = Math.floor(seconds / 60);
  if (mins < 60) return `about ${mins} minutes`;
  const hours = Math.round(mins / 60);
  if (hours < 48) return `about ${hours} ${hours === 1 ? 'hour' : 'hours'}`;
  return `about ${Math.round(hours / 24)} days`;
}

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
  // ⭐ A STALLED QUEUE OUTRANKS A GOOD LAST DELIVERY, because "Last delivered
  // yesterday" beside a queue that has not moved since reads as healthy. Events
  // ride WordPress cron, which only runs when a request reaches PHP — on a
  // heavily cached site (or a very quiet one) it may not be running at all, and
  // nothing else on this screen would ever say so. An observation, not an
  // accusation: it states what is true and what usually causes it, and stops.
  if (service.queued > 0 && service.stalledFor > STALL_AFTER) {
    const n = service.queued;
    return {
      text:
        `${n} ${n === 1 ? 'event has' : 'events have'} been waiting ${roughly(service.stalledFor)}. ` +
        'Nothing can go out until your site runs its scheduled tasks. ' +
        'This usually means WordPress cron is not running.',
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
