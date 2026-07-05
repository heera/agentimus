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
		$table = ActivityTable::name(); // the sub-site's own prefixed table.
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
		delete_option( ActivityTable::VERSION_OPTION );

		Plugin::install_site(); // exactly what wp_initialize_site runs for a new sub-site.

		$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		restore_current_blog();

		$this->assertTrue( $exists, 'a newly-created sub-site is given its own activity table' );
	}
}
