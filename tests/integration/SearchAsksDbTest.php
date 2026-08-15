<?php
/**
 * The ask ledger against a real database.
 *
 * This table exists to keep two things apart that the search-queries table
 * cannot: a page with no searches, and a page nobody has asked about yet. Both
 * store no rows, and telling an owner the first sentence when the second is true
 * is the exact fault this arc was opened to fix. Every state below is therefore
 * asserted as its own distinct answer, not as "empty or not".
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Search\Asks;

final class SearchAsksDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Asks::VERSION_OPTION );
		Asks::install();
		$this->truncate();
	}

	public function tear_down(): void {
		$this->truncate();
		parent::tear_down();
	}

	/**
	 * ⚠️ DELETE, never TRUNCATE. TRUNCATE forces MySQL to commit, which ends the
	 * transaction the test case wraps every test in — so posts created by one
	 * test survive into the next and every count assertion below quietly drifts.
	 * That cost a confusing half hour; the note is here so it costs nobody else one.
	 *
	 * The posts go too: waiting() counts every published page on the site, so
	 * this class needs a known starting point rather than whatever ran before it.
	 */
	private function truncate() {
		global $wpdb;
		$table = Asks::name();
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->postmeta}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A published post, with a date so ordering assertions mean something.
	 *
	 * @param string $when Y-m-d H:i:s.
	 * @param string $title Post title.
	 * @return int
	 */
	private function post( $when, $title = 'A post' ) {
		return (int) self::factory()->post->create( array(
			'post_status' => 'publish',
			'post_type'   => 'post',
			'post_title'  => $title,
			'post_date'   => $when,
			'post_date_gmt' => $when,
		) );
	}

	/* ------------------------------------------------------------- the table */

	public function test_install_creates_the_table() {
		global $wpdb;
		$table   = Asks::name();
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table" ); // phpcs:ignore WordPress.DB

		foreach ( array( 'source', 'page_key', 'page_id', 'page_url', 'asked_at', 'status', 'found', 'error' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
		$this->assertSame( Asks::VERSION, get_option( Asks::VERSION_OPTION ) );
	}

	/* ------------------------------------------------- the three real states */

	public function test_a_page_never_asked_about_has_no_answer_at_all() {
		$this->assertNull(
			Asks::state( 'bing', 'https://example.com/never/', 0 ),
			'Null is the answer the screen has to say out loud, not an empty column.'
		);
	}

	public function test_asked_and_found_is_a_different_answer_from_asked_and_empty() {
		Asks::record( 'bing', 'https://example.com/busy/', 11, Asks::STATUS_OK, 4 );
		Asks::record( 'bing', 'https://example.com/quiet/', 12, Asks::STATUS_NONE, 0 );

		$busy  = Asks::state( 'bing', 'https://example.com/busy/', 11 );
		$quiet = Asks::state( 'bing', 'https://example.com/quiet/', 12 );

		$this->assertSame( Asks::STATUS_OK, $busy['status'] );
		$this->assertSame( 4, $busy['found'] );

		$this->assertSame( Asks::STATUS_NONE, $quiet['status'] );
		$this->assertSame( 0, $quiet['found'] );
		$this->assertNotSame( '', (string) $quiet['askedAt'], 'A page asked about knows when.' );
	}

	public function test_a_refusal_keeps_the_engines_own_words() {
		Asks::record( 'bing', 'https://example.com/x/', 13, Asks::STATUS_ERROR, 0, 'Bing answered 429: too many requests' );

		$state = Asks::state( 'bing', 'https://example.com/x/', 13 );
		$this->assertSame( Asks::STATUS_ERROR, $state['status'] );
		$this->assertSame( 'Bing answered 429: too many requests', $state['error'] );
	}

	public function test_asking_the_same_page_again_updates_rather_than_duplicates() {
		Asks::record( 'bing', 'https://example.com/x/', 14, Asks::STATUS_NONE, 0 );
		Asks::record( 'bing', 'https://example.com/x/', 14, Asks::STATUS_OK, 6 );

		global $wpdb;
		$table = Asks::name();
		$this->assertSame( '1', (string) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( Asks::STATUS_OK, Asks::state( 'bing', 'https://example.com/x/', 14 )['status'] );
	}

	public function test_each_engine_keeps_its_own_ledger() {
		Asks::record( 'bing', 'https://example.com/x/', 15, Asks::STATUS_OK, 2 );

		$this->assertNotNull( Asks::state( 'bing', 'https://example.com/x/', 15 ) );
		$this->assertNull( Asks::state( 'google', 'https://example.com/x/', 15 ), 'One engine being asked says nothing about the other.' );
	}

	/* ---------------------------------------------------------- the rotation */

	public function test_the_rotation_takes_never_asked_pages_newest_first() {
		$old = $this->post( '2026-01-01 10:00:00', 'Old' );
		$mid = $this->post( '2026-05-01 10:00:00', 'Middle' );
		$new = $this->post( '2026-08-01 10:00:00', 'New' );

		$next = Asks::next( 'bing', 2 );
		$ids  = array_column( $next, 'id' );

		$this->assertSame( array( $new, $mid ), $ids, 'A page published this week is the one an owner is looking at.' );
		$this->assertNotContains( $old, $ids );
	}

	public function test_a_page_already_asked_about_stops_taking_a_new_pages_turn() {
		$a = $this->post( '2026-08-01 10:00:00', 'A' );
		$b = $this->post( '2026-07-01 10:00:00', 'B' );

		Asks::record( 'bing', get_permalink( $a ), $a, Asks::STATUS_OK, 3 );

		$ids = array_column( Asks::next( 'bing', 5 ), 'id' );
		// A comes back only in the second half of the queue — the pages waiting
		// longest for another look — never ahead of a page never asked at all.
		$this->assertSame( $b, $ids[0] );
	}

	/**
	 * The half that stops the rotation circling the same pages for ever, which
	 * is exactly what the old fixed "ten busiest pages" loop did every day.
	 */
	public function test_once_everything_has_been_asked_the_oldest_ask_goes_next() {
		$a = $this->post( '2026-08-01 10:00:00', 'A' );
		$b = $this->post( '2026-07-01 10:00:00', 'B' );

		global $wpdb;
		$table = Asks::name();
		Asks::record( 'bing', get_permalink( $a ), $a, Asks::STATUS_OK, 1 );
		Asks::record( 'bing', get_permalink( $b ), $b, Asks::STATUS_OK, 1 );
		// Age A's ask so the ordering has something to sort on.
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET asked_at = %s WHERE page_key = %s", '2026-01-01 00:00:00', Asks::key( $a, '' ) ) ); // phpcs:ignore WordPress.DB

		$ids = array_column( Asks::next( 'bing', 1 ), 'id' );
		$this->assertSame( array( $a ), $ids, 'The page waiting longest goes next.' );
	}

	/**
	 * The site-wide list of searches is not a page and cannot be asked about, so
	 * it must never take a real page's turn in the queue — including in the
	 * second half, which walks the ledger itself.
	 */
	public function test_the_site_wide_set_is_never_offered_as_a_page_to_ask_about() {
		$post = $this->post( '2026-08-01 10:00:00', 'Real' );
		Asks::record( 'bing', '', 0, Asks::STATUS_OK, 9 );
		Asks::record( 'bing', get_permalink( $post ), $post, Asks::STATUS_OK, 2 );

		$urls = array_column( Asks::next( 'bing', 5 ), 'url' );

		$this->assertNotEmpty( $urls, 'The queue must still offer the real page.' );
		$this->assertNotContains( '', $urls );
		$this->assertContains( get_permalink( $post ), $urls );
	}

	/* ------------------------------------------------------------- the counts */

	public function test_waiting_counts_published_pages_with_no_answer_yet() {
		$a = $this->post( '2026-08-01 10:00:00', 'A' );
		$this->post( '2026-07-01 10:00:00', 'B' );

		$this->assertSame( 2, Asks::waiting( 'bing' ) );

		Asks::record( 'bing', get_permalink( $a ), $a, Asks::STATUS_NONE, 0 );
		$this->assertSame( 1, Asks::waiting( 'bing' ), 'Asked and told nothing still counts as answered.' );
	}

	public function test_asked_counts_pages_and_not_the_site_wide_set() {
		Asks::record( 'bing', '', 0, Asks::STATUS_OK, 9 );
		$this->assertSame( 0, Asks::asked( 'bing' ), 'The site-wide set is not a page.' );

		Asks::record( 'bing', 'https://example.com/x/', 21, Asks::STATUS_OK, 2 );
		$this->assertSame( 1, Asks::asked( 'bing' ) );
	}

	public function test_forgetting_one_engine_leaves_the_other_alone() {
		Asks::record( 'bing', 'https://example.com/x/', 22, Asks::STATUS_OK, 1 );
		Asks::record( 'google', 'https://example.com/x/', 22, Asks::STATUS_OK, 1 );

		Asks::forget( 'bing' );

		$this->assertNull( Asks::state( 'bing', 'https://example.com/x/', 22 ) );
		$this->assertNotNull( Asks::state( 'google', 'https://example.com/x/', 22 ) );
	}
}
