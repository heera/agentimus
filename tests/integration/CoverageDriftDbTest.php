<?php
/**
 * A coverage verdict is an answer to ONE question — does a passage of this page
 * answer THIS search — and the engines move the question without anybody
 * touching the page.
 *
 * ⭐⭐ THE FAULT THESE PIN. A worklist row carried two clocks: `coverage` was the
 * verdict the sweep stored, while the search printed beside it was read from the
 * engines' table in that same request. When Search Console changed which search
 * a page is found for, the row printed the NEW search next to the OLD search's
 * answer and called it that search's verdict.
 *
 * Measured on a real site before the fix: of 32 rows whose search the engines
 * choose, 8 disagreed with a fresh reading — against 0 of 14 whose focus the
 * owner had chosen by hand. A query the owner picks cannot drift, and that
 * asymmetry is the fault's own signature, so both halves are pinned here.
 *
 * ⭐ The question was already being recorded — {@see \Agentimus\Grades::hash()}
 * folds the focus in beside the title and the content — and nothing ever
 * compared it back. A save empties the fingerprint and every queue notices; a
 * search changing emptied nothing, because SQL cannot know which search a page
 * is found for today.
 *
 * ⛔ And the remedy must be the ageing one. A verdict measured against another
 * search is still the last thing we read; deleting it would empty the owner's
 * list for a reason that has nothing to do with their site
 * {@see \Agentimus\Grades::mark_stale()}.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Focus;
use Agentimus\Grades;
use Agentimus\Search\Table;
use Agentimus\Settings;
use Agentimus\Worklist;

final class CoverageDriftDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
		delete_option( Table::VERSION_OPTION );
		Table::install();
		$this->reset();

		// The worklist reads the engines' table only for a source that is both
		// connected and holding rows.
		( new \Agentimus\Google\Settings() )->connect( '{"type":"service_account"}', 'bot@example.com', 'sc-domain:example.com' );
	}

	public function tear_down(): void {
		$this->reset();
		parent::tear_down();
	}

	/**
	 * ⚠️ DELETE, never TRUNCATE — TRUNCATE commits, which ends the transaction
	 * wrapping each test and leaks one test's rows into the next.
	 */
	private function reset() {
		global $wpdb;
		foreach ( array( Grades::name(), Table::name(), $wpdb->postmeta, $wpdb->posts ) as $table ) {
			$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
		}
	}

	/** A published post long enough to be graded as an article. */
	private function post( $title, $content ) {
		return (int) self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		) );
	}

	/** One row of an engine's report, for this post. */
	private function reported( $post_id, $query, $impressions = 100 ) {
		return array(
			'query'       => $query,
			'page_url'    => get_permalink( $post_id ),
			'page_id'     => (int) $post_id,
			'clicks'      => 3,
			'impressions' => (int) $impressions,
			'position'    => 4.5,
			'range_start' => '2026-07-01',
			'range_end'   => '2026-07-28',
		);
	}

	/** What the engines say this site is found for, replacing whatever was there. */
	private function engines_say( array $rows ) {
		Table::replace( 'google', $rows );
	}

	/** Read the whole site, the way the sweep does. */
	private function sweep() {
		( new Worklist( new Settings() ) )->sweep( 200 );
	}

	/** One page of the worklist, as any screen or ability receives it. */
	private function row( $post_id ) {
		$out = ( new Worklist( new Settings() ) )->issues( 'fixable', 1, 30 );
		foreach ( $out['items'] as $item ) {
			if ( (int) $item['id'] === (int) $post_id ) {
				return $item;
			}
		}
		$clear = ( new Worklist( new Settings() ) )->issues( 'clear', 1, 30 );
		foreach ( $clear['items'] as $item ) {
			if ( (int) $item['id'] === (int) $post_id ) {
				return $item;
			}
		}
		return null;
	}

	/* ----------------------------------------------------------- the fault */

	/**
	 * ⭐⭐ THE ONE THIS EXISTS FOR. The page never changed; the engines changed
	 * their mind about what it is found for. The stored answer is now an answer
	 * to a question nobody asked.
	 */
	public function test_a_verdict_is_re_read_when_the_engines_move_the_search_under_it() {
		$id = $this->post(
			'Detecting an ajax request',
			'<p>An ajax request arrives with a header that names it, and every framework reads that same header to decide.</p>'
		);

		$this->engines_say( array( $this->reported( $id, 'detect ajax request header' ) ) );
		$this->sweep();

		$before = $this->row( $id );
		$this->assertNotNull( $before, 'The page has to be on the list before anything can drift under it.' );
		$this->assertSame( 'detect ajax request header', $before['search']['query'] );
		$this->assertFalse( $before['stale'], 'Freshly read against the search it is shown for.' );
		$measured = (string) $before['coverage'];
		$this->assertNotSame( '', $measured, 'A verdict has to exist before it can go out of date.' );
		$this->assertSame( array(), Grades::ungraded( array( 'post' ), 10 ), 'Nothing is owed yet.' );

		// Search Console reports again, and a different search is now busiest.
		// ⛔ Nothing about the POST changed — no save, no edit, no new checks.
		$this->engines_say( array( $this->reported( $id, 'cakephp ajax detector source' ) ) );

		$after = $this->row( $id );
		$this->assertNotNull( $after, '⛔ The row must not vanish — a page missing from a list reads as a page with nothing wrong.' );
		$this->assertSame( 'cakephp ajax detector source', $after['search']['query'], 'The row shows the search the engines report now.' );
		$this->assertTrue(
			$after['stale'],
			'⛔ …and must say the verdict beside it was measured against a different one.'
		);
		$this->assertSame(
			$measured,
			(string) $after['coverage'],
			'⛔ The last reading STAYS. Ageing a verdict is a fact about us; deleting it is a claim about their site.'
		);
		$this->assertSame(
			array( $id ),
			Grades::ungraded( array( 'post' ), 10 ),
			'⛔ And the queues have to see it — this is the only way that state reaches them.'
		);
		$this->assertSame( 1, Grades::rechecking( array( 'post' ) ), 'Counted as a reading the site still owes.' );
		$this->assertSame( 0, Grades::remaining( array( 'post' ) ), '⛔ Not "never looked at" — it was read, against another search.' );
	}

	/** One real reading ends it, so a row can never be queued for ever. */
	public function test_the_next_sweep_settles_it() {
		$id = $this->post( 'Detecting an ajax request', '<p>An ajax request arrives with a header that names it.</p>' );
		$this->engines_say( array( $this->reported( $id, 'detect ajax request header' ) ) );
		$this->sweep();

		$this->engines_say( array( $this->reported( $id, 'cakephp ajax detector source' ) ) );
		$this->assertTrue( $this->row( $id )['stale'] );

		$this->sweep();

		$settled = $this->row( $id );
		$this->assertFalse( $settled['stale'], 'Read again against the search it is now found for.' );
		$this->assertSame( array(), Grades::ungraded( array( 'post' ), 10 ) );
		$this->assertSame( 0, Grades::rechecking( array( 'post' ) ) );
	}

	/**
	 * ⭐ THE CONTROL, and the reason the fault was findable at all. A focus the
	 * owner typed is theirs; the engines cannot move it, so nothing may go stale
	 * underneath it. Firing here would re-read the whole site for nothing.
	 */
	public function test_a_focus_the_owner_chose_never_drifts_when_the_engines_move() {
		$id = $this->post( 'Detecting an ajax request', '<p>An ajax request arrives with a header that names it.</p>' );
		update_post_meta( $id, Focus::META, 'detect ajax request header' );

		$this->engines_say( array( $this->reported( $id, 'something else entirely' ) ) );
		$this->sweep();

		$before = $this->row( $id );
		$this->assertSame( 'detect ajax request header', $before['search']['query'], 'The owner’s choice wins over the engine’s report.' );
		$this->assertTrue( $before['search']['chosen'] );
		$this->assertFalse( $before['stale'] );

		// The engines change their mind. The owner did not.
		$this->engines_say( array( $this->reported( $id, 'a third unrelated search' ) ) );

		$after = $this->row( $id );
		$this->assertSame( 'detect ajax request header', $after['search']['query'], 'Still the owner’s search.' );
		$this->assertFalse(
			$after['stale'],
			'⛔ Nothing the verdict was measured against has moved, so nothing may be re-read.'
		);
		$this->assertSame( array(), Grades::ungraded( array( 'post' ), 10 ), '⛔ A false alarm here re-reads the whole site for nothing.' );
	}

	/**
	 * ⭐ THE OWNER'S SCREEN SHOWS A FRESH READING — {@see \Agentimus\Worklist::page()}
	 * re-reads every row it prints, so it never displayed the stale verdict this
	 * fault produced. What it shares with the agent's list is the RANK and the
	 * tab counts, and those are read from the store.
	 *
	 * So the screen must notice the drift too, or an owner who never connects an
	 * agent has their list ordered by answers to questions the engines moved on
	 * from, until the thirty-day idle re-read gets round to it.
	 */
	public function test_the_owners_screen_ages_the_store_it_ranks_by() {
		$id = $this->post( 'Detecting an ajax request', '<p>An ajax request arrives with a header that names it.</p>' );
		$this->engines_say( array( $this->reported( $id, 'detect ajax request header' ) ) );
		$this->sweep();
		$this->assertSame( array(), Grades::ungraded( array( 'post' ), 10 ) );

		$this->engines_say( array( $this->reported( $id, 'cakephp ajax detector source' ) ) );

		// The owner opens the Content screen. Nothing else has happened.
		( new Worklist( new Settings() ) )->page( 'fixable', 1, 20 );

		$this->assertSame(
			array( $id ),
			Grades::ungraded( array( 'post' ), 10 ),
			'⛔ The ranking is read from the store, so the store has to be told.'
		);
	}

	/* ------------------------------------------------- the stable question */

	/**
	 * ⚠️ `usort` is not stable before PHP 8.0 — the floor this plugin supports —
	 * so two searches on equal impressions could come back in either order, and
	 * "the search this page is found for" would differ between one read and the
	 * next. The verdict is stored against whichever won, so an unstable winner
	 * is a stored answer to a question that changes by itself: the row would
	 * mark itself stale, re-read, and be able to flip straight back.
	 *
	 * Small sites live on ties — two searches at 3 impressions each is ordinary.
	 */
	public function test_the_busiest_search_breaks_a_tie_the_same_way_every_time() {
		$id = $this->post( 'A page two searches reach equally', '<p>Words enough to read.</p>' );
		$this->engines_say( array(
			$this->reported( $id, 'zebra query', 3 ),
			$this->reported( $id, 'alpha query', 3 ),
		) );

		// The list is built from the store, so it has to have been read once.
		$this->sweep();

		$first = $this->row( $id )['search']['query'];
		$this->assertNotSame( '', (string) $first );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( $first, $this->row( $id )['search']['query'], 'The same tie must break the same way on every read.' );
			$this->assertFalse( $this->row( $id )['stale'], '⛔ A tie must not be able to age a verdict all by itself.' );
		}
	}

	/* --------------------------------------------------------- the store */

	/**
	 * The store has to hand back the question, or the one caller holding both
	 * halves has nothing to compare.
	 */
	public function test_the_store_hands_back_the_question_each_verdict_answered() {
		$id = $this->post( 'A graded page', '<p>Words enough to read.</p>' );
		$this->engines_say( array( $this->reported( $id, 'the search it was read against' ) ) );
		$this->sweep();

		$stored = Grades::stored( array( $id ) )[ $id ];
		$this->assertArrayHasKey( 'hash', $stored );
		$this->assertSame(
			Grades::hash( get_post( $id ), 'the search it was read against' ),
			$stored['hash'],
			'The recorded fingerprint is the title, the content AND the search — all three.'
		);
	}

	/**
	 * ⛔ A row written before this schema knew to record the question has an
	 * empty fingerprint, and an empty one means "unknown", never "different".
	 * Reading it as different would mark every row on the site stale on upgrade
	 * — v2's mistake, in a new place.
	 */
	public function test_an_unknown_question_is_not_read_as_a_changed_one() {
		$id = $this->post( 'Graded by an older version', '<p>Words enough to read.</p>' );
		$this->engines_say( array( $this->reported( $id, 'some search' ) ) );
		$this->sweep();

		global $wpdb;
		$table = Grades::name();
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET content_hash = '' WHERE post_id = %d", $id ) ); // phpcs:ignore WordPress.DB

		// It is stale for the ordinary reason, and the row still renders.
		$row = $this->row( $id );
		$this->assertNotNull( $row );
		$this->assertTrue( $row['stale'] );

		// ⛔ And the comparison must not have written anything of its own: the
		// fingerprint stays empty until a real reading replaces it.
		$this->assertSame(
			'',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT content_hash FROM $table WHERE post_id = %d", $id ) ), // phpcs:ignore WordPress.DB
			'An unknown question is left alone, not overwritten.'
		);
	}
}
