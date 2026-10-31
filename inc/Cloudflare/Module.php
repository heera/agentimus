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
		// An unblock, an allow or the switch going off must reach Cloudflare now.
		add_action( 'agentimus_settings_saved', array( $this, 'reconcile_edge' ) );
		add_action( 'agentimus_settings_reset', array( $this, 'reconcile_edge' ) );
	}

	/**
	 * Take names out of the Cloudflare rule that should no longer be on it. Fresh
	 * stores: this object is shared and its copy holds whatever it first read.
	 *
	 * @return void
	 */
	public function reconcile_edge() {
		( new EdgeBlock( new Settings(), new \Agentimus\Settings(), $this->client ) )->reconcile();
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
		// First, so a failed read below cannot hold up a removal that is owed.
		( new EdgeBlock( new Settings(), new \Agentimus\Settings(), $this->client ) )->reconcile( true );

		$token = $this->settings->token();
		$zone  = (string) $this->settings->get( 'zone_id', '' );
		if ( '' === $token || '' === $zone ) {
			return;
		}

		$now    = time();
		$latest = Table::latest_hour();
		$since  = $latest > 0 ? $latest - self::OVERLAP_SECONDS : $now - self::BACKFILL_SECONDS;
		$since  = max( $since, $now - self::BACKFILL_SECONDS );
		// ⛔ An hour stored before probes were counted keeps saying "no probes"
		// until it is re-read — and the overlap only re-reads two hours, so a
		// scanner's morning would go on firing the warning for a day and
		// inflating the week for seven. Once, re-read everything Cloudflare
		// still holds; a re-read hour replaces its row.
		$backfill = ! get_option( Table::PROBES_OPTION );
		if ( $backfill ) {
			$since = $now - self::BACKFILL_SECONDS;
		}

		while ( $since < $now ) {
			$until = min( $since + self::CHUNK_SECONDS, $now );
			$out   = $this->client->hourly_traffic( $token, $zone, $since, $until );
			if ( ! isset( $out['error'] ) ) {
				$robots = $this->client->robots_fetches( $token, $zone, $since, $until );
				// Without the robots reads, `served` would count them as pages — so
				// the hour is not stored half-known; it waits for the next poll.
				if ( isset( $robots['error'] ) ) {
					$out = $robots;
				}
			}
			if ( ! isset( $out['error'] ) ) {
				// Same rule for the blocked paths: without them every scanner probe
				// would be stored as a reader turned away.
				$paths = $this->client->blocked_paths( $token, $zone, $since, $until );
				if ( isset( $paths['error'] ) ) {
					$out = $paths;
				}
			}
			if ( isset( $out['error'] ) ) {
				// Keep the last good data; the panel shows the numbers' age and this note.
				$this->settings->record_poll( (string) $out['error'] );
				return;
			}
			Table::upsert( self::aggregate( (array) $out['rows'], (array) $robots['rows'], (array) $paths['rows'] ) );
			$since = $until;
		}

		$this->settings->record_poll( '' );
		if ( $backfill ) {
			update_option( Table::PROBES_OPTION, 1, false );
		}
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
	 * Across those buckets, `served` counts the requests that got a page: a 2xx
	 * the edge let through, less the /robots.txt reads. ⛔ Passing the edge is
	 * not taking content — a trainer reading robots.txt is reading the very line
	 * that says no, and a scanner wearing a trainer's name that the origin
	 * answers 403 or 404 took nothing. The training notice counts this.
	 *
	 * Within `blocked`, `probes` counts the requests for a path no reader asks
	 * for ({@see is_probe_path()}). ⛔⛔ A BLOCKED PROBE IS NOT A READER TURNED
	 * AWAY. On heera.it (2026-10-17) "Cloudflare is blocking Anthropic" and
	 * "…You.com" were one scanner on Google Cloud asking for .env files under
	 * both names, stopped by his own bad-paths rule — the third time after
	 * OpenAI (08-29, 09-17). Neither company publishes addresses to check, so
	 * the spoof check could only say "unknown"; the path says it plainly.
	 *
	 * @param array $raw    Rows from Client::hourly_traffic().
	 * @param array $robots Rows from Client::robots_fetches() for the same window.
	 * @param array $paths  Rows from Client::blocked_paths() for the same window.
	 * @return array<int,array> Rows shaped for Table::upsert().
	 */
	public static function aggregate( array $raw, array $robots = array(), array $paths = array() ) {
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
					'probes'   => 0,
					'served'   => 0,
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
			if ( ! $edge_refused && self::is_page( $row ) ) {
				$buckets[ $key ]['served'] += $n;
			}
		}

		foreach ( $robots as $row ) {
			$key = self::hour_key( (string) $row['hour'] ) . '|' . self::crawler_token( (string) $row['ua'] );
			if ( isset( $buckets[ $key ] ) && self::is_page( $row ) ) {
				$buckets[ $key ]['served'] = max( 0, $buckets[ $key ]['served'] - (int) $row['requests'] );
			}
		}

		foreach ( $paths as $row ) {
			$key = self::hour_key( (string) $row['hour'] ) . '|' . self::crawler_token( (string) $row['ua'] );
			if ( isset( $buckets[ $key ] ) && self::is_probe_path( (string) $row['path'] ) ) {
				$buckets[ $key ]['probes'] += (int) $row['requests'];
			}
		}
		// Two reads of the same window can disagree by a few requests; a probe
		// count is a share of `blocked` and never more than it.
		foreach ( $buckets as $key => $bucket ) {
			$buckets[ $key ]['probes'] = min( $bucket['probes'], $bucket['blocked'] );
		}

		return array_values( $buckets );
	}

	/**
	 * Whether a request path is a scanner's probe — a secret or a config file
	 * that no reader of the site, human or AI, ever asks for.
	 *
	 * Deliberately narrow: hidden files and folders (/.env, /.git/config,
	 * /.aws/credentials — every one except /.well-known/), anything ending in
	 * .env, wp-config.* copies, database dumps and editor backups, phpinfo, and
	 * Vite's /@fs/ file-read hole. An ordinary page, a feed or robots.txt is
	 * never a probe. Decoded first, so /admin%2F.env is caught like /admin/.env.
	 *
	 * @param string $path Request path, as Cloudflare reports it.
	 * @return bool
	 */
	public static function is_probe_path( $path ) {
		$path = strtolower( (string) $path );
		for ( $i = 0; $i < 2 && false !== strpos( $path, '%' ); $i++ ) {
			$path = rawurldecode( $path );
		}
		$path = (string) strtok( $path, '?' );

		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || '.well-known' === $segment ) {
				continue;
			}
			if ( '.' === $segment[0] || '@fs' === $segment || 0 === strpos( $segment, 'wp-config' ) || 0 === strpos( $segment, 'phpinfo' ) ) {
				return true;
			}
			if ( preg_match( '/\.(env|sql|bak|swp)$/', $segment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a raw row is a successful response — the edge's own status, so a
	 * cache hit counts the same as an origin answer.
	 *
	 * @param array $row A raw row.
	 * @return bool
	 */
	private static function is_page( array $row ) {
		$status = (int) $row['edge_status'];
		return $status >= 200 && $status < 300;
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
