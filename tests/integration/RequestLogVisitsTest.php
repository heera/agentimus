<?php
/**
 * GET /activity/log?group=visits and read-request-log's `group` — the request log
 * read as visits, against the real table.
 *
 * The grouping rules themselves are pinned in the unit {@see \Agentimus\Tests\VisitsTest};
 * this covers what only the store can get wrong: the filters apply before grouping,
 * the cursor walk across the 500-row read chunks never splits or repeats a visit,
 * and a bad `group` is a 400.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Repository;
use Agentimus\Activity\Table;

final class RequestLogVisitsTest extends RestTestCase {

	const ROUTE = '/agentimus/v1/activity/log';

	/** @var int Seconds before now of the oldest seeded hit. */
	private $start;

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		wp_set_current_user( $this->admin );
		$this->start = time() - 6 * HOUR_IN_SECONDS;
	}

	private function hit( $agent, $seconds, $path = '/a/' ) {
		global $wpdb;
		$wpdb->insert(
			Table::name(),
			array(
				'endpoint' => 'page',
				'agent'    => $agent,
				'ua'       => $agent . '/1.0',
				'network'  => 'example.net',
				'path'     => $path,
				'hit_at'   => gmdate( 'Y-m-d H:i:s', $this->start + $seconds ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	private function get( array $params ) {
		$request = new \WP_REST_Request( 'GET', self::ROUTE );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_burst_of_pages_comes_back_as_one_visit() {
		$this->hit( 'Orbbot', 0, '/privacy/' );
		$this->hit( 'Orbbot', 10, '/blog/' );
		$this->hit( 'Orbbot', 40, '/perfumery/' );

		$res = $this->get( array( 'group' => 'visits' ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertCount( 1, $data['visits'] );
		$this->assertSame( 3, $data['visits'][0]['count'] );
		$this->assertSame( 40, $data['visits'][0]['seconds'] );
		$this->assertSame( array( '/perfumery/', '/blog/', '/privacy/' ), array_column( $data['visits'][0]['rows'], 'path' ) );
		$this->assertSame( 3, $data['total'], 'The total still counts requests.' );
		$this->assertSame( 120, $data['gapSeconds'] );
		$this->assertFalse( $data['hasMore'] );
	}

	public function test_filters_apply_before_grouping() {
		$this->hit( 'Orbbot', 0, '/privacy/' );
		$this->hit( 'Bingbot', 5, '/privacy/' );
		$this->hit( 'Orbbot', 10, '/blog/' );

		$data = $this->get( array( 'group' => 'visits', 'agent' => 'Orbbot' ) )->get_data();
		$this->assertCount( 1, $data['visits'] );
		$this->assertSame( 'Orbbot', $data['visits'][0]['agent'] );
		$this->assertSame( 2, $data['visits'][0]['count'] );
	}

	public function test_the_cursor_walk_across_read_chunks_shows_every_request_once() {
		// 1,200 requests: a long visit from one crawler (every 30s, never pausing),
		// interleaved with one-page visits from others, so pages and the 500-row read
		// chunks both fall in the middle of the long visit.
		$seconds = 0;
		for ( $i = 0; $i < 1200; $i++ ) {
			$seconds += 3;
			$this->hit( 0 === $i % 10 ? 'Googlebot' : 'Visitor' . $i, $seconds, '/p' . $i . '/' );
		}

		$seen   = array();
		$visits = 0;
		$before = 0;
		do {
			$params = array( 'per_page' => 50 );
			if ( $before > 0 ) {
				$params['before'] = $before;
			}
			$page = Repository::visits( $params );
			foreach ( $page['visits'] as $visit ) {
				++$visits;
				$this->assertFalse( $visit['partial'] );
				$seen[] = $visit['agent'];
			}
			$before = (int) $page['cursor'];
		} while ( $page['hasMore'] );

		$this->assertSame( 1, count( array_keys( $seen, 'Googlebot', true ) ), 'The crawler\'s visit appears once, whole.' );
		$this->assertSame( 1081, $visits, '1,080 one-page visits plus the crawler\'s one.' );
	}

	public function test_a_numbered_page_is_the_page_the_cursor_walk_reaches() {
		$seconds = 0;
		for ( $i = 0; $i < 300; $i++ ) {
			$seconds += 0 === $i % 4 ? 200 : 20;
			$this->hit( 'Bot' . ( $i % 3 ), $seconds, '/p' . $i . '/' );
		}

		$walk   = array();
		$before = 0;
		do {
			$page   = Repository::visits( array( 'per_page' => 10 ) + ( $before ? array( 'before' => $before ) : array() ) );
			$walk[] = array_column( $page['visits'], 'id' );
			$before = (int) $page['cursor'];
		} while ( $page['hasMore'] );

		$total = Repository::visits( array( 'per_page' => 10 ) )['visitTotal'];
		$this->assertSame( array_sum( array_map( 'count', $walk ) ), $total, 'visitTotal counts every visit the walk shows.' );
		foreach ( $walk as $n => $ids ) {
			$jump = Repository::visits( array( 'per_page' => 10, 'offset' => $n * 10 ) );
			$this->assertSame( $ids, array_column( $jump['visits'], 'id' ), 'Page ' . ( $n + 1 ) . ' by number matches the walk.' );
		}
		$this->assertSame( array(), Repository::visits( array( 'per_page' => 10, 'offset' => $total ) )['visits'], 'Past the end is an empty page.' );
	}

	public function test_an_unknown_group_is_a_400() {
		$this->assertSame( 400, $this->get( array( 'group' => 'sessions' ) )->get_status() );
	}

	public function test_rows_stay_the_default() {
		$this->hit( 'Orbbot', 0 );
		$data = $this->get( array() )->get_data();
		$this->assertArrayHasKey( 'rows', $data );
		$this->assertArrayNotHasKey( 'visits', $data );
	}

	public function test_the_agent_tool_returns_visits_when_asked() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+); the feature self-gates below that.' );
		}
		$this->hit( 'Orbbot', 0 );
		$this->hit( 'Orbbot', 10 );
		$out = wp_get_ability( 'agentimus/read-request-log' )->execute( array( 'group' => 'visits' ) );
		$this->assertIsArray( $out, 'The output validates against the declared schema.' );
		$this->assertSame( 2, $out['visits'][0]['count'] );
	}
}
