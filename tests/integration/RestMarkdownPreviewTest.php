<?php
/**
 * The Markdown preview endpoint through the real REST controller. Proves the two
 * target shapes an agent receives:
 *
 *  - the SITE target (post = 0) previews the index Markdown Agentimus serves at the
 *    home URL and /llms.txt — byte-identical to what index_markdown() ships, so the
 *    "Agent preview" panel shows exactly what an agent gets at the home URL (not an
 *    empty "pick a page" placeholder);
 *  - a POST target previews that post's OWN Markdown twin, at its `.md` URL.
 *
 * Guards the two branches from crossing (a regression that fed the index to posts,
 * or emptied the site target again).
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\LlmsText;
use Agentimus\Settings;

final class RestMarkdownPreviewTest extends RestTestCase {

	private function preview( $post_id ) {
		wp_set_current_user( $this->admin );
		$req = new \WP_REST_Request( 'GET', '/agentimus/v1/preview/markdown' );
		$req->set_param( 'post', $post_id );
		$resp = rest_do_request( $req );
		$this->assertSame( 200, $resp->get_status() );
		return (array) $resp->get_data();
	}

	public function test_site_target_previews_the_index_markdown() {
		$data = $this->preview( 0 );

		$this->assertSame( 'site', $data['target']['type'] );
		$this->assertTrue( $data['postIncluded'], 'the site index IS served, so it counts as included' );
		$this->assertNotSame( '', $data['markdown'], 'the site target now has Markdown (the index), not an empty placeholder' );
		$this->assertSame( 0, strpos( $data['markdown'], '# ' ), 'the index opens with the site heading' );

		// The preview must be exactly what ships — not a re-rendered approximation.
		$expected = ( new LlmsText( new Settings() ) )->index_markdown();
		$this->assertSame( $expected, $data['markdown'], 'preview parity with index_markdown()' );

		// The advertised live URL is the home markdown route, and it is public.
		$this->assertSame( home_url( '/index.md' ), $data['mdUrl'] );
		$this->assertTrue( $data['livePublic'] );
	}

	public function test_post_target_previews_its_own_markdown_not_the_index() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'A Real Post',
				'post_content' => '<p>Body of a real post.</p>',
				'post_status'  => 'publish',
			)
		);

		$data = $this->preview( $post_id );

		$this->assertSame( 'post', $data['target']['type'] );
		$this->assertTrue( $data['postIncluded'] );
		$this->assertNotSame( '', $data['markdown'] );

		// The two branches must not cross: a post gets its OWN twin, not the site index.
		$index = ( new LlmsText( new Settings() ) )->index_markdown();
		$this->assertNotSame( $index, $data['markdown'], 'a post target must not serve the site index' );
		$this->assertNotSame( home_url( '/index.md' ), $data['mdUrl'], 'a post .md URL is not the home index' );
		$this->assertStringEndsWith( '.md', $data['mdUrl'] );
	}
}
