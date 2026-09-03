<?php
/**
 * Solo-mode head output — the basic search-SEO surfaces Agentimus covers only
 * when no SEO suite is installed ({@see SeoContext}): a per-page SEO title,
 * Open Graph/X share cards, and canonical links on the views core leaves bare.
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

	/** @var int wp_head priority to open the gap-detection buffer — just inside Description's own pair. */
	const HEAD_BUFFER_OPEN = -99998;

	/** @var int wp_head priority to close it. */
	const HEAD_BUFFER_CLOSE = 99998;

	/** @var Settings */
	private $settings;

	/** @var bool Whether this request opened the head buffer (so close only acts when open did). */
	private $buffering = false;

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

		// Each surface rides its own toggle; every one re-checks the mode at
		// request time, so these hooks are inert the moment a suite activates.
		if ( $this->settings->enabled( 'enable_seo_titles' ) ) {
			// After core builds the parts (10) but early enough that a theme
			// tweaking its own title at a later priority still wins — Agentimus
			// supplies the value, it doesn't insist on the last word.
			add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 20 );
		}
		if ( $this->settings->enabled( 'enable_social_cards' ) || $this->settings->enabled( 'enable_canonicals' ) ) {
			// Cards + canonical are GAP-ONLY, like the description meta tag: many
			// themes (the-alpha among them) and plugins emit their own og:/canonical
			// tags, and a hardcoded list of them would never be complete. So the
			// head is buffered and ours append only when nobody else printed one.
			// Nested INSIDE Description's buffer (-99999/99999): opens stack, closes
			// unwind in reverse, and its description regex never matches our tags.
			add_action( 'wp_head', array( $this, 'buffer_start' ), self::HEAD_BUFFER_OPEN );
			add_action( 'wp_head', array( $this, 'buffer_end' ), self::HEAD_BUFFER_CLOSE );
		}
	}

	/**
	 * Open the head buffer on a candidate request — solo mode is the one gate
	 * both surfaces share; each printer re-runs its own finer gates at close.
	 * A no-op in coexist mode, so suite-run sites carry no buffering overhead.
	 */
	public function buffer_start() {
		try {
			$solo = SeoContext::solo(); // Runs the agentimus_solo_mode filter (third-party code).
		} catch ( \Throwable $e ) {
			return; // Degrade to "don't buffer, don't emit".
		}
		if ( ! $solo ) {
			return;
		}
		$this->buffering = true;
		ob_start();
	}

	/**
	 * Close the buffer, print the head unchanged, then append whichever of our
	 * surfaces the head still lacks. The head itself is echoed BEFORE the gap
	 * checks run, so no failure of ours can ever cost the page its head.
	 */
	public function buffer_end() {
		if ( ! $this->buffering ) {
			return;
		}
		$this->buffering = false;
		$head = (string) ob_get_clean();
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page's own head, passed through untouched.

		try {
			if ( $this->settings->enabled( 'enable_social_cards' ) && ! preg_match( '/property\s*=\s*["\']og:/i', $head ) ) {
				$this->output_social_cards();
			}
			if ( $this->settings->enabled( 'enable_canonicals' ) && ! preg_match( '/rel\s*=\s*["\']canonical["\']/i', $head ) ) {
				$this->output_canonical();
			}
		} catch ( \Throwable $e ) {
			return; // Head already printed; a failed append prints nothing.
		}
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

	/* ---------------------------------------------------------------------- *
	 *  Open Graph / X share cards
	 * ---------------------------------------------------------------------- */

	/**
	 * Print the share-card tags for the front page and singular covered views.
	 * Called by {@see buffer_end()} only when the head has no og: tags from any
	 * other source (a theme, Jetpack, a social plugin) — the gap check IS the
	 * stand-down, so there is no emitter list to maintain. The filter below is
	 * the escape hatch for a source the buffer can't see (tags printed outside
	 * wp_head).
	 */
	public function output_social_cards() {
		if ( ! SeoContext::solo() ) {
			return;
		}
		/**
		 * Filter whether Agentimus prints Open Graph/X card tags on this request.
		 *
		 * @param bool $emit Whether to emit.
		 */
		if ( ! apply_filters( 'agentimus_emit_social_cards', true ) ) {
			return;
		}

		$tags = $this->social_card_tags();
		if ( empty( $tags ) ) {
			return;
		}
		foreach ( $tags as $key => $value ) {
			// og:* live in `property`, twitter:* in `name` — that's each spec's attribute.
			$attr    = ( 0 === strpos( $key, 'twitter:' ) ) ? 'name' : 'property';
			$is_url  = in_array( $key, array( 'og:url', 'og:image' ), true );
			$content = $is_url ? esc_url( $value ) : esc_attr( $value );
			echo '<meta ' . esc_attr( $attr ) . '="' . esc_attr( $key ) . '" content="' . $content . '" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $content escaped per-kind above.
		}
	}

	/**
	 * The card tags for the current view, or an empty array off card-worthy views.
	 * Front page (either kind) is a `website`; a singular covered, published,
	 * non-password view is an `article`. Archives get no cards — shares point at
	 * posts, not at listings.
	 *
	 * @return array<string,string> Ordered tag map: og:/twitter: key => content.
	 */
	private function social_card_tags() {
		$front = is_front_page();
		$post  = is_singular( Content::post_types() ) ? get_post() : null;
		if ( $post && ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) ) {
			$post = null; // Never describe (or leak the featured image of) a non-public post.
		}
		if ( ! $front && ! $post ) {
			return array();
		}

		if ( $front ) {
			// A static front page enriches the website card; the blog-as-front
			// case falls back to the site's own name and tagline.
			$type  = 'website';
			$title = (string) get_bloginfo( 'name' );
			$desc  = $post ? Description::for_post( $post ) : '';
			$desc  = ( '' !== $desc ) ? $desc : (string) get_bloginfo( 'description' );
			$url   = home_url( '/' );
		} else {
			$type   = 'article';
			$custom = self::sanitize_title( get_post_meta( $post->ID, self::META_TITLE, true ) );
			$title  = ( '' !== $custom ) ? $custom : self::sanitize_title( get_the_title( $post ) );
			$desc   = Description::for_post( $post );
			$url    = (string) get_permalink( $post );
		}

		$tags = array(
			'og:type'      => $type,
			'og:site_name' => (string) get_bloginfo( 'name' ),
			'og:locale'    => (string) get_locale(),
			'og:title'     => $title,
			'og:url'       => $url,
		);
		if ( '' !== $desc ) {
			$tags['og:description'] = $desc;
		}

		$image = $this->social_image( $post );
		if ( null !== $image ) {
			$tags['og:image'] = $image['url'];
			if ( $image['width'] > 0 && $image['height'] > 0 ) {
				$tags['og:image:width']  = (string) $image['width'];
				$tags['og:image:height'] = (string) $image['height'];
			}
			if ( '' !== $image['alt'] ) {
				$tags['og:image:alt'] = $image['alt'];
			}
		}
		// The large-image card only when there is an image to be large with.
		$tags['twitter:card'] = ( null !== $image ) ? 'summary_large_image' : 'summary';

		// X reads the title, text and image from the og: tags above, so the card
		// renders for every site, connected to X or not. The one thing Open
		// Graph cannot say is WHICH X account the site is — twitter:site — and
		// the plugin knows that only when the owner has connected X (the handle
		// the announcements post as). Printed then, never guessed otherwise.
		$handle = ltrim( (string) Integrations\Services\Twitter::connection()['handle'], '@' );
		if ( '' !== $handle && Integrations\Services\Twitter::connected() ) {
			$tags['twitter:site'] = '@' . $handle;
		}

		/**
		 * Filter the share-card tag map before printing (og:/twitter: key => content).
		 * Values are escaped after this filter, so a provider can't break the head.
		 *
		 * @param array         $tags The tag map.
		 * @param \WP_Post|null $post The post being carded, null on a blog-as-front view.
		 */
		return (array) apply_filters( 'agentimus_social_card_tags', $tags, $post );
	}

	/**
	 * The card image, first match wins: the post's featured image → the owner's
	 * site-wide default (`social_default_image`) → the entity image (Site Icon or
	 * the `agentimus_entity_image` override) that Schema already publishes.
	 *
	 * Public because it is the single answer to "what image will this page share?" —
	 * the editor panel's Share tab shows it as the link preview.
	 *
	 * @param \WP_Post|null $post The post, when the view has one.
	 * @return array{url:string,width:int,height:int,alt:string}|null
	 */
	public function social_image( $post ) {
		if ( $post && function_exists( 'get_post_thumbnail_id' ) ) {
			$image = self::attachment_image( (int) get_post_thumbnail_id( $post ) );
			if ( null !== $image ) {
				return $image;
			}
		}

		$image = self::attachment_image( (int) $this->settings->get( 'social_default_image', 0 ) );
		if ( null !== $image ) {
			return $image;
		}

		$url = ( new Schema( $this->settings ) )->entity_image();
		if ( '' !== $url ) {
			return array(
				'url'    => $url,
				'width'  => 0,
				'height' => 0,
				'alt'    => '',
			);
		}
		return null;
	}

	/**
	 * Resolve an attachment into card-image facts, or null when it isn't one.
	 *
	 * @param int $id Attachment ID (0 = none).
	 * @return array{url:string,width:int,height:int,alt:string}|null
	 */
	private static function attachment_image( $id ) {
		if ( $id < 1 || ! function_exists( 'wp_get_attachment_image_src' ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}
		return array(
			'url'    => (string) $src[0],
			'width'  => isset( $src[1] ) ? (int) $src[1] : 0,
			'height' => isset( $src[2] ) ? (int) $src[2] : 0,
			'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Canonical links
	 * ---------------------------------------------------------------------- */

	/**
	 * Print a canonical link on the views core leaves bare. Core's rel_canonical
	 * covers EVERY singular view (a static front page included), so this only
	 * fills in the blog-as-front page and the main archive kinds. Called by
	 * {@see buffer_end()} only when the head has no canonical from any other
	 * source (core's, a theme's).
	 */
	public function output_canonical() {
		if ( ! SeoContext::solo() ) {
			return;
		}
		$url = $this->canonical_url();

		/**
		 * Filter the canonical URL Agentimus is about to print ('' = print nothing).
		 *
		 * @param string $url The resolved canonical URL.
		 */
		$url = (string) apply_filters( 'agentimus_canonical_url', $url );
		if ( '' === $url ) {
			return;
		}
		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
	}

	/**
	 * The canonical URL for the current view, or '' when this is not our view to
	 * canonicalise: singular (core's job), paginated pages 2+ (a canonical must be
	 * self-referential there, and building paged permalinks across permalink
	 * structures is a swamp — emitting NOTHING is honest, pointing page 5 at page 1
	 * would be wrong), search, 404s, and date archives (out of scope, low value).
	 *
	 * @return string
	 */
	private function canonical_url() {
		if ( is_singular() ) {
			return '';
		}
		if ( function_exists( 'is_paged' ) && is_paged() ) {
			return '';
		}
		if ( is_front_page() ) {
			return home_url( '/' );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			return is_string( $link ) ? $link : ''; // WP_Error → nothing, never a guessed URL.
		}
		if ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			$type = is_array( $type ) ? (string) reset( $type ) : (string) $type;
			$link = get_post_type_archive_link( $type );
			return is_string( $link ) ? $link : '';
		}
		if ( is_author() ) {
			return (string) get_author_posts_url( (int) get_queried_object_id() );
		}
		return '';
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
		// The field's NAME first (scannable), its explanation second — three
		// fields share this box, so each needs a heading of its own.
		echo '<p class="agentimus-fieldhead"><label for="agentimus-seo-title-input">'
			. esc_html__( 'SEO title', 'agentimus' ) . '</label></p>';
		echo '<p class="agentimus-fieldhint">'
			. esc_html(
				sprintf(
					/* translators: %s: the content type in lowercase, e.g. "post", "page", "product". */
					__( 'Title for search results and browser tabs. Leave blank to use this %s’s own title. Your site name stays appended either way.', 'agentimus' ),
					$noun
				)
			)
			. '</p>';
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
		if ( ! MetaSave::verified( $post_id, self::NONCE ) ) {
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
