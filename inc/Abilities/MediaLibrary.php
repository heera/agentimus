<?php
/**
 * The media library, for agents: add an image from a URL, edit an item's words.
 *
 * ⛔ URL only, images only. No bytes come through this server. Only JPG, PNG, GIF and
 * WebP: an SVG can carry a script, so it stays out, and that list is held here rather
 * than taken from core's filterable one. The address only gets a request made; the
 * downloaded file itself then decides, so an SVG named .jpg is refused.
 *
 * The site's upload limit is enforced while downloading (`limit_response_size`), so a
 * huge file stops at the cap instead of filling the disk first. The request goes through
 * wp_safe_remote_get, which refuses internal addresses, on redirects too.
 *
 * Alt text on an existing item stays with describe-image, which guards against replacing
 * what somebody wrote. Here it is only the first description of a brand new image.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\AgentAccess\Events;
use Agentimus\AgentAccess\Module;
use Agentimus\AgentAccess\Store;
use Agentimus\Media;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class MediaLibrary {

	/** Extensions an address may end in. Fixed here on purpose: see the class doc. */
	const EXTENSIONS = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp' );

	/** What the downloaded file must really be. */
	const MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/** Media writes per connection, per hour. */
	const HOURLY_LIMIT = 30;

	/** Seconds a download may take. */
	const TIMEOUT = 60;

	/** The input fields that map to a post field, and render as HTML. */
	const FIELDS = array(
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	);

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * PURE: does this address end in an extension we fetch? Reads the last extension of
	 * the path, so a query string can't supply one and a.jpg.php is not a JPG.
	 *
	 * @param string $url The address.
	 * @return bool
	 */
	public static function extension_allowed( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return in_array( strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
	}

	/**
	 * @param array $input { url, title?, caption?, description?, alt? }.
	 * @return array|\WP_Error {@see answer()}.
	 */
	public function import( array $input ) {
		$off = $this->off();
		if ( null !== $off ) {
			return $off;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'agentimus_cannot_upload', __( 'The connected user may not upload files. Nothing was fetched.', 'agentimus' ), array( 'status' => 403 ) );
		}

		$url = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
		if ( ! preg_match( '#^https?://#i', $url ) || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new \WP_Error( 'agentimus_bad_media_url', __( 'url must be a full http(s) address. Nothing was fetched.', 'agentimus' ), array( 'status' => 400 ) );
		}
		if ( ! self::extension_allowed( $url ) ) {
			return self::bad_type();
		}

		$fields = array();
		foreach ( self::FIELDS as $in => $field ) {
			if ( isset( $input[ $in ] ) ) {
				$unsafe = AgentMarkup::refusal( (string) $input[ $in ] );
				if ( null !== $unsafe ) {
					return $unsafe;
				}
				$fields[ $field ] = (string) $input[ $in ];
			}
		}
		if ( isset( $fields['post_title'] ) && '' === trim( $fields['post_title'] ) ) {
			unset( $fields['post_title'] ); // Blank means the default: named after the file.
		}

		$alt = isset( $input['alt'] ) ? trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $input['alt'] ) ) ) : '';
		if ( mb_strlen( $alt ) > MediaWriter::MAX_ALT ) {
			return new \WP_Error(
				'agentimus_alt_too_long',
				sprintf(
					/* translators: %d: the maximum. */
					__( 'alt is limited to %d characters: it is read aloud in one breath. Nothing was fetched.', 'agentimus' ),
					MediaWriter::MAX_ALT
				),
				array( 'status' => 400 )
			);
		}

		$limited = self::over_limit();
		if ( null !== $limited ) {
			return $limited;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$tmp  = wp_tempnam( $name );

		$id = $this->fetch_and_store( $url, $name, $tmp, $fields );

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( '' !== $alt ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		}

		$this->log( sprintf( 'media %d imported', (int) $id ) );

		return self::answer( (int) $id, $fields );
	}

	/**
	 * @param string $url    The address.
	 * @param string $name   File name to store under.
	 * @param string $tmp    Temporary file to download into.
	 * @param array  $fields Post fields for the new item.
	 * @return int|\WP_Error Attachment ID.
	 */
	private function fetch_and_store( $url, $name, $tmp, array $fields ) {
		$max      = (int) wp_max_upload_size();
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'stream'              => true,
				'filename'            => $tmp,
				// One byte over the limit tells "too large" from "exactly at it".
				'limit_response_size' => $max + 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'agentimus_media_fetch_failed',
				sprintf(
					/* translators: %s: why the download failed. */
					__( 'The image could not be downloaded: %s Nothing was added.', 'agentimus' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'agentimus_media_fetch_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The image could not be downloaded: that address answered %d. Nothing was added.', 'agentimus' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		clearstatcache( true, $tmp );
		$size = file_exists( $tmp ) ? (int) filesize( $tmp ) : 0;
		if ( $size > $max ) {
			return new \WP_Error(
				'agentimus_media_too_large',
				sprintf(
					/* translators: %s: the site's upload limit, e.g. 64 MB. */
					__( 'That file is larger than this site’s upload limit of %s. Nothing was added.', 'agentimus' ),
					size_format( $max )
				),
				array( 'status' => 413 )
			);
		}

		// ⛔ The file decides, not the address.
		if ( 0 === $size || ! in_array( (string) wp_get_image_mime( $tmp ), self::MIMES, true ) ) {
			return self::bad_type();
		}

		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			null,
			wp_slash( $fields )
		);

		return is_wp_error( $id ) ? $id : (int) $id;
	}

	/**
	 * @param array $input { attachment_id, title?, caption?, description? }. Only what is sent changes.
	 * @return array|\WP_Error {@see answer()}.
	 */
	public function update( array $input ) {
		$off = $this->off();
		if ( null !== $off ) {
			return $off;
		}

		$post = get_post( isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0 );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'agentimus_not_found', __( 'No media library item with that id. search-media finds them.', 'agentimus' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'agentimus_cannot_edit_media', __( 'The connected user may not edit this item. Nothing was written.', 'agentimus' ), array( 'status' => 403 ) );
		}

		$fields  = array();
		$changed = array();
		foreach ( self::FIELDS as $in => $field ) {
			if ( ! isset( $input[ $in ] ) ) {
				continue;
			}
			$value = (string) $input[ $in ];
			if ( 'title' === $in && '' === trim( $value ) ) {
				return new \WP_Error( 'agentimus_no_media_title', __( 'A title can’t be blank. Nothing was written.', 'agentimus' ), array( 'status' => 400 ) );
			}
			$unsafe = AgentMarkup::refusal( $value, (string) $post->$field );
			if ( null !== $unsafe ) {
				return $unsafe;
			}
			$fields[ $field ] = $value;
			$changed[]        = $in;
		}

		if ( ! $fields ) {
			return new \WP_Error( 'agentimus_nothing_to_change', __( 'Send a title, a caption or a description. Alt text is written with describe-image. Nothing was written.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$limited = self::over_limit();
		if ( null !== $limited ) {
			return $limited;
		}

		$done = wp_update_post( wp_slash( array_merge( array( 'ID' => $post->ID ), $fields ) ), true );
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$this->log( sprintf( 'media %d changed: %s', $post->ID, implode( ', ', $changed ) ) );

		return self::answer( $post->ID, $fields );
	}

	/**
	 * The stored item, read back, saying if WordPress or a plugin changed what was sent.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $sent Post fields sent.
	 * @return array
	 */
	private static function answer( $id, array $sent ) {
		$post = get_post( $id );
		$note = '';
		foreach ( $sent as $field => $value ) {
			if ( (string) $value !== (string) $post->$field ) {
				$note = __( 'What is stored differs from what was sent: this site changed it as it saved. The fields above show what is stored now.', 'agentimus' );
				break;
			}
		}
		return array_merge( (array) Media::row( $id ), array( 'note' => $note ) );
	}

	/** @return \WP_Error|null */
	private function off() {
		if ( $this->settings->enabled( 'agent_writes_content' ) ) {
			return null;
		}
		return new \WP_Error(
			'agentimus_content_off',
			__( 'Agents may not manage the media library on this site. The owner can allow it under MCP Server, with “Let agents manage content”. Nothing was written.', 'agentimus' ),
			array( 'status' => 403 )
		);
	}

	/** @return \WP_Error */
	private static function bad_type() {
		return new \WP_Error( 'agentimus_bad_media_type', __( 'Only JPG, PNG, GIF and WebP images can be added this way. SVG stays out because it can carry a script. The address must end in the file’s extension, and the file must really be that image. Nothing was added.', 'agentimus' ), array( 'status' => 400 ) );
	}

	/**
	 * Count a write against this connection's limit. Runs after every check, so a
	 * refusal spends nothing and a burst is stopped before it fetches.
	 *
	 * @return \WP_Error|null
	 */
	private static function over_limit() {
		$key   = 'agentimus_media_writes_' . md5( get_current_user_id() . '|' . Module::current_credential() );
		$count = (int) get_transient( $key );
		if ( $count >= self::HOURLY_LIMIT ) {
			return new \WP_Error(
				'agentimus_media_rate_limited',
				sprintf(
					/* translators: %d: writes allowed per hour. */
					__( 'This connection has written to the media library %d times in the last hour, which is the limit. Nothing was written. Try again later.', 'agentimus' ),
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
