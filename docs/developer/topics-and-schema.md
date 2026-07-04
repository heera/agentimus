---
title: Customizing topics & schema
parent: Developer Reference
nav_order: 6
---

Agentimus emits two machine-readable descriptions of each page: the JSON-LD `<script type="application/ld+json">` graph in `wp_head`, and the per-page Markdown surfaces. Both are driven by a small set of filters, so you can shape exactly what an AI assistant sees without touching the plugin. This page documents every topic and schema hook, their signatures, and how per-page topics turn into `keywords` and `about` DefinedTerms.

Everything here is drawn from `inc/Topics.php`, `inc/Schema.php`, and `examples/topic-links-wikidata.php`. Nothing described is a front-end network call: Agentimus never looks anything up at request time, so your filters are the only source of external knowledge (like Wikidata links).

## How the pieces fit together

Two concerns, one data flow:

- **Topics** — a per-page list of what the page is *about*. It is the single source of truth in `Agentimus\Topics::for_post( $post )`, which both the Schema class and the Markdown class call. Because both surfaces read the same resolver, they can never disagree about a page.
- **Schema** — the JSON-LD `@graph`. The per-post node reads the resolved topics and turns them into `keywords` (flat strings) plus `about` DefinedTerm entities.

The topic list for a page is assembled in this order:

1. **Manual topics** — what the editor typed in the "Topics for AI" meta box (post meta `_agentimus_topics`). Manual entries come first, so they win the dedupe.
2. **Derived topics** — the page's own taxonomy terms (categories then tags by default), added only when the page's derive toggle is on (`_agentimus_topics_derive`, defaulting to the site setting `topics_derive_default`).
3. **Normalize** — the merged list is HTML-stripped, trimmed, blanks dropped, each item clipped to `Topics::MAX_LEN` (60 chars), deduped case-insensitively (first casing wins), and capped to `Topics::cap()` (the `topics_max` setting, default 12, clamped to 1–50).
4. **`agentimus_post_topics` filter** — your last word on the list.
5. **Re-normalize** — the filtered list is normalized *again*, so a filter can never overflow the cap, inject blanks, or slip markup into a machine surface.

Two rules matter for accuracy when writing filters:

- The **exclude list** (`agentimus_topic_exclude`) and the **numeric-junk rule** (`agentimus_topic_meaningful`) apply only to the *auto-derived* taxonomy path (and the site-level llms.txt Topics list). A manually typed topic is explicit intent and is never dropped by either.
- The whole topics feature is gated by the `enable_topics` setting; `Topics::for_post()` returns `[]` when it is off.

## Topic hooks

### agentimus_post_topics

The final say over a page's topic list. Fires inside `Topics::for_post()` after the manual and derived lists are merged and normalized; whatever you return is re-normalized. Use this when your topics are **not** taxonomy terms — for example, values computed from post meta, an ACF field, or a WooCommerce attribute your add-on already knows.

```php
/**
 * @param string[]  $topics Resolved topics (manual + derived), already normalized.
 * @param \WP_Post   $post   The post being described.
 * @return string[]
 */
add_filter( 'agentimus_post_topics', function ( $topics, $post ) {
	if ( 'product' === $post->post_type ) {
		$brand = get_post_meta( $post->ID, '_brand', true );
		if ( $brand ) {
			// Prepend so it survives the dedupe/cap ahead of lower-value terms.
			array_unshift( $topics, $brand );
		}
	}
	return $topics;
}, 10, 2 );
```

You cannot break the output by returning too many items or a malformed value — the re-normalize step trims, caps, deduplicates, and strips tags again.

### agentimus_derive_taxonomies

Which taxonomies the auto-derive reads terms from. Default: the two WordPress built-ins, `category` and `post_tag`. Add your own so a vendor content type's terms are offered wherever that type is agent-visible.

```php
/**
 * @param string[]  $taxonomies Taxonomy slugs (default ['category','post_tag']).
 * @param \WP_Post   $post       The post being described.
 * @return string[]
 */
add_filter( 'agentimus_derive_taxonomies', function ( $taxonomies, $post ) {
	if ( 'product' === $post->post_type ) {
		$taxonomies[] = 'product_cat';
		$taxonomies[] = 'product_tag';
		$taxonomies[] = 'pa_brand'; // a global product attribute
	}
	return $taxonomies;
}, 10, 2 );
```

