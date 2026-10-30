<?php
/**
 * set-aside-search-page: the search worklist's own list, one page per call, by post
 * or by address. The owner's route and the agent's tool run the same code.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\Registrar;
use Agentimus\Abilities\Triage;
use Agentimus\Settings;

final class SearchPageAsideDbTest extends DbTestCase {

	/** @var Settings */
	private $settings;

	/** @var int */
	private $post;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings = new Settings();
		$this->post     = self::factory()->post->create();
	}

	private function triage() {
		return new Triage( $this->settings );
	}

	public function test_a_page_goes_aside_and_comes_back() {
		$out = $this->triage()->set_search_page_aside( $this->post, '', true );

		$this->assertIsArray( $out );
		$this->assertTrue( $out['aside'] );
		$this->assertTrue( $out['changed'] );
		$this->assertContains( $this->post, array_map( 'intval', $this->settings->stored()['search_ignored'] ) );

		$back = $this->triage()->set_search_page_aside( $this->post, '', false );

		$this->assertFalse( $back['aside'] );
		$this->assertTrue( $back['changed'] );
		$this->assertNotContains( $this->post, array_map( 'intval', $this->settings->stored()['search_ignored'] ) );
	}

	public function test_only_that_list_changes() {
		$before = $this->settings->stored();
		$this->triage()->set_search_page_aside( $this->post, '', true );
		$after = $this->settings->stored();

		$this->assertSame( array( 'search_ignored' ), array_keys( array_filter(
			$after,
			static function ( $value, $key ) use ( $before ) {
				return $value !== $before[ $key ];
			},
			ARRAY_FILTER_USE_BOTH
		) ) );
		$this->assertNotContains( $this->post, array_map( 'intval', (array) $after['optimize_ignored'] ), 'The citability list is a different decision.' );
	}

	public function test_asking_for_the_state_it_is_in_writes_nothing() {
		$this->triage()->set_search_page_aside( $this->post, '', true );
		$out = $this->triage()->set_search_page_aside( $this->post, '', true );

		$this->assertTrue( $out['aside'] );
		$this->assertFalse( $out['changed'] );
		$this->assertSame( 1, count( array_keys( array_map( 'intval', $this->settings->stored()['search_ignored'] ), $this->post, true ) ) );
	}

	public function test_a_page_with_no_post_is_held_by_its_address() {
		$url = home_url( '/some-archive/' );
		$out = $this->triage()->set_search_page_aside( 0, $url, true );

		$this->assertTrue( $out['changed'] );
		$this->assertSame( 0, $out['postId'] );
		$this->assertContains( $out['url'], $this->settings->stored()['search_ignored_urls'] );

		$this->assertTrue( $this->triage()->set_search_page_aside( 0, $url, false )['changed'] );
		$this->assertSame( array(), array_values( (array) $this->settings->stored()['search_ignored_urls'] ) );
	}

	public function test_another_sites_address_is_refused() {
		$out = $this->triage()->set_search_page_aside( 0, 'https://elsewhere.test/page/', true );
		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_bad_url', $out->get_error_code() );
	}

	public function test_a_page_that_does_not_exist_cannot_go_aside() {
		$out = $this->triage()->set_search_page_aside( 99999999, '', true );
		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_bad_post', $out->get_error_code() );
	}

	/** A post deleted later must not be stuck on the list for good. */
	public function test_a_page_that_has_gone_can_still_come_back() {
		$this->triage()->set_search_page_aside( $this->post, '', true );
		wp_delete_post( $this->post, true );

		$this->assertTrue( $this->triage()->set_search_page_aside( $this->post, '', false )['changed'] );
	}

	public function test_neither_a_post_nor_an_address_is_refused() {
		$this->assertWPError( $this->triage()->set_search_page_aside( 0, '', true ) );
	}

	/** ⭐ The owner's screen goes through the same code, so the two can't drift. */
	public function test_the_owners_route_still_works() {
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/search/ignore' );
		$request->set_param( 'post', $this->post );
		$request->set_param( 'ignored', true );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $this->post, array_map( 'intval', $this->settings->stored()['search_ignored'] ) );
	}

	/** ⛔ A value a filter adds on read must not be written back by this save. */
	public function test_it_saves_from_the_stored_settings_not_the_filtered_read() {
		$filter = static function ( $values ) {
			$values['topics_max'] = 3;
			return $values;
		};
		add_filter( 'agentimus_settings', $filter );
		$before = $this->settings->stored()['topics_max'];

		$this->triage()->set_search_page_aside( $this->post, '', true );

		remove_filter( 'agentimus_settings', $filter );
		$this->assertSame( $before, $this->settings->stored()['topics_max'] );
	}

	public function test_it_is_a_write_tool() {
		$this->assertTrue( Registrar::is_write_tool_name( 'agentimus/set-aside-search-page' ) );
	}
}
