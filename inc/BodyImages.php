<?php
/**
 * The `<img>` tags inside a post body — found, read, and rewritten in place.
 *
 * ⭐⭐ WHY THIS IS SEPARATE, and why it is string work rather than a DOM parse.
 * The alt text a reader actually gets for an in-content image lives in the BODY,
 * not on the attachment: WordPress copies the library's alt into the block at
 * the moment the image is inserted and never looks at it again. Describing the
 * attachment afterwards changes nothing on a page that is already published —
 * which is why {@see \Agentimus\Abilities\MediaWriter} cannot close an
 * `alt_text` row, however well it closes `featured_alt`.
 *
 * ⛔ SO THE FIX HAS TO EDIT THE STORED BODY, AND TOUCH NOTHING ELSE IN IT. A DOM
 * parse would round-trip the whole document — block comments, shortcodes,
 * entities and all — and hand back something subtly different everywhere. Every
 * method here works on byte offsets into the original string and rewrites
 * exactly the span it was asked about, so a body with one alt added is
 * byte-identical to the body it came from apart from that one attribute.
 *
 * ⭐ Pure PHP on purpose: no WordPress functions, so the tag grammar can be
 * tested without an install, and the classification that needs WordPress (is
 * this alt a description?) stays with the checks that own it
 * ({@see \Agentimus\PageCheck::counts_as_described()}).
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class BodyImages {

	/**
	 * One `<img>` tag.
	 *
	 * ⚠️ Not `<img[^>]*>`: an attribute value may legally contain `>`, and a
	 * srcset or a title with one in it would end the tag early and leave the
	 * rewrite writing into the middle of the document. The alternation walks
	 * quoted values whole, so only a `>` OUTSIDE quotes closes the tag.
	 */
	const TAG = '/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i';

	/**
	 * One figure, so an image can be asked whether its author captioned it.
	 *
	 * ⚠️ Non-greedy, so a figure inside a figure would close early. WordPress
	 * does not nest them, and the failure mode is a caption not seen rather
	 * than one invented — the safe direction for something that only ever adds
	 * a flag.
	 */
	const FIGURE = '/<figure\b[^>]*>.*?<\/figure>/is';

	/** A figcaption with actual words in it — an empty one proves nothing. */
	const CAPTION = '/<figcaption\b[^>]*>(.*?)<\/figcaption>/is';

	/**
	 * The CLASSIC editor's caption, which is a shortcode and not a figure.
	 *
	 * ⛔⛔ THE GENERALITY BUG THIS EXISTS FOR. The checks read the RENDERED body
	 * — `the_content` runs shortcodes, so `[caption]` arrives at them already
	 * expanded into `<figure class="wp-caption">…<figcaption>` and is seen. This
	 * class reads the STORED body, where it is still a shortcode. Recognising
	 * only figures meant that on any site written in the classic editor the
	 * check would flag a captioned image and describe-content-image would refuse
	 * it as decorative — the guard disagreeing with the check for the third
	 * time, and the first time it would have shipped affecting somebody else's
	 * site rather than this one's.
	 *
	 * ⚠️ `[^\]]*` gives up on an attribute value containing `]`. The failure
	 * direction is a caption NOT seen, which loses a flag rather than inventing
	 * one — the safe way round for something that only ever accuses.
	 */
	const CAPTION_SHORTCODE = '/\[caption\b[^\]]*\](.*?)\[\/caption\]/is';

	/**
	 * The caption box an older theme emits — `<div class="wp-caption">`.
	 *
	 * Reached when raw caption markup is what the body actually holds rather
	 * than the shortcode that usually produces it. Matched by its class so an
	 * ordinary `<div>` is never mistaken for one, and closed non-greedily on
	 * the same terms as {@see FIGURE}.
	 */
	const LEGACY_BOX = '/<div\b[^>]*\bclass\s*=\s*"[^"]*\bwp-caption\b[^"]*"[^>]*>.*?<\/div>/is';

	/**
	 * Every image in a body, in document order.
	 *
	 * @param string $html Post content, as STORED.
	 * @return array<int,array<string,mixed>> index (1-based), offset, length,
	 *         tag, src, alt, hasAlt, attachmentId.
	 */
	public static function scan( $html ) {
		$html = (string) $html;
		$out  = array();
		if ( '' === $html || ! preg_match_all( self::TAG, $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		$captioned = self::captioned_spans( $html );

		foreach ( $m[0] as $i => $hit ) {
			$tag = (string) $hit[0];
			$alt = self::attr( $tag, 'alt' );
			$at  = (int) $hit[1];
			$out[] = array(
				'index'        => $i + 1,
				'offset'       => $at,
				'length'       => strlen( $tag ),
				'tag'          => $tag,
				'src'          => (string) self::attr( $tag, 'src' )['value'],
				'alt'          => (string) $alt['value'],
				// ⭐ The author's own evidence that this picture means something.
				// Nobody writes a sentence underneath a picture that says nothing,
				// so a caption is what separates "deliberately decorative" from
				// "the field was left blank".
				'hasCaption'   => self::within( $at, $captioned ),
				// ⛔ PRESENT-AND-EMPTY IS NOT ABSENT. alt="" is the WAI marker for
				// a decorative image — a decision somebody made — and the content
				// check deliberately does not flag it. Collapsing the two here
				// would make a fixing run announce every spacer and divider on
				// the site.
				'hasAlt'       => (bool) $alt['found'],
				'attachmentId' => self::attachment_id( $tag ),
			);
		}
		return $out;
	}

	/**
	 * The same tag with its alt set — the only thing this class writes.
	 *
	 * Replaces the existing attribute in place when there is one (so its
	 * position among the others is kept), and otherwise inserts one directly
	 * after `<img`. Everything else in the tag is copied byte for byte.
	 *
	 * @param string $tag The tag, as found by {@see scan()}.
	 * @param string $alt The description to set. Escaped here.
	 * @return string
	 */
	public static function with_alt( $tag, $alt ) {
		$tag   = (string) $tag;
		$value = 'alt="' . htmlspecialchars( (string) $alt, ENT_QUOTES, 'UTF-8' ) . '"';
		$found = self::attr( $tag, 'alt' );

		if ( $found['found'] ) {
			return substr_replace( $tag, $value, $found['offset'], $found['length'] );
		}
		// After `<img`, never at the end: a tag may close as `/>` or `>`, and
		// appending would have to know which.
		return substr_replace( $tag, ' ' . $value, 4, 0 );
	}

	/**
	 * Put a rewritten tag back into the body it came from.
	 *
	 * @param string $html  The body.
	 * @param array  $image A row from {@see scan()}.
	 * @param string $tag   The replacement tag.
	 * @return string
	 */
	public static function replace( $html, array $image, $tag ) {
		return substr_replace( (string) $html, (string) $tag, (int) $image['offset'], (int) $image['length'] );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * One attribute of one tag: whether it is there, its decoded value, and the
	 * exact span it occupies so it can be rewritten without touching its
	 * neighbours.
	 *
	 * ⚠️ `(?<![\w-])` so `alt` does not match inside `data-alt` — a real
	 * attribute on lazy-loading and lightbox markup.
	 *
	 * @param string $tag  The tag.
	 * @param string $name Attribute name.
	 * @return array{found:bool,value:string,offset:int,length:int}
	 */
	private static function attr( $tag, $name ) {
		$pattern = '/(?<![\w-])' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
		if ( ! preg_match( $pattern, (string) $tag, $m, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'found'  => false,
				'value'  => '',
				'offset' => 0,
				'length' => 0,
			);
		}
		// The first capturing group that actually took part — an unquoted value
		// leaves the quoted ones at offset -1.
		$value = '';
		foreach ( array( 1, 2, 3 ) as $g ) {
			if ( isset( $m[ $g ] ) && -1 !== (int) $m[ $g ][1] ) {
				$value = (string) $m[ $g ][0];
				break;
			}
		}
		return array(
			'found'  => true,
			'value'  => html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ),
			'offset' => (int) $m[0][1],
			'length' => strlen( (string) $m[0][0] ),
		);
	}

	/**
	 * The [start, end) spans of every figure that carries a real caption.
	 *
	 * @param string $html Post content.
	 * @return array<int,array{0:int,1:int}>
	 */
	private static function captioned_spans( $html ) {
		$spans = array();

		// ⭐ ONE RULE FOR ALL THREE SHAPES: a caption box whose text, once the
		// picture is taken out of it, still says something. That is true of a
		// `<figcaption>`, of the `<p class="wp-caption-text">` an older theme
		// emits, and of the bare text a `[caption]` shortcode carries — so none
		// of them needs its own pattern for the words, only for the box.
		foreach ( array( self::CAPTION_SHORTCODE, self::FIGURE, self::LEGACY_BOX ) as $pattern ) {
			if ( ! preg_match_all( $pattern, $html, $boxes, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $boxes[0] as $box ) {
				$inner = (string) preg_replace( self::TAG, '', (string) $box[0] );
				// ⚠️ And the shortcode's OWN brackets, which are text to
				// strip_tags: `[caption width="600"][/caption]` around a lone
				// picture would otherwise read as a caption saying "caption".
				$inner = (string) preg_replace( '/\[\/?caption\b[^\]]*\]/i', '', $inner );
				// Tags stripped before the emptiness test: a caption holding only
				// a <br> is an empty caption wearing markup.
				if ( '' !== trim( html_entity_decode( strip_tags( $inner ), ENT_QUOTES, 'UTF-8' ) ) ) {
					$spans[] = array( (int) $box[1], (int) $box[1] + strlen( (string) $box[0] ) );
				}
			}
		}
		return $spans;
	}

	/** Is this offset inside one of those spans? */
	private static function within( $offset, array $spans ) {
		foreach ( $spans as $span ) {
			if ( $offset >= $span[0] && $offset < $span[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The library id WordPress writes onto an inserted image, as `wp-image-123`.
	 *
	 * ⭐ 0 is a normal answer, not a failure: an image pasted in by hand, or one
	 * hotlinked from elsewhere, belongs to no attachment — and its alt still
	 * needs writing, which is why nothing here requires an id.
	 *
	 * @param string $tag The tag.
	 * @return int
	 */
	private static function attachment_id( $tag ) {
		return preg_match( '/(?<![\w-])wp-image-(\d+)/i', (string) $tag, $m ) ? (int) $m[1] : 0;
	}
}
