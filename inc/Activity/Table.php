<?php
/**
 * Activity log table — a dedicated, low-overhead store for agent hits on the
 * discovery / llms endpoints. A custom table (single INSERT per hit) is the
 * right tool for append-heavy event logging: no option read-modify-write race,
 * and it queries cleanly for the dashboard.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

defined( 'ABSPATH' ) || exit;

final class Table {

	/** Bump when the schema changes to trigger a dbDelta upgrade. */
	const VERSION        = '6';
	const VERSION_OPTION = 'agentimus_activity_db_version';

	/**
	 * Fully-qualified table name (site-prefixed).
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_agent_hits';
	}

	/**
	 * Create/upgrade the table only when the stored schema version differs —
	 * cheap to call on every boot (one option read).
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

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, lowercase types.
		// `verdict` records the live forward-confirmed reverse-DNS result for a UA that
		// claimed a verifiable search engine, captured at hit-time and stored WITHOUT the
		// IP that produced it (see Recorder): 0 = unchecked/inconclusive, 1 = verified,
		// 2 = spoofed (conclusively not that engine). `network` is the opt-in "identify every
		// bot" attribution — the owning registrable domain (e.g. amazonaws.com), org-level and
		// NOT the IP. Both columns are added by dbDelta on upgrade.
		$sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  endpoint varchar(64) NOT NULL DEFAULT '',
  agent varchar(64) NOT NULL DEFAULT '',
  ua varchar(255) NOT NULL DEFAULT '',
  verdict tinyint(1) NOT NULL DEFAULT 0,
  network varchar(128) NOT NULL DEFAULT '',
  signer varchar(64) NOT NULL DEFAULT '',
  refused tinyint(1) NOT NULL DEFAULT 0,
  hit_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY hit_at (hit_at),
  KEY endpoint (endpoint),
  KEY agent (agent),
  KEY ua (ua(191))
) $collate;";

		dbDelta( $sql );

		// Belt-and-suspenders. dbDelta can silently skip an ADD COLUMN on a table whose schema
		// has drifted (a leftover column from a removed feature is enough to confuse it) — and
		// we'd still stamp the version below, leaving every query that selects the new column
		// failing forever with no retry. Guarantee the columns dbDelta was meant to add really
		// exist BEFORE recording the version.
		self::ensure_column( 'verdict', 'tinyint(1) NOT NULL DEFAULT 0' );
		self::ensure_column( 'network', "varchar(128) NOT NULL DEFAULT ''" );
		self::ensure_column( 'signer', "varchar(64) NOT NULL DEFAULT ''" );
		self::ensure_column( 'refused', 'tinyint(1) NOT NULL DEFAULT 0' ); // 1 = turned away at the door, never served. Excluded from every "what was read" total.

		update_option( self::VERSION_OPTION, self::VERSION ); // Autoloaded: read on every boot by maybe_install(), so it belongs in the single alloptions load.
	}

	/**
	 * Add a column when it is missing — a guard against dbDelta silently skipping an ADD COLUMN
	 * on a drifted table. Idempotent (no-op when the column already exists). Column name and
	 * definition are our own literals, never user input.
	 *
	 * @param string $column     Column name.
	 * @param string $definition Column definition SQL.
	 * @return void
	 */
	private static function ensure_column( $column, $definition ) {
		global $wpdb;
		$table  = self::name();
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the LIKE value is bound.
		if ( $exists ) {
			return;
		}
		$wpdb->query( "ALTER TABLE $table ADD COLUMN $column $definition" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table, $column and $definition are our own literals, not user input.
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
