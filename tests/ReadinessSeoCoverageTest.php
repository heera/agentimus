<?php
/**
 * The "Search SEO coverage" readiness row ({@see Readiness::check_seo_coverage()})
 * — the mode surface: solo passes (or warns naming exactly the disabled basics),
 * coexist credits the suite and never warns about our own toggles.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Readiness;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ReadinessSeoCoverageTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Reflection-call the private check (report() touches WP-heavy deps). */
	private function coverage_row() {
		$m = new \ReflectionMethod( Readiness::class, 'check_seo_coverage' );
		$m->setAccessible( true );
		return $m->invoke( new Readiness( new Settings() ) );
	}

	/** Solo with the defaults (all basics on): a clean pass, claim stated. */
	public function test_solo_all_on_passes() {
		$row = $this->coverage_row();
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'none needed', $row['detail'] );
	}

	/** Solo with basics off: warn that NAMES the off ones, CTA to the card. */
	public function test_solo_with_basics_off_warns_naming_them() {
		\update_option( Settings::OPTION, array( 'enable_social_cards' => false, 'enable_canonicals' => false ) );
		$row = $this->coverage_row();
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'social share cards', $row['detail'] );
		$this->assertStringContainsString( 'canonical links', $row['detail'] );
		$this->assertStringNotContainsString( 'SEO titles', $row['detail'] ); // Still on — not named.
		$this->assertSame( 'ar-sec-search-basics', $row['action']['anchor'] );
	}

	/** Coexist: a pass crediting the suite — our toggles are irrelevant there. */
	public function test_coexist_credits_the_suite_and_never_warns() {
		\update_option( Settings::OPTION, array( 'enable_social_cards' => false ) );
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		$row = $this->coverage_row();
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'handles search SEO', $row['detail'] );
	}
}
