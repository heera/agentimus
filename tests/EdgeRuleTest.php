<?php
/**
 * EdgeRule — the checks a blocked name must pass before it may reach Cloudflare,
 * and the rule text it would become. A wrong rule there turns a visitor away on
 * every page, so each way of being wrong gets its own refusal here.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Cloudflare\EdgeRule;
use PHPUnit\Framework\TestCase;

final class EdgeRuleTest extends TestCase {

	const SQLSPIDER = 'Mozilla/5.0 (compatible; SQLSpider/1.5)';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_af_options'] = array();
		$GLOBALS['_af_filters'] = array();
	}

	/**
	 * @param string $ua       The logged user agent.
	 * @param int    $verified Requests from it that proved genuine.
	 * @return array One evidence row.
	 */
	private function seen( $ua, $verified = 0 ) {
		return array( 'ua' => $ua, 'hits' => 3, 'verified' => $verified );
	}

	/**
	 * @param array $out A check() result.
	 * @return string[] The refusal codes.
	 */
	private function codes( array $out ) {
		return array_column( $out['refusals'], 'code' );
	}

	public function test_a_bot_that_names_itself_passes() {
		$out = EdgeRule::check( 'sqlspider', array( $this->seen( self::SQLSPIDER ) ) );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( array(), $out['refusals'] );
		$this->assertSame( EdgeRule::FORM_VERSIONED, $out['form'] );
	}

	public function test_a_stored_name_in_mixed_case_is_checked_lowercase() {
		$out = EdgeRule::check( 'SQLSpider', array( $this->seen( self::SQLSPIDER ) ) );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'sqlspider', $out['token'] );
	}

	public function test_a_very_short_name_is_refused() {
		$out = EdgeRule::check( 'abc', array( $this->seen( 'abc/2.2' ) ) );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'too_short', $this->codes( $out ) );
	}

	public function test_a_four_letter_name_that_is_its_own_product_passes() {
		// On heera.it's real list, and still visiting. The rule matches at a word start.
		$this->assertTrue( EdgeRule::check( 'buck', array( $this->seen( 'Buck/2.6.0; (+https://example.test/about.html)' ) ) )['ok'] );
	}

	public function test_a_client_the_rule_would_not_catch_is_no_reason_to_refuse() {
		// Both seen on heera.it. Neither is caught by a word-start match on the name.
		$this->assertTrue( EdgeRule::check( 'sqlspider', array(
			$this->seen( self::SQLSPIDER ),
			$this->seen( 'Mozilla/5.0 (compatible; NotSQLSpider/1.5)' ),
		) )['ok'] );
		$this->assertTrue( EdgeRule::check( 'semrushbot', array(
			$this->seen( 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)' ),
			$this->seen( 'Mozilla/5.0 (compatible; SemrushBot-BA; +http://www.semrush.com/bot.html)' ),
		) )['ok'] );
	}

	public function test_only_lookalikes_seen_counts_as_never_seen() {
		$out = EdgeRule::check( 'sqlspider', array( $this->seen( 'Mozilla/5.0 (compatible; NotSQLSpider/1.5)' ) ) );

		$this->assertContains( 'never_seen', $this->codes( $out ) );
		$this->assertTrue( $out['unseen'] );
	}

	public function test_a_name_never_seen_can_pass_only_when_the_owner_insists() {
		$this->assertFalse( EdgeRule::check( 'sqlspider', array() )['ok'] );

		$out = EdgeRule::check( 'sqlspider', array(), array(), true );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( EdgeRule::FORM_BOTH, $out['form'] );
	}

	public function test_insisting_never_overrides_a_hard_refusal() {
		$this->assertFalse( EdgeRule::check( 'wordpress', array(), array(), true )['ok'] );
		$this->assertFalse( EdgeRule::check( 'mcp-scraper', array(), array( 'mcp-scraper' ), true )['ok'] );
		$this->assertFalse( EdgeRule::check( 'abc', array(), array(), true )['ok'] );
		$this->assertFalse( EdgeRule::check( 'sql*spider', array(), array(), true )['ok'] );
	}

	public function test_a_client_writing_its_name_two_ways_gets_both_forms() {
		$out = EdgeRule::check( 'petalbot', array(
			$this->seen( 'Mozilla/5.0 (compatible;PetalBot;+https://webmaster.petalsearch.com/site/petalbot)' ),
			$this->seen( 'Mozilla/5.0 (compatible; PetalBot/1.0)' ),
		) );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( EdgeRule::FORM_BOTH, $out['form'] );
	}

	public function test_the_compatible_form_stops_at_the_end_of_the_name() {
		$expression = EdgeRule::expression( array( 'petalbot' => EdgeRule::FORM_COMPATIBLE ) );

		foreach ( array( '"compatible; petalbot;"', '"compatible; petalbot)"', '"compatible;petalbot;"', '"compatible;petalbot)"' ) as $clause ) {
			$this->assertStringContainsString( $clause, $expression );
		}
		$this->assertStringNotContainsString( '"compatible; petalbot"', $expression );
	}

	public function test_capacity_is_named_and_shrinks_with_use() {
		$empty = EdgeRule::capacity( array() );
		$this->assertGreaterThan( 20, $empty );
		$this->assertLessThan( 30, $empty );
		$this->assertSame( $empty, EdgeRule::capacity( array( 'sqlspider' => EdgeRule::FORM_VERSIONED ) ) );
	}

	public function test_a_wildcard_or_pattern_entry_is_refused() {
		foreach ( array( 'sql*spider', '/sqlspider/', 'sql spider', 'sql"spider' ) as $entry ) {
			$out = EdgeRule::check( $entry, array( $this->seen( self::SQLSPIDER ) ) );
			$this->assertContains( 'not_a_name', $this->codes( $out ), $entry );
		}
	}

	public function test_a_name_never_seen_is_refused() {
		$out = EdgeRule::check( 'sqlspider', array() );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'never_seen', $this->codes( $out ) );
	}

	public function test_a_name_with_a_proved_genuine_request_is_refused() {
		$out = EdgeRule::check( 'sqlspider', array( $this->seen( self::SQLSPIDER, 1 ) ) );

		$this->assertContains( 'proved_genuine', $this->codes( $out ) );
	}

	public function test_a_different_product_containing_the_name_is_refused() {
		$out = EdgeRule::check( 'mid-docs', array(
			$this->seen( 'Mozilla/5.0 (compatible; mid-docs/1.0)' ),
			$this->seen( 'othercrawler/1.0 mid-docs/1.0' ),
		) );

		$this->assertContains( 'other_clients', $this->codes( $out ) );
		$this->assertContains( 'othercrawler/1.0 mid-docs/1.0', $out['otherClients'] );
	}

	public function test_a_real_browser_carrying_the_name_is_refused() {
		$browser = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 sqlspider/1.5';
		$out     = EdgeRule::check( 'sqlspider', array( $this->seen( self::SQLSPIDER ), $this->seen( $browser ) ) );

		$this->assertContains( 'other_clients', $this->codes( $out ) );
	}

	public function test_a_search_engine_carrying_the_name_is_refused() {
		$engine = 'Mozilla/5.0 (compatible; Googlebot/2.1; sqlspider/1.5)';
		$out    = EdgeRule::check( 'sqlspider', array( $this->seen( $engine ) ) );

		$this->assertContains( 'other_clients', $this->codes( $out ) );
	}

	public function test_a_name_touching_an_allowed_client_is_refused_both_ways() {
		$GLOBALS['_af_options']['agentimus_settings'] = array( 'allowed_agents' => array( 'mcp-scraper' ) );

		// Inside the allowed name: the rule would also catch the allowed client.
		$this->assertContains(
			'touches_allowed',
			$this->codes( EdgeRule::check( 'scraper', array( $this->seen( 'scraper/1.0' ) ), array( 'mcp-scraper' ) ) )
		);
		// The allowed name inside it.
		$this->assertContains(
			'touches_allowed',
			$this->codes( EdgeRule::check( 'mcp-scraper-pro', array( $this->seen( 'mcp-scraper-pro/1.0' ) ), array( 'mcp-scraper' ) ) )
		);
	}

	public function test_services_a_site_depends_on_are_refused() {
		foreach ( array( 'wordpress', 'agentimus', 'cloudflare', 'letsencrypt', 'stripe', 'paypal', 'googleother', 'bingpreview', 'duckduckgo-favicons' ) as $name ) {
			$out = EdgeRule::check( $name, array( $this->seen( $name . '/1.0' ) ) );
			$this->assertContains( 'depends_on', $this->codes( $out ), $name );
		}
	}

	public function test_a_name_without_a_version_uses_the_compatible_form() {
		$out = EdgeRule::check( 'somecrawler', array( $this->seen( 'Mozilla/5.0 (compatible; SomeCrawler; +https://x.example)' ) ) );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( EdgeRule::FORM_COMPATIBLE, $out['form'] );
	}

	public function test_the_rule_matches_the_name_at_a_word_start_and_keeps_its_exceptions() {
		$this->assertSame(
			'(lower(http.user_agent) contains " sqlspider/" or lower(http.user_agent) contains "(sqlspider/" or starts_with(lower(http.user_agent), "sqlspider/"))'
			. ' and not cf.client.bot'
			. ' and http.request.uri.path ne "/robots.txt"'
			. ' and not starts_with(http.request.uri.path, "/.well-known/acme-challenge/")',
			EdgeRule::expression( array( 'sqlspider' => EdgeRule::FORM_VERSIONED ) )
		);
	}

	public function test_several_names_share_one_rule() {
		$expression = EdgeRule::expression( array(
			'sqlspider'   => EdgeRule::FORM_VERSIONED,
			'somecrawler' => EdgeRule::FORM_COMPATIBLE,
		) );

		$this->assertStringContainsString( 'contains " sqlspider/"', $expression );
		$this->assertStringContainsString( 'contains "compatible; somecrawler;"', $expression );
		$this->assertSame( 1, substr_count( $expression, 'not cf.client.bot' ) );
	}

	public function test_no_names_means_no_rule() {
		$this->assertSame( '', EdgeRule::expression( array() ) );
	}

	public function test_a_rule_too_long_for_cloudflare_is_named() {
		$many = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$many[ 'crawlername' . $i ] = EdgeRule::FORM_VERSIONED;
		}

		$this->assertFalse( EdgeRule::fits( $many ) );
		$this->assertTrue( EdgeRule::fits( array( 'sqlspider' => EdgeRule::FORM_VERSIONED ) ) );
	}
}
