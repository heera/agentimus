<?php
/**
 * The public scorecard — its pure seams.
 *
 * Locks the promises the public surfaces make: the tier bar is fixed at
 * TIER_MIN and the tier display NEVER leaks the number below it; the badge
 * prints exactly what the chosen display allows; the page escapes everything
 * dynamic and only advertises a social image when one can actually render;
 * the accent picker never chooses a colour white text can't sit on; and the
 * two settings enums snap to their offered choices.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Scorecard;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ScorecardTest extends TestCase {

	protected function setUp(): void {
		_af_reset_options();
	}

	private function snap( $score = 93 ) {
		return array(
			'score'     => $score,
			'band'      => 'Excellent',
			'ready'     => true,
			'blocked'   => false,
			'rungs'     => array(
				array( 'key' => 'findable', 'label' => 'Findable', 'score' => 100 ),
				array( 'key' => 'readable', 'label' => 'Readable', 'score' => 75 ),
				array( 'key' => 'trusted', 'label' => 'Trusted', 'score' => 40 ),
				array( 'key' => 'optimized', 'label' => 'Optimized', 'score' => null ),
			),
			'generated' => 1753056000,
		);
	}

	private function ctx( $og = '' ) {
		return array(
			'site'    => 'Example & Sons <b>',
			'home'    => 'https://example.test/',
			'url'     => 'https://example.test/ai-readiness/',
			'icon'    => '',
			'badge'   => 'https://example.test/ai-readiness/badge.svg',
			'og'      => $og,
			'updated' => 'July 21, 2026',
			'plugin'  => 'https://wordpress.org/plugins/agentimus/',
		);
	}

	/* -- the tier bar ------------------------------------------------------ */

	public function test_tier_is_earned_exactly_at_the_fixed_bar() {
		$this->assertFalse( Scorecard::tier_earned( Scorecard::TIER_MIN - 1 ) );
		$this->assertTrue( Scorecard::tier_earned( Scorecard::TIER_MIN ) );
		$this->assertTrue( Scorecard::tier_earned( 100 ) );
	}

	/* -- the badge --------------------------------------------------------- */

	public function test_badge_score_mode_prints_the_number() {
		$svg = Scorecard::badge_svg( $this->snap( 93 ), 'score', 'light', '#146b64' );
		$this->assertStringContainsString( '93/100', $svg );
		$this->assertStringContainsString( 'AI READINESS', $svg );
	}

	public function test_badge_tier_mode_earned_wears_the_mark() {
		$svg = Scorecard::badge_svg( $this->snap( 92 ), 'tier', 'light', '#146b64' );
		$this->assertStringContainsString( 'READY', $svg );
		$this->assertStringNotContainsString( '92/100', $svg );
	}

	public function test_badge_tier_mode_below_the_bar_never_leaks_the_number() {
		$svg = Scorecard::badge_svg( $this->snap( 87 ), 'tier', 'light', '#146b64' );
		$this->assertStringContainsString( 'IN PROGRESS', $svg );
		$this->assertStringNotContainsString( '87', $svg );
		// The not-earned segment must not wear the celebratory accent either.
		// fill= specifically: the brand mark's teal RING carries the same hex
		// as a stroke on every badge, and that is not a leak.
		$this->assertStringNotContainsString( 'fill="#146b64"', $svg );
	}

	public function test_badge_shape_option_rounds_the_corners() {
		$rect = Scorecard::badge_svg( $this->snap(), 'score', 'light', '#146b64' );
		$this->assertStringContainsString( 'rx="4"', $rect );

		$pill = Scorecard::badge_svg( $this->snap(), 'score', 'light', '#146b64', array( 'shape' => 'pill' ) );
		$this->assertStringContainsString( 'rx="14"', $pill );
		$this->assertStringNotContainsString( 'rx="4"', $pill );
	}

	public function test_badge_custom_colours_apply_except_while_in_progress() {
		$svg = Scorecard::badge_svg( $this->snap( 93 ), 'score', 'light', '#146b64', array( 'bg' => '#123456', 'fg' => '#fedcba' ) );
		$this->assertStringContainsString( '#123456', $svg );
		$this->assertStringContainsString( '#fedcba', $svg );
		// fill= specifically — the brand mark's ring strokes this hex always.
		$this->assertStringNotContainsString( 'fill="#146b64"', $svg );

		// The not-earned state must stay visually distinct — never the owner's
		// celebration colour.
		$prog = Scorecard::badge_svg( $this->snap( 87 ), 'tier', 'light', '#146b64', array( 'bg' => '#123456' ) );
		$this->assertStringContainsString( '#6b6558', $prog );
		$this->assertStringNotContainsString( '#123456', $prog );
	}

	public function test_badge_appearance_settings_sanitize() {
		$settings = new Settings();

		$clean = $settings->sanitize( array(
			'scorecard_badge_shape' => 'pill',
			'scorecard_badge_bg'    => '#ABCDEF',
			'scorecard_badge_fg'    => 'red',
		) );
		$this->assertSame( 'pill', $clean['scorecard_badge_shape'] );
		$this->assertSame( '#abcdef', $clean['scorecard_badge_bg'] );
		$this->assertSame( '', $clean['scorecard_badge_fg'] );

		$clean = $settings->sanitize( array( 'scorecard_badge_shape' => 'blob' ) );
		$this->assertSame( 'rectangle', $clean['scorecard_badge_shape'] );

		$clean = $settings->sanitize( array( 'scorecard_warn_color' => '#D09A2F' ) );
		$this->assertSame( '#d09a2f', $clean['scorecard_warn_color'] );
		$clean = $settings->sanitize( array( 'scorecard_warn_color' => 'amberish' ) );
		$this->assertSame( '', $clean['scorecard_warn_color'] );
	}

	public function test_badge_rejects_a_malformed_accent() {
		$svg = Scorecard::badge_svg( $this->snap(), 'score', 'light', 'javascript:alert(1)' );
		$this->assertStringNotContainsString( 'javascript:', $svg );
		$this->assertStringContainsString( Scorecard::ACCENT, $svg );
	}

	/* -- the page ---------------------------------------------------------- */

	public function test_page_escapes_the_site_name() {
		$html = Scorecard::page_html( $this->snap(), $this->ctx(), 'score', 'auto', '#146b64' );
		$this->assertStringNotContainsString( 'Example & Sons <b>', $html );
		$this->assertStringContainsString( 'Example &amp; Sons', $html );
	}

	public function test_page_advertises_the_social_image_only_when_it_can_render() {
		$without = Scorecard::page_html( $this->snap(), $this->ctx( '' ), 'score', 'auto', '#146b64' );
		$this->assertStringNotContainsString( 'og:image', $without );
		$this->assertStringNotContainsString( 'twitter:card', $without );

		$with = Scorecard::page_html( $this->snap(), $this->ctx( 'https://example.test/ai-readiness/card.png' ), 'score', 'auto', '#146b64' );
		$this->assertStringContainsString( 'og:image', $with );
		$this->assertStringContainsString( '/ai-readiness/card.png', $with );
	}

	public function test_page_tier_mode_below_the_bar_hides_every_number() {
		$html = Scorecard::page_html( $this->snap( 87 ), $this->ctx(), 'tier', 'auto', '#146b64' );
		$this->assertStringContainsString( 'Working toward', $html );
		$this->assertStringNotContainsString( '87', $html );
		// Rung percentages are numbers too — tier mode drops them all.
		$this->assertStringNotContainsString( '75%', $html );
	}

	public function test_page_can_hide_the_site_name_behind_the_domain() {
		$ctx              = $this->ctx();
		$ctx['host']      = 'example.test';
		$ctx['show_name'] = false;
		$html             = Scorecard::page_html( $this->snap(), $ctx, 'score', 'auto', '#146b64' );
		$this->assertStringNotContainsString( 'Example &amp; Sons', $html );
		$this->assertStringContainsString( 'example.test', $html );
	}

	public function test_page_score_mode_shows_score_and_rung_numbers() {
		$html = Scorecard::page_html( $this->snap( 93 ), $this->ctx(), 'score', 'auto', '#146b64' );
		$this->assertStringContainsString( '>93<', $html );
		$this->assertStringContainsString( '75%', $html );
		$this->assertStringContainsString( 'not measured', $html );
	}

	/* -- the settings enums -------------------------------------------------- */

	public function test_scorecard_enums_snap_to_offered_choices() {
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'scorecard_display' => 'tier', 'scorecard_style' => 'dark' ) );
		$this->assertSame( 'tier', $clean['scorecard_display'] );
		$this->assertSame( 'dark', $clean['scorecard_style'] );

		$clean = $settings->sanitize( array( 'scorecard_display' => 'braggy', 'scorecard_style' => 'neon' ) );
		$this->assertSame( 'score', $clean['scorecard_display'] );
		$this->assertSame( 'auto', $clean['scorecard_style'] );
	}

	public function test_share_scorecard_defaults_off() {
		$defaults = ( new Settings() )->defaults();
		$this->assertFalse( $defaults['share_scorecard'] );
	}

	public function test_path_is_owner_configurable() {
		update_option( Settings::OPTION, array( 'scorecard_path' => 'my-score' ) );
		$this->assertSame( '/my-score', ( new Scorecard( new Settings() ) )->path() );

		_af_reset_options();
		$this->assertSame( '/ai-readiness', ( new Scorecard( new Settings() ) )->path() );
	}

	public function test_path_sanitises_to_url_safe_or_falls_back() {
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'scorecard_path' => '/My Score!!/' ) );
		$this->assertSame( 'myscore', $clean['scorecard_path'] );

		$clean = $settings->sanitize( array( 'scorecard_path' => 'reports//ai' ) );
		$this->assertSame( 'reports/ai', $clean['scorecard_path'] );

		// A public URL must never be empty: garbage collapses to the default.
		$clean = $settings->sanitize( array( 'scorecard_path' => '///' ) );
		$this->assertSame( 'ai-readiness', $clean['scorecard_path'] );
	}
}
