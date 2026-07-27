---
title: MCP server
parent: User Manual
nav_order: 16
---

## What this is, in plain words

AI tools that live on your computer — Claude Desktop, the ChatGPT app (through its Codex side), Claude Code, the Codex CLI and others — can act as little assistants for your site. The **MCP server** is how they talk to it. MCP (Model Context Protocol) is simply the common language these tools speak.

Turn on **Settings → Discovery → MCP server**, and an AI tool you connect can *ask your site questions* and get live answers: "How AI-ready is this site?", "Which AI crawlers visited this week?", "Is this bot really Googlebot?", "How readable is this page for AI?". The same nine read-only reports you see in the Agentimus admin — readiness, AI traffic, the request log, bot identification, and the page/schema/Markdown previews — become questions an AI assistant can ask on your behalf.

And if you choose to — with a second switch that starts off — the same connection can *act* for you too: draft and edit posts, file them under categories and tags, set a featured image, and apply Readiness fixes. That's covered in [Letting agents write](#letting-agents-write-optional) below.

Everything needed ships with the plugin. There is nothing else to install, no account to create, and no third-party service in the middle — the AI tool talks directly to your own site.

## What it can and cannot do

**Can:** read the nine Agentimus reports listed above. With the write switch on (below), also the five write tools — and nothing else.

**Cannot:** act anonymously — nothing about this is public. And until you flip the write switch, it cannot write, change, delete or publish anything: every tool it gets is read-only, and the write tools don't merely refuse — they don't exist.

Three locks stand between the internet and those reports:

1. **Your switch.** Off by default. While it's off, the server doesn't exist — the address returns "not found" and the MCP machinery isn't even loaded.
2. **A login.** Every request must sign in as one of your WordPress users — usually with an *application password* (a per-app key you create under **Users → Profile**). No login, no answer.
3. **Permissions, per question.** Each tool checks the signed-in user's permissions exactly like the admin screen it comes from. A subscriber-level login can connect, but every report politely refuses it — only users who could see the screen can ask the question.

And one camera: every call an AI tool makes is recorded under **More → Agent Access**, with the user and the named application password that made it.

## Letting agents write (optional)

Below the server switch sits a second one: **Let connected agents write**. It starts off, and while it's off the write tools don't exist on any surface. Turn it on and five more tools appear:

- **Create a post or page** — a complete draft in one call: title, body, categories, tags, a featured image (from your media library, or imported from an image URL), plus its AI description and Topics for AI.
- **Update a post or page** — only the fields the agent passes change; everything else is left alone. Body edits keep a normal revision on posts and pages.
- **Set a page's AI description** and **Set a page's Topics for AI** — small, targeted follow-ups to the readability report, no need to resend the content.
- **Apply a readiness fix** — enacts a Readiness check's own remediation, from a fixed list of safe switches. It can only turn documented features *on*; it will never loosen a protection, make a hidden site public, or touch anything the fix text doesn't name. Fixes that need your words or your judgement come back honestly as "that's the owner's call".

The rules that travel with every write:

- **Drafts first.** A new post arrives as a draft (or pending). Publishing needs a *third* switch — **Let agents publish without your review** — and even then the signed-in user's own publish permission. Until you flip it, agents can prepare; only you can go live.
- **Edits to already-published posts go live on save** — same as you editing in wp-admin, and deliberately so: "fix the weak opening on my published article" is the whole point. The agent needs edit permission on that exact post, and body changes keep a revision to roll back to.
- **The agent is never more powerful than its user.** Filing under existing categories, inventing new ones, and importing images each follow the signed-in user's own WordPress permissions — an author-level key can't do what an author can't.
- **Everything is on the record.** Every write lands under **More → Agent Access**, attributed to the user and the named key that made it.
- **Writes draft to the site's own quality bar.** The create/update tools show the agent the same readability rules the in-admin assistant writes to — paragraph length, plain wording, real inline sources — and every write's response includes the post's AI-readability grade (the pass/warn tally plus the rows needing attention), so the agent can fix its draft without a second call.

A practical tip that follows from all this: mint the agent's key on a user whose role matches the trust you're extending — an editor-level user for content work, your admin user only if you also want it applying Readiness fixes (those change plugin settings, so they require an administrator).

## Connecting an AI tool

The short version: **the settings card does this for you.** Turn the server on, pick your tool on the card — Claude Desktop, ChatGPT / Codex, Claude Code — click **Create key**, and copy the finished setup it writes: your address, your username and the encoded login are all filled in. Then press **Test the connection**: the card makes the very calls your AI tool will make — sign-in, the MCP handshake, listing the tools — and shows a verdict for each, so "server problem" and "wrong key" stop looking identical. The card also keeps a status line: whether the server is answering right now, and when an AI tool last called (drawn from Agent Access).

Everything below is the same information for anyone who'd rather wire it by hand, or is reading this away from the admin.

Every tool needs the same three facts:

- **Address:** `https://your-site.com/wp-json/agentimus/v1/mcp` (the card shows yours, with a copy button)
- **Transport:** Streamable HTTP — MCP's plain web-request flavour
- **Login:** HTTP Basic — your WordPress username plus an **application password** (Users → Profile → Application Passwords), sent as a header: `Authorization: Basic <encoded-login>`, where `<encoded-login>` is base64 of `username:app-password`. The settings card computes it for you; in a terminal, `printf '%s' 'USERNAME:APP-PASSWORD' | base64` does the same. (Paste the app password with or without its spaces — WordPress ignores them either way.)

Name each key after the tool it's for — "Claude Code", "Cursor" — one key per tool, so you can revoke one later without touching the others.

