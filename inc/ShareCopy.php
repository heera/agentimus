<?php
/**
 * Share copy — ready-to-post social text for the post being edited, as the
 * "Share" tab of the Agentimus editor panel ({@see EditorPanel}, after
 * JSON-LD).
 *
 * The author finishes a post (written, AI-drafted, or edited — it doesn't
 * matter) and wants to announce it. Opening the tab shows a LINK PREVIEW —
 * the image and text a network's scraper will build a card from, resolved by
 * the same {@see Seo::social_image()} the cards use — and one uniform card
 * per network, each an editable draft with a character counter, a Copy
 * button, and an "open composer" link where the network accepts pre-filled
 * text (X, WhatsApp, Telegram and Reddit do; LinkedIn and Facebook take only
 * the URL, so there the flow is copy-then-open).
 *
 * The default networks are the GENERAL-PUBLIC set — X, Facebook, LinkedIn,
 * WhatsApp, Telegram, Reddit — chosen for the ordinary site (a blogger, a
 * shop with a blog), not the tech crowd; niche ones (Bluesky, Mastodon…) are
 * a `agentimus_share_copy_networks` filter entry away, and the release
 * version's settings should let an owner pick their own set.
 *
 * The default drafts involve no AI at all: each network's format is known, so
 * they compose from what the site already has — the title, the AI description
 * ({@see Description}), the topics ({@see Topics}) and the permalink — free,
 * instant, and available on sites with no provider. AI is PER CARD: a
 * "Rewrite with AI" button on each network (shown only when a provider is
 * configured) spends ONE request through {@see Assist::generate()}, the same
 * governed choke point as every Agentimus draft, on that network alone — an
 * author who only posts to one place only ever pays for that one. Either way
 * NOTHING is posted anywhere: Agentimus never talks to a social network — the
 * author copies, or clicks through to the network's own composer, and posts
 * there.
 *
 * Gated by the `enable_share_copy` setting (on by default — an authoring aid
 * with no front-end output, same posture as the AI Readability panel) plus the
 * `agentimus_share_copy_enabled` filter as a code-level override. Off means
 * off everywhere: the tab is not composed AND the REST route refuses.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class ShareCopy {

	const NS    = 'agentimus/v1';
	const ROUTE = '/share-copy/generate';

	/** Chars of body text an AI request works against — enough to catch the substance, bounded on big posts. */
	const MAX_BODY_CHARS = 3000;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * REST only — the tab itself is composed (rendered, styled, scripted) by
	 * {@see EditorPanel} like every other panel section.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/**
	 * Code-level override for the Share tab, layered ON TOP of the
	 * enable_share_copy setting (see {@see is_enabled()}).
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filter whether the Share tab is offered.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'agentimus_share_copy_enabled', true );
	}

	/**
	 * Section-API alias of {@see enabled()}, matching the other panel sections.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->settings->enabled( 'enable_share_copy' ) && self::enabled();
	}

	/* ---------------------------------------------------------------------- *
	 *  The networks
	 * ---------------------------------------------------------------------- */

	/**
	 * The networks a card is drafted for — the general-public set. `limit` 0
	 * means no counter. The intent `href` is a template the JS fills at click
	 * time — {text} is the card's CURRENT textarea value (the author may have
	 * edited it), {url} the permalink — so what opens in the composer is what
	 * the author approved.
	 *
	 * `intent.carries` says what the composer link can pre-fill: 'text' (the
	 * whole post travels), 'title' (Reddit: title + URL as separate fields),
	 * or 'url' (LinkedIn/Facebook: URL only — the Copy button is the way the
	 * text travels). Telegram's own share page carries the URL separately from
	 * the text, so its draft (and brief) deliberately excludes the link.
	 *
	 * @return array<string,array>
	 */
	public static function networks() {
		$networks = array(
			'x'        => array(
				'label'  => 'X (Twitter)', // Not bare "X" — as a card heading that reads like a close button.
				'limit'  => 280,
				'brief'  => __( 'punchy and specific, at most 260 characters including the link, at most two hashtags', 'agentimus' ),
				'intent' => array(
					'carries' => 'text',
					'href'    => 'https://x.com/intent/post?text={text}',
					'label'   => __( 'Open X', 'agentimus' ),
				),
			),
			'facebook' => array(
				'label'  => 'Facebook',
				'limit'  => 0,
				'brief'  => __( 'one or two friendly sentences, then the link', 'agentimus' ),
				'hint'   => __( 'Facebook won’t accept text from outside — Open copies your draft, so just paste it into the composer.', 'agentimus' ),
				'intent' => array(
					'carries' => 'url',
					'href'    => 'https://www.facebook.com/sharer/sharer.php?u={url}',
					'label'   => __( 'Open Facebook', 'agentimus' ),
				),
			),
			'linkedin' => array(
				'label'  => 'LinkedIn',
				'limit'  => 0,
				'brief'  => __( 'two or three short paragraphs, professional but personal, at most three hashtags, the link on its own last line', 'agentimus' ),
				'hint'   => __( 'LinkedIn won’t accept text from outside — Open copies your draft, so just paste it into the composer.', 'agentimus' ),
				'intent' => array(
					'carries' => 'url',
					'href'    => 'https://www.linkedin.com/sharing/share-offsite/?url={url}',
					'label'   => __( 'Open LinkedIn', 'agentimus' ),
				),
			),
			'reddit'   => array(
				'label'  => 'Reddit',
				'limit'  => 0,
				'brief'  => __( 'the BODY text of a link submission — one or two honest first-person sentences on what the post covers and why it might interest the community; no marketing tone, no hashtags, no link (Reddit attaches it)', 'agentimus' ),
				'hint'   => __( 'Reddit takes the post title and link by itself — this text goes in as the body.', 'agentimus' ),
				'intent' => array(
					'carries' => 'text',
					// {title} = the post's own title; proven live: the new composer
					// fills title, link AND body from these three parameters.
					'href'    => 'https://www.reddit.com/submit?url={url}&title={title}&text={text}',
					'label'   => __( 'Open Reddit', 'agentimus' ),
				),
			),
			// The messengers close the list — platforms you POST to first, apps
			// you SEND through last (Heera's ordering).
			'whatsapp' => array(
				'label'  => 'WhatsApp',
				'limit'  => 0,
				'brief'  => __( 'a short friendly message you would send to a group chat — one or two sentences, then the link', 'agentimus' ),
				'intent' => array(
					'carries' => 'text',
					'href'    => 'https://api.whatsapp.com/send?text={text}',
					'label'   => __( 'Open WhatsApp', 'agentimus' ),
				),
			),
			'telegram' => array(
				'label'  => 'Telegram',
				'limit'  => 0,
				'brief'  => __( 'one or two sentences for a channel post; the link is attached separately by Telegram, so do NOT include it', 'agentimus' ),
				'hint'   => __( 'Telegram attaches the link by itself, so it isn’t in the text.', 'agentimus' ),
				'intent' => array(
					'carries' => 'text',
					'href'    => 'https://t.me/share/url?url={url}&text={text}',
					'label'   => __( 'Open Telegram', 'agentimus' ),
				),
			),
		);

		/**
		 * Adjust the share-copy networks — reorder, drop, or add one (Bluesky,
		 * Mastodon, …). An added entry needs label, limit, brief and an intent
		 * with carries/href/label.
		 *
		 * @param array<string,array> $networks
		 */
		return apply_filters( 'agentimus_share_copy_networks', $networks );
	}

	/* ---------------------------------------------------------------------- *
	 *  Drafting — format templates by default, ONE AI call per card on request
	 * ---------------------------------------------------------------------- */

	/**
	 * Format drafts for every offered network from what the site already
	 * knows — no AI involved.
	 *
	 * @param \WP_Post $post The post.
	 * @return array<string,string> Network key → draft text.
	 */
	private function local_drafts( \WP_Post $post ) {
		$title = (string) get_the_title( $post );
		$url   = (string) get_permalink( $post );
		$desc  = (string) Description::for_post( $post );
		$tags  = self::hashtags( $post, 2 );

		$short = trim( $title . ( '' !== $tags ? "\n\n" . $tags : '' ) . "\n\n" . $url );

		$long = trim(
			sprintf(
				/* translators: %s: post title. */
				__( 'New on the blog: %s', 'agentimus' ),
				$title
			) . ( '' !== $desc ? "\n\n" . $desc : '' ) . "\n\n" . $url
		);

		$titled = trim( $title . ( '' !== $desc ? ' — ' . $desc : '' ) );

		$drafts = array(
			'x'        => $short,
			'facebook' => $titled . "\n\n" . $url,
			'linkedin' => $long,
			'whatsapp' => $titled . "\n\n" . $url,
			'telegram' => $titled, // Telegram's share page attaches the URL itself.
			// Reddit's card is the submission BODY — the title and link travel as
			// their own composer fields (see the intent template above).
			'reddit'   => '' !== $desc ? $desc : $title,
		);

		// Only keys that are actually offered (the networks filter may trim the
		// list); a filter-added network starts from the plain short template.
		$out = array();
		foreach ( self::networks() as $key => $network ) {
			$out[ $key ] = isset( $drafts[ $key ] ) ? $drafts[ $key ] : $short;
		}
		return $out;
	}

	/**
	 * ONE AI request writes ONE network's copy — the per-card button's server
	 * half. The reply is the post text itself (no list format to parse), and
	 * it is data, not gospel: empty or errored means '' and the caller keeps
	 * the template draft.
	 *
	 * @param \WP_Post $post The post.
	 * @param string   $key  Network key (must exist in {@see networks()}).
	 * @return string The drafted text, '' on any failure.
	 */
	private function draft_one_ai( \WP_Post $post, $key ) {
		$networks = self::networks();
		if ( ! isset( $networks[ $key ] ) ) {
			return '';
		}
		$network = $networks[ $key ];

		$body = (string) Content::markdown_source( $post );
		if ( '' === trim( $body ) ) {
			$body = wp_strip_all_tags( (string) $post->post_content );
		}
		$body = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, self::MAX_BODY_CHARS ) : substr( $body, 0, self::MAX_BODY_CHARS );

		$system = __( 'You write a ready-to-post social media announcement of an article, in the article\'s own language, for one named network. Match the network\'s brief. Use the article link EXACTLY as given when the brief calls for it, never a shortened or altered one. State only what the article supports — no invented claims, no engagement bait. Reply with the post text only — no preamble, no surrounding quotes, no commentary.', 'agentimus' );

		$prompt = sprintf(
			"Article title: %s\nLink: %s\nSummary: %s\nTopics: %s\n\nArticle text (excerpt):\n%s\n\nNetwork: %s\nBrief: %s",
			get_the_title( $post ),
			get_permalink( $post ),
			Description::for_post( $post ),
			implode( ', ', Topics::for_post( $post ) ),
			$body,
			$network['label'],
			isset( $network['brief'] ) ? $network['brief'] : __( 'a short announcement including the link', 'agentimus' )
		);

		// 4000, not ~1200: a thinking provider's reasoning tokens come out of
		// the same budget (proven live — a tight budget came back truncated).
		$reply = ( new Assist( $this->settings ) )->generate( $system, $prompt, 4000 );
		if ( is_wp_error( $reply ) ) {
			return '';
		}

		$text = trim( (string) $reply );
		$text = (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', $text ); // A stray code fence, if the model added one.
		$text = trim( $text, "\"'“”‘’ \t\r\n" );
		return sanitize_textarea_field( $text );
	}

	/**
	 * Up to $max topics as #CamelCase hashtags ('' when the post has none).
	 *
	 * @param \WP_Post $post Post.
	 * @param int      $max  Max hashtags.
	 * @return string Space-separated hashtags.
	 */
	private static function hashtags( \WP_Post $post, $max ) {
		$tags = array();
		foreach ( array_slice( Topics::for_post( $post ), 0, max( 0, (int) $max ) ) as $topic ) {
			$tag = preg_replace( '/[^A-Za-z0-9]/', '', ucwords( (string) $topic ) );
			if ( '' !== $tag ) {
				$tags[] = '#' . $tag;
			}
		}
		return implode( ' ', $tags );
	}

	/**
	 * What a network's scraper will build a link card from — image via the one
	 * shared resolver ({@see Seo::social_image()}: featured image → site-wide
	 * default → entity image), text via the same sources the drafts use.
	 *
	 * @param \WP_Post $post The post.
	 * @return array{image:string,title:string,description:string,host:string}
	 */
	private function preview( \WP_Post $post ) {
		$image = ( new Seo( $this->settings ) )->social_image( $post );
		$host  = (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_HOST );

		return array(
			'image'       => null !== $image ? (string) $image['url'] : '',
			'title'       => (string) get_the_title( $post ),
			'description' => (string) Description::for_post( $post ),
			'host'        => $host,
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  REST
	 * ---------------------------------------------------------------------- */

	/**
	 * POST /share-copy/generate — gated per post by edit_post, exactly like
	 * the editor the tab sits in. Without `network`: the full set of format
	 * drafts plus the link preview (the tab's first load — never AI). With
	 * `network` (+ use_ai): that one card redrafted.
	 *
	 * The network value is validated in the CALLBACK, not via an args enum —
	 * an enum rejects before permission_callback runs (400-not-403; the
	 * 1.27.1 lesson).
	 */
	public function register_rest() {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_generate' ),
				// Off means off everywhere: a disabled feature must not keep a live,
				// invisible REST surface (the enable_agent_writes doctrine).
				'permission_callback' => function ( $request ) {
					return $this->is_enabled() && current_user_can( 'edit_post', absint( $request['post'] ) );
				},
				'args'                => array(
					'post'    => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'network' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'use_ai'  => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);
	}

	/**
	 * The route handler. AI runs only for a single named card, only when the
	 * author asked AND a provider is there; anything less (or an AI failure)
	 * means the format draft, never an error.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_generate( \WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'agentimus_field_off', __( 'The Share tab is turned off.', 'agentimus' ), array( 'status' => 400 ) );
		}
		$post = get_post( absint( $request['post'] ) );
		if ( ! $post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error( 'agentimus_not_found', __( 'That content is not available.', 'agentimus' ), array( 'status' => 404 ) );
		}

		$networks = self::networks();
		$key      = (string) $request['network'];

		// One card asked for — redraft just that network.
		if ( '' !== $key ) {
			if ( ! isset( $networks[ $key ] ) ) {
				return new \WP_Error( 'agentimus_unknown_network', __( 'That network is not offered here.', 'agentimus' ), array( 'status' => 404 ) );
			}
			$drafts = $this->local_drafts( $post );
			$text   = '';
			$source = 'local';
			if ( rest_sanitize_boolean( $request['use_ai'] ) && Assist::ai_available() ) {
				$text = $this->draft_one_ai( $post, $key );
			}
			if ( '' !== $text ) {
				$source = 'ai';
			} else {
				$text = $drafts[ $key ];
			}
			return rest_ensure_response(
				array(
					'source'  => $source,
					'network' => array_merge( $this->card( $key, $networks[ $key ] ), array( 'text' => $text ) ),
				)
			);
		}

		// The tab's first load: every card as a format draft, plus the preview.
		$drafts = $this->local_drafts( $post );
		$cards  = array();
		foreach ( $networks as $k => $network ) {
			$cards[] = array_merge( $this->card( $k, $network ), array( 'text' => $drafts[ $k ] ) );
		}

		return rest_ensure_response(
			array(
				'source'    => 'local',
				'permalink' => (string) get_permalink( $post ),
				'published' => 'publish' === $post->post_status,
				'preview'   => $this->preview( $post ),
				'networks'  => $cards,
			)
		);
	}

	/**
	 * The client-facing shape of one network (everything but the text).
	 *
	 * @param string $key     Network key.
	 * @param array  $network Its {@see networks()} entry.
	 * @return array
	 */
	private function card( $key, array $network ) {
		return array(
			'key'    => $key,
			'label'  => $network['label'],
			'limit'  => (int) $network['limit'],
			'hint'   => isset( $network['hint'] ) ? (string) $network['hint'] : '',
			'intent' => $network['intent'],
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  The panel section (rendered by EditorPanel)
	 * ---------------------------------------------------------------------- */

	/**
	 * The tab's shell — the drafts load themselves the first time the tab is
	 * opened; the JS does the rest.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function render_meta_box( $post ) {
		?>
		<div class="agentimus-sc" data-post="<?php echo (int) $post->ID; ?>">
			<p class="agentimus-sc__lead"><?php esc_html_e( 'Announce this post without writing the announcements.', 'agentimus' ); ?></p>
			<p class="agentimus-sc__intro"><?php esc_html_e( 'Each network gets a ready-to-post draft in its own format. Edit to taste, then copy it — or open the network’s composer with it already filled in. The preview shows the card networks will build from this link.', 'agentimus' ); ?></p>
			<span class="agentimus-sc__note" aria-live="polite"></span>
			<div class="agentimus-sc__body">
				<div class="agentimus-sc__grid"></div>
				<aside class="agentimus-sc__aside"></aside>
			</div>
			<p class="agentimus-sc__foot" hidden></p>
		</div>
		<?php
	}

	/**
	 * The tab's behaviour. Vanilla JS, no build step. The first time the pane
	 * becomes visible the format drafts + preview are fetched (no AI); each
	 * card's own AI button re-drafts that card alone. Copy uses the async
	 * clipboard where the context is secure and falls back to
	 * select-and-execCommand elsewhere — local HTTP sites have no
	 * navigator.clipboard at all.
	 *
	 * @return string
	 */
	public static function js() {
		$config = array(
			'endpoint'    => esc_url_raw( rest_url( self::NS . self::ROUTE ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'aiAvailable' => Assist::ai_available(),
			'sparkSvg'    => '<svg class="agentimus-assist__icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3l1.9 4.7L18.6 9l-4.7 1.9L12 15.6 10.1 10.9 5.4 9l4.7-1.3z"/></svg>',
			'i18n'        => array(
				'working'      => __( 'Drafting…', 'agentimus' ),
				'error'        => __( 'Couldn’t write the drafts — please try again.', 'agentimus' ),
				'localNote'    => __( 'Drafted from the post’s title, description and topics — no AI involved.', 'agentimus' ),
				'aiNote'       => __( 'Rewritten by your AI provider — edit to taste before posting.', 'agentimus' ),
				'aiFellBack'   => __( 'The AI rewrite didn’t come back — this is the format draft.', 'agentimus' ),
				'rewrite'      => __( 'Rewrite with AI', 'agentimus' ),
				'rewriting'    => __( 'Rewriting…', 'agentimus' ),
				'copy'         => __( 'Copy', 'agentimus' ),
				'copied'       => __( 'Copied ✓', 'agentimus' ),
				'chars'        => __( 'characters', 'agentimus' ),
				'previewLabel' => __( 'Link preview', 'agentimus' ),
				'previewHint'  => __( 'The image and text networks build this link’s card from.', 'agentimus' ),
				'pasteHint'    => __( 'Text copied — paste it into the composer that just opened (this network can’t be pre-filled).', 'agentimus' ),
				'noImage'      => __( 'No image to share — set a featured image (or a default social image in Settings) so this link gets a picture.', 'agentimus' ),
				'unpublished'  => __( 'This post isn’t published yet — the link in these drafts is its current address and can change at publish time.', 'agentimus' ),
			),
		);

		return 'window.agentimusSc=' . wp_json_encode( $config ) . ';' . <<<'JS'
(function(){
  var CFG = window.agentimusSc || {};
  if (!CFG.endpoint) { return; }

  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

  function post(body){
    return fetch(CFG.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify(body)
    }).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, body: j }; }); });
  }

  function copyText(area, btn){
    var done = function(){
      var old = btn.textContent;
      btn.textContent = CFG.i18n.copied;
      btn.disabled = true;
      setTimeout(function(){ btn.textContent = old; btn.disabled = false; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(area.value).then(done, function(){ fallback(); });
    } else { fallback(); }
    function fallback(){
      area.focus();
      area.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      area.setSelectionRange(0, 0);
      area.blur();
    }
  }

  function intentHref(tpl, text, url, title){
    return tpl.replace('{text}', encodeURIComponent(text))
              .replace('{url}', encodeURIComponent(url))
              .replace('{title}', encodeURIComponent(title || ''));
  }

  function previewCard(p){
    var item = document.createElement('div');
    item.className = 'agentimus-sc__card agentimus-sc__card--preview';
    var media = p.image
      ? '<div class="agentimus-sc__previmg" style="background-image:url(&quot;' + esc(p.image) + '&quot;)"></div>'
      : '<div class="agentimus-sc__previmg agentimus-sc__previmg--none">' + esc(CFG.i18n.noImage) + '</div>';
    item.innerHTML =
      '<div class="agentimus-sc__head"><span class="agentimus-sc__network">' + esc(CFG.i18n.previewLabel) + '</span></div>' +
      '<div class="agentimus-sc__prevframe">' + media +
        '<div class="agentimus-sc__prevbody">' +
          '<span class="agentimus-sc__prevhost">' + esc(p.host || '') + '</span>' +
          '<span class="agentimus-sc__prevtitle">' + esc(p.title || '') + '</span>' +
          '<span class="agentimus-sc__prevdesc">' + esc(p.description || '') + '</span>' +
        '</div>' +
      '</div>' +
      '<p class="agentimus-sc__prevhint">' + esc(CFG.i18n.previewHint) + '</p>';
    return item;
  }

  function card(net, ctx){
    var item = document.createElement('div');
    item.className = 'agentimus-sc__card';
    var ai = CFG.aiAvailable
      ? '<button type="button" class="button button-small agentimus-sc__aione">' + CFG.sparkSvg + esc(CFG.i18n.rewrite) + '</button>'
      : '';
    item.innerHTML =
      '<div class="agentimus-sc__head">' +
        '<span class="agentimus-sc__network">' + esc(net.label) + '</span>' +
        '<span class="agentimus-sc__count"></span>' +
      '</div>' +
      '<textarea class="agentimus-sc__text" rows="6"></textarea>' +
      (net.hint ? '<p class="agentimus-sc__cardhint">' + esc(net.hint) + '</p>' : '') +
      '<div class="agentimus-sc__row">' +
        '<button type="button" class="button button-small agentimus-sc__copy">' + esc(CFG.i18n.copy) + '</button>' + ai +
        '<button type="button" class="button button-small button-link agentimus-sc__open">' + esc(net.intent.label) + '</button>' +
      '</div>';

    var area    = item.querySelector('.agentimus-sc__text');
    var count   = item.querySelector('.agentimus-sc__count');
    var copyBtn = item.querySelector('.agentimus-sc__copy');
    area.value = net.text;

    function recount(){
      if (!net.limit) { count.textContent = ''; return; }
      var n = area.value.length;
      count.textContent = n + '/' + net.limit + ' ' + CFG.i18n.chars;
      count.classList.toggle('agentimus-sc__count--over', n > net.limit);
    }
    area.addEventListener('input', recount);
    recount();

    copyBtn.addEventListener('click', function(){
      copyText(area, this);
    });
    item.querySelector('.agentimus-sc__open').addEventListener('click', function(){
      // A URL-only composer (Facebook/LinkedIn) can't carry the text — put it
      // on the clipboard on the way out, so the author just pastes on arrival.
      if (net.intent.carries === 'url') {
        copyText(area, copyBtn);
        ctx.note(CFG.i18n.pasteHint);
      }
      window.open(intentHref(net.intent.href, area.value, ctx.permalink(), ctx.title()), '_blank', 'noopener');
    });

    var aiBtn = item.querySelector('.agentimus-sc__aione');
    if (aiBtn) {
      aiBtn.addEventListener('click', function(){
        aiBtn.disabled = true;
        aiBtn.innerHTML = CFG.sparkSvg + esc(CFG.i18n.rewriting);
        post({ post: ctx.id(), network: net.key, use_ai: true })
          .then(function(res){
            aiBtn.disabled = false;
            aiBtn.innerHTML = CFG.sparkSvg + esc(CFG.i18n.rewrite);
            if (!res.ok) { ctx.note((res.body && res.body.message) || CFG.i18n.error); return; }
            area.value = (res.body.network && res.body.network.text) || area.value;
            recount();
            ctx.note(res.body.source === 'ai' ? CFG.i18n.aiNote : CFG.i18n.aiFellBack);
          })
          .catch(function(){
            aiBtn.disabled = false;
            aiBtn.innerHTML = CFG.sparkSvg + esc(CFG.i18n.rewrite);
            ctx.note(CFG.i18n.error);
          });
      });
    }
    return item;
  }

  function boot(){
    var box = document.querySelector('.agentimus-sc');
    if (!box) { return false; }
    var note  = box.querySelector('.agentimus-sc__note');
    var grid  = box.querySelector('.agentimus-sc__grid');
    var aside = box.querySelector('.agentimus-sc__aside');
    var foot  = box.querySelector('.agentimus-sc__foot');
    var loaded = false, busy = false, permalink = '', postTitle = '';

    var ctx = {
      id: function(){ return parseInt(box.getAttribute('data-post'), 10); },
      permalink: function(){ return permalink; },
      title: function(){ return postTitle; },
      note: function(t){ note.textContent = t; }
    };

    function load(){
      if (loaded || busy || !ctx.id()) { return; }
      busy = true;
      note.textContent = CFG.i18n.working;
      post({ post: ctx.id() })
        .then(function(res){
          busy = false;
          if (!res.ok) { note.textContent = (res.body && res.body.message) || CFG.i18n.error; return; }
          loaded = true;
          permalink = res.body.permalink || '';
          postTitle = (res.body.preview && res.body.preview.title) || '';
          note.textContent = CFG.i18n.localNote;
          grid.innerHTML = '';
          aside.innerHTML = '';
          aside.appendChild(previewCard(res.body.preview || {}));
          (res.body.networks || []).forEach(function(net){ grid.appendChild(card(net, ctx)); });
          if (res.body.published === false) {
            foot.textContent = CFG.i18n.unpublished;
            foot.hidden = false;
          } else {
            foot.hidden = true;
          }
        })
        .catch(function(){ busy = false; note.textContent = CFG.i18n.error; });
    }

    if (window.IntersectionObserver) {
      var io = new IntersectionObserver(function(entries){
        for (var i = 0; i < entries.length; i++) {
          if (entries[i].isIntersecting) { io.disconnect(); load(); return; }
        }
      });
      io.observe(box);
    } else {
      load();
    }
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
	 * Tab styles — admin palette, scoped. Every card is the same shape: a
	 * flex column in a uniform grid, textarea filling the middle, actions
	 * pinned to the bottom — so the row reads as one organized set. The
	 * preview card mimics a link card: cover image on a 1.91:1 frame (the og
	 * ratio), then host/title/description.
	 *
	 * @return string
	 */
	public static function css() {
		return '.agentimus-sc__lead{font-size:13px;font-weight:600;color:#1d2327;margin:2px 0 2px}'
			. '.agentimus-sc__intro{color:#646970;font-size:12px;max-width:72ch;margin:0 0 10px}'
			. '.agentimus-sc__note{display:inline-flex;align-items:center;gap:7px;font-size:12px;color:#2c3338;background:#f6f7f7;border:1px solid #dcdcde;border-radius:999px;padding:3px 12px;margin:0 0 2px}'
			. '.agentimus-sc__note:empty{display:none}'
			. '.agentimus-sc__note::before{content:"";flex:none;width:6px;height:6px;border-radius:50%;background:#2271b1}'
			. '.agentimus-sc__body{display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;align-items:flex-start}'
			. '.agentimus-sc__grid{flex:1 1 620px;display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;align-items:stretch}'
			. '.agentimus-sc__aside{flex:1 1 240px;max-width:320px}'
			. '.agentimus-sc__aside .agentimus-sc__card{min-height:0}'
			. '.agentimus-sc__card{display:flex;flex-direction:column;border:1px solid #e0e0e0;border-radius:4px;padding:12px;background:#fff;min-height:240px;box-sizing:border-box}'
			. '.agentimus-sc__head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px}'
			. '.agentimus-sc__network{font-weight:600;font-size:13px}'
			. '.agentimus-sc__count{font-size:11px;color:#646970}'
			. '.agentimus-sc__count--over{color:#b32d2e;font-weight:600}'
			. '.agentimus-sc__text{flex:1;width:100%;font-size:13px;line-height:1.5;resize:none;box-sizing:border-box}'
			. '.agentimus-sc__cardhint{font-size:11px;color:#646970;font-style:italic;margin:4px 0 0}'
			. '.agentimus-sc__row{display:flex;gap:8px;align-items:center;margin-top:8px}'
			. '.agentimus-sc__row .agentimus-sc__open{margin-left:auto}'
			. '.agentimus-sc__aione{display:inline-flex;align-items:center;gap:4px}'
			. '.agentimus-sc__foot{color:#646970;font-size:12px;font-style:italic;margin:10px 0 0}'
			. '.agentimus-sc__prevframe{flex:1;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;display:flex;flex-direction:column;background:#f6f7f7}'
			. '.agentimus-sc__previmg{aspect-ratio:1.91/1;background-size:cover;background-position:center;background-color:#dcdcde}'
			. '.agentimus-sc__previmg--none{display:flex;align-items:center;justify-content:center;text-align:center;color:#646970;font-size:12px;padding:12px;box-sizing:border-box;background:repeating-linear-gradient(45deg,#f0f0f1,#f0f0f1 8px,#e8e8e9 8px,#e8e8e9 16px)}'
			. '.agentimus-sc__prevbody{display:flex;flex-direction:column;gap:2px;padding:8px 10px}'
			. '.agentimus-sc__prevhost{font-size:11px;color:#646970;text-transform:uppercase}'
			. '.agentimus-sc__prevtitle{font-weight:600;font-size:13px;color:#1d2327;overflow:hidden;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical}'
			. '.agentimus-sc__prevdesc{font-size:12px;color:#646970;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}'
			. '.agentimus-sc__prevhint{color:#646970;font-size:11px;margin:8px 0 0}';
	}
}
