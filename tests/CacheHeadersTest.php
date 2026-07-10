<?php
/**
 * CacheHeaders — the one decision behind whether the agent endpoints are edge-cacheable
 * (`public, max-age`) or kept out of shared caches (`no-store`) so every fetch reaches
 * WordPress. Locks the pure header value and the setting + filter that drive it.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\CacheHeaders;
use PHPUnit\Framework\TestCase;

final class CacheHeadersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Hermetic: no stored settings and no filters carried over from another test.
		$GLOBALS['_af_options'] = array();
		$GLOBALS['_af_filters'] = array();
	}

	/* -- value(): the pure Cache-Control decision ------------------------- */

	public function test_value_is_edge_cacheable_when_not_bypassing() {
		$this->assertSame( 'public, max-age=3600', CacheHeaders::value( false, 3600 ) );
		$this->assertSame( 'public, max-age=300', CacheHeaders::value( false, 300 ) );
	}

	public function test_value_clamps_a_negative_max_age_to_zero() {
		$this->assertSame( 'public, max-age=0', CacheHeaders::value( false, -5 ) );
	}

	public function test_value_is_no_store_when_bypassing() {
		$this->assertSame( 'no-store, max-age=0', CacheHeaders::value( true, 3600 ) );
	}

	/* -- bypass(): the setting + the code filter -------------------------- */

	public function test_bypass_is_off_by_default() {
		$this->assertFalse( CacheHeaders::bypass() );
	}

	public function test_bypass_follows_the_setting() {
		$GLOBALS['_af_options']['agentimus_settings'] = array( 'bypass_shared_cache' => true );
		$this->assertTrue( CacheHeaders::bypass() );
	}

	public function test_bypass_can_be_forced_on_by_filter() {
		add_filter( 'agentimus_bypass_shared_cache', static function () { return true; } );
		$this->assertTrue( CacheHeaders::bypass(), 'a code filter can force the endpoints uncacheable even with the setting off' );
	}
}
