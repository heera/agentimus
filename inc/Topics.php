<?php
/**
 * Topics for AI — per-page topic keywords that flow into the machine-readable
 * surfaces (JSON-LD `keywords`, per-page markdown front matter). Extends the
 * site-level `## Topics` idea (which is derived from categories in
 * {@see LlmsText::topics()}) down to the page level: a *static* list the editor
 * types in, plus a *dynamic* half derived from the page's own tags & categories.
 * Manual entries always win; the merged list is trimmed, deduped
 * case-insensitively and capped.
 *
 * This class is the SINGLE SOURCE OF TRUTH: Schema and Markdown both call
 * {@see for_post()} and emit whatever it returns, so the two surfaces can never
 * disagree about what a page is about. Emission inherits each surface's existing
 * privacy guards (password-protected / unpublished posts are already blocked in
 * Schema::output() and Markdown::post()), so topics add no new leak surface.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Topics {

	/** @var string Post meta: the editor-entered (static) topic list. */
	const META_TOPICS = '_agentimus_topics';

	/** @var string Post meta: '1'/'0' — also include this page's tags & categories. Unset = follow the site default. */
	const META_DERIVE = '_agentimus_topics_derive';

	/** @var string Nonce action/field for the classic meta box. */
	const NONCE = 'agentimus_topics_meta';

	/** @var int Hard cap on the length of a single topic (chars), so one value can't bloat a machine surface. */
	const MAX_LEN = 60;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the post meta, the editor UI and the cache-busting hooks.
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
			add_action( 'save_post', array( $this, 'save' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		}

		// A pure REST meta write (block-editor sidebar / headless) does not always
		// fire save_post, and save_post is what busts the generated caches
		// (Cache::register_flush_hooks). Flush on our meta changing too so llms.txt,
		// the full-text edition and the JSON-LD can never serve stale topics. The
		// editor path fires both; Cache::flush() is idempotent, so the overlap is free.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_on_meta' ), 10, 3 );
		}
	}

	/**
	 * Register both post metas on every agent-visible post type, so they are
	 * sanitised on write and exposed to the REST/block editor (headless-friendly).
	 * The auth callback gates REST writes to users who can edit the post.
	 */
	public static function register_meta() {
		$auth = array( __CLASS__, 'can_edit' );

		foreach ( Content::post_types() as $type ) {
			register_post_meta(
				$type,
				self::META_TOPICS,
				array(
					'type'              => 'array',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_manual' ),
					'auth_callback'     => $auth,
				)
			);
			register_post_meta(
				$type,
				self::META_DERIVE,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_flag' ),
					'auth_callback'     => $auth,
				)
			);
		}
	}

	/**
	 * The resolved topic list for a post: manual ∪ derived (if derivation is on),
	 * trimmed, deduped case-insensitively and capped. Returns [] when the feature
	 * is off or the post is missing. Manual entries come first, so they win the
	 * dedupe against a derived term with the same text.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return string[]
	 */
	public static function for_post( $post ) {
		if ( ! self::enabled() ) {
			return array();
		}
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}

		$topics = self::manual( $post->ID );
		if ( self::derive_enabled( $post->ID ) ) {
			$topics = array_merge( $topics, self::derived( $post ) );
		}
		$topics = self::normalize( $topics, self::cap() );

		/**
		 * Filter the resolved topics for a post. An add-on can add topics an add-on
		 * knows about (e.g. WooCommerce product attributes) or refine the list.
		 *
		 * @param string[] $topics Resolved, normalised topics.
		 * @param \WP_Post $post   The post.
		 */
		$topics = apply_filters( 'agentimus_post_topics', $topics, $post );

		// Re-normalise after the filter: a provider must not be able to bust the
		// cap, inject blanks, or slip markup/whitespace into a machine surface.
		return self::normalize( is_array( $topics ) ? $topics : array(), self::cap() );
	}

	/**
	 * Whether the topics feature is enabled site-wide.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) ( new Settings() )->enabled( 'enable_topics' );
	}

	/**
	 * The editor-entered (static) topics for a post, as a raw list (normalised by
	 * the caller alongside the derived half).
	 *
	 * @param int $id Post ID.
	 * @return string[]
	 */
	private static function manual( $id ) {
		return self::to_list( get_post_meta( $id, self::META_TOPICS, true ) );
	}

	/**
	 * Whether to also derive topics from this post's tags & categories: the saved
	 * per-post choice, or the site default when the post has never set one.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	private static function derive_enabled( $id ) {
		$flag = get_post_meta( $id, self::META_DERIVE, true );
		if ( '1' === $flag ) {
			return true;
		}
		if ( '0' === $flag ) {
			return false;
		}
		// Unset (never saved) → follow the site-wide default.
		return (bool) ( new Settings() )->get( 'topics_derive_default', true );
	}

	/**
	 * The derived-topics seed for a post (its category + tag names, before merging
	 * with the manual list). Public so the editor UI can show a live preview that
	 * matches what {@see for_post()} will emit.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return string[]
	 */
	public static function derived_topics( $post ) {
		$post = get_post( $post );
		return $post ? self::derived( $post ) : array();
	}

	/**
	 * Topic names derived from a post's core taxonomies (categories then tags).
	 * Custom-post-type taxonomies (e.g. WooCommerce product_cat) are intentionally
	 * left to the `agentimus_post_topics` filter, so this stays deterministic.
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	private static function derived( $post ) {
		// Skip the default "Uncategorized" category (and any owner-excluded slugs) so a
		// placeholder term never becomes an AI keyword — consistent with the site-level
		// Topics section (see LlmsText::topics()), which honours this same filter.
		$exclude = (array) apply_filters( 'agentimus_topic_exclude', array( 'uncategorized' ) );

		$names = array();
		foreach ( array( 'category', 'post_tag' ) as $tax ) {
			if ( ! is_object_in_taxonomy( $post->post_type, $tax ) ) {
				continue;
			}
			// Full term objects (not just names) so we can filter by slug.
			$terms = wp_get_post_terms( $post->ID, $tax );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( (array) $terms as $term ) {
				if ( is_object( $term ) && ! in_array( $term->slug, $exclude, true ) ) {
					$names[] = $term->name;
				}
			}
		}
		return $names;
	}

	/**
	 * Sanitiser for the manual topic list meta (also used by the meta-box save):
	 * accepts an array or a comma/newline string, strips tags, trims, drops blanks,
	 * dedupes case-insensitively and caps count + per-item length.
	 *
	 * @param mixed $value Raw meta/input value.
	 * @return string[]
	 */
	public static function sanitize_manual( $value ) {
		return self::normalize( self::to_list( $value ), self::cap() );
	}

	/**
	 * Sanitiser for the derive flag meta: canonicalise any truthy form to '1',
	 * everything else to '0'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_flag( $value ) {
		$truthy = array( '1', 1, true, 'true', 'on', 'yes' );
		return in_array( $value, $truthy, true ) ? '1' : '0';
	}

	/**
	 * auth_callback for register_post_meta: only users who can edit the post may
	 * write these metas over REST.
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
	 * Flush the generated caches when a post's topic meta changes via a path that
	 * doesn't fire save_post (e.g. a REST meta-only write). Hooked on
	 * added/updated/deleted_post_meta; a no-op for every other meta key.
	 *
	 * @param int|int[] $meta_id   Meta row id(s) — unused.
	 * @param int       $object_id Post ID — unused.
	 * @param string    $meta_key  The meta key that changed.
	 */
	public static function flush_on_meta( $meta_id, $object_id, $meta_key ) {
		if ( self::META_TOPICS === $meta_key || self::META_DERIVE === $meta_key ) {
			Cache::flush();
		}
	}

	/**
	 * Add the "Topics for AI" meta box to every agent-visible post type. Skipped
	 * entirely when the feature is off.
	 */
	public function add_meta_box() {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( Content::post_types() as $type ) {
			add_meta_box(
				'agentimus-topics',
				__( 'Topics for AI', 'agentimus' ),
				array( $this, 'render_meta_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box: a comma-separated topics field (progressively enhanced
	 * into a chip editor by {@see assets()}), the derive checkbox, and a preview of
	 * what will be emitted. With JS the preview updates live as you type; without
	 * it, the plain textarea still works and the preview shows the saved state.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$manual   = self::manual( $post->ID );
		$derive   = self::derive_enabled( $post->ID );
		$resolved = self::for_post( $post );

		// Seed for the client-side live preview: the manual list, the page's derived
		// tags/categories, and the same cap/length limits the resolver applies.
		$config = wp_json_encode(
			array(
				'manual'  => array_values( $manual ),
				'derived' => array_values( self::derived_topics( $post ) ),
				'cap'     => self::cap(),
				'maxLen'  => self::MAX_LEN,
			)
		);

		echo '<div class="agentimus-topics" data-config="' . esc_attr( (string) $config ) . '">';

		// Use the content type's own noun (post / page / product / …) so the copy
		// reads right wherever the box appears, instead of a hardcoded "page".
		$obj  = get_post_type_object( $post->post_type );
		$noun = ( $obj && ! empty( $obj->labels->singular_name ) ) ? strtolower( $obj->labels->singular_name ) : $post->post_type;

		echo '<p><label for="agentimus-topics-input">'
			. esc_html(
				sprintf(
					/* translators: %s: the content type in lowercase, e.g. "post", "page", "product". */
					__( 'Topics this %s is about — so AI assistants understand and cite it correctly. Separate with commas.', 'agentimus' ),
					$noun
				)
			)
			. '</label></p>';

		echo '<textarea id="agentimus-topics-input" name="agentimus_topics" class="widefat agentimus-topics__raw" rows="3" placeholder="'
			. esc_attr__( 'e.g. llms.txt, AI visibility, structured data', 'agentimus' ) . '">'
			. esc_textarea( implode( ', ', $manual ) ) . '</textarea>';

		// Only offer "derive from tags & categories" where the content type actually
		// has those taxonomies — on a Page (no tags/categories) the checkbox would do
		// nothing, so it's hidden rather than shown as a dead control.
		if ( is_object_in_taxonomy( $post->post_type, 'category' ) || is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
			echo '<p><label><input type="checkbox" name="agentimus_topics_derive" value="1" '
				. checked( $derive, true, false ) . ' /> '
				. esc_html__( 'Also include all tags & categories', 'agentimus' ) . '</label></p>';
		}

		echo '<p class="description agentimus-topics__preview">' . esc_html__( 'Will be emitted:', 'agentimus' )
			. ' <span class="agentimus-topics__preview-value" data-empty="' . esc_attr__( 'nothing yet', 'agentimus' ) . '">'
			. ( empty( $resolved )
				? '<em>' . esc_html__( 'nothing yet', 'agentimus' ) . '</em>'
				: esc_html( implode( ', ', $resolved ) ) )
			. '</span></p>';

		echo '</div>';
	}

	/**
	 * Progressively enhance the editor meta box into a chip input with a live
	 * preview. Vanilla JS + CSS, inlined on a registered handle (the same pattern
	 * as the admin menu-icon style) so there is no build step and nothing loads on
	 * screens that don't need it — only the post/page editor for a covered type.
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

		$handle = 'agentimus-topics';
		wp_register_script( $handle, false, array(), AGENTIMUS_VERSION, true );
		wp_enqueue_script( $handle );
		wp_add_inline_script( $handle, self::inline_js() );

		wp_register_style( $handle, false, array(), AGENTIMUS_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, self::inline_css() );
	}

	/**
	 * The chip-editor + live-preview enhancement script. Self-contained vanilla JS:
	 * turns .agentimus-topics__raw into chips, keeps the (hidden) textarea in sync
	 * for submission, and recomputes the "Will be emitted" preview — manual ∪
	 * derived seed, deduped case-insensitively and capped — mirroring for_post().
	 *
	 * @return string
	 */
	private static function inline_js() {
		return <<<'JS'
(function(){var root=document.querySelector('.agentimus-topics');if(!root){return;}var cfg;try{cfg=JSON.parse(root.getAttribute('data-config')||'{}');}catch(e){return;}var textarea=root.querySelector('.agentimus-topics__raw');var checkbox=root.querySelector('input[name="agentimus_topics_derive"]');var preview=root.querySelector('.agentimus-topics__preview-value');if(!textarea){return;}var cap=cfg.cap>0?cfg.cap:12;var maxLen=cfg.maxLen>0?cfg.maxLen:60;var derived=Array.isArray(cfg.derived)?cfg.derived:[];var empty=preview?(preview.getAttribute('data-empty')||'nothing yet'):'nothing yet';function clean(s){return String(s).replace(/<[^>]*>/g,'').replace(/\s+/g,' ').trim().slice(0,maxLen);}function splitList(str){return String(str||'').split(/[\r\n,]+/).map(clean).filter(Boolean);}function normalize(list){var out=[],seen={};for(var i=0;i<list.length;i++){var item=clean(list[i]);if(!item){continue;}var key=item.toLowerCase();if(seen[key]){continue;}seen[key]=true;out.push(item);if(out.length>=cap){break;}}return out;}var manual=normalize(splitList(textarea.value));var editor=document.createElement('div');editor.className='agentimus-topics__chips';var input=document.createElement('input');input.type='text';input.className='agentimus-topics__input';input.id='agentimus-topics-chipinput';input.setAttribute('placeholder',textarea.getAttribute('placeholder')||'');textarea.style.display='none';textarea.setAttribute('tabindex','-1');textarea.setAttribute('aria-hidden','true');textarea.parentNode.insertBefore(editor,textarea);editor.appendChild(input);function syncTextarea(){textarea.value=manual.join(', ');}function updatePreview(){if(!preview){return;}var derive=checkbox?checkbox.checked:false;var merged=normalize(manual.concat(derive?derived:[]));if(merged.length){preview.textContent=merged.join(', ');}else{preview.innerHTML='<em></em>';preview.firstChild.textContent=empty;}}function renderChips(){var chips=editor.querySelectorAll('.agentimus-topics__chip');for(var i=0;i<chips.length;i++){chips[i].parentNode.removeChild(chips[i]);}manual.forEach(function(topic,idx){var chip=document.createElement('span');chip.className='agentimus-topics__chip';var text=document.createElement('span');text.className='agentimus-topics__chip-text';text.textContent=topic;var x=document.createElement('button');x.type='button';x.className='agentimus-topics__chip-remove';x.setAttribute('aria-label','Remove '+topic);x.textContent='×';x.addEventListener('click',function(){removeAt(idx);});chip.appendChild(text);chip.appendChild(x);editor.insertBefore(chip,input);});}function commit(){syncTextarea();renderChips();updatePreview();}function removeAt(idx){manual.splice(idx,1);commit();}function addFromInput(){var parts=splitList(input.value);if(parts.length){manual=normalize(manual.concat(parts));input.value='';commit();}}input.addEventListener('keydown',function(e){if('Enter'===e.key||','===e.key){e.preventDefault();addFromInput();}else if('Backspace'===e.key&&''===input.value&&manual.length){removeAt(manual.length-1);}});input.addEventListener('blur',addFromInput);editor.addEventListener('click',function(e){if(e.target===editor){input.focus();}});if(checkbox){checkbox.addEventListener('change',updatePreview);}commit();})();
JS;
	}

	/**
	 * Styles for the chip editor. WordPress-admin palette; scoped to our container.
	 *
	 * @return string
	 */
	private static function inline_css() {
		return '.agentimus-topics__chips{display:flex;flex-wrap:wrap;gap:4px;align-items:center;padding:4px;min-height:34px;border:1px solid #8c8f94;border-radius:4px;background:#fff;cursor:text}'
			. '.agentimus-topics__chips:focus-within{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}'
			. '.agentimus-topics__chip{display:inline-flex;align-items:center;gap:2px;background:#f0f0f1;border:1px solid #dcdcde;border-radius:3px;padding:1px 2px 1px 8px;font-size:12px;line-height:1.8}'
			. '.agentimus-topics__chip-remove{border:0;background:transparent;cursor:pointer;font-size:15px;line-height:1;color:#646970;padding:0 3px}'
			. '.agentimus-topics__chip-remove:hover{color:#d63638}'
			// flex-basis:100% forces the input onto its own row below the chips, always.
			// The scoped selector (and :focus) out-specifies WordPress admin's
			// input[type=text] chrome, so it reads as one clean box, not a field-in-a-field.
			. '.agentimus-topics__chips .agentimus-topics__input,.agentimus-topics__chips .agentimus-topics__input:focus{flex:1 0 100%;min-width:0;margin:0;border:0;outline:0;box-shadow:none;background:transparent;min-height:0;padding:3px 2px;font-size:13px;color:inherit}'
			. '.agentimus-topics__preview{margin-top:8px}'
			. '.agentimus-topics__preview-value{font-style:normal}';
	}

	/**
	 * Persist the meta box on save. Guarded by nonce, autosave/revision checks and
	 * the edit_post capability; only acts when this POST actually carried our meta
	 * box, so a quick-edit or REST save never wipes the topics.
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

		$raw    = isset( $_POST['agentimus_topics'] ) ? wp_unslash( $_POST['agentimus_topics'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by sanitize_manual() below.
		$manual = self::sanitize_manual( $raw );
		if ( empty( $manual ) ) {
			delete_post_meta( $post_id, self::META_TOPICS );
		} else {
			update_post_meta( $post_id, self::META_TOPICS, $manual );
		}

		// The checkbox is absent from the POST when unchecked — record the explicit
		// choice either way, so the site default only applies until the first save.
		update_post_meta( $post_id, self::META_DERIVE, isset( $_POST['agentimus_topics_derive'] ) ? '1' : '0' );
	}

	/**
	 * Coerce an array, or a comma/newline-delimited string, into a list of strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function to_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Normalise a raw list: strip tags, trim, drop blanks, clip each item to
	 * MAX_LEN, dedupe case-insensitively (keeping first-seen casing) and cap the
	 * count.
	 *
	 * @param mixed $list Raw list.
	 * @param int   $cap  Max items.
	 * @return string[]
	 */
	private static function normalize( $list, $cap ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $list as $item ) {
			$item = trim( wp_strip_all_tags( (string) $item ) );
			if ( '' === $item ) {
				continue;
			}
			$item = self::clip( $item, self::MAX_LEN );
			$key  = strtolower( $item );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
			if ( $cap > 0 && count( $out ) >= $cap ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Clip a string to a character length, multibyte-aware when the extension is
	 * present.
	 *
	 * @param string $value Value.
	 * @param int    $len   Max characters.
	 * @return string
	 */
	private static function clip( $value, $len ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $len );
		}
		return substr( $value, 0, $len );
	}

	/**
	 * The per-page topic cap, clamped to a sane range. Public so the editor UI can
	 * enforce the same limit client-side.
	 *
	 * @return int
	 */
	public static function cap() {
		$cap = (int) ( new Settings() )->get( 'topics_max', 12 );
		return max( 1, min( 50, $cap ) );
	}
}
