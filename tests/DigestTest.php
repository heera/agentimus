<?php
/**
 * Weekly digest — the quiet-week gate, the subject, the rendered body's
 * promises (first-note, stop link, escaping, honest comparisons, no zero-count
 * sections), and the Module's send flow (recipient resolution, snapshot
 * discipline, the test-send bypass).
 *
 * Renderer is pure and Module::deliver() takes the data array directly, so
 * everything here runs on fixtures — no database, no mailer. wp_mail is the
 * bootstrap capture stub ($GLOBALS['_af_mail']).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Digest\Module;
use Agentimus\Digest\Renderer;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class DigestTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** A believable full week. Override pieces per test. */
	private function fixture( array $over = array() ): array {
		return array_replace_recursive(
			array(
				'site_name' => 'Test Site',
				'first'     => false,
				'period'    => array(
					'label' => 'Jul 13, 2026 – Jul 19, 2026',
					'days'  => 7,
				),
				'agents'    => array(
					'total'     => 143,
					'prev'      => 102,
					'by_client' => array(
						array( 'label' => 'ChatGPT', 'hits' => 64 ),
						array( 'label' => 'PerplexityBot', 'hits' => 31 ),
					),
				),
				'referrals' => array(
					'total'     => 12,
					'prev'      => 3,
					'by_source' => array( array( 'label' => 'ChatGPT', 'hits' => 9 ) ),
				),
				'impostors' => array( 'total' => 9 ),
				'access'    => array( 'events' => 4 ),
				'score'     => array( 'now' => 84, 'prev' => 78 ),
				'nudge'     => array( 'label' => 'Add a featured image', 'detail' => 'Link previews have no picture to show.' ),
				'links'     => array(
					'dashboard' => 'https://example.test/wp-admin/admin.php?page=agentimus',
					'stop'      => 'https://example.test/wp-admin/admin-post.php?action=agentimus_digest_stop&k=abc',
				),
			),
			$over
		);
	}

	/** Every count zeroed, score flat — the week the digest must stay silent about. */
	private function quiet_fixture( array $over = array() ): array {
		return $this->fixture(
			array_replace_recursive(
				array(
					'agents'    => array( 'total' => 0, 'prev' => 0, 'by_client' => array() ),
					'referrals' => array( 'total' => 0, 'prev' => 0, 'by_source' => array() ),
					'impostors' => array( 'total' => 0 ),
					'access'    => array( 'events' => 0 ),
					'score'     => array( 'now' => 84, 'prev' => 84 ),
					'nudge'     => null,
				),
				$over
			)
		);
	}

	/* -- The quiet-week gate ---------------------------------------------- */

	public function test_a_quiet_week_is_not_sent() {
		$this->assertFalse( Renderer::should_send( $this->quiet_fixture() ) );
	}

	public function test_any_agent_traffic_sends() {
		$data = $this->quiet_fixture( array( 'agents' => array( 'total' => 1 ) ) );
		$this->assertTrue( Renderer::should_send( $data ) );
	}

	public function test_a_score_move_alone_sends() {
		$data = $this->quiet_fixture( array( 'score' => array( 'now' => 85, 'prev' => 84 ) ) );
		$this->assertTrue( Renderer::should_send( $data ) );
	}

	public function test_first_run_with_no_traffic_stays_silent() {
		// prev score is null on the first run — that must read as "did not move",
		// not as movement, or a dead site gets a pointless first email.
		$data = $this->quiet_fixture( array( 'first' => true, 'score' => array( 'now' => 84, 'prev' => null ) ) );
		$this->assertFalse( Renderer::should_send( $data ) );
	}

	/* -- Subject ----------------------------------------------------------- */

	public function test_subject_leads_with_reads_and_score() {
		$this->assertSame( 'Your site’s AI week: 143 agent reads, score 84', Renderer::subject( $this->fixture() ) );
	}

	public function test_subject_without_a_score_still_counts_reads() {
		$data = $this->fixture( array( 'score' => array( 'now' => null, 'prev' => null ) ) );
		$this->assertSame( 'Your site’s AI week: 143 agent reads', Renderer::subject( $data ) );
	}

	/* -- Body -------------------------------------------------------------- */

	public function test_first_email_explains_itself_and_later_ones_do_not() {
		$first = Renderer::html( $this->fixture( array( 'first' => true ) ) );
		$later = Renderer::html( $this->fixture() );
		$this->assertStringContainsString( 'first weekly note', $first );
		$this->assertStringNotContainsString( 'first weekly note', $later );
	}

	public function test_the_stop_link_is_always_present() {
		$html = Renderer::html( $this->fixture() );
		$this->assertStringContainsString( 'action=agentimus_digest_stop', $html );
		$this->assertStringContainsString( 'Stop these emails', $html );
	}

	public function test_client_labels_are_escaped() {
		$data = $this->fixture(
			array( 'agents' => array( 'by_client' => array( array( 'label' => '<script>alert(1)</script>', 'hits' => 5 ) ) ) )
		);
		$html = Renderer::html( $data );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_comparison_reads_in_plain_words() {
		$up = Renderer::html( $this->fixture() );
		$this->assertStringContainsString( 'Up from 102 the week before.', $up );

		$down = Renderer::html( $this->fixture( array( 'agents' => array( 'total' => 50, 'prev' => 102 ) ) ) );
		$this->assertStringContainsString( 'Down from 102 the week before.', $down );

		$flat = Renderer::html( $this->fixture( array( 'agents' => array( 'total' => 102, 'prev' => 102 ) ) ) );
		$this->assertStringContainsString( 'Same as the week before.', $flat );
	}

	public function test_an_unknowable_prior_week_says_nothing() {
		// retention < two windows → prev is null everywhere → no comparison lines at all.
		$html = Renderer::html(
			$this->fixture(
				array(
					'agents'    => array( 'prev' => null ),
					'referrals' => array( 'prev' => null ),
				)
			)
		);
		$this->assertStringNotContainsString( 'the week before', $html );
	}

	public function test_zero_count_sections_stay_out() {
		$html = Renderer::html( $this->fixture( array( 'impostors' => array( 'total' => 0 ), 'access' => array( 'events' => 0 ) ) ) );
		$this->assertStringNotContainsString( 'Impostors', $html );
		$this->assertStringNotContainsString( 'Connected agents', $html );
	}

	public function test_first_digest_score_has_no_invented_delta() {
		// Traffic comparisons may legitimately appear on a first digest (the log
		// already holds the prior week) — only the SCORE delta must stay silent,
		// because its baseline is the snapshot and no snapshot exists yet.
		$html = Renderer::html( $this->fixture( array( 'first' => true, 'score' => array( 'now' => 84, 'prev' => null ) ) ) );
		$this->assertStringContainsString( 'no earlier score to compare', $html );
		$this->assertStringNotContainsString( 'last week', $html );
	}

	public function test_no_external_resources_ever() {
		// No <img>, no external stylesheet/script — the no-phone-home promise
		// applies to the email itself.
		$html = Renderer::html( $this->fixture( array( 'first' => true ) ) );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<link', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/* -- Module: recipient, send flow, snapshot ----------------------------- */

	private function module(): Module {
		return new Module( new Settings() );
	}

	public function test_recipient_falls_back_to_the_admin_email() {
		update_option( 'admin_email', 'owner@example.test' );
		$this->assertSame( 'owner@example.test', $this->module()->recipient() );
	}

	public function test_recipient_honours_a_valid_override() {
		update_option( 'admin_email', 'owner@example.test' );
		update_option( Settings::OPTION, array( 'digest_recipient' => 'reports@example.test' ) );
		$this->assertSame( 'reports@example.test', $this->module()->recipient() );
	}

	public function test_an_invalid_override_falls_back_instead_of_failing() {
		update_option( 'admin_email', 'owner@example.test' );
		update_option( Settings::OPTION, array( 'digest_recipient' => 'not-an-address' ) );
		$this->assertSame( 'owner@example.test', $this->module()->recipient() );
	}

	public function test_deliver_sends_and_snapshots() {
		update_option( 'admin_email', 'owner@example.test' );
		$sent = $this->module()->deliver( $this->fixture() );

		$this->assertTrue( $sent );
		$this->assertCount( 1, $GLOBALS['_af_mail'] );
		$mail = $GLOBALS['_af_mail'][0];
		$this->assertSame( 'owner@example.test', $mail['to'] );
		$this->assertStringContainsString( '143 agent reads', $mail['subject'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', (array) $mail['headers'] );

		$snapshot = get_option( Module::SNAPSHOT_OPTION );
		$this->assertSame( 84, $snapshot['score'] );
	}

	public function test_deliver_skips_a_quiet_week_entirely() {
		update_option( 'admin_email', 'owner@example.test' );
		$this->assertFalse( $this->module()->deliver( $this->quiet_fixture() ) );
		$this->assertSame( array(), $GLOBALS['_af_mail'] );
		$this->assertFalse( get_option( Module::SNAPSHOT_OPTION, false ) );
	}

	public function test_a_test_send_bypasses_the_gate_but_not_the_snapshot() {
		update_option( 'admin_email', 'owner@example.test' );
		$sent = $this->module()->deliver( $this->quiet_fixture(), true );

		$this->assertTrue( $sent );
		$this->assertCount( 1, $GLOBALS['_af_mail'] );
		$this->assertStringStartsWith( 'Test: ', $GLOBALS['_af_mail'][0]['subject'] );
		// A test must not eat next week's comparison baseline.
		$this->assertFalse( get_option( Module::SNAPSHOT_OPTION, false ) );
	}

	public function test_a_failed_send_keeps_the_old_baseline() {
		update_option( 'admin_email', 'owner@example.test' );
		$GLOBALS['_af_mail_ok'] = false;
		$this->assertFalse( $this->module()->deliver( $this->fixture() ) );
		// No snapshot written: next week still compares against what was last SENT.
		$this->assertFalse( get_option( Module::SNAPSHOT_OPTION, false ) );
	}

	public function test_stop_url_mints_and_reuses_one_key() {
		$first  = Module::stop_url();
		$second = Module::stop_url();
		$this->assertSame( $first, $second );
		$this->assertStringContainsString( 'action=' . Module::STOP_ACTION, $first );
		$this->assertNotEmpty( get_option( Module::STOP_KEY_OPTION ) );
	}

	/* -- The send slot ------------------------------------------------------ */

	private function moment( string $stamp ): \DateTimeImmutable {
		return new \DateTimeImmutable( $stamp, new \DateTimeZone( 'UTC' ) );
	}

	public function test_next_occurrence_lands_on_the_chosen_day_and_hour() {
		// From Wednesday noon, "Friday at 8" is two days ahead.
		$next = Module::next_occurrence( 5, 8, $this->moment( '2026-07-22 12:00:00' ) );
		$this->assertSame( '2026-07-24 08:00:00', gmdate( 'Y-m-d H:i:s', $next ) );
	}

	public function test_a_slot_later_today_still_counts_as_today() {
		$next = Module::next_occurrence( 3, 15, $this->moment( '2026-07-22 08:00:00' ) );
		$this->assertSame( '2026-07-22 15:00:00', gmdate( 'Y-m-d H:i:s', $next ) );
	}

	public function test_a_slot_already_passed_waits_a_full_week() {
		$next = Module::next_occurrence( 3, 8, $this->moment( '2026-07-22 09:30:00' ) );
		$this->assertSame( '2026-07-29 08:00:00', gmdate( 'Y-m-d H:i:s', $next ) );
	}

	public function test_exactly_on_the_slot_schedules_next_week_not_now() {
		// Strictly future: an event scheduled AT "now" would fire immediately and
		// then again next week — one send too many.
		$next = Module::next_occurrence( 3, 8, $this->moment( '2026-07-22 08:00:00' ) );
		$this->assertSame( '2026-07-29 08:00:00', gmdate( 'Y-m-d H:i:s', $next ) );
	}

	public function test_sunday_is_day_seven() {
		$next = Module::next_occurrence( 7, 20, $this->moment( '2026-07-22 12:00:00' ) );
		$this->assertSame( '2026-07-26 20:00:00', gmdate( 'Y-m-d H:i:s', $next ) );
	}

	/* -- Settings ----------------------------------------------------------- */

	public function test_digest_defaults_on_with_empty_recipient() {
		$defaults = ( new Settings() )->defaults();
		$this->assertTrue( $defaults['digest_enabled'] );
		$this->assertSame( '', $defaults['digest_recipient'] );
	}

	public function test_digest_defaults_to_monday_morning() {
		$defaults = ( new Settings() )->defaults();
		$this->assertSame( 1, $defaults['digest_day'] );
		$this->assertSame( 8, $defaults['digest_hour'] );
	}

	public function test_sanitize_keeps_a_chosen_slot() {
		$clean = ( new Settings() )->sanitize( array( 'digest_day' => 5, 'digest_hour' => 18 ) );
		$this->assertSame( 5, $clean['digest_day'] );
		$this->assertSame( 18, $clean['digest_hour'] );
	}

	public function test_sanitize_snaps_an_impossible_slot_to_the_default() {
		$clean = ( new Settings() )->sanitize( array( 'digest_day' => 0, 'digest_hour' => 24 ) );
		$this->assertSame( 1, $clean['digest_day'] );
		$this->assertSame( 8, $clean['digest_hour'] );
	}

	public function test_sanitize_keeps_a_real_recipient_and_drops_a_broken_one() {
		$clean = ( new Settings() )->sanitize( array( 'digest_recipient' => 'reports@example.test' ) );
		$this->assertSame( 'reports@example.test', $clean['digest_recipient'] );

		$clean = ( new Settings() )->sanitize( array( 'digest_recipient' => 'not an address' ) );
		$this->assertSame( '', $clean['digest_recipient'] );
	}
}
