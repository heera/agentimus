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

	/** @var string Option-backed run lock. */
	const LOCK_OPTION = 'agentimus_google_lock';

	/** @var int A poll is two HTTP calls; a lock older than this is a died run to steal. */
	const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/** @var int The report window, in days. */
	const WINDOW_DAYS = 56;

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
		Search\Table::maybe_install();
		add_action( self::CRON, array( $this, 'poll' ) );
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

		Search\Table::replace( 'google', $rows );
		$this->settings->record_poll( '' );
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
