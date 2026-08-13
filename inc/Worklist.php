<?php
/**
 * Worklist — one row per piece of content, instead of one row per complaint.
 *
 * The Optimize card is organised by COMPLAINT: nine rows, each unfolding the
 * pages it affects. To learn what is wrong with one page an owner had to read
 * all nine and assemble the answer themselves. Search Opportunities knew a
 * different part of the same page, and the editor knew a third. Same
 * information, three places, none of them the page.
 *
 * This turns it ninety degrees. A row is a post, a page, or any custom type the
 * site publishes, and it carries everything known about that one thing: the
 * search it is actually found for, whether it answers that search, and whatever
 * the content checks flagged.
 *
 * Two costs are managed deliberately:
 *
 *  - Parsing a page is not free, so the list is CAPPED and says so. A sample
 *    that quietly stops at thirty reads as "these are all your pages".
 *  - Nothing here runs on a normal admin page load. The front door ships with
 *    the boot payload; this is fetched by the screen that shows it.
 *
 * @package Agentimus
 */

namespace Agentimus;

use Agentimus\Search\Coverage;
use Agentimus\Search\Report;

defined( 'ABSPATH' ) || exit;

final class Worklist {

	/** Filter tag on the assembled list. */
	const FILTER = 'agentimus_worklist';

	/** Rows built per request. Each one parses a page, so this is a real cost. */
	const MAX_ITEMS = 30;

