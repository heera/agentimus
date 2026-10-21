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

	/**
	 * A word ending in "e" and its plural are one word. "perfumes" lost its
	 * "es" and became "perfum" while "perfume" stayed "perfume", so a page
	 * saying "perfume" never answered "winter perfumes" (heera.it, 1.53.2).
	 */
	public function test_a_word_ending_in_e_folds_with_its_plural() {
		foreach ( array( 'perfume', 'theme', 'image', 'horse', 'freeze' ) as $one ) {
			$this->assertSame( Coverage::words( $one ), Coverage::words( $one . 's' ), "$one / {$one}s" );
		}
		$html = '<p>My favourite winter perfume for the cold nights.</p>';
		$this->assertSame( Coverage::ANSWERED, Coverage::measure( $html, '', 'winter perfumes' )['state'] );
	}

	/**
	 * …without folding a word into a DIFFERENT one. Dropping every final "e"
	 * would make "theme" read as "them", so a page saying "give them a call"
	 * would answer "wordpress theme".
	 */
	public function test_folding_a_plural_never_lands_on_another_word() {
		$this->assertNotSame( Coverage::words( 'them' ), Coverage::words( 'theme' ) );
		$this->assertNotSame( Coverage::words( 'them' ), Coverage::words( 'themes' ) );
		$this->assertNotSame( Coverage::words( 'plan' ), Coverage::words( 'planes' ) );
		$this->assertSame( Coverage::MISSING, Coverage::measure( '<p>Give them a call.</p>', '', 'theme' )['state'] );
		// The -es plurals still fold with their singular.
		foreach ( array( 'class' => 'classes', 'box' => 'boxes', 'match' => 'matches', 'case' => 'cases', 'process' => 'processes', 'hero' => 'heroes', 'potato' => 'potatoes', 'category' => 'categories', 'query' => 'queries', 'story' => 'stories' ) as $one => $many ) {
			$this->assertSame( Coverage::words( $one ), Coverage::words( $many ), "$one / $many" );
		}
	}

	/**
	 * "-ing" and "-ed" still meet a word that ends in "e". Folding "changes" to
	 * "change" (not "chang") cut it off from "changing" — and "updates" from
	 * "updating", "writes" from "writing". Both directions must hold.
	 */
	public function test_a_gerund_meets_its_base_word_ending_in_e() {
		$pairs = array(
			array( '<p>Office hours change next week.</p>', 'office hours changing' ),
			array( '<p>Office hours are changing next week.</p>', 'office hours change' ),
			array( '<p>How to update a plugin safely.</p>', 'updating plugin' ),
			array( '<p>Updating a plugin safely.</p>', 'update plugins' ),
			array( '<p>Writes the cache to disk.</p>', 'writing cache' ),
			// A stem already ending in "e" still meets its "ee" word.
			array( '<p>The team disagrees.</p>', 'team disagreed' ),
		);
		foreach ( $pairs as $pair ) {
			$this->assertSame( Coverage::ANSWERED, Coverage::measure( $pair[0], '', $pair[1] )['state'], $pair[1] );
		}
		// …and a plain word ending in "e" still never meets a shorter one.
		$this->assertSame( Coverage::MISSING, Coverage::measure( '<p>Give them a call.</p>', '', 'theme' )['state'] );
	}

	/**
	 * An unrendered shortcode is markup, not words. Once underscores split,
	 * "[woocommerce_my_account]" said "account", and a page holding nothing
	 * but that tag answered "my account".
	 */
	public function test_a_shortcode_tag_is_not_a_passage() {
		$this->assertSame( Coverage::MISSING, Coverage::measure( '<p>[woocommerce_my_account]</p>', '', 'my account' )['state'] );
		$this->assertSame( Coverage::MISSING, Coverage::measure( '<p>[js_demo_board status="done"]</p>', '', 'board' )['state'] );
		// Prose around a tag is still read, and a bracketed citation is prose.
		$this->assertSame( Coverage::ANSWERED, Coverage::measure( '<p>Your account settings [account_form] live here.</p>', '', 'account settings' )['state'] );
		$this->assertSame( Coverage::ANSWERED, Coverage::measure( '<p>Crawlers obey robots.txt [1].</p>', '', 'crawlers robots.txt' )['state'] );
	}

	/** Every stem is a fixed point, or two forms of a word could fold apart again. */
	public function test_stemming_twice_changes_nothing() {
		foreach ( array( 'perfumes', 'perfume', 'classes', 'class', 'crawlers', 'blocking', 'cases', 'case', 'trees', 'themes', 'used', 'processes', 'process', 'matches' ) as $w ) {
			$once = Coverage::stem( $w );
			$this->assertSame( $once, Coverage::stem( $once ), $w );
		}
	}

	/** Dotted and hyphenated terms are single words, not fragments to match on. */
	public function test_dotted_and_hyphenated_terms_survive_intact() {
		$this->assertContains( 'llms.txt', Coverage::words( 'llms.txt wordpress' ) );
		$this->assertContains( 'json-ld', Coverage::words( 'json-ld schema' ) );
		$this->assertNotContains( 'txt', Coverage::words( 'llms.txt' ), 'a fragment must not become its own hit' );
	}

	/**
	 * …but a PAGE that writes "AI-discovery" or "the_excerpt" still says
	 * "discovery" and "excerpt". Read only whole, those words were missing from
	 * the very paragraph that answered (heera.it 3612 and 241, 1.53.1).
	 */
	public function test_a_page_compound_still_says_its_parts() {
		$m = Coverage::measure(
			'<p>Agentimus adds an AI-discovery layer to your WordPress site.</p>',
			'',
			'ai discovery layer for wordpress'
		);
		$this->assertSame( Coverage::ANSWERED, $m['state'] );

		$m = Coverage::measure(
			'<p>I wanted to show video clips with the_excerpt on my index page.</p>',
			'',
			'show video in excerpt'
		);
		$this->assertSame( Coverage::ANSWERED, $m['state'] );
	}

	/**
	 * A page about "PHP 5.5.0" or "PHP 5.5.x" is about PHP 5.5 — it never wrote
	 * a bare "5.5", and was "barely" for "php 5.5 new features" (heera.it 1111).
	 */
	public function test_a_page_version_says_its_release() {
		$html = '<p>The PHP development team announced PHP 5.5.0, with a large number of new features.</p>';
		$this->assertSame( Coverage::ANSWERED, Coverage::measure( $html, '', 'php 5.5 new features' )['state'] );

		$html = '<p>Most improvements in PHP 5.5.x have no impact on existing code.</p>';
		$this->assertSame( Coverage::ANSWERED, Coverage::measure( $html, '', 'php 5.5 code' )['state'] );

		// One-sided, like compounds: a page saying 5.5 has not said which patch.
		$html = '<p>PHP 5.5 shipped generators.</p>';
		$this->assertSame( Coverage::BARELY, Coverage::measure( $html, '', 'php 5.5.0' )['state'] );
	}

	/** The search side stays whole: a page saying "json" alone does not answer "json-ld". */
	public function test_a_search_compound_still_needs_the_whole_term() {
		$m = Coverage::measure( '<p>Send json from the server.</p>', '', 'json-ld' );

		$this->assertSame( Coverage::MISSING, $m['state'] );
	}

	public function test_stopwords_and_short_words_are_dropped() {
		$this->assertSame( array( 'block', 'gptbot' ), Coverage::words( 'how to block gptbot' ) );
	}

	/**
	 * A search of nothing but stopwords cannot be answered OR FAILED.
	 *
	 * ⛔ This test said exactly that in its name and then asserted MISSING —
	 * which is a failure, and a claim about a page nothing was ever compared to.
	 * The name was right and the assertion was wrong.
	 */
	public function test_a_meaningless_search_measures_nothing() {
		$m = Coverage::measure( '<p>Anything at all.</p>', '', 'how to' );

		$this->assertSame( 0, $m['words'] );
		$this->assertSame( Coverage::UNREADABLE, $m['state'] );
		$this->assertFalse( Coverage::is_measured( $m['state'] ), 'Nothing was compared, so nothing may be claimed.' );
	}

	/**
	 * ⛔⛔ THE ONE THAT MATTERED. terms() extracts `[a-z0-9._-]`, so a search in
	 * any non-Latin script yields nothing to look for — and that used to come
	 * back MISSING: "None of it is on the page — this may not be what the post
	 * is for", about a page that answers it perfectly.
	 *
	 * On a site written in any of these languages that was EVERY page carrying a
	 * reported search: permanently on the worklist, permanently accused, and
	 * unfixable by writing anything at all. The plugin is not for English sites.
	 */
	public function test_a_search_in_a_script_this_check_cannot_read_is_not_a_failed_page() {
		$pages = array(
			'Russian'  => array( 'как заблокировать роботов', '<p>Как заблокировать роботов через robots.txt.</p>' ),
			'Chinese'  => array( '如何向智能体分发结构化内容', '<p>我们解释了如何向智能体分发结构化的机器可读内容。</p>' ),
			'Japanese' => array( 'クローラー ブロック 方法', '<p>ワードプレスでクローラーをブロックする方法。</p>' ),
			'Arabic'   => array( 'كيفية حظر الروبوتات', '<p>كيفية حظر الروبوتات في ووردبريس.</p>' ),
			'Greek'    => array( 'πώς να μπλοκάρετε ρομπότ', '<p>πώς να μπλοκάρετε τα ρομπότ.</p>' ),
			'Hindi'    => array( 'रोबोट कैसे ब्लॉक करें', '<p>रोबोट को कैसे ब्लॉक करें।</p>' ),
		);
		foreach ( $pages as $language => $case ) {
			list( $query, $html ) = $case;
			$m = Coverage::measure( $html, 'Title', $query );

			$this->assertSame(
				Coverage::UNREADABLE,
				$m['state'],
				"⛔ In $language the page answers its search and this used to report MISSING."
			);
			$this->assertFalse( Coverage::is_measured( $m['state'] ), "⛔ $language: nothing was compared." );
		}
	}

	/** And the sentence an owner reads has to name the limit, not blame the page. */
	public function test_an_unreadable_search_explains_itself_without_accusing_the_page() {
		$said = Coverage::explain( array( 'state' => Coverage::UNREADABLE ) );

		$this->assertSame( 'Not measured', $said['label'] );
		$this->assertStringNotContainsString( 'None of it is on the page', $said['why'] );
		$this->assertStringContainsString( 'Latin', $said['why'], '⭐ The absence has to name itself.' );
	}

	/**
	 * ⛔ A change to what coverage SAYS must move the key that decides whether a
	 * stored verdict is re-read — or the fix reaches no existing install.
	 */
	public function test_the_ruleset_covers_what_this_class_judges_by_and_is_stable() {
		$one = Coverage::ruleset();

		$this->assertNotSame( '', $one );
		$this->assertSame( $one, Coverage::ruleset(), 'Unstable between calls would re-grade for ever.' );

		$widen = static function ( $stop ) {
			return array_merge( (array) $stop, array( 'wordpress' ) );
		};
		add_filter( 'agentimus_coverage_stopwords', $widen );
		$after = Coverage::ruleset();
		remove_filter( 'agentimus_coverage_stopwords', $widen );

		$this->assertNotSame( $one, $after, 'Dropping a word from the search changes what "answered" means.' );
		$this->assertSame( $one, Coverage::ruleset(), 'And it settles again when the filter goes.' );
	}

	/**
	 * 1.53.1 taught coverage to read classic-editor posts. Every install
	 * graded before that holds "missing" verdicts for them, and only a moved
	 * fingerprint sends those back to be read. 7f85fba0 is the 1.53.0 one.
	 */
	public function test_reading_classic_posts_moved_the_ruleset() {
		$this->assertNotSame( '7f85fba0', Coverage::ruleset() );
	}

	/**
	 * 1.53.2 lets a page's hyphenated and underscored words count their parts.
	 * Pages graded under 1.53.1 (1aeb4ff6) must be read again to get it.
	 */
	public function test_reading_compound_parts_moved_the_ruleset() {
		$this->assertNotSame( '1aeb4ff6', Coverage::ruleset() );
	}

	/** …and versions saying their release moved it again: 80cc0386 is dev2's, already on heera.it. */
	/** …and folding "perfume" with "perfumes" moved it once more: cc18bf66 is 1.53.2's. */
	public function test_folding_a_final_e_moved_the_ruleset() {
		$this->assertNotSame( 'cc18bf66', Coverage::ruleset() );
	}

	public function test_reading_versions_moved_the_ruleset() {
		$this->assertNotSame( '80cc0386', Coverage::ruleset() );
	}

	/* ---- passages ----------------------------------------------------------- */

	public function test_passages_carry_their_nearest_heading() {
		$p = Coverage::passages( '<h2>First</h2><p>One.</p><h2>Second</h2><p>Two.</p><li>Three.</li>' );

		$this->assertCount( 3, $p );
		$this->assertSame( 'First', $p[0]['heading'] );
		$this->assertSame( 'Second', $p[1]['heading'] );
		$this->assertSame( 'Second', $p[2]['heading'], 'a list item belongs to the heading above it' );
	}

	/** The quote is text a reader sees, not markup: "&nbsp;" came back raw (heera.it 2021). */
	public function test_passages_are_plain_text_not_entities() {
		$p = Coverage::passages( '<h2>Q&amp;A</h2><p>building an&nbsp;ACL&nbsp;in Laravel &amp; more</p>' );

		$this->assertSame( 'Q&A', $p[0]['heading'] );
		$this->assertSame( 'building an ACL in Laravel & more', $p[0]['text'] );
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
