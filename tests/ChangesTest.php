<?php
/**
 * Change feed — the pure `since` parsing and delta filtering that decide what a
 * polling agent receives. The query/shape paths are WP-heavy (WP_Query, permalinks)
 * and covered by integration; these lock the two functions that can silently
 * mis-scope the feed: parse_since (must accept ISO-8601 AND Unix, reject junk) and
 * filter_since (must be a STRICT lower bound so an agent never re-fetches the item
 * it already has at exactly `since`, nor misses one modified a second later).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Changes;
use PHPUnit\Framework\TestCase;

final class ChangesTest extends TestCase {

	/** Reflection-call the private static since parser. */
	private function parse( string $raw ) {
		$m = new \ReflectionMethod( Changes::class, 'parse_since' );
		$m->setAccessible( true );
		return $m->invoke( null, $raw );
	}

	/** Reflection-call the private static delta filter. */
	private function filter( array $items, $since ): array {
		$m = new \ReflectionMethod( Changes::class, 'filter_since' );
		$m->setAccessible( true );
		return (array) $m->invoke( null, $items, $since );
	}

	/** Reflection-read the clamped window size. */
	private function maxItems(): int {
		$m = new \ReflectionMethod( Changes::class, 'max_items' );
		$m->setAccessible( true );
		return (int) $m->invoke( null );
	}

	/* -- parse_since ------------------------------------------------------ */

	public function test_parse_empty_is_null() {
		$this->assertNull( $this->parse( '' ) );
		$this->assertNull( $this->parse( '   ' ) );
	}

	public function test_parse_unix_timestamp() {
		$this->assertSame( 1751600000, $this->parse( '1751600000' ) );
	}

	public function test_parse_iso8601() {
		// A fixed UTC instant, independent of the machine's timezone.
		$this->assertSame( strtotime( '2026-07-01T00:00:00+00:00' ), $this->parse( '2026-07-01T00:00:00Z' ) );
	}

	public function test_parse_garbage_is_null() {
		$this->assertNull( $this->parse( 'not-a-date' ) );
	}

	/* -- filter_since ----------------------------------------------------- */

	/** A null bound passes everything through untouched. */
	public function test_filter_null_passes_all() {
		$items = array( array( '_ts' => 100 ), array( '_ts' => 200 ) );
		$this->assertCount( 2, $this->filter( $items, null ) );
	}

	/** Strictly-after: an item AT `since` is excluded (the agent already has it). */
	public function test_filter_boundary_is_exclusive() {
		$items = array( array( '_ts' => 100, 'id' => 1 ), array( '_ts' => 101, 'id' => 2 ) );
		$out   = $this->filter( $items, 100 );
		$this->assertCount( 1, $out );
		$this->assertSame( 2, $out[0]['id'] );
	}

	/** Everything older than or equal to `since` drops out. */
	public function test_filter_excludes_older() {
		$items = array( array( '_ts' => 50 ), array( '_ts' => 150 ), array( '_ts' => 250 ) );
		$this->assertCount( 2, $this->filter( $items, 100 ) );
	}

	/* -- max_items -------------------------------------------------------- */

	public function test_max_items_defaults_within_ceiling() {
		$n = $this->maxItems();
		$this->assertGreaterThanOrEqual( 1, $n );
		$this->assertLessThanOrEqual( Changes::MAX_CEILING, $n );
		$this->assertSame( Changes::DEFAULT_MAX, $n );
	}

	public function test_max_items_filter_is_clamped() {
		add_filter( 'agentimus_changes_max', static function () { return 999999; } );
		$this->assertSame( Changes::MAX_CEILING, $this->maxItems() );
		remove_all_filters( 'agentimus_changes_max' );

		add_filter( 'agentimus_changes_max', static function () { return 0; } );
		$this->assertSame( 1, $this->maxItems() );
		remove_all_filters( 'agentimus_changes_max' );
	}
}
