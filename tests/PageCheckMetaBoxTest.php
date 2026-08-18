<?php
/**
 * The Readability section body ({@see PageCheckMetaBox::rows_html()}) — the
 * markup the editor meta box renders and the REST auto-refresh route
 * ({@see EditorPanel::rest_page_check()}) returns verbatim. Locks the wrapper and
 * that the summary reflects the analysis, so the extraction stays render-identical.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\EditorPanel;
use Agentimus\PageCheckMetaBox;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class PageCheckMetaBoxTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	public function test_rows_html_reports_substance_for_a_full_post() {
		$post = new \WP_Post( array( 'ID' => 1, 'post_content' => '<p>' . str_repeat( 'word ', 150 ) . '</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );

		$this->assertStringContainsString( 'class="agentimus-pc"', $html );
		$this->assertStringContainsString( 'agentimus-pc__head', $html );
		$this->assertStringContainsString( 'agentimus-pc__row', $html );
		$this->assertStringContainsString( 'Enough substance', $html );
	}

	public function test_rows_html_flags_thin_content() {
		$post = new \WP_Post( array( 'ID' => 2, 'post_content' => '<p>Just a few words.</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );

		// The label names the gap without grading the writing — these strings are
		// quoted verbatim on the Optimize screen, where "Thin content" read as a
		// verdict on the author rather than a thing to do.
		$this->assertStringContainsString( 'Not enough substance yet', $html );
		$this->assertStringContainsString( 'is-warn', $html );
	}

	public function test_rows_html_featured_row_warns_without_one_click_outside_the_editor() {
		unset( $GLOBALS['_af_thumbnails'] );
		$post = new \WP_Post( array( 'ID' => 4, 'post_content' => '<p>' . str_repeat( 'word ', 150 ) . '</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );

		// The post-fact check rides along: no thumbnail → warn row…
		$this->assertStringContainsString( 'No featured image', $html );
		// …but its one-click Generate renders only in a block-editor context.
		$this->assertStringNotContainsString( 'agentimus-pc__genfeat', $html );
	}

	public function test_rows_html_featured_row_passes_when_set() {
		$GLOBALS['_af_thumbnails'] = array( 5 => true );
		$post = new \WP_Post( array( 'ID' => 5, 'post_content' => '<p>' . str_repeat( 'word ', 150 ) . '</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );
		unset( $GLOBALS['_af_thumbnails'] );

		$this->assertStringNotContainsString( 'No featured image', $html );
		$this->assertStringContainsString( 'Featured image', $html );
	}

	public function test_rows_html_output_is_html_escaped() {
		// A row label/detail is escaped, so nothing a check emits can break the markup.
		$post = new \WP_Post( array( 'ID' => 3, 'post_content' => '<p>' . str_repeat( 'word ', 150 ) . '</p>' ) );
		$html = PageCheckMetaBox::rows_html( $post );

		$this->assertStringNotContainsString( '<script', $html );
		// The em-dash separator in the summary survives (UTF-8 passthrough, not mangled).
		$this->assertStringContainsString( 'agentimus-pc__reflect', $html );
	}

	/* -- What the box is MISSING -------------------------------------------- */

	/** Render the switched-off note for a given set of disabled feature keys. */
	private function note( array $off ): string {
		\_af_reset_options();
		$settings = new Settings();
		$all      = $settings->all();
		foreach ( $off as $key ) {
			$all[ $key ] = false;
		}
		\update_option( 'agentimus_settings', $all );

		$panel  = new EditorPanel( new Settings() );
		$method = new \ReflectionMethod( $panel, 'render_missing' );
		\_af_accessible( $method );
		\ob_start();
		$method->invoke( $panel );
		return trim( (string) \ob_get_clean() );
	}

	/**
	 * ⚠️ A PANEL WITH ONE SECTION RENDERS NO TAB BAR, so an owner with two of
	 * three switched off sees an unlabelled box and concludes it is broken. He
	 * did exactly that, 2026-08-18. An absence has to name itself.
	 */
	public function test_the_box_says_which_tabs_are_switched_off() {
		$note = $this->note( array( 'enable_page_checks', 'enable_schema' ) );

		// ⭐ The tab names carry the emphasis — they are what the reader is hunting
		// for and failing to find.
		$this->assertStringContainsString( '<strong>Readability and JSON-LD</strong> tabs are switched off', $note );
		// ⭐ The TAB and its SWITCH are different words: a note naming only the tab
		// sends someone hunting for "JSON-LD" in a list that says "Rich data for
		// search". Both, always.
		$this->assertStringContainsString( 'Readability tips', $note );
		$this->assertStringContainsString( 'Rich data for search', $note );
		$this->assertStringContainsString( 'Settings → Discovery → Features', $note );
	}

	/** One off reads as one. */
	public function test_a_single_switched_off_tab_is_named_in_the_singular() {
		$note = $this->note( array( 'enable_schema' ) );

		$this->assertStringContainsString( '<strong>JSON-LD</strong> tab is switched off', $note );
		$this->assertStringNotContainsString( 'tabs are', $note );
	}

	/** ⛔ Nothing is printed when everything is on — which is the default. */
	public function test_nothing_is_said_when_every_section_is_on() {
		$this->assertSame( '', $this->note( array() ) );
	}

	/**
	 * ⛔ THE BOX MUST NOT VANISH. With all three sections off it used not to be
	 * registered at all — so a first-timer had no clue the editor could offer any
	 * of this, and someone who had switched it off had nothing to switch back on.
	 * His catch, 2026-08-18.
	 */
	public function test_the_box_explains_itself_when_nothing_is_switched_on() {
		\_af_reset_options();
		$settings = new Settings();
		$all      = $settings->all();
		foreach ( array( 'enable_page_checks', 'enable_schema', 'enable_share_copy' ) as $key ) {
			$all[ $key ] = false;
		}
		\update_option( 'agentimus_settings', $all );

		$panel = new EditorPanel( new Settings() );
		\ob_start();
		$panel->render_meta_box( new \WP_Post( array( 'ID' => 1, 'post_content' => '<p>Body.</p>' ) ) );
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( 'Agentimus can add three things to this editor', $html );
		// Each one named with the switch that brings it back — the switch names
		// differ from the tab names, so listing the tabs alone would not help.
		$this->assertStringContainsString( 'Readability tips', $html );
		$this->assertStringContainsString( 'Rich data for search', $html );
		$this->assertStringContainsString( 'Share drafts', $html );
		$this->assertStringContainsString( 'Settings → Discovery → Features', $html );
		// ⛔ And no empty tab bar above it.
		$this->assertStringNotContainsString( 'agentimus-panel__tabs', $html );
	}

	/** ⛔ It gets out of the way the moment anything is on. */
	public function test_the_intro_disappears_once_a_section_is_enabled() {
		\_af_reset_options();
		$panel = new EditorPanel( new Settings() );
		\ob_start();
		$panel->render_meta_box( new \WP_Post( array( 'ID' => 1, 'post_content' => '<p>Body.</p>' ) ) );
		$html = (string) \ob_get_clean();

		$this->assertStringNotContainsString( 'agentimus-panel__intro', $html );
	}

	/**
	 * ⚠️⚠️ THE SAME GUARD, TWICE. `add_meta_box()` bailed when nothing was
	 * enabled, and so did `assets()`. Removing only the first shipped an empty
	 * state with NO STYLESHEET — names, switch pills and blurbs ran together as
	 * one unbroken line on a real editor screen, 2026-08-18. My own preview
	 * harness had injected the CSS by hand, so it looked right there and only
	 * there. This asks the harness what was actually enqueued.
	 */
	public function test_the_panel_stylesheet_loads_even_when_nothing_is_switched_on() {
		\_af_reset_options();
		$settings = new Settings();
		$all      = $settings->all();
		foreach ( array( 'enable_page_checks', 'enable_schema', 'enable_share_copy' ) as $key ) {
			$all[ $key ] = false;
		}
		\update_option( 'agentimus_settings', $all );

		$GLOBALS['_af_inline_css'] = '';
		$GLOBALS['_af_screen']     = (object) array( 'post_type' => 'post' );

		( new EditorPanel( new Settings() ) )->assets( 'post.php' );

		$this->assertStringContainsString(
			'agentimus-panel__introitem',
			(string) $GLOBALS['_af_inline_css'],
			'The empty state ships its own markup, so it must ship its own stylesheet too.'
		);
	}
}
