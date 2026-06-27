# How to release a new version of Agentimus

This is the step-by-step guide for putting a new version of the plugin live on
WordPress.org. Follow it top to bottom. You don't need to understand how it all
works — just do the steps in order. 🙂

> **The lazy way:** if you're using Claude Code, just say
> **"release 1.5.1"** (use your real version number) and it will do every step
> below for you and tell you when it's live. The rest of this file is for doing
> it by hand.

---

## Before you start

Make sure of three things:

1. All your changes are finished and saved.
2. You're on the `main` branch.
3. The tests pass. Run this in the plugin folder:
   ```
   composer test
   ```
   You want to see **OK** at the end. If a test fails, fix it before releasing.

---

## Step 1 — Pick the new version number

Versions look like `1.5.0` — three numbers with dots. Look at the current one
(in `agentimus.php`, the `Version:` line) and bump it:

| What changed | Bump which number | Example |
|---|---|---|
| Tiny fix or small tweak | last number | `1.5.0` → `1.5.1` |
| A new feature | middle number | `1.5.0` → `1.6.0` |
| Huge rewrite / big breaking change | first number | `1.5.0` → `2.0.0` |

Most of the time you'll just bump the last number. In this guide we'll pretend
the new version is **`1.5.1`** — replace that with your real number everywhere.

---

## Step 2 — Write the new number in 3 places

The number has to match in all three, or WordPress.org gets confused.

1. **`agentimus.php`** — near the top, the line that says:
   ```
    * Version:           1.5.0
   ```
   Change `1.5.0` to `1.5.1`.

2. **`agentimus.php`** — a bit lower, the line:
   ```
   define( 'AGENTIMUS_VERSION', '1.5.0' );
   ```
   Change `1.5.0` to `1.5.1`.

3. **`readme.txt`** — near the top:
   ```
   Stable tag: 1.5.0
   ```
   Change `1.5.0` to `1.5.1`.

---

## Step 3 — Say what changed (in `readme.txt`)

Open `readme.txt` and find the line `== Changelog ==`. Right under it, add a new
block for your version (newest goes on top):

```
= 1.5.1 =
* A short, plain sentence about what you fixed or added.
* Another line if there's more than one thing.
```

Then find `== Upgrade Notice ==` and add a matching short block:

```
= 1.5.1 =
One sentence telling people why they'd want this update.
```

Keep it simple and honest — real people read this.

---

## Step 4 — New screenshots? (skip if the admin screens look the same)

**Only** do this if you changed how the plugin's admin pages *look*. If you
didn't, skip to Step 5 — the old screenshots are fine.

If the look changed, the picture files live in the `.wordpress-org/` folder
(`screenshot-1.png`, `screenshot-2.png`, …). Replace the ones that changed with
new pictures of the same size. (If you're using Claude Code, just ask it to
"sync the screenshots" — it knows how.)

---

## Step 5 — Save everything to GitHub

In the plugin folder, run:

```
git commit -am "release: 1.5.1"
git push origin main
```

This uploads your changes. Two automatic checks will run on GitHub and should
both turn **green** (a green check ✓). Nothing is published to WordPress.org yet
— that's the next step.

---

## Step 6 — The magic step: tag it 🚀

This is the step that actually publishes to WordPress.org. Run:

```
git tag 1.5.1
git push origin 1.5.1
```

⚠️ **The tag must be just the numbers** — `1.5.1`, **never** `v1.5.1`. The
letter `v` breaks it (nothing will publish and you won't get an error).

That's it! GitHub now builds the plugin and uploads everything (code, readme,
and screenshots) to WordPress.org by itself. It takes about a minute.

---

## Step 7 — Check it worked

Wait a minute or two, then paste this in your terminal (change `1.5.1`):

```
curl -s https://plugins.svn.wordpress.org/agentimus/trunk/readme.txt | grep "Stable tag:"
```

If it prints `Stable tag: 1.5.1`, **you're done — it's live!** 🎉

(The public plugin page at wordpress.org/plugins/agentimus can take a few hours
to catch up because of caching — that's normal. The line above is the real,
instant proof.)

---

## ✅ The golden rules (don't skip these)

- The version number is the **same** in all 3 places **and** the tag **and**
  `Stable tag`.
- The tag is **numbers only** — `1.5.1`, not `v1.5.1`.
- **Don't** commit the `assets/admin/` folder — it's built automatically.
- The wp.org page lags a few hours. Don't re-publish to "force" it; just wait.

---

## 😱 "I see a red X / something looks broken"

- A red **"Update WordPress.org assets"** run used to show up on every release.
  We turned that off, so you shouldn't see it anymore. If it ever appears, it's
  harmless — it never breaks a release.
- If the **tag deploy** itself fails (the one called *"Deploy to WordPress.org"*),
  the most common cause is the version number not matching in all the places from
  Step 2, or using a `v` in the tag. Fix the number, delete the bad tag, and tag
  again:
  ```
  git tag -d 1.5.1            # delete the local tag
  git push origin :1.5.1      # delete it on GitHub
  # fix the version, commit, push, then redo Step 6
  ```

---

## Special case: changing only a picture or readme (no new version)

If you *only* want to swap the banner/icon or fix a typo in `readme.txt` —
no code change, no new version — do this instead of a full release:

1. Change the file(s) in `.wordpress-org/` (or `readme.txt`) and push to `main`.
2. Then run this one command to publish just those:
   ```
   gh workflow run wordpress-org-assets.yml
   ```

That's the only time you run that command by hand.
