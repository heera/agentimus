<?php
/**
 * Passage-level coverage ({@see Search\Coverage}).
 *
 * The state that earns this class is SCATTERED: every word present, none of
 * them together. A word count calls that page covered and leaves the owner with
 * nothing to fix; this has to keep telling them apart.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Search\Coverage;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* ---- the four states --------------------------------------------------- */

	public function test_one_passage_carrying_the_whole_search_is_answered() {
		$html = '<h2>Agentimus in a nutshell</h2>'
			. '<p>Agentimus is a free WordPress plugin that publishes llms.txt for AI agents.</p>'
			. '<p>Unrelated closing paragraph.</p>';

		$m = Coverage::measure( $html, 'Release notes', 'llms.txt wordpress plugin' );

		$this->assertSame( Coverage::ANSWERED, $m['state'] );
		$this->assertSame( 'Agentimus in a nutshell', $m['heading'], 'the answering passage names its heading' );
		$this->assertNotSame( '', $m['quote'] );
	}

	/**
	 * The finding this class exists for: all three words on the page, no passage
	 * holding them together. A naive word count would call this covered.
	 */
	public function test_words_spread_across_passages_are_scattered() {
		$html = '<p>Our WordPress plugin shipped today.</p>'
			. '<h2>Protocol notes</h2><p>The MCP specification is young.</p>'
			. '<p>Any server can implement it.</p>';

		$m = Coverage::measure( $html, 'Notes', 'wordpress mcp server' );

		$this->assertSame( Coverage::SCATTERED, $m['state'] );
		$this->assertSame( 3, $m['on_page'], 'every word is present…' );
		$this->assertLessThan( 3, $m['in_passage'], '…but never in one passage' );
	}

	public function test_some_words_only_is_barely() {
		$html = '<p>Our WordPress plugin shipped today.</p>';

		$m = Coverage::measure( $html, 'Notes', 'wordpress mcp server' );

		$this->assertSame( Coverage::BARELY, $m['state'] );
		$this->assertGreaterThan( 0, $m['on_page'] );
		$this->assertLessThan( $m['words'], $m['on_page'] );
	}

	public function test_nothing_on_the_page_is_missing() {
		$html = '<p>A review of three summer fragrances.</p>';

		$m = Coverage::measure( $html, 'Fragrance picks', 'wordpress mcp server' );

		$this->assertSame( Coverage::MISSING, $m['state'] );
		$this->assertSame( 0, $m['on_page'] );
	}

	/* ---- honesty about the evidence ---------------------------------------- */

	/**
	 * A heading is only named when the passage under it actually answered.
	 * Pointing at the best of a bad set sends the owner to the wrong paragraph.
	 */
	public function test_no_heading_is_named_unless_the_page_answered() {
		$html = '<p>Our WordPress plugin shipped today.</p>'
			. '<h2>Protocol notes</h2><p>The MCP specification is young.</p>'
			. '<p>Any server can implement it.</p>';

		$m = Coverage::measure( $html, 'Notes', 'wordpress mcp server' );

		$this->assertSame( Coverage::SCATTERED, $m['state'] );
		$this->assertSame( '', $m['heading'] );
		$this->assertSame( '', $m['quote'] );
	}

	/** A title match is reported separately — it is the edit most worth making. */
	public function test_title_words_are_counted_but_do_not_answer_the_page() {
		$html = '<p>Body copy that mentions none of it.</p>';

		$m = Coverage::measure( $html, 'The WordPress MCP server, explained', 'wordpress mcp server' );

		$this->assertSame( 3, $m['in_title'] );
		$this->assertSame( Coverage::MISSING, $m['state'], 'a title is not an answer' );
	}

	/* ---- what counts as a word --------------------------------------------- */

	public function test_a_heading_answers_alongside_the_passage_beneath_it() {
		$html = '<h2>How to block GPTBot</h2><p>Turn the switch on and save.</p>';

		$m = Coverage::measure( $html, 'Notes', 'block gptbot' );

		$this->assertSame( Coverage::ANSWERED, $m['state'] );
	}

	/** Plurals must not read as a different word, or half of English misses. */
	public function test_plurals_and_gerunds_fold_together() {
		$html = '<p>Blocking crawlers is a setting.</p>';

		$this->assertSame( Coverage::ANSWERED, Coverage::measure( $html, '', 'block crawler' )['state'] );
		// The pair that broke first: dropping "ers" folded the plural past the
		// singular the search used, so "crawlers" stopped matching "crawler".
		$this->assertSame( Coverage::words( 'crawler' ), Coverage::words( 'crawlers' ) );
		$this->assertSame( Coverage::words( 'class' ), Coverage::words( 'classes' ) );
	}

	/** Dotted and hyphenated terms are single words, not fragments to match on. */
	public function test_dotted_and_hyphenated_terms_survive_intact() {
		$this->assertContains( 'llms.txt', Coverage::words( 'llms.txt wordpress' ) );
		$this->assertContains( 'json-ld', Coverage::words( 'json-ld schema' ) );
		$this->assertNotContains( 'txt', Coverage::words( 'llms.txt' ), 'a fragment must not become its own hit' );
	}

	public function test_stopwords_and_short_words_are_dropped() {
		$this->assertSame( array( 'block', 'gptbot' ), Coverage::words( 'how to block gptbot' ) );
	}

	/** A search of nothing but stopwords cannot be answered or failed. */
	public function test_a_meaningless_search_measures_nothing() {
		$m = Coverage::measure( '<p>Anything at all.</p>', '', 'how to' );

		$this->assertSame( 0, $m['words'] );
		$this->assertSame( Coverage::MISSING, $m['state'] );
	}

	/* ---- passages ----------------------------------------------------------- */

	public function test_passages_carry_their_nearest_heading() {
		$p = Coverage::passages( '<h2>First</h2><p>One.</p><h2>Second</h2><p>Two.</p><li>Three.</li>' );

		$this->assertCount( 3, $p );
		$this->assertSame( 'First', $p[0]['heading'] );
		$this->assertSame( 'Second', $p[1]['heading'] );
		$this->assertSame( 'Second', $p[2]['heading'], 'a list item belongs to the heading above it' );
	}

	/** Script and style bodies are not prose and must not match on variable names. */
	public function test_scripts_and_styles_are_not_passages() {
		$html = '<script>var crawler = "blocking";</script><style>.crawler{}</style><p>Nothing here.</p>';

		$this->assertSame( Coverage::MISSING, Coverage::measure( $html, '', 'crawler blocking' )['state'] );
	}

	public function test_malformed_and_empty_html_do_not_break() {
		$this->assertSame( array(), Coverage::passages( '' ) );
		$this->assertSame( Coverage::MISSING, Coverage::measure( '<p>unclosed', '', 'anything here' )['state'] );
	}

	/* ---- the seam ----------------------------------------------------------- */

	/** A site can replace the measurement without touching what renders it. */
	public function test_the_verdict_can_be_filtered() {
		\add_filter( 'agentimus_coverage', function ( $out ) {
			$out['state'] = Coverage::ANSWERED;
			return $out;
		} );

		$this->assertSame( Coverage::ANSWERED, Coverage::measure( '<p>Nothing.</p>', '', 'wordpress mcp server' )['state'] );
	}

	public function test_stopwords_can_be_filtered() {
		\add_filter( 'agentimus_coverage_stopwords', function () {
			return array( 'wordpress' );
		} );

		$this->assertNotContains( 'wordpress', Coverage::words( 'wordpress plugin' ) );
	}

	/* -- the per-word verdict ---------------------------------------------- */

	/**
	 * Each searched word comes back in the spelling the SEARCHER used, not the
	 * stem it was folded to. "llms.txt" must never surface as "llms.tx".
	 */
	public function test_terms_keep_the_searchers_spelling() {
		$out   = Coverage::measure( '<p>Nothing here.</p>', 'T', 'Crawlers llms.txt WordPress' );
		$words = array_column( $out['terms'], 'word' );

		$this->assertSame( array( 'crawlers', 'llms.txt', 'wordpress' ), $words );
	}

	/** Three states per word, and they follow the passage the verdict chose. */
	public function test_each_word_reports_where_it_was_found() {
		$html = '<h2>About llms.txt</h2><p>The llms.txt file and WordPress.</p><p>A plugin lives elsewhere.</p>';
		$out  = Coverage::measure( $html, 'T', 'llms.txt wordpress plugin' );
		$by   = array();
		foreach ( $out['terms'] as $t ) {
			$by[ $t['word'] ] = $t;
		}

		$this->assertTrue( $by['llms.txt']['in_passage'], 'in the best passage' );
		$this->assertTrue( $by['wordpress']['in_passage'] );
		// "plugin" is on the page, but in a DIFFERENT passage — that distinction
		// is the whole point of the middle badge.
		$this->assertFalse( $by['plugin']['in_passage'] );
		$this->assertTrue( $by['plugin']['on_page'] );
	}

	public function test_a_missing_search_marks_every_word_absent() {
		$out = Coverage::measure( '<p>Entirely unrelated prose.</p>', 'T', 'llms.txt wordpress plugin' );

		$this->assertSame( 'missing', $out['state'] );
		foreach ( $out['terms'] as $t ) {
			$this->assertFalse( $t['on_page'], $t['word'] . ' should be absent' );
			$this->assertFalse( $t['in_passage'] );
		}
	}

	/** One badge per distinct word — a search that repeats itself must not. */
	public function test_a_repeated_word_yields_one_badge() {
		$out = Coverage::measure( '<p>x</p>', 'T', 'plugin plugin plugins' );
		$this->assertCount( 1, $out['terms'] );
	}
}
