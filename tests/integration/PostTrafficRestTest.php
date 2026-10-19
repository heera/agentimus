<?php
/**
 * GET /post-traffic — the opened Your Content row's detail. Owner data, so
 * admins only; an unknown post is a 404, never an empty payload that reads
 * as "nobody came".
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class PostTrafficRestTest extends RestTestCase {

	private function get( $post ) {
		$request = new \WP_REST_Request( 'GET', '/agentimus/v1/post-traffic' );
		$request->set_param( 'post', $post );
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_admin_gets_the_post_s_traffic() {
		wp_set_current_user( $this->admin );
		$id = self::factory()->post->create();

		$res = $this->get( $id );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $id, $res->get_data()['postId'] );
		$this->assertArrayHasKey( 'daily', $res->get_data() );
	}

	public function test_an_editor_is_refused() {
		wp_set_current_user( $this->editor );
		$this->assertSame( 403, $this->get( self::factory()->post->create() )->get_status() );
	}

	public function test_an_unknown_post_is_not_found() {
		wp_set_current_user( $this->admin );
		$this->assertSame( 404, $this->get( 99999999 )->get_status() );
	}
}
