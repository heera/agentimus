<?php
/**
 * Content writes for agents — create/update posts and pages, with the Agentimus
 * per-page fields (AI description, Topics for AI) settable in the same call.
 *
 * The execution half of the create-content / update-content abilities. Everything
 * runs AS the authenticated user through core's own write path (wp_insert_post /
 * wp_update_post), so core's capability-based sanitisation applies unchanged — a
 * user without unfiltered_html gets their markup kses-filtered exactly as they
 * would in the editor, and post_author/attribution work like any other write.
 *
 * Trust boundaries, in order:
 *   1. The write abilities only EXIST when the owner flipped enable_agent_writes
 *      (see Registrar::register_write_abilities) — off means off on every surface.
 *   2. The user's own capabilities gate each call (create needs the type's
 *      edit_posts; update needs edit_post on the target) — an agent can never do
 *      more than the human whose key it holds.
 *   3. Going LIVE is a third, separate switch: status=publish (creating published,
 *      or moving a draft to published) needs agent_writes_publish AND the user's
 *      publish capability. Draft-first is the default — "write drafts I review"
 *      and "publish to my site" are different levels of trust. Editing a post that
 *      is ALREADY published is deliberately not behind that switch: it is the
 *      check-page → fix loop's whole point, and the user's edit_post capability
 *      (boundary 2) is the honest gate for it.
 *
 * Scope: the same agent-visible post types every other Agentimus surface covers
 * (Content::post_types()) — the write surface never reaches content the read
 * surface wouldn't.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Settings;
use Agentimus\Content;
use Agentimus\Topics;
use Agentimus\Description;

defined( 'ABSPATH' ) || exit;

final class ContentWriter {

	/** The statuses an agent may set. Draft first — it is also the default. */
	const STATUSES = array( 'draft', 'pending', 'publish' );

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Create a post/page (draft by default), optionally with its AI description
	 * and topics in the same call.
	 *
	 * @param array $input Validated ability input: { type?, title, content?, excerpt?,
	 *                     slug?, status?, description?, topics?, topics_derive? }.
	 * @return array|\WP_Error The written post's summary (see {@see summarize()}).
	 */
	public function create( array $input ) {
		$type  = isset( $input['type'] ) && '' !== (string) $input['type'] ? (string) $input['type'] : 'post';
		$title = isset( $input['title'] ) ? trim( (string) $input['title'] ) : '';

		if ( '' === $title ) {
			return new \WP_Error( 'agentimus_no_title', __( 'A title is required.', 'agentimus' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $type, Content::post_types(), true ) || ! post_type_exists( $type ) ) {
			return new \WP_Error(
				'agentimus_bad_type',
				sprintf(
					/* translators: %s: comma-separated list of allowed post types. */
					__( 'That type is not agent-visible on this site. Allowed: %s.', 'agentimus' ),
					implode( ', ', Content::post_types() )
				),
				array( 'status' => 400 )
			);
		}

		$status = $this->validate_status(
			isset( $input['status'] ) ? (string) $input['status'] : 'draft',
			$type,
			false // Creating: any transition INTO publish is "going live".
		);
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$postarr = array(
			'post_type'   => $type,
			'post_title'  => $title,
			'post_status' => $status,
		);
		foreach ( array(
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		) as $in => $field ) {
			if ( isset( $input[ $in ] ) ) {
				$postarr[ $field ] = (string) $input[ $in ];
			}
		}
		if ( isset( $input['slug'] ) && '' !== (string) $input['slug'] ) {
			$postarr['post_name'] = sanitize_title( (string) $input['slug'] );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->write_ai_meta( (int) $post_id, $input );

		return $this->summarize( (int) $post_id );
	}

	/**
	 * Update a post/page — only the provided fields are touched.
	 *
	 * @param array $input Validated ability input: { post_id, title?, content?, excerpt?,
	 *                     slug?, status?, description?, topics?, topics_derive? }.
	 * @return array|\WP_Error The written post's summary (see {@see summarize()}).
	 */
	public function update( array $input ) {
		$post = get_post( (int) ( $input['post_id'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'agentimus_not_found', __( 'Post not found.', 'agentimus' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error( 'agentimus_bad_type', __( 'That post’s type is not agent-visible on this site.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$postarr = array( 'ID' => $post->ID );

		if ( isset( $input['status'] ) && '' !== (string) $input['status'] && (string) $input['status'] !== $post->post_status ) {
			$status = $this->validate_status(
				(string) $input['status'],
				$post->post_type,
				'publish' === $post->post_status // Already live: keeping it published is not a NEW publish.
			);
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			$postarr['post_status'] = $status;
		}

		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$postarr['post_title'] = trim( (string) $input['title'] );
		}
		foreach ( array(
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		) as $in => $field ) {
			if ( isset( $input[ $in ] ) ) {
				$postarr[ $field ] = (string) $input[ $in ];
			}
		}
		if ( isset( $input['slug'] ) && '' !== (string) $input['slug'] ) {
			$postarr['post_name'] = sanitize_title( (string) $input['slug'] );
		}

		// Meta-only calls skip wp_update_post entirely (it would bump post_modified
		// for a no-op); the meta writes below carry their own cache-flush hooks.
		if ( count( $postarr ) > 1 ) {
			$updated = wp_update_post( $postarr, true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$this->write_ai_meta( $post->ID, $input );

		return $this->summarize( $post->ID );
	}

	/**
	 * Validate a requested status against the allowed set, the publish switch and
	 * the user's own publish capability.
	 *
	 * @param string $status            Requested status.
	 * @param string $type              Post type (for its publish capability).
	 * @param bool   $already_published Whether the post is already live (an update
	 *                                  keeping publish is not a NEW publish).
	 * @return string|\WP_Error The validated status.
	 */
	private function validate_status( $status, $type, $already_published ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error(
				'agentimus_bad_status',
				sprintf(
					/* translators: %s: comma-separated list of allowed statuses. */
					__( 'Status must be one of: %s.', 'agentimus' ),
					implode( ', ', self::STATUSES )
				),
				array( 'status' => 400 )
			);
		}

		if ( 'publish' === $status && ! $already_published ) {
			if ( ! $this->settings->enabled( 'agent_writes_publish' ) ) {
				return new \WP_Error(
					'agentimus_publish_off',
					__( 'Publishing by agents is off on this site. Create it as a draft (or pending) for the owner to review — or the owner can allow agent publishing in Settings → MCP server.', 'agentimus' ),
					array( 'status' => 403 )
				);
			}
			$pto = get_post_type_object( $type );
			$cap = ( $pto && isset( $pto->cap->publish_posts ) ) ? $pto->cap->publish_posts : 'publish_posts';
			if ( ! current_user_can( $cap ) ) {
				return new \WP_Error(
					'agentimus_cannot_publish',
					__( 'The connected user may not publish this content type.', 'agentimus' ),
					array( 'status' => 403 )
				);
			}
		}

		return $status;
	}

	/**
	 * Write the Agentimus per-page fields carried in the input. update_post_meta runs
	 * each meta's registered sanitiser (Topics::sanitize_manual / Description::sanitize)
	 * and fires the meta hooks that flush the generated caches — same path as the editor.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $input   Ability input.
	 */
	private function write_ai_meta( $post_id, array $input ) {
		if ( isset( $input['description'] ) ) {
			$description = Description::sanitize( $input['description'] );
			if ( '' === $description ) {
				delete_post_meta( $post_id, Description::META ); // Blank = fall back to the excerpt.
			} else {
				update_post_meta( $post_id, Description::META, $description );
			}
		}
		if ( isset( $input['topics'] ) ) {
			$topics = Topics::sanitize_manual( $input['topics'] );
			if ( empty( $topics ) ) {
				delete_post_meta( $post_id, Topics::META_TOPICS );
			} else {
				update_post_meta( $post_id, Topics::META_TOPICS, $topics );
			}
		}
		if ( isset( $input['topics_derive'] ) ) {
			update_post_meta( $post_id, Topics::META_DERIVE, Topics::sanitize_flag( $input['topics_derive'] ) );
		}
	}

	/**
	 * The written post's summary — what the agent needs to continue the loop:
	 * the id (for check-page / further edits), the live and edit URLs, and the
	 * stored AI fields as they now stand.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function summarize( $post_id ) {
		$post = get_post( $post_id );

		return array(
			'id'          => (int) $post_id,
			'type'        => $post ? (string) $post->post_type : '',
			'status'      => $post ? (string) $post->post_status : '',
			'title'       => $post ? (string) $post->post_title : '',
			'url'         => (string) get_permalink( $post_id ),
			'editUrl'     => (string) get_edit_post_link( $post_id, 'raw' ),
			'description' => Description::explicit( $post_id ),
			'topics'      => array_values( (array) get_post_meta( $post_id, Topics::META_TOPICS, true ) ),
		);
	}
}
