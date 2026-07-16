<?php
/**
 * DOM→markdown converter shapes for WP 7.1-era block markup: Tabs (ARIA
 * tablist/tabpanel), Playlist track buttons, <br> inside <pre> (wpautop's
 * line-break rendering for preformatted/verse), and pipe tables. Fixtures
 * mirror the server-rendered HTML of core blocks on 7.1-beta1.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownConvertTest extends TestCase {

	/** Trimmed server render of core/tabs on 7.1-beta1 (directives elided). */
	private const TABS_HTML = '<div class="wp-block-tabs">'
		. '<div aria-label="Tabbed content" role="tablist" class="wp-block-tab-list">'
		. '<button id="tab__t-0" type="button" role="tab">Installation</button>'
		. '<button id="tab__t-1" type="button" role="tab">Configuration</button>'
		. '</div>'
		. '<div class="wp-block-tab-panels">'
		. '<section hidden aria-labelledby="tab__t-0" id="t-0" role="tabpanel" class="wp-block-tab-panel"><p>Install the plugin and activate it.</p></section>'
		. '<section hidden aria-labelledby="tab__t-1" id="t-1" role="tabpanel" class="wp-block-tab-panel"><p>Open Settings and pick a mode.</p></section>'
		. '</div></div>';

	public function test_tab_panels_get_their_labels_and_the_tab_strip_is_skipped() {
		$md = Markdown::from_html( self::TABS_HTML );

		$this->assertStringContainsString( "**Installation**\n\nInstall the plugin and activate it.", $md );
		$this->assertStringContainsString( "**Configuration**\n\nOpen Settings and pick a mode.", $md );
		// The tab strip must not render as a run-on word blob.
		$this->assertStringNotContainsString( 'InstallationConfiguration', $md );
	}

	public function test_hidden_tab_panels_are_still_converted() {
		// Server HTML ships every panel with `hidden` (the Interactivity API
		// unhides at runtime) — markdown must include them all regardless.
		$md = Markdown::from_html( self::TABS_HTML );
		$this->assertStringContainsString( 'Install the plugin and activate it.', $md );
		$this->assertStringContainsString( 'Open Settings and pick a mode.', $md );
	}

	public function test_tabpanel_without_resolvable_label_keeps_its_content() {
		$md = Markdown::from_html( '<section role="tabpanel" aria-labelledby="nope"><p>Orphan panel body.</p></section>' );
		$this->assertStringContainsString( 'Orphan panel body.', $md );
		$this->assertStringNotContainsString( '**', $md );
	}

	public function test_playlist_track_renders_title_artist_and_length() {
		$html = '<figure class="wp-block-playlist"><ol class="wp-block-playlist__tracklist">'
			. '<li class="wp-block-playlist-track"><button class="wp-block-playlist-track__button">'
			. '<span class="wp-block-playlist-track__content">'
			. '<span class="wp-block-playlist-track__title">Opening Track</span>'
			. '<span class="wp-block-playlist-track__artist">Artist One</span></span>'
			. '<span class="wp-block-playlist-track__length"><span class="screen-reader-text">Duration: </span>3:21</span>'
			. '</button></li></ol></figure>';

		$md = Markdown::from_html( $html );

		$this->assertStringContainsString( '1. **Opening Track** — Artist One (3:21)', $md );
		// The screen-reader prefix must not double up inside the parens.
		$this->assertStringNotContainsString( 'Duration:', $md );
	}

	public function test_playlist_button_without_title_span_falls_back_to_inner_text() {
		$md = Markdown::from_html( '<button class="wp-block-playlist-track__button">Just text</button>' );
		$this->assertStringContainsString( 'Just text', $md );
	}

	public function test_br_inside_pre_becomes_a_newline() {
		$md = Markdown::from_html( '<pre class="wp-block-preformatted">line one<br>line two<br>line three</pre>' );
		$this->assertStringContainsString( "```\nline one\nline two\nline three\n```", $md );
	}

	public function test_pre_with_real_newlines_is_unchanged() {
		$md = Markdown::from_html( "<pre class=\"wp-block-code\"><code>function hello() {\n    return 1;\n}</code></pre>" );
		$this->assertStringContainsString( "```\nfunction hello() {\n    return 1;\n}\n```", $md );
	}

	public function test_table_renders_as_pipe_table_with_header_separator() {
		$html = '<figure class="wp-block-table"><table>'
			. '<thead><tr><th>Plan</th><th>Price</th></tr></thead>'
			. '<tbody><tr><td>Free</td><td>$0</td></tr><tr><td>Pro</td><td>$49</td></tr></tbody>'
			. '</table></figure>';

		$md = Markdown::from_html( $html );

		$this->assertStringContainsString( "| Plan | Price |\n| --- | --- |\n| Free | $0 |\n| Pro | $49 |", $md );
	}

	public function test_headerless_table_promotes_its_first_row() {
		$md = Markdown::from_html( '<table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>' );
		$this->assertStringContainsString( "| a | b |\n| --- | --- |\n| c | d |", $md );
	}

	public function test_table_cells_escape_literal_pipes() {
		$md = Markdown::from_html( '<table><tr><td>a|b</td></tr></table>' );
		$this->assertStringContainsString( 'a\\|b', $md );
	}

	public function test_empty_table_emits_nothing() {
		$this->assertSame( "\n", Markdown::from_html( '<table></table>' ) );
	}
}
