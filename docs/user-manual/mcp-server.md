---
title: MCP server
parent: User Manual
nav_order: 16
---

## What this is, in plain words

AI assistants — Claude on the web, in the desktop app or in the terminal, Cursor, ChatGPT, the Codex CLI and others — can work on your site for you. The **MCP server** is how they talk to it. MCP (Model Context Protocol) is simply the common language these tools speak.

Turn on **Settings → Discovery → MCP server**, and an AI tool you connect can *ask your site questions* and get live answers: "How AI-ready is this site?", "Which AI crawlers visited this week?", "Is this bot really Googlebot?", "How readable is this page for AI?". The same twenty read-only reports you see in the Agentimus admin — the ranked findings list, readiness, the pages worth fixing and what each is flagged for, your site's own categories and tags, your audience of people and machines, AI traffic, search performance, the request log, bot identification, internal-link suggestions, and the page/schema/Markdown previews — become questions an AI assistant can ask on your behalf.

And if you choose to — with a second switch that starts off — the same connection can *act* for you too: draft and edit posts, file them under categories and tags, set a featured image, and apply Readiness fixes. That's covered in [Letting agents write](#letting-agents-write-optional) below.

Everything needed ships with the plugin. There is nothing else to install, no account to create, and no third-party service in the middle — the AI tool talks directly to your own site.

## What it can and cannot do

**Can:** read the twenty Agentimus reports listed above. With the write switch on (below), also the six write tools — and nothing else.

**Cannot:** act anonymously — nothing about this is public. And until you flip the write switch, it cannot write, change, delete or publish anything: every tool it gets is read-only, and the write tools don't merely refuse — they don't exist.

A key can be read-only on its own, too. An assistant connected with a read-only key is shown the twenty read tools and nothing else; a read-and-write key is shown all twenty-six. The limit is checked on the server whatever the key claims, so hiding the write tools does not add safety — it saves the assistant from finding the wall by walking into it.

Three locks stand between the internet and those reports:

