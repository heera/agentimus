<?php
/**
 * Read a page's body, and change one passage of it.
 *
 * ⭐⭐ WHY THIS EXISTS. Until this class the agent surface could rewrite a post
 * and could not touch a sentence of one. `update-content` REPLACES the body, and
 * nothing handed back the body to begin with — `preview-markdown` returns the
 * .md twin, which is a rendering, not the source. So "add a source link to this
 * paragraph" — a two-word edit — meant reconstructing an entire published
 * article from a lossy rendering and overwriting the owner's block markup with
 * the reconstruction. Every small fix on the worklist was priced like a rewrite,
 * and an agent asked to "fix all the issues" either did nothing or did far too
 * much. That is the gap this closes, and it is the one that governs the others:
 * an agent that cannot make a small change safely cannot maintain a site.
 *
 * ⛔ THE EDIT IS ANCHORED, AND THE ANCHOR MUST BE UNIQUE. `old` has to appear in
 * the stored body exactly once. Not found is a refusal — the body is not what
 * the caller thinks it is, and a "fix" applied to a guess is how content gets
 * mangled. Found twice is also a refusal, naming the count, because replacing
 * the wrong one of two identical sentences is a change nobody can see until it
 * is far too late. This is the same contract a careful editor works to, and it
 * makes the failure mode "nothing happened, here is why" instead of "something
 * happened, somewhere".
 *
 * ⛔ AND IT CANNOT LEAVE THE DOCUMENT BROKEN. Three mechanical guards, no
 * judgement in any of them: an edit may not leave a block comment half-open
 * ({@see imbalance()}), may not empty the page, and may not run while somebody
 * has the post open in the editor — that last one is a bug this project has been
 * bitten by three times, where an editor left open autosaves over an API write
 * minutes later. WordPress already knows who holds a post ({@see
 * wp_check_post_lock()}); nothing was asking it.
 *
 * ⭐ dry_run answers the same question without writing, so an agent can look
 * before it leaps and an owner can be shown the change first.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Content;
use Agentimus\PageBuilders;

defined( 'ABSPATH' ) || exit;

final class ContentEditor {

	/** Characters of surrounding text shown either side of a change. */
	const CONTEXT = 90;

	/**
	 * One page's body, exactly as stored.
	 *
	 * ⛔ The SOURCE, not a rendering: block comments, shortcodes and all. It is
	 * what {@see edit()} matches against, so anything else here would be an
	 * anchor that cannot be found.
	 *
	 * @param int $post_id Post ID.
	 * @return array|\WP_Error
	 */
	public function read( $post_id ) {
		$post = self::post_for( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$content = (string) $post->post_content;
		$owner   = PageBuilders::owner( $post );
		$locked  = self::locked_by( (int) $post->ID );

		$notes = array();
		if ( null !== $owner ) {
			$notes[] = sprintf(
				/* translators: %s: page builder name. */
				__( 'This page is built with %s. What is below is the stored body, and it is NOT what visitors see — the layout lives in the builder. edit-content refuses it for that reason.', 'agentimus' ),
				$owner['name']
			);
		}
		if ( '' !== $locked ) {
			$notes[] = sprintf(
				/* translators: %s: display name of the user editing. */
				__( '%s has this post open in the editor right now. An edit made here would be overwritten by their next autosave, so edit-content refuses until they are done.', 'agentimus' ),
				$locked
			);
		}

		return array(
			'postId'   => (int) $post->ID,
			'title'    => (string) $post->post_title,
			'type'     => (string) $post->post_type,
			'status'   => (string) $post->post_status,
			'url'      => (string) get_permalink( $post ),
			'modified' => (string) $post->post_modified_gmt,
			// "blocks" or "classic" — an agent editing block markup needs to know
			// which it is holding, because a paragraph in one is a bare <p> and in
			// the other is wrapped in comments it must not break.
			'format'   => has_blocks( $content ) ? 'blocks' : 'classic',
			'builder'  => null === $owner ? '' : (string) $owner['name'],
			'lockedBy' => $locked,
			'editable' => null === $owner && '' === $locked,
			'content'  => $content,
			'length'   => (int) mb_strlen( $content ),
			'note'     => implode( ' ', $notes ),
		);
	}

	/**
	 * Replace one passage of one page's body.
	 *
	 * @param array $input post_id, old, new, dry_run.
	 * @return array|\WP_Error
	 */
	public function edit( array $input ) {
		$post = self::post_for( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$post_id = (int) $post->ID;
		$dry     = ! empty( $input['dry_run'] );

		$owner = PageBuilders::owner( $post );
		if ( null !== $owner ) {
			return new \WP_Error(
				'agentimus_builder_page',
				sprintf(
					/* translators: %s: page builder name (e.g. Elementor). */
					__( 'This page is built with %1$s — its visible content is not in the body this tool edits, so a change here would either show nowhere or destroy the design. Edit it in %1$s. update-content still writes every other field on this page.', 'agentimus' ),
					$owner['name']
				),
				array( 'status' => 409 )
			);
		}

		$old = isset( $input['old'] ) ? (string) $input['old'] : '';
		$new = isset( $input['new'] ) ? (string) $input['new'] : '';
		if ( '' === $old ) {
			return new \WP_Error(
				'agentimus_no_anchor',
				__( '`old` is required: the exact text to replace, copied from what read-content returned. This tool never edits by position or by guess.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$content = (string) $post->post_content;
		$matches = substr_count( $content, $old );

		if ( 0 === $matches ) {
			return new \WP_Error(
				'agentimus_no_match',
				__( 'That text is not in this page’s body, so nothing was changed. Read the page with read-content and copy the passage from what it returns — the stored source is not the rendered page, and it is not the .md twin: it can hold block comments, shortcodes and HTML entities where the reading shows plain words.', 'agentimus' ),
				array( 'status' => 409 )
			);
		}
		if ( $matches > 1 ) {
			return new \WP_Error(
				'agentimus_many_matches',
				sprintf(
					/* translators: %d: how many times the text appears. */
					__( 'That text appears %d times in this page, so which one to change is a guess — and nothing was changed. Include more of the surrounding text until the passage is unique.', 'agentimus' ),
					$matches
				),
				array( 'status' => 409 )
			);
		}

		if ( $old === $new ) {
			return $this->result( $post_id, $post->post_title, false, $dry, $content, 0, __( '`old` and `new` are the same text — there was nothing to change.', 'agentimus' ) );
		}

		$at    = (int) strpos( $content, $old );
		$after = substr_replace( $content, $new, $at, strlen( $old ) );

		if ( '' === trim( $after ) ) {
			return new \WP_Error(
				'agentimus_would_empty',
				__( 'That edit would leave the page with no body at all. Nothing was changed. If the page really should be emptied, that is a decision for the owner in the editor, not a fix.', 'agentimus' ),
				array( 'status' => 409 )
			);
		}

		// ⛔ Block comments come in pairs, and a replacement that eats one half of
		// a pair leaves markup the editor cannot open — the failure this guard
		// exists for. Compared as a BALANCE rather than a count, so an edit is
		// free to add or remove whole blocks; it may only not leave one hanging.
		if ( self::imbalance( $after ) !== self::imbalance( $content ) ) {
			return new \WP_Error(
				'agentimus_broken_blocks',
				__( 'That edit would leave a block comment half-open, which is markup the editor cannot read back. Nothing was changed. Anchor on text INSIDE a block, or include both the opening `<!-- wp:… -->` and its closing `<!-- /wp:… -->` in `old` and in `new`.', 'agentimus' ),
				array( 'status' => 409 )
			);
		}

		// ⚠️ THE AUTOSAVE TRAP, closed at last. An editor left open sends an
		// autosave carrying the body as it was when the tab loaded, which lands
		// minutes after an API write and silently undoes it. It has cost this
		// project three separate incidents. WordPress already tracks the lock.
		$locked = self::locked_by( $post_id );
		if ( '' !== $locked ) {
			return new \WP_Error(
				'agentimus_post_locked',
				sprintf(
					/* translators: %s: display name of the user editing. */
					__( '%s has this post open in the editor right now, and their next autosave would overwrite anything written here. Nothing was changed. Try again once the editor is closed.', 'agentimus' ),
					$locked
				),
				array( 'status' => 409 )
			);
		}

		if ( $dry ) {
			return $this->result(
				$post_id,
				$post->post_title,
				false,
				true,
				$after,
				$at + strlen( $new ),
				__( 'Nothing was written — this is what the edit would do. Send the same call without dry_run to apply it.', 'agentimus' )
			);
		}

		// wp_slash: core's write path unslashes what it is given, so raw input
		// would lose one level of literal backslashes — the bug that once ate a
		// character out of a published post ({@see ContentWriter}).
		$saved = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $after,
				)
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// ⭐ READ IT BACK, and mean it. If the site filtered part of what we sent
		// (a user without unfiltered_html cannot store every kind of markup), the
		// page now holds something other than this edit — which must be reported
		// as what it is, with the way back, not as a success.
		$stored = (string) get_post_field( 'post_content', $post_id );
		if ( $stored !== $after ) {
			return new \WP_Error(
				'agentimus_edit_filtered',
				sprintf(
					/* translators: %d: revision id holding the body from before the edit. */
					__( 'The body was saved but is not what this edit produced — the site filtered part of it, which happens when the connected user may not store some kinds of markup. The page as it was before is kept in revision %d. Read the page again before doing anything else.', 'agentimus' ),
					self::latest_revision( $post_id )
				),
				array( 'status' => 500 )
			);
		}

		return $this->result(
			$post_id,
			$post->post_title,
			true,
			false,
			$stored,
			$at + strlen( $new ),
			__( 'Changed. Everything else on the page is exactly as it was, and the previous body is kept as a revision.', 'agentimus' )
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * The post both calls act on, with the two checks they share.
	 *
	 * @param mixed $post_id Post ID.
	 * @return \WP_Post|\WP_Error
	 */
	private static function post_for( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return new \WP_Error(
				'agentimus_bad_post',
				__( 'A post id is required. Every row read-content-issues returns carries one, as `id`.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'agentimus_not_found', __( 'No post with that id.', 'agentimus' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( (string) $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error( 'agentimus_bad_type', __( 'That post’s type is not agent-visible on this site.', 'agentimus' ), array( 'status' => 400 ) );
		}
		return $post;
	}

	/**
	 * Who has this post open in the editor, by name — '' when nobody does.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function locked_by( $post_id ) {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$user_id = wp_check_post_lock( $post_id );
		if ( ! $user_id ) {
			return '';
		}
		$user = get_userdata( (int) $user_id );
		// A name, never an id: this sentence is read by the owner.
		return $user ? (string) $user->display_name : __( 'Somebody', 'agentimus' );
	}

	/**
	 * How many block comments are left hanging: opens that are not self-closing,
	 * minus closes. Zero in a well-formed document, and the number an edit is
	 * required to leave exactly as it found it.
	 *
	 * @param string $html Post content.
	 * @return int
	 */
	private static function imbalance( $html ) {
		$opens  = preg_match_all( '/<!--\s+wp:/', $html );
		$selfie = preg_match_all( '/<!--\s+wp:[^>]*?\/-->/', $html );
		$closes = preg_match_all( '#<!--\s+/wp:#', $html );
		return (int) $opens - (int) $selfie - (int) $closes;
	}

	/** The newest revision id, which after a save holds the body from before it. */
	private static function latest_revision( $post_id ) {
		$revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
		$first     = is_array( $revisions ) ? reset( $revisions ) : null;
		return $first instanceof \WP_Post ? (int) $first->ID : 0;
	}

	/**
	 * The one return literal, so a dry run, a no-op and a real edit all answer in
	 * the same shape. EditContentAbility tests read the keys out of HERE to check
	 * the declared output schema.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title   Post title.
	 * @param bool   $changed Whether anything was written.
	 * @param bool   $dry     Whether this was a dry run.
	 * @param string $body    The body as it is now (or as it would be).
	 * @param int    $end     Offset just past the changed text, for the excerpt.
	 * @param string $message Plain-language account of what happened.
	 * @return array
	 */
	private function result( $post_id, $title, $changed, $dry, $body, $end, $message ) {
		return array(
			'postId'     => (int) $post_id,
			'title'      => (string) $title,
			'changed'    => (bool) $changed,
			'dryRun'     => (bool) $dry,
			'context'    => $end > 0 ? self::excerpt( $body, $end ) : '',
			'revisionId' => $changed ? self::latest_revision( $post_id ) : 0,
			'length'     => (int) mb_strlen( $body ),
			'message'    => (string) $message,
		);
	}

	/**
	 * The changed passage with its surroundings, so the answer SHOWS what landed
	 * rather than asserting it — the difference between a tool an owner can check
	 * over an agent's shoulder and one they have to trust.
	 *
	 * @param string $body Body the change is in.
	 * @param int    $end  Offset just past the changed text.
	 * @return string
	 */
	private static function excerpt( $body, $end ) {
		$from = max( 0, $end - self::CONTEXT * 2 );
		$len  = min( strlen( $body ) - $from, self::CONTEXT * 3 );
		$cut  = substr( $body, $from, $len );
		return ( $from > 0 ? '…' : '' ) . $cut . ( $from + $len < strlen( $body ) ? '…' : '' );
	}
}
