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

	/* -- players ----------------------------------------------------------- */

	/** Trimmed oEmbed render of a core/embed YouTube block. */
	private const YOUTUBE_HTML = '<figure class="wp-block-embed is-provider-youtube">'
		. '<div class="wp-block-embed__wrapper">'
		. '<iframe title="What is llms.txt?" width="500" height="281" src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed" frameborder="0" allowfullscreen></iframe>'
		. '</div></figure>';

	public function test_a_video_embed_is_named_and_linked_instead_of_vanishing() {
		$md = Markdown::from_html( self::YOUTUBE_HTML );

		// The regression this exists for: an <iframe> holds no text, so the whole
		// figure used to convert to an empty string — a video post read as blank.
		$this->assertNotSame( "\n", $md, 'A video must leave a trace in the plain-text edition.' );
		$this->assertStringContainsString( 'Video: [What is llms.txt?](https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed)', $md );
	}

	public function test_an_unresolved_embed_block_is_still_named_a_video() {
		// Caught on a live post. WordPress only swaps an embed's URL for a player
		// inside the loop, and the .md twin is generated OFF the loop — so the
		// figure arrives here as a bare URL, which used to be dumped into the
		// document as if it were body text.
		$md = Markdown::from_html(
			'<figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">'
			. 'https://www.youtube.com/watch?v=TOfxwCbK1Ro' . '</div></figure>'
		);

		$this->assertStringContainsString( 'Video: [https://www.youtube.com/watch?v=TOfxwCbK1Ro](https://www.youtube.com/watch?v=TOfxwCbK1Ro)', $md );
	}

	/** Trimmed TikTok oEmbed: a blockquote, no iframe, URL only on `cite`. */
	private const TIKTOK_HTML = '<figure class="wp-block-embed is-type-video is-provider-tiktok">'
		. '<div class="wp-block-embed__wrapper">'
		. '<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@scout2015/video/6718335390845095173">'
		. '<section><a href="https://www.tiktok.com/@scout2015?refer=embed">@scout2015</a>'
		. '<p>Scramble up ur name and I will try to guess it</p></section></blockquote>'
		. '</div></figure>';

	public function test_a_script_rendered_provider_is_named_and_keeps_its_caption() {
		// TikTok and Instagram never render an iframe server-side — they render a
		// blockquote and put the video's address on `cite`, where no text node ever
		// sees it. The video went uncounted in the twin until this.
		$md = Markdown::from_html( self::TIKTOK_HTML );

		$this->assertStringContainsString( 'Video: [https://www.tiktok.com/@scout2015/video/6718335390845095173]', $md );
		// The caption is real text an assistant can quote — naming the video must
		// not cost us the words the provider DID render.
		$this->assertStringContainsString( 'Scramble up ur name', $md );
	}

	public function test_a_figure_with_a_real_player_is_not_named_twice() {
		$md = Markdown::from_html( self::YOUTUBE_HTML );

		$this->assertSame( 1, substr_count( $md, 'Video:' ) );
	}

	public function test_a_caption_survives_but_the_bare_url_is_not_repeated() {
		// An unresolved embed carries its address as body text, and the Video: line
		// above already states it — but the author's caption is real prose about the
		// media and must come through.
		$md = Markdown::from_html(
			'<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">https://youtu.be/abc12345</div>'
			. '<figcaption>A short walkthrough of content negotiation</figcaption></figure>'
		);

		$this->assertStringContainsString( 'A short walkthrough of content negotiation', $md );
		$this->assertSame( 1, substr_count( $md, 'https://youtu.be/abc12345](' ), 'The link is written once…' );
		$this->assertStringNotContainsString( "\n\nhttps://youtu.be/abc12345\n", $md, '…and never again as loose body text.' );
	}

	public function test_an_unresolved_embed_of_something_else_is_left_as_it_was() {
		$md = Markdown::from_html( '<figure class="wp-block-embed is-type-rich"><div class="wp-block-embed__wrapper">https://example.com/status/123</div></figure>' );

		$this->assertStringNotContainsString( 'Video:', $md );
		$this->assertStringContainsString( 'https://example.com/status/123', $md );
	}

	public function test_a_self_hosted_player_uses_its_source_url() {
		$md = Markdown::from_html( '<video controls><source src="https://example.com/talk.mp4" type="video/mp4"></video>' );
		$this->assertStringContainsString( 'Video: [https://example.com/talk.mp4](https://example.com/talk.mp4)', $md );

		$audio = Markdown::from_html( '<audio src="https://example.com/ep1.mp3" title="Episode 1"></audio>' );
		$this->assertStringContainsString( 'Audio: [Episode 1](https://example.com/ep1.mp3)', $audio );
	}

	public function test_a_non_video_iframe_is_never_called_a_video() {
		// Calling a map "Video" would be a plain falsehood in a document whose
		// promise is that it matches the page.
		$md = Markdown::from_html( '<iframe title="Our office" src="https://www.google.com/maps/embed?pb=123"></iframe>' );
		$this->assertStringContainsString( 'Embedded: [Our office]', $md );
		$this->assertStringNotContainsString( 'Video:', $md );
	}

	public function test_a_title_with_brackets_cannot_break_the_link() {
		$md = Markdown::from_html( '<figure class="wp-block-embed is-type-video"><iframe title="Talk [2026]" src="https://vimeo.com/76979871"></iframe></figure>' );
		$this->assertStringContainsString( 'Video: [Talk 2026](https://vimeo.com/76979871)', $md );
	}

	public function test_a_hand_written_iframe_is_named_neutrally() {
		// The checks no longer grade markup somebody wrote by hand, so this document
		// must not announce a video the rest of the plugin says is not there — however
		// familiar the host looks. It still says something is embedded, and where.
		$md = Markdown::from_html( '<iframe title="Some talk" src="https://www.youtube.com/embed/abc123"></iframe>' );

		$this->assertStringContainsString( 'Embedded: [Some talk](https://www.youtube.com/embed/abc123)', $md );
		$this->assertStringNotContainsString( 'Video:', $md );
	}

	public function test_a_player_with_nothing_to_point_at_says_nothing() {
		$this->assertSame( "\n", Markdown::from_html( '<video controls></video>' ) );
	}

	public function test_a_transcript_beside_the_video_survives_into_markdown() {
		// The whole point of the on-page transcript: these are the words an
		// assistant can actually quote.
		$md = Markdown::from_html(
			self::YOUTUBE_HTML . '<details><summary>Transcript</summary><p>So today I want to talk about llms.txt.</p></details>'
		);
		$this->assertStringContainsString( 'So today I want to talk about llms.txt.', $md );
	}
}
