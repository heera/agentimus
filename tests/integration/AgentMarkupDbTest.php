<?php
/**
 * An agent's write may not add anything that runs in a reader's browser.
 *
 * ⛔ WHY AN ADMINISTRATOR'S LOGIN DOES NOT EXCUSE IT. An administrator holds
 * `unfiltered_html`, so core filters nothing: a script an agent stored — because
 * a page or a comment it read told it to — ran in the owner's session. The owner
 * typing a script into the editor is a decision. An agent doing it is not.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\ContentEditor;
use Agentimus\Abilities\ContentWriter;
use Agentimus\Settings;

final class AgentMarkupDbTest extends DbTestCase {

	const OWNER_SCRIPT = '<script>ownerAnalytics();</script>';
	const OWNER_EMBED  = '<iframe src="https://example.com/embed"></iframe>';

	public function set_up(): void {
		parent::set_up();
		if ( ! method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			$this->markTestSkipped( 'Below WP 6.5 only the in-admin assistant writes; AgentMarkupFallbackTest covers it.' );
		}
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
		$this->assertTrue( current_user_can( 'unfiltered_html' ), 'The whole point: core filters nothing for this user.' );
	}

	private function writer() {
		return new ContentWriter( new Settings() );
	}

	private function owner_post( $body ) {
		return self::factory()->post->create(
			array(
				'post_title'   => 'Owner post',
				'post_content' => $body,
				'post_excerpt' => 'Owner excerpt',
				'post_status'  => 'draft',
			)
		);
	}

	private function assertRefused( $result ) {
		$this->assertWPError( $result, 'That markup was accepted.' );
		$this->assertSame( 'agentimus_unsafe_markup', $result->get_error_code() );
	}

	/** @return array<string,array{string}> */
	public function unsafe_markup() {
		return array(
			'script'             => array( '<p>x</p><script>alert(1)</script>' ),
			'script, any case'   => array( '<ScRiPt SRC="https://evil.test/a.js"></sCrIpT>' ),
			'event handler'      => array( '<img src="a.png" onerror="alert(1)">' ),
			'javascript: link'   => array( '<a href="javascript:alert(1)">x</a>' ),
			'hidden scheme'      => array( '<a href="java&#10;script:alert(1)">x</a>' ),
			'entity scheme'      => array( '<a href="&#106;avascript:alert(1)">x</a>' ),
			'svg'                => array( '<svg onload="alert(1)"></svg>' ),
			'iframe'             => array( '<iframe src="https://evil.test"></iframe>' ),
			'style element'      => array( '<style>body{display:none}</style>' ),
			'meta redirect'      => array( '<meta http-equiv="refresh" content="0;url=https://evil.test">' ),
			'form takeover'      => array( '<form action="javascript:alert(1)"><button>x</button></form>' ),
			'after a comment'    => array( '<!-- x --><script>alert(1)</script>' ),
			'inside a wp:html'   => array( "<!-- wp:html -->\n<script>alert(1)</script>\n<!-- /wp:html -->" ),
			'out of a block attr' => array( '<!-- wp:paragraph {"x":"--><script>alert(1)</script>"} --><p>x</p><!-- /wp:paragraph -->' ),
			'in a block attr'    => array( '<!-- wp:foo {"html":"\u003cscript\u003ealert(1)\u003c/script\u003e"} /-->' ),
			'tag left unfinished' => array( '<p>x</p><img src=x onerror=alert(1) ' ),
			'script left open'   => array( '<p>x</p><script>alert(1)' ),
		);
	}

	/** @dataProvider unsafe_markup */
	public function test_create_refuses_it_in_the_body( $markup ) {
		$before = wp_count_posts()->draft;
		$this->assertRefused( $this->writer()->create( array( 'title' => 'T', 'content' => $markup ) ) );
		$this->assertSame( $before, wp_count_posts()->draft, 'A refused call left a post behind.' );
	}

	/** @dataProvider unsafe_markup */
	public function test_edit_refuses_it_and_leaves_the_body_alone( $markup ) {
		$id = $this->owner_post( '<p>Hello</p>' );
		$this->assertRefused( ( new ContentEditor() )->edit( array( 'post_id' => $id, 'old' => '<p>Hello</p>', 'new' => $markup ) ) );
		$this->assertSame( '<p>Hello</p>', get_post_field( 'post_content', $id ) );
	}

	public function test_every_html_field_is_covered_on_create_and_update() {
		$bad = 'x<script>alert(1)</script>';
		$id  = $this->owner_post( '<p>Hello</p>' );

		foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
			$this->assertRefused( $this->writer()->create( array( 'title' => 'T', $field => $bad ) ) );
			$this->assertRefused( $this->writer()->update( array( 'post_id' => $id, $field => $bad ) ) );
		}

		$post = get_post( $id );
		$this->assertSame( 'Owner post', $post->post_title );
		$this->assertSame( '<p>Hello</p>', $post->post_content );
		$this->assertSame( 'Owner excerpt', $post->post_excerpt );
	}

	public function test_a_refused_update_writes_none_of_its_other_fields() {
		$id = $this->owner_post( '<p>Hello</p>' );
		$this->assertRefused(
			$this->writer()->update(
				array(
					'post_id'     => $id,
					'description' => 'Should not land',
					'excerpt'     => 'Should not land',
					'content'     => '<script>alert(1)</script>',
				)
			)
		);
		$this->assertSame( 'Owner excerpt', get_post_field( 'post_excerpt', $id ) );
		$this->assertSame( '', (string) \Agentimus\Description::explicit( $id ) );
	}

	public function test_a_dry_run_says_the_same_as_the_real_call() {
		$id = $this->owner_post( '<p>Hello</p>' );
		$this->assertRefused(
			( new ContentEditor() )->edit(
				array( 'post_id' => $id, 'old' => 'Hello', 'new' => 'Hello<script>alert(1)</script>', 'dry_run' => true )
			)
		);
	}

	public function test_a_script_cannot_be_assembled_across_two_edits() {
		$id     = $this->owner_post( '<p>Hello</p>' );
		$editor = new ContentEditor();
		$first  = $editor->edit( array( 'post_id' => $id, 'old' => 'Hello', 'new' => 'Hello<scr' ) );
		$this->assertRefused( $first );
		$this->assertSame( '<p>Hello</p>', get_post_field( 'post_content', $id ) );
	}

	public function test_the_refusal_names_what_it_refused() {
		$result = $this->writer()->create( array( 'title' => 'T', 'content' => '<img src="a.png" onerror="alert(1)">' ) );
		$this->assertRefused( $result );
		$this->assertStringContainsString( 'onerror', $result->get_error_message() );
	}

	/**
	 * ⭐ The owner's own markup is not the agent's to lose. A page with their
	 * embed or analytics snippet stays editable, and the snippet stays.
	 */
	public function test_what_the_owner_already_stored_survives_an_edit() {
		$body = '<p>Hello</p>' . self::OWNER_SCRIPT . self::OWNER_EMBED;
		$id   = $this->owner_post( $body );

		$edited = ( new ContentEditor() )->edit( array( 'post_id' => $id, 'old' => 'Hello', 'new' => 'Goodbye' ) );
		$this->assertIsArray( $edited );
		$this->assertSame( '<p>Goodbye</p>' . self::OWNER_SCRIPT . self::OWNER_EMBED, get_post_field( 'post_content', $id ) );

		$updated = $this->writer()->update(
			array(
				'post_id' => $id,
				'content' => self::OWNER_EMBED . '<p>Rewritten</p>' . self::OWNER_SCRIPT,
			)
		);
		$this->assertIsArray( $updated );
		$this->assertSame( self::OWNER_EMBED . '<p>Rewritten</p>' . self::OWNER_SCRIPT, get_post_field( 'post_content', $id ) );
	}

	/** ⛔ What the owner stored is not a pass for anything that looks like it. */
	public function test_the_owners_markup_does_not_cover_a_lookalike() {
		$id = $this->owner_post( '<p>Hello</p>' . self::OWNER_SCRIPT );

		foreach ( array(
			'<p>Hello</p><script>evil();</script>',
			'<p>Hello</p>' . self::OWNER_SCRIPT . self::OWNER_SCRIPT,
			'<p>Hello</p><script src="https://evil.test/a.js">ownerAnalytics();</script>',
		) as $body ) {
			$this->assertRefused( $this->writer()->update( array( 'post_id' => $id, 'content' => $body ) ) );
		}
		$this->assertSame( '<p>Hello</p>' . self::OWNER_SCRIPT, get_post_field( 'post_content', $id ) );
	}

	/** ⛔ Green on nothing proves nothing: real block markup must land byte for byte. */
	public function test_ordinary_block_markup_lands_unchanged() {
		$body = <<<'HTML'
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading" id="a-b">Tips &amp; tricks</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#111"}}} -->
<p style="color:#111">A <a href="https://example.com/?a=1&b=2" target="_blank" rel="noreferrer noopener">link</a>, <a href="/relative">another</a>, <a href="#frag">a jump</a>, <a href="mailto:a@b.test">mail</a>, 2 < 3 and C:\temp\new.</p>
<!-- /wp:paragraph -->

