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
			return;
		}

		Table::upsert( (array) $out['rows'] );
		$this->settings->record_poll( '' );
		Table::prune();
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
