<?php
/**
 * Agent Access event table — a rollup store for the machine-credential and ability
 * events described in {@see Events}. Separate from the activity log on purpose: that
 * table is keyed by User-Agent and endpoint ("who READ my agent surface"), this one is
 * keyed by credential and subject ("who ACTED on it"). They cannot share a schema.
 *
 * DELIBERATELY NO IP COLUMN, and no hash of one. Agentimus stores no personal data by
 * default (see Activity\Recorder), and location-newness is the source of every noisy
 * false positive a feature like this can produce (rotating cloud IPs, CGNAT, mobile vs
 * wifi). Dropping it means every event we emit is one a human actually wants to see, and
 * it keeps this feature out of the privacy declaration entirely.
 *
 * @package Agentimus
 */

namespace Agentimus\AgentAccess;

defined( 'ABSPATH' ) || exit;

final class Table {

	/** Bump when the schema changes to trigger a dbDelta upgrade. */
	const VERSION        = '1';
	const VERSION_OPTION = 'agentimus_agent_access_db_version';

	/**
	 * Fully-qualified table name (site-prefixed).
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_agent_events';
	}

	/**
	 * Create/upgrade the table only when the stored schema version differs — cheap to call
	 * on every boot (one option read).
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
		//
		// `cred` is the application-password UUID — an identifier for the key, NEVER the key
		// itself (WordPress only ever hands us the hash and the UUID). `subject` is the ability
		// name or the password's human label.
		//
		// The UNIQUE key is what makes this a ROLLUP: a repeat of the same (kind, user, credential,
		// subject) bumps `hits` and `last_at` instead of inserting. So the owner is alerted the
		// first time a credential uses an ability, and the row then quietly accrues a usage count
		// as a receipt — bounded at roughly abilities × credentials rather than growing per call.
		$sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(32) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cred varchar(64) NOT NULL DEFAULT '',
  subject varchar(191) NOT NULL DEFAULT '',
  detail varchar(191) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 1,
  first_at datetime NOT NULL,
  last_at datetime NOT NULL,
  seen tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY event (kind,user_id,cred,subject(100)),
  KEY last_at (last_at),
  KEY seen (seen)
) $collate;";

		dbDelta( $sql );

		// Belt-and-suspenders, same reasoning as Activity\Table: dbDelta can silently skip an
		// ADD COLUMN on a drifted table, and we would still stamp the version below — leaving
		// every query that selects the column failing forever with no retry.
		self::ensure_column( 'detail', "varchar(191) NOT NULL DEFAULT ''" );
		self::ensure_column( 'seen', 'tinyint(1) NOT NULL DEFAULT 0' );

		update_option( self::VERSION_OPTION, self::VERSION ); // Autoloaded: read on every boot by maybe_install(), so it belongs in the single alloptions load.
	}

	/**
	 * Add a column when it is missing — a guard against dbDelta silently skipping an ADD COLUMN
	 * on a drifted table. Idempotent. Column name and definition are our own literals, never
	 * user input.
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

}
