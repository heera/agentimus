<?php
/**
 * The skip-path identity seed: skipping the wizard is not abandonment. With
 * `seed`, an EMPTY about is filled from the site's own tagline — but never a
 * factory placeholder, never over an owner's words, and never without the
 * explicit seed flag.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Settings;

final class OnboardingSeedTest extends RestTestCase {

	private function complete( array $body ) {
		wp_set_current_user( $this->admin );
		$req = new \WP_REST_Request( 'POST', '/agentimus/v1/onboarding' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	public function test_seed_fills_an_empty_about_from_a_real_tagline() {
		update_option( 'blogdescription', 'Perfume notes and PHP.' );
		$res = $this->complete( array( 'seed' => true ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'Perfume notes and PHP.', ( new Settings() )->identity( 'about', '' ) );
	}

	public function test_the_factory_tagline_never_seeds() {
		update_option( 'blogdescription', 'Just another WordPress site' );
		$this->complete( array( 'seed' => true ) );
		$this->assertSame( '', ( new Settings() )->identity( 'about', '' ) );
	}

	public function test_an_owner_set_about_survives_a_seeded_skip() {
		$settings                   = new Settings();
		$all                        = $settings->all();
		$all['identity']['about'] = 'Mine, thanks.';
		$settings->update( $all );

		update_option( 'blogdescription', 'A different tagline' );
		$this->complete( array( 'seed' => true ) );
		$this->assertSame( 'Mine, thanks.', ( new Settings() )->identity( 'about', '' ) );
	}

	public function test_plain_completion_seeds_nothing() {
		update_option( 'blogdescription', 'Perfume notes and PHP.' );
		$this->complete( array() );
		$this->assertSame( '', ( new Settings() )->identity( 'about', '' ) );
	}

	public function test_completion_stamps_whatsnew_so_fresh_installs_skip_their_own_release_notes() {
		delete_option( 'agentimus_whatsnew_seen' );
		$this->complete( array() );
		$this->assertSame( AGENTIMUS_VERSION, get_option( 'agentimus_whatsnew_seen' ), 'What’s New debuts on the first UPDATE, not on day one.' );

		// An already-seen marker from a real past is never touched.
		update_option( 'agentimus_whatsnew_seen', '1.20.0' );
		$this->complete( array() );
		$this->assertSame( '1.20.0', get_option( 'agentimus_whatsnew_seen' ) );
	}

	public function test_completion_queues_the_next_steps_card_and_dismissal_is_final() {
		$this->complete( array() );
		$this->assertSame( 'show', get_option( 'agentimus_next_steps' ), 'Finishing (or skipping) queues the dashboard map.' );

		wp_set_current_user( $this->admin );
		rest_do_request( new \WP_REST_Request( 'POST', '/agentimus/v1/nextsteps-seen' ) );
		$this->assertSame( 'done', get_option( 'agentimus_next_steps' ) );

		// A later re-onboarding must not resurrect a dismissed card.
		$this->complete( array() );
		$this->assertSame( 'done', get_option( 'agentimus_next_steps' ) );
	}
}
