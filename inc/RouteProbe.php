<?php
/**
 * Route probe — asks the site's own front door who really answers /llms.txt and
 * what the home page <head> carries. Another plugin or a static file can take
 * over a route while every setting here still says "on"; the probe is how
 * Agentimus notices, instead of reporting its own configuration as if it were
 * the truth.
 *
 * Doctrine (mirrors BotRanges): the read path NEVER fetches. Readiness checks
 * read the stored summary only; the two loopback requests run in a one-off cron
 * event, queued when the summary is stale or when the plugin/theme mix changes.
 * A failed fetch keeps the last good summary and records the error — checks
 * built on this data must treat "could not verify" as informational, never as
 * a site problem (fail-open).
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class RouteProbe {

	/** Stored summary (option; autoload off — only readiness surfaces read it). */
	const OPTION = 'agentimus_route_probe';

	/** Filter tag on data() — the seam tests and site owners override. */
	const FILTER = 'agentimus_route_probe';

	/**
	 * The one-off cron event that runs the loopback fetches. WordPress actions
	 * and filters share ONE hook namespace, so this name must never equal
	 * FILTER: apply_filters(FILTER) would invoke the cron-registered refresh()
	 * and recurse without end. RouteProbeTest locks the two apart.
	 */
	const CRON = 'agentimus_route_probe_refresh';

	/** Age after which a summary counts as stale and a refresh is queued. */
	const STALE_AFTER = 12 * HOUR_IN_SECONDS;

	/** Per-response body cap — the <head> and llms.txt both live well below this. */
	const MAX_BYTES = 204800;

	/**
	 * Hook the cron handler, and queue a fresh probe whenever the plugin/theme
	 * mix changes — that is exactly the moment a route can silently change hands.
	 *
	 * @return void
	 */
	public static function watch() {
		add_action( self::CRON, array( self::class, 'refresh' ) );
		add_action( 'activated_plugin', array( self::class, 'schedule_soon' ) );
		add_action( 'deactivated_plugin', array( self::class, 'schedule_soon' ) );
		add_action( 'switch_theme', array( self::class, 'schedule_soon' ) );
	}

	/**
	 * Queue the one-off probe event, if it is not already queued.
	 *
	 * @return void
	 */
	public static function schedule_soon() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
	}

	/**
	 * Queue a refresh when there is no summary yet, or the one we have is stale.
	 * Called from the surfaces that serve the readiness report; the report reads
	 * whatever is stored and never waits for this.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$data = self::data();
		if ( null === $data || ( time() - (int) $data['checked_at'] ) > self::STALE_AFTER ) {
			self::schedule_soon();
		}
	}

	/**
	 * Clear the queued event (deactivation).
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * The stored summary, or null before the first successful probe.
	 *
	 * Shape: {
	 *   checked_at: int,
	 *   error:      string,       // last fetch error, '' when the run was clean
	 *   llms:  null | { http: int, ours: bool, first_line: string },
	 *   head:  null | { descriptions: int, og_titles: int, others: string[] },
	 * }
	 *
	 * @return array|null
	 */
	public static function data() {
		$raw = get_option( self::OPTION, null );
		$raw = is_array( $raw ) && isset( $raw['checked_at'] ) ? $raw : null;

		/**
		 * Override or inspect the stored route-probe summary. Tests inject
		 * states here; a site owner can force null to silence probe-backed
		 * readiness rows entirely.
		 *
		 * @param array|null $raw The stored summary.
		 */
		return apply_filters( self::FILTER, $raw );
	}

	/**
	 * Run the loopback fetches and store the summary. Cron context only.
	 *
	 * @return void
	 */
	public static function refresh() {
		$settings = new Settings();
		$previous = self::data();
		$summary  = array(
			'checked_at' => time(),
			'error'      => '',
			'llms'       => null,
			'head'       => null,
		);

		if ( $settings->enabled( 'enable_llms_txt' ) ) {
			$expected = ( new LlmsText( $settings ) )->llms_txt();
			$response = self::fetch( home_url( '/llms.txt' ) );
			if ( is_array( $response ) ) {
				$summary['llms'] = array(
					'http'       => $response['code'],
					'ours'       => self::is_ours( $response['body'], $expected ),
					'first_line' => strtok( trim( $response['body'] ), "\n" ),
				);
			} else {
				$summary['error'] = $response;
			}
		}

		$response = self::fetch( home_url( '/' ) );
		if ( is_array( $response ) ) {
			$summary['head'] = self::head_summary( $response['body'] );
		} else {
			$summary['error'] = '' === $summary['error'] ? $response : $summary['error'];
		}

		// A run that fetched nothing keeps the previous summary intact — stale
		// truth beats fresh nothing — and only records when and why it failed.
		if ( null === $summary['llms'] && null === $summary['head'] && null !== $previous ) {
			$previous['error'] = $summary['error'];
			$summary           = $previous;
		}

		update_option( self::OPTION, $summary, false );

		// Same cadence, no network: let the robots watch take its look too.
		RobotsWatch::observe();
	}

	/**
	 * Whether the served llms.txt is the one this plugin generates. Exact match
	 * first; a prefix match second, so a harmless trailing addition (a filter,
	 * a server footer) does not read as a foreign file.
	 *
	 * @param string $served   Body the site returned.
	 * @param string $expected Body this plugin generates.
	 * @return bool
	 */
	public static function is_ours( $served, $expected ) {
		$served   = trim( self::strip_bom( (string) $served ) );
		$expected = trim( (string) $expected );
		if ( '' === $expected ) {
			return false;
		}
		if ( hash_equals( md5( $expected ), md5( $served ) ) ) {
			return true;
		}
		$head = substr( $expected, 0, 200 );
		return '' !== $head && 0 === strpos( $served, $head );
	}

	/**
	 * Summarize a page's <head>: how many meta descriptions, how many social
	 * card title tags, and which generators other than WordPress core announce
	 * themselves — the closest thing to a name for "who else prints tags here".
	 *
	 * Pure; safe on malformed HTML. Only the capped prefix up to </head> is read.
	 *
	 * @param string $html Full page HTML.
	 * @return array{descriptions:int,og_titles:int,others:array<int,string>}
	 */
	public static function head_summary( $html ) {
		$html = substr( (string) $html, 0, self::MAX_BYTES );
		$end  = stripos( $html, '</head>' );
		$head = false === $end ? $html : substr( $html, 0, $end );

		$descriptions = preg_match_all( '/<meta\b[^>]*\bname\s*=\s*["\']description["\']/i', $head, $m );
		$og_titles    = preg_match_all( '/<meta\b[^>]*\bproperty\s*=\s*["\']og:title["\']/i', $head, $m );

		$others = array();
		// Generator content, both attribute orders (name first / content first).
		preg_match_all( '/<meta\b[^>]*\bname\s*=\s*["\']generator["\'][^>]*\bcontent\s*=\s*["\']([^"\']+)["\']/i', $head, $a );
		preg_match_all( '/<meta\b[^>]*\bcontent\s*=\s*["\']([^"\']+)["\'][^>]*\bname\s*=\s*["\']generator["\']/i', $head, $b );
		foreach ( array_merge( $a[1], $b[1] ) as $generator ) {
			$generator = trim( $generator );
			// Core's own tag is on every WordPress site; it names nobody.
			if ( '' === $generator || 0 === stripos( $generator, 'WordPress' ) ) {
				continue;
			}
			if ( ! in_array( $generator, $others, true ) ) {
				$others[] = $generator;
			}
		}

		return array(
			'descriptions' => (int) $descriptions,
			'og_titles'    => (int) $og_titles,
			'others'       => $others,
		);
	}

	/**
	 * One capped, short-timeout GET against this site itself.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return array{code:int,body:string}|string Response, or an error sentence.
	 */
	private static function fetch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Agentimus/' . AGENTIMUS_VERSION . ' self-check',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Drop a UTF-8 byte-order mark, if one leads the body.
	 *
	 * @param string $text Raw body.
	 * @return string
	 */
	private static function strip_bom( $text ) {
		return 0 === strpos( $text, "\xEF\xBB\xBF" ) ? substr( $text, 3 ) : $text;
	}
}
