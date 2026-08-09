<?php
/**
 * FlaggedIps — an OPT-IN, minimised store of source IP addresses for FLAGGED clients only
 * (an impersonator that failed reverse-DNS, or a legacy-device spoof). It exists so the
 * review card can show the actual addresses to block at a host/CDN — the one thing the
 * verdict-only design couldn't give.
 *
 * Deliberately narrow, for privacy and GDPR data-minimisation:
 *   - default OFF (a normal install stores no IP at all — see Settings::store_flagged_ips);
 *   - only ever populated for the security-relevant clients (never ordinary traffic);
 *   - keyed by the client's review identity (dismiss_key), deduped, capped per client,
 *     pruned on a short retention, cleared with the log, and PURGED when the owner opts
 *     back out. Its own table, so it can be dropped independently of the activity log.
 *
 * An IP is personal data; this keeps the footprint to the smallest defensible set (the
 * legitimate-interest basis is security / abuse-prevention, GDPR Recital 49).
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

defined( 'ABSPATH' ) || exit;

final class FlaggedIps {

	const VERSION        = '1';
	const VERSION_OPTION = 'agentimus_flagged_ips_db_version';

	/** Newest distinct IPs kept per client, and the number shown on a card. */
	const PER_CLIENT_MAX = 25;
	const DISPLAY_MAX    = 12;

	/** Whole-table backstop. The per-client cap bounds one client, but a flood
	 *  rotating review keys (a distinct ckey per spoofed token) makes each a fresh
	 *  bucket the per-client cap can't reach — so the table as a whole is bounded
	 *  too. Kept small: this is the only PII the plugin stores. Filter
	 *  `agentimus_flagged_ips_max_rows`. */
	const MAX_ROWS = 5000;

	/** Default retention for stored IPs, in days — shorter than the activity log's, as
	 *  this is the only PII the plugin ever keeps. Filter `agentimus_flagged_ip_retention_days`. */
	const RETENTION_DAYS = 14;

	/**
	 * Fully-qualified table name (site-prefixed).
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_flagged_ips';
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
	 * Create the table via dbDelta.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		// `ckey` is the client's review identity (Repository::dismiss_key): tok:<token> or
		// ua:<md5>. One row per (client, ip); `hits` counts repeats. dbDelta is whitespace-
		// sensitive: two spaces after PRIMARY KEY, lowercase types.
		$sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ckey varchar(64) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 1,
  first_at datetime NOT NULL,
  last_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ckey_ip (ckey,ip),
  KEY last_at (last_at)
) $collate;";

		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION ); // Autoloaded: read on every boot by maybe_install(), so it belongs in the single alloptions load.
	}

	/**
	 * Record one flagged-client IP: upsert (bump hits + last_at on a repeat), then keep the
	 * per-client set bounded. Silently no-ops on empty input.
	 *
	 * @param string $ckey Client review key (Repository::dismiss_key).
	 * @param string $ip   Source IP.
	 * @return void
	 */
	public static function record( $ckey, $ip ) {
		$ckey = substr( (string) $ckey, 0, 64 );
		$ip   = substr( trim( (string) $ip ), 0, 45 );
		if ( '' === $ckey || '' === $ip ) {
			return;
		}
		global $wpdb;
		$table = self::name();
		$now   = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; values are bound via prepare().
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (ckey, ip, hits, first_at, last_at) VALUES (%s, %s, 1, %s, %s)
				 ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = VALUES(last_at)",
				$ckey,
				$ip,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Opportunistic per-client cap (cheap rand on the common path).
		if ( 1 === wp_rand( 1, 8 ) ) {
			self::cap_client( $ckey );
		}
		// Whole-table backstop, sampled rarer (it runs a full-table COUNT): a flood
		// rotating ckeys sails past the per-client cap, so bound the table too. During
		// exactly that flood, record() runs often, so the rare sample still fires.
		if ( 1 === wp_rand( 1, 50 ) ) {
			self::trim_to_cap();
		}
	}

	/**
	 * Cap the whole table to the newest {@see MAX_ROWS} rows — the recency-first
	 * backstop the per-client cap can't provide across rotating clients. Same
	 * anti-join the sibling stores use; keyed on last_at to match this store's
	 * retention model (newest kept, oldest dropped).
	 *
	 * @return void
	 */
	public static function trim_to_cap() {
		$max = (int) apply_filters( 'agentimus_flagged_ips_max_rows', self::MAX_ROWS );
		if ( $max < 1 ) {
			return; // Cap disabled.
		}
		global $wpdb;
		$table = self::name();
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the value ($max) is bound via prepare().
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		if ( $count <= $max ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE t FROM $table t LEFT JOIN ( SELECT id FROM $table ORDER BY last_at DESC, id DESC LIMIT %d ) keep ON t.id = keep.id WHERE keep.id IS NULL",
				$max
			)
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Keep only the most-recently-seen {@see PER_CLIENT_MAX} IPs for one client.
	 *
	 * @param string $ckey Client review key.
	 * @return void
	 */
	private static function cap_client( $ckey ) {
		global $wpdb;
		$table = self::name();
		// Delete by ID against an explicit keep-list, NOT `last_at < cutoff`.
		//
		// `last_at` has one-second granularity, so when a client presents more than PER_CLIENT_MAX
		// addresses inside a single second every row ties with the cutoff, `< cutoff` matches
		// nothing, and the cap silently no-ops. That is not a corner case here: a distributed scan
		// hitting from many IPs at once is precisely the traffic that gets flagged, so the cap
		// failed exactly when it was needed. `id` is auto-increment and cannot tie.
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; values are bound via prepare().
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE ckey = %s AND id NOT IN (
					SELECT id FROM ( SELECT id FROM $table WHERE ckey = %s ORDER BY last_at DESC, id DESC LIMIT %d ) AS keep
				)",
				$ckey,
				$ckey,
				self::PER_CLIENT_MAX
			)
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * The captured IPs for a set of client keys, newest-first, capped for display.
	 *
	 * @param string[] $ckeys Client review keys.
	 * @return array<string,array<int,array{ip:string,hits:int,lastSeen:string}>> ckey => IP rows.
	 */
	public static function for_keys( array $ckeys ) {
		$ckeys = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $k ) {
							return substr( (string) $k, 0, 64 );
						},
						$ckeys
					)
				)
			)
		);
		if ( empty( $ckeys ) ) {
			return array();
		}
		global $wpdb;
		$table        = self::name();
		$placeholders = implode( ',', array_fill( 0, count( $ckeys ), '%s' ) );
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own name; the IN list is a run of bound %s placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT ckey, ip, hits, last_at FROM $table WHERE ckey IN ($placeholders) ORDER BY last_at DESC", ...$ckeys ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$out = array();
		foreach ( (array) $rows as $r ) {
			$k = (string) $r['ckey'];
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array();
			}
			if ( count( $out[ $k ] ) >= self::DISPLAY_MAX ) {
				continue;
			}
			$out[ $k ][] = array(
				'ip'       => (string) $r['ip'],
				'hits'     => (int) $r['hits'],
				'lastSeen' => gmdate( 'c', strtotime( $r['last_at'] . ' UTC' ) ),
			);
		}
		return $out;
	}

	/**
	 * Delete IPs older than the (filterable) short retention. Scheduled with the daily prune.
	 *
	 * @return void
	 */
	public static function prune() {
		if ( get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			return; // Table not installed on this site.
		}
		$days   = (int) apply_filters( 'agentimus_flagged_ip_retention_days', self::RETENTION_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		global $wpdb;
		$table = self::name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE last_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the value is bound via prepare().
		self::trim_to_cap(); // Daily backstop, independent of the write-time sampling.
	}

	/**
	 * Empty the store — used when the log is cleared, and when the owner opts back out of
	 * IP storage (don't keep data they just declined).
	 *
	 * @return void
	 */
	public static function clear() {
		if ( get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			return;
		}
		global $wpdb;
		$table = self::name();
		$wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- admin-gated truncation of our own prefix-derived table.
	}
}
