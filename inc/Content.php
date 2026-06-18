<?php
/**
 * Content registry — the single source of truth for *which* content is
 * agent-visible and *how* its body is sourced. This is the seam that lets the
 * plugin cover any site: WooCommerce products, custom post types, and
 * page-builder content all flow through here via settings + filters.
 *
 * @package HeeraAgentDiscovery
 */

namespace HeeraAgentDiscovery;

defined( 'ABSPATH' ) || exit;

final class Content {

	/**
	 * Captured at registration time: post-type slug => the plugin folder that
	 * registered it. Lets source() name the vendor at runtime with NO hardcoded
	 * plugin list. Populated only on our settings screen (see watch_origins()).
	 *
	 * @var array<string,string>
	 */
	private static $origins = array();

	/**
	 * Public post types that are candidates for inclusion (minus attachments).
	 *
	 * @return string[]
	 */
	public static function available() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * The post types Heera Discovery actually exposes: the configured selection
	 * (intersected with what's available), falling back to the privacy-safe
	 * default (posts + pages) — never silently to every public type, which
	 * would leak every CPT — then filtered so an add-on can add or remove types
	 * programmatically.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$available  = self::available();
		$configured = (array) ( new Settings() )->get( 'post_types', array() );

		$types = array_values( array_intersect( $configured, $available ) );
		if ( empty( $types ) ) {
			$types = Settings::default_post_types();
		}

		/**
		 * Filter the agent-visible post types.
		 *
		 * @param string[] $types     Resolved post types.
		 * @param string[] $available All public post types.
		 */
		$types = (array) apply_filters( 'heera_agent_discovery_post_types', $types, $available );
		return array_values( array_unique( array_filter( $types ) ) );
	}

	/**
	 * Post types ordered for the index: pages first, posts second, the rest
	 * alphabetically — so the document reads predictably on any site.
	 *
	 * @return string[]
	 */
	public static function index_sections() {
		$types = self::post_types();
		usort(
			$types,
			static function ( $a, $b ) {
				$rank = static function ( $t ) {
					return 'page' === $t ? 0 : ( 'post' === $t ? 1 : 2 );
				};
				$ra = $rank( $a );
				$rb = $rank( $b );
				return $ra === $rb ? strcmp( $a, $b ) : $ra - $rb;
			}
		);
		return $types;
	}

	/**
	 * A cheap (COUNT-only) estimate of the /llms-full.txt size, for the admin's
	 * size warning. Never runs the_content or the markdown converter. Mirrors the
	 * real generator's per-type limits (pages → 50, others → llms_full_posts) so
	 * the estimate can't drift from what is actually emitted.
	 *
	 * @param Settings $settings Settings store.
	 * @return array{items:int,est_bytes:int,budget_bytes:int,will_truncate:bool}
	 */
	public static function estimate_full_size( Settings $settings ) {
		$per_type = (int) $settings->get( 'llms_full_posts', 50 );
		$per_type = $per_type > 0 ? $per_type : 50;

		$items = 0;
		foreach ( self::index_sections() as $post_type ) {
			$limit  = 'page' === $post_type ? 50 : $per_type;
			$items += min( self::published_count( $post_type ), $limit );
		}

		$avg_bytes    = max( 1, (int) apply_filters( 'heera_agent_discovery_llms_full_avg_item_bytes', 4096 ) );
		$est_bytes    = $items * $avg_bytes;
		$budget_bytes = max( 64, (int) $settings->get( 'llms_full_max_kb', 1024 ) ) * 1024;

		return array(
			'items'         => $items,
			'est_bytes'     => $est_bytes,
			'budget_bytes'  => $budget_bytes,
			'will_truncate' => $est_bytes > $budget_bytes,
		);
	}

	/**
	 * Published-item count for a post type (cheap; from wp_count_posts).
	 *
	 * @param string $post_type Post type slug.
	 * @return int
	 */
	private static function published_count( $post_type ) {
		$counts = wp_count_posts( $post_type );
		return ( is_object( $counts ) && isset( $counts->publish ) ) ? (int) $counts->publish : 0;
	}

