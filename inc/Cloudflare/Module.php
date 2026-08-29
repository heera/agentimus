<?php
/**
 * Cloudflare edge module — the hourly poll that copies Cloudflare's aggregate
 * numbers into the site's own table, and the schedule that runs it.
 *
 * One HTTPS request per window to Cloudflare's GraphQL Analytics API, nothing
 * else: no crawling, no third party, and the whole feature stands down (no
 * cron, no queries) until a token is connected. Fail-open throughout — an API
 * error records itself and keeps the last good data.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

use Agentimus\Activity\Catalog;
use Agentimus\PollLock;

defined( 'ABSPATH' ) || exit;

final class Module {

	use PollLock;

	/** @var string The recurring poll event. */
	const CRON = 'agentimus_edge_poll';

	/** @var string Option-backed run lock — an option, not a transient, so it works without a persistent object cache. */
	const LOCK_OPTION = 'agentimus_edge_lock';

	/** @var int A poll is a handful of HTTP calls; a lock older than this is a died run to steal. */
	const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/** @var int How far one GraphQL window reaches — bounds response size per call,
	 * and keeps the connect-time inline poll to three calls, not twelve. */
	const CHUNK_SECONDS = DAY_IN_SECONDS;

	/** @var int First-ever poll reaches back this far (Cloudflare Free keeps only days anyway). */
	const BACKFILL_SECONDS = 3 * DAY_IN_SECONDS;

	/** @var int Re-poll this much before the last stored hour — CF's aggregation lags a little. */
	const OVERLAP_SECONDS = 2 * HOUR_IN_SECONDS;

	/** @var Settings */
	private $settings;

	/** @var Client */
	private $client;

	/**
	 * @param Settings    $settings The Cloudflare connection store.
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
		Table::maybe_install();
		add_action( self::CRON, array( $this, 'poll' ) );
		// Reconcile the schedule from the admin, where a human just changed the
		// connection — cheap (one wp_next_scheduled read) and self-healing.
		add_action( 'admin_init', array( $this, 'sync_schedule' ) );
	}

	/**
	 * Keep the cron slot consistent with the connection state: scheduled while
	 * connected, absent while not. No-ops on the already-correct common case.
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
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/**
	 * Activation-time scheduling: only when a connection already exists (an
	 * upgrade or a reactivation) — a fresh install schedules nothing until the
	 * owner connects.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! ( new Settings() )->connected() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
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
	 * The cron handler: fetch the window since the last stored hour, aggregate
	 * per crawler, store, prune. Never overlaps itself.
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
	 * numbers immediately instead of "come back in an hour".
	 *
	 * @return void
	 */
	public function run_poll() {
		$token = $this->settings->token();
		$zone  = (string) $this->settings->get( 'zone_id', '' );
		if ( '' === $token || '' === $zone ) {
			return;
		}

		$now    = time();
		$latest = Table::latest_hour();
		$since  = $latest > 0 ? $latest - self::OVERLAP_SECONDS : $now - self::BACKFILL_SECONDS;
		$since  = max( $since, $now - self::BACKFILL_SECONDS );

		while ( $since < $now ) {
			$until = min( $since + self::CHUNK_SECONDS, $now );
			$out   = $this->client->hourly_traffic( $token, $zone, $since, $until );
			if ( isset( $out['error'] ) ) {
				// Keep the last good data; the panel shows the numbers' age and this note.
				$this->settings->record_poll( (string) $out['error'] );
				return;
			}
			Table::upsert( self::aggregate( (array) $out['rows'] ) );
			$since = $until;
		}

		$this->settings->record_poll( '' );
		Table::prune();

		// With fresh hours stored, ask whether the blocked traffic behind any
		// firing conflict is really its operator — the one moment this can run,
		// because reading the summary must stay a read of stored rows.
		SpoofCheck::run( $this->settings, new \Agentimus\Settings(), $this->client );
	}

	/**
	 * Collapse Cloudflare's raw (hour, UA, cache, status) rows into one row per
	 * (hour, known AI crawler). Unknown user agents are dropped — this panel is
	 * about AI crawlers, and the Catalog is the same registry the request log
	 * uses, so names line up across screens.
	 *
	 * Each request lands in exactly one bucket:
	 *  - blocked : the edge answered 403/429 without ever contacting the origin
	 *              (a challenge or block — the origin never saw it);
	 *  - cached  : served from Cloudflare's cache;
	 *  - origin  : everything else — the request reached this server.
	 *
	 * @param array $raw Rows from Client::hourly_traffic().
	 * @return array<int,array> Rows shaped for Table::upsert().
	 */
	public static function aggregate( array $raw ) {
		$cached_statuses = array( 'hit', 'stale', 'updating', 'revalidated' );
		$buckets         = array();

		foreach ( $raw as $row ) {
			$token = self::crawler_token( (string) $row['ua'] );
			if ( '' === $token ) {
				continue;
			}
			$hour = self::hour_key( (string) $row['hour'] );
			if ( '' === $hour ) {
				continue;
			}

			$key = $hour . '|' . $token;
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'hour_at'  => $hour,
					'ua'       => $token,
					'requests' => 0,
					'cached'   => 0,
					'origin'   => 0,
					'blocked'  => 0,
					'bytes'    => 0,
				);
			}

			$n = (int) $row['requests'];

			$buckets[ $key ]['requests'] += $n;
			$buckets[ $key ]['bytes']    += (int) $row['bytes'];

			$edge_refused = in_array( (int) $row['edge_status'], array( 403, 429 ), true ) && 0 === (int) $row['origin_status'];
			if ( $edge_refused ) {
				$buckets[ $key ]['blocked'] += $n;
			} elseif ( in_array( (string) $row['cache_status'], $cached_statuses, true ) ) {
				$buckets[ $key ]['cached'] += $n;
			} else {
				$buckets[ $key ]['origin'] += $n;
			}
		}

		return array_values( $buckets );
	}

	/**
	 * Match a raw User-Agent to a Catalog token — the same substring rule
	 * Catalog::identify() applies, but returning the stable token we store.
	 * Only AI-kind crawlers count; a search or SEO bot returns ''.
	 *
	 * @param string $ua Raw User-Agent.
	 * @return string The catalog token, or '' for no (AI) match.
	 */
	public static function crawler_token( $ua ) {
		$ua_lc = strtolower( (string) $ua );
		if ( '' === $ua_lc ) {
			return '';
		}
		foreach ( Catalog::known() as $token => $row ) {
			if ( false !== strpos( $ua_lc, (string) $token ) ) {
				return 'ai' === (string) $row[2] ? (string) $token : '';
			}
		}
		return '';
	}

	/**
	 * Normalize Cloudflare's datetimeHour (RFC3339, UTC) into the table's
	 * datetime format. '' when unparseable.
	 *
	 * @param string $hour e.g. "2026-07-30T14:00:00Z".
	 * @return string e.g. "2026-07-30 14:00:00".
	 */
	private static function hour_key( $hour ) {
		$ts = strtotime( $hour );
		return $ts ? gmdate( 'Y-m-d H:00:00', $ts ) : '';
	}

}
