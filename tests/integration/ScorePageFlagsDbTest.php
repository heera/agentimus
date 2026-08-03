<?php
/**
 * Score::page_flags() — the single-page citability verdict behind the search
 * worklist's cross-flag. Locks the promise that freed that flag from the
 * recency sample: any published article gets a verdict on demand, and the
 * gates the Optimize worklist applies (set-aside, unpublished) hold here too —
 * the flag must never claim what the worklist itself would excuse.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Score;
use Agentimus\Settings;
use WP_UnitTestCase;

final class ScorePageFlagsDbTest extends WP_UnitTestCase {

	public function test_a_thin_published_post_is_flagged_regardless_of_edit_recency() {
		$id = self::factory()->post->create(
			array(
				'post_content' => 'Too short to cite.',
				'post_status'  => 'publish',
			)
		);

		$flags = ( new Score( new Settings() ) )->page_flags( $id );
		$this->assertNotEmpty( $flags, 'a page outside any sample still gets its verdict' );
	}

	public function test_a_set_aside_page_is_never_flagged() {
		$id = self::factory()->post->create(
			array(
				'post_content' => 'Too short to cite.',
				'post_status'  => 'publish',
			)
		);

		$settings = new Settings();
		$all      = $settings->all(); // Partial updates reset unset settings — always merge into all().
		$all['optimize_ignored'] = array( $id );
		$settings->update( $all );

		$this->assertSame( array(), ( new Score( $settings ) )->page_flags( $id ) );
	}

	public function test_an_unpublished_post_is_never_flagged() {
		$id = self::factory()->post->create(
			array(
				'post_content' => 'Too short to cite.',
				'post_status'  => 'draft',
			)
		);

		$this->assertSame( array(), ( new Score( new Settings() ) )->page_flags( $id ) );
	}
}
