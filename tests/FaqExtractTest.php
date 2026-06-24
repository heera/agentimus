<?php
/**
 * Faq::extract — pulls Q&A pairs from rendered HTML via two deliberate signals
 * (details/summary disclosure blocks + heading-questions), de-duplicates by
 * question, and ignores prose headings. Pure (DOM only), so no WP stubs needed.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Faq;
use PHPUnit\Framework\TestCase;

final class FaqExtractTest extends TestCase {

	public function test_extracts_details_blocks() {
		$html = '<details><summary>What is it?</summary><p>A discovery layer.</p></details>'
			. '<details><summary>Is it free?</summary><p>Yes, fully.</p></details>';
		$pairs = Faq::extract( $html );
		$this->assertCount( 2, $pairs );
		$this->assertSame( 'What is it?', $pairs[0]['q'] );
		$this->assertSame( 'A discovery layer.', $pairs[0]['a'] );
		$this->assertSame( 'Is it free?', $pairs[1]['q'] );
	}

	public function test_extracts_heading_questions() {
		$html = '<h2>How does it work?</h2><p>It publishes signals.</p>'
			. '<h3>Who is it for?</h3><p>Site owners and agents.</p>';
		$pairs = Faq::extract( $html );
		$this->assertCount( 2, $pairs );
		$this->assertSame( 'How does it work?', $pairs[0]['q'] );
		$this->assertSame( 'It publishes signals.', $pairs[0]['a'] );
	}

	public function test_ignores_non_question_headings() {
		$html = '<h2>About the project</h2><p>Some prose that is not a FAQ.</p>';
		$this->assertSame( array(), Faq::extract( $html ) );
	}

	public function test_dedupes_question_across_signals() {
		// Same question as both a heading and a disclosure block → one pair.
		$html = '<h2>What is it?</h2><p>Heading answer.</p>'
			. '<details><summary>What is it?</summary><p>Details answer.</p></details>';
		$pairs = Faq::extract( $html );
		$this->assertCount( 1, $pairs );
	}

	public function test_collapses_whitespace_in_answer() {
		$html = '<h2>Why?</h2><p>Line one.</p><p>Line two.</p>';
		$pairs = Faq::extract( $html );
		$this->assertCount( 1, $pairs );
		$this->assertSame( 'Line one. Line two.', $pairs[0]['a'] );
	}

	public function test_empty_on_no_content() {
		$this->assertSame( array(), Faq::extract( '' ) );
		$this->assertSame( array(), Faq::extract( '<p>Just a paragraph.</p>' ) );
	}
}
