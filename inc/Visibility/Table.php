<?php
/**
 * The AI-visibility results table — one row per (prompt × provider) check within
 * a run. Append-heavy event data belongs in a dedicated table (single INSERT per
 * check, clean time-series queries) rather than an option, exactly as the free
 * core does for its agent-hit log.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

defined( 'ABSPATH' ) || exit;

final class Table {

	/** Bump when the schema changes to trigger a dbDelta upgrade. */
	const VERSION        = '1';
	const VERSION_OPTION = 'agentimus_visibility_db_version';

	/**
	 * Fully-qualified, site-prefixed table name. On multisite each site keeps its
	 * own table, so a network dashboard aggregates real per-site data.
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_visibility';
	}

	/**
	 * Create/upgrade only when the stored schema version differs — cheap to call
	 * on every boot.
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
		$sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL DEFAULT 0,
  checked_at datetime NOT NULL,
  provider varchar(32) NOT NULL DEFAULT '',
  model varchar(96) NOT NULL DEFAULT '',
  prompt_hash char(32) NOT NULL DEFAULT '',
  prompt text NOT NULL,
  mentioned tinyint(1) NOT NULL DEFAULT 0,
  cited tinyint(1) NOT NULL DEFAULT 0,
  position smallint(6) NOT NULL DEFAULT 0,
  competitors text NOT NULL,
  answer_excerpt text NOT NULL,
  error varchar(191) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY checked_at (checked_at),
  KEY provider (provider),
  KEY prompt_hash (prompt_hash)
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
