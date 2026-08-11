<?php
/**
 * Page-builder awareness — who really owns a post's visible body.
 *
 * On a builder-driven site the body a visitor reads does not live in
 * post_content. Elementor keeps it in `_elementor_data` (post_content holds a
 * flattened copy the builder refreshes on ITS saves), Beaver Builder keeps it
 * in `_fl_builder_data` (post_content is never touched again), Divi and
 * WPBakery store their layout as shortcode markup INSIDE post_content. Two
 * things go quietly wrong the moment nobody states this:
 *
 *   1. WRITES LIE. An agent replacing post_content on an Elementor page gets a
 *      success response and a readability grade — while the visible page does
 *      not change, the .md twin starts serving text no human sees, and the
 *      builder's next save erases the edit. On Divi/WPBakery the same replace
 *      would destroy the page's design outright. ContentWriter asks
 *      {@see PageBuilders::owner()} and refuses the body replacement with the
 *      honest reason instead.
 *
 *   2. READS GO STALE. Our machine surfaces (.md, llms-full, PageCheck, the
 *      description fallback) derive from post_content via `the_content` — but
 *      the builders' own the_content filters bail outside a real front-end
 *      loop, so off-loop reads see the stale/flattened copy, not the page.
 *      For builders with a standalone render API (Elementor, Beaver Builder)
 *      this class supplies the REAL body through the same
 *      `agentimus_markdown_source` seam third parties already use — the FAQ's
 *      "output reflects your finished page" promise, made true.
 *
 * Detection is a cheap meta/marker check gated on the builder actually being
 * active — when the builder plugin is gone, WordPress renders post_content
 * again and ownership genuinely returns to the body this plugin writes.
 * The table is filterable (`agentimus_page_builders`) so an unlisted builder
 * can be taught without waiting on us.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class PageBuilders {

	/** Cached per-request owner lookups, keyed by post ID. */
	private static $owners = array();

	/** Meta key caching the builder-derived summary text (hash + text). */
	const SUMMARY_META = '_agentimus_builder_summary';

	/** Cap on the cached summary source text — the description fallback needs
	 *  sentences, not the whole page. */
	const SUMMARY_MAX_CHARS = 2000;

	/** Re-entrancy latch: a builder render must never recurse into our own seam. */
	private static $rendering = false;

	/**
	 * Register the read-side provider on the same seam third-party renderers
	 * use. Priority 20: an explicit `agentimus_markdown_source` provider at the
	 * documented default (10) keeps winning over this built-in one.
	 */
	public function register() {
		add_filter( 'agentimus_markdown_source', array( __CLASS__, 'filter_markdown_source' ), 20, 2 );
	}

	/**
	 * The builder table. Each entry:
	 *   name    string    Human name, used in refusal messages.
	 *   storage 'meta'|'content'  Where the layout lives. 'content' means the
	 *                     layout IS post_content (replacing it destroys the design).
	 *   active  callable():bool             Whether the builder runs on this site.
	 *   owns    callable(\WP_Post):bool     Whether it owns THIS post's body.
	 *   render  callable(\WP_Post):?string  Standalone render of the real body
	 *                                       (optional — only Elementor and Beaver
	 *                                       Builder expose one).
	 *   hash    callable(\WP_Post):string   Fingerprint of the builder's layout
	 *                                       data (optional; invalidates the
	 *                                       cached summary text).
	 *
	 * @return array[] Keyed by builder id.
	 */
	public static function builders() {
		$builders = array(
			'elementor'         => array(
				'name'    => 'Elementor',
				'storage' => 'meta',
				'active'  => static function () {
					return class_exists( '\Elementor\Plugin' );
				},
				'owns'    => static function ( \WP_Post $post ) {
					return 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );
				},
				'render'  => static function ( \WP_Post $post ) {
					if ( ! class_exists( '\Elementor\Plugin' ) ) {
						return null;
					}
					$plugin = \Elementor\Plugin::$instance;
					if ( ! $plugin || ! isset( $plugin->frontend ) ) {
						return null;
					}
					$html = (string) $plugin->frontend->get_builder_content_for_display( $post->ID );
					return '' !== trim( $html ) ? $html : null;
				},
				'hash'    => static function ( \WP_Post $post ) {
					return md5( (string) get_post_meta( $post->ID, '_elementor_data', true ) );
				},
			),
			'beaver-builder'    => array(
				'name'    => 'Beaver Builder',
				'storage' => 'meta',
				'active'  => static function () {
					return class_exists( 'FLBuilderModel' );
				},
				'owns'    => static function ( \WP_Post $post ) {
					return (bool) get_post_meta( $post->ID, '_fl_builder_enabled', true );
				},
				'render'  => static function ( \WP_Post $post ) {
					if ( ! shortcode_exists( 'fl_builder_insert_layout' ) ) {
						return null;
					}
					$html = (string) do_shortcode( '[fl_builder_insert_layout id="' . (int) $post->ID . '"]' );
					return '' !== trim( wp_strip_all_tags( $html ) ) ? $html : null;
				},
				'hash'    => static function ( \WP_Post $post ) {
					return md5( (string) maybe_serialize( get_post_meta( $post->ID, '_fl_builder_data', true ) ) );
				},
			),
			'divi'              => array(
				'name'    => 'Divi',
				'storage' => 'content',
				'active'  => static function () {
					return defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_BUILDER_PLUGIN_VERSION' ) || function_exists( 'et_pb_is_pagebuilder_used' );
				},
				'owns'    => static function ( \WP_Post $post ) {
					return 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true );
				},
			),
			'bricks'            => array(
				'name'    => 'Bricks',
				'storage' => 'meta',
				'active'  => static function () {
					return defined( 'BRICKS_VERSION' );
				},
				'owns'    => static function ( \WP_Post $post ) {
					return 'bricks' === get_post_meta( $post->ID, '_bricks_editor_mode', true );
				},
			),
			'oxygen'            => array(
				'name'    => 'Oxygen',
				'storage' => 'meta',
				'active'  => static function () {
					return defined( 'CT_VERSION' );
				},
				'owns'    => static function ( \WP_Post $post ) {
					return '' !== (string) get_post_meta( $post->ID, 'ct_builder_shortcodes', true )
						|| '' !== (string) get_post_meta( $post->ID, 'ct_builder_json', true );
				},
			),
			'wpbakery'          => array(
				'name'    => 'WPBakery Page Builder',
				'storage' => 'content',
				'active'  => static function () {
					return defined( 'WPB_VC_VERSION' );
				},
				'owns'    => static function ( \WP_Post $post ) {
					return false !== strpos( (string) $post->post_content, '[vc_row' );
				},
			),
			'siteorigin-panels' => array(
				'name'    => 'SiteOrigin Page Builder',
				'storage' => 'meta',
				'active'  => static function () {
					return class_exists( 'SiteOrigin_Panels' );
				},
				'owns'    => static function ( \WP_Post $post ) {
					$data = get_post_meta( $post->ID, 'panels_data', true );
					return is_array( $data ) && ! empty( $data['widgets'] );
				},
			),
		);

		/**
		 * Filter the known page builders. Add an entry to teach Agentimus a
		 * builder it does not know (see the shape in {@see PageBuilders::builders()}),
		 * or unset one to hand its pages back to the normal content path.
		 *
		 * @param array[] $builders Builder table, keyed by id.
		 */
		return (array) apply_filters( 'agentimus_page_builders', $builders );
	}

	/**
	 * The builder that owns a post's visible body, or null when the body is
	 * ordinary post_content. Only ACTIVE builders count: with the builder
	 * plugin gone, WordPress renders post_content again and the leftover meta
	 * is just history.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return array|null { id, name, storage } or null.
	 */
	public static function owner( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( array_key_exists( $post->ID, self::$owners ) ) {
			return self::$owners[ $post->ID ];
		}

		$found = null;
		foreach ( self::builders() as $id => $builder ) {
			if ( empty( $builder['active'] ) || ! is_callable( $builder['active'] ) || ! call_user_func( $builder['active'] ) ) {
				continue;
			}
			if ( empty( $builder['owns'] ) || ! is_callable( $builder['owns'] ) || ! call_user_func( $builder['owns'], $post ) ) {
				continue;
			}
			$found = array(
				'id'      => (string) $id,
				'name'    => isset( $builder['name'] ) ? (string) $builder['name'] : (string) $id,
				'storage' => ( isset( $builder['storage'] ) && 'content' === $builder['storage'] ) ? 'content' : 'meta',
			);
			break;
		}

		self::$owners[ $post->ID ] = $found;
		return $found;
	}

	/**
	 * The real rendered body for a builder-owned post, via the builder's own
	 * standalone render API. Null when nobody owns the post, the owner has no
	 * render door, or the render comes back empty (callers then fall back to
	 * the normal content path — a stale body beats no body).
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return string|null
	 */
	public static function render( $post ) {
		$post  = get_post( $post );
		$entry = self::owner_entry( $post );
		if ( null === $entry || empty( $entry['render'] ) || ! is_callable( $entry['render'] ) ) {
			return null;
		}
		if ( self::$rendering ) {
			return null; // A builder render that re-enters our seam must not loop.
		}
		self::$rendering = true;
		try {
			$html = call_user_func( $entry['render'], $post );
		} finally {
			self::$rendering = false;
		}
		return is_string( $html ) && '' !== trim( $html ) ? $html : null;
	}

	/**
	 * `agentimus_markdown_source` provider: hand the seam the builder's real
	 * body. An earlier provider's answer is final — this built-in one only
	 * speaks when nobody else has.
	 *
	 * @param string|null $html Existing override, or null.
	 * @param \WP_Post    $post Post.
	 * @return string|null
	 */
	public static function filter_markdown_source( $html, $post ) {
		if ( null !== $html || ! $post instanceof \WP_Post ) {
			return $html;
		}
		return self::render( $post );
	}

	/**
	 * Plain-text summary source for a builder-owned post — what the
	 * description fallback reads instead of the stale post_content. Rendering
	 * a page to derive one line is too expensive to repeat per view, so the
	 * text is cached in post meta and keyed to a fingerprint of the builder's
	 * layout data: one render per builder save, lazily, on first need.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return string|null Stripped, collapsed text — or null when no builder
	 *                     with a render door owns the post.
	 */
	public static function summary_text( $post ) {
		$post  = get_post( $post );
		$entry = self::owner_entry( $post );
		if ( null === $entry || empty( $entry['render'] ) || ! is_callable( $entry['render'] ) ) {
			return null;
		}

		$hash   = ( ! empty( $entry['hash'] ) && is_callable( $entry['hash'] ) ) ? (string) call_user_func( $entry['hash'], $post ) : '';
		$cached = get_post_meta( $post->ID, self::SUMMARY_META, true );
		if ( is_array( $cached ) && isset( $cached['hash'], $cached['text'] ) && $cached['hash'] === $hash ) {
			return (string) $cached['text'];
		}

		$html = self::render( $post );
		if ( null === $html ) {
			return null;
		}
		$text = wp_strip_all_tags( $html );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, self::SUMMARY_MAX_CHARS );
		} else {
			$text = substr( $text, 0, self::SUMMARY_MAX_CHARS );
		}

		update_post_meta( $post->ID, self::SUMMARY_META, array(
			'hash' => $hash,
			'text' => $text,
		) );
		return $text;
	}

	/**
	 * The owning builder's FULL table entry (with callables), or null.
	 *
	 * @param \WP_Post|null $post Post.
	 * @return array|null
	 */
	private static function owner_entry( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$owner = self::owner( $post );
		if ( null === $owner ) {
			return null;
		}
		$builders = self::builders();
		return isset( $builders[ $owner['id'] ] ) ? $builders[ $owner['id'] ] : null;
	}

	/**
	 * Forget this request's owner lookups (tests; long-running processes after
	 * a save changed a post's builder markers).
	 */
	public static function flush_runtime_cache() {
		self::$owners = array();
	}
}
