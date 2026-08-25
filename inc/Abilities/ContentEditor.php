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
use Agentimus\BodyImages;
use Agentimus\PageCheck;

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
			// ⭐ THE IMAGES, NAMED. Without this an agent holding an `alt_text`
			// row knows a page has undescribed pictures and has to find them by
			// eye in raw block markup, then retype a whole <img> tag to add one
			// attribute. Each row here is aimable at describe-content-image, and
			// `needs` says which ones the checks are actually asking about.
			'images'   => self::images_in( $content ),
			'note'     => implode( ' ', $notes ),
		);
	}

	/**
	 * The images in a body, as an agent needs to see them.
	 *
	 * ⚠️ SCANNED FROM THE STORED BODY, while the alt_text check reads the
	 * RENDERED one. The two can differ, and the difference is the honest
	 * answer: a picture drawn by a shortcode, a gallery block or a theme
	 * template is not in this list because it is not in this body — and it
	 * cannot be fixed by editing this body either. describe-content-image
	 * refuses those by name rather than writing somewhere that has no effect.
	 *
	 * @param string $content Post content, as stored.
	 * @return array<int,array<string,mixed>>
	 */
	public static function images_in( $content ) {
		$out = array();
		foreach ( BodyImages::scan( $content ) as $image ) {
			$out[] = array(
				'index'        => (int) $image['index'],
				'src'          => (string) $image['src'],
				'alt'          => (string) $image['alt'],
				'attachmentId' => (int) $image['attachmentId'],
				// alt="" — the WAI marker for a picture that carries no meaning.
				'decorative'   => (bool) ( $image['hasAlt'] && '' === $image['alt'] ),
				// …and whether its author wrote a sentence underneath it, which is
				// the evidence that says the marker was a blank field.
				'captioned'    => (bool) $image['hasCaption'],
				'needs'        => self::image_needs( $image ),
			);
		}
		return $out;
	}

	/**
	 * What the checks want from one image: '' when nothing.
	 *
	 * ⭐ The classification lives with {@see PageCheck::counts_as_described()},
	 * not here — same law as the featured-image writer. What this adds is the
	 * one rule that only applies INSIDE a body: a missing attribute and an
	 * empty one mean different things.
	 *
	 * @param array $image A row from {@see BodyImages::scan()}.
	 * @return string 'no-alt', 'file-name' or ''.
	 */
	private static function image_needs( array $image ) {
		if ( empty( $image['hasAlt'] ) ) {
			return 'no-alt';
		}
		if ( '' === (string) $image['alt'] ) {
			// ⛔ Decorative AND captioned is a contradiction the author wrote
			// themselves — {@see PageCheck::has_caption()} carries the reasoning,
			// and this must agree with it or the tool refuses the row the check
			// raises. Uncaptioned stays a decision, and stays unflagged.
			return empty( $image['hasCaption'] ) ? '' : 'blank-alt';
		}
		return PageCheck::counts_as_described( (string) $image['alt'], (string) $image['src'] ) ? '' : 'file-name';
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

		return $this->apply(
			$post,
			$content,
			$after,
			$at + strlen( $new ),
			$dry,
			__( 'Changed. Everything else on the page is exactly as it was, and the previous body is kept as a revision.', 'agentimus' )
		);
	}

	/**
	 * The write itself, and every guard that stands between a new body and the
	 * database. Shared, so that a second tool writing a body cannot end up with
	 * a weaker set of them than {@see edit()} has.
	 *
	 * ⭐ THE REASON IT IS ONE METHOD: these guards were written for edit-content
	 * and are not about editing passages at all — they are about not leaving a
	 * document broken, and not losing to an autosave. Anything that rewrites a
	 * stored body needs all of them, and the way to guarantee that is to give
	 * it no way to write without them.
	 *
	 * @param \WP_Post $post    The post being written.
	 * @param string   $content The body as it is now.
	 * @param string   $after   The body as it should be.
	 * @param int      $cursor  Offset just past the change, for the excerpt.
	 * @param bool     $dry     True to check everything and write nothing.
	 * @param string   $message What to say when it lands.
	 * @return array|\WP_Error
	 */
	private function apply( \WP_Post $post, $content, $after, $cursor, $dry, $message ) {
		$post_id = (int) $post->ID;

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
				$cursor,
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
			$cursor,
			$message
		);
	}

	/**
	 * Describe ONE image inside a page's body.
	 *
	 * ⭐⭐ WHY IT IS NOT describe-image. The alt a reader gets for an in-content
	 * picture is the one in the BODY: WordPress copies the library's alt into
	 * the block when the image is inserted and never consults it again.
	 * Describing the attachment afterwards leaves every published page exactly
	 * as undescribed as it was — so the `alt_text` rows on the worklist could be
	 * found, and could not be closed, by the tool that closes `featured_alt`.
	 * This writes where the check reads.
	 *
	 * ⛔ AND IT IS A BODY WRITE, so it carries every guard edit-content carries
	 * ({@see apply()}): a builder-owned page is refused, a post somebody has
	 * open is refused, the previous body is kept as a revision and the save is
	 * read back. The one thing it does NOT do is match text — the tag is found
	 * and rewritten by offset, so there is no anchor to get wrong and no way to
	 * disturb a neighbouring byte.
	 *
	 * ⛔ IT WILL NOT OVERWRITE A DECISION. A description somebody wrote is
	 * refused without replace=true, exactly as in the library. So is alt="" —
	 * that is the standards marker for a decorative image, the checks
	 * deliberately do not flag it, and a fixing run announcing every spacer on
	 * a site would be damage, not a fix.
	 *
	 * @param array $input post_id, alt, one of attachment_id|src|index, replace, dry_run.
	 * @return array|\WP_Error
	 */
	public function describe_in_content( array $input ) {
		$post = self::post_for( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$owner = PageBuilders::owner( $post );
		if ( null !== $owner ) {
			return new \WP_Error(
				'agentimus_builder_page',
				sprintf(
					/* translators: %s: page builder name (e.g. Elementor). */
					__( 'This page is built with %1$s, so its images are not in the body this tool writes to — a description added here would reach nobody. Describe them in %1$s.', 'agentimus' ),
					$owner['name']
				),
				array( 'status' => 409 )
			);
		}

		$alt = isset( $input['alt'] ) ? sanitize_text_field( (string) $input['alt'] ) : '';
		$alt = trim( (string) preg_replace( '/\s+/u', ' ', $alt ) );
		if ( '' === $alt ) {
			return new \WP_Error(
				'agentimus_empty_alt',
				__( 'A description is required. Say what the picture SHOWS, in one plain sentence, as you would to somebody who cannot see it — not the file name, and not “image of”. ⛔ To mark an image decorative instead, that is alt="" and it is a decision for the owner in the editor, not something a fixing run does.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		if ( mb_strlen( $alt ) > MediaWriter::MAX_ALT ) {
			return new \WP_Error(
				'agentimus_alt_too_long',
				sprintf(
					/* translators: 1: the submitted length, 2: the maximum. */
					__( 'That description is %1$d characters; the limit is %2$d. Alt text is read aloud in one breath — say what the picture shows, not everything the page says about it. Nothing was written.', 'agentimus' ),
					mb_strlen( $alt ),
					MediaWriter::MAX_ALT
				),
				array( 'status' => 400 )
			);
		}

		$content = (string) $post->post_content;
		$images  = BodyImages::scan( $content );
		if ( ! $images ) {
			return new \WP_Error(
				'agentimus_no_images',
				__( 'There are no <img> tags in this page’s stored body. If a check reports images on it, they are drawn by a shortcode, a gallery or the theme rather than written into the body — and no edit to the body can describe them. read-content lists exactly what is here.', 'agentimus' ),
				array( 'status' => 409 )
			);
		}

		$found = self::pick_image( $images, $input );
		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$replace = ! empty( $input['replace'] );
		if ( ! empty( $found['hasAlt'] ) && ! $replace ) {
			// ⭐ A CAPTIONED blank is not a decision — the checks flag it, so this
			// writes it without asking, exactly as it does a file-name alt. Only
			// an UNCAPTIONED blank is somebody saying "this picture means
			// nothing", and only that is refused.
			if ( '' === (string) $found['alt'] && empty( $found['hasCaption'] ) ) {
				return new \WP_Error(
					'agentimus_decorative_image',
					__( 'This image is marked decorative — it carries alt="", which is the standard way of saying it holds no meaning a reader needs, and it has no caption suggesting otherwise, so the content checks do not flag it. Nothing was written. If that is wrong and the picture does say something, send replace=true.', 'agentimus' ),
					array( 'status' => 409 )
				);
			}
			if ( '' !== (string) $found['alt'] && PageCheck::counts_as_described( (string) $found['alt'], (string) $found['src'] ) ) {
				return new \WP_Error(
					'agentimus_already_described',
					sprintf(
						/* translators: %s: the description already on the image. */
						__( 'This image is already described: “%s”. Nothing was written. A description somebody wrote is not something a fixing run replaces, and no content check asks for that — if this one is genuinely wrong, read it, then send replace=true with the correction.', 'agentimus' ),
						$found['alt']
					),
					array( 'status' => 409 )
				);
			}
		}

		$tag   = BodyImages::with_alt( $found['tag'], $alt );
		$after = BodyImages::replace( $content, $found, $tag );

		$out = $this->apply(
			$post,
			$content,
			$after,
			(int) $found['offset'] + strlen( $tag ),
			! empty( $input['dry_run'] ),
			'' === (string) $found['alt']
				? __( 'Described. The picture reaches readers and screen readers with a description now; everything else on the page is exactly as it was.', 'agentimus' )
				: __( 'Description replaced. Everything else on the page is exactly as it was.', 'agentimus' )
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		// ⛔ THE LIBRARY COPY IS A SEPARATE FACT, AND THIS TOOL DOES NOT WRITE IT.
		// Filling it quietly would change what `featured_alt` reports on every
		// other page using this picture, without marking any of them for
		// re-reading — the exact staleness MediaWriter exists to avoid. So it is
		// REPORTED instead: the agent is told the library still needs a
		// description and can call describe-image, which closes that loop
		// properly.
		$library = (int) $found['attachmentId'] > 0
			? trim( (string) get_post_meta( (int) $found['attachmentId'], '_wp_attachment_image_alt', true ) )
			: '';
		$owes = (int) $found['attachmentId'] > 0
			&& ! PageCheck::counts_as_described( $library, (string) wp_get_attachment_url( (int) $found['attachmentId'] ) );

		return array_merge(
			$out,
			array(
				'index'          => (int) $found['index'],
				'src'            => (string) $found['src'],
				'attachmentId'   => (int) $found['attachmentId'],
				'alt'            => $alt,
				'previous'       => (string) $found['alt'],
				'libraryAlt'     => $library,
				'libraryNeedsDescribing' => (bool) $owes,
			)
		);
	}

	/**
	 * Which of a page's images this call means.
	 *
	 * ⛔ IT NEVER GUESSES BETWEEN TWO. Three ways to aim — the library id, the
	 * file, or the position — and naming more than one is refused rather than
	 * ranked. The single exception is a page with exactly ONE image, where
	 * there is nothing to choose between and demanding a choice is friction for
	 * its own sake.
	 *
	 * @param array $images Rows from {@see BodyImages::scan()}.
	 * @param array $input  The ability input.
	 * @return array|\WP_Error
	 */
	private static function pick_image( array $images, array $input ) {
		$attachment = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
		$src        = isset( $input['src'] ) ? trim( (string) $input['src'] ) : '';
		$index      = isset( $input['index'] ) ? (int) $input['index'] : 0;

		$given = ( $attachment > 0 ? 1 : 0 ) + ( '' !== $src ? 1 : 0 ) + ( $index > 0 ? 1 : 0 );
		if ( $given > 1 ) {
			return new \WP_Error(
				'agentimus_two_targets',
				__( 'Name the image one way — attachment_id, src or index — not several. They can point at different pictures, and this tool never guesses which one you meant.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		if ( 0 === $given ) {
			if ( 1 === count( $images ) ) {
				return $images[0];
			}
			return new \WP_Error(
				'agentimus_which_image',
				sprintf(
					/* translators: 1: how many images, 2: a list of "index N: file". */
					__( 'This page has %1$d images, so which one to describe has to be said: pass index, src or attachment_id. They are %2$s.', 'agentimus' ),
					count( $images ),
					self::image_list( $images )
				),
				array( 'status' => 400 )
			);
		}

		if ( $index > 0 ) {
			foreach ( $images as $image ) {
				if ( (int) $image['index'] === $index ) {
					return $image;
				}
			}
			return new \WP_Error(
				'agentimus_no_such_image',
				sprintf(
					/* translators: 1: the index asked for, 2: how many images the page has. */
					__( 'There is no image %1$d on this page — it has %2$d. read-content lists them with the index each one answers to.', 'agentimus' ),
					$index,
					count( $images )
				),
				array( 'status' => 404 )
			);
		}

		if ( $attachment > 0 ) {
			$hits = array();
			foreach ( $images as $image ) {
				if ( (int) $image['attachmentId'] === $attachment ) {
					$hits[] = $image;
				}
			}
			// ⭐ THE CLASSIC-EDITOR FALLBACK. `wp-image-123` is written by the
			// block editor; an image placed years ago, or by hand, carries no
			// class at all and would look like somebody else's picture. Its file
			// still names it, so the file is tried before giving up.
			if ( ! $hits ) {
				$file = (string) wp_get_attachment_url( $attachment );
				$base = '' !== $file ? basename( (string) preg_replace( '/[?#].*$/', '', $file ) ) : '';
				if ( '' !== $base ) {
					foreach ( $images as $image ) {
						if ( false !== strpos( (string) $image['src'], $base ) ) {
							$hits[] = $image;
						}
					}
				}
			}
			return self::one_of( $hits, __( 'attachment_id', 'agentimus' ), $images );
		}

		$hits = array();
		foreach ( $images as $image ) {
			if ( (string) $image['src'] === $src || false !== strpos( (string) $image['src'], $src ) ) {
				$hits[] = $image;
			}
		}
		return self::one_of( $hits, __( 'src', 'agentimus' ), $images );
	}

	/**
	 * Exactly one match, or a refusal that says what happened.
	 *
	 * @param array  $hits   What matched.
	 * @param string $by     Which parameter was used, for the message.
	 * @param array  $images Every image on the page, for the list.
	 * @return array|\WP_Error
	 */
	private static function one_of( array $hits, $by, array $images ) {
		if ( 1 === count( $hits ) ) {
			return $hits[0];
		}
		if ( ! $hits ) {
			return new \WP_Error(
				'agentimus_no_such_image',
				sprintf(
					/* translators: 1: the parameter used, 2: a list of "index N: file". */
					__( 'No image on this page matches that %1$s. Nothing was written — the picture is not where the caller thinks it is. The page holds %2$s.', 'agentimus' ),
					$by,
					self::image_list( $images )
				),
				array( 'status' => 404 )
			);
		}
		return new \WP_Error(
			'agentimus_many_images',
			sprintf(
				/* translators: 1: how many matched, 2: the parameter used, 3: a list of "index N: file". */
				__( '%1$d images on this page match that %2$s, so which one is a guess — and nothing was written. Aim it with index instead: the page holds %3$s.', 'agentimus' ),
				count( $hits ),
				$by,
				self::image_list( $images )
			),
			array( 'status' => 409 )
		);
	}

	/** "index 1: sunrise.jpg, index 2: bridge.png" — bounded, for a refusal. */
	private static function image_list( array $images ) {
		$parts = array();
		foreach ( array_slice( $images, 0, 8 ) as $image ) {
			$src     = (string) preg_replace( '/[?#].*$/', '', (string) $image['src'] );
			$parts[] = sprintf(
				/* translators: 1: the image's position on the page, 2: its file name. */
				__( 'index %1$d: %2$s', 'agentimus' ),
				(int) $image['index'],
				'' === $src ? __( '(no src)', 'agentimus' ) : basename( $src )
			);
		}
		if ( count( $images ) > 8 ) {
			$parts[] = sprintf(
				/* translators: %d: how many more images there are. */
				__( 'and %d more', 'agentimus' ),
				count( $images ) - 8
			);
		}
		return implode( ', ', $parts );
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
