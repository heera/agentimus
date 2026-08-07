<?php
/**
 * Template tags — the theme-facing way to read what Agentimus knows.
 *
 * Everything here already reaches machines: topics and descriptions ride the
 * JSON-LD and the plain-text twin, and a media note reaches both. What a theme
 * could not do until now is put any of it on the page for a person, without
 * reaching into `Agentimus\…` classes that carry no promise of stability.
 * These three functions are that promise.
 *
 * Loaded eagerly, before `plugins_loaded`, so a theme can call them from
 * anywhere without worrying about order. Each one:
 *
 *   - reads only — no writes, no HTTP, no scheduling;
 *   - takes a post or ID, and falls back to the current post in the loop;
 *   - returns an EMPTY value when the feature is switched off, the post is
 *     gone, or nobody has filled that field in. Nothing is invented, so a
 *     theme can treat "empty" as "there is nothing worth showing here";
 *   - returns plain text, already stripped of markup — escape it on output
 *     anyway, the way you would with any core template tag.
 *
 * Guarded with function_exists() so a redeclaration elsewhere can never take
 * the site down with a fatal.
 *
 * @package Agentimus
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'agentimus_get_topics' ) ) {
	/**
	 * The topics for a post — what it is about, in a few short phrases.
	 *
	 * Combines whatever the editor typed with whatever Agentimus derived from
	 * the post's own taxonomy (when derivation is on), the editor's entries
	 * first. Deduplicated, trimmed and capped, exactly as the machine surfaces
	 * receive them, so a theme printing them cannot disagree with the schema.
	 *
	 * Example — a tag list under the title:
	 *
	 *     foreach ( agentimus_get_topics() as $topic ) {
	 *         printf( '<li>%s</li>', esc_html( $topic ) );
	 *     }
	 *
	 * @since 1.37.0
	 *
	 * @param int|\WP_Post|null $post Post or ID. Default: the current post.
	 * @return string[] Topics, or an empty array.
	 */
	function agentimus_get_topics( $post = null ) {
		if ( ! class_exists( '\Agentimus\Topics' ) ) {
			return array();
		}
		return \Agentimus\Topics::for_post( null === $post ? get_post() : $post );
	}
}

if ( ! function_exists( 'agentimus_get_description' ) ) {
	/**
	 * The one-line description of a post, as assistants receive it.
	 *
	 * The editor's own wording when there is one; otherwise the post's excerpt,
	 * or a short summary drawn from the body. This is the sentence already
	 * carried by the structured data and the plain-text edition — printing it
	 * in a template is how a card, an archive listing or a search result on the
	 * site itself says the same thing a machine is told.
	 *
	 * Example — a subtitle that only appears when there is one:
	 *
	 *     $summary = agentimus_get_description();
	 *     if ( '' !== $summary ) {
	 *         printf( '<p class="summary">%s</p>', esc_html( $summary ) );
	 *     }
	 *
	 * @since 1.37.0
	 *
	 * @param int|\WP_Post|null $post Post or ID. Default: the current post.
	 * @return string The description, or '' when there is none.
	 */
	function agentimus_get_description( $post = null ) {
		if ( ! class_exists( '\Agentimus\Description' ) ) {
			return '';
		}
		return \Agentimus\Description::for_post( null === $post ? get_post() : $post );
	}
}

if ( ! function_exists( 'agentimus_get_media_context' ) ) {
	/**
	 * The line of context written for one video or audio item — what that
	 * player is about, for anyone who cannot watch or listen to it.
	 *
	 * Pass the media's address in whatever form your template holds it. The
	 * same video legitimately has more than one URL (a block stores the page
	 * you would visit, the player embeds a different one), and this resolves
	 * them to a single identity before looking, so a note written in the editor
	 * is found either way.
	 *
	 * Agentimus deliberately publishes nothing to the front end on its own —
	 * these notes exist for machines. This function is how a theme chooses to
	 * show one to a person as well, such as a caption beneath a player.
	 *
	 * Example:
	 *
	 *     $note = agentimus_get_media_context( $video_url );
	 *     if ( '' !== $note ) {
	 *         printf( '<figcaption>%s</figcaption>', esc_html( $note ) );
	 *     }
	 *
	 * @since 1.37.0
	 *
	 * @param string            $url  The video or audio URL.
	 * @param int|\WP_Post|null $post Post or ID the media sits on. Default: the
	 *                                current post.
	 * @return string The note, or '' when none was written for that item.
	 */
	function agentimus_get_media_context( $url, $post = null ) {
		if ( ! class_exists( '\Agentimus\MediaContext' ) || '' === trim( (string) $url ) ) {
			return '';
		}
		$post = get_post( null === $post ? get_post() : $post );
		if ( ! $post ) {
			return '';
		}
		return \Agentimus\MediaContext::for_url( $post->ID, $url );
	}
}
