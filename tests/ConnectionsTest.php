<?php
/**
 * The shared connection store — the promises that make "connect once, use
 * anywhere" true instead of merely intended:
 *
 *   1. ONE HOME. A credential row is stored whole, read back whole, and a
 *      service never connected answers an empty row — no nulls, no guessing.
 *   2. NEIGHBOURS STAND. Forgetting one connection leaves every other row
 *      untouched; the option itself leaves with its last row, the way the
 *      delivery-state map already behaves.
 *   3. ADOPTION. A Telegram token stored before the store existed is moved in
 *      on first read — moved, not copied, so the credential keeps exactly one
 *      home and a later disconnect cannot resurrect it from the old option.
 *   4. THE DISCONNECT IS TOTAL. forget_token() clears the store row AND the
 *      legacy option in the same breath.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Integrations\Connections;
use Agentimus\Integrations\Services\Telegram;
use PHPUnit\Framework\TestCase;

final class ConnectionsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* -- One home ------------------------------------------------------------- */

	public function test_a_row_round_trips_whole() {
		Connections::store( 'telegram', array( 'token' => '111:abc' ) );

		$this->assertSame( array( 'token' => '111:abc' ), Connections::read( 'telegram' ) );
		$this->assertTrue( Connections::exists( 'telegram' ) );
	}

	public function test_a_service_never_connected_answers_an_empty_row() {
		$this->assertSame( array(), Connections::read( 'linkedin' ) );
		$this->assertFalse( Connections::exists( 'linkedin' ) );
	}

	public function test_store_replaces_the_row_whole() {
		Connections::store( 'x', array( 'token' => 'old', 'secret' => 'keep-me?' ) );
		Connections::store( 'x', array( 'token' => 'new' ) );

		// The connect path always holds the complete grant, so a write is a
		// replacement — a stale field surviving a reconnect would be a leak.
		$this->assertSame( array( 'token' => 'new' ), Connections::read( 'x' ) );
	}

	/* -- Neighbours stand ------------------------------------------------------ */

	public function test_forgetting_one_connection_leaves_its_neighbour() {
		Connections::store( 'telegram', array( 'token' => '111:abc' ) );
		Connections::store( 'x', array( 'token' => 'xoxo' ) );

		Connections::forget( 'telegram' );

		$this->assertFalse( Connections::exists( 'telegram' ) );
		$this->assertTrue( Connections::exists( 'x' ) );
	}

	public function test_the_option_leaves_with_its_last_row() {
		Connections::store( 'telegram', array( 'token' => '111:abc' ) );
		Connections::forget( 'telegram' );

		$this->assertArrayNotHasKey( Connections::OPTION, $GLOBALS['_af_options'] );
	}

	public function test_forgetting_the_never_connected_is_quiet() {
		Connections::forget( 'linkedin' );

		$this->assertArrayNotHasKey( Connections::OPTION, $GLOBALS['_af_options'] );
	}

	/* -- Adoption (the pre-store Telegram token) -------------------------------- */

	public function test_a_legacy_telegram_token_is_adopted_on_first_read() {
		$GLOBALS['_af_options'][ Telegram::TOKEN_OPTION ] = '111:legacy';

		$this->assertSame( '111:legacy', Telegram::token() );

		// Moved, not copied: the store holds it, the old option is gone.
		$this->assertSame( array( 'token' => '111:legacy' ), Connections::read( Telegram::ID ) );
		$this->assertArrayNotHasKey( Telegram::TOKEN_OPTION, $GLOBALS['_af_options'] );
	}

	public function test_has_token_sees_a_legacy_token_too() {
		$GLOBALS['_af_options'][ Telegram::TOKEN_OPTION ] = '111:legacy';

		$this->assertTrue( Telegram::has_token() );
	}

	public function test_the_store_outranks_a_lingering_legacy_option() {
		Connections::store( Telegram::ID, array( 'token' => '111:stored' ) );
		$GLOBALS['_af_options'][ Telegram::TOKEN_OPTION ] = '111:stale';

		$this->assertSame( '111:stored', Telegram::token() );
	}

	public function test_store_token_writes_the_store_and_clears_the_legacy_home() {
		$GLOBALS['_af_options'][ Telegram::TOKEN_OPTION ] = '111:stale';

		Telegram::store_token( '111:fresh' );

		$this->assertSame( array( 'token' => '111:fresh' ), Connections::read( Telegram::ID ) );
		$this->assertArrayNotHasKey( Telegram::TOKEN_OPTION, $GLOBALS['_af_options'] );
		$this->assertSame( '111:fresh', Telegram::token() );
	}

	/* -- The disconnect is total ------------------------------------------------ */

	public function test_forget_token_clears_both_homes() {
		Telegram::store_token( '111:abc' );
		$GLOBALS['_af_options'][ Telegram::TOKEN_OPTION ] = '111:zombie';

		Telegram::forget_token();

		$this->assertFalse( Telegram::has_token() );
		$this->assertFalse( Connections::exists( Telegram::ID ) );
		$this->assertArrayNotHasKey( Telegram::TOKEN_OPTION, $GLOBALS['_af_options'] );
	}
}
