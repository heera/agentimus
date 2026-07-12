// Document-level Escape for dialogs. A panel-scoped @keydown.esc only hears the
// key while focus is INSIDE the panel — one backdrop click parks focus on <body>
// and Esc goes silent (found on Agent preview, 2026-07-13). So every dialog also
// binds Escape at the document for exactly as long as it is open. The panel
// handler stays for the common case; this is the safety net.
//
// Usage: this._unEsc = bindDocEsc(() => this.close())  … later  this._unEsc().
export function bindDocEsc(handler) {
  const fn = (e) => {
    if ('Escape' === e.key) handler();
  };
  document.addEventListener('keydown', fn);
  return () => document.removeEventListener('keydown', fn);
}