Agentimus narrows your list to taxonomies the post's type actually registers (via `is_object_in_taxonomy()`), then runs those terms through the *same* path as the core ones — the editor's derive toggle, the exclude list, the numeric-junk rule, de-duplication, and the cap. No vendor-specific code ever lives in Agentimus. Terms are read categories-first, then tags, in the order your array lists them.

### agentimus_topic_exclude

Taxonomy **slugs** to omit from a page's auto-derived topics — and from the site-level `## Topics` list in `llms.txt` (the same filter feeds both). The default already excludes `uncategorized`, so a placeholder category never becomes an AI keyword.

```php
/**
 * @param string[] $slugs Excluded term slugs (default ['uncategorized']).
 * @return string[]
 */
add_filter( 'agentimus_topic_exclude', function ( $slugs ) {
	$slugs[] = 'changelog';
	$slugs[] = 'archived';
	return $slugs;
} );
```

This filters by slug, not name, and only affects derived terms — a manual topic with the same text is untouched.

### agentimus_topic_meaningful

The numeric-junk rule. By default Agentimus drops an auto-derived term whose name is *only* digits and separators — matching the regex `^[\d\s.,\/-]+$`. A category literally named `67`, a stray numeric ID, or a bare year used as a noise category tells an assistant nothing, and a wrong keyword is worse than a missing one. Return `true` to keep a numeric name on a site where a number really is the subject (a history archive, a film database where `1984` is the title).

```php
/**
 * @param bool    $meaningful Whether to keep the term as a topic (default false for a purely-numeric name).
 * @param string  $name       The term name.
 * @param object  $term       The term object (may be null; present on the derived path).
 * @return bool
 */
add_filter( 'agentimus_topic_meaningful', function ( $meaningful, $name, $term ) {
	// Keep four-digit years as topics on a history site.
	if ( preg_match( '/^\d{4}$/', trim( $name ) ) ) {
		return true;
	}
	return $meaningful;
}, 10, 3 );
```

This rule runs on every auto-derive path: the per-post derived terms, the site-level `llms.txt` category list, and the editor's suggestion pool (so autocomplete stays clean too). It is deliberately **not** applied to manually typed topics.

### agentimus_topic_suggestions

The autocomplete pool offered in the editor's "Topics for AI" box. By default it is drawn from topics already used on other posts (most-used first), the site's own tags and categories, and the site's declared Expertise (its `knowsAbout` pillars). Add your own controlled vocabulary so authors reuse consistent terms instead of fragmenting them ("WP" vs "WordPress").

```php
/**
 * @param string[] $pool Suggested topic strings.
 * @return string[]
 */
add_filter( 'agentimus_topic_suggestions', function ( $pool ) {
	$pool[] = 'llms.txt';
	$pool[] = 'AI visibility';
	$pool[] = 'structured data';
	return $pool;
} );
```

