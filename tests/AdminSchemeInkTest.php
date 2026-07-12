<?php
/**
 * Admin::scheme_ink() — the colour math that adapts the rail score card's dark
 * surface to the user's admin colour scheme. Pure (hex in, hex out), so it's
 * exercised here in isolation: bright scheme bases must darken to card depth
 * while keeping their hue; already-dark bases pass through; junk yields ''.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Admin;
use PHPUnit\Framework\TestCase;

final class AdminSchemeInkTest extends TestCase {

	/** Perceived luma of a "#rrggbb" string, same weights the implementation uses. */
	private function luma( $hex ) {
		$rgb = array_map( 'hexdec', str_split( ltrim( $hex, '#' ), 2 ) );
		return ( 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2] ) / 255;
	}

	public function test_bright_scheme_base_is_darkened_to_card_depth() {
		// Core "Blue" scheme base — far too bright for 50%-alpha cream text.
		$ink = Admin::scheme_ink( '#096484' );
		$this->assertNotSame( '#096484', $ink );
		$this->assertLessThanOrEqual( Admin::CARD_LUMA + 0.005, $this->luma( $ink ), 'Surface must land at (or just under) the card luma ceiling.' );
	}

	public function test_darkening_keeps_the_hue() {
		// Multiplicative scaling: the channel ORDER (b > g > r for Blue) survives,
		// so the card still reads as the scheme's colour, only deeper.
		$rgb = array_map( 'hexdec', str_split( ltrim( Admin::scheme_ink( '#096484' ), '#' ), 2 ) );
		$this->assertGreaterThan( $rgb[1], $rgb[2], 'Blue channel stays dominant.' );
		$this->assertGreaterThan( $rgb[0], $rgb[1], 'Green stays above red.' );
	}

	public function test_near_neutral_base_gains_a_visible_tint() {
		// Core "Coffee" base is a warm near-grey: without the saturation floor it
		// darkens into something indistinguishable from the default ink — a match
		// nobody can see (Heera caught this on the Coffee scheme). The floor keeps
		// the warm hue but makes it readable: red clearly above green above blue.
		$rgb = array_map( 'hexdec', str_split( ltrim( Admin::scheme_ink( '#46403c' ), '#' ), 2 ) );
		$this->assertGreaterThan( $rgb[1] + 5, $rgb[0], 'Red channel clearly dominant — the tint is visible.' );
		$this->assertGreaterThan( $rgb[2], $rgb[1], 'Warm ramp: green above blue.' );
	}

	public function test_true_grey_stays_neutral() {
		// "Light" scheme's #e5e5e5 has no hue — boosting saturation would invent
		// a colour the scheme never had, so a grey darkens to a grey.
		$rgb = array_map( 'hexdec', str_split( ltrim( Admin::scheme_ink( '#e5e5e5' ), '#' ), 2 ) );
		$this->assertSame( $rgb[0], $rgb[1] );
		$this->assertSame( $rgb[1], $rgb[2] );
	}

	public function test_already_dark_grey_passes_through_unchanged() {
		// Core "Modern" base sits under both clamps already — no double-darkening.
		$this->assertSame( '#1e1e1e', Admin::scheme_ink( '#1e1e1e' ) );
	}

	public function test_shorthand_hex_is_accepted() {
		$this->assertSame( '#111111', Admin::scheme_ink( '#111' ) );
	}

	public function test_unparseable_input_yields_empty_string() {
		$this->assertSame( '', Admin::scheme_ink( 'transparent' ) );
		$this->assertSame( '', Admin::scheme_ink( '#12345' ) );
		$this->assertSame( '', Admin::scheme_ink( '' ) );
	}

	/* -- card_ink_for(): curated map first, math only as fallback --------- */

	public function test_every_non_default_core_scheme_is_in_the_curated_map() {
		// The core schemes are a frozen set; each gets an exact, hand-picked
		// ink rather than a derived one. "fresh" is deliberately absent.
		$core = array( 'light', 'modern', 'blue', 'coffee', 'ectoplasm', 'midnight', 'ocean', 'sunrise' );
		foreach ( $core as $scheme ) {
			$this->assertArrayHasKey( $scheme, Admin::SCHEME_INKS, $scheme );
			$this->assertMatchesRegularExpression( '/\A#[0-9a-f]{6}\z/', Admin::SCHEME_INKS[ $scheme ], $scheme );
		}
		$this->assertArrayNotHasKey( 'fresh', Admin::SCHEME_INKS );
	}

	public function test_scheme_scope_targets_only_the_dark_surfaces() {
		// Heera's call: the theme flavour goes on the card, buttons and editable
		// chips — nothing else. The scope is the contract; accents/toggles/links
		// keep the designed teal.
		$this->assertSame(
			'.ar-btn,.ar-tags__chip,.ar-rail-card.ar-rail-card--readiness',
			Admin::SCHEME_SCOPE
		);
	}

	public function test_core_scheme_uses_the_map_not_the_math() {
		// The map wins even when the registered base would derive differently.
		$this->assertSame( '#46403c', Admin::card_ink_for( 'coffee', '#ffffff' ) );
	}

	public function test_default_scheme_emits_nothing() {
		$this->assertSame( '', Admin::card_ink_for( 'fresh', '#222222' ) );
		$this->assertSame( '', Admin::card_ink_for( '', '#222222' ) );
	}

	public function test_third_party_scheme_falls_back_to_the_derivation() {
		$this->assertSame(
			Admin::scheme_ink( '#096484' ),
			Admin::card_ink_for( 'some-custom-scheme', '#096484' )
		);
	}
}
