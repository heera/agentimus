<?php
/**
 * The wizard's guided "switch on Search engine visibility" fix: an explicit
 * make_public tick riding the settings save flips blog_public, the response
 * already reports the site open, and the key itself never becomes a stored
 * setting. No tick — no touch.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class WizardMakePublicTest extends RestTestCase {

	protected function tearDown(): void {
		update_option( 'blog_public', 1 );
		parent::tearDown();
	}

	private function save( array $body ) {
		wp_set_current_user( $this->admin );
		$req = new \WP_REST_Request( 'POST', '/agentimus/v1/settings' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	public function test_ticked_fix_flips_blog_public_and_the_response_shows_it() {
		update_option( 'blog_public', 0 );
		$res = $this->save( array( 'make_public' => true ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '1', (string) get_option( 'blog_public' ) );

		$rows = wp_list_pluck( $res->get_data()['readiness'], 'status', 'id' );
		$this->assertSame( 'pass', $rows['public'], 'The same response must already report the site open.' );
		$this->assertArrayNotHasKey( 'make_public', $res->get_data()['settings'], 'The tick is an action, not a setting.' );
	}

	public function test_without_the_tick_the_option_is_untouched() {
		update_option( 'blog_public', 0 );
		$this->save( array() );
		$this->assertSame( '0', (string) get_option( 'blog_public' ) );
	}
}