	/** Content flags shown per row before the rest are counted. */
	const MAX_FLAGS = 3;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The whole worklist.
	 *
	 * @return array{items:array<int,array>,counts:array<string,int>,capped:bool,total:int,searchState:string}
	 */
	public function all() {
		$search = $this->search_by_post();
		$ranked = $this->candidates( $search );

		$total  = count( $ranked );
		$capped = $total > self::MAX_ITEMS;
		$ranked = array_slice( $ranked, 0, self::MAX_ITEMS );

		$aside = $this->set_aside_ids();
		$items = array();
		foreach ( $ranked as $id ) {
			$item = $this->item( (int) $id, isset( $search[ $id ] ) ? $search[ $id ] : array(), in_array( (int) $id, $aside, true ) );
			if ( $item ) {
				$items[] = $item;
			}
		}

		// Every parked page ships, wherever it ranks. The cap governs the
		// worth-looking-at list; Set aside is a LEDGER — a page parked from a
		// finding that ranked below the shortlist used to vanish from this
		// screen entirely: still excluded from grading, listed on Readiness,
		// but unfindable and unrestorable HERE, where it was parked. Parked
		// pages are few by nature (each is a hand decision), so the extra
		// rows stay bounded.
		$listed = array();
		foreach ( $items as $item ) {
			$listed[ (int) $item['id'] ] = true;
		}
		foreach ( $aside as $id ) {
			if ( isset( $listed[ (int) $id ] ) ) {
				continue;
			}
			$item = $this->item( (int) $id, isset( $search[ $id ] ) ? $search[ $id ] : array(), true );
			if ( $item ) {
				$items[] = $item;
			}
		}

		// Sorted by what a fix is worth: impressions already being earned on a
		// search the page answers poorly. A page with no data cannot be ranked
		// this way and sits below the ones that can.
		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $b['stake'] <=> (int) $a['stake'];
			}
		);

		// Exclusive by construction: every row lands in exactly one bucket, so the
		// chips add up to the list. Overlapping counts ("9 worth fixing, 16 with
		// no data" over 30 rows) make an owner distrust the whole screen.
		$counts = array(
			'fixable'  => 0,
			'clear'    => 0,
			'setAside' => 0,
		);
		$no_search = 0;
		foreach ( $items as $item ) {
			if ( ! $item['focus'] ) {
				++$no_search;
			}
			if ( $item['setAside'] ) {
				++$counts['setAside'];
			} elseif ( $this->needs_work( $item ) ) {
				++$counts['fixable'];
			} else {
				++$counts['clear'];
			}
		}

		$payload = array(
			'items'       => $items,
			'counts'      => $counts,
			// Said out loud. A sample that stops silently reads as the whole site.
			'capped'      => $capped,
			'total'       => $total,
			// Not a chip: "no search data yet" is a fact about a row, not a
			// verdict on it, and a page with no data can still need work.
			'noSearchData' => $no_search,
			'searchState' => $this->search_state(),
			// Whose report every row above was drawn from, and how many pages
			// that report can speak about. On Bing the answer is the busiest few,
			// which changes what an empty focus column MEANS: not "no searches
			// reached this page" but "this source never looked at this page".
			'engine'      => $this->engine_label(),
			'pageCap'     => Report::page_cap(),
		);

		/**
		 * The assembled content worklist.
		 *
		 * @param array    $payload  items/counts/capped/total/searchState.
		 * @param Settings $settings Plugin settings.
		 */
		$payload = apply_filters( self::FILTER, $payload, $this->settings );
		return is_array( $payload ) ? $payload : array( 'items' => array(), 'counts' => $counts, 'capped' => false, 'total' => 0, 'searchState' => '', 'engine' => '', 'pageCap' => 0 );
	}

	/**
	 * The cheap facts, for the state before anyone has asked for the real scan.
	 *
	 * No page is parsed here — counts only — so this can ride along with the
	 * admin bootstrap. It exists because an empty panel offering a button is a
	 * worse invitation than one that already knows something true about the
	 * site: how much content there is, how much of it search has found, and how
	 * much the owner has already decided not to be nagged about.
	 *
	 * @return array{published:int,withSearch:int,setAside:int,searchState:string}
	 */
	public function preview() {
		$published = 0;
		foreach ( $this->post_types() as $type ) {
			$counts     = wp_count_posts( $type );
			$published += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		$with_search = 0;
		try {
			$with_search = count( $this->search_by_post() );
		} catch ( \Throwable $e ) {
			$with_search = 0; // No table yet, or no source. Not a number worth failing over.
		}

		return array(
			'published'   => $published,
			'withSearch'  => $with_search,
			// LIVE pages only — the raw option collects fossils (ids of posts
			// since deleted or unpublished), and Readiness already counts the
			// living. Two surfaces naming two totals for one list reads as a
			// miscount (seen live: 18 here, 11 there, 5 on the tab).
			'setAside'    => $this->set_aside_live_count(),
			'searchState' => $this->search_state(),
			// "12 of your 300 pages have search data" invites one of two very
			// different conclusions, and only the source can say which: Google
			// found 12, or Bing was only ever asked about 10.
			'engine'      => $this->engine_label(),
			'pageCap'     => Report::page_cap(),
		);
	}

	/**
	 * Whether a row is asking for something. A page answering its search with no
	 * content flags is finished, and saying so is how the list stays short.
	 *
	 * @param array $item One row.
	 * @return bool
	 */
	private function needs_work( array $item ) {
		if ( $item['flags'] ) {
			return true;
		}
		$state = isset( $item['coverage']['state'] ) ? $item['coverage']['state'] : '';
		return '' !== $state && Coverage::ANSWERED !== $state;
	}

	/**
	 * Every post the engines reported a search for, keyed by post ID, best
	 * search first. Pages the engines named but this site cannot resolve to a
	 * post (an archive, a dead permalink) have nothing to open in an editor, so
	 * they are not rows here.
	 *
	 * @return array<int,array<int,array>>
	 */
	private function search_by_post() {
		$state = Report::source_state();
		if ( '' === (string) $state['source'] ) {
			return array();
		}

		$out = array();
		foreach ( (array) Search\Table::snapshot( $state['source'] ) as $row ) {
			$id = (int) ( isset( $row['page_id'] ) ? $row['page_id'] : 0 );
			if ( $id <= 0 ) {
				continue;
			}
			// A machine probe is not a search anyone typed, and no edit to a page
			// makes one click. Excluded here exactly as the worklists exclude it.
			if ( Search\Opportunities::is_operator_query( (string) $row['query'] ) ) {
				continue;
			}
			$out[ $id ][] = $row;
		}

		foreach ( $out as &$rows ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					return (int) $b['impressions'] <=> (int) $a['impressions'];
				}
			);
		}
		unset( $rows );

		return $out;
	}

	/**
	 * The engine whose report the focus came from, named the way people say it.
	 *
	 * @return string 'Google', 'Bing', or '' when neither is answering.
	 */
	private function engine_label() {
		$source = (string) Report::source_state()['source'];
		if ( 'google' === $source ) {
			return 'Google';
		}
		if ( 'bing' === $source ) {
			return 'Bing';
		}
		return '';
	}

	/**
	 * The engine's own top search for a page whose owner chose a different one.
	 *
	 * Only speaks when there is something to add: a chosen focus, a reported
	 * search, and the two not being the same phrase. The engine is named from
	 * the same source the rest of the row reads, so a Bing-only site is never
	 * told Google said it.
	 *
	 * @param array          $chosen The owner's choice ({ query, chosen }).
	 * @param array<int,array> $rows  The page's reported searches, best first.
	 * @param string         $shown  The query the row is already showing.
	 * @return array|null { query, position, impressions, clicks, engine }
	 */
	private function reported_against( array $chosen, array $rows, $shown ) {
		if ( empty( $chosen['chosen'] ) || ! $rows ) {
			return null;
		}
		$top = $rows[0];
		if ( (string) $top['query'] === (string) $shown ) {
			return null;
		}
		return array(
			'query'       => (string) $top['query'],
			'position'    => round( (float) $top['position'], 1 ),
			'impressions' => (int) $top['impressions'],
			'clicks'      => (int) $top['clicks'],
			'engine'      => $this->engine_label(),
		);
	}

	/**
	 * Rebuild rows for named posts only.
	 *
	 * The expensive half of {@see all()} is per row — each one renders and reads
	 * its page — so re-reading the whole list to catch one edit is thirty times
	 * the work it needs. The cheap half (the search snapshot) is shared and runs
	 * once here as well.
	 *
	 * @param array<int,int> $ids Post IDs.
	 * @return array<int,array> Rows, in the order asked for; missing posts drop out.
	 */
	public function rows_for( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$ids = array_slice( $ids, 0, self::MAX_ITEMS );

		$search = $this->search_by_post();
		$aside  = $this->set_aside_ids();

		$out = array();
		foreach ( $ids as $id ) {
			$item = $this->item( $id, isset( $search[ $id ] ) ? $search[ $id ] : array(), in_array( $id, $aside, true ) );
			if ( $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * When each of these posts was last edited — no page is read.
	 *
	 * @param array<int,int> $ids Post IDs.
	 * @return array<int,string> id => post_modified_gmt.
	 */
	public function stamps( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', $ids ); // Ints only, cast above.
		$rows = $wpdb->get_results( "SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN ($in)" ); // phpcs:ignore WordPress.DB -- ids are cast to int; no user string reaches this.

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->ID ] = (string) $row->post_modified_gmt;
		}
		return $out;
	}

	/**
	 * Which posts get a row, in the order they earn one: everything the engines
	 * reported first (those can be ranked by what a fix is worth), then the most
	 * recently edited content, so a site with no search data still has a list.
	 *
	 * @param array $search Search rows keyed by post ID.
	 * @return array<int,int> Post IDs.
	 */
	private function candidates( array $search ) {
		$ids = array_keys( $search );

		$recent = get_posts(
			array(
				'post_type'        => $this->post_types(),
				'post_status'      => 'publish',
				'numberposts'      => self::MAX_ITEMS,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		foreach ( (array) $recent as $id ) {
			if ( ! in_array( (int) $id, $ids, true ) ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/**
	 * The content types this site treats as agent-visible — the same set the
	 * rest of the plugin describes, so the worklist can never grade something
	 * that never reaches a machine surface.
	 *
	 * @return array<int,string>
	 */
	private function post_types() {
		$types = (array) apply_filters( 'agentimus_post_types', array( 'post', 'page' ), array() );
		$types = array_values( array_filter( array_map( 'strval', $types ), 'post_type_exists' ) );
		return $types ? $types : array( 'post' );
	}

	/**
	 * Posts the owner set aside from citability grading. Note this is the
	 * OPTIMIZE list only: "don't grade this for quoting" and "don't suggest
	 * search fixes for this" are separate judgements by design, and merging them
	 * here would silently apply one decision to the other.
	 *
	 * @return array<int,int>
	 */
	private function set_aside_ids() {
		$raw = (array) $this->settings->get( 'optimize_ignored', array() );
		return array_values( array_filter( array_map( 'intval', $raw ) ) );
	}

	/**
	 * Parked pages that still EXIST — the number every surface should quote.
	 * The raw list keeps fossil ids on purpose (a restored post gets its
	 * verdict back), but a count that includes ghosts reads as a miscount
	 * beside Readiness's living one.
	 *
	 * @return int
	 */
	private function set_aside_live_count() {
		$n = 0;
		foreach ( $this->set_aside_ids() as $id ) {
			$post = get_post( $id );
			if ( $post && 'publish' === $post->post_status ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Build one row.
	 *
	 * @param int   $id     Post ID.
	 * @param array $rows   That post's search rows, best first.
	 * @param bool  $aside  Whether it is set aside.
	 * @return array|null
	 */
	private function item( $id, array $rows, $aside ) {
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		$html  = $this->rendered( $post );
		$focus = null;
		$cov   = null;

		// The author's own choice wins, so this row and the editor's panel can
		// never disagree about what the page is for. Without a choice, the search
		// that brings the most people stands in.
		// A page may now be for SEVERAL searches. This screen judges one, so it
		// takes the first — the one the author put first. The editor is where
		// the rest are measured. {@see Focus::primary()}
		$chosen          = Focus::for_post( $post );
		$chosen['all']   = $chosen['query'];
		$chosen['query'] = Focus::primary( $post );
		$best            = null;
		if ( $chosen['chosen'] && '' !== $chosen['query'] ) {
			foreach ( $rows as $row ) {
				if ( $row['query'] === $chosen['query'] ) {
					$best = $row;
					break;
				}
			}
			// Chosen by hand, or its search has since dropped out of the report:
			// still the focus, just with no numbers to show against it.
			if ( ! $best ) {
				$best = array(
					'query'       => $chosen['query'],
					'position'    => 0.0,
					'impressions' => 0,
					'clicks'      => 0,
				);
			}
		} elseif ( $rows ) {
			$best = $rows[0];
		}

		// Every search the author chose, each with its own verdict — the editor
		// measures them all, and a row that showed only the first was quietly
		// hiding the other decisions it was made from.
		$others = array();
		if ( $chosen['chosen'] ) {
			foreach ( Focus::phrases( $chosen['all'] ) as $phrase ) {
				$c = Focus::coverage( $post, $phrase, $html );
				$others[] = array(
					'query' => $phrase,
					'state' => $c ? (string) $c['state'] : '',
				);
			}
		}

		if ( $best ) {
			$focus = array(
				'query'       => (string) $best['query'],
				// One entry when there is one search; the row renders whatever
				// is here, so it never has to know which case it is in.
				'all'         => $others,
				'position'    => round( (float) $best['position'], 1 ),
				'impressions' => (int) $best['impressions'],
				'clicks'      => (int) $best['clicks'],
				'others'      => max( 0, count( $rows ) - 1 ),
				'chosen'      => (bool) $chosen['chosen'],
				// What the ENGINE actually shows this page for, when the owner
				// chose something else. Choosing a focus used to hide the
				// reported search entirely — the row said "You chose X" and the
				// only trace of reality was a "+3 more searches" count, so a
				// page aimed at the wrong words looked identical to one aimed
				// at the right ones. Null when nothing was reported, and null
				// when the two agree (there is no second thing to say).
				'reported'    => $this->reported_against( $chosen, $rows, (string) $best['query'] ),
				// WHICH engine said so. The row already distinguishes the author's
				// choice from a reported search; naming the reporter is the rest of
				// that sentence, and it is the difference between "we think this
				// page is about X" and "Google shows this page for X".
				'engine'      => (bool) $chosen['chosen'] ? '' : $this->engine_label(),
			);
			// Through Focus::coverage — the SEO-title-aware measurement the editor
			// and the 'others' loop above already use — so this row can never
			// contradict the editor's in_title verdict when an SEO title stands.
			$cov = Focus::coverage( $post, $focus['query'], $html );
		}

		$flags = PageCheck::flags( $post );

		$type  = get_post_type_object( $post->post_type );
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		return array(
			'id'       => (int) $post->ID,
			// When this row was true of. A screen holding the list can ask which
			// posts have been edited since — one indexed query — instead of
			// re-reading thirty pages to discover that one changed.
			'modified' => (string) $post->post_modified_gmt,
			'title'    => '' !== trim( $title ) ? $title : __( '(untitled)', 'agentimus' ),
			// Named, because "Pages" would be a lie on a site whose content is
			// Products, Docs or Recipes — and the owner needs to know which of
			// their things this row is.
			'type'     => $type ? (string) $type->labels->singular_name : (string) $post->post_type,
			'url'      => (string) get_permalink( $post ),
			'edit'     => (string) get_edit_post_link( $post->ID, 'raw' ),
			'focus'    => $focus,
			'coverage' => $cov,
			'flags'    => array_slice( $flags, 0, self::MAX_FLAGS ),
			'moreFlags' => max( 0, count( $flags ) - self::MAX_FLAGS ),
			'setAside' => (bool) $aside,
			'stake'    => $this->stake( $focus, $cov ),
		);
	}

	/**
	 * What a fix on this row is worth: impressions already being earned, scaled
	 * by how far the page is from answering the search that earns them. A page
	 * that already answers has nothing at stake however busy it is — that
	 * traffic is not being lost.
	 *
	 * @param array|null $focus Focus search.
	 * @param array|null $cov   Coverage verdict.
	 * @return int
	 */
	private function stake( $focus, $cov ) {
		if ( ! $focus || ! $cov ) {
			return 0;
		}
		$gap = array(
			Coverage::ANSWERED  => 0.0,
			Coverage::SCATTERED => 1.0,
			Coverage::BARELY    => 0.7,
			Coverage::MISSING   => 0.3, // Often the page simply isn't for it.
		);
		$weight = isset( $gap[ $cov['state'] ] ) ? $gap[ $cov['state'] ] : 0.0;
		return (int) round( $focus['impressions'] * $weight );
	}

	/**
	 * A post's own rendered content — the form the checks and the machine
	 * surfaces read, without third-party `the_content` filters joining in.
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private function rendered( $post ) {
		$content = (string) $post->post_content;
		return function_exists( 'do_blocks' ) ? do_blocks( $content ) : $content;
	}

	/**
	 * Whether search data is connected at all, so the screen can explain empty
	 * focus columns instead of leaving them blank.
	 *
	 * @return string 'ready' | 'collecting' | 'not_connected'
	 */
	private function search_state() {
		$state = Report::source_state();
		if ( ! $state['sources']['bing']['connected'] && ! $state['sources']['google']['connected'] ) {
			return 'not_connected';
		}
		return '' === (string) $state['source'] ? 'collecting' : 'ready';
	}
}
