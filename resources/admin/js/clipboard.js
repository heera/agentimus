/**
 * Copy `text` to the clipboard, resolving true on success and false otherwise.
 *
 * Prefers the async Clipboard API, which needs a secure context (HTTPS or
 * localhost); on plain HTTP it is absent or throws, so we fall back to a
 * throwaway off-screen <textarea> + execCommand('copy') — the one path that
 * still works there. Empty text resolves false without touching the clipboard.
 *
 * Callers own their own feedback (a "copied" flag, a flash toast); this reports
 * only whether the write landed.
 */
export async function copyText(text) {
  if (!text) return false;
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch (e) { /* fall through to the legacy path */ }
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch (e) {
    return false;
  }
}
