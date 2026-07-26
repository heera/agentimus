<?php
/**
 * Internal-link suggestions — "this post should link to these posts of yours".
 *
 * Finding candidates is DELIBERATELY not an AI job: related posts fall out of
 * signals the site already has — shared topics ({@see Topics}), shared
 * categories and tags, and a candidate's title wording appearing in the text —
 * free, instant, and available on sites with no AI provider at all. The ONE
 * optional AI request (through {@see Assist::generate()}, the same governed
 * choke point as every Agentimus draft) only dresses the result: it picks the
 * anchor phrase and writes the one-line "why". An AI-proposed phrase is never
 * trusted blindly — if it doesn't appear verbatim in the text, the local
 * finder's phrase (or the append fallback) is used instead.
 *
 * Nothing here writes anything. The editor box's Insert is a plain block-editor
 * edit (undo works, the post saves when the author saves), and the MCP twin is
 * read-only — an agent that wants to ACT on a suggestion goes through the
 * governed update tool like any other write.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class InternalLinks {

	const NS    = 'agentimus/v1';
	const ROUTE = '/internal-links/suggest';

	/** Suggestions per request — three good links beat ten plausible ones. */
	const MAX_SUGGESTIONS = 3;

	/** Recent published posts scored per request — bounds the work on big sites. */
	const POOL = 150;

	/** Chars of body text the phrase finder (and the AI dresser) work against. */
	const MAX_BODY_CHARS = 8000;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * REST on every request; the editor box only in the admin.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		}
	}

	/**
	 * Whether the feature is offered. No settings card — it's a tool, not an
	 * emission — but an owner can veto it in code.
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filter whether the internal-link suggestions are offered.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'agentimus_internal_links_enabled', true );
	}

	/* ---------------------------------------------------------------------- *
	 *  Finding — local signals only
	 * ---------------------------------------------------------------------- */

	/**
	 * The posts this one should most plausibly link to, scored on local signals.
	 *
	 * @param \WP_Post $post  The post being edited.
	 * @param int      $limit Max candidates.
	 * @return array<int,array{post:\WP_Post,score:int,shared:string[]}>
	 */
	public static function candidates( \WP_Post $post, $limit = self::MAX_SUGGESTIONS ) {
		$pool = get_posts(
			array(
				'post_type'        => Content::post_types(),
				'post_status'      => 'publish',
				'numberposts'      => self::POOL,
				'exclude'          => array( $post->ID ),
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$body_lc   = self::body_text( $post, true );
		$my_topics = self::topic_set( $post );
		$my_terms  = self::term_slugs( $post );

		$scored = array();
		foreach ( $pool as $candidate ) {
			if ( ! $candidate instanceof \WP_Post || $candidate->ID === $post->ID ) {
				continue;
			}
			$shared = array();
			$score  = 0;

			foreach ( array_intersect( $my_topics, self::topic_set( $candidate ) ) as $topic ) {
				$score   += 3;
				$shared[] = $topic;
			}
			foreach ( array_intersect( $my_terms, self::term_slugs( $candidate ) ) as $slug ) {
				$score += 2;
			}
			// The candidate's title wording appearing in THIS post's text is the
			// strongest "you already talk about it" signal.
			$hits = 0;
			foreach ( self::meaningful_words( (string) $candidate->post_title ) as $word ) {
				if ( false !== strpos( $body_lc, $word ) ) {
					$hits++;
				}
			}
			$score += min( 5, $hits );

			// One generic title word appearing once is noise ("single", "page"…):
			// a suggestion needs at least two points of evidence behind it.
			if ( $score >= 2 ) {
				$scored[] = array(
					'post'   => $candidate,
					'score'  => $score,
					'shared' => array_slice( array_values( array_unique( $shared ) ), 0, 3 ),
				);
			}
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_slice( $scored, 0, max( 1, (int) $limit ) );
	}

	/**
	 * The longest run of a candidate title's words that appears verbatim in the
	 * text — the phrase Insert would link. '' when nothing usable appears (the
	 * suggestion then offers the append fallback instead).
	 *
	 * @param string $title The candidate post's title.
	 * @param string $body  The subject post's plain text.
	 * @return string The phrase AS IT APPEARS in the body (original casing).
	 */
	public static function anchor_phrase( $title, $body ) {
		$words = preg_split( '/\s+/', trim( wp_strip_all_tags( (string) $title ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $words ) || '' === $body ) {
			return '';
		}
		$count = count( $words );

		// Longest n-grams first; within a length, leftmost first. Single words must
		// be meaningful — linking "the" helps nobody.
		for ( $len = $count; $len >= 1; $len-- ) {
			for ( $start = 0; $start + $len <= $count; $start++ ) {
				$phrase = implode( ' ', array_slice( $words, $start, $len ) );
				if ( 1 === $len && ! in_array( strtolower( $phrase ), self::meaningful_words( $phrase ), true ) ) {
					continue;
				}
				$found = self::find_in_text( $phrase, $body );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}
		return '';
	}

	/**
	 * Case-insensitive, word-bounded search returning the phrase in the body's
	 * own casing ('' when absent).
	 *
	 * @param string $phrase Needle.
	 * @param string $body   Haystack (plain text).
	 * @return string
	 */
	public static function find_in_text( $phrase, $body ) {
		$pattern = '/(?<![A-Za-z0-9])(' . preg_quote( $phrase, '/' ) . ')(?![A-Za-z0-9])/iu';
		return preg_match( $pattern, $body, $m ) ? $m[1] : '';
	}

	/* ---------------------------------------------------------------------- *
	 *  Suggesting — local rows, optionally dressed by ONE AI call
	 * ---------------------------------------------------------------------- */

	/**
	 * The suggestion rows for a post: candidate + anchor phrase + why. With a
	 * configured AI, one request picks better phrases and writes the "why";
	 * without one, the local finder's phrase and a plain shared-signals line do
	 * the job — the feature never needs a provider.
	 *
	 * @param \WP_Post $post    The post.
	 * @param bool     $use_ai  Whether to spend the one dressing call.
	 * @return array<int,array{id:int,title:string,url:string,phrase:string,why:string}>
	 */
	public function suggest( \WP_Post $post, $use_ai ) {
		$body = self::body_text( $post, false );
		$rows = array();

		foreach ( self::candidates( $post ) as $candidate ) {
			$target = $candidate['post'];
			$why    = ! empty( $candidate['shared'] )
				? sprintf(
					/* translators: %s: comma-separated shared topic list. */
					__( 'You both cover: %s.', 'agentimus' ),
					implode( ', ', $candidate['shared'] )
				)
				: __( 'Its subject appears in this post’s text.', 'agentimus' );

			$rows[] = array(
				'id'     => (int) $target->ID,
				'title'  => (string) get_the_title( $target ),
				'url'    => (string) get_permalink( $target ),
				'phrase' => self::anchor_phrase( (string) $target->post_title, $body ),
				'why'    => $why,
			);
		}

		if ( $use_ai && ! empty( $rows ) ) {
			$rows = $this->dress_with_ai( $post, $body, $rows );
		}
		return $rows;
	}

	/**
	 * ONE AI request dresses every row: a better anchor phrase and a specific
	 * "why" per candidate. The reply is data, not gospel — a proposed phrase
	 * that isn't verbatim in the text is discarded in favour of the local one.
	 *
	 * @param \WP_Post $post The post.
	 * @param string   $body Its plain text.
	 * @param array    $rows Locally built rows.
	 * @return array Same rows, possibly improved.
	 */
	private function dress_with_ai( \WP_Post $post, $body, array $rows ) {
		$list = '';
		foreach ( $rows as $i => $row ) {
			$list .= ( $i + 1 ) . '. ' . $row['title'] . "\n";
		}
		$system = __( 'You place internal links in an article. For EACH numbered candidate post, pick the best short phrase from the ARTICLE TEXT to turn into a link to it, and give one short plain reason. The phrase MUST be copied verbatim from the article text — never invent or alter words. Reply with one line per candidate, exactly: number | phrase | reason. No other text.', 'agentimus' );
		$prompt = sprintf(
			"Article title: %s\n\nArticle text:\n%s\n\nCandidate posts to link to:\n%s",
			get_the_title( $post ),
			function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 4000 ) : substr( $body, 0, 4000 ),
			$list
		);

		$reply = ( new Assist( $this->settings ) )->generate( $system, $prompt, 800 );
		if ( is_wp_error( $reply ) ) {
			return $rows; // The local rows already stand on their own.
		}

		foreach ( explode( "\n", (string) $reply ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 3 ) {
				continue;
			}
			$index = (int) $parts[0] - 1;
			if ( ! isset( $rows[ $index ] ) ) {
				continue;
			}
			$phrase = trim( $parts[1], " \t\"'“”‘’" );
			$found  = self::find_in_text( $phrase, $body );
			if ( '' !== $found ) {
				$rows[ $index ]['phrase'] = $found;
			}
			$why = sanitize_text_field( $parts[2] );
			if ( '' !== $why ) {
				$rows[ $index ]['why'] = $why;
			}
		}
		return $rows;
	}

	/* ---------------------------------------------------------------------- *
	 *  REST
	 * ---------------------------------------------------------------------- */

	/**
	 * POST /internal-links/suggest — gated per post by edit_post, exactly like
	 * the field the suggestions serve.
	 */
	public function register_rest() {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_suggest' ),
				'permission_callback' => static function ( $request ) {
					return current_user_can( 'edit_post', absint( $request['post'] ) );
				},
				'args'                => array(
					'post' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * The route handler. AI is used when available; its absence is a quieter
	 * result, never an error.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_suggest( \WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'agentimus_field_off', __( 'Internal-link suggestions are turned off.', 'agentimus' ), array( 'status' => 400 ) );
		}
		$post = get_post( absint( $request['post'] ) );
		if ( ! $post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error( 'agentimus_not_found', __( 'That content is not available.', 'agentimus' ), array( 'status' => 404 ) );
		}

		$suggestions = $this->suggest( $post, Assist::ai_available() );
		return rest_ensure_response( array( 'suggestions' => $suggestions ) );
	}

	/* ---------------------------------------------------------------------- *
	 *  Editor box
	 * ---------------------------------------------------------------------- */

	/**
	 * Its own sidebar box, beside the AI description/topics box (Heera's D1).
	 */
	public function add_meta_box() {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( Content::post_types() as $type ) {
			add_meta_box(
				'agentimus-internal-links',
				Topics::meta_box_title( __( 'Link to your own posts', 'agentimus' ) ),
				array( $this, 'render_meta_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * The box shell — the button and an empty list; the JS does the rest.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function render_meta_box( $post ) {
		?>
		<div class="agentimus-il" data-post="<?php echo (int) $post->ID; ?>">
			<p class="agentimus-il__intro"><?php esc_html_e( 'Posts that link to each other help readers, search engines and AI assistants find the rest of your site. Agentimus can suggest which of your own posts this one should link to.', 'agentimus' ); ?></p>
			<button type="button" class="button agentimus-il__suggest"><?php esc_html_e( 'Suggest links', 'agentimus' ); ?></button>
			<span class="agentimus-il__note" aria-live="polite"></span>
			<div class="agentimus-il__list"></div>
		</div>
		<?php
	}

	/**
	 * The box's behaviour — enqueued only on the editor for covered types.
	 * Insert is a BLOCK-EDITOR edit: it wraps the phrase where it already sits
	 * (or appends a "See also" paragraph when no phrase exists), so undo is the
	 * editor's own and nothing saves until the author saves.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, Content::post_types(), true ) || ! self::enabled() ) {
			return;
		}

		$config = array(
			'endpoint' => esc_url_raw( rest_url( self::NS . self::ROUTE ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'i18n'     => array(
				'working'   => __( 'Looking for related posts…', 'agentimus' ),
				'none'      => __( 'No clearly related posts found yet — as your site grows, suggestions appear here.', 'agentimus' ),
				'needsSave' => __( 'Save a draft first, then try again.', 'agentimus' ),
				'error'     => __( 'Couldn’t fetch suggestions — please try again.', 'agentimus' ),
				'insert'    => __( 'Insert', 'agentimus' ),
				'append'    => __( 'Add “See also”', 'agentimus' ),
				'dismiss'   => __( 'Dismiss', 'agentimus' ),
				'linked'    => __( 'Linked ✓', 'agentimus' ),
				'added'     => __( 'Added ✓', 'agentimus' ),
				'links'     => __( 'Links the phrase', 'agentimus' ),
				'noPhrase'  => __( 'No matching phrase in the text — adds a “See also” line at the end instead.', 'agentimus' ),
				'blockOnly' => __( 'Inserting needs the block editor; copy the address instead:', 'agentimus' ),
			),
		);

		wp_register_script( 'agentimus-internal-links', false, array(), AGENTIMUS_VERSION, true );
		wp_enqueue_script( 'agentimus-internal-links' );
		wp_add_inline_script( 'agentimus-internal-links', 'window.agentimusIl=' . wp_json_encode( $config ) . ';', 'before' );
		wp_add_inline_script( 'agentimus-internal-links', self::inline_js() );

		wp_register_style( 'agentimus-internal-links', false, array(), AGENTIMUS_VERSION );
		wp_enqueue_style( 'agentimus-internal-links' );
		wp_add_inline_style( 'agentimus-internal-links', self::inline_css() );
	}

	/* ---------------------------------------------------------------------- *
	 *  Shared helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * The post's plain text, bounded.
	 *
	 * @param \WP_Post $post  Post.
	 * @param bool     $lower Lowercased for matching.
	 * @return string
	 */
	private static function body_text( \WP_Post $post, $lower ) {
		$body = (string) Content::markdown_source( $post );
		if ( '' === trim( $body ) ) {
			$body = (string) $post->post_content;
		}
		$body = wp_strip_all_tags( $body );
		$body = trim( (string) preg_replace( '/\s+/', ' ', $body ) );
		$body = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, self::MAX_BODY_CHARS ) : substr( $body, 0, self::MAX_BODY_CHARS );
		return $lower ? strtolower( $body ) : $body;
	}

	/**
	 * Lowercased resolved topics for overlap scoring.
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	private static function topic_set( \WP_Post $post ) {
		return array_map( 'strtolower', Topics::for_post( $post ) );
	}

	/**
	 * Category + tag slugs.
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	private static function term_slugs( \WP_Post $post ) {
		$slugs = array();
		foreach ( array( 'category', 'post_tag' ) as $tax ) {
			$terms = wp_get_post_terms( $post->ID, $tax );
			foreach ( is_wp_error( $terms ) ? array() : (array) $terms as $term ) {
				if ( is_object( $term ) && ! empty( $term->slug ) && 'uncategorized' !== $term->slug ) {
					$slugs[] = (string) $term->slug;
				}
			}
		}
		return $slugs;
	}

	/**
	 * The words in a string worth matching on: lowercased, four letters up.
	 *
	 * @param string $text Input.
	 * @return string[]
	 */
	public static function meaningful_words( $text ) {
		$words = preg_split( '/[^a-z0-9]+/i', strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values(
			array_filter(
				(array) $words,
				static function ( $word ) {
					return strlen( $word ) >= 4;
				}
			)
		);
	}

	/**
	 * The box's behaviour. Vanilla JS, no build step, mirroring the Assist
	 * injector's conventions.
	 *
	 * @return string
	 */
	private static function inline_js() {
		return <<<'JS'
(function(){
  var CFG = window.agentimusIl || {};
  if (!CFG.endpoint) { return; }

  function blocksReady(){
    try { return !!(window.wp && wp.data && wp.blocks && wp.data.dispatch('core/block-editor')); }
    catch (e) { return false; }
  }
  function escAttr(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
  function escReg(s){ return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  // Wrap the FIRST occurrence of phrase (outside existing tags) in one paragraph
  // or heading block. Returns true when a block was edited.
  function linkPhrase(phrase, url){
    var sel = wp.data.select('core/block-editor');
    var blocks = sel.getBlocks();
    var re = new RegExp('(^|>)([^<]*?)(' + escReg(phrase) + ')', 'i');
    function walk(list){
      for (var i = 0; i < list.length; i++) {
        var b = list[i];
        if ((b.name === 'core/paragraph' || b.name === 'core/heading') && typeof (b.attributes||{}).content === 'string') {
          var html = b.attributes.content;
          if (html.indexOf('<a') === -1 || true) {
            var m = html.match(re);
            if (m) {
              var replaced = html.replace(re, function(_, pre, mid, hit){
                return pre + mid + '<a href="' + escAttr(url) + '">' + hit + '</a>';
              });
              wp.data.dispatch('core/block-editor').updateBlockAttributes(b.clientId, { content: replaced });
              return true;
            }
          }
        }
        if (b.innerBlocks && b.innerBlocks.length && walk(b.innerBlocks)) { return true; }
      }
      return false;
    }
    return walk(blocks);
  }

  function appendSeeAlso(title, url){
    var block = wp.blocks.createBlock('core/paragraph', {
      content: 'See also: <a href="' + escAttr(url) + '">' + escAttr(title) + '</a>'
    });
    wp.data.dispatch('core/block-editor').insertBlocks(block);
    return true;
  }

  function render(box, rows){
    var list = box.querySelector('.agentimus-il__list');
    list.innerHTML = '';
    if (!rows.length) {
      list.innerHTML = '<p class="agentimus-il__none">' + escAttr(CFG.i18n.none) + '</p>';
      return;
    }
    rows.forEach(function(row){
      var item = document.createElement('div');
      item.className = 'agentimus-il__item';
      var phraseNote = row.phrase
        ? '<p class="agentimus-il__phrase">' + escAttr(CFG.i18n.links) + ' <code>' + escAttr(row.phrase) + '</code></p>'
        : '<p class="agentimus-il__phrase agentimus-il__phrase--none">' + escAttr(CFG.i18n.noPhrase) + '</p>';
      item.innerHTML =
        '<p class="agentimus-il__title">' + escAttr(row.title) + '</p>' +
        '<p class="agentimus-il__why">' + escAttr(row.why) + '</p>' +
        phraseNote +
        '<div class="agentimus-il__row">' +
          '<button type="button" class="button button-small agentimus-il__insert">' +
            escAttr(row.phrase ? CFG.i18n.insert : CFG.i18n.append) + '</button>' +
          '<button type="button" class="button button-small agentimus-il__dismiss">' + escAttr(CFG.i18n.dismiss) + '</button>' +
        '</div>';
      item.querySelector('.agentimus-il__dismiss').addEventListener('click', function(){ item.remove(); });
      item.querySelector('.agentimus-il__insert').addEventListener('click', function(){
        var btn = this;
        if (!blocksReady()) {
          var fallback = document.createElement('p');
          fallback.className = 'agentimus-il__why';
          fallback.textContent = CFG.i18n.blockOnly + ' ' + row.url;
          item.appendChild(fallback);
          return;
        }
        var done = row.phrase ? linkPhrase(row.phrase, row.url) : appendSeeAlso(row.title, row.url);
        if (!done) { done = appendSeeAlso(row.title, row.url); }
        if (done) {
          btn.textContent = row.phrase ? CFG.i18n.linked : CFG.i18n.added;
          btn.disabled = true;
        }
      });
      list.appendChild(item);
    });
  }

  function boot(){
    var box = document.querySelector('.agentimus-il');
    if (!box) { return false; }
    var btn = box.querySelector('.agentimus-il__suggest');
    var note = box.querySelector('.agentimus-il__note');
    btn.addEventListener('click', function(){
      var id = parseInt(box.getAttribute('data-post'), 10);
      if (!id) { note.textContent = CFG.i18n.needsSave; return; }
      btn.disabled = true;
      note.textContent = CFG.i18n.working;
      fetch(CFG.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ post: id })
      }).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, body: j }; }); })
        .then(function(res){
          btn.disabled = false;
          if (!res.ok) { note.textContent = (res.body && res.body.message) || CFG.i18n.error; return; }
          note.textContent = '';
          render(box, (res.body && res.body.suggestions) || []);
        })
        .catch(function(){ btn.disabled = false; note.textContent = CFG.i18n.error; });
    });
    return true;
  }

  function run(tries){
    if (!boot() && tries > 0) { setTimeout(function(){ run(tries - 1); }, 300); }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ run(20); });
  } else {
    run(20);
  }
})();
JS;
	}

	/**
	 * Box styles — admin palette, scoped.
	 *
	 * @return string
	 */
	private static function inline_css() {
		return '.agentimus-il__intro{color:#646970;font-size:12px;margin:0 0 8px}'
			. '.agentimus-il__suggest{width:100%;text-align:center}'
			. '.agentimus-il__note{display:block;font-size:12px;color:#646970;margin-top:6px}'
			. '.agentimus-il__item{border-top:1px solid #f0f0f1;padding:10px 0}'
			. '.agentimus-il__title{font-weight:600;margin:0 0 2px}'
			. '.agentimus-il__why{color:#646970;font-size:12px;margin:0 0 4px}'
			. '.agentimus-il__phrase{font-size:12px;margin:0 0 8px}'
			. '.agentimus-il__phrase code{background:#f6f7f7;border:1px solid #e0e0e0;border-radius:3px;padding:1px 5px;font-size:11.5px}'
			. '.agentimus-il__phrase--none{color:#646970;font-style:italic}'
			. '.agentimus-il__row{display:flex;gap:6px}'
			. '.agentimus-il__none{color:#646970;font-size:12px;margin:8px 0 0}';
	}
}
