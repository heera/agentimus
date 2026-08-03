<?php
/**
 * Bing module — the daily poll that copies Bing's crawl statistics into the
 * site's own table, the schedule that runs it, and the verification tag the
 * site prints so Bing can confirm ownership.
 *
 * One HTTPS request per poll to Bing's Webmaster API, nothing else. The whole
 * feature stands down (no cron, no queries) until a key is connected.
 * Fail-open throughout — an API error records itself and keeps the last good
 * data.
 *
 * @package Agentimus
 */

namespace Agentimus\Bing;

defined( 'ABSPATH' ) || exit;

final class Module {

	/** @var string The recurring poll event. */
	const CRON = 'agentimus_bing_poll';

	/** @var string Option-backed run lock — an option, not a transient, so it works without a persistent object cache. */
	const LOCK_OPTION = 'agentimus_bing_lock';

	/** @var int A poll is one HTTP call; a lock older than this is a died run to steal. */
	const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/** @var Settings */
	private $settings;

	/** @var Client */
	private $client;

	/**
	 * @param Settings    $settings The Bing connection store.
	 * @param Client|null $client   Injectable for tests.
	 */
	public function __construct( Settings $settings, Client $client = null ) {
		$this->settings = $settings;
		$this->client   = $client ? $client : new Client();
	}

	/**
	 * Hooks only — no work in the constructor.
	 *
	 * @return void
	 */
	public function register() {
		Table::maybe_install();
		\Agentimus\Search\Table::maybe_install();
		add_action( self::CRON, array( $this, 'poll' ) );
		add_action( 'admin_init', array( $this, 'sync_schedule' ) );
		// The verification tag: printed whenever a code is stored, so "click
		// Verify" works in Bing's dashboard and through our VerifySite call
		// alike. Early in the head, like the other identity tags.
		add_action( 'wp_head', array( $this, 'print_verification_tag' ), 1 );
	}

	/**
	 * The msvalidate.01 meta tag, when the owner has pasted a code.
	 *
	 * @return void
	 */
	public function print_verification_tag() {
		$code = (string) $this->settings->get( 'msvalidate', '' );
		if ( '' === $code ) {
			return;
		}
		echo '<meta name="msvalidate.01" content="' . esc_attr( $code ) . '" />' . "\n";
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
		$this->maybe_backfill_queries();
	}

	/** @var string Marks the one-time query-stats backfill as done. */
	const BACKFILL_OPTION = 'agentimus_bing_query_backfill';

