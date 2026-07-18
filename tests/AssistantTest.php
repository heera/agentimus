<?php
/**
 * Assistant — the in-admin writing assistant's pure core: turning model output
 * into a sanitised draft document (parse_draft), the bounded dressing lists
 * (clean_list), the launcher-state payload, and the compose contract's shape.
 * The REST plumbing is thin and exercised live; the decisions live here.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Assistant;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class AssistantTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function valid_json(): string {
		return wp_json_encode(
			array(
				'title'       => 'Choosing a Backup Plugin',
				'excerpt'     => 'What actually matters when you pick one.',
				'content'     => '<h2>Start here</h2><p>Backups are boring until they are everything.</p>',
				'description' => 'A practical guide to choosing a WordPress backup plugin.',
				'topics'      => array( 'backups', 'wordpress maintenance' ),
				'tags'        => array( 'backups', 'plugins' ),
				'categories'  => array( 'Guides' ),
			)
		);
	}

	/* -- parse_draft --------------------------------------------------------- */

	public function test_a_clean_json_document_parses_into_a_draft() {
		$draft = Assistant::parse_draft( $this->valid_json() );
		$this->assertSame( 'Choosing a Backup Plugin', $draft['title'] );
		$this->assertStringContainsString( '<h2>Start here</h2>', $draft['content'] );
		$this->assertSame( array( 'backups', 'wordpress maintenance' ), $draft['topics'] );
		$this->assertSame( array( 'Guides' ), $draft['categories'] );
	}

	public function test_fenced_and_prose_wrapped_json_still_parse() {
		$fenced = "```json\n" . $this->valid_json() . "\n```";
		$this->assertSame( 'Choosing a Backup Plugin', Assistant::parse_draft( $fenced )['title'] );

		$prose = "Here is your draft:\n" . $this->valid_json() . "\nHope that helps!";
		$this->assertSame( 'Choosing a Backup Plugin', Assistant::parse_draft( $prose )['title'] );
	}

	public function test_script_tags_do_not_survive_into_the_preview() {
		$data            = json_decode( $this->valid_json(), true );
		$data['content'] = '<p>Fine.</p><script>alert(1)</script><h2>Also fine</h2>';
		$draft           = Assistant::parse_draft( wp_json_encode( $data ) );
		$this->assertStringNotContainsString( '<script', $draft['content'] );
		$this->assertStringContainsString( '<h2>Also fine</h2>', $draft['content'] );
	}

	public function test_missing_title_or_empty_body_is_a_clear_error_not_a_draft() {
		$no_title = json_decode( $this->valid_json(), true );
		unset( $no_title['title'] );
		$this->assertWPError( Assistant::parse_draft( wp_json_encode( $no_title ) ) );

		$empty_body            = json_decode( $this->valid_json(), true );
		$empty_body['content'] = '<p>   </p>';
		$this->assertWPError( Assistant::parse_draft( wp_json_encode( $empty_body ) ) );

		$this->assertWPError( Assistant::parse_draft( 'The model rambled and returned no JSON at all.' ) );
	}

	public function test_dressing_lists_are_bounded_even_when_the_model_overshoots() {
		$data           = json_decode( $this->valid_json(), true );
		$data['topics'] = array_map( static function ( $i ) { return "topic $i"; }, range( 1, 20 ) );
		$data['tags']   = array_map( static function ( $i ) { return "tag $i"; }, range( 1, 20 ) );
		$draft          = Assistant::parse_draft( wp_json_encode( $data ) );
		$this->assertCount( Assistant::MAX_TOPICS, $draft['topics'] );
		$this->assertCount( Assistant::MAX_TAGS, $draft['tags'] );
	}

	/* -- clean_list ----------------------------------------------------------- */

	public function test_clean_list_dedupes_drops_junk_and_bounds() {
		$out = Assistant::clean_list(
			array( ' seo ', 'seo', '', array( 'nested' ), str_repeat( 'x', 61 ), 'aeo' ),
			5
		);
		$this->assertSame( array( 'seo', 'aeo' ), $out );
		$this->assertCount( 2, Assistant::clean_list( array( 'a', 'b', 'c' ), 2 ) );
	}

	/* -- Launcher state -------------------------------------------------------- */

	public function test_state_reflects_the_writes_switch_and_provider_absence() {
		$assistant = new Assistant( new Settings() );
		$state     = $assistant->state();
		$this->assertFalse( $state['writesOn'], 'Writes ship off by default.' );
		$this->assertFalse( $state['providerReady'], 'No wp_ai_client_prompt in tests → no provider.' );

		update_option( Settings::OPTION, array( 'enable_agent_writes' => true ) );
		$this->assertTrue( ( new Assistant( new Settings() ) )->state()['writesOn'] );
	}

	/* -- Compose contract ------------------------------------------------------ */

	public function test_the_system_prompt_pins_the_json_contract_parse_draft_expects() {
		$system = Assistant::system_prompt();
		foreach ( array( '"title"', '"excerpt"', '"content"', '"description"', '"topics"', '"tags"', '"categories"' ) as $key ) {
			$this->assertStringContainsString( $key, $system );
		}
		$this->assertStringContainsString( 'ONLY a single JSON object', $system );
	}

	/* -- Image slots + injection ---------------------------------------------- */

	public function test_image_slots_are_suggestions_with_bounds_and_survive_with_attachments() {
		$slots = Assistant::clean_image_slots(
			array(
				array( 'alt' => 'A tidy desk with a laptop showing a dashboard', 'after_heading' => 'The three layers' ),
				array( 'alt' => 'shrt' ), // Too short to seed a generator — dropped.
				array( 'alt' => 'A close-up of a robots.txt file on screen', 'after_heading' => '', 'attachment_id' => 42 ),
				'not-an-array',
				array( 'alt' => 'x5', 'after_heading' => 'Nope' ),
			)
		);
		$this->assertCount( 2, $slots );
		$this->assertSame( 'The three layers', $slots[0]['after_heading'] );
		$this->assertArrayNotHasKey( 'attachment_id', $slots[0] );
		$this->assertSame( 42, $slots[1]['attachment_id'], 'A chosen image must survive the refine round-trip.' );
	}

	public function test_compose_contract_includes_image_slots_and_forbids_inline_img() {
		$system = Assistant::system_prompt();
		$this->assertStringContainsString( '"images"', $system );
		$this->assertStringContainsString( 'after_heading', $system );
		$this->assertStringContainsString( 'never put <img> tags in "content"', $system );
	}

	public function test_figures_inject_after_their_anchor_heading() {
		$content = '<p>Intro.</p><h2>First section</h2><p>Body one.</p><h2>Second section</h2><p>Body two.</p>';
		$figure  = Assistant::figure_html( 'https://example.test/a.png', 'A desk', 7 );
		$out     = Assistant::inject_images( $content, array( array( 'html' => $figure, 'after_heading' => 'second section' ) ) );
		$this->assertMatchesRegularExpression( '#<h2>Second section</h2><figure[^>]*><img[^>]*wp-image-7#', $out, 'Case-insensitive text anchoring.' );
		$this->assertStringNotContainsString( '<figure', substr( $out, 0, strpos( $out, 'Second' ) ), 'Not injected anywhere earlier.' );
	}

	public function test_missing_anchor_and_empty_anchor_fall_back_to_the_intro_never_vanish() {
		$content = '<p>Intro.</p><h2>Kept heading</h2><p>Body.</p>';
		$figA    = Assistant::figure_html( 'https://example.test/a.png', 'A', 1 );
		$figB    = Assistant::figure_html( 'https://example.test/b.png', 'B', 2 );
		$out     = Assistant::inject_images(
			$content,
			array(
				array( 'html' => $figA, 'after_heading' => 'Renamed by a revision' ), // Gone → intro fallback.
				array( 'html' => $figB, 'after_heading' => '' ),                      // "" → intro by design.
			)
		);
		$this->assertSame( 2, substr_count( $out, '<figure' ), 'No image ever vanishes on a moved anchor.' );
		$intro_end = strpos( $out, '<h2>' );
		$this->assertSame( 2, substr_count( substr( $out, 0, $intro_end ), '<figure' ), 'Both landed in the intro position.' );

		$bare = Assistant::inject_images( 'No paragraphs at all', array( array( 'html' => $figA, 'after_heading' => '' ) ) );
		$this->assertStringEndsWith( '</figure>', $bare, 'Content without paragraphs appends.' );
	}

	/* -- Refine -------------------------------------------------------------- */

	public function test_revision_prompt_carries_the_draft_and_the_preserve_contract() {
		$draft  = Assistant::parse_draft( $this->valid_json() );
		$prompt = Assistant::revision_prompt( $draft, 'add a short section on caching' );
		$this->assertStringContainsString( 'Choosing a Backup Plugin', $prompt, 'The current draft rides in the prompt.' );
		$this->assertStringContainsString( 'add a short section on caching', $prompt );
		$this->assertStringContainsString( 'preserve everything else', $prompt, 'A one-line request must not license a full rewrite.' );
		$this->assertStringContainsString( 'same single JSON object', $prompt, 'The reply must re-enter the same parse path.' );
	}

	public function test_sanitize_draft_gates_a_client_supplied_draft_the_same_as_model_output() {
		// The refine route re-sanitises the HELD draft before it rides in a prompt —
		// same rules as parse_draft, so nothing un-sanitised travels either direction.
		$dirty = array(
			'title'   => "  Backup <script>alert(1)</script> Guide  ",
			'content' => '<p>Fine.</p><script>alert(2)</script>',
			'topics'  => array( 'ok', str_repeat( 'x', 99 ) ),
		);
		$clean = Assistant::sanitize_draft( $dirty );
		$this->assertStringNotContainsString( '<script', $clean['title'] );
		$this->assertStringNotContainsString( '<script', $clean['content'] );
		$this->assertSame( array( 'ok' ), $clean['topics'] );

		$this->assertWPError( Assistant::sanitize_draft( 'not-an-array' ) );
		$this->assertWPError( Assistant::sanitize_draft( array( 'title' => 'No body' ) ) );
	}

	/* -- The outline gate ----------------------------------------------------- */

	private function valid_outline_json(): string {
		return wp_json_encode(
			array(
				'title'    => 'Choosing a Backup Plugin',
				'sections' => array(
					array(
						'heading' => 'What actually matters',
						'note'    => 'The three properties that separate real backups from checkbox features.',
					),
					array(
						'heading' => 'Common mistakes',
						'note'    => 'Where restores fail in practice.',
					),
				),
			)
		);
	}

	public function test_a_clean_fenced_or_prose_wrapped_outline_parses() {
		$clean = Assistant::parse_outline( $this->valid_outline_json() );
		$this->assertSame( 'Choosing a Backup Plugin', $clean['title'] );
		$this->assertCount( 2, $clean['sections'] );
		$this->assertSame( 'What actually matters', $clean['sections'][0]['heading'] );

		$fenced = "```json\n" . $this->valid_outline_json() . "\n```";
		$this->assertSame( 'Common mistakes', Assistant::parse_outline( $fenced )['sections'][1]['heading'] );

		$prose = "Here is your outline:\n" . $this->valid_outline_json() . "\nShape it as you like.";
		$this->assertCount( 2, Assistant::parse_outline( $prose )['sections'] );
	}

	public function test_an_outline_needs_a_title_and_at_least_one_real_section() {
		$this->assertWPError( Assistant::parse_outline( 'no json here at all' ) );
		$this->assertWPError( Assistant::sanitize_outline( 'not-an-array' ) );
		$this->assertWPError( Assistant::sanitize_outline( array( 'title' => 'No sections' ) ) );
		$this->assertWPError(
			Assistant::sanitize_outline(
				array(
					'title'    => '',
					'sections' => array( array( 'heading' => 'Orphan' ) ),
				)
			),
			'A missing title is not an outline.'
		);
		$this->assertWPError(
			Assistant::sanitize_outline(
				array(
					'title'    => 'All rows heading-less',
					'sections' => array( array( 'note' => 'no heading' ), array( 'heading' => '   ' ), 'scalar-row' ),
				)
			),
			'Heading-less rows are dropped; none surviving means no outline.'
		);
	}

	public function test_sanitize_outline_gates_an_owner_edited_outline_the_same_as_model_output() {
		// The compose route re-holds the client's outline to the schema — same
		// both-directions rule as the draft round-trip.
		$edited = array(
			'title'    => "  Backup <script>alert(1)</script> Guide  ",
			'sections' => array_merge(
				array(
					array(
						'heading' => '  Kept and trimmed  ',
						'note'    => str_repeat( 'n', 999 ),
					),
					array( 'heading' => '' ), // The owner emptied this row — dropped.
				),
				array_fill( 0, 15, array( 'heading' => 'Filler section' ) ) // Overshoot.
			),
		);
		$clean  = Assistant::sanitize_outline( $edited );
		$this->assertStringNotContainsString( '<script', $clean['title'] );
		$this->assertSame( 'Kept and trimmed', $clean['sections'][0]['heading'] );
		$this->assertSame( 240, mb_strlen( $clean['sections'][0]['note'] ), 'Notes are bounded.' );
		$this->assertLessThanOrEqual( Assistant::MAX_OUTLINE_SECTIONS, count( $clean['sections'] ), 'The section list is capped.' );
	}

	public function test_outline_prompt_carries_the_brief_and_the_follow_exactly_contract() {
		$outline = Assistant::parse_outline( $this->valid_outline_json() );
		$prompt  = Assistant::outline_prompt( 'A practical post on backup plugins.', $outline );
		$this->assertStringContainsString( 'A practical post on backup plugins.', $prompt, 'The brief still leads.' );
		$this->assertStringContainsString( 'What actually matters', $prompt, 'The outline rides as JSON.' );
		$this->assertStringContainsString( 'Follow it exactly', $prompt );
		$this->assertStringContainsString( 'Do not add, remove, merge or reorder sections', $prompt );
		$this->assertStringContainsString( 'verbatim', $prompt, 'Verbatim headings keep image-slot anchors matchable.' );
	}

	public function test_the_outline_system_prompt_pins_the_json_contract_parse_outline_expects() {
		$prompt = Assistant::outline_system_prompt();
		$this->assertStringContainsString( 'ONLY a single JSON object', $prompt );
		foreach ( array( '"title"', '"sections"', '"heading"', '"note"' ) as $key ) {
			$this->assertStringContainsString( $key, $prompt );
		}
		$this->assertStringContainsString( 'introduction', $prompt, 'Intro/conclusion stay out of the skeleton — compose writes them around it.' );
	}

	/* -- Blockify: HTML → native block markup --------------------------------- */

	public function test_blockify_wraps_the_contract_vocabulary_in_native_blocks() {
		$html = '<p>Intro with <strong>bold</strong> and <a href="https://example.com/">a link</a>.</p>'
			. '<h2>First section</h2><p>Body.</p><h3>Sub point</h3>'
			. '<ul><li>One</li><li>Two</li></ul>';
		$out  = Assistant::blockify( $html );

		$this->assertStringContainsString( "<!-- wp:paragraph -->\n<p>Intro with <strong>bold</strong>", $out );
		$this->assertStringContainsString( 'href="https://example.com/"', $out, 'Inline markup survives untouched.' );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">First section</h2>', $out );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->', $out );
		$this->assertStringContainsString( '<ul class="wp-block-list">', $out );
		$this->assertSame( 2, substr_count( $out, '<!-- wp:list-item -->' ), 'One list-item block per li.' );
	}

	public function test_blockify_quotes_ordered_lists_and_bare_text() {
		$out = Assistant::blockify( '<blockquote><p>Quoted line.</p></blockquote><ol><li>A</li></ol>Loose text.' );
		$this->assertStringContainsString( '<!-- wp:quote -->', $out );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><!-- wp:paragraph -->', $out, 'Quote nests paragraph blocks.' );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $out );
		$this->assertStringContainsString( '<ol class="wp-block-list">', $out );
		$this->assertStringContainsString( '<p>Loose text.</p>', $out, 'Stray top-level text becomes a paragraph, never disappears.' );

		$bare = Assistant::blockify( '<blockquote>No paragraph wrapper.</blockquote>' );
		$this->assertStringContainsString( '<p>No paragraph wrapper.</p>', $bare, 'A bare-text quote gains its paragraph.' );
	}

	public function test_blockify_turns_injected_figures_into_image_blocks_in_place() {
		$content = Assistant::inject_images(
			'<h2>Anchor</h2><p>After.</p>',
			array(
				array(
					'html'          => Assistant::figure_html( 'https://x.test/i.jpg', 'Alt text', 42 ),
					'after_heading' => 'Anchor',
				),
			)
		);
		$out = Assistant::blockify( $content );
		$this->assertStringContainsString( '<!-- wp:image {"id":42,"sizeSlug":"large","linkDestination":"none"} -->', $out );
		$this->assertStringContainsString( 'wp-image-42', $out );
		$heading = strpos( $out, 'wp:heading' );
		$image   = strpos( $out, 'wp:image' );
		$para    = strpos( $out, '<p>After.</p>' );
		$this->assertTrue( $heading < $image && $image < $para, 'Image block sits between its heading and the next paragraph.' );
	}

	public function test_blockify_falls_back_honestly_instead_of_breaking() {
		$this->assertStringContainsString(
			"<!-- wp:html -->\n<h4>Odd heading</h4>",
			Assistant::blockify( '<h4>Odd heading</h4>' ),
			'Vocabulary strays ride the custom-HTML block, rendered verbatim.'
		);

		$already = "<!-- wp:paragraph -->\n<p>Done.</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( $already, Assistant::blockify( $already ), 'Existing block markup is never double-wrapped.' );

		$utf8 = Assistant::blockify( '<p>Curly ’quotes’, −180°C and em—dash.</p>' );
		$this->assertStringContainsString(
			'Curly ’quotes’, −180°C and em—dash.',
			html_entity_decode( $utf8, ENT_QUOTES, 'UTF-8' ),
			'Multibyte text survives the DOM round-trip.'
		);
	}

	/* -- Edit-existing: the gate and the mirror ------------------------------- */

	public function test_edit_gate_names_the_blocks_it_cannot_rewrite() {
		$table = "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>x</td></tr></tbody></table></figure>\n<!-- /wp:table -->";
		$this->assertStringContainsString( 'table', Assistant::edit_gate_reason( $table ) );

		$safe = Assistant::blockify( '<h2>A</h2><p>Fine.</p><ul><li>x</li></ul><blockquote><p>q</p></blockquote>' );
		$this->assertSame( '', Assistant::edit_gate_reason( $safe ), 'The assistant’s own vocabulary always passes.' );
		$this->assertSame( '', Assistant::edit_gate_reason( '<h2>Classic</h2><p>No block comments at all.</p>' ) );
	}

	public function test_edit_gate_bounds_images_and_length() {
		$figures = str_repeat( Assistant::figure_html( 'https://x.test/i.jpg', 'Some alt text', 7 ), 5 );
		$this->assertStringContainsString( 'more images', Assistant::edit_gate_reason( '<p>Hi.</p>' . $figures ) );

		$long = '<p>' . implode( ' ', array_fill( 0, 4200, 'word' ) ) . '</p>';
		$this->assertStringContainsString( 'longer than', Assistant::edit_gate_reason( $long ) );
	}

	public function test_content_to_doc_mirrors_create_lifting_figures_into_anchored_slots() {
		// Build a post the way create() builds one: figures injected, then blockified.
		$original = Assistant::blockify(
			Assistant::inject_images(
				'<p>Intro.</p><h2>Alpha</h2><p>Body A.</p><h2>Beta</h2><p>Body B.</p>',
				array(
					array(
						'html'          => Assistant::figure_html( 'https://x.test/sun.jpg', 'The Sun in extreme ultraviolet.', 42 ),
						'after_heading' => 'Alpha',
					),
				)
			)
		);

		$doc = Assistant::content_to_doc( $original );
		$this->assertStringNotContainsString( '<!-- wp:', $doc['content'], 'Block comments are stripped for the model.' );
		$this->assertStringNotContainsString( '<figure', $doc['content'], 'Figures never ride through the model.' );
		$this->assertStringContainsString( '<p>Body A.</p>', $doc['content'] );

		$this->assertCount( 1, $doc['images'] );
		$this->assertSame( 42, $doc['images'][0]['attachment_id'] );
		$this->assertSame( 'Alpha', $doc['images'][0]['after_heading'], 'The nearest preceding heading anchors the slot.' );
		$this->assertSame( 'The Sun in extreme ultraviolet.', $doc['images'][0]['alt'] );
	}

	public function test_content_to_doc_round_trips_back_through_inject_and_blockify() {
		$doc     = Assistant::content_to_doc(
			Assistant::blockify(
				Assistant::inject_images(
					'<h2>Alpha</h2><p>Text.</p>',
					array(
						array(
							'html'          => Assistant::figure_html( 'https://x.test/i.jpg', 'A described image.', 42 ),
							'after_heading' => 'Alpha',
						),
					)
				)
			)
		);
		$rebuilt = Assistant::blockify(
			Assistant::inject_images(
				$doc['content'],
				array(
					array(
						'html'          => Assistant::figure_html( 'https://x.test/i.jpg', $doc['images'][0]['alt'], $doc['images'][0]['attachment_id'] ),
						'after_heading' => $doc['images'][0]['after_heading'],
					),
				)
			)
		);
		$this->assertStringContainsString( '<!-- wp:image {"id":42', $rebuilt );
		$this->assertTrue(
			strpos( $rebuilt, 'wp:heading' ) < strpos( $rebuilt, 'wp:image' ),
			'The figure returns to its place after the heading.'
		);
	}

	public function test_content_to_doc_never_loses_an_image_and_classic_posts_work() {
		// A classic (no block comments) post with a figure whose alt is unusable.
		$classic = '<h2>Head</h2>' . Assistant::figure_html( 'https://x.test/i.jpg', 'x', 9 ) . '<p>Text.</p>';
		$doc     = Assistant::content_to_doc( $classic );
		$this->assertCount( 1, $doc['images'], 'A short-alt figure gains a placeholder alt instead of being dropped.' );
		$this->assertSame( 9, $doc['images'][0]['attachment_id'] );
		$this->assertGreaterThanOrEqual( 5, mb_strlen( $doc['images'][0]['alt'] ) );
	}

	public function test_placeholder_figures_become_empty_image_blocks_with_alt() {
		$content = Assistant::inject_images(
			'<h2>Alpha</h2><p>Text.</p>',
			array(
				array(
					'html'          => Assistant::placeholder_figure_html( 'The Sun in extreme ultraviolet.' ),
					'after_heading' => 'Alpha',
				),
			)
		);
		$out = Assistant::blockify( $content );
		$this->assertStringContainsString( "<!-- wp:image -->", $out, 'A url-less figure becomes a bare image block (no id attrs).' );
		$this->assertStringNotContainsString( '"id":', $out );
		$this->assertStringContainsString( 'alt="The Sun in extreme ultraviolet."', $out, 'The alt rides into the editor — it is the Generate prompt there.' );
		$this->assertTrue( strpos( $out, 'wp:heading' ) < strpos( $out, 'wp:image' ), 'Placeholder sits after its anchor heading.' );

		// And the mirror: an edit round-trip lifts the placeholder back into a slot.
		$doc = Assistant::content_to_doc( $out );
		$this->assertCount( 1, $doc['images'] );
		$this->assertArrayNotHasKey( 'attachment_id', $doc['images'][0] );
		$this->assertSame( 'Alpha', $doc['images'][0]['after_heading'] );
	}

	public function test_stacked_figures_under_one_heading_keep_their_order() {
		$content = Assistant::inject_images(
			'<h2>Gallery</h2><p>Text.</p>',
			array(
				array(
					'html'          => Assistant::figure_html( 'https://x.test/1.jpg', 'First image here.', 1 ),
					'after_heading' => 'Gallery',
				),
				array(
					'html'          => Assistant::figure_html( 'https://x.test/2.jpg', 'Second image here.', 2 ),
					'after_heading' => 'Gallery',
				),
			)
		);
		$this->assertTrue(
			strpos( $content, 'wp-image-1' ) < strpos( $content, 'wp-image-2' ),
			'Later injections slot in AFTER earlier ones, not before.'
		);
	}

	/* -- Per-section revise (the editor's Revise with AI) --------------------- */

	public function test_section_contract_allows_additions_but_pins_the_rest() {
		$sys = Assistant::section_system_prompt();
		$this->assertStringContainsString( 'ADD new content before or after', $sys );
		$this->assertStringContainsString( 'EXACTLY as it is', $sys );
		$this->assertStringContainsString( 'no markdown fences', $sys );
		$this->assertStringContainsString( 'no <img>', $sys );

		$prompt = Assistant::revise_block_prompt( 'My Post', 'Full text here.', '<p>The section.</p>', 'add a conclusion after this' );
		$this->assertStringContainsString( 'for context only', $prompt, 'The full post rides along but must not come back.' );
		$this->assertStringContainsString( '<p>The section.</p>', $prompt );
		$this->assertStringContainsString( 'add a conclusion after this', $prompt );
	}

	public function test_clean_section_html_cuts_fences_and_refuses_empty() {
		$fenced = "```html\n<p>Revised.</p><p>Added.</p>\n```";
		$this->assertSame( '<p>Revised.</p><p>Added.</p>', Assistant::clean_section_html( $fenced ) );
		$this->assertStringNotContainsString( '<script', Assistant::clean_section_html( '<p>ok</p><script>x()</script>' ) );
		$this->assertWPError( Assistant::clean_section_html( '' ) );
		$this->assertWPError( Assistant::clean_section_html( '<script>only()</script>' ) );
	}

	/* -- Friendly AI errors --------------------------------------------------- */

	public function test_a_provider_quota_wall_becomes_one_actionable_sentence() {
		// The literal shape Gemini's free tier answers with.
		$spew = new \WP_Error(
			'ai_client_error',
			'Too Many Requests (429) - You exceeded your current quota, please check your plan and billing details. '
			. 'For more information on this error, head to: https://ai.google.dev/gemini-api/docs/rate-limits. '
			. '* Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, '
			. 'limit: 20, model: gemini-3.5-flash Please retry in 14.662791842s.'
		);
		$nice = Assistant::friendly_ai_error( $spew );
		$this->assertSame( 'agentimus_ai_provider_limited', $nice->get_error_code() );
		$this->assertStringNotContainsString( 'googleapis', $nice->get_error_message(), 'No metric names.' );
		$this->assertStringNotContainsString( 'http', $nice->get_error_message(), 'No documentation URLs.' );
		$this->assertLessThan( 200, mb_strlen( $nice->get_error_message() ) );
	}

	public function test_our_own_errors_pass_through_untouched() {
		$ours = new \WP_Error(
			'agentimus_ai_rate_limited',
			'Too many AI requests in a short time. Please wait a moment and try again.',
			array( 'status' => 429 )
		);
		$this->assertSame( $ours, Assistant::friendly_ai_error( $ours ), 'agentimus_* errors are already human — never rewritten, even when they mention rate limits.' );
	}

	public function test_unrecognised_provider_noise_is_clipped_to_one_clean_line() {
		$noise = new \WP_Error( 'ai_client_error', "Internal   failure\nwith\nnewlines " . str_repeat( 'x', 400 ) );
		$nice  = Assistant::friendly_ai_error( $noise );
		$this->assertSame( 'agentimus_ai_failed', $nice->get_error_code() );
		$this->assertStringNotContainsString( "\n", $nice->get_error_message(), 'Whitespace collapses to one line.' );
		$this->assertLessThanOrEqual( 160, mb_strlen( $nice->get_error_message() ) );
	}

	/** Local assertWPError (PHPUnit's WP-specific one isn't in this harness). */
	private function assertWPError( $value, string $message = '' ) {
		$this->assertInstanceOf( \WP_Error::class, $value, $message );
	}
}
