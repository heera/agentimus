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

	/** Local assertWPError (PHPUnit's WP-specific one isn't in this harness). */
	private function assertWPError( $value ) {
		$this->assertInstanceOf( \WP_Error::class, $value );
	}
}
