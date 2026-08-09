<?php
/**
 * The Agentimus brand mark — the one place the plugin's logo SVG is drawn.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * The mark (dark rounded square, paper "A", amber crossbar, teal ring) is needed
 * in two places that can't share markup — the server-rendered meta-box titles and
 * the block-editor inspector panel, which receives it as a string — so it lives
 * here as one drawing. A second hand-drawn copy in JavaScript would drift from
 * this one the first time the mark changed. Extracted from {@see Admin}, which
 * keeps `brand_title()` / `brand_icon()` as delegates for its historical callers.
 */
final class Brand {

	/**
	 * A meta-box title wearing the brand tile, so every Agentimus box is
	 * recognisable at a glance. WordPress echoes meta-box titles as raw HTML;
	 * the icon is decorative (aria-hidden), the text still labels the box.
	 *
	 * @param string $text The plain-text title (already translated).
	 * @return string
	 */
	public static function title( $text ) {
		$icon = self::icon( 16, 'flex:none;margin-top:2px' );
		// No white-space:nowrap here: the h2 shares its flex row with WP's own
		// header controls (move/collapse, wider since 7.1 wrapped them in
		// tooltips), and an unshrinkable title pushes those controls out past
		// the box edge in the 280px sidebar. Long titles wrap to a second line
		// instead; flex-start + the icon's top margin keep the mark aligned
		// with the first line when they do.
		return '<span style="display:inline-flex;align-items:flex-start;gap:5px">' . $icon . '<span>' . esc_html( $text ) . '</span></span>';
	}

	/**
	 * The Agentimus mark as a standalone SVG.
	 *
	 * @param int    $size  Pixel size (square).
	 * @param string $style Optional inline style for the root element.
	 * @return string
	 */
	public static function icon( $size = 16, $style = '' ) {
		$size = max( 8, (int) $size );
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"'
			. ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>'
			. '<rect x="1.2" y="1.2" width="21.6" height="21.6" rx="6" fill="#1b1913" stroke="#146b64" stroke-width="1.5"/>'
			. '<path d="M7.35 17.3 12 6.7 16.65 17.3" stroke="#f3f0e7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
			. '<path d="M9.5 13H14.5" stroke="#ad7b18" stroke-width="1.9" stroke-linecap="round"/></svg>';
	}
}
