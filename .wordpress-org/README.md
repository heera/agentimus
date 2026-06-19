# WordPress.org listing assets

The wp.org plugin-directory assets for **Agentimus** — the icon, banner and
screenshots shown on the listing page. They live here (and are excluded from the
distributed plugin via `.distignore`) because they are *directory* assets, not
plugin code: at publish time they go into the SVN `assets/` directory, never into
`trunk/`. The official deploy actions (e.g. `10up/action-wordpress-plugin-asset-update`)
read this `.wordpress-org/` directory.

## Files
- `icon-128x128.png`, `icon-256x256.png`, `icon-512x512.png` — the "A" mark.
- `banner-772x250.png`, `banner-1544x500.png` — listing banner (1x + retina).
- `screenshot-1.png` … `screenshot-4.png` — admin screens, in `readme.txt` order:
  1. Dashboard  2. Settings  3. Readiness  4. Discovery

## How they were generated (`sources/`)
- **Banner** — `sources/banner.html` rendered with headless Chrome at 1544×500,
  then downscaled to 772×250. Uses an inline vector of the "A" tile.
- **Screenshots** — the *real* built admin app (`assets/admin/app.js` + `app.css`)
  rendered through `sources/harness.html`, which:
  - injects `window.AgentimusData` from a wp-cli reflection dump of
    `Admin::bootstrap_data()` (`sources/dump_bootstrap.php`; the site host is
    rewritten to `heera.it`),
  - stubs the `/activity` REST call with sample data (`sources/gen_activity.py`),
  - is loaded once per tab via the URL hash, captured with headless Chrome, then
    trimmed of trailing background (`sources/trim.py`).
  - `sources/enrich_identity.php` / `restore_identity.php` temporarily fill the
    local identity (bio / expertise) for the capture, then revert it.

To regenerate after a UI change: `npm run build`, then re-run the steps above.
(Paths inside the source files are local to the author's machine — adjust them
before re-running.) Alternatively, replace the PNGs with real captures from a
live admin — just keep the file names.