	/**
	 * The plural label for a post type's section heading.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function label( $post_type ) {
		$obj = get_post_type_object( $post_type );
		if ( $obj && isset( $obj->labels->name ) && '' !== $obj->labels->name ) {
			return $obj->labels->name;
		}
		return ucfirst( $post_type );
	}

	/**
	 * Start recording which plugin registers each post type. Hooked on
	 * plugins_loaded (before `init`) so init-time registrations are captured.
	 * Scoped to our settings screen by the caller, so the backtrace cost is never
	 * paid on a normal page load.
	 */
	public static function watch_origins() {
		add_action( 'registered_post_type', array( __CLASS__, 'record_origin' ), 10, 1 );
	}

	/**
	 * Record the plugin folder that registered $post_type, via the call stack.
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function record_origin( $post_type ) {
		if ( isset( self::$origins[ $post_type ] ) ) {
			return;
		}
		$dir = self::registrant_dir();
		if ( '' !== $dir ) {
			self::$origins[ $post_type ] = $dir;
		}
	}

	/**
	 * Walk the call stack to the first frame inside a third-party plugin (not
	 * core, not us) and return its top-level plugin folder, or '' for core/unknown.
	 *
	 * @return string
	 */
	private static function registrant_dir() {
		$plugins = wp_normalize_path( WP_PLUGIN_DIR );
		$ours    = wp_normalize_path( HEERA_AGENT_DISCOVERY_DIR );
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			if ( 0 === strpos( $file, $ours ) ) {
				continue; // our own frames
			}
			if ( 0 === strpos( $file, $plugins ) ) {
				return (string) strtok( ltrim( substr( $file, strlen( $plugins ) ), '/' ), '/' );
			}
		}
		return '';
	}

	/**
	 * A human source hint for a post type, to disambiguate collisions (e.g. two
	 * plugins that both label their type "Products"). The vendor name is figured
	 * out at runtime — the registering plugin's own header "Name" — so NOTHING is
	 * hardcoded. Returns '' for core/unknown (the slug already disambiguates).
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function source( $post_type ) {
		$source = isset( self::$origins[ $post_type ] ) ? self::plugin_name( self::$origins[ $post_type ] ) : '';

		/**
		 * Filter the source label shown next to a post type.
		 *
		 * @param string $source    Source plugin name, or ''.
		 * @param string $post_type Post type slug.
		 */
		return (string) apply_filters( 'heera_agent_discovery_post_type_source', $source, $post_type );
	}

	/**
	 * Resolve a plugin folder to its own header "Name" (e.g. "woocommerce" →
	 * "WooCommerce"), read from the plugin's metadata — never hardcoded. Falls back
	 * to a titleized folder slug. Cached per request.
	 *
	 * @param string $dir Plugin folder.
	 * @return string
	 */
	private static function plugin_name( $dir ) {
		static $names = null;
		if ( null === $names ) {
			$names = array();
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $data ) {
				$folder = strtok( (string) $file, '/' );
				if ( $folder && ! isset( $names[ $folder ] ) && ! empty( $data['Name'] ) ) {
					$names[ $folder ] = $data['Name'];
				}
			}
		}
		return isset( $names[ $dir ] ) ? $names[ $dir ] : ucwords( str_replace( array( '-', '_' ), ' ', $dir ) );
	}

	/**
	 * Published items of a post type, newest first (pages by menu order).
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $limit     Max items.
	 * @return \WP_Post[]
	 */
	public static function query( $post_type, $limit ) {
		$limit = $limit > 0 ? $limit : 50;

		if ( 'page' === $post_type ) {
			return (array) get_pages(
				array(
					'sort_column' => 'menu_order,post_title',
					'number'      => $limit,
				)
			);
		}

		return (array) get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Resolve the HTML body for a post. Add-ons (page builders, custom
	 * renderers) can short-circuit with `heera_agent_discovery_markdown_source`;
	 * otherwise we run the standard `the_content` filter.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function markdown_source( $post ) {
		/**
		 * Filter the HTML source for a post's markdown rendering. Return a
		 * string to override; return null to use post_content.
		 *
		 * @param string|null $html Override HTML, or null.
		 * @param \WP_Post     $post Post.
		 */
		$html = apply_filters( 'heera_agent_discovery_markdown_source', null, $post );
		if ( null === $html ) {
			$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally running content through WordPress core's own the_content filter, not declaring a new hook.
		}
		return (string) $html;
	}

	/**
	 * Whether a post has a renderable body (so template-only / builder-empty
	 * items don't become title-only stubs in the full-text edition).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function has_body( $post ) {
		if ( '' !== trim( (string) $post->post_content ) ) {
			return true;
		}
		return null !== apply_filters( 'heera_agent_discovery_markdown_source', null, $post );
	}
}
