<?php
/**
 * Activity log table — a dedicated, low-overhead store for agent hits on the
 * discovery / llms endpoints. A custom table (single INSERT per hit) is the
 * right tool for append-heavy event logging: no option read-modify-write race,
 * and it queries cleanly for the dashboard.
 *
 * @package HeeraAgentDiscovery
 */

namespace HeeraAgentDiscovery\Activity;

defined( 'ABSPATH' ) || exit;

final class Table {

	/** Bump when the schema changes to trigger a dbDelta upgrade. */
	const VERSION        = '1';
	const VERSION_OPTION = 'heera_agent_discovery_activity_db_version';

	/**
	 * Fully-qualified table name (site-prefixed).
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'heera_agent_discovery_agent_hits';
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
		$sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  endpoint varchar(64) NOT NULL DEFAULT '',
  agent varchar(64) NOT NULL DEFAULT '',
  ua varchar(255) NOT NULL DEFAULT '',
  hit_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY hit_at (hit_at),
  KEY endpoint (endpoint),
  KEY agent (agent)
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
