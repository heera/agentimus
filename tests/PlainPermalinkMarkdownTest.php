<?php
/**
 * A site on plain permalinks ("?p=123") has no .md twins: route() only answers
 * a path ending in .md, and "https://site/?p=123.md" is a query string, not a
 * path. Before this, the change feed, the page's Link header and the preview
 * advertised exactly that dead address, and llms.txt and the SKILL.md told
 * agents to "append .md" to URLs it can't be appended to.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Changes;
use Agentimus\Discovery\Envelope;
use Agentimus\Discovery\Registry;
use Agentimus\Discovery\SkillMd;
use Agentimus\LlmsText;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class PlainPermalinkMarkdownTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_registry();
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_registry();
		\_af_reset_options();
	}

	private function shape( string $url ): array {
		$m = new \ReflectionMethod( Changes::class, 'shape' );
		\_af_accessible( $m );
		return $m->invoke(
			null,
			array(
				'id'        => 5,
				'type'      => 'post',
				'title'     => 'Hello',
				'url'       => $url,
				'modified'  => '2026-10-29T00:00:00Z',
				'published' => '2026-10-29T00:00:00Z',
				'action'    => 'updated',
				'rest'      => '',
			),
			true
		);
	}

	public function test_change_feed_names_no_twin_for_a_query_string_permalink() {
		$item = $this->shape( 'https://example.com/?p=5' );
		$this->assertArrayNotHasKey( 'markdown', $item );
		$this->assertArrayNotHasKey( 'alternate', $item['_links'] );
	}

	public function test_change_feed_keeps_the_twin_for_a_pretty_permalink() {
		$item = $this->shape( 'https://example.com/hello/' );
		$this->assertSame( 'https://example.com/hello.md', $item['markdown'] );
		$this->assertSame( 'https://example.com/hello.md', $item['_links']['alternate']['href'] );
	}

	public function test_llms_txt_does_not_promise_dot_md_on_plain_permalinks() {
		update_option( 'permalink_structure', '' );
		$this->assertStringNotContainsString( '.md`', ( new LlmsText( new Settings() ) )->llms_txt() );
	}

	public function test_skill_md_does_not_promise_dot_md_on_plain_permalinks() {
		update_option( 'permalink_structure', '' );
		$settings = new Settings();
		$md       = ( new SkillMd( $settings, new Envelope( $settings, Registry::instance() ) ) )->markdown();
		$this->assertStringNotContainsString( 'append `.md`', $md );
	}
}
