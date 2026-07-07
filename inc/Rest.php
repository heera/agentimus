<?php
/**
 * REST controller backing the Vue admin: read/save settings and fetch the
 * readiness report. All routes require `manage_options` and the standard REST
 * nonce (apiFetch / X-WP-Nonce).
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NAMESPACE = 'agentimus/v1';

	/**
	 * How many targets of one post type the preview picker loads per batch. A type
	 * with fewer than this returns in full (no "Load more"); a bigger one (posts,
	 * products, any CPT) paginates via `offset`. Pages are few, so they load whole.
	 */
	const PREVIEW_PAGE_SIZE = 25;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Define the routes.
	 */
	public function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/reset',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reset_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/onboarding',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'complete_onboarding' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/readiness',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_readiness' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		// JSON-LD preview: the exact @graph the front end would emit for the site
		// or a chosen post, so an owner can inspect and validate it without viewing
		// page source. `post` = 0/absent → the site-wide identity graph.
		register_rest_route(
			self::NAMESPACE,
			'/preview/schema',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema_preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// The Markdown twin of a page/post — what an agent receives when it requests
		// the page as text/markdown. The site target (post = 0) previews the index
		// Markdown served at the home URL and /llms.txt.
		register_rest_route(
			self::NAMESPACE,
			'/preview/markdown',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_markdown_preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// The pickable targets for the preview: the in-scope pages & posts, most
		// recently edited first, optionally filtered by a title search. Grouped by
		// post type and paginated per group — `type` + `offset` fetch a group's next
		// batch for the "Load more" button (see get_schema_targets()).
		register_rest_route(
			self::NAMESPACE,
			'/preview/targets',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema_targets' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'offset' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

	}

	/**
	 * Permission gate.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response(
			array(
				'settings'  => $this->settings->all(),
				'readiness' => ( new Readiness( $this->settings ) )->report(),
			)
		);
	}

	/**
	 * POST /settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = (array) $request->get_param( 'settings' );
		}
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$input = $input['settings'];
		}

		$saved = $this->settings->update( (array) $input );

		return rest_ensure_response(
			array(
				'settings'  => $saved,
				'readiness' => ( new Readiness( $this->settings ) )->report(),
				'saved'     => true,
			)
		);
	}

	/**
	 * POST /settings/reset — restore factory defaults.
	 *
	 * @return \WP_REST_Response
	 */
	public function reset_settings() {
		$defaults = $this->settings->reset();

		return rest_ensure_response(
			array(
				'settings'  => $defaults,
				'readiness' => ( new Readiness( $this->settings ) )->report(),
				'reset'     => true,
			)
		);
	}

	/**
	 * POST /onboarding — mark the first-run setup wizard complete (or skipped) so
	 * it never shows again. Stored as its own option, not inside settings, so a
	 * factory reset doesn't re-trigger the wizard.
	 *
	 * @return \WP_REST_Response
	 */
	public function complete_onboarding() {
		update_option( 'agentimus_onboarded', AGENTIMUS_VERSION );
		return rest_ensure_response( array( 'onboarded' => true ) );
	}

	/**
	 * GET /readiness.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_readiness() {
		return rest_ensure_response( ( new Readiness( $this->settings ) )->report() );
	}

	/**
	 * GET /preview/schema — the JSON-LD document for the site or a chosen post.
	 *
	 * Returns the graph regardless of whether the front end is currently emitting
	 * it (schema disabled, or an SEO plugin owns it): the point of a preview is to
	 * show what WOULD ship. The `active`/`reason` fields let the UI explain when the
	 * live `<head>` is empty. An unpublished post (draft/pending/scheduled/private)
	 * is previewed with its would-be per-post node — flagged by `postNote` as not yet
	 * live — so the owner sees what publishing will emit. A password-protected post
	 * is the one exception: its body never ships as schema, so it still yields only
	 * the site-level nodes. `livePublic` marks whether the target is reachable at a
	 * public URL right now, gating the URL-based validators.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_schema_preview( \WP_REST_Request $request ) {
		$schema  = new Schema( $this->settings );
		$seo     = $schema->seo_plugin_active();
		$enabled = $this->settings->enabled( 'enable_schema' );
		$post_id = absint( $request->get_param( 'post' ) );

		$post          = null;
		$post_included = true;
		$post_note     = '';
		$live_public   = true; // The site view is always reachable at a public URL.
		$target        = array(
			'type'  => 'site',
			'id'    => 0,
			'label' => __( 'Site-wide identity', 'agentimus' ),
			'url'   => home_url( '/' ),
		);

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
				return new \WP_Error(
					'agentimus_preview_not_found',
					__( 'That content is not available to preview.', 'agentimus' ),
					array( 'status' => 404 )
				);
			}
			$target = array(
				'type'  => 'post',
				'id'    => (int) $post->ID,
				'label' => $this->preview_label( $post ),
				'url'   => (string) get_permalink( $post ),
			);
			// Only a published, non-gated post is reachable at a public URL — the
			// precondition for the URL-based validators (a draft URL would 404).
			$live_public = ( 'publish' === $post->post_status && '' === (string) $post->post_password );
			if ( '' !== (string) $post->post_password ) {
				// Never previewed: a gated body stays private even once published.
				$post_included = false;
				$post_note     = __( 'This is password-protected, so its content is never exposed as schema — only the site-wide identity below.', 'agentimus' );
			} elseif ( 'publish' !== $post->post_status ) {
				// Preview the node this post WILL emit once it's published — clearly
				// flagged as not-yet-live so the owner doesn't mistake it for live output.
				$post_note = __( 'Not published yet — this is a preview of the per-post schema that will ship once you publish. It isn’t in your live page’s <head> yet.', 'agentimus' );
			}
		}

		$doc = ( null === $post )
			? $schema->build_document( null, true )
			: $schema->build_document( $post, false, true );

		return rest_ensure_response(
			array(
				'active'       => (bool) ( $enabled && ! $seo ),
				'reason'       => ! $enabled ? 'disabled' : ( $seo ? 'seo_plugin' : 'ok' ),
				'seoPlugin'    => (bool) $seo,
				'target'       => $target,
				'postIncluded' => (bool) $post_included,
				'postNote'     => $post_note,
				'livePublic'   => (bool) $live_public,
				'graph'        => $doc,
				// Pretty, slash-unescaped JSON for reading/validating. The live <head>
				// escapes slashes to stay safe inside <script>; the data is identical.
				'json'         => null === $doc
					? ''
					: (string) wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			)
		);
	}

	/**
	 * GET /preview/markdown — the Markdown a page/post is served as (`.md` / the
	 * `Accept: text/markdown` twin). The site target (post = 0) previews the index
	 * Markdown (`index_markdown()`), served at the home URL (`.md`) and /llms.txt.
	 * Mirrors the front-end privacy guard — a draft or password-protected
	 * post yields nothing, with `postNote` saying why. `active`/`reason` report
	 * whether Markdown delivery is switched on, so the UI can preview it either way.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_markdown_preview( \WP_REST_Request $request ) {
		$enabled = $this->settings->enabled( 'enable_markdown' );
		$post_id = absint( $request->get_param( 'post' ) );

		if ( $post_id <= 0 ) {
			// The site target DOES have a Markdown twin: the index Agentimus serves at
			// the home URL (append `.md`) and at /llms.txt — the exact bytes
			// index_markdown() ships (which reuses the llms.txt index). It's built from
			// the site identity + the page/post list, not a single page's body, so it's
			// noted as such rather than presented as a per-page document.
			$llms = new LlmsText( $this->settings );
			return rest_ensure_response(
				array(
					'active'       => (bool) $enabled,
					'reason'       => $enabled ? 'ok' : 'disabled',
					'target'       => array(
						'type'  => 'site',
						'id'    => 0,
						'label' => __( 'Site-wide identity', 'agentimus' ),
						'url'   => home_url( '/' ),
					),
					'postIncluded' => true,
					'postNote'     => __( 'The site index — built from your identity and the list of pages & posts, not a single page’s body.', 'agentimus' ),
					'livePublic'   => true,
					'markdown'     => (string) $llms->index_markdown(),
					'mdUrl'        => home_url( '/index.md' ),
				)
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error(
				'agentimus_preview_not_found',
				__( 'That content is not available to preview.', 'agentimus' ),
				array( 'status' => 404 )
			);
		}

		$target = array(
			'type'  => 'post',
			'id'    => (int) $post->ID,
			'label' => $this->preview_label( $post ),
			'url'   => (string) get_permalink( $post ),
		);

		$post_included = true;
		$post_note     = '';
		$markdown      = '';
		$md_url        = '';
		// Markdown is genuine served content (no "would-be" preview), so a public URL
		// exists only for a published, non-gated post — same rule as the schema path.
		$live_public = ( 'publish' === $post->post_status && '' === (string) $post->post_password );

		if ( 'publish' !== $post->post_status ) {
			$post_included = false;
			$post_note     = __( 'This isn’t published, so no Markdown is served for it yet.', 'agentimus' );
		} elseif ( '' !== (string) $post->post_password ) {
			$post_included = false;
			$post_note     = __( 'This is password-protected, so its content is never served as Markdown.', 'agentimus' );
		} else {
			$markdown  = Markdown::post( $post->ID );
			$permalink = (string) get_permalink( $post );
			// Mirrors Endpoints::markdown_alternate_url(): the permalink with `.md`.
			$md_url = '' !== $permalink ? untrailingslashit( $permalink ) . '.md' : '';
		}

		return rest_ensure_response(
			array(
				'active'       => (bool) $enabled,
				'reason'       => $enabled ? 'ok' : 'disabled',
				'target'       => $target,
				'postIncluded' => (bool) $post_included,
				'postNote'     => $post_note,
				'livePublic'   => (bool) $live_public,
				'markdown'     => (string) $markdown,
				'mdUrl'        => $md_url,
			)
		);
	}

	/**
	 * GET /preview/targets — the in-scope content the preview can describe, grouped
	 * by post type (Pages, Posts, then CPTs), most-recently-modified first, filtered
	 * by an optional title search.
	 *
	 * Each group carries its full `total` and a batch of up to PREVIEW_PAGE_SIZE
	 * `items`, so a small type (pages) arrives whole while a large one (posts,
	 * products) paginates: pass `type` + `offset` to fetch that group's next batch
	 * for the "Load more" button. Response: `{ groups: [ { type, typeLabel,
	 * typeSource, total, items, offset, hasMore } ] }`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_schema_targets( \WP_REST_Request $request ) {
		$search    = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$only_type = sanitize_key( (string) $request->get_param( 'type' ) );
		$offset    = absint( $request->get_param( 'offset' ) );

		$scoped = Content::post_types();

		// A "Load more" request names one type and an offset; the initial load walks
		// every in-scope type from the top.
		if ( '' !== $only_type && in_array( $only_type, $scoped, true ) ) {
			$types = array( $only_type );
		} else {
			$types  = $scoped;
			$offset = 0; // offset only makes sense for a single-type batch.
		}

		// Pages first, then Posts, then any CPTs (alphabetically) — mirrors the picker.
		$rank = static function ( $t ) {
			return 'page' === $t ? 0 : ( 'post' === $t ? 1 : 2 );
		};
		usort(
			$types,
			static function ( $a, $b ) use ( $rank ) {
				$d = $rank( $a ) - $rank( $b );
				return 0 !== $d ? $d : strcmp( $a, $b );
			}
		);

		$groups = array();
		foreach ( $types as $type ) {
			$args = array(
				'post_type'           => $type,
				'post_status'         => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page'      => self::PREVIEW_PAGE_SIZE,
				'offset'              => $offset,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			);
			if ( '' !== $search ) {
				$args['s'] = $search;
			}

			$query = new \WP_Query( $args );

			$items = array();
			foreach ( $query->posts as $post ) {
				$items[] = array(
					'id'     => (int) $post->ID,
					'type'   => $type,
					'label'  => $this->preview_label( $post ),
					'status' => $post->post_status,
					'url'    => (string) get_permalink( $post ),
				);
			}

			$total = (int) $query->found_posts;

			// An empty type is simply absent from the initial list; a Load-more request
			// still returns its (empty) group so the client can settle its state.
			if ( 0 === $total && '' === $only_type ) {
				continue;
			}

			$obj = get_post_type_object( $type );

			$groups[] = array(
				'type'       => $type,
				'typeLabel'  => ( $obj && ! empty( $obj->labels->name ) ) ? $obj->labels->name : ucfirst( $type ),
				// Vendor that registered the type, so a generic "Products" can be attributed (FluentCart vs WooCommerce vs …).
				'typeSource' => Content::source( $type ),
				'total'      => $total,
				'items'      => $items,
				'offset'     => $offset,
				'hasMore'    => ( $offset + count( $items ) ) < $total,
			);
		}

		return rest_ensure_response( array( 'groups' => $groups ) );
	}

	/**
	 * A human label for a previewable post — its title, or a stable placeholder for
	 * an untitled draft so the picker never shows a blank row.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function preview_label( $post ) {
		// Decode entities (get_the_title() returns e.g. &#8220;…&#8221;) so the label
		// reads as clean text, not raw HTML entities.
		$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $title ) {
			/* translators: %d: post ID. */
			$title = sprintf( __( '(untitled #%d)', 'agentimus' ), (int) $post->ID );
		}
		return $title;
	}
}
