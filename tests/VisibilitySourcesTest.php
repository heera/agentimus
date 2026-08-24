<?php
/**
 * The shape of a cited source ({@see Sources}) and what it is allowed to claim.
 *
 * A source used to be a bare URL, which assumed every engine hands back the
 * address of the page it read. Gemini returns Google's redirector for every
 * grounding chunk and names the real site only in a sibling field — so a URL
 * alone could name Google as the source of everything, and could never match
 * the owner's own domain. These pin the pair that replaced it: what a label may
 * be read as, what it may not, and what one row per cited site means.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Visibility\Sources;
use PHPUnit\Framework\TestCase;

final class VisibilitySourcesTest extends TestCase {

	private function wrapped( $label ) {
		return array(
			'url'   => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AbC1',
			'label' => $label,
		);
	}

	/* -- normalize: one shape, whatever came in ---------------------------- */

	public function test_a_bare_url_string_normalizes_into_a_labelless_record() {
		$this->assertSame(
			array( array( 'url' => 'https://example.com/page', 'label' => '' ) ),
			Sources::normalize( array( 'https://example.com/page' ) )
		);
	}

	public function test_a_record_survives_normalization_intact() {
		$this->assertSame(
			array( array( 'url' => 'https://x.test/a', 'label' => 'x.test' ) ),
			Sources::normalize( array( array( 'url' => 'https://x.test/a', 'label' => 'x.test' ) ) )
		);
	}

	public function test_a_source_that_names_nothing_is_dropped() {
		$this->assertSame( array(), Sources::normalize( array( '', array( 'url' => '', 'label' => '' ) ) ) );
	}

	/* -- hosts: every host a source could stand for ------------------------ */

	public function test_a_wrapped_source_offers_both_its_label_and_its_wrapper() {
		$this->assertSame(
			array( 'heera.it', 'vertexaisearch.cloud.google.com' ),
			Sources::hosts( $this->wrapped( 'heera.it' ) )
		);
	}

	/** A label is a host only when it looks like one — a page title names none. */
	public function test_a_page_title_label_is_never_read_as_a_host() {
		$this->assertSame(
			array( 'vertexaisearch.cloud.google.com' ),
			Sources::hosts( $this->wrapped( 'Sheikh Heera — Software Developer' ) )
		);
	}

	public function test_a_www_label_is_reported_bare() {
		$this->assertContains( 'heera.it', Sources::hosts( $this->wrapped( 'www.heera.it' ) ) );
	}

	public function test_a_scheme_less_bare_url_still_yields_its_host() {
		$this->assertSame( array( 'myexample.com' ), Sources::hosts( 'myexample.com/page?q=1' ) );
	}

	/* -- key: one row per cited site --------------------------------------- */

	/** Two grounding chunks read from one site are one source to a reader. */
	public function test_two_wrapped_chunks_from_one_site_share_a_key() {
		$a = array( 'url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/A', 'label' => 'heera.it' );
		$b = array( 'url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/B', 'label' => 'heera.it' );
		$this->assertSame( Sources::key( $a ), Sources::key( $b ) );
	}

	/** Two real pages on one domain are two sources — each link is worth keeping. */
	public function test_two_real_pages_on_one_domain_keep_separate_keys() {
		$this->assertNotSame(
			Sources::key( 'https://example.com/one' ),
			Sources::key( 'https://example.com/two' )
		);
	}
}