### Claude Code

```
claude mcp add --transport http agentimus https://your-site.com/wp-json/agentimus/v1/mcp \
  --header "Authorization: Basic $(printf '%s' 'YOUR-USERNAME:APP-PASSWORD' | base64)"
```

Works as-is on macOS and Linux — the `$(printf …)` computes the encoded login inline. On Windows, let the settings card compute it and paste the finished command. Add `--scope user` to connect the server in every folder, not just the current one.

### Claude Desktop

Claude Desktop's connector screen only accepts OAuth sign-ins, so the login header rides a small bridge, `mcp-remote` (needs Node.js installed). Add this to `claude_desktop_config.json` — open it via Settings → Developer → Edit Config; the file lives at `~/Library/Application Support/Claude/` on macOS, `%APPDATA%\Claude\` on Windows — then restart the app:

```json
{
  "mcpServers": {
    "agentimus": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "https://your-site.com/wp-json/agentimus/v1/mcp",
        "--header", "Authorization:${AGENTIMUS_AUTH}"
      ],
      "env": { "AGENTIMUS_AUTH": "Basic <encoded-login>" }
    }
  }
}
```

The odd shape — no space after `Authorization:`, the value tucked into `env` — is deliberate: Claude Desktop on Windows mangles spaces inside `args`, and this form works on every platform. If the file already lists other servers, merge the `"agentimus"` block into the existing `"mcpServers"` object.

### Cursor

Save as `.cursor/mcp.json` in your project — or `~/.cursor/mcp.json` for all projects — then enable the server under Cursor Settings → MCP:

```json
{
  "mcpServers": {
    "agentimus": {
      "url": "https://your-site.com/wp-json/agentimus/v1/mcp",
      "headers": { "Authorization": "Basic <encoded-login>" }
    }
  }
}
```

### Codex — the CLI, the IDE extension, and the ChatGPT desktop app

These three share one Codex configuration, so a single file covers them all — including Codex inside the ChatGPT desktop app, which is where the former standalone Codex app now lives. Add this to `~/.codex/config.toml` (recent Codex versions speak HTTP natively — no bridge needed; inside a session, `/mcp` shows whether it connected):

```toml
[mcp_servers.agentimus]
url = "https://your-site.com/wp-json/agentimus/v1/mcp"
http_headers = { "Authorization" = "Basic <encoded-login>" }
```

### ChatGPT

ChatGPT's own connector screen — on the web and in the app — can't connect: it accepts only OAuth sign-ins (or none), with no way to send a login header, and a WordPress login isn't an OAuth flow. The way in is the app's **Codex** side, which connects fine — use the Codex setup above. (ChatGPT on the web has no equivalent; if that changes, this page will too.)

### Anything else

Any MCP client that speaks Streamable HTTP and can send a header connects with the three facts above. A tool that only launches local (stdio) servers can use the same bridge Claude Desktop does:

```
npx -y mcp-remote https://your-site.com/wp-json/agentimus/v1/mcp \
  --header "Authorization: Basic <encoded-login>"
```

Once connected, ask the tool something: *"What's my site's AI readiness score, and what should I fix first?"* Then open **More → Agent Access** and watch the calls appear, attributed to that key.

**Worth knowing:** an application password isn't scoped to this server — it signs in as that user across your site's whole REST API. That's how WordPress works, not something Agentimus can change. So: one password per tool, and for an extra margin, a dedicated user with only the permissions the tool needs.

## Turning it off

Flip the switch off and save. The endpoint stops existing immediately — a connected AI tool is cut off mid-conversation, not politely phased out. Nothing lingers. (Revoking the application password does the same for one tool while leaving the server up for others.)

## What about other plugins' MCP servers?

Some plugins (WooCommerce, for example) can run MCP servers of their own, with their own tools. Agentimus's switch controls **only Agentimus's server** — it neither enables nor prevents anyone else's:

- Our switch off + Woo's on → Woo's server runs, ours doesn't.
- Each server exposes only its own tools. An AI tool connected to ours cannot reach Woo's tools through us, and vice versa — and whichever door a request comes through, every tool still checks its own permissions.
- Your site's public `/.well-known/mcp.json` file honestly advertises every MCP server it can see running — Agentimus's and others' — with their tool names, so agents can discover what your site offers. Advertising is metadata only; calling anything still requires a login.
- The MCP library also offers a generic "run any registered ability" server. Agentimus keeps that **switched off** when it's the one loading the library — you opted into nine named read-only tools, not an everything-endpoint.

## If connecting fails

- **Start with the card's "Test the connection" button.** It runs the same calls your AI tool makes and tells you which step failed — the server not answering, the sign-in refused, or the handshake itself — which is most of the diagnosis done in one click.
- **401 even though the password is right?** Check the *username* first — it must be the WordPress login name of the user the password belongs to. WordPress silently ignores a sign-in for a username that doesn't exist, which looks identical to a server problem.
- **A browser window pops open asking you to sign in** (Claude Desktop / the `mcp-remote` bridge)? That's the bridge falling back to an OAuth attempt after your site said 401 — which means the username or password in the header is wrong, not that you need to sign in there. Fix the header and the popup stops.
- **The switch does nothing?** The MCP server needs WordPress 6.9 or newer (it's built on the WordPress Abilities API). The settings card tells you if that's the issue.
- **Behind a very aggressive proxy or firewall**, the `Authorization` header can occasionally be stripped before reaching WordPress. Test with a wrong password for a *known-good* username: if you get an "incorrect password" error, the header arrives fine and the problem is elsewhere.
