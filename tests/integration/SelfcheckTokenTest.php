<?php
/**
 * The self-check token that keeps the readiness screen's own live checks out of
 * the visit log. The checks fetch anonymously (they grade what an agent
 * receives), so the recorder can't recognise the owner by cookie — instead the
 * fetches carry a server-minted, short-lived token in X-Agentimus-Selfcheck.
 * Under test: a valid token skips, a forged or absent one doesn't, only the
 * hash is stored, and the mint endpoint is admin-only.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Owner;

final class SelfcheckTokenTest extends RestTestCase {

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_AGENTIMUS_SELFCHECK'] );
		delete_transient( Owner::SELFCHECK_TRANSIENT );
		delete_transient( Owner::PROBE_TRANSIENT );
		parent::tearDown();
	}

	public function test_a_valid_token_skips_the_log_even_for_an_anonymous_request() {
		wp_set_current_user( 0 ); // The live checks send no credentials.
		$token = Owner::mint_self_check_token();

		$_SERVER['HTTP_X_AGENTIMUS_SELFCHECK'] = $token;
		$this->assertTrue( Owner::skip(), 'A request bearing the minted token is the site testing itself.' );
	}

	public function test_a_forged_or_missing_token_does_not_skip() {
		wp_set_current_user( 0 );
		Owner::mint_self_check_token();

		$this->assertFalse( Owner::skip(), 'No header → a normal anonymous request.' );

		$_SERVER['HTTP_X_AGENTIMUS_SELFCHECK'] = str_repeat( 'a', 32 );
		$this->assertFalse( Owner::skip(), 'A guessed token buys nothing.' );
	}

	/**
	 * The route probe's token lives in its own slot: a cron probe firing in the
	 * middle of a "Verify live" run must not clobber that run's token, and both
	 * kinds of self-traffic skip the log.
	 */
	public function test_probe_token_has_its_own_slot_and_both_skip() {
		wp_set_current_user( 0 );
		$live  = Owner::mint_self_check_token();
		$probe = Owner::mint_probe_token();

		$_SERVER['HTTP_X_AGENTIMUS_SELFCHECK'] = $probe;
		$this->assertTrue( Owner::skip(), 'The route probe is the site checking its own front door.' );

		$_SERVER['HTTP_X_AGENTIMUS_SELFCHECK'] = $live;
		$this->assertTrue( Owner::skip(), 'The live-check token still validates after a probe minted.' );
	}

	public function test_only_the_hash_is_stored() {
		$token  = Owner::mint_self_check_token();
		$stored = get_transient( Owner::SELFCHECK_TRANSIENT );
		$this->assertNotSame( $token, $stored, 'A DB read must never reveal a usable token.' );
		$this->assertSame( hash( 'sha256', $token ), $stored );
	}

	public function test_minting_is_admin_only() {
		wp_set_current_user( $this->subscriber );
		$status = rest_do_request( new \WP_REST_Request( 'POST', '/agentimus/v1/activity/selfcheck-token' ) )->get_status();
		$this->assertContains( $status, array( 401, 403 ) );

		wp_set_current_user( $this->admin );
		$res = rest_do_request( new \WP_REST_Request( 'POST', '/agentimus/v1/activity/selfcheck-token' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{32}\z/', $res->get_data()['token'] );
	}
}
