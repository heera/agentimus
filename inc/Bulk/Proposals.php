<?php
/**
 * Fill the gaps — the proposal store. A bulk draft is never live content: it is
 * parked in its own postmeta until the owner approves it on the review list (or
 * rejects it, which just deletes it). Approving writes the REAL field through
 * the same sanitisers the editor's own save path uses, then removes the
 * proposal — so at any moment a value is either proposed or applied, never both.
 *
 * The apply path re-checks the gap first (the gap-only law): if an author
 * filled the field by hand while the proposal sat in review, their value wins
 * and the proposal is discarded as "already filled" — a bulk tool must never
 * overwrite explicit intent.
 *
 * @package Agentimus
 */

namespace Agentimus\Bulk;

use Agentimus\Description;
use Agentimus\Topics;

defined( 'ABSPATH' ) || exit;

final class Proposals {

	/** Proposal postmeta, one key per fillable field (underscore = never in Custom Fields UI). */
	const META = array(
		'description' => '_agentimus_proposed_description',
		'topics'      => '_agentimus_proposed_topics',
		'alt'         => '_agentimus_proposed_alt',
	);

	/**
	 * The proposal meta key for a field.
	 *
	 * @param string $field Field id.
	 * @return string
	 */
	public static function meta_key( $field ) {
		return self::META[ $field ];
	}

	/**
	 * Park a generated value as a proposal on its post/attachment.
	 *
	 * @param int          $post_id Post or attachment ID.
	 * @param string       $field   Field id.
	 * @param string|array $value   The cleaned draft.
	 */
	public static function save( $post_id, $field, $value ) {
		update_post_meta(
			(int) $post_id,
			self::meta_key( $field ),
			array(
				'value' => $value,
				'at'    => time(),
			)
		);
	}

	/**
	 * A stored proposal's value, or null when none is parked.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field id.
	 * @return string|array|null
	 */
	public static function get( $post_id, $field ) {
		$row = get_post_meta( (int) $post_id, self::meta_key( $field ), true );
		if ( ! is_array( $row ) || ! array_key_exists( 'value', $row ) ) {
			return null;
		}
		return $row['value'];
	}

	/**
	 * Drop a proposal (the review list's "Dismiss", and the tail of every apply).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field id.
	 */
	public static function reject( $post_id, $field ) {
		delete_post_meta( (int) $post_id, self::meta_key( $field ) );
	}

	/**
	 * The field's CURRENT live value — what the review row shows beside the
	 * proposal, and what the gap-guard checks before applying.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field id.
	 * @return string|array
	 */
	public static function current( $post_id, $field ) {
		$post_id = (int) $post_id;
		if ( 'description' === $field ) {
			return (string) get_post_meta( $post_id, Description::META, true );
		}
		if ( 'topics' === $field ) {
			$list = get_post_meta( $post_id, Topics::META_TOPICS, true );
			return is_array( $list ) ? array_values( $list ) : array();
		}
		return (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true );
	}

	/**
	 * Whether the live field already holds an explicit value (→ the proposal must
	 * not be applied).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field id.
	 * @return bool
	 */
	public static function filled( $post_id, $field ) {
		$current = self::current( $post_id, $field );
		return is_array( $current ) ? ! empty( $current ) : '' !== trim( (string) $current );
	}

	/**
	 * Apply one proposal: gap-guard, sanitise exactly like the editor save path,
	 * write the real field, drop the proposal.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field id.
	 * @return string 'applied' | 'skipped_filled' (author beat us to it) | 'skipped_empty' (no proposal parked).
	 */
	public static function apply( $post_id, $field ) {
		$post_id = (int) $post_id;
		$value   = self::get( $post_id, $field );
		if ( null === $value ) {
			return 'skipped_empty';
		}
		if ( self::filled( $post_id, $field ) ) {
			// The author filled it while the proposal waited — their value wins, and
			// the stale proposal goes so it can't be applied by a later "Use all".
			self::reject( $post_id, $field );
			return 'skipped_filled';
		}

		if ( 'description' === $field ) {
			update_post_meta( $post_id, Description::META, Description::clean( (string) $value ) );
		} elseif ( 'topics' === $field ) {
			update_post_meta( $post_id, Topics::META_TOPICS, Topics::sanitize_manual( $value ) );
		} else {
			update_post_meta( $post_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $value ) );
		}

		self::reject( $post_id, $field );
		return 'applied';
	}

	/**
	 * One review row: everything the screen needs to show a proposal honestly —
	 * where it lands, what's there now, what's proposed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field id.
	 * @return array|null Null when the proposal vanished (raced away).
	 */
	public static function row( $post_id, $field ) {
		$post_id = (int) $post_id;
		$value   = self::get( $post_id, $field );
		if ( null === $value ) {
			return null;
		}

		$row = array(
			'id'       => $post_id,
			'title'    => (string) get_the_title( $post_id ),
			'editLink' => (string) get_edit_post_link( $post_id, 'raw' ),
			'current'  => self::current( $post_id, $field ),
			'proposed' => $value,
		);
		if ( 'alt' === $field ) {
			$thumb = wp_get_attachment_image_url( $post_id, 'thumbnail' );
			$row['thumb'] = $thumb ? (string) $thumb : '';
		}
		return $row;
	}
}
