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
	 * Test seam.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$memo = array();
	}
}
