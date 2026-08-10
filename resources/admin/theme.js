/**
 * The admin theme: Light / Dark / System, remembered per browser (localStorage
 * — a viewing preference like a drawer's open state, not site data, so it
 * never touches Settings). The <html data-ar-theme="…"> attribute carries the
 * RESOLVED mode, always "light" or "dark"; "system" exists only as the stored
 * setting, resolved here and re-resolved live when the OS scheme flips.
 * wp-admin around the app keeps its own colors — app.css scopes every dark
 * rule to the app and its teleported surfaces.
 */

const KEY = 'agentimus-theme';
const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

/** The stored setting: 'light' | 'dark' | 'system' (default). */
export function themeSetting() {
  let v = null;
  try { v = window.localStorage.getItem(KEY); } catch (e) { /* private mode */ }
  return v === 'light' || v === 'dark' ? v : 'system';
}

function resolve(setting) {
  if (setting === 'light' || setting === 'dark') return setting;
  return media && media.matches ? 'dark' : 'light';
}

function apply() {
  document.documentElement.dataset.arTheme = resolve(themeSetting());
}

/** Store a setting ('system' clears the key) and repaint immediately. */
export function setTheme(setting) {
  try {
    if (setting === 'system') window.localStorage.removeItem(KEY);
    else window.localStorage.setItem(KEY, setting);
  } catch (e) { /* still applies for this page view */ }
  apply();
}

/** Resolve and stamp the attribute; call once before the app mounts, so the
 *  first paint is already in the right mode. Also re-applies on OS changes
 *  while the setting is "system". */
export function initTheme() {
  apply();
  if (media) {
    const rerun = () => { if (themeSetting() === 'system') apply(); };
    if (media.addEventListener) media.addEventListener('change', rerun);
    else if (media.addListener) media.addListener(rerun); // older Safari
  }
}
