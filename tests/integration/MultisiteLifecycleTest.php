<?php
/**
 * Multisite lifecycle: a sub-site created while Agentimus is network-active gets its
 * OWN tables via Plugin::install_site() (the wp_initialize_site path). Runs only in a
 * multisite install (WP_MULTISITE=1); self-skips otherwise.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Plugin;
use Agentimus\Activity\Table as ActivityTable;
use Agentimus\AgentAccess\Table as AgentAccessTable;

final class MultisiteLifecycleTest extends DbTestCase {

	public function set_up(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs only in a multisite install (WP_MULTISITE=1).' );
		}
		parent::set_up();
	}

	public function test_a_new_subsite_gets_its_own_tables_via_install_site() {
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );

		global $wpdb;
		// Every custom table the plugin owns, keyed by the option that gates its install. A table
		// missing here is a table no one checks reaches a new sub-site — and on a network, a
		// missing table is a fatal query on that site's very first request.
		$tables = array(
			ActivityTable::name()    => ActivityTable::VERSION_OPTION,
			AgentAccessTable::name() => AgentAccessTable::VERSION_OPTION,
		);
		foreach ( $tables as $table => $version_option ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
			delete_option( $version_option );
		}

		Plugin::install_site(); // exactly what wp_initialize_site runs for a new sub-site.

		$exists = array();
		foreach ( array_keys( $tables ) as $table ) {
			$exists[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
		restore_current_blog();

		foreach ( $exists as $table => $found ) {
			$this->assertTrue( $found, "a newly-created sub-site is given its own $table" );
		}
	}
}
