<?php
/**
 * What the SERVED page does with a featured image's description.
 *
 * The featured image is drawn by the theme, so nothing the content parser sees
 * can tell how the picture reaches a reader. Two cheap routes were tried and
 * both lie: the media library says what was TYPED, and `get_the_post_thumbnail()`
 * called from wp-admin says what an ADMIN request renders — the-alpha
 * substitutes the post title inline in single.php, so that call returns alt=""
 * for a picture the public page describes. 1.37.0 shipped the narrowed claim it
 * could prove; this is the machinery that earns the full one.
 *
 * These tests are the two halves that need no network: reading an alt out of a
 * served page, and deciding what one means.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\ThemeImageProbe;
use PHPUnit\Framework\TestCase;

final class ThemeImageProbeTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_af_filters'][ ThemeImageProbe::FILTER ], $GLOBALS['_af_attachments'] );
	}

	/** The bootstrap's attachment stub — wp_get_attachment_url() reads this. */
	private function attachment( $id, $url ) {
		$GLOBALS['_af_attachments'][ (int) $id ] = array( 'url' => $url );
	}

	/* -------------------------------------------------- reading the page */

	public function test_it_finds_the_alt_of_the_featured_image_on_the_page() {
		$this->attachment( 7, 'https://example.com/wp-content/uploads/2026/08/harbour-at-dusk.jpg' );
		$html = '<article><img src="https://example.com/wp-content/uploads/2026/08/harbour-at-dusk.jpg" alt="Fishing boats tied up at dusk" /></article>';

		$this->assertSame( 'Fishing boats tied up at dusk', ThemeImageProbe::alt_for_attachment( $html, 7 ) );
	}

	/**
	 * ⚠️ The `src` on a page is almost never the original file: WordPress serves
	 * a resized copy, and a CDN can rewrite the host on top of that. Matching the
	 * exact URL would have found nothing on most real sites and quietly reported
	 * "no answer" for every one of them.
	 */
	public function test_it_matches_the_resized_copy_a_page_actually_serves() {
		$this->attachment( 7, 'https://example.com/wp-content/uploads/2026/08/harbour-at-dusk.jpg' );
		$html = '<img src="https://cdn.example.net/uploads/2026/08/harbour-at-dusk-1024x683.jpg" alt="Boats" srcset="...">';

		$this->assertSame( 'Boats', ThemeImageProbe::alt_for_attachment( $html, 7 ) );
	}

	public function test_an_image_with_no_alt_attribute_is_an_empty_description_not_a_missing_page() {
		$this->attachment( 7, 'https://example.com/uploads/harbour-at-dusk.jpg' );
		$html = '<img src="https://example.com/uploads/harbour-at-dusk.jpg" width="800">';

		// ⛔ Empty string, never null: the page WAS read and the picture WAS found.
		// Null means "we could not look", which is a different answer entirely.
		$this->assertSame( '', ThemeImageProbe::alt_for_attachment( $html, 7 ) );
	}

	public function test_a_page_without_the_picture_answers_nothing_rather_than_empty() {
		$this->attachment( 7, 'https://example.com/uploads/harbour-at-dusk.jpg' );
		$html = '<img src="https://example.com/uploads/something-else.jpg" alt="A different picture">';

		$this->assertNull( ThemeImageProbe::alt_for_attachment( $html, 7 ), 'Not finding the image is not evidence about it.' );
	}

	public function test_another_image_on_the_page_is_not_mistaken_for_this_one() {
		$this->attachment( 7, 'https://example.com/uploads/harbour-at-dusk.jpg' );
		$html = '<img src="https://example.com/uploads/logo.png" alt="The site logo">'
			. '<img src="https://example.com/uploads/harbour-at-dusk-300x200.jpg" alt="Boats at dusk">';

		$this->assertSame( 'Boats at dusk', ThemeImageProbe::alt_for_attachment( $html, 7 ) );
	}

	/* ------------------------------------------------ reading the answer */

	public function test_it_tells_the_library_description_from_the_post_title() {
		$library = 'Fishing boats tied up at dusk';
		$title   = 'Why harbours matter';

		$this->assertSame( ThemeImageProbe::USES_LIBRARY, ThemeImageProbe::classify( $library, $library, $title ) );
		$this->assertSame( ThemeImageProbe::USES_TITLE, ThemeImageProbe::classify( $title, $library, $title ) );
		$this->assertSame( ThemeImageProbe::USES_NOTHING, ThemeImageProbe::classify( '', $library, $title ) );
		$this->assertSame( ThemeImageProbe::USES_NOTHING, ThemeImageProbe::classify( '   ', $library, $title ) );
	}

	/**
	 * ⭐ FROM A REAL PAGE, 2026-08-19. the-alpha serves the post title as the
	 * featured image's alt, and WordPress texturizes what it prints — so
	 * "Madonna - Frozen (DJ Zhuk Remix)" left the database with a hyphen and
	 * reached the page as an en dash. Compared byte for byte the title did not
	 * match the title, and the probe concluded the theme was describing pictures
	 * with words of its own: the most flattering possible reading of a page that
	 * describes nothing.
	 */
	public function test_the_title_is_recognised_after_wordpress_has_typeset_it() {
		$stored = 'Madonna - Frozen (DJ Zhuk Remix)';
		$served = 'Madonna &#8211; Frozen (DJ Zhuk Remix)';

		$this->assertSame( ThemeImageProbe::USES_TITLE, ThemeImageProbe::classify( $served, '', $stored ) );
		// Curly quotes and non-breaking spaces are the same trick.
		$this->assertSame(
			ThemeImageProbe::USES_TITLE,
			ThemeImageProbe::classify( 'It&#8217;s  a  guide', '', "It's a guide" )
		);
	}

	/**
	 * ⛔ A library description that merely repeats the post title is no evidence
	 * about which one the theme reached for — and "it used the library" is the
	 * flattering guess. The title wins the tie.
	 */
	public function test_when_the_description_equals_the_title_the_theme_gets_no_credit() {
		$same = 'Why harbours matter';
		$this->assertSame( ThemeImageProbe::USES_TITLE, ThemeImageProbe::classify( $same, $same, $same ) );
	}

	/**
	 * ⛔ Words we cannot account for are still WORDS. A theme printing a caption,
	 * or a plugin supplying its own description, has described the picture — and
	 * accusing that page of an emptiness nobody saw would be the same fault as
	 * the admin-side call this probe replaced.
	 */
	public function test_a_description_from_somewhere_else_still_counts_as_described() {
		$this->assertSame(
			ThemeImageProbe::USES_LIBRARY,
			ThemeImageProbe::classify( 'A caption the theme prints', 'Something else entirely', 'A title' )
		);
	}

	public function test_the_cron_hook_and_the_filter_can_never_be_the_same_name() {
		// Actions and filters share ONE hook namespace: if these matched, reading
		// the stored answer would invoke the refresh registered on the cron hook
		// and recurse until the request died.
		$this->assertNotSame( ThemeImageProbe::CRON, ThemeImageProbe::FILTER );
	}
}
