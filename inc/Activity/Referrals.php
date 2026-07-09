<?php
/**
 * Referrals — count real human visits that arrive FROM an AI assistant
 * (ChatGPT, Perplexity, Gemini, …). The mirror of {@see Recorder}, which logs
 * bots TAKING content; this is the "giving back" side: is AI sending you readers?
 *
 * First-party and AGGREGATE-ONLY: we keep a per-day count per (source, landing
 * path) and DELIBERATELY no IP, no User-Agent, no query string — so no stored row
 * ever represents a person. Detection reads two signals already on the request
 * (the Referer host and the utm_source tag AI tools stamp on their links); there
 * are no outbound calls.
 *
 * Gated by the same `enable_activity` flag as the rest of the activity log — it's
 * one feature, two directions.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Referrals {

	/** Bump when the schema changes to trigger a dbDelta upgrade. */
	const VERSION        = '1';
	const VERSION_OPTION = 'agentimus_referrals_db_version';

	/** @var bool|null Per-request cache of the enable flag. */
	private static $enabled = null;

	/** Per-day drill-down keeps at most this many (source → page) rows; the rest
	 *  roll into a "+N more" so a busy day never balloons the card. */
	const DAY_TOP = 12;

	/** Hard ceiling on stored (day, source, path) rows — a backstop to the daily
	 *  age-based prune, so a spoofed-referrer flood of many distinct one-hit paths
	 *  can't bank unbounded rows before the cron fires. The trim keeps the BUSIEST
	 *  rows (the ones the dashboard actually shows) and drops the long tail — exactly
	 *  where such a flood lands. Generous; filter `agentimus_referrals_max_rows`
	 *  (0 disables the cap). */
	const MAX_ROWS = 50000;

	/** Odds (1-in-N) that an upsert also runs the bounded row-cap trim, keeping the
	 *  table near its ceiling mid-day without a per-request COUNT. */
	const CAP_CHECK_ODDS = 200;

	/**
	 * Fully-qualified table name (site-prefixed).
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_ai_referrals';
	}

	/* ---------------------------------------------------------------------- *
	 *  Detection
	 * ---------------------------------------------------------------------- */

	/**
	 * Known AI-assistant sources: a referrer host OR a utm_source value (lowercased,
	 * www-stripped) maps to a canonical label. Filterable so a site can add its own.
	 *
	 * Google "AI Overviews" is intentionally absent — it arrives with a plain
	 * google.com referrer, indistinguishable from normal search, so counting it
	 * would be guesswork.
	 *
	 * @return array<string,string> needle => label.
	 */
	public static function sources() {
		$map = array(
			'chatgpt.com'           => 'ChatGPT',
			'chat.openai.com'       => 'ChatGPT',
			'perplexity.ai'         => 'Perplexity',
			'gemini.google.com'     => 'Gemini',
			'bard.google.com'       => 'Gemini',
			'copilot.microsoft.com' => 'Copilot',
			'claude.ai'             => 'Claude',
			'you.com'               => 'You.com',
			'poe.com'               => 'Poe',
		);

		/**
		 * Filter the AI-referral source map (needle => label). Needles are matched
		 * against the referrer host and the utm_source value, both lowercased.
		 *
		 * @param array<string,string> $map Source map.
		 */
		return (array) apply_filters( 'agentimus_ai_referral_sources', $map );
	}

	/**
	 * Resolve the AI source for a request from its referrer + utm_source, or '' if
	 * none. PURE — no WordPress query state, no DB — so it unit-tests directly.
	 *
	 * @param string $referer Raw Referer header.
	 * @param string $utm     Raw utm_source value.
	 * @return string Canonical label, or '' when not an AI source.
	 */
	public static function source_for( $referer, $utm ) {
		$map = self::sources();

		// 1. Referrer host (covers Perplexity, Gemini, Claude, … which pass it).
		$host = strtolower( (string) wp_parse_url( (string) $referer, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		if ( '' !== $host ) {
			foreach ( $map as $needle => $label ) {
				$n = preg_replace( '/^www\./', '', strtolower( (string) $needle ) );
				if ( '' !== $n && ( $host === $n || self::is_subdomain_of( $host, $n ) ) ) {
					return $label;
				}
			}
		}

		// 2. utm_source tag (covers ChatGPT, which stamps utm_source=chatgpt.com,
		// so it's caught even when the referrer is stripped).
		$utm = preg_replace( '/^www\./', '', strtolower( trim( (string) $utm ) ) );
		if ( '' !== $utm && isset( $map[ $utm ] ) ) {
			return $map[ $utm ];
		}

		return '';
	}

	/**
	 * Whether $host is a subdomain of $domain (e.g. x.chatgpt.com of chatgpt.com).
	 *
	 * @param string $host   Lowercased host.
	 * @param string $domain Lowercased domain.
	 * @return bool
	 */
	private static function is_subdomain_of( $host, $domain ) {
		$suffix = '.' . $domain;
		return strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix;
	}

	/* ---------------------------------------------------------------------- *
	 *  Recording
	 * ---------------------------------------------------------------------- */

	/**
	 * On a front-end content view, record a +1 for the AI source — if there is one.
	 * Hooked on template_redirect; bails fast (no DB touch) on everything that
	 * isn't a human page view referred from a known AI assistant.
	 */
	public static function maybe_record() {
		if ( ! self::enabled() ) {
			return;
		}
		// In browser-beacon "CDN mode" the front-end beacon is the source of truth — stand
		// the server-side path down so a visit is never counted twice (there is no per-visit
		// id to dedupe on). See record_from_client().
		if ( self::beacon_enabled() ) {
			return;
		}
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}
		if ( is_feed() || is_404() || is_trackback() || is_robots() ) {
			return;
		}

		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- only its host is parsed + matched against a fixed list, never stored or output.
		$utm     = isset( $_GET['utm_source'] ) ? wp_unslash( $_GET['utm_source'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- matched against a fixed list, never stored or output.
		if ( '' === $referer && '' === $utm ) {
			return; // No source signal at all → cheapest exit (direct visits, most internal nav).
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path is parsed out by clean_path(); query (UTM) is discarded.
		self::count_hit( (string) $referer, (string) $utm, (string) $uri );
	}

	/**
	 * Record an AI-referral from the front-end beacon (opt-in "CDN mode"). The browser
	 * sends the navigation's referrer, utm_source and landing path; the SERVER re-derives
	 * the source with {@see source_for()} — never trusting a client-supplied label — exactly
	 * as {@see maybe_record()} does, and stores only (day, source, path) as always: no IP,
	 * no User-Agent, no query string. Runs only when beacon mode is the chosen source.
	 * Returns whether it counted; the endpoint discards that so a spoofer gets no oracle.
	 *
	 * @param string $referer Navigation referrer (document.referrer).
	 * @param string $utm     utm_source value.
	 * @param string $path    Landing path.
	 * @return bool
	 */
	public static function record_from_client( $referer, $utm, $path ) {
		if ( ! self::beacon_enabled() ) {
			return false;
		}
		return self::count_hit( (string) $referer, (string) $utm, (string) $path );
	}

	/**
	 * The shared referral-counting core: skip the owner, resolve the AI source from
	 * (referer, utm), keep only human browsers, and record one hit for the landing path.
	 * Both entry points — the server-side {@see maybe_record()} and the browser beacon
	 * {@see record_from_client()} — funnel through here, so the "what counts as an AI
	 * referral" rule lives in exactly one place.
	 *
	 * @param string $referer Navigation referrer.
	 * @param string $utm     utm_source value.
	 * @param string $uri     Raw landing URL/URI (its query string is stripped before storing).
	 * @return bool Whether a hit was counted.
	 */
	private static function count_hit( $referer, $utm, $uri ) {
		// Skip the owner browsing their own site (shares Recorder's filter).
		if ( apply_filters( 'agentimus_activity_skip_self', is_user_logged_in() && current_user_can( 'manage_options' ) ) ) {
			return false;
		}
		$source = self::source_for( $referer, $utm );
		if ( '' === $source ) {
			return false; // A referrer/utm, but not from a known AI assistant.
		}
		// Count only human browsers — a bot arriving with a referrer isn't a "visitor AI
		// sent". (A real reader clicking from an AI answer uses a browser.)
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only classification, no state change.
		if ( 'Browser' !== Classifier::classify( $ua ) ) {
			return false;
		}
		self::increment( $source, self::clean_path( $uri ) );
		return true;
	}

	/**
	 * Reduce a raw URL/URI to a stored landing path: the path component only (query
	 * string — and any UTM/PII in it — discarded), non-empty, length-capped. Shared by the
	 * server-side recorder and the browser beacon so both store identical paths.
	 *
	 * @param string $uri Raw URL or request URI.
	 * @return string
	 */
	private static function clean_path( $uri ) {
		$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}
		return substr( $path, 0, 190 );
	}

	/**
	 * Increment the daily counter for (today UTC, source, path).
	 *
	 * @param string $source Canonical AI source label.
	 * @param string $path   Landing path.
	 */
	private static function increment( $source, $path ) {
		global $wpdb;
		$table = self::name();
		$wpdb->query( // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; values are bound via prepare().
			$wpdb->prepare(
				"INSERT INTO $table (day, source, path, hits) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1",
				gmdate( 'Y-m-d' ),
				substr( $source, 0, 40 ),
				$path
			)
		);

		// Opportunistic backstop: most upserts pay only a cheap rand(); roughly one in
		// CAP_CHECK_ODDS runs a single bounded trim, so a flood of distinct landing
		// paths can't bank unbounded rows before the daily prune cron.
		if ( 1 === wp_rand( 1, self::CAP_CHECK_ODDS ) ) {
			self::trim_to_cap();
		}
	}

	/**
	 * Cap the table to the busiest {@see MAX_ROWS} (day, source, path) rows — a backstop
	 * to the daily, age-based {@see prune()}. Keeps the highest-hit rows (the ones the
	 * dashboard surfaces) and drops the long tail, which is exactly where a spoofed-
	 * referrer flood of one-hit paths accumulates. Called opportunistically (sampled)
	 * from the upsert path, guarded by a COUNT so the heavier DELETE runs only when the
	 * table is actually over the cap. Filterable; a cap of 0 disables it.
	 */
	public static function trim_to_cap() {
		$max = (int) apply_filters( 'agentimus_referrals_max_rows', self::MAX_ROWS );
		if ( $max < 1 ) {
			return; // Cap disabled.
		}
		global $wpdb;
		$table = self::name();
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the value ($max) is bound via prepare().
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		if ( $count <= $max ) {
			return; // Under the cap — nothing to do.
		}
		// Keep the $max busiest rows; drop the rest. An anti-join (LEFT JOIN … IS NULL)
		// against a derived "keep" set — the derived table both materializes first (so
		// MySQL allows deleting from a table it selects from) and optimizes better than
		// a NOT IN anti-subquery.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE t FROM $table t LEFT JOIN ( SELECT id FROM $table ORDER BY hits DESC, id DESC LIMIT %d ) keep ON t.id = keep.id WHERE keep.id IS NULL",
				$max
			)
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Whether activity logging (which includes referrals) is on (cached per request).
	 *
	 * @return bool
	 */
	private static function enabled() {
		if ( null === self::$enabled ) {
			self::$enabled = (bool) ( new Settings() )->enabled( 'enable_activity' );
		}
		return self::$enabled;
	}

	/**
	 * Whether browser-beacon "CDN mode" is the chosen source of truth for referrals. When
	 * ON, the server-side {@see maybe_record()} stands down and the front-end beacon
	 * ({@see record_from_client()}) counts instead — the two can't be deduped (no per-visit
	 * id is stored), so it is deliberately one OR the other. Opt-in; default OFF, so a
	 * normal install adds no front-end script and counts server-side. Its purpose is to
	 * survive a full-page CDN/edge cache, which hides server-side hits. Requires activity
	 * logging to be on. Filterable to force from code.
	 *
	 * @return bool
	 */
	public static function beacon_enabled() {
		$on = self::enabled() && (bool) ( new Settings() )->enabled( 'enable_referral_beacon' );
		/**
		 * Filter whether AI-referral counting runs client-side (browser beacon) instead of
		 * server-side. Return true to force "CDN mode" from code.
		 *
		 * @param bool $on Whether the browser beacon is the source of truth.
		 */
		return (bool) apply_filters( 'agentimus_referral_beacon', $on );
	}

	/* ---------------------------------------------------------------------- *
	 *  Read (dashboard)
	 * ---------------------------------------------------------------------- */

	/**
	 * Dashboard payload: totals (today / window), top sources, and top landing
	 * pages over the reporting window.
	 *
	 * @param int $window Reporting window in days (matches the activity log).
	 * @return array{enabled:bool,totals:array{today:int,window:int},bySource:array,topPages:array}
	 */
	public static function summary( $window ) {
		global $wpdb;
		$table  = self::name();
		$window = max( 1, (int) $window );
		$since  = gmdate( 'Y-m-d', time() - $window * DAY_IN_SECONDS );
		$today  = gmdate( 'Y-m-d' );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; all values are bound via prepare().
		$today_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits),0) FROM $table WHERE day = %s", $today ) );
		$window_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits),0) FROM $table WHERE day >= %s", $since ) );

		$by_source = $wpdb->get_results( $wpdb->prepare( "SELECT source AS label, SUM(hits) AS hits FROM $table WHERE day >= %s GROUP BY source ORDER BY hits DESC LIMIT 8", $since ), ARRAY_A );
		$top_pages = $wpdb->get_results( $wpdb->prepare( "SELECT path, SUM(hits) AS hits FROM $table WHERE day >= %s GROUP BY path ORDER BY hits DESC LIMIT 8", $since ), ARRAY_A );

		// Per-day drill-down: which source landed on which page, by day. Newest day
		// first; within a day, busiest pairing first. The store has no clock time —
		// the day is the finest "when" there is — and nothing here identifies a person.
		$detail = $wpdb->get_results( $wpdb->prepare( "SELECT day, source, path, SUM(hits) AS hits FROM $table WHERE day >= %s GROUP BY day, source, path ORDER BY day DESC, hits DESC", $since ), ARRAY_A );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'enabled'  => true,
			'beacon'   => self::beacon_enabled(),
			'totals'   => array(
				'today'  => $today_count,
				'window' => $window_count,
			),
			'bySource' => array_map(
				static function ( $r ) {
					return array( 'label' => (string) $r['label'], 'hits' => (int) $r['hits'] );
				},
				(array) $by_source
			),
			'topPages' => array_map(
				static function ( $r ) {
					return array( 'path' => (string) $r['path'], 'hits' => (int) $r['hits'] );
				},
				(array) $top_pages
			),
			'daily'    => self::bucket_days( (array) $detail ),
		);
	}

	/**
	 * Fold the count-ordered {day, source, path, hits} rows into a per-day list:
	 * { date, hits (day total), rows: first DAY_TOP (source → page) pairings,
	 * rowCount: distinct pairings that day }. Input is ordered day DESC, hits DESC,
	 * so days come out newest-first and each day's kept rows are its busiest while
	 * `rowCount` still reflects the full distinct total (drives a "+N more").
	 *
	 * @param array $rows Ordered detail rows.
	 * @return array<int,array{date:string,hits:int,rows:array,rowCount:int}>
	 */
	private static function bucket_days( $rows ) {
		$days  = array();
		$index = array();
		foreach ( $rows as $r ) {
			$date = (string) $r['day'];
			if ( ! isset( $index[ $date ] ) ) {
				$index[ $date ] = count( $days );
				$days[]         = array( 'date' => $date, 'hits' => 0, 'rows' => array(), 'rowCount' => 0 );
			}
			$i                      = $index[ $date ];
			$days[ $i ]['hits']    += (int) $r['hits'];
			$days[ $i ]['rowCount'] += 1;
			if ( count( $days[ $i ]['rows'] ) < self::DAY_TOP ) {
				$days[ $i ]['rows'][] = array(
					'source' => (string) $r['source'],
					'path'   => (string) $r['path'],
					'hits'   => (int) $r['hits'],
				);
			}
		}
		return $days;
	}

	/* ---------------------------------------------------------------------- *
	 *  Maintenance
	 * ---------------------------------------------------------------------- */

	/**
	 * Drop rows older than the activity retention window. Hooked to the same daily
	 * prune cron as the agent-hit log.
	 */
	public static function prune() {
		// Referral counts age on the same clock as the hit log, and stop aging on the same
		// switch. Go through Repository rather than re-applying the filter against the raw
		// constant here — that copy ignored the owner's retention setting entirely.
		if ( Repository::auto_prune() ) {
			global $wpdb;
			$table  = self::name();
			$cutoff = gmdate( 'Y-m-d', time() - Repository::retention_days() * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE day < %s", $cutoff ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the value is bound via prepare().
		}

		// The cap is not optional: guarantee it at least daily, independent of the sampled
		// upsert path and of whether age-based pruning ran.
		self::trim_to_cap();
	}

	/**
	 * Create/upgrade the table only when the stored schema version differs.
	 */
	public static function maybe_install() {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create the daily-aggregate table via dbDelta. The UNIQUE KEY powers the
	 * INSERT … ON DUPLICATE KEY upsert; the path prefix keeps it within index limits.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, lowercase types.
		$sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  source varchar(40) NOT NULL DEFAULT '',
  path varchar(190) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq (day,source,path(150)),
  KEY day (day)
) $collate;";

		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Drop the table and forget the version (used by uninstall).
	 */
	public static function drop() {
		global $wpdb;
		$table = self::name();
		$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema teardown; $table is our own prefix-derived name, not user input.
		delete_option( self::VERSION_OPTION );
	}
}
