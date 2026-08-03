<?php
/**
 * Auto-purge the agent-facing files from a page cache on a content change.
 *
 * A page cache (a caching plugin, Nginx FastCGI, Varnish, a CDN) purges the pages an
 * edit touches — the post, the home page, its archives — but it has no idea Agentimus's
 * machine files even exist. So after you publish, a cache keeps serving a *stale*
 * `llms.txt`, a stale change feed, and a stale `.md` twin of the very post you edited,
 * until its own TTL runs out. Agents get old content in the meantime.
 *
 * This closes that gap: whenever content or identity changes, ask every page cache we can
 * detect to drop Agentimus's files — the site-wide ones (`llms.txt`, `llms-full.txt`,
 * `robots.txt`, the change feed, the `.well-known` docs) and the edited post's own `.md`
 * twin (a URL no generic cache plugin knows to purge). Every adapter is feature-detected,
 * so only a cache that's actually installed is called, and a generic `agentimus_purge_url`
 * action lets any other cache hook in. On by default; a filter or the Settings switch
 * turns it off. It does NOT fix the activity-log under-count (that needs the fetch to reach
 * WordPress — see {@see CacheHeaders}); this is purely about freshness.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class CachePurge {

	/** @var array<string,true> URLs collected this request, deduped, flushed on shutdown. */
	private static $queue = array();

	/** @var bool Whether the shutdown flush is already scheduled. */
	private static $scheduled = false;

	/**
	 * Whether auto-purge is on. Setting (default true), overridable by code.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$on = (bool) ( new Settings() )->get( 'purge_cache_on_change', true );
		/**
		 * Turn the automatic cache purge of the agent files on/off.
		 *
		 * @param bool $on Whether to purge on a content change.
		 */
		return (bool) apply_filters( 'agentimus_purge_on_change', $on );
	}

	/**
	 * Register the content-change hooks. No-op when disabled.
	 */
	public static function boot() {
		if ( ! self::enabled() ) {
			return;
		}
		// The site-wide files change on ANY content/identity change — reuse the exact seam
		// the internal caches fire from, so the two never disagree about "content changed".
		add_action( 'agentimus_cache_flushed', array( __CLASS__, 'queue_site_files' ) );
		// The edited/removed post's own .md twin — a URL generic cache plugins never purge.
		add_action( 'save_post', array( __CLASS__, 'queue_post' ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'queue_post' ), 20, 1 );
	}

	/**
	 * Queue the site-wide agent files for purge.
	 */
	public static function queue_site_files() {
		$urls = self::site_urls();
		// The front page's listings change on every content change. Caching
		// plugins purge it themselves — only the edge needs telling, so it
		// joins the set only when the Cloudflare source is connected.
		if ( Cloudflare\Purge::available() ) {
			$urls[] = home_url( '/' );
		}
		self::queue( $urls );
	}

	/**
	 * Queue one post's `.md` twin, skipping revisions/autosaves and non-public types.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function queue_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! is_post_type_viewable( get_post_type( $post_id ) ) ) {
			return; // Nav-menu items, non-public types, … have no public .md.
		}
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$urls = array( untrailingslashit( $permalink ) . '.md' );
			// The edited page itself: every caching plugin already purges it,
			// but none of them can reach Cloudflare — an edited post keeps
			// serving stale from the edge until its TTL runs out. When the
			// edge source is connected, the page joins the set.
			if ( Cloudflare\Purge::available() ) {
				$urls[] = (string) $permalink;
			}
			self::queue( $urls );
		}
	}

	/**
	 * The site-wide agent files, as absolute URLs.
	 *
	 * @return string[]
	 */
	public static function site_urls() {
		$urls = array(
			home_url( '/llms.txt' ),
			home_url( '/llms-full.txt' ),
			home_url( '/robots.txt' ),
			home_url( '/agentimus-changes.json' ),
		);
		foreach ( array( 'discovery.json', 'agent-card.json', 'agent.json', 'mcp.json', 'openapi.json', 'tdmrep.json', 'security.txt' ) as $doc ) {
			$urls[] = home_url( '/.well-known/' . $doc );
		}
		return $urls;
	}

	/**
	 * Add URLs to the request's purge set and arrange a single flush at shutdown, so a bulk
	 * edit of many posts purges `llms.txt` once, not once per post.
	 *
	 * @param string[] $urls URLs to purge.
	 */
	private static function queue( array $urls ) {
		foreach ( $urls as $url ) {
			if ( '' !== (string) $url ) {
				self::$queue[ (string) $url ] = true;
			}
		}
		if ( ! self::$scheduled && ! empty( self::$queue ) ) {
			self::$scheduled = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ) );
		}
	}

	/**
	 * Purge everything queued this request.
	 */
	public static function flush_queue() {
		$urls        = array_keys( self::$queue );
		self::$queue = array();
		self::purge( $urls );
	}

	/**
	 * The final, deduped URL set to purge — empties dropped, duplicates collapsed, and the
	 * `agentimus_purge_urls` filter applied. Pure (given the filter); unit-tested.
	 *
	 * @param string[] $urls Candidate URLs.
	 * @return string[]
	 */
	public static function urls_to_purge( array $urls ) {
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		/**
		 * The exact URLs Agentimus asks the page cache to drop on a content change.
		 *
		 * @param string[] $urls Absolute URLs.
		 */
		return array_values( array_filter( (array) apply_filters( 'agentimus_purge_urls', $urls ) ) );
	}

	/**
	 * Ask every detected page cache to drop these URLs. Each adapter is guarded so only an
	 * installed cache is called; the `agentimus_purge_url` action lets any other cache in.
	 *
	 * @param string[] $urls URLs to purge.
	 */
	public static function purge( array $urls ) {
		$urls = self::urls_to_purge( $urls );
		if ( empty( $urls ) ) {
			return;
		}

		// WP Rocket — takes the whole list at once.
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls );
		}

		// Cloudflare — also the whole list at once (batched by the API's own
		// limit). Quiet no-op unless the edge source is connected; a refusal
		// (e.g. a token without the purge permission) is recorded on the
		// connection and shown in the edge panel's rail, never fatal here.
		Cloudflare\Purge::purge_urls( $urls );

		foreach ( $urls as $url ) {
			// Nginx Helper — the global purger exposes a per-URL purge.
			if ( isset( $GLOBALS['nginx_purger'] ) && is_object( $GLOBALS['nginx_purger'] ) && method_exists( $GLOBALS['nginx_purger'], 'purge_url' ) ) {
				$GLOBALS['nginx_purger']->purge_url( $url, false );
			}
			// W3 Total Cache.
			if ( function_exists( 'w3tc_flush_url' ) ) {
				w3tc_flush_url( $url );
			}
			// WP Super Cache (1.7+).
			if ( function_exists( 'wpsc_delete_url_cache' ) ) {
				wpsc_delete_url_cache( $url );
			}
			// Cache Enabler.
			if ( is_callable( array( 'Cache_Enabler', 'clear_page_cache_by_url' ) ) ) {
				\Cache_Enabler::clear_page_cache_by_url( $url );
			}
			// LiteSpeed Cache, and any other cache / custom integration. The hook name is
			// LiteSpeed's, not ours — prefixing it with `agentimus_` (as the naming sniff wants)
			// would mean LiteSpeed never hears it, which is the entire point of firing it.
			do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook we deliberately fire.
			/**
			 * Purge a single agent-file URL from a cache Agentimus doesn't natively support.
			 *
			 * @param string $url Absolute URL to purge.
			 */
			do_action( 'agentimus_purge_url', $url );
		}

		/**
		 * Fires after Agentimus has asked the caches to drop this content change's URLs.
		 *
		 * @param string[] $urls The purged URLs.
		 */
		do_action( 'agentimus_purged', $urls );
	}
}
