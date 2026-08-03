<?php
/**
 * Accept-header negotiation for the markdown twin.
 *
 * A page URL that can answer with two different bodies is the one thing a shared
 * cache can get catastrophically wrong: if a CDN stores the markdown under the
 * page's URL, every human visitor is served raw markdown. (This happened in the
 * wild — a crawler asked for markdown seconds after a post was published, and the
 * edge cached that answer.) So the gate is deliberately narrow: markdown is served
 * ONLY to a client that explicitly asks for it AND prefers it to HTML.
 *
 * The bug this pins: the old test was a bare `stripos( $accept, 'text/markdown' )`,
 * which ignored quality values — so a client that plainly preferred HTML got markdown.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Endpoints;
use PHPUnit\Framework\TestCase;

final class AcceptNegotiationTest extends TestCase {

	/** What real browsers send. None of them may ever be answered with markdown. */
	const CHROME  = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
	const SAFARI  = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
	const FIREFOX = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';

	/**
	 * @dataProvider browser_accepts
	 */
	public function test_a_browser_is_never_answered_with_markdown( $accept ) {
		$this->assertFalse( Endpoints::prefers_markdown( $accept ) );
	}

	public function browser_accepts() {
		return array(
			'chrome'  => array( self::CHROME ),
			'safari'  => array( self::SAFARI ),
			'firefox' => array( self::FIREFOX ),
		);
	}

	public function test_a_wildcard_never_grants_markdown() {
		// curl's default. "Anything" is not "markdown" — and every client that CAN'T be
		// answered with markdown at a page URL is one that can't poison a shared cache.
		$this->assertFalse( Endpoints::prefers_markdown( '*/*' ) );
		$this->assertFalse( Endpoints::prefers_markdown( '' ) );
	}

	public function test_an_agent_asking_only_for_markdown_gets_it() {
		$this->assertTrue( Endpoints::prefers_markdown( 'text/markdown' ) );
		$this->assertTrue( Endpoints::prefers_markdown( 'text/markdown, */*;q=0.1' ) );
	}

	public function test_quality_values_decide_the_winner() {
		// The regression: HTML outranks markdown, so HTML must win.
		$this->assertFalse( Endpoints::prefers_markdown( 'text/html;q=0.9, text/markdown;q=0.8' ) );
		// Markdown outranks HTML → markdown.
		$this->assertTrue( Endpoints::prefers_markdown( 'text/markdown;q=0.9, text/html;q=0.8' ) );
		$this->assertTrue( Endpoints::prefers_markdown( 'text/markdown, text/html;q=0.5' ) );
	}

	public function test_a_tie_goes_to_html() {
		// Equal weight is not a preference, and the page URL's own media type is HTML.
		// A caller that wants markdown can say so — or fetch the .md twin.
		$this->assertFalse( Endpoints::prefers_markdown( 'text/html, text/markdown' ) );
		$this->assertFalse( Endpoints::prefers_markdown( 'text/markdown;q=0.7, text/html;q=0.7' ) );
	}

	public function test_a_zero_weight_is_a_refusal_not_a_request() {
		$this->assertFalse( Endpoints::prefers_markdown( 'text/markdown;q=0' ) );
	}

	public function test_parsing_is_forgiving_about_whitespace_case_and_params() {
		$this->assertTrue( Endpoints::prefers_markdown( '  TEXT/MARKDOWN ;  q=1.0 ,  text/html ; q=0.2 ' ) );
		$this->assertTrue( Endpoints::prefers_markdown( 'text/markdown;charset=utf-8;q=0.9, text/html;q=0.4' ) );
	}

	public function test_xhtml_counts_as_html_for_the_comparison() {
		// An old-style browser Accept that ranks XHTML top and markdown below it.
		$this->assertFalse( Endpoints::prefers_markdown( 'application/xhtml+xml;q=0.9, text/markdown;q=0.5' ) );
	}

	/* -- The default: page URLs do NOT negotiate (1.21.2) -------------------- */

	public function test_page_url_negotiation_is_off_by_default() {
		// A page URL answering with two different bodies cannot be made safe from the
		// origin: a CDN that force-caches page URLs (Cloudflare "Cache Everything" +
		// Edge TTL) ignores every no-store directive AND `Vary: Accept`, so an agent's
		// markdown answer gets served to human readers. The .md twin — a distinct URL —
		// is the safe route, and it stays advertised. So this convenience is opt-in.
		$GLOBALS['_af_filters'] = array();
		$this->assertFalse( Endpoints::negotiates_markdown() );
	}

	public function test_it_can_be_turned_back_on_where_the_caching_is_sound() {
		$GLOBALS['_af_filters']['agentimus_negotiate_markdown'] = array(
			static function () {
				return true;
			},
		);
		$this->assertTrue( Endpoints::negotiates_markdown() );
		$GLOBALS['_af_filters'] = array();
	}

	/* -- The markdown answer says "don't store this" in every cache dialect -- */

	public function test_a_markdown_answer_refuses_storage_in_every_cache_dialect() {
		// One dialect per cache layer: the client/spec one, the generic CDN one,
		// Cloudflare's vendor one that outranks it — and nginx's own, because an
		// origin-side FastCGI cache set to ignore `Cache-Control` still honours
		// `X-Accel-Expires`. Dropping any of these re-opens the 1.21.x poisoning:
		// an agent's markdown answer cached under the page URL, served to humans.
		$headers = Endpoints::markdown_no_store_headers();
		$this->assertContains( 'Cache-Control: no-store, max-age=0', $headers );
		$this->assertContains( 'CDN-Cache-Control: no-store', $headers );
		$this->assertContains( 'Cloudflare-CDN-Cache-Control: no-store', $headers );
		$this->assertContains( 'X-Accel-Expires: 0', $headers );
	}
}
