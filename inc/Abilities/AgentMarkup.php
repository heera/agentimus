<?php
/**
 * What an agent's write may not add to a page: anything that runs in a reader's
 * browser.
 *
 * ⛔ WHY CORE'S FILTER IS NOT ENOUGH. An administrator holds `unfiltered_html`, so
 * under an administrator's key core stores whatever arrives. Agents read text
 * written by strangers (comments, posts, fetched pages) and can be talked into
 * things by it, and a stored script runs in the owner's logged-in session, with
 * everything that session can do. The owner typing a script is a decision; an
 * agent typing one is not.
 *
 * ⭐ WHY IT REFUSES INSTEAD OF RUNNING kses. Turning kses on for the write would
 * (a) quietly strip what the agent sent, reporting a write that did not happen,
 * (b) rewrite harmless bytes, where these tools promise to store verbatim, and
 * (c) delete the owner's own embeds and snippets from any page an agent touches.
 * So the rule is kses's rule — the same allowed list, the same protocols — applied
 * as a question before the write, and only to what the write ADDS.
 *
 * Read by a real HTML parser (the one core ships), never by a regex: the question
 * is what a browser will see, and the attacks live in the gap between the two.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

defined( 'ABSPATH' ) || exit;

final class AgentMarkup {

	/** Harmless, written by WordPress itself on images, and missing from kses's list. */
	const IMAGE_ATTRIBUTES = array( 'srcset', 'sizes', 'decoding', 'fetchpriority' );

	/**
	 * Check a field an agent is about to write.
	 *
	 * @param string $after  The value as it would be stored.
	 * @param string $before The value stored now ('' for a new post).
	 * @return \WP_Error|null The refusal, or null when the write may go ahead.
	 */
	public static function refusal( $after, $before = '' ) {
		$after  = (string) $after;
		$before = (string) $before;

		// Below WP 6.5 there is no parser to tell the owner's markup from the
		// agent's, so a value kses would change at all is refused. Only the in-admin
		// assistant reaches this (the MCP tools need 6.9), and its text has already
		// been through kses.
		if ( ! method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			return wp_kses_post( $after ) === $after ? null : self::error( array() );
		}

		$found = self::findings( $after );
		if ( ! $found ) {
			return null;
		}

		// As a count, not a set: a second copy of the owner's script is the agent's.
		$allowed = array_count_values( self::findings( $before ) );
		$added   = array();
		foreach ( $found as $finding ) {
			if ( ! empty( $allowed[ $finding ] ) ) {
				--$allowed[ $finding ];
				continue;
			}
			$added[] = strstr( $finding, "\0", true );
		}

		return $added ? self::error( $added ) : null;
	}

	/**
	 * Every piece of markup in a value that kses would not let through. Each entry
	 * is "label\0fingerprint" — the label is what the refusal says, the fingerprint
	 * is the full content, so that a look-alike never passes as what the owner stored.
	 *
	 * @param string $html The value.
	 * @return string[]
	 */
	private static function findings( $html ) {
		if ( false === strpos( $html, '<' ) ) {
			return array();
		}

		$found = self::scan( $html );

		// A block's attributes live in a comment no browser reads, but a block's
		// render callback may print them. Core's kses filters them too.
		if ( false !== strpos( $html, '<!-- wp:' ) ) {
			foreach ( parse_blocks( $html ) as $block ) {
				$found = array_merge( $found, self::scan_block_attributes( $block ) );
			}
		}

		return $found;
	}

	/**
	 * @param string $html HTML.
	 * @return string[]
	 */
	private static function scan( $html ) {
		$allowed   = wp_kses_allowed_html( 'post' );
		$uri_attrs = wp_kses_uri_attributes();
		$protocols = wp_allowed_protocols();
		$found     = array();
		$tags      = new \WP_HTML_Tag_Processor( $html );

		while ( $tags->next_token() ) {
			if ( '#tag' !== $tags->get_token_type() || $tags->is_tag_closer() ) {
				continue;
			}

			$tag   = strtolower( (string) $tags->get_tag() );
			$names = (array) $tags->get_attribute_names_with_prefix( '' );

			if ( ! isset( $allowed[ $tag ] ) ) {
				$parts = array( $tags->get_modifiable_text() );
				foreach ( $names as $name ) {
					$parts[] = array( $name, $tags->get_attribute( $name ) );
				}
				$found[] = '<' . $tag . ">\0" . md5( (string) wp_json_encode( $parts ) );
				continue;
			}

			foreach ( $names as $name ) {
				$value = $tags->get_attribute( $name );
				$known = isset( $allowed[ $tag ][ $name ] ) || 0 === strpos( $name, 'data-' ) || 0 === strpos( $name, 'aria-' )
					|| ( 'img' === $tag && in_array( $name, self::IMAGE_ATTRIBUTES, true ) );
				$bad   = $known && is_string( $value ) && in_array( $name, $uri_attrs, true )
					&& trim( wp_kses_bad_protocol( $value, $protocols ) ) !== trim( $value );
				if ( ! $known || $bad ) {
					$found[] = $name . ' on <' . $tag . ">\0" . md5( (string) wp_json_encode( $value ) );
				}
			}
		}

		// A tag or a script left open swallows whatever the page prints after it.
		if ( $tags->paused_at_incomplete_token() ) {
			$found[] = __( 'an unfinished tag', 'agentimus' ) . "\0" . md5( substr( $html, -200 ) );
		}

		return $found;
	}

	/**
	 * @param array $block A parsed block.
	 * @return string[]
	 */
	private static function scan_block_attributes( array $block ) {
		$found = array();

		array_walk_recursive(
			$block['attrs'],
			static function ( $value ) use ( &$found ) {
				if ( is_string( $value ) && false !== strpos( $value, '<' ) ) {
					$found = array_merge( $found, self::scan( $value ) );
				}
			}
		);
		foreach ( $block['innerBlocks'] as $inner ) {
			$found = array_merge( $found, self::scan_block_attributes( $inner ) );
		}

		return $found;
	}

	/**
	 * @param string[] $added Labels of what the write would add.
	 * @return \WP_Error
	 */
	private static function error( array $added ) {
		$message = __( 'Nothing was written: this would add markup that can run in a reader’s browser, or that this site only lets a person add. Agents may not store scripts, event handlers, javascript: links, iframes, styles or SVG, even under an administrator’s login, because a stored script runs with the owner’s session. To show a video or post, use its URL in an embed block. If the owner wants this exact markup, they can add it in the editor, and agent edits will then leave it in place. To show a literal “<”, write &lt;.', 'agentimus' );

		if ( $added ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated markup that was refused, e.g. "<script>, onerror on <img>". */
				__( 'Refused: %s.', 'agentimus' ),
				implode( ', ', array_slice( array_unique( $added ), 0, 5 ) )
			);
		}

		return new \WP_Error( 'agentimus_unsafe_markup', $message, array( 'status' => 400 ) );
	}
}
