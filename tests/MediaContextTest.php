<?php
/**
 * Media context — the line an owner writes about each video or audio item, and
 * the switch that says a page was never meant to be read.
 *
 * The rule these lock is the one the feature was redesigned around: Agentimus
 * carries the MEANING of media into the machine surfaces, and does not own the
 * media's own words. It stores no transcript and writes nothing to the front
 * end; a transcript published by any other tool is detected and credited.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\MediaContext;
use Agentimus\PageCheck;
use PHPUnit\Framework\TestCase;

final class MediaContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_af_posts']    = array();
		$GLOBALS['_af_postmeta'] = array();
	}

	private function fixture( int $id, string $content = '', array $meta = array() ): \stdClass {
		$post                = new \stdClass();
		$post->ID            = $id;
		$post->post_content  = $content;
		$post->post_status   = 'publish';
		$post->post_password = '';
		$post->post_type     = 'post';
		$post->post_excerpt  = '';

		$GLOBALS['_af_posts'][ $id ]    = $post;
		$GLOBALS['_af_postmeta'][ $id ] = $meta;

		return $post;
	}

	/* -- identity ----------------------------------------------------------- */

	public function test_a_note_survives_the_embed_resolving_to_a_different_url() {
		// The whole reason notes are keyed rather than positional: WordPress stores
		// `watch?v=ID` in the block and renders `embed/ID` once it resolves the
		// oEmbed. A note written in the editor must still be found afterwards.
		$this->assertSame(
			PageCheck::media_key( 'https://www.youtube.com/watch?v=TOfxwCbK1Ro&list=RDTOfxwCbK1Ro&start_radio=1' ),
			PageCheck::media_key( 'https://www.youtube.com/embed/TOfxwCbK1Ro?feature=oembed' )
		);
		$this->assertSame(
			PageCheck::media_key( 'https://vimeo.com/76979871' ),
			PageCheck::media_key( 'https://player.vimeo.com/video/76979871' )
		);
	}

	public function test_the_playlist_id_is_not_mistaken_for_the_video_id() {
		// A YouTube watch URL carries BOTH; the longer one is the playlist, which is
		// not this video's identity. Two videos from the same playlist must differ.
		$a = PageCheck::media_key( 'https://www.youtube.com/watch?v=aaa111&list=RDaaa111' );
		$b = PageCheck::media_key( 'https://www.youtube.com/watch?v=bbb222&list=RDaaa111' );
		$this->assertNotSame( $a, $b );
	}

	public function test_structural_path_words_are_never_the_identity() {
		// `/{id}/iframe` is how several infrastructure players address a video —
		// keying on "iframe" would give every video on the account one identity.
		$a = PageCheck::media_key( 'https://customer-x.cloudflarestream.com/6b9e68b07dfee8cc/iframe' );
		$b = PageCheck::media_key( 'https://customer-x.cloudflarestream.com/1111111111111111/iframe' );
		$this->assertNotSame( $a, $b );
		$this->assertStringNotContainsString( 'iframe', $a );
	}

	/* -- detection ---------------------------------------------------------- */

	public function test_audio_is_detected_as_first_class_media() {
		// A podcast episode has exactly the same problem a silent video does, and
		// was excluded from this feature entirely before the redesign.
		$items = PageCheck::media_items( '<audio src="https://example.com/ep1.mp3" title="Episode 1"></audio>' );

		$this->assertCount( 1, $items );
		$this->assertSame( 'audio', $items[0]['kind'] );
		$this->assertSame( 'Episode 1', $items[0]['name'] );
	}

	public function test_each_item_is_listed_once_in_document_order() {
		$html = '<figure class="wp-block-embed is-type-video"><div>https://www.youtube.com/watch?v=aaa111</div></figure>'
			. '<audio src="https://example.com/ep1.mp3"></audio>'
			. '<figure class="wp-block-embed is-type-video"><div><iframe src="https://player.vimeo.com/video/76979871"></iframe></div></figure>';

		$items = PageCheck::media_items( $html );

		$this->assertCount( 3, $items );
		$this->assertSame( array( 'video', 'audio', 'video' ), array_column( $items, 'kind' ) );
	}

	public function test_the_same_media_twice_is_one_item() {
		$embed = '<figure class="wp-block-embed is-type-video"><div>https://www.youtube.com/watch?v=aaa111</div></figure>';
		$this->assertCount( 1, PageCheck::media_items( $embed . $embed ) );
	}

	public function test_a_hand_written_iframe_is_the_authors_own_business() {
		// His call: the plugin grades WordPress's own embeds. Markup somebody wrote
		// by hand — an iframe, whatever is inside it — is not the plugin's to police,
		// and reaching into it was where this feature kept growing machinery.
		$this->assertSame( array(), PageCheck::media_items( '<iframe src="https://www.youtube.com/embed/aaa111"></iframe>' ) );
		$this->assertSame( array(), PageCheck::media_items( '<iframe src="https://player.vimeo.com/video/76979871"></iframe>' ) );

		// The same player INSIDE an embed block is WordPress's doing, and is graded.
		$this->assertCount(
			1,
			PageCheck::media_items( '<figure class="wp-block-embed is-type-video"><div><iframe src="https://www.youtube.com/embed/aaa111"></iframe></div></figure>' )
		);
	}

	/* -- notes -------------------------------------------------------------- */

	public function test_items_carry_the_note_written_against_them() {
		// Notes are stored by URL — the shape the block panel writes from JS, so the
		// key rules never need a second implementation in another language.
		$this->fixture(
			1,
			'<figure class="wp-block-embed is-type-video"><div>https://www.youtube.com/watch?v=aaa111</div></figure>'
			. '<figure class="wp-block-embed is-type-video"><div>https://vimeo.com/222222</div></figure>',
			array( '_agentimus_media_context' => array( 'https://www.youtube.com/watch?v=aaa111' => 'A talk about llms.txt.' ) )
		);

		$items = MediaContext::items_for( 1 );

		$this->assertSame( 'A talk about llms.txt.', $items[0]['context'] );
		$this->assertSame( '', $items[1]['context'], 'An undescribed item borrows nothing.' );
		$this->assertSame( 'A talk about llms.txt.', MediaContext::text_for( 1 ) );
	}

	public function test_a_post_with_no_media_has_no_items() {
		$this->fixture( 2, '<p>Just words.</p>' );
		$this->assertSame( array(), MediaContext::items_for( 2 ) );

		$this->fixture( 3, '<audio src="https://example.com/ep1.mp3"></audio>' );
		$this->assertCount( 1, MediaContext::items_for( 3 ) );
	}

	public function test_a_caption_is_the_default_description_and_a_note_overrides_it() {
		// A caption is already a description of the media, written by the author and
		// visible to the reader — a better default than anything we could infer, and
		// it never goes stale because it is read from the page, never copied.
		$caption = '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/abc12345</div>'
			. '<figcaption>A short walkthrough of content negotiation</figcaption></figure>';

		$this->fixture( 7, $caption );
		$items = MediaContext::items_for( 7 );
		$this->assertSame( 'caption', $items[0]['source'] );
		$this->assertSame( 'A short walkthrough of content negotiation', $items[0]['context'] );

		$this->fixture( 7, $caption, array( '_agentimus_media_context' => array( 'https://youtu.be/abc12345' => 'Twelve minutes on Accept headers.' ) ) );
		$items = MediaContext::items_for( 7 );
		$this->assertSame( 'note', $items[0]['source'], 'A written note always wins.' );
		$this->assertSame( 'Twelve minutes on Accept headers.', $items[0]['context'] );
	}

	public function test_a_caption_never_corrupts_the_video_identity() {
		// Regression: a captioned embed concatenates its bare URL and its caption
		// with no separator, so ".../abc12345" + "A short walkthrough" read as
		// ".../abc12345A" — every caption silently changed the video's identity.
		$items = PageCheck::media_items(
			'<figure class="wp-block-embed is-type-video"><div>https://youtu.be/abc12345</div>'
			. '<figcaption>A short walkthrough of content negotiation</figcaption></figure>'
		);

		$this->assertSame( 'https://youtu.be/abc12345', $items[0]['url'] );
		$this->assertSame( PageCheck::media_key( 'https://youtu.be/abc12345' ), $items[0]['key'] );
	}

	/* -- transcripts belong to someone else --------------------------------- */

	public function test_a_transcript_published_by_any_tool_is_read_not_owned() {
		$text = MediaContext::on_page_transcript( '<details><summary>Transcript</summary><p>So today I want to talk about llms.txt.</p></details>' );
		$this->assertStringContainsString( 'So today I want to talk about llms.txt.', $text );

		$heading = MediaContext::on_page_transcript( '<h2>Transcript</h2><p>First line.</p><h2>Credits</h2><p>Thanks.</p>' );
		$this->assertStringContainsString( 'First line.', $heading );
		$this->assertStringNotContainsString( 'Thanks.', $heading, 'The section ends where the next one begins.' );

		// Prose ABOUT transcripts is not one.
		$this->assertSame( '', MediaContext::on_page_transcript( '<p>Always publish a transcript with your video, because assistants cannot hear.</p>' ) );
	}

	public function test_the_class_owns_exactly_one_thing() {
		// The guarantee behind the redesign, and the two scope calls after it: ONE
		// meta key holding notes. No transcript store, no page-level switch, nothing
		// written to the front end, and nothing rendered into the admin sidebar.
		$this->assertSame( '_agentimus_media_context', MediaContext::META );
		$this->assertFalse( class_exists( '\Agentimus\Transcript' ), 'The transcript store was removed, not renamed.' );
		$this->assertFalse( method_exists( MediaContext::class, 'render_field' ), 'The sidebar section was removed.' );
		$this->assertFalse( method_exists( MediaContext::class, 'media_first' ), 'The set-aside switch was removed.' );
		$this->assertFalse( method_exists( MediaContext::class, 'save' ), 'Nothing saves from a form any more.' );
	}

	/* -- sanitising ---------------------------------------------------------- */

	public function test_notes_are_plain_text_capped_and_key_checked() {
		$clean = MediaContext::sanitize_note( "<script>alert(1)</script>A   talk\n\nabout llms.txt." );
		$this->assertStringNotContainsString( '<', $clean );
		$this->assertSame( 'A talk about llms.txt.', $clean );

		$this->assertLessThanOrEqual( MediaContext::MAX_LEN, strlen( MediaContext::sanitize_note( str_repeat( 'a', MediaContext::MAX_LEN + 500 ) ) ) );
		$this->assertSame( '', MediaContext::sanitize_note( array( 'nope' ) ) );

		$map = MediaContext::sanitize_map(
			array(
				'https://www.youtube.com/watch?v=aaa111' => 'Fine.',
				'not-a-url'                              => 'Dropped.',
				'javascript:alert(1)'                    => 'Dropped.',
				'https://www.youtube.com/watch?v=bbb222' => '   ',
			)
		);
		$this->assertSame( array( 'https://www.youtube.com/watch?v=aaa111' => 'Fine.' ), $map );
		$this->assertSame( array(), MediaContext::sanitize_map( 'not an array' ) );
	}

}
