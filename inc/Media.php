<?php
/**
 * Media — searching the library, for agents that need to NAME an image.
 *
 * This exists to close a seam the write tier opened. `create-content` grades a
 * new draft and tells the agent "No featured image — choose an image that
 * stands for it", and `featured_image` accepts an attachment ID. But nothing
 * let an agent see what IDs exist, so the ID half of that parameter was
 * unusable by exactly the audience the warning addressed. The only route left
 * was importing a URL from somewhere else on the internet — for a picture the
 * owner may already have had.
 *
 * Read-only on purpose, and it will stay that way. Uploading BYTES through MCP
 * was considered and rejected: tool parameters travel through the model, so a
 * 3MB image becomes ~4MB of base64 in the context window, clients choke on it,
 * and it opens a file-write surface the protocol never intended (there is no
 * client→server binary channel in the spec). URL import stays the way new
 * bytes arrive; this is how existing ones are found.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Media {

	/** Rows one search may return, whatever the caller asks for. */
	const MAX_RESULTS = 50;

	/** Rows returned when the caller doesn't say. */
	const DEFAULT_RESULTS = 20;

	/**
	 * Search the media library.
	 *
	 * Two passes, because WordPress splits an attachment's words across two
	 * places: `s` covers the title, caption and description, while ALT TEXT
	 * lives in postmeta and no core query reaches it. Alt is often the only
	 * place a photograph is actually described — a file called `IMG_4831.jpg`
	 * with the alt "sunrise over the Buriganga" is invisible to a title search
	 * and is exactly what someone means when they ask for a picture of a
	 * sunrise. Missing it would leave the tool answering "no images" about a
	 * library full of them.
	 *
	 * Results are deduplicated by ID, title matches first (a deliberate title
	 * beats an incidental alt), and bounded before anything is loaded.
	 *
	 * @param string $q     Free text; empty returns the most recent uploads.
	 * @param int    $limit How many rows at most.
	 * @param string $mime  MIME prefix filter, e.g. 'image' — empty for all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function search( $q = '', $limit = self::DEFAULT_RESULTS, $mime = 'image' ) {
		$limit = max( 1, min( self::MAX_RESULTS, (int) $limit ) );
		$q     = trim( (string) $q );
		$mime  = sanitize_text_field( (string) $mime );

		$base = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		);
		if ( '' !== $mime ) {
			$base['post_mime_type'] = $mime;
		}

		$ids = array();

		if ( '' === $q ) {
			$ids = get_posts( $base );
		} else {
			$ids = get_posts( array_merge( $base, array( 's' => $q ) ) );

			// Only pay for the meta scan when the first pass left room. On a
			// library where the title search already filled the page, the alt
			// pass could only add rows nobody asked to see.
			if ( count( $ids ) < $limit ) {
				$alt = get_posts( array_merge( $base, array(
					'posts_per_page' => $limit - count( $ids ),
					'post__not_in'   => $ids,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query -- The only place alt text lives; bounded by posts_per_page.
						array(
							'key'     => '_wp_attachment_image_alt',
							'value'   => $q,
							'compare' => 'LIKE',
						),
					),
				) ) );
				$ids = array_merge( $ids, $alt );
			}
		}

		$out = array();
		foreach ( $ids as $id ) {
			$row = self::row( (int) $id );
			if ( $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * How many described images are examined for file-name alts in one call.
	 *
	 * The empty-alt pass is exact — SQL answers it — but "the alt is only the
	 * file name" cannot be asked of the database, so those are found by reading.
	 * Bounded, and the count read is reported, because a filter that quietly
	 * looked at 400 of 4,000 images and said "that is all of them" would be a
	 * lie the owner cannot see.
	 */
	const SCAN_CAP = 400;

	/**
	 * The library's UNDESCRIBED images — the ones a fixing run is for.
	 *
	 * ⭐⭐ WHY IT IS NOT A SEARCH. describe-image could always write a library
	 * item's alt, and nothing could tell it WHICH items needed one: search
	 * matches words, and the images that need describing are precisely those
	 * with no words on them. "Which of my images have no description?" was
	 * unanswerable, so the media half of the worklist could only ever be
	 * cleared one picture at a time, by an owner who already knew.
	 *
	 * ⭐ "Undescribed" is {@see \Agentimus\PageCheck::counts_as_described()}'s
	 * answer, not this class's — so an image whose alt is its own file name is
	 * listed here for the same reason the checks flag it.
	 *
	 * @param int    $limit How many rows at most.
	 * @param string $mime  MIME prefix filter, e.g. 'image'.
	 * @return array{items:array<int,array<string,mixed>>,scanned:int}
	 */
	public static function undescribed( $limit = self::DEFAULT_RESULTS, $mime = 'image' ) {
		$limit = max( 1, min( self::MAX_RESULTS, (int) $limit ) );
		$mime  = sanitize_text_field( (string) $mime );

		$base = array(
			'post_type'     => 'attachment',
			'post_status'   => 'inherit',
			'orderby'       => 'date',
			'order'         => 'DESC',
			'no_found_rows' => true,
			'fields'        => 'ids',
		);
		if ( '' !== $mime ) {
			$base['post_mime_type'] = $mime;
		}

		// Pass one: no description at all. Exact, and the common case by far.
		$ids = get_posts(
			array_merge(
				$base,
				array(
					'posts_per_page' => $limit,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query -- The only place alt text lives; bounded by posts_per_page.
						'relation' => 'OR',
						array(
							'key'     => '_wp_attachment_image_alt',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_wp_attachment_image_alt',
							'value'   => '',
							'compare' => '=',
						),
					),
				)
			)
		);

		// Pass two: a description that is only the file name. Only paid for when
		// the first pass left room on the page.
		$scanned = 0;
		if ( count( $ids ) < $limit ) {
			$described = get_posts(
				array_merge(
					$base,
					array(
						'posts_per_page' => self::SCAN_CAP,
						'post__not_in'   => $ids,
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query -- Bounded by SCAN_CAP.
							array(
								'key'     => '_wp_attachment_image_alt',
								'value'   => '',
								'compare' => '!=',
							),
						),
					)
				)
			);
			$scanned = count( $described );
			// One query for every meta value, rather than one per image.
			if ( $described ) {
				update_meta_cache( 'post', $described );
			}
			foreach ( $described as $id ) {
				if ( count( $ids ) >= $limit ) {
					break;
				}
				$alt = trim( (string) get_post_meta( (int) $id, '_wp_attachment_image_alt', true ) );
				// The stored path, not the public URL: it carries the file name
				// the comparison needs and is already in the meta cache.
				$file = (string) get_post_meta( (int) $id, '_wp_attached_file', true );
				if ( '' !== $alt && ! PageCheck::counts_as_described( $alt, $file ) ) {
					$ids[] = (int) $id;
				}
			}
		}

		$out = array();
		foreach ( $ids as $id ) {
			$row = self::row( (int) $id );
			if ( $row ) {
				$out[] = $row;
			}
		}
		return array(
			'items'   => $out,
			'scanned' => (int) $scanned,
		);
	}

	/**
	 * One attachment as an agent needs it: enough to choose between pictures,
	 * and the ID to name the one it chose.
	 *
	 * `alt` rides along because it is the only human description most images
	 * carry, and `url` because a multimodal client can look at the picture
	 * rather than guessing from a filename. Dimensions are here for the same
	 * reason a person checks them — a 300px logo is not a featured image.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,mixed>|null Null when the ID isn't a real attachment.
	 */
	public static function row( $id ) {
		$id   = (int) $id;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$meta = (array) wp_get_attachment_metadata( $id );

		return array(
			'id'     => $id,
			'title'  => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'url'    => (string) wp_get_attachment_url( $id ),
			'mime'   => (string) get_post_mime_type( $id ),
			'width'  => (int) ( isset( $meta['width'] ) ? $meta['width'] : 0 ),
			'height' => (int) ( isset( $meta['height'] ) ? $meta['height'] : 0 ),
			'date'   => (string) get_the_date( 'Y-m-d', $post ),
		);
	}
}
