/**
 * The Machines card's words for its own number. With page reads in the count,
 * "fetched your agent files" says the opposite of the truth.
 *
 * @param {{pagesCounted: boolean}|null} m The `machines` half of the audience payload.
 * @returns {string} The line under the headline number.
 */
export function machinesUnit(m) {
  return m && m.pagesCounted ? 'requests to your site' : 'fetched your agent files';
}

/**
 * What the headline number is made of. Empty when it is all one kind.
 *
 * @param {{pagesCounted: boolean, fetches: number, pages: number, files: number}|null} m The `machines` half.
 * @param {(v: number) => string} n The card's number formatter.
 * @returns {string} "9,800 pages · 2,334 agent files", or ''.
 */
export function machinesSplit(m, n) {
  if (!m || !m.pagesCounted || !m.fetches) return '';
  const pages = `${n(m.pages)} ${m.pages === 1 ? 'page' : 'pages'}`;
  const files = `${n(m.files)} ${m.files === 1 ? 'agent file' : 'agent files'}`;
  return `${pages} · ${files}`;
}
