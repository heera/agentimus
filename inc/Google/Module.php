<?php
/**
 * Google module — the daily poll that copies Search Console's query×page
 * performance into the site's own table, and the schedule that runs it.
 *
 * Two HTTPS requests per poll (a token, then one report), nothing else. The
 * whole feature stands down until a key is connected. Fail-open throughout —
 * an API error records itself and keeps the last good snapshot.
 *
 * @package Agentimus
 */

namespace Agentimus\Google;

use Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Module {

	/** @var string The recurring poll event. */
	const CRON = 'agentimus_google_poll';

	/** @var string Single events that continue an index sweep left unfinished
	 * by a budgeted chunk — so the daily cron request never runs long either. */
	const CRON_INDEX = 'agentimus_google_index_chunk';

	/** @var string Option-backed run lock. */
	const LOCK_OPTION = 'agentimus_google_lock';

	/** @var int A poll is two HTTP calls; a lock older than this is a died run to steal. */
	const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/** @var int The report window, in days. */
	const WINDOW_DAYS = 56;

	/** @var string Daily clicks/impressions history + Discover totals — kept
	 * where Search Console's own window ends, like the Bing side's history. */
	const TREND_OPTION = 'agentimus_google_trend';

	/** @var string The last GA4 window — totals only, never per-visitor anything. */
	const GA4_OPTION = 'agentimus_google_ga4';

	/** @var int Days of daily history kept at most. */
	const TREND_KEEP = 400;

	/** @var int Search Console data lags — the window ends this many days ago, where numbers are final. */
	const LAG_DAYS = 2;

	/** @var Settings */
	private $settings;

	/** @var Client */
	private $client;

	/**
	 * @param Settings    $settings The Google connection store.
	 * @param Client|null $client   Injectable for tests.
	 */
	public function __construct( Settings $settings, ?Client $client = null ) {
		$this->settings = $settings;
		$this->client   = $client ? $client : new Client();
	}

	/**
	 * Hooks only — no work in the constructor.
	 *
	 * @return void
	 */
	public function register() {
		Search\Table::maybe_install();
		add_action( self::CRON, array( $this, 'poll' ) );
		add_action( self::CRON_INDEX, array( $this, 'index_chunk' ) );
		add_action( 'admin_init', array( $this, 'sync_schedule' ) );
	}

	/**
	 * Keep the cron slot consistent with the connection state.
	 *
	 * @return void
	 */
	public function sync_schedule() {
		$scheduled = (bool) wp_next_scheduled( self::CRON );
		if ( ! $this->settings->connected() ) {
			if ( $scheduled ) {
				wp_clear_scheduled_hook( self::CRON );
			}
			return;
		}
		if ( ! $scheduled ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Activation-time scheduling: only when a connection already exists.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! ( new Settings() )->connected() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Deactivation cleanup.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
		wp_clear_scheduled_hook( self::CRON_INDEX );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * The cron handler. Never overlaps itself.
	 *
	 * @return void
	 */
	public function poll() {
		if ( ! $this->settings->connected() ) {
			return;
		}
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && ( time() - $held ) < self::LOCK_TTL ) {
			return;
		}
		update_option( self::LOCK_OPTION, time(), false );
		try {
			$this->run_poll();
			// The index sweep rides the same daily slot but keeps its own
			// bookkeeping: a spent inspection quota must not smear "poll
			// failed" over a perfectly good performance snapshot. One chunk
			// here; the continuation event carries whatever remains.
			$this->run_index_sweep( 15.0 );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * One poll, also callable inline right after connect so the screen has
	 * numbers immediately. The report covers the trailing window ending where
	 * Search Console's numbers are final; each poll replaces the snapshot.
	 *
	 * @return void
	 */
	public function run_poll() {
		$sa_json  = $this->settings->sa_json();
		$property = (string) $this->settings->get( 'property', '' );
		if ( '' === $sa_json || '' === $property ) {
			return;
		}

		$auth = Auth::token( $sa_json );
		if ( isset( $auth['error'] ) ) {
			$this->settings->record_poll( (string) $auth['error'] );
			return;
		}

		$end   = gmdate( 'Y-m-d', time() - self::LAG_DAYS * DAY_IN_SECONDS );
		$start = gmdate( 'Y-m-d', time() - ( self::LAG_DAYS + self::WINDOW_DAYS ) * DAY_IN_SECONDS );
		$out   = $this->client->search_analytics( $auth['token'], $property, $start, $end );
		if ( isset( $out['error'] ) ) {
			$this->settings->record_poll( (string) $out['error'] );
			return;
		}

		$rows = array();
		foreach ( (array) $out['rows'] as $row ) {
			$rows[] = array(
				'query'       => $row['query'],
				'page_url'    => $row['page'],
				'page_id'     => Search\Pages::resolve( $row['page'] ),
				'clicks'      => $row['clicks'],
				'impressions' => $row['impressions'],
				'position'    => $row['position'],
				'range_start' => $start,
				'range_end'   => $end,
			);
		}

		// The decoded API response is dead once the rows are mapped, and on a big
		// property it is the largest single thing in memory. Dropped before the
		// write rather than at the end of the function, so the insert never runs
		// alongside a copy nothing will read again.
		unset( $out );

		Search\Table::replace( 'google', $rows );
		unset( $rows );
		$this->settings->record_poll( '' );

		// The trend series and Discover totals ride the same poll — two cheap
		// extra calls. Their failures never smear the snapshot above: the
		// window numbers just landed, and a transport blip on the extras
		// leaves the last good series in place rather than a false zero.
		$trend = get_option( self::TREND_OPTION, array() );
		$trend = is_array( $trend ) ? $trend : array();

		$daily = $this->client->search_analytics_daily( $auth['token'], $property, $start, $end );
		if ( isset( $daily['days'] ) ) {
			$trend['daily'] = self::merge_daily(
				isset( $trend['daily'] ) && is_array( $trend['daily'] ) ? $trend['daily'] : array(),
				$daily['days'],
				self::TREND_KEEP
			);
			// Stamped on SUCCESS only: keeping last-good data on a blip is
			// right, but a trend quietly stale for a week must be able to
			// say so on the screen instead of posing as current.
			$trend['daily_at'] = time();
		}

		$discover = $this->client->discover_totals( $auth['token'], $property, $start, $end );
		if ( isset( $discover['totals'] ) ) {
			$trend['discover'] = $discover['totals'];
		}

		update_option( self::TREND_OPTION, $trend, false );

		$this->poll_analytics();
	}

	/**
	 * The GA4 half alone, for the moment Analytics is connected — the same
	 * first-numbers-now courtesy the key connect and the Bing connect give.
	 * Without it, a Readers screen that just verified successfully sits empty
	 * until tomorrow's cron, looking broken to the owner who connected it.
	 *
	 * @return void
	 */
	public function run_analytics_poll() {
		$this->poll_analytics();
	}

	/**
	 * The GA4 half of the poll — everyone who came, not only the ones an engine
	 * or an assistant sent.
	 *
	 * Runs LAST and never touches the Search Console outcome above: analytics is
	 * a second, independent grant, and a property the key can't read must leave
	 * the search snapshot exactly as it found it. Its own error field, its own
	 * timestamp, its own silence.
	 *
	 * A SHORT window (the dashboard's own report span, not Search Console's 56
	 * days): this number sits beside counts from the request log, and two figures
	 * side by side must cover the same days or the comparison is a lie.
	 *
	 * @return void
	 */
	private function poll_analytics() {
		if ( ! $this->settings->analytics_connected() ) {
			return;
		}
		$property = (string) $this->settings->get( 'ga4_property', '' );

		$auth = Auth::token( $this->settings->sa_json(), Auth::SCOPE_ANALYTICS );
		if ( isset( $auth['error'] ) ) {
			$this->settings->record_ga4_poll( (string) $auth['error'] );
			return;
		}

		$window = \Agentimus\Activity\Repository::report_days();
		$end    = gmdate( 'Y-m-d' );
		$start  = gmdate( 'Y-m-d', time() - ( max( 1, $window ) - 1 ) * DAY_IN_SECONDS );

		$client = new Analytics();

		$out = $client->totals( $auth['token'], $property, $start, $end );
		if ( isset( $out['error'] ) ) {
			// Last good numbers stay put. A transport blip must not turn into a
			// confident zero on a card that says how many people read the site.
			$this->settings->record_ga4_poll( (string) $out['error'] );
			return;
		}

		// GA4's own reading of how many of those readers an assistant sent — the
		// second opinion the Readers screen shows beside our own count. A failure
		// here costs only the split: the totals above are already good, and
		// throwing them away over a secondary report would be a poor trade.
		$split = $client->ai_split( $auth['token'], $property, $start, $end );

		// The busiest pages for EVERYONE — the human half's equivalent of the
		// AI landing-page list, and the thing that makes that list mean
		// something (a page with 8 AI visits reads differently when the page
		// has 40 in total than when it has 9).
		$pages = $client->top_pages( $auth['token'], $property, $start, $end, 8 );

		update_option(
			self::GA4_OPTION,
			array(
				'totals'  => $out['totals'],
				'split'   => isset( $split['split'] ) ? $split['split'] : null,
				'pages'   => isset( $pages['pages'] ) ? $pages['pages'] : array(),
				'window'  => (int) $window,
				'start'   => $start,
				'end'     => $end,
				'fetched' => time(),
			),
			false
		);
		$this->settings->record_ga4_poll( '' );
	}

	/**
	 * The stored GA4 snapshot, or an empty shell.
	 *
	 * @return array
	 */
	public static function analytics_snapshot() {
		$stored = get_option( self::GA4_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Merge a fresh daily window into the stored history: fresh days replace
	 * stored ones (Search Console revises recent days), older history stays —
	 * the series keeps growing where Google's own window ends. Sorted by
	 * date, capped from the OLD end.
	 *
	 * @param array $stored Stored rows [{date, clicks, impressions}].
	 * @param array $fresh  This poll's window rows, same shape.
	 * @param int   $keep   Days kept at most.
	 * @return array
	 */
	public static function merge_daily( array $stored, array $fresh, $keep ) {
		$by_date = array();
		foreach ( $stored as $row ) {
			if ( ! empty( $row['date'] ) ) {
				$by_date[ (string) $row['date'] ] = $row;
			}
		}
		foreach ( $fresh as $row ) {
			if ( ! empty( $row['date'] ) ) {
				$by_date[ (string) $row['date'] ] = array(
					'date'        => (string) $row['date'],
					'clicks'      => (int) ( isset( $row['clicks'] ) ? $row['clicks'] : 0 ),
					'impressions' => (int) ( isset( $row['impressions'] ) ? $row['impressions'] : 0 ),
				);
			}
		}
		ksort( $by_date );
		return array_slice( array_values( $by_date ), -1 * max( 1, (int) $keep ) );
	}

	/**
	 * One budgeted chunk of the index sweep ({@see Index::sweep()} for why it
	 * is chunked). Called by the daily poll, by the REST "Check now", and by
	 * the continuation event; when a chunk leaves work pending, the next
	 * continuation is scheduled here — so no single web request ever carries
	 * the whole watchlist.
	 *
	 * @param float $budget Seconds this chunk may spend inspecting.
	 * @return array|null The stored payload, or null when not connected.
	 */
	/**
	 * One live inspection, on the owner's click. Same auth path as the sweep,
	 * one URL instead of a queue, and no cron: this is never scheduled, because
	 * it exists to answer a question someone is asking right now.
	 *
	 * @param string $url The URL to inspect.
	 * @return array{status:string,row:?array,error:string}
	 */
	public function inspect_one( $url ) {
		$sa_json  = $this->settings->sa_json();
		$property = (string) $this->settings->get( 'property', '' );
		if ( '' === $sa_json || '' === $property ) {
			return array( 'status' => 'error', 'row' => null, 'error' => __( 'Google Search Console is not connected.', 'agentimus' ) );
		}

		$auth = Auth::token( $sa_json );
		if ( isset( $auth['error'] ) ) {
			return array( 'status' => 'error', 'row' => null, 'error' => (string) $auth['error'] );
		}

		return Index::inspect_now( $this->client, $auth['token'], $property, $url );
	}

	public function run_index_sweep( $budget = 6.0 ) {
		$sa_json  = $this->settings->sa_json();
		$property = (string) $this->settings->get( 'property', '' );
		if ( '' === $sa_json || '' === $property ) {
			return null;
		}

		$auth = Auth::token( $sa_json );
		if ( isset( $auth['error'] ) ) {
			// A dead token fails every inspection identically — record once, keep
			// the last good answers.
			$stored          = Index::stored();
			$stored['error'] = (string) $auth['error'];
			update_option( Index::OPTION, $stored, false );
			return $stored;
		}

		// The registered-sitemaps snapshot rides the sweep, refreshed once per
		// RUN, not per chunk — one cheap GET answering the question no on-site
		// check can: does Google still fetch what the owner once registered?
		if ( ! Index::run_in_flight() ) {
			Index::store_sitemaps( $this->client->sitemaps( $auth['token'], $property ) );
		}

		$out = Index::sweep( $this->client, $auth['token'], $property, Index::run_targets(), $budget );

		if ( ! empty( $out['queue'] ) && '' === (string) $out['error'] && ! wp_next_scheduled( self::CRON_INDEX ) ) {
			// A safety net, not the fast path: the panel's own polling loop
			// usually finishes the run in seconds; this picks it up if the
			// owner closed the tab mid-sweep (or the daily cron started it).
			// A silent blip (a slow answer shy of Index::BLIP_LIMIT) keeps the
			// net armed — the run should heal on its own. After a LOUD pause
			// the net stands down: a one-minute retry against a dead network
			// or a dead token is a loop, not a net. That queue waits for the
			// owner's next panel visit or the daily sweep.
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_INDEX );
		}
		return $out;
	}

	/**
	 * The continuation event's handler.
	 *
	 * @return void
	 */
	public function index_chunk() {
		$this->run_index_sweep( 15.0 );
	}

	/**
	 * Match this site against the properties a key can read. Preference order:
	 * the exact URL-prefix property for this origin, then the sc-domain
	 * property covering this host — the closest claim wins. Unverified
	 * permission levels don't count as a match.
	 *
	 * @param array $sites Rows from {@see Client::sites()}.
	 * @return string The property string, or '' when nothing matches.
	 */
	public static function match_property( array $sites ) {
		$home = trailingslashit( (string) home_url( '/' ) );
		$host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$bare = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;

		$domain_match = '';
		foreach ( $sites as $site ) {
			$property   = (string) ( $site['property'] ?? '' );
			$permission = (string) ( $site['permission'] ?? '' );
			if ( '' === $property || 'siteUnverifiedUser' === $permission ) {
				continue;
			}
			if ( 0 === strpos( $property, 'sc-domain:' ) ) {
				$prop_host = strtolower( substr( $property, 10 ) );
				if ( $prop_host === $bare || $prop_host === $host ) {
					$domain_match = $property;
				}
				continue;
			}
			$prop_url  = trailingslashit( $property );
			$prop_host = strtolower( (string) wp_parse_url( $prop_url, PHP_URL_HOST ) );
			if ( $prop_url === $home || $prop_host === $host ) {
				return $property; // The exact origin claim — the best possible match.
			}
		}
		return $domain_match;
	}
}
