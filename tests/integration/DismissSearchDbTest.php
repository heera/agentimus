<?php
/**
 * The third ledger: setting aside a SEARCH, not a page.
 *
 * ⛔⛔ WHY IT HAD TO EXIST. A worklist row saying "this page does not answer the
 * search it is found for" can be true two ways: the PAGE is lacking, or the
 * SEARCH is not a question this site could ever answer. There was a lever for
 * the first and none for the second — so the only move available was to set
 * aside the PAGE, which removes good writing from the worklist to silence a bad
 * query. The set-aside tool's own text warns against precisely that, and on a
 * real site it had already happened: a post was excused from grading because a
 * spam URL pointed at it.
 *
 * ⭐ These pin the two guards that keep it a ledger of decisions rather than a
 * store of arbitrary strings, and the reversibility that keeps a decision from
 * becoming a trap.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\Triage;
use Agentimus\Search\Noise;
use Agentimus\Search\Table;
use Agentimus\Settings;

final class DismissSearchDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Table::VERSION_OPTION );
		Table::install();
		$this->reset();
		Noise::flush();
	}

	public function tear_down(): void {
		$this->reset();
		Noise::flush();
		parent::tear_down();
	}

	private function reset() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		$s   = new Settings();
		$all = $s->stored();
		$all['search_dismissed'] = array();
		$s->update( $all );
	}

	private function engines_reported( $query ) {
		Table::replace( 'google', array(
			array(
				'query'       => $query,
				'page_url'    => home_url( '/hello-world/' ),
				'page_id'     => 1,
				'clicks'      => 0,
				'impressions' => 40,
				'position'    => 7.0,
				'range_start' => '2026-07-01',
				'range_end'   => '2026-07-28',
			),
		) );
	}

	private function triage() {
		return new Triage( new Settings() );
	}

	/* ------------------------------------------------------------- the point */

	public function test_a_dismissed_search_stops_judging_every_page() {
		$this->engines_reported( 'yes or no' );
		$this->assertSame( '', Noise::kind( 'yes or no' ), 'A plain short phrase is not detectable noise.' );

		$out = $this->triage()->dismiss_search( 'yes or no', true );

		$this->assertIsArray( $out );
		$this->assertTrue( $out['dismissed'] );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( Noise::DISMISSED, Noise::kind( 'yes or no' ) );
		$this->assertTrue( Noise::is_noise( 'yes or no' ), '⛔ Every surface that judges a page by a search must now skip it.' );
	}

	/** ⛔ Nothing about any page changes — that is the whole difference from set-aside-page. */
	public function test_it_touches_no_page_and_no_other_ledger() {
		$this->engines_reported( 'yes or no' );
		$before = ( new Settings() )->get( 'optimize_ignored', array() );

		$this->triage()->dismiss_search( 'yes or no', true );

		$this->assertSame( $before, ( new Settings() )->get( 'optimize_ignored', array() ), '⛔ The citability ledger is a different judgement and must not move.' );
		$this->assertSame( array(), ( new Settings() )->get( 'search_ignored', array() ), '⛔ Nor the page-level search ledger.' );
	}

	/**
	 * ⛔ THE GUARD. Without it this stops being a ledger of decisions and becomes
	 * a store of arbitrary strings an agent can fill with anything.
	 */
	public function test_it_refuses_a_search_no_engine_ever_reported() {
		$out = $this->triage()->dismiss_search( 'zqxjv wombat telegraph', true );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'agentimus_unknown_query', $out->get_error_code() );
		$this->assertSame( array(), ( new Settings() )->get( 'search_dismissed', array() ) );
	}

	/**
	 * ⭐ …but putting one BACK is always allowed. An engine that stops reporting
	 * a search must never strand a decision with no way to undo it: a decision
	 * has to be reversible on worse evidence than it was made on.
	 */
	public function test_restoring_never_needs_the_search_to_still_be_reported() {
		$this->engines_reported( 'yes or no' );
		$this->triage()->dismiss_search( 'yes or no', true );

		// The engine goes quiet — the search is gone from the report entirely.
		// ⚠️ Emptied directly, NOT via replace( 'google', array() ): that is a
		// deliberate no-op so an empty poll can never wipe a stored report.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		$this->assertFalse( Table::has_query( 'yes or no' ) );

		$out = $this->triage()->dismiss_search( 'yes or no', false );

		$this->assertIsArray( $out, '⛔ Refusing here would trap the owner’s own decision.' );
		$this->assertFalse( $out['dismissed'] );
		$this->assertSame( '', Noise::kind( 'yes or no' ) );
	}

	/** One spelling, so a decision can always be found again to undo it. */
	public function test_case_and_spacing_cannot_split_one_search_into_two_entries() {
		$this->engines_reported( 'Yes Or No' );

		$this->triage()->dismiss_search( '  YES   or   No  ', true );

		$this->assertTrue( Noise::is_dismissed( 'yes or no' ) );
		$this->assertSame( array( 'yes or no' ), ( new Settings() )->get( 'search_dismissed', array() ) );
	}

	/** A no-op must report itself as one — a partial outcome never reads as success. */
	public function test_dismissing_twice_reports_no_change() {
		$this->engines_reported( 'yes or no' );
		$this->triage()->dismiss_search( 'yes or no', true );

		$again = $this->triage()->dismiss_search( 'yes or no', true );

		$this->assertTrue( $again['dismissed'] );
		$this->assertFalse( $again['changed'], '⛔ Nothing was written; saying otherwise is a lie about what happened.' );
	}

	/** The whole ledger rides back, so no caller keeps its own copy of the count. */
	public function test_it_answers_with_the_whole_ledger() {
		$this->engines_reported( 'yes or no' );
		$this->triage()->dismiss_search( 'yes or no', true );
		$this->engines_reported( 'on the website itself' );
		$out = $this->triage()->dismiss_search( 'on the website itself', true );

		$this->assertSame( array( 'on the website itself', 'yes or no' ), $out['setAside'] );
	}

	/**
	 * ⛔ An automatic rule that also applies is the BINDING reason. A screen
	 * offering "restore" on a row that cannot come back is lying about its button.
	 */
	public function test_an_automatic_rule_outranks_the_owners_dismissal_as_the_reason() {
		Table::replace( 'google', array(
			array(
				'query'       => 'site:example.com thing',
				'page_url'    => home_url( '/hello-world/' ),
				'page_id'     => 1,
				'clicks'      => 0,
				'impressions' => 5,
				'position'    => 9.0,
				'range_start' => '2026-07-01',
				'range_end'   => '2026-07-28',
			),
		) );
		$this->triage()->dismiss_search( 'site:example.com thing', true );

		$this->assertSame(
			Noise::OPERATOR,
			Noise::kind( 'site:example.com thing' ),
			'⛔ Restoring this would not bring it back, so the operator rule is the reason to show.'
		);
	}
}
