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
	 * Register the route the editor polls to refresh the AI Readability rows after a
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
	 * GET /agentimus/v1/page-check/{id} — the AI Readability rows for a saved post,
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
	private function sections() {
		$sections = array();
		if ( $this->readability->is_enabled() ) {
			$sections['readability'] = array(
				'label'  => __( 'AI Readability', 'agentimus' ),
				'render' => array( $this->readability, 'render_meta_box' ),
			);
		}
		if ( $this->schema->is_enabled() ) {
			$sections['schema'] = array(
				'label'  => __( 'JSON-LD', 'agentimus' ),
				'render' => array( $this->schema, 'render_meta_box' ),
			);
		}
		if ( $this->share->is_enabled() ) {
			$sections['share'] = array(
				'label'  => __( 'Share', 'agentimus' ),
				'render' => array( $this->share, 'render_meta_box' ),
			);
		}
		return $sections;
	}

	/**
	 * Add the one box to every agent-visible post type — but only when at least one
	 * section is enabled.
	 */
	public function add_meta_box() {
		if ( empty( $this->sections() ) ) {
			return;
		}
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
		if ( empty( $this->sections() ) ) {
			return;
		}
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
		return '.agentimus-mbbadge{display:inline-flex;align-items:center;gap:5px;margin-left:12px;margin-right:auto;font-size:11px;font-weight:400;color:#996a00;line-height:1;vertical-align:middle}'
			. '.agentimus-panel__tabs{display:flex;gap:16px;border-bottom:1px solid #dcdcde;margin:0 0 14px;padding:0}'
			. '.agentimus-panel__tab{appearance:none;background:none;border:0;border-bottom:2px solid transparent;padding:6px 2px;margin-bottom:-1px;cursor:pointer;font-size:13px;color:#646970}'
			. '.agentimus-panel__tab:hover{color:#1d2327}'
			. '.agentimus-panel__tab.is-active{color:#1d2327;border-bottom-color:#2271b1;font-weight:600}'
			. '.agentimus-panel__pane{display:none}'
			. '.agentimus-panel__pane.is-active{display:block}';
	}
}
