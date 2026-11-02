<?php
/**
 * Move one post or page to the trash, or bring one back, for agents.
 *
 * ⛔ Never permanent delete, and no emptying the trash. One item per call.
 *
 * Trash takes a live page down, so it holds to ten an hour across the WHOLE SITE, not per
 * connection: the harm is to the site. It won't touch the homepage or the blog page, or
 * a post somebody has open in the editor. Restoring has its own limit, so a burst of
 * trash can never block the restore that undoes it.
 *
 * Restore always comes back as a draft, which is WordPress's own default. A site can
 * filter that to the old status, but that would put a page live past the publish switch,
 * so it is pinned for this one call. The owner's own restore is left alone.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\AgentAccess\Events;
use Agentimus\AgentAccess\Module;
use Agentimus\AgentAccess\Store;
use Agentimus\Content;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class ContentTrash {

	/** Items moved to the trash per hour, across the whole site. */
	const HOURLY_LIMIT = 10;

	/** Restores per connection, per hour. */
	const RESTORE_HOURLY_LIMIT = 30;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array $input { post_id }.
	 * @return array|\WP_Error {@see answer()}.
	 */
	public function trash( array $input ) {
		$post = $this->target( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( 'trash' === $post->post_status ) {
			return new \WP_Error( 'agentimus_already_trashed', __( 'That is already in the trash. Nothing was changed.', 'agentimus' ), array( 'status' => 409 ) );
		}

		if ( 'page' === get_option( 'show_on_front' ) && in_array( $post->ID, array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ), true ) ) {
			return new \WP_Error(
				'agentimus_trash_front_page',
				__( 'This page is the site’s homepage or blog page, so trashing it would break the front of the site. The owner can change that under Settings → Reading first. Nothing was changed.', 'agentimus' ),
				array( 'status' => 409 )
			);
		}

		$locked = ContentEditor::locked_by( $post->ID );
		if ( '' !== $locked ) {
			return new \WP_Error(
				'agentimus_post_locked',
				sprintf(
					/* translators: %s: who has the post open. */
					__( '%s has this open in the editor right now, and saving would bring it straight back. Nothing was changed.', 'agentimus' ),
					$locked
				),
				array( 'status' => 409 )
			);
		}

		$limited = self::over_limit(
			'agentimus_trash_writes',
			self::HOURLY_LIMIT,
			'agentimus_trash_rate_limited',
			/* translators: %d: items allowed per hour. */
			__( 'Agents have moved %d items to the trash on this site in the last hour, which is the limit. Nothing was changed. Try again later, or ask the owner.', 'agentimus' )
		);
		if ( null !== $limited ) {
			return $limited;
		}

		$previous = (string) $post->post_status;
		$done     = wp_trash_post( $post->ID );

		// Read back: a plugin can stop a trash through pre_trash_post.
		if ( ! $done || 'trash' !== get_post_status( $post->ID ) ) {
			return new \WP_Error( 'agentimus_trash_failed', __( 'Something on this site stopped the trash. The post is where it was.', 'agentimus' ), array( 'status' => 500 ) );
		}

		$this->log( sprintf( 'post %d trashed, was %s', $post->ID, $previous ) );

		/** This action is documented in inc/Abilities/ContentWriter.php */
		do_action( 'agentimus_agent_wrote_content', $post->ID, 'trash' );

		return self::answer(
			$post->ID,
			$previous,
			'publish' === $previous
				? __( 'Moved to the trash. It was live, so visitors no longer see it. restore-content brings it back as a draft.', 'agentimus' )
				: __( 'Moved to the trash. restore-content brings it back as a draft.', 'agentimus' )
		);
	}

	/**
	 * @param array $input { post_id }.
	 * @return array|\WP_Error {@see answer()}.
	 */
	public function restore( array $input ) {
		$post = $this->target( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( 'trash' !== $post->post_status ) {
			return new \WP_Error( 'agentimus_not_trashed', __( 'That is not in the trash. list-content with status=trash shows what is. Nothing was changed.', 'agentimus' ), array( 'status' => 409 ) );
		}

		$limited = self::over_limit(
			'agentimus_restore_writes_' . md5( get_current_user_id() . '|' . Module::current_credential() ),
			self::RESTORE_HOURLY_LIMIT,
			'agentimus_restore_rate_limited',
			/* translators: %d: restores allowed per hour. */
			__( 'This connection has restored %d items in the last hour, which is the limit. Nothing was changed. Try again later.', 'agentimus' )
		);
		if ( null !== $limited ) {
			return $limited;
		}

		$previous = (string) get_post_meta( $post->ID, '_wp_trash_meta_status', true );

		$as_draft = static function () {
			return 'draft';
		};
		add_filter( 'wp_untrash_post_status', $as_draft, PHP_INT_MAX );
		$done = wp_untrash_post( $post->ID );
		remove_filter( 'wp_untrash_post_status', $as_draft, PHP_INT_MAX );

		if ( ! $done || 'draft' !== get_post_status( $post->ID ) ) {
			return new \WP_Error( 'agentimus_restore_failed', __( 'Something on this site stopped the restore. Read the post before trying again.', 'agentimus' ), array( 'status' => 500 ) );
		}

		$this->log( sprintf( 'post %d restored as draft', $post->ID ) );

		/** This action is documented in inc/Abilities/ContentWriter.php */
		do_action( 'agentimus_agent_wrote_content', $post->ID, 'restore' );

		return self::answer(
			$post->ID,
			$previous,
			'' === $previous
				? __( 'Restored as a draft.', 'agentimus' )
				: sprintf(
					/* translators: %s: the status it had before the trash. */
					__( 'Restored as a draft, so it is not live. Before the trash it was “%s”. Putting it live again follows the publish rule: update-content with status=publish, or the owner.', 'agentimus' ),
					$previous
				)
		);
	}

	/**
	 * The post a call aims at, after the checks both directions share.
	 *
	 * @param array $input Ability input.
	 * @return \WP_Post|\WP_Error
	 */
	private function target( array $input ) {
		if ( ! $this->settings->enabled( 'agent_writes_content' ) ) {
			return new \WP_Error(
				'agentimus_content_off',
				__( 'Agents may not trash or restore content on this site. The owner can allow it under MCP Server, by choosing the Write level. Nothing was changed.', 'agentimus' ),
				array( 'status' => 403 )
			);
		}

		// One id, never a list: (int) of an array would aim at post 1.
		$id   = isset( $input['post_id'] ) && is_scalar( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'agentimus_not_found', __( 'No post or page with that id. Nothing was changed.', 'agentimus' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error( 'agentimus_bad_type', __( 'That content type is not shared with agents on this site. Nothing was changed.', 'agentimus' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new \WP_Error( 'agentimus_cannot_trash', __( 'The connected user may not delete this item, and the trash follows that permission. Nothing was changed.', 'agentimus' ), array( 'status' => 403 ) );
		}
		return $post;
	}

	/**
	 * @param int    $post_id  The post.
	 * @param string $previous The status it had before the trash, '' when unknown.
	 * @param string $message  Plain sentence for the owner.
	 * @return array
	 */
	private static function answer( $post_id, $previous, $message ) {
		$post = get_post( $post_id );
		return array(
			'id'             => (int) $post_id,
			'title'          => (string) $post->post_title,
			'type'           => (string) $post->post_type,
			'status'         => (string) $post->post_status,
			'previousStatus' => (string) $previous,
			'message'        => (string) $message,
		);
	}

	/**
	 * Count a write. Runs after every check, so a refusal spends nothing.
	 *
	 * @param string $key     Transient key.
	 * @param int    $limit   Writes allowed per hour.
	 * @param string $code    Error code.
	 * @param string $message Message, with %d for the limit.
	 * @return \WP_Error|null
	 */
	private static function over_limit( $key, $limit, $code, $message ) {
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new \WP_Error( $code, sprintf( $message, $limit ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return null;
	}

	/**
	 * A line for Agent Access, in the subject because that is what the screen shows.
	 *
	 * @param string $subject What changed.
	 */
	private function log( $subject ) {
		if ( Module::enabled( $this->settings ) ) {
			Store::record( Events::KIND_SETTING_CHANGED, get_current_user_id(), Module::current_credential(), $subject );
		}
	}
}
