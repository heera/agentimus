<?php
/**
 * AI-usage signals — the new "no AI training" channels beyond robots.txt:
 *   - /.well-known/tdmrep.json   (Tdmrep::json)
 *   - the tdm-reservation header decision (Endpoints::tdmrep_state)
 *   - the settings that drive them (defaults + sanitise)
 *
 * The load-bearing rules: the reservation mirrors content_signal.ai_train
 * exactly; per-bot escalation only applies in the header (and only when opted
 * in); and the default-ON channels survive a partial update that omits the key.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\WellKnown;
use Agentimus\Endpoints;
use Agentimus\Readiness;
use Agentimus\Settings;
use Agentimus\Tdmrep;
use PHPUnit\Framework\TestCase;

final class AiUsageSignalsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Persist a partial content_signal (merged over defaults by Settings::all). */
	private function set_signal( array $signal ): void {
		update_option( Settings::OPTION, array( 'content_signal' => $signal ) );
	}

	/* -- tdmrep.json body ------------------------------------------------- */

	public function test_tdmrep_defaults_to_reserved() {
		// Fresh install: ai_train defaults to false → "no training" → reserved.
		$doc = json_decode( ( new Tdmrep( new Settings() ) )->json(), true );
		$this->assertSame( '/', $doc[0]['location'] );
		$this->assertSame( 1, $doc[0]['tdm-reservation'] );
	}

	public function test_tdmrep_not_served_when_training_allowed() {
		$this->set_signal( array( 'ai_train' => true ) );
		$this->assertSame( '', ( new Tdmrep( new Settings() ) )->json(), 'An open site serves no opt-out file — on the web, absence already means "allowed".' );
	}

	public function test_tdmrep_includes_policy_url_when_set() {
		update_option( Settings::OPTION, array( 'tdm_policy_url' => 'https://example.test/ai-policy' ) );
		$doc = json_decode( ( new Tdmrep( new Settings() ) )->json(), true );
		$this->assertSame( 'https://example.test/ai-policy', $doc[0]['tdm-policy'] );
	}

	public function test_tdmrep_is_empty_when_channel_off() {
		update_option( Settings::OPTION, array( 'enable_tdmrep' => false ) );
		$this->assertSame( '', ( new Tdmrep( new Settings() ) )->json(), 'Off must yield "" so the router emits a clean 404, not a stub.' );
	}

	/* -- tdm-reservation header decision (pure) --------------------------- */

	public function test_state_reserved_globally_by_default() {
		$state = ( new Endpoints( new Settings() ) )->tdmrep_state();
		$this->assertTrue( $state['reserved'] );
	}

	public function test_state_not_reserved_when_training_allowed() {
		$this->set_signal( array( 'ai_train' => true ) );
		$state = ( new Endpoints( new Settings() ) )->tdmrep_state();
		$this->assertFalse( $state['reserved'] );
	}

	/* -- settings: defaults + sanitise ------------------------------------ */

	public function test_defaults_expose_the_new_channels() {
		$d = ( new Settings() )->defaults();
		$this->assertTrue( $d['enable_ai_header'] );
		$this->assertTrue( $d['enable_tdmrep'] );
		$this->assertFalse( $d['ai_noai_header'] );
		$this->assertSame( '', $d['tdm_policy_url'] );
	}

	public function test_sanitize_keeps_default_on_channels_when_key_omitted() {
		// A partial update that doesn't mention the channels must not silently
		// disable them (the isset-guard, mirroring block_spoofed).
		$clean = ( new Settings() )->sanitize( array() );
		$this->assertTrue( $clean['enable_ai_header'] );
		$this->assertTrue( $clean['enable_tdmrep'] );
	}

	public function test_sanitize_respects_an_explicit_off_and_cleans_policy_url() {
		$clean = ( new Settings() )->sanitize( array(
			'enable_ai_header' => false,
			'enable_tdmrep'    => false,
			'ai_noai_header'   => true,
			'tdm_policy_url'   => '  https://example.test/policy  ',
		) );
		$this->assertFalse( $clean['enable_ai_header'] );
		$this->assertFalse( $clean['enable_tdmrep'] );
		$this->assertTrue( $clean['ai_noai_header'] );
		$this->assertSame( 'https://example.test/policy', $clean['tdm_policy_url'] );
	}

	/* -- routing + readiness ---------------------------------------------- */

	public function test_tdmrep_is_a_routed_well_known_name() {
		$this->assertContains( 'tdmrep.json', WellKnown::routed_names() );
	}

	/** Reflection-call the private readiness check (report() touches WP-heavy deps). */
	private function ai_check(): array {
		$m = new \ReflectionMethod( Readiness::class, 'check_ai_usage_policy' );
		$m->setAccessible( true );
		return $m->invoke( new Readiness( new Settings() ) );
	}

	public function test_readiness_passes_when_reserved_and_a_channel_is_on() {
		// Defaults: reserved + both new channels on.
		$row = $this->ai_check();
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'tdmrep.json', $row['detail'] );
	}

	public function test_readiness_warns_when_reserved_but_channels_off() {
		update_option( Settings::OPTION, array(
			'enable_ai_header' => false,
			'enable_tdmrep'    => false,
		) );
		$row = $this->ai_check();
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'ar-feat-enable_ai_header', $row['action']['anchor'] );
	}

	public function test_readiness_pass_is_informational_when_training_allowed() {
		$this->set_signal( array( 'ai_train' => true ) );
		$row = $this->ai_check();
		$this->assertSame( 'pass', $row['status'] );
	}
}
