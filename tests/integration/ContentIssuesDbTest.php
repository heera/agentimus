<?php
/**
 * The content worklist, for something that cannot look at a screen.
 *
 * ⭐⭐ THE GAP THIS CLOSES. Driving his own site as an agent, read-findings said
 * "36 Posts and Pages are worth fixing" and handed back a UI anchor — no ids —
 * while check-page needed a post id there was no way to obtain. An agent could
 * CHANGE content and never FIND what needed changing.
 *
 * These tests walk the real path: publish, let the sweep read, then ask the way
 * an agent asks. They pin the three promises the tool makes — that the ranking
 * is the owner's own, that a row names EVERY issue rather than a screen's first
 * three, and that a verdict older than the owner's last save says so — plus the
 * drift guard every ability needs: output validates with additionalProperties
 * false, so a key the callback returns and the schema forgot makes the tool
 * reject its own honest answer for every MCP client.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\Registrar;
use Agentimus\Grades;
use Agentimus\Settings;
use Agentimus\Worklist;

final class ContentIssuesDbTest extends DbTestCase {

	private const NAME = 'agentimus/read-content-issues';

	public function set_up(): void {
		parent::set_up();
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
	}

	/** A published post with enough words to be graded as an article. */
	private function post( $title, $content ) {
		return (int) self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		) );
	}

	/** Read the whole site, the way the cron does. */
	private function sweep() {
		( new Worklist( new Settings() ) )->sweep( 200 );
	}

	private function issues( $filter = 'fixable', $page = 1, $per = 20 ) {
		return ( new Worklist( new Settings() ) )->issues( $filter, $page, $per );
	}

	/* ------------------------------------------------------------ the rows */

	public function test_it_hands_back_the_ids_the_other_tools_need() {
		$thin = $this->post( 'Thin', 'Too short.' );
		$this->sweep();

		$out = $this->issues();
		$ids = array_column( $out['items'], 'id' );

		$this->assertContains( $thin, $ids, 'The page an agent must open has to be IN the answer, by id.' );
		$row = $out['items'][ array_search( $thin, $ids, true ) ];
		$this->assertSame( 'post', $row['postType'], 'The write tools take a type slug, so the row carries one.' );
		$this->assertNotSame( '', $row['url'] );
		$this->assertTrue( $row['needsWork'] );
	}

	public function test_a_row_names_every_issue_not_the_screens_first_three() {
		// Long enough to be judged on its structure: prose with no headings, no
		// figures and nothing cited trips five checks, which is more than a screen
		// row shows.
		$id = $this->post( 'Longer', str_repeat( 'A plain sentence about a plain thing that goes on for a while and never cites anything at all. ', 40 ) );
		$this->sweep();

		$row    = $this->issues()['items'][0];
		$ids    = array_column( $row['issues'], 'id' );
		$screen = ( new Worklist( new Settings() ) )->page( 'fixable', 1, 20 )['items'][0];

		$this->assertSame( $id, (int) $row['id'] );
		// The screen keeps three and counts the rest ("+2 more") because a person
		// can click the row open. A caller that can only act on what it was handed
		// would silently skip the tail — so the tool hands over the whole set, and
		// the screen's own "shown + hidden" is what it must equal.
		$this->assertGreaterThan( 0, (int) $screen['moreFlags'], 'The screen is not truncating here — the comparison would be vacuous.' );
		$this->assertCount( count( $screen['flags'] ) + (int) $screen['moreFlags'], $ids );
		$this->assertSame( $ids, array_unique( $ids ) );
		foreach ( $row['issues'] as $issue ) {
			$this->assertNotSame( '', $issue['id'] );
			$this->assertNotSame( '', $issue['label'], 'An id with no words makes the caller invent the other half.' );
		}
	}

	public function test_the_counts_are_the_ones_the_owner_sees() {
		$this->post( 'Thin', 'Too short.' );
		$this->post( 'Fuller', str_repeat( 'A concrete point backed by a real figure: 42% in 2024. ', 40 ) );
		$this->sweep();

		$out    = $this->issues();
		$counts = $out['counts'];
		$screen = ( new Worklist( new Settings() ) )->page( 'fixable', 1, 20 );

		// ⚠️ One count, read twice — the whole reason this shares the screen's
		// ranking rather than computing a second opinion.
		$this->assertSame( $screen['counts'], $counts );
		$this->assertSame( (int) $screen['total'], (int) $out['total'] );
		$this->assertSame( $counts['fixable'] + $counts['clear'] + $counts['setAside'], 2 );
	}

	public function test_a_page_read_before_its_last_edit_says_so() {
		$id = $this->post( 'Edited after reading', 'Too short.' );
		$this->sweep();

		$before = $this->issues();
		$this->assertFalse( $before['items'][0]['stale'] );
		$this->assertSame( 0, (int) $before['rechecking'] );

		Grades::mark_stale( $id ); // ← what save_post does.

		$after = $this->issues();
		$this->assertSame( $id, (int) $after['items'][0]['id'], 'A page you edited keeps its place.' );
		$this->assertTrue(
			$after['items'][0]['stale'],
			'⛔ The row must say its issues describe the draft before the edit — reporting them as today’s truth is the fault this flag exists to prevent.'
		);
		$this->assertSame( 1, (int) $after['rechecking'] );
	}

	public function test_a_set_aside_page_leaves_the_worklist_and_keeps_its_own_bucket() {
		$id = $this->post( 'Not for quoting', 'Too short.' );
		$this->sweep();

		$settings = new Settings();
		$all      = $settings->all(); // Partial updates reset unset settings — merge into all().
		$all['optimize_ignored'] = array( $id );
		$settings->update( $all );

		$this->assertSame( array(), array_column( $this->issues( 'fixable' )['items'], 'id' ) );
		$parked = $this->issues( 'setAside' );
		$this->assertSame( array( $id ), array_column( $parked['items'], 'id' ) );
		$this->assertTrue( $parked['items'][0]['setAside'] );
	}

	public function test_an_unread_site_says_how_much_it_has_not_looked_at() {
		$this->post( 'Never read', 'Too short.' );

		// No sweep: the store knows nothing. An empty list here is the most
		// expensive lie the tool could tell, so the number that contradicts it
		// has to be in the same payload.
		$out = $this->issues();
		$this->assertSame( array(), $out['items'] );
		$this->assertSame( 1, (int) $out['grading'], 'Nothing read yet must be stated, not implied by an empty list.' );
	}

	/* ----------------------------------------------------------- the schema */

	/** The ability as an MCP client reaches it — the REAL registry, not a stub. */
	private function ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+); the feature self-gates below that.' );
		}
		$ability = wp_get_ability( self::NAME );
		$this->assertNotNull( $ability, 'read-content-issues did not register — the slug moved, or register_abilities() never ran.' );
		return $ability;
	}

	private function output_schema() {
		return $this->ability()->get_output_schema();
	}

	/**
	 * Assert every key an actual payload carries is declared.
	 *
	 * @param array  $declared Schema properties map.
	 * @param array  $actual   A real payload (or row).
	 * @param string $where    Human name for the failure message.
	 * @return void
	 */
	private function assert_declared( array $declared, array $actual, $where ) {
		foreach ( array_keys( $actual ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$declared,
				"$where returns `$key` but the output schema does not declare it — with additionalProperties:false the ability rejects its own response."
			);
		}
	}

	/**
	 * ⭐ The production path, end to end: input validation, the permission gate,
	 * and — the half that matters here — WP_Ability::execute()'s own output
	 * validation. An undeclared key does not get quietly dropped; the ability
	 * rejects its own honest answer for every MCP client, which is how 1.30.0
	 * shipped a read-request-log nobody could call.
	 */
	public function test_the_ability_answers_for_real_over_its_own_schema() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = $this->post( 'Thin', 'Too short.' );
		$this->sweep();

		$out = $this->ability()->execute( array( 'filter' => 'fixable' ) );

		$this->assertNotWPError( $out, 'The tool refused its own payload — a key it returns is undeclared, or the input schema rejected a valid call.' );
		$this->assertSame( $id, (int) $out['items'][0]['id'] );

		// And with no input at all: over REST a read ability is a GET, and an
		// agent that sends nothing must still get the useful bucket.
		$bare = $this->ability()->execute( null );
		$this->assertNotWPError( $bare );
		$this->assertSame( 'fixable', $bare['filter'] );
	}

	public function test_a_reader_without_the_capability_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$out = $this->ability()->execute( array( 'filter' => 'fixable' ) );

		$this->assertWPError( $out, 'The content worklist names every page on the site; it is not a public reading.' );
	}

	public function test_every_key_the_answer_carries_is_declared() {
		$this->post( 'Thin', 'Too short.' );
		$this->sweep();

		$schema = $this->output_schema();
		$out    = $this->issues();

		$this->assertFalse( $schema['additionalProperties'], 'Strict mode is what makes drift fatal rather than silent.' );
		$this->assert_declared( $schema['properties'], $out, 'Worklist::issues()' );

		$this->assertNotEmpty( $out['items'], 'No rows — the row drift check would pass vacuously.' );
		$row = $out['items'][0];
		$this->assert_declared( $schema['properties']['items']['items']['properties'], $row, 'A worklist row' );
		$this->assert_declared( $schema['properties']['counts']['properties'], $out['counts'], 'The bucket counts' );
		$this->assertNotEmpty( $row['issues'], 'No issues — the issue drift check would pass vacuously.' );
		$this->assert_declared(
			$schema['properties']['items']['items']['properties']['issues']['items']['properties'],
			$row['issues'][0],
			'An issue'
		);
	}

	public function test_the_coverage_states_it_declares_are_the_ones_it_can_return() {
		$schema = $this->output_schema();
		$declared = $schema['properties']['items']['items']['properties']['coverage']['enum'];

		foreach ( array( \Agentimus\Search\Coverage::ANSWERED, \Agentimus\Search\Coverage::SCATTERED, \Agentimus\Search\Coverage::BARELY, \Agentimus\Search\Coverage::MISSING ) as $state ) {
			$this->assertContains( $state, $declared, 'A state the store can hold but the schema denies would be rejected on the wire.' );
		}
		$this->assertContains( '', $declared, 'Empty is a real answer: no search to judge the page against.' );
	}
}
