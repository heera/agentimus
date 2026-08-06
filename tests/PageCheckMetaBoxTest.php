<?php
/**
 * The AI Readability section body ({@see PageCheckMetaBox::rows_html()}) — the
 * markup the editor meta box renders and the REST auto-refresh route
 * ({@see EditorPanel::rest_page_check()}) returns verbatim. Locks the wrapper and
 * that the summary reflects the analysis, so the extraction stays render-identical.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\PageCheckMetaBox;
use PHPUnit\Framework\TestCase;

final class PageCheckMetaBoxTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	public function test_rows_html_reports_substance_for_a_full_post() {
		$post = new \WP_Post( array( 'ID' => 1, 'post_content' => '<p>' . str_repeat( 'word ', 150 ) . '</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );

		$this->assertStringContainsString( 'class="agentimus-pc"', $html );
		$this->assertStringContainsString( 'agentimus-pc__head', $html );
		$this->assertStringContainsString( 'agentimus-pc__row', $html );
		$this->assertStringContainsString( 'Enough substance', $html );
	}

	public function test_rows_html_flags_thin_content() {
		$post = new \WP_Post( array( 'ID' => 2, 'post_content' => '<p>Just a few words.</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );

		// The label names the gap without grading the writing — these strings are
		// quoted verbatim on the Optimize screen, where "Thin content" read as a
		// verdict on the author rather than a thing to do.
		$this->assertStringContainsString( 'Not enough substance yet', $html );
		$this->assertStringContainsString( 'is-warn', $html );
	}

	public function test_rows_html_featured_row_warns_without_one_click_outside_the_editor() {
		unset( $GLOBALS['_af_thumbnails'] );
		$post = new \WP_Post( array( 'ID' => 4, 'post_content' => '<p>' . str_repeat( 'word ', 150 ) . '</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );

		// The post-fact check rides along: no thumbnail → warn row…
		$this->assertStringContainsString( 'No featured image', $html );
		// …but its one-click Generate renders only in a block-editor context.
		$this->assertStringNotContainsString( 'agentimus-pc__genfeat', $html );
	}

	public function test_rows_html_featured_row_passes_when_set() {
		$GLOBALS['_af_thumbnails'] = array( 5 => true );
		$post = new \WP_Post( array( 'ID' => 5, 'post_content' => '<p>' . str_repeat( 'word ', 150 ) . '</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );
		unset( $GLOBALS['_af_thumbnails'] );

		$this->assertStringNotContainsString( 'No featured image', $html );
		$this->assertStringContainsString( 'Featured image', $html );
	}

	public function test_rows_html_output_is_html_escaped() {
		// A row label/detail is escaped, so nothing a check emits can break the markup.
		$post = new \WP_Post( array( 'ID' => 3, 'post_content' => '<p>' . str_repeat( 'word ', 150 ) . '</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );

		$this->assertStringNotContainsString( '<script', $html );
		// The em-dash separator in the summary survives (UTF-8 passthrough, not mangled).
		$this->assertStringContainsString( 'agentimus-pc__reflect', $html );
	}
}
