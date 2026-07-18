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

	/** @var string Transient caching the editor's topic-suggestion pool. */
	const SUGGEST_TRANSIENT = 'agentimus_topic_suggest';

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
		foreach ( self::derive_taxonomies( $post ) as $tax ) {
			// Full term objects (not just names) so we can filter by slug.
			$terms = wp_get_post_terms( $post->ID, $tax );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( (array) $terms as $term ) {
				if ( ! is_object( $term ) || in_array( $term->slug, $exclude, true ) ) {
					continue;
				}
				if ( self::is_meaningful_topic_name( $term->name, $term ) ) {
					$names[] = $term->name;
				}
			}
		}
		return $names;
	}

	/**
	 * Whether a TAXONOMY-DERIVED term name is worth surfacing as an AI topic. Default:
	 * drop a name that is only digits and separators — a placeholder/junk category like
	 * "67", a stray numeric ID, a bare year used as a noise category. Such a value tells
	 * an assistant nothing (a wrong keyword is worse than a missing one), and no site
	 * owner should have to hunt these down for clean output. Applies only to the
	 * auto-derive paths (per-post {@see derived()} and the site-level llms.txt category
	 * list) — a manually typed topic is explicit intent and is never filtered here. It
	 * is overridable for the rare site where a number really is the subject.
	 *
	 * @param string $name Term name.
	 * @param object $term Term object (for filter context).
	 * @return bool
	 */
	public static function is_meaningful_topic_name( $name, $term = null ) {
		$name       = trim( (string) $name );
		$meaningful = '' !== $name && ! preg_match( '/^[\d\s.,\/-]+$/', $name );

		/**
		 * Filter whether an auto-derived taxonomy term becomes an AI topic. Default
		 * false for a purely-numeric name (junk/placeholder categories, stray IDs).
		 * Return true to keep a numeric term — e.g. a history or film site where "1984"
		 * is genuinely the subject.
		 *
		 * @param bool   $meaningful Whether to keep the term as a topic.
		 * @param string $name       The term name.
		 * @param object $term       The term object (may be null).
		 */
		return (bool) apply_filters( 'agentimus_topic_meaningful', $meaningful, $name, $term );
	}

	/**
	 * The taxonomies whose terms feed the auto-derived topics, narrowed to those the
	 * post's type actually has. Core ships only the two WordPress built-ins; a vendor
	 * (e.g. WooCommerce) declares its own via the filter, and those terms then flow
	 * through the SAME path — the derive toggle, the exclude list, dedupe and the cap
	 * — so no vendor-specific code ever lives in core.
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	private static function derive_taxonomies( $post ) {
		/**
		 * Filter which taxonomies the auto-derive reads topics from. Default: the
		 * WordPress built-ins `category` and `post_tag`. A vendor adds its own
		 * (e.g. `product_cat`) so its terms are offered as topics wherever its
		 * content type is agent-visible — Agentimus needs no knowledge of the vendor.
		 *
		 * @param string[] $taxonomies Taxonomy slugs.
		 * @param \WP_Post  $post       The post being described.
		 */
		$taxonomies = (array) apply_filters( 'agentimus_derive_taxonomies', array( 'category', 'post_tag' ), $post );

		$out = array();
		foreach ( $taxonomies as $tax ) {
			$tax = (string) $tax;
			if ( '' !== $tax && ! in_array( $tax, $out, true ) && is_object_in_taxonomy( $post->post_type, $tax ) ) {
				$out[] = $tax;
			}
		}
		return $out;
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
			delete_transient( self::SUGGEST_TRANSIENT ); // A new topic should show up in suggestions.
		}
	}

	/**
	 * Add the "Topics for AI" meta box to every agent-visible post type. Skipped
	 * entirely when the feature is off.
	 */
	public function add_meta_box() {
		// One SHARED box for both AI-dressing features — description on top,
		// topics below — instead of two stacked boxes fighting for sidebar
		// space. Registered under the long-standing 'agentimus-topics' id so
		// any saved metabox order (and muscle memory) carries over. The title
		// tells the truth about which halves are on.
		$topics      = self::enabled();
		$description = Description::enabled();
		if ( ! $topics && ! $description ) {
			return;
		}
		if ( $topics && $description ) {
			$title = __( 'AI description & topics', 'agentimus' );
		} elseif ( $topics ) {
			$title = __( 'Topics for AI', 'agentimus' );
		} else {
			$title = __( 'AI description', 'agentimus' );
		}
		foreach ( Content::post_types() as $type ) {
			add_meta_box(
				'agentimus-topics',
				self::meta_box_title( $title ),
				array( $this, 'render_meta_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * A meta-box title prefixed with the Agentimus "A" mark, so an owner sees at a
	 * glance which plugin owns the box. WordPress echoes meta-box titles as raw HTML,
	 * so the inline SVG renders in the handle; it's decorative (aria-hidden) because
	 * the text still labels the box. Same mark as the admin menu icon (Admin.php), in
	 * the brand teal. A small size + the no-wrap rule in inline_css() keep it on one
	 * line beside WordPress's own header controls in the narrow sidebar.
	 *
	 * @param string $text The plain-text title (already translated).
	 * @return string
	 */
	private static function meta_box_title( $text ) {
		return Admin::brand_title( $text ); // The one shared brand tile.
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
		// The shared box's top half: the AI description, when that feature is on.
		// Its own save/assets hooks are untouched — only the container is shared.
		if ( Description::enabled() ) {
			( new Description( $this->settings ) )->render_meta_box( $post );
			if ( self::enabled() ) {
				echo '<hr style="margin:14px 0 12px;border:0;border-top:1px solid #dcdcde" />';
			}
		}
		if ( ! self::enabled() ) {
			return;
		}

		wp_nonce_field( self::NONCE, self::NONCE );

		$manual   = self::manual( $post->ID );
		$derive   = self::derive_enabled( $post->ID );

		// Seed for the client-side chip editor: the manual list, the page's derived
		// tags/categories, the cap/length limits the resolver applies, plus the derive
		// taxonomies and exclude slugs so the block editor can live-sync the "auto"
		// chips as terms are added/removed (see the live-sync block in inline_js).
		$config = wp_json_encode(
			array(
				'manual'    => array_values( $manual ),
				'derived'   => array_values( self::derived_topics( $post ) ),
				'cap'       => self::cap(),
				'maxLen'    => self::MAX_LEN,
				'deriveTax' => array_values( self::derive_taxonomies( $post ) ),
				// FALSE POSITIVE, not a suppressed query: the VIP sniff matches the key name
				// `exclude` and assumes a get_posts() call. This is a JSON config payload for the
				// topic editor's Vue component — no query runs here.
				'exclude'   => array_values( (array) apply_filters( 'agentimus_topic_exclude', array( 'uncategorized' ) ) ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
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

		// Only offer "derive from taxonomies" where the content type actually has one
		// (core: tags/categories; a vendor may add its own via agentimus_derive_taxonomies).
		// On a type with none — e.g. a plain Page — the checkbox would do nothing, so it's
		// hidden rather than shown as a dead control.
		if ( ! empty( self::derive_taxonomies( $post ) ) ) {
			echo '<p><label><input type="checkbox" name="agentimus_topics_derive" value="1" '
				. checked( $derive, true, false ) . ' /> '
				. esc_html__( 'Also include all tags & categories', 'agentimus' ) . '</label></p>';
		}

		// Autocomplete from a consistent vocabulary — the chip input references this
		// via `list` (see inline_js), so the browser suggests as the author types.
		$suggestions = self::suggestions();
		if ( ! empty( $suggestions ) ) {
			echo '<datalist id="agentimus-topics-suggest">';
			foreach ( $suggestions as $suggestion ) {
				echo '<option value="' . esc_attr( $suggestion ) . '"></option>';
			}
			echo '</datalist>';
		}

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
	 * The chip-editor enhancement script. Self-contained vanilla JS: turns
	 * .agentimus-topics__raw into a chip strip (manual chips you can remove, plus
	 * tag/category "auto" chips when derivation is on) and keeps the hidden textarea in
	 * sync for submission.
	 *
	 * @return string
	 */
	private static function inline_js() {
		return <<<'JS'
(function(){
  var root = document.querySelector('.agentimus-topics');
  if (!root) { return; }
  var cfg; try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { return; }
  var textarea = root.querySelector('.agentimus-topics__raw');
  var checkbox = root.querySelector('input[name="agentimus_topics_derive"]');
  if (!textarea) { return; }

  var cap    = cfg.cap > 0 ? cfg.cap : 12;
  var maxLen = cfg.maxLen > 0 ? cfg.maxLen : 60;
  var derived = Array.isArray(cfg.derived) ? cfg.derived : [];
  var showAuto = checkbox ? checkbox.checked : false;

  function lc(s){ return String(s).toLowerCase(); }
  function clean(s){ return String(s).replace(/<[^>]*>/g,'').replace(/\s+/g,' ').trim().slice(0,maxLen); }
  function splitList(str){ return String(str||'').split(/[\r\n,]+/).map(clean).filter(Boolean); }
  function normalize(list){ var out=[],seen={}; for(var i=0;i<list.length;i++){ var item=clean(list[i]); if(!item){continue;} var key=item.toLowerCase(); if(seen[key]){continue;} seen[key]=true; out.push(item); if(out.length>=cap){break;} } return out; }

  var manual = normalize(splitList(textarea.value));

  // ---- chip editor (progressive enhancement of the textarea) ----
  var editor = document.createElement('div');
  editor.className = 'agentimus-topics__chips';
  var input = document.createElement('input');
  input.type = 'text';
  input.className = 'agentimus-topics__input';
  input.id = 'agentimus-topics-chipinput';
  input.setAttribute('placeholder', textarea.getAttribute('placeholder') || '');
  if (document.getElementById('agentimus-topics-suggest')) { input.setAttribute('list','agentimus-topics-suggest'); }
  textarea.style.display = 'none';
  textarea.setAttribute('tabindex','-1');
  textarea.setAttribute('aria-hidden','true');
  textarea.parentNode.insertBefore(editor, textarea);
  editor.appendChild(input);

  function syncTextarea(){ textarea.value = manual.join(', '); }

  // The unified topic set: manual chips, then tag/category ("auto") chips when the
  // derive box is on. Auto chips a manual entry already covers are dropped (dedupe).
  function currentTopics(){
    var out = manual.map(function(n){ return { name:n, kind:'manual' }; });
    if (showAuto) {
      var seen = {}; manual.forEach(function(n){ seen[lc(n)] = 1; });
      derived.forEach(function(n){ var k = lc(n); if (!seen[k]) { seen[k] = 1; out.push({ name:n, kind:'auto' }); } });
    }
    return out;
  }

  function renderChips(){
    var chips = editor.querySelectorAll('.agentimus-topics__chip');
    for (var i=0;i<chips.length;i++){ chips[i].parentNode.removeChild(chips[i]); }
    currentTopics().forEach(function(t){
      var chip = document.createElement('span');
      chip.className = 'agentimus-topics__chip' + (t.kind==='auto' ? ' is-auto' : '');
      var text = document.createElement('span'); text.className='agentimus-topics__chip-text'; text.textContent = t.name; chip.appendChild(text);
      if (t.kind==='auto') {
        var badge = document.createElement('span'); badge.className='agentimus-topics__chip-auto'; badge.textContent='auto'; chip.appendChild(badge);
      } else {
        var x = document.createElement('button'); x.type='button'; x.className='agentimus-topics__chip-remove'; x.setAttribute('aria-label','Remove ' + t.name); x.textContent='×';
        x.addEventListener('click', function(){ removeTopic(t.name); });
        chip.appendChild(x);
      }
      editor.insertBefore(chip, input);
    });
  }

  function commit(){ syncTextarea(); renderChips(); }
  function removeTopic(name){ manual = manual.filter(function(n){ return n !== name; }); commit(); }
  function addFromInput(){ var parts = splitList(input.value); if (parts.length) { manual = normalize(manual.concat(parts)); input.value=''; commit(); } }

  input.addEventListener('keydown', function(e){
    if ('Enter'===e.key || ','===e.key) { e.preventDefault(); addFromInput(); }
    else if ('Backspace'===e.key && ''===input.value && manual.length) { removeTopic(manual[manual.length-1]); }
  });
  input.addEventListener('blur', addFromInput);
  editor.addEventListener('click', function(e){ if (e.target===editor) { input.focus(); } });
  if (checkbox) { checkbox.addEventListener('change', function(){ showAuto = checkbox.checked; commit(); }); }
  commit();

  // Accept topics drafted by the AI assist (see Assist): it dispatches this event with a
  // list of names, which merge into the manual chips exactly like typed ones.
  root.addEventListener('agentimus-topics:add', function(e){
    var names = e && e.detail && e.detail.topics;
    if (Array.isArray(names) && names.length) { manual = normalize(manual.concat(names)); commit(); }
  });

  // Live-sync the tag/category "auto" chips with the block editor as you add or remove
  // terms — no save/refresh. Block editor only (needs wp.data); the classic editor keeps
  // the on-load snapshot, and a non-core derive taxonomy still refreshes on save. Mirrors
  // the server's default rules (excluded slugs + purely-numeric names skipped); the SAVED
  // output is always the authoritative server value.
  (function(){
    var attrFor = { category: 'categories', post_tag: 'tags' };
    var watch = (Array.isArray(cfg.deriveTax) ? cfg.deriveTax : []).filter(function(t){ return attrFor[t]; });
    if (!watch.length) { return; }
    var exclude = {}; (Array.isArray(cfg.exclude) ? cfg.exclude : []).forEach(function(s){ exclude[String(s).toLowerCase()] = 1; });
    function compute(){
      if (!(window.wp && wp.data && wp.data.select)) { return null; }
      var ed = wp.data.select('core/editor'), core = wp.data.select('core');
      if (!ed || !core || !ed.getEditedPostAttribute) { return null; }
      var names = [];
      watch.forEach(function(tax){
        (ed.getEditedPostAttribute(attrFor[tax]) || []).forEach(function(id){
          var rec = core.getEntityRecord('taxonomy', tax, id);
          if (!rec || !rec.name) { return; }
          if (exclude[String(rec.slug || '').toLowerCase()]) { return; }
          if (/^[\d\s.,\/-]+$/.test(String(rec.name).trim())) { return; }
          names.push(rec.name);
        });
      });
      return names;
    }
    var lastKey = derived.slice().sort().join('');
    function start(tries){
      if (!(window.wp && wp.data && wp.data.subscribe)) { if (tries > 0) { setTimeout(function(){ start(tries - 1); }, 300); } return; }
      wp.data.subscribe(function(){
        var next = compute();
        if (!next) { return; }
        var key = next.slice().sort().join('');
        if (key !== lastKey) { lastKey = key; derived = next; if (showAuto) { renderChips(); } }
      });
    }
    start(20);
  })();
})();
JS;
	}

	/**
	 * Styles for the chip editor. WordPress-admin palette; scoped to our container.
	 *
	 * @return string
	 */
	private static function inline_css() {
		// Keep the "A" mark + title on one line beside WP's own header controls
		// (move up/down + collapse) in the narrow sidebar, instead of wrapping.
		return '#agentimus-topics .hndle{white-space:nowrap}'
			. '.agentimus-topics__chips{display:flex;flex-wrap:wrap;gap:5px;align-items:center;padding:5px;min-height:36px;border:1px solid #8c8f94;border-radius:4px;background:#fff;cursor:text}'
			. '.agentimus-topics__chips:focus-within{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}'
			. '.agentimus-topics__chip{display:inline-flex;align-items:center;gap:5px;background:#f0f0f1;border:1px solid #dcdcde;border-radius:3px;padding:2px 4px 2px 8px;font-size:12px;line-height:1.7;color:#2c3338}'
			. '.agentimus-topics__chip.is-auto{background:#f4f1fa;border-color:#ddd4ee;border-style:dashed;color:#5b5170}'
			. '.agentimus-topics__chip-auto{font-size:9px;letter-spacing:.05em;text-transform:uppercase;color:#8073a6;background:#ece5f7;border-radius:3px;padding:1px 4px}'
			. '.agentimus-topics__chip-remove{border:0;background:transparent;cursor:pointer;font-size:14px;line-height:1;color:#787c82;padding:0 2px;border-radius:2px}'
			. '.agentimus-topics__chip-remove:hover{color:#d63638}'
			// The chip-add input sits on its own row below the chips (flex-basis:100%);
			// the scoped selector out-specifies WordPress admin input chrome.
			. '.agentimus-topics__chips .agentimus-topics__input,.agentimus-topics__chips .agentimus-topics__input:focus{flex:1 0 100%;min-width:0;margin:0;border:0;outline:0;box-shadow:none;background:transparent;min-height:0;padding:3px 2px;font-size:13px;color:inherit}';
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
	 * A de-duplicated pool of topics to suggest in the editor, so authors reuse a
	 * consistent vocabulary instead of fragmenting it ("WP" vs "WordPress"). Drawn
	 * from topics already used elsewhere, the site's own tags & categories, and the
	 * declared Expertise. Bounded and cached (busts when a post's topics change).
	 *
	 * @return string[]
	 */
	public static function suggestions() {
		$cached = get_transient( self::SUGGEST_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$pool = array();

		// 1. Topics already used on other posts (bounded scan, most-used first).
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 500", self::META_TOPICS ) );
			$freq = array();
			foreach ( (array) $rows as $val ) {
				foreach ( (array) maybe_unserialize( $val ) as $topic ) {
					$topic = trim( (string) $topic );
					if ( '' === $topic ) {
						continue;
					}
					$key = strtolower( $topic );
					if ( ! isset( $freq[ $key ] ) ) {
						$freq[ $key ] = array( 'label' => $topic, 'n' => 0 );
					}
					++$freq[ $key ]['n'];
				}
			}
			uasort( $freq, static function ( $a, $b ) { return $b['n'] - $a['n']; } );
			foreach ( $freq as $row ) {
				$pool[] = $row['label'];
			}
		}

		// 2. The site's own tags & categories — the canonical existing vocabulary.
		foreach ( array( 'category', 'post_tag' ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 100, 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) ) {
				$pool = array_merge( $pool, (array) $terms );
			}
		}

		// 3. The site's declared Expertise (its knowsAbout pillars).
		$pool = array_merge( $pool, (array) ( new Settings() )->identity( 'expertise', array() ) );

		/**
		 * Filter the topic suggestions offered in the editor's Topics-for-AI box.
		 *
		 * @param string[] $pool Suggested topic strings.
		 */
		$pool = apply_filters( 'agentimus_topic_suggestions', $pool );

		$out  = array();
		$seen = array();
		foreach ( (array) $pool as $item ) {
			$item = trim( wp_strip_all_tags( (string) $item ) );
			if ( '' === $item ) {
				continue;
			}
			// Never SUGGEST a bare number ("67", a stray ID) as a topic — same rule the
			// derived/emitted topics use, so the editor's own autocomplete stays clean.
			if ( ! self::is_meaningful_topic_name( $item ) ) {
				continue;
			}
			$key = strtolower( $item );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
			if ( count( $out ) >= 150 ) {
				break;
			}
		}

		set_transient( self::SUGGEST_TRANSIENT, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Topic coverage across published, agent-visible content, for the Readiness
	 * report: how many such posts exist and how many carry their own (manual) topics,
	 * plus whether auto-fill is on by default. Cheap — two counts, no per-post render.
	 *
	 * @return array{total:int,with_topics:int,derive:bool}
	 */
	public static function coverage() {
		$types = Content::post_types();

		$total = 0;
		foreach ( $types as $type ) {
			$counts = wp_count_posts( $type );
			if ( is_object( $counts ) && isset( $counts->publish ) ) {
				$total += (int) $counts->publish;
			}
		}

		$with = 0;
		global $wpdb;
		if ( ! empty( $types ) && isset( $wpdb ) && is_object( $wpdb ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
			$args         = array_merge( array( self::META_TOPICS, 'a:0:{}' ), $types );
			// ReplacementsWrongNumber is a FALSE POSITIVE here, not a suppressed bug: the sniff
			// counts the literal placeholders it can see and cannot evaluate $placeholders, which
			// array_fill() builds to match $types exactly. $args is passed as prepare()'s single
			// array argument, which WPDB explicitly supports, so the counts do line up at runtime.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$with = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value <> '' AND m.meta_value <> %s AND p.post_status = 'publish' AND p.post_type IN ($placeholders)", $args ) );
		}

		return array(
			'total'       => $total,
			'with_topics' => $with,
			'derive'      => (bool) ( new Settings() )->get( 'topics_derive_default', true ),
		);
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
