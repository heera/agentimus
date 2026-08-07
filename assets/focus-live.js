/**
 * The Search & AI box's live coverage check.
 *
 * Pick a different search, or type one, and the verdict re-measures without a
 * save and without a reload. It measures what is IN THE EDITOR — including text
 * you have not saved yet — so the loop is write, look, adjust, rather than
 * write, save, reload, look.
 *
 * ⚠️ READ ONLY, deliberately. The rest of this box is plain radios and one text
 * input with no JavaScript at all, because a meta box that SAVES through REST
 * gets clobbered by the form POST Gutenberg fires straight afterwards. That rule
 * is about writes. This never writes: it posts the focus and the current text to
 * a route that measures and returns markup. The saved value still travels with
 * the form exactly as before, so with JS broken or blocked the box behaves as it
 * always did.
 *
 * The server renders the replacement HTML using the same function that rendered
 * the original, so a live verdict can never drift from a saved one in wording,
 * marks or order.
 */
( function () {
	'use strict';

	var DEBOUNCE = 350;
	var box, live, timer, seq = 0;

	function query() {
		var picked = box.querySelector( 'input[name="agentimus_focus_pick"]:checked' );
		var custom = box.querySelector( 'input[name="agentimus_focus_custom"]' );
		if ( picked && '__custom__' !== picked.value ) {
			return picked.value;
		}
		return custom ? custom.value.trim() : '';
	}

	/**
	 * The post as it stands in the editor. Gutenberg first; the classic editor
	 * and the code editor both fall back to the textarea. Returning null means
	 * "no opinion" — the server then measures the saved post, which is the old
	 * behaviour and still correct.
	 */
	function editorState() {
		try {
			if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
				var sel = wp.data.select( 'core/editor' );
				if ( sel.getEditedPostContent ) {
					return {
						content: sel.getEditedPostContent(),
						title: sel.getEditedPostAttribute ? sel.getEditedPostAttribute( 'title' ) : '',
					};
				}
			}
		} catch ( e ) {
			// A Gutenberg version that moved the store is not a reason to break
			// the box; fall through to the textarea.
		}
		var ta = document.querySelector( '#content' );
		var ti = document.querySelector( '#title' );
		if ( ta ) {
			return { content: ta.value, title: ti ? ti.value : '' };
		}
		return null;
	}

	function check() {
		var q = query();
		if ( ! q ) {
			return; // Nothing chosen yet — leave whatever is on screen.
		}
		var state = editorState();
		var mine = ++seq;

		live.setAttribute( 'aria-busy', 'true' );

		wp.apiFetch( {
			path: '/agentimus/v1/focus/check',
			method: 'POST',
			data: {
				post: parseInt( live.getAttribute( 'data-post' ), 10 ),
				query: q,
				content: state ? state.content : undefined,
				title: state ? state.title : undefined,
			},
		} ).then( function ( res ) {
			// Answers can land out of order when someone types quickly; only the
			// newest one is allowed to paint, or the panel settles on a verdict
			// for a search that has already been replaced.
			if ( mine !== seq ) {
				return;
			}
			live.innerHTML = res && res.html ? res.html : '';
			live.removeAttribute( 'aria-busy' );
		} ).catch( function () {
			// A failed check leaves the last good verdict up rather than blanking
			// it: stale-but-true beats empty, and the next keystroke retries.
			if ( mine === seq ) {
				live.removeAttribute( 'aria-busy' );
			}
		} );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( check, DEBOUNCE );
	}

	function start() {
		box = document.querySelector( '#agentimus-topics' );
		live = box ? box.querySelector( '.agentimus-focus__live' ) : null;
		if ( ! box || ! live || ! window.wp || ! wp.apiFetch ) {
			return; // No box, or no REST — the form still works untouched.
		}

		// A radio is a decision: check at once. Typing is not: wait for a pause.
		box.addEventListener( 'change', function ( e ) {
			if ( e.target && 'agentimus_focus_pick' === e.target.name ) {
				check();
			}
		} );
		box.addEventListener( 'input', function ( e ) {
			if ( e.target && 'agentimus_focus_custom' === e.target.name ) {
				// Typing in the free field is choosing it.
				var custom = box.querySelector( 'input[name="agentimus_focus_pick"][value="__custom__"]' );
				if ( custom && ! custom.checked ) {
					custom.checked = true;
				}
				schedule();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
