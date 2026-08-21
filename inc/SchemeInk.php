<?php
/**
 * Colour maths that adapts the admin's dark surfaces to the user's WordPress
 * admin colour scheme.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * The score card, buttons and editable chips take a "card ink" — a dark surface
 * tuned to the active admin colour scheme so the plugin sits in the owner's
 * chosen palette rather than fighting it. Core schemes get an exact, hand-picked
 * depth; anything else is derived from the scheme's base colour by pushing its
 * hue to card depth. Extracted from {@see Admin}, which keeps `card_ink_for()`,
 * `scheme_ink()`, and the CARD_LUMA / SCHEME_INKS constants as delegates/aliases.
 */
final class SchemeInk {

	/** Perceived-luma ceiling for a card surface — a guard so no derived tint reads
	 *  too bright for the muted text on it. */
	const CARD_LUMA = 0.14;

	/** HSL lightness above which a scheme's MENU counts as bright, and the ink
	 *  has to echo the scheme's dark anchor instead of sitting under the menu.
	 *  Between WP 7.1's sunrise menu (0.36, dark) and classic blue's (0.49,
	 *  bright) — the two cases his eye separated. */
	const BRIGHT_MENU_L = 0.42;

	/**
	 * HSL saturation below which a colour is a GREY — it can be read, but it
	 * cannot MEAN anything.
	 *
	 * ⭐ ONE QUANTITY, ONE OWNER — his law. This number was tuned once, on the
	 * brighter ocean ({@see verdict_can_take()}), by listing every ink and
	 * surface the app can emit and reading where the line had to fall: ocean's
	 * #738e96 at 14.3% is a colour, WP 7.1's midnight at 12.6% and coffee's
	 * older bar at 7.7% are greys. Two callers now ask the same question — may
	 * this colour stand in for the green, and may it stand in for the accent —
	 * and both read it from here.
	 */
	const COLOUR_FLOOR = 0.14;

