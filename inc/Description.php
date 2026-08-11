<?php
/**
 * AI description — a per-page one-line summary that flows into the machine-readable
 * surfaces (JSON-LD `description`, the per-page markdown lead) and, as a gap-only
 * fallback, the HTML `<meta name="description">`.
 *
 * The description is first an AEO/GEO signal: it tells an answer engine in one
 * sentence what a page is so it can be summarised and cited correctly. It is no
 * longer Agentimus's only search-facing field, though: in solo mode
 * ({@see SeoContext}) the {@see Seo} class covers titles — with Open Graph/X
 * cards and canonicals landing there too — while an installed SEO suite still
 * owns all of that in coexist mode.
 *
 * This class is the SINGLE SOURCE OF TRUTH, exactly like {@see Topics}: Schema,
 * Markdown and the meta-tag emitter all call {@see for_post()} and emit whatever it
 * returns, so the surfaces can never disagree. When the editor leaves it blank the
 * resolver falls back to the post's excerpt — its manual excerpt when set, otherwise a
 * short summary derived from the body (block delimiters, shortcodes and tags stripped),
 * so every post has a description without the author writing one. That derivation
 * deliberately avoids `the_content` (which an off-loop filter can blank — see
 * {@see Content::markdown_source()}), so the fallback is robust even where the rendered
 * body is not. A site can refine or replace the whole result via the
 * `agentimus_post_description` filter.
 *
 * The HTML meta tag makes Agentimus the DESCRIPTION AUTHORITY on any site without a
 * dedicated SEO plugin: it buffers the `<head>` and, when it has a description of its own
 * (the editor's value or the excerpt), REPLACES whatever `name="description"` tag the
 * theme printed — an author-written line beats an auto-truncation — or appends one when
 * the head has none. There is always exactly one such tag, never a duplicate. When
 * Agentimus has nothing of its own it leaves an existing tag alone. It still stands down
 * WHOLESALE for a known schema/SEO plugin (the same detection Schema uses via
 * {@see Schema::seo_plugin_present()}), which owns descriptions on the sites that run one,
 * so Agentimus never fights Yoast/Rank Math/etc. The JSON-LD and markdown surfaces have no
 * such conflict — Agentimus's graph stands down wholesale for an SEO plugin in
 * {@see Schema::output()}, and the .md endpoints are Agentimus's own — so those always
 * carry it when the feature is on. Emission inherits each surface's existing privacy
 * guards (password-protected / unpublished posts are already blocked), so the description
 * adds no new leak surface.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Description {

	/** @var string Post meta: the editor-entered one-line AI description. */
	const META = '_agentimus_description';

	/** @var string Nonce action/field for the classic meta box. */
	const NONCE = 'agentimus_description_meta';

	/** @var int Hard cap on the stored/emitted length (chars), so one value can't bloat a machine surface or a meta tag. */
	const MAX_LEN = 300;

	/** @var int Words kept when deriving the fallback summary from a post body (no manual excerpt) — a meta-description-friendly length. */
	const SUMMARY_WORDS = 30;

	/** @var int wp_head priority to OPEN the head buffer — before any realistic emitter. */
	const HEAD_BUFFER_OPEN = -99999;

	/** @var int wp_head priority to CLOSE the head buffer — after every realistic emitter. */
	const HEAD_BUFFER_CLOSE = 99999;

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
	 * Register the post meta, the front-end meta-tag emitter, the editor UI and the
	 * cache-busting hooks.
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		// Gap-only <meta name="description">: wrap the whole head in a buffer (only on a
		// candidate page — see buffer_start()), then at the very end inject our tag only if
		// nothing else already emitted one. This detects ANY description source — an AI-SEO
		// plugin, a niche SEO plugin, the theme — not just the hardcoded SEO-plugin list.
		add_action( 'wp_head', array( $this, 'buffer_start' ), self::HEAD_BUFFER_OPEN );
		add_action( 'wp_head', array( $this, 'buffer_end' ), self::HEAD_BUFFER_CLOSE );

		if ( is_admin() ) {
			// No meta box of its own: the description renders as the top section of
			// the shared "AI description & topics" box — {@see Topics::add_meta_box()}
			// registers it and calls {@see render_meta_box()} for this half.
			add_action( 'save_post', array( $this, 'save' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		}

		// A pure REST meta write does not always fire save_post (which is what busts the
		// generated caches), so flush on our meta changing too — the JSON-LD and the
		// full-text edition can never serve a stale description. Cache::flush() is
		// idempotent, so the overlap with the save_post path is free.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_on_meta' ), 10, 3 );
		}
	}

	/**
	 * Register the post meta on every agent-visible post type, so it is sanitised on
	 * write and exposed to the REST/block editor (headless-friendly). The auth callback
	 * gates REST writes to users who can edit the post.
	 */
	public static function register_meta() {
		$auth = array( __CLASS__, 'can_edit' );

		foreach ( Content::post_types() as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize' ),
					'auth_callback'     => $auth,
				)
			);
		}
	}

	/**
	 * Whether the AI-description feature is enabled site-wide.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) ( new Settings() )->enabled( 'enable_ai_description' );
	}

	/**
	 * Whether the HTML `<meta name="description">` sub-option is on. Independent of the
	 * master toggle: with the feature on but this off, the JSON-LD and .md surfaces still
	 * carry the description (they call {@see for_post()}, gated only by {@see enabled()}),
	 * but Agentimus never touches the page `<head>`.
	 *
	 * @return bool
	 */
	public static function meta_tag_enabled() {
		return (bool) ( new Settings() )->enabled( 'ai_description_meta_tag' );
	}

	/**
	 * The resolved description for a post: the editor's explicit value, or the post's
	 * excerpt (manual, else a short summary from the body) when that is blank. Cleaned
	 * (tags stripped, whitespace collapsed, capped). Returns '' when the feature is off,
	 * the post is missing, or there is no content at all to summarise.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return string
	 */
	public static function for_post( $post ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}

		$explicit = self::clean( self::explicit( $post->ID ) );
		$out      = ( '' !== $explicit ) ? $explicit : self::clean( self::excerpt( $post ) );

		/**
		 * Filter the resolved AI description for a post. An add-on can supply or refine
		 * the summary (e.g. a product's short description for a WooCommerce product).
		 *
		 * @param string   $out  Resolved, cleaned description.
		 * @param \WP_Post $post The post.
		 */
		$out = (string) apply_filters( 'agentimus_post_description', $out, $post );

		// Re-clean after the filter: a provider must not be able to bust the cap or slip
		// markup/newlines into a machine surface or a meta-tag attribute.
		return self::clean( $out );
	}

	/**
	 * The editor-entered (explicit) description for a post, raw.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	public static function explicit( $id ) {
		return (string) get_post_meta( $id, self::META, true );
	}

	/**
	 * The post's excerpt for the fallback: its MANUAL excerpt when set, otherwise a short
	 * summary derived from the body. The derivation strips block delimiters, shortcodes and
	 * tags off the RAW content and trims to {@see SUMMARY_WORDS} — deliberately NOT via
	 * get_the_excerpt()/`the_content`, whose off-loop run can blank the body on some sites
	 * (see {@see Content::markdown_source()}), so the fallback stays robust where rendering
	 * is not. Returns '' only when there is no manual excerpt and no body text at all.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return string
	 */
	public static function excerpt( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}

		$manual = (string) ( $post->post_excerpt ?? '' );
		if ( '' !== trim( $manual ) ) {
			return $manual;
		}

		// On a page a builder owns, post_content is the one place the body ISN'T
		// (stale on Beaver Builder, a between-saves copy on Elementor) — a summary
		// derived from it puts words in the meta description no visitor ever sees.
		// PageBuilders keeps a cached plain-text render of the real body.
		$content = PageBuilders::summary_text( $post );
		if ( null === $content ) {
			$content = (string) ( $post->post_content ?? '' );
			if ( function_exists( 'excerpt_remove_blocks' ) ) {
				$content = excerpt_remove_blocks( $content ); // Drop block delimiters, keep inner HTML.
			}
			$content = strip_shortcodes( $content );
			$content = wp_strip_all_tags( $content );
			$content = trim( (string) preg_replace( '/\s+/', ' ', $content ) );
		}
		if ( '' === $content ) {
			return '';
		}

		$summary = wp_trim_words( $content, self::SUMMARY_WORDS, '' );
		// Don't leave the summary dangling on a separator — a trailing em/en-dash, colon or
		// comma from a mid-sentence cut reads as broken in a meta description. Unicode-aware
		// (byte-wise rtrim would corrupt a multibyte dash); sentence punctuation is kept.
		return (string) preg_replace( '/[\s\x{2014}\x{2013}\x{2012}\-:;,|·]+$/u', '', $summary );
	}

	/**
	 * Normalise a description: strip tags, collapse whitespace to single spaces (so it
	 * is safe in a one-line meta attribute), trim and clip to MAX_LEN.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
		return self::clip( $value, self::MAX_LEN );
	}

	/**
	 * Clip a string to a character length, multibyte-aware when the extension is present,
	 * trimming any trailing partial-word whitespace.
	 *
	 * @param string $value Value.
	 * @param int    $len   Max characters.
	 * @return string
	 */
	private static function clip( $value, $len ) {
		$out = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $len ) : substr( $value, 0, $len );
		return rtrim( $out );
	}

	/**
	 * Sanitiser for the description meta (also used by the meta-box save).
	 *
	 * @param mixed $value Raw meta/input value.
	 * @return string
	 */
	public static function sanitize( $value ) {
		return self::clean( $value );
	}

	/**
	 * auth_callback for register_post_meta: only users who can edit the post may write
	 * this meta over REST.
	 *
	 * @param bool   $allowed   Current decision.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Post ID.
	 * @return bool
	 */
	public static function can_edit( $allowed, $meta_key, $object_id ) {
		return current_user_can( 'edit_post', $object_id );
	}

	/**
	 * Whether this request is a candidate for the authoritative `<meta name="description">`:
	 * the feature is on, it's a singular view of a covered, published, non-password post,
	 * no known schema/SEO plugin owns descriptions, and no filter has vetoed it. The
	 * replace-or-append decision happens later in {@see filter_head()} — this only decides
	 * whether it's worth buffering the head at all.
	 *
	 * @return bool
	 */
	public function should_emit() {
		if ( ! self::enabled() ) {
			return false;
		}
		// Sub-option off: enrich only the JSON-LD + .md surfaces, never the page <head>.
		if ( ! self::meta_tag_enabled() ) {
			return false;
		}
		if ( ! is_singular( Content::post_types() ) ) {
			return false;
		}
		$post = get_post();
		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		// Stand aside up front for a known SEO plugin (same detection Schema uses), so the
		// common case never even buffers the head.
		if ( Schema::seo_plugin_present() ) {
			return false;
		}

		/**
		 * Filter whether Agentimus should consider printing its fallback description meta
		 * on this request. Return false to suppress it (e.g. a theme that emits its own
		 * description tag outside wp_head, which the buffer can't see).
		 *
		 * @param bool     $emit Whether to consider emitting.
		 * @param \WP_Post $post The post being described.
		 */
		return (bool) apply_filters( 'agentimus_emit_meta_description', true, $post );
	}

	/**
	 * Open the head buffer on a candidate request, so {@see buffer_end()} can inspect the
	 * finished head before deciding whether to add the description tag. A no-op otherwise,
	 * so non-candidate pages carry no buffering overhead.
	 */
	public function buffer_start() {
		// should_emit() runs the `agentimus_emit_meta_description` filter (third-party code)
		// on a human-facing page. A throw here would fatal before the buffer even opens, so
		// degrade to "don't buffer, don't emit" and leave the theme's head alone.
		try {
			$emit = $this->should_emit();
		} catch ( \Throwable $e ) {
			return;
		}
		if ( $emit ) {
			$this->buffering = true;
			ob_start();
		}
	}

	/**
	 * Close the head buffer and flush it, injecting the fallback description tag only when
	 * the head carries none already (from any plugin or the theme).
	 */
	public function buffer_end() {
		if ( ! $this->buffering ) {
			return;
		}
		$this->buffering = false;

		// Take the head out of the buffer FIRST, then transform it. filter_head() calls
		// for_post(), which runs the `agentimus_post_description` filter (third-party code).
		// Holding the string means a throw costs us only our own <meta> tag — without this,
		// ob_get_clean() has already emptied the buffer and the entire <head> would be lost
		// along with the request. Never let a description filter blank a page's head.
		$head = (string) ob_get_clean();
		try {
			$head = $this->filter_head( $head );
		} catch ( \Throwable $e ) {
			// $head keeps the theme's original markup — emit it untransformed.
		}

		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput -- buffered head (core/other-hook output) plus our own esc_attr'd tag.
	}

	/**
	 * Given the buffered `<head>` output, return it with Agentimus's `<meta name="description">`
	 * as the authoritative one: when Agentimus has a description of its own (the editor's
	 * value or the excerpt), REPLACE any tag the theme/anything else printed with ours, or
	 * append one when the head has none. When Agentimus has nothing to offer, leave an
	 * existing tag untouched (never blank it). Pure and side-effect-free, so it is
	 * unit-testable without buffering. A real SEO plugin is already excluded in
	 * {@see should_emit()}, so this never overrides Yoast/Rank Math/etc.
	 *
	 * @param string $head The captured head HTML.
	 * @return string
	 */
	public function filter_head( $head ) {
		if ( ! $this->should_emit() ) {
			return $head;
		}
		$desc = self::for_post( get_post() );
		if ( '' === $desc ) {
			return $head; // Nothing of our own — never blank an existing tag.
		}

		$tag = '<meta name="description" content="' . esc_attr( $desc ) . '">';

		// Match a full <meta> tag carrying name="description" in any attribute order, and
		// replace it with ours — an author-written line (or the excerpt) beats a theme's
		// auto-truncation, and there is still exactly one tag. A callback replacement keeps a
		// literal '$' or '\' in the description from being read as a backreference. Matches
		// only name="description" exactly, so name="twitter:description" is left alone.
		$pattern = '/<meta\b[^>]*\bname\s*=\s*(["\'])\s*description\s*\1[^>]*>/i';
		$out     = preg_replace_callback( $pattern, static function () use ( $tag ) { return $tag; }, $head, 1, $count );
		if ( $count > 0 && null !== $out ) {
			return $out;
		}

		return $head . "\n" . $tag . "\n";
	}

	/**
	 * Flush the generated caches when a post's description meta changes via a path that
	 * doesn't fire save_post (e.g. a REST meta-only write). A no-op for every other key.
	 *
	 * @param int|int[] $meta_id   Meta row id(s) — unused.
	 * @param int       $object_id Post ID — unused.
	 * @param string    $meta_key  The meta key that changed.
	 */
	public static function flush_on_meta( $meta_id, $object_id, $meta_key ) {
		if ( self::META === $meta_key ) {
			Cache::flush();
		}
	}

	/**
	 * Render the meta box: a one-line description field, a live character count, and a
	 * preview of what agents will actually get — the typed value, or (when blank) the
	 * excerpt fallback, so the author can see there is never a truly empty description.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$explicit = self::clean( self::explicit( $post->ID ) );
		$fallback = self::clean( self::excerpt( $post ) );

		$obj  = get_post_type_object( $post->post_type );
		$noun = ( $obj && ! empty( $obj->labels->singular_name ) ) ? strtolower( $obj->labels->singular_name ) : $post->post_type;

		$config = wp_json_encode(
			array(
				'fallback' => $fallback,
				'maxLen'   => self::MAX_LEN,
				'ideal'    => 160, // Soft target: the length a search snippet typically shows.
			)
		);

		echo '<div class="agentimus-desc" data-config="' . esc_attr( (string) $config ) . '">';

		// Mention the meta-description role only when the sub-option is actually on, so the
		// box never promises something the settings have turned off.
		/* translators: %s: the content type in lowercase, e.g. "post", "page", "product". */
		$with_meta = __( 'One short sentence saying what this %s is about. AI assistants read it, and it becomes the page’s meta description unless an SEO plugin handles that.', 'agentimus' );
		/* translators: %s: the content type in lowercase, e.g. "post", "page", "product". */
		$ai_only   = __( 'One short sentence saying what this %s is about, written for AI assistants.', 'agentimus' );

		echo '<p class="agentimus-fieldhead"><label for="agentimus-description-input">'
			. esc_html__( 'AI description', 'agentimus' ) . '</label></p>';
		echo '<p class="agentimus-fieldhint">'
			. esc_html( sprintf( self::meta_tag_enabled() ? $with_meta : $ai_only, $noun ) )
			. '</p>';

		echo '<textarea id="agentimus-description-input" name="agentimus_description" class="widefat agentimus-desc__input" rows="3" maxlength="' . esc_attr( (string) self::MAX_LEN ) . '" placeholder="'
			. esc_attr__( 'e.g. A plain-language guide to why the HTTP QUERY method exists and when to use it.', 'agentimus' ) . '">'
			. esc_textarea( $explicit ) . '</textarea>';

		echo '<p class="agentimus-desc__count" aria-live="polite"></p>';

		// Fallback hint: when the field is blank, show the excerpt-derived summary the
		// surfaces will use instead, so "empty" never reads as "no description".
		echo '<p class="agentimus-desc__fallback"' . ( '' === $fallback ? ' style="display:none"' : '' ) . '>'
			. '<span class="agentimus-desc__fallback-label">' . esc_html__( 'If left blank, AI assistants will use:', 'agentimus' ) . '</span> '
			. '<span class="agentimus-desc__fallback-text">' . esc_html( $fallback ) . '</span></p>';

		echo '</div>';
	}

	/**
	 * Progressively enhance the meta box: a live character count (with a soft ideal) and
	 * a show/hide of the fallback hint as the field is filled or cleared. Vanilla JS +
	 * CSS inlined on a registered handle — no build step, loads only on the editor for a
	 * covered type (same pattern as {@see Topics::assets()}).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( ! self::enabled() ) {
			return;
		}
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, Content::post_types(), true ) ) {
			return;
		}

		$handle = 'agentimus-description';
		wp_register_script( $handle, false, array(), AGENTIMUS_VERSION, true );
		wp_enqueue_script( $handle );
		wp_add_inline_script( $handle, self::inline_js() );

		wp_register_style( $handle, false, array(), AGENTIMUS_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, self::inline_css() );
	}

	/**
	 * The character-count + fallback-toggle enhancement script.
	 *
	 * @return string
	 */
	private static function inline_js() {
		return <<<'JS'
(function(){
  var root = document.querySelector('.agentimus-desc');
  if (!root) { return; }
  var cfg; try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
  var input    = root.querySelector('.agentimus-desc__input');
  var count    = root.querySelector('.agentimus-desc__count');
  var fallback = root.querySelector('.agentimus-desc__fallback');
  if (!input || !count) { return; }

  var maxLen = cfg.maxLen > 0 ? cfg.maxLen : 300;
  var ideal  = cfg.ideal  > 0 ? cfg.ideal  : 160;
  var hasFallback = !!(cfg.fallback && String(cfg.fallback).length);

  function update(){
    var len = input.value.replace(/\s+/g, ' ').trim().length;
    count.textContent = len + ' / ' + maxLen + ' characters' + (len > ideal ? ' — search engines may trim past ~' + ideal : '');
    count.className = 'agentimus-desc__count' + (len > ideal ? ' is-over' : '');
    if (fallback && hasFallback) { fallback.style.display = len === 0 ? '' : 'none'; }
  }
  input.addEventListener('input', update);
  update();
})();
JS;
	}

	/**
	 * Styles for the meta box. WordPress-admin palette; scoped to our container.
	 *
	 * @return string
	 */
	private static function inline_css() {
		// (No own-box .hndle rule anymore — the shared box's handle styling
		// lives with Topics, which registers the container.)
		return '.agentimus-desc__count{margin:4px 0 0;color:#646970;font-size:12px}'
			. '.agentimus-desc__count.is-over{color:#bd8600}'
			. '.agentimus-desc__fallback{margin:8px 0 0;padding:6px 8px;background:#f0f6fc;border-left:3px solid #72aee6;border-radius:2px;font-size:12px;color:#2c3338;line-height:1.5}'
			. '.agentimus-desc__fallback-label{font-weight:600;color:#1d2327}';
	}

	/**
	 * Persist the meta box on save. Guarded by nonce, autosave/revision checks and the
	 * edit_post capability; only acts when this POST actually carried our box, so a
	 * quick-edit or REST save never wipes the description.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post — unused (kept for the save_post signature).
	 */
	public function save( $post_id, $post ) {
		if ( ! MetaSave::verified( $post_id, self::NONCE ) ) {
			return;
		}

		$raw   = isset( $_POST['agentimus_description'] ) ? wp_unslash( $_POST['agentimus_description'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by sanitize() below.
		$value = self::sanitize( $raw );
		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $value );
		}
	}

}
