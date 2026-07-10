<?php
/**
 * Referrals::source_for() — the pure matcher that decides whether a visit came
 * from an AI assistant, from the referrer host and/or the utm_source tag.
 *
 * Load-bearing behaviour: high precision (what it matches really is that source),
 * www- and subdomain-tolerant, case-insensitive, and "" for anything unknown so
 * normal traffic is never miscounted.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Referrals;
use PHPUnit\Framework\TestCase;

final class ReferralSourceTest extends TestCase {

	public function test_matches_referrer_host() {
		$this->assertSame( 'ChatGPT', Referrals::source_for( 'https://chatgpt.com/', '' ) );
		$this->assertSame( 'Perplexity', Referrals::source_for( 'https://www.perplexity.ai/search?q=x', '' ) );
		$this->assertSame( 'Gemini', Referrals::source_for( 'https://gemini.google.com/app', '' ) );
		$this->assertSame( 'Claude', Referrals::source_for( 'https://claude.ai/chat/abc', '' ) );
	}

	public function test_matches_subdomain_of_a_known_source() {
		$this->assertSame( 'ChatGPT', Referrals::source_for( 'https://eu.chatgpt.com/', '' ) );
	}

	public function test_is_case_insensitive() {
		$this->assertSame( 'ChatGPT', Referrals::source_for( 'HTTPS://ChatGPT.COM/', '' ) );
	}

	public function test_matches_utm_source_when_referrer_is_missing() {
		// ChatGPT stamps utm_source=chatgpt.com, so it's caught even with no referrer.
		$this->assertSame( 'ChatGPT', Referrals::source_for( '', 'chatgpt.com' ) );
		$this->assertSame( 'Perplexity', Referrals::source_for( '', 'Perplexity.ai' ) );
	}

	public function test_unknown_or_empty_is_not_a_source() {
		$this->assertSame( '', Referrals::source_for( 'https://example.com/page', '' ) );
		$this->assertSame( '', Referrals::source_for( 'https://www.google.com/search?q=x', '' ), 'Plain Google search must not count as AI.' );
		$this->assertSame( '', Referrals::source_for( '', '' ) );
		$this->assertSame( '', Referrals::source_for( 'not-a-url', 'nope' ) );
	}

	public function test_lookalike_domain_does_not_false_match() {
		// "foryou.com" must not match the "you.com" source.
		$this->assertSame( '', Referrals::source_for( 'https://foryou.com/', '' ) );
	}

	/** The assistants added after the first cut of the map. */
	public function test_matches_the_newer_assistant_hosts() {
		$this->assertSame( 'Grok', Referrals::source_for( 'https://grok.com/chat', '' ) );
		$this->assertSame( 'DeepSeek', Referrals::source_for( 'https://chat.deepseek.com/', '' ), 'subdomain of deepseek.com' );
		$this->assertSame( 'Meta AI', Referrals::source_for( 'https://www.meta.ai/', '' ) );
		$this->assertSame( 'Mistral', Referrals::source_for( 'https://chat.mistral.ai/', '' ) );
		$this->assertSame( 'DuckDuckGo AI', Referrals::source_for( 'https://duck.ai/', '' ) );
		$this->assertSame( 'Claude', Referrals::source_for( 'https://claude.com/', '' ) );
	}

	/**
	 * An assistant that stamps a bare token rather than its hostname
	 * (`utm_source=perplexity`, not `perplexity.ai`) must still be caught — that is the
	 * whole reason the map carries dotless needles.
	 */
	public function test_matches_bare_utm_tokens() {
		$this->assertSame( 'Perplexity', Referrals::source_for( '', 'perplexity' ) );
		$this->assertSame( 'ChatGPT', Referrals::source_for( '', 'openai' ) );
		$this->assertSame( 'ChatGPT', Referrals::source_for( '', 'chatgpt' ) );
		$this->assertSame( 'Copilot', Referrals::source_for( '', 'Copilot' ) );
		$this->assertSame( 'Grok', Referrals::source_for( '', 'grok' ) );
	}

	/**
	 * A dotless needle exists ONLY to match utm_source. It must never match a referrer
	 * host, or "openai.com" — a marketing page, not an assistant — would count as a
	 * ChatGPT referral.
	 */
	public function test_bare_tokens_never_match_a_referrer_host() {
		$this->assertSame( '', Referrals::source_for( 'https://openai.com/index/hello', '' ) );
		$this->assertSame( '', Referrals::source_for( 'https://mistral.ai/news', '' ), 'only chat.mistral.ai is the assistant' );
	}

	/**
	 * Hosts that also serve ordinary search stay out: their referrer can't distinguish an
	 * AI answer from a plain search click, and a guess is worse than a known blind spot.
	 */
	public function test_search_engines_are_never_counted_as_ai() {
		$this->assertSame( '', Referrals::source_for( 'https://www.google.com/search?q=x', '' ), 'AI Overviews' );
		$this->assertSame( '', Referrals::source_for( 'https://www.bing.com/chat', '' ), 'Copilot in Bing search' );
		$this->assertSame( '', Referrals::source_for( 'https://duckduckgo.com/?q=x', '' ) );
		$this->assertSame( '', Referrals::source_for( 'https://kagi.com/assistant', '' ) );
		$this->assertSame( '', Referrals::source_for( 'https://x.com/i/grok', '' ), 'Grok on X is not distinguishable from any X link' );
	}
}
