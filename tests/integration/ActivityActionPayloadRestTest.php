<?php
/**
 * Every route that answers with an activity payload must hand back the SAME
 * shape as GET /activity. The admin app replaces its whole `activity` object
 * with whatever these routes return, and the audience / systems cards gate
 * their first-load skeletons on those blocks being present — so one route
 * trimming the payload makes a one-row action (Ignore, Block, Allow, Re-check,
 * Clear) flash whole neighbouring cards back into "loading", which is exactly
 * how the review queue's Ignore made "Who Reached Your Site" reload.
 *
 * Parity is asserted by KEY SET, derived from the GET baseline at run time —
 * never a hand-copied list, which would go green while both sides drifted.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Table as ActivityTable;
use Agentimus\AgentAccess\Table as AgentAccessTable;

final class ActivityActionPayloadRestTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		ActivityTable::install();
		AgentAccessTable::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . ActivityTable::name() );
		wp_set_current_user( $this->admin );
	}

	private function dispatch( $method, $route, array $params = array() ) {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_get_server()->dispatch( $request );
	}

	/** One recorded hit, so the stats have a real row to aggregate. */
	private function hit() {
		global $wpdb;
		$wpdb->insert(
			ActivityTable::name(),
			array(
				'endpoint' => 'llms.txt',
				'agent'    => 'SemrushBot',
				'ua'       => 'Mozilla/5.0 (compatible; SemrushBot/7.0; +http://www.semrush.com/bot.html)',
				'hit_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	public function test_every_activity_response_matches_the_get_baseline() {
		$this->hit();

		$resp = $this->dispatch( 'GET', '/agentimus/v1/activity' );
		$this->assertSame( 200, $resp->get_status() );
		$expected = array_keys( (array) $resp->get_data() );
		sort( $expected );

		// The blocks the dashboard cards gate on must exist at all — without this,
		// key parity would happily compare two equally bare payloads.
		foreach ( array( 'audience', 'systems', 'agentAccessUnseen' ) as $key ) {
			$this->assertContains( $key, $expected, "GET /activity must carry `$key`" );
		}

		// SemrushBot: unprotected, non-generic — Guard::suggest_token() derives a
		// safe token, so Block and Allow take their success paths. GPTBot: in the
		// verified-bots registry but with no stored IPs, so Re-check answers its
		// honest 'no-ip' — the activity-carrying path that needs no live DNS.
		$semrush = 'Mozilla/5.0 (compatible; SemrushBot/7.0; +http://www.semrush.com/bot.html)';
		$gptbot  = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';

		// route => how to reach the activity payload inside the response.
		$wrapped = static function ( $data ) {
			return isset( $data['activity'] ) ? $data['activity'] : null;
		};
		$bare    = static function ( $data ) {
			return $data;
		};
		$cases = array(
			'POST /activity/dismiss'  => array( 'POST', '/agentimus/v1/activity/dismiss', array( 'ua' => $semrush, 'hits' => 3 ), $bare ),
			'POST /activity/block'    => array( 'POST', '/agentimus/v1/activity/block', array( 'ua' => $semrush ), $wrapped ),
			'POST /activity/allow'    => array( 'POST', '/agentimus/v1/activity/allow', array( 'ua' => $semrush ), $wrapped ),
			'POST /activity/reverify' => array( 'POST', '/agentimus/v1/activity/reverify', array( 'ua' => $gptbot ), $wrapped ),
			// Clear goes last — it wipes the rows the earlier cases lean on.
			'DELETE /activity'        => array( 'DELETE', '/agentimus/v1/activity', array(), $bare ),
		);

		foreach ( $cases as $label => $case ) {
			list( $method, $route, $params, $extract ) = $case;
			$resp = $this->dispatch( $method, $route, $params );
			$this->assertSame( 200, $resp->get_status(), "$label must succeed" );

			$activity = $extract( (array) $resp->get_data() );
			$this->assertIsArray( $activity, "$label must carry an activity payload" );

			$keys = array_keys( $activity );
			sort( $keys );
			$this->assertSame( $expected, $keys, "$label must return the same activity shape GET /activity serves" );
		}
	}
}
