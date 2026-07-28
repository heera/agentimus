<?php
/**
 * Robots watch — remembers what robots.txt said last time, and records a change
 * event when the policy lines move. A plugin can rewrite robots.txt the moment
 * it activates (silently changing the site's AI-crawler policy); this is how
 * the owner finds out.
 *
 * The watch reads no network: it reconstructs the served robots.txt the same
 * way core's do_robots() builds it (static file wins; otherwise the core
 * default through the `robots_txt` filter — which is exactly where another
 * plugin's takeover happens). It observes on RouteProbe's cron cadence, so a
 * plugin activation triggers a look within a minute.
 *
 * A change is an OBSERVATION, not an accusation: the site owner's own edits
 * land here too. The surfaces that show it say so, and the event clears
 * itself after EXPIRE_AFTER.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class RobotsWatch {

	/** Stored state: baseline lines + hash, and the latest change event. */
	const OPTION = 'agentimus_robots_watch';

	/** A change note that old is stale news — it clears itself. */
	const EXPIRE_AFTER = 14 * DAY_IN_SECONDS;

	/** Directives that carry policy; everything else is noise for the diff. */
	const DIRECTIVES = array( 'user-agent', 'allow', 'disallow', 'content-signal', 'sitemap', 'crawl-delay' );

	/**
	 * The robots.txt the site serves, reconstructed locally. A static file at
	 * the web root wins (that is what the server sends); otherwise core's
	 * default text through the `robots_txt` filter, which mirrors do_robots().
	 *
	 * @return array{contents:string,static:bool}
	 */
	public static function served() {
		$file = Paths::site_root() . 'robots.txt';
		if ( file_exists( $file ) ) {
			return array(
				'contents' => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read-only diagnostic.
				'static'   => true,
			);
		}

		$public   = get_option( 'blog_public' );
		$contents = "User-agent: *\n";
		$contents .= ( '0' === (string) $public )
			? "Disallow: /\n"
			: "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

		return array(
			'contents' => (string) apply_filters( 'robots_txt', $contents, $public ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own robots_txt filter to reconstruct the served robots.txt (mirrors do_robots()), not declaring a new hook.
			'static'   => false,
		);
	}

	/**
	 * Look at today's robots.txt and compare it to the remembered one. First
	 * look remembers silently. A later look that finds different policy lines
	 * records a change event (added/removed lines) and moves the baseline
	 * forward, so the same change is never reported twice.
	 *
	 * @return void
	 */
	public static function observe() {
		$lines = self::normalize( self::served()['contents'] );
		$hash  = md5( implode( "\n", $lines ) );
		$state = get_option( self::OPTION, null );

		if ( ! is_array( $state ) || ! isset( $state['hash'], $state['lines'] ) ) {
			update_option(
				self::OPTION,
				array(
					'hash'     => $hash,
					'lines'    => $lines,
					'taken_at' => time(),
					'change'   => null,
				),
				false
			);
			return;
		}

		if ( hash_equals( (string) $state['hash'], $hash ) ) {
			return;
		}

		$added   = array_values( array_diff( $lines, (array) $state['lines'] ) );
		$removed = array_values( array_diff( (array) $state['lines'], $lines ) );

		$state['hash']     = $hash;
		$state['lines']    = $lines;
		$state['taken_at'] = time();
		if ( ! empty( $added ) || ! empty( $removed ) ) {
			$state['change'] = array(
				'at'      => time(),
				'added'   => $added,
				'removed' => $removed,
			);
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * The latest change event, or null when there is none — or when the one on
	 * record is old enough to have expired (then it is also cleared, so the
	 * option does not carry stale news forever).
	 *
	 * @return array{at:int,added:array<int,string>,removed:array<int,string>}|null
	 */
	public static function change() {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || empty( $state['change']['at'] ) ) {
			return null;
		}
		if ( ( time() - (int) $state['change']['at'] ) > self::EXPIRE_AFTER ) {
			$state['change'] = null;
			update_option( self::OPTION, $state, false );
			return null;
		}
		return $state['change'];
	}

	/**
	 * Reduce robots.txt to its policy lines: known directives only, comments
	 * dropped, whitespace collapsed, the directive name lowercased (values are
	 * kept as written — paths are case-sensitive). Pure.
	 *
	 * @param string $text Raw robots.txt.
	 * @return array<int,string> Normalized lines, file order kept.
	 */
	public static function normalize( $text ) {
		$out = array();
		foreach ( preg_split( '/\R/u', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$directive = strtolower( trim( $parts[0] ) );
			if ( ! in_array( $directive, self::DIRECTIVES, true ) ) {
				continue;
			}
			$value = trim( preg_replace( '/\s+/', ' ', $parts[1] ) );
			$out[] = $directive . ': ' . $value;
		}
		return $out;
	}

	/**
	 * A short human sentence for a list of changed lines: the first few shown,
	 * the rest counted. Pure.
	 *
	 * @param array $lines Normalized lines.
	 * @param int   $max   How many to show before counting the rest.
	 * @return string E.g. "user-agent: GPTBot; disallow: / (+3 more)".
	 */
	public static function excerpt( array $lines, $max = 4 ) {
		$lines = array_map( 'strval', $lines );
		$shown = array_slice( $lines, 0, max( 1, (int) $max ) );
		$rest  = count( $lines ) - count( $shown );
		$text  = implode( '; ', $shown );
		if ( $rest > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: how many more changed lines exist beyond the ones shown. */
				__( '(+%d more)', 'agentimus' ),
				$rest
			);
		}
		return $text;
	}
}
