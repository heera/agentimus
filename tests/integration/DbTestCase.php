<?php
/**
 * Base case for tests that exercise the plugin's OWN custom tables.
 *
 * WP_UnitTestCase wraps every test in a transaction and, as part of that, rewrites
 * `CREATE TABLE` / `DROP TABLE` into their TEMPORARY forms (see start_transaction()).
 * That is right for WordPress's core tables but wrong for ours: it turns a real
 * dbDelta create or a real DROP into a no-op on the real table, so a schema/lifecycle
 * assertion can never see the truth. We remove those two rewrites here; the tables are
 * instead isolated by truncating them in each test's set_up.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use WP_UnitTestCase;

abstract class DbTestCase extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}
}
