---
title: Findings
parent: User Manual
nav_order: 3.5
---

**Findings** is one front door for everything open across your site. The score could read 99 and "Excellent" while five separate screens quietly held work you had never seen; this tab gathers all of it into a single ranked list, so the first row is always the one worth doing.

## What lands here

Two kinds of things, merged into one list:

- **Pages losing a click they already earn** — read from the search engines' own numbers: pages one push from page one, pages on page one being scrolled past, and several pages splitting one search between them.
- **Anything the setup checks caught** — whatever Readiness would flag, stated as the work it actually is.

Every row says what to do in plain words and carries the button that lands where the fix actually lives: content findings hand their exact pages to the worklist below, and search findings open the matching card on **Visibility → Search**.

## How it is ranked

By what each finding costs you — visitors lost, AI assistants turned away, or a page underselling itself — never by which part of the plugin noticed it. Findings move through three stages on the one screen: what needs you, what is finished on your side and waiting on a search engine, and — briefly, at the top — what has actually improved.

## The count in the nav

The Findings tab carries its own count, and that count only ever measures work you can do something about. Anything merely waiting on a later report gets a quiet dot instead of a number — a number in a nav is a promise that doing the work makes it go down, and waiting is not workable. Clients waiting on an allow-or-block verdict are not findings; they stay with the bell.

## Your content, one row at a time

Beneath the ranked list sits the **content worklist**: one row per post, page or anything else you publish, saying what that piece is actually found for, whether it answers that, and anything else it needs. Anything not meant to be cited can be **set aside** — parked on its own ledger, listed and counted rather than merely absent, so "not meant to be cited" stays a decision you can review instead of a hole in a list.

Opening it from an issue on [Readiness](readiness.html) narrows the list to that one check — the heading says which check, how many pages carry it and how to get back to the whole list — and hands you every page it flags, ranked and paged, rather than the handful the card can show. Fix what the list was opened for and the row keeps its place, marked as done: a row that vanished the moment you fixed it would be a fix you never saw land. The counts above the list always describe your whole site, not the rows currently on screen.

### Which content is checked

From 1.37.0 the worklist covers **every kind of content you publish**, not only posts and pages. Before that it read posts and pages whatever your site was made of, so a shop's products were never looked at.

A line above the list names the kinds being checked, and the gear beside **Check again** lets you change them. Everything eligible is checked by default. Two things start switched off, and each says why on its own card:

- **A content type with a very large number of published items.** No update should begin reading thousands of pages without you asking for it.
- **A type its own plugin keeps out of your site's search.** That is usually a template library rather than content.

Products are checked for the searches they are found for, but they are never graded as writing. A product page is short on purpose, so "this needs more substance" would be the wrong advice on one. This is why the Optimized rung on [Readiness](readiness.html) can name fewer kinds of content than the worklist does — checking and grading are not the same question.

Connected agents can read the same ranked list over MCP with the `read-findings` tool — the same rows, the same order, bounded at twelve.
