<?php
/**
 * Media — the library search that lets an agent NAME an image.
 *
 * The decisions worth locking: alt text is searched (it is where photographs
 * are actually described), the second pass only runs when the first left room,
 * and the row carries what someone needs to choose between pictures.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Media;
use PHPUnit\Framework\TestCase;

final class MediaTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_get_posts_calls'] = array();
		$GLOBALS['_af_get_posts_queue'] = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Registers an attachment the stubs can answer for. */
	private function attach( int $id, string $title, string $alt = '', array $meta = array() ): void {
		$GLOBALS['_af_posts'][ $id ]       = new \WP_Post( array( 'ID' => $id, 'post_title' => $title, 'post_type' => 'attachment' ) );
		$GLOBALS['_af_postmeta'][ $id ]    = array( '_wp_attachment_image_alt' => $alt );
		$GLOBALS['_af_attachments'][ $id ] = array( 'mime' => 'image/jpeg', 'url' => "https://example.com/{$id}.jpg", 'meta' => $meta );
	}

	public function test_an_empty_query_asks_for_the_most_recent_uploads() {
		$this->attach( 5, 'IMG_4831' );
		$GLOBALS['_af_get_posts_queue'] = array( array( 5 ) );

		$out = Media::search( '', 10 );

		$this->assertCount( 1, $GLOBALS['_af_get_posts_calls'], 'No search terms, no second pass.' );
		$args = $GLOBALS['_af_get_posts_calls'][0];
		$this->assertArrayNotHasKey( 's', $args );
		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( 'attachment', $args['post_type'] );
		$this->assertSame( 'image', $args['post_mime_type'], 'Pictures by default — the seam this closes is featured images.' );
		$this->assertSame( 5, $out[0]['id'] );
	}

	public function test_alt_text_is_searched_because_that_is_where_photographs_are_described() {
		// A camera filename tells you nothing; the alt is the only description.
		$this->attach( 9, 'IMG_4831', 'sunrise over the Buriganga' );
		$GLOBALS['_af_get_posts_queue'] = array( array(), array( 9 ) );

		$out = Media::search( 'sunrise', 10 );

		$this->assertCount( 2, $GLOBALS['_af_get_posts_calls'], 'Title pass found nothing, so the alt pass runs.' );
		$this->assertSame( 'sunrise', $GLOBALS['_af_get_posts_calls'][0]['s'] );
		$meta = $GLOBALS['_af_get_posts_calls'][1]['meta_query'][0];
		$this->assertSame( '_wp_attachment_image_alt', $meta['key'] );
		$this->assertSame( 'LIKE', $meta['compare'] );
		$this->assertSame( 'sunrise', $meta['value'] );
		$this->assertSame( 9, $out[0]['id'], 'The image a title-only search would have missed.' );
	}

	public function test_the_alt_pass_is_skipped_when_the_first_pass_already_filled_the_page() {
		$this->attach( 1, 'Sunrise one' );
		$this->attach( 2, 'Sunrise two' );
		$GLOBALS['_af_get_posts_queue'] = array( array( 1, 2 ) );

		Media::search( 'sunrise', 2 );

		// A second query could only add rows nobody asked to see, so it is not paid for.
		$this->assertCount( 1, $GLOBALS['_af_get_posts_calls'] );
	}

	public function test_the_alt_pass_asks_only_for_the_room_that_is_left_and_never_repeats_a_hit() {
		$this->attach( 1, 'Sunrise one' );
		$this->attach( 7, 'IMG_2', 'sunrise again' );
		$GLOBALS['_af_get_posts_queue'] = array( array( 1 ), array( 7 ) );

		$out = Media::search( 'sunrise', 5 );

		$second = $GLOBALS['_af_get_posts_calls'][1];
		$this->assertSame( 4, $second['posts_per_page'], 'Asks for the remainder, not the whole page again.' );
		$this->assertSame( array( 1 ), $second['post__not_in'], 'A title hit cannot come back as an alt hit.' );
		$this->assertSame( array( 1, 7 ), array_column( $out, 'id' ), 'Title matches lead: a deliberate title beats an incidental alt.' );
	}

	public function test_the_limit_is_bounded_however_it_is_asked_for() {
		$GLOBALS['_af_get_posts_queue'] = array( array() );
		Media::search( '', 9999 );
		$this->assertSame( Media::MAX_RESULTS, $GLOBALS['_af_get_posts_calls'][0]['posts_per_page'] );

		$GLOBALS['_af_get_posts_calls'] = array();
		$GLOBALS['_af_get_posts_queue'] = array( array() );
		Media::search( '', 0 );
		$this->assertSame( 1, $GLOBALS['_af_get_posts_calls'][0]['posts_per_page'], 'Zero or negative still asks for something.' );
	}

	public function test_a_row_carries_what_you_need_to_choose_between_pictures() {
		$this->attach( 42, 'Sunrise', 'Sunrise over the river', array( 'width' => 1600, 'height' => 900 ) );
		$GLOBALS['_af_get_posts_queue'] = array( array( 42 ) );

		$row = Media::search( '', 5 )[0];

		$this->assertSame( 42, $row['id'], 'The id is the whole point — it is what featured_image takes.' );
		$this->assertSame( 'Sunrise over the river', $row['alt'] );
		$this->assertSame( 'https://example.com/42.jpg', $row['url'] );
		$this->assertSame( 1600, $row['width'] );
		$this->assertSame( 900, $row['height'] );
		$this->assertSame( 'image/jpeg', $row['mime'] );
	}

	public function test_anything_that_is_not_an_attachment_is_dropped_rather_than_half_described() {
		$GLOBALS['_af_posts'][3]        = new \WP_Post( array( 'ID' => 3, 'post_title' => 'A post', 'post_type' => 'post' ) );
		$GLOBALS['_af_get_posts_queue'] = array( array( 3, 404 ) );

		$this->assertSame( array(), Media::search( '', 5 ), 'A post id and a missing id both answer with nothing.' );
		$this->assertNull( Media::row( 3 ) );
		$this->assertNull( Media::row( 0 ) );
	}
}
