<?php
/**
 * Content registry — the single source of truth for *which* content is
 * agent-visible and *how* its body is sourced. This is the seam that lets the
 * plugin cover any site: WooCommerce products, custom post types, and
 * page-builder content all flow through here via settings + filters.
 *
 * @package Agentimus
 */

namespace Agentimus;

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
	 * ⭐⭐ CHECKING IS NOT ADVERTISING, and the two scopes answer to different
	 * defaults for a reason that is not a preference.
	 *
	 * {@see post_types()} below governs what LEAVES the site — llms.txt, the .md
	 * twins, schema, discovery. Widening that silently would publish every custom
	 * type an owner ever installed, so it is opt-in and defaults to posts and
	 * pages.
	 *
	 * This governs what is READ FOR THE OWNER — the worklist, the sweep, the
	 * findings. It publishes nothing: the findings tool is behind `can_manage`
	 * and appears on no public surface. Reading a product to tell its owner it
	 * never answers the search it is found for leaks precisely nothing, so the
	 * privacy argument that justifies the narrow default over there has no force
	 * here, and refusing to look is simply a worse dashboard.
	 *
	 * ⚠️ The two used to be one setting, and the worklist honoured NEITHER — it
	 * carried its own hardcoded `array('post','page')`, so ticking Products in
	 * Settings changed nothing and unticking Posts changed nothing either.
	 *
	 * @return string[]
	 */
	public static function checkable() {
		// Every public type with a page of its own. Attachments are the one real
		// exclusion: a media item's own page has no authored content to read.
		$out = self::available();

		/**
		 * The content types this site can check at all.
		 *
		 * @param string[] $out Post-type slugs eligible for checking.
		 */
		$out = (array) apply_filters( 'agentimus_checkable_post_types', $out );
		return array_values( array_unique( array_filter( array_map( 'strval', $out ) ) ) );
	}

	/**
	 * Why a type would START switched off — '' when it would start on.
	 *
	 * ⭐ Nothing is made UNREACHABLE by either reason. His rule is that the
	 * plugin decides what a thing IS and the owner decides what happens to it,
	 * so a type we would not have chosen still appears, still ticks on, and says
	 * why it was left off. Refusing to show it at all would leave an owner with
	 * a question and nowhere to ask it.
	 *
	 * @param string $post_type Post-type slug.
	 * @return string A sentence for the panel, or ''.
	 */
	public static function check_default_off_reason( $post_type ) {
		$obj = get_post_type_object( $post_type );

		// The VENDOR's own declaration, not a guess at their slug: a type kept
		// out of the site's own search is a template library, a building block,
		// a container — reachable, but not something anybody goes looking for.
		// Real content (posts, pages, products, docs) leaves this false.
		if ( $obj && ! empty( $obj->exclude_from_search ) ) {
			return __( 'The plugin that added this keeps it out of your site’s own search, so it starts off. Tick it to check these too.', 'agentimus' );
		}

		// ⭐ His standing constraint: nothing this plugin does may cost a site
		// its performance, and most of them are on shared hosting. Reading a page
		// is the one genuinely expensive thing here, so an update that silently
		// queued twelve thousand renders is exactly the bill nobody asked for.
		if ( self::published_count( $post_type ) > self::CHECK_AUTO_MAX ) {
			return __( 'There’s a lot of this, and reading it all takes a while — so it’s your call, not ours. Tick it whenever you want it checked.', 'agentimus' );
		}

		return '';
	}

	/**
	 * The content types this site actually checks: everything eligible, minus
	 * what the owner switched off.
	 *
	 * ⭐ Stored as REFUSALS, never as a positive list. A saved "yes" list is a
	 * snapshot of the plugins installed the day it was saved, so a type added
	 * next month would sit unchecked until somebody remembered a settings screen
	 * they had forgotten. Storing the "no" means new content types join on their
	 * own and the owner's decisions still outlive the plugin that made them —
	 * the same reasoning `post_types_vetoed` is built on.
	 *
	 * ⚠️ Deliberately cheap: one option and the registered types, no COUNT
	 * queries. The sweep, the worklist and every save go through here, and a
	 * resolver that counted posts would put a query on paths that must stay
	 * free. Size is decided ONCE per type {@see note_new_checkable_types()}.
	 *
	 * @return string[]
	 */
	public static function check_post_types() {
		$types = self::checkable();
		$off   = (array) ( new Settings() )->get( 'check_types_off', array() );
		if ( $off ) {
			$types = array_values( array_diff( $types, array_map( 'sanitize_key', $off ) ) );
		}
		return $types;
	}

	/**
	 * @var int Published items above which a newly-seen type starts switched OFF.
	 *
	 * ⭐ His standing constraint: nothing this plugin does may cost the site its
	 * performance, and most of these sites are on shared hosting. Reading a page
	 * is the one genuinely expensive thing here, so a plugin update that
	 * silently queued twelve thousand product renders would be exactly the bill
	 * nobody asked for. Under this many items the owner never meets the choice;
	 * over it, the type is offered with its count in plain sight and one tick
	 * starts it.
	 */
	const CHECK_AUTO_MAX = 1000;

	/**
	 * Decide the default for any eligible type nobody has decided about yet.
	 *
	 * ⚠️ ADMIN ONLY, and once per type ever. This is the one place a published
	 * count is taken, because it is the one decision that needs one; every other
	 * caller reads the resolved list. A type is recorded as seen whichever way
	 * it goes, so the owner's later change is never overwritten by this running
	 * again.
	 *
	 * @param Settings $settings Settings store.
	 * @return void
	 */
	public static function note_new_checkable_types( Settings $settings ) {
		$seen = (array) $settings->get( 'check_types_seen', array() );
		$new  = array_values( array_diff( self::checkable(), $seen ) );
		if ( ! $new ) {
			return;
		}

		$off = (array) $settings->get( 'check_types_off', array() );

		// ⛔⛔ THE SIZE GUARD MUST NEVER SWITCH OFF SOMETHING THE SITE WAS ALREADY
		// CHECKING. On the first run every eligible type is "new", because nothing
		// has been decided about anything yet — so without this, upgrading a site
		// with more than CHECK_AUTO_MAX published posts would silently STOP
		// checking its posts. A guard meant to protect a big site from work it
		// never asked for would have taken away work it already had, on exactly
		// the sites least able to notice.
		//
		// The old scope was posts and pages ({@see Settings::default_post_types()},
		// the same pair the worklist hardcoded before this release), so on the
		// first seeding those are recorded as decided-ON whatever their size. The
		// guard then applies only to types that are genuinely new to this site.
		$grandfathered = empty( $seen ) ? Settings::default_post_types() : array();

		foreach ( $new as $slug ) {
			if ( ! in_array( $slug, $grandfathered, true ) && '' !== self::check_default_off_reason( $slug ) ) {
				$off[] = $slug;
			}
			$seen[] = $slug;
		}

		// ⚠️ Merged into the FULL settings array: update() sanitizes what it is
		// given and writes the result whole, so a partial array silently resets
		// every unset boolean on the site.
		$all                      = $settings->stored();
		$all['check_types_seen']  = array_values( array_unique( $seen ) );
		$all['check_types_off']   = array_values( array_unique( $off ) );
		$settings->update( $all );
	}

	/**
	 * The post types Agentimus actually exposes: the configured selection
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
		$types = (array) apply_filters( 'agentimus_post_types', $types, $available );
		$types = array_values( array_unique( array_filter( $types ) ) );

		// The owner's refusals, applied LAST — after the filter, so a plugin's
		// "yes" cannot outrank the site owner's "no" on their own site. This is
		// the promise the Provider Integrations section already makes in words:
		// the plugin decides what a thing IS, the owner decides whether it is
		// advertised.
		//
		// A veto only ever REMOVES. It cannot add a type back, so a stale entry
		// for a plugin that has since gone is inert rather than dangerous, and
		// re-ticking simply drops the entry — handing the decision back to the
		// plugin rather than pinning the type on.
		$vetoed = (array) ( new Settings() )->get( 'post_types_vetoed', array() );
		if ( $vetoed ) {
			$types = array_values( array_diff( $types, array_map( 'sanitize_key', $vetoed ) ) );
		}
		return $types;
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
		// Cache the estimate: it runs a COUNT query per post type, and its only caller
		// (the Readiness report) is rebuilt on every admin load/save. The inputs are
		// content (published counts) and settings, both of which fire Cache::flush, so
		// the cached value is always current.
		$cached = Cache::get( Cache::LLMS_FULL_EST );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$per_type = (int) $settings->get( 'llms_full_posts', 50 );
		$per_type = $per_type > 0 ? $per_type : 50;

		$items = 0;
		foreach ( self::index_sections() as $post_type ) {
			$limit  = 'page' === $post_type ? 50 : $per_type;
			$items += min( self::published_count( $post_type ), $limit );
		}

		$avg_bytes    = max( 1, (int) apply_filters( 'agentimus_llms_full_avg_item_bytes', 4096 ) );
		$est_bytes    = $items * $avg_bytes;
		$budget_bytes = max( 64, (int) $settings->get( 'llms_full_max_kb', 1024 ) ) * 1024;

		$result = array(
			'items'         => $items,
			'est_bytes'     => $est_bytes,
			'budget_bytes'  => $budget_bytes,
			'will_truncate' => $est_bytes > $budget_bytes,
		);
		Cache::set( Cache::LLMS_FULL_EST, $result );
		return $result;
	}

	/**
	 * Published-item count for a post type (cheap; from wp_count_posts).
	 *
	 * Public because the size guard and the scope panel both need it — and both
	 * ask on a screen or a cron tick, never on a page load.
	 *
	 * @param string $post_type Post type slug.
	 * @return int
	 */
	public static function published_count( $post_type ) {
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
	 * The SINGULAR name, for prose.
	 *
	 * label() is the plural — right on a heading or a chip, wrong the moment a
	 * sentence uses it as a noun ("This posts has more images…"). WordPress keeps
	 * both, so anything that writes a sentence about ONE item asks for this.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function singular( $post_type ) {
		$obj = get_post_type_object( $post_type );
		if ( $obj && isset( $obj->labels->singular_name ) && '' !== $obj->labels->singular_name ) {
			return $obj->labels->singular_name;
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
		$ours    = wp_normalize_path( AGENTIMUS_DIR );
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
		return (string) apply_filters( 'agentimus_post_type_source', $source, $post_type );
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
			// get_pages() has no has_password filter, so drop password-protected
			// pages here — they must not surface in the index or full-text edition.
			$pages = (array) get_pages(
				array(
					'sort_column' => 'menu_order,post_title',
					'number'      => $limit,
				)
			);
			return array_values(
				array_filter(
					$pages,
					static function ( $page ) {
						return '' === (string) $page->post_password;
					}
				)
			);
		}

		return (array) get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'has_password'     => false, // Exclude password-protected posts from agent-visible content.
				'numberposts'      => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Resolve the HTML body for a post. Add-ons (page builders, custom
	 * renderers) can short-circuit with `agentimus_markdown_source`;
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
		$html = apply_filters( 'agentimus_markdown_source', null, $post );
		if ( null === $html ) {
			$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally running content through WordPress core's own the_content filter, not declaring a new hook.

			// Defensive fallback. `the_content` carries every third-party filter on
			// the site, and some blank the body when the filter runs outside the main
			// front-end loop — the editor meta-box render (which makes a real post read
			// as "0 words" in PageCheck) or our own off-loop .md generation for
			// /slug.md and /llms-full.txt (which would ship a body-less document). When
			// the filtered result comes back empty but the post genuinely has content,
			// expand just this post's own blocks — no third-party filters in the chain —
			// so we always measure and emit the real content.
			if ( '' === trim( wp_strip_all_tags( (string) $html ) ) && '' !== trim( (string) $post->post_content ) ) {
				$html = wpautop( do_blocks( $post->post_content ) );
			}
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
		return null !== apply_filters( 'agentimus_markdown_source', null, $post );
	}
}
