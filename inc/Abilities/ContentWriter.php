<?php
/**
 * Content writes for agents — create/update posts and pages, with the Agentimus
 * per-page fields (AI description, Topics for AI), the core organisation
 * (categories, tags, featured image) all settable in the same call — so an agent
 * can produce a fully-dressed post without anyone opening wp-admin. Taxonomy and
 * media follow the same rule as everything else: the connected user's own
 * capabilities, mirrored from wp-admin (assign_terms to file a post under terms,
 * edit_terms to invent new ones, upload_files to import an image from a URL).
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

		// Resolve terms and the featured image BEFORE inserting, so a capability
		// refusal or a failed image import aborts cleanly instead of leaving a
		// half-dressed post behind. (A term created here for a post that then
		// fails to insert is harmless — it's just an empty term.)
		$term_ids = $this->resolve_terms( $input, $type );
		if ( is_wp_error( $term_ids ) ) {
			return $term_ids;
		}
		$featured = $this->resolve_featured_image( $input, $type );
		if ( is_wp_error( $featured ) ) {
			return $featured;
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

		$this->apply_terms( (int) $post_id, $term_ids );
		$this->apply_featured_image( (int) $post_id, $featured );
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

		$term_ids = $this->resolve_terms( $input, $post->post_type );
		if ( is_wp_error( $term_ids ) ) {
			return $term_ids;
		}
		$featured = $this->resolve_featured_image( $input, $post->post_type );
		if ( is_wp_error( $featured ) ) {
			return $featured;
		}

		// Meta-only calls skip wp_update_post entirely (it would bump post_modified
		// for a no-op); the meta writes below carry their own cache-flush hooks.
		if ( count( $postarr ) > 1 ) {
			$updated = wp_update_post( $postarr, true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$this->apply_terms( $post->ID, $term_ids );
		$this->apply_featured_image( $post->ID, $featured );
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

	/** The taxonomy each input field maps to. */
	const TAXONOMY_FIELDS = array(
		'categories' => 'category',
		'tags'       => 'post_tag',
	);

	/**
	 * Resolve the categories/tags carried in the input into term IDs — creating
	 * missing terms only when the user may manage that taxonomy — WITHOUT touching
	 * the post yet. Capability rules mirror wp-admin exactly: assigning needs the
	 * taxonomy's assign_terms cap, creating a new term needs its edit_terms cap
	 * (so an author can file a post under existing categories but can't invent
	 * new ones, same as in the editor).
	 *
	 * @param array  $input Ability input.
	 * @param string $type  Post type the terms will be assigned to.
	 * @return array<string,int[]>|\WP_Error taxonomy → term ids (only for fields present
	 *                                       in the input; an empty list means "clear").
	 */
	private function resolve_terms( array $input, $type ) {
		$out = array();

		foreach ( self::TAXONOMY_FIELDS as $field => $taxonomy ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}
			if ( ! is_object_in_taxonomy( $type, $taxonomy ) ) {
				return new \WP_Error(
					'agentimus_no_taxonomy',
					sprintf(
						/* translators: 1: input field (categories/tags), 2: post type. */
						__( 'This site’s “%2$s” content type does not use %1$s.', 'agentimus' ),
						$field,
						$type
					),
					array( 'status' => 400 )
				);
			}
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) ) {
				return new \WP_Error(
					'agentimus_cannot_assign_terms',
					sprintf(
						/* translators: %s: input field (categories/tags). */
						__( 'The connected user may not assign %s.', 'agentimus' ),
						$field
					),
					array( 'status' => 403 )
				);
			}

			$names = array();
			foreach ( (array) $input[ $field ] as $name ) {
				$name = trim( sanitize_text_field( (string) $name ) );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}

			$ids = array();
			foreach ( array_unique( $names ) as $name ) {
				$term = get_term_by( 'name', $name, $taxonomy );
				if ( ! $term ) {
					$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
				}
				if ( $term ) {
					$ids[] = (int) $term->term_id;
					continue;
				}
				// Mirror the editor exactly: free-form (non-hierarchical) terms like
				// tags are creatable by anyone who may assign them — that is how the
				// tags box has always worked — while a hierarchical taxonomy like
				// categories only offers existing terms unless the user may manage it.
				if ( is_taxonomy_hierarchical( $taxonomy ) && ! current_user_can( $tax->cap->edit_terms ) ) {
					return new \WP_Error(
						'agentimus_cannot_create_term',
						sprintf(
							/* translators: 1: term name, 2: input field (categories/tags). */
							__( '“%1$s” does not exist and the connected user may not create new %2$s — use existing ones, or ask the owner to add it.', 'agentimus' ),
							$name,
							$field
						),
						array( 'status' => 403 )
					);
				}
				$created = wp_insert_term( $name, $taxonomy );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				$ids[] = (int) $created['term_id'];
			}

			$out[ $taxonomy ] = $ids;
		}

		return $out;
	}

	/**
	 * Assign the resolved terms. REPLACE semantics, like every other field here:
	 * the list you pass is the list the post ends up with ([] clears it).
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $term_ids taxonomy → term ids, from {@see resolve_terms()}.
	 */
	private function apply_terms( $post_id, array $term_ids ) {
		foreach ( $term_ids as $taxonomy => $ids ) {
			wp_set_post_terms( $post_id, $ids, $taxonomy, false );
		}
	}

	/**
	 * Resolve the featured_image input into an attachment ID — WITHOUT touching the
	 * post yet. Accepts an existing attachment ID ("123") or an http(s) image URL,
	 * which is imported into the media library exactly like core's own importers
	 * (same download, mime and size rules) and needs the user's upload_files cap.
	 * featured_image_alt, when given, becomes the imported image's alt text.
	 *
	 * @param array  $input Ability input.
	 * @param string $type  Post type (must support thumbnails).
	 * @return int|string|null|\WP_Error Attachment ID to set, '' to remove,
	 *                                   null when the input carries no featured_image.
	 */
	private function resolve_featured_image( array $input, $type ) {
		if ( ! isset( $input['featured_image'] ) ) {
			return null;
		}
		$ref = trim( (string) $input['featured_image'] );
		if ( '' === $ref ) {
			return ''; // Explicit empty = remove the featured image.
		}
		if ( ! post_type_supports( $type, 'thumbnail' ) ) {
			return new \WP_Error(
				'agentimus_no_thumbnail_support',
				sprintf(
					/* translators: %s: post type. */
					__( 'This site’s “%s” content type does not support a featured image (the active theme decides that).', 'agentimus' ),
					$type
				),
				array( 'status' => 400 )
			);
		}

		if ( ctype_digit( $ref ) ) {
			$id = (int) $ref;
			if ( ! wp_attachment_is_image( $id ) ) {
				return new \WP_Error( 'agentimus_not_an_image', __( 'That attachment ID is not an image in the media library.', 'agentimus' ), array( 'status' => 400 ) );
			}
			return $id;
		}

		if ( ! preg_match( '#^https?://#i', $ref ) ) {
			return new \WP_Error( 'agentimus_bad_image_ref', __( 'featured_image must be an attachment ID or an http(s) image URL.', 'agentimus' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'agentimus_cannot_upload', __( 'The connected user may not upload files, so an image URL can’t be imported — pass an existing attachment ID instead.', 'agentimus' ), array( 'status' => 403 ) );
		}

		// Core's importer path: download_url() fetches via wp_safe_remote_get and the
		// standard upload pipeline enforces the site's mime/size rules.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_sideload_image( $ref, 0, null, 'id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( isset( $input['featured_image_alt'] ) && '' !== trim( (string) $input['featured_image_alt'] ) ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['featured_image_alt'] ) );
		}
		return (int) $id;
	}

	/**
	 * Set/remove the featured image resolved by {@see resolve_featured_image()},
	 * and file an imported image under the post in the media library.
	 *
	 * @param int             $post_id  Post ID.
	 * @param int|string|null $featured Attachment ID, '' to remove, null to leave alone.
	 */
	private function apply_featured_image( $post_id, $featured ) {
		if ( null === $featured ) {
			return;
		}
		if ( '' === $featured ) {
			delete_post_thumbnail( $post_id );
			return;
		}
		set_post_thumbnail( $post_id, (int) $featured );
		// An image imported for this post should belong to it in the library.
		$attachment = get_post( (int) $featured );
		if ( $attachment && 0 === (int) $attachment->post_parent ) {
			wp_update_post(
				array(
					'ID'          => (int) $featured,
					'post_parent' => $post_id,
				)
			);
		}
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

		// Absent topics meta reads back as '' (the meta has no registered default),
		// and (array) '' would be [""] — a phantom blank topic in every response.
		$topics = get_post_meta( $post_id, Topics::META_TOPICS, true );

		$thumb_id = (int) get_post_thumbnail_id( $post_id );

		return array(
			'id'            => (int) $post_id,
			'type'          => $post ? (string) $post->post_type : '',
			'status'        => $post ? (string) $post->post_status : '',
			'title'         => $post ? (string) $post->post_title : '',
			'url'           => (string) get_permalink( $post_id ),
			'editUrl'       => (string) get_edit_post_link( $post_id, 'raw' ),
			'description'   => Description::explicit( $post_id ),
			'topics'        => is_array( $topics ) ? array_values( $topics ) : array(),
			'categories'    => self::term_names( $post_id, 'category' ),
			'tags'          => self::term_names( $post_id, 'post_tag' ),
			'featuredImage' => $thumb_id > 0
				? array(
					'id'  => $thumb_id,
					'url' => (string) wp_get_attachment_url( $thumb_id ),
				)
				: null,
		);
	}

	/**
	 * A post's term names for one taxonomy, as a plain list — empty (never an
	 * error) for a post type that doesn't use the taxonomy.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	private static function term_names( $post_id, $taxonomy ) {
		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
		return is_wp_error( $terms ) ? array() : array_values( array_map( 'strval', (array) $terms ) );
	}
}
