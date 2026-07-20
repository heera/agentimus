<?php
/**
 * AssistantEditor — the writing assistant's presence INSIDE the block editor.
 * The drawer plans (brief → outline → draft); the editor is the image
 * workshop. Two affordances, both driving the same governed
 * /assistant/generate-image route (scene-describer → image model →
 * media-library import, rate-limited at the Assist choke point):
 *
 *  - a "Generate image" button in EVERY Image block's toolbar — the block's
 *    own alt text is the prompt, so the placeholders the assistant leaves
 *    behind (alt filled, media empty) are one click from real images, and a
 *    filled block regenerates the same way;
 *  - a "Featured image (AI)" document-sidebar panel — one click, seeded from
 *    the post title, set as featured on success;
 *  - the same one click again on the AI-Readability panel's "No featured
 *    image" row (rendered by PageCheckMetaBox when featured_one_click() says
 *    the whole chain could deliver, bound here by delegation).
 *
 * Enqueued only where it can actually work: block editor on agent-visible
 * types, writes switch on, an image-capable provider connected, and a user
 * who may upload. No build step — the script is inline, using wp globals,
 * the same pattern EditorPanel ships with.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class AssistantEditor {

	const HANDLE = 'agentimus-assistant-editor';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the editor assets and the featured-image box.
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		// Priority 9: registered BEFORE Topics' box (default 10), so in the
		// side column this box sits directly ABOVE "AI description & topics".
		add_action( 'add_meta_boxes', array( $this, 'featured_meta_box' ), 9 );
	}

	/**
	 * Whether the editor-side assistant can serve the current screen: block
	 * editor on an agent-visible type, writes switch on, a user who may edit.
	 * The TEXT features (per-section revise) need no more than this; the image
	 * features add their own capability/provider checks on top.
	 *
	 * @return bool
	 */
	private function can_serve() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, Content::post_types(), true ) ) {
			return false;
		}
		if ( ! method_exists( $screen, 'is_block_editor' ) || ! $screen->is_block_editor() ) {
			return false;
		}
		if ( ! $this->settings->enabled( 'enable_agent_writes' ) ) {
			return false;
		}
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Whether image generation can serve here — on top of {@see can_serve()}:
	 * the user may upload and the provider can actually paint.
	 *
	 * @return bool
	 */
	private function images_ready() {
		return current_user_can( 'upload_files' ) && Assistant::image_ready();
	}

	/**
	 * Whether the AI-Readability "No featured image" row may offer its one-click
	 * Generate — true only where the whole chain could deliver: a block-editor
	 * context (the panel's REST refresh counts — only the block editor calls it),
	 * the writes switch on, a user who may edit and upload, and a provider that
	 * can actually paint. Static because the row renderer
	 * ({@see PageCheckMetaBox::rows_html()}) has no AssistantEditor instance.
	 *
	 * @return bool
	 */
	public static function featured_one_click() {
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || ! method_exists( $screen, 'is_block_editor' ) || ! $screen->is_block_editor() ) {
				return false;
			}
		}
		if ( ! ( new Settings() )->enabled( 'enable_agent_writes' ) ) {
			return false;
		}
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		return Assistant::image_ready();
	}

	/**
	 * The "Featured image (AI)" box — a real side meta box (not a document
	 * panel), so it sits in the meta-box column right above the
	 * "AI description & topics" box, wearing the same Agentimus mark.
	 */
	public function featured_meta_box() {
		if ( ! $this->can_serve() || ! $this->images_ready() ) {
			return;
		}
		$screen = get_current_screen();
		add_meta_box(
			'agentimus-featured-ai',
			self::meta_box_title( __( 'Generate Featured Image', 'agentimus' ) ),
			array( $this, 'render_featured_box' ),
			$screen->post_type,
			'side',
			'default'
		);
	}

	/**
	 * The box body: one sentence, one full-width button. The behaviour lives
	 * in the same inline editor script that powers the per-block Generate.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_featured_box( $post ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP passes the post; the JS reads editor state instead.
		echo '<p class="agentimus-feat__hint">'
			. esc_html__( 'Generates a featured image from the post title and saves it to your library.', 'agentimus' )
			. '</p>';
		echo '<button type="button" class="button agentimus-feat__btn" id="agentimus-generate-featured">'
			. esc_html__( 'Generate featured image', 'agentimus' )
			. '</button>';
	}

	/**
	 * The shared Agentimus mark + title, identical to the one on the
	 * "AI description & topics" box (see Topics::meta_box_title()).
	 *
	 * @param string $text The plain-text title (already translated).
	 * @return string
	 */
	private static function meta_box_title( $text ) {
		return Admin::brand_title( $text ); // The one shared brand tile.
	}

	/**
	 * Enqueue on block-editor screens for agent-visible types — and only when
	 * every prerequisite holds, so a dead button never renders.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		if ( ! $this->can_serve() ) {
			return;
		}

		wp_register_script(
			self::HANDLE,
			false,
			array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-blocks', 'wp-data', 'wp-api-fetch' ),
			AGENTIMUS_VERSION,
			true
		);
		wp_enqueue_script( self::HANDLE );
		// The image affordances hide themselves when the provider can't paint
		// or the user can't upload; the text features stand on their own.
		wp_add_inline_script(
			self::HANDLE,
			'var _agentimusEd = ' . wp_json_encode( array( 'imagesReady' => $this->images_ready() ) ) . ';',
			'before'
		);
		wp_add_inline_script( self::HANDLE, self::js() );

		wp_register_style( self::HANDLE, false, array(), AGENTIMUS_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style(
			self::HANDLE,
			'#agentimus-featured-ai .hndle{white-space:nowrap}'
			. '.agentimus-feat__hint{margin:0 0 10px;color:#646970;font-size:12px;line-height:1.5}'
			. '.agentimus-feat__btn{width:100%;text-align:center;justify-content:center}'
			. '.agentimus-feat__btn[disabled]{cursor:default}'
		);
	}

	/**
	 * The editor script: the per-block Generate button and the featured panel.
	 *
	 * @return string
	 */
	private static function js() {
		return <<<'JS'
(function () {
	if (!window.wp || !wp.hooks || !wp.element || !wp.blockEditor || !wp.components) { return; }
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	/* The brand tile — the same mark every Agentimus meta box header wears. */
	var tileIcon = function (size) {
		return el('svg', { width: size, height: size, viewBox: '0 0 24 24', fill: 'none', 'aria-hidden': 'true', style: { flex: 'none' } },
			el('rect', { x: 1.2, y: 1.2, width: 21.6, height: 21.6, rx: 6, fill: '#1b1913', stroke: '#146b64', strokeWidth: 1.5 }),
			el('path', { d: 'M7.35 17.3 12 6.7 16.65 17.3', stroke: '#f3f0e7', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' }),
			el('path', { d: 'M9.5 13H14.5', stroke: '#ad7b18', strokeWidth: 1.9, strokeLinecap: 'round' })
		);
	};
	var tile = tileIcon(16);
	function notice(kind, msg) {
		wp.data.dispatch('core/notices')[('ok' === kind) ? 'createSuccessNotice' : 'createErrorNotice'](msg, { type: 'snackbar' });
	}
	function generate(alt, then, done) {
		var title = '';
		try { title = wp.data.select('core/editor').getEditedPostAttribute('title') || ''; } catch (e) {}
		wp.apiFetch({ path: '/agentimus/v1/assistant/generate-image', method: 'POST', data: { alt: alt, title: title } })
			.then(function (r) { then(r); notice('ok', 'Image generated and saved to your media library.'); })
			.catch(function (e) { notice('err', (e && e.message) || 'The image didn’t come back — please try again.'); })
			.then(function () { done(); });
	}

	var imagesReady = !!(window._agentimusEd && window._agentimusEd.imagesReady);
	var REVISABLE = ['core/paragraph', 'core/heading', 'core/list', 'core/quote'];

	/* 1 — one BlockEdit filter carrying both affordances:
	   image blocks get the toolbar "Generate image" (when the provider can
	   paint), and text blocks get the "Revise with AI" sidebar panel — the
	   selected block is the anchor; the instruction may rewrite it or ADD new
	   blocks around it ("add a conclusion after this"), and the server answers
	   with the section's final form as real block markup. */
	wp.hooks.addFilter('editor.BlockEdit', 'agentimus/assistant',
		wp.compose.createHigherOrderComponent(function (BlockEdit) {
			return function (props) {
				var isImage = 'core/image' === props.name;
				var isText = -1 !== REVISABLE.indexOf(props.name);
				if ((!isImage || !imagesReady) && !isText) { return el(BlockEdit, props); }
				var state = useState(false);
				var busy = state[0];
				var setBusy = state[1];
				var ask = useState('');
				var text = ask[0];
				var setText = ask[1];

				if (isImage) {
					var alt = (props.attributes && props.attributes.alt) || '';
					return el(Fragment, null,
						el(BlockEdit, props),
						el(wp.blockEditor.BlockControls, { group: 'other' },
							el(wp.components.ToolbarButton, {
								icon: tileIcon(20),
								label: busy ? 'Generating…' : 'Generate image from the alt text',
								isBusy: busy,
								disabled: busy,
								onClick: function () {
									if (busy) { return; }
									if (alt.trim().length < 5) {
										notice('err', 'Write the alt text first — it’s the description the image is painted from.');
										return;
									}
									setBusy(true);
									generate(alt, function (r) {
										props.setAttributes({ id: r.id, url: r.fullUrl || r.url, alt: alt, sizeSlug: 'large' });
									}, function () { setBusy(false); });
								}
							})
						)
					);
				}

				var revise = function () {
					var instruction = text.trim();
					if (busy) { return; }
					if (instruction.length < 4) {
						notice('err', 'Say what to change or add — e.g. “make this shorter” or “add a conclusion after this”.');
						return;
					}
					var sel = wp.data.select('core/block-editor').getBlock(props.clientId);
					if (!sel) { return; }
					var title = '';
					var context = '';
					try {
						title = wp.data.select('core/editor').getEditedPostAttribute('title') || '';
						context = wp.data.select('core/editor').getEditedPostContent() || '';
					} catch (e) {}
					setBusy(true);
					wp.apiFetch({
						path: '/agentimus/v1/assistant/revise-block',
						method: 'POST',
						data: { block: wp.blocks.serialize(sel), instruction: instruction, context: context, title: title }
					}).then(function (r) {
						var blocks = wp.blocks.parse(r.content);
						if (!blocks.length) { throw new Error('The revision came back empty.'); }
						wp.data.dispatch('core/block-editor').replaceBlocks(props.clientId, blocks);
						setText('');
						notice('ok', 'Section updated — undo (Ctrl+Z) brings the old version back.');
					}).catch(function (e) {
						notice('err', (e && e.message) || 'The revision didn’t come back — please try again.');
					}).then(function () { setBusy(false); });
				};

				return el(Fragment, null,
					el(BlockEdit, props),
					el(wp.blockEditor.InspectorControls, null,
						el(wp.components.PanelBody, {
							/* Deliberately generic: this panel rewrites the selected
							   section AND inserts new blocks around it — "Revise"
							   would undersell half of what it does. */
							title: el('span', { style: { display: 'inline-flex', alignItems: 'center', gap: '6px' } }, tile, 'Ask AI'),
							initialOpen: true
						},
							el(wp.components.TextareaControl, {
								label: 'What should change here?',
								hideLabelFromVision: true,
								placeholder: 'e.g. make this shorter · turn it into a list · add a conclusion after this',
								value: text,
								disabled: busy,
								onChange: setText,
								rows: 3,
								__nextHasNoMarginBottom: true
							}),
							el('p', { style: { margin: '6px 0 10px', color: '#757575', fontSize: '12px' } },
								'Works on this section. It can also add new blocks before or after it — the whole post travels along as context.'),
							el(wp.components.Button, {
								variant: 'secondary',
								isBusy: busy,
								disabled: busy,
								style: { width: '100%', justifyContent: 'center' },
								onClick: revise
							}, busy ? 'Working…' : 'Apply')
						)
					)
				);
			};
		}, 'withAgentimusAssistant')
	);

	/* 2 — featured-image generation, shared by its two buttons: the side meta
	   box's (server-rendered once, bound directly) and the AI-Readability
	   "No featured image" row's one-click (that panel re-renders its rows after
	   every save, so the click is delegated on the document — the Fix-with-AI
	   pattern). Both seed from the post title and set the result as featured. */
	function setFeaturedFromTitle(btn) {
		if (btn.disabled) { return; }
		var title = '';
		try { title = (wp.data.select('core/editor').getEditedPostAttribute('title') || '').trim(); } catch (e) {}
		if (!title) {
			notice('err', 'Give the post a title first — the featured image is generated from it.');
			return;
		}
		var idle = btn.innerHTML;
		btn.disabled = true;
		btn.textContent = 'Generating…';
		generate('A featured image for the post “' + title + '”', function (r) {
			wp.data.dispatch('core/editor').editPost({ featured_media: r.id });
		}, function () {
			btn.disabled = false;
			btn.innerHTML = idle;
		});
	}
	function bindFeatured() {
		var btn = document.getElementById('agentimus-generate-featured');
		if (btn) { btn.addEventListener('click', function () { setFeaturedFromTitle(btn); }); }
	}
	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', bindFeatured);
	} else {
		bindFeatured();
	}
	if (imagesReady) {
		document.addEventListener('click', function (e) {
			var btn = e.target && e.target.closest ? e.target.closest('.agentimus-pc__genfeat') : null;
			if (btn) { setFeaturedFromTitle(btn); }
		});
	}
})();
JS;
	}
}
