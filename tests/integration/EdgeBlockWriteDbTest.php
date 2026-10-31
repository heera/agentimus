<?php
/**
 * Adding and removing names in Agentimus's one Cloudflare rule. Runs against a
 * fake zone that keeps its rules, so each test can look at what the zone ended
 * up with and not only at what was sent.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Review;
use Agentimus\Activity\Table as ActivityTable;
use Agentimus\Cloudflare\EdgeBlock;
use Agentimus\Cloudflare\EdgeRule;
use Agentimus\Cloudflare\Module as CloudflareModule;
use Agentimus\Cloudflare\Settings as CloudflareSettings;
use Agentimus\Settings;

final class EdgeBlockWriteDbTest extends DbTestCase {

	const SQLSPIDER = 'Mozilla/5.0 (compatible; SQLSpider/1.5)';
	const OTHERBOT  = 'Mozilla/5.0 (compatible; OtherBot/2.0)';

	/** @var array[]|null The zone's rules; null means the zone has no ruleset yet. */
	private $zone;

	/** @var array<int,array{method:string,url:string}> Writes that reached the zone. */
	private $writes = array();

	/** @var bool Whether writes are refused with a 403. */
	private $refuse_writes = false;

	/** @var string The zone's plan. */
	private $plan = 'free';

	/** @var bool Whether the zone accepts a write but keeps something else. */
	private $garble = false;

	/** @var callable|null */
	private $http;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new CloudflareSettings() )->connect( 'test-token', 'zone123', 'example.org' );
		$this->zone = array(
			array( 'id' => 'bad-paths', 'description' => 'Block bad paths', 'expression' => 'x', 'action' => 'block' ),
			array( 'id' => 'other', 'description' => 'Another', 'expression' => 'y', 'action' => 'block' ),
		);
		$this->http = function ( $pre, $args, $url ) {
			return $this->answer( isset( $args['method'] ) ? (string) $args['method'] : 'GET', (string) $url, isset( $args['body'] ) ? (string) $args['body'] : '' );
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
		$this->switch_on( true );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->http, 10 );
		( new CloudflareSettings() )->disconnect();
		parent::tear_down();
	}

	/** The fake zone. */
	private function answer( $method, $url, $body ) {
		if ( false !== strpos( $url, '/graphql' ) ) {
			return $this->reply( 200, array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'httpRequestsAdaptiveGroups' => array() ) ) ) ) ) );
		}
		if ( 'GET' === $method && preg_match( '#/zones/zone123$#', $url ) ) {
			return $this->reply( 200, array( 'success' => true, 'result' => array( 'id' => 'zone123', 'plan' => array( 'legacy_id' => $this->plan ) ) ) );
		}
		if ( 'GET' === $method ) {
			if ( null === $this->zone ) {
				return $this->reply( 404, array( 'success' => false, 'errors' => array( array( 'message' => 'not found' ) ) ) );
			}
			return $this->reply( 200, array( 'success' => true, 'result' => array( 'id' => 'set1', 'rules' => $this->zone ) ) );
		}

		$this->writes[] = array( 'method' => $method, 'url' => $url );
		if ( $this->refuse_writes ) {
			return $this->reply( 403, array( 'success' => false, 'errors' => array( array( 'message' => 'request is not authorized' ) ) ) );
		}

		$data = json_decode( $body, true );
		$id   = substr( (string) strrchr( $url, '/' ), 1 );
		if ( 'PUT' === $method ) {
			$this->zone = array();
			foreach ( (array) $data['rules'] as $rule ) {
				$this->zone[] = array_merge( $rule, array( 'id' => 'ours' ) );
			}
		} elseif ( 'POST' === $method ) {
			$this->zone[] = array_merge( $data, array( 'id' => 'ours' ) );
		} elseif ( 'PATCH' === $method ) {
			foreach ( $this->zone as $i => $rule ) {
				if ( $rule['id'] === $id ) {
					$this->zone[ $i ] = array_merge( $data, array( 'id' => $id ) );
				}
			}
		} elseif ( 'DELETE' === $method ) {
			$this->zone = array_values( array_filter( $this->zone, static function ( $rule ) use ( $id ) {
				return $rule['id'] !== $id;
			} ) );
		}
		if ( $this->garble ) {
			foreach ( $this->zone as $i => $rule ) {
				if ( 'ours' === $rule['id'] ) {
					$this->zone[ $i ]['expression'] = 'something else';
				}
			}
		}
		return $this->reply( 200, array( 'success' => true, 'result' => array( 'id' => 'set1' ) ) );
	}

	private function reply( $code, array $json ) {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'headers' => array(), 'cookies' => array(), 'body' => wp_json_encode( $json ) );
	}

	private function switch_on( $on ) {
		$settings = new Settings();
		$all      = $settings->stored();
		$all['cf_edge_block'] = (bool) $on;
		$settings->update( $all );
	}

	private function hit( $ua ) {
		global $wpdb;
		$wpdb->insert( ActivityTable::name(), array( 'endpoint' => 'page', 'agent' => 'x', 'ua' => $ua, 'hit_at' => gmdate( 'Y-m-d H:i:s' ) ), array( '%s', '%s', '%s', '%s' ) );
	}

	/** Log a visit, put the name on the block list, and add it at the edge. */
	private function block( $token, $ua = self::SQLSPIDER ) {
		$this->hit( $ua );
		( new Settings() )->block_agent( $token );
		return $this->edge()->add( $token );
	}

	private function edge() {
		return new EdgeBlock( new CloudflareSettings(), new Settings() );
	}

	/** @return array|null Our rule in the zone. */
	private function our_rule() {
		foreach ( (array) $this->zone as $rule ) {
			if ( isset( $rule['ref'] ) && EdgeRule::REF === $rule['ref'] ) {
				return $rule;
			}
		}
		return null;
	}

	private function theirs_untouched() {
		$this->assertSame( array( 'bad-paths', 'other' ), array_values( array_filter( array_column( $this->zone, 'id' ), static function ( $id ) {
			return 'ours' !== $id;
		} ) ) );
		$this->assertSame( 'x', $this->zone[0]['expression'] );
		$this->assertSame( 'y', $this->zone[1]['expression'] );
	}

	private function methods() {
		return array_column( $this->writes, 'method' );
	}

	public function test_adding_a_name_adds_one_rule_and_leaves_the_others() {
		$out = $this->block( 'sqlspider' );

		$this->assertTrue( $out['ok'], (string) ( $out['error'] ?? '' ) );
		$this->assertSame( array( 'POST' ), $this->methods() );
		$this->assertStringContainsString( '/zones/zone123/rulesets/set1/rules', $this->writes[0]['url'] );
		$this->theirs_untouched();
		$rule = $this->our_rule();
		$this->assertSame( EdgeRule::ACTION, $rule['action'] );
		$this->assertStringContainsString( '" sqlspider/"', $rule['expression'] );
		$this->assertTrue( $rule['enabled'] );
		$this->assertSame( array( 'sqlspider' ), array_keys( ( new CloudflareSettings() )->edge_names() ) );
	}

	public function test_a_second_name_joins_the_same_rule() {
		$this->block( 'sqlspider' );
		$this->block( 'otherbot', self::OTHERBOT );

		$this->assertSame( array( 'POST', 'PATCH' ), $this->methods() );
		$this->assertStringEndsWith( '/rules/ours', $this->writes[1]['url'] );
		$this->assertStringContainsString( '" sqlspider/"', $this->our_rule()['expression'] );
		$this->assertStringContainsString( '" otherbot/"', $this->our_rule()['expression'] );
		$this->assertCount( 3, $this->zone );
		$this->theirs_untouched();
	}

	public function test_a_zone_with_no_ruleset_gets_one() {
		$this->zone = null;

		$out = $this->block( 'sqlspider' );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( array( 'PUT' ), $this->methods() );
		$this->assertStringContainsString( '/phases/http_request_firewall_custom/entrypoint', $this->writes[0]['url'] );
	}

	public function test_a_refused_name_sends_nothing() {
		$out = $this->block( 'abc', 'abc/2.6.0; (+https://example.test)' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'too_short', array_column( $out['refusals'], 'code' ) );
		$this->assertSame( array(), $this->writes );
		$this->assertSame( array(), ( new CloudflareSettings() )->edge_names() );
	}

	public function test_a_name_not_on_the_block_list_is_refused() {
		$this->hit( self::SQLSPIDER );

		$out = $this->edge()->add( 'sqlspider' );

		$this->assertContains( 'not_listed', array_column( $out['refusals'], 'code' ) );
		$this->assertSame( array(), $this->writes );
	}

	public function test_with_the_switch_off_nothing_is_sent() {
		$this->switch_on( false );

		$out = $this->block( 'sqlspider' );

		$this->assertContains( 'switched_off', array_column( $out['refusals'], 'code' ) );
		$this->assertSame( array(), $this->writes );
	}

	public function test_a_refused_write_keeps_the_local_block_and_stores_no_name() {
		$this->refuse_writes = true;

		$out = $this->block( 'sqlspider' );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'request is not authorized. Your Cloudflare token needs the Zone WAF: Edit permission.', $out['error'] );
		$this->assertSame( array(), ( new CloudflareSettings() )->edge_names() );
		$this->assertContains( 'sqlspider', ( new Settings() )->get( 'blocked_agents' ) );
	}

	public function test_a_rule_that_does_not_read_back_as_sent_is_a_failure() {
		$this->garble = true;

		$out = $this->block( 'sqlspider' );

		$this->assertFalse( $out['ok'] );
		$this->assertNotSame( '', $out['error'] );
	}

	public function test_a_full_rule_is_refused_before_anything_is_sent() {
		$many = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$many[ 'crawlername' . $i ] = EdgeRule::FORM_VERSIONED;
		}
		$this->hit( self::SQLSPIDER );
		$settings = new Settings();
		$all      = $settings->stored();
		$all['blocked_agents'] = array_merge( array_keys( $many ), array( 'sqlspider' ) );
		$settings->update( $all );
		( new CloudflareSettings() )->note_edge( $many );

		$out = $this->edge()->add( 'sqlspider' );

		$this->assertContains( 'rule_full', array_column( $out['refusals'], 'code' ) );
		$this->assertSame( array(), $this->writes );
	}

	public function test_unblocking_removes_the_name_and_the_last_one_removes_the_rule() {
		$this->block( 'sqlspider' );
		$this->block( 'otherbot', self::OTHERBOT );
		$this->writes = array();

		( new Settings() )->remove_agent_token( 'sqlspider', 'blocked_agents' );

		$this->assertStringNotContainsString( 'sqlspider', $this->our_rule()['expression'] );
		$this->assertStringContainsString( 'otherbot', $this->our_rule()['expression'] );
		$this->assertSame( array( 'PATCH' ), $this->methods() );

		( new Settings() )->remove_agent_token( 'otherbot', 'blocked_agents' );

		$this->assertNull( $this->our_rule() );
		$this->theirs_untouched();
		$this->assertSame( array(), ( new CloudflareSettings() )->edge_names() );
	}

	public function test_allowing_a_client_removes_a_name_that_overlaps_it() {
		$this->block( 'sqlspider' );

		( new Settings() )->allow_agent( 'sqlspider' );

		$this->assertNull( $this->our_rule() );
	}

	public function test_turning_the_switch_off_removes_the_rule() {
		$this->block( 'sqlspider' );

		$this->switch_on( false );

		$this->assertNull( $this->our_rule() );
		$this->theirs_untouched();
	}

	public function test_a_settings_reset_removes_the_rule() {
		$this->block( 'sqlspider' );

		( new Settings() )->reset();

		$this->assertNull( $this->our_rule() );
	}

	public function test_a_removal_that_fails_is_recorded_and_the_hourly_poll_retries_it() {
		$this->block( 'sqlspider' );
		$this->refuse_writes = true;

		( new Settings() )->remove_agent_token( 'sqlspider', 'blocked_agents' );

		$store = new CloudflareSettings();
		$this->assertNotNull( $this->our_rule() );
		$this->assertSame( array( 'sqlspider' ), array_keys( $store->edge_names() ) );
		$this->assertSame( 'request is not authorized', $store->public_view()['edgeError'] );

		$this->refuse_writes = false;
		( new CloudflareModule( new CloudflareSettings() ) )->poll_now();

		$this->assertNull( $this->our_rule() );
		$this->assertSame( '', ( new CloudflareSettings() )->public_view()['edgeError'] );
	}

	public function test_a_rule_the_owner_deleted_at_cloudflare_is_not_put_back() {
		$this->block( 'sqlspider' );
		array_pop( $this->zone );
		$this->writes = array();

		( new CloudflareModule( new CloudflareSettings() ) )->poll_now();

		$this->assertSame( array(), $this->writes );
		$this->assertNull( $this->our_rule() );
		$this->assertSame( array(), ( new CloudflareSettings() )->edge_names() );
	}

	public function test_disconnecting_removes_the_rule_first() {
		$this->block( 'sqlspider' );

		$request  = new \WP_REST_Request( 'DELETE', '/agentimus/v1/cloudflare' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $this->our_rule() );
		$this->assertArrayNotHasKey( 'edgeRuleLeft', (array) $response->get_data() );
	}

	public function test_disconnecting_says_so_when_the_rule_could_not_be_removed() {
		$this->block( 'sqlspider' );
		$this->refuse_writes = true;

		$response = rest_do_request( new \WP_REST_Request( 'DELETE', '/agentimus/v1/cloudflare' ) );
		$data     = (array) $response->get_data();

		$this->assertFalse( $data['connected'], 'The owner can always disconnect.' );
		$this->assertSame( array( 'sqlspider' ), $data['edgeRuleLeft'] );
	}

	public function test_the_owners_block_click_also_blocks_at_cloudflare() {
		$this->hit( self::SQLSPIDER );

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/activity/block' );
		$request->set_param( 'ua', self::SQLSPIDER );
		$data = (array) rest_do_request( $request )->get_data();

		$this->assertTrue( $data['edge']['ok'] );
		$this->assertNotNull( $this->our_rule() );
	}

	public function test_the_owners_block_click_stays_local_with_the_switch_off() {
		$this->switch_on( false );
		$this->hit( self::SQLSPIDER );

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/activity/block' );
		$request->set_param( 'ua', self::SQLSPIDER );
		$data = (array) rest_do_request( $request )->get_data();

		$this->assertArrayNotHasKey( 'edge', $data );
		$this->assertSame( array(), $this->writes );
	}

	public function test_an_agents_block_stays_local() {
		$this->hit( self::SQLSPIDER );

		$out = Review::decide( new Settings(), self::SQLSPIDER, 'block' );

		$this->assertTrue( $out['decided'] );
		$this->assertSame( array(), $this->writes );
	}

	/** @param string[] $tokens Names for the list. */
	private function listed( array $tokens ) {
		$settings = new Settings();
		$all      = $settings->stored();
		$all['blocked_agents'] = $tokens;
		$settings->update( $all );
	}

	private function overview() {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/cloudflare/edge-block' ) );
		$this->assertSame( 200, $response->get_status() );
		return (array) $response->get_data();
	}

	/** @return array<string,array> Overview rows by name. */
	private function rows() {
		return array_column( $this->overview()['rows'], null, 'token' );
	}

	public function test_the_overview_gives_every_blocked_name_a_status() {
		$this->hit( self::SQLSPIDER );
		$this->hit( self::OTHERBOT );
		$this->hit( 'abc/1.0' );
		$this->listed( array( 'sqlspider', 'OtherBot', 'abc', 'neverseen' ) );
		$this->edge()->add( 'sqlspider' );

		$rows = $this->rows();

		$this->assertSame( 'on', $rows['sqlspider']['status'] );
		$this->assertSame( 'can_add', $rows['OtherBot']['status'] );
		$this->assertSame( 'refused', $rows['abc']['status'] );
		$this->assertNotSame( '', $rows['abc']['reason'] );
		$this->assertSame( 'unseen', $rows['neverseen']['status'] );
		$this->assertSame( 1, $this->overview()['used'] );
		$this->assertGreaterThan( 20, $this->overview()['capacity'] );
	}

	public function test_the_overview_is_quiet_when_there_is_nothing_to_offer() {
		$this->switch_on( false );
		$out = $this->overview();
		$this->assertFalse( $out['available'] );
		$this->assertSame( 'switched_off', $out['reason'] );

		$this->switch_on( true );
		( new CloudflareSettings() )->disconnect();
		$this->assertSame( 'not_connected', $this->overview()['reason'] );
	}

	public function test_the_overview_says_when_no_custom_rule_is_free() {
		$this->zone = array_fill( 0, 5, array( 'id' => 'x', 'expression' => 'x', 'action' => 'block' ) );
		$this->plan = 'free';

		$this->assertTrue( $this->overview()['slotsFull'] );

		$this->plan = 'pro';
		$this->assertFalse( $this->overview()['slotsFull'] );
	}

	public function test_add_all_sends_only_what_passes_in_one_change() {
		$this->hit( self::SQLSPIDER );
		$this->hit( self::OTHERBOT );
		$this->listed( array( 'sqlspider', 'otherbot', 'abc', 'neverseen' ) );

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/cloudflare/edge-block/bulk' );
		$request->set_param( 'tokens', array( 'sqlspider', 'otherbot', 'abc', 'neverseen' ) );
		$out = (array) rest_do_request( $request )->get_data();

		$this->assertSame( array( 'POST' ), $this->methods(), 'One change, however many names.' );
		$this->assertSame( array( 'sqlspider', 'otherbot' ), $out['added'] );
		$this->assertSame( array( 'abc', 'neverseen' ), array_column( $out['skipped'], 'token' ) );
		$this->assertStringContainsString( '" otherbot/"', $this->our_rule()['expression'] );
		$this->assertStringNotContainsString( 'neverseen', $this->our_rule()['expression'] );
		$this->theirs_untouched();
	}

	public function test_a_name_not_seen_goes_up_only_when_the_owner_insists() {
		$this->listed( array( 'neverseen' ) );

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/cloudflare/edge-block' );
		$request->set_param( 'token', 'neverseen' );
		$this->assertFalse( rest_do_request( $request )->get_data()['ok'] );
		$this->assertSame( array(), $this->writes );

		$request->set_param( 'force', true );
		$this->assertTrue( rest_do_request( $request )->get_data()['ok'] );
		$this->assertStringContainsString( '" neverseen/"', $this->our_rule()['expression'] );
		$this->assertStringContainsString( '"compatible; neverseen;"', $this->our_rule()['expression'] );
	}

	public function test_insisting_cannot_add_a_hard_refusal() {
		$this->listed( array( 'wordpress' ) );

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/cloudflare/edge-block' );
		$request->set_param( 'token', 'wordpress' );
		$request->set_param( 'force', true );

		$this->assertFalse( rest_do_request( $request )->get_data()['ok'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_only_an_owner_can_add_or_remove() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array( 'GET', 'POST' ) as $method ) {
			$request = new \WP_REST_Request( $method, '/agentimus/v1/cloudflare/edge-block' );
			$request->set_param( 'token', 'sqlspider' );
			$this->assertSame( 403, rest_do_request( $request )->get_status() );
		}
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'POST', '/agentimus/v1/cloudflare/edge-block/bulk' ) )->get_status() );
		$this->assertSame( array(), $this->writes );
	}

	public function test_the_owner_can_add_by_route_and_unblocking_is_how_a_name_leaves() {
		$this->hit( self::SQLSPIDER );
		( new Settings() )->block_agent( 'sqlspider' );

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/cloudflare/edge-block' );
		$request->set_param( 'token', 'sqlspider' );
		$this->assertTrue( rest_do_request( $request )->get_data()['ok'] );
		$this->assertNotNull( $this->our_rule() );

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/activity/client-remove' );
		$request->set_param( 'token', 'sqlspider' );
		$request->set_param( 'list', 'blocked_agents' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$this->assertNull( $this->our_rule() );
		$this->assertNotContains( 'sqlspider', ( new Settings() )->get( 'blocked_agents' ) );
	}
}
