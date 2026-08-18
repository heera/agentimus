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

	/**
	 * Rows built per request. Each one parses a page, so this is a real cost.
	 *
	 * ⚠️ No longer the ceiling on the LIST — only on one page of it. It used to
	 * be both, which is why an owner with 340 pages was shown thirty and given no
	 * way to reach the rest: the ranking itself could not be computed beyond what
	 * one request could afford to parse. Grades are stored now {@see Grades}, so
	 * the ranking covers the whole site and this governs one screenful.
	 */
	const MAX_ITEMS = 30;

	/** Rows on one page of the list. */
	const PER_PAGE = 20;

	/** Content flags shown per row before the rest are counted. */
	const MAX_FLAGS = 3;

	/** @var string Option holding the sweep's run lock (a start time). */
	const SWEEP_LOCK = 'agentimus_sweep_lock';

	/**
	 * @var int Seconds before a held sweep lock is treated as abandoned.
	 *
	 * Comfortably longer than {@see Grades::SWEEP_SECONDS} so a healthy slow run
	 * is never robbed mid-chunk, and short enough that a run killed by a host's
	 * execution limit cannot wedge the sweep until someone notices.
	 */
	const SWEEP_LOCK_TTL = 300;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Bind the grading sweep and the two events that make a grade go stale.
	 *
	 * @return void
	 */
	public function register() {
		// ⚠️ A plugin update does not re-run activation, so a table added in a
		// new version has to be created from a register() or it will never exist
		// on a site that already had the plugin. It also has to happen BEFORE the
		// hooks below: deleting a post fires deleted_post, and a write against a
		// table that is not there is a database error in the owner's log.
		Grades::maybe_install();

		add_action( Grades::CRON, array( $this, 'sweep_and_continue' ) );

		// Marking only — never grading here. A save is the one moment the owner
		// is waiting for the editor to come back, and rendering their page again
		// to re-grade it would be felt every single time.
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Grade a chunk, and come straight back if there is more to do.
	 *
	 * The same shape the index sweep uses: an hourly schedule keeps it alive,
	 * but a site with three hundred ungraded pages should not take three hundred
	 * hours to finish, so a full chunk books the next one a minute out.
	 *
	 * ⛔ ONLY the initial fill chases itself. The re-grade horizon rides the
	 * hourly beat and never books a follow-up: a site that has read all of its
	 * content must not then re-read it at a page a minute for ever. That single
	 * condition is the difference between a background job and a treadmill.
	 *
	 * @return void
	 */
	public function sweep_and_continue() {
		$run = $this->sweep_run();
		if ( 'fill' === $run['mode'] && $run['more'] ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Grades::CRON );
		}
	}

	/**
	 * A post was saved: its grade is now a statement about an older version.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    The post.
	 * @return void
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post || 'publish' !== $post->post_status ) {
			// Unpublished content has no place in a list of pages to work on.
			Grades::forget( $post_id );
			return;
		}
		Grades::mark_stale( $post_id );
	}

	/**
	 * A post was deleted: nothing about it is true any more.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete( $post_id ) {
		Grades::forget( $post_id );
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
			// found 12, or Bing has not finished working through the other 288.
			'engine'      => $this->engine_label(),
			'pageCap'     => Report::page_cap(),
			'waiting'     => Report::waiting_pages(),
		);
	}

	/**
	 * The three bucket counts, what the fixable bucket is asking for, and how
	 * much of the site has not been read yet. No page is parsed — these are the
	 * grade store's own numbers, the same ones the chips carry.
	 *
	 * ⭐ Public because the FRONT DOOR has to be able to speak for this list.
	 * It used to count from a sample of its own (Score's 25 most recently
	 * modified posts, content flags only) and so said "nothing needs your
	 * attention" on a site whose Worth Fixing chip read 36. Two counts of one
	 * thing is one count too many; this is the one.
	 *
	 * @return array{fixable:int,clear:int,setAside:int,flagged:int,unanswered:int,grading:int}
	 */
	public function tally() {
		$types = $this->post_types();
		$aside = $this->set_aside_ids();

		return array_merge(
			Grades::counts( $types, $aside ),
			Grades::fixable_split( $types, $aside ),
			// ⚠️ The tally's honesty about itself: until the sweep has finished,
			// `fixable` is a count over PART of the site. A caller that prints
			// the number without this one is making the finished claim.
			array( 'grading' => Grades::remaining( $types ) )
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
	private function search_by_post( $source = '' ) {
		if ( '' === (string) $source ) {
			$state  = Report::source_state();
			$source = (string) $state['source'];
		}
		if ( '' === (string) $source ) {
			return array();
		}

		$out = array();
		foreach ( (array) Search\Table::snapshot( $source ) as $row ) {
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
	 * One page of the list, ranked over the WHOLE site.
	 *
	 * ⭐ This is what the grade store bought. The order is unchanged — pages
	 * needing work first, then by what a fix is worth — but it is now an indexed
	 * query over every published page rather than a sort of the thirty this
	 * request could afford to read. Page four costs what page one costs.
	 *
	 * Only the rows on this page are parsed. Their detail (which words are
	 * missing, which passage answers the search) is built live and never cached,
	 * so a row can never quietly describe a version of the post that no longer
	 * exists.
	 *
	 * @param string $filter 'fixable' | 'clear' | 'setAside'.
	 * @param int    $page   1-based page number.
	 * @param int    $per    Rows per page.
	 * @return array
	 */
	public function page( $filter, $page = 1, $per = self::PER_PAGE ) {
		$filter = in_array( $filter, array( 'fixable', 'clear', 'setAside' ), true ) ? $filter : 'fixable';
		// ⚠️ A missing or zero size means "use the normal page size", NOT "one
		// row". Clamping with max(1, …) turned an absent parameter into a
		// one-row page and an eight-row list into eight pages — which the tests
		// never saw, because every one of them passed a size explicitly.
		$per    = (int) $per > 0 ? min( self::MAX_ITEMS, (int) $per ) : self::PER_PAGE;
		$page   = max( 1, (int) $page );

		$types = $this->post_types();
		$aside = $this->set_aside_ids();
		$slice = Grades::page( $filter, $types, $aside, $page, $per );

		$search = $this->search_by_post();
		// Both engines, read once for the whole page rather than once per row.
		$maps   = $this->engine_maps();
		$items  = array();
		foreach ( $slice['ids'] as $id ) {
			$item = $this->item( (int) $id, isset( $search[ $id ] ) ? $search[ $id ] : array(), in_array( (int) $id, $aside, true ) );
			if ( $item ) {
				$item['engines'] = $this->engine_blocks( (int) $id, (string) $item['url'], $maps );
				$items[]         = $item;
			}
		}

		$payload = array(
			'items'        => $items,
			'counts'       => Grades::counts( $types, $aside ),
			'filter'       => $filter,
			'page'         => $page,
			'per'          => $per,
			'total'        => (int) $slice['total'],
			// ⚠️ The screen's honesty about ITSELF. Until every page has been
			// graded, this list is ranked over part of the site — a different
			// claim from the finished one, and only this number can say which is
			// on screen.
			'grading'      => Grades::remaining( $types ),
			'noSearchData' => Grades::without_search( $types ),
			'searchState'  => $this->search_state(),
			'engine'       => $this->engine_label(),
			'pageCap'      => Report::page_cap(),
			'waiting'      => Report::waiting_pages(),
		);

		/** This filter's contract is unchanged; the payload simply carries paging now. */
		$payload = apply_filters( self::FILTER, $payload, $this->settings );
		return is_array( $payload ) ? $payload : array( 'items' => array(), 'counts' => array(), 'total' => 0 );
	}

	/** @var int Searches shown per engine on an opened row before the rest are counted. */
	const MAX_ENGINE_ROWS = 5;

	/**
	 * Read both engines once, for a whole page of rows.
	 *
	 * ⚠️ Once, not once per row: each call is a full snapshot read, and doing it
	 * inside the row loop would be twenty of them for twenty rows.
	 *
	 * @return array
	 */
	private function engine_maps() {
		$state = Report::source_state();
		$maps  = array( 'state' => $state['sources'], 'rows' => array(), 'asks' => array(), 'checked' => array() );

		foreach ( array( 'google', 'bing' ) as $source ) {
			if ( empty( $state['sources'][ $source ]['connected'] ) ) {
				continue;
			}
			$maps['rows'][ $source ] = $this->search_by_post( $source );
		}

		if ( ! empty( $state['sources']['google']['connected'] ) ) {
			$maps['checked']['google'] = (int) ( new \Agentimus\Google\Settings() )->get( 'last_poll_at', 0 );
		}
		if ( ! empty( $state['sources']['bing']['connected'] ) ) {
			// Bing is asked page by page, so "when was this checked" is a fact
			// about the PAGE, not about the connection.
			$maps['asks'] = Search\Asks::map( 'bing' );
		}

		return $maps;
	}

	/**
	 * What each connected engine can say about ONE page.
	 *
	 * ⭐ THE POINT OF THE WHOLE ARC. Every screen before this picked one engine
	 * and showed it as though it were the answer, so a site with both connected
	 * saw only Google and never learned the other half. Here each engine gets its
	 * own block, its own window and its own last-checked stamp, and ⛔ THE TWO ARE
	 * NEVER SUMMED — an average of two engines' positions is a number neither of
	 * them reported.
	 *
	 * The `state` is what lets an absence name itself, which the queries table
	 * alone cannot do:
	 *
	 *   reported      → these are the searches, here they are
	 *   none          → asked, and the engine reported nothing for this page
	 *   unasked       → nobody has asked about this page yet (Bing only)
	 *   error         → asked, and the engine refused; its own words are kept
	 *   not_connected → this engine is not connected at all
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     The page's permalink.
	 * @param array  $maps    From {@see engine_maps()}.
	 * @return array<string,array>
	 */
	private function engine_blocks( $post_id, $url, array $maps ) {
		$out = array();

		foreach ( array( 'google', 'bing' ) as $source ) {
			$connected = ! empty( $maps['state'][ $source ]['connected'] );
			$block     = array(
				'name'      => 'google' === $source ? __( 'Google', 'agentimus' ) : __( 'Bing', 'agentimus' ),
				'state'     => 'not_connected',
				'rows'      => array(),
				'more'      => 0,
				'window'    => array( 'start' => '', 'end' => '' ),
				'checkedAt' => 0,
				'error'     => '',
			);

			if ( ! $connected ) {
				$out[ $source ] = $block;
				continue;
			}

			$rows = isset( $maps['rows'][ $source ][ $post_id ] ) ? $maps['rows'][ $source ][ $post_id ] : array();

			if ( 'bing' === $source ) {
				$ask = isset( $maps['asks'][ Search\Asks::key( $post_id, $url ) ] )
					? $maps['asks'][ Search\Asks::key( $post_id, $url ) ]
					: null;
				if ( null === $ask ) {
					// The sentence this arc exists for. Not "no searches reached
					// this page" — nobody has put the question to Bing yet.
					$block['state']  = 'unasked';
					$out[ $source ] = $block;
					continue;
				}
				$block['checkedAt'] = strtotime( $ask['askedAt'] . ' UTC' );
				$block['error']     = (string) $ask['error'];
				if ( Search\Asks::STATUS_ERROR === $ask['status'] ) {
					$block['state'] = 'error';
					$out[ $source ] = $block;
					continue;
				}
			} else {
				$block['checkedAt'] = isset( $maps['checked']['google'] ) ? (int) $maps['checked']['google'] : 0;
			}

			if ( ! $rows ) {
				$block['state'] = 'none';
				$out[ $source ] = $block;
				continue;
			}

			$block['state'] = 'reported';
			// Each engine's REAL window, off the rows themselves. Bing's is
			// anchored at its own newest weekly bucket and can end days before
			// Google's, so one shared "last 56 days" over both was never true.
			$block['window'] = array(
				'start' => (string) ( isset( $rows[0]['range_start'] ) ? $rows[0]['range_start'] : '' ),
				'end'   => (string) ( isset( $rows[0]['range_end'] ) ? $rows[0]['range_end'] : '' ),
			);
			foreach ( array_slice( $rows, 0, self::MAX_ENGINE_ROWS ) as $row ) {
				$block['rows'][] = array(
					'query'       => (string) $row['query'],
					'position'    => round( (float) $row['position'], 1 ),
					'impressions' => (int) $row['impressions'],
					'clicks'      => (int) $row['clicks'],
				);
			}
			$block['more']  = max( 0, count( $rows ) - self::MAX_ENGINE_ROWS );
			$out[ $source ] = $block;
		}

		return $out;
	}

	/**
	 * Grade the next few ungraded pages.
	 *
	 * Runs on cron, a bounded chunk at a time, because grading a page means
	 * rendering and reading it — the same real cost the worklist used to pay
	 * inside somebody's page load, only now nobody is waiting for it.
	 *
	 * @param int|null $limit How many to grade; the sweep's own chunk by default.
	 * @return int How many were graded.
	 */
	public function sweep( $limit = null ) {
		$run = $this->sweep_run( $limit );
		return $run['done'];
	}

	/**
	 * The sweep, with the two facts {@see sweep_and_continue()} needs back:
	 * which queue this run drew from, and whether that queue still has more.
	 *
	 * ⭐ Two guards stand between this and the shared hosting most of these
	 * sites live on, because rendering a page is the one genuinely expensive
	 * thing this plugin does:
	 *
	 *  - A run lock, so two cron ticks can never render the same chunk twice
	 *    over. WP's own cron guard usually prevents it; "usually" is not a
	 *    guarantee an owner's server should have to rely on.
	 *  - A time budget. The chunk bounds how many pages are read, this bounds
	 *    how long that may take, and on a slow host the second one is what
	 *    actually protects the request.
	 *
	 * @param int|null $limit How many; the sweep's own chunk by default.
	 * @return array{done:int,mode:string,more:bool}
	 */
	private function sweep_run( $limit = null ) {
		$idle = array( 'done' => 0, 'mode' => 'fill', 'more' => false );

		if ( ! self::acquire_sweep_lock() ) {
			return $idle;
		}

		try {
			return $this->sweep_locked( $limit );
		} finally {
			self::release_sweep_lock();
		}
	}

	/**
	 * The sweep proper, inside the run lock.
	 *
	 * @param int|null $limit How many; the sweep's own chunk by default.
	 * @return array{done:int,mode:string,more:bool}
	 */
	private function sweep_locked( $limit = null ) {
		// ⭐ Before the queue is chosen, never after. A site whose owner has not
		// opened the dashboard since installing a shop still gets the size guard
		// applied here — the cron is what would otherwise have queued twelve
		// thousand product renders, so this is the path that has to be safe on
		// its own. Costs an array_diff on every run and a COUNT only in the one
		// run where a content type is genuinely new.
		Content::note_new_checkable_types( $this->settings );

		$types = $this->post_types();
		$chunk = null === $limit ? Grades::SWEEP_CHUNK : max( 0, (int) $limit );

		// Never read for the first time, before anything is read again: content
		// nobody has looked at is what the "still to read" count is promising.
		$mode = 'fill';
		$ids  = Grades::ungraded( $types, $chunk );
		if ( ! $ids ) {
			// Nothing waiting. Spend the tick on the oldest verdicts instead —
			// this is the ONLY place the horizon runs, so a site still filling
			// never pays for it.
			$mode = 'refresh';
			$ids  = Grades::stale_graded( $types, $chunk );
		}
		if ( ! $ids ) {
			return array( 'done' => 0, 'mode' => $mode, 'more' => false );
		}

		// Read once for the whole chunk: the snapshot is shared by every row, and
		// re-reading it per post would be the expensive half of this loop.
		$search = $this->search_by_post();
		$done   = 0;
		// ⭐ The budget is the CRON's, not every caller's. An explicit limit is
		// somebody asking for a known amount of work — a test, a re-check, a
		// WP-CLI run — and quietly serving them half of it because a shared host
		// was slow would be the plugin lying about what it did. The unattended
		// path is the one that has to protect the site.
		$deadline = null === $limit ? microtime( true ) + Grades::SWEEP_SECONDS : 0.0;

		foreach ( $ids as $id ) {
			// ⚠️ Checked BEFORE each page, not after: the point is to never
			// START work that could overrun, and one page can cost a second on
			// the hosting this exists for. A run that stops early leaves the
			// rest in the queue, and the next tick picks them up — the only
			// cost of a slow host is more ticks, never a failed request.
			if ( $deadline > 0.0 && $done > 0 && microtime( true ) >= $deadline ) {
				break;
			}
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				// Unpublished, deleted, or no longer a type the engines can see.
				Grades::forget( $id );
				continue;
			}
			$item = $this->item( (int) $id, isset( $search[ $id ] ) ? $search[ $id ] : array(), false, true );
			if ( ! $item ) {
				Grades::forget( $id );
				continue;
			}
			$grade = isset( $item['grade'] ) ? (array) $item['grade'] : array();
			Grades::record( (int) $id, array(
				'needsWork' => $this->needs_work( $item ),
				'flags'     => count( (array) $item['flags'] ) + (int) $item['moreFlags'],
				'stake'     => (int) $item['stake'],
				'coverage'  => isset( $item['coverage']['state'] ) ? (string) $item['coverage']['state'] : '',
				'hasFocus'  => ! empty( $item['focus'] ),
				// The citability half — measured in the same render as the rest.
				'points'    => isset( $grade['points'] ) ? (int) $grade['points'] : 0,
				'flagIds'   => isset( $grade['ids'] ) ? (array) $grade['ids'] : array(),
				'gradeable' => ! empty( $grade['gradeable'] ),
				'hash'      => Grades::hash( $post, ! empty( $item['focus'] ) ? (string) $item['focus']['query'] : '' ),
			) );
			$done++;
		}

		// ⚠️ The sweep is now an INPUT to the cached Optimized pillar, which it
		// never was while that pillar parsed its own sample in the request. Left
		// unbusted, the score and the by-issue list would keep describing
		// whatever the store held when the cache was warmed — so reading more of
		// the site would visibly change nothing, and `graded` could sit beside a
		// freshly-read "0 still to read" contradicting it. Once per run, not once
		// per row: a chunk is one advance in what this site knows about itself.
		if ( $done > 0 ) {
			Cache::forget( Cache::OPTIMIZE );
		}

		return array(
			'done' => $done,
			'mode' => $mode,
			// The QUEUE handed back a full chunk, so assume there is more of it —
			// not "we graded a full chunk", which goes false the moment the time
			// budget cuts in or a few rows turn out to be deleted, and would park
			// a half-read site on the hourly beat for the rest of the day. Every
			// path through the loop either records a grade or forgets the row, so
			// a chase can never repeat the same work.
			'more' => count( $ids ) >= $chunk,
		);
	}

	/**
	 * Take the sweep's run lock, stealing one whose run has plainly died.
	 *
	 * Deliberately NOT the {@see PollLock} trait: that one carries a `poll_now()`
	 * built around a provider call this has no equivalent of, and inheriting a
	 * public method that would fatal if anyone called it is a worse trade than
	 * fifteen lines here. The lock's shape — an option holding a start time,
	 * stolen once stale — is the same, on purpose.
	 *
	 * @return bool Whether this caller now holds the lock.
	 */
	private static function acquire_sweep_lock() {
		$held = (int) get_option( self::SWEEP_LOCK, 0 );
		if ( $held > 0 && ( time() - $held ) < self::SWEEP_LOCK_TTL ) {
			return false;
		}
		// autoload false: read once an hour by cron, never by a page load.
		update_option( self::SWEEP_LOCK, time(), false );
		return true;
	}

	/**
	 * Release the sweep's run lock.
	 *
	 * @return void
	 */
	private static function release_sweep_lock() {
		delete_option( self::SWEEP_LOCK );
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

	/*
	 * ⛔ candidates() lived here. It picked which posts got a row — everything
	 * the engines reported, then the most recently edited content, capped at
	 * MAX_ITEMS — and its cap was the reason the list could never cover a whole
	 * site. The stored grades replaced it: every published page is graded by the
	 * sweep, and the ranked order comes out of an indexed query {@see Grades::page()}.
	 */

	/**
	 * The content types this site checks.
	 *
	 * ⚠️ This used to read `apply_filters('agentimus_post_types', array('post',
	 * 'page'), array())` — a hardcoded pair wearing a filter's clothes. It
	 * honoured neither the owner's selection nor their veto, and no provider
	 * could reach it either: the filter's second argument is the list of
	 * available types, and this passed an EMPTY array, so the one callback that
	 * folds a plugin's types in ({@see Integrations\Plugins\Provider}) could
	 * never match. A store could tick Products in Settings, watch the Products
	 * provider light up on the Integrations screen, and still have every product
	 * silently absent from this list.
	 *
	 * ⭐ It also has to be the SAME set {@see Gradeability::post_types()} builds
	 * on. The two are asked of one table: this fills it, that reads it, and when
	 * they disagreed `Grades::remaining()` counted pages the sweep would never
	 * reach — so "still to read" could never fall to zero and the Readiness card
	 * carried a permanent apology for work that was never going to happen.
	 *
	 * @return array<int,string>
	 */
	private function post_types() {
		$types = Content::check_post_types();
		return array_values( array_filter( array_map( 'strval', $types ), 'post_type_exists' ) );
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
	 * @param bool  $grade  Also return the `grade` block the STORE keeps —
	 *                      citability points, flagged check ids, gradeability.
	 *                      ⛔ Off for every screen: those three are the sweep's
	 *                      business, and a payload that carries them invites a
	 *                      surface to read a stored fact it should be asking the
	 *                      store for. Only {@see sweep()} asks for them.
	 * @return array|null
	 */
	private function item( $id, array $rows, $aside, $grade = false ) {
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

		// ⚠️ ONE analyze() for the whole row. It renders the page, and three
		// different consumers want a piece of it — the flag labels shown here,
		// the citability points the Optimized pillar averages, and the flagged
		// ids the by-issue list is rebuilt from. Asking twice would double the
		// only genuinely expensive thing this method does.
		$summary = PageCheck::summarize( PageCheck::analyze( $post ) );
		$flags   = $summary['flags'];

		$type  = get_post_type_object( $post->post_type );
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		$out = array(
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

		if ( $grade ) {
			// Not for the screen — for the store. The sweep hands these to Grades
			// so the Optimized pillar can be scored over the whole site without
			// re-rendering a single page. ⛔ Gradeability is asked with an EMPTY
			// set-aside list: whether a page is article-like is a fact about the
			// page, while set-aside is a decision the owner reverses from a
			// button, and the two must not be baked into one column.
			$out['grade'] = array(
				'points'    => (int) $summary['points'],
				'ids'       => $summary['ids'],
				// ⚠️ BOTH halves of the question, because the checking scope is
				// now wider than the gradeable one. is_gradeable() answers "is
				// this page article-like" — it never looks at the post type, so
				// a product with real prose in it came back true and was stored
				// as gradeable-for-quoting. Nothing read it wrongly (every query
				// filters by type first), but a stored fact that is not true is
				// a trap for whoever reads it next.
				'gradeable' => in_array( (string) $post->post_type, Gradeability::post_types(), true )
					&& Gradeability::is_gradeable( $post, array() ),
			);
		}

		return $out;
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
