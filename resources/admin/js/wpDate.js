/**
 * Format dates the way THIS WordPress formats dates.
 *
 * The app used to print timestamps with toLocale*() — the browser's idea of a date —
 * which ignored the formats the owner chose under Settings → General. These helpers
 * render with the site's own `date_format`/`time_format` (injected into the boot
 * payload by Admin::bootstrap_data()), implementing the PHP date() tokens those
 * options are written in. Month and weekday names come from Intl in the admin
 * language (<html lang>), so a translated admin keeps translated names.
 *
 * Each helper takes a Date plus a `utc` flag: true reads the UTC clock face (the
 * activity store's calendar days and timestamps are UTC and say so on screen),
 * false the viewer's local one — the same semantics each call site had before.
 */

const FALLBACK_DATE = 'F j, Y';
const FALLBACK_TIME = 'g:i a';

function opt(key, fallback) {
  const boot = window.AgentimusData || {};
  return typeof boot[key] === 'string' && boot[key] ? boot[key] : fallback;
}

// One Intl formatter per (token, clock) — they are expensive to construct and the
// month/weekday name shapes never change within a page load.
const intlCache = {};
function intlName(token, opts, d, utc) {
  const key = token + (utc ? ':utc' : '');
  if (!intlCache[key]) {
    const lang = document.documentElement.lang || undefined;
    intlCache[key] = new Intl.DateTimeFormat(lang, utc ? { ...opts, timeZone: 'UTC' } : opts);
  }
  return intlCache[key].format(d);
}

const pad = (n) => (n < 10 ? '0' : '') + n;

// English ordinal suffix for `S` (as in "jS F Y"). The app's surrounding prose is
// English, so English suffixes rather than a shipped translation table.
function ordinal(j) {
  if (j % 100 >= 11 && j % 100 <= 13) return 'th';
  return ['th', 'st', 'nd', 'rd'][j % 10] || 'th';
}

// The viewer's timezone abbreviation for `T` — formatToParts, because the short
// form only exists as a part, never as its own format.
function tzAbbr(d) {
  const lang = document.documentElement.lang || undefined;
  const part = new Intl.DateTimeFormat(lang, { timeZoneName: 'short' })
    .formatToParts(d)
    .find((p) => 'timeZoneName' === p.type);
  return part ? part.value : '';
}

/**
 * The PHP date() tokens a Settings → General format can realistically hold.
 * Unknown letters pass through as literals, `\x` escapes exactly like PHP.
 */
function tokens(d, utc) {
  const Y = utc ? d.getUTCFullYear() : d.getFullYear();
  const n = (utc ? d.getUTCMonth() : d.getMonth()) + 1;
  const j = utc ? d.getUTCDate() : d.getDate();
  const w = utc ? d.getUTCDay() : d.getDay();
  const G = utc ? d.getUTCHours() : d.getHours();
  const i = utc ? d.getUTCMinutes() : d.getMinutes();
  const s = utc ? d.getUTCSeconds() : d.getSeconds();
  const g = G % 12 || 12;
  return {
    d: pad(j),
    j: String(j),
    S: ordinal(j),
    D: intlName('D', { weekday: 'short' }, d, utc),
    l: intlName('l', { weekday: 'long' }, d, utc),
    N: String(0 === w ? 7 : w),
    w: String(w),
    F: intlName('F', { month: 'long' }, d, utc),
    M: intlName('M', { month: 'short' }, d, utc),
    m: pad(n),
    n: String(n),
    Y: String(Y),
    y: String(Y).slice(-2),
    a: G < 12 ? 'am' : 'pm',
    A: G < 12 ? 'AM' : 'PM',
    g: String(g),
    h: pad(g),
    G: String(G),
    H: pad(G),
    i: pad(i),
    s: pad(s),
    T: utc ? 'UTC' : tzAbbr(d),
    U: String(Math.floor(d.getTime() / 1000)),
  };
}

function run(fmt, d, utc) {
  if (!(d instanceof Date) || Number.isNaN(d.getTime())) return '';
  const t = tokens(d, utc);
  let out = '';
  for (let k = 0; k < fmt.length; k++) {
    const c = fmt.charAt(k);
    if ('\\' === c) {
      k += 1;
      out += k < fmt.length ? fmt.charAt(k) : '';
    } else {
      out += Object.prototype.hasOwnProperty.call(t, c) ? t[c] : c;
    }
  }
  return out;
}

/** The site's date format (Settings → General → "Date Format"). */
export function formatDate(d, utc = false) {
  return run(opt('dateFormat', FALLBACK_DATE), d, utc);
}

/** The site's time format (Settings → General → "Time Format"). */
export function formatTime(d, utc = false) {
  return run(opt('timeFormat', FALLBACK_TIME), d, utc);
}

/** Date and time together, joined the way core's list tables join them. */
export function formatStamp(d, utc = false) {
  const date = formatDate(d, utc);
  const time = formatTime(d, utc);
  return date && time ? `${date} ${time}` : date || time;
}

/**
 * The app's short relative age — "just now" / "5m ago" / "3h ago" / "2d ago".
 * The one wording used across the data panels (Request Log, Edge/Bing/Google
 * "updated Nm ago", the review feed). Takes epoch MILLISECONDS already
 * normalised by the caller: an ISO string via `new Date(iso).getTime()`, a
 * Unix-seconds stamp via `Number(ts) * 1000`. Empty for a missing or
 * unparseable time (NaN or 0), and "just now" for anything in the future.
 *
 * "just now" covers a rounded age under one minute — so an item 30–59s old
 * reads "1m ago" (the minute-first rounding four of the six former copies
 * used). From one minute up, every former copy was already byte-identical.
 */
export function relTimeShort(ms) {
  if (!ms) return '';
  const m = Math.round((Date.now() - ms) / 60000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h}h ago`;
  return `${Math.round(h / 24)}d ago`;
}

/**
 * Why a day-scoped card can name a date the reader's own clock has left — or
 * '' when the two agree and there is nothing to explain.
 *
 * ⛔⛔ THE COUNTS ARE UTC DAYS AND CANNOT BE ANYTHING ELSE. Reads are stamped
 * in GMT and the referral store keeps one row per (day, source, path) with the
 * day baked in at write time — there is no per-visit stamp to re-bucket, here
 * or in the history already stored. Counting one of them on the site's clock
 * and the other on UTC would put two different days in one row.
 *
 * ⭐ SO THE DAY STAYS UTC AND THE LABEL SAYS SO — but only in the hours when
 * that matters. His site is six hours ahead: at 01:47 on August 25 the card
 * read "August 24" and was right, and there was no way to know that from the
 * screen. 2026-08-25.
 *
 * ⛔ Measured against THIS BROWSER, not the site's timezone option: the
 * setting says where the site keeps its calendar, never where the person
 * reading is. wpftest is set to UTC and gets read on a +06 laptop — the
 * server cannot see that, and a note keyed to the option stays silent for
 * exactly the reader who needs it.
 *
 * @param {string} serverToday The day the server calls today, 'YYYY-MM-DD' UTC.
 * @returns {string}
 */
export function utcDayNote(serverToday) {
  if (!serverToday) return '';
  const now = new Date();
  const here = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  if (here === serverToday) return '';
  return `Days here are counted in UTC, the same as your weekly email. Your own clock is on ${formatDate(now)}.`;
}
