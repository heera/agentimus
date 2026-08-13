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
		// side column this box sits directly ABOVE "AI Description & Topics".
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
	 * "AI Description & Topics" box, wearing the same Agentimus mark.
	 */
	public function featured_meta_box() {
		if ( ! $this->can_serve() || ! $this->images_ready() ) {
			return;
		}
		$screen = get_current_screen();
		add_meta_box(
			'agentimus-featured-ai',
			// Short title, like core's own panel — the button below says what the
			// box DOES, and the title stays one line in the 280px sidebar.
			self::meta_box_title( __( 'Featured Image', 'agentimus' ) ),
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
	 * "AI Description & Topics" box (see Topics::meta_box_title()).
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
			// wp-plugins carries registerPlugin, which mounts the multi-block ask
			// into the block options menu — the one place Gutenberg still renders
			// while several blocks are selected.
			array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-blocks', 'wp-data', 'wp-api-fetch', 'wp-plugins', 'wp-edit-post' ),
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
			// Header reads like a core sidebar panel (Categories/Tags): title +
			// the collapse toggle only — Heera's call 2026-07-25. The classic
			// move-up/down arrows add ~88px of chrome (wider still since 7.1
			// wrapped them in tooltips) and forced the title onto two lines in
			// the 280px sidebar; boxes stay drag-sortable by their headers.
			'#agentimus-featured-ai .handle-order-higher,#agentimus-featured-ai .handle-order-lower{display:none}'
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

	/* Whichever blocks the ask applies to: the multi-selection when there is
	   one, otherwise the single selected block. Gutenberg keeps these in two
	   different places, and reading only the second is why "select three
	   paragraphs" used to mean "revise the last one". */
	function targetIds() {
		var be = wp.data.select('core/block-editor');
		var many = be.getMultiSelectedBlockClientIds ? be.getMultiSelectedBlockClientIds() : [];
		if (many && many.length) { return many; }
		var one = be.getSelectedBlockClientId();
		return one ? [one] : [];
	}

	/* Blocks in a selection the assistant can't write back. The server answers
	   with clean HTML in its own vocabulary, so anything outside it would come
	   back as something else — a table would return as paragraphs. Named rather
	   than silently dropped: the fix is to narrow the selection, which takes a
	   second once you know which block is the problem. */
	function unsupportedIn(blocks) {
		var names = [];
		(blocks || []).forEach(function (b) {
			if (b && -1 === REVISABLE.indexOf(b.name) && -1 === names.indexOf(b.name)) { names.push(b.name); }
		});
		return names;
	}

	/* ONE revision path, shared by the block panel and the multi-block modal, so
	   the two can never drift. Sends the selection as serialized block markup
	   plus the post as context; the answer is real block markup that REPLACES
	   the whole selection — which is how one instruction can turn three
	   paragraphs into two, or add a heading above them. */
	function reviseBlocks(ids, instruction, quiet) {
		var be = wp.data.select('core/block-editor');
		var blocks = ids.map(function (id) { return be.getBlock(id); }).filter(Boolean);
		if (!blocks.length) { return Promise.resolve(false); }

		var title = '';
		var context = '';
		var type = '';
		try {
			var ed = wp.data.select('core/editor');
			title = ed.getEditedPostAttribute('title') || '';
			context = ed.getEditedPostContent() || '';
			/* The post type rides along so a page is revised with the page-shaped
			   prompt — shorter, no invented sections — instead of the article one. */
			type = ed.getCurrentPostType() || '';
		} catch (e) {}

		/* Returns a promise so a plan can run its accepted edits one after another
		   and know when each has landed. `quiet` silences the per-edit snackbar:
		   during a plan the summary at the end is the honest report, and eleven
		   toasts are not. */
		var sent = wp.blocks.serialize(blocks);

		return wp.apiFetch({
			path: '/agentimus/v1/assistant/revise-block',
			method: 'POST',
			data: {
				block: sent,
				instruction: instruction,
				context: context,
				title: title,
				type: type
			}
		}).then(function (r) {
			var out = wp.blocks.parse(r.content);
			if (!out.length) { throw new Error('The revision came back empty.'); }

			/* A model that hands back exactly what it was given has not made a
			   change, and must never be reported as though it had. It happens for
			   an honest reason: an instruction can ask for something the writing
			   rules forbid — "add ten products" against "never invent products" —
			   and returning the block untouched is the correct answer to that.
			   Counting it as applied is what turns a principled refusal into a
			   silent no-op the owner discovers by reading their own post. */
			if (norm(wp.blocks.serialize(out)) === norm(sent)) {
				throw new Error('The section came back unchanged — the instruction may ask for something the writer '
					+ 'has no grounds to write. Try naming the specifics yourself.');
			}

			wp.data.dispatch('core/block-editor').replaceBlocks(ids, out);
			if (!quiet) {
				notice('ok', (1 === ids.length ? 'Section updated' : ids.length + ' blocks updated')
					+ ' — undo (Ctrl+Z) brings the old version back.');
			}
			/* The NEW client ids, not a boolean. replaceBlocks mints fresh ids, so
			   the block a caller just revised no longer exists under the id it
			   passed in — and a plan with two edits on the same block would look
			   up a dead id for the second one. */
			return { ids: out.map(function (b) { return b.clientId; }) };
		}).catch(function (e) {
			var msg = (e && e.message) || 'The revision didn’t come back — please try again.';
			/* `quiet` suppresses the toast, never the reason. A plan runs this in a
			   loop, so eleven toasts would be noise — but swallowing WHY an edit
			   failed left "0 changes applied" as the whole explanation. */
			if (!quiet) { notice('err', msg); }
			return { error: msg };
		});
	}

	/* Block markup differs in whitespace between a serialize round-trip and the
	   original, so compare on content, not on characters. */
	function norm(markup) {
		return String(markup).replace(/\s+/g, ' ').trim();
	}

	/* 0 — the MULTI-BLOCK ask, in the block options (⋮) menu.
	   It cannot live where the single-block panel lives: Gutenberg renders the
	   block inspector's per-block InspectorControls only while ONE block is
	   selected — multi-selection replaces the whole inspector with its own
	   summary — so a panel added through editor.BlockEdit disappears at exactly
	   the moment this feature is wanted. BlockSettingsMenuControls does render
	   for a multi-selection, and it hands over the selected client IDs, so the
	   ⋮ menu is where an ask about several blocks belongs. The menu item opens a
	   modal because an instruction wants room to be written, and because the
	   plan review this will grow into needs somewhere to live. */
	function AskModal(props) {
		var ids = props.ids;
		var ask = useState('');
		var text = ask[0];
		var setText = ask[1];
		var work = useState(false);
		var busy = work[0];
		var setBusy = work[1];

		var be = wp.data.select('core/block-editor');
		var blocks = ids.map(function (id) { return be.getBlock(id); }).filter(Boolean);
		var blocked = unsupportedIn(blocks);
		var label = 1 === ids.length ? 'Ask AI about this block' : 'Ask AI about these ' + ids.length + ' blocks';

		var apply = function () {
			var instruction = text.trim();
			if (busy || instruction.length < 4) {
				notice('err', 'Say what to change — e.g. \u201Ctighten these\u201D or \u201Cturn them into one paragraph\u201D.');
				return;
			}
			setBusy(true);
			reviseBlocks(ids, instruction).then(function (r) {
				setBusy(false);
				if (r && r.ids) { props.onDone(); }
			});
		};

		return el(wp.components.Modal, {
			title: label,
			onRequestClose: function () { if (!busy) { props.onDone(); } },
			style: { maxWidth: '520px' }
		},
			el('p', { style: { margin: '0 0 12px', color: '#646970', fontSize: '13px', lineHeight: 1.5 } },
				'One instruction for the whole selection. The answer replaces all of it, so a change can '
				+ 'merge blocks, split them, or add new ones \u2014 the rest of the post travels along as context '
				+ 'and is left alone.'),
			blocked.length
				? el('p', { style: { margin: '0 0 12px', color: '#a15c00', fontSize: '13px', lineHeight: 1.5 } },
					'This selection includes ' + blocked.join(', ') + ' \u2014 the assistant writes text blocks, so '
					+ 'those would come back as something else. Narrow the selection to text and try again.')
				: null,
			el(wp.components.TextareaControl, {
				label: 'What should change?',
				hideLabelFromVision: true,
				placeholder: 'e.g. tighten these two paragraphs \u00B7 make the tone plainer \u00B7 turn this into a list',
				value: text,
				disabled: busy || !!blocked.length,
				onChange: setText,
				rows: 4,
				__nextHasNoMarginBottom: true
			}),
			el('div', { style: { display: 'flex', justifyContent: 'flex-end', gap: '8px', marginTop: '14px' } },
				el(wp.components.Button, {
					variant: 'tertiary',
					disabled: busy,
					onClick: function () { props.onDone(); }
				}, 'Cancel'),
				el(wp.components.Button, {
					variant: 'primary',
					isBusy: busy,
					disabled: busy || !!blocked.length,
					onClick: apply
				}, busy ? 'Working\u2026' : 'Apply')
			)
		);
	}

	/* The menu item and the modal are SIBLINGS at the plugin's root, never nested.
	   Rendering the modal inside the BlockSettingsMenuControls fill put it inside
	   the dropdown\u2019s own popover: the menu stayed open on top of it, and closing
	   the menu first would have unmounted the modal along with the fill that owned
	   it. Held here instead, the dropdown closes the moment the item is clicked and
	   the modal outlives it. */
	function MultiAskRoot() {
		var open = useState(null);
		var ids = open[0];
		var setIds = open[1];

		return el(Fragment, null,
			wp.blockEditor.BlockSettingsMenuControls
				? el(wp.blockEditor.BlockSettingsMenuControls, null, function (fillProps) {
					/* The STORE is the authority, not the Fill\u2019s prop \u2014 the prop is
					   whatever the dropdown was opened against, the selectors are the
					   actual selection. The prop stays as the fallback. */
					var sel = targetIds();
					if (!sel.length && fillProps && fillProps.selectedClientIds) {
						sel = fillProps.selectedClientIds;
					}
					if (!sel || !sel.length) { return null; }
					return el(wp.components.MenuItem, {
						icon: tileIcon(20),
						onClick: function () {
							setIds(sel);
							if (fillProps && fillProps.onClose) { fillProps.onClose(); }
						}
					}, 1 === sel.length ? 'Ask AI about this block' : 'Ask AI about these ' + sel.length + ' blocks');
				})
				: null,
			ids ? el(AskModal, { ids: ids, onDone: function () { setIds(null); } }) : null
		);
	}

	if (wp.plugins && wp.plugins.registerPlugin) {
		wp.plugins.registerPlugin('agentimus-assistant-multi', { render: MultiAskRoot });
	}

	/* ── The document-level planner ──────────────────────────────────────────
	   One instruction about the WHOLE post, answered as a list of targeted
	   edits instead of a new document. The two halves are deliberately split:

	     · the SERVER plans. It sees an outline — number, block type, the first
	       140 characters — so it cannot rewrite anything, and returns targets
	       with a per-target instruction.
	     · the CLIENT executes, one accepted edit at a time, through the same
	       revise-block call the per-section panel uses. Deletes and moves need
	       no model at all.

	   A block the plan doesn't name is never touched, so a table, an embed or a
	   separator is safe without anyone checking for it. */
	function outlineOf(blocks) {
		return blocks.map(function (b) {
			var text = '';
			try {
				/* The rendered text of the block, however it stores it — attributes
				   differ per block type, so serialize-and-strip is the one reading
				   that works for all of them. */
				text = wp.blocks.serialize(b).replace(/<!--[\s\S]*?-->/g, ' ').replace(/<[^>]*>/g, ' ');
				text = text.replace(/\s+/g, ' ').trim();
			} catch (e) {}
			return { type: b.name, text: text };
		});
	}

	/* Human wording for a proposal, so the review list reads as sentences rather
	   than as a payload. */
	function editLabel(edit) {
		var n = edit.target;
		if ('delete' === edit.action) { return 'Delete block ' + n; }
		if ('move-after' === edit.action) { return 'Move block ' + n + ' after block ' + edit.to; }
		if ('insert-after' === edit.action) { return 'Add after block ' + n; }
		if ('insert-before' === edit.action) { return 'Add before block ' + n; }
		return 'Rewrite block ' + n;
	}

	/* ONE accepted edit. Targets were resolved to client ids when the plan
	   arrived, so nothing here depends on positions that earlier edits have
	   already shifted — the single most likely way a batch like this corrupts a
	   document. */
	function applyEdit(edit, anchors) {
		var dispatch = wp.data.dispatch('core/block-editor');
		var select = wp.data.select('core/block-editor');
		/* Where this edit actually lands NOW. A plan can name the same block more
		   than once — "add fragrances 1 and 2 after block 22", then 3 and 4, and
		   so on — and each revision replaces that block with new ones under new
		   ids. Without this the second edit onward would look up a block that
		   stopped existing a moment ago and be dropped as "couldn't be applied".
		   The anchor advances to the LAST block each revision produced, so
		   successive inserts stack in the order they were proposed rather than
		   piling up in reverse. */
		var id = (anchors && anchors[edit.clientId]) || edit.clientId;
		if (!id || !select.getBlock(id)) { return Promise.resolve(false); }

		if ('delete' === edit.action) {
			dispatch.removeBlock(id);
			return Promise.resolve(true);
		}
		if ('move-after' === edit.action) {
			var toId = (anchors && anchors[edit.toClientId]) || edit.toClientId;
			if (!toId || !select.getBlock(toId)) { return Promise.resolve(false); }
			var to = select.getBlockIndex(toId);
			var from = select.getBlockIndex(id);
			/* Moving down lands after the target; moving up lands directly on it,
			   because removing the block first shifts everything below up one. */
			dispatch.moveBlocksToPosition([id], '', '', from < to ? to : to + 1);
			return Promise.resolve(true);
		}

		/* replace / insert-after / insert-before all go through revise-block: it
		   already returns one-or-more blocks for a selection, so an insert is
		   just a revision told to keep what it was given and add beside it. */
		var instruction = edit.instruction;
		if ('insert-after' === edit.action) {
			instruction = 'Leave the block you are given EXACTLY as it is, word for word, and then after it add: ' + instruction;
		} else if ('insert-before' === edit.action) {
			instruction = 'Add this BEFORE the block you are given: ' + instruction
				+ '\nThen repeat the block you were given, exactly as it is, word for word, after it.';
		}
		return reviseBlocks([id], instruction, true).then(function (r) {
			if (!r || !r.ids || !r.ids.length) { return r && r.error ? { error: r.error } : false; }
			if (anchors) {
				/* Both the id this edit was written against and the id it actually
				   used now point at the tail of what came back — so a third edit on
				   the same original block still finds its way. */
				anchors[edit.clientId] = r.ids[r.ids.length - 1];
				anchors[id] = r.ids[r.ids.length - 1];
			}
			return true;
		});
	}

	function PlannerSidebar() {
		var ask = useState('');
		var text = ask[0];
		var setText = ask[1];
		var st = useState(null);
		var plan = st[0];
		var setPlan = st[1];
		var work = useState('');
		var phase = work[0];
		var setPhase = work[1];

		var makePlan = function () {
			var instruction = text.trim();
			if (phase || instruction.length < 4) {
				notice('err', 'Say what should change across the post — e.g. “make the whole thing shorter and plainer”.');
				return;
			}
			var select = wp.data.select('core/block-editor');
			var blocks = select.getBlocks();
			if (!blocks.length) {
				notice('err', 'There’s nothing in this post to plan against yet.');
				return;
			}
			var title = '';
			var type = '';
			try {
				var ed = wp.data.select('core/editor');
				title = ed.getEditedPostAttribute('title') || '';
				type = ed.getCurrentPostType() || '';
			} catch (e) {}

			setPhase('planning');
			wp.apiFetch({
				path: '/agentimus/v1/assistant/plan',
				method: 'POST',
				data: { instruction: instruction, outline: outlineOf(blocks), title: title, type: type }
			}).then(function (r) {
				/* Resolve every address to a client id NOW, against the same block
				   list the plan was made from. From here on the plan refers to
				   blocks, not to positions, and stays correct while it edits. */
				var edits = (r.edits || []).map(function (e) {
					var b = blocks[e.target - 1];
					return Object.assign({}, e, {
						clientId: b && b.clientId,
						excerpt: b ? (outlineOf([b])[0].text || '').slice(0, 90) : '',
						toClientId: e.to && blocks[e.to - 1] ? blocks[e.to - 1].clientId : null,
						accepted: true
					});
				}).filter(function (e) { return !!e.clientId; });
				setPlan({ edits: edits, note: r.note || '' });
			}).catch(function (e) {
				notice('err', (e && e.message) || 'The plan didn’t come back — please try again.');
			}).then(function () { setPhase(''); });
		};

		var runPlan = function () {
			var accepted = (plan.edits || []).filter(function (e) { return e.accepted; });
			if (!accepted.length || phase) { return; }
			var done = 0;
			var failed = 0;
			/* Carried across the whole run: original client id → the id that block
			   lives under now, after earlier edits replaced it. */
			var anchors = {};
			setPhase('applying 1/' + accepted.length);

			var firstError = '';

			var step = function (i) {
				if (i >= accepted.length) { return Promise.resolve(); }
				setPhase('applying ' + (i + 1) + '/' + accepted.length);
				return applyEdit(accepted[i], anchors).then(function (r) {
					if (true === r) {
						done += 1;
					} else {
						failed += 1;
						if (!firstError && r && r.error) { firstError = r.error; }
					}
					return step(i + 1);
				});
			};

			step(0).then(function () {
				setPhase('');
				/* A plan that partly failed stays on screen: its untried half is
				   still worth something, and clearing it would leave the owner
				   with a count and no way to retry what didn't land. */
				if (!failed) { setPlan(null); setText(''); }
				if (!done && failed) {
					/* Nothing landed — the REASON is the whole message. A bare
					   "0 changes applied" sends someone to read their own post
					   looking for a difference that was never made. */
					notice('err', firstError || 'Nothing could be applied — try saying it another way.');
					return;
				}
				notice(failed ? 'err' : 'ok',
					done + (1 === done ? ' change applied' : ' changes applied')
					+ (failed ? ', ' + failed + ' couldn’t be: ' + (firstError || 'no reason given') : '')
					+ ' — undo (Ctrl+Z) steps back through them, and the post still needs saving.');
			});
		};

		var toggle = function (i) {
			var next = plan.edits.slice();
			next[i] = Object.assign({}, next[i], { accepted: !next[i].accepted });
			setPlan({ edits: next, note: plan.note });
		};

		var acceptedCount = plan ? plan.edits.filter(function (e) { return e.accepted; }).length : 0;

		return el(Fragment, null,
			el('p', { style: { margin: '0 0 12px', color: '#646970', fontSize: '13px', lineHeight: 1.5 } },
				'One instruction for the whole post. You get a list of proposed changes to accept or reject — '
				+ 'nothing is touched until you apply them, and blocks the plan doesn’t name are left alone.'),
			el(wp.components.TextareaControl, {
				label: 'What should change?',
				hideLabelFromVision: true,
				placeholder: 'e.g. make the whole thing shorter and plainer · remove anything that repeats · add a short conclusion',
				value: text,
				disabled: !!phase,
				onChange: setText,
				rows: 4,
				__nextHasNoMarginBottom: true
			}),
			el(wp.components.Button, {
				variant: 'primary',
				isBusy: 'planning' === phase,
				disabled: !!phase,
				style: { width: '100%', justifyContent: 'center', marginTop: '10px' },
				onClick: makePlan
			}, 'planning' === phase ? 'Reading the post…' : (plan ? 'Plan again' : 'Plan changes')),

			plan && plan.note
				? el('p', { style: { margin: '14px 0 0', color: '#a15c00', fontSize: '12px', lineHeight: 1.5 } }, plan.note)
				: null,

			plan && !plan.edits.length
				? el('p', { style: { margin: '14px 0 0', color: '#646970', fontSize: '13px' } },
					'No changes proposed — as far as the assistant can tell, the post already does what you asked.')
				: null,

			plan && plan.edits.length
				? el('div', { style: { marginTop: '16px' } },
					el('p', { style: { margin: '0 0 8px', fontWeight: 600, fontSize: '13px' } },
						plan.edits.length + (1 === plan.edits.length ? ' proposed change' : ' proposed changes')),
					plan.edits.map(function (e, i) {
						return el('div', {
							key: i,
							style: {
								padding: '10px 0',
								borderTop: '1px solid #e0e0e0',
								opacity: e.accepted ? 1 : 0.55
							}
						},
							el(wp.components.CheckboxControl, {
								label: editLabel(e),
								checked: !!e.accepted,
								disabled: !!phase,
								onChange: function () { toggle(i); },
								__nextHasNoMarginBottom: true
							}),
							e.excerpt
								? el('p', { style: { margin: '2px 0 0 32px', color: '#757575', fontSize: '11px', fontStyle: 'italic' } },
									'“' + e.excerpt + '…”')
								: null,
							e.why
								? el('p', { style: { margin: '4px 0 0 32px', color: '#646970', fontSize: '12px', lineHeight: 1.45 } }, e.why)
								: null,
							/* The instruction that will ACTUALLY run, not just the
							   reason for it. They are different sentences and the
							   difference matters: "why" argues for the edit, the
							   instruction decides what the new text says. Accepting
							   on the reason alone means approving wording nobody
							   has read — and an instruction like "name all ten"
							   produces a very different paragraph from "say how
							   many and what kind". */
							e.instruction
								? el('p', {
									style: {
										margin: '5px 0 0 32px', padding: '5px 8px',
										background: '#f0f0f0', borderRadius: '2px',
										color: '#1e1e1e', fontSize: '11.5px', lineHeight: 1.45
									}
								}, 'Will ask: ' + e.instruction)
								: null
						);
					}),
					el(wp.components.Button, {
						variant: 'primary',
						isBusy: 0 === phase.indexOf('applying'),
						disabled: !!phase || !acceptedCount,
						style: { width: '100%', justifyContent: 'center', marginTop: '14px' },
						onClick: runPlan
					}, 0 === phase.indexOf('applying')
						? phase.replace('applying', 'Applying') + '…'
						: 'Apply ' + acceptedCount + (1 === acceptedCount ? ' change' : ' changes')),
					el(wp.components.Button, {
						variant: 'tertiary',
						disabled: !!phase,
						style: { width: '100%', justifyContent: 'center', marginTop: '6px' },
						onClick: function () { setPlan(null); }
					}, 'Discard this plan')
				)
				: null
		);
	}

	/* PluginSidebar moved from wp.editPost to wp.editor in WP 6.6; both are
	   still exported, so take whichever this install has. */
	var editorPkg = (wp.editor && wp.editor.PluginSidebar) ? wp.editor : wp.editPost;
	if (editorPkg && editorPkg.PluginSidebar && wp.plugins && wp.plugins.registerPlugin) {
		wp.plugins.registerPlugin('agentimus-assistant-planner', {
			render: function () {
				return el(Fragment, null,
					editorPkg.PluginSidebarMoreMenuItem
						? el(editorPkg.PluginSidebarMoreMenuItem, { target: 'agentimus-planner', icon: tileIcon(24) }, 'Ask AI about this post')
						: null,
					el(editorPkg.PluginSidebar, {
						name: 'agentimus-planner',
						title: 'Ask AI',
						icon: tileIcon(24)
					}, el('div', { style: { padding: '16px' } }, el(PlannerSidebar, null)))
				);
			}
		});
	}

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

				/* Goes through the same reviseBlocks() the multi-block modal uses —
				   one request shape, one set of rules, one place to fix. */
				var revise = function () {
					var instruction = text.trim();
					if (busy) { return; }
					if (instruction.length < 4) {
						notice('err', 'Say what to change or add — e.g. “make this shorter” or “add a conclusion after this”.');
						return;
					}
					setBusy(true);
					reviseBlocks([props.clientId], instruction).then(function (r) {
						setBusy(false);
						if (r && r.ids) { setText(''); }
					});
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
