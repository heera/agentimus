<?php
/**
 * What ONE connection may do: Everything, or Only some things.
 *
 * A connection is an assistant the owner approved (its OAuth client) or the shared
 * connection token. The site's own switches (Off / Read / Write and the extras) stay
 * the ceiling: a write tool whose switch is off is not on the server at all. Inside
 * that ceiling each connection holds one of two kinds of access:
 *
 *  - **everything**: every box, worked out when asked, so it follows the switches.
 *  - **some**: the boxes the owner ticked. Reading is always included.
 *
 * His ruling, 2026-11-22 (step A of adopting Fluent MCP's tools): the approval
 * screen's one button, Allow, gives Everything; "Allow less" opens the boxes. A
 * connection from before this had a scope instead: write becomes Everything (what
 * it could do already), read becomes Only some things with nothing ticked. Nothing
 * gains.
 *
 * Only a person logged in to wp-admin changes a connection's access (the REST routes
 * refuse an application password, and HardBlocks keeps both records as they are
 * during an agent call). An application password is no connection here: it keeps
 * following the site's switches.
 *
 * ⛔ Every write tool belongs to exactly one box (ConnectionAccessTest holds it), so a
 * new tool can't slip past Only some things by belonging to none.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\McpToken;
use Agentimus\Oauth\Server;

defined( 'ABSPATH' ) || exit;

final class ConnectionAccess {

	const EVERYTHING = 'everything';
	const SOME       = 'some';

	/** Each box and the write tools it opens. `publish` opens a power inside create-content and update-content. */
	const BOXES = array(
		'posts'       => array( 'create-content', 'update-content', 'edit-content', 'describe-image', 'describe-content-image', 'write-description', 'write-topics', 'write-search-fields' ),
		'publish'     => array(),
		'content'     => array( 'list-content', 'create-term', 'update-term', 'import-media', 'update-media', 'trash-content', 'restore-content' ),
		'reviews'     => array( 'set-aside-page', 'set-aside-search-page', 'dismiss-search', 'review-client', 'recheck-client', 'retry-announcement' ),
		'settings'    => array( 'update-settings', 'save-citation-product', 'apply-fix' ),
		'maintenance' => array( 'refresh-data', 'purge-edge-cache' ),
	);

	/**
	 * The boxes as the screens show them, in order.
	 *
	 * @return array<int, array{key: string, label: string, about: string}>
	 */
	public static function choices() {
		return array(
			array(
				'key'   => 'posts',
				'label' => __( 'Write and edit posts', 'agentimus' ),
				'about' => __( 'Drafts, edits, AI descriptions, topics and image descriptions.', 'agentimus' ),
			),
			array(
				'key'   => 'publish',
				'label' => __( 'Publish', 'agentimus' ),
				'about' => __( 'Put a post live or schedule it. Also needs “Publish without your review” in Settings.', 'agentimus' ),
			),
			array(
				'key'   => 'content',
				'label' => __( 'Manage terms, media and the trash', 'agentimus' ),
				'about' => __( 'Categories and tags, the media library, list drafts, trash and restore posts.', 'agentimus' ),
			),
			array(
				'key'   => 'reviews',
				'label' => __( 'Sort the review lists', 'agentimus' ),
				'about' => __( 'Set pages and searches aside, allow or block clients, retry an announcement.', 'agentimus' ),
			),
			array(
				'key'   => 'settings',
				'label' => __( 'Change Agentimus settings', 'agentimus' ),
				'about' => __( 'The settings agents may change, and Readiness fixes.', 'agentimus' ),
			),
			array(
				'key'   => 'maintenance',
				'label' => __( 'Refresh data and purge the cache', 'agentimus' ),
				'about' => __( 'Fetch fresh numbers from connected services and clear the edge cache.', 'agentimus' ),
			),
		);
	}

	/**
	 * Clean an access record, or build one from an older connection's scope.
	 *
	 * @param mixed  $access The stored record, or what a screen sent.
	 * @param string $scope  The older scope, used when there is no record.
	 * @return array{preset: string, boxes: string[]}
	 */
	public static function normalize( $access, $scope = 'read' ) {
		if ( ! is_array( $access ) || ! isset( $access['preset'] ) ) {
			return 'write' === $scope ? self::everything() : self::some( array() );
		}
		if ( self::EVERYTHING === $access['preset'] ) {
			return self::everything();
		}
		return self::some( isset( $access['boxes'] ) ? (array) $access['boxes'] : array() );
	}

	/**
	 * Everything.
	 *
	 * @return array{preset: string, boxes: string[]}
	 */
	public static function everything() {
		return array(
			'preset' => self::EVERYTHING,
			'boxes'  => array(),
		);
	}

	/**
	 * Only some things: the boxes given, known ones only, in screen order.
	 *
	 * @param string[] $boxes Box keys.
	 * @return array{preset: string, boxes: string[]}
	 */
	public static function some( array $boxes ) {
		$boxes = array_map( 'strval', $boxes );
		return array(
			'preset' => self::SOME,
			'boxes'  => array_values( array_filter( array_keys( self::BOXES ), static function ( $key ) use ( $boxes ) {
				return in_array( $key, $boxes, true );
			} ) ),
		);
	}

	/**
	 * The scope an access record needs on its tokens: write when any box can write.
	 *
	 * @param array $access A normalized record.
	 * @return string 'read' or 'write'.
	 */
	public static function scope_for( array $access ) {
		return ( self::EVERYTHING === $access['preset'] || array() !== $access['boxes'] ) ? 'write' : 'read';
	}

	/**
	 * The access of the connection running this request, or null when no
	 * connection did (an application password, a browser login): those follow
	 * the site's switches alone.
	 *
	 * @return array{preset: string, boxes: string[]}|null
	 */
	public static function current() {
		$grant = Server::request_access();
		if ( null !== $grant ) {
			return $grant;
		}
		return McpToken::request_access();
	}

	/**
	 * Whether this request's connection holds a box.
	 *
	 * @param string $box Box key.
	 * @return bool
	 */
	public static function allows( $box ) {
		$access = self::current();
		return null === $access || self::EVERYTHING === $access['preset'] || in_array( $box, $access['boxes'], true );
	}

	/**
	 * The box a write tool belongs to, or null for a tool that only reads.
	 *
	 * @param string $slug Tool slug, without the agentimus/ prefix.
	 * @return string|null
	 */
	public static function box_of( $slug ) {
		foreach ( self::BOXES as $box => $slugs ) {
			if ( in_array( $slug, $slugs, true ) ) {
				return $box;
			}
		}
		return null;
	}

	/**
	 * Whether this request's connection may use a tool. A write tool in no box is
	 * refused: an unsorted tool must never pass as allowed.
	 *
	 * @param string $slug Tool slug.
	 * @return bool
	 */
	public static function allows_tool( $slug ) {
		if ( ! in_array( $slug, Registrar::WRITE_SLUGS, true ) ) {
			return true;
		}
		$box = self::box_of( $slug );
		if ( null === self::current() ) {
			return true;
		}
		return null !== $box && self::allows( $box );
	}

	/**
	 * Wrap a write tool's permission callback with this connection's boxes.
	 *
	 * @param string   $slug       Tool slug.
	 * @param callable $permission The tool's own permission callback.
	 * @return callable
	 */
	public static function gate( $slug, callable $permission ) {
		return static function ( $input = null ) use ( $slug, $permission ) {
			if ( ! self::allows_tool( $slug ) ) {
				return false;
			}
			return call_user_func( $permission, $input );
		};
	}
}
