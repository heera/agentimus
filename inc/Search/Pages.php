<?php
/**
 * Pages — resolve engine-reported page URLs to local post IDs, once per URL
 * per run. The ID is what wires an opportunity to its fixes (the editor, the
 * readability panel, the set-aside list); a URL that maps to nothing (an
 * archive, a feed, a long-gone permalink) resolves to 0 and the engine treats
 * it accordingly.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Pages {

	/** @var array<string,int> Per-request memo. */
	private static $memo = array();

	/** @var array<string,\WP_Term|null> Per-request memo for {@see term()}. */
	private static $terms = array();

	/**
	 * The local post ID for an engine-reported URL, or 0.
	 *
	 * @param string $url Absolute URL as the engine reported it.
	 * @return int
	 */
	public static function resolve( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return 0;
		}
		if ( array_key_exists( $url, self::$memo ) ) {
			return self::$memo[ $url ];
		}
		$id = (int) url_to_postid( $url );
		if ( 0 === $id && '/' !== substr( $url, -1 ) ) {
			// Engines report both trailing-slash forms; try the canonical one.
			$id = (int) url_to_postid( trailingslashit( $url ) );
		}
		self::$memo[ $url ] = $id;
		return $id;
	}

	/**
	 * The stable identity for an engine-reported URL — how the set-aside ledger
	 * keys pages that map to no post. Trailing-slash variants collapse to one
	 * entry, because engines report both spellings of the same page.
	 *
	 * @param string $url Absolute URL as the engine reported it.
	 * @return string Empty when there is nothing to key.
	 */
	public static function key( $url ) {
		$url = trim( (string) $url );
		return '' === $url ? '' : untrailingslashit( $url );
	}

	/**
	 * The tag, category or other public term whose archive this URL is, or null.
	 *
	 * An unmapped URL is not always nameless. A term archive is the commonest
	 * page an engine reports that has no post behind it, and the site knows
	 * exactly which term it is — so the owner reads "Tag: navigator.locks",
	 * not "/tag/navigator-locks", and gets the term's own edit screen.
	 *
	 * Matched by asking WordPress for the term's link and comparing paths, never
	 * by guessing at bases: a custom tag base, a hierarchical category or a
	 * custom taxonomy all resolve the same way, and a slug that only LOOKS like
	 * a term (a gone post, a page named like a tag) does not.
	 *
	 * @param string $url Absolute URL as the engine reported it.
	 * @return \WP_Term|null
	 */
	public static function term( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return null;
		}
		if ( array_key_exists( $url, self::$terms ) ) {
			return self::$terms[ $url ];
		}
		self::$terms[ $url ] = null;

		// Plain permalinks: every term archive lives at the site root, named
		// only by its query ("/?tag=x", "/?cat=5").
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			self::$terms[ $url ] = self::query_term( $url, $query );
			if ( self::$terms[ $url ] ) {
				return self::$terms[ $url ];
			}
		}

		// Page 2 of an archive is still that archive; so is its feed.
		$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$path = (string) preg_replace( '#/(?:page/\d+|feed(?:/[a-z0-9]+)?)$#i', '', $path );
		$slug = rawurldecode( (string) substr( (string) strrchr( '/' . $path, '/' ), 1 ) );
		if ( '' === $slug ) {
			return null;
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$term = get_term_by( 'slug', $slug, $taxonomy ); // Core sanitizes a slug lookup itself.
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( is_string( $link ) && untrailingslashit( (string) wp_parse_url( $link, PHP_URL_PATH ) ) === $path ) {
				self::$terms[ $url ] = $term;
				break;
			}
		}
		return self::$terms[ $url ];
	}

	/**
	 * The term a plain-permalink archive address names, or null.
	 *
	 * Same proof as a pretty one: the term's own link must say the same thing.
	 * Every argument of that link must be in the address with the same value,
	 * at the same path — so "/?tag=x" is the tag, while "/?s=x" or a "?tag="
	 * on some other page is not.
	 *
	 * @param string $url   Absolute URL.
	 * @param string $query Its query string.
	 * @return \WP_Term|null
	 */
	private static function query_term( $url, $query ) {
		parse_str( $query, $args );
		$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		$candidates = array();
		if ( isset( $args['cat'] ) && is_scalar( $args['cat'] ) ) {
			$candidates[] = get_term( (int) $args['cat'], 'category' );
		}
		if ( isset( $args['taxonomy'], $args['term'] ) && is_scalar( $args['taxonomy'] ) && is_scalar( $args['term'] ) ) {
			$candidates[] = get_term_by( 'slug', (string) $args['term'], (string) $args['taxonomy'] );
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$var = (string) $taxonomy->query_var;
			if ( '' !== $var && isset( $args[ $var ] ) && is_scalar( $args[ $var ] ) ) {
				$candidates[] = get_term_by( 'slug', (string) $args[ $var ], $taxonomy->name );
			}
		}

		foreach ( $candidates as $term ) {
			if ( ! $term instanceof \WP_Term || ! is_taxonomy_viewable( $term->taxonomy ) ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( ! is_string( $link ) || untrailingslashit( (string) wp_parse_url( $link, PHP_URL_PATH ) ) !== $path ) {
				continue;
			}
			parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $want );
			if ( array() === $want ) {
				continue;
			}
			foreach ( $want as $key => $value ) {
				if ( ! isset( $args[ $key ] ) || (string) $args[ $key ] !== (string) $value ) {
					continue 2;
				}
			}
			return $term;
		}
		return null;
	}

	/**
	 * What to call an engine-reported page, in every list that shows one.
	 *
	 * One namer for every door — the admin cards, the split rows, the agent's
	 * reads, the Findings wins — so a page is never "Tag: x" on one screen and
	 * "/tag/x" on the next. In order: the post's title; "<Taxonomy>: <term>"
	 * for a term archive; "Homepage" for the site root; the path for the
	 * rest (a gone permalink, a date archive), which is all that is known.
	 *
	 * @param int    $page_id Resolved post ID, or 0.
	 * @param string $url     Absolute URL as the engine reported it.
	 * @return string
	 */
	public static function title( $page_id, $url ) {
		$page_id = (int) $page_id;
		if ( $page_id > 0 ) {
			// Decoded, not escaped: get_the_title() returns HTML entities, and
			// every consumer escapes text itself.
			return html_entity_decode( (string) get_the_title( $page_id ), ENT_QUOTES, 'UTF-8' );
		}
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$path = '' !== $path ? $path : '/';
		// The term first: on plain permalinks a tag page's path IS the root.
		$term = self::term( $url );
		if ( $term ) {
			$tax   = get_taxonomy( $term->taxonomy );
			$label = $tax && ! empty( $tax->labels->singular_name ) ? (string) $tax->labels->singular_name : (string) $term->taxonomy;
			/* translators: 1: taxonomy name, e.g. "Tag"; 2: the term's name. */
			return sprintf( __( '%1$s: %2$s', 'agentimus' ), $label, html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ) );
		}
		if ( self::is_home_path( $path ) ) {
			return __( 'Homepage', 'agentimus' );
		}
		return $path;
	}

	/**
	 * The term archive's edit screen, or '' when the URL is no term archive
	 * or this user cannot edit it.
	 *
	 * @param string $url Absolute URL as the engine reported it.
	 * @return string
	 */
	public static function term_edit_url( $url ) {
		$term = self::term( $url );
		return $term ? (string) get_edit_term_link( $term, $term->taxonomy ) : '';
	}

	/**
	 * Whether a path is the site root — subdirectory installs compare against
	 * their real home path, not "/".
	 *
	 * @param string $path URL path ('/' when bare).
	 * @return bool
	 */
	public static function is_home_path( $path ) {
		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		return untrailingslashit( (string) $path ) === $home;
	}

	/**
	 * Test seam.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$memo  = array();
		self::$terms = array();
	}
}
