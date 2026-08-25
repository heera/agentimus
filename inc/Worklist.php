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
		// ⭐⭐ SELF-HEALING, and it has to be. The hourly sweep was scheduled in
		// activate() alone — and an UPDATE never activates anything, so every
		// site that upgraded into the grade store (rather than installing fresh)
		// has been running without the beat that reads its content. The backlog
		// then only moves when somebody opens Your Content, which is exactly the
		// screen that reports the backlog: it can say "75 still to be read" for
		// days and be describing a queue nothing was draining. Seen on heera.it,
		// 2026-08-19, 75 pages pending and the number barely moving.
		//
		// Idempotent by construction — {@see Grades::schedule()} books nothing
		// when the event already exists — and one wp_next_scheduled() is an
		// autoloaded-option read. Same shape as BotRanges and RouteProbe.
		Grades::schedule();

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
	 * ⭐ And book a reading, rather than leaving it to the hourly beat. The
	 * moment somebody edits a page is the moment they come back to the screen
	 * that sent them there, and "your verdict will refresh within the hour" is
	 * not an answer to "did I fix it?". The verdict stays visible and marked
	 * while it waits {@see Grades::mark_stale()}; this is what keeps that wait
	 * to about a minute.
	 *
	 * ⚠️ Self-throttling, and it has to be: an import saving four hundred posts
	 * calls this four hundred times. WordPress refuses a single event whose hook
	 * already has one scheduled within ten minutes of it — including the next
	 * turn of the hourly sweep — so a storm books one run, and a save just after
	 * a run was already due books nothing at all.
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
		self::regrade_soon( $post_id );
	}

	/**
	 * This page's verdict is out of date — keep it on screen, and book the
	 * reading that replaces it.
	 *
	 * ⭐ Public because SAVING IS NOT THE ONLY WAY. An agent writing the focus
	 * keyword or the SEO title through the abilities changes what the page is
	 * measured against without touching a word of it — and a meta-only write
	 * never fires `save_post`, so nothing here would have noticed. Every path
	 * that invalidates a verdict says so through this one method.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function regrade_soon( $post_id ) {
		Grades::mark_stale( $post_id );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Grades::CRON );
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
	 * One page's title, as every surface of this list says it.
	 *
	 * ⛔ THE ONE OWNER, for the same reason {@see counts()} is. Titles arrive from
	 * the database entity-encoded — `Php Dynamic Getter &#038; Setter` — and a
	 * caller that skips this says a different name for the same page than the row
	 * it was handed. Caught live on heera.it 2026-08-22: the set-aside tool
	 * answered with the raw title while `read-content-issues` answered decoded,
	 * so an agent reading a row and then acting on it got two names for post 1024.
	 * ⚠️ An agent does not render HTML — it repeats what it is given, into prose
	 * and sometimes back into a write.
	 *
	 * @param \WP_Post|int $post Post or ID.
	 * @return string Decoded, tag-free, never empty.
	 */
	public static function title_of( $post ) {
		$title = self::decode_title( get_the_title( $post ) );
		return '' !== trim( $title ) ? $title : __( '(untitled)', 'agentimus' );
	}

	/**
	 * The decode itself, without the (untitled) fallback.
	 *
	 * ⛔ SPLIT OUT BECAUSE THE FALLBACK IS NOT ALWAYS THE RIGHT ANSWER. Several
	 * callers already have a BETTER one than "(untitled)": Findings falls back to
	 * the page's URL path, and Rest to a translated "Post %d". Pointing those at
	 * title_of() would have silently killed that code — "(untitled)" is never
	 * empty, so their own `'' === $title` branch could never run again. They take
	 * this method; everything with no fallback of its own takes title_of().
	 *
	 * ⭐ ENT_HTML5 ALONGSIDE ENT_QUOTES, which is a real fix and not tidying:
	 * `&apos;` is an HTML5-only named entity, so under ENT_QUOTES alone
	 * `Tom&apos;s guide` decodes to the literal string `Tom&apos;s guide`.
	 * Verified: every other case in this codebase's titles decodes identically
	 * under both, and only `&apos;` differs. inc/Rest.php already used these
	 * flags — it was the most correct copy of the seven, and this adopts it
	 * rather than levelling everyone down to the owner's older pair.
	 * ⚠️ THIS IS THE SAME BUG CLASS AS 2026-08-22's: an agent does not render
	 * HTML, it repeats what it is handed — a leaked `&apos;` reaches prose and
	 * sometimes a write.
	 *
	 * ⛔ 'UTF-8' IS STATED, NEVER LEFT TO THE INI. Without it html_entity_decode()
	 * follows `default_charset`, which the host owns — and inc/Changes.php was
	 * doing exactly that, so the public change feed's titles depended on a php.ini
	 * this plugin does not control. PHP made UTF-8 the default only in 8.1 and the
	 * floor here is 7.4.
	 *
	 * @param mixed $raw A raw title, as the database hands it over.
	 * @return string Decoded and tag-free. May be empty — that is the caller's to answer.
	 */
	public static function decode_title( $raw ) {
		return html_entity_decode( wp_strip_all_tags( (string) $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The three exclusive bucket counts — fixable, clear, setAside — over the
	 * whole site.
	 *
	 * ⛔ THE ONE OWNER of this trio. Everything that quotes them comes here: the
	 * chips, `read-content-issues`, `tally()` below, and the set-aside ability's
	 * answer after a write. His law — two surfaces counting the same thing must
	 * read ONE count — and the way it gets broken is never a wrong sum, it is a
	 * second caller assembling the same numbers from its own idea of which post
	 * types and which parked ids are in scope. There is one idea of that, here.
	 *
	 * @return array{fixable:int,clear:int,setAside:int}
	 */
	public function counts() {
		return Grades::counts( $this->post_types(), $this->set_aside_ids() );
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
			$this->counts(),
			Grades::fixable_split( $types, $aside ),
			// ⚠️ The tally's honesty about itself, BOTH HALVES. `grading` is
			// content nobody has read; `rechecking` is content read under an
			// older set of checks and queued to be read again. Either one means
			// `fixable` is a count that will move, and a caller printing the
			// number without them is making the finished claim. ⛔ Both exclude
			// the set-aside, like every count they stand beside.
			array(
				// ⛔ `grading` stays over EVERYTHING, and honestly so: a page
				// nobody has read has no gradeable value to filter on, and the
				// line that prints it already says the number may move.
				'grading'    => Grades::remaining( $types ),
				// ⚠️ GRADEABLE ONLY — the same population `fixable` counts, on
				// the same law the score card already learned: "75 graded · 88
				// being read again" is two true numbers and an impossible pair.
				// {@see Grades::COVERED_SQL}
				'rechecking' => Grades::rechecking( $types, true, $aside ),
			)
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
					$by_demand = (int) $b['impressions'] <=> (int) $a['impressions'];
					if ( 0 !== $by_demand ) {
						return $by_demand;
					}
					// ⚠️ A TIE MUST BREAK THE SAME WAY EVERY TIME. `usort` is not
					// stable before PHP 8.0 — the floor this plugin supports — so
					// two searches on equal impressions could come back in either
					// order, and "the search this page is found for" would differ
					// between one read and the next. Small sites live on ties: a
					// page with two searches at 3 impressions each is ordinary.
					// (Ordered by the search itself, so the choice is reproducible
					// on any host and in any PHP version.)
					// The verdict is stored against whichever search won, so an
					// unstable winner means a stored answer to a question that
					// changes by itself.
					return strcmp( (string) $a['query'], (string) $b['query'] );
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
	public function page( $filter, $page = 1, $per = self::PER_PAGE, $issue = '' ) {
		$filter = in_array( $filter, array( 'fixable', 'clear', 'setAside' ), true ) ? $filter : 'fixable';
		// ⚠️ A missing or zero size means "use the normal page size", NOT "one
		// row". Clamping with max(1, …) turned an absent parameter into a
		// one-row page and an eight-row list into eight pages — which the tests
		// never saw, because every one of them passed a size explicitly.
		$per    = (int) $per > 0 ? min( self::MAX_ITEMS, (int) $per ) : self::PER_PAGE;
		$page   = max( 1, (int) $page );

		$types = $this->post_types();
		$aside = $this->set_aside_ids();
		// ⭐ A check id narrows the SAME query rather than fetching a bundle of
		// ids: the by-issue card can name sixty pages and this walks them twenty
		// at a time, ranked and paged like every other view of this list.
		// ⛔ Passed through UNVALIDATED on purpose. An id nothing flags matches
		// no rows, so a bad one empties the list — the safe direction. Dropping
		// it instead would widen the list back to the whole bucket while the
		// screen still said "sixty pages flagged X", which is the failure that
		// looks like success. The doors that take it from outside answer 400
		// for an id no check owns.
		$slice = Grades::page( $filter, $types, $aside, $page, $per, (string) $issue );

		$search = $this->search_by_post();
		// Both engines, read once for the whole page rather than once per row.
		$maps   = $this->engine_maps();
		// ⭐ Only to notice a verdict measured against another search
		// {@see age_if_question_moved()} — every ROW below is re-read live, so
		// this screen never SHOWS a stored verdict. What it does share with the
		// agent's list is the RANK and the tab counts, and those come from the
		// store: leaving the drift unnoticed here would rank the owner's list by
		// answers to questions the engines have since moved on from. One indexed
		// read for the whole page.
		$stored = Grades::stored( $slice['ids'] );
		$items  = array();
		foreach ( $slice['ids'] as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				list( , $best ) = $this->focus_choice( $post, isset( $search[ $id ] ) ? $search[ $id ] : array() );
				$this->age_if_question_moved( $post, $best, isset( $stored[ $id ]['hash'] ) ? $stored[ $id ]['hash'] : '' );
			}
			$item = $this->item( (int) $id, isset( $search[ $id ] ) ? $search[ $id ] : array(), in_array( (int) $id, $aside, true ) );
			if ( $item ) {
				$item['engines'] = $this->engine_blocks( (int) $id, (string) $item['url'], $maps );
				$items[]         = $item;
			}
		}

		$payload = array(
			'items'        => $items,
			'counts'       => $this->counts(),
			'filter'       => $filter,
			// ⚠️ WHAT THIS LIST IS, when it is not all of it. `counts` describes
			// the whole bucket and `total` describes these rows — while an issue
			// filter is on the two disagree ON PURPOSE, and this is the only
			// thing that explains the gap. Sixty rows under a chip reading 68
			// with nothing saying why is the same contradiction in a new place.
			'issue'        => (string) $issue,
			'issueLabel'   => '' === (string) $issue ? '' : PageCheck::issue_label( (string) $issue ),
			'page'         => $page,
			'per'          => $per,
			'total'        => (int) $slice['total'],
			// ⚠️ The screen's honesty about ITSELF. Until every page has been
			// graded, this list is ranked over part of the site — a different
			// claim from the finished one, and only this number can say which is
			// on screen.
			'grading'      => Grades::remaining( $types ),
			// …and the other half of it. Each ROW below is re-read to build this
			// payload, so its verdict is current; the RANK and the tab counts are
			// read from the store, which for a page edited since it was swept
			// still describes the earlier draft.
			// ⚠️ NOT SET-ASIDE, like every count beside it. A page the owner
			// excused is out of `counts` and out of the rows, so counting it as
			// "being read again" describes a population nothing else on this
			// payload measures — the arithmetic that read "75 graded · 86 being
			// read again" on his card, one screen along.
			'rechecking'   => Grades::rechecking( $types, false, $aside ),
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

	/**
	 * The same list, for something that cannot look at a screen.
	 *
	 * ⭐⭐ WHY THIS EXISTS. An agent connected over MCP could change a page but
	 * never find out which page needed changing: the findings tool said "36
	 * Posts and Pages are worth fixing" and handed back a UI anchor — no ids —
	 * while check-page needed a post id there was no way to obtain. This is the
	 * missing half. Same ranking, same three buckets, same counts as the screen
	 * {@see page()}, because a list that disagreed with the owner's would make
	 * the agent and the owner argue about a site neither could see whole.
	 *
	 * ⭐ It reads NO page. Every verdict here is one the sweep already measured
	 * and stored, so an agent can walk three hundred pages of content for the
	 * price of two indexed queries a page — where the screen renders its rows
	 * because it shows live detail (which words are missing, which passage
	 * answers the search). The detail hand-off is check-page, one page at a
	 * time, which is exactly the tool that had no way to be aimed.
	 *
	 * ⚠️ Because the verdicts are stored, each row says HOW OLD it is: `stale`
	 * marks a page saved since it was read. ⛔ Never present a stale row's
	 * issues as today's truth — re-read that one page instead.
	 *
	 * @param string $filter 'fixable' | 'clear' | 'setAside'.
	 * @param int    $page   1-based page number.
	 * @param int    $per    Rows per page.
	 * @return array
	 */
	public function issues( $filter, $page = 1, $per = self::PER_PAGE, $issue = '' ) {
		$filter = in_array( $filter, array( 'fixable', 'clear', 'setAside' ), true ) ? $filter : 'fixable';
		// Same clamp as the screen: absent means "the normal page size", never one row.
		$per    = (int) $per > 0 ? min( self::MAX_ITEMS, (int) $per ) : self::PER_PAGE;
		$page   = max( 1, (int) $page );

		$types  = $this->post_types();
		$aside  = $this->set_aside_ids();
		// ⭐ The same narrowing the owner's screen gets. An assistant told "60
		// pages flag Featured image not described" could only walk the whole
		// list and re-derive which sixty; now it asks for them.
		$slice  = Grades::page( $filter, $types, $aside, $page, $per, (string) $issue );
		$stored = Grades::stored( $slice['ids'] );
		$search = $this->search_by_post();
		$engine = $this->engine_label();

		// One query for the posts and one for their meta, rather than a pair per
		// row — the focus lives in post meta, so priming it is the difference
		// between two queries and forty.
		if ( $slice['ids'] && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $slice['ids'], false, true );
		}

		$items = array();
		foreach ( $slice['ids'] as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue; // Unpublished under us; the sweep will forget it.
			}
			// ⚠️ Missing means the store has no row at all for a page its own
			// ranking just handed back — impossible today (page() joins this
			// table) and treated as "unread" rather than "fine" if it ever
			// happens. An empty verdict must never print as a clean one.
			$g = isset( $stored[ $id ] ) ? $stored[ $id ] : array( 'stale' => true );
			list( $chosen, $best ) = $this->focus_choice( $post, isset( $search[ $id ] ) ? $search[ $id ] : array() );

			// ⛔⛔ THIS ROW MUST NOT CARRY TWO CLOCKS — the verdict is the sweep's,
			// the search beside it is the engines' as of a moment ago
			// {@see age_if_question_moved()}.
			if ( $this->age_if_question_moved( $post, $best, isset( $g['hash'] ) ? $g['hash'] : '' ) ) {
				$g['stale'] = true;
			}

			$type  = get_post_type_object( $post->post_type );
			$title = self::title_of( $post );

			$items[] = array(
				'id'        => (int) $post->ID,
				'title'     => $title,
				'postType'  => (string) $post->post_type,
				'typeLabel' => $type ? (string) $type->labels->singular_name : (string) $post->post_type,
				'url'       => (string) get_permalink( $post ),
				'editUrl'   => (string) get_edit_post_link( $post->ID, 'raw' ),
				'modified'  => (string) $post->post_modified_gmt,
				'needsWork' => ! empty( $g['needsWork'] ),
				// ⭐ ALL of them, never the screen's first three. A row that says
				// "and 2 more" is readable to a person who can click it open and
				// useless to something that has to act on the list it was given.
				'issues'    => self::issue_rows( isset( $g['flagIds'] ) ? (array) $g['flagIds'] : array() ),
				'points'    => isset( $g['points'] ) ? (int) $g['points'] : 0,
				'coverage'  => isset( $g['coverage'] ) ? (string) $g['coverage'] : '',
				'search'    => $best ? array(
					'query'       => (string) $best['query'],
					// Whether the AUTHOR chose this search or the engine reported it.
					'chosen'      => (bool) $chosen['chosen'],
					'engine'      => (bool) $chosen['chosen'] ? '' : $engine,
					'position'    => isset( $best['position'] ) ? round( (float) $best['position'], 1 ) : 0.0,
					'impressions' => isset( $best['impressions'] ) ? (int) $best['impressions'] : 0,
					'clicks'      => isset( $best['clicks'] ) ? (int) $best['clicks'] : 0,
				) : null,
				'stake'     => isset( $g['stake'] ) ? (int) $g['stake'] : 0,
				'setAside'  => in_array( (int) $post->ID, $aside, true ),
				'stale'     => ! empty( $g['stale'] ),
				'readAt'    => isset( $g['gradedAt'] ) ? (string) $g['gradedAt'] : '',
			);
		}

		return array(
			'items'        => $items,
			'filter'       => $filter,
			'page'         => $page,
			'per'          => $per,
			'total'        => (int) $slice['total'],
			'counts'       => $this->counts(),
			// ⚠️ WHAT THIS LIST IS, when it is not all of it. `counts` describes
			// the whole bucket and `total` describes the rows — the two disagree
			// on purpose while an issue filter is on, and this is the only thing
			// that explains the gap. A screen showing 60 rows under a chip
			// reading 68 with nothing saying why is the contradiction again.
			'issue'        => (string) $issue,
			'issueLabel'   => '' === (string) $issue ? '' : PageCheck::issue_label( (string) $issue ),
			'grading'      => Grades::remaining( $types ),
			// ⚠️ NOT SET-ASIDE, like every count beside it. A page the owner
			// excused is out of `counts` and out of the rows, so counting it as
			// "being read again" describes a population nothing else on this
			// payload measures — the arithmetic that read "75 graded · 86 being
			// read again" on his card, one screen along.
			'rechecking'   => Grades::rechecking( $types, false, $aside ),
			'noSearchData' => Grades::without_search( $types ),
			'engine'       => $engine,
			'types'        => array_values( $types ),
		);
	}

	/**
	 * Flagged check ids, each with the name of the problem it stands for.
	 *
	 * The id is what a caller acts on and the label is what it can say out loud;
	 * handing over one without the other makes something invent the other half.
	 *
	 * @param array<int,string> $ids Check ids.
	 * @return array<int,array{id:string,label:string}>
	 */
	private static function issue_rows( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$id = (string) $id;
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'label' => PageCheck::issue_label( $id ),
			);
		}
		return $out;
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

		// Keep the served-page answer current, from the one place that is allowed
		// to want an HTTP request. ⛔ It only QUEUES one — this run grades with
		// whatever is already stored, and a probe that has never run means the
		// featured-image check falls back to the claim needing no fetch.
		ThemeImageProbe::maybe_schedule();

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
	/**
	 * WHICH search this page is judged on, and the numbers reported against it.
	 *
	 * The author's own choice wins, so a row and the editor's panel can never
	 * disagree about what the page is for. Without a choice, the search that
	 * brings the most people stands in. A page may be for SEVERAL searches; a
	 * row judges one — the one the author put first {@see Focus::primary()} —
	 * and the editor is where the rest are measured.
	 *
	 * ⭐ Extracted so the rendered row and the render-free one the MCP
	 * content-issues tool builds cannot drift apart. Two surfaces naming
	 * different searches for one page would be the same species of fault as two
	 * surfaces counting the same work differently.
	 *
	 * @param \WP_Post $post The post.
	 * @param array    $rows That post's search rows, best first.
	 * @return array{0:array,1:array|null} The focus meta, and the winning row (null when there is none).
	 */
	private function focus_choice( $post, array $rows ) {
		$chosen          = Focus::for_post( $post );
		$chosen['all']   = $chosen['query'];
		$chosen['query'] = Focus::primary( $post );

		$best = null;
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

		return array( $chosen, $best );
	}

	/**
	 * Notice that a stored verdict answered a DIFFERENT question, and age it.
	 *
	 * ⭐⭐ A coverage verdict answers exactly one thing — does a passage of this
	 * page answer THIS search — and the engines move which search a page is
	 * found for without anybody touching the page. A list built from the store
	 * therefore printed the search the engines report NOW beside the answer to
	 * the one they reported at sweep time, and called it that search's verdict.
	 *
	 * Measured on a live site before this existed: of 32 rows whose search the
	 * engines choose, 8 disagreed with a fresh reading — against 0 of 14 whose
	 * focus the owner had chosen by hand. A query the owner picks cannot drift,
	 * and that asymmetry is the fault's own signature.
	 *
	 * ⭐ The question was already being recorded — {@see Grades::hash()} folds
	 * the focus in beside the title and the content — and nothing ever compared
	 * it back. It cannot be a test in Grades: SQL can compare a ruleset or spot
	 * an emptied fingerprint, but it cannot know which search a page is found
	 * for today. So the comparison lives here, where the stored answer and the
	 * live question are both in hand, and its result is written back the
	 * ordinary way so the queues can see it too.
	 *
	 * ⛔ AGED, NEVER DELETED. The last reading is still the last reading; only
	 * the fingerprint is cleared {@see Grades::mark_stale()}. Emptying the
	 * verdict would empty the owner's list for a reason that has nothing to do
	 * with their site.
	 *
	 * ⚠️ An UNKNOWN question is not a changed one. A row written before the
	 * store recorded one carries '' here, and reading that as "different" would
	 * mark every page on the site stale the moment somebody upgraded.
	 *
	 * The write is self-limiting: once the fingerprint is cleared, the next pass
	 * reads '' and does nothing until a real reading has replaced it.
	 *
	 * @param \WP_Post   $post  The post the verdict describes.
	 * @param array|null $best  The search it is found for now, or null for none.
	 * @param string     $known The fingerprint recorded with the stored verdict.
	 * @return bool True when the verdict was measured against another search.
	 */
	private function age_if_question_moved( $post, $best, $known ) {
		$known = (string) $known;
		if ( '' === $known ) {
			return false;
		}
		if ( Grades::hash( $post, $best ? (string) $best['query'] : '' ) === $known ) {
			return false;
		}
		Grades::mark_stale( (int) $post->ID );
		return true;
	}

	private function item( $id, array $rows, $aside, $grade = false ) {
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		$html  = $this->rendered( $post );
		$focus = null;
		$cov   = null;

		list( $chosen, $best ) = $this->focus_choice( $post, $rows );

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
		$title = self::title_of( $post );

		$out = array(
			'id'       => (int) $post->ID,
			// When this row was true of. A screen holding the list can ask which
			// posts have been edited since — one indexed query — instead of
			// re-reading thirty pages to discover that one changed.
			'modified' => (string) $post->post_modified_gmt,
			'title'    => $title,
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
			// ⭐ The ids behind those labels, WHOLE — never the three the row
			// shows. A screen narrowed to one check has to be able to tell that
			// a row no longer carries it, and testing the visible labels would
			// call a page fixed because its fourth flag was the one hidden
			// behind "+2 more".
			// ⚠️ From THIS render, not from the store: a page fixed a minute ago
			// is already clean here while the stored verdict still says
			// otherwise, which is exactly the moment the owner is looking.
			'flagIds'  => array_values( (array) $summary['ids'] ),
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
				// ⭐ Asked through the shared predicate, because the substance
				// check now reads the same answer: the column the score is
				// averaged over and the flag the worklist prints must not be
				// able to disagree about whether a page is an article.
				'gradeable' => Gradeability::is_graded_for_quoting( $post ),
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
