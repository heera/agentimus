<?php
/**
 * Saved skills: the owner's rules and ways of doing a task, written down so every
 * agent follows them without being told again.
 *
 * A skill is a name, a line on when to use it, and its steps, all plain text. It is
 * stored as a private post type: revisions and export come with it, and nothing is
 * ever rendered as HTML. A skill on is published; a skill off is a draft.
 *
 * His ruling, 2026-11-22 (step D of adopting Fluent MCP's tools):
 *  - A skill the owner writes is on as soon as it is saved.
 *  - ⛔ A skill an AGENT saves waits off until the owner turns it on, and an agent
 *    that changes its own skill turns it off again. A saved skill steers every agent
 *    that comes later; one planted by text an agent read must not take effect with
 *    nobody looking.
 *  - An agent can never change a skill the owner wrote, turn one off or delete one.
 *  - 50 skills at most.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

defined( 'ABSPATH' ) || exit;

final class Skills {

	const POST_TYPE = 'agentimus_skill';

	/** Who wrote it: 'person' or 'agent'. */
	const BY = '_agentimus_skill_by';

	const MAX       = 50;
	const NAME_MAX  = 80;
	const WHEN_MAX  = 300;
	const STEPS_MAX = 8000;

	/**
	 * Hook the post type.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( self::class, 'register_post_type' ) );
	}

	/**
	 * A private post type: no screen of WordPress's own, no REST route, no page.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Agent skills', 'agentimus' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'supports'            => array( 'title', 'editor', 'excerpt', 'revisions' ),
			)
		);
	}

	/* -- reading ---------------------------------------------------------- */

	/**
	 * Every skill, for the owner's screen.
	 *
	 * @return array[]
	 */
	public static function all() {
		$rows = array();
		foreach ( self::posts( array( 'publish', 'draft' ) ) as $post ) {
			$rows[] = self::row( $post, true );
		}
		return $rows;
	}

	/**
	 * The skills that are on, for agents: name and when to use it.
	 *
	 * @return array[]
	 */
	public static function on() {
		$rows = array();
		foreach ( self::posts( array( 'publish' ) ) as $post ) {
			$rows[] = array(
				'name' => $post->post_title,
				'when' => $post->post_excerpt,
			);
		}
		return $rows;
	}

	/**
	 * One skill that is on, by name, for an agent. An off skill is not found.
	 *
	 * @param string $name Skill name.
	 * @return array|null
	 */
	public static function get_on( $name ) {
		$post = self::find( $name );
		if ( null === $post || 'publish' !== $post->post_status ) {
			return null;
		}
		return array(
			'name'  => $post->post_title,
			'when'  => $post->post_excerpt,
			'steps' => $post->post_content,
		);
	}

	/* -- an agent saves --------------------------------------------------- */

	/**
	 * Save a skill an agent wrote. It is stored off, for the owner to turn on.
	 *
	 * @param string $name  Skill name.
	 * @param string $when  When to use it.
	 * @param string $steps The steps.
	 * @return array|\WP_Error What was saved.
	 */
	public static function save_from_agent( $name, $when, $steps ) {
		$fields = self::clean( $name, $when, $steps );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$existing = self::find( $fields['name'] );
		if ( null !== $existing && 'agent' !== self::by( $existing ) ) {
			return new \WP_Error(
				'agentimus_skill_person',
				__( 'The site owner wrote this skill, so an agent can’t change it. Save yours under another name, or ask the owner to change it.', 'agentimus' ),
				array( 'status' => 403 )
			);
		}
		if ( null === $existing && self::count() >= self::MAX ) {
			return self::full();
		}
		$id = self::write( null === $existing ? 0 : $existing->ID, $fields, 'draft', 'agent' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array(
			'name'    => $fields['name'],
			'changed' => null !== $existing,
			'on'      => false,
			'message' => __( 'Saved. It stays off until the site owner turns it on under Agentimus → More → Skills, so other agents won’t follow it yet.', 'agentimus' ),
		);
	}

	/* -- the owner writes ------------------------------------------------- */

	/**
	 * Create or change a skill from the owner's screen. The owner's skill is on
	 * unless they say off; an agent's skill the owner edits becomes theirs.
	 *
	 * @param int    $id    0 to create.
	 * @param string $name  Skill name.
	 * @param string $when  When to use it.
	 * @param string $steps The steps.
	 * @param bool   $on    On or off.
	 * @return array|\WP_Error The saved row.
	 */
	public static function save_from_owner( $id, $name, $when, $steps, $on = true ) {
		$fields = self::clean( $name, $when, $steps );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$id    = (int) $id;
		$other = self::find( $fields['name'] );
		if ( null !== $other && $other->ID !== $id ) {
			return new \WP_Error( 'agentimus_skill_name', __( 'Another skill already has this name.', 'agentimus' ), array( 'status' => 409 ) );
		}
		if ( $id > 0 && null === self::post( $id ) ) {
			return self::missing();
		}
		if ( 0 === $id && self::count() >= self::MAX ) {
			return self::full();
		}
		$saved = self::write( $id, $fields, $on ? 'publish' : 'draft', 'person' );
		return is_wp_error( $saved ) ? $saved : self::row( get_post( $saved ), true );
	}

	/**
	 * Turn a skill on or off, from the owner's screen.
	 *
	 * @param int  $id Skill id.
	 * @param bool $on On or off.
	 * @return array|\WP_Error The row.
	 */
	public static function set_on( $id, $on ) {
		$post = self::post( $id );
		if ( null === $post ) {
			return self::missing();
		}
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => $on ? 'publish' : 'draft',
			)
		);
		return self::row( get_post( $post->ID ), true );
	}

	/**
	 * Delete a skill, from the owner's screen. It goes for good, with its revisions.
	 *
	 * @param int $id Skill id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$post = self::post( $id );
		return null !== $post && false !== wp_delete_post( $post->ID, true );
	}

	/* -- helpers ---------------------------------------------------------- */

	/**
	 * Clean the three fields as plain text, within their limits.
	 *
	 * @param mixed $name  Name.
	 * @param mixed $when  When to use it.
	 * @param mixed $steps Steps.
	 * @return array|\WP_Error
	 */
	private static function clean( $name, $when, $steps ) {
		$name  = trim( sanitize_text_field( (string) $name ) );
		$when  = trim( sanitize_textarea_field( (string) $when ) );
		$steps = trim( sanitize_textarea_field( (string) $steps ) );
		if ( '' === $name || '' === $steps ) {
			return new \WP_Error( 'agentimus_skill_empty', __( 'A skill needs a name and its steps.', 'agentimus' ), array( 'status' => 400 ) );
		}
		if ( self::length( $name ) > self::NAME_MAX || self::length( $when ) > self::WHEN_MAX || self::length( $steps ) > self::STEPS_MAX ) {
			return new \WP_Error(
				'agentimus_skill_long',
				sprintf(
					/* translators: 1: name limit, 2: when limit, 3: steps limit. */
					__( 'Too long. A name takes %1$d characters, when to use it %2$d, and the steps %3$d.', 'agentimus' ),
					self::NAME_MAX,
					self::WHEN_MAX,
					self::STEPS_MAX
				),
				array( 'status' => 400 )
			);
		}
		return array(
			'name'  => $name,
			'when'  => $when,
			'steps' => $steps,
		);
	}

	/**
	 * Insert or update, slashed: WordPress unslashes what it saves.
	 *
	 * @param int    $id     0 to create.
	 * @param array  $fields Clean fields.
	 * @param string $status publish or draft.
	 * @param string $by     person or agent.
	 * @return int|\WP_Error
	 */
	private static function write( $id, array $fields, $status, $by ) {
		$post = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $fields['name'],
			'post_excerpt' => $fields['when'],
			'post_content' => $fields['steps'],
			'post_status'  => $status,
		);
		if ( $id > 0 ) {
			$post['ID'] = $id;
		}
		// Plain text in, plain text stored: no content filter may rewrite a rule.
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		$saved = $id > 0 ? wp_update_post( wp_slash( $post ), true ) : wp_insert_post( wp_slash( $post ), true );
		if ( $kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		update_post_meta( (int) $saved, self::BY, $by );
		return (int) $saved;
	}

	/**
	 * Skills by status, newest change first.
	 *
	 * @param string[] $statuses Statuses.
	 * @return \WP_Post[]
	 */
	private static function posts( array $statuses ) {
		return get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => $statuses,
				'numberposts'      => self::MAX,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * A skill by name, on or off, matched without regard to case.
	 *
	 * @param string $name Skill name.
	 * @return \WP_Post|null
	 */
	private static function find( $name ) {
		$name = strtolower( trim( (string) $name ) );
		foreach ( self::posts( array( 'publish', 'draft' ) ) as $post ) {
			if ( strtolower( $post->post_title ) === $name ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * A skill by id, or null when the id is no skill.
	 *
	 * @param int $id Post id.
	 * @return \WP_Post|null
	 */
	private static function post( $id ) {
		$post = get_post( (int) $id );
		return ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) ? $post : null;
	}

	/**
	 * How many skills there are.
	 *
	 * @return int
	 */
	private static function count() {
		return count( self::posts( array( 'publish', 'draft' ) ) );
	}

	/**
	 * Who wrote a skill.
	 *
	 * @param \WP_Post $post The skill.
	 * @return string
	 */
	private static function by( $post ) {
		return 'agent' === get_post_meta( $post->ID, self::BY, true ) ? 'agent' : 'person';
	}

	/**
	 * A skill for the owner's screen.
	 *
	 * @param \WP_Post $post  The skill.
	 * @param bool     $steps Whether to include the steps.
	 * @return array
	 */
	private static function row( $post, $steps ) {
		$row = array(
			'id'       => (int) $post->ID,
			'name'     => $post->post_title,
			'when'     => $post->post_excerpt,
			'on'       => 'publish' === $post->post_status,
			'by'       => self::by( $post ),
			'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
		);
		if ( $steps ) {
			$row['steps'] = $post->post_content;
		}
		return $row;
	}

	/**
	 * Character count that understands multibyte text.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	private static function full() {
		return new \WP_Error(
			'agentimus_skill_full',
			/* translators: %d: the limit. */
			sprintf( __( 'This site already has %d skills, the most it keeps. The owner can delete one under Agentimus → More → Skills.', 'agentimus' ), self::MAX ),
			array( 'status' => 409 )
		);
	}

	private static function missing() {
		return new \WP_Error( 'agentimus_skill_missing', __( 'That skill no longer exists.', 'agentimus' ), array( 'status' => 404 ) );
	}
}
