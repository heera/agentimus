<?php
/**
 * The preview answers what a Cloudflare block for one name would do, from the
 * site's own log and what Cloudflare saw. It reads and never writes.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Table as ActivityTable;
use Agentimus\Cloudflare\EdgeRule;
use Agentimus\Cloudflare\Settings as CloudflareSettings;
use Agentimus\Settings;

final class EdgeBlockPreviewDbTest extends DbTestCase {

	const ROUTE = '/agentimus/v1/cloudflare/edge-block/preview';

	const SQLSPIDER = 'Mozilla/5.0 (compatible; SQLSpider/1.5)';

	/** @var array<int,array{method:string,url:string,body:string}> */
	private $calls = array();

	/** @var array{code:int,body:string} What the ruleset read answers. */
	private $ruleset;

	/** @var array{code:int,body:string} What the GraphQL read answers. */
	private $graphql;

	/** @var callable|null */
	private $http;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new CloudflareSettings() )->connect( 'test-token', 'zone123', 'example.org' );

		$this->ruleset = $this->rules( array( array( 'id' => 'theirs', 'description' => 'Block bad paths' ) ) );
		$this->graphql = $this->seen_at_edge( array() );

		$this->http = function ( $pre, $args, $url ) {
			$this->calls[] = array(
				'method' => isset( $args['method'] ) ? (string) $args['method'] : 'GET',
				'url'    => (string) $url,
				'body'   => isset( $args['body'] ) ? (string) $args['body'] : '',
			);
			$answer = false !== strpos( (string) $url, '/graphql' ) ? $this->graphql : $this->ruleset;
			return array(
				'response' => array( 'code' => $answer['code'], 'message' => '' ),
				'headers'  => array(),
				'cookies'  => array(),
				'body'     => $answer['body'],
			);
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->http, 10 );
		( new CloudflareSettings() )->disconnect();
		parent::tear_down();
	}

	/**
	 * @param array $rules Rules in the zone's custom ruleset.
	 * @return array{code:int,body:string}
	 */
	private function rules( array $rules ) {
		return array(
			'code' => 200,
			'body' => wp_json_encode( array( 'success' => true, 'errors' => array(), 'result' => array( 'id' => 'set1', 'rules' => $rules ) ) ),
		);
	}

	/**
	 * @param array<string,int> $uas User agent => requests at the edge.
	 * @return array{code:int,body:string}
	 */
	private function seen_at_edge( array $uas ) {
		$groups = array();
		foreach ( $uas as $ua => $count ) {
			$groups[] = array( 'count' => $count, 'dimensions' => array( 'userAgent' => $ua ) );
		}
		return array(
			'code' => 200,
			'body' => wp_json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'httpRequestsAdaptiveGroups' => $groups ) ) ) ) ) ),
		);
	}

	/**
	 * @param string $ua      The user agent.
	 * @param int    $verdict 0 unchecked, 1 genuine, 2 spoofed.
	 * @return void
	 */
	private function hit( $ua, $verdict = 0 ) {
		global $wpdb;
		$wpdb->insert(
			ActivityTable::name(),
			array( 'endpoint' => 'page', 'agent' => 'x', 'ua' => $ua, 'verdict' => $verdict, 'hit_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * @param string $token The name to preview.
	 * @return array{status:int,data:array}
	 */
	private function preview( $token ) {
		$request = new \WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'token', $token );
		$response = rest_do_request( $request );
		return array( 'status' => $response->get_status(), 'data' => (array) $response->get_data() );
	}

	/**
	 * @param array $data A preview answer.
	 * @return string[]
	 */
	private function codes( array $data ) {
		return array_column( $data['refusals'], 'code' );
	}

	public function test_only_an_owner_can_ask() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->preview( 'sqlspider' )['status'] );
		$this->assertSame( array(), $this->calls );
	}

	public function test_a_logged_bot_is_ready_and_the_rule_is_shown() {
		$this->hit( self::SQLSPIDER );
		$this->hit( self::SQLSPIDER );

		$out = $this->preview( 'sqlspider' );

		$this->assertSame( 200, $out['status'] );
		$this->assertTrue( $out['data']['ok'] );
		$this->assertTrue( $out['data']['ready'] );
		$this->assertSame( 2, $out['data']['log']['clients'][0]['hits'] );
		$this->assertSame( EdgeRule::ACTION, $out['data']['rule']['action'] );
		$this->assertStringContainsString( '" sqlspider/"', $out['data']['rule']['expression'] );
		$this->assertSame( 1, $out['data']['cloudflare']['ruleCount'] );
		$this->assertFalse( $out['data']['cloudflare']['ourRule'] );
	}

	public function test_it_never_writes_to_cloudflare() {
		$this->hit( self::SQLSPIDER );

		$this->preview( 'sqlspider' );

		$this->assertNotEmpty( $this->calls );
		foreach ( $this->calls as $call ) {
			if ( false !== strpos( $call['url'], '/graphql' ) ) {
				$this->assertStringNotContainsString( 'mutation', $call['body'] );
				continue;
			}
			$this->assertSame( 'GET', $call['method'], $call['url'] );
		}
	}

	public function test_a_browser_seen_only_at_the_edge_is_refused() {
		// The log keeps bots. Only Cloudflare sees the people.
		$this->hit( self::SQLSPIDER );
		$this->graphql = $this->seen_at_edge( array(
			'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 SQLSpider/1.5' => 40,
			'Mozilla/5.0 (Macintosh) Firefox/130.0' => 900,
		) );

		$out = $this->preview( 'sqlspider' );

		$this->assertFalse( $out['data']['ok'] );
		$this->assertContains( 'other_clients', $this->codes( $out['data'] ) );
		$this->assertCount( 1, $out['data']['edge']['clients'], 'Only names containing the token are kept.' );
	}

	public function test_a_failed_edge_check_refuses_rather_than_passes() {
		$this->hit( self::SQLSPIDER );
		$this->graphql = array( 'code' => 200, 'body' => '{"errors":[{"message":"not authorized"}]}' );

		$out = $this->preview( 'sqlspider' );

		$this->assertFalse( $out['data']['ok'] );
		$this->assertContains( 'edge_unchecked', $this->codes( $out['data'] ) );
		$this->assertFalse( $out['data']['edge']['checked'] );
	}

	public function test_a_token_without_the_rules_permission_is_named() {
		$this->hit( self::SQLSPIDER );
		$this->ruleset = array( 'code' => 403, 'body' => '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}' );

		$out = $this->preview( 'sqlspider' );

		$this->assertTrue( $out['data']['ok'], 'The name is still safe.' );
		$this->assertFalse( $out['data']['ready'], 'But nothing can be done with this token.' );
		$this->assertTrue( $out['data']['cloudflare']['needsPermission'] );
	}

	public function test_a_zone_with_no_custom_rules_yet_can_still_be_ready() {
		$this->hit( self::SQLSPIDER );
		$this->ruleset = array( 'code' => 404, 'body' => '{"success":false,"errors":[{"code":10003,"message":"could not find entrypoint ruleset"}]}' );

		$out = $this->preview( 'sqlspider' );

		$this->assertTrue( $out['data']['ready'] );
		$this->assertTrue( $out['data']['cloudflare']['canRead'] );
		$this->assertSame( 0, $out['data']['cloudflare']['ruleCount'] );
	}

	public function test_our_own_rule_is_recognised_among_others() {
		$this->hit( self::SQLSPIDER );
		$this->ruleset = $this->rules( array(
			array( 'id' => 'theirs', 'description' => 'Block bad paths' ),
			array( 'id' => 'ours', 'ref' => EdgeRule::REF ),
		) );

		$out = $this->preview( 'sqlspider' );

		$this->assertSame( 2, $out['data']['cloudflare']['ruleCount'] );
		$this->assertTrue( $out['data']['cloudflare']['ourRule'] );
	}

	public function test_it_says_whether_the_name_is_on_the_block_list() {
		$this->hit( self::SQLSPIDER );

		$this->assertFalse( $this->preview( 'sqlspider' )['data']['listed'] );

		( new Settings() )->block_agent( 'SQLSpider' );

		$this->assertTrue( $this->preview( 'sqlspider' )['data']['listed'] );
	}

	public function test_a_refused_name_makes_no_search_of_the_log_or_cloudflare() {
		$out = $this->preview( 'sql"spider' );

		$this->assertFalse( $out['data']['ok'] );
		$this->assertContains( 'not_a_name', $this->codes( $out['data'] ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_without_a_connection_it_says_so() {
		( new CloudflareSettings() )->disconnect();

		$out = $this->preview( 'sqlspider' );

		$this->assertFalse( $out['data']['ok'] );
		$this->assertContains( 'not_connected', $this->codes( $out['data'] ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_an_empty_name_is_a_bad_request() {
		$this->assertSame( 400, $this->preview( '' )['status'] );
	}
}
