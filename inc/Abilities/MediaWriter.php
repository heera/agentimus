<?php
/**
 * Describe an image — the alt-text writer.
 *
 * ⭐⭐ WHY THIS EXISTS. On a real site, 2026-08-25: 54 of the 58 content flags
 * were the same one — "Featured image not described". An agent could FIND all
 * fifty-four (read-content-issues narrows to one check) and fix none of them,
 * because nothing in this plugin wrote alt text onto an image that already
 * existed. `featured_image_alt` on the write tools only lands on the sideload
 * path, i.e. an image being imported for the first time ({@see ContentWriter}),
 * so the only way to clear the flag through a tool was to re-import the same
 * picture and leave a duplicate in the library. The most mechanical work on the
 * whole worklist — write a sentence saying what a photograph shows — was the one
 * thing the agent surface could not do.
 *
 * ⛔ IT ONLY EVER ADDS A DESCRIPTION, OR CORRECTS ONE IT IS TOLD TO. An image
 * that is already DESCRIBED is REFUSED unless the caller passes replace=true and
 * has therefore read what is there. That asymmetry is the whole safety argument
 * for letting an agent loose on "fix everything": an agent paraphrasing
 * sentences the owner wrote, at scale, quietly, would be exactly the kind of
 * damage a fixing run must not be able to do.
 *
 * ⛔⛔ AND "DESCRIBED" IS NOT "THE FIELD IS NOT EMPTY". {@see PageCheck::counts_as_described()}
 * owns that question, because the CHECKS own it: a field holding the file name
 * is flagged as undescribed, and a guard that read it as somebody's writing
 * refused the one row the worklist was pointing at (heera.it, 2026-08-25 — the
 * refusal even claimed the checks were satisfied). Ask the checks; never keep a
 * second opinion here.
 *
 * ⭐ AND IT CLOSES THE LOOP. Alt text lives on the attachment, not on the page,
 * so writing it does not save the page and nothing marks the page's stored
 * reading out of date. The worklist would go on reporting a flag that is fixed
 * until the sweep happened to come round again. Every page using this image as
 * its featured image is marked for re-reading here ({@see Grades::mark_stale()}
 * — aged, never deleted), and the ids come back in the answer so the agent can
 * say which pages will be read again rather than claiming they already were.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Grades;
use Agentimus\PageCheck;
use Agentimus\Content;

defined( 'ABSPATH' ) || exit;

final class MediaWriter {

	/**
	 * The longest description this tool accepts.
	 *
	 * Not a WordPress limit — the field has none. It is a limit on what a
	 * description IS: screen readers read alt text aloud in one breath, and a
	 * paragraph in this field is a paragraph nobody hears the end of. Long input
	 * is refused rather than truncated, because a sentence cut at 250 characters
	 * is worse than the one the caller would have written to fit.
	 */
	const MAX_ALT = 250;

	/** How many pages we mark for re-reading after describing one image. */
	const MAX_REFRESH = 50;

	/**
	 * The attachment this call is aimed at, resolved from either aiming mode.
	 *
	 * ⭐ Shared with the permission callback on purpose: the gate and the write
	 * must resolve the SAME image, or the check is being made against one
	 * picture and the write lands on another.
	 *
	 * @param array $input Ability input.
	 * @return array{attachment:int,post:int}|\WP_Error
	 */
	public static function target( array $input ) {
		$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$attachment = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;

		if ( $post_id > 0 && $attachment > 0 ) {
			return new \WP_Error(
				'agentimus_two_targets',
				__( 'Send post_id or attachment_id, not both — they can name two different pictures, and this tool never guesses which one you meant.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		if ( $post_id < 1 && $attachment < 1 ) {
			return new \WP_Error(
				'agentimus_no_target',
				__( 'Which image? Pass post_id to describe that page’s featured image — the `id` on any read-content-issues row — or attachment_id for one media library item from search-media.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				return new \WP_Error( 'agentimus_not_found', __( 'No post with that id.', 'agentimus' ), array( 'status' => 404 ) );
			}
			$attachment = (int) get_post_thumbnail_id( $post_id );
			if ( $attachment < 1 ) {
				// ⛔ NOT EVERY SITE IS A BLOG. A content type that does not support
				// featured images has no picture to aim at AND is never flagged for
				// one — check_featured_image() passes such a type outright. Telling
				// its owner's agent to go and set one would send it chasing a box
				// that does not exist on the screen.
				if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
					$pto   = get_post_type_object( $post->post_type );
					$label = $pto && ! empty( $pto->labels->singular_name ) ? $pto->labels->singular_name : $post->post_type;
					return new \WP_Error(
						'agentimus_no_featured_support',
						sprintf(
							/* translators: %s: the content type's singular name, e.g. "Product". */
							__( '“%s” does not use featured images, so there is none to describe and no content check asks for one. To describe a picture that belongs to this item, find it with search-media and aim this tool with attachment_id instead.', 'agentimus' ),
							$label
						),
						array( 'status' => 409 )
					);
				}
				// It CAN hold one and does not. Only point at update-content when
				// update-content would actually take this type — it refuses
				// anything outside the owner's agent-visible set, and advice that
				// ends in a refusal is worse than no advice.
				$reachable = in_array( $post->post_type, Content::post_types(), true );
				return new \WP_Error(
					'agentimus_no_featured_image',
					$reachable
						? __( 'This item has no featured image, so there is nothing to describe — the flag on it is “no featured image”, not “not described”. update-content sets one: pass featured_image with featured_image_alt and it arrives already described.', 'agentimus' )
						: __( 'This item has no featured image, so there is nothing to describe — the flag on it is “no featured image”, not “not described”. Set one in the editor; this content type is outside the set the write tools may touch.', 'agentimus' ),
					array( 'status' => 409 )
				);
			}
		}

		if ( ! wp_attachment_is_image( $attachment ) ) {
			return new \WP_Error(
				'agentimus_not_an_image',
				__( 'That media library item is not an image. Alt text describes a picture; other files are not read out this way.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'attachment' => (int) $attachment,
			'post'       => (int) $post_id,
		);
	}

	/**
	 * Write one image's description.
	 *
	 * @param array $input Ability input: post_id|attachment_id, alt, replace.
	 * @return array|\WP_Error {@see result()} for the shape.
	 */
	public function describe( array $input ) {
		$target = self::target( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$attachment = $target['attachment'];

		// One line of plain text: alt is read aloud, so a newline in it is a
		// pause nobody meant. Collapsed rather than refused — this is formatting,
		// not meaning, and the caller's sentence survives it unchanged.
		$alt = isset( $input['alt'] ) ? sanitize_text_field( (string) $input['alt'] ) : '';
		$alt = trim( (string) preg_replace( '/\s+/u', ' ', $alt ) );

		if ( '' === $alt ) {
			return new \WP_Error(
				'agentimus_empty_alt',
				__( 'A description is required. Say what the picture SHOWS, in one plain sentence, as you would to somebody who cannot see it — not the file name, and not “image of”. ⛔ To remove a description, use the media library: this tool only ever adds one or corrects one, so a run of automatic fixes can never blank a description the owner wrote.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		if ( mb_strlen( $alt ) > self::MAX_ALT ) {
			return new \WP_Error(
				'agentimus_alt_too_long',
				sprintf(
					/* translators: 1: the submitted length, 2: the maximum. */
					__( 'That description is %1$d characters; the limit is %2$d. Alt text is read aloud in one breath — say what the picture shows, not everything the page says about it. Nothing was written: shorten it and send it again, rather than having it cut mid-sentence.', 'agentimus' ),
					mb_strlen( $alt ),
					self::MAX_ALT
				),
				array( 'status' => 400 )
			);
		}

		$current = trim( (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );

		// ⭐ ASK THE CHECKS, don't hold a second opinion — {@see PageCheck::counts_as_described()}
		// carries the reasoning. A field with the file name in it is not a
		// description, and guarding on "not empty" made this tool refuse the exact
		// rows the checks were raising.
		$described = PageCheck::counts_as_described( $current, (string) wp_get_attachment_url( $attachment ) );

		// ⛔ THE GUARD THIS TOOL IS BUILT AROUND. See the class docblock: a
		// description that already exists is somebody's writing, and replacing it
		// has to be asked for by name.
		if ( $described && empty( $input['replace'] ) ) {
			return new \WP_Error(
				'agentimus_already_described',
				sprintf(
					/* translators: %s: the description already stored. */
					__( 'This image is already described: “%s”. Nothing was written. A description somebody wrote is not something a fixing run replaces, and no content check asks for that — if this one is genuinely wrong, read it, then send replace=true with the correction.', 'agentimus' ),
					$current
				),
				array( 'status' => 409 )
			);
		}

		if ( $current === $alt ) {
			return $this->result(
				$attachment,
				$target['post'],
				$alt,
				$current,
				false,
				array(),
				__( 'Already described in exactly these words — nothing was written.', 'agentimus' )
			);
		}

		update_post_meta( $attachment, '_wp_attachment_image_alt', $alt );

		// ⭐ READ IT BACK. His bar: a partial outcome never reports as success. A
		// filter on this meta key (some media plugins have one) can rewrite or
		// drop the value, and "described" is then a claim about something that
		// did not happen.
		$stored = trim( (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );
		if ( $stored !== $alt ) {
			return new \WP_Error(
				'agentimus_describe_failed',
				sprintf(
					/* translators: %s: what is actually stored now. */
					__( 'The description did not store as sent — the field now holds “%s”. Something on this site is rewriting alt text. Read the image again before trying anything else.', 'agentimus' ),
					$stored
				),
				array( 'status' => 500 )
			);
		}

		return $this->result(
			$attachment,
			$target['post'],
			$stored,
			$current,
			true,
			$this->refresh_pages( $attachment ),
			$this->written_message( $current, $described )
		);
	}

	/**
	 * What actually happened, in the caller's terms.
	 *
	 * ⭐ Three outcomes, not two. "Description replaced" over a field holding
	 * the file name would report a correction nobody made — the picture had no
	 * description, and what was overwritten was never one.
	 *
	 * @param string $current  What the field held before the write.
	 * @param bool   $described Whether that counted as a description.
	 * @return string
	 */
	private function written_message( $current, $described ) {
		if ( '' === $current ) {
			return __( 'Described. Image search and screen readers can read it now.', 'agentimus' );
		}
		if ( ! $described ) {
			return __( 'Described. What stood in the field was the file name, which tells a reader nothing — this is the picture’s first real description.', 'agentimus' );
		}
		return __( 'Description replaced.', 'agentimus' );
	}

	/**
	 * Mark every page using this image as its featured image for re-reading.
	 *
	 * ⛔ Marked, not re-read: the reading itself is the sweep's work and takes a
	 * page render. Saying "re-read" here would be a claim about a measurement
	 * nobody has taken yet. {@see Grades::mark_stale()} ages the stored verdict
	 * rather than deleting it, so the page keeps its place on the owner's screen
	 * with its reading marked out of date — never vanishing as though it were
	 * fine.
	 *
	 * @param int $attachment Attachment ID.
	 * @return int[] Page ids now owed a fresh reading.
	 */
	private function refresh_pages( $attachment ) {
		$ids = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'numberposts'      => self::MAX_REFRESH,
				'fields'           => 'ids',
				'meta_key'         => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- _thumbnail_id is core's own indexed lookup, and this runs once per described image.
				'meta_value'       => (string) $attachment, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
				'suppress_filters' => false,
			)
		);
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		foreach ( $ids as $id ) {
			Grades::mark_stale( $id );
		}
		return $ids;
	}

	/**
	 * The one return literal, so every path answers in the same shape — the
	 * no-op one included. DescribeImageAbility tests read the keys out of HERE
	 * to check the declared output schema.
	 *
	 * @param int    $attachment Attachment ID.
	 * @param int    $post_id    The page this call was aimed at, 0 when aimed at the library item.
	 * @param string $alt        What is stored NOW.
	 * @param string $previous   What was there before ('' when nothing).
	 * @param bool   $changed    Whether this call is what wrote it.
	 * @param int[]  $refreshed  Pages owed a fresh reading.
	 * @param string $message    Plain-language account of what happened.
	 * @return array
	 */
	private function result( $attachment, $post_id, $alt, $previous, $changed, array $refreshed, $message ) {
		return array(
			'attachmentId' => (int) $attachment,
			'postId'       => (int) $post_id,
			'image'        => (string) get_the_title( $attachment ),
			'url'          => (string) wp_get_attachment_url( $attachment ),
			'alt'          => (string) $alt,
			'previous'     => (string) $previous,
			'changed'      => (bool) $changed,
			'refreshed'    => array_values( array_map( 'intval', $refreshed ) ),
			'message'      => (string) $message,
		);
	}
}
