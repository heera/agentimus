---
title: Building & contributing
parent: Developer Reference
nav_order: 7
---

Agentimus ships as a plain WordPress plugin with a small Vue 3 admin app compiled on top.
There is no framework, no build step for the PHP, and no runtime bundler — the only compiled
artifact is the admin UI. This page covers everything you need to build the plugin from a
clean checkout, run the test suite, and open a pull request.

## Requirements

Two sets of requirements matter: what the plugin needs to **run**, and what a contributor
needs to **build and test** it.

### Runtime (what the plugin needs to run)

| Requirement | Version | Source of truth |
| --- | --- | --- |
| WordPress | 6.0 or newer (tested up to 7.0) | `Requires at least: 6.0` in `agentimus.php` and `readme.txt` |
| PHP | 7.4 or newer | `Requires PHP: 7.4` in `agentimus.php`; `"php": ">=7.4"` in `composer.json` |

The plugin has **no PHP runtime dependencies** — `composer.json` declares only a dev
requirement (PHPUnit). Everything under `inc/` is vanilla PHP, PSR-4 autoloaded by a
hand-rolled loader in `agentimus.php`, so no Composer `install` is needed to run the plugin.

### Build & test toolchain (what a contributor needs)

| Tool | Used for | Notes |
| --- | --- | --- |
| Node.js + npm | Building the Vue admin app | Vite 5 is the build tool, so Node 18+ is the practical floor |
| Composer | Installing the test dependency (PHPUnit) | Only needed to run the test suite, not to run the plugin |
| PHP 7.4+ CLI | Running PHPUnit locally | The suite runs against PHP 7.4 and 8.2 in CI |

## Repository layout

A clean checkout looks like this (build output and dependency folders are created on demand
and are git-ignored):

```text
agentimus/
├── agentimus.php          # Main plugin file: header, constants, PSR-4 autoloader, boot
├── uninstall.php          # Uninstall cleanup
├── inc/                   # PHP source — namespace Agentimus\, PSR-4 (ships)
│   ├── Plugin.php         #   orchestrator
│   ├── Settings.php       #   single option store
│   ├── Endpoints.php  Markdown.php  Schema.php  Readiness.php  Rest.php  Admin.php  …
│   ├── Discovery/         #   Registry, Resource, Envelope (the discovery contract)
│   ├── Activity/  Visibility/  …
├── resources/             # Vue 3 admin source — the input to Vite (ships)
│   └── admin/
│       ├── main.js        #   Vite entry point
│       ├── App.vue
│       ├── api.js  app.css  livecheck.js  tiers.js  confirm.js
│       └── components/    #   SettingsForm.vue, DiscoveryHub.vue, ReadinessPanel.vue, …
├── assets/
│   ├── admin/             # BUILD OUTPUT from Vite — git-ignored, ships in the .zip
│   └── webmcp.js          # committed static asset
├── vite.config.js         # Vite build config (ships)
├── package.json           # npm scripts + Vue/Vite deps (ships)
├── tests/                 # PHPUnit suite + bootstrap.php (dev-only)
├── phpunit.xml.dist       # PHPUnit config (dev-only)
├── composer.json          # Dev dependency (PHPUnit) + `test` script (dev-only)
├── examples/              # Copy-paste hook/integration reference files
├── readme.txt             # WordPress.org readme (ships)
├── README.md              # GitHub readme (dev-only)
├── RELEASING.md           # Release runbook (dev-only)
├── LICENSE                # GPL-2.0-or-later
├── .distignore            # What is excluded from the distributed .zip / SVN
└── .github/workflows/     # CI: ci.yml, php-compat.yml, deploy.yml, wordpress-org-assets.yml
```

### What ships vs. what stays in the repo

The distributed plugin (the WordPress.org `.zip` / SVN trunk) is **not** the same set of files
as the Git repository. `.distignore` is the authoritative manifest. In short:

