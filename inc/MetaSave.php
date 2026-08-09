<?php
/**
 * The shared guard for classic-editor meta-box saves.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * Every classic-editor meta box (focus keyword, SEO title, topics, description)
 * runs the same five checks before it writes: our nonce is present and valid,
 * this is not an autosave, not a revision, and the user may edit the post. Those
 * checks are a security predicate, so a single home keeps them from drifting
 * apart across the save handlers.
 */
final class MetaSave {

	/**
	 * Whether a `save_post` handler may proceed to write its meta.
	 *
	 * The nonce action and the `$_POST` field name are the same string — each
	 * meta box registers its nonce under its own `NONCE` constant, which is passed
	 * here as both.
	 *
	 * @param int    $post_id The post being saved.
	 * @param string $nonce   The meta box's nonce action / field name.
	 * @return bool True when the save is genuine and permitted.
	 */
	public static function verified( $post_id, $nonce ) {
		if ( ! isset( $_POST[ $nonce ] ) ) {
			return false; // Not our form (quick-edit, REST, autosave-only, …).
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce ] ) ), $nonce ) ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}
}
