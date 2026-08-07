---
title: Template tags
parent: Developer Reference
nav_order: 7
---

Agentimus writes for machines. Topics, the one-line description and the note attached to a video all travel to the JSON-LD graph and the plain-text twin of a page — and none of them appear on the rendered page unless a theme asks for them.

These three functions are how a theme asks. They are the only global functions the plugin defines, and the only part of it a theme should call: everything else lives behind `Agentimus\…` class names that may be reorganised without warning.

They come from `inc/template-tags.php`, which loads before `plugins_loaded`, so there is no wrong moment to call one.

## The three tags

| Function | Gives you | Empty value |
| --- | --- | --- |
| `agentimus_get_topics( $post = null )` | `string[]` — the short phrases describing what the page covers | `[]` |
| `agentimus_get_description( $post = null )` | `string` — the single sentence assistants are handed | `''` |
| `agentimus_get_media_context( $url, $post = null )` | `string` — the line explaining one video or audio item | `''` |

`$post` accepts an ID or a `WP_Post`. Omit it inside the loop and the current post is used.

## What they promise

**They read; nothing else.** No writes, no outbound requests, no scheduled work. Calling one in a template costs a couple of meta reads.

**They agree with the machine surfaces.** Each tag calls the very resolver the schema and Markdown output use, so a value printed in your template and the value an assistant receives cannot drift apart. There is no second code path to keep in step.

**Empty means empty.** Switch a feature off, pass a post that has been deleted, call one outside the loop, or leave the field blank in the editor, and you get an empty string or an empty array. Nothing is guessed to fill the space, so `if ( '' !== $value )` is a reliable test for "is there anything here worth showing".

**They cannot white-screen a site.** Every tag is wrapped in `function_exists()`, checks that the class it needs is present, and returns an empty value rather than throwing when a post cannot be found.

**The text is plain, but escape it anyway.** Markup is already stripped and lengths already capped on the way in. Treat the result exactly as you would `get_the_title()` and run it through `esc_html()` at the point of output.

## Worked example

A single-post template that shows the summary as a subtitle and the topics as a list, each appearing only when there is something to show:

```php
<?php
$summary = agentimus_get_description();
if ( '' !== $summary ) :
	?>
	<p class="entry-summary"><?php echo esc_html( $summary ); ?></p>
<?php endif; ?>

<?php
$topics = agentimus_get_topics();
if ( $topics ) :
	?>
	<ul class="entry-topics">
		<?php foreach ( $topics as $topic ) : ?>
			<li><?php echo esc_html( $topic ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
```

## Media notes and the two-URL problem

One video carries more than one address. An embed block stores the page a person would visit (`youtube.com/watch?v=…`); the player that ends up in the markup uses another (`youtube.com/embed/…`). A note saved against one would be invisible to a template holding the other.

`agentimus_get_media_context()` resolves both to a single identity before it looks, so pass whichever URL your template already has:

```php
$note = agentimus_get_media_context( $video_url );
if ( '' !== $note ) {
	printf( '<figcaption>%s</figcaption>', esc_html( $note ) );
}
```

Showing that note to visitors is entirely your call. Agentimus keeps it for assistants and never prints it itself — a video's own words belong to whatever media plugin owns the player, and this line is only the metadata around it.

## One tag that does not exist, on purpose

There is no `agentimus_is_agent_request()`, and there will not be.

A function like that is a switch for serving one page to a crawler and a different one to a reader. The plugin's entire argument is that a site should be legible to both *without* that split — and cloaking is a good way to be penalised by the search engines and assistants you were trying to reach. Every value above describes content a human visitor can already see.

If you need to vary output, vary it on something honest: the post type, a template part, a taxonomy, a setting of your own.

## Related

- **Hooks & filters** — to change what a value *is* before anything reads it, filter it rather than editing what you print. `agentimus_post_topics` and `agentimus_post_description` run inside these same resolvers.
- **Topics & schema** — how a topic list becomes `keywords` and `about` entries in the JSON-LD graph.
- **REST & endpoints** — for reading the same data from outside WordPress.
