<?php
/**
 * UnknownSources — the diagnostic that makes {@see Referrals}' blind spots visible.
 *
 * A missed AI referral leaves NO trace: nothing in the referral store distinguishes
 * "no assistant sent anyone" from "an assistant sent readers and we didn't recognise
 * the source". The recognised-source map is a finite list against a field that keeps
 * growing, so the gap is real and unmeasurable from the dashboard alone.
 *
 * So when a human browser arrives from somewhere we do NOT recognise, this records the
 * bare signal — the external referrer host, and/or the utm_source token — and nothing
 * else. Read the resulting list, spot an assistant that should have counted, and add it
 * with the `agentimus_ai_referral_sources` filter.
 *
 * OPT-IN, and off by default, for one honest reason: unlike the referral counter (which
 * writes only on the rare AI-referred visit), this would write on EVERY externally
 * referred page view. It is a diagnostic to switch on for a week, not a permanent log.
 *
 * Privacy: identical stance to {@see Referrals}. A per-day count per (kind, token) — no
 * IP, no User-Agent, no path, no query string beyond the utm_source tag itself. No stored
 * row represents a person, and the site's own pages are never recorded as referrers.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class UnknownSources {

	/** Bump when the schema changes to trigger a dbDelta upgrade. */
	const VERSION        = '1';
	const VERSION_OPTION = 'agentimus_unknown_sources_db_version';

	/** Rows shown per list on the dashboard. */
	const TOP = 12;

	/** A utm_source longer than this is not a source tag, it's a payload. */
	const MAX_TOKEN = 60;

	/** Hard ceiling on stored (day, kind, token) rows. Far smaller than the referral
	 *  store's: this one is fed by ordinary traffic and by anyone who can put a string
	 *  in `?utm_source=`, so the long tail is the whole risk. The trim keeps the busiest
	 *  rows — which are exactly the ones worth reading. Filter
	 *  `agentimus_unknown_sources_max_rows` (0 disables the cap). */
	const MAX_ROWS = 2000;

	/** Odds (1-in-N) that an upsert also runs the bounded row-cap trim. Tighter than the
	 *  referral store's because this table fills faster. */
	const CAP_CHECK_ODDS = 50;

	/**
	 * Fully-qualified table name (site-prefixed).
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_unknown_sources';
	}

	/**
	 * Whether the diagnostic is switched on. Requires the activity log itself, since this
	 * records nothing the log doesn't already see.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = new Settings();
		return (bool) $settings->enabled( 'enable_activity' ) && (bool) $settings->enabled( 'log_unknown_referrers' );
	}

	/* ---------------------------------------------------------------------- *
	 *  Recording
	 * ---------------------------------------------------------------------- */

	/**
	 * Note a visit that {@see Referrals::source_for()} could not attribute. Called only
	 * for requests that already passed the owner and human-browser tests, so what lands
	 * here is a real reader arriving from somewhere unrecognised.
	 *
	 * Records up to two independent signals — they answer different questions ("which site
	 * sent them" vs "what did the link claim"), and an assistant that strips its referrer
	 * shows up in only one of them.
	 *
	 * @param string $referer Raw Referer header / document.referrer.
	 * @param string $utm     Raw utm_source value.
	 */
	public static function note( $referer, $utm ) {
		if ( ! self::enabled() ) {
			return;
		}

		$host = self::external_host( (string) $referer );
		if ( '' !== $host ) {
			self::increment( 'host', $host );
		}

		$token = self::clean_token( (string) $utm );
		if ( '' !== $token ) {
			self::increment( 'utm', $token );
		}
	}

	/**
	 * The referrer's host, if it is EXTERNAL — '' for no referrer, an unparseable one, or
	 * the site's own pages. Internal navigation carries a referrer on every click, so
	 * without this the table would fill with the site's own hostname and nothing else.
	 *
	 * @param string $referer Raw referrer.
	 * @return string Lowercased, www-stripped host, or ''.
	 */
	private static function external_host( $referer ) {
		$host = strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^www\./', '', $host );
		if ( '' === $host ) {
			return '';
		}
		$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$own = (string) preg_replace( '/^www\./', '', $own );
		if ( '' !== $own && ( $host === $own || Referrals::is_subdomain_of( $host, $own ) ) ) {
			return ''; // Our own pages: ordinary internal navigation, not a referrer at all.
		}
		return substr( $host, 0, 190 );
	}

	/**
	 * Normalise a utm_source into a storable token, or '' if it isn't one. The value is
	 * attacker-controlled (anyone can append `?utm_source=…`), so it is stripped of markup
	 * and control characters and hard length-capped before it ever reaches the table.
	 *
	 * @param string $utm Raw utm_source value.
	 * @return string
	 */
	private static function clean_token( $utm ) {
		$token = strtolower( trim( sanitize_text_field( $utm ) ) );
		$token = (string) preg_replace( '/^www\./', '', $token );
		return substr( $token, 0, self::MAX_TOKEN );
	}

	/**
	 * Increment the daily counter for (today UTC, kind, token).
	 *
	 * @param string $kind  'host' or 'utm'.
	 * @param string $token The host or utm_source value.
	 */
	private static function increment( $kind, $token ) {
		global $wpdb;
		$table = self::name();
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; values are bound via prepare().
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (day, kind, token, hits) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1",
				gmdate( 'Y-m-d' ),
				$kind,
				$token
			)
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( 1 === wp_rand( 1, self::CAP_CHECK_ODDS ) ) {
			self::trim_to_cap();
		}
	}

	/**
	 * Cap the table to the busiest {@see MAX_ROWS} rows. Same anti-join as the referral
	 * store's trim: materialise the "keep" set, delete everything outside it.
	 */
	public static function trim_to_cap() {
		$max = (int) apply_filters( 'agentimus_unknown_sources_max_rows', self::MAX_ROWS );
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
				"DELETE t FROM $table t LEFT JOIN ( SELECT id FROM $table ORDER BY hits DESC, id DESC LIMIT %d ) keep ON t.id = keep.id WHERE keep.id IS NULL",
				$max
			)
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/* ---------------------------------------------------------------------- *
	 *  Read (dashboard)
	 * ---------------------------------------------------------------------- */

	/**
	 * Dashboard payload: the busiest unrecognised hosts and utm tokens over the window.
	 * Returns an empty, disabled shell when the diagnostic is off — which is the common
	 * case, and costs no query.
	 *
	 * @param int $window Reporting window in days, counting today.
	 * @return array{enabled:bool,hosts:array,utm:array}
	 */
	public static function summary( $window ) {
		$window = max( 1, (int) $window );
		// Inclusive of today, exactly as Referrals::summary() counts its window.
		$since = gmdate( 'Y-m-d', time() - ( $window - 1 ) * DAY_IN_SECONDS );
		return self::report( $since, gmdate( 'Y-m-d' ) );
	}

	/**
	 * The same lists over an explicit day range, so the AI-traffic screen's diagnostic covers
	 * exactly the range its cards do. A card showing March beside a diagnostic showing the
	 * last 30 days would invite the wrong conclusion about what went unattributed.
	 *
	 * @param string $from Inclusive day (Y-m-d).
	 * @param string $to   Inclusive day (Y-m-d).
	 * @return array{enabled:bool,hosts:array,utm:array}
	 */
	public static function report( $from, $to ) {
		if ( ! self::enabled() ) {
			return array( 'enabled' => false, 'hosts' => array(), 'utm' => array() );
		}

		$table = self::name();
		return array(
			'enabled' => true,
			'hosts'   => self::top( $table, 'host', $from, $to ),
			'utm'     => self::top( $table, 'utm', $from, $to ),
		);
	}

	/**
	 * The busiest tokens of one kind over the range.
	 *
	 * @param string $table Table name.
	 * @param string $kind  'host' or 'utm'.
	 * @param string $from  Inclusive day (Y-m-d).
	 * @param string $to    Inclusive day (Y-m-d).
	 * @return array<int,array{token:string,hits:int}>
	 */
	private static function top( $table, $kind, $from, $to ) {
		global $wpdb;
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; all values are bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT token, SUM(hits) AS hits FROM $table WHERE kind = %s AND day >= %s AND day <= %s GROUP BY token ORDER BY hits DESC LIMIT %d",
				$kind,
				$from,
				$to,
				self::TOP
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array_map(
			static function ( $r ) {
				return array( 'token' => (string) $r['token'], 'hits' => (int) $r['hits'] );
			},
			(array) $rows
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Maintenance
	 * ---------------------------------------------------------------------- */

	/**
	 * Age out on the same clock, and the same switch, as the rest of the activity log;
	 * then enforce the cap regardless. Hooked to the daily prune cron.
	 */
	public static function prune() {
		global $wpdb;
		$table = self::name();

		if ( Repository::auto_prune() ) {
			$cutoff = gmdate( 'Y-m-d', time() - Repository::retention_days() * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE day < %s", $cutoff ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the value is bound via prepare().
		}

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
	 * Create the daily-aggregate table via dbDelta.
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
  kind varchar(4) NOT NULL DEFAULT 'host',
  token varchar(190) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq (day,kind,token(150)),
  KEY day (day)
) $collate;";

		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION ); // Autoloaded: read on every boot by maybe_install(), so it belongs in the single alloptions load.
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
