/**
 * Paint a change now, and take it back if the write behind it fails — an
 * optimistic flip that never reverts leaves the screen saying something the
 * site never recorded. A newer change made while the write was out is left
 * alone. The failure is re-thrown so the caller can still say what happened.
 *
 * @param {object} row    The object the screen reads.
 * @param {string} key    The field to change.
 * @param {*} value       Its new value.
 * @param {() => Promise<*>} write The save behind it.
 * @returns {Promise<*>} The write's answer.
 */
export function optimistic(row, key, value, write) {
  const before = row[key];
  row[key] = value;
  let pending;
  try {
    pending = Promise.resolve(write());
  } catch (e) {
    pending = Promise.reject(e);
  }
  return pending.catch((e) => {
    if (row[key] === value) row[key] = before;
    throw e;
  });
}
