/**
 * The words on a Connected agents row for what one connection may do.
 *
 * Everything follows the site's switches; Only some things is reading plus the
 * boxes ticked. A connection from before access records answers from its scope:
 * write = Everything, read = nothing ticked.
 */
export function connectionAccessLabel(access, scope, totalBoxes) {
  const a = access && access.preset ? access : { preset: scope === 'write' ? 'everything' : 'some', boxes: [] };
  if (a.preset === 'everything') return 'Everything';
  const n = Array.isArray(a.boxes) ? a.boxes.length : 0;
  if (n === 0) return 'Read only';
  return totalBoxes ? `Only some things · ${n} of ${totalBoxes}` : 'Only some things';
}
