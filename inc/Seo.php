<?php
/**
 * Solo-mode head output — the basic search-SEO surfaces Agentimus covers only
 * when no SEO suite is installed ({@see SeoContext}): a per-page SEO title now;
 * Open Graph/X cards and canonical URLs land beside it in this class.
 *
 * The mode is re-checked at emission time, not at registration: activate an SEO
 * suite and every surface here stands down on the very next request, no
 * settings save needed. In coexist mode this class stays inert — the suite owns
 * search SEO and Agentimus never fights it.
 *
 * The SEO title replaces the TITLE PART of the document title and keeps the
 * site's own separator + name suffix (via `document_title_parts`, never an
 * output buffer). Deliberately NOT `pre_get_document_title`: a full-title
 * override would silently drop the site name and pagination affixes the owner
 * never asked to lose, and a template syntax to put them back is exactly the
 * complexity this plugin promises to avoid.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Seo {

	/** @var string Post meta: the editor-entered SEO title. */
	const META_TITLE = '_agentimus_seo_title';

	/** @var string Nonce action/field for the classic meta-box save. */
	const NONCE = 'agentimus_seo_meta';

	/** @var int Hard cap on the stored title (chars) — search results show ~60, so this is generous headroom, not a target. */
	const TITLE_MAX_LEN = 120;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the post meta, the title filter and the editor save handler.
	 */
	public function register() {
		// The meta registers regardless of mode or toggle, so a value set in solo
		// mode survives (and stays REST-visible) through a suite trial and back —
		// the same data-preservation stance every other Agentimus meta takes.
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		if ( is_admin() ) {
			add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		}

		if ( ! $this->settings->enabled( 'enable_seo_titles' ) ) {
			return;
		}
		// After core builds the parts (10) but early enough that a theme tweaking
		// its own title at a later priority still wins — Agentimus supplies the
		// value, it doesn't insist on the last word.
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 20 );
	}

	/**
	 * Register the SEO-title meta on every agent-visible post type — sanitised on
	 * write, exposed to the REST/block editor, writes gated to users who can edit
	 * the post. Mirrors {@see Description::register_meta()}.
	 */
	public static function register_meta() {
		foreach ( Content::post_types() as $type ) {
			register_post_meta(
				$type,
				self::META_TITLE,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_title' ),
					'auth_callback'     => array( Description::class, 'can_edit' ),
				)
			);
		}
	}

	/**
	 * Whether the SEO-title editor field should show: feature on AND solo mode.
	 * In coexist mode the suite has its own title field — showing a second one
	 * would put two competing inputs on the same screen.
	 *
	 * @return bool
	 */
	public static function title_ui_enabled() {
		return (bool) ( new Settings() )->enabled( 'enable_seo_titles' ) && SeoContext::solo();
	}

	/**
	 * Swap the title part for the editor's SEO title, when there is one to swap.
	 *
	 * @param array $parts Core's document title parts.
	 * @return array
	 */
	public function filter_title_parts( $parts ) {
		$title = $this->custom_title();
		if ( '' !== $title && is_array( $parts ) ) {
			$parts['title'] = $title;
		}
		return $parts;
	}

	/**
	 * The SEO title for the current request, or '' when any gate says no:
	 * feature off, coexist mode, not a singular covered view, or no value set.
	 *
	 * @return string
	 */
	private function custom_title() {
		if ( ! $this->settings->enabled( 'enable_seo_titles' ) ) {
			return '';
		}
		if ( ! SeoContext::solo() ) {
			return '';
		}
		if ( ! is_singular( Content::post_types() ) ) {
			return '';
		}
		$post = get_post();
		if ( ! $post ) {
			return '';
		}
		return self::sanitize_title( get_post_meta( $post->ID, self::META_TITLE, true ) );
	}

	/**
	 * Normalise a title: strip tags, collapse whitespace to single spaces, trim,
	 * clip to the cap (multibyte-aware). Same discipline as Description::clean() —
	 * the value ends up inside a <title> element and later a social-card attribute.
	 *
	 * @param mixed $value Raw meta/input value.
	 * @return string
	 */
	public static function sanitize_title( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
		$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::TITLE_MAX_LEN ) : substr( $value, 0, self::TITLE_MAX_LEN );
		return rtrim( $value );
	}

	/**
	 * Render the SEO-title field — called by {@see Topics::render_meta_box()} as
	 * the top section of the shared editor box, above the AI description, the
	 * order a search snippet shows them.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_title_field( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$value = self::sanitize_title( get_post_meta( $post->ID, self::META_TITLE, true ) );

		$obj  = get_post_type_object( $post->post_type );
		$noun = ( $obj && ! empty( $obj->labels->singular_name ) ) ? strtolower( $obj->labels->singular_name ) : $post->post_type;

		echo '<div class="agentimus-seo-title">';
		echo '<p><label for="agentimus-seo-title-input">'
			. esc_html(
				sprintf(
					/* translators: %s: the content type in lowercase, e.g. "post", "page", "product". */
					__( 'Title for search results and browser tabs. Leave blank to use this %s’s own title. Your site name stays appended either way.', 'agentimus' ),
					$noun
				)
			)
			. '</label></p>';
		echo '<input type="text" id="agentimus-seo-title-input" name="agentimus_seo_title" class="widefat" maxlength="' . esc_attr( (string) self::TITLE_MAX_LEN ) . '" value="'
			. esc_attr( $value ) . '" placeholder="' . esc_attr( $post->post_title ) . '" />';
		echo '</div>';
	}

	/**
	 * Classic-editor save for the SEO title. Nonce + autosave/revision + capability
	 * guards mirror {@see Description::save()}; only acts when this POST carried our
	 * field, so quick-edit and REST saves never wipe it.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post — unused (kept for the save_post signature).
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return; // Not our form (quick-edit, REST, autosave-only, …).
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw   = isset( $_POST['agentimus_seo_title'] ) ? wp_unslash( $_POST['agentimus_seo_title'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by sanitize_title() below.
		$value = self::sanitize_title( $raw );
		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META_TITLE );
		} else {
			update_post_meta( $post_id, self::META_TITLE, $value );
		}
	}
}
