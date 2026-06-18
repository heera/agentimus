<?php
/**
 * JSON-LD output: a sitewide WebSite + Person/Organization entity, and a
 * BlogPosting + BreadcrumbList on single posts.
 *
 * Defers entirely when a schema-emitting SEO plugin is active, so the site
 * never ships duplicate or conflicting structured data — the single most
 * common reason schema plugins get rejected or one-starred.
 *
 * @package Agentomatic
 */

namespace Agentomatic;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook wp_head if schema is enabled and no SEO plugin owns it.
	 */
	public function register() {
		if ( ! $this->settings->enabled( 'enable_schema' ) ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'output' ), 1 );
	}

	/**
	 * Whether another plugin is already emitting schema we'd duplicate.
	 *
	 * @return bool
	 */
	public function seo_plugin_active() {
		$active = defined( 'WPSEO_VERSION' )                 // Yoast.
			|| class_exists( 'RankMath' )                    // Rank Math.
			|| defined( 'SEOPRESS_VERSION' )                 // SEOPress.
			|| class_exists( '\\The_SEO_Framework\\Load' )   // The SEO Framework.
			|| defined( 'AIOSEO_VERSION' );                  // All in One SEO.

		/**
		 * Filter whether Agentomatic should stand down on schema.
		 *
		 * @param bool $active Whether to defer.
		 */
		return (bool) apply_filters( 'agentomatic_defer_schema', $active );
	}

	/**
	 * Print the JSON-LD graph in the head.
	 */
	public function output() {
		if ( $this->seo_plugin_active() ) {
			return;
		}

		$graph = array( $this->website_node(), $this->entity_node() );

		if ( is_singular( Content::post_types() ) ) {
			$post = get_post();
			$node = $this->article_node( $post );
			/**
			 * Filter the per-post schema node. An add-on (e.g. WooCommerce) can
			 * return a Product/Event/… node here, or null to omit it.
			 *
			 * @param array|null $node Schema node.
			 * @param \WP_Post   $post Post.
			 */
			$node = apply_filters( 'agentomatic_schema_for_post', $node, $post );
			if ( ! empty( $node ) ) {
				$graph[] = $node;
			}
			$graph[] = $this->breadcrumb_node();
		}

		$graph = array_values( array_filter( $graph ) );

		/**
		 * Filter the JSON-LD @graph nodes before output.
		 *
		 * @param array $graph Schema nodes.
		 */
		$graph = apply_filters( 'agentomatic_schema_graph', $graph );
		if ( empty( $graph ) ) {
			return;
		}

		$doc = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		// Do NOT use JSON_UNESCAPED_SLASHES here — this JSON sits inside an HTML
		// <script> tag, and escaped slashes ("<\/script>") are exactly what stop a
		// graph value containing "</script>" from breaking out of the tag. (The
		// .well-known/*.json files, served as application/json, can unescape them.)
		echo "\n<script type=\"application/ld+json\">" .
			wp_json_encode( $doc, JSON_UNESCAPED_UNICODE ) . // phpcs:ignore WordPress.Security.EscapeOutput -- slash-escaped JSON in a script context.
			"</script>\n";
	}

	/**
	 * The WebSite node (enables the sitelinks search box).
	 *
	 * @return array
	 */
	private function website_node() {
		return array(
			'@type'           => 'WebSite',
			'@id'             => home_url( '/#website' ),
			'url'             => home_url( '/' ),
			'name'            => get_bloginfo( 'name' ),
			'description'     => get_bloginfo( 'description' ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);
	}

	/**
	 * The site entity — Person, or an Organization (sub)type, from identity settings.
	 *
	 * @return array
	 */
	private function entity_node() {
		// 'Person' is the human case; any other configured value is a schema.org
		// Organization (sub)type — Organization, LocalBusiness, Store, … — emitted
		// as-is (validated against entity_types() on save).
		$stored  = (string) $this->settings->identity( 'entity_type', 'Person' );
		$type    = '' !== $stored ? $stored : 'Person';
		$name    = (string) $this->settings->identity( 'name', get_bloginfo( 'name' ) );
		$about   = (string) $this->settings->identity( 'about' );
		$same_as = array_values( array_filter( (array) $this->settings->identity( 'same_as', array() ) ) );
		$knows   = array_values( array_filter( (array) $this->settings->identity( 'expertise', array() ) ) );

		$node = array(
			'@type' => $type,
			'@id'   => home_url( '/#identity' ),
			'name'  => '' !== $name ? $name : get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);
		if ( '' !== $about ) {
			$node['description'] = $about;
		}
		if ( ! empty( $knows ) ) {
			$node['knowsAbout'] = $knows;
		}
		if ( ! empty( $same_as ) ) {
			$node['sameAs'] = $same_as;
		}
		if ( 'Person' === $type ) {
			$role = (string) $this->settings->identity( 'role' );
			if ( '' !== $role ) {
				$node['jobTitle'] = $role;
			}
		}
		return $node;
	}

	/**
	 * Default per-post node. The schema @type is resolved from a filterable
	 * post-type → type map (post → BlogPosting, page → WebPage, else Article),
	 * so any content type gets a sensible default an add-on can override.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private function article_node( $post ) {
		/**
		 * Filter the post-type → schema @type map.
		 *
		 * @param array $map Map of post_type => schema type.
		 */
		$map = apply_filters(
			'agentomatic_schema_type_map',
			array(
				'post' => 'BlogPosting',
				'page' => 'WebPage',
			)
		);
		$type = isset( $map[ $post->post_type ] ) ? $map[ $post->post_type ] : 'Article';
		$url  = get_permalink( $post );

		return array(
			'@type'            => $type,
			'@id'              => $url . '#' . strtolower( $type ),
			'url'              => $url,
			'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
			'datePublished'    => get_the_date( DATE_W3C, $post ),
			'dateModified'     => get_the_modified_date( DATE_W3C, $post ),
			'author'           => array( '@id' => home_url( '/#identity' ) ),
			'publisher'        => array( '@id' => home_url( '/#identity' ) ),
			'mainEntityOfPage' => $url,
		);
	}

	/**
	 * BreadcrumbList for the current single post (Home → Category → Post).
	 *
	 * @return array
	 */
	private function breadcrumb_node() {
		$items = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => __( 'Home', 'agentomatic' ),
				'item'     => home_url( '/' ),
			),
		);

		$cats = get_the_category();
		$pos  = 2;
		if ( ! empty( $cats ) ) {
			$cat     = $cats[0];
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => $cat->name,
				'item'     => get_category_link( $cat ),
			);
		}
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => wp_strip_all_tags( get_the_title() ),
			'item'     => get_permalink(),
		);

		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}
}
