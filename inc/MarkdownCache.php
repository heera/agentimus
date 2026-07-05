<?php
/**
 * MarkdownCache — a cheap, universal cache for a post's rendered `.md` body, so serving it
 * to crawlers doesn't re-run `the_content` + the DOM→markdown walk on every request.
 *
 * TIERED so it works on ANY host:
 *   1. Persistent object cache (Redis/Memcached) when present — fastest.
 *   2. A filesystem file under uploads/ — works on shared hosting with no object cache;
 *      a file read is far cheaper than a DB transient AND regeneration, and the OS keeps
 *      hot files in RAM. This is the tier that lets a modest host survive heavy crawling.
 *   3. Regenerate — only on a cold cache, a changed post, or when the tiers are unusable.
 *
 * Self-invalidating: the cache key/file carry a fingerprint of the post's modified time,
 * the plugin version, and a settings "epoch" bumped on every {@see Cache::flush()} (i.e.
 * any content/settings/term change), so a stale body is never served — the fingerprint
 * simply stops matching and the body is rebuilt. Deleted posts' files are removed on
 * `deleted_post`; the whole directory is dropped on uninstall. Turn it off with the
 * `agentimus_markdown_cache` filter.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class MarkdownCache {

	/** Object-cache group + how long an entry lives there (the file tier has no TTL). */
	const GROUP = 'agentimus_md';
	const TTL   = DAY_IN_SECONDS;

	/** Bumped on every cache flush so all cached bodies invalidate at once. */
	const EPOCH_OPTION = 'agentimus_md_cache_epoch';

	/**
	 * Wire invalidation into the existing cache-flush seam.
	 */
	public static function register() {
		// Any content/settings/term change fires this (see Cache::flush()) → invalidate all
		// cached markdown by rolling the epoch (a single option write; the fingerprint does
		// the rest). A post edit also self-invalidates via its modified time.
		add_action( 'agentimus_cache_flushed', array( __CLASS__, 'bump_epoch' ) );
		// Remove a deleted post's file so it can't orphan on disk (its body will never be
		// requested again).
		add_action( 'deleted_post', array( __CLASS__, 'forget' ) );
	}

	/**
	 * The rendered markdown for a published post, from cache when possible. Falls straight
	 * through to {@see Markdown::post()} when caching is off or the post can't be cached.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function post( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! self::enabled() ) {
			return Markdown::post( $post_id );
		}
		$fp = self::fingerprint( $post_id );
		if ( '' === $fp ) {
			return Markdown::post( $post_id ); // Not a cacheable (published) post.
		}
		$key = 'md_' . $post_id . '_' . $fp;

		// 1) Object cache.
		$hit = wp_cache_get( $key, self::GROUP );
		if ( is_string( $hit ) ) {
			return $hit;
		}
		// 2) Filesystem (fingerprint stored as the first line, body after it).
		$file = self::file( $post_id );
		if ( '' !== $file && is_readable( $file ) ) {
			$raw = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors -- reading our own cache file; failure falls through to regeneration.
			if ( is_string( $raw ) ) {
				$nl = strpos( $raw, "\n" );
				if ( false !== $nl && substr( $raw, 0, $nl ) === $fp ) {
					$body = substr( $raw, $nl + 1 );
					wp_cache_set( $key, $body, self::GROUP, self::TTL );
					return $body;
				}
			}
		}
		// 3) Regenerate and populate both tiers.
		$body = Markdown::post( $post_id );
		wp_cache_set( $key, $body, self::GROUP, self::TTL );
		self::write_file( $post_id, $fp, $body );
		return $body;
	}

	/**
	 * Whether caching is on. Filterable so a site can force fresh rendering.
	 *
	 * @return bool
	 */
	private static function enabled() {
		/**
		 * Enable the per-post markdown body cache. Default true.
		 *
		 * @param bool $on Whether to cache rendered `.md` bodies.
		 */
		return (bool) apply_filters( 'agentimus_markdown_cache', true );
	}

	/**
	 * A fingerprint of everything that changes a post's markdown output — its modified
	 * time, the plugin version, and the flush epoch. '' when the post isn't cacheable
	 * (missing, unpublished, or password-protected — same gate as {@see Markdown::post()}).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function fingerprint( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return '';
		}
		return md5( (string) $post->post_modified_gmt . '|' . AGENTIMUS_VERSION . '|' . (string) get_option( self::EPOCH_OPTION, '0' ) );
	}

	/**
	 * The cache directory (created on demand under uploads/), or '' when it can't be used.
	 *
	 * @return string
	 */
	private static function dir() {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) {
			return '';
		}
		$dir = $up['basedir'] . '/agentimus-md-cache';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return ( is_dir( $dir ) && wp_is_writable( $dir ) ) ? $dir : '';
	}

	/**
	 * The cache file path for a post, or '' when the directory isn't usable.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function file( $post_id ) {
		$dir = self::dir();
		return '' === $dir ? '' : $dir . '/' . (int) $post_id . '.md';
	}

	/**
	 * Write the body (fingerprint-prefixed) to the post's cache file.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $fp      Fingerprint.
	 * @param string $body    Rendered markdown.
	 */
	private static function write_file( $post_id, $fp, $body ) {
		$file = self::file( $post_id );
		if ( '' === $file ) {
			return;
		}
		@file_put_contents( $file, $fp . "\n" . $body, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors -- our own cache file; a write failure is non-fatal (next request regenerates).
	}

	/**
	 * Drop a post's cache file (object-cache entries expire by fingerprint/TTL on their own).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function forget( $post_id ) {
		$file = self::file( (int) $post_id );
		if ( '' !== $file && file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors -- our own cache file.
		}
	}

	/**
	 * Roll the epoch so every cached body's fingerprint stops matching — a one-write mass
	 * invalidation. Hooked to `agentimus_cache_flushed`. A unique token (not a timestamp)
	 * so an edit + flush within the same second still changes the fingerprint.
	 */
	public static function bump_epoch() {
		update_option( self::EPOCH_OPTION, uniqid( '', true ), false );
	}

	/**
	 * Remove the whole cache directory (uninstall).
	 */
	public static function purge_all() {
		$dir = self::dir();
		if ( '' === $dir ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*.md' ) as $f ) {
			@unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors -- our own cache files.
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.directory_rmdir, WordPress.PHP.NoSilencedErrors -- our own now-empty cache dir.
	}
}
