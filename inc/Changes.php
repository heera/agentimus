<?php
/**
 * Change feed — a JSON list of recently added or updated content at
 * /agentimus-changes.json, with a `?since=` filter so an agent re-reads only the
 * delta instead of recrawling the whole site. Routing and response headers live
 * in {@see Endpoints}; this class owns the query, the cached item list, and the
 * pure `since` parsing/filtering.
 *
 * Scope is exactly what /llms.txt and the sitemap expose: the agent-visible post
 * types ({@see Content::post_types()}), published, non-password-protected, newest
 * modified first. Additions and edits come from a live query; removals are merged
 * in from {@see Tombstones} as `action:"deleted"` items so a caching agent can
 * evict URLs that no longer exist.
 *
 * Each item carries a `_links.rest` pointer to its canonical WordPress core
 * `wp/v2` resource (when the type is REST-exposed), so an agent can drill down
 * from the delta into core's own API. We register our surfaces under the
 * `agentimus/v1` namespace and *reference* core at `wp/v2`; the two are distinct.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Changes {

	/** Default cap on the feed window (newest-modified items). Filterable. */
	const DEFAULT_MAX = 200;

	/** Hard ceiling on the window, so a filter can't ask for an unbounded scan. */
	const MAX_CEILING = 2000;

	/**
	 * Seconds between publish and last-modify within which an item is still
	 * "created" rather than "updated" — a freshly published post has the two
	 * timestamps within a tick of each other.
	 */
	const CREATED_WINDOW = 2;

	/**
	 * The full JSON document for a feed request. Reads the cached item list once,
	 * then filters + shapes per request (so unlimited distinct `since` values share
	 * a single cache entry, and settings toggles like markdown reflect immediately).
	 *
	 * @param Settings $settings  Settings store (for the markdown-link toggle).
	 * @param string   $since_raw Raw `since` query value (ISO-8601 or a Unix time).
	 * @return string JSON.
	 */
	public static function document( Settings $settings, $since_raw = '' ) {
		$live = self::items();

		// Merge in deletions (tombstones), excluding any post that is live again so
		// a re-published page never reads as deleted. Both live and deleted rows
		// carry `_ts` (modified time / deletion time), so they order together.
		$live_ids = array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			$live
		);
		$all = array_merge( $live, Tombstones::rows( $live_ids ) );
		usort(
			$all,
			static function ( $a, $b ) {
				return (int) $b['_ts'] <=> (int) $a['_ts'];
			}
		);

		// The window is the newest max_items events (edits + deletions combined).
		$max       = self::max_items();
		$truncated = count( $all ) > $max;
		if ( $truncated ) {
			$all = array_slice( $all, 0, $max );
		}

		$since    = self::parse_since( $since_raw );
		$markdown = $settings->enabled( 'enable_markdown' );

		$out = array();
		foreach ( self::filter_since( $all, $since ) as $raw ) {
			$out[] = self::shape( $raw, $markdown );
		}

		$doc = array(
			'generated' => gmdate( 'c' ),
			'since'     => null === $since ? null : gmdate( 'c', $since ),
			'count'     => count( $out ),
			'truncated' => $truncated,
			'items'     => $out,
		);

		$json = wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '{}' : $json;
	}

	/**
	 * The cached raw item list — newest modified first, capped at max_items(). The
	 * cache holds settings-independent content data only (URLs/timestamps), keyed by
	 * one fixed transient that {@see Cache::flush()} drops on any content change.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function items() {
		$cached = get_transient( Cache::CHANGES );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$items = self::build();
		set_transient( Cache::CHANGES, $items, Cache::TTL );
		return $items;
	}

	/**
	 * Query the agent-visible content, newest modified first, into raw item rows.
	 * Mirrors the sitemap's WP_Query shape (publish, no password) so the feed can
	 * never expose a URL the sitemap/llms.txt wouldn't.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function build() {
		$types = Content::post_types();
		if ( empty( $types ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => self::max_items(),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$modified_ts  = (int) get_post_modified_time( 'U', true, $post );
			$published_ts = (int) get_post_time( 'U', true, $post );
			$items[]      = array(
				'id'        => (int) $post->ID,
				'type'      => (string) $post->post_type,
				'title'     => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES ),
				'url'       => (string) get_permalink( $post ),
				'modified'  => (string) get_post_modified_time( 'c', true, $post ),
				'published' => (string) get_post_time( 'c', true, $post ),
				'action'    => ( $modified_ts - $published_ts ) <= self::CREATED_WINDOW ? 'created' : 'updated',
				'rest'      => self::rest_resource_url( $post ),
				'_ts'       => $modified_ts,
			);
		}
		return $items;
	}

	/**
	 * Shape a raw row into an output item: add the markdown twin (when markdown
	 * delivery is on) and the HAL-style `_links`, and drop the internal `_ts`/`rest`
	 * scratch fields.
	 *
	 * @param array<string,mixed> $raw      Raw row from build().
	 * @param bool                $markdown Whether markdown delivery is enabled.
	 * @return array<string,mixed>
	 */
	private static function shape( array $raw, $markdown ) {
		// A deletion tombstone: just the URL to evict and when it went away — no
		// title/markdown/rest link, since the resource no longer exists.
		if ( isset( $raw['action'] ) && 'deleted' === $raw['action'] ) {
			return array(
				'id'      => $raw['id'],
				'type'    => $raw['type'],
				'url'     => $raw['url'],
				'action'  => 'deleted',
				'deleted' => $raw['deleted'],
				'_links'  => array( 'self' => array( 'href' => $raw['url'] ) ),
			);
		}

		$item = array(
			'id'        => $raw['id'],
			'type'      => $raw['type'],
			'title'     => $raw['title'],
			'url'       => $raw['url'],
			'modified'  => $raw['modified'],
			'published' => $raw['published'],
			'action'    => $raw['action'],
		);

		$md_url = ( $markdown && '' !== $raw['url'] ) ? untrailingslashit( $raw['url'] ) . '.md' : '';
		if ( '' !== $md_url ) {
			$item['markdown'] = $md_url;
		}

		$links = array( 'self' => array( 'href' => $raw['url'] ) );
		if ( '' !== $md_url ) {
			$links['alternate'] = array(
				'href' => $md_url,
				'type' => 'text/markdown',
			);
		}
		if ( '' !== $raw['rest'] ) {
			// The canonical core wp/v2 resource, so an agent can drill down.
			$links['rest'] = array( 'href' => $raw['rest'] );
		}
		$item['_links'] = $links;

		return $item;
	}

	/**
	 * The WordPress core `wp/v2` REST URL for a post, or '' when its type isn't
	 * REST-exposed (no `show_in_rest`). Honours a custom `rest_base` /
	 * `rest_namespace` so a CPT that publishes under its own namespace links there.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function rest_resource_url( \WP_Post $post ) {
		$obj = get_post_type_object( $post->post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return '';
		}
		$base      = ! empty( $obj->rest_base ) ? $obj->rest_base : $post->post_type;
		$namespace = ! empty( $obj->rest_namespace ) ? $obj->rest_namespace : 'wp/v2';
		return esc_url_raw( rest_url( trailingslashit( $namespace ) . $base . '/' . (int) $post->ID ) );
	}

	/**
	 * Parse a `since` value into a Unix timestamp, or null when absent/unparseable.
	 * Accepts a bare Unix timestamp or any ISO-8601 / strtotime-parseable string.
	 * Pure — no WordPress dependency, so it unit-tests directly.
	 *
	 * @param string $raw Raw value.
	 * @return int|null
	 */
	private static function parse_since( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( ctype_digit( $raw ) ) {
			return (int) $raw;
		}
		$ts = strtotime( $raw );
		return false === $ts ? null : (int) $ts;
	}

	/**
	 * Keep only items modified strictly after `$since` (by the internal `_ts`
	 * epoch). A null `$since` returns the list unchanged. Pure.
	 *
	 * @param array<int,array<string,mixed>> $items Raw rows (each with `_ts`).
	 * @param int|null                       $since Lower bound (exclusive), or null.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filter_since( array $items, $since ) {
		if ( null === $since ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $item ) {
			if ( isset( $item['_ts'] ) && (int) $item['_ts'] > $since ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * The feed window size — how many newest-modified items the feed can hold.
	 *
	 * @return int
	 */
	private static function max_items() {
		/**
		 * Filter the change-feed window (max items). Clamped to [1, MAX_CEILING].
		 *
		 * @param int $max Default DEFAULT_MAX.
		 */
		$max = (int) apply_filters( 'agentimus_changes_max', self::DEFAULT_MAX );
		return max( 1, min( self::MAX_CEILING, $max ) );
	}
}
