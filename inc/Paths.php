<?php
/**
 * Filesystem-location helpers.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the PUBLIC site root — the document root that maps to home_url(),
 * where /robots.txt, /humans.txt and the /.well-known/ directory actually live.
 *
 * This is deliberately NOT ABSPATH. ABSPATH is the WordPress *install* directory,
 * which is not always the public site root: on a "WordPress in its own directory"
 * setup core lives in a subfolder (e.g. /wp/) while the site is served from the
 * parent (/), so the web-root files above are siblings of ABSPATH, not children.
 * Mirrors core's get_home_path() derivation (home vs siteurl), preferring that
 * function in the admin where it is loaded, and falling back to the same logic on
 * the front end where it is not.
 */
final class Paths {

	/** @var string|null Memoised, trailing-slashed public site root. */
	private static $site_root = null;

	/**
	 * Absolute, normalised, trailing-slashed path to the public site root.
	 *
	 * @return string
	 */
	public static function site_root() {
		if ( null !== self::$site_root ) {
			return self::$site_root;
		}

		// In the admin, core's own resolver is authoritative.
		if ( function_exists( 'get_home_path' ) ) {
			self::$site_root = wp_normalize_path( trailingslashit( get_home_path() ) );
			return self::$site_root;
		}

		$root = ABSPATH; // Common case (and safe fallback): WordPress is the web root.

		if ( function_exists( 'set_url_scheme' ) && isset( $_SERVER['SCRIPT_FILENAME'] ) ) {
			$home    = set_url_scheme( (string) get_option( 'home' ), 'http' );
			$siteurl = set_url_scheme( (string) get_option( 'siteurl' ), 'http' );
			if ( '' !== $home && 0 !== strcasecmp( $home, $siteurl ) ) {
				$rel    = str_ireplace( $home, '', $siteurl ); // The subdirectory, e.g. "/wp".
				$script = str_replace( '\\', '/', sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) );
				$pos    = strripos( $script, trailingslashit( $rel ) );
				if ( false !== $pos ) {
					$root = substr( $script, 0, $pos );
				}
			}
		}

		self::$site_root = wp_normalize_path( trailingslashit( $root ) );
		return self::$site_root;
	}
}
