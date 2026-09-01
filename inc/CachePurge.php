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
 * ⛔ THAT FIRST SENTENCE IS TRUE OF A PLUGIN AND FALSE OF A CDN, and believing it
 * of both cost this file its biggest hole. A caching plugin does clear the post,
 * the home page and the archives — inside its own store. Cloudflare holds its own
 * copy of every one of those pages, no caching plugin can reach it, and the only
 * code that can is here. So the listing surfaces an edit changes — the posts page
 * (which on a site with a static front page is NOT `/`), the category and tag
 * archives, the feed, the sitemap — are purged too, EDGE-ONLY: see
 * {@see site_listing_urls()} and {@see post_listing_urls()}. Local caches are left
 * to do their own job, because double-purging them is waste.
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
		// A term edit changes its ARCHIVE PAGE — the description printed on it, its
		// title — with no post save anywhere in sight, so none of the hooks above
		// fire. Describing every live term in one sitting left every one of those
		// archives serving stale from the edge for the rest of its TTL.
		add_action( 'edited_term', array( __CLASS__, 'queue_term' ), 20, 3 );
		// On delete, hook BEFORE the row goes: get_term_link() can no longer
		// resolve the term once delete_term fires.
		add_action( 'pre_delete_term', array( __CLASS__, 'queue_term_before_delete' ), 20, 2 );
	}

	/**
	 * Queue the site-wide agent files for purge.
	 */
	public static function queue_site_files() {
		$urls = self::site_urls();
		// The front page's listings change on every content change. Caching
		// plugins purge it themselves — only the edge needs telling, so it
		// joins the set only when the automatic edge purge will actually run.
		if ( Cloudflare\Purge::armed() ) {
			$urls = array_merge( $urls, self::site_listing_urls() );
		}
		self::queue( $urls );
	}

	/**
	 * The site-wide LISTINGS a publish changes — the pages that show a list of
	 * posts rather than a post.
	 *
	 * ⛔ Edge-only, and that is the whole point of this method existing. The
	 * docblock at the top of this file assumed a page cache "purges the pages an
	 * edit touches — the post, the home page, its archives". True of a caching
	 * PLUGIN. False of a CDN, which no caching plugin can reach: the plugin
	 * clears its own copy of /blog and Cloudflare goes on serving the old one
	 * for the rest of its TTL. So these join the set only when the automatic
	 * edge purge will actually run, and never for the local caches that already
	 * handle them.
	 *
	 * ⭐ The front page is not enough. A site whose posts page is a SEPARATE
	 * page (Settings → Reading) has its article index at /blog, and purging '/'
	 * never touches it — the single most visible staleness there is.
	 *
	 * @return string[]
	 */
	public static function site_listing_urls() {
		$urls = array( home_url( '/' ) );

		// The posts page, when the front page is a static one. Only then: with
		// posts on the front page this is the URL already above.
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			$link       = $posts_page ? get_permalink( $posts_page ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}

		// The main feed — a list of posts like any other, cached like any other.
		$feed = get_feed_link();
		if ( is_string( $feed ) && '' !== $feed ) {
			$urls[] = $feed;
		}

		// The sitemap: WE regenerate it on every publish (or core does), and
		// then leave it sitting in the edge cache with yesterday's list.
		return array_merge( $urls, Sitemap::public_urls() );
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
			// serving stale from the edge until its TTL runs out. It joins the
			// set only when the automatic edge purge will actually run.
			if ( Cloudflare\Purge::armed() ) {
				$urls[] = (string) $permalink;
				$urls   = array_merge( $urls, self::post_listing_urls( $post_id ) );
			}
			self::queue( $urls );
		}
	}

	/**
	 * The LISTINGS this one post appears on — its category and tag archives,
	 * and its post type's archive.
	 *
	 * ⛔ Edge-only, for the reason in {@see site_listing_urls()}.
	 *
	 * ⭐ Deliberately UNCAPPED, Heera's call 2026-08-23 with the numbers in
	 * front of him: on his own content the median post carries 4 of these and
	 * the worst carries 11, and a ceiling would only ever silently drop
	 * somebody's archive page — the failure it prevents (a long tail of API
	 * calls) is cheaper than the one it creates (a page that quietly keeps
	 * serving stale). What a purge actually cleared is reported per purge
	 * instead ({@see Cloudflare\Settings::record_purge()}), so a set too big for
	 * the edge to take in one go says so rather than reading as success.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function post_listing_urls( $post_id ) {
		$post_id = (int) $post_id;
		$urls    = array();

		foreach ( get_object_taxonomies( get_post_type( $post_id ), 'objects' ) as $taxonomy ) {
			// Public ones only: a private taxonomy has no archive to go stale.
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post_id, $taxonomy->name );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) && '' !== $link ) {
					$urls[] = $link;
				}
			}
		}

		$archive = get_post_type_archive_link( get_post_type( $post_id ) );
		if ( is_string( $archive ) && '' !== $archive ) {
			$urls[] = $archive;
		}

		return $urls;
	}

	/**
	 * Queue one term's archive page after the term itself was edited.
	 *
	 * ⛔ Edge-only, for the reason in {@see site_listing_urls()} — and doubly so
	 * here: local caching plugins hook these same core seams themselves, while
	 * the copy at the edge has nobody else to clear it. Only the archive URL is
	 * queued: a description edit (the common case, and the one that bit) changes
	 * no other address, and a rename's ghost at the old slug ages out with its
	 * TTL rather than earning sitemap-wide purges on every edit.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function queue_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( ! Cloudflare\Purge::armed() ) {
			return;
		}
		$tax = get_taxonomy( (string) $taxonomy );
		if ( ! $tax || empty( $tax->public ) ) {
			return; // A private taxonomy has no archive to go stale.
		}
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( is_string( $link ) && '' !== $link ) {
			self::queue( array( $link ) );
		}
	}

	/**
	 * `pre_delete_term` adapter: same queue, different argument order — the hook
	 * passes (term_id, taxonomy) with no tt_id between them.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function queue_term_before_delete( $term_id, $taxonomy ) {
		self::queue_term( $term_id, 0, $taxonomy );
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
		// Every adapter here is local except Cloudflare — a real network call.
		// When that call is about to happen, close the visitor's connection
		// first where the server allows it (PHP-FPM): the response is already
		// built at shutdown, so the save returns instantly and the edge purge
		// finishes in the background of this same process — outcome still read,
		// still recorded. Elsewhere the short AUTO_TIMEOUT bounds the wait.
		if ( ! empty( $urls ) && function_exists( 'fastcgi_finish_request' ) && Cloudflare\Purge::armed() ) {
			fastcgi_finish_request();
		}
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