	/**
	 * Is there enough colour in this to carry a MEANING, rather than only enough
	 * contrast to be seen?
	 *
	 * ⛔ THE GUARD THAT WAS MISSING, and his catch 2026-08-21 on the DEFAULT
	 * scheme: `modern` registers #1e1e1e, a pure near-black, so the accent
	 * re-key in {@see Admin::scheme_css()} emitted `--ar-accent:#1e1e1e` and
	 * every accent-driven thing in the app went black — the traffic chart's
	 * bars, the links, the tab underlines, the numbers, the readiness ring.
	 * ⚠️ The existing gate asked the wrong question. `carries_text()` asks
	 * whether the colour can be READ, and a near-black on cream reads better
	 * than anything: 18:1. It passed exactly because it has no colour in it.
	 * ⭐ So the accent needs BOTH gates — readable AND coloured — and this is
	 * the second one. It is the same judgement `verdict_can_take()` already
	 * makes about a grey verdict mark ("we did not look", not "this is fine"),
	 * applied one level up: a grey accent is not the scheme's flavour, it is the
	 * absence of one, and the honest answer there is to emit nothing and leave
	 * the designed teal standing.
	 *
	 * @param string $hex Colour.
	 * @return bool
	 */
	public static function carries_colour( $hex ) {
		$hex = ltrim( strtolower( trim( (string) $hex ) ), '#' );
		if ( ! preg_match( '/\A[0-9a-f]{6}\z/', $hex ) ) {
			return false;
		}
		$rgb = array_map( 'hexdec', str_split( $hex, 2 ) );
		$max = max( $rgb ) / 255;
		$min = min( $rgb ) / 255;
		$d   = $max - $min;
		if ( $d <= 0 ) {
			return false;
		}
		$l = ( $max + $min ) / 2;

		return ( $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min ) ) >= self::COLOUR_FLOOR;
	}

	/**
	 * The scheme-flavoured surface for the buttons, chips and readiness rug,
	 * KEYED BY colors[0] — the first swatch WordPress registers.
	 *
	 * ⚠️ colors[0] and NOT "the menu colour", because there is no stable slot
	 * for the menu. Verified against WordPress's own colors.scss: $base-color is
	 * colors[1] for six core schemes, colors[2] for classic blue, and colors[0]
	 * for light and modern. colors[0] is the one slot that is stable AND unique
	 * per scheme-and-generation — 14 keys, no collisions.
	 *
	 * ⚠️ And not by SLUG, which is how this was written until 2026-08-20.
	 * WordPress RETUNES schemes: 7.1 moved every core bar, so `sunrise` kept
	 * handing back a dark mixed for the OLD #b43c38 on installs whose bar is
	 * #6f2724. Keyed by colour, a stale entry stops matching and the rule takes
	 * over — degraded, not wrong.
	 *
	 * ⭐ HIS RULE, 2026-08-20, from looking at both generations side by side:
	 *
	 *   BRIGHT menu  -> the ink IS the menu colour. Nothing that carries light
	 *                   text can approach a bright menu, so stop trying: take
	 *                   the colour, and the light text it already carries goes
	 *                   with it.
	 *   DARK menu    -> sit just under the menu. ⛔ Never ON colors[0]: that is
	 *                   deeper than the deepest thing on screen and reads as a
	 *                   hole — the thing he rejected on sunrise (#6f2724).
	 */
	const SCHEME_INKS = array(
		// ---- WordPress before 7.1 ----
		'#096484' => '#245278', // blue      — his call 2026-08-20: ONE blue for every
		                        //             generation, so this is the 7.1 bar (8.09:1)
		'#627c83' => '#39535a', // ocean     — his call 2026-08-20: ONE ocean for every
		                        //             generation, so this is the 7.1 bar (8.07:1)
		'#b43c38' => '#8a312d', // sunrise   — his call 2026-08-20: ONE sunrise for every
		                        //             generation, so this is the 7.1 bar (8.07:1)
		'#46403c' => '#46403c', // coffee    — dark menu; EXACT bar (4.5:1)
		'#413256' => '#432e5f', // ectoplasm — his pick 2026-08-20: the 7.1 value, for both
		'#25282b' => '#25282b', // midnight  — dark menu; EXACT bar (6.7:1)

		// ---- WP 7.1 — every menu here is dark. Some sit just under it; blue,
		//      ocean and sunrise wear the bar ITSELF, his call 2026-08-20 — all
		//      three clear 8:1 on the cream card, so the deepening bought contrast
		//      nobody needed and cost the scheme's own colour. ----
		'#183751' => '#245278', // blue      — his pick 2026-08-20: the EXACT bar, not a deepening (8.09:1)
		'#382e27' => '#4e4036', // coffee
		'#392751' => '#432e5f', // ectoplasm
		'#232a2e' => '#2d353a', // midnight
		'#2b3f44' => '#39535a', // ocean     — his pick 2026-08-20: the EXACT bar, not a deepening (8.07:1)
		'#6f2724' => '#8a312d', // sunrise   — his pick 2026-08-20: the EXACT bar (8.07:1)

		// ---- unchanged across both ----
		// ⭐ fresh — RULE 10 AGAIN, his pick 2026-08-21. This scheme used to return
		//    before the map was ever read, so its slabs wore the app's own warm
		//    near-black #1b1913 by day and the designed night card #262d31 after
		//    dark. It now wears the night card in both, like `modern` below.
		// ⛔ #262d31 IS NOT A FRESH COLOUR — it is app.css's dark --ar-surface, and
		//    he named it directly (over #1d2327, fresh's own bar, which he asked
		//    for first and replaced). So this entry is keyed by fresh's colors[0]
		//    like every other row, but the VALUE answers "what is this slab at
		//    night", not "what colour is this scheme". ⚠️ Which means a WordPress
		//    retune of fresh moves the KEY here and nothing else.
		// ⚠️ THE OTHER COPY is the base dark --ar-surface in app.css (:root
		//    [data-ar-theme="dark"]). Both move together or the slab reads two
		//    colours again — the same pairing as modern's, noted below.
		// ⛔ AND FRESH STILL ADOPTS NOTHING ELSE — see Admin::scheme_css(). It
		//    lends its slab a depth; the accent, the greens and the rug stay the
		//    designed ones, because this is the scheme they were designed on.
		'#1d2327' => '#262d31', // fresh  — the designed night card, his call 2026-08-21
		// ⭐ light — HIS PICK 2026-08-21, replacing the #ebeaea slab of 08-20 and
		//    returning to the MID GREY this scheme wore before it. ⛔ Read the
		//    rug section in schemes.css before touching it: on #6e6e6e nothing
		//    coloured reaches 4.5:1 (green 2.27, teal 2.10, amber 2.58), which is
		//    why the first #6e6e6e attempt was abandoned. It works now because
		//    the rug went MONOCHROME with it — the white-rug treatment he wrote
		//    for the scheme-coloured grounds later the same day.
		// ⚠️ 0.4314 vs BRIGHT_MENU_L 0.42 — eleven thousandths. That margin is
		//    what keeps is_bright() true here, and is_bright() is what keeps this
		//    scheme's accent on the designed teal instead of its #c64606 orange
		//    highlight. ⭐⭐ THAT LANDMINE IS DEFUSED as of the same evening: he
		//    asked for #656363, which measures 0.3922 and WOULD have flipped it,
		//    so the exemption moved off is_bright() and onto the slug in
		//    Admin::scheme_css() — where it always belonged, since "this is the
		//    scheme schemes.css dresses by hand" is an identity, not a
		//    brightness. The ink is now free to be any depth.
		'#e5e5e5' => '#656363', // light  — his call 2026-08-21, a step down from #6e6e6e
		// ⭐ modern (the DEFAULT scheme) — ONE CARD, NOT TWO. His call 2026-08-21:
		//    "make the rail's bg as its dark mode looks". This scheme's ink
		//    surfaces used to be its menu colour #1e1e1e by day and its night
		//    card #242424 after dark — one slab, two colours, for no reason
		//    anybody could see. It now wears the night card in both modes.
		// ⚠️ THE OTHER HALF OF THIS VALUE lives in schemes.css, as --ar-surface
		//    in the MODERN section. This file's own convention is to write a
		//    shared hex out in both places rather than share one rule (see the
		//    note gold, in four sections) — so if WordPress retunes `modern`,
		//    BOTH have to move, and neither is the copy.
		// ⛔ It stays a GREY, which is the point: carries_colour() still refuses
		//    it, so the app keeps its designed teal accent on the Default scheme.
		'#1e1e1e' => '#242424', // modern — its night card, by his call 2026-08-21
	);

	/* ⛔ SCHEME_SURFACES AND card_surface_for() ARE GONE — 2026-08-21, his call.
	 * They answered "which schemes dress their surfaces in a BRIGHT menu colour
	 * instead of a dark ink". Three did (blue, ocean, sunrise) until 2026-08-20,
	 * when he collapsed each onto its own 7.1 bar — "just one for all" — and the
	 * table emptied. An empty table meant card_surface_for() could only answer '',
	 * which meant the branch in Admin::scheme_css() that consumed it was
	 * unreachable, which is why that branch went too.
	 * ⚠️ RESTORING THE BEHAVIOUR IS NOT ONE LINE. It needs the table, the reader
	 * AND the branch back together; a row on its own would now do nothing. The
	 * branch's own note in Admin::scheme_css() records what it did.
	 * ⛔ is_bright() SURVIVES and is unrelated — it still decides whether a hover
	 * lifts or deepens, and still fences the Light scheme off the accent re-key. */

	/**
	 * The card surface for a scheme: the curated map for the core schemes
	 * (exact, hand-picked), the {@see scheme_ink()} derivation for anything
	 * else (a third-party scheme the map can't know about), and '' when there
	 * is no scheme to read.
	 *
	 * ⚠️ "fresh" USED TO RETURN '' HERE, and stopped on 2026-08-21 when he gave
	 * that scheme a light ink of its own (#262d31, the designed night card).
	 * It is now an ordinary row in the map. What fresh still does NOT do is
	 * adopt an accent, a green or a white rug — that exemption moved to
	 * {@see Admin::scheme_css()}, which is where adoption is decided.
	 *
	 * @param string $scheme Scheme slug from the user's profile, e.g. "coffee".
	 *                       Used only to spot an empty choice and to hand the
	 *                       derivation a menu; the ink itself is chosen by
	 *                       colour, never by slug.
	 * @param string $base   The scheme's registered base colour (colors[0]) —
	 *                       what this install actually paints, and the key the
	 *                       curated map is looked up by.
	 * @return string Card-depth hex, or '' (no scheme / nothing usable).
	 */
	public static function card_ink_for( $scheme, $base ) {
		$scheme = (string) $scheme;
		if ( '' === $scheme ) {
			return '';
		}
		$base = strtolower( trim( (string) $base ) );
		if ( isset( self::SCHEME_INKS[ $base ] ) ) {
			return self::SCHEME_INKS[ $base ];
		}

		// Unmeasured scheme: apply the rule. The menu is a best guess for one we
		// have never seen — colors[1] holds it for six of the eight core schemes
		// — and $base (colors[0]) is the anchor.
		return self::derive_ink( self::registered_menu( $scheme, $base ), $base );
	}

	/**
	 * May this scheme's colour stand in for GREEN on the wordless verdict marks?
	 *
	 * ⭐ HIS CALL, 2026-08-20: on a scheme that is not green, a green "pass" bar
	 * is the one loud thing on a page whose whole job is to show you what needs
	 * attention. Quieting it to the scheme's own colour lets the gold and the red
	 * do that work alone.
	 *
	 * ⛔ BUT ONLY WHERE THE COLOUR CAN CARRY IT, and this is not a style question.
	 * Sunrise's ink is hue 3 — FOUR degrees from --ar-bad. Re-keyed there, every
	 * passing check would paint red and read as a failure. Coffee's sits between
	 * warn and bad. Light, modern, midnight's brighter variation and ocean's are
	 * near-neutral: a verdict mark in grey is the "off" state, which means "we
	 * did not look", not "this is fine".
	 *
	 * So the guard is computed, not a hand list — a future WordPress retune moves
	 * these colours (it already has, twice) and a list would quietly go stale:
	 *
	 *   saturation >= 14%   or the mark is grey and says nothing
	 *                       (COLOUR_FLOOR — the accent gate reads the same one)
	 *   lightness 15–62%    or it cannot be seen on the card
	 *   >= 25deg from BOTH --ar-warn (40) and --ar-bad (7)
	 *
	 * ⭐ THE SATURATION GATE IS 14% AND NOT 20%, his call 2026-08-20 on the
	 * brighter ocean: he looked at a green pass-bar beside a grey-green scheme
	 * and asked for the scheme's colour, and the number that was refusing it was
	 * drawn from the dark inks rather than measured against this one. #738e96 is
	 * 14.3% saturated and reads as a colour on the card, not as an "off" state.
	 * ⚠️ 14% and not, say, 10% — that is the whole margin. WP 7.1's midnight sits
	 * at 12.6%, its older one at 7.5% and coffee's older bar at 7.7%, and those
	 * ARE greys: a verdict mark in grey says "we did not look", not "this is
	 * fine". The gate admits exactly one more colour than it did, and it was
	 * chosen by listing every ink and surface the app can emit and reading where
	 * the line had to fall.
	 *
	 * ⭐ THE CEILING IS 62% AND NOT 55%, his call 2026-08-20 on the brighter
	 * blue. A bright menu's surface is LIGHT by definition — that is what makes
	 * it bright — so a ceiling drawn for the dark-menu inks was refusing the one
	 * family it was never measured against. #52accc sits at 56%. The gates that
	 * actually protect a verdict mark are the other two, and they still hold:
	 * ocean's brighter surface (#738e96) is 14% saturated and stays green,
	 * sunrise's (#cf4944) is hue 2 and stays green. Only the blue passes.
	 * ⚠️ #52accc is 2.5:1 on the cream card — under the 3:1 floor for a graphic.
	 * His call, made twice, with the number on the table: the mark is redundant
	 * beside the PASS pill that names the same verdict in words.
	 *
	 * ⚠️ Applies to the WORDLESS marks only — the rule beside a check, the group
	 * rung, the totals dot. The worded pills ("Anyone can read this", PASS) keep
	 * --ar-good: their colour is redundant beside the word, and they sit next to
	 * accent-coloured switches, where sharing a colour would erase the difference
	 * between what you chose and what is true.
	 *
	 * @param string $ink The colour the accent actually takes — the ink on a
	 *                    dark-menu scheme, the SURFACE on a bright one.
	 * @return bool
	 */
	public static function verdict_can_take( $ink ) {
		$ink = ltrim( strtolower( trim( (string) $ink ) ), '#' );
		if ( ! preg_match( '/\A[0-9a-f]{6}\z/', $ink ) ) {
			return false;
		}
		$rgb = array_map( 'hexdec', str_split( $ink, 2 ) );
		$max = max( $rgb ) / 255;
		$min = min( $rgb ) / 255;
		$l   = ( $max + $min ) / 2;
		$d   = $max - $min;
		if ( $d <= 0 ) {
			return false;
		}
		$sat = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
		if ( $sat < self::COLOUR_FLOOR || $l < 0.15 || $l > 0.62 ) {
			return false;
		}
		$hue = self::hue_of( $rgb );
		foreach ( array( 40.0, 7.0 ) as $taken ) {
			$gap = fmod( abs( $hue - $taken ), 360 );
			if ( min( $gap, 360 - $gap ) < 25 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The scheme's menu tone, best-effort: colors[1] where it exists, else the
	 * given fallback. ⚠️ Only used for schemes the curated map has never seen —
	 * the core ones never reach it.
	 *
	 * @param string $scheme   Scheme slug.
	 * @param string $fallback Used when colors[1] is missing.
	 * @return string
	 */
	private static function registered_menu( $scheme, $fallback ) {
		global $_wp_admin_css_colors;
		$hex = isset( $_wp_admin_css_colors[ $scheme ]->colors[1] )
			? strtolower( trim( (string) $_wp_admin_css_colors[ $scheme ]->colors[1] ) )
			: '';

		return preg_match( '/\A#[0-9a-f]{6}\z/', $hex ) ? $hex : $fallback;
	}

	/**
	 * The ACTIVE scheme's own surface colour, straight from WordPress.
	 *
	 * colors[1] is the menu/surface tone (colors[0] is the darker bar above it);
	 * a scheme registering only one colour falls back to that.
	 *
	 * This exists because WordPress RETUNES schemes. Midnight was #363b3f for
	 * years and became #333c42 in 7.1 — same slug, different colour, and the
	 * owner has one install of each. Anything keyed to the slug alone paints
	 * one of them wrong.
	 *
	 * @return string Lowercase #rrggbb, or '' when there is nothing usable.
	 */
	public static function active_surface() {
		$scheme = get_user_option( 'admin_color' );
		if ( ! is_string( $scheme ) || '' === $scheme ) {
			return '';
		}

		global $_wp_admin_css_colors;
		$colors = isset( $_wp_admin_css_colors[ $scheme ]->colors )
			? (array) $_wp_admin_css_colors[ $scheme ]->colors
			: array();

		$hex = '';
		if ( isset( $colors[1] ) ) {
			$hex = (string) $colors[1];
		} elseif ( isset( $colors[0] ) ) {
			$hex = (string) $colors[0];
		}

		$hex = strtolower( trim( $hex ) );

		return preg_match( '/\A#[0-9a-f]{6}\z/', $hex ) ? $hex : '';
	}

	/**
	 * Hue in degrees from an RGB triplet.
	 *
	 * @param array $rgb Three 0-255 channels.
	 * @return float
	 */
	private static function hue_of( $rgb ) {
		$r   = $rgb[0] / 255;
		$g   = $rgb[1] / 255;
		$b   = $rgb[2] / 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$d   = $max - $min;
		if ( $d <= 0 ) {
			return 0.0;
		}
		if ( $max === $r ) {
			$h = fmod( ( $g - $b ) / $d, 6 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}

		return fmod( $h * 60 + 360, 360 );
	}

	/**
	 * Can this colour be read AS TEXT on the app's light card?
	 *
	 * ⭐ The gate for WordPress's own highlight, colors[2] — his idea, 2026-08-20:
	 * the colour the admin menu paints on the current item is the scheme author
	 * saying "this one", which is exactly what an accent means. It is used for
	 * links, tab underlines, numbers and the verdict marks, so the bar is the
	 * body-text floor rather than the graphic one.
	 *
	 * ⚠️ Seven of the nine core schemes clear it — ectoplasm 5.50, fresh 5.08,
	 * coffee 4.88, ocean 4.83, light 4.82, sunrise 4.51, blue 4.50. Midnight's
	 * #69a8bb (2.61) and modern's #7b90ff (2.84) do not: they were mixed to sit
	 * on a dark menu, not on paper. Those fall back to the derived ink, which is
	 * what they wear today.
	 *
	 * @param string $hex Candidate colour.
	 * @return bool
	 */
	public static function carries_text( $hex ) {
		$hex = strtolower( trim( (string) $hex ) );
		if ( ! preg_match( '/\A#[0-9a-f]{6}\z/', $hex ) ) {
			return false;
		}

		return self::contrast( $hex, self::INK_TEXT ) >= 4.5;
	}

	/**
	 * WCAG contrast ratio between two hex colours.
	 *
	 * @param string $a First colour.
	 * @param string $b Second colour.
	 * @return float
	 */
	private static function contrast( $a, $b ) {
		$lum = static function ( $hex ) {
			$rgb = array_map( 'hexdec', str_split( ltrim( $hex, '#' ), 2 ) );
			$out = 0.0;
			foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
				$c    = $rgb[ $i ] / 255;
				$c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
				$out += $weight * $c;
			}

			return $out;
		};
		$la = $lum( $a );
		$lb = $lum( $b );

		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/** The light theme's --ar-paper — the text every ink surface carries. */
	const INK_TEXT = '#fffdf7';

	/**
	 * The ink for a menu colour we have not measured — HIS RULE, 2026-08-20,
	 * given after looking at both generations of blue and sunrise side by side.
	 *
	 * A card ink has one job it cannot negotiate: be dark enough to carry light
	 * text and coloured status badges. Where it sits RELATIVE to the chrome then
	 * depends on how bright the chrome is, and the two cases want opposite moves:
	 *
	 *  - BRIGHT menu (sunrise #cf4944; classic blue and ocean took this path
	 *    until 2026-08-20, when he collapsed each to a single colour):
	 *    nothing that carries light text can come near it, so the ink echoes the
	 *    scheme's own DARK ANCHOR — colors[0], the tone the chrome already shows
	 *    in its current-item block. In classic blue that is #096484, and the ink
	 *    beside it reads as belonging to the room rather than dropped into it.
	 *
	 *  - DARK menu (every WP 7.1 bar): the anchor is now DARKER than the menu,
	 *    and an ink taken there goes deeper than the deepest thing on screen —
	 *    it reads as a hole. He rejected exactly that on sunrise (#6f2724) and
	 *    picked the tone just under the menu instead. So: blend toward the
	 *    anchor, do not land on it.
	 *
	 * @param string $menu   The colour the menu actually paints.
	 * @param string $anchor colors[0], the darker bar — '' when unavailable.
	 * @return string Card-depth hex, or ''.
	 */
	public static function derive_ink( $menu, $anchor ) {
		$menu   = strtolower( trim( (string) $menu ) );
		$anchor = strtolower( trim( (string) $anchor ) );
		if ( ! preg_match( '/\A#[0-9a-f]{6}\z/', $menu ) ) {
			return '';
		}
		$rgb = array_map( 'hexdec', str_split( ltrim( $menu, '#' ), 2 ) );
		$l   = ( max( $rgb ) + min( $rgb ) ) / 2 / 255;

		if ( $l > self::BRIGHT_MENU_L ) {
			// Bright menu — the ink IS the menu. ⛔ Do not deepen: every attempt
			// to find a dark that "goes with" a bright menu lands somewhere
			// nobody chose, and he settled it by pointing at the answer —
			// buttons the same blue as the sidebar.
			return $menu;
		}

		// Dark menu — sit just under it. ⛔ Not ON the anchor: that is the hole.
		if ( ! preg_match( '/\A#[0-9a-f]{6}\z/', $anchor ) ) {
			return self::scheme_ink( $menu );
		}
		$a = array_map( 'hexdec', str_split( ltrim( $anchor, '#' ), 2 ) );
		$m = array();
		foreach ( $rgb as $i => $c ) {
			$m[] = (int) round( $c + ( $a[ $i ] - $c ) * 0.40 );
		}

		return sprintf( '#%02x%02x%02x', $m[0], $m[1], $m[2] );
	}

	/**
	 * Turn a scheme colour into a card-surface tint: the scheme's HUE at card
	 * depth, saturated enough to actually read as that colour. Three moves:
	 *
	 *  1. Lightness clamps to 0.13 — a bright base ("Blue" #096484) becomes a
	 *     deep tint of itself; an already-darker base passes through.
	 *  2. Saturation gets a 0.30 floor — the near-neutral bases (Coffee's warm
	 *     grey, Midnight's slate) otherwise collapse into a dark that is
	 *     indistinguishable from the default ink, i.e. "matching" nobody can
	 *     see. True greys (Light's #e5e5e5, saturation ~0) stay neutral: a grey
	 *     has no hue, and boosting one would invent a colour the scheme never had.
	 *  3. The CARD_LUMA ceiling still applies as a guard — perceived luma and
	 *     HSL lightness disagree most for yellow-green hues, which would
	 *     otherwise come out too bright for the muted text.
	 *
	 * @param string $hex Scheme colour, e.g. "#096484" (also accepts #abc).
	 * @return string Card-depth 6-digit hex, or '' when $hex isn't parseable.
	 */
	public static function scheme_ink( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/\A[0-9a-fA-F]{6}\z/', $hex ) ) {
			return '';
		}

		$rgb = array_map( 'hexdec', str_split( $hex, 2 ) );

		// RGB → HSL.
		$r   = $rgb[0] / 255;
		$g   = $rgb[1] / 255;
		$b   = $rgb[2] / 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$d   = $max - $min;
		$s   = 0.0;
		$h   = 0.0;
		if ( $d > 0 ) {
			$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
			if ( $max === $r ) {
				$h = fmod( ( $g - $b ) / $d, 6 );
			} elseif ( $max === $g ) {
				$h = ( $b - $r ) / $d + 2;
			} else {
				$h = ( $r - $g ) / $d + 4;
			}
			$h = fmod( $h * 60 + 360, 360 );
		}

		$l = min( $l, 0.13 );
		if ( $s > 0.02 ) {
			$s = max( $s, 0.30 );
		}

		// HSL → RGB.
		$c   = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x   = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m   = $l - $c / 2;
		$map = array(
			array( $c, $x, 0 ),
			array( $x, $c, 0 ),
			array( 0, $c, $x ),
			array( 0, $x, $c ),
			array( $x, 0, $c ),
			array( $c, 0, $x ),
		);
		list( $r, $g, $b ) = $map[ min( 5, (int) floor( $h / 60 ) ) ];
		$rgb               = array(
			(int) round( ( $r + $m ) * 255 ),
			(int) round( ( $g + $m ) * 255 ),
			(int) round( ( $b + $m ) * 255 ),
		);

		$luma = ( 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2] ) / 255;
		if ( $luma > self::CARD_LUMA ) {
			$scale = self::CARD_LUMA / $luma;
			foreach ( $rgb as $i => $channel ) {
				$rgb[ $i ] = (int) round( $channel * $scale );
			}
		}

		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}
}
