<?php
/**
 * GET /agentimus/v1/activity/log — the request-log endpoint's HTTP contract: validation
 * rejects malformed input with a 400 rather than silently dropping the filter, and the
 * payload carries the paging + retention metadata the UI needs to describe its own limits.
 *
 * The permission surface itself is covered by {@see RestPermissionTest}, which dispatches
 * every registered route as a non-admin.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Table;

final class ActivityLogRestTest extends RestTestCase {

	const ROUTE = '/agentimus/v1/activity/log';

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		wp_set_current_user( $this->admin );
	}

	private function get( array $params = array() ) {
		$request = new \WP_REST_Request( 'GET', self::ROUTE );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function hit( $agent = 'GPTBot', $ua = 'GPTBot/1.0' ) {
		global $wpdb;
		$wpdb->insert(
			Table::name(),
			array(
				'endpoint' => 'discovery.json',
				'agent'    => $agent,
				'ua'       => $ua,
				'hit_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	public function test_admin_gets_the_paging_and_retention_envelope() {
		$this->hit();

		$resp = $this->get();
		$this->assertSame( 200, $resp->get_status() );

		$data = (array) $resp->get_data();
		foreach ( array( 'rows', 'total', 'perPage', 'cursor', 'hasMore', 'retentionDays' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "the payload must carry `$key`" );
		}
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 30, $data['retentionDays'] );
		$this->assertFalse( $data['hasMore'] );
	}

	/**
	 * A typo'd date must be a 400. If it were ignored, the endpoint would quietly return the
	 * whole window and the operator would read it as "no requests that day".
	 */
	public function test_a_malformed_date_is_rejected_not_ignored() {
		foreach ( array( '2026-7-9', '09-07-2026', 'yesterday', '2026-07-09T00:00:00' ) as $bad ) {
			$resp = $this->get( array( 'from' => $bad ) );
			$this->assertSame( 400, $resp->get_status(), "`$bad` must not be accepted as a date" );
			$this->assertSame( 'agentimus_bad_date', $resp->get_data()['code'] );
		}
	}

	public function test_an_inverted_date_range_is_rejected() {
		$resp = $this->get( array( 'from' => '2026-07-09', 'to' => '2026-07-01' ) );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'agentimus_bad_range', $resp->get_data()['code'] );
	}

	public function test_an_out_of_range_verdict_is_rejected() {
		$this->assertSame( 400, $this->get( array( 'verdict' => 7 ) )->get_status() );
		$this->assertSame( 400, $this->get( array( 'verdict' => -1 ) )->get_status() );
		// 0, 1 and 2 are the real domain (unchecked / verified / spoofed).
		foreach ( array( 0, 1, 2 ) as $ok ) {
			$this->assertSame( 200, $this->get( array( 'verdict' => $ok ) )->get_status() );
		}
	}

	/** per_page is clamped, not trusted: a caller cannot ask for the whole table. */
	public function test_per_page_is_clamped() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->hit( 'GPTBot', 'UA-' . $i );
		}

		$this->assertSame( 200, $this->get( array( 'per_page' => 100000 ) )->get_data()['perPage'] );
		$this->assertSame( 1, $this->get( array( 'per_page' => 0 ) )->get_data()['perPage'] );
	}

	/** The cursor walks backwards and the endpoint reports when it has run out. */
	public function test_cursor_paging_over_http() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->hit( 'GPTBot', 'UA-' . $i );
		}

		$first = $this->get( array( 'per_page' => 2 ) )->get_data();
		$this->assertTrue( $first['hasMore'] );
		$this->assertNotNull( $first['cursor'] );

		$second = $this->get( array( 'per_page' => 2, 'before' => $first['cursor'] ) )->get_data();
		$this->assertFalse( $second['hasMore'] );
		$this->assertNull( $second['cursor'] );
		$this->assertSame( 3, $second['total'], 'total stays the size of the filtered set' );
	}
}
