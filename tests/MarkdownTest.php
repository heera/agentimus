<?php
/**
 * Markdown endpoint privacy. A published-but-password-protected post (the HTML
 * site shows only a password form for it) must never have its body emitted as
 * markdown via /slug.md, Accept: text/markdown, or /llms-full.txt. Likewise
 * non-published statuses and missing posts. Regression test for the pre-1.0.0
 * release audit.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/**
	 * Register a minimal post fixture for the mocked get_post().
	 *
	 * @param int   $id        Post ID.
	 * @param array $overrides Field overrides.
	 * @return void
	 */
	private function fixture( $id, array $overrides = array() ) {
		$GLOBALS['_af_posts'][ $id ] = (object) array_merge(
			array(
				'ID'            => $id,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'page',
				'post_title'    => 'Public Page',
				'post_content'  => '<p>Readable body.</p>',
				'post_author'   => 1,
			),
			$overrides
		);
	}

	public function test_password_protected_post_is_not_exposed() {
		$this->fixture( 1, array( 'post_password' => 'hunter2', 'post_content' => '<p>Members-only secret.</p>' ) );
		$out = Markdown::post( 1 );
		$this->assertSame( "# Not found\n", $out );
		$this->assertStringNotContainsString( 'Members-only secret', $out );
	}

	public function test_non_published_post_is_not_exposed() {
		$this->fixture( 2, array( 'post_status' => 'draft' ) );
		$this->assertSame( "# Not found\n", Markdown::post( 2 ) );
	}

	public function test_missing_post_is_not_found() {
		$this->assertSame( "# Not found\n", Markdown::post( 999 ) );
	}

	public function test_public_published_post_still_renders() {
		$this->fixture( 3 );
		$md = Markdown::post( 3 );
		$this->assertStringContainsString( '# Public Page', $md );
		$this->assertStringContainsString( 'Readable body.', $md );
		$this->assertStringNotContainsString( 'Not found', $md );
	}
}
