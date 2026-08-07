<?php
/**
 * The editor's focus field ({@see Focus}).
 *
 * The contract worth locking: what a page is "for" resolves the same way for
 * every reader, a value the author typed is never silently discarded, and the
 * measurement is of the SAVED page rather than whatever happens to be in the
 * editor.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Focus;
use Agentimus\Search\Coverage;
use PHPUnit\Framework\TestCase;

final class FocusTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/**
	 * @param int   $id   Post ID.
	 * @param array $meta Post meta.
	 * @param array $post Post-field overrides.
	 */
	private function fixture( $id, array $meta = array(), array $post = array() ) {
		$GLOBALS['_af_posts'][ $id ] = (object) array_merge(
			array(
				'ID'            => $id,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
				'post_title'    => 'A Post',
				'post_content'  => '<p>Body.</p>',
				'post_excerpt'  => '',
				'post_author'   => 1,
			),
			$post
		);
		$GLOBALS['_af_postmeta'][ $id ] = $meta;
		return $GLOBALS['_af_posts'][ $id ];
	}

	/* ---- sanitising ---------------------------------------------------------- */

	/** The value is compared against page text and echoed into an attribute. */
	public function test_a_focus_is_one_line_of_plain_text() {
		$this->assertSame( 'wordpress mcp server', Focus::sanitize( '  wordpress   mcp server  ' ) );
		$this->assertSame( 'one two', Focus::sanitize( "one\n\ntwo" ) );
		// A script survives neither its tags nor its contents — wp_strip_all_tags
		// drops the whole element, which is what keeps this safe in an attribute.
		$this->assertSame( '', Focus::sanitize( '<script>alert(1)</script>' ) );
		$this->assertSame( 'hello', Focus::sanitize( '<b>hello</b>' ) );
	}

	public function test_a_focus_is_capped() {
		$this->assertSame( Focus::MAX_LEN, mb_strlen( Focus::sanitize( str_repeat( 'a', 400 ) ) ) );
	}

	/* ---- what the page is for ------------------------------------------------ */

	/** A saved choice is the answer, and says it was chosen. */
	public function test_a_saved_choice_wins() {
		$this->fixture( 1, array( Focus::META => 'wordpress mcp server' ) );

		$f = Focus::for_post( 1 );
		$this->assertSame( 'wordpress mcp server', $f['query'] );
		$this->assertTrue( $f['chosen'] );
	}

	/**
	 * With nothing chosen and no search data reachable, there is no focus — and
	 * `chosen` says so, which is what lets a surface tell "the author decided
	 * this" from "we picked the busiest search for you".
	 */
	public function test_no_choice_and_no_data_is_an_empty_focus() {
		$this->fixture( 2 );

		$f = Focus::for_post( 2 );
		$this->assertSame( '', $f['query'] );
		$this->assertFalse( $f['chosen'] );
	}

	public function test_a_missing_post_never_fatals() {
		$f = Focus::for_post( 4242 );
		$this->assertSame( '', $f['query'] );
		$this->assertFalse( $f['chosen'] );
	}

	/**
	 * No search table in unit land: the lookup has to come back empty rather
	 * than take the editor down with it.
	 */
	public function test_searches_survive_having_no_search_table() {
		$this->assertSame( array(), Focus::searches( 1 ) );
		$this->assertSame( array(), Focus::searches( 0 ) );
	}

	/* ---- the measurement ----------------------------------------------------- */

	/** The verdict comes from the same resolver every other surface uses. */
	public function test_coverage_measures_the_saved_content() {
		$post = $this->fixture(
			3,
			array(),
			array(
				'post_title'   => 'Notes',
				'post_content' => '<h2>Connecting</h2><p>Agentimus ships a WordPress MCP server you can point Claude at.</p>',
			)
		);

		$c = Focus::coverage( $post, 'wordpress mcp server' );

		$this->assertSame( Coverage::ANSWERED, $c['state'] );
		$this->assertSame( 'Connecting', $c['heading'] );
	}

	/** A title match is reported separately — it is the edit most worth making. */
	public function test_coverage_reports_the_title_separately() {
		$post = $this->fixture(
			4,
			array(),
			array( 'post_title' => 'The WordPress MCP server', 'post_content' => '<p>Nothing relevant.</p>' )
		);

		$c = Focus::coverage( $post, 'wordpress mcp server' );

		$this->assertSame( 3, $c['in_title'] );
		$this->assertSame( Coverage::MISSING, $c['state'], 'a title is not an answer' );
	}

	/** Nothing to measure is null, not a verdict of "missing" against nothing. */
	public function test_no_focus_measures_nothing() {
		$post = $this->fixture( 5 );

		$this->assertNull( Focus::coverage( $post, '' ) );
		$this->assertNull( Focus::coverage( $post, '   ' ) );
		$this->assertNull( Focus::coverage( null, 'anything at all' ) );
	}

	/* ---- the field's own promises -------------------------------------------- */

	/**
	 * The list of choices is bounded, so a page found for two hundred searches
	 * does not turn the sidebar into a scroll.
	 */
	public function test_the_choice_list_is_bounded() {
		$this->assertGreaterThan( 0, Focus::MAX_CHOICES );
		$this->assertLessThanOrEqual( 10, Focus::MAX_CHOICES );
	}

	/**
	 * The meta key is ours alone. The block editor saves twice when meta boxes
	 * are on screen, and a key shared with another writer would be the classic
	 * way for one save to wipe the other.
	 */
	public function test_the_meta_key_is_its_own() {
		$this->assertSame( '_agentimus_focus', Focus::META );
		$this->assertNotSame( Focus::META, \Agentimus\Description::META );
	}
}