1. **Your switch.** Off by default. While it's off, the server doesn't exist — the address returns "not found" and the MCP machinery isn't even loaded.
2. **A key.** Every request must arrive with one, and there are three kinds: an assistant you **approved** (it gets its own key), the **shared connection token**, or an **application password**. No key, no answer. Setting each one up is covered under [Connecting an AI assistant](#connecting-an-ai-assistant).
3. **Permissions, per question.** Each tool checks the signed-in user's permissions exactly like the admin screen it comes from. A subscriber-level login can connect, but every report politely refuses it — only users who could see the screen can ask the question.

And one camera: every call an AI tool makes is recorded under **More → Agent Access**, with the user and the door it came through — *via Claude's connection* for an assistant you approved, *via connection token* for the shared token, or the application password's own name.

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
- **Everything is on the record.** Every write lands under **More → Agent Access**, attributed to the user and the door it came through.
- **Writes draft to the site's own quality bar.** The create/update tools show the agent the same readability rules the in-admin assistant writes to — paragraph length, plain wording, real inline sources — and every write's response includes the post's AI-readability grade (the pass/warn tally plus the rows needing attention), so the agent can fix its draft without a second call.

A practical tip that follows from all this: give the agent a key whose user matches the trust you're extending. An approved assistant and the shared connection token both act as the administrator who set them up, so they can do everything an administrator can — including applying Readiness fixes, which change plugin settings and therefore need an administrator. An application password can belong to any user, so it is the way to hand an assistant less: an editor-level user for content work.

## Connecting an AI assistant

Everything starts from one line on the settings card, under **Your server address**:

```
https://your-site.com/wp-json/agentimus/v1/mcp
```

The card shows yours with a **Copy** button, above a status line that says whether the server is answering right now, how many tools it offers, and when an assistant last called. The transport is **Streamable HTTP** — MCP's plain web-request flavour. Everything else is about how the assistant proves who it is.

There are three ways to do that. Try them in this order:

1. **Approval.** You give the assistant the address and nothing else. It asks *you* for permission on a page on your own site, and you decide what it may do. Best for anything that supports it.
2. **A shared connection token.** One secret you create and paste in. For assistants that cannot ask for approval — Codex is one of them today.
3. **An application password.** The original path, still fully supported. Best when you want one revocable key per tool, tied to a specific WordPress user.

Whichever you use, the assistant appears under **Connected assistants** on the card, and every call it makes shows in **More → Agent Access**.

### Approval — the assistant asks, you decide

1. Copy your server address and give it to the assistant. There is nothing to paste back.
2. The assistant reads your site, introduces itself, and sends your browser to `https://your-site.com/agentimus/connect` — a page on your own site. If you are not signed in to WordPress, it asks you to sign in first.
3. The page names what the assistant **calls itself** and the address it will **return to**. Both are worth a look. If this is not something you started, close the tab.
4. Choose **Read only** or **Read and write**, then press **Approve**. You may grant less than the assistant asked for. If **Let connected agents write** is off, only **Read only** is offered, and the page says so.
5. The assistant now appears under **Connected assistants**, with its own **Disconnect** button. Disconnecting one leaves the others working.

Only an administrator can approve a connection, and the connection acts as the account that approved it.

Underneath, this is OAuth 2.1 with PKCE and dynamic client registration — the standard assistants already speak. It all runs on your own site: nothing is brokered by us or anyone else. The keys an assistant receives last one hour and it renews them quietly in the background; a connection left unused for 30 days asks for your approval again.

Confirmed working: Claude Desktop, Claude on claude.ai, and Cursor.

### The shared connection token — for assistants that cannot ask

Some assistants have no way to ask for approval. Give those one secret instead. On the card, open the **Shared token** fold:

1. Choose **Read only** or **Read and write**. The second is offered only when **Let connected agents write** is on.
2. Press **Create connection token**.
3. Copy it now. It is shown **once** — Agentimus stores only a SHA-256 fingerprint of it, so it can never show it again.
4. In the assistant, send it as a header:

```
Authorization: Bearer agmcp_...
```

A site has one shared token at a time. **Rotate Token** gives you a fresh secret and cuts off every assistant holding the old one; **Revoke Token** cuts them all off with nothing to replace it. The token acts as the WordPress user who created it, and shows as a single row named **Shared token** under **Connected assistants**. If you lose it, rotate — there is nothing to recover.

### Application passwords — one key per tool

The per-tool path, unchanged, in its own **Application passwords** fold on the card. This is HTTP Basic: your WordPress username plus an **application password** (Users → Profile → Application Passwords), sent as `Authorization: Basic <encoded-login>`, where `<encoded-login>` is base64 of `username:app-password`. The card computes it for you; in a terminal, `printf '%s' 'USERNAME:APP-PASSWORD' | base64` does the same. (Paste the app password with or without its spaces — WordPress ignores them either way.)

The fold walks four steps: **Pick your AI tool**, **Give it a key** (press **Create key**, or paste one you saved), **Copy the setup**, and **Check it works**. That last button, **Test the connection**, makes the very calls your AI tool will make — sign-in, the MCP handshake, listing the tools — and shows a verdict for each, so "server problem" and "wrong key" stop looking identical.

Name each key after the tool it's for — "Claude Code", "Cursor" — one key per tool, so you can revoke one later without touching the others.

**Worth knowing:** an application password isn't scoped to this server — it signs in as that user across your site's whole REST API. That's how WordPress works, not something Agentimus can change. So: one password per tool, and for an extra margin, a dedicated user with only the permissions the tool needs. Approved connections and the shared token behave differently here: both are honoured **only** on the MCP address, and nowhere else in WordPress.

## Setting up your assistant

The card has step-by-step instructions for one assistant at a time, under **Setting up an assistant**. The same steps are below.

### Claude — web, desktop and mobile

Settings → Connectors → Add custom connector. Paste your server address. Claude opens the approval page on your site; approve, and Claude appears under **Connected assistants**.

Claude Desktop no longer needs the `mcp-remote` bridge. It speaks HTTP directly and asks for your approval itself.

### Claude Code

```
claude mcp add --transport http agentimus https://your-site.com/wp-json/agentimus/v1/mcp
```

Then start Claude Code. It sends you to the approval page on your site the first time it needs the server. Add `--scope user` to connect the server in every folder, not just the current one.

### Cursor

Settings → MCP → add a server with the address above and **no key**. Then ask Cursor something about your site: it shows an **Authenticate** button that opens the approval page. Approve, and Cursor is connected.

To wire it by hand instead, save this as `.cursor/mcp.json` in your project — or `~/.cursor/mcp.json` for all projects:

```json
{
  "mcpServers": {
    "agentimus": {
      "url": "https://your-site.com/wp-json/agentimus/v1/mcp"
    }
  }
}
```

Needs a recent Cursor — older versions ignore MCP install links and show nothing at all.

### ChatGPT and Codex

ChatGPT — on the web and in the desktop app — uses the approval flow, behind one switch (verified July 2026):

1. In ChatGPT: **Settings → Security → Developer mode**, on. This is what unlocks custom connectors; ChatGPT pauses its memory feature while it is on.
2. **Plugins → the "+" button**: paste your server address, keep Authentication on **OAuth**, tick the acknowledgement, and **Create**.
3. Press **Sign in with Agentimus** — ChatGPT sends you to the consent page on your own site. Approve, and it appears under Connected assistants.

Two things to know. ChatGPT insists on the exact discovery address the standard derives from your server URL, which this plugin serves — but if your site sits behind **Cloudflare**, the free plan's *Bot Fight Mode* blocks ChatGPT's calls with a 403 before they reach your site, and it has to be off for the connection (and every later tool call) to work. Anthropic's fetchers are on Cloudflare's verified-bots list; OpenAI's are not.

Codex cannot ask for approval. Give it the shared connection token instead:

Codex — the CLI, the IDE extension, and Codex inside the ChatGPT desktop app — shares one configuration file, so a single entry covers all three. Add this to `~/.codex/config.toml`, then restart it (inside a session, `/mcp` shows whether it connected):

```toml
[mcp_servers.agentimus]
url = "https://your-site.com/wp-json/agentimus/v1/mcp"
http_headers = { "Authorization" = "Bearer agmcp_..." }
```

### Anything else

Any MCP client that speaks Streamable HTTP connects with the address above. If it can ask for approval, it sends you to the consent page and needs nothing else. If it cannot, create a shared connection token and let it send `Authorization: Bearer agmcp_...`. The card also carries ready-made steps for **VS Code** (Command Palette → MCP: Add Server → HTTP → paste the address).

A tool that can only launch local (stdio) servers needs a bridge:

```
npx -y mcp-remote https://your-site.com/wp-json/agentimus/v1/mcp \
  --header "Authorization: Bearer agmcp_..."
```

Once connected, ask the tool something: *"What's my site's AI readiness score, and what should I fix first?"* Then open **More → Agent Access** and watch the calls appear, each naming the door it came through.

## Turning it off

Flip the switch off and save. The endpoint stops existing immediately — every connected AI tool is cut off mid-conversation, not politely phased out. Nothing lingers.

To cut off one assistant while leaving the server up for the others:

- **Disconnect** beside an approved assistant, under **Connected assistants**, ends that connection alone.
- **Revoke Token** ends every assistant holding the shared connection token, all at once. **Rotate Token** does the same and hands you a fresh secret.
- Revoking an application password (Users → Profile) ends that one tool.

## What about other plugins' MCP servers?

Some plugins (WooCommerce, for example) can run MCP servers of their own, with their own tools. Agentimus's switch controls **only Agentimus's server** — it neither enables nor prevents anyone else's:

- Our switch off + Woo's on → Woo's server runs, ours doesn't.
- Each server exposes only its own tools. An AI tool connected to ours cannot reach Woo's tools through us, and vice versa — and whichever door a request comes through, every tool still checks its own permissions.
- Your site's public `/.well-known/mcp.json` file honestly advertises every MCP server it can see running — Agentimus's and others' — with their tool names, so agents can discover what your site offers. Advertising is metadata only; calling anything still requires a key.
- The MCP library also offers a generic "run any registered ability" server. Agentimus keeps that **switched off** when it's the one loading the library — you opted into a named set of tools, not an everything-endpoint.

## If connecting fails

- **A browser window opens on your own site, asking you to approve?** That is the approval flow working, not an error. Check the name and the return address on the page, then approve.
- **The assistant says "auth unsupported", or offers no way to sign in?** It cannot do the approval flow. Create a shared connection token and give it the `Authorization: Bearer` header instead.
- **The approval page says the request is not valid?** The assistant sent something the server won't accept — an unknown client, a return address it never registered, or a request without PKCE. Ask the assistant to start the connection again; the codes it uses last only 60 seconds.
- **The approval page returns "not found"?** The MCP server switch is off — the page only exists while the server does. If the switch is on, re-save **Settings → Permalinks** once so WordPress picks up the address.
- **"Only an administrator can connect an assistant to this site"?** Approving needs an administrator account. Sign in as one and start the connection again.
- **Your address starts with `http://`?** Many AI clients refuse insecure connections to anything but your own machine. That is fine for local development; a live site needs HTTPS.
- **Application password: 401 even though the password is right?** Check the *username* first — it must be the WordPress login name of the user the password belongs to. WordPress silently ignores a sign-in for a username that doesn't exist, which looks identical to a server problem. The **Test the connection** button in the **Application passwords** fold runs the same calls your AI tool makes and tells you which step failed.
- **The switch does nothing?** The MCP server needs WordPress 6.9 or newer (it's built on the WordPress Abilities API). The settings card tells you if that's the issue.
- **Behind a very aggressive proxy or firewall**, the `Authorization` header can occasionally be stripped before reaching WordPress — which breaks all three sign-in methods, since each rides that header. Test with a wrong password for a *known-good* username: if you get an "incorrect password" error, the header arrives fine and the problem is elsewhere.
