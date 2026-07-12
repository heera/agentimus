<?php
/**
 * Catalog — the known-crawler recognition layer. identify() turns a raw UA into a
 * plain-English identity card (name / operator / kind / docs URL) so the review
 * queue can tell an owner WHO a flagged client is, not just its token. Pure, so
 * it's exercised here in full isolation.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Catalog;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase {

	const SHAPBOT  = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ShapBot/0.1.0';
	const SEMRUSH  = 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)';
	const GPTBOT   = 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)';
	const APPLEEXT = 'Mozilla/5.0 (compatible; Applebot-Extended/0.1; +http://www.apple.com/go/applebot)';
	const CHROME   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	/* -- Recognised crawlers --------------------------------------------- */

	public function test_identifies_an_ai_crawler_with_operator_and_docs_link() {
		$card = Catalog::identify( self::SHAPBOT );
		$this->assertIsArray( $card );
		$this->assertSame( 'ShapBot', $card['name'] );
		$this->assertSame( 'Parallel', $card['operator'] );
		$this->assertSame( 'ai', $card['kind'] );
		$this->assertNotSame( '', $card['url'], 'A recognised crawler should carry a "what is this?" link.' );
	}

	public function test_identifies_an_seo_crawler() {
		$card = Catalog::identify( self::SEMRUSH );
		$this->assertSame( 'SemrushBot', $card['name'] );
		$this->assertSame( 'Semrush', $card['operator'] );
		$this->assertSame( 'seo', $card['kind'] );
	}

	public function test_card_always_has_the_four_documented_keys() {
		$card = Catalog::identify( self::GPTBOT );
		$this->assertSame( array( 'name', 'operator', 'kind', 'url' ), array_keys( $card ) );
		$this->assertSame( 'OpenAI', $card['operator'] );
	}

	public function test_longer_token_wins_over_a_prefix_it_contains() {
		// "applebot-extended" (an AI opt-out signal) must not be mistaken for a bare
		// "applebot": the catalog is ordered specific-first.
		$this->assertSame( 'Applebot-Extended', Catalog::identify( self::APPLEEXT )['name'] );
	}

	/* -- Non-matches ------------------------------------------------------ */

	public function test_a_real_browser_is_not_a_known_crawler() {
		$this->assertNull( Catalog::identify( self::CHROME ) );
	}

	public function test_empty_ua_is_null() {
		$this->assertNull( Catalog::identify( '' ) );
	}

	public function test_an_unrecognised_bot_is_null() {
		$this->assertNull( Catalog::identify( 'WhateverBot/1.0 (+http://example.com)' ) );
	}

	/* -- Unknown-crawler guidance (self_declared) ------------------------- */

	public function test_self_declared_extracts_the_plus_url_host_and_name() {
		$g = Catalog::self_declared( 'Mozilla/5.0 (compatible; SomeNewBot/1.0; +https://www.example.com/bot)' );
		$this->assertSame( 'https://www.example.com/bot', $g['url'] );
		$this->assertSame( 'example.com', $g['host'], 'Host is shown sans www so the owner sees the destination.' );
		$this->assertSame( 'SomeNewBot', $g['name'] );
		$this->assertStringContainsString( 'duckduckgo.com', $g['lookup'] );
	}

	public function test_self_declared_prefers_a_bot_like_token_and_has_no_url() {
		$g = Catalog::self_declared( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; FancyCrawler/2.3' );
		$this->assertSame( 'FancyCrawler', $g['name'], 'Browser tokens are skipped; the bot-like token wins.' );
		$this->assertSame( '', $g['url'], 'No +URL present, so url stays empty.' );
		$this->assertStringContainsString( 'FancyCrawler', urldecode( $g['lookup'] ) );
	}

	public function test_self_declared_extracts_a_bare_url_without_the_plus() {
		// Regression: ethicrawl writes its home page WITHOUT the "+" convention, so
		// the review panel said "declares no home page of its own" while the UA
		// shown right above it plainly contained one.
		$g = Catalog::self_declared( 'ethicrawl/0.1 (Ethical crawler respecting robots and AI directives; http://ethicrawl.ai/crawler; contact@ethicrawl.ai)' );
		$this->assertSame( 'http://ethicrawl.ai/crawler', $g['url'] );
		$this->assertSame( 'ethicrawl.ai', $g['host'] );
		$this->assertSame( 'ethicrawl', $g['name'] );
	}

	public function test_self_declared_only_surfaces_http_links() {
		$g = Catalog::self_declared( 'EvilBot/1.0 (+javascript:alert(1))' );
		$this->assertSame( '', $g['url'], 'Non-http(s) self-declared schemes are never surfaced.' );
	}

	public function test_self_declared_is_empty_for_no_ua() {
		$this->assertSame(
			array( 'name' => '', 'url' => '', 'host' => '', 'lookup' => '' ),
			Catalog::self_declared( '' )
		);
	}
}
