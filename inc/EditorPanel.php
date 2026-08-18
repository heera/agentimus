<?php
/**
 * The single "Agentimus" editor meta box: one branded panel that gathers the
 * per-post Agentimus tools as tabs, instead of scattering a separate meta box per
 * feature across the editor's generic "Meta Boxes" strip.
 *
 * Each tab is a self-contained section renderer ({@see PageCheckMetaBox} = AI
 * Readability, {@see SchemaMetaBox} = JSON-LD, {@see ShareCopy} = Share). Only
 * the enabled sections appear; with a single one enabled the tab bar is
 * omitted. Server-rendered, admin-only.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class EditorPanel {

	const HANDLE = 'agentimus-editor-panel';

	/** @var Settings */
	private $settings;

	/** @var PageCheckMetaBox */
	private $readability;

	/** @var SchemaMetaBox */
	private $schema;

	/** @var ShareCopy */
	private $share;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings    = $settings;
		$this->readability = new PageCheckMetaBox( $settings );
		$this->schema      = new SchemaMetaBox( $settings );
		$this->share       = new ShareCopy( $settings );
	}

	/**
	 * Register the box and its assets — admin only.
	 */
	public function register() {
		// The refresh route must register on REST requests too (which are not
		// is_admin()), so hook it before the admin-only meta box + assets.
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Register the route the editor polls to refresh the Readability rows after a
	 * save. The block editor saves without a page reload, so the server-rendered box
	 * would otherwise stay stale until a manual refresh; this returns the same markup
	 * the meta box renders. Gated per post by the edit_post capability.
	 */
	public function register_rest() {
		register_rest_route(
			'agentimus/v1',
			'/page-check',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_page_check' ),
				'permission_callback' => static function ( $request ) {
					return current_user_can( 'edit_post', absint( $request['post'] ) );
				},
				'args'                => array(
					'post' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET /agentimus/v1/page-check/{id} — the Readability rows for a saved post,
	 * as the markup the meta box would render. `enabled:false` when the section is
	 * off (the editor simply leaves the panel as-is).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_page_check( \WP_REST_Request $request ) {
		if ( ! $this->readability->is_enabled() ) {
			return rest_ensure_response( array( 'enabled' => false, 'html' => '' ) );
		}
		$post = get_post( absint( $request['post'] ) );
		if ( ! $post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error(
				'agentimus_page_check_not_found',
				__( 'That content is not available.', 'agentimus' ),
				array( 'status' => 404 )
			);
		}
		return rest_ensure_response(
			array(
				'enabled' => true,
				'html'    => PageCheckMetaBox::rows_html( $post ),
			)
		);
	}

	/**
	 * The enabled sections in tab order: key => { label, render }. Readability
	 * first (the friendliest at-a-glance view), then the JSON-LD graph.
	 *
	 * @return array<string,array{label:string,render:callable}>
	 */
	private function catalogue() {
		return array(
			'readability' => array(
				// ⭐ NOT "AI Readability". Twelve of its fourteen rows are checks a
				// classic SEO tool runs too — alt text, headings, heading order,
				// thin content, freshness — and naming only one audience taught
				// owners that alt text was an AI nicety they could skip. His call,
				// 2026-08-18. The audience is stated once in the panel's lead
				// instead, so each row can stay short.
				'label'  => __( 'Readability', 'agentimus' ),
				// ⭐ The TAB and its SWITCH are different words, so a note that
				// only named the tab would send someone hunting for "JSON-LD" in a
				// settings list that says "Rich data for search". Both, always.
				'switch' => __( 'Readability tips', 'agentimus' ),
				'blurb'  => __( 'How easily this page can be read, sectioned and quoted — by AI assistants, by search engines and by people.', 'agentimus' ),
				'on'     => $this->readability->is_enabled(),
				'render' => array( $this->readability, 'render_meta_box' ),
			),
			'schema'      => array(
				'label'  => __( 'JSON-LD', 'agentimus' ),
				'switch' => __( 'Rich data for search', 'agentimus' ),
				'blurb'  => __( 'The structured description this page publishes, exactly as a search engine or an assistant receives it.', 'agentimus' ),
				'on'     => $this->schema->is_enabled(),
				'render' => array( $this->schema, 'render_meta_box' ),
			),
			'share'       => array(
				'label'  => __( 'Share', 'agentimus' ),
				'switch' => __( 'Share drafts', 'agentimus' ),
				'blurb'  => __( 'Ready-to-post drafts for X, LinkedIn, Facebook and more, written from the post itself.', 'agentimus' ),
				'on'     => $this->share->is_enabled(),
				'render' => array( $this->share, 'render_meta_box' ),
			),
		);
	}

	/**
	 * The sections that are switched OFF — what the box is missing, so it can say
	 * so instead of looking broken. ⚠️ A panel with one section renders no tab
	 * bar at all, so an owner with two of three switched off sees an unlabelled
	 * box and reasonably concludes it is broken. He did, 2026-08-18.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function missing() {
		$out = array();
		foreach ( $this->catalogue() as $key => $section ) {
			if ( empty( $section['on'] ) ) {
				$out[ $key ] = $section;
			}
		}
		return $out;
	}

	private function sections() {
		$sections = array();
		foreach ( $this->catalogue() as $key => $section ) {
			if ( ! empty( $section['on'] ) ) {
				$sections[ $key ] = array(
					'label'  => $section['label'],
					'render' => $section['render'],
				);
			}
		}
		return $sections;
	}

	/**
	 * Add the one box to every agent-visible post type — but only when at least one
	 * section is enabled.
	 */
	public function add_meta_box() {
		// ⛔ NO EARLY RETURN when everything is switched off. It used to bail,
		// and the box simply vanished — a first-timer had no clue the editor
		// could offer any of this, and someone who had switched it off had
		// nothing to switch back on. His call, 2026-08-18. The box stays and
		// explains itself instead; WordPress's own Screen Options still hides
		// it for anyone who truly wants it gone.
		foreach ( Content::post_types() as $type ) {
			add_meta_box(
				'agentimus-panel',
				Admin::brand_title( __( 'Agentimus', 'agentimus' ) ),
				array( $this, 'render_meta_box' ),
				$type,
				'normal',
				'low'
			);
		}
	}

	/**
	 * Render the tabbed panel. A single enabled section renders plainly (no tabs);
	 * two or more get a tab bar with the first active.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		$sections = $this->sections();
		$tabbed   = count( $sections ) > 1;

		echo '<div class="agentimus-panel">';

		if ( ! $sections ) {
			$this->render_intro();
			echo '</div>';
			return;
		}

		if ( $tabbed ) {
			echo '<div class="agentimus-panel__tabs" role="tablist">';
			$first = true;
			foreach ( $sections as $key => $section ) {
				printf(
					'<button type="button" class="agentimus-panel__tab%1$s" data-target="%2$s">%3$s</button>',
					$first ? ' is-active' : '',
					esc_attr( $key ),
					esc_html( $section['label'] )
				);
				$first = false;
			}
			echo '</div>';
		}

		// ⭐ AN ABSENCE MUST NAME ITSELF, AND DO IT WHERE THE ABSENCE IS. This sat
		// at the foot of the box at first — fourteen rows below the tab bar it was
		// talking about, which is nowhere near where anyone looks for a missing
		// tab. His catch, 2026-08-18. Directly under the tabs it reads as a
		// caption on the row of tabs; with only one section enabled there is no
		// tab bar at all, and it takes that empty place instead.
		$this->render_missing( $tabbed );

		$first = true;
		foreach ( $sections as $key => $section ) {
			$active = ( ! $tabbed || $first ) ? ' is-active' : '';
			printf(
				'<div class="%1$s" data-pane="%2$s">',
				esc_attr( 'agentimus-panel__pane' . $active ),
				esc_attr( $key )
			);
			call_user_func( $section['render'], $post );
			echo '</div>';
			$first = false;
		}

		echo '</div>';
	}

	/**
	 * What this box offers, for an editor where none of it is switched on. Not a
	 * nag — it names the three things and the switch that brings each one back,
	 * then gets out of the way the moment any of them is on.
	 */
	private function render_intro() {
		echo '<div class="agentimus-panel__intro">';
		// ⭐ The lead explains the pill ONCE, so each card can carry the switch
		// name bare instead of repeating "Switch:" three times.
		echo '<p class="agentimus-panel__introlead">'
			. esc_html__( 'Agentimus can add three things to this editor. All of them are switched off right now — the name beside each one is its switch.', 'agentimus' )
			. '</p>';

		echo '<ul class="agentimus-panel__introlist">';
		foreach ( $this->catalogue() as $section ) {
			printf(
				'<li class="agentimus-panel__introitem">'
					. '<span class="agentimus-panel__introhead">'
						. '<span class="agentimus-panel__introname">%1$s</span>'
						. '<span class="agentimus-panel__introswitch">%2$s</span>'
					. '</span>'
					. '<span class="agentimus-panel__introblurb">%3$s</span>'
				. '</li>',
				esc_html( $section['label'] ),
				esc_html( $section['switch'] ),
				esc_html( $section['blurb'] )
			);
		}
		echo '</ul>';

		echo '<p class="agentimus-panel__introfoot">'
			. esc_html__( 'Turn any of them on under Settings → Discovery → Features.', 'agentimus' )
			. '</p>';
		echo '</div>';
	}

	/**
	 * The one-line note about switched-off sections. Nothing is printed when all
	 * three are on — which is the default, so most sites never see this.
	 */
	private function render_missing( $tabbed = false ) {
		$missing = $this->missing();
		if ( ! $missing ) {
			return;
		}

		$tabs    = array();
		$switches = array();
		foreach ( $missing as $section ) {
			$tabs[]    = $section['label'];
			$switches[] = '“' . $section['switch'] . '”';
		}

		// ⭐ The TAB NAMES carry the emphasis. They are what the reader is looking
		// for and failing to find, so they have to be the part the eye lands on —
		// the rest of the sentence is instructions, and instructions are read
		// second. Both halves are escaped before they meet the template.
		$note = sprintf(
			/* translators: 1: the names of the switched-off tabs, emphasised. 2: the matching switch names, quoted. */
			_n(
				'The %1$s tab is switched off. Turn on %2$s under Settings → Discovery → Features.',
				'The %1$s tabs are switched off. Turn on %2$s under Settings → Discovery → Features.',
				count( $missing ),
				'agentimus'
			),
			'<strong>' . esc_html( self::and_list( $tabs ) ) . '</strong>',
			esc_html( self::and_list( $switches ) )
		);

		printf(
			'<p class="agentimus-panel__off%1$s">%2$s</p>',
			$tabbed ? '' : ' is-alone',
			wp_kses_post( $note ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both inserted values are escaped above; only <strong> survives kses.
		);
	}

	/**
	 * "a, b and c" — the way a person says it, not a comma-joined machine list.
	 *
	 * @param array $items Already-formatted items.
	 * @return string
	 */
	private static function and_list( array $items ) {
		$items = array_values( array_filter( $items ) );
		$n     = count( $items );
		if ( $n < 2 ) {
			return $n ? $items[0] : '';
		}
		$last = array_pop( $items );
		/* translators: 1: all items but the last, comma-joined. 2: the last item. */
		return sprintf( __( '%1$s and %2$s', 'agentimus' ), implode( ', ', $items ), $last );
	}

	/**
	 * Enqueue the combined panel + section assets — only on the editor for a
	 * covered post type, and only when a section is enabled. Same no-build inline
	 * pattern the sections used before.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		// ⛔ NO SECTIONS-EMPTY GUARD HERE EITHER. It used to bail alongside the
		// one in add_meta_box(), which was consistent while the box did not
		// render at all. Now that it renders an empty state, bailing here left
		// that state with NO STYLESHEET — names, switch pills and blurbs ran
		// together as one unbroken line. Seen live, 2026-08-18.
		// ⚠️ My own preview harness had injected the CSS by hand, so it looked
		// right there and only there. The scripts below are self-guarding: the
		// tab binder finds no tabs, and the REST refresh returns early with no
		// readability pane.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, Content::post_types(), true ) ) {
			return;
		}

		wp_register_style( self::HANDLE, false, array(), AGENTIMUS_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, self::css() . PageCheckMetaBox::css() . SchemaMetaBox::css() . ShareCopy::css() );

		// In the block editor the panel refreshes itself over REST after a save (no
		// page reload happens there); those two core scripts back that. The classic
		// editor reloads the whole page on save, so it needs neither.
		$is_block = $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
		$deps     = $is_block ? array( 'wp-data', 'wp-api-fetch' ) : array();
		wp_register_script( self::HANDLE, false, $deps, AGENTIMUS_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, self::js() . SchemaMetaBox::js() . ShareCopy::js() );
	}

	/**
	 * Tab-switch behaviour, scoped per panel so multiple panels never cross-talk.
	 *
	 * @return string
	 */
	private static function js() {
		return <<<'JS'
(function(){var tabs=document.querySelectorAll('.agentimus-panel__tab');Array.prototype.forEach.call(tabs,function(tab){tab.addEventListener('click',function(){var panel=tab.closest('.agentimus-panel');if(!panel){return;}var target=tab.getAttribute('data-target');Array.prototype.forEach.call(panel.querySelectorAll('.agentimus-panel__tab'),function(t){t.classList.toggle('is-active',t===tab);});Array.prototype.forEach.call(panel.querySelectorAll('.agentimus-panel__pane'),function(p){p.classList.toggle('is-active',p.getAttribute('data-pane')===target);});});});})();
(function(){
	if(!window.wp||!wp.data||!wp.apiFetch){return;}
	if(!document.querySelector('.agentimus-panel__pane[data-pane="readability"]')){return;}
	var sel=function(){return wp.data.select('core/editor');};
	if(!sel()||!sel().getCurrentPostId){return;}
	function refresh(){
		var id=sel().getCurrentPostId();
		if(!id){return;}
		wp.apiFetch({path:'/agentimus/v1/page-check?post='+id}).then(function(res){
			if(!res||typeof res.html!=='string'||!res.html){return;}
			Array.prototype.forEach.call(document.querySelectorAll('.agentimus-panel__pane[data-pane="readability"]'),function(pane){pane.innerHTML=res.html;});
		}).catch(function(){});
	}
	var was=false;
	wp.data.subscribe(function(){
		var ed=sel();
		if(!ed||!ed.isSavingPost){return;}
		var saving=ed.isSavingPost()&&!ed.isAutosavingPost();
		if(was&&!saving&&(!ed.didPostSaveRequestSucceed||ed.didPostSaveRequestSucceed())){refresh();}
		was=saving;
	});
})();
(function(){
	/* The collapsed "Meta Boxes" drawer hides real warnings — so a tiny branded
	   badge on core's toggle says when AI-readability checks need attention.
	   Counts come from the panel's own rendered rows (always in sync, including
	   after the post-save refresh above); shows nothing when everything passes. */
	var root=document.getElementById('agentimus-panel');
	if(!root){return;}
	var TILE='<svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" style="flex:none"><rect x="1.2" y="1.2" width="21.6" height="21.6" rx="6" fill="#1b1913" stroke="#146b64" stroke-width="1.5"/><path d="M7.35 17.3 12 6.7 16.65 17.3" stroke="#f3f0e7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.5 13H14.5" stroke="#ad7b18" stroke-width="1.9" stroke-linecap="round"/></svg>';
	function host(){return document.querySelector('.edit-post-meta-boxes-main__presenter > button');}
	function warns(){return document.querySelectorAll('#agentimus-panel .agentimus-pc__row.is-warn, #agentimus-panel .agentimus-pc__row.is-fail').length;}
	function update(){
		var h=host();
		if(!h){return;}
		var n=warns();
		var b=h.querySelector('.agentimus-mbbadge');
		if(!n){if(b){b.remove();}return;}
		if(b&&b.getAttribute('data-n')===String(n)){return;}
		if(!b){
			b=document.createElement('span');
			b.className='agentimus-mbbadge';
			var chevron=h.querySelector(':scope > svg');
			h.insertBefore(b,chevron||null);
		}
		b.setAttribute('data-n',String(n));
		b.innerHTML=TILE+'<span>'+(1===n?'1 check needs attention':n+' checks need attention')+'</span>';
	}
	var tries=0;
	(function boot(){if(host()){update();}else if(tries++<40){setTimeout(boot,500);}})();
	if(window.MutationObserver){
		var t=null,sched=function(){clearTimeout(t);t=setTimeout(update,250);};
		new MutationObserver(sched).observe(root,{childList:true,subtree:true});
		// The editor may re-render the toggle (wiping the badge) — watch and re-add.
		var wait=setInterval(function(){
			var m=document.querySelector('.edit-post-meta-boxes-main__presenter');
			if(m){clearInterval(wait);new MutationObserver(sched).observe(m,{childList:true,attributes:true});}
		},700);
	}
})();
JS;
	}

	/**
	 * Tab-bar styles. The section styles are appended by their own css() methods.
	 *
	 * @return string
	 */
	private static function css() {
		// The box's own header strip gets a quiet ground to tie it to the body.
		// The cursor is left to core on purpose: metaboxes are draggable, and
		// the move cursor is WordPress telling the owner so — not ours to hide.
		return '#agentimus-panel .postbox-header{background:#fbfbfc}'
			. '.agentimus-mbbadge{display:inline-flex;align-items:center;gap:5px;margin-left:12px;margin-right:auto;font-size:11px;font-weight:400;color:#996a00;line-height:1;vertical-align:middle}'
			. '.agentimus-panel__tabs{display:flex;gap:16px;border-bottom:1px solid #dcdcde;margin:0 0 14px;padding:0}'
			// The switched-off note: below everything, above a hairline, quiet
			// enough to read as information rather than a warning.
			// ⚠️ PLAIN GREY 12px WAS INVISIBLE — it sat directly under a tab bar and
			// read as more chrome, so the one line explaining a missing tab was the
			// easiest thing in the box to skip. His call, 2026-08-18. Core's own
			// info-notice colours: recognisable at a glance, and blue rather than
			// amber because a switched-off feature is a state, not a fault.
			. '.agentimus-panel__off{display:block;margin:0 0 14px;padding:9px 12px;background:#f0f6fc;border-left:4px solid #72aee6;border-radius:2px;color:#2c3338;font-size:12px;line-height:1.6}'
			. '.agentimus-panel__off strong{font-weight:600;color:#1d2327}'
			// With one section there is no tab bar above it, so it takes that place
			// with a little more air rather than hugging the box edge.
			. '.agentimus-panel__off.is-alone{margin:2px 0 14px}'
			// The empty state. Plain and welcoming rather than tinted like the
			// switched-off note: nothing is wrong here, there is simply nothing
			// switched on yet. One card per thing, so the three are weighed
			// against each other rather than read as a paragraph.
			. '.agentimus-panel__introlead{margin:0 0 14px;font-size:13px;line-height:1.6;color:#1d2327;max-width:64em}'
			. '.agentimus-panel__introlist{display:grid;gap:10px;margin:0 0 14px;padding:0;list-style:none}'
			. '.agentimus-panel__introitem{padding:12px 14px;background:#f6f7f7;border:1px solid #f0f0f1;border-radius:4px}'
			// ⛔ NOT space-between. This box is full-width, so pushing the pill to
			// the far edge left a hand's width of nothing between a name and its
			// own switch — two related things reading as unrelated. It sits
			// directly after the name instead.
			. '.agentimus-panel__introhead{display:flex;align-items:baseline;gap:9px;margin-bottom:4px}'
			. '.agentimus-panel__introname{font-weight:600;font-size:13px;color:#1d2327}'
			// ⚠️ flex:0 0 auto + nowrap, or a long switch name gets squeezed and
			// breaks mid-word — the exact bug the NEW badge had tonight.
			. '.agentimus-panel__introswitch{flex:0 0 auto;white-space:nowrap;font-size:11px;line-height:1.6;color:#50575e;background:#fff;border:1px solid #dcdcde;border-radius:999px;padding:1px 9px}'
			. '.agentimus-panel__introblurb{display:block;font-size:12px;line-height:1.6;color:#50575e}'
			. '.agentimus-panel__introfoot{margin:0;font-size:12px;color:#646970}'
			. '.agentimus-panel__tab{appearance:none;background:none;border:0;border-bottom:2px solid transparent;padding:6px 2px;margin-bottom:-1px;cursor:pointer;font-size:13px;color:#646970}'
			. '.agentimus-panel__tab:hover{color:#1d2327}'
			. '.agentimus-panel__tab.is-active{color:#1d2327;border-bottom-color:#2271b1;font-weight:600}'
			. '.agentimus-panel__pane{display:none}'
			. '.agentimus-panel__pane.is-active{display:block}';
	}
}
