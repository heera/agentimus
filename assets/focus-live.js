/**
 * The Search & AI box's focus editor and live coverage check.
 *
 * Two things, both progressive enhancements over a form that works without
 * them:
 *
 *  1. The free-text focus becomes CHIPS. Type a word, press Enter or comma, and
 *     it becomes a removable chip while the box empties for the next one. The
 *     check measures word by word, so a word is the unit the author should be
 *     handling — a sentence in a text field hid that.
 *  2. The verdict re-measures on every change, against what is IN THE EDITOR,
 *     unsaved text included. Write, look, adjust — not write, save, reload.
 *
 * ⚠️ READ ONLY, deliberately. The rest of this box is plain radios and one text
 * input with no JavaScript, because a meta box that SAVES through REST gets
 * clobbered by the form POST Gutenberg fires straight afterwards. That rule is
 * about writes. Nothing here writes: the route measures text the caller already
 * has and returns markup, and the value still leaves through the form field as
 * it always did.
 *
 * ⚠️ The no-JS path must survive. The original text input keeps carrying the
 * value — this script hands its NAME to a hidden twin holding the joined words
 * and repurposes the visible one as the "add a word" box. With JS blocked or
 * broken, the untouched input submits exactly as before.
 */
( function () {
	'use strict';

	var box, live, hidden, entry, chipWrap;
	var words = [];
	var seq = 0;

	/* -- the value ---------------------------------------------------------- */

	function joined() {
		return words.join( ' ' );
	}

	function sync() {
		if ( hidden ) {
			hidden.value = joined();
		}
		// Typing in this field IS choosing it, so the radio follows along.
		var pick = box.querySelector( 'input[name="agentimus_focus_pick"][value="__custom__"]' );
		if ( pick && words.length && ! pick.checked ) {
			pick.checked = true;
		}
	}

	function add( raw ) {
		// A pasted phrase becomes several chips, because the check would have
		// split it into several words anyway.
		String( raw )
			.split( /[\s,]+/ )
			.map( function ( w ) {
				return w.trim().toLowerCase();
			} )
			.filter( Boolean )
			.forEach( function ( w ) {
				if ( -1 === words.indexOf( w ) ) {
					words.push( w );
				}
			} );
	}

	function remove( word ) {
		var at = words.indexOf( word );
		if ( at > -1 ) {
			words.splice( at, 1 );
		}
	}

	/* -- the chips ---------------------------------------------------------- */

	function paintChips() {
		chipWrap.innerHTML = '';
		words.forEach( function ( w ) {
			var chip = document.createElement( 'span' );
			chip.className = 'agentimus-focus__chip';

			var text = document.createElement( 'span' );
			text.textContent = w;
			chip.appendChild( text );

			var x = document.createElement( 'button' );
			x.type = 'button';
			x.className = 'agentimus-focus__chipx';
			x.setAttribute( 'aria-label', 'Remove ' + w );
			x.textContent = '×';
			x.addEventListener( 'click', function () {
				remove( w );
				commit();
				entry.focus();
			} );
			chip.appendChild( x );

			chipWrap.appendChild( chip );
		} );
		chipWrap.hidden = 0 === words.length;
	}

	function commit() {
		paintChips();
		sync();
		check();
	}

	/* -- the check ---------------------------------------------------------- */

	/**
	 * The post as it stands in the editor. Gutenberg first; the classic and code
	 * editors fall back to the textarea. Null means "no opinion" — the server
	 * then measures the saved post, which is the old behaviour and still right.
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
			// A Gutenberg that moved its store is not a reason to break the box.
		}
		var ta = document.querySelector( '#content' );
		var ti = document.querySelector( '#title' );
		if ( ta ) {
			return { content: ta.value, title: ti ? ti.value : '' };
		}
		return null;
	}

	function currentQuery() {
		var picked = box.querySelector( 'input[name="agentimus_focus_pick"]:checked' );
		if ( picked && '__custom__' !== picked.value ) {
			return picked.value;
		}
		return joined();
	}

	function check() {
		var state = editorState();
		var mine = ++seq;

		live.setAttribute( 'aria-busy', 'true' );

		wp.apiFetch( {
			path: '/agentimus/v1/focus/check',
			method: 'POST',
			data: {
				post: parseInt( live.getAttribute( 'data-post' ), 10 ),
				// An EMPTY query is sent deliberately: the server answers with the
				// "add some words" state, which is how removing the last chip
				// clears a verdict instead of leaving it standing.
				query: currentQuery(),
				content: state ? state.content : undefined,
				title: state ? state.title : undefined,
			},
		} ).then( function ( res ) {
			// Answers can land out of order when someone works quickly; only the
			// newest paints, or the panel settles on a verdict for words that
			// have already been removed.
			if ( mine !== seq ) {
				return;
			}
			live.innerHTML = res && res.html ? res.html : '';
			live.removeAttribute( 'aria-busy' );
		} ).catch( function () {
			// A failed check leaves the last good verdict up rather than blanking
			// it: stale-but-true beats empty, and the next change retries.
			if ( mine === seq ) {
				live.removeAttribute( 'aria-busy' );
			}
		} );
	}

	/* -- wiring ------------------------------------------------------------- */

	function start() {
		box = document.querySelector( '#agentimus-topics' );
		live = box ? box.querySelector( '.agentimus-focus__live' ) : null;
		entry = box ? box.querySelector( 'input[name="agentimus_focus_custom"]' ) : null;
		if ( ! box || ! live || ! entry || ! window.wp || ! wp.apiFetch ) {
			return; // The form still works untouched.
		}

		// Hand the field's NAME to a hidden twin, so the visible one is free to
		// be an entry box and the value still submits with the form.
		hidden = document.createElement( 'input' );
		hidden.type = 'hidden';
		hidden.name = 'agentimus_focus_custom';
		entry.parentNode.insertBefore( hidden, entry );
		entry.removeAttribute( 'name' );

		add( entry.value );
		entry.value = '';
		entry.placeholder = 'Add a word, press Enter';

		chipWrap = document.createElement( 'div' );
		chipWrap.className = 'agentimus-focus__chips';
		entry.parentNode.insertBefore( chipWrap, entry );

		paintChips();
		sync();

		entry.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ',' === e.key ) {
				e.preventDefault(); // Enter in a meta box would submit the post.
				if ( entry.value.trim() ) {
					add( entry.value );
					entry.value = '';
					commit();
				}
				return;
			}
			// Backspace on an empty box takes back the last word — the standard
			// behaviour of every chip field, and the fastest way to undo a typo.
			if ( 'Backspace' === e.key && '' === entry.value && words.length ) {
				words.pop();
				commit();
			}
		} );

		// Whatever is half-typed when focus leaves still counts: losing a word
		// because you clicked away is a small betrayal.
		entry.addEventListener( 'blur', function () {
			if ( entry.value.trim() ) {
				add( entry.value );
				entry.value = '';
				commit();
			}
		} );

		box.addEventListener( 'change', function ( e ) {
			if ( e.target && 'agentimus_focus_pick' === e.target.name ) {
				check();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
