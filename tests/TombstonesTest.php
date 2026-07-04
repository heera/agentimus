<?php
/**
 * Deletion tombstones — the read/prune/dedup logic that decides which removals a
 * polling agent sees. Recording is a thin WP hook (get_permalink on a WP_Post);
 * these lock the parts that can silently corrupt the feed: rows() must drop
 * entries older than the retention window, must exclude ids that are live again
 * (a re-published post must never read as deleted), and must shape a clean
 * `action:"deleted"` row.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Tombstones;
use PHPUnit\Framework\TestCase;

final class TombstonesTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Seed the option store with raw tombstone records (id => record). */
	private function seed( array $records ): void {
		update_option( Tombstones::OPTION, $records );
	}

	private function record( int $id, int $ts, string $type = 'post' ): array {
		return array(
			'id'   => $id,
			'type' => $type,
			'url'  => "https://example.com/gone-$id/",
			'ts'   => $ts,
		);
	}

	public function test_empty_store_yields_no_rows() {
		$this->assertSame( array(), Tombstones::rows() );
	}

	public function test_row_shape_is_a_deletion() {
		$this->seed( array( 5 => $this->record( 5, time() ) ) );
		$rows = Tombstones::rows();
		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( 'deleted', $row['action'] );
		$this->assertSame( 5, $row['id'] );
		$this->assertSame( 'https://example.com/gone-5/', $row['url'] );
		$this->assertArrayHasKey( 'deleted', $row );      // ISO-8601 removal time
		$this->assertArrayHasKey( '_ts', $row );          // sort key for the feed merge
	}

	public function test_prunes_entries_older_than_retention() {
		$old = time() - ( Tombstones::RETAIN_DAYS + 1 ) * DAY_IN_SECONDS;
		$new = time() - DAY_IN_SECONDS;
		$this->seed(
			array(
				1 => $this->record( 1, $old ),
				2 => $this->record( 2, $new ),
			)
		);
		$rows = Tombstones::rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $rows[0]['id'] );
	}

	public function test_retention_window_is_filterable() {
		$ts = time() - 10 * DAY_IN_SECONDS; // 10 days ago
		$this->seed( array( 7 => $this->record( 7, $ts ) ) );

		// Shrink retention below the entry's age → it's pruned from the view.
		add_filter( 'agentimus_tombstone_retain_days', static function () { return 5; } );
		$this->assertCount( 0, Tombstones::rows() );
		remove_all_filters( 'agentimus_tombstone_retain_days' );
	}

	public function test_excludes_ids_that_are_live_again() {
		$this->seed(
			array(
				1 => $this->record( 1, time() ),
				2 => $this->record( 2, time() ),
			)
		);
		// Post 1 was re-published (present in the live feed) → must not appear as deleted.
		$rows = Tombstones::rows( array( 1 ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $rows[0]['id'] );
	}

	public function test_strip_trashed_suffix() {
		// The mangled trashed slug is reduced to the original public slug.
		$this->assertSame( 'hello-world', Tombstones::strip_trashed_suffix( 'hello-world__trashed' ) );
		// A clean slug (unpublished, not trashed) is untouched.
		$this->assertSame( 'hello-world', Tombstones::strip_trashed_suffix( 'hello-world' ) );
		// Only one suffix is removed.
		$this->assertSame( 'a__trashed', Tombstones::strip_trashed_suffix( 'a__trashed__trashed' ) );
	}

	public function test_skips_malformed_records() {
		$this->seed(
			array(
				1 => array( 'id' => 1 ), // missing ts/url
				2 => $this->record( 2, time() ),
			)
		);
		$rows = Tombstones::rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $rows[0]['id'] );
	}
}
