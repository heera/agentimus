<?php
/**
 * Schema::build_document() — the graph assembler shared by the front-end output
 * and the admin JSON-LD preview. Proves the two target shapes (site vs post), the
 * services-placement rule, that the per-post privacy guard still holds when a post
 * is built directly (the preview path), and that the preview flag relaxes only the
 * publish-status half of that guard (draft → would-be node) while password gating
 * still holds.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Schema;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class SchemaPreviewTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function schema(): Schema {
		return new Schema( new Settings() );
	}

	/** Every node @type in a built document (an @type may be a string or array). */
	private function types( $doc ): array {
		$out = array();
		foreach ( (array) ( $doc['@graph'] ?? array() ) as $node ) {
			if ( isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $t ) {
					$out[] = $t;
				}
			}
		}
		return $out;
	}

	private function post( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'ID'            => 11,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
				'post_title'    => 'Hello World',
				'post_content'  => '<p>Just a paragraph.</p>',
			),
			$overrides
		);
	}

	private function with_services(): void {
		update_option(
			Settings::OPTION,
			array( 'identity' => array( 'services' => array( array( 'name' => 'Consulting' ) ) ) )
		);
	}

	/* -- Site graph ------------------------------------------------------- */

	public function test_site_graph_has_identity_and_services_but_no_post_nodes() {
		$this->with_services();
		$types = $this->types( $this->schema()->build_document( null ) );

		$this->assertContains( 'WebSite', $types );
		$this->assertContains( 'Person', $types );   // default entity type
		$this->assertContains( 'Service', $types );
		$this->assertNotContains( 'BlogPosting', $types );
		$this->assertNotContains( 'BreadcrumbList', $types );
	}

	/* -- Post graph ------------------------------------------------------- */

	public function test_post_graph_has_article_and_breadcrumb_not_services() {
		$this->with_services();
		$types = $this->types( $this->schema()->build_document( $this->post(), false ) );

		$this->assertContains( 'WebSite', $types );
		$this->assertContains( 'BlogPosting', $types );      // post → BlogPosting
		$this->assertContains( 'BreadcrumbList', $types );
		$this->assertNotContains( 'Service', $types );        // services are site-level
	}

	public function test_page_type_maps_to_webpage() {
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_type' => 'page' ) ), false ) );
		$this->assertContains( 'WebPage', $types );
	}

	public function test_faq_content_adds_faqpage_node() {
		$faq   = '<h2>What is it?</h2><p>A layer.</p><h2>Is it free?</h2><p>Yes.</p>';
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_content' => $faq ) ), false ) );
		$this->assertContains( 'FAQPage', $types );
	}

	/** A title with HTML entities (get_the_title() returns &#8220;…&#8221;) must
	 * reach the graph as real characters — entities aren't decoded inside a
	 * <script type="application/ld+json">, so emitting them would corrupt the value. */
	public function test_entities_in_title_are_decoded_in_the_graph() {
		$doc      = $this->schema()->build_document( $this->post( array( 'post_title' => 'The &#8220;AI Tax&#8221;' ) ), false );
		$headline = '';
		foreach ( (array) $doc['@graph'] as $node ) {
			if ( isset( $node['headline'] ) ) {
				$headline = $node['headline'];
				break;
			}
		}
		$this->assertSame( "The \u{201C}AI Tax\u{201D}", $headline );
		$this->assertStringNotContainsString( '&#8220;', $headline );
	}

	/* -- Privacy guard on the direct (preview) path ----------------------- */

	public function test_draft_post_yields_site_nodes_only() {
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_status' => 'draft' ) ), false ) );
		$this->assertContains( 'WebSite', $types );
		$this->assertNotContains( 'BlogPosting', $types );
		$this->assertNotContains( 'BreadcrumbList', $types );
	}

	public function test_password_protected_post_yields_site_nodes_only() {
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_password' => 'secret' ) ), false ) );
		$this->assertContains( 'WebSite', $types );
		$this->assertNotContains( 'BlogPosting', $types );
	}

	/* -- Preview relaxes ONLY the publish-status guard -------------------- */

	public function test_preview_includes_would_be_node_for_draft() {
		// preview = true → the admin preview shows the per-post node a draft WILL emit
		// once published, so the owner can check it before publishing.
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_status' => 'draft' ) ), false, true ) );
		$this->assertContains( 'BlogPosting', $types );
		$this->assertContains( 'BreadcrumbList', $types );
	}

	public function test_preview_still_excludes_password_protected_post() {
		// The password guard is never relaxed — a gated body stays private even once
		// published, so the preview must not fabricate a would-be node for it.
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_password' => 'secret' ) ), false, true ) );
		$this->assertContains( 'WebSite', $types );
		$this->assertNotContains( 'BlogPosting', $types );
	}

	/* -- The services-placement default ----------------------------------- */

	public function test_include_services_defaults_to_true_for_site_false_for_post() {
		$this->with_services();
		// Null post → site view → services included by default.
		$this->assertContains( 'Service', $this->types( $this->schema()->build_document( null ) ) );
		// A post with the default (unspecified) flag → services omitted.
		$this->assertNotContains( 'Service', $this->types( $this->schema()->build_document( $this->post() ) ) );
	}

	/* -- FAQ size ceiling (bounds the per-request DOM cost) --------------- */

	private const FAQ_HTML = '<details><summary>What is it?</summary><p>A discovery layer.</p></details>'
		. '<details><summary>Is it free?</summary><p>Yes, fully.</p></details>';

	public function test_a_normal_faq_post_still_emits_a_faqpage_node() {
		$doc = $this->schema()->build_document( $this->post( array( 'post_content' => self::FAQ_HTML ) ), false );
		$this->assertContains( 'FAQPage', $this->types( $doc ) );
	}

	public function test_faq_detection_is_skipped_above_the_size_ceiling() {
		// Force a tiny ceiling so even this small FAQ post exceeds it — the expensive
		// do_blocks + DOM parse must be skipped on an oversized body.
		add_filter( 'agentimus_faq_max_bytes', static function () { return 10; } );
		$doc = $this->schema()->build_document( $this->post( array( 'post_content' => self::FAQ_HTML ) ), false );
		$this->assertNotContains( 'FAQPage', $this->types( $doc ) );
	}

	/* -- Speakable (AR-CONT-04): article-shaped content only ---------------- */

	/** The post/article node of a built document, or null. */
	private function content_node( $doc ) {
		foreach ( (array) ( $doc['@graph'] ?? array() ) as $node ) {
			if ( isset( $node['@type'] ) && in_array( $node['@type'], array( 'BlogPosting', 'Article', 'WebPage' ), true ) ) {
				return $node;
			}
		}
		return null;
	}

	public function test_posts_carry_a_speakable_specification() {
		$node = $this->content_node( $this->schema()->build_document( $this->post(), false ) );
		$this->assertSame( 'SpeakableSpecification', $node['speakable']['@type'] );
		// Headline + lead selectors for both theme families ship by default.
		$this->assertContains( '.entry-title', $node['speakable']['cssSelector'] );
		$this->assertContains( '.entry-content > p:first-of-type', $node['speakable']['cssSelector'] );
	}

	public function test_pages_do_not_carry_speakable() {
		// WebPage chrome (contact forms, link hubs) is not read-aloud material.
		$node = $this->content_node( $this->schema()->build_document( $this->post( array( 'post_type' => 'page' ) ), false ) );
		$this->assertArrayNotHasKey( 'speakable', $node );
	}

	public function test_an_empty_selector_filter_suppresses_the_speakable_node() {
		add_filter( 'agentimus_speakable_selectors', static function () { return array(); } );
		$node = $this->content_node( $this->schema()->build_document( $this->post(), false ) );
		$this->assertArrayNotHasKey( 'speakable', $node );
	}

	/* -- VideoObject ------------------------------------------------------- */

	/** Every VideoObject node in a built document. */
	private function video_nodes( $doc ): array {
		$out = array();
		foreach ( (array) ( $doc['@graph'] ?? array() ) as $node ) {
			if ( 'VideoObject' === ( $node['@type'] ?? '' ) ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	public function test_every_player_on_the_page_gets_its_own_node() {
		// Captions give each item a description, without which nothing is emitted
		// at all ({@see test_a_node_is_only_emitted_when_we_have_something_to_add}).
		$content = '<figure class="wp-block-embed is-type-video"><div>https://www.youtube.com/watch?v=aaa111</div><figcaption>The first talk.</figcaption></figure>'
			. '<figure class="wp-block-embed is-type-video"><div>https://vimeo.com/222222</div><figcaption>The second talk.</figcaption></figure>'
			. '<figure class="wp-block-video"><video src="https://example.com/talk.mp4"></video><figcaption>The third talk.</figcaption></figure>';

		$videos = $this->video_nodes( $this->schema()->build_document( $this->post( array( 'post_content' => $content ) ), false ) );

		$this->assertCount( 3, $videos, 'A page with three players describes three players.' );

		// The first keeps the bare fragment it has always had; the rest are unique,
		// or the @graph would carry three nodes claiming one identity.
		$ids = array_map( static function ( $n ) { return $n['@id']; }, $videos );
		$this->assertCount( 3, array_unique( $ids ) );
		$this->assertStringEndsWith( '#video', $ids[0] );

		// A player URL and a media-file URL are different properties.
		$this->assertArrayHasKey( 'embedUrl', $videos[0] );
		$this->assertArrayHasKey( 'contentUrl', $videos[2] );
	}

	public function test_the_same_video_twice_on_a_page_is_one_node() {
		$embed   = '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div><figcaption>One talk.</figcaption></figure>';
		$this->assertCount( 1, $this->video_nodes( $this->schema()->build_document( $this->post( array( 'post_content' => $embed . $embed ) ), false ) ) );
	}

	public function test_a_transcript_on_the_page_is_used_only_when_there_is_one_item() {
		// Read from the page, never stored by us — whichever tool published it.
		$body = '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div><figcaption>One talk.</figcaption></figure>'
			. '<h2>Transcript</h2><p>Every word that was said.</p>';
		$one  = $this->video_nodes( $this->schema()->build_document( $this->post( array( 'post_content' => $body ) ), false ) );
		$this->assertStringContainsString( 'Every word that was said.', $one[0]['transcript'] ?? '' );

		// With several players we cannot know WHICH one it belongs to, and hanging
		// it on all of them would tell an assistant they all say the same thing —
		// worse than saying nothing.
		$many = $this->video_nodes(
			$this->schema()->build_document(
					$this->post( array( 'post_content' => $body . '<figure class="wp-block-embed is-type-video"><div>https://vimeo.com/222222</div><figcaption>Another talk.</figcaption></figure>' ) ),
				false
			)
		);
		$this->assertCount( 2, $many );
		foreach ( $many as $node ) {
			$this->assertArrayNotHasKey( 'transcript', $node );
		}
	}

	public function test_each_item_carries_its_OWN_note_and_never_a_borrowed_one() {
		$body = '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div></figure>'
			. '<figure class="wp-block-embed is-type-video"><div>https://vimeo.com/222222</div><figcaption>The other one.</figcaption></figure>';
		$GLOBALS['_af_postmeta'][11] = array(
			// Stored by URL, exactly as the block panel writes it.
			'_agentimus_media_context' => array( 'https://youtu.be/aaa111' => 'A talk about llms.txt.' ),
		);

		$nodes = $this->video_nodes( $this->schema()->build_document( $this->post( array( 'post_content' => $body ) ), false ) );

		$this->assertCount( 2, $nodes );
		$this->assertSame( 'A talk about llms.txt.', $nodes[0]['description'] );
		// Its OWN caption, never its neighbour's note and never the page's
		// description — either would claim the two are the same thing.
		$this->assertSame( 'The other one.', $nodes[1]['description'] );

		unset( $GLOBALS['_af_postmeta'][11] );
	}

	public function test_a_node_is_only_emitted_when_we_have_something_to_add() {
		// Without a description our node is name + url + date — exactly what a video
		// plugin already emits, better, with a real thumbnail. Saying it again would
		// duplicate their work to contribute nothing.
		$bare = $this->video_nodes(
			$this->schema()->build_document( $this->post( array( 'post_content' => '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div></figure>' ) ), false )
		);
		$this->assertSame( array(), $bare );

		// One caption is enough to make it worth saying.
		$described = $this->video_nodes(
			$this->schema()->build_document( $this->post( array( 'post_content' => '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div><figcaption>A talk.</figcaption></figure>' ) ), false )
		);
		$this->assertCount( 1, $described );
	}

	public function test_we_stand_down_when_something_else_already_describes_the_media() {
		// Detected by the SYMPTOM — a VideoObject already in the page — never by a
		// list of plugin names, which would be wrong the day one renamed a class or
		// a new one shipped.
		$foreign = '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div><figcaption>A talk.</figcaption></figure>'
			. '<script type="application/ld+json">{"@context":"https://schema.org","@type":"VideoObject","name":"Someone else\'s node"}</script>';

		$this->assertSame( array(), $this->video_nodes( $this->schema()->build_document( $this->post( array( 'post_content' => $foreign ) ), false ) ) );

		// A page whose JSON-LD is about something else entirely is not a conflict.
		$unrelated = '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div><figcaption>A talk.</figcaption></figure>'
			. '<script type="application/ld+json">{"@type":"Recipe","name":"Soup"}</script>';
		$this->assertCount( 1, $this->video_nodes( $this->schema()->build_document( $this->post( array( 'post_content' => $unrelated ) ), false ) ) );
	}

	public function test_a_plugin_can_declare_it_emits_its_own() {
		// For emitters that run in wp_head or wp_footer, after us, and so cannot be
		// seen from here at all.
		$defer = static function () { return true; };
		add_filter( 'agentimus_defer_video_schema', $defer );

		$nodes = $this->video_nodes(
			$this->schema()->build_document( $this->post( array( 'post_content' => '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div><figcaption>A talk.</figcaption></figure>' ) ), false )
		);

		remove_filter( 'agentimus_defer_video_schema', $defer );
		$this->assertSame( array(), $nodes );
	}

	public function test_a_note_survives_the_embed_being_resolved_to_another_url() {
		// The block stores watch?v=ID; the resolved player is embed/ID. A note keyed
		// to one must be found from the other, or it would silently vanish the
		// moment WordPress resolved the embed.
		$this->assertSame(
			\Agentimus\PageCheck::media_key( 'https://www.youtube.com/watch?v=TOfxwCbK1Ro&list=RDTOfxwCbK1Ro' ),
			\Agentimus\PageCheck::media_key( 'https://www.youtube.com/embed/TOfxwCbK1Ro?feature=oembed' )
		);
		$this->assertSame(
			\Agentimus\PageCheck::media_key( 'https://vimeo.com/76979871' ),
			\Agentimus\PageCheck::media_key( 'https://player.vimeo.com/video/76979871' )
		);
		// Two genuinely different videos still get two keys.
		$this->assertNotSame(
			\Agentimus\PageCheck::media_key( 'https://www.youtube.com/embed/aaa111' ),
			\Agentimus\PageCheck::media_key( 'https://www.youtube.com/embed/bbb222' )
		);
	}

	public function test_audio_becomes_an_audio_object() {
		$podcast = '<figure class="wp-block-audio"><audio src="https://example.com/ep1.mp3"></audio><figcaption>Episode 12, on content negotiation.</figcaption></figure>';

		$this->assertSame( array(), $this->video_nodes( $this->schema()->build_document( $this->post( array( 'post_content' => $podcast ) ), false ) ), 'video_nodes() filters on VideoObject.' );
		$this->assertContains( 'AudioObject', $this->types( $this->schema()->build_document( $this->post( array( 'post_content' => $podcast ) ), false ) ) );
	}

	public function test_the_thumbnail_is_the_videos_own_or_absent() {
		$GLOBALS['_af_thumbnails'][11]  = 99;
		$GLOBALS['_af_attachments'][99] = array( 'https://example.com/featured.png', 1200, 600 );

		// core/video's own Poster control — this video's still, chosen by the author.
		$poster = $this->video_nodes(
			$this->schema()->build_document(
				$this->post( array( 'post_content' => '<figure class="wp-block-video"><video poster="https://example.com/still.jpg" src="https://example.com/talk.mp4"></video><figcaption>The talk.</figcaption></figure>' ) ),
				false
			)
		);
		$this->assertSame( 'https://example.com/still.jpg', $poster[0]['thumbnailUrl'] );

		// One video and no poster: the post's featured image is a fair stand-in.
		$single = $this->video_nodes(
			$this->schema()->build_document( $this->post( array( 'post_content' => '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div><figcaption>One talk.</figcaption></figure>' ) ), false )
		);
		$this->assertSame( 'https://example.com/featured.png', $single[0]['thumbnailUrl'] );

		// Several videos and no posters: repeating one image across all of them
		// claims they look alike, which is simply untrue. Absent beats wrong.
		$many = $this->video_nodes(
			$this->schema()->build_document(
				$this->post( array( 'post_content' => '<figure class="wp-block-embed is-type-video"><div>https://youtu.be/aaa111</div><figcaption>One.</figcaption></figure><figure class="wp-block-embed is-type-video"><div>https://vimeo.com/222222</div><figcaption>Two.</figcaption></figure>' ) ),
				false
			)
		);
		$this->assertCount( 2, $many );
		foreach ( $many as $node ) {
			$this->assertArrayNotHasKey( 'thumbnailUrl', $node );
		}

		unset( $GLOBALS['_af_thumbnails'][11], $GLOBALS['_af_attachments'][99] );
	}

	public function test_a_page_with_no_player_carries_no_video_node() {
		$this->assertSame( array(), $this->video_nodes( $this->schema()->build_document( $this->post(), false ) ) );
	}
}
