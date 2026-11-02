<?php
/**
 * Categories and tags, for agents: create one, rename one, describe one, move one.
 *
 * ⛔ Never delete or merge: those move every post filed under a term at once. A slug
 * is set once, at creation, because changing it breaks the term's address and nothing
 * redirects the old one. Only the two taxonomies the write tools can file a post in.
 *
 * Refused, where WordPress would allow it:
 *   - A second term with the same name, even under another parent. create-content and
 *     update-content take terms by name, so they could not tell the two apart.
 *   - A taken slug. WordPress would change it quietly and the caller would hold an
 *     address that isn't the one it asked for.
 *   - A parent that makes a loop. WordPress would quietly drop the move.
 *
 * WordPress runs kses over every term description, for admins too, and removes what it
 * doesn't allow without a word: even a <p>. {@see AgentMarkup} refuses what could run,
 * so that is never silent, and `note` says when WordPress changed anything else. Names
 * lose their markup to sanitize_text_field.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\AgentAccess\Events;
use Agentimus\AgentAccess\Module;
use Agentimus\AgentAccess\Store;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class TermWriter {

	/** Term writes per connection, per hour. A real tidy-up fits; a runaway loop does not. */
	const HOURLY_LIMIT = 30;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array $input { taxonomy, name, slug?, description?, parent? }.
	 * @return array|\WP_Error {@see answer()}.
	 */
	public function create( array $input ) {
		$off = $this->off();
		if ( null !== $off ) {
			return $off;
		}

		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
		if ( ! in_array( $taxonomy, ContentWriter::TAXONOMY_FIELDS, true ) || ! taxonomy_exists( $taxonomy ) ) {
			return self::bad_taxonomy();
		}
		if ( ! current_user_can( get_taxonomy( $taxonomy )->cap->edit_terms ) ) {
			return self::cannot_manage();
		}

		$name = self::clean_name( $input );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$description = isset( $input['description'] ) ? (string) $input['description'] : '';
		$unsafe      = AgentMarkup::refusal( $description );
		if ( null !== $unsafe ) {
			return $unsafe;
		}

		$parent = isset( $input['parent'] ) ? (int) $input['parent'] : 0;
		$bad    = self::bad_parent( $parent, $taxonomy );
		if ( null !== $bad ) {
			return $bad;
		}

		$taken = self::name_taken( $name, $taxonomy, 0 );
		if ( null !== $taken ) {
			return $taken;
		}

		$slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
		if ( '' !== $slug && get_term_by( 'slug', $slug, $taxonomy ) ) {
			return new \WP_Error(
				'agentimus_slug_taken',
				sprintf(
					/* translators: %s: the slug. */
					__( 'The slug “%s” is already used here. WordPress would quietly pick another address, so nothing was written. Send a different slug, or none to derive one from the name.', 'agentimus' ),
					$slug
				),
				array( 'status' => 409 )
			);
		}

		$limited = self::over_limit();
		if ( null !== $limited ) {
			return $limited;
		}

		$args = array(
			'description' => $description,
			'parent'      => $parent,
		);
		if ( '' !== $slug ) {
			$args['slug'] = $slug;
		}

		// wp_slash: the term write path unslashes, same as posts.
		$made = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $args ) );
		if ( is_wp_error( $made ) ) {
			return $made;
		}

		$term = get_term( (int) $made['term_id'], $taxonomy );
		$this->log( sprintf( '%s created: %s', $taxonomy, mb_substr( $term->name, 0, 60 ) ) );

		return self::answer( $term, $description );
	}

	/**
	 * @param array $input { term_id, name?, description?, parent? }. Only what is sent changes.
	 * @return array|\WP_Error {@see answer()}.
	 */
	public function update( array $input ) {
		$off = $this->off();
		if ( null !== $off ) {
			return $off;
		}

		$term = get_term( isset( $input['term_id'] ) ? (int) $input['term_id'] : 0 );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'agentimus_not_found', __( 'No category or tag with that id. read-terms lists them.', 'agentimus' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $term->taxonomy, ContentWriter::TAXONOMY_FIELDS, true ) ) {
			return self::bad_taxonomy();
		}
		if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
			return self::cannot_manage();
		}

		if ( isset( $input['slug'] ) ) {
			return new \WP_Error(
				'agentimus_slug_fixed',
				__( 'A slug can’t be changed here: it is the term’s address, and nothing would redirect the old one. Renaming keeps the slug. The owner can change it in wp-admin. Nothing was written.', 'agentimus' ),
				array( 'status' => 409 )
			);
		}

		$args    = array();
		$changed = array();

		if ( isset( $input['name'] ) ) {
			$name = self::clean_name( $input );
			if ( is_wp_error( $name ) ) {
				return $name;
			}
			$taken = self::name_taken( $name, $term->taxonomy, (int) $term->term_id );
			if ( null !== $taken ) {
				return $taken;
			}
			$args['name'] = $name;
			$changed[]    = 'name';
		}

		if ( isset( $input['description'] ) ) {
			$unsafe = AgentMarkup::refusal( (string) $input['description'], (string) $term->description );
			if ( null !== $unsafe ) {
				return $unsafe;
			}
			$args['description'] = (string) $input['description'];
			$changed[]           = 'description';
		}

		if ( isset( $input['parent'] ) ) {
			$parent = (int) $input['parent'];
			$bad    = self::bad_parent( $parent, $term->taxonomy, (int) $term->term_id );
			if ( null !== $bad ) {
				return $bad;
			}
			$args['parent'] = $parent;
			$changed[]      = 'parent';
		}

		if ( ! $args ) {
			return new \WP_Error( 'agentimus_nothing_to_change', __( 'Send a name, a description or a parent. Nothing was written.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$limited = self::over_limit();
		if ( null !== $limited ) {
			return $limited;
		}

		$done = wp_update_term( $term->term_id, $term->taxonomy, wp_slash( $args ) );
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$after = get_term( $term->term_id, $term->taxonomy );
		$this->log( sprintf( '%s %d changed: %s', $term->taxonomy, $term->term_id, implode( ', ', $changed ) ) );

		return self::answer( $after, isset( $args['description'] ) ? $args['description'] : null );
	}

	/**
	 * What a caller needs to go on: the stored values, read back.
	 *
	 * @param \WP_Term    $term The term.
	 * @param string|null $sent The description sent, null when none was.
	 * @return array
	 */
	private static function answer( \WP_Term $term, $sent = null ) {
		$url = get_term_link( $term );
		return array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
			'url'         => is_wp_error( $url ) ? '' : (string) $url,
			'note'        => null !== $sent && (string) $sent !== (string) $term->description
				? __( 'WordPress changed the description as it saved, because term descriptions allow only basic inline HTML. `description` above is what is stored now.', 'agentimus' )
				: '',
		);
	}

	/** @return \WP_Error|null */
	private function off() {
		if ( $this->settings->enabled( 'agent_writes_content' ) ) {
			return null;
		}
		return new \WP_Error(
			'agentimus_content_off',
			__( 'Agents may not manage categories and tags on this site. The owner can allow it under MCP Server, with “Let agents manage content”. Nothing was written.', 'agentimus' ),
			array( 'status' => 403 )
		);
	}

	/** @return \WP_Error */
	private static function bad_taxonomy() {
		return new \WP_Error( 'agentimus_bad_taxonomy', __( 'Only categories (category) and tags (post_tag) can be written here. Nothing was written.', 'agentimus' ), array( 'status' => 400 ) );
	}

	/** @return \WP_Error */
	private static function cannot_manage() {
		return new \WP_Error( 'agentimus_cannot_manage_terms', __( 'The connected user may not manage these terms. Nothing was written.', 'agentimus' ), array( 'status' => 403 ) );
	}

	/**
	 * @param array $input Ability input.
	 * @return string|\WP_Error The name, without markup or stray space.
	 */
	private static function clean_name( array $input ) {
		$name = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'agentimus_no_term_name', __( 'A name is required, in plain text. Nothing was written.', 'agentimus' ), array( 'status' => 400 ) );
		}
		return $name;
	}

	/**
	 * @param string $name     The name wanted.
	 * @param string $taxonomy Its taxonomy.
	 * @param int    $self     The term being renamed, 0 when creating.
	 * @return \WP_Error|null
	 */
	private static function name_taken( $name, $taxonomy, $self ) {
		$same = get_term_by( 'name', $name, $taxonomy );
		if ( ! $same || (int) $same->term_id === $self ) {
			return null;
		}
		return new \WP_Error(
			'agentimus_term_exists',
			sprintf(
				/* translators: 1: the existing term's name, 2: its id. */
				__( '“%1$s” already exists (id %2$d). The write tools file posts by name, so two terms with one name could not be told apart. Reuse that one. Nothing was written.', 'agentimus' ),
				$same->name,
				(int) $same->term_id
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * @param int    $parent   The parent wanted, 0 for the top level.
	 * @param string $taxonomy The term's taxonomy.
	 * @param int    $self     The term being moved, 0 when creating.
	 * @return \WP_Error|null
	 */
	private static function bad_parent( $parent, $taxonomy, $self = 0 ) {
		if ( 0 === $parent ) {
			return null;
		}
		$reason = '';
		if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
			$reason = __( 'Tags have no parents.', 'agentimus' );
		} elseif ( ! get_term( $parent, $taxonomy ) instanceof \WP_Term ) {
			$reason = __( 'That parent is not a category on this site.', 'agentimus' );
		} elseif ( $self > 0 && ( $parent === $self || in_array( $parent, array_map( 'intval', (array) get_term_children( $self, $taxonomy ) ), true ) ) ) {
			$reason = __( 'A category can’t sit under itself or under one of its own children.', 'agentimus' );
		}
		if ( '' === $reason ) {
			return null;
		}
		/* translators: %s: why the parent was refused. */
		return new \WP_Error( 'agentimus_bad_term_parent', sprintf( __( '%s Nothing was written.', 'agentimus' ), $reason ), array( 'status' => 400 ) );
	}

	/**
	 * Count a write against this connection's limit. Runs last, so a refusal spends nothing.
	 *
	 * @return \WP_Error|null
	 */
	private static function over_limit() {
		$key   = 'agentimus_term_writes_' . md5( get_current_user_id() . '|' . Module::current_credential() );
		$count = (int) get_transient( $key );
		if ( $count >= self::HOURLY_LIMIT ) {
			return new \WP_Error(
				'agentimus_terms_rate_limited',
				sprintf(
					/* translators: %d: writes allowed per hour. */
					__( 'This connection has written categories and tags %d times in the last hour, which is the limit. Nothing was written. Try again later.', 'agentimus' ),
					self::HOURLY_LIMIT
				),
				array( 'status' => 429 )
			);
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
