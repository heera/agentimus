<?php
/**
 * Repository::as_list — what "several" means for the request log's filters.
 *
 * The log's Client / Endpoint / Network / Verification controls became
 * multi-pick on 2026-08-21 ("right now, it's not very flexible"). A query string
 * can only carry text, so a list travels as "a,b" and arrives here; the UI hands
 * over a real array. Both must land on the same values, because the SQL below
 * turns a list of one into `col = %s` and a longer one into `IN (...)`.
 *
 * Pins: both input shapes, the empties that must not become `IN ('')`, the
 * de-duplication that keeps a repeated pick from widening the SQL, and the
 * non-scalars a malformed request can carry.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Repository;
use PHPUnit\Framework\TestCase;

final class ActivityFilterListTest extends TestCase {

	public function test_a_comma_string_is_the_wire_shape() {
		$this->assertSame( array( 'ChatGPT', 'Cursor' ), Repository::as_list( 'ChatGPT,Cursor' ) );
	}

	public function test_an_array_is_the_ui_shape() {
		$this->assertSame( array( 'ChatGPT', 'Cursor' ), Repository::as_list( array( 'ChatGPT', 'Cursor' ) ) );
	}

	public function test_one_value_is_still_a_list() {
		$this->assertSame( array( 'ChatGPT' ), Repository::as_list( 'ChatGPT' ) );
	}

	/** ⛔ The guard that matters: an empty filter is NO filter, never `IN ()`. */
	public function test_nothing_selected_is_no_filter() {
		$this->assertSame( array(), Repository::as_list( '' ) );
		$this->assertSame( array(), Repository::as_list( array() ) );
		$this->assertSame( array(), Repository::as_list( null ) );
	}

	/** A trailing comma from a joined picker must not become an empty needle. */
	public function test_empty_parts_are_dropped() {
		$this->assertSame( array( 'ChatGPT' ), Repository::as_list( 'ChatGPT,' ) );
		$this->assertSame( array( 'ChatGPT', 'Cursor' ), Repository::as_list( ',ChatGPT,,Cursor,' ) );
	}

	public function test_surrounding_space_is_trimmed() {
		$this->assertSame( array( 'ChatGPT', 'Cursor' ), Repository::as_list( ' ChatGPT , Cursor ' ) );
	}

	/** The same pick twice would add a redundant OR to the query. */
	public function test_repeats_collapse() {
		$this->assertSame( array( 'ChatGPT' ), Repository::as_list( 'ChatGPT,ChatGPT' ) );
		$this->assertSame( array( 'a', 'b' ), Repository::as_list( array( 'a', 'b', 'a' ) ) );
	}

	/** Order is the owner's, not sorted: the picker's own sequence survives. */
	public function test_order_is_preserved() {
		$this->assertSame( array( 'Cursor', 'ChatGPT' ), Repository::as_list( 'Cursor,ChatGPT' ) );
	}

	/** Verification mixes a verdict with an outcome; both are just tokens here. */
	public function test_verdict_tokens_pass_through_untouched() {
		$this->assertSame( array( '2', 'refused' ), Repository::as_list( '2,refused' ) );
	}

	/** A malformed request can nest anything; nothing non-scalar reaches the SQL. */
	public function test_non_scalars_are_ignored() {
		$this->assertSame( array( 'ChatGPT' ), Repository::as_list( array( 'ChatGPT', array( 'x' ), null, false, new \stdClass() ) ) );
		$this->assertSame( array(), Repository::as_list( new \stdClass() ) );
	}
}
