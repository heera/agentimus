<?php
/**
 * Per-page content-quality checks — the HTML analysis that grades a post for AI
 * readability. These lock the parser (headings/links/images/word counts from real,
 * messy markup) and each check's pass/warn boundary, so the editor panel never
 * mis-grades a page.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\PageCheck;
use PHPUnit\Framework\TestCase;

final class PageCheckTest extends TestCase {

	/** Invoke a private static check with a stats array. */
	private function check( string $method, array $stats ): array {
		$m = new \ReflectionMethod( PageCheck::class, $method );
		$m->setAccessible( true );
		return (array) $m->invoke( null, $stats );
	}

	/* -- stats() parser --------------------------------------------------- */

	public function test_stats_parses_structure() {
		$html = '<h2>A</h2><h3>B</h3><p>one two three</p><a href="#">go now</a><img alt="x" src="a.jpg"><img src="b.jpg">';
		$s    = PageCheck::stats( $html, false );

		$this->assertSame( array( 2, 3 ), $s['headings'] );
		$this->assertSame( array( 3 ), $s['paragraphs'] );   // one paragraph, 3 words
		$this->assertSame( 1, $s['links'] );
		$this->assertSame( 2, $s['link_words'] );            // "go now"
		$this->assertSame( 2, $s['images'] );
		$this->assertSame( 1, $s['images_no_alt'] );         // the second img has no alt
		$this->assertSame( 7, $s['words'] );                 // A B one two three go now
	}

	public function test_stats_tolerates_empty_and_plain() {
		$this->assertSame( 0, PageCheck::stats( '', false )['words'] );
		$this->assertSame( array(), PageCheck::stats( '', false )['headings'] );
	}

	/* -- individual checks ------------------------------------------------ */

	public function test_thin_content_warns() {
		$this->assertSame( 'warn', $this->check( 'check_words', array( 'words' => 20 ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_words', array( 'words' => 500 ) )['status'] );
	}

	public function test_headings_only_expected_on_long_content() {
		// Long content, no headings → warn.
		$this->assertSame( 'warn', $this->check( 'check_headings', array( 'words' => 800, 'headings' => array() ) )['status'] );
		// Short content, no headings → pass (doesn't need them).
		$this->assertSame( 'pass', $this->check( 'check_headings', array( 'words' => 120, 'headings' => array() ) )['status'] );
		// Long content WITH headings → pass.
		$this->assertSame( 'pass', $this->check( 'check_headings', array( 'words' => 800, 'headings' => array( 2, 2 ) ) )['status'] );
	}

	public function test_heading_order_flags_skipped_levels() {
		$this->assertSame( 'warn', $this->check( 'check_heading_order', array( 'headings' => array( 2, 4 ) ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_heading_order', array( 'headings' => array( 2, 3, 3, 4 ) ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_heading_order', array( 'headings' => array() ) )['status'] );
	}

	public function test_link_density_warns_when_nav_heavy() {
		// 60 words, 40 of them linked → 66% → warn.
		$this->assertSame( 'warn', $this->check( 'check_link_density', array( 'words' => 60, 'link_words' => 40 ) )['status'] );
		// Normal prose → pass.
		$this->assertSame( 'pass', $this->check( 'check_link_density', array( 'words' => 300, 'link_words' => 20 ) )['status'] );
		// Too short to judge → pass.
		$this->assertSame( 'pass', $this->check( 'check_link_density', array( 'words' => 30, 'link_words' => 25 ) )['status'] );
	}

	public function test_alt_text_warns_on_missing() {
		$this->assertSame( 'warn', $this->check( 'check_alt_text', array( 'images' => 3, 'images_no_alt' => 1 ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_alt_text', array( 'images' => 3, 'images_no_alt' => 0 ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_alt_text', array( 'images' => 0, 'images_no_alt' => 0 ) )['status'] );
	}

	public function test_summary_prefers_excerpt_or_lead() {
		$base = array( 'words' => 400, 'paragraphs' => array( 3 ), 'has_excerpt' => false );
		// Weak lead, no excerpt → warn.
		$this->assertSame( 'warn', $this->check( 'check_summary', $base )['status'] );
		// Manual excerpt → pass.
		$this->assertSame( 'pass', $this->check( 'check_summary', array_merge( $base, array( 'has_excerpt' => true ) ) )['status'] );
		// Substantive first paragraph → pass.
		$this->assertSame( 'pass', $this->check( 'check_summary', array_merge( $base, array( 'paragraphs' => array( 25 ) ) ) )['status'] );
		// Thin page → stands down (thin check owns it) → pass.
		$this->assertSame( 'pass', $this->check( 'check_summary', array( 'words' => 40, 'paragraphs' => array( 2 ), 'has_excerpt' => false ) )['status'] );
	}

	/* -- summary() -------------------------------------------------------- */

	public function test_summary_counts_by_status() {
		$rows = array(
			array( 'status' => 'pass' ),
			array( 'status' => 'warn' ),
			array( 'status' => 'warn' ),
		);
		$this->assertSame( array( 'pass' => 1, 'warn' => 2, 'fail' => 0 ), PageCheck::summary( $rows ) );
	}
}
