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

	/** Rows in the dashboard's "Top sources" leaderboard. The `sourceCount` KPI still reports
	 *  the true distinct total, so a 9th source is counted even though it isn't listed. */
	const TOP_SOURCES = 8;

	/** Rows in the "Top landing pages" leaderboard. Paths are unbounded, so this one is a
	 *  real SQL LIMIT rather than a slice. */
	const TOP_PAGES = 8;

	/** Assistants offered in the report's source filter. A closed vocabulary — the cap is a
	 *  guard against a filter returning something silly, never a real ceiling. */
	const FACET_LIMIT = 50;

	/** Ceiling on a single day's drill-down when the panel asks to see past DAY_TOP. */
	const DAY_MAX = 200;

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
	 * Two shapes of needle, matched by {@see source_for()}:
	 *
	 *  - HOSTS, which match the referrer host and any subdomain of it, and also match a
	 *    utm_source that carries the host verbatim (ChatGPT stamps `utm_source=chatgpt.com`).
	 *  - BARE TOKENS, which contain no dot and so can never equal a host — they exist only
	 *    to catch an assistant that stamps `utm_source=perplexity` rather than a hostname.
	 *    Cheap to carry, and the alternative is silently losing every referrer-stripped
	 *    arrival from that source.
	 *
	 * DELIBERATELY ABSENT: any host that also serves ordinary search — google.com (AI
	 * Overviews / AI Mode), bing.com (Copilot in search), duckduckgo.com, kagi.com, x.com
	 * (Grok). Their referrer is indistinguishable from a plain search click, so counting
	 * them would be guesswork dressed up as data. Their dedicated assistant hosts, where
	 * one exists (gemini.google.com, copilot.microsoft.com, duck.ai), ARE counted.
	 *
	 * @return array<string,string> needle => label.
	 */
	public static function sources() {
		$map = array(
			// Dedicated assistant hosts.
			'chatgpt.com'           => 'ChatGPT',
			'chat.openai.com'       => 'ChatGPT',
			'perplexity.ai'         => 'Perplexity',
			'gemini.google.com'     => 'Gemini',
			'bard.google.com'       => 'Gemini',
			'copilot.microsoft.com' => 'Copilot',
			'claude.ai'             => 'Claude',
			'claude.com'            => 'Claude',
			'grok.com'              => 'Grok',
			'deepseek.com'          => 'DeepSeek', // Subdomain rule covers chat.deepseek.com.
			'meta.ai'               => 'Meta AI',
			'chat.mistral.ai'       => 'Mistral',
			'duck.ai'               => 'DuckDuckGo AI',
			'phind.com'             => 'Phind',
			'you.com'               => 'You.com',
			'poe.com'               => 'Poe',

			// utm_source tokens that are not hostnames.
			'chatgpt'               => 'ChatGPT',
			'openai'                => 'ChatGPT',
			'perplexity'            => 'Perplexity',
			'gemini'                => 'Gemini',
			'copilot'               => 'Copilot',
			'claude'                => 'Claude',
			'grok'                  => 'Grok',
			'deepseek'              => 'DeepSeek',
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
	 * Public because {@see UnknownSources} needs the same rule to tell an external
	 * referrer from the site's own pages, and two copies would drift.
	 *
	 * @param string $host   Lowercased host.
	 * @param string $domain Lowercased domain.
	 * @return bool
	 */
	public static function is_subdomain_of( $host, $domain ) {
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
		// Skip the owner browsing their own site (shares the hit log's rule). Reads the
		// cookie rather than the current user: the beacon posts to a nonce-less REST route,
		// where WordPress has already reset the owner to "logged out". {@see Owner::skip()}.
		if ( Owner::skip() ) {
			return false;
		}
		// Count only human browsers — a bot arriving with a referrer isn't a "visitor AI
		// sent". (A real reader clicking from an AI answer uses a browser.) Tested BEFORE
		// attribution so the diagnostic below never records a crawler's referrer either.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only classification, no state change.
		if ( 'Browser' !== Classifier::classify( $ua ) ) {
			return false;
		}
		$source = self::source_for( $referer, $utm );
		if ( '' === $source ) {
			// A real reader from somewhere we can't name. Recognition is a finite list, so
			// this is the ONLY record that the miss happened — see UnknownSources, which is
			// off unless the owner turns the diagnostic on.
			UnknownSources::note( $referer, $utm );
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
	 * @param int $window Reporting window in days, counting today (matches the activity log).
	 * @return array{enabled:bool,sourceCount:int,totals:array{today:int,window:int},bySource:array,topPages:array}
	 */
	public static function summary( $window ) {
		$window = max( 1, (int) $window );
		// The window is INCLUSIVE of today, and `day` is a whole calendar day: a 30-day
		// report runs from today back through today-29. Subtracting a full $window would
		// sweep in a 31st day and inflate every total against the label above the card.
		$since = gmdate( 'Y-m-d', time() - ( $window - 1 ) * DAY_IN_SECONDS );
		$today = gmdate( 'Y-m-d' );

		$agg = self::aggregate( $since, $today, '', '' );

		$today_count = 0;
		foreach ( $agg['daily'] as $d ) {
			if ( $today === (string) $d['date'] ) {
				$today_count = (int) $d['hits'];
			}
		}

		$by_source = $agg['bySource'];
		$top_pages = $agg['topPages'];
		$daily     = $agg['daily'];

		// The distinct total, not the length of the top-8 slice below: a site hearing from
		// more sources than the leaderboard shows would otherwise under-report the KPI.
		$source_count = count( $by_source );

		return array(
			'enabled'     => true,
			'beacon'      => self::beacon_enabled(),
			'sourceCount' => $source_count,
			'totals'      => array(
				'today'  => $today_count,
				'window' => $agg['total'],
			),
			// The dashboard's leaderboard stays at 8; `sourceCount` above is what says how many
			// there really were. Sliced here, not in SQL, so the count stays exact.
			'bySource'    => array_slice( $by_source, 0, self::TOP_SOURCES ),
			'topPages'    => $top_pages,
			'daily'       => $daily,
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  The filtered report (AI traffic screen)
	 * ---------------------------------------------------------------------- */

	/**
	 * The AI-traffic screen's report, over an arbitrary day range and optionally narrowed to
	 * one assistant and/or a landing-path prefix.
	 *
	 * Why filters here at all, when the dashboard card has none: the store answers questions
	 * the summary structurally cannot. "Which pages does Perplexity send readers to" is a
	 * source × page cross-tab — the leaderboards sum across every source, and a day's
	 * drill-down only shows its busiest {@see DAY_TOP} pairings. And the dashboard is capped
	 * at {@see Repository::report_days()} while the table keeps {@see Repository::retention_days()},
	 * so on a site that keeps 90 days, 60 of them were stored and unreachable.
	 *
	 * DELIBERATELY only three filters. The store has five columns — day, source, path, hits,
	 * id — and nothing else. A "network" or "user-agent" filter here would imply a per-visit
	 * record this table does not keep, and never will.
	 *
	 * @param array $args from, to (Y-m-d), source (exact label), path (prefix).
	 * @return array
	 */
	public static function report( array $args = array() ) {
		$range  = self::clamp_range( $args );
		$source = isset( $args['source'] ) ? substr( sanitize_text_field( (string) $args['source'] ), 0, 40 ) : '';
		$path   = isset( $args['path'] ) ? substr( (string) $args['path'], 0, 190 ) : '';
		$path   = '' === $path ? '' : self::clean_path( '/' === substr( $path, 0, 1 ) ? $path : '/' . $path );

		$agg = self::aggregate( $range['from'], $range['to'], $source, $path );

		return array(
			'enabled'     => true,
			'beacon'      => self::beacon_enabled(),
			'range'       => $range,
			'filters'     => array( 'source' => $source, 'path' => $path ),
			'sourceCount' => count( $agg['bySource'] ),
			'total'       => $agg['total'],
			// Days that actually saw a visit — not the length of the range, which would count
			// the quiet ones and read as coverage the site never had.
			'activeDays'  => count( $agg['daily'] ),
			// The full leaderboards here, not the dashboard's top five: this IS the report.
			'bySource'    => $agg['bySource'],
			'topPages'    => $agg['topPages'],
			'daily'       => $agg['daily'],
			'unknown'     => UnknownSources::report( $range['from'], $range['to'] ),
		);
	}

	/**
	 * The assistants this site has actually heard from, over everything still retained —
	 * NOT over the current range, or narrowing the range would quietly delete the option you
	 * need to widen it again. Ordered by volume, so the ones you get sit at the top.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	public static function facets() {
		global $wpdb;
		$table = self::name();
		$since = self::retention_floor();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; values are bound via prepare().
			$wpdb->prepare( "SELECT source, SUM(hits) AS hits FROM $table WHERE day >= %s GROUP BY source ORDER BY hits DESC LIMIT %d", $since, self::FACET_LIMIT ),
			ARRAY_A
		);
		return array_map(
			static function ( $r ) {
				return array( 'value' => (string) $r['source'], 'label' => (string) $r['source'] );
			},
			(array) $rows
		);
	}

	/**
	 * The oldest day the store can still be holding. With auto-delete off, nothing ages out
	 * on a clock — only the row cap collects — so there is no floor to enforce.
	 *
	 * @return string Y-m-d.
	 */
	private static function retention_floor() {
		if ( ! Repository::auto_prune() ) {
			return '1970-01-01';
		}
		return gmdate( 'Y-m-d', time() - Repository::retention_days() * DAY_IN_SECONDS );
	}

	/**
	 * Resolve and clamp a requested day range. Defaults to the dashboard's window; never
	 * reaches past what retention could still be holding (a range that can't match a row is
	 * not a report, it's a row of zeros); never inverted.
	 *
	 * @param array $args from, to.
	 * @return array{from:string,to:string,floor:string,isDefault:bool}
	 */
	private static function clamp_range( array $args ) {
		$today = gmdate( 'Y-m-d' );
		$floor = self::retention_floor();

		$valid = static function ( $v ) {
			return is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v );
		};

		// The dashboard's window, and the range this screen opens on.
		$default_from = gmdate( 'Y-m-d', time() - ( Repository::report_days() - 1 ) * DAY_IN_SECONDS );
		if ( $default_from < $floor ) {
			$default_from = $floor;
		}

		$to   = ( isset( $args['to'] ) && $valid( $args['to'] ) ) ? $args['to'] : $today;
		$from = ( isset( $args['from'] ) && $valid( $args['from'] ) ) ? $args['from'] : $default_from;

		if ( $to > $today ) {
			$to = $today;
		}
		if ( $from < $floor ) {
			$from = $floor;
		}
		if ( $from > $to ) {
			$from = $to;
		}

		return array(
			'from'  => $from,
			'to'    => $to,
			'floor' => $floor,
			// The panel pre-fills its date inputs from this range, so it re-sends them on every
			// Filter press. Without this the screen could not tell "the last 30 days" from "a
			// range the owner chose that happens to be the last 30 days" — and would offer to
			// Clear a filter nobody set.
			'isDefault' => ( $from === $default_from && $to === $today ),
		);
	}

	/**
	 * The three window-scanning queries every view of this table is built from: per-day
	 * totals, the source leaderboard, the page leaderboard. One place, so a filter can never
	 * apply to one of them and not the others.
	 *
	 * @param string $from   Inclusive day.
	 * @param string $to     Inclusive day.
	 * @param string $source Exact source label, or ''.
	 * @param string $path   Landing-path prefix, or ''.
	 * @return array{daily:array,bySource:array,topPages:array,total:int}
	 */
	private static function aggregate( $from, $to, $source, $path ) {
		global $wpdb;
		$table = self::name();

		$where  = 'day >= %s AND day <= %s';
		$params = array( $from, $to );
		if ( '' !== $source ) {
			$where   .= ' AND source = %s';
			$params[] = $source;
		}
		if ( '' !== $path ) {
			// A PREFIX match, like the request log's User-Agent filter: `LIKE 'x%'` can use
			// the index, `'%x%'` cannot, and a landing path is read left-to-right anyway.
			$where   .= ' AND path LIKE %s';
			$params[] = $wpdb->esc_like( $path ) . '%';
		}

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; $where is built from literals; every value is bound via prepare().

		// Per-day TOTALS only — never the per-day rows. At most one row per day in the range,
		// however large the table grows; a day's drill-down is fetched on demand by
		// {@see day_rows()} when the owner actually opens it. This used to select every
		// (day, source, path) row in the window and fold them in PHP: on a table near its
		// 50,000-row cap that read ~45,000 rows, discarded ~99% of them, and still shipped a
		// 40 KB blob on every dashboard load.
		$daily = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(hits) AS hits, COUNT(*) AS rowCount FROM $table WHERE $where GROUP BY day ORDER BY day DESC", $params ), ARRAY_A );

		// `source` is a canonical label from a fixed, filterable map — a handful of values,
		// never one per visitor — so grouping without a LIMIT is bounded by design. The
		// generous cap is a guard against a filter that returns something silly, and it is
		// far above any real source list, so the distinct count is the true total.
		$by_source = $wpdb->get_results( $wpdb->prepare( "SELECT source AS label, SUM(hits) AS hits FROM $table WHERE $where GROUP BY source ORDER BY hits DESC LIMIT 100", $params ), ARRAY_A );

		// Paths, by contrast, are unbounded (one per URL), so this one must LIMIT in SQL.
		$top_pages = $wpdb->get_results( $wpdb->prepare( "SELECT path, SUM(hits) AS hits FROM $table WHERE $where GROUP BY path ORDER BY hits DESC LIMIT %d", array_merge( $params, array( self::TOP_PAGES ) ) ), ARRAY_A );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// The range total is exactly the day buckets, summed — same rows, same filters. A
		// separate SUM query would scan the range again to reach the same answer.
		$total = 0;
		$days  = array();
		foreach ( (array) $daily as $d ) {
			$total  += (int) $d['hits'];
			$days[]  = array(
				'date'     => (string) $d['day'],
				'hits'     => (int) $d['hits'],
				// Distinct (source → page) pairings that day, under the same filters. Drives
				// the "+N more" note once the drill-down is loaded, and lets the panel skip
				// the request entirely for a day with nothing to show.
				'rowCount' => (int) $d['rowCount'],
			);
		}

		return array(
			'daily'    => $days,
			'total'    => $total,
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
		);
	}

	/**
	 * One day's drill-down: its busiest {@see DAY_TOP} (source → page) pairings, newest-
	 * heaviest first, plus how many distinct pairings that day actually had (so the panel
	 * can say "+N more" honestly rather than implying it showed everything).
	 *
	 * Fetched only when the owner opens a day. The LIMIT is in SQL, not in PHP: a busy day
	 * can hold thousands of pairings, and reading them all to keep twelve is exactly the
	 * cost this endpoint exists to avoid.
	 *
	 * Honours the report's filters, so an opened day shows the same slice as the cards above
	 * it. `full` lifts the cap to {@see DAY_MAX}: without it, the pairings past DAY_TOP were
	 * counted in "+N more" but reachable from nowhere in the UI.
	 *
	 * @param string $date Calendar day, Y-m-d (UTC), already validated by the caller.
	 * @param array  $args source (exact label), path (prefix), full (bool).
	 * @return array{date:string,hits:int,rowCount:int,rows:array<int,array{source:string,path:string,hits:int}>,capped:bool}
	 */
	public static function day_rows( $date, array $args = array() ) {
		global $wpdb;
		$table = self::name();

		$source = isset( $args['source'] ) ? substr( sanitize_text_field( (string) $args['source'] ), 0, 40 ) : '';
		$path   = isset( $args['path'] ) ? substr( (string) $args['path'], 0, 190 ) : '';
		$limit  = empty( $args['full'] ) ? self::DAY_TOP : self::DAY_MAX;

		$where  = 'day = %s';
		$params = array( $date );
		if ( '' !== $source ) {
			$where   .= ' AND source = %s';
			$params[] = $source;
		}
		if ( '' !== $path ) {
			$where   .= ' AND path LIKE %s';
			$params[] = $wpdb->esc_like( $path ) . '%';
		}

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; $where is built from literals; all values are bound via prepare().
		// `id` breaks the tie so two pairings with equal hits keep a stable order between
		// loads — otherwise a refresh could silently reshuffle the list.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT source, path, hits FROM $table WHERE $where ORDER BY hits DESC, id ASC LIMIT %d", array_merge( $params, array( $limit ) ) ),
			ARRAY_A
		);
		$totals = $wpdb->get_row(
			$wpdb->prepare( "SELECT COALESCE(SUM(hits),0) AS hits, COUNT(*) AS rowCount FROM $table WHERE $where", $params ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = array_map(
			static function ( $r ) {
				return array(
					'source' => (string) $r['source'],
					'path'   => (string) $r['path'],
					'hits'   => (int) $r['hits'],
				);
			},
			(array) $rows
		);

		$row_count = (int) ( $totals['rowCount'] ?? 0 );

		return array(
			'date'     => (string) $date,
			'hits'     => (int) ( $totals['hits'] ?? 0 ),
			'rowCount' => $row_count,
			'rows'     => $out,
			// True when even "show all" couldn't reach the tail. Said out loud rather than
			// letting the list imply it showed everything.
			'capped'   => count( $out ) < $row_count && count( $out ) >= self::DAY_MAX,
		);
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
