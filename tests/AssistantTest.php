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

	/* -- Shapes ---------------------------------------------------------------- */

	public function test_shape_is_decided_by_hierarchy_not_by_a_hard_coded_list() {
		$this->assertSame( Assistant::SHAPE_ARTICLE, Assistant::shape_for( 'post' ) );
		$this->assertSame( Assistant::SHAPE_PAGE, Assistant::shape_for( 'page' ) );

		// A page-like CPT classifies itself the day it's registered — nothing to
		// configure, no list to keep in step.
		$GLOBALS['_af_hierarchical_types'] = array( 'handbook' );
		$this->assertSame( Assistant::SHAPE_PAGE, Assistant::shape_for( 'handbook' ) );
		$this->assertSame( Assistant::SHAPE_ARTICLE, Assistant::shape_for( 'essay' ), 'A flat CPT is an article.' );
		unset( $GLOBALS['_af_hierarchical_types'] );

		// An unknown or empty type can't be hierarchical, so it writes as an article.
		$this->assertSame( Assistant::SHAPE_ARTICLE, Assistant::shape_for( '' ) );
	}

	public function test_the_shape_filter_can_override_and_only_the_two_shapes_exist() {
		add_filter( 'agentimus_assistant_shape', function ( $shape, $type ) {
			return 'landing' === $type ? Assistant::SHAPE_PAGE : $shape;
		}, 10, 2 );
		$this->assertSame( Assistant::SHAPE_PAGE, Assistant::shape_for( 'landing' ) );

		// Anything the filter invents that isn't the page shape lands back on the
		// article — the prompts branch on two values and only two exist.
		add_filter( 'agentimus_assistant_shape', function () {
			return 'brochure';
		} );
		$this->assertSame( Assistant::SHAPE_ARTICLE, Assistant::shape_for( 'page' ) );
	}

	public function test_a_page_prompt_drops_the_article_furniture() {
		$page = Assistant::system_prompt( Assistant::SHAPE_PAGE );

		// Same JSON keys either way — the parser must never have to know which
		// shape it's holding — but a page is told to send the article furniture
		// back empty rather than being offered it.
		foreach ( array( '"title"', '"excerpt"', '"content"', '"description"', '"topics"', '"tags"', '"categories"', '"images"' ) as $key ) {
			$this->assertStringContainsString( $key, $page );
		}
		$this->assertStringContainsString( 'ALWAYS an empty array', $page );
		$this->assertStringNotContainsString( 'after_heading', $page, 'A page is offered no image anchors.' );
		$this->assertStringNotContainsString( 'answer engines lift it as the summary', $page );
		$this->assertStringContainsString( '[placeholder]', $page, 'A page that carries obligations must not invent facts.' );

		// The article shape is untouched by any of it.
		$article = Assistant::system_prompt();
		$this->assertStringContainsString( 'after_heading', $article );
		$this->assertStringNotContainsString( 'ALWAYS an empty array', $article );
		$this->assertSame( $article, Assistant::system_prompt( Assistant::SHAPE_ARTICLE ), 'Default is the article shape.' );
	}

	public function test_every_generating_prompt_takes_a_shape() {
		foreach ( array( 'outline_system_prompt', 'staged_part_system_prompt', 'staged_meta_system_prompt', 'section_system_prompt' ) as $fn ) {
			$article = Assistant::$fn();
			$page    = Assistant::$fn( Assistant::SHAPE_PAGE );
			$this->assertNotSame( $article, $page, $fn . '() must differ by shape.' );
			$this->assertSame( $article, Assistant::$fn( Assistant::SHAPE_ARTICLE ), $fn . '() defaults to the article.' );
		}

		// The outline's floor comes down for a page: two sections is a real page,
		// eight is an article wearing a page's name.
		$this->assertStringContainsString( '2–5', Assistant::outline_system_prompt( Assistant::SHAPE_PAGE ) );
		$this->assertStringContainsString( '3–8', Assistant::outline_system_prompt() );
	}

	public function test_the_type_list_carries_each_shape_and_both_labels() {
		$GLOBALS['_af_available_post_types'] = array( 'post', 'page' );
		$types = Assistant::types();
		$by    = array();
		foreach ( $types as $t ) {
			// Both names travel: the plural names the chooser, the singular writes
			// the sentences around it. Shipping only the plural is what produced
			// "Describe the posts" and "This pages uses blocks…".
			$this->assertArrayHasKey( 'label', $t );
			$this->assertArrayHasKey( 'singular', $t );
			$this->assertNotSame( $t['label'], $t['singular'], 'A type must offer a distinct singular for prose.' );
			$by[ $t['slug'] ] = $t['shape'];
		}
		$this->assertSame( Assistant::SHAPE_ARTICLE, $by['post'] );
		$this->assertSame( Assistant::SHAPE_PAGE, $by['page'] );
		unset( $GLOBALS['_af_available_post_types'] );
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

	/* -- Staged compose: one part per call, the client assembles --------------- */

	public function test_staged_part_prompt_carries_the_spine_and_this_sections_assignment() {
		$outline = Assistant::parse_outline( $this->valid_outline_json() );
		$prompt  = Assistant::staged_part_prompt( 'A practical post on backup plugins.', $outline, 'section', 1 );
		$this->assertStringContainsString( 'A practical post on backup plugins.', $prompt, 'The brief is the spine.' );
		$this->assertStringContainsString( 'What actually matters', $prompt, 'The COMPLETE outline rides along — every part sees the whole plan.' );
		$this->assertStringContainsString( 'section 2 of 2', $prompt );
		$this->assertStringContainsString( '<h2>Common mistakes</h2>', $prompt, 'The verbatim-heading instruction names the exact markup.' );
		$this->assertStringContainsString( 'Where restores fail in practice.', $prompt, 'The section\'s own note is its assignment.' );
		$this->assertStringContainsString( 'The section before it is "What actually matters"', $prompt, 'Neighbours stand in for the shared state parallel calls can\'t have.' );
		$this->assertStringContainsString( 'last section', $prompt );
	}

	public function test_staged_intro_and_conclusion_are_headingless_bookends() {
		$outline = Assistant::parse_outline( $this->valid_outline_json() );

		$intro = Assistant::staged_part_prompt( 'Brief here.', $outline, 'intro' );
		$this->assertStringContainsString( 'INTRODUCTION', $intro );
		$this->assertStringContainsString( '"What actually matters"', $intro, 'The intro leads into the FIRST section.' );
		$this->assertStringContainsString( 'No heading of its own', $intro );

		$conclusion = Assistant::staged_part_prompt( 'Brief here.', $outline, 'conclusion' );
		$this->assertStringContainsString( 'CLOSING', $conclusion );
		$this->assertStringContainsString( '"Common mistakes"', $conclusion, 'The closing lands after the LAST section.' );
		$this->assertStringContainsString( 'No heading of its own', $conclusion );
	}

	public function test_the_writing_prompts_carry_the_readability_contract_in_sync_with_the_checks() {
		// The rules the AI Readability box grades ride every article-writing
		// prompt — and the paragraph cap derives from PageCheck's own
		// threshold, so instruction and check can never drift apart.
		$rules = Assistant::readability_rules();
		$this->assertStringContainsString( (string) ( \Agentimus\PageCheck::LONG_PARAGRAPH_WORDS - 10 ), $rules, 'The hard cap sits under the check\'s warn threshold.' );
		$this->assertStringContainsString( 'short sentences and plain words', $rules, 'Reading ease.' );
		$this->assertStringContainsString( 'never invented', $rules, 'Specifics stay grounded.' );
		$this->assertStringContainsString( 'never a guessed URL', $rules, 'Cited sources without hallucinated links.' );
		$this->assertStringContainsString( 'not a link list', $rules, 'Prose-vs-links.' );

		foreach ( array( Assistant::system_prompt(), Assistant::staged_part_system_prompt() ) as $prompt ) {
			$this->assertStringContainsString( $rules, $prompt, 'Both article writers carry the same contract.' );
		}
		$this->assertStringContainsString( 'summarises the whole piece', Assistant::system_prompt(), 'Whole-document compose opens with the liftable summary.' );

		$outline = Assistant::parse_outline( $this->valid_outline_json() );
		$this->assertStringContainsString(
			'summary of the whole article',
			Assistant::staged_part_prompt( 'Brief here.', $outline, 'intro' ),
			'In the staged pipeline the summary rule belongs to the intro part.'
		);
	}

	public function test_the_staged_part_system_prompt_pins_the_html_contract() {
		$prompt = Assistant::staged_part_system_prompt();
		$this->assertStringContainsString( 'ONE PART', $prompt );
		$this->assertStringContainsString( 'no images', $prompt, 'Images belong to the meta call\'s slots, never inline.' );
		$this->assertStringContainsString( 'no post title', $prompt );
		$this->assertStringContainsString( 'assembled in outline order', $prompt, 'Continuity rules stand in for shared state.' );
		$this->assertStringContainsString( 'the brief wins', $prompt, 'Same voice hierarchy as the whole-document contract.' );
	}

	public function test_enforce_section_heading_makes_the_outline_heading_win() {
		// The model paraphrased its heading — ours replaces it, verbatim.
		$html = Assistant::enforce_section_heading( '<h2>Mistakes People Make</h2><p>Body text.</p>', 'Common mistakes' );
		$this->assertStringStartsWith( '<h2>Common mistakes</h2>', $html );
		$this->assertStringContainsString( '<p>Body text.</p>', $html );
		$this->assertStringNotContainsString( 'Mistakes People Make', $html );

		// No heading at all — ours is prepended.
		$this->assertStringStartsWith(
			'<h2>Common mistakes</h2><p>Straight to it.</p>',
			Assistant::enforce_section_heading( '<p>Straight to it.</p>', 'Common mistakes' )
		);

		// A section that was ONLY its heading is refused, not padded.
		$this->assertWPError( Assistant::enforce_section_heading( '<h2>All hat</h2>', 'Common mistakes' ) );

		// Special characters survive the verbatim promise, escaped.
		$html = Assistant::enforce_section_heading( '<p>x</p>', 'Cache & the "ghost" of a 404' );
		$this->assertStringContainsString( 'Cache &amp; the &quot;ghost&quot; of a 404', $html );
	}

	public function test_strip_leading_heading_cuts_one_bookend_heading_only() {
		// The intro/closing lose a heading the model added anyway…
		$this->assertSame( '<p>Welcome.</p>', Assistant::strip_leading_heading( '<h2>Introduction</h2><p>Welcome.</p>' ) );
		$this->assertSame( '<p>Bye.</p>', Assistant::strip_leading_heading( "<h3>Wrapping up</h3>\n<p>Bye.</p>" ) );
		// …but only the LEADING one: later subheads are content.
		$this->assertSame(
			'<p>Lead.</p><h3>Detail</h3><p>More.</p>',
			Assistant::strip_leading_heading( '<p>Lead.</p><h3>Detail</h3><p>More.</p>' )
		);
		$this->assertWPError( Assistant::strip_leading_heading( '<h2>Nothing else</h2>' ), 'A heading with no body is not a part.' );
	}

	public function test_parse_meta_parses_the_dressing_and_bounds_it_like_a_draft() {
		$json = wp_json_encode(
			array(
				'title'       => 'Choosing a Backup Plugin',
				'excerpt'     => 'What actually matters when you pick one.',
				'description' => str_repeat( 'd', 300 ),
				'topics'      => array_fill( 0, 20, 'topic' ),
				'tags'        => array( 'backups', 'plugins' ),
				'categories'  => array( 'Guides' ),
				'images'      => array(
					array(
						'alt'           => 'A dusty server room with one glowing restore light',
						'after_heading' => 'Common mistakes',
					),
					array( 'alt' => 'x' ), // Too thin to be a slot.
				),
			)
		);
		$meta = Assistant::parse_meta( "```json\n" . $json . "\n```" );
		$this->assertSame( 'Choosing a Backup Plugin', $meta['title'] );
		$this->assertSame( 200, mb_strlen( $meta['description'] ), 'Description is bounded.' );
		$this->assertLessThanOrEqual( Assistant::MAX_TOPICS, count( $meta['topics'] ) );
		$this->assertCount( 1, $meta['images'], 'Slots are held to the same rules as compose\'s.' );
		$this->assertSame( 'Common mistakes', $meta['images'][0]['after_heading'], 'Slots anchor to outline headings.' );
		$this->assertArrayNotHasKey( 'content', $meta, 'The dressing never carries a body.' );

		$this->assertWPError( Assistant::parse_meta( 'no json at all' ) );
		$this->assertWPError( Assistant::parse_meta( '{"excerpt":"but no title"}' ), 'A title-less dressing is a clear error.' );
	}

	public function test_the_staged_meta_system_prompt_pins_its_json_contract() {
		$prompt = Assistant::staged_meta_system_prompt();
		$this->assertStringContainsString( 'ONLY a single JSON object', $prompt );
		foreach ( array( '"title"', '"excerpt"', '"description"', '"topics"', '"tags"', '"categories"', '"images"', '"after_heading"' ) as $key ) {
			$this->assertStringContainsString( $key, $prompt );
		}
		$this->assertStringNotContainsString( '"content"', $prompt, 'The body belongs to the parts, never the dressing.' );
		$this->assertStringContainsString( 'outline', $prompt, 'Image anchors tie to the outline\'s own headings.' );
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

	public function test_blockify_heals_a_sentence_split_around_a_loose_link() {
		// The real shape from a staged draft: the model closed the paragraph,
		// dropped the link bare, and opened a new paragraph mid-sentence.
		$out = Assistant::blockify(
			'<p>We refactored our plugin to hook into WordPress at the</p>'
			. '<a href="https://developer.wordpress.org/reference/hooks/plugins_loaded/">plugins_loaded</a>'
			. '<p>action, running at priority 0.</p>'
		);
		$this->assertSame( 1, substr_count( $out, '<!-- wp:paragraph -->' ), 'One sentence, one paragraph block.' );
		$this->assertStringNotContainsString( 'wp:html', $out, 'The bare link never reaches the custom-HTML fallback.' );
		$this->assertStringContainsString(
			'at the <a href="https://developer.wordpress.org/reference/hooks/plugins_loaded/">plugins_loaded</a> action',
			$out,
			'The link sits inline, spaced, where the sentence wants it.'
		);
	}

	public function test_blockify_leaves_deliberate_boundaries_alone() {
		// A finished sentence before the link, a capitalised one after: nothing
		// here is mid-sentence, so nothing merges — the link just gets a real
		// paragraph instead of the custom-HTML fallback.
		$out = Assistant::blockify(
			'<p>Read the docs.</p><a href="https://example.com/">Reference</a><p>Next topic starts here.</p>'
		);
		$this->assertSame( 3, substr_count( $out, '<!-- wp:paragraph -->' ) );
		$this->assertStringContainsString( "<p><a href=\"https://example.com/\">Reference</a></p>", $out );
		$this->assertStringNotContainsString( 'wp:html', $out );

		// An unfinished paragraph absorbs the run, but an Uppercase follower
		// stays its own paragraph — merging is only for plain continuations.
		$half = Assistant::blockify( '<p>It hooks into</p><em>plugins_loaded</em><p>Priorities matter.</p>' );
		$this->assertSame( 2, substr_count( $half, '<!-- wp:paragraph -->' ) );
		$this->assertStringContainsString( '<p>It hooks into <em>plugins_loaded</em></p>', $half );

		// A colon is a terminal boundary: "the following:" introduces, it does
		// not continue — the loose run stands alone.
		$colon = Assistant::blockify( '<p>Consider the following:</p><code>wp_cache_set</code><p>and its friends.</p>' );
		$this->assertStringContainsString( '<p><code>wp_cache_set</code> and its friends.</p>', $colon, 'The standalone run still pulls a lowercase continuation in.' );
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

	public function test_edit_gate_bounds_length_but_never_images() {
		// Images are lifted out before the model runs and put back after, so their
		// number says nothing about what a rewrite can hold. This many used to be
		// refused; refusing it was the suggestion ceiling escaping onto the
		// owner's own pictures.
		$figures = str_repeat( Assistant::figure_html( 'https://x.test/i.jpg', 'Some alt text', 7 ), 12 );
		$this->assertSame( '', Assistant::edit_gate_reason( '<p>Hi.</p>' . $figures ), 'A picture-heavy post is still editable.' );

		$long = '<p>' . implode( ' ', array_fill( 0, 4200, 'word' ) ) . '</p>';
		$this->assertStringContainsString( 'longer than', Assistant::edit_gate_reason( $long ) );
	}

	public function test_existing_images_ride_through_while_invented_ones_stay_capped() {
		// Twelve real images (each carrying an attachment) plus a greedy pile of
		// invented ones. Every real image survives; the inventions stop at the
		// ceiling. Slicing the array as one list — the old behaviour — would have
		// returned four slots and quietly deleted eight pictures.
		$slots = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$slots[] = array( 'alt' => 'A real photograph number ' . $i, 'attachment_id' => 100 + $i );
		}
		for ( $i = 1; $i <= 9; $i++ ) {
			$slots[] = array( 'alt' => 'An invented suggestion number ' . $i );
		}

		$clean    = Assistant::clean_image_slots( $slots );
		$existing = array_filter( $clean, function ( $s ) { return ! empty( $s['attachment_id'] ); } );
		$invented = array_filter( $clean, function ( $s ) { return empty( $s['attachment_id'] ); } );

		$this->assertCount( 12, $existing, 'Every image the owner already had must survive the round-trip.' );
		$this->assertCount( Assistant::MAX_IMAGE_SLOTS, $invented, 'Invention stays bounded.' );

		// Order is preserved, so re-injection still lands each figure where it was.
		$this->assertSame( 101, $clean[0]['attachment_id'] );
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

	public function test_an_image_quota_wall_names_the_library_alternative() {
		$spew = new \WP_Error( 'ai_client_error', 'Quota exceeded for metric: generativelanguage.googleapis.com/generate_images_free_tier, limit: 0' );
		$nice = Assistant::friendly_image_error( $spew );
		$this->assertSame( 'agentimus_image_quota', $nice->get_error_code() );
		$this->assertStringContainsString( 'library', $nice->get_error_message(), 'The owner is pointed at the working alternative.' );
		$this->assertSame( 429, $nice->get_error_data()['status'] );
	}

	public function test_an_empty_image_error_still_reaches_the_editor_as_a_sentence() {
		// WP 7.0's AI Client throws without a message — seen live on heera.it.
		$blank = new \WP_Error( 'ai_client_error', '' );
		$nice  = Assistant::friendly_image_error( $blank );
		$this->assertSame( 'agentimus_image_failed', $nice->get_error_code() );
		$this->assertNotSame( '', $nice->get_error_message(), 'Never an empty message.' );
		$this->assertStringContainsString( 'library', $nice->get_error_message() );
	}

	public function test_image_provider_noise_is_clipped_and_never_a_gateway_status() {
		$noise = new \WP_Error( 'ai_client_error', "Upstream\nimage   failure " . str_repeat( 'y', 400 ) );
		$nice  = Assistant::friendly_image_error( $noise );
		$this->assertSame( 'agentimus_image_failed', $nice->get_error_code() );
		$this->assertStringNotContainsString( "\n", $nice->get_error_message(), 'Whitespace collapses to one line.' );
		$this->assertLessThanOrEqual( 160, mb_strlen( $nice->get_error_message() ) );
		$this->assertSame( 500, $nice->get_error_data()['status'], 'A provider failure is our 500 — never a 502 a CDN can intercept.' );
	}

	/** Local assertWPError (PHPUnit's WP-specific one isn't in this harness). */
	private function assertWPError( $value, string $message = '' ) {
		$this->assertInstanceOf( \WP_Error::class, $value, $message );
	}
}
