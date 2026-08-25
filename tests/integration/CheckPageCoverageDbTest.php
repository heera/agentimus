<?php
/**
 * check-page used to answer only HALF the question it was asked.
 *
 * ⛔⛔ THE FAULT THESE PIN. Whether a page needs work has two halves
 * {@see \Agentimus\Worklist::needs_work()}: the content checks, and whether the
 * page's words answer the search it is found for. The whole-site worklist read
 * both. The PER-PAGE tool an agent naturally reaches for — check-page — graded
 * the content checks and nothing else, so a page flagged ONLY on coverage came
 * back a spotless pass/warn/fail tally with no mention of the thing that put it
 * on the list. An agent told "fix this page" read that as "nothing to fix" and
 * reported success on work it had not done.
 *
 * Measured on a real site the day this was written: all 28 rows of the owner's
 * worklist were coverage-only, so the per-page reading was wrong every single
 * time it was asked.
 *
 * ⭐ The owner was never blind to it — the editor's Search & AI panel has always
 * shown the verdict and its reason. The blindness belonged to the agent alone,
 * which is the shape worth naming: a surface the owner has and an agent does not.
 *
 * ⛔ And the silence must not read as a verdict. A page no engine has reported
 * has nothing to be measured against; saying "missing" there would be a reading
 * of the page invented out of the absence of a question.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Focus;
use Agentimus\Grades;
use Agentimus\PageCheck;
use Agentimus\Search\Coverage;
use Agentimus\Search\Table;
use Agentimus\Settings;
use Agentimus\Worklist;

final class CheckPageCoverageDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
		delete_option( Table::VERSION_OPTION );
		Table::install();
		$this->reset();
	}

	public function tear_down(): void {
		$this->reset();
		parent::tear_down();
	}

	/** ⚠️ DELETE, never TRUNCATE — TRUNCATE commits and ends the test's transaction. */
	private function reset() {
		global $wpdb;
		foreach ( array( Grades::name(), Table::name(), $wpdb->postmeta, $wpdb->posts ) as $table ) {
			$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
		}
	}

	private function connect_google() {
		( new \Agentimus\Google\Settings() )->connect( '{"type":"service_account"}', 'bot@example.com', 'sc-domain:example.com' );
	}

	/**
	 * A page with nothing wrong with it AS CONTENT — short enough that the
	 * checks wanting headings, sources and figures do not apply, plain enough to
	 * read easily, and carrying its own opening summary. The only thing left
	 * that can put it on the worklist is coverage, which is the whole point.
	 */
	private function clean_post( $title = 'Cross-tab locking in the browser' ) {
		$body = '<p>Two browser tabs of the same app will happily run the same background job twice, '
			. 'and the second run is the one that corrupts your data. The browser ships a lock for this.</p>'
			. '<p>Ask for a named lock before the job starts. The tab that gets it runs the work. '
			. 'Every other tab waits its turn, and the lock is released the moment that tab goes away.</p>'
			. '<p>That last part is what makes it safe. A tab that crashes cannot hold a lock for ever, '
			. 'because the browser owns the lock and not your code. Nothing has to time out and guess.</p>';
		$id = (int) self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $body,
		) );
		// ⚠️ A featured image, DESCRIBED. Without one the page warns on
		// `featured_image`, and a fixture that is not spotless cannot show that a
		// spotless page still needs work — the fault would hide behind a real flag.
		$thumb = (int) self::factory()->attachment->create_object(
			'cross-tab-lock.png',
			$id,
			array( 'post_mime_type' => 'image/png', 'post_type' => 'attachment' )
		);
		update_post_meta( $thumb, '_wp_attachment_image_alt', 'Two browser tabs, one holding a lock' );
		set_post_thumbnail( $id, $thumb );
		return $id;
	}

	private function reported( $post_id, $query, $impressions = 400 ) {
		return array(
			'query'       => $query,
			'page_url'    => get_permalink( $post_id ),
			'page_id'     => (int) $post_id,
			'clicks'      => 0,
			'impressions' => (int) $impressions,
			'position'    => 8.4,
			'range_start' => '2026-07-01',
			'range_end'   => '2026-07-28',
		);
	}

	/** Exactly what the check-page ability returns, built the way it builds it. */
	private function check_page( $post_id ) {
		$post     = get_post( $post_id );
		$worklist = new Worklist( new Settings() );
		$rows     = PageCheck::analyze( $post );
		$coverage = $worklist->coverage_for( $post );
		return array(
			'summary'   => PageCheck::summary( $rows ),
			'checks'    => $rows,
			'needsWork' => $worklist->needs_work(
				array(
					'flags'    => PageCheck::summarize( $rows )['flags'],
					'coverage' => array( 'state' => $coverage['state'] ),
				)
			),
			'coverage'  => $coverage,
		);
	}

	/* ------------------------------------------------------------ the fault */

	/**
	 * ⭐⭐ THE ONE THIS EXISTS FOR. Every content check passes. The page still
	 * needs work, and check-page has to say so.
	 */
	public function test_a_page_that_passes_every_check_still_needs_work_when_it_does_not_answer_its_search() {
		$this->connect_google();
		$id = $this->clean_post();
		Table::replace( 'google', array( $this->reported( $id, 'postgres replication lag alerting' ) ) );

		$out = $this->check_page( $id );

		$this->assertSame(
			0,
			$out['summary']['warn'] + $out['summary']['fail'],
			'FIXTURE: this page has to be spotless as content, or it cannot demonstrate the fault.'
		);
		$this->assertTrue(
			$out['needsWork'],
			'⛔⛔ THE BUG: a spotless content tally used to be the whole answer, so an agent read this page as finished.'
		);
		$this->assertTrue( $out['coverage']['measured'] );
		$this->assertNotSame( Coverage::ANSWERED, $out['coverage']['state'] );
		$this->assertNotSame( '', $out['coverage']['detail'], 'A verdict has to say why, or nobody can act on it.' );
	}

	/**
	 * ⭐ WHICH words, not how many. A count says "1 of 4"; this names the three
	 * to go and write about — the difference between a verdict and a task.
	 */
	public function test_it_names_the_words_the_page_does_not_carry() {
		$this->connect_google();
		$id = $this->clean_post();
		Table::replace( 'google', array( $this->reported( $id, 'browser lock postgres replication' ) ) );

		$cov = $this->check_page( $id )['coverage'];

		$this->assertNotSame( array(), $cov['terms'], 'The words are the actionable half.' );
		$by = array();
		foreach ( $cov['terms'] as $term ) {
			$by[ $term['word'] ] = $term['onPage'];
		}
		$this->assertArrayHasKey( 'postgres', $by );
		$this->assertFalse( $by['postgres'], 'Not a word of this page — it has to come back false.' );
		$this->assertTrue( $by['lock'], 'The page is about locks; a word it DOES carry must not be reported missing.' );
	}

	/**
	 * ⛔ The search a page is judged on is a decision, and two surfaces naming
	 * different searches for one page is the same species of fault as two
	 * surfaces counting one thing differently.
	 */
	public function test_it_names_the_same_search_the_worklist_names() {
		$this->connect_google();
		$id = $this->clean_post();
		Table::replace( 'google', array( $this->reported( $id, 'postgres replication lag alerting' ) ) );
		( new Worklist( new Settings() ) )->sweep( 50 );

		$row = null;
		foreach ( ( new Worklist( new Settings() ) )->issues( 'fixable', 1, 30 )['items'] as $item ) {
			if ( (int) $item['id'] === $id ) {
				$row = $item;
			}
		}
		$this->assertNotNull( $row, 'The page has to be on the list for the two to be comparable.' );

		$cov = $this->check_page( $id )['coverage'];
		$this->assertSame( $row['search']['query'], $cov['query'], '⛔ One chooser, both surfaces.' );
		$this->assertSame( $row['coverage'], $cov['state'], '⛔ …and one reading.' );
	}

	/**
	 * ⛔⛔ AN UNKNOWN IS NOT A MEASUREMENT. No engine has reported this page and
	 * nobody chose what it is for; there is no question, so there is no answer.
	 */
	public function test_a_page_with_no_search_is_not_reported_as_a_page_that_failed() {
		$this->connect_google();
		$id    = $this->clean_post();
		$other = $this->clean_post( 'Something else entirely' );
		// The engine HAS reported — just never about this page. ⚠️ With an empty
		// table the honest answer is "collecting", a different silence with a
		// different remedy, and pinning the wrong one here would hide that.
		Table::replace( 'google', array( $this->reported( $other, 'something else entirely' ) ) );

		$out = $this->check_page( $id );

		$this->assertFalse( $out['coverage']['measured'] );
		$this->assertSame(
			'',
			$out['coverage']['state'],
			'⛔ Empty, never "missing" — "missing" is a reading of the page, and no page was read against anything.'
		);
		$this->assertSame( 'no_search', $out['coverage']['reason'], 'The absence has to name itself.' );
		$this->assertNotSame( '', $out['coverage']['detail'] );
		$this->assertFalse( $out['needsWork'], 'Nothing is asking for anything: clean content, and no search to miss.' );
	}

	/** With no source connected at all, the silence is a different one, and says so. */
	public function test_it_distinguishes_no_source_from_no_search() {
		$id  = $this->clean_post();
		$cov = $this->check_page( $id )['coverage'];

		$this->assertFalse( $cov['measured'] );
		$this->assertSame( 'not_connected', $cov['reason'] );
		$this->assertSame( '', $cov['state'] );
	}

	/**
	 * A focus the OWNER typed is still a question, even on a site no engine has
	 * reported — and then no engine is the one claiming it.
	 */
	public function test_an_owner_chosen_focus_is_measured_with_no_engine_behind_it() {
		$id = $this->clean_post();
		update_post_meta( $id, Focus::META, 'browser tab lock' );

		$cov = $this->check_page( $id )['coverage'];

		$this->assertTrue( $cov['measured'], 'A typed focus is a question this can answer.' );
		$this->assertTrue( $cov['chosen'] );
		$this->assertSame( '', $cov['engine'], '⛔ Nobody reported this — the site must not put an engine’s name to the owner’s own guess.' );
		$this->assertSame( 'browser tab lock', $cov['query'] );
	}

	/** A page that answers its search is finished, and the tool has to say THAT too. */
	public function test_a_page_that_answers_its_search_is_not_asking_for_anything() {
		$this->connect_google();
		$id = $this->clean_post();
		Table::replace( 'google', array( $this->reported( $id, 'named lock tab' ) ) );

		$out = $this->check_page( $id );

		$this->assertSame( Coverage::ANSWERED, $out['coverage']['state'] );
		$this->assertFalse( $out['needsWork'], 'Clean content and an answered search is a finished page.' );
		$this->assertNotSame( '', $out['coverage']['quote'], 'It can show the passage it found.' );
	}

	/**
	 * ⛔ The 1.30.0 bug: ability output validates with additionalProperties:false,
	 * so an undeclared key makes the tool reject its own honest response for every
	 * MCP client while the admin screens carry on working and hide it. Validated
	 * against the REAL registered schema, on a REAL payload, in every state.
	 */
	public function test_every_state_survives_the_abilitys_own_output_schema() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'No Abilities API on this core — the unit drift test covers the declaration.' );
		}
		// ⛔ NOT re-registered here: WP 6.9 refuses any registration outside
		// `wp_abilities_api_init` and raises a doing-it-wrong that fails the test.
		// The plugin has already registered by now — read what IT registered,
		// which is also the only schema real clients ever see.
		$ability = wp_get_ability( 'agentimus/check-page' );
		if ( ! $ability ) {
			$this->markTestSkipped( 'check-page is not registered on this core.' );
		}
		$schema = $ability->get_output_schema();

		$this->connect_google();
		$id = $this->clean_post();

		$states = array(
			'answered'   => 'named lock tab',
			'barely'     => 'browser postgres replication',
			'missing'    => 'postgres replication lag',
			'no_search'  => null,
		);
		foreach ( $states as $name => $query ) {
			Table::replace( 'google', null === $query ? array() : array( $this->reported( $id, $query ) ) );
			$valid = rest_validate_value_from_schema( $this->check_page( $id ), $schema, 'check-page' );
			$this->assertNotWPError( $valid, "check-page rejected its own response in the `$name` state: " . ( is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
		}
	}
}
