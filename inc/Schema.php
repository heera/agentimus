<?php
/**
 * JSON-LD output: a sitewide WebSite + Person/Organization entity, and a
 * BlogPosting + BreadcrumbList on single posts.
 *
 * Defers entirely when a schema-emitting SEO plugin is active, so the site
 * never ships duplicate or conflicting structured data — the single most
 * common reason schema plugins get rejected or one-starred.
 *
 * @package Agentimus
 */

namespace Agentimus;

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
		 * Filter whether Agentimus should stand down on schema.
		 *
		 * @param bool $active Whether to defer.
		 */
		return (bool) apply_filters( 'agentimus_defer_schema', $active );
	}

	/**
	 * Print the JSON-LD graph in the head.
	 */
	public function output() {
		if ( $this->seo_plugin_active() ) {
			return;
		}

		$graph = array( $this->website_node(), $this->entity_node() );

		// Services are an entity-level offering, so they belong on the front page
		// alongside the site identity — not repeated on every post.
		if ( is_front_page() ) {
			foreach ( $this->service_nodes() as $service ) {
				$graph[] = $service;
			}
		}

		// Per-post structured data, but never for content the HTML site itself
		// gates: a password-protected post (whose body Q&A would otherwise leak
		// into the public FAQPage node) or a non-published status. Mirrors the
		// guard in Markdown::post(); the site-level identity nodes above stand.
		$post = is_singular( Content::post_types() ) ? get_post() : null;
		if ( $post && 'publish' === $post->post_status && '' === (string) $post->post_password ) {
			$node = $this->article_node( $post );
			/**
			 * Filter the per-post schema node. An add-on (e.g. WooCommerce) can
			 * return a Product/Event/… node here, or null to omit it.
			 *
			 * @param array|null $node Schema node.
			 * @param \WP_Post   $post Post.
			 */
			$node = apply_filters( 'agentimus_schema_for_post', $node, $post );
			// is_array(), not just ! empty(): a scalar return (string/number) passes
			// ! empty() but would corrupt the @graph / break json_encode downstream.
			if ( is_array( $node ) && ! empty( $node ) ) {
				$graph[] = $node;
			}
			$graph[] = $this->breadcrumb_node();

			// A FAQPage when the content clearly is one — agents lift the Q&A.
			$faq = $this->faq_node( $post );
			if ( $faq ) {
				$graph[] = $faq;
			}
		}

		$graph = array_values( array_filter( $graph ) );

		/**
		 * Filter the JSON-LD @graph nodes before output.
		 *
		 * @param array $graph Schema nodes.
		 */
		$filtered = apply_filters( 'agentimus_schema_graph', $graph );
		// Fall back to the valid graph if a filter hands back a non-array, then keep
		// only array nodes — the @graph that reaches json_encode is always well-formed.
		$graph = array_values( array_filter( is_array( $filtered ) ? $filtered : $graph, 'is_array' ) );
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
			'name'            => $this->clean( get_bloginfo( 'name' ) ),
			'description'     => $this->clean( get_bloginfo( 'description' ) ),
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
	 * Decode HTML entities and strip tags so a value reads as clean plain text in the
	 * JSON-LD. get_bloginfo() can return entity-encoded text (e.g. "&amp;"), which is
	 * wrong inside a JSON string value.
	 *
	 * @param string $value Possibly entity-encoded text.
	 * @return string
	 */
	private function clean( $value ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
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
		$not     = (string) $this->settings->identity( 'not_description' );
		$aud     = (string) $this->settings->identity( 'audience' );
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
		// A negative description disambiguates the entity so agents don't miscategorize it.
		if ( '' !== $not ) {
			$node['disambiguatingDescription'] = $not;
		}
		if ( '' !== $aud ) {
			$node['audience'] = array( '@type' => 'Audience', 'audienceType' => $aud );
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
	 * `Service` nodes from the owner-declared services list (opt-in; empty unless
	 * the site actually offers services — we never guess them from content). Each
	 * is linked back to the site entity as its provider, so an agent can answer
	 * "what can I hire this site for?".
	 *
	 * @return array[] Zero or more Service nodes.
	 */
	private function service_nodes() {
		$services = (array) $this->settings->identity( 'services', array() );
		$nodes    = array();
		foreach ( $services as $svc ) {
			if ( ! is_array( $svc ) ) {
				continue;
			}
			$name = isset( $svc['name'] ) ? $this->clean( $svc['name'] ) : '';
			if ( '' === $name ) {
				continue; // A nameless service is not a service.
			}
			$node = array(
				'@type'    => 'Service',
				'name'     => $name,
				'provider' => array( '@id' => home_url( '/#identity' ) ),
			);
			$desc = isset( $svc['description'] ) ? $this->clean( $svc['description'] ) : '';
			if ( '' !== $desc ) {
				$node['description'] = $desc;
			}
			$url = isset( $svc['url'] ) ? esc_url_raw( (string) $svc['url'] ) : '';
			if ( '' !== $url ) {
				$node['url'] = $url;
			}
			$nodes[] = $node;
		}
		return $nodes;
	}

	/**
	 * A `FAQPage` node when the post clearly is one. We require at least two Q&A
	 * pairs — a single question is prose, a list is an FAQ — so we never emit
	 * guessed/spammy FAQ markup. The detected pairs are filterable.
	 *
	 * @param \WP_Post $post Post.
	 * @return array|null
	 */
	private function faq_node( $post ) {
		// Render blocks (so the Details block becomes <details><summary>) without
		// firing the full the_content chain — avoids third-party render side effects.
		$html  = function_exists( 'do_blocks' ) ? do_blocks( (string) $post->post_content ) : (string) $post->post_content;
		$pairs = Faq::extract( $html );

		/**
		 * Filter the FAQ pairs detected for a post — supply or refine them.
		 *
		 * @param array    $pairs `[ ['q'=>…,'a'=>…], … ]`.
		 * @param \WP_Post $post  The post.
		 */
		$pairs = (array) apply_filters( 'agentimus_faq_pairs', $pairs, $post );

		$entities = array();
		foreach ( $pairs as $pair ) {
			$q = isset( $pair['q'] ) ? $this->clean( $pair['q'] ) : '';
			$a = isset( $pair['a'] ) ? $this->clean( $pair['a'] ) : '';
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $a,
				),
			);
		}

		if ( count( $entities ) < 2 ) {
			return null;
		}

		$url = get_permalink( $post );
		return array(
			'@type'      => 'FAQPage',
			'@id'        => $url . '#faq',
			'url'        => $url,
			'mainEntity' => $entities,
		);
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
			'agentimus_schema_type_map',
			array(
				'post' => 'BlogPosting',
				'page' => 'WebPage',
			)
		);
		$type = isset( $map[ $post->post_type ] ) ? $map[ $post->post_type ] : 'Article';
		$url  = get_permalink( $post );

		$node = array(
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

		// Per-page AI topics → schema.org `keywords` (flat terms) plus `about`
		// DefinedTerm entities. Each `about` node can carry `sameAs` links (Wikidata,
		// Wikipedia, an official site) that disambiguate the exact entity for an
		// assistant — supplied via the `agentimus_topic_links` filter, never a
		// front-end lookup. Emitted only when topics exist; inherits this method's
		// password/publish guard.
		$topics = Topics::for_post( $post );
		if ( ! empty( $topics ) ) {
			$node['keywords'] = $topics;
			$about            = $this->about_nodes( $topics, $post );
			if ( ! empty( $about ) ) {
				$node['about'] = $about;
			}
		}

		return $node;
	}

	/**
	 * Build the `about` DefinedTerm nodes for a post's topics. Each is the topic as
	 * a named entity; when an owner/add-on supplies authoritative reference URLs for
	 * it (see {@see topic_links()}), they are attached as `sameAs` so an assistant can
	 * resolve the exact thing — e.g. "Mercury" the planet vs the element.
	 *
	 * @param string[] $topics Resolved topics.
	 * @param \WP_Post  $post   Post.
	 * @return array[]
	 */
	private function about_nodes( array $topics, $post ) {
		$nodes = array();
		foreach ( $topics as $topic ) {
			$term = array(
				'@type' => 'DefinedTerm',
				'name'  => $topic,
			);
			$links = $this->topic_links( $topic, $post );
			if ( ! empty( $links ) ) {
				$term['sameAs'] = 1 === count( $links ) ? $links[0] : $links;
			}
			$nodes[] = $term;
		}
		return $nodes;
	}

	/**
	 * Authoritative reference URLs for one topic, emitted as `sameAs`. Core supplies
	 * NONE — no front-end network calls and no risky auto-matching (a wrong Wikidata
	 * guess is worse than none). An owner or add-on maps topics to Wikidata/Wikipedia
	 * URLs via the filter; the result is sanitised and de-duplicated.
	 *
	 * @param string   $topic Topic text.
	 * @param \WP_Post  $post  Post.
	 * @return string[]
	 */
	private function topic_links( $topic, $post ) {
		/**
		 * Filter authoritative reference URLs (Wikidata, Wikipedia, an official site)
		 * for a topic — emitted as schema.org `sameAs` on its `about` DefinedTerm.
		 *
		 * @param string[] $urls  Reference URLs (default none).
		 * @param string   $topic The topic text.
		 * @param \WP_Post  $post  The post.
		 */
		$urls = (array) apply_filters( 'agentimus_topic_links', array(), (string) $topic, $post );

		$out = array();
		foreach ( $urls as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( '' !== $url && ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
		}
		return $out;
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
				'name'     => __( 'Home', 'agentimus' ),
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
