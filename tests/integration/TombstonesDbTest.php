<?php
/**
 * Deletion tombstones against a real WordPress. Found on heera.it 2026-11-28:
 * the change feed listed six posts "deleted" at https://heera.it (the
 * homepage) and one at https://heera.it/__trashed-4. They were posts that
 * were never public — drafts with no slug, whose forced-public permalink
 * falls back to the home URL. A post that never had a public URL must not
 * tell agents a public URL died.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Tombstones;
use WP_UnitTestCase;

final class TombstonesDbTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Tombstones::OPTION );
	}

	/** @return string[] URLs the feed would report as deleted. */
	private function deleted_urls() {
		return array_column( Tombstones::rows(), 'url' );
	}

	public function test_deleting_a_never_published_draft_records_nothing() {
		$id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_name' => '' ) );

		wp_delete_post( $id, true );

		$this->assertSame( array(), $this->deleted_urls(), 'it never had a public URL to lose' );
	}

	public function test_trashing_then_deleting_a_never_published_draft_records_nothing() {
		$id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_title' => '', 'post_name' => '' ) );

		wp_trash_post( $id );
		wp_delete_post( $id, true );

		$this->assertSame( array(), $this->deleted_urls() );
	}

	public function test_a_published_post_trashed_then_deleted_reports_its_real_url() {
		$id  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'oud-for-winter' ) );
		$url = get_permalink( $id );

		wp_trash_post( $id );
		wp_delete_post( $id, true );

		$this->assertSame( array( $url ), $this->deleted_urls() );
	}

	public function test_a_published_post_deleted_outright_reports_its_real_url() {
		$id  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'gone-today' ) );
		$url = get_permalink( $id );

		wp_delete_post( $id, true );

		$this->assertSame( array( $url ), $this->deleted_urls() );
	}

	/**
	 * Rows already stored on a live site by the old code stay in the option
	 * for 90 days — the feed must stop serving them now, not in February.
	 */
	public function test_stored_rows_pointing_at_home_or_a_trashed_slug_are_not_served() {
		$real = home_url( '/a-real-post/' );
		update_option(
			Tombstones::OPTION,
			array(
				1 => array( 'id' => 1, 'type' => 'post', 'url' => untrailingslashit( home_url( '/' ) ), 'ts' => time() ),
				2 => array( 'id' => 2, 'type' => 'post', 'url' => home_url( '/' ), 'ts' => time() ),
				3 => array( 'id' => 3, 'type' => 'post', 'url' => home_url( '/__trashed-4' ), 'ts' => time() ),
				5 => array( 'id' => 5, 'type' => 'post', 'url' => $real, 'ts' => time() ),
			),
			false
		);

		$this->assertSame( array( $real ), $this->deleted_urls() );
	}
}