- **Ships in the `.zip`:** `agentimus.php`, `uninstall.php`, `readme.txt`, `LICENSE`, `inc/`,
  the built `assets/`, **and** the Vue source `resources/` plus `vite.config.js` and
  `package.json`. The source and build config ship on purpose — see the
  [no minified-only code policy](#the-no-minified-only-code-policy).
- **Excluded from the `.zip`** (via `.distignore`): `.git`, `.github`, `.wordpress-org`,
  `node_modules`, `vendor`, `tests`, `phpunit.xml.dist`, `composer.json`, `composer.lock`,
  `package-lock.json`, `README.md`, and `RELEASING.md`.

## Building the Vue admin

The admin UI is a Vue 3 app (Options API) built with Vite. The PHP side enqueues the compiled
bundle; it does not read a manifest, so the output filenames are pinned.

```bash
npm install        # install Vue + Vite (into node_modules/, git-ignored)
npm run build      # one-off production build into assets/admin/
npm run dev        # rebuild on every change (vite build --watch)
```

Both scripts are defined in `package.json`:

```json
"scripts": {
  "dev": "vite build --watch",
  "build": "vite build"
}
```

### What the build produces

`vite.config.js` is configured so PHP can enqueue the result without any manifest lookup:

- **Output directory:** `assets/admin/` (`emptyOutDir: true`, so it is wiped and rebuilt).
- **Entry point:** `resources/admin/main.js`.
- **Stable filenames:** the JS is always `app.js` and the CSS is always `app.css`
  (`cssCodeSplit: false`, `manifest: false`). Other emitted assets land under
  `assets/admin/assets/[name][extname]`.
- **IIFE format:** the bundle is emitted as an IIFE (`format: 'iife'`). This is deliberate —
  the admin bundle is enqueued as a *classic* script, so it must not leak top-level bindings
  into the global scope. A minifier-chosen name like `wp` or `lodash` at the top level would
  collide with WordPress's own globals (`Identifier 'wp' has already been declared`). Wrapping
  everything in a function scope avoids that.

### `assets/admin/` is git-ignored build output

`assets/admin/` is listed in `.gitignore` and must **never** be committed:

```gitignore
# Build output (regenerated by `npm run build`)
assets/admin/
```

It is a build artifact. It is regenerated locally by `npm run build`, and produced fresh by
the release pipeline for the distributed `.zip`. If you clone the repo and do not run the
build, the admin screens will not have their JS/CSS — run `npm install && npm run build`
first. (The committed `assets/webmcp.js` is a separate, hand-maintained static asset and is
not part of the Vite build.)

## Running the PHPUnit test suite

The tests are **pure-logic unit tests** — they exercise the discovery contract (Resource
validation, the Registry collector, Envelope derivation), Markdown/Schema output, the Guard,
Topics, Readiness and more, **without a full WordPress install**. `tests/bootstrap.php`
defines the minimal WordPress function/constant surface the tested classes touch and autoloads
the `Agentimus\` classes straight from `inc/`.

```bash
composer install   # installs PHPUnit ^9 into vendor/ (git-ignored)
composer test      # runs phpunit against phpunit.xml.dist
```

`composer test` is just an alias for `phpunit` (defined in `composer.json`), so you can also
invoke the binary directly:

```bash
./vendor/bin/phpunit
```

### How the suite is wired

- **Config:** `phpunit.xml.dist` — bootstrap is `tests/bootstrap.php`, and the single test
  suite `agentimus-unit` runs every test in the `tests/` directory (34 test files at the time
  of writing, e.g. `RegistryTest.php`, `MarkdownTest.php`, `GuardTest.php`, `TopicsTest.php`).
- **No WordPress required:** `bootstrap.php` stubs functions like `apply_filters`,
  `get_option`, `get_post`, `wp_get_post_terms`, and friends with small stateful
  implementations, and defines constants such as `AGENTIMUS_DIR` and `AGENTIMUS_VERSION`. This
  means the suite runs on a bare PHP 7.4+ CLI with only Composer's dev dependency installed —
  no database, no `wp-load.php`.
- **Determinism helpers:** helpers such as `_af_reset_options()` and `_af_reset_registry()`
  reset the global stubs and the `Registry` singleton between tests.

### What CI runs

`.github/workflows/ci.yml` runs the suite on a matrix of **PHP 7.4 and 8.2** via
`composer install` → `composer test`. Separately, `.github/workflows/php-compat.yml` guards
the declared PHP floor two ways:

1. `php -l` across PHP **7.4 → 8.3** to catch syntax a version cannot parse (`match`, enums,
   nullsafe, constructor promotion, named args, …).
2. **PHPCompatibilityWP** against 7.4 to catch *API* usage newer than the floor (e.g.
   `str_contains`) while knowing what WordPress core polyfills.

Run the tests locally before pushing; a red CI check will block a release.

## The "no minified-only code" policy

Agentimus deliberately ships the human-readable Vue source alongside the built bundle so the
plugin is fully auditable and the build is reproducible. From the README's License section:

> The admin app is built from Vue source in `resources/` with Vite — no minified-only code
> ships, so the build is reproducible.

Concretely:

- `resources/` (the Vue source), `vite.config.js`, and `package.json` are **included** in the
  distributed `.zip` (they are intentionally absent from `.distignore`).
- The compiled `assets/admin/app.js` is minified by Vite, but it is never the *only* copy of
  that code — anyone can re-run `npm install && npm run build` from the shipped source and
  reproduce it.

Practical consequence for contributors: do not introduce vendored, pre-minified, or
obfuscated blobs as the sole form of any code. If it runs, its readable source must ship too.

## PHP conventions

- **Namespace & autoloading:** all PHP lives under the `Agentimus\` namespace and is PSR-4
  autoloaded by a minimal loader defined in `agentimus.php` — `Agentimus\Foo\Bar` maps to
  `inc/Foo/Bar.php`. There is no Composer autoloader in production and no framework; a full
  framework would undercut the point of a lightweight machine-readability plugin.
- **Coding target:** write for PHP 7.4. The `php-compat` workflow will fail the build if you
  use syntax or APIs newer than 7.4 that WordPress does not polyfill.
- **Extension seams:** new behaviour that a companion or Pro add-on might need should hook the
  documented actions and filters rather than editing core paths. See the Hooks & filters
  reference page, and the copy-paste examples in `examples/` (e.g.
  `examples/all-hooks-reference.php`, `examples/integrate-your-plugin.php`).

## A typical contribution loop

1. Branch off `main`.
2. Make your change under `inc/` (PHP) and/or `resources/` (Vue).
3. If you touched the admin UI, run `npm run build` (or keep `npm run dev` running) so the
   change is reflected in `assets/admin/` locally — but **do not commit** `assets/admin/`.
4. Run `composer test` and make sure it prints `OK`.
5. Push and open a pull request. CI (`ci.yml` + `php-compat.yml`) runs the PHPUnit matrix and
   the PHP compatibility checks; both must be green.

## Releasing

Publishing a new version to WordPress.org is a separate, tag-driven process — see the
**RELEASING.md** runbook in the repo root for the full step-by-step. The essentials:

- Bump the version in **three** places and keep them identical: the `Version:` header and the
  `AGENTIMUS_VERSION` constant (both in `agentimus.php`), and `Stable tag:` in `readme.txt`.
- Add a changelog and upgrade-notice entry to `readme.txt`.
- Commit and push to `main`, then push a tag whose name is **numbers only** (`1.5.1`, never
  `v1.5.1`). Pushing the tag triggers `.github/workflows/deploy.yml`, which builds the plugin
  and deploys code, readme, and screenshots to WordPress.org.
- Never commit `assets/admin/` — the release pipeline builds it.

## License

Agentimus is licensed under **GPL-2.0-or-later** (see the `LICENSE` file and the
`License: GPL-2.0-or-later` header in `agentimus.php`). Because the Vue source ships alongside
the compiled admin bundle, the entire distributed plugin is auditable and reproducible from
source.
