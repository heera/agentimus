<?php
/**
 * Standing — has the owner done everything a worklist page can ask of them?
 *
 * One question, asked by three surfaces that must never disagree about the same
 * page: the editor panel says it in a sentence ("your side is done"), the
 * Findings screen needs it as a fact (to tell work from waiting), and the
 * Opportunities card needs it to know whether to keep asking. Two copies of this
 * rule would drift, and the drift shows up as a badge counting work the post's
 * own editor says is finished.
 *
 * WHY ITS OWN CLASS: this rule used to live on {@see \Agentimus\Focus}, which is
 * a meta box — it registers boxes, renders fields, enqueues assets and saves
 * post meta. Two subsystems that render no editor at all had come to depend on
 * it for a domain fact. The renderer and the rule are different jobs with
 * different reasons to change, so they are different classes; Focus now asks
 * this one, like everybody else.
 *
 * Pure reads. Nothing here writes, fetches or schedules — it is safe on any
 * admin page load, which is exactly where it is called from.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

use Agentimus\Content;
use Agentimus\Description;
use Agentimus\Focus;
use Agentimus\Seo;

defined( 'ABSPATH' ) || exit;

final class Standing {

	/**
	 * Whether the owner's side is done, for one page on one worklist.
	 *
	 * @param \WP_Post|int $post  The post.
	 * @param string       $kind  Opportunities::KIND_NEAR or ::KIND_SEEN.
	 * @param array|null   $cover A coverage verdict the caller has already
	 *                            measured — the expensive half, and the editor
	 *                            has always just done it.
	 * @return bool False whenever anything is unreadable — never a guess.
	 */
	public static function done( $post, $kind, array $cover = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		return Opportunities::KIND_SEEN === $kind
			? self::seen_done( $post )
			: self::near_done( $post, Focus::primary( $post ), $cover );
	}

	/**
	 * A page ranking just off page one: answered on the page, every searched word
	 * in the title searchers see, and at least one of the site's own posts
	 * pointing here.
	 *
	 * The rank band is deliberately NOT tested — the caller already knows which
	 * group the page is in, and this answers only the part the owner controls.
	 *
	 * @param \WP_Post   $post  The post.
	 * @param string     $query The search it is judged against.
	 * @param array|null $cover A verdict already measured for this pair.
	 * @return bool
	 */
	public static function near_done( $post, $query, array $cover = null ) {
		if ( null === $cover ) {
			$cover = Focus::coverage( $post, $query );
		}
		if ( ! is_array( $cover ) || Coverage::ANSWERED !== $cover['state'] ) {
			return false;
		}
		// A COUNT, not a flag: "okf" in the title does not carry "okf bundle".
		$words = (int) $cover['words'];
		if ( $words < 1 || (int) $cover['in_title'] < $words ) {
			return false;
		}
		return self::inbound_links( $post ) > 0;
	}

	/**
	 * A page that IS on page one and gets scrolled past.
	 *
	 * Nothing is wrong with such a page — what a searcher reads before deciding
	 * is the title and description pair, so that pair is the whole ask, and
	 * writing both is the whole of the owner's side. Whether anyone then clicks
	 * is the searcher's business and the engine's to report.
	 *
	 * Both fields must be written BY HAND. A derived description means the owner
	 * has not rewritten anything, and counting a fallback as work done would
	 * retire a finding nobody acted on.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	public static function seen_done( $post ) {
		// With an SEO plugin owning titles, the field we asked them to rewrite is
		// not ours to read. Unreadable is not done: we say nothing rather than
		// retire a finding on a guess.
		if ( ! Seo::title_ui_enabled() ) {
			return false;
		}
		if ( '' === Seo::sanitize_title( (string) get_post_meta( $post->ID, Seo::META_TITLE, true ) ) ) {
			return false;
		}
		return '' !== trim( Description::explicit( $post->ID ) );
	}

	/**
	 * The same question for a set of pages, answered once and cached for an hour.
	 *
	 * Measuring is not free — coverage runs the block renderer and the stemmer
	 * over the post body, and the inbound-link count is a LIKE across every
	 * published post — and this runs on every admin page load.
	 *
	 * The cache key hangs on the last time ANY post was modified, not on these
	 * posts' own timestamps. Adding the internal link that finishes a page is an
	 * edit to a DIFFERENT post, so a per-post key would have gone stale in
	 * precisely the case this exists for: the fix made, and the screen still
	 * asking for it.
	 *
	 * @param array  $ids  Post IDs.
	 * @param string $kind Opportunities::KIND_NEAR or ::KIND_SEEN.
	 * @return array<int,bool> Post ID → the owner's side is done.
	 */
	public static function map( array $ids, $kind ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		sort( $ids );

		$key    = 'agentimus_side_done_' . md5( (string) $kind . '|' . implode( ',', $ids ) . '|' . (string) get_lastpostmodified( 'gmt' ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = self::done( $id, $kind );
		}
		set_transient( $key, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * The post IDs behind a set of worklist cards.
	 *
	 * Both card shapes are accepted on purpose: the same card is called `page_id`
	 * on the way out of {@see Opportunities} and `postId` once it has been shaped
	 * for the wire, and both callers need this identical loop. One place to get
	 * it right beats two that agree today.
	 *
	 * @param array $cards Raw or wire cards.
	 * @return array<int,int> IDs, zeros dropped.
	 */
	public static function card_ids( array $cards ) {
		$ids = array();
		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$id = isset( $card['postId'] ) ? (int) $card['postId'] : (int) ( isset( $card['page_id'] ) ? $card['page_id'] : 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * How many of the site's own published posts link to this one.
	 *
	 * One COUNT over post_content for the slug as a link path — matched with a
	 * closing boundary (/ or ") so "the-okf" never counts "the-okf-2". Memoized
	 * per request: the editor's advice and this class's verdict both want it.
	 *
	 * @param \WP_Post $post The post.
	 * @return int
	 */
	public static function inbound_links( $post ) {
		static $memo = array();

		$id = (int) $post->ID;
		if ( isset( $memo[ $id ] ) ) {
			return $memo[ $id ];
		}
		$slug = (string) $post->post_name;
		if ( '' === $slug ) {
			$memo[ $id ] = 0;
			return 0;
		}

		global $wpdb;
		$types = array_map( 'esc_sql', Content::post_types() );
		$in    = "'" . implode( "','", $types ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is esc_sql'd above.
		$memo[ $id ] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID != %d AND post_type IN ($in) AND (post_content LIKE %s OR post_content LIKE %s)",
				$id,
				'%' . $wpdb->esc_like( '/' . $slug . '/' ) . '%',
				'%' . $wpdb->esc_like( '/' . $slug . '"' ) . '%'
			)
		);
		return $memo[ $id ];
	}
}
