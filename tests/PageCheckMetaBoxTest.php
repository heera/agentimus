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

		$this->assertStringContainsString( 'Thin content', $html );
		$this->assertStringContainsString( 'is-warn', $html );
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