The pool is cached in a transient (`agentimus_topic_suggest`, busted when any post's topics change) and passed through the numeric-junk rule before display. Bare numbers are never suggested.

## From topics to keywords & `about` DefinedTerms

On a singular view of a covered post type, `Schema::article_node()` reads `Topics::for_post( $post )` and — when the list is non-empty — writes two properties onto the per-post node:

- **`keywords`** — the resolved topics as a flat array of strings.
- **`about`** — one `DefinedTerm` node per topic, each with the topic as its `name`. If your `agentimus_topic_links` filter supplies reference URLs for a topic, they are attached as `sameAs` (a single URL becomes a string; multiple become an array).

A `BlogPosting` node with three topics, one of which has an authoritative link, ships like this:

```json
{
  "@type": "BlogPosting",
  "@id": "https://example.com/hello-world/#blogposting",
  "url": "https://example.com/hello-world/",
  "headline": "Hello World",
  "keywords": ["WordPress", "llms.txt", "AI visibility"],
  "about": [
    { "@type": "DefinedTerm", "name": "WordPress", "sameAs": "https://www.wikidata.org/wiki/Q13166" },
    { "@type": "DefinedTerm", "name": "llms.txt" },
    { "@type": "DefinedTerm", "name": "AI visibility" }
  ],
  "author":    { "@id": "https://example.com/#identity" },
  "publisher": { "@id": "https://example.com/#identity" }
}
```

The `about` block is emitted only when topics exist, and inherits the node's password/publish guard (see below). The same resolved list also becomes the `topics` front matter on the page's Markdown surface — the two never disagree because they call the same resolver.

## Linking topics to Wikidata / Wikipedia (agentimus_topic_links)

**There is no built-in Wikidata UI.** Agentimus deliberately makes no front-end network calls and does no automatic entity matching — a wrong Wikidata guess is worse than none. The supported way to disambiguate a topic (the planet Mercury vs the element, Java the language vs the island) is to supply a small, curated `topic → URL` map through the `agentimus_topic_links` filter. The result is emitted as `sameAs` on that topic's `about` DefinedTerm.

```php
/**
 * @param string[]  $urls  Reference URLs for this topic (default none — core supplies nothing).
 * @param string    $topic The topic text.
 * @param \WP_Post   $post  The post being described.
 * @return string[]
 */
add_filter( 'agentimus_topic_links', function ( $urls, $topic ) {
	$map = array(
		'WordPress'               => 'https://www.wikidata.org/wiki/Q13166',
		'PHP'                     => 'https://www.wikidata.org/wiki/Q59',
		'Artificial intelligence' => 'https://www.wikidata.org/wiki/Q11660',
		// Two links are fine — e.g. the Wikidata entry AND your own canonical page:
		// 'Agentimus' => array( 'https://www.wikidata.org/wiki/…', 'https://heera.it/agentimus' ),
	);
	if ( isset( $map[ $topic ] ) ) {
		$urls = array_merge( $urls, (array) $map[ $topic ] );
	}
	return $urls;
}, 10, 2 );
```

Each returned URL is run through `esc_url_raw()` and de-duplicated, so a malformed or repeated URL is dropped. To find a Wikidata id, search [wikidata.org](https://www.wikidata.org) — the id is the `Q…` in the entry's URL (WordPress → `Q13166`).

This exact snippet ships as `examples/topic-links-wikidata.php`. Drop that file into `wp-content/mu-plugins/` (it loads automatically) or copy the `add_filter()` call into your own theme or plugin, then edit `$map` to your topics. Agentimus does not load the example file itself.

{: .note }
> `$topic` is matched by its exact resolved text, including casing as it appears in `keywords`. Key your map on the string the editor actually entered (or normalize both sides in the callback if you want case-insensitive matching).

## Schema hooks

The JSON-LD graph is only emitted when the `enable_schema` setting is on **and** no schema-emitting SEO plugin is active — Agentimus stands down for Yoast, Rank Math, SEOPress, The SEO Framework, and All in One SEO so it never ships duplicate structured data. That deferral is itself filterable via `agentimus_defer_schema` (return `false` to force Agentimus to emit anyway). The per-post nodes below are also gated: a node is only built on a singular view of a covered type, and never for a password-protected or unpublished post (the admin preview relaxes only the publish-status half).

### agentimus_schema_type_map

Maps a post type to its schema.org `@type`. Defaults: `post` → `BlogPosting`, `page` → `WebPage`; any unmapped type falls back to `Article`. The chosen type also forms the node's `@id` fragment (e.g. `…/#blogposting`).

```php
/**
 * @param array $map Map of post_type => schema.org @type.
 * @return array
 */
add_filter( 'agentimus_schema_type_map', function ( $map ) {
	$map['acme_event']   = 'Event';
	$map['acme_recipe']  = 'Recipe';
	$map['product']      = 'Product';
	return $map;
} );
```

Use this when you just want a different `@type` on the default node — the standard fields (`headline`, `datePublished`, `author`, `keywords`, `about`, …) are still emitted. Reach for `agentimus_schema_for_post` instead when you need to change the node's *shape*.

### agentimus_schema_for_post

Replace or edit a single post's JSON-LD node entirely. Fires after the default node (with its topics/keywords/about) is built. Return a modified array to reshape it, a brand-new array to replace it (e.g. a full `Product` or `Event` node), or `null` to omit the per-post node while still keeping the breadcrumb and any FAQ node.

```php
/**
 * @param array|null $node The default per-post node (or null).
 * @param \WP_Post   $post The post.
 * @return array|null
 */
add_filter( 'agentimus_schema_for_post', function ( $node, $post ) {
	if ( 'product' !== $post->post_type ) {
		return $node;
	}
	return array(
		'@type'  => 'Product',
		'@id'    => get_permalink( $post ) . '#product',
		'name'   => get_the_title( $post ),
		'offers' => array(
			'@type'         => 'Offer',
			'price'         => get_post_meta( $post->ID, '_price', true ),
			'priceCurrency' => 'USD',
			'availability'  => 'https://schema.org/InStock',
		),
	);
}, 10, 2 );
```

The return is validated with `is_array()` before it joins the graph — a scalar (string/number) return is discarded rather than corrupting the `@graph`, and `null` cleanly omits the node.

### agentimus_faq_pairs

Supply or refine the question/answer pairs used to build the `FAQPage` node. Agentimus auto-detects pairs from the rendered post content (headings and Details blocks) via `Faq::extract()`; this filter lets you add, edit, or clear them.

```php
/**
 * @param array[]  $pairs Detected pairs: [ ['q' => '…', 'a' => '…'], … ].
 * @param \WP_Post $post  The post.
 * @return array[]
 */
add_filter( 'agentimus_faq_pairs', function ( $pairs, $post ) {
	$pairs[] = array(
		'q' => 'Does Agentimus require an API key?',
		'a' => 'No — the core plugin runs entirely on your own server.',
	);
	return $pairs;
}, 10, 2 );
```

A `FAQPage` node is emitted **only** when at least two valid pairs remain (a single question is prose, not an FAQ), and each pair's `q` and `a` are stripped to clean plain text. Return fewer than two pairs to suppress the node.

### agentimus_schema_graph

The last-chance filter over the entire `@graph` array, after every node (WebSite, identity Person/Organization, Services, per-post, breadcrumb, FAQ) is assembled and just before JSON encoding. Use it to add a node no other hook covers, or to remove one globally.

```php
/**
 * @param array $graph The full list of @graph nodes.
 * @return array
 */
add_filter( 'agentimus_schema_graph', function ( $graph ) {
	$graph[] = array(
		'@type'    => 'WPFooter',
		'@id'      => home_url( '/#footer' ),
		'copyrightYear' => (int) gmdate( 'Y' ),
	);
	return $graph;
} );
```

If your filter returns a non-array, Agentimus falls back to the valid graph; it then keeps only array nodes, so a stray scalar entry can never reach `json_encode`. If the graph ends up empty, no `<script>` tag is printed at all.

## Quick reference

| Filter | Fires in | Signature | Default / notes |
| --- | --- | --- | --- |
| `agentimus_post_topics` | `Topics::for_post()` | `($topics, $post)` | Final topic list; return is re-normalized (trim/dedupe/cap). |
| `agentimus_derive_taxonomies` | `Topics::derive_taxonomies()` | `($taxonomies, $post)` | `['category','post_tag']`; narrowed to taxonomies the type has. |
| `agentimus_topic_exclude` | derived topics + `llms.txt` Topics | `($slugs)` | `['uncategorized']`; matches by slug; derived terms only. |
| `agentimus_topic_meaningful` | derived topics, suggestions, `llms.txt` | `($meaningful, $name, $term)` | `false` for a purely-numeric name; derived paths only. |
| `agentimus_topic_suggestions` | `Topics::suggestions()` | `($pool)` | Editor autocomplete pool; cached; junk-numeric filtered. |
| `agentimus_topic_links` | `Schema::topic_links()` | `($urls, $topic, $post)` | `[]` (core supplies none); emitted as `about` → `sameAs`. |
| `agentimus_defer_schema` | `Schema::seo_plugin_active()` | `($active)` | `true` when a supported SEO plugin is active; stands schema down. |
| `agentimus_schema_type_map` | `Schema::article_node()` | `($map)` | `post`→`BlogPosting`, `page`→`WebPage`, else `Article`. |
| `agentimus_schema_for_post` | `Schema::build_document()` | `($node, $post)` | Per-post node; return array to replace, `null` to omit (scalars dropped). |
| `agentimus_faq_pairs` | `Schema::faq_node()` | `($pairs, $post)` | `[['q'=>…,'a'=>…], …]`; needs ≥ 2 pairs to emit `FAQPage`. |
| `agentimus_schema_graph` | `Schema::build_document()` | `($graph)` | Whole `@graph`; non-array return falls back to the valid graph. |

For a runnable copy of every hook, see `examples/all-hooks-reference.php` and `examples/topic-links-wikidata.php` in the plugin. The Hooks & filters reference and Integrate your plugin pages cover the rest of the extension surface.
