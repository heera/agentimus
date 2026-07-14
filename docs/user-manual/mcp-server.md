---
title: MCP server
parent: User Manual
nav_order: 12
---

## What this is, in plain words

AI tools that live on your computer — Claude Code, Cursor, ChatGPT's connectors and others — can act as little assistants for your site. The **MCP server** is how they talk to it. MCP (Model Context Protocol) is simply the common language these tools speak.

Turn on **Settings → Discovery → MCP server**, and an AI tool you connect can *ask your site questions* and get live answers: "How AI-ready is this site?", "Which AI crawlers visited this week?", "Is this bot really Googlebot?", "How readable is this page for AI?". The same nine read-only reports you see in the Agentimus admin — readiness, AI traffic, the request log, bot identification, and the page/schema/Markdown previews — become questions an AI assistant can ask on your behalf.

Everything needed ships with the plugin. There is nothing else to install, no account to create, and no third-party service in the middle — the AI tool talks directly to your own site.

## What it can and cannot do

**Can:** read the nine Agentimus reports listed above. That's the whole list.

**Cannot:** write, change, delete or publish anything. It can't edit settings, touch posts, or act on your site in any way — every tool it gets is read-only. It also can't be used anonymously: nothing about this is public.

Three locks stand between the internet and those reports:

1. **Your switch.** Off by default. While it's off, the server doesn't exist — the address returns "not found" and the MCP machinery isn't even loaded.
2. **A login.** Every request must sign in as one of your WordPress users — usually with an *application password* (a per-app key you create under **Users → Profile**). No login, no answer.
3. **Permissions, per question.** Each tool checks the signed-in user's permissions exactly like the admin screen it comes from. A subscriber-level login can connect, but every report politely refuses it — only users who could see the screen can ask the question.

And one camera: every call an AI tool makes is recorded under **More → Agent access**, with the user and the named application password that made it.

## Connecting an AI tool

1. Turn on **Settings → Discovery → MCP server** and save. The card shows your site's MCP address (something like `https://your-site.com/wp-json/agentimus/v1/mcp`) with a copy button.
2. Create an **application password**: Users → Profile → Application Passwords. Name it after the tool — "claude-code", "cursor" — one password per tool, so you can revoke one later without touching the others.
3. Tell your AI tool about it. In Claude Code, for example:

   ```
   claude mcp add --transport http agentimus https://your-site.com/wp-json/agentimus/v1/mcp \
     --header "Authorization: Basic $(printf 'YOUR-USERNAME:APP-PASSWORD' | base64)"
   ```

4. Ask it something: *"What's my site's AI readiness score, and what should I fix first?"*

Then open **More → Agent access** and watch the calls appear, attributed to that password.

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

- **401 even though the password is right?** Check the *username* first — it must be the WordPress login name of the user the password belongs to. WordPress silently ignores a sign-in for a username that doesn't exist, which looks identical to a server problem.
- **The switch does nothing?** The MCP server needs WordPress 6.9 or newer (it's built on the WordPress Abilities API). The settings card tells you if that's the issue.
- **Behind a very aggressive proxy or firewall**, the `Authorization` header can occasionally be stripped before reaching WordPress. Test with a wrong password for a *known-good* username: if you get an "incorrect password" error, the header arrives fine and the problem is elsewhere.
