<?php
/**
 * Cache build-lock — the mutex that keeps a cold-cache crawler burst from
 * stampeding the expensive generators (/llms-full.txt and the fallback sitemap).
 * Backed by wp_cache_add()'s atomic add-if-absent; exercised here through the
 * harness's faithful object-cache stub.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Cache;
use PHPUnit\Framework\TestCase;

final class CacheLockTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	public function test_first_caller_wins_and_a_concurrent_caller_is_blocked() {
		$this->assertTrue( Cache::acquire_lock( Cache::LLMS_FULL ), 'The first builder should acquire the lock.' );
		$this->assertFalse( Cache::acquire_lock( Cache::LLMS_FULL ), 'A concurrent builder must be blocked while the first holds it.' );
	}

	public function test_releasing_frees_the_lock_for_the_next_caller() {
		$this->assertTrue( Cache::acquire_lock( Cache::LLMS_FULL ) );
		Cache::release_lock( Cache::LLMS_FULL );
		$this->assertTrue( Cache::acquire_lock( Cache::LLMS_FULL ), 'After release the next builder should win.' );
	}

	public function test_locks_are_namespaced_per_key_so_distinct_builds_do_not_collide() {
		$this->assertTrue( Cache::acquire_lock( 'agentimus_sm_1_a' ) );
		// A different generator/page key is an independent lock.
		$this->assertTrue( Cache::acquire_lock( 'agentimus_sm_1_b' ) );
		// ...but re-taking the same key while held is blocked.
		$this->assertFalse( Cache::acquire_lock( 'agentimus_sm_1_a' ) );
	}
}
