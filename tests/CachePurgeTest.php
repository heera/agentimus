<?php
/**
 * CachePurge — the auto-purge of the agent files on a content change. Locks the enable
 * decision, the site-wide URL set, and the deduped/filterable purge list. The cache-plugin
 * adapters and the content-change hooks are exercised live (they need real plugins / WP).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\CachePurge;
use PHPUnit\Framework\TestCase;

final class CachePurgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_af_options'] = array();
		$GLOBALS['_af_filters'] = array();
	}

	/* -- enabled(): setting + filter -------------------------------------- */

	public function test_enabled_by_default() {
		$this->assertTrue( CachePurge::enabled() );
	}

	public function test_a_filter_can_turn_it_off() {
		add_filter( 'agentimus_purge_on_change', static function () { return false; } );
		$this->assertFalse( CachePurge::enabled() );
	}

	/* -- site_urls(): the files a cache plugin doesn't know to refresh ----- */

	public function test_site_urls_cover_the_agent_files() {
		$urls = CachePurge::site_urls();
		foreach ( array(
			'https://example.test/llms.txt',
			'https://example.test/llms-full.txt',
			'https://example.test/robots.txt',
			'https://example.test/agentimus-changes.json',
			'https://example.test/.well-known/discovery.json',
			'https://example.test/.well-known/mcp.json',
		) as $expected ) {
			$this->assertContains( $expected, $urls );
		}
	}

	/* -- urls_to_purge(): dedupe, drop empties, filter -------------------- */

	public function test_urls_to_purge_dedupes_and_drops_empties() {
		$this->assertSame( array( '/a', '/b' ), CachePurge::urls_to_purge( array( '/a', '/a', '', '/b' ) ) );
	}

	public function test_purge_urls_filter_can_extend_the_set() {
		add_filter( 'agentimus_purge_urls', static function ( $urls ) {
			$urls[] = '/extra';
			return $urls;
		} );
		$this->assertSame( array( '/a', '/extra' ), CachePurge::urls_to_purge( array( '/a' ) ) );
	}
}
