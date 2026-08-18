<?php
/**
 * The stored content grades against a real database.
 *
 * This table is what makes the worklist cover a whole site. Before it, the
 * ranking could only ever be computed over the thirty pages one request could
 * afford to read, so an owner with three hundred pages saw thirty and had no way
 * to reach the rest — you cannot page through a ranking that was never computed.
 *
 * The assertions below are therefore mostly about completeness: that paging
 * loses nothing, that the counts describe the whole site rather than one screen,
 * and that a page whose grade went stale comes back round to be re-read.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Grades;
use Agentimus\Settings;
use Agentimus\Worklist;

final class GradesDbTest extends DbTestCase {

	/** @var array<int,string> The types the worklist grades. */
	private $types = array( 'post', 'page' );

	public function set_up(): void {
		parent::set_up();
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
		$this->reset();
	}

	public function tear_down(): void {
		$this->reset();
		parent::tear_down();
	}

	/**
	 * ⚠️ DELETE, never TRUNCATE — TRUNCATE forces a commit, which ends the
	 * transaction wrapping each test and leaks one test's posts into the next.
	 */
	private function reset() {
		global $wpdb;
		$table = Grades::name();
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->postmeta}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB
	}

	private function post( $title = 'A post' ) {
		return (int) self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_title'   => $title,
			'post_content' => 'Some words about ' . $title . '.',
		) );
	}

	/**
	 * @param int  $id    Post ID.
	 * @param bool $needs Whether it needs work.
	 * @param int  $stake What a fix is worth.
	 * @param bool $focus Whether a search reaches it.
	 */
	private function grade( $id, $needs, $stake, $focus = true ) {
		Grades::record( $id, array(
			'needsWork' => $needs,
			'flags'     => $needs ? 2 : 0,
			'stake'     => $stake,
			'coverage'  => $needs ? 'on_page' : 'answered',
			'hasFocus'  => $focus,
			// ⚠️ The ids and the points BELONG with the flag count: they come from
			// one reading of the page, and a row carrying flags it cannot name is
			// a contradiction the sweep now re-reads {@see Grades::UNREADABLE_SQL}.
			// A fixture that writes one without the others is testing a row this
			// code cannot produce.
			'flagIds'   => $needs ? array( 'summary', 'headings' ) : array(),
			'points'    => $needs ? 60 : 100,
			'gradeable' => true,
			'hash'      => 'h' . $id,
		) );
	}

	/**
	 * A grade with the flag count and the coverage set INDEPENDENTLY.
	 *
	 * grade() above ties them together, which is fine for order and paging but
	 * cannot express the row this whole split is about: no flags at all, and a
	 * search the page does not answer.
	 *
	 * @param int    $id       Post ID.
	 * @param bool   $needs    Whether it needs work.
	 * @param int    $flags    How many content flags it carries.
	 * @param string $coverage A Coverage state.
	 */
	private function store( $id, $needs, $flags, $coverage ) {
		Grades::record( $id, array(
			'needsWork' => $needs,
			'flags'     => $flags,
			'stake'     => 10,
			'coverage'  => $coverage,
			'hasFocus'  => true,
			// Named flags, one id each — the shape a real reading writes.
			'flagIds'   => array_slice( array( 'summary', 'headings', 'sources', 'evidence' ), 0, max( 0, (int) $flags ) ),
			'points'    => $flags > 0 ? 60 : 100,
			'gradeable' => true,
			'hash'      => 'h' . $id,
		) );
	}

	/* --------------------------------------------------------------- schema */

	public function test_install_creates_the_table() {
		global $wpdb;
		$table   = Grades::name();
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table" ); // phpcs:ignore WordPress.DB

		foreach ( array( 'post_id', 'needs_work', 'flags', 'stake', 'coverage', 'has_focus', 'content_hash', 'graded_at' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
		$this->assertSame( Grades::VERSION, get_option( Grades::VERSION_OPTION ) );
	}

	/* ------------------------------------------------------- the fingerprint */

	public function test_the_fingerprint_follows_the_words_and_the_chosen_search() {
		$id   = $this->post( 'Same' );
		$post = get_post( $id );

		$before = Grades::hash( $post, '' );
		$this->assertSame( $before, Grades::hash( $post, '' ), 'Nothing changed, so nothing should be re-read.' );

		// Changing which search a page is measured against changes its grade
		// without touching a word of the content.
		$this->assertNotSame( $before, Grades::hash( $post, 'a chosen search' ) );

		$post->post_content = 'Different words entirely.';
		$this->assertNotSame( $before, Grades::hash( $post, '' ) );
	}

	/* ------------------------------------------------------------- the order */

	public function test_pages_needing_work_come_first_and_then_by_what_a_fix_is_worth() {
		$quiet  = $this->post( 'Fine' );
		$small  = $this->post( 'Needs a little' );
		$big    = $this->post( 'Needs a lot' );

		$this->grade( $quiet, false, 9000 );
		$this->grade( $small, true, 10 );
		$this->grade( $big, true, 500 );

		$out = Grades::page( 'fixable', $this->types, array(), 1, 20 );
		$this->assertSame( array( $big, $small ), $out['ids'], 'A busy page nobody needs to fix does not outrank one that needs fixing.' );
		$this->assertSame( 2, $out['total'] );
	}

	/* ----------------------------------------------------------- the paging */

	public function test_paging_loses_nothing_and_repeats_nothing() {
		$ids = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$id = $this->post( 'Item ' . $i );
			$this->grade( $id, true, 100 - $i ); // Distinct stakes ⇒ a stable order.
			$ids[] = $id;
		}

		$one = Grades::page( 'fixable', $this->types, array(), 1, 3 );
		$two = Grades::page( 'fixable', $this->types, array(), 2, 3 );
		$three = Grades::page( 'fixable', $this->types, array(), 3, 3 );

		$this->assertSame( 7, $one['total'], 'The total describes the whole site, not one screen.' );
		$this->assertCount( 3, $one['ids'] );
		$this->assertCount( 3, $two['ids'] );
		$this->assertCount( 1, $three['ids'] );

		$seen = array_merge( $one['ids'], $two['ids'], $three['ids'] );
		$this->assertSame( $ids, $seen, 'Every item appears exactly once, in rank order.' );
	}

	/**
	 * ⚠️ Every other test here passes a page size, which is exactly why this one
	 * exists: with none given, the list showed ONE row per page and an eight-row
	 * list became eight pages. A default only breaks where nobody states it.
	 */
	public function test_asking_without_a_page_size_uses_the_normal_one() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->grade( $this->post( 'Item ' . $i ), true, 10 + $i );
		}

		$out = ( new Worklist( new Settings() ) )->page( 'fixable', 1, 0 );

		$this->assertCount( 5, $out['items'], 'A missing size means the normal page size, not one row.' );
		$this->assertSame( Worklist::PER_PAGE, $out['per'] );
	}

	public function test_a_page_beyond_the_end_is_empty_rather_than_wrong() {
		$this->grade( $this->post( 'Only one' ), true, 5 );

		$out = Grades::page( 'fixable', $this->types, array(), 9, 20 );
		$this->assertSame( array(), $out['ids'] );
		$this->assertSame( 1, $out['total'] );
	}

	/* ---------------------------------------------------------- the filters */

	public function test_the_three_filters_are_exclusive_and_add_up() {
		$fix    = $this->post( 'Needs work' );
		$clear  = $this->post( 'Fine' );
		$parked = $this->post( 'Parked' );

		$this->grade( $fix, true, 50 );
		$this->grade( $clear, false, 0 );
		$this->grade( $parked, true, 900 );

		$counts = Grades::counts( $this->types, array( $parked ) );

		$this->assertSame( 1, $counts['fixable'] );
		$this->assertSame( 1, $counts['clear'] );
		$this->assertSame( 1, $counts['setAside'] );

		// A parked page is not ALSO counted as worth fixing, however high it ranks.
		$this->assertSame( array( $fix ), Grades::page( 'fixable', $this->types, array( $parked ), 1, 20 )['ids'] );
		$this->assertSame( array( $parked ), Grades::page( 'setAside', $this->types, array( $parked ), 1, 20 )['ids'] );
		$this->assertSame( array( $clear ), Grades::page( 'clear', $this->types, array( $parked ), 1, 20 )['ids'] );
	}

	/* ------------------------------------------------- what the count is OF */

	/**
	 * The two halves the front door prints beside the count.
	 *
	 * They have to add up to `fixable`, or the finding is describing a different
	 * list from the one its button opens — which is the fault the split exists
	 * to close. The front door used to count a 25-post sample of its own and
	 * could report silence while Worth Fixing read 36.
	 */
	public function test_the_fixable_split_adds_up_and_edits_win_over_coverage() {
		$edits  = $this->post( 'Needs an edit' );
		$search = $this->post( 'Answers nothing' );
		$both   = $this->post( 'Needs both' );
		$fine   = $this->post( 'Fine' );
		$parked = $this->post( 'Parked' );

		$this->store( $edits, true, 2, 'answered' );
		$this->store( $search, true, 0, 'barely' );
		$this->store( $both, true, 3, 'missing' );
		$this->store( $fine, false, 0, 'answered' );
		$this->store( $parked, true, 1, 'barely' );

		$counts = Grades::counts( $this->types, array( $parked ) );
		$split  = Grades::fixable_split( $this->types, array( $parked ) );

		$this->assertSame( 3, $counts['fixable'] );
		// A page with both problems is still one page to open, counted under the
		// edits it can actually be given — the same flags-lead rule the row badge
		// uses, so the finding and the row can never describe it differently.
		$this->assertSame( 2, $split['flagged'] );
		$this->assertSame( 1, $split['unanswered'] );
		$this->assertSame(
			$counts['fixable'],
			$split['flagged'] + $split['unanswered'],
			'The halves a finding prints must add up to the number above them.'
		);
	}

	/**
	 * One count, read by both screens. The chips on Your Content and the finding
	 * on the front door are the same number by construction now, not by two
	 * subsystems happening to agree.
	 */
	public function test_the_tally_is_the_one_count_both_screens_read() {
		$fix = $this->post( 'Needs work' );
		$this->post( 'Never read' );
		$this->store( $fix, true, 1, 'barely' );

		$tally = ( new Worklist( new Settings() ) )->tally();

		$this->assertSame( 1, $tally['fixable'] );
		$this->assertSame( 1, $tally['flagged'] );
		$this->assertSame( 0, $tally['unanswered'] );
		// ⚠️ The number that stops the count reading as finished. A page nobody
		// has read yet is not a page with nothing wrong, and a front door that
		// cannot tell those apart is the one that says "nothing needs your
		// attention" about a site it has not looked at.
		$this->assertSame( 1, $tally['grading'] );
	}

	/* ---------------------------------------------------- staleness + sweep */

	public function test_an_ungraded_page_is_offered_to_the_sweep_and_counted_as_waiting() {
		$graded   = $this->post( 'Done' );
		$ungraded = $this->post( 'Not yet' );
		$this->grade( $graded, true, 10 );

		$this->assertSame( array( $ungraded ), Grades::ungraded( $this->types, 10 ) );
		$this->assertSame( 1, Grades::remaining( $this->types ) );
	}

	public function test_saving_a_post_puts_it_back_in_the_queue() {
		$id = $this->post( 'Edited later' );
		$this->grade( $id, false, 0 );
		$this->assertSame( 0, Grades::remaining( $this->types ) );

		Grades::mark_stale( $id );

		$this->assertSame( array( $id ), Grades::ungraded( $this->types, 10 ), 'An edited page has to be read again.' );
		// ⚠️ "Still to read" means NEVER read — a page whose verdict predates an
		// edit has one, it is simply out of date. Counting it here put the same
		// page in two totals at once ("88 graded · 1 still to read" on 88 pages)
		// and, worse, was the number that made the card drop it from every list.
		$this->assertSame( 0, Grades::remaining( $this->types ), 'It has a grade; it is being re-read, not read for the first time.' );
		$this->assertSame( 1, Grades::rechecking( $this->types ), 'The re-read the sweep owes has its own count.' );
	}

	/**
	 * ⭐ THE BUG THIS TEST EXISTS FOR — his report, 2026-08-19.
	 *
	 * Open a page from Readiness → Optimize Your Content, fix ONE of the things
	 * it is flagged for, come back: the whole page vanished from the card, as
	 * though every complaint about it had been answered. Refresh later and it is
	 * back, still carrying the issues nobody fixed.
	 *
	 * Saving marked the grade out of date, and every read of the store dropped
	 * rows without a current grade — so an edit deleted the page's verdict
	 * instead of ageing it. The card then reported a success it had never
	 * verified, and the Optimized pillar moved because a page left the average,
	 * not because anything improved.
	 *
	 * The last reading is kept until the sweep replaces it: out of date is a
	 * state the screen can say out loud, and missing is not.
	 */
	public function test_an_edited_page_keeps_its_verdict_until_the_sweep_reads_it_again() {
		$id = $this->post( 'Two things wrong with it' );
		Grades::record( $id, array(
			'needsWork' => true,
			'flags'     => 2,
			'stake'     => 10,
			'coverage'  => 'on_page',
			'hasFocus'  => true,
			'points'    => 60,
			'flagIds'   => array( 'summary', 'headings' ),
			'gradeable' => true,
			'hash'      => 'h' . $id,
		) );

		$before = Grades::optimize( $this->types, array() );
		$this->assertSame( 1, $before['posts'] );
		$this->assertSame( array( $id ), $before['issues']['summary']['posts'] );

		Grades::mark_stale( $id ); // ← what save_post does.

		$after = Grades::optimize( $this->types, array() );
		$this->assertSame( 1, $after['posts'], 'A page you edited is still a page that was graded.' );
		$this->assertSame( array( $id ), $after['issues']['summary']['posts'], 'It must not leave the card as if it were fixed.' );
		$this->assertSame( 60, $after['score'], 'The pillar must not move because a page dropped out of the average.' );
		$this->assertSame( array( $id ), $after['pending'], '…but the card has to be able to say the verdict is older than the edit.' );
		$this->assertSame( array( $id ), Grades::posts_with_flag( $this->types, array(), 'summary' ), 'Set All Aside has to see it too.' );
	}

	/**
	 * ⭐ A verdict written by an older schema repairs itself.
	 *
	 * Found on a real site while driving the content-issues tool: seventeen pages
	 * ranked as "worth fixing" carrying a flag COUNT and not one flag id — rows
	 * from before the store kept ids and points, left behind by a migration that
	 * never reached that site. The screens hid it (they re-render every row they
	 * print); anything reading the store got a page that needs work for no
	 * reason it could state.
	 *
	 * ⛔ The row is not hidden and not deleted. It goes back in the queue, says
	 * it cannot be trusted meanwhile, and one reading ends the state for good.
	 */
	public function test_a_verdict_this_schema_cannot_read_is_re_read_rather_than_believed() {
		$id = $this->post( 'Graded by an older version' );
		Grades::record( $id, array(
			'needsWork' => true,
			'flags'     => 2,       // Two things wrong…
			'flagIds'   => array(), // …and not one of them named.
			'points'    => 0,
			'gradeable' => false,
			'stake'     => 10,
			'coverage'  => '',
			'hasFocus'  => false,
			'hash'      => 'h' . $id,
		) );

		$this->assertSame( array( $id ), Grades::ungraded( $this->types, 10 ), 'It has to be read again — nothing else can say what is wrong with it.' );
		$this->assertSame( 1, Grades::rechecking( $this->types ), 'And be counted as a reading the site still owes.' );
		$this->assertSame( 0, Grades::remaining( $this->types ), 'It was read once, so it is not “never looked at”.' );
		$this->assertTrue( Grades::stored( array( $id ) )[ $id ]['stale'], '⛔ Anything reading this row must be told not to repeat it.' );

		// One real reading ends it — the row can never be queued for ever.
		$this->store( $id, true, 2, 'barely' );
		$this->assertSame( array(), Grades::ungraded( $this->types, 10 ) );
		$this->assertSame( 0, Grades::rechecking( $this->types ) );
		$this->assertFalse( Grades::stored( array( $id ) )[ $id ]['stale'] );
	}

	public function test_forgetting_a_page_removes_it_from_every_answer() {
		$id = $this->post( 'Deleted later' );
		$this->grade( $id, true, 10 );

		Grades::forget( $id );

		$this->assertSame( array(), Grades::page( 'fixable', $this->types, array(), 1, 20 )['ids'] );
		$this->assertSame( 0, Grades::counts( $this->types, array() )['fixable'] );
	}

	public function test_pages_no_search_reaches_are_counted_on_their_own() {
		$found = $this->post( 'Found' );
		$quiet = $this->post( 'Quiet' );
		$this->grade( $found, true, 10, true );
		$this->grade( $quiet, true, 10, false );

		$this->assertSame( 1, Grades::without_search( $this->types ) );
	}

	/**
	 * The sweep end to end: it reads real pages and leaves real grades behind.
	 * Deliberately small — each page is rendered once, which is the whole reason
	 * this work happens on a schedule instead of in somebody's page load.
	 */
	public function test_the_sweep_grades_what_it_finds_and_the_queue_shrinks() {
		$this->post( 'One' );
		$this->post( 'Two' );
		$this->assertSame( 2, Grades::remaining( $this->types ) );

		$done = ( new Worklist( new Settings() ) )->sweep( 5 );

		$this->assertSame( 2, $done );
		$this->assertSame( 0, Grades::remaining( $this->types ) );

		$counts = Grades::counts( $this->types, array() );
		$this->assertSame( 2, $counts['fixable'] + $counts['clear'], 'Every graded page lands in exactly one bucket.' );
	}
}
