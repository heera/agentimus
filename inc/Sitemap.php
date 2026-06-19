<?php
/**
 * Sitemap detection — the single source of truth for *which* XML sitemap this
 * site serves and *who* owns it. Agentimus never generates a competing sitemap;
 * it detects the existing one (WordPress core or a major SEO plugin) and links
 * it from robots.txt, llms.txt and the discovery document.
 *
 * Detection mirrors Schema::seo_plugin_active() so the two stay in lockstep.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Sitemap {

	/** Where the Agentimus-generated fallback sitemap is served. */
	const PATH = '/agentimus-sitemap.xml';

	/**
	 * Resolve the live sitemap, in priority order:
	 *   1. WordPress core sitemaps (on by default since 5.5).
	 *   2. A known SEO plugin (which would have disabled core).
	 *   3. Agentimus's own generator — but ONLY if the owner opted in AND neither
	 *      of the above provides one, so we never emit a duplicate.
	 *
	 * When nothing provides one, `url` is an empty string — callers must treat
	 * that as "no sitemap to advertise" rather than guessing a URL that may 404.
	 *
	 * @return array{url:string,source:string,label:string}
	 */
	public static function detect() {
		// 1. WordPress core sitemaps.
		if ( function_exists( 'wp_sitemaps_get_server' ) ) {
			$server = wp_sitemaps_get_server();
			if ( $server && $server->sitemaps_enabled() ) {
				return array(
					'url'    => home_url( '/wp-sitemap.xml' ),
					'source' => 'core',
					'label'  => __( 'WordPress core', 'agentimus' ),
				);
			}
		}

		// 2. A known SEO plugin owns the sitemap — link its real location.
		foreach ( self::providers() as $p ) {
			if ( $p['active'] ) {
				return array(
					'url'    => home_url( $p['path'] ),
					'source' => $p['source'],
					'label'  => $p['label'],
				);
			}
		}

		// 3. Agentimus's opt-in fallback — fills the gap when nobody else does.
		if ( ( new Settings() )->enabled( 'enable_sitemap' ) ) {
			return array(
				'url'    => home_url( self::PATH ),
				'source' => 'agentimus',
				'label'  => __( 'Agentimus', 'agentimus' ),
			);
		}

		// 4. Nothing detected.
		$none = array(
			'url'    => '',
			'source' => '',
			'label'  => '',
		);

		/**
		 * Filter the detected sitemap (lets an add-on declare one Agentimus can't
		 * see, e.g. a less common plugin or a hand-rolled file).
		 *
		 * @param array $none The "no sitemap" result.
		 */
		return apply_filters( 'agentimus_sitemap', $none );
	}

	/* ---------------------------------------------------------------------- *
	 *  Fallback generator — a real sitemap *index* plus paginated sub-sitemaps,
	 *  the same shape core and the SEO plugins ship, so big sites are covered
	 *  too. The index lives at PATH; each sub-sitemap is one content type, one
	 *  page of up to per_page() URLs, newest-modified first.
	 * ---------------------------------------------------------------------- */

	/**
	 * Render the document for a sitemap request path, or '' if the path is not a
	 * valid Agentimus sitemap (so the caller can 404 it). Results are cached for
	 * an hour, namespaced by a generation token that Cache::flush() resets on
	 * any content change.
	 *
	 * @param string $path Request path, e.g. '/agentimus-sitemap.xml' or
	 *                     '/agentimus-sitemap-post-2.xml'.
	 * @return string
	 */
	public static function body( $path ) {
		if ( self::PATH === $path ) {
			return self::cached( $path, array( __CLASS__, 'index_xml' ) );
		}

		// /agentimus-sitemap-{type}-{page}.xml — {type} may contain hyphens, so
		// anchor on the trailing -{digits}.xml and treat the rest as the type.
		if ( preg_match( '#^/agentimus-sitemap-(.+)-(\d+)\.xml$#', $path, $m ) ) {
			$type = $m[1];
			$page = (int) $m[2];
			if ( $page < 1 || ! in_array( $type, Content::post_types(), true ) ) {
				return '';
			}
			if ( $page > self::page_count( $type ) ) {
				return '';
			}
			return self::cached(
				$path,
				static function () use ( $type, $page ) {
					return self::sub_xml( $type, $page );
				}
			);
		}

		return '';
	}

	/**
	 * The sitemap index: one <sitemap> entry per content-type page, each with the
	 * newest <lastmod> in that page so crawlers know which sub-sitemaps changed.
	 *
	 * @return string
	 */
	private static function index_xml() {
		$entries = '';
		foreach ( Content::post_types() as $type ) {
			$pages = self::page_count( $type );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$loc     = home_url( self::sub_path( $type, $page ) );
				$lastmod = self::page_lastmod( $type, $page );
				$entries .= "\t<sitemap>\n\t\t<loc>" . esc_url( $loc ) . "</loc>\n";
				if ( '' !== $lastmod ) {
					$entries .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
				}
				$entries .= "\t</sitemap>\n";
			}
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		$xml .= $entries;
		$xml .= '</sitemapindex>' . "\n";
		return $xml;
	}

	/**
	 * One sub-sitemap: a urlset for a single content type and page. The very
	 * first sub-sitemap also carries the home page URL (deduped against a static
	 * front page), mirroring how WordPress core seeds the front page.
	 *
	 * @param string $type Post type.
	 * @param int    $page 1-based page.
	 * @return string
	 */
	private static function sub_xml( $type, $page ) {
		$query = new \WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'posts_per_page'         => self::per_page(),
				'paged'                  => $page,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$home  = untrailingslashit( home_url( '/' ) );
		$nodes = '';

		if ( self::is_first_page( $type, $page ) ) {
			$nodes .= self::url_node( home_url( '/' ), self::home_lastmod() );
		}

		foreach ( $query->posts as $post ) {
			$loc = (string) get_permalink( $post );
			// A static front page resolves to the home URL — emitted once, above.
			if ( untrailingslashit( $loc ) === $home ) {
				continue;
			}
			$nodes .= self::url_node( $loc, (string) get_post_modified_time( 'c', true, $post ) );
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		$xml .= $nodes;
		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	/**
	 * One <url> entry.
	 *
	 * @param string $loc     Absolute URL.
	 * @param string $lastmod ISO-8601 timestamp, or '' to omit.
	 * @return string
	 */
	private static function url_node( $loc, $lastmod ) {
		$node = "\t<url>\n\t\t<loc>" . esc_url( $loc ) . "</loc>\n";
		if ( '' !== $lastmod ) {
			$node .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
		}
		return $node . "\t</url>\n";
	}

	/** Sub-sitemap path for a type + page. */
	private static function sub_path( $type, $page ) {
		return '/agentimus-sitemap-' . $type . '-' . (int) $page . '.xml';
	}

	/** Max URLs per sub-sitemap (protocol ceiling is 50k). */
	private static function per_page() {
		$n = (int) apply_filters( 'agentimus_sitemap_max_urls', 2000 );
		return max( 1, min( 50000, $n ) );
	}

	/** Published count for a type. */
	private static function published_count( $type ) {
		$counts = wp_count_posts( $type );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/** Number of sub-sitemap pages a type needs (0 if it has no content). */
	private static function page_count( $type ) {
		return (int) ceil( self::published_count( $type ) / self::per_page() );
	}

	/** Whether this is the globally-first sub-sitemap (gets the home page URL). */
	private static function is_first_page( $type, $page ) {
		if ( 1 !== (int) $page ) {
			return false;
		}
		foreach ( Content::post_types() as $candidate ) {
			if ( self::published_count( $candidate ) > 0 ) {
				return $candidate === $type;
			}
		}
		return false;
	}

	/** Newest <lastmod> within a type's page (page is newest-first). */
	private static function page_lastmod( $type, $page ) {
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'publish',
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'numberposts'      => 1,
				'offset'           => ( (int) $page - 1 ) * self::per_page(),
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		return $ids ? (string) get_post_modified_time( 'c', true, $ids[0] ) : '';
	}

	/** lastmod for the home URL: the front page's, else the newest content's. */
	private static function home_lastmod() {
		$front = (int) get_option( 'page_on_front' );
		if ( $front && 'publish' === get_post_status( $front ) ) {
			return (string) get_post_modified_time( 'c', true, $front );
		}
		$ids = get_posts(
			array(
				'post_type'        => Content::post_types(),
				'post_status'      => 'publish',
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		return $ids ? (string) get_post_modified_time( 'c', true, $ids[0] ) : '';
	}

	/**
	 * Return a cached render, or build + cache it. Keyed by a generation token
	 * so Cache::flush() (content changes) invalidates every page at once without
	 * having to enumerate them.
	 *
	 * @param string   $path    Request path (cache identity).
	 * @param callable $builder Produces the XML.
	 * @return string
	 */
	private static function cached( $path, $builder ) {
		$key = self::cache_key( $path );
		$hit = get_transient( $key );
		if ( is_string( $hit ) && '' !== $hit ) {
			return $hit;
		}
		$xml = (string) call_user_func( $builder );
		if ( '' !== $xml ) {
			set_transient( $key, $xml, HOUR_IN_SECONDS );
		}
		return $xml;
	}

	/** Generation-namespaced transient key for a sitemap path. */
	private static function cache_key( $path ) {
		$gen = get_transient( Cache::SITEMAP_GEN );
		if ( ! $gen ) {
			$gen = (string) time();
			set_transient( Cache::SITEMAP_GEN, $gen, DAY_IN_SECONDS );
		}
		return 'agentimus_sm_' . $gen . '_' . md5( $path );
	}

	/**
	 * Best-effort sitemap URL, or '' when none is detectable.
	 *
	 * @return string
	 */
	public static function url() {
		$detected = self::detect();
		return isset( $detected['url'] ) ? (string) $detected['url'] : '';
	}

	/**
	 * Known SEO sitemap providers in priority order, each with the path it
	 * serves. Yoast/Rank Math expose `/sitemap_index.xml`; AIOSEO, SEOPress and
	 * The SEO Framework expose `/sitemap.xml`.
	 *
	 * @return array<int,array{active:bool,source:string,label:string,path:string}>
	 */
	private static function providers() {
		return array(
			array(
				'active' => defined( 'WPSEO_VERSION' ),
				'source' => 'yoast',
				'label'  => __( 'Yoast SEO', 'agentimus' ),
				'path'   => '/sitemap_index.xml',
			),
			array(
				'active' => class_exists( 'RankMath' ),
				'source' => 'rankmath',
				'label'  => __( 'Rank Math', 'agentimus' ),
				'path'   => '/sitemap_index.xml',
			),
			array(
				'active' => defined( 'AIOSEO_VERSION' ),
				'source' => 'aioseo',
				'label'  => __( 'All in One SEO', 'agentimus' ),
				'path'   => '/sitemap.xml',
			),
			array(
				'active' => defined( 'SEOPRESS_VERSION' ),
				'source' => 'seopress',
				'label'  => __( 'SEOPress', 'agentimus' ),
				'path'   => '/sitemap.xml',
			),
			array(
				'active' => class_exists( '\\The_SEO_Framework\\Load' ),
				'source' => 'seoframework',
				'label'  => __( 'The SEO Framework', 'agentimus' ),
				'path'   => '/sitemap.xml',
			),
		);
	}
}
