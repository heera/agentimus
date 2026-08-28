<?php
/**
 * What the Recorder actually WRITES when a request carried a Web Bot Auth
 * signature — asserted on the stored row, not on the helper that feeds it.
 *
 * ⛔⛔ THE BUG THIS FILE EXISTS FOR. The signer used to be written only when the
 * signature MOVED the verdict, and the verdict only moves for the two operators
 * this site recognises by name. So a valid signature from anybody else left no
 * trace whatsoever: the crypto passed, the row said "unchecked", and an owner
 * asking "has anything signed to me yet?" was told no by a log that had seen it.
 * Standing and evidence are separate questions and the pair below pins both — the
 * stranger is RECORDED (evidence) and NOT PROMOTED (standing).
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Recorder;
use Agentimus\Activity\Table;
use Agentimus\BotSignature;

final class RecorderSignatureDbTest extends DbTestCase {

	/** A UA that names no verifiable engine, so the claim path does no DNS. */
	const UA = 'AcmeAgent/1.0 (+https://agents.acme.example)';

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() ); // phpcs:ignore WordPress.DB
		// verify_bots is what arms signature checking at all; without it the
		// Recorder must not so much as look at the headers.
		update_option( 'agentimus_settings', array( 'enable_activity' => true, 'verify_bots' => true ) );
		$_SERVER['HTTP_USER_AGENT'] = self::UA;
		$this->reset_buckets();
	}

	public function tear_down(): void {
		BotSignature::prime_memo( null );
		unset( $_SERVER['HTTP_USER_AGENT'] );
		$this->reset_buckets();
		parent::tear_down();
	}

	/** The flood buckets are shared and outlive a test; a stale one would sample us away. */
	private function reset_buckets(): void {
		$win = (int) floor( time() / Recorder::FLOOD_WINDOW );
		foreach ( array( 'a', 'u' ) as $bucket ) {
			delete_transient( Recorder::RATE_PREFIX . md5( $bucket ) . '_' . $win );
		}
	}

	/** Record one hit under a planted signature verdict and return the stored row. */
	private function record_with( array $verdict ) {
		BotSignature::prime_memo( $verdict );
		Recorder::record( 'llms.txt' );
		global $wpdb;
		return (array) $wpdb->get_row( 'SELECT verdict, signer FROM ' . Table::name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public function test_a_recognised_operators_signature_is_recorded_and_promoted() {
		$row = $this->record_with( array( 'state' => 'verified', 'signer' => 'https://chatgpt.com', 'reason' => '' ) );

		$this->assertSame( 'OpenAI agent', $row['signer'] );
		$this->assertSame( 1, (int) $row['verdict'], 'a signature we can vouch for outranks any DNS inference' );
	}

	public function test_a_stranger_that_signs_is_recorded_without_being_promoted() {
		$row = $this->record_with( array( 'state' => 'verified', 'signer' => 'https://agents.acme.example', 'reason' => '' ) );

		$this->assertSame( 'agents.acme.example', $row['signer'], 'the signature HAPPENED, and the row is the only place that survives' );
		$this->assertSame( 0, (int) $row['verdict'], 'and it still earns no standing for it — identity, not reputation' );
	}

	public function test_a_forged_signature_is_recorded_against_the_operator_it_claimed() {
		$row = $this->record_with( array( 'state' => 'failed', 'signer' => 'https://chatgpt.com', 'reason' => 'bad math' ) );

		$this->assertSame( 'chatgpt.com', $row['signer'], 'the victim of the impersonation, which is what the review card needs' );
		$this->assertSame( 2, (int) $row['verdict'] );
	}

	public function test_an_unsigned_request_is_recorded_with_no_signature_at_all() {
		$row = $this->record_with( array( 'state' => 'unsigned', 'signer' => '', 'reason' => '' ) );

		$this->assertSame( '', $row['signer'], 'most crawlers do not sign yet, and are never marked as if they had' );
		$this->assertSame( 0, (int) $row['verdict'] );
	}

	/**
	 * ⛔ Verification is opt-in because it costs outbound lookups. With it off the
	 * Recorder must not read a verdict at all — not even to note who signed.
	 */
	public function test_with_verification_off_a_signature_is_not_even_looked_at() {
		update_option( 'agentimus_settings', array( 'enable_activity' => true, 'verify_bots' => false ) );

		$row = $this->record_with( array( 'state' => 'verified', 'signer' => 'https://chatgpt.com', 'reason' => '' ) );

		$this->assertSame( '', $row['signer'] );
		$this->assertSame( 0, (int) $row['verdict'] );
	}
}
