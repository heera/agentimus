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

	/** Hand-picked card-depth surface per core admin scheme (contrast ratio noted). */
	const SCHEME_INKS = array(
		'light'     => '#333333', // neutral grey scheme → neutral dark (5:1)
		'modern'    => '#1e1e1e', // its own menu colour, already card-depth
		'blue'      => '#07485f', // #096484 deepened just past the bar (4.3:1)
		'coffee'    => '#46403c', // EXACT menu colour (4.5:1)
		'ectoplasm' => '#413256', // EXACT menu colour (5.2:1)
		'midnight'  => '#25282b', // EXACT menu colour (6.7:1)
		'ocean'     => '#3a4a4f', // #627c83 deepened to the bar (4.1:1)
		'sunrise'   => '#7e2a27', // #b43c38 deepened to the bar (4.2:1)
	);

	/**
	 * The card surface for a scheme: the curated map for the core schemes
	 * (exact, hand-picked), the {@see scheme_ink()} derivation for anything
	 * else (a third-party scheme the map can't know about), and '' for the
	 * default "fresh" — which keeps the designed warm ink.
	 *
	 * @param string $scheme Scheme slug from the user's profile, e.g. "coffee".
	 * @param string $base   The scheme's registered base colour (colors[0]),
	 *                       used only for the unlisted-scheme fallback.
	 * @return string Card-depth hex, or '' (default scheme / nothing usable).
	 */
	public static function card_ink_for( $scheme, $base ) {
		$scheme = (string) $scheme;
		if ( '' === $scheme || 'fresh' === $scheme ) {
			return '';
		}
		if ( isset( self::SCHEME_INKS[ $scheme ] ) ) {
			return self::SCHEME_INKS[ $scheme ];
		}

		return self::scheme_ink( (string) $base );
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
