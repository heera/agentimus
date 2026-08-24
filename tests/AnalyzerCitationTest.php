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

	/* -- Wrapped sources: the URL is the engine's redirector, not the site --- */

	/** One grounding chunk: Google's redirect URL, the site named in the label. */
	private function wrapped( $label ) {
		return array(
			'url'   => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AbC1',
			'label' => $label,
		);
	}

	/**
	 * The one this was written for. Every grounded Gemini citation arrives with
	 * Google's redirector as its URL, so a URL-only test could never match this
	 * site — "0% linked · never linked its site" was produced by the code, not
	 * read out of the answer.
	 */
	public function test_a_wrapped_citation_is_cited_through_its_label() {
		$this->assertTrue( $this->cited( array( $this->wrapped( 'myexample.com' ) ) ) );
	}

	public function test_a_wrapped_subdomain_label_is_cited() {
		$this->assertTrue( $this->cited( array( $this->wrapped( 'docs.myexample.com' ) ) ) );
	}

	public function test_a_wrapped_lookalike_label_is_not_cited() {
		$this->assertFalse( $this->cited( array( $this->wrapped( 'notmyexample.com' ) ) ) );
	}

	/** The wrapper itself is never the site — an answer that cited nothing of
	 *  ours must not read as citing us just because Google wrapped it. */
	public function test_a_wrapped_citation_for_another_site_is_not_cited() {
		$this->assertFalse( $this->cited( array( $this->wrapped( 'competitor.com' ) ) ) );
	}

	/** A label that is a page title names no host, and must not be guessed into one. */
	public function test_a_page_title_label_alone_is_not_cited() {
		$this->assertFalse( $this->cited( array( $this->wrapped( 'Everything about myexample.com' ) ) ) );
	}
}
