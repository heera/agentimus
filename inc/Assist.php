<?php
/**
 * AI authoring assist — "Draft with AI" for the per-page fields.
 *
 * The OUTBOUND companion to {@see \Agentimus\Abilities\Registrar} (which lets AI read
 * Agentimus): this lets the author ask the site's OWN configured AI to draft the two
 * fields Agentimus adds to the editor — the {@see Description} one-liner and the
 * {@see Topics} keyword list. It routes through WordPress 7.0's provider-agnostic AI
 * Client ({@see wp_ai_client_prompt()}), so it NEVER touches a raw provider key: the
 * plugin describes the task, WordPress injects the credentials the admin configured
 * under Settings → AI, runs it, and hands back text.
 *
 * The suggestion always lands in the field as EDITABLE text and is never auto-saved —
 * the author reviews it and saves the post like any manual edit. The buttons are only
 * enqueued when a text-capable AI provider is actually configured, so on a site without
 * AI set up nothing appears. Whole feature no-ops on cores without the AI Client, so the
 * plugin's 6.3+ baseline is unaffected.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Assist {

	/** Script/style handle. */
	const HANDLE = 'agentimus-assist';

	/** REST namespace + routes (under the plugin's existing namespace). */
	const NS        = 'agentimus/v1';
	const ROUTE     = '/suggest';
	const ROUTE_FIX = '/suggest-fix';

	/** Chars of page body fed to the model — bounds token cost while giving it enough to work with. */
	const MAX_CONTEXT_CHARS = 4000;

	/**
	 * Output-token budget per request. Deliberately generous: reasoning models (e.g.
	 * Gemini 2.5, o-series) spend "thinking" tokens against this same cap BEFORE the
	 * answer, so a tight budget truncates the reply mid-sentence. The visible result is
	 * bounded elsewhere anyway — Description::clean() clips to 300 chars, and topics are
	 * capped/deduped by Topics::sanitize_manual() — so headroom costs a little latency,
	 * never a runaway response.
	 *
	 * 800 was still too tight and it showed: a "Fix with AI" on the `evidence` check —
	 * which asks for a LIST of two or three specifics — came back cut off mid-word
	 * ("…from a reputable research firm ("). A truncated list is worse than a short one,
	 * because the author can't tell the model stopped early. The fix bodies are the
	 * longest thing this class asks for, so the budget is sized for them.
	 */
	const OUTPUT_TOKENS = 1600;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The REST route registers on every request (REST is not is_admin()); the editor
	 * assets only in the admin.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		}
	}

	/* ---------------------------------------------------------------------- *
	 *  Availability
	 * ---------------------------------------------------------------------- */

	/**
	 * Whether the site can generate text right now: the WP 7.0 AI Client is present,
	 * AI is not disabled for the request, and at least one registered provider is
	 * configured (has a key). Cheap enough for a per-editor-load gate; the REST handler
	 * still does the authoritative per-prompt {@see wp_ai_client_prompt()} capability
	 * check before spending anything.
	 *
	 * @return bool
	 */
	public static function ai_available() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return false;
		}
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return false;
		}
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			foreach ( (array) $registry->getRegisteredProviderIds() as $id ) {
				if ( $registry->isProviderConfigured( $id ) ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
	}

	/** Which fields the assist can draft on this site (a field must have its feature on). */
	private static function fields() {
		return array(
			'description' => Description::enabled(),
			'topics'      => Topics::enabled(),
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  REST
	 * ---------------------------------------------------------------------- */

	/**
	 * POST /agentimus/v1/suggest — draft one field for a post. Gated per post by
	 * edit_post, exactly like the meta the suggestion targets.
	 */
	public function register_rest() {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => static function ( $request ) {
					return current_user_can( 'edit_post', absint( $request['post'] ) );
				},
				'args'                => array(
					'post'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					// Validated in the callback (not via an `enum` here) so a subscriber is
					// denied by the permission gate first — an enum would 400 before the 403,
					// exactly what RestPermissionTest guards against.
					'field' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			self::ROUTE_FIX,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest_fix' ),
				'permission_callback' => static function ( $request ) {
					return current_user_can( 'edit_post', absint( $request['post'] ) );
				},
				'args'                => array(
					'post'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					// No `enum` here for the same reason as /suggest above.
					'check' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The AI-fixable readability checks (a subset of {@see PageCheck} ids), each with the
	 * prompt that drafts its fix and whether the result is safe to APPLY automatically.
	 * Only the opening summary is auto-applied — an additive paragraph. The rest are
	 * copy-paste suggestions: proposing headings, rewriting prose, or listing what to add
	 * are edits an author must own, not a bot rewriting the page. Checks not listed here
	 * (heading order, link density, freshness, alt text) are mechanical or need image
	 * understanding, so they get no button.
	 *
	 * @param string $check PageCheck id.
	 * @return array{system:string,user:string,apply:?string}|null
	 */
	private static function fix_spec( $check ) {
		$specs = array(
			'summary'  => array(
				'system' => __( 'You write the opening summary for a web page: one clear sentence (two at most) stating what the page is about, so an AI assistant can grab the gist. Plain text only — no markdown, no quotation marks, no preamble. Use only what the content supports.', 'agentimus' ),
				'user'   => "Title: %s\n\nContent:\n%s\n\nWrite the opening summary.",
				'apply'  => 'prependParagraph',
			),
			'headings' => array(
				'system' => __( 'You propose a section-heading outline so an AI can navigate a page. Return only the headings, one per line, each prefixed with "## " for a section or "### " for a subsection. Base them on the actual content. No preamble, no prose.', 'agentimus' ),
				'user'   => "Title: %s\n\nContent:\n%s\n\nPropose the heading outline.",
				'apply'  => null,
			),
			'passages' => array(
				'system' => __( 'You improve quotability. Find the single longest, densest paragraph in the content and rewrite it as two or three shorter, self-contained paragraphs that keep the meaning. Return only the rewritten paragraphs as plain text separated by blank lines. No preamble.', 'agentimus' ),
				'user'   => "Title: %s\n\nContent:\n%s\n\nRewrite the longest paragraph into shorter ones.",
				'apply'  => null,
			),
			'evidence' => array(
				'system' => __( 'You make a page more citable. Suggest two or three concrete specifics it could add — a statistic, a concrete example, or a credible type of source to cite — grounded in its topic. Return a short list, each line starting with "- ". Do not invent fake figures; say what kind of specific to add and where.', 'agentimus' ),
				'user'   => "Title: %s\n\nContent:\n%s\n\nSuggest concrete specifics to add.",
				'apply'  => null,
			),
			'words'    => array(
				'system' => __( 'A web page is too thin to be citable. Propose a brief outline of what to ADD to make it substantive — three to five bullet points of missing sections or questions to answer. Return only the bulleted outline, each line starting with "- ". Do NOT write filler prose.', 'agentimus' ),
				'user'   => "Title: %s\n\nContent:\n%s\n\nOutline what to add.",
				'apply'  => null,
			),
		);
		return isset( $specs[ $check ] ) ? $specs[ $check ] : null;
	}

	/**
	 * Whether a readability check can be drafted by the assist.
	 *
	 * @param string $check PageCheck id.
	 * @return bool
	 */
	public static function fixable( $check ) {
		return null !== self::fix_spec( (string) $check );
	}

	/**
	 * POST /agentimus/v1/suggest-fix — draft a concrete fix for one warn/fail readability
	 * check. Returns { check, suggestion, apply } where apply is a safe, additive editor
	 * action (only the opening summary) or null for a copy-paste suggestion.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function suggest_fix( \WP_REST_Request $request ) {
		$check = (string) $request['check'];
		$spec  = self::fix_spec( $check );
		if ( null === $spec ) {
			return new \WP_Error( 'rest_invalid_param', __( 'That check can’t be drafted by AI.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$post = get_post( absint( $request['post'] ) );
		if ( ! $post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error( 'agentimus_not_found', __( 'That content is not available.', 'agentimus' ), array( 'status' => 404 ) );
		}
		if ( ! $this->settings->enabled( 'enable_page_checks' ) ) {
			return new \WP_Error( 'agentimus_field_off', __( 'AI Readability is turned off.', 'agentimus' ), array( 'status' => 400 ) );
		}
		if ( ! self::ai_available() ) {
			return new \WP_Error( 'agentimus_ai_unavailable', __( 'No AI provider is configured. Add one under Settings → AI.', 'agentimus' ), array( 'status' => 503 ) );
		}

		$ctx = $this->context( $post );
		if ( '' === $ctx['title'] && '' === $ctx['body'] ) {
			return new \WP_Error( 'agentimus_thin', __( 'This page has too little content to draft from yet.', 'agentimus' ), array( 'status' => 422 ) );
		}

		$text = $this->generate( $spec['system'], sprintf( $spec['user'], $ctx['title'], $ctx['body'] ), self::OUTPUT_TOKENS );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$text = trim( $text );

		$apply = null;
		if ( 'prependParagraph' === $spec['apply'] ) {
			// The opening summary is applied as a single clean paragraph — run it through the
			// description cleaner (tags out, one line, capped) so what we insert is safe.
			$text  = Description::clean( trim( $text, " \t\n\r\0\x0B\"'“”‘’" ) );
			$apply = array(
				'type'    => 'prependParagraph',
				'content' => $text,
			);
		}

		return rest_ensure_response(
			array(
				'check'      => $check,
				'suggestion' => $text,
				'apply'      => $apply,
			)
		);
	}

	/**
	 * Draft the requested field. Returns { field, text } for a description or
	 * { field, topics: [...] } for topics, or a WP_Error the editor surfaces inline.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function suggest( \WP_REST_Request $request ) {
		$field = (string) $request['field'];
		if ( ! in_array( $field, array( 'description', 'topics' ), true ) ) {
			return new \WP_Error( 'rest_invalid_param', __( 'Unknown field to draft.', 'agentimus' ), array( 'status' => 400 ) );
		}
		$post = get_post( absint( $request['post'] ) );

		if ( ! $post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error( 'agentimus_not_found', __( 'That content is not available.', 'agentimus' ), array( 'status' => 404 ) );
		}
		if ( ( 'description' === $field && ! Description::enabled() ) || ( 'topics' === $field && ! Topics::enabled() ) ) {
			return new \WP_Error( 'agentimus_field_off', __( 'That feature is turned off.', 'agentimus' ), array( 'status' => 400 ) );
		}
		if ( ! self::ai_available() ) {
			return new \WP_Error( 'agentimus_ai_unavailable', __( 'No AI provider is configured. Add one under Settings → AI.', 'agentimus' ), array( 'status' => 503 ) );
		}

		$ctx = $this->context( $post );
		if ( '' === $ctx['title'] && '' === $ctx['body'] ) {
			return new \WP_Error( 'agentimus_thin', __( 'This page has too little content to draft from yet. Add some content and try again.', 'agentimus' ), array( 'status' => 422 ) );
		}

		return ( 'topics' === $field )
			? $this->suggest_topics( $ctx )
			: $this->suggest_description( $ctx );
	}

	/**
	 * The page context handed to the model: its title and a cleaned, capped body. Uses
	 * {@see Content::markdown_source()} (robust even where the rendered body is filtered
	 * off-loop), falling back to the raw stripped content.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{title:string,body:string}
	 */
	private function context( \WP_Post $post ) {
		$title = html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		// markdown_source() returns the page's HTML (the source the .md converter reads); the
		// raw content is the off-loop-safe fallback. Either way, strip to plain text so the
		// model gets clean prose, not tag noise that just burns tokens.
		$body = (string) Content::markdown_source( $post );
		if ( '' === trim( $body ) ) {
			$body = (string) $post->post_content;
		}
		$body = wp_strip_all_tags( $body );
		$body = trim( (string) preg_replace( '/\s+/', ' ', $body ) );
		$body = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, self::MAX_CONTEXT_CHARS ) : substr( $body, 0, self::MAX_CONTEXT_CHARS );

		return array(
			'title' => trim( $title ),
			'body'  => trim( $body ),
		);
	}

	/**
	 * Draft the one-line AI description.
	 *
	 * @param array{title:string,body:string} $ctx Page context.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function suggest_description( array $ctx ) {
		$system = __( 'You write a single, plain-language sentence describing a web page so an AI answer engine can summarise and cite it correctly. Output ONE sentence only: no quotation marks, no markdown, no line breaks, and about 160 characters or fewer. State what the page is and who it is for — do not invent facts that are not in the content.', 'agentimus' );
		$prompt = sprintf( "Title: %s\n\nContent:\n%s\n\nWrite the one-sentence description.", $ctx['title'], $ctx['body'] );

		$text = $this->generate( $system, $prompt, self::OUTPUT_TOKENS );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		// Strip any wrapping quotes the model added, then run the same cleaner the field's
		// own save path uses (tags out, whitespace collapsed, capped).
		$text = Description::clean( trim( $text, " \t\n\r\0\x0B\"'“”‘’" ) );
		return rest_ensure_response( array( 'field' => 'description', 'text' => $text ) );
	}

	/**
	 * Draft the topic keyword list.
	 *
	 * @param array{title:string,body:string} $ctx Page context.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function suggest_topics( array $ctx ) {
		$cap    = Topics::cap();
		$system = sprintf(
			/* translators: %d: the maximum number of topics. */
			__( 'You extract what a web page is about as short topic keywords for AI answer engines. Return a plain comma-separated list of at most %d topics, each one to four words, lower-case unless a proper noun. No numbering, no sentences, no explanation — just the comma-separated list. Only use topics grounded in the content.', 'agentimus' ),
			$cap
		);
		$prompt = sprintf( "Title: %s\n\nContent:\n%s\n\nList the topics.", $ctx['title'], $ctx['body'] );

		$text = $this->generate( $system, $prompt, self::OUTPUT_TOKENS );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		// Parse + sanitise through the field's own sanitiser (splits on commas/newlines,
		// trims, dedupes case-insensitively, caps each length and the count).
		$topics = Topics::sanitize_manual( $text );
		return rest_ensure_response( array( 'field' => 'topics', 'topics' => array_values( (array) $topics ) ) );
	}

	/**
	 * Run one text generation through the site's configured AI, returning the trimmed
	 * string or a WP_Error (already carrying an HTTP status) for the editor to show.
	 *
	 * @param string $system     System instruction.
	 * @param string $prompt     User prompt.
	 * @param int    $max_tokens Output cap.
	 * @return string|\WP_Error
	 */
	private function generate( $system, $prompt, $max_tokens ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error( 'agentimus_ai_unavailable', __( 'AI is not available in this environment.', 'agentimus' ), array( 'status' => 503 ) );
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->using_max_tokens( (int) $max_tokens )
			->using_temperature( 0.4 );

		// Authoritative capability check: false when no configured model can do text
		// generation — surface it as a clean 503 rather than letting generate_text() fail.
		if ( ! $builder->is_supported_for_text_generation() ) {
			return new \WP_Error( 'agentimus_ai_unavailable', __( 'No AI text model is configured. Add or enable one under Settings → AI.', 'agentimus' ), array( 'status' => 503 ) );
		}

		$text = $builder->generate_text();
		if ( is_wp_error( $text ) ) {
			return $text; // Already a WP_Error with an HTTP status from the AI Client.
		}
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( '' === $text ) {
			return new \WP_Error( 'agentimus_ai_empty', __( 'The AI returned an empty response — please try again.', 'agentimus' ), array( 'status' => 502 ) );
		}
		return $text;
	}

	/* ---------------------------------------------------------------------- *
	 *  Editor assets
	 * ---------------------------------------------------------------------- */

	/**
	 * Enqueue the button injector — only on the editor for a covered post type, only
	 * when AI is configured, and only for the fields whose feature is on.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, Content::post_types(), true ) ) {
			return;
		}

		$fields      = array_filter( self::fields() );
		$page_checks = (bool) $this->settings->enabled( 'enable_page_checks' );
		if ( empty( $fields ) && ! $page_checks ) {
			return;
		}

		/**
		 * Whether the "Draft with AI" editor assist is offered. Defaults to on when a
		 * text-capable AI provider is configured; return false to hide it regardless.
		 *
		 * @param bool $enabled Whether to offer the assist.
		 */
		if ( ! (bool) apply_filters( 'agentimus_ai_assist_enabled', self::ai_available() ) ) {
			return;
		}

		$config = array(
			'endpoint'    => esc_url_raw( rest_url( self::NS . self::ROUTE ) ),
			'endpointFix' => esc_url_raw( rest_url( self::NS . self::ROUTE_FIX ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'fields'      => array(
				'description' => ! empty( $fields['description'] ),
				'topics'      => ! empty( $fields['topics'] ),
			),
			'readability' => $page_checks,
			'i18n'        => array(
				'draft'     => __( 'Draft with AI', 'agentimus' ),
				'suggest'   => __( 'Suggest with AI', 'agentimus' ),
				'working'   => __( 'Drafting…', 'agentimus' ),
				'needsSave' => __( 'Save a draft first, then try again.', 'agentimus' ),
				'error'     => __( 'Couldn’t draft — check your AI provider under Settings → AI.', 'agentimus' ),
				'apply'     => __( 'Apply', 'agentimus' ),
				'applied'   => __( 'Applied ✓', 'agentimus' ),
				'copy'       => __( 'Copy', 'agentimus' ),
				'copied'     => __( 'Copied ✓', 'agentimus' ),
				'copyFailed' => __( 'Press ⌘/Ctrl+C to copy', 'agentimus' ),
			),
		);

		wp_register_script( self::HANDLE, false, array(), AGENTIMUS_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, 'window.agentimusAssist=' . wp_json_encode( $config ) . ';', 'before' );
		wp_add_inline_script( self::HANDLE, self::inline_js() );

		wp_register_style( self::HANDLE, false, array(), AGENTIMUS_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, self::inline_css() );
	}

	/**
	 * The button injector. Adds a "Draft with AI" control to the description box and a
	 * "Suggest with AI" control to the topics box; on click it calls the REST route and
	 * drops the result into the field (the description textarea directly; topics via a
	 * custom event the chip editor listens for). Never saves — the author still saves the
	 * post. Vanilla JS, no build step, matching the other editor enhancements.
	 *
	 * @return string
	 */
	private static function inline_js() {
		return <<<'JS'
(function(){
  var CFG = window.agentimusAssist || {};
  if (!CFG.endpoint) { return; }
  var SPARK = '<svg class="agentimus-assist__icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3l1.9 4.7L18.6 9l-4.7 1.9L12 15.6 10.1 10.9 5.4 9l4.7-1.3z"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/></svg>';

  function postId(){
    try {
      if (window.wp && wp.data && wp.data.select) {
        var ed = wp.data.select('core/editor');
        if (ed && ed.getCurrentPostId) { var id = ed.getCurrentPostId(); if (id) { return id; } }
      }
    } catch (e) {}
    var el = document.getElementById('post_ID');
    return el ? parseInt(el.value, 10) : 0;
  }

  function request(field, btn, note, done){
    var id = postId();
    if (!id) { note.textContent = CFG.i18n.needsSave; note.className = 'agentimus-assist__note is-error'; return; }
    btn.disabled = true; btn.classList.add('is-busy');
    note.textContent = CFG.i18n.working; note.className = 'agentimus-assist__note';
    fetch(CFG.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ post: id, field: field })
    }).then(function(r){
      return r.json().then(function(j){ return { ok: r.ok, body: j }; });
    }).then(function(res){
      btn.disabled = false; btn.classList.remove('is-busy');
      if (!res.ok) {
        note.textContent = (res.body && res.body.message) ? res.body.message : CFG.i18n.error;
        note.className = 'agentimus-assist__note is-error';
        return;
      }
      note.textContent = '';
      done(res.body);
    }).catch(function(){
      btn.disabled = false; btn.classList.remove('is-busy');
      note.textContent = CFG.i18n.error; note.className = 'agentimus-assist__note is-error';
    });
  }

  function makeControl(label, onClick){
    var wrap = document.createElement('div'); wrap.className = 'agentimus-assist';
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'button button-small agentimus-assist__btn';
    btn.innerHTML = SPARK + '<span>' + label + '</span>';
    var note = document.createElement('span');
    note.className = 'agentimus-assist__note'; note.setAttribute('aria-live', 'polite');
    btn.addEventListener('click', function(){ onClick(btn, note); });
    wrap.appendChild(btn); wrap.appendChild(note);
    return wrap;
  }

  function enhanceDescription(){
    var box = document.querySelector('.agentimus-desc');
    if (!box || box.querySelector('.agentimus-assist')) { return true; }
    var ta = box.querySelector('.agentimus-desc__input');
    if (!ta) { return false; }
    var ctrl = makeControl(CFG.i18n.draft, function(btn, note){
      request('description', btn, note, function(body){
        if (typeof body.text === 'string' && body.text) {
          ta.value = body.text;
          ta.dispatchEvent(new Event('input', { bubbles: true }));
          ta.focus();
        }
      });
    });
    ta.insertAdjacentElement('afterend', ctrl);
    return true;
  }

  function enhanceTopics(){
    var box = document.querySelector('.agentimus-topics');
    if (!box || box.querySelector('.agentimus-assist')) { return true; }
    var chips = box.querySelector('.agentimus-topics__chips');
    var ctrl = makeControl(CFG.i18n.suggest, function(btn, note){
      request('topics', btn, note, function(body){
        if (Array.isArray(body.topics) && body.topics.length) {
          box.dispatchEvent(new CustomEvent('agentimus-topics:add', { detail: { topics: body.topics } }));
        }
      });
    });
    if (chips) { chips.insertAdjacentElement('afterend', ctrl); } else { box.appendChild(ctrl); }
    return true;
  }

  // ---- AI Readability: "Fix with AI" on warn/fail rows ----
  // The readability panel is server-rendered AND re-rendered over REST after each save, so
  // the click is delegated on the document (survives innerHTML replacement) not bound per button.
  function canInsertBlocks(){
    try { return !!(window.wp && wp.data && wp.blocks && wp.blocks.createBlock && wp.data.dispatch('core/block-editor')); }
    catch (e) { return false; }
  }
  function prependParagraph(content){
    try {
      wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('core/paragraph', { content: content }), 0);
      return true;
    } catch (e) { return false; }
  }
  function mkBtn(label, onClick){
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'button button-small'; b.textContent = label;
    b.addEventListener('click', onClick);
    return b;
  }
  /**
   * navigator.clipboard only exists in a SECURE context (HTTPS or localhost). On a
   * plain-HTTP site it is undefined, so guarding on it and doing nothing else — which is
   * what this used to do — left the Copy button silently dead. Fall back to the legacy
   * textarea + execCommand path, exactly like the rest of the admin (see uaTip.js), and
   * always report the outcome rather than failing in silence.
   */
  function copyText(text){
    if (!text) { return Promise.resolve(false); }
    var legacy = function(){
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed'; ta.style.top = '-1000px'; ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
      } catch (e) { return false; }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function(){ return true; }, function(){ return legacy(); });
    }
    return Promise.resolve(legacy());
  }
  function renderFix(out, body){
    out.innerHTML = ''; out.className = 'agentimus-pc__fixout'; out.hidden = false;
    var text = (body && typeof body.suggestion === 'string') ? body.suggestion : '';
    var pre = document.createElement('div'); pre.className = 'agentimus-pc__suggestion'; pre.textContent = text;
    out.appendChild(pre);
    var actions = document.createElement('div'); actions.className = 'agentimus-pc__fixactions';
    if (body && body.apply && body.apply.type === 'prependParagraph' && canInsertBlocks()) {
      var applyBtn = mkBtn(CFG.i18n.apply, function(){
        if (prependParagraph(body.apply.content)) { applyBtn.textContent = CFG.i18n.applied; applyBtn.disabled = true; }
      });
      actions.appendChild(applyBtn);
    }
    var copyBtn = mkBtn(CFG.i18n.copy, function(){
      copyText(text).then(function(ok){
        copyBtn.textContent = ok ? CFG.i18n.copied : CFG.i18n.copyFailed;
      });
    });
    actions.appendChild(copyBtn);
    out.appendChild(actions);
  }
  function handleFix(btn){
    var check = btn.getAttribute('data-check');
    var row = btn.closest('.agentimus-pc__row');
    var container = btn.closest('.agentimus-pc');
    var out = row ? row.querySelector('.agentimus-pc__fixout') : null;
    var id = (container && container.getAttribute('data-post')) ? parseInt(container.getAttribute('data-post'), 10) : postId();
    if (!id) { if (out) { out.hidden = false; out.className = 'agentimus-pc__fixout is-error'; out.textContent = CFG.i18n.needsSave; } return; }
    btn.disabled = true; btn.classList.add('is-busy');
    if (out) { out.hidden = false; out.className = 'agentimus-pc__fixout'; out.textContent = CFG.i18n.working; }
    fetch(CFG.endpointFix, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ post: id, check: check })
    }).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, body: j }; }); })
      .then(function(res){
        btn.disabled = false; btn.classList.remove('is-busy');
        if (!res.ok) { if (out) { out.hidden = false; out.className = 'agentimus-pc__fixout is-error'; out.textContent = (res.body && res.body.message) ? res.body.message : CFG.i18n.error; } return; }
        if (out) { renderFix(out, res.body); }
      }).catch(function(){
        btn.disabled = false; btn.classList.remove('is-busy');
        if (out) { out.hidden = false; out.className = 'agentimus-pc__fixout is-error'; out.textContent = CFG.i18n.error; }
      });
  }
  if (CFG.readability) {
    document.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('.agentimus-pc__fix') : null;
      if (btn) { handleFix(btn); }
    });
  }

  // The meta boxes are server-rendered but the block editor relocates them after load,
  // so retry a few times until each target is found (then stop). Mirrors the retry the
  // topics live-sync uses.
  function run(tries){
    var done = true;
    if (CFG.fields && CFG.fields.description) { done = enhanceDescription() && done; }
    if (CFG.fields && CFG.fields.topics)      { done = enhanceTopics() && done; }
    if (!done && tries > 0) { setTimeout(function(){ run(tries - 1); }, 300); }
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
	 * Styles for the assist control — WordPress-admin palette, scoped to our wrapper.
	 *
	 * @return string
	 */
	private static function inline_css() {
		return '.agentimus-assist{display:flex;flex-wrap:wrap;align-items:stretch;gap:6px 8px;margin:8px 0 0}'
			. '.agentimus-assist__btn{display:flex;width:100%;align-items:center;justify-content:center;gap:5px}'
			. '.agentimus-assist__btn .agentimus-assist__icon{color:#8073a6;flex:none}'
			. '.agentimus-assist__btn.is-busy{opacity:.7;cursor:progress}'
			. '.agentimus-assist__btn.is-busy .agentimus-assist__icon{animation:agentimus-assist-spin 1s linear infinite}'
			. '.agentimus-assist__note{font-size:12px;color:#646970;line-height:1.5}'
			. '.agentimus-assist__note.is-error{color:#d63638}'
			// AI Readability "Fix with AI" affordance.
			. '.agentimus-pc__fix{display:inline-flex;align-items:center;gap:4px;margin-top:6px;background:none;border:1px solid #c3c4c7;border-radius:3px;padding:2px 8px;font-size:12px;color:#2271b1;cursor:pointer}'
			. '.agentimus-pc__fix:hover{border-color:#2271b1;background:#f6f7f7}'
			. '.agentimus-pc__fix .agentimus-assist__icon{color:#8073a6}'
			. '.agentimus-pc__fix.is-busy{opacity:.7;cursor:progress}'
			. '.agentimus-pc__fix.is-busy .agentimus-assist__icon{animation:agentimus-assist-spin 1s linear infinite}'
			. '.agentimus-pc__fixout{margin-top:8px;font-size:12px;color:#646970}'
			. '.agentimus-pc__fixout.is-error{color:#d63638}'
			. '.agentimus-pc__suggestion{white-space:pre-wrap;background:#f6f7f7;border-left:3px solid #8073a6;border-radius:2px;padding:8px 10px;color:#2c3338;line-height:1.5}'
			. '.agentimus-pc__fixactions{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}'
			. '@keyframes agentimus-assist-spin{to{transform:rotate(360deg)}}';
	}
}
