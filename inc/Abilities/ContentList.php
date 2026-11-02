<?php
/**
 * What is waiting and not live: drafts, pending posts, scheduled posts and trash.
 *
 * No other tool lists these. read-content-issues covers published pages, and
 * read-content needs an id, so an agent could not find a post it had scheduled.
 *
 * Mirrors the owner's post list: a user who may not edit other people's posts sees
 * only their own. That is decided in the query, so `total` never counts a row the
 * caller cannot see. Private and published posts are not listed: this is the list
 * of what is not live, and a private post is someone's decision about who reads it.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Content;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class ContentList {

	/** The statuses this tool lists. Never publish or private. */
	const STATUSES = array( 'draft', 'pending', 'future', 'trash' );

	/** The most rows in one answer. */
	const MAX_PER = 100;

	/** Rows in one answer when none is asked for. */
	const DEFAULT_PER = 20;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array $input { type?, status?, search?, page?, per? }.
	 * @return array|\WP_Error { type, items, total, pages, page, per }.
	 */
	public function read( array $input ) {
		if ( ! $this->settings->enabled( 'agent_writes_content' ) ) {
			return new \WP_Error(
				'agentimus_content_off',
				__( 'Agents may not list unpublished content on this site. The owner can allow it under MCP Server, by choosing the Write level.', 'agentimus' ),
				array( 'status' => 403 )
			);
		}

		$type = isset( $input['type'] ) && '' !== (string) $input['type'] ? (string) $input['type'] : 'post';
		$pto  = get_post_type_object( $type );
		if ( ! $pto || ! in_array( $type, Content::post_types(), true ) ) {
			return new \WP_Error(
				'agentimus_bad_type',
				sprintf(
					/* translators: %s: comma-separated list of allowed post types. */
					__( 'That content type isn’t shared with agents on this site. Allowed: %s.', 'agentimus' ),
					implode( ', ', Content::post_types() )
				),
				array( 'status' => 400 )
			);
		}
		if ( ! current_user_can( $pto->cap->edit_posts ) ) {
			return new \WP_Error( 'agentimus_cannot_list', __( 'The connected user may not edit this content type.', 'agentimus' ), array( 'status' => 403 ) );
		}

		$status = isset( $input['status'] ) ? (string) $input['status'] : '';
		if ( '' !== $status && ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error(
				'agentimus_bad_status',
				sprintf(
					/* translators: %s: comma-separated list of statuses. */
					__( 'Status must be one of: %s. This tool lists what is not live.', 'agentimus' ),
					implode( ', ', self::STATUSES )
				),
				array( 'status' => 400 )
			);
		}

		$per  = isset( $input['per'] ) && (int) $input['per'] > 0 ? min( self::MAX_PER, (int) $input['per'] ) : self::DEFAULT_PER;
		$page = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'post_type'           => $type,
			'post_status'         => '' === $status ? self::STATUSES : array( $status ),
			'posts_per_page'      => $per,
			'paged'               => $page,
			'ignore_sticky_posts' => true,
			// Scheduled posts read soonest first. Anything else, latest work first.
			'orderby'             => 'future' === $status ? 'date' : 'modified',
			'order'               => 'future' === $status ? 'ASC' : 'DESC',
		);
		if ( ! current_user_can( $pto->cap->edit_others_posts ) ) {
			$args['author'] = get_current_user_id();
		}
		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = trim( (string) $input['search'] );
		}

		$query = new \WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$scheduled = ContentWriter::scheduled_for( $post );
			$items[]   = array(
				'id'             => (int) $post->ID,
				'title'          => (string) $post->post_title,
				'type'           => (string) $post->post_type,
				'status'         => (string) $post->post_status,
				'modified'       => (string) $post->post_modified_gmt,
				'scheduledFor'   => $scheduled,
				'missedSchedule' => '' !== $scheduled && strtotime( $post->post_date_gmt . ' UTC' ) < time(),
				'statusBeforeTrash' => 'trash' === $post->post_status ? (string) get_post_meta( $post->ID, '_wp_trash_meta_status', true ) : '',
			);
		}

		return array(
			'type'  => $type,
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $page,
			'per'   => $per,
		);
	}
}
