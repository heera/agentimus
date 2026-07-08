<?php
/**
 * AI-Visibility prompt suggestions (Phase 1 · T4) — the pure phrasing + de-dup seam.
 *
 * Locks that the generator turns brand/topics/competitors into natural questions,
 * never re-suggests a prompt already tracked (case-insensitively), and caps the count.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Visibility\Suggest;
use PHPUnit\Framework\TestCase;

final class VisibilitySuggestTest extends TestCase {

	public function test_builds_brand_topic_and_competitor_questions() {
		$out = Suggest::questions( 'Agentimus', array( 'llms.txt plugin' ), array( 'Yoast' ), array() );
		$this->assertContains( 'What is Agentimus?', $out );
		$this->assertContains( 'What is the best llms.txt plugin?', $out );
		$this->assertContains( 'Agentimus vs Yoast', $out );
	}

	public function test_skips_prompts_already_tracked_case_insensitively() {
		$out = Suggest::questions( 'Agentimus', array(), array(), array( 'what is agentimus' ) );
		$this->assertNotContains( 'What is Agentimus?', $out );
	}

	public function test_competitor_questions_need_a_brand() {
		$out = Suggest::questions( '', array(), array( 'Yoast' ), array() );
		$this->assertSame( array(), $out );
	}

	public function test_deduplicates_repeated_topics() {
		$out = Suggest::questions( '', array( 'SEO', 'seo', ' SEO ' ), array(), array() );
		$this->assertSame( array( 'What is the best SEO?', 'How do I choose SEO?' ), $out );
	}

	public function test_is_capped() {
		$topics = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$topics[] = 'topic ' . $i;
		}
		$this->assertLessThanOrEqual( 10, count( Suggest::questions( 'Brand', $topics, array(), array() ) ) );
	}
}
