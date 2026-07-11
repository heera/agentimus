<?php
/**
 * AI-Visibility prompt suggestions — the pure phrasing + de-dup seam.
 *
 * Locks that the generator turns brand/category/competitors into natural questions,
 * never re-suggests a prompt already tracked (case-insensitively), and caps the count.
 *
 * Also locks the rule that motivated the category field: suggestions come from what the
 * product IS, never from the site's editorial topics. A suggestion becomes a tracked
 * prompt that is graded every run, so a question the product could never legitimately
 * answer ("What is the best JavaScript?") would score a permanent zero and be reported
 * as "AI never mentions you". Better to offer nothing than to offer that.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Visibility\Suggest;
use PHPUnit\Framework\TestCase;

final class VisibilitySuggestTest extends TestCase {

	public function test_builds_brand_category_and_competitor_questions() {
		$out = Suggest::questions( 'Agentimus', 'WordPress SEO plugin', array( 'Yoast' ), array() );
		$this->assertContains( 'What is Agentimus?', $out );
		$this->assertContains( 'What is the best WordPress SEO plugin?', $out );
		$this->assertContains( 'Agentimus vs Yoast', $out );
	}

	/**
	 * The whole point of the category: the buyer-intent questions are the ones worth
	 * grading, and they never mention the brand.
	 */
	public function test_category_questions_are_unbranded() {
		$out = Suggest::questions( 'Agentimus', 'WordPress SEO plugin', array(), array() );
		$this->assertContains( 'How do I choose the right WordPress SEO plugin?', $out );
		$this->assertContains( 'Which WordPress SEO plugin do you recommend?', $out );
	}

	/**
	 * With no category we can only ask about the brand — we must NOT invent a market for
	 * it. This is the regression that the old topics-driven generator produced.
	 */
	public function test_without_a_category_only_brand_questions_are_offered() {
		$out = Suggest::questions( 'Agentimus', '', array( 'Yoast' ), array() );
		$this->assertSame( array( 'What is Agentimus?', 'Agentimus vs Yoast' ), $out );
	}

	/**
	 * Every phrasing must survive a category starting with a vowel-sounding consonant —
	 * the a/an trap. The templates dodge it by construction ("the right X", "a good X"),
	 * so this asserts no "an" was ever needed.
	 */
	public function test_phrasing_never_needs_an_article_fix() {
		$out = Suggest::questions( 'Acme', 'user management plugin', array(), array() );
		$this->assertContains( 'How do I choose the right user management plugin?', $out );
		$this->assertContains( 'Is Acme a good user management plugin?', $out );
		foreach ( $out as $q ) {
			$this->assertStringNotContainsString( ' an user', $q );
			$this->assertStringNotContainsString( ' a an ', $q );
		}
	}

	public function test_skips_prompts_already_tracked_case_insensitively() {
		$out = Suggest::questions( 'Agentimus', '', array(), array( 'what is agentimus' ) );
		$this->assertNotContains( 'What is Agentimus?', $out );
	}

	public function test_competitor_questions_need_a_brand() {
		$out = Suggest::questions( '', '', array( 'Yoast' ), array() );
		$this->assertSame( array(), $out );
	}

	public function test_collapses_whitespace_in_a_pasted_category() {
		$out = Suggest::questions( '', "  WordPress  SEO\nplugin ", array(), array() );
		$this->assertContains( 'What is the best WordPress SEO plugin?', $out );
	}

	public function test_is_capped() {
		$competitors = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$competitors[] = 'Rival ' . $i;
		}
		$this->assertLessThanOrEqual( 10, count( Suggest::questions( 'Brand', 'CRM', $competitors, array() ) ) );
	}

	/* ---------------------------------------------------------------------- *
	 *  The AI reply parser (exercised through strip_branded + questions).
	 * ---------------------------------------------------------------------- */

	/** A question naming the product measures nothing — the model gets our name back. */
	public function test_strip_branded_drops_questions_naming_the_product() {
		$out = Suggest::strip_branded(
			array( 'What is the best CRM?', 'Is Acme the best CRM?', 'acme vs the rest?' ),
			'Acme'
		);
		$this->assertSame( array( 'What is the best CRM?' ), $out );
	}

	public function test_strip_branded_is_a_no_op_without_a_brand() {
		$in = array( 'What is the best CRM?' );
		$this->assertSame( $in, Suggest::strip_branded( $in, '' ) );
	}

	/**
	 * A product named after a common word ("Redirection", "Members") makes the substring
	 * filter eat innocent questions. That's accepted — but it must never be able to leave
	 * the owner with nothing, which is why ai_questions() falls back to the templates.
	 * Locking the filter's behaviour here so that trade-off stays visible.
	 */
	public function test_strip_branded_can_empty_the_list_for_a_common_word_brand() {
		$out = Suggest::strip_branded(
			array( 'How do I set up a redirection in WordPress?' ),
			'Redirection'
		);
		$this->assertSame( array(), $out, 'documents the known false positive the AI fallback covers' );
	}
}
