<?php
/**
 * Fill the gaps (bulk backfill): the proposal store's park/approve/dismiss life
 * cycle, the gap-only law on apply (an author's own value always wins), the
 * runner's draft loop (per-item errors don't end a run; a no-vision provider
 * ends the alt leg after one miss), and the scanner's clamps and query shape.
 *
 * Generation itself is exercised through the runner's drafter seam — the real
 * Assist calls are per-item wrappers around draft_description()/draft_topics()/
 * draft_alt(), which ride the same prompts the editor buttons use.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Bulk\Proposals;
use Agentimus\Bulk\Runner;
use Agentimus\Bulk\Scanner;
use Agentimus\Description;
use Agentimus\Settings;
use Agentimus\Topics;
use PHPUnit\Framework\TestCase;

final class BulkTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/**
	 * Register a post fixture.
	 *
	 * @param int   $id   Post ID.
	 * @param array $post Field overrides.
	 */
	private function fixture( $id, array $post = array() ) {
		$GLOBALS['_af_posts'][ $id ] = (object) array_merge(
			array(
				'ID'           => $id,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Post ' . $id,
				'post_content' => 'Body of post ' . $id,
				'post_excerpt' => '',
				'post_parent'  => 0,
			),
			$post
		);
	}

	/* ---------------------------------------------------------------- *
	 *  Proposals — park / review / approve / dismiss
	 * ---------------------------------------------------------------- */

	public function test_proposal_round_trip_and_reject() {
		$this->fixture( 5 );
		$this->assertNull( Proposals::get( 5, 'description' ) );

		Proposals::save( 5, 'description', 'A clear one-liner.' );
		$this->assertSame( 'A clear one-liner.', Proposals::get( 5, 'description' ) );

		Proposals::reject( 5, 'description' );
		$this->assertNull( Proposals::get( 5, 'description' ) );
	}

	public function test_apply_writes_the_real_field_and_drops_the_proposal() {
		$this->fixture( 5 );
		Proposals::save( 5, 'description', '  A clear <b>one-liner</b>.  ' );

		$this->assertSame( 'applied', Proposals::apply( 5, 'description' ) );
		// Written through the SAME cleaner as the editor save path: tags out, trimmed.
		$this->assertSame( 'A clear one-liner.', get_post_meta( 5, Description::META, true ) );
		$this->assertNull( Proposals::get( 5, 'description' ) );
	}

	public function test_apply_topics_sanitises_through_the_field_sanitiser() {
		$this->fixture( 7 );
		Proposals::save( 7, 'topics', array( ' WordPress ', 'wordpress', '<i>ai</i>' ) );

		$this->assertSame( 'applied', Proposals::apply( 7, 'topics' ) );
		// Trimmed, tag-stripped, deduped case-insensitively.
		$this->assertSame( array( 'WordPress', 'ai' ), array_values( get_post_meta( 7, Topics::META_TOPICS, true ) ) );
	}

	public function test_apply_alt_writes_attachment_alt_meta() {
		$this->fixture( 9, array( 'post_type' => 'attachment' ) );
		Proposals::save( 9, 'alt', 'A red bicycle leaning on a brick wall.' );

		$this->assertSame( 'applied', Proposals::apply( 9, 'alt' ) );
		$this->assertSame( 'A red bicycle leaning on a brick wall.', get_post_meta( 9, '_wp_attachment_image_alt', true ) );
	}

	public function test_gap_only_law_an_author_value_wins_and_the_stale_proposal_goes() {
		$this->fixture( 5 );
		Proposals::save( 5, 'description', 'The machine version.' );
		// The author filled the field while the proposal sat in review.
		update_post_meta( 5, Description::META, 'The human version.' );

		$this->assertSame( 'skipped_filled', Proposals::apply( 5, 'description' ) );
		$this->assertSame( 'The human version.', get_post_meta( 5, Description::META, true ) );
		// The stale proposal is gone, so a later "Use all" can't resurrect it.
		$this->assertNull( Proposals::get( 5, 'description' ) );
	}

	public function test_apply_without_a_proposal_is_a_noop() {
		$this->fixture( 5 );
		$this->assertSame( 'skipped_empty', Proposals::apply( 5, 'description' ) );
		$this->assertSame( '', get_post_meta( 5, Description::META, true ) );
	}

	public function test_review_row_carries_current_and_proposed() {
		$this->fixture( 5, array( 'post_title' => 'Hello world' ) );
		Proposals::save( 5, 'description', 'Proposed line.' );

		$row = Proposals::row( 5, 'description' );
		$this->assertSame( 5, $row['id'] );
		$this->assertSame( 'Hello world', $row['title'] );
		$this->assertSame( '', $row['current'] );
		$this->assertSame( 'Proposed line.', $row['proposed'] );
		$this->assertStringContainsString( 'post=5', $row['editLink'] );

		$this->assertNull( Proposals::row( 6, 'description' ) );
	}

	/* ---------------------------------------------------------------- *
	 *  Runner — the draft loop
	 * ---------------------------------------------------------------- */

	/** A runner whose scanner reads the canned wpdb results. */
	private function runner() {
		return new Runner( new \Agentimus\Assist( new Settings() ), new Scanner() );
	}

	public function test_run_parks_a_proposal_per_item_and_reports_remaining() {
		$this->fixture( 5 );
		$this->fixture( 7 );
		$GLOBALS['_af_db_var'] = 12; // The census after the run.

		$result = $this->runner()->run(
			'description',
			array( 5, 7 ),
			static function ( $id ) {
				return 'Draft for ' . $id;
			}
		);

		$this->assertCount( 2, $result['generated'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 12, $result['remaining'] );
		$this->assertTrue( $result['vision'] );
		$this->assertSame( 'Draft for 5', Proposals::get( 5, 'description' ) );
		$this->assertSame( 'Draft for 7', Proposals::get( 7, 'description' ) );
	}

	public function test_one_failing_item_is_an_error_entry_not_the_end_of_the_run() {
		$this->fixture( 5 );
		$this->fixture( 7 );
		$result = $this->runner()->run(
			'description',
			array( 5, 7 ),
			static function ( $id ) {
				return 5 === $id
					? new \WP_Error( 'agentimus_thin', 'Too little content.' )
					: 'Draft for ' . $id;
			}
		);

		$this->assertCount( 1, $result['generated'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( 5, $result['errors'][0]['id'] );
		$this->assertNull( Proposals::get( 5, 'description' ) );
		$this->assertSame( 'Draft for 7', Proposals::get( 7, 'description' ) );
	}

	public function test_no_vision_stops_the_alt_run_after_the_first_miss() {
		$this->fixture( 9, array( 'post_type' => 'attachment' ) );
		$this->fixture( 11, array( 'post_type' => 'attachment' ) );
		$calls  = 0;
		$result = $this->runner()->run(
			'alt',
			array( 9, 11 ),
			static function () use ( &$calls ) {
				$calls++;
				return new \WP_Error( 'agentimus_ai_no_vision', 'No vision model.' );
			}
		);

		// Said once, then stopped — never a second paid attempt that fails identically.
		$this->assertSame( 1, $calls );
		$this->assertFalse( $result['vision'] );
		$this->assertCount( 1, $result['errors'] );
	}

	public function test_run_widens_the_rate_budget_only_for_its_own_duration() {
		$this->fixture( 5 );
		$seen = null;
		$this->runner()->run(
			'description',
			array( 5 ),
			static function () use ( &$seen ) {
				$seen = apply_filters( 'agentimus_assist_rate_max', 20 );
				return 'Draft.';
			}
		);

		$this->assertSame( Runner::BULK_RATE_MAX, $seen, 'inside the run, the bulk budget rides the Assist filter' );
		$this->assertSame( 20, apply_filters( 'agentimus_assist_rate_max', 20 ), 'after the run, the button budget is back' );
	}

	public function test_run_never_redrafts_or_overwrites() {
		$this->fixture( 5 );
		$this->fixture( 7 );
		$this->fixture( 8 );
		Proposals::save( 5, 'description', 'Already drafted.' );          // holds a draft
		update_post_meta( 7, Description::META, 'Author filled it.' );    // author beat us

		$calls = array();
		$this->runner()->run(
			'description',
			array( 5, 7, 8 ),
			static function ( $id ) use ( &$calls ) {
				$calls[] = $id;
				return 'Draft for ' . $id;
			}
		);

		// Only the genuinely undrafted, unfilled item cost a call.
		$this->assertSame( array( 8 ), $calls );
		$this->assertSame( 'Already drafted.', Proposals::get( 5, 'description' ) );
	}

	public function test_item_row_carries_null_proposed_before_drafting() {
		$this->fixture( 5, array( 'post_title' => 'Bare page' ) );

		$row = Proposals::item_row( 5, 'description' );
		$this->assertSame( 'Bare page', $row['title'] );
		$this->assertNull( $row['proposed'] );

		Proposals::save( 5, 'description', 'Now drafted.' );
		$this->assertSame( 'Now drafted.', Proposals::item_row( 5, 'description' )['proposed'] );
	}

	public function test_items_page_lists_drafted_and_undrafted_alike() {
		$GLOBALS['_af_db_col'] = array();
		( new Scanner() )->items_page( 'description', 2, 20 );

		$sql = end( $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'LIMIT 20 OFFSET 20', $sql );
		$this->assertStringContainsString( 'ORDER BY p.post_modified DESC', $sql );
		// The transparent scan list must NOT hide items that already hold a draft.
		$this->assertStringNotContainsString( Proposals::meta_key( 'description' ), $sql );
	}

	/* ---------------------------------------------------------------- *
	 *  Scanner — clamps and query shape
	 * ---------------------------------------------------------------- */

	public function test_scanner_field_vocabulary() {
		$this->assertTrue( Scanner::valid_field( 'description' ) );
		$this->assertTrue( Scanner::valid_field( 'topics' ) );
		$this->assertTrue( Scanner::valid_field( 'alt' ) );
		$this->assertFalse( Scanner::valid_field( 'title' ) );
		$this->assertFalse( Scanner::valid_field( '' ) );
	}

	public function test_missing_ids_clamps_to_the_run_cap() {
		$GLOBALS['_af_db_col'] = array();
		( new Scanner() )->missing_ids( 'description', 9999 );

		$sql = end( $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'LIMIT ' . Scanner::run_cap(), $sql );
	}

	public function test_missing_ids_skips_items_that_already_hold_a_proposal() {
		$GLOBALS['_af_db_col'] = array();
		( new Scanner() )->missing_ids( 'topics', 3 );

		$sql = end( $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( Proposals::meta_key( 'topics' ), $sql );
		$this->assertStringContainsString( 'ORDER BY p.post_modified DESC', $sql );
		// Present-but-empty counts as missing: the empty serialized array is not a value.
		$this->assertStringContainsString( "a:0:{}", $sql );
	}

	public function test_alt_census_is_scoped_to_images_pages_actually_use() {
		$GLOBALS['_af_db_var'] = 0;
		( new Scanner() )->missing_count( 'alt' );

		$sql = end( $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( "post_mime_type LIKE 'image/%'", $sql );
		$this->assertStringContainsString( '_thumbnail_id', $sql );
		$this->assertStringContainsString( '_wp_attachment_image_alt', $sql );
		$this->assertStringContainsString( 'post_parent IN', $sql, 'attached to a covered post, or…' );
	}

	public function test_missing_ids_excludes_items_that_failed_this_run() {
		$GLOBALS['_af_db_col'] = array();
		( new Scanner() )->missing_ids( 'description', 3, array( 41, 42 ) );

		$sql = end( $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'p.ID NOT IN ( 41, 42 )', $sql, 'a failed item is never re-picked (and re-paid) within a run' );
	}

	public function test_run_cap_is_filterable() {
		$this->assertSame( Scanner::RUN_CAP, Scanner::run_cap() );

		add_filter(
			'agentimus_bulk_run_cap',
			static function () {
				return 10;
			}
		);
		$this->assertSame( 10, Scanner::run_cap() );
	}
}
