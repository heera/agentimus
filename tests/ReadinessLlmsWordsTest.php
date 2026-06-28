<?php
/**
 * Readiness — the /llms.txt "substance" check (the 200-word minimum).
 *
 * An llms.txt that's near-empty gives an agent almost nothing to read or cite.
 * check_llms_words() measures the generated index against MIN_LLMS_WORDS and warns
 * (nudging real content, never filler) when it falls short. These lock: the pure
 * word counter (URLs must not inflate the count), the off-path (no double-warn),
 * and the thin-site warn path.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Readiness;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ReadinessLlmsWordsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Reflection-call the private readiness check (report() touches WP-heavy deps). */
	private function check(): array {
		$m = new \ReflectionMethod( Readiness::class, 'check_llms_words' );
		$m->setAccessible( true );
		return $m->invoke( new Readiness( new Settings() ) );
	}

	/** Reflection-call the private static word counter. */
	private function count_words( string $markdown ): int {
		$m = new \ReflectionMethod( Readiness::class, 'word_count' );
		$m->setAccessible( true );
		return (int) $m->invoke( null, $markdown );
	}

	/** Reflection-call the grading seam with a known count (skips llms.txt generation). */
	private function grade( int $words ): array {
		$m = new \ReflectionMethod( Readiness::class, 'llms_words_row' );
		$m->setAccessible( true );
		return $m->invoke( new Readiness( new Settings() ), $words );
	}

	/* -- The word counter ------------------------------------------------- */

	public function test_word_count_counts_prose() {
		$this->assertSame( 5, $this->count_words( 'one two three four five' ) );
	}

	public function test_word_count_ignores_link_urls() {
		// Two markdown links: only the labels ("OpenAI", "Anthropic") plus "and"
		// should count — the URLs must not inflate the total.
		$md = '[OpenAI](https://openai.com/blog/some/deep/path) and [Anthropic](https://www.anthropic.com/research)';
		$this->assertSame( 3, $this->count_words( $md ) );
	}

	/* -- The check -------------------------------------------------------- */

	public function test_passes_silently_when_index_is_off() {
		// With /llms.txt disabled, check_llms_txt() already warns — this one must
		// stand down to a clean pass rather than double-flag the same gap.
		update_option( Settings::OPTION, array( 'enable_llms_txt' => false ) );
		$row = $this->check();
		$this->assertSame( 'pass', $row['status'] );
		$this->assertSame( '', $row['fix'] );
	}

	public function test_warns_when_thin_and_points_at_identity() {
		// Below the floor → warn, with the fix routed to Identity (where real
		// content — a profile, expertise — is added, never filler).
		$row = $this->grade( 40 );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'ar-about', $row['action']['anchor'] );
		$this->assertStringContainsString( '200', $row['detail'] );
	}

	public function test_passes_when_at_or_above_the_floor() {
		$row = $this->grade( Readiness::MIN_LLMS_WORDS );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertSame( '', $row['fix'] );
	}
}