	/**
	 * Query stats arrived after Bing did: a site that connected Bing BEFORE this
	 * feature existed has crawl numbers but no query rows, and would show
	 * "connected, no numbers yet" until the next daily poll — a whole day of
	 * looking broken to someone who just updated. Schedule one catch-up poll,
	 * once ever, a minute after the owner next opens wp-admin.
	 *
	 * @return void
	 */
	private function maybe_backfill_queries() {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}
		// Nothing to catch up on when rows already exist (a fresh connect polls
		// inline) — mark it done and never look again.
		if ( \Agentimus\Search\Table::has_rows( 'bing' ) ) {
			update_option( self::BACKFILL_OPTION, 1, false );
			return;
		}
		update_option( self::BACKFILL_OPTION, 1, false );
		if ( ! wp_next_scheduled( self::CRON ) || wp_next_scheduled( self::CRON ) > time() + MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON );
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
		if ( ! self::acquire_lock() ) {
			return;
		}
		try {
			$this->run_poll();
		} finally {
			self::release_lock();
		}
	}

	/**
	 * One poll, also callable inline right after connect so the panel has
	 * numbers immediately instead of "come back tomorrow". Bing returns its
	 * whole recent window each time; a re-polled day replaces its row.
	 *
	 * @return void
	 */
	public function run_poll() {
		$key  = $this->settings->api_key();
		$site = (string) $this->settings->get( 'site_url', '' );
		if ( '' === $key || '' === $site ) {
			return;
		}

		$out = $this->client->crawl_stats( $key, $site );
		if ( isset( $out['error'] ) ) {
			// Keep the last good data; the card shows the numbers' age and this note.
			$this->settings->record_poll( (string) $out['error'] );
		} else {
			Table::upsert( (array) $out['rows'] );
			$this->settings->record_poll( '' );
			Table::prune();
		}

		// The query-stats half runs regardless of how the crawl half fared, and
		// records its outcome on its own line — two datasets, two honest statuses.
		$this->run_query_poll( $key, $site );

		// The sitemap snapshot — one cheap call. Fail-open: on error the stored
		// snapshot stands (it carries Bing's own dates, so it stays honest).
		$feeds = $this->client->feeds( $key, $site );
		if ( ! isset( $feeds['error'] ) ) {
			$this->settings->record_feeds( (array) $feeds['rows'] );
		}
	}

	/** @var int Per-page query breakdowns are fetched for this many top pages — bounded work, one poll a day. */
	const QUERY_TOP_PAGES = 10;

	/**
	 * Snapshot Bing's query performance: the site-wide query list (feeds the
	 * site's own CTR median), plus per-page query breakdowns for the top pages
	 * by impressions (the rows that can name the page to fix). At most
	 * 2 + QUERY_TOP_PAGES HTTP calls, once a day. Fail-open: any error keeps
	 * the previous snapshot and records itself.
	 *
	 * @param string $key  API key, plaintext.
	 * @param string $site WMT site URL.
	 * @return void
	 */
	public function run_query_poll( $key, $site ) {
		$rows = array();

		// 1. Site-wide queries — median material, no page attribution here.
		$site_wide = $this->client->query_stats( $key, $site );
		if ( isset( $site_wide['error'] ) ) {
			$this->settings->record_query_poll( (string) $site_wide['error'] );
			return;
		}
		foreach ( (array) $site_wide['rows'] as $row ) {
			$rows[] = array(
				'query'       => $row['key'],
				'page_url'    => '',
				'page_id'     => 0,
				'clicks'      => $row['clicks'],
				'impressions' => $row['impressions'],
				'position'    => $row['position'],
			);
		}

		// 2. Top pages, then each page's own queries.
		$pages = $this->client->page_stats( $key, $site );
		if ( isset( $pages['error'] ) ) {
			$this->settings->record_query_poll( (string) $pages['error'] );
			return;
		}
		$page_rows = (array) $pages['rows'];
		usort( $page_rows, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );
		// Budgeted like the Google sweep learned to be: this loop can run
		// INSIDE a web request (connect / Refresh), and ~10 sequential calls
		// against a slow morning is a held FPM worker racing the gateway
		// timeout. Past the deadline the remaining pages simply skip their
		// per-page detail this poll — the site-wide numbers above are already
		// stored, and tomorrow's cron (no budget pressure) fills the rest.
		$deadline = microtime( true ) + 20.0;
		foreach ( array_slice( $page_rows, 0, self::QUERY_TOP_PAGES ) as $page ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$per = $this->client->page_query_stats( $key, $site, $page['key'] );
			if ( isset( $per['error'] ) ) {
				continue; // One page's breakdown failing must not sink the snapshot.
			}
			$page_id = \Agentimus\Search\Pages::resolve( $page['key'] );
			foreach ( (array) $per['rows'] as $row ) {
				$rows[] = array(
					'query'       => $row['key'],
					'page_url'    => $page['key'],
					'page_id'     => $page_id,
					'clicks'      => $row['clicks'],
					'impressions' => $row['impressions'],
					'position'    => $row['position'],
				);
			}
		}

		\Agentimus\Search\Table::replace( 'bing', $rows );
		$this->settings->record_query_poll( '' );
	}

	/**
	 * Take the run lock, or report it held.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && ( time() - $held ) < self::LOCK_TTL ) {
			return false;
		}
		update_option( self::LOCK_OPTION, time(), false );
		return true;
	}

	/**
	 * Release the run lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}
}