<!-- wp:image {"id":12,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://example.com/a.png" alt="" class="wp-image-12" srcset="https://example.com/a.png 1x" sizes="100vw" width="10" height="10" loading="lazy"/><figcaption class="wp-element-caption">A <em>caption</em></figcaption></figure>
<!-- /wp:image -->

<!-- wp:code -->
<pre class="wp-block-code"><code>if ( a &lt; b ) { echo "&lt;script&gt;"; }</code></pre>
<!-- /wp:code -->

<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>a</th></tr></thead><tbody><tr><td data-align="left">b</td></tr></tbody></table></figure>
<!-- /wp:table -->

<!-- wp:video -->
<figure class="wp-block-video"><video controls src="https://example.com/a.mp4"></video></figure>
<!-- /wp:video -->

<!-- wp:button {"url":"https://example.com"} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com" aria-label="Go">Go</a></div>
<!-- /wp:button -->

[gallery ids="1,2"]
HTML;

		$created = $this->writer()->create( array( 'title' => 'Tips & Tricks: 2 < 3', 'content' => $body, 'excerpt' => 'A <em>short</em> one' ) );
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$this->assertSame( $body, get_post_field( 'post_content', $created['id'] ) );
		$this->assertSame( 'Tips & Tricks: 2 < 3', get_post_field( 'post_title', $created['id'] ) );

		$edited = ( new ContentEditor() )->edit( array( 'post_id' => $created['id'], 'old' => 'a jump', 'new' => 'a <strong>jump</strong>' ) );
		$this->assertIsArray( $edited, is_wp_error( $edited ) ? $edited->get_error_message() : '' );
	}

	/** A body this tool never touches the tags of must not start refusing. */
	public function test_describing_an_image_works_beside_the_owners_script() {
		$id = $this->owner_post( '<img src="https://example.com/a.png">' . self::OWNER_SCRIPT );
		$described = ( new ContentEditor() )->describe_in_content( array( 'post_id' => $id, 'alt' => 'A red bicycle', 'index' => 0 ) );
		$this->assertIsArray( $described, is_wp_error( $described ) ? $described->get_error_message() : '' );
		$this->assertStringContainsString( 'A red bicycle', get_post_field( 'post_content', $id ) );
		$this->assertStringContainsString( self::OWNER_SCRIPT, get_post_field( 'post_content', $id ) );
	}
}
