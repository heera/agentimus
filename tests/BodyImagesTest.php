<?php
/**
 * BodyImages — the `<img>` grammar the in-content alt fix is built on.
 *
 * ⭐ These are the tests that matter most in that feature, because everything
 * above them trusts two claims: that a tag is found whole, and that rewriting
 * its alt leaves every other byte of the document alone. A regex that ends a
 * tag early does not fail loudly — it writes an attribute into the middle of
 * somebody's post.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\BodyImages;
use PHPUnit\Framework\TestCase;

final class BodyImagesTest extends TestCase {

	/* -- scan ----------------------------------------------------------- */

	public function test_it_finds_images_in_document_order_with_their_details() {
		$html = '<!-- wp:image {"id":12} --><figure><img src="/a/sunrise.jpg" alt="A sunrise" class="wp-image-12"/></figure><!-- /wp:image -->'
			. '<p>Words.</p><img src="/b/bridge.png">';

		$found = BodyImages::scan( $html );

		$this->assertCount( 2, $found );
		$this->assertSame( 1, $found[0]['index'] );
		$this->assertSame( '/a/sunrise.jpg', $found[0]['src'] );
		$this->assertSame( 'A sunrise', $found[0]['alt'] );
		$this->assertTrue( $found[0]['hasAlt'] );
		$this->assertSame( 12, $found[0]['attachmentId'] );

		$this->assertSame( 2, $found[1]['index'] );
		$this->assertSame( '/b/bridge.png', $found[1]['src'] );
		$this->assertFalse( $found[1]['hasAlt'], 'no alt attribute at all' );
		$this->assertSame( 0, $found[1]['attachmentId'], 'no wp-image class is a normal answer' );
	}

	/**
	 * ⛔ PRESENT-AND-EMPTY IS NOT ABSENT. alt="" is the WAI marker for a
	 * decorative image; collapsing it into "missing" would make a fixing run
	 * announce every spacer on a site.
	 */
	public function test_an_empty_alt_is_present_not_missing() {
		$found = BodyImages::scan( '<img src="/line.png" alt="">' );

		$this->assertTrue( $found[0]['hasAlt'] );
		$this->assertSame( '', $found[0]['alt'] );
	}

	/** ⚠️ `data-alt` is a real attribute on lightbox and lazy-load markup. */
	public function test_data_alt_is_not_mistaken_for_alt() {
		$found = BodyImages::scan( '<img src="/x.jpg" data-alt="not this">' );

		$this->assertFalse( $found[0]['hasAlt'] );
		$this->assertSame( '', $found[0]['alt'] );
	}

	/**
	 * ⛔ THE ONE THAT WOULD CORRUPT A POST. `<img[^>]*>` ends this tag inside
	 * the title attribute, and everything downstream then rewrites a span that
	 * is not a tag.
	 */
	public function test_a_greater_than_inside_a_quoted_value_does_not_end_the_tag() {
		$html  = '<img src="/x.jpg" title="a > b" alt="Chart"><p>after</p>';
		$found = BodyImages::scan( $html );

		$this->assertCount( 1, $found );
		$this->assertSame( 'Chart', $found[0]['alt'] );
		$this->assertSame( '<img src="/x.jpg" title="a > b" alt="Chart">', $found[0]['tag'] );
	}

	public function test_single_quoted_and_unquoted_values_are_read() {
		$found = BodyImages::scan( "<img src='/y.jpg' alt='Single quoted'><img src=/z.jpg alt=Bare>" );

		$this->assertSame( 'Single quoted', $found[0]['alt'] );
		$this->assertSame( '/y.jpg', $found[0]['src'] );
		$this->assertSame( 'Bare', $found[1]['alt'] );
		$this->assertSame( '/z.jpg', $found[1]['src'] );
	}

	public function test_entities_in_an_alt_come_back_decoded() {
		$found = BodyImages::scan( '<img src="/x.jpg" alt="Fish &amp; chips">' );

		$this->assertSame( 'Fish & chips', $found[0]['alt'] );
	}

	public function test_a_body_with_no_images_scans_to_nothing() {
		$this->assertSame( array(), BodyImages::scan( '<p>Just words.</p>' ) );
		$this->assertSame( array(), BodyImages::scan( '' ) );
	}

	/* -- captions: the evidence an empty alt was a blank field ---------- */

	public function test_an_image_in_a_captioned_figure_is_marked_captioned() {
		$found = BodyImages::scan(
			'<figure class="wp-block-image"><img src="/a.jpg" alt=""/><figcaption class="wp-element-caption">Seven days at the edge.</figcaption></figure>'
		);

		$this->assertTrue( $found[0]['hasCaption'] );
	}

	public function test_an_image_with_no_caption_is_not() {
		$found = BodyImages::scan( '<figure><img src="/a.jpg" alt=""/></figure><p>A paragraph nearby.</p>' );

		$this->assertFalse( $found[0]['hasCaption'] );
	}

	/** ⛔ An empty figcaption proves nothing — the editor leaves one behind. */
	public function test_an_empty_caption_does_not_count() {
		$found = BodyImages::scan( '<figure><img src="/a.jpg" alt=""/><figcaption class="wp-element-caption"></figcaption></figure>' );
		$this->assertFalse( $found[0]['hasCaption'] );

		$markup = BodyImages::scan( '<figure><img src="/b.jpg" alt=""/><figcaption><br/></figcaption></figure>' );
		$this->assertFalse( $markup[0]['hasCaption'], 'a caption wearing markup is still empty' );
	}

	/** Each image is judged by ITS OWN figure, not by a caption elsewhere. */
	public function test_a_caption_does_not_leak_to_the_next_image() {
		$found = BodyImages::scan(
			'<figure><img src="/one.jpg" alt=""/><figcaption>Described here.</figcaption></figure>'
			. '<figure><img src="/two.jpg" alt=""/></figure>'
		);

		$this->assertTrue( $found[0]['hasCaption'] );
		$this->assertFalse( $found[1]['hasCaption'] );
	}

	/**
	 * ⛔⛔ THE CLASSIC EDITOR'S CAPTION IS A SHORTCODE, NOT A FIGURE.
	 *
	 * The checks read the RENDERED body, where `the_content` has already turned
	 * `[caption]` into `<figure class="wp-caption">`, so they flag it. This
	 * class reads the STORED body, where it is still a shortcode. Seeing only
	 * figures meant the check flagged a captioned image on every classic-editor
	 * site while the writer refused it as decorative.
	 */
	public function test_a_classic_caption_shortcode_counts_as_a_caption() {
		$found = BodyImages::scan( '[caption id="attachment_9" align="alignnone" width="600"]<img src="/a.jpg" alt="" class="wp-image-9" /> A potter at the wheel.[/caption]' );

		$this->assertTrue( $found[0]['hasCaption'] );
	}

	/** The caption may be written before the picture, and often is. */
	public function test_a_shortcode_caption_before_the_image_counts() {
		$found = BodyImages::scan( '[caption width="600"]Some words <img src="/a.jpg" alt=""/>[/caption]' );

		$this->assertTrue( $found[0]['hasCaption'] );
	}

	/** ⛔ …and an EMPTY shortcode proves nothing, exactly like an empty figcaption. */
	public function test_an_empty_caption_shortcode_does_not_count() {
		$found = BodyImages::scan( '[caption id="attachment_9" width="600"]<img src="/a.jpg" alt="" />[/caption]' );

		$this->assertFalse( $found[0]['hasCaption'], 'the picture is all that is in there' );
	}

	/** Shortcodes and figures can share one body, and each image answers for itself. */
	public function test_shortcode_and_figure_captions_do_not_bleed_into_each_other() {
		$found = BodyImages::scan(
			'[caption width="6"]<img src="/one.jpg" alt=""/> Words.[/caption]'
			. '<figure><img src="/two.jpg" alt=""/></figure>'
		);

		$this->assertTrue( $found[0]['hasCaption'] );
		$this->assertFalse( $found[1]['hasCaption'] );
	}

	/* -- with_alt ------------------------------------------------------- */

	public function test_it_inserts_an_alt_after_img_keeping_every_other_attribute() {
		$tag = '<img src="/a.jpg" class="wp-image-9" srcset="/a.jpg 1x, /a2.jpg 2x" loading="lazy"/>';

		$out = BodyImages::with_alt( $tag, 'A red door.' );

		$this->assertSame( '<img alt="A red door." src="/a.jpg" class="wp-image-9" srcset="/a.jpg 1x, /a2.jpg 2x" loading="lazy"/>', $out );
	}

	public function test_it_replaces_an_existing_alt_in_place() {
		$tag = '<img src="/a.jpg" alt="old" class="wp-image-9">';

		$out = BodyImages::with_alt( $tag, 'new words' );

		$this->assertSame( '<img src="/a.jpg" alt="new words" class="wp-image-9">', $out, 'and it stays where it was among the attributes' );
	}

	public function test_it_replaces_a_single_quoted_alt_with_a_double_quoted_one() {
		$out = BodyImages::with_alt( "<img src='/a.jpg' alt='old'>", 'new' );

		$this->assertSame( "<img src='/a.jpg' alt=\"new\">", $out );
	}

	/** ⛔ A quote in a description must not end the attribute it lives in. */
	public function test_it_escapes_what_would_break_the_attribute() {
		$out = BodyImages::with_alt( '<img src="/a.jpg">', 'A sign reading "Open" & nothing else' );

		$this->assertStringContainsString( '&quot;Open&quot;', $out );
		$this->assertStringContainsString( '&amp;', $out );
		$this->assertSame( 1, substr_count( $out, 'alt="' ), 'exactly one alt attribute' );
	}

	public function test_a_self_closing_tag_stays_self_closing() {
		$out = BodyImages::with_alt( '<img src="/a.jpg" />', 'Something' );

		$this->assertStringEndsWith( '/>', $out );
	}

	/* -- replace -------------------------------------------------------- */

	/**
	 * ⭐⭐ THE WHOLE POINT: one attribute changes and the document is otherwise
	 * byte-for-byte what it was — block comments, shortcodes, entities and the
	 * second image included.
	 */
	public function test_putting_a_tag_back_disturbs_nothing_else() {
		$html = '<!-- wp:image {"id":3} -->' . "\n"
			. '<figure class="wp-block-image"><img src="/one.jpg" class="wp-image-3"/></figure>' . "\n"
			. '<!-- /wp:image -->' . "\n"
			. '[gallery ids="4,5"] &amp; <img src="/two.jpg" alt="Two">';

		$images = BodyImages::scan( $html );
		$after  = BodyImages::replace( $html, $images[0], BodyImages::with_alt( $images[0]['tag'], 'One picture.' ) );

		$this->assertSame( str_replace( '<img src="/one.jpg"', '<img alt="One picture." src="/one.jpg"', $html ), $after );
		$this->assertStringContainsString( '[gallery ids="4,5"] &amp;', $after, 'the shortcode and the entity are untouched' );
		$this->assertStringContainsString( '<img src="/two.jpg" alt="Two">', $after, 'the other image is untouched' );
		$this->assertSame( substr_count( $html, '<!-- wp:' ), substr_count( $after, '<!-- wp:' ) );
	}

	public function test_the_second_image_is_replaced_at_its_own_offset() {
		$html   = '<img src="/one.jpg"><img src="/two.jpg">';
		$images = BodyImages::scan( $html );

		$after = BodyImages::replace( $html, $images[1], BodyImages::with_alt( $images[1]['tag'], 'Two.' ) );

		$this->assertSame( '<img src="/one.jpg"><img alt="Two." src="/two.jpg">', $after );
	}
}
