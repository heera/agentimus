<?php
/**
 * IndexNow — tell search engines about a change the moment it happens.
 *
 * Search engines discover an edit whenever they next crawl; IndexNow
 * (indexnow.org) is the standard that lets the site say "this URL changed,
 * come look" the moment it happens. One POST to api.indexnow.org reaches every
 * participating engine (Bing, Yandex, Seznam, Naver — and Bing's index is what
 * ChatGPT search and Copilot read today), so a new post can be findable in
 * minutes instead of days.
 *
 * OFF by default, one switch: the plugin's standing promise is "no outbound
 * requests by default", and this is an outbound request — the readme's
 * External-services section names it. What is sent is exactly the changed
 * URL list, the site's key, and nothing else.
 *
 * Mechanics mirror {@see CachePurge}: changes queue during the request,
 * dedupe, and flush as ONE ping at shutdown — after the response has been
 * closed where the server allows it, so publishing never waits on a search
 * engine. Unpublished/trashed posts ping their dying URL too (stripped of the
 * `__trashed` slug suffix, same repair {@see Tombstones} does), so engines
 * recrawl, see the 404, and drop it.
 *
 * The key is minted once, stored locally, and served at /{key}.txt — that
 * file is how engines verify the ping really came from this site.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class IndexNow {

	/** @var string Option: key + last-ping bookkeeping. Autoload off — publish-time reads only. */
	const OPTION = 'agentimus_indexnow';

	/** @var string The shared endpoint — one POST propagates to every participating engine. */
	const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/** @var int Sanity cap on one ping's URL list (the spec allows 10,000; a bulk edit doesn't need it). */
	const MAX_URLS = 100;

	/** @var string Core's alt-text key — it lives on the image, so its pages are found by lookup. */
	const ALT_META = '_wp_attachment_image_alt';

	/** @var array<string,true> URLs collected this request, deduped, flushed on shutdown. */
	private static $queue = array();

	/** @var bool Whether the shutdown flush is already scheduled. */
	private static $scheduled = false;

	/**
	 * Whether the owner turned the feature on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) ( new Settings() )->get( 'indexnow_enabled', false );
	}

	/**
	 * Register the hooks. The status listener stays registered even when the
	 * switch is off — the switch is re-read at queue time, so flipping it
	 * needs no cache flush and no second visit.
	 */
	public static function boot() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		// Writes that change what an engine reads WITHOUT a status transition:
		// a description, search title or cover image saved on its own (the
		// write-description / describe-image / write-search-fields tools, a
		// REST meta write), and a category or tag edited. Same queue, so a
		// full editor save that touches all of them still sends one ping.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_meta' ), 10, 3 );
		}
		add_action( 'edited_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_key_file' ), 0 );
		// The seam for content that lives outside posts (a commerce plugin's
		// custom-table catalog, a forum's virtual pages): any plugin can
		// `do_action( 'agentimus_announce_url', $url )` on its own change
		// events and ride this queue, key and bookkeeping — the mirror of
		// CachePurge's `agentimus_purge_url` on the cache side.
		add_action( 'agentimus_announce_url', array( __CLASS__, 'announce' ) );
	}

	/**
	 * Queue one URL from outside the post lifecycle. Same-host only — this
	 * site's key must never vouch for someone else's address.
	 *
	 * @param string $url Absolute URL on this site whose content changed.
	 */
	public static function announce( $url ) {
		if ( ! self::enabled() ) {
			return;
		}
		$url  = esc_url_raw( (string) $url );
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( '' === $url || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
			return;
		}
		self::$queue[ $url ] = true;
		if ( ! self::$scheduled ) {
			self::$scheduled = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), PHP_INT_MAX );
		}
	}

	/**
	 * Queue the changed URL on any publish, update, or unpublish of
	 * agent-visible content.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       The post.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( ! self::enabled() || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! in_array( (string) $post->post_type, Content::post_types(), true )
			|| ! is_post_type_viewable( $post->post_type )
			|| '' !== (string) $post->post_password ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			// A new post or an edit to a live one — both mean "come read this".
			$url = (string) get_permalink( $post );
		} elseif ( 'publish' === $old_status ) {
			// The URL is dying (trash, draft, private): ping it so engines
			// recrawl, meet the 404, and drop it — the same "tell them it's
			// gone" the change feed does for agents. On trash the slug already
			// carries core's __trashed suffix; repair it to the public URL.
			$url = (string) get_permalink( $post );
			if ( '' !== (string) $post->post_name ) {
				$url = str_replace( $post->post_name, Tombstones::strip_trashed_suffix( $post->post_name ), $url );
			}
		} else {
			return; // Draft-to-draft noise — nothing public changed.
		}
		self::queue( $url );
	}

	/**
	 * The post meta that changes what a search engine reads on the page itself:
	 * the meta description and JSON-LD description, the <title>, the schema's
	 * keywords and media, and the cover image. Anything else (edit locks, view
	 * counters, another plugin's bookkeeping) is not a reason to recrawl.
	 *
	 * @return string[]
	 */
	public static function page_meta_keys() {
		return array(
			Description::META,
			Seo::META_TITLE,
			Topics::META_TOPICS,
			Topics::META_DERIVE,
			MediaContext::META,
			'_thumbnail_id',
		);
	}

	/**
	 * Queue a live page whose meta changed without a status transition. Alt
	 * text belongs to the image, so it queues every live page using that image
	 * as its cover.
	 *
	 * @param int|int[] $meta_id   Unused.
	 * @param int       $object_id Post (or attachment) ID.
	 * @param string    $meta_key  The key that changed.
	 */
	public static function on_meta( $meta_id, $object_id, $meta_key ) {
		if ( self::ALT_META === $meta_key ) {
			if ( ! self::enabled() ) {
				return;
			}
			$ids = get_posts(
				array(
					'post_type'        => Content::post_types(),
					'post_status'      => 'publish',
					'numberposts'      => self::MAX_URLS,
					'fields'           => 'ids',
					'meta_key'         => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- core's own indexed lookup, once per alt-text write.
					'meta_value'       => (string) (int) $object_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
					'suppress_filters' => false,
				)
			);
			foreach ( (array) $ids as $id ) {
				self::queue_live_post( get_post( (int) $id ) );
			}
			return;
		}
		if ( ! in_array( (string) $meta_key, self::page_meta_keys(), true ) || ! self::enabled() ) {
			return;
		}
		self::queue_live_post( get_post( (int) $object_id ) );
	}

	/**
	 * Queue a public category, tag or custom-taxonomy archive after its term
	 * is edited — its name and description are what that page shows.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Unused.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( ! self::enabled() || ! is_taxonomy_viewable( (string) $taxonomy ) ) {
			return;
		}
		$url = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( is_string( $url ) ) {
			self::queue( $url );
		}
	}

	/**
	 * Queue a post's permalink only while it is a live, public, agent-visible
	 * page — the same gate the status listener applies.
	 *
	 * @param \WP_Post|null $post Post.
	 */
	private static function queue_live_post( $post ) {
		if ( ! $post instanceof \WP_Post
			|| 'publish' !== $post->post_status
			|| ! in_array( (string) $post->post_type, Content::post_types(), true )
			|| ! is_post_type_viewable( $post->post_type )
			|| '' !== (string) $post->post_password ) {
			return;
		}
		self::queue( (string) get_permalink( $post ) );
	}

	/**
	 * Add one URL to this request's ping, scheduling the flush once.
	 *
	 * @param string $url Absolute URL.
	 */
	private static function queue( $url ) {
		if ( '' === $url ) {
			return;
		}
		self::$queue[ $url ] = true;
		if ( ! self::$scheduled ) {
			self::$scheduled = true;
			// Late, so it runs after CachePurge's flush (and after the response
			// is closed where the purge path already closed it) — a save never
			// waits on a search engine.
			add_action( 'shutdown', array( __CLASS__, 'flush' ), PHP_INT_MAX );
		}
	}

	/**
	 * Send the request's queued URLs as one ping, and record what happened.
	 */
	public static function flush() {
		$urls            = array_slice( array_keys( self::$queue ), 0, self::MAX_URLS );
		self::$queue     = array();
		self::$scheduled = false;
		if ( empty( $urls ) || ! self::enabled() ) {
			return;
		}

		$key      = self::key();
		$host     = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 5,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode(
					array(
						'host'        => $host,
						'key'         => $key,
						'keyLocation' => home_url( '/' . $key . '.txt' ),
						'urlList'     => array_values( $urls ),
					)
				),
			)
		);

		$state = self::state_raw();
		if ( is_wp_error( $response ) ) {
			$state['last_error'] = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			// The spec answers 200 (done) or 202 (accepted, key check pending).
			$state['last_error'] = ( $code >= 200 && $code < 300 ) ? '' : sprintf(
				/* translators: %d: HTTP status code. */
				__( 'IndexNow answered HTTP %d.', 'agentimus' ),
				$code
			);
		}
		$state['last_at']   = time();
		$state['last_urls'] = count( $urls );
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Serve the verification file at /{key}.txt — how engines confirm the
	 * ping came from this site.
	 */
	public static function serve_key_file() {
		// The cheap shape test runs first: only a /{32 alnum}.txt path is worth
		// the option read (autoload is off), so ordinary page views never pay it.
		$path = Request::path(); // Home-relative, so a subdirectory install answers at its keyLocation.
		if ( ! preg_match( '#^/[a-z0-9]{32}\.txt$#', $path ) || ! self::enabled() ) {
			return;
		}
		$key = self::key();
		if ( $path !== '/' . $key . '.txt' ) {
			return;
		}
		// WordPress already stamped this unknown path 404 in handle_404(),
		// which runs BEFORE template_redirect — serving a body without
		// overriding the status ships the key inside a 404 no engine (or
		// browser) will read. Same belt-and-suspenders as Endpoints::send().
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) {
			$GLOBALS['wp_query']->is_404 = false;
		}
		status_header( 200 );
		if ( function_exists( 'http_response_code' ) ) {
			http_response_code( 200 );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $key; // phpcs:ignore WordPress.Security.EscapeOutput -- plugin-minted [a-z0-9] token, no user input.
		exit;
	}

	/**
	 * The site's IndexNow key — minted once, on first need.
	 *
	 * @return string
	 */
	public static function key() {
		$state = self::state_raw();
		if ( '' === $state['key'] ) {
			$state['key'] = strtolower( wp_generate_password( 32, false, false ) );
			update_option( self::OPTION, $state, false );
		}
		return $state['key'];
	}

	/**
	 * The public address of the verification file — for the Settings hint, so
	 * the owner can open it and see the half an engine checks.
	 *
	 * @return string
	 */
	public static function key_url() {
		return home_url( '/' . self::key() . '.txt' );
	}

	/**
	 * The card-facing state: whether it's on, and how the last ping went.
	 *
	 * @return array{enabled:bool,lastAt:int,lastError:string,lastUrls:int}
	 */
	public static function state() {
		$raw = self::state_raw();
		return array(
			'enabled'   => self::enabled(),
			'lastAt'    => (int) $raw['last_at'],
			'lastError' => (string) $raw['last_error'],
			'lastUrls'  => (int) $raw['last_urls'],
		);
	}

	/**
	 * The stored option, defaults-merged.
	 *
	 * @return array{key:string,last_at:int,last_error:string,last_urls:int}
	 */
	private static function state_raw() {
		$raw = get_option( self::OPTION, array() );
		return array_merge(
			array( 'key' => '', 'last_at' => 0, 'last_error' => '', 'last_urls' => 0 ),
			is_array( $raw ) ? $raw : array()
		);
	}

	/**
	 * Test seam — clear the request-scope queue.
	 */
	public static function reset() {
		self::$queue     = array();
		self::$scheduled = false;
	}
}
