<?php
/**
 * Which content is an article worth grading for citability.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * The content-citability pillar ({@see Score}) grades posts and pages written to
 * be read and cited. This decides which content qualifies: the right post types,
 * minus commerce products and the structural/empty pages that would otherwise
 * read as false "thin content". Extracted from Score because "is this an article"
 * is a self-contained question — the same one the worklist will eventually ask —
 * with its own filters and edge cases. Score keeps the set-aside ledger and hands
 * it in, so the owner's manual "not cited content" choices still apply.
 */
final class Gradeability {

	/**
	 * The post types that carry citable, doc-like content — the stuff written to be
	 * read and cited. Commerce products are excluded, because a product page is short
	 * by design and isn't written to be quoted, so article checks (thin content,
	 * quotable passages) misfire on it. Starts from the agent-visible types, drops
	 * known commerce types, and is filterable both ways so an adapter can exclude its
	 * own type or force a custom one back in.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		/**
		 * Commerce/product post types to exclude from citability grading. Seeded with the
		 * common carts (WooCommerce, EDD, FluentCart).
		 *
		 * @param string[] $commerce Post-type slugs treated as commerce, not articles.
		 */
		$commerce = (array) apply_filters(
			'agentimus_commerce_post_types',
			array( 'product', 'product_variation', 'download', 'shop_order', 'shop_coupon', 'fluent-products' )
		);

		$types = array_values( array_diff( Content::post_types(), $commerce ) );

		/**
		 * The exact post types graded for content citability, after commerce is removed.
		 *
		 * @param string[] $types Post-type slugs to grade.
		 */
		return array_values( (array) apply_filters( 'agentimus_citability_post_types', $types ) );
	}

	/**
	 * Whether a sampled post is a real article whose content can be graded for citability.
	 * Excludes the structural and empty pages that would otherwise read as false positives:
	 *
	 *  - The blog-index container ("Posts page"): WordPress renders the loop, so it has no
	 *    authored content of its own — grading it "thin" is always wrong.
	 *  - Commerce plugins' designated pages (cart, checkout, account, shop…): structural
	 *    containers the plugin renders, not articles.
	 *  - Container-only authored content (shortcodes / namespaced plugin blocks, no prose).
	 *  - The owner's set-aside: pages marked "not cited content" from the worklist.
	 *  - Any page with no extractable text.
	 *
	 * @param \WP_Post $post        The sampled post.
	 * @param int[]    $ignored_ids Post IDs the owner set aside from grading (Score's ledger).
	 * @return bool
	 */
	public static function is_gradeable( \WP_Post $post, array $ignored_ids ) {
		if ( (int) $post->ID === (int) get_option( 'page_for_posts' ) ) {
			return false;
		}
		// Commerce plugins' designated pages (cart, checkout, account, shop…) are
		// structural in exactly the Posts-page way: a shortcode/block container
		// the plugin renders. Grading one "thin" tells the owner to fatten their
		// cart page — advice nobody should follow.
		if ( in_array( (int) $post->ID, self::commerce_page_ids(), true ) ) {
			return false;
		}
		// The plugin-agnostic version of the same fact: authored content that is
		// only a container (shortcodes / namespaced plugin blocks, no prose of the
		// author's own) — whatever plugin it belongs to, known here or not.
		if ( self::is_container_content( (string) $post->post_content ) ) {
			return false;
		}
		// Owner set-aside: pages marked "not cited content" from the worklist — content
		// that isn't meant to be quoted (a landing/utility/index page). Always surfaced
		// as a visible "set aside" count, so this never silently inflates the score.
		if ( in_array( (int) $post->ID, $ignored_ids, true ) ) {
			return false;
		}
		if ( str_word_count( wp_strip_all_tags( (string) Content::markdown_source( $post ) ) ) < 1 ) {
			return false;
		}
		/**
		 * Exclude a specific page/post from content-citability grading (the Optimize
		 * pillar) — e.g. a utility page an owner or adapter doesn't want graded as an article.
		 *
		 * @param bool     $gradeable Default true.
		 * @param \WP_Post $post      The post being considered.
		 */
		return (bool) apply_filters( 'agentimus_gradeable_post', true, $post );
	}

	/**
	 * Pages a commerce plugin has designated as its structural surfaces (cart,
	 * checkout, account, shop…). Never gradable as articles — they're containers
	 * the plugin fills at render time.
	 *
	 * The WooCommerce options are read directly rather than through
	 * wc_get_page_id(), which only exists while Woo is active — the designation
	 * itself is the signal, and compat layers set the same options without Woo
	 * loaded (FluentCart sites carry them, proven live). FluentCart's own store
	 * settings are scanned for any `*_page_id` entry. Anything else can use the
	 * `agentimus_gradeable_post` filter above.
	 *
	 * @return int[]
	 */
	private static function commerce_page_ids() {
		$ids = array();
		foreach ( array( 'cart', 'checkout', 'myaccount', 'shop', 'terms' ) as $key ) {
			$ids[] = (int) get_option( 'woocommerce_' . $key . '_page_id', 0 );
		}
		$ids = array_merge( $ids, self::page_id_entries( get_option( 'fluent_cart_store_settings' ) ) );
		return array_values( array_filter( array_unique( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Whether authored content is only a CONTAINER: shortcodes and/or namespaced
	 * plugin blocks with (next to) no prose of the author's own. The rendered
	 * page may well have words — a cart says "your cart is empty" — but they
	 * belong to the rendering plugin, so "thin content" advice is unactionable:
	 * there is nothing in the editor to expand.
	 *
	 * The markers are structural, not plugin names: a shortcode token, or a block
	 * with a namespace (`wp:woocommerce/cart`, `wp:someplugin/thing`) — core
	 * blocks (`wp:paragraph`) carry no namespace, so authored block content never
	 * trips this. Measured on wpftest: Woo's cart/checkout/account pages strip to
	 * 0–14 skeleton words; a real article keeps its full count.
	 *
	 * @param string $raw Raw post_content.
	 * @return bool
	 */
	private static function is_container_content( $raw ) {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return false; // The empty-page gate below owns this case.
		}
		$has_marker = preg_match( '#<!--\s*wp:[a-z][\w-]*/#i', $raw ) // namespaced (plugin) block
			|| preg_match( '#\[[a-zA-Z][\w-]*[^\]]*\]#', $raw );      // shortcode token
		if ( ! $has_marker ) {
			return false;
		}
		$prose = preg_replace( '#<!--\s*/?wp:[^>]*-->#', ' ', $raw );
		$prose = preg_replace( '#\[/?[a-zA-Z][\w-]*[^\]]*\]#', ' ', $prose );
		$prose = trim( wp_strip_all_tags( (string) $prose ) );
		// Under 20 words around a plugin widget isn't an article — it's a caption.
		return str_word_count( $prose ) < 20;
	}

	/**
	 * Collect the integer values of keys ending in `_page_id`, anywhere in a
	 * settings tree — the shape both FluentCart's flat and nested settings use.
	 *
	 * @param mixed $tree An option value: an array of settings, or anything else.
	 * @return int[]
	 */
	private static function page_id_entries( $tree ) {
		if ( ! is_array( $tree ) ) {
			return array();
		}
		$out = array();
		foreach ( $tree as $key => $value ) {
			if ( is_array( $value ) ) {
				$out = array_merge( $out, self::page_id_entries( $value ) );
			} elseif ( is_string( $key ) && '_page_id' === substr( $key, -8 ) && (int) $value > 0 ) {
				$out[] = (int) $value;
			}
		}
		return $out;
	}
}
