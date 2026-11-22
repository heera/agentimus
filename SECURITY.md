# Security Policy

Agentimus publishes a small, deliberately public "discovery layer" for AI agents
(llms.txt, `/.well-known/*`, an agent card, an MCP surface) and records bounded,
privacy-preserving traffic stats for the site owner. This document explains what
that surface intentionally exposes, how to report a problem, and the assessment
the plugin has been through.

## Reporting a vulnerability

Please report privately — do **not** open a public issue for a security bug.

- Preferred: a [GitHub private security advisory](https://github.com/heera/agentimus/security/advisories/new).
- Or email **mail@heera.it** with "Agentimus security" in the subject.

Include the version, a description, and ideally a proof-of-concept request. You'll
get an acknowledgement, a fix timeline once triaged, and credit in the changelog if
you'd like it. Please give a reasonable window to ship a fix before any public
disclosure. Only the latest released version is supported with security fixes.

## What Agentimus exposes by design

These are **intended** to be public and are not vulnerabilities in themselves:

- **Discovery documents** — `/llms.txt`, `/llms-full.txt`, `/.well-known/discovery.json`,
  `agent-card.json`, `mcp.json`, the MCP server cards, `api-catalog`, `openapi.json`,
  and the agent-skills index. These describe the site's *public* identity, public
  content, and machine endpoints, plus `GET /wp-json/agentimus/v1/discovery`.
- **Markdown of public pages** — appending `.md` to a URL (or `Accept: text/markdown`)
  returns the page as markdown. This is gated to the same content WordPress already
  serves publicly (see below).
- **Aggregate traffic stats** — counts of agent/crawler hits, shown only in wp-admin.

What is **never** exposed: draft / pending / future / private / trashed posts,
password-protected post bodies, author logins or emails, file-system paths, API
keys or secrets, nonces, or any per-visitor identifier.

## Security posture

Design choices that keep the public surface safe:

- **No PII in analytics.** The activity log stores the endpoint, a friendly agent
  label, and a truncated User-Agent — **no IP address** and no per-visitor identity.
  "Traffic from AI" is aggregate-only (per day / source / path counts).
- **Content access control.** Markdown and the discovery enumerations only ever
  surface `publish`-status, non-password-protected, public-post-type content; the
  `.md` handler returns `# Not found` for anything else.
- **Agent writes can't add scripts.** Content tools refuse scripts, event handlers,
  `javascript:` links, iframes, style elements and SVG, even under an administrator's
  login, where WordPress itself would filter nothing. Markup the owner already stored
  stays. This covers the MCP tools only: an application password can still use the
  REST API, which follows the user's own permissions.
- **Limits on every agent call, whatever its login.** During a request on the MCP
  address (`/agentimus/v1/mcp`), even an administrator's agent can't edit plugin or
  theme files, run WordPress updates, upload any file type, import or export, use
  menus or the site editor, or use multisite network powers. It can't create, edit,
  promote or delete any user, or create or read anyone's application passwords. It
  can't change or delete the site address, the secret keys, the admin email, the
  sign-up settings, the roles list, Agentimus's agent-access switches or its sign-in
  data. It can install plugins and themes from WordPress.org only, turn plugins on and
  off, switch themes, and update and delete plugins and themes, but never turn off,
  uninstall or delete Agentimus. Tools other plugins add to the Agentimus server are
  held to the same limits. Like the script rule, this covers the MCP address only:
  an application password used elsewhere follows its user's own permissions.
- **Parameterized storage.** All database writes/reads use `$wpdb->prepare()` or
  `$wpdb->insert()` format arrays — no string-interpolated SQL.
- **Escaped output.** Headers use registered relations and `esc_url_raw()`; the Vue
  admin renders all logged strings through auto-escaping (no `v-html`); content types
  are fixed, not request-derived.
- **Bounded generation.** `/llms-full.txt` is built under a wall-clock deadline, a
  byte budget, and a per-item cap, then cached — an unauthenticated request can't
  trigger unbounded work.
- **Admin actions are gated.** Every settings/activity/block REST route requires
  `manage_options` and the standard REST nonce.
- **Optional request Guard.** Owners may opt in to blocking denylisted or spoofed
  agents at the generated endpoints (off by default; ACME/Let's-Encrypt safe).
- **Flood-resistant logging.** Recognised crawlers are always logged; unidentified
  or spoofed clients are rate-sampled under a burst so a flood of disposable
  user-agents can't drown real traffic out of the bounded log (see below).

## Assessment summary

An authorized black-box + source review (2026-06-30) tested the unauthenticated
public surface for: authentication bypass on admin routes, non-public content
leakage via the `.md` handler and discovery documents, path traversal / LFI, SSRF,
RCE / command execution, SQL injection, stored & reflected XSS, header (CRLF)
injection, user enumeration, and resource-exhaustion DoS.

**No exploitable vulnerability was found.** Admin routes reject unauthenticated
requests; draft/private/password-protected content does not leak; traversal and
injection attempts failed; `/llms-full.txt` generation is bounded and cached.

The one observation was **first-party analytics integrity**: because logging happens
on public hits, a visitor could pollute the (bounded, PII-free) activity log or
inflate "Traffic from AI" counts. This is now mitigated by the flood-sampling
described above. It is bounded by a hard row cap and a daily prune regardless.

## Notes for site owners (hardening)

- **WordPress user enumeration** via `/wp-json/wp/v2/users` and `?author=N` is core
  WordPress behaviour, **not** Agentimus. Close it with a security/SEO plugin if you
  don't want author slugs public.
- To stop logging entirely, turn off activity tracking in **Agentimus → Settings**.
- To actively reject scanners/spoofed agents (not just sample their logging), enable
  the request Guard under **Settings → Block scanners & scrapers**.
