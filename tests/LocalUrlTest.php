<?php
/**
 * LocalUrl::resolve — the one pasted-URL rule every "check this page" box reads
 * (Google's index lookup and Bing's URL check). Extracted from Google\Index;
 * these cases pin the shapes real people paste, so the two boxes can never
 * drift apart on what "this site's page" means.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\LocalUrl;
use PHPUnit\Framework\TestCase;

final class LocalUrlTest extends TestCase {

	public function test_a_bare_path_is_this_site_by_definition() {
		$this->assertSame( home_url( '/terms/' ), LocalUrl::resolve( 'terms/' ) );
		$this->assertSame( home_url( '/sitemap.xml' ), LocalUrl::resolve( '/sitemap.xml' ) );
	}

	public function test_a_full_local_url_passes_through() {
		$url = home_url( '/hello-world/' );
		$this->assertSame( $url, LocalUrl::resolve( $url ) );
	}

	public function test_a_foreign_host_answers_empty() {
		$this->assertSame( '', LocalUrl::resolve( 'https://elsewhere.test/x/' ) );
		$this->assertSame( '', LocalUrl::resolve( '//elsewhere.test/x' ), 'scheme-relative is still a host claim' );
	}

	public function test_a_scheme_relative_local_paste_resolves() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$out  = LocalUrl::resolve( '//' . $host . '/a-page/' );
		$this->assertSame( $host, wp_parse_url( $out, PHP_URL_HOST ), 'the paste Bing used to mis-read as a path' );
	}

	public function test_the_site_written_out_host_first_is_recognised() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$out  = LocalUrl::resolve( $host . '/terms/' );
		$this->assertSame( $host, wp_parse_url( $out, PHP_URL_HOST ) );
	}

	public function test_empty_answers_empty() {
		$this->assertSame( '', LocalUrl::resolve( '' ) );
		$this->assertSame( '', LocalUrl::resolve( '   ' ) );
	}
}
