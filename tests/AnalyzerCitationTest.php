<?php
/**
 * Citation detection ({@see Analyzer::domain_in_citations}, exercised through the
 * public {@see Analyzer::analyze}) matches the site's exact host or a subdomain of
 * it — never a look-alike. A bare substring test used to count "notmyexample.com"
 * or "myexample.com.evil.net" as citing "myexample.com".
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Visibility\Analyzer;
use PHPUnit\Framework\TestCase;

final class AnalyzerCitationTest extends TestCase {

	/** Cited only via the citations list — the answer text never names the host. */
	private function cited( array $citations ) {
		$out = Analyzer::analyze(
			array(
				'text'      => 'An answer that does not name the site in its prose.',
				'citations' => $citations,
			),
			'Example',
			'myexample.com',
			array()
		);
		return $out['cited'];
	}

	public function test_exact_host_is_cited() {
		$this->assertTrue( $this->cited( array( 'https://myexample.com/page' ) ) );
	}

	public function test_subdomain_is_cited() {
		$this->assertTrue( $this->cited( array( 'https://docs.myexample.com/guide' ) ) );
	}

	public function test_scheme_less_bare_domain_is_cited() {
		$this->assertTrue( $this->cited( array( 'myexample.com' ) ) );
	}

	public function test_prefix_lookalike_is_not_cited() {
		$this->assertFalse( $this->cited( array( 'https://notmyexample.com/page' ) ) );
	}

	public function test_suffix_lookalike_is_not_cited() {
		$this->assertFalse( $this->cited( array( 'https://myexample.com.evil.net/page' ) ) );
	}

	public function test_unrelated_host_is_not_cited() {
		$this->assertFalse( $this->cited( array( 'https://competitor.com/page' ) ) );
	}
}
