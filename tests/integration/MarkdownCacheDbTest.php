<?php
/**
 * MarkdownCache against real WordPress: it caches a post's rendered `.md` body (object
 * cache + filesystem tiers), serves it without re-rendering, and invalidates on an edit,
 * an epoch bump (any flush), or the disable filter. None of this is exercisable without
 * real posts, a real object cache, and a real uploads directory.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\MarkdownCache;
use Agentimus\Markdown;

final class MarkdownCacheDbTest extends DbTestCase {

	private function make_post( $content ) {
		return self::factory()->post->create(
			array( 'post_status' => 'publish', 'post_title' => 'Cache Test', 'post_content' => $content )
		);
	}

	/** Change the stored content WITHOUT touching post_modified — so the fingerprint stays
	 *  the same and a cache HIT is proven by it returning the OLD body. */
	private function set_content_raw( $id, $content ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
		clean_post_cache( $id );
	}

	public function tear_down(): void {
		$up = wp_upload_dir();
		if ( empty( $up['error'] ) && ! empty( $up['basedir'] ) ) {
			$dir = $up['basedir'] . '/agentimus-md-cache';
			foreach ( (array) glob( $dir . '/*.md' ) as $f ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_cache_flush();
		delete_option( 'agentimus_md_cache_epoch' );
		parent::tear_down();
	}

	public function test_caches_the_body_and_serves_it_without_regenerating() {
		$id    = $this->make_post( 'ALPHA-marker content here.' );
		$first = MarkdownCache::post( $id );
		$this->assertStringContainsString( 'ALPHA-marker', $first );

		// Content changes underneath, but the fingerprint is unchanged → a HIT must return
		// the OLD body (proving it did NOT re-render).
		$this->set_content_raw( $id, 'BETA-marker content here.' );
		$second = MarkdownCache::post( $id );
		$this->assertStringContainsString( 'ALPHA-marker', $second, 'served from cache, not regenerated' );
		$this->assertStringNotContainsString( 'BETA-marker', $second );
	}

	public function test_serves_from_the_filesystem_tier_when_object_cache_is_cold() {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) || ! wp_is_writable( $up['basedir'] ) ) {
			$this->markTestSkipped( 'uploads dir not writable — filesystem tier unavailable here' );
		}
		$id = $this->make_post( 'FS-marker body.' );
		MarkdownCache::post( $id ); // warms both tiers (writes the file)

		// Drop the object cache; the file tier (fingerprint still matches) must serve it.
		wp_cache_flush();
		$this->set_content_raw( $id, 'CHANGED body.' );
		$body = MarkdownCache::post( $id );
		$this->assertStringContainsString( 'FS-marker', $body, 'the filesystem tier served the cached body' );
	}

	public function test_an_epoch_bump_invalidates_everything() {
		$id = $this->make_post( 'ONE body.' );
		MarkdownCache::post( $id );

		// A settings/content flush bumps the epoch → every fingerprint changes → miss.
		$this->set_content_raw( $id, 'TWO body.' );
		wp_cache_flush();
		MarkdownCache::bump_epoch();
		$body = MarkdownCache::post( $id );
		$this->assertStringContainsString( 'TWO body.', $body, 'epoch bump invalidated the cache' );
	}

	public function test_editing_the_post_invalidates_via_modified_time() {
		$id = $this->make_post( 'FIRST edition.' );
		MarkdownCache::post( $id );

		wp_update_post( array( 'ID' => $id, 'post_content' => 'SECOND edition.' ) ); // changes post_modified + flushes
		$this->assertStringContainsString( 'SECOND edition.', MarkdownCache::post( $id ) );
	}

	public function test_disabled_by_filter_renders_fresh() {
		$id = $this->make_post( 'FRESH body.' );
		add_filter( 'agentimus_markdown_cache', '__return_false' );
		$this->assertSame( Markdown::post( $id ), MarkdownCache::post( $id ) );
	}

	public function test_a_non_published_post_is_never_cached() {
		$id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => 'draft body' ) );
		$this->assertSame( Markdown::post( $id ), MarkdownCache::post( $id ), 'drafts pass straight through (Not found)' );
	}
}
