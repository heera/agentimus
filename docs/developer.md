---
title: Developer Reference
nav_order: 3
has_children: true
permalink: /developer.html
---

# Developer Reference

For plugin authors, theme developers, and integrators who want to extend Agentimus or make their own plugin discoverable to AI agents.

Agentimus is a small, framework-free plugin with a wide, well-documented hook surface. These pages cover:

1. **Architecture** — how the plugin is put together.
2. **WP_Discovery Protocol** — the open registration hook other plugins can implement.
3. **Hooks & filters** — the complete extension surface.
4. **REST & endpoints** — the admin API and the front-end machine files.
5. **Integrate your plugin** — make your content discoverable in a few lines.
6. **Topics & schema** — shape the structured data by code.
7. **Template tags** — read what the plugin knows from inside a theme.
8. **Building & contributing** — dev setup, tests, and releases.

## Which one do I need?

Four extension points, and picking the wrong one is the usual reason something feels harder than it should. Start from the goal:

| Your goal | What to use | Page |
| --- | --- | --- |
| Print a page's topics, summary or a video's note **in a theme template** | Template tags — `agentimus_get_topics()`, `agentimus_get_description()`, `agentimus_get_media_context()` | Template tags |
| Change **what a value is** before any surface prints it — schema, the plain-text twin and your theme alike | A filter, usually `agentimus_post_topics` or `agentimus_post_description` | Hooks & filters |
| Add fields, nodes or whole entities to the **JSON-LD graph** | The schema filters | Topics & schema |
| Advertise **your own plugin's API, feed or content** so agents can find it | The registration action, `wpdiscovery_register` | Integrate your plugin |
| Read the same data from **outside WordPress** — a script, a dashboard, an agent | The REST routes, or the bundled MCP server | REST & endpoints |

The distinction that catches people out is the first two rows. A template tag decides *where a value appears*; a filter decides *what the value is*. Filter it once and every reader — the schema, the Markdown edition, an assistant over MCP, and your own template — sees the same corrected text. Rewriting the string in a template fixes only the pixels.

{: .note }
> Every hook, endpoint, and file name here is drawn from the plugin source. When in doubt, read the code — the `examples/` folder ships runnable references (`all-hooks-reference.php`, `integrate-your-plugin.php`, `topic-links-wikidata.php`).

Use the sidebar to jump to any page.
