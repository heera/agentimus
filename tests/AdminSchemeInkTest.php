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
use Agentimus\SchemeInk;
use Agentimus\Settings;
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

	public function test_the_curated_map_is_keyed_by_colour_not_by_slug() {
		// ⚠️ THE POINT OF THIS TEST. The map used to be keyed by slug, and
		// WordPress retunes schemes: 7.1 moved every core bar, so `sunrise`
		// kept handing back a dark tuned to the OLD #b43c38 on installs whose
		// bar is #6f2724. Keyed by colour, a stale entry simply stops matching
		// and the derivation takes over — degraded, not wrong.
		foreach ( Admin::SCHEME_INKS as $base => $ink ) {
			$this->assertMatchesRegularExpression( '/\A#[0-9a-f]{6}\z/', $base, 'key ' . $base );
			$this->assertMatchesRegularExpression( '/\A#[0-9a-f]{6}\z/', $ink, 'ink for ' . $base );
		}
		// No slug may appear as a key.
		foreach ( array( 'light', 'modern', 'blue', 'coffee', 'ectoplasm', 'midnight', 'ocean', 'sunrise', 'fresh' ) as $slug ) {
			$this->assertArrayNotHasKey( $slug, Admin::SCHEME_INKS, $slug );
		}
	}

	public function test_both_generations_of_every_core_bar_are_covered() {
		// The colour WordPress registered before 7.1, and the one it registers
		// now. Both must resolve, or one generation of installs gets a derived
		// ink where a measured one exists.
		// ⚠️ colors[0] — the stable key. Verified against WordPress's colors.scss:
		// the MENU is colors[1] for six schemes, colors[2] for classic blue and
		// colors[0] for light/modern, so no slot always holds it. colors[0] is
		// the slot that is stable and unique per scheme-and-generation.
		$pairs = array(
			'blue'      => array( '#096484', '#183751' ),
			'coffee'    => array( '#46403c', '#382e27' ),
			'ectoplasm' => array( '#413256', '#392751' ),
			'midnight'  => array( '#25282b', '#232a2e' ),
			'ocean'     => array( '#627c83', '#2b3f44' ),
			'sunrise'   => array( '#b43c38', '#6f2724' ),
		);
		foreach ( $pairs as $scheme => $bases ) {
			foreach ( $bases as $base ) {
				$this->assertArrayHasKey( $base, Admin::SCHEME_INKS, $scheme . ' ' . $base );
			}
		}
	}

	public function test_scheme_scope_targets_only_the_dark_surfaces() {
		// Heera's call: the theme flavour goes on the card, the buttons, the
		// editable chips and the chosen half of the source picker — nothing else.
		// The scope is the contract; accents, toggles and links keep the designed
		// teal.
		// ⭐ Membership is a PROPERTY, not a taste: every one of these paints
		// `background: var(--ar-ink); color: var(--ar-paper)`. The source picker
		// was missing and so its selected half stayed the app's near-black on a
		// blue install — his catch, 2026-08-20. Anything else written that way
		// belongs here too.
		$this->assertSame(
			'.ar-btn,.ar-tags__chip,.ar-rail-card.ar-rail-card--readiness,.ar-srcpick__btn.is-on',
			Admin::SCHEME_SCOPE
		);
	}

	public function test_the_ink_follows_the_colour_the_install_actually_paints() {
		// Same slug, two WordPresses, two answers — which is the whole fix.
		// Keys are colors[0] for each generation.
		$this->assertSame( '#46403c', Admin::card_ink_for( 'coffee', '#46403c' ) );
		$this->assertSame( '#4e4036', Admin::card_ink_for( 'coffee', '#382e27' ) );
		// And the slug carries no weight of its own: an unrecognised colour goes
		// through the RULE, even under a core slug.
		$this->assertSame(
			SchemeInk::derive_ink( '#ffffff', '' ),
			Admin::card_ink_for( 'coffee', '#ffffff' )
		);
	}

	public function test_the_rule_splits_on_how_bright_the_menu_is() {
		// HIS RULE, 2026-08-20. A bright menu cannot be sat under by anything
		// that carries light text, so the ink echoes the dark anchor; a menu
		// that is already dark is sat just under, because landing ON the anchor
		// goes deeper than the deepest thing on screen and reads as a hole.
		// Bright menu (classic blue) -> the menu ITSELF. His call: buttons the
		// same blue as the sidebar, with the text flipped dark.
		$this->assertSame( '#52accc', SchemeInk::derive_ink( '#52accc', '#096484' ) );
		// Dark menu (WP 7.1 blue and sunrise) -> just under the menu.
		$this->assertSame( '#1f4768', SchemeInk::derive_ink( '#245278', '#183751' ) );
		$this->assertSame( '#7f2d29', SchemeInk::derive_ink( '#8a312d', '#6f2724' ) );
		// ⛔ The anchor itself is what he rejected on sunrise — the rule must
		// never return it for a dark menu.
		$this->assertNotSame( '#6f2724', SchemeInk::derive_ink( '#8a312d', '#6f2724' ) );
	}

	public function test_no_scheme_dresses_its_surfaces_in_a_bright_menu() {
		// ⭐ HIS CALL, 2026-08-20: one colour per scheme, on every WordPress that
		// scheme has shipped on. The bright-menu treatment — buttons and rug in
		// the menu's own light colour — had three users and now has none.
		// ⛔ NO SCHEME IS A BRIGHT-SURFACE SCHEME ANY MORE — his call, 2026-08-20,
		// one colour per scheme across every generation. All three that used this
		// path (blue, ocean, sunrise) now wear their own 7.1 bar instead.
		$this->assertSame( '', SchemeInk::card_surface_for( '#096484' ) );
		$this->assertSame( '', SchemeInk::card_surface_for( '#627c83' ) );
		$this->assertSame( '', SchemeInk::card_surface_for( '#b43c38' ) );
		$this->assertSame( array(), SchemeInk::SCHEME_SURFACES );
		// ⛔ And the DARK ink survives for these schemes, because the ghost
		// button paints it on the PAGE, not on the ink. Losing it was the bug
		// that dropped the rug to the app's neutral #1b1913.
		// Each collapsed scheme wears its 7.1 bar on both generations.
		$this->assertSame( '#245278', Admin::card_ink_for( 'blue', '#096484' ) );
		$this->assertSame( '#39535a', Admin::card_ink_for( 'ocean', '#627c83' ) );
		$this->assertSame( '#8a312d', Admin::card_ink_for( 'sunrise', '#b43c38' ) );
		// A dark-menu scheme has no bright surface at all.
		foreach ( array( '#183751', '#6f2724', '#232a2e', '#46403c' ) as $base ) {
			$this->assertSame( '', SchemeInk::card_surface_for( $base ), $base );
		}
	}

	public function test_a_verdict_mark_never_takes_a_colour_that_reads_as_alarm() {
		// ⛔⛔ THE SAFETY TEST. Sunrise's ink is hue 3 — FOUR degrees from
		// --ar-bad. Re-keyed there, every PASSING check would paint red. This is
		// not a style preference; it is the difference between "fine" and
		// "broken" on the page whose whole job is telling them apart.
		foreach ( array( '#7f2d29', '#7e2a27' ) as $sunrise ) {
			$this->assertFalse( SchemeInk::verdict_can_take( $sunrise ), $sunrise );
		}
		// Coffee sits between warn and bad.
		$this->assertFalse( SchemeInk::verdict_can_take( '#4e4036' ) );
		// Near-neutrals say nothing: a grey mark is the "off" state, which means
		// "we did not look", not "this is fine".
		// ⚠️ #3a4a4f LEFT THIS LIST on 2026-08-20 when the gate moved to 14%: at
		// 15.3% saturation it is a dark slate-teal, more coloured than #738e96,
		// which he accepted at 14.3%. Keeping it here would have asserted that one
		// of the two is grey and the other is not. It is never asked in practice —
		// it is the brighter ocean's GHOST-button ink, and that scheme's accent is
		// its surface — but the property has to hold either way.
		foreach ( array( '#e5e5e5', '#1e1e1e', '#2d353a' ) as $flat ) {
			$this->assertFalse( SchemeInk::verdict_can_take( $flat ), $flat );
		}
		// And the ones that genuinely can.
		foreach ( array( '#1f4768', '#07485f', '#432e5f', '#413256', '#334b51' ) as $ok ) {
			$this->assertTrue( SchemeInk::verdict_can_take( $ok ), $ok );
		}
		// ⭐ THE BRIGHT SURFACES, asked about since 2026-08-20 — on those schemes
		// the accent takes the SURFACE, so the surface is what a verdict mark
		// would have to wear. Only the blue clears the gates. This is the test
		// that makes raising the lightness ceiling to 62% safe: it is the hue and
		// saturation gates that keep the other two green, not the ceiling.
		$this->assertTrue( SchemeInk::verdict_can_take( '#52accc' ) );
		$this->assertTrue( SchemeInk::verdict_can_take( '#738e96' ) );
		$this->assertFalse( SchemeInk::verdict_can_take( '#cf4944' ) );
		// ⛔ AND THE MARGIN UNDER THE SATURATION GATE, which is 1.7 points wide.
		// #738e96 is 14.3% saturated and passes on his call; WP 7.1's midnight is
		// 12.6% and must not, or a "pass" mark becomes a grey one. This pair is
		// the whole reason the gate is 14% and not a rounder number.
		$this->assertFalse( SchemeInk::verdict_can_take( '#2d353a' ) );
	}

	public function test_a_derived_ink_always_carries_its_text() {
		// The depth is chosen BY the text, so this is the property, not a
		// coincidence of the numbers above.
		foreach ( array( '#4796b3', '#245278', '#cf4944', '#8a312d', '#738e96', '#ffffff' ) as $menu ) {
			$ink = SchemeInk::derive_ink( $menu, '' );
			$this->assertMatchesRegularExpression( '/\A#[0-9a-f]{6}\z/', $ink, $menu );
		}
	}

	public function test_default_scheme_emits_nothing() {
		$this->assertSame( '', Admin::card_ink_for( 'fresh', '#222222' ) );
		$this->assertSame( '', Admin::card_ink_for( '', '#222222' ) );
	}

	public function test_light_and_modern_are_keyed_by_their_own_menu_too() {
		// ⭐ These two paint their menu with colors[0], not colors[1] — which is
		// exactly the fallback active_surface() already makes. Both generations
		// must still land on his picks.
		$this->assertSame( '#ebeaea', Admin::card_ink_for( 'light', '#e5e5e5' ) );
		$this->assertSame( '#1e1e1e', Admin::card_ink_for( 'modern', '#1e1e1e' ) );
	}

	public function test_third_party_scheme_falls_back_to_the_rule() {
		// ⭐ A colour we have never measured goes through the RULE, not the map.
		// With no registered colors[1] there is no separate menu to read, so the
		// dark branch has nothing to blend toward and the colour stands as its
		// own ink — the honest answer for a scheme nobody has looked at.
		$this->assertSame(
			SchemeInk::derive_ink( '#3d2b56', '#3d2b56' ),
			Admin::card_ink_for( 'some-custom-scheme', '#3d2b56' )
		);
		// And a BRIGHT unmeasured colour takes itself, per his rule.
		$this->assertSame( '#8fb4cc', SchemeInk::derive_ink( '#8fb4cc', '#3d2b56' ) );
		// ⚠️ An unmeasured scheme gets no pressable surface, so its buttons keep
		// the ink. That is the known edge of this design — a third-party scheme
		// with a bright menu is dressed dark — pinned here as a decision rather
		// than a surprise.
		$this->assertSame( '', SchemeInk::card_surface_for( '#3d2b56' ) );
	}

	/**
	 * The body stamp reports the scheme's REGISTERED surface, so a dark dialect
	 * keys off the colour WordPress is actually serving. Midnight is the whole
	 * reason: it was #363b3f for years and is #333c42 from WP 7.1 — same slug,
	 * two different nights, and the owner runs one install of each.
	 */
	public function test_active_surface_reports_the_registered_colour() {
		$this->with_scheme( 'midnight', array( '#25282b', '#363b3f', '#69a8bb', '#e14d43' ) );
		$this->assertSame( '#363b3f', SchemeInk::active_surface(), 'The older Midnight.' );

		$this->with_scheme( 'midnight', array( '#232a2e', '#333c42', '#69a8bb', '#cf4339' ) );
		$this->assertSame( '#333c42', SchemeInk::active_surface(), 'WP 7.1 retuned it.' );
	}

	public function test_active_surface_falls_back_to_the_bar_colour() {
		$this->with_scheme( 'oneoff', array( '#445566' ) );
		$this->assertSame( '#445566', SchemeInk::active_surface() );
	}

	public function test_active_surface_is_empty_when_there_is_nothing_usable() {
		$this->with_scheme( 'broken', array( 'not-a-colour' ) );
		$this->assertSame( '', SchemeInk::active_surface() );

		$this->with_scheme( 'missing', array() );
		$this->assertSame( '', SchemeInk::active_surface() );
	}

	/**
	 * The class is the colour, so a dialect can name the night it was mixed
	 * for; an unusable scheme adds nothing rather than a broken selector.
	 */
	public function test_body_class_carries_the_colour() {
		$admin = new Admin( new Settings() );

		$this->with_scheme( 'midnight', array( '#25282b', '#363b3f' ) );
		$this->assertSame( 'wp-admin agentimus-scheme-363b3f', $admin->scheme_body_class( 'wp-admin' ) );

		$this->with_scheme( 'midnight', array( '#232a2e', '#333c42' ) );
		$this->assertSame( 'wp-admin agentimus-scheme-333c42', $admin->scheme_body_class( 'wp-admin' ) );

		$this->with_scheme( 'broken', array( 'nope' ) );
		$this->assertSame( 'wp-admin', $admin->scheme_body_class( 'wp-admin' ) );
	}

	/** Register a scheme as core does and make it the current user's choice. */
	private function with_scheme( $slug, array $colors ) {
		global $_wp_admin_css_colors;
		$_wp_admin_css_colors                       = array( $slug => (object) array( 'colors' => $colors ) );
		$GLOBALS['_af_user_options']['admin_color'] = $slug;
	}
}
