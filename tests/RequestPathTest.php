<?php
/**
 * Request::path() — the request path relative to the site's home, which is
 * what every "/llms.txt"-style comparison means. On a site installed in a
 * subdirectory (home = https://example.test/blog) the raw path is
 * "/blog/llms.txt"; compared raw it never matched, so llms.txt, the .md pages,
 * the sitemap and the IndexNow key file all fell through to a 404.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Request;
use PHPUnit\Framework\TestCase;

final class RequestPathTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		\_af_reset_options();
	}

	private function path( string $uri ): string {
		$_SERVER['REQUEST_URI'] = $uri;
		return Request::path();
	}

	public function test_root_install_keeps_the_raw_path() {
		$this->assertSame( '/llms.txt', $this->path( '/llms.txt' ) );
		$this->assertSame( '/hello-world.md', $this->path( '/hello-world.md?x=1' ) );
		$this->assertSame( '/', $this->path( '/' ) );
	}

	public function test_subdirectory_install_strips_the_home_path() {
		$GLOBALS['_af_home'] = 'https://example.test/blog';
		$this->assertSame( '/llms.txt', $this->path( '/blog/llms.txt' ) );
		$this->assertSame( '/hello-world.md', $this->path( '/blog/hello-world.md' ) );
		$this->assertSame( '/wp-sitemap.xml', $this->path( '/blog/wp-sitemap.xml' ) );
		$this->assertSame( '/', $this->path( '/blog/' ) );
		$this->assertSame( '/', $this->path( '/blog' ) );
	}

	public function test_a_lookalike_prefix_is_not_the_home_path() {
		// "/blogroll/llms.txt" starts with "/blog" but is not under it.
		$GLOBALS['_af_home'] = 'https://example.test/blog';
		$this->assertSame( '/blogroll/llms.txt', $this->path( '/blogroll/llms.txt' ) );
	}
}
