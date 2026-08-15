<?php
/**
 * Search\Table against a real database — the write path that every number on
 * every search screen comes out of.
 *
 * This table had no database test at all, which is how three silent faults
 * lived in it: a part-written snapshot reported itself as a clean poll, the row
 * cap dropped the quiet tail with nothing said, and the live rows were deleted
 * BEFORE the new ones were written, so a failed write left the site with less
 * than it started with. Each of those has a test here, and each test fails
 * against the old code.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Search\Table;

final class SearchTableDbTest extends DbTestCase {

	/** @var callable|null The active INSERT-breaking filter, so tear_down can lift it. */
	private $breaker = null;

	public function set_up(): void {
		parent::set_up();
		delete_option( Table::VERSION_OPTION );
		Table::install();
		$this->truncate();
	}

	public function tear_down(): void {
		$this->unbreak_inserts();
		$this->truncate();
		parent::tear_down();
	}

	/**
	 * ⚠️ DELETE, never TRUNCATE — TRUNCATE forces MySQL to commit, which ends the
	 * transaction this test case wraps every test in and lets one test's rows
	 * leak into the next.
	 */
	private function truncate() {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One row, shaped the way both pollers shape theirs.
	 *
	 * @param string $query   The search.
	 * @param int    $page_id Post ID.
	 * @param int    $impr    Impressions — also the sort key the readers use.
	 * @return array
	 */
	private function row( $query, $page_id = 1, $impr = 100 ) {
		return array(
			'query'       => $query,
			'page_url'    => 'https://example.com/?p=' . $page_id,
			'page_id'     => $page_id,
			'clicks'      => 5,
			'impressions' => $impr,
			'position'    => 4.5,
			'range_start' => '2026-06-01',
			'range_end'   => '2026-07-26',
		);
	}

	/**
	 * Make every INSERT into our table fail, the way a packet-limit error does:
	 * wpdb returns false and nothing lands.
	 */
	private function break_inserts() {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$table         = Table::name();
		$this->breaker = static function ( $sql ) use ( $table ) {
			if ( 0 === stripos( ltrim( (string) $sql ), 'INSERT INTO ' . $table ) ) {
				return 'INSERT INTO ' . $table . ' (no_such_column) VALUES (1)';
			}
			return $sql;
		};
		add_filter( 'query', $this->breaker );
	}

	private function unbreak_inserts() {
		global $wpdb;
		if ( $this->breaker ) {
			remove_filter( 'query', $this->breaker );
			$this->breaker = null;
		}
		$wpdb->suppress_errors( false );
	}

	private function live_count( $source, $type ) {
		global $wpdb;
		$table = Table::name();
		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(*) FROM $table WHERE source = %s AND search_type = %s AND staged = 0",
			$source,
			$type
		) );
	}

	private function staged_count() {
		global $wpdb;
		$table = Table::name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE staged = 1" ); // phpcs:ignore WordPress.DB
	}

	/* ---------------------------------------------------------------- schema */

	public function test_install_creates_the_surface_and_staging_columns() {
		global $wpdb;
		$table   = Table::name();
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table" ); // phpcs:ignore WordPress.DB

		$this->assertContains( 'search_type', $columns );
		$this->assertContains( 'staged', $columns );
		$this->assertSame( Table::VERSION, get_option( Table::VERSION_OPTION ) );
	}

	/**
	 * The upgrade case, and the one most likely to be got wrong: rows stored
	 * before the surface column existed take its default of 'web'. For Bing that
	 * is the wrong word — its reads look for 'all' — so an owner updating the
	 * plugin would have watched every Bing number vanish until the next daily
	 * poll. install() moves them across.
	 */
	public function test_upgrading_keeps_bings_existing_rows_visible() {
		global $wpdb;
		$table = Table::name();

		// A row exactly as the previous version would have left it.
		$wpdb->insert(
			$table,
			array(
				'source'      => 'bing',
				'search_type' => 'web',
				'staged'      => 0,
				'query_text'  => 'from before the upgrade',
				'page_url'    => 'https://example.com/x',
				'page_id'     => 7,
				'clicks'      => 1,
				'impressions' => 50,
				'position'    => 3.0,
				'range_start' => '2026-06-01',
				'range_end'   => '2026-07-26',
			)
		);
		$this->assertFalse( Table::has_rows( 'bing' ), 'Precondition: the old wording is invisible to the new read.' );

		delete_option( Table::VERSION_OPTION );
		Table::install();

		$this->assertTrue( Table::has_rows( 'bing' ), 'An update must not cost an owner their numbers.' );
		$this->assertSame( 'from before the upgrade', Table::snapshot( 'bing' )[0]['query'] );
	}

	public function test_a_row_written_without_a_surface_defaults_to_the_sources_own() {
		Table::replace( 'google', array( $this->row( 'a' ) ) );
		Table::replace( 'bing', array( $this->row( 'b' ) ) );

		// Google reports the combined "All" tab; Bing has no surface split at all,
		// so its rows say so rather than borrowing Google's word for it.
		$this->assertSame( 1, $this->live_count( 'google', 'web' ) );
		$this->assertSame( 1, $this->live_count( 'bing', 'all' ) );
		$this->assertSame( 0, $this->live_count( 'bing', 'web' ) );
	}

	/* ----------------------------------------------------------- clean write */

	public function test_a_clean_write_reports_what_it_wrote() {
		$out = Table::replace( 'google', array( $this->row( 'a' ), $this->row( 'b' ) ) );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 2, $out['written'] );
		$this->assertSame( 2, $out['expected'] );
		$this->assertSame( 0, $out['dropped'] );
		$this->assertSame( 0, $this->staged_count(), 'A finished write leaves nothing staged.' );
	}

	public function test_a_second_write_replaces_the_first() {
		Table::replace( 'google', array( $this->row( 'old one' ), $this->row( 'old two' ) ) );
		Table::replace( 'google', array( $this->row( 'new one' ) ) );

		$rows = Table::snapshot( 'google' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'new one', $rows[0]['query'] );
	}

	public function test_an_empty_answer_is_never_a_wipe() {
		Table::replace( 'google', array( $this->row( 'kept' ) ) );

		$out = Table::replace( 'google', array() );

		$this->assertTrue( $out['ok'], 'Nothing to write is not a failure.' );
		$this->assertCount( 1, Table::snapshot( 'google' ), 'An empty answer must not erase the last good snapshot.' );
	}

	/* ------------------------------------------------- the three real faults */

	public function test_a_failed_write_leaves_the_previous_snapshot_standing() {
		Table::replace( 'google', array( $this->row( 'still here', 1, 500 ) ) );

		$this->break_inserts();
		$out = Table::replace( 'google', array( $this->row( 'never lands' ) ) );
		$this->unbreak_inserts();

		$this->assertFalse( $out['ok'], 'A write that stored nothing must not report success.' );
		$this->assertSame( 0, $out['written'] );

		$rows = Table::snapshot( 'google' );
		$this->assertCount( 1, $rows, 'The snapshot that was already here has to survive a failed poll.' );
		$this->assertSame( 'still here', $rows[0]['query'] );
		$this->assertSame( 0, $this->staged_count(), 'A failed write leaves no half-written rows behind.' );
	}

	public function test_a_part_written_snapshot_reports_failure_not_success() {
		// Two chunks' worth, with the write broken partway: the old code returned
		// a positive count here and both pollers read that as a clean poll.
		$rows = array();
		for ( $i = 0; $i < Table::INSERT_CHUNK + 10; $i++ ) {
			$rows[] = $this->row( 'q' . $i );
		}

		global $wpdb;
		$table = Table::name();
		$seen  = 0;
		$wpdb->suppress_errors( true );
		$filter = static function ( $sql ) use ( $table, &$seen ) {
			if ( 0 === stripos( ltrim( (string) $sql ), 'INSERT INTO ' . $table ) ) {
				$seen++;
				if ( $seen > 1 ) {
					return 'INSERT INTO ' . $table . ' (no_such_column) VALUES (1)';
				}
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		$out = Table::replace( 'google', $rows );
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( false );

		$this->assertGreaterThan( 1, $seen, 'The test needs more than one chunk to be meaningful.' );
		$this->assertFalse( $out['ok'], 'Part of a snapshot is not a snapshot.' );
		$this->assertSame( 0, $this->staged_count() );
		$this->assertSame( 0, $this->live_count( 'google', 'web' ), 'Nothing half-written becomes the truth.' );
	}

	public function test_rows_dropped_by_the_cap_are_counted() {
		$rows = array();
		for ( $i = 0; $i < Table::MAX_ROWS + 3; $i++ ) {
			$rows[] = $this->row( 'q' . $i );
		}

		$out = Table::replace( 'google', $rows );

		$this->assertTrue( $out['ok'], 'Hitting the cap is a known limit, not a broken write.' );
		$this->assertSame( 3, $out['dropped'], 'The store has to say how many it left out.' );
		$this->assertSame( Table::MAX_ROWS, $out['written'] );
	}

	/* ------------------------------------------------ surfaces never mix */

	public function test_two_surfaces_do_not_double_count() {
		Table::replace( 'google', array( $this->row( 'a', 1, 100 ), $this->row( 'b', 2, 200 ) ), 'web' );
		Table::replace( 'google', array( $this->row( 'c', 3, 900 ) ), 'image' );

		// The whole point: a caller that does not name a surface must get exactly
		// what it got before image rows existed. Anything else silently inflates
		// every clicks and impressions figure in the plugin.
		$this->assertCount( 2, Table::snapshot( 'google' ) );
		$this->assertSame( 300, Table::totals( 'google' )['impressions'] );

		$this->assertCount( 1, Table::snapshot( 'google', 'image' ) );
		$this->assertSame( 900, Table::totals( 'google', 'image' )['impressions'] );
	}

	public function test_replacing_one_surface_leaves_the_others_standing() {
		Table::replace( 'google', array( $this->row( 'web one' ) ), 'web' );
		Table::replace( 'google', array( $this->row( 'image one' ) ), 'image' );

		Table::replace( 'google', array( $this->row( 'web two' ) ), 'web' );

		$this->assertSame( 'web two', Table::snapshot( 'google', 'web' )[0]['query'] );
		$this->assertCount( 1, Table::snapshot( 'google', 'image' ) );
		$this->assertSame( 'image one', Table::snapshot( 'google', 'image' )[0]['query'] );
	}

	/* ------------------------------------------- one page at a time (Bing) */

	/**
	 * The fault that kept Bing's coverage frozen. Its poll asks about one page
	 * per request, so writing those few pages used to erase every page it had
	 * not reached — meaning the same handful were refreshed for ever and no
	 * other page could ever gain any Bing data at all.
	 */
	public function test_writing_one_pages_rows_leaves_every_other_page_standing() {
		Table::replace_page( 'bing', 'https://example.com/a/', array( $this->row( 'a one', 1 ) ), 'all' );
		Table::replace_page( 'bing', 'https://example.com/b/', array( $this->row( 'b one', 2 ) ), 'all' );

		Table::replace_page( 'bing', 'https://example.com/a/', array( $this->row( 'a two', 1 ) ), 'all' );

		$queries = array_column( Table::snapshot( 'bing' ), 'query' );
		sort( $queries );
		$this->assertSame( array( 'a two', 'b one' ), $queries );
	}

	public function test_the_site_wide_set_is_its_own_scope() {
		Table::replace_page( 'bing', '', array( $this->row( 'site wide', 0 ) ), 'all' );
		Table::replace_page( 'bing', 'https://example.com/a/', array( $this->row( 'page one', 1 ) ), 'all' );

		// Refreshing the site-wide list must not disturb a page's own rows.
		Table::replace_page( 'bing', '', array( $this->row( 'site wide again', 0 ) ), 'all' );

		$queries = array_column( Table::snapshot( 'bing' ), 'query' );
		sort( $queries );
		$this->assertSame( array( 'page one', 'site wide again' ), $queries );
	}

	/**
	 * The one case where erasing a page's rows IS right: the engine was asked and
	 * said it has nothing. Leaving yesterday's rows would put the table in direct
	 * contradiction with the ask ledger.
	 */
	public function test_clearing_one_page_removes_only_that_page() {
		Table::replace_page( 'bing', 'https://example.com/a/', array( $this->row( 'a one', 1 ) ), 'all' );
		Table::replace_page( 'bing', 'https://example.com/b/', array( $this->row( 'b one', 2 ) ), 'all' );

		Table::clear_page( 'bing', 'https://example.com/a/', 'all' );

		$queries = array_column( Table::snapshot( 'bing' ), 'query' );
		$this->assertSame( array( 'b one' ), $queries );
	}

	/**
	 * ⚠️ The pair to the test above, and the reason the two are separate methods:
	 * an empty answer from an API is usually a blip, so a WRITE of nothing must
	 * never wipe. Only an explicit clear may.
	 */
	public function test_writing_an_empty_list_for_a_page_leaves_its_rows_alone() {
		Table::replace_page( 'bing', 'https://example.com/a/', array( $this->row( 'a one', 1 ) ), 'all' );

		Table::replace_page( 'bing', 'https://example.com/a/', array(), 'all' );

		$this->assertCount( 1, Table::snapshot( 'bing' ) );
	}

	public function test_has_rows_answers_per_surface() {
		Table::replace( 'google', array( $this->row( 'only image', 1, 10 ) ), 'image' );

		$this->assertFalse( Table::has_rows( 'google' ), 'Image rows alone are not the default surface.' );
		$this->assertTrue( Table::has_rows( 'google', 'image' ) );
	}
}
