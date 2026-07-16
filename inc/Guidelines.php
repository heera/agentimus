<?php
/**
 * Content Guidelines bridge — reads the site's editorial guidelines (WordPress's
 * experimental Content Guidelines feature: brand voice, copy/image rules, site
 * context, per-block rules) and folds them into every surface where an AI writes
 * for this site: the in-admin Draft/Suggest/Fix prompts and the MCP write tools'
 * descriptions, so a connected agent drafts in the site's own voice.
 *
 * Thin by design: the feature is experimental (Gutenberg plugin flag today,
 * targeted at core after 7.1), so this class is the ONLY file that knows its
 * storage shape. Everything else asks for a plain-text digest. When the feature
 * is absent this is a no-op everywhere — {@see self::available()} gates on the
 * post type, which holds for the Gutenberg experiment now and a core merge later.
 *
 * Guidelines are deliberately NOT exposed on any unauthenticated endpoint: WP
 * itself auth-gates their REST routes, and we match that stance — they reach
 * agents only through authenticated write-tier tooling and the site's own AI.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Guidelines {

	/** Storage shape of the upstream feature (Gutenberg experiment / future core). */
	const POST_TYPE    = 'wp_guideline';
	const TAXONOMY     = 'wp_guideline_type';
	const TERM_CONTENT = 'content';
	const BLOCK_PREFIX = '_guideline_block_';

	/** Category meta keys → the label each digest line leads with. */
	const CATEGORIES = array(
		'copy'       => 'Copy & tone',
		'images'     => 'Images',
		'site'       => 'Site context',
		'additional' => 'Additional',
	);

	/** Digest caps: full feeds AI prompts, brief rides MCP tool descriptions. */
	const FULL_CHARS  = 4000;
	const BRIEF_CHARS = 900;

	/** Request-scoped digest cache (null = not built yet). */
	private static $digest = null;

	/**
	 * Whether the Content Guidelines feature exists on this site.
	 *
	 * @return bool
	 */
	public static function available() {
		return post_type_exists( self::POST_TYPE );
	}

	/**
	 * The site's content guidelines as a compact plain-text digest, '' when the
	 * feature is absent or no published content guideline exists. Defensive: this
	 * feeds prompt assembly inside REST/ability calls, so upstream storage changes
	 * or a throwing filter must degrade to '' — never fatal the caller.
	 *
	 * @return string
	 */
	public static function digest() {
		if ( null !== self::$digest ) {
			return self::$digest;
		}
		try {
			$out = self::build();
		} catch ( \Throwable $e ) {
			$out = '';
		}

		/**
		 * Filters the guidelines digest injected into AI prompts and MCP write-tool
		 * descriptions. Return '' to opt out entirely.
		 *
		 * @param string $out The assembled digest ('' when none).
		 */
		self::$digest = (string) apply_filters( 'agentimus_guidelines', $out );
		return self::$digest;
	}

	/**
	 * A shorter cut of the digest for MCP tool descriptions.
	 *
	 * @return string
	 */
	public static function brief() {
		return self::clip( self::digest(), self::BRIEF_CHARS );
	}

	/**
	 * Drop the request cache (tests; or after programmatic guideline writes).
	 */
	public static function reset_cache() {
		self::$digest = null;
	}

	/**
	 * Assemble the digest from the newest published guideline carrying the
	 * `content` type term.
	 *
	 * @return string
	 */
	private static function build() {
		if ( ! self::available() ) {
			return '';
		}

		$post = self::content_guideline();
		if ( ! $post ) {
			return '';
		}

		$lines = array();
		foreach ( self::CATEGORIES as $key => $label ) {
			$v = trim( (string) get_post_meta( $post->ID, '_guideline_' . $key, true ) );
			if ( '' !== $v ) {
				$lines[] = $label . ': ' . preg_replace( '/\s+/', ' ', $v );
			}
		}

		// Per-block rules live under one prefixed meta key each (_guideline_block_core/heading).
		$block = array();
		foreach ( (array) get_post_meta( $post->ID ) as $key => $vals ) {
			if ( 0 !== strpos( (string) $key, self::BLOCK_PREFIX ) ) {
				continue;
			}
			$v = trim( (string) ( is_array( $vals ) ? reset( $vals ) : $vals ) );
			if ( '' !== $v ) {
				$block[ substr( (string) $key, strlen( self::BLOCK_PREFIX ) ) ] = preg_replace( '/\s+/', ' ', $v );
			}
		}
		if ( ! empty( $block ) ) {
			ksort( $block );
			$rules = array();
			foreach ( $block as $name => $rule ) {
				$rules[] = $name . ': ' . $rule;
			}
			$lines[] = 'Block rules — ' . implode( '; ', $rules );
		}

		return self::clip( implode( "\n", $lines ), self::FULL_CHARS );
	}

	/**
	 * The newest published wp_guideline post carrying the `content` term, or null.
	 *
	 * @return \WP_Post|object|null
	 */
	private static function content_guideline() {
		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 10,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			)
		);
		foreach ( (array) $posts as $post ) {
			$terms = wp_get_post_terms( $post->ID, self::TAXONOMY );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( (array) $terms as $term ) {
				if ( is_object( $term ) && isset( $term->slug ) && self::TERM_CONTENT === $term->slug ) {
					return $post;
				}
			}
		}
		return null;
	}

	/**
	 * Cut a digest at a word boundary under $max characters.
	 *
	 * @param string $text Digest.
	 * @param int    $max  Character cap.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = substr( $text, 0, $max );
		$sp  = strrpos( $cut, ' ' );
		return rtrim( false !== $sp ? substr( $cut, 0, $sp ) : $cut ) . '…';
	}
}
