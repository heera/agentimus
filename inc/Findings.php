<?php
/**
 * Findings — every open question this plugin can answer about a site, merged
 * into one ranked list.
 *
 * The plugin grew a finding system per feature: readiness rows, the content
 * worklist, search opportunities, the citation measurement. Each is correct and
 * each lives on its own screen, so an owner had to visit five places and add
 * the results up in their head. Worse, the dashboard's headline score can read
 * "Excellent" — configuration really can be perfect — while four of those
 * systems each hold something unread.
 *
 * The review queue is deliberately NOT a source here (his call): the nav bell
 * already carries its live count and opens the queue itself, and a row on the
 * front door was the same information twice — each copy pointing at the other.
 *
 * This class does the adding up. One list, ranked by what a finding COSTS the
 * owner rather than by which subsystem produced it: a decision only they can
 * make outranks a chore, and a chore outranks something merely worth knowing.
 *
 * Two rules make it safe to put on the front door:
 *
 *  - Every source is wrapped. A subsystem that throws (a missing table, a
 *    provider mid-migration) costs its own finding and nothing else — the screen
 *    still renders the rest. A front door that can be blanked by one bad query
 *    is worse than no front door.
 *  - Nothing here fetches, schedules or writes. It reads what the other systems
 *    have already stored, so it is safe on any admin page load.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Findings {

	/** Filter tag on the assembled list. */
	const FILTER = 'agentimus_findings';

	/** Most findings returned; the tail is real but not worth a front door. */
	const MAX = 12;

	/** Evidence chips shown per finding before the rest are counted. */
	const MAX_EVIDENCE = 4;

	/** A decision only the owner can make, or a live trust problem. */
	const URGENT = 'urgent';

	/** Traffic or citability is measurably on the table. */
	const WORTH = 'worth';

	/** Worth knowing, costs nothing today. Rendered under its own divider. */
	const LATER = 'later';

	/**
	 * The owner's side is done; what happens next belongs to a search engine.
	 *
	 * This tier exists because a COUNT IN A NAV IS A PROMISE that doing the work
	 * makes it go down, and the search findings could not keep it: no edit
	 * clears "ranks 8th" — only a later report does, weeks on. So the badge said
	 * "one thing needs you" while the editor, on the very same page, said your
	 * side is done. Both were locally true and jointly a lie, and a counter you
	 * cannot work down is a counter you learn to ignore.
	 *
	 * Waiting findings are NOT hidden — they keep their row, their evidence and
	 * their date. They are simply not counted, because nobody can act on them.
	 */
	const WAITING = 'waiting';

	/**
	 * Sort weights. Explicit integers rather than an enum order, so the ranking
	 * is readable, arguable and testable in one place. Higher sorts first.
	 *
	 * The ordering claim: an impersonator is worse than a thin page because only
	 * the owner can judge it and it is happening now; a page one push from page
	 * one beats a site-wide chore because the traffic is already being earned and
	 * lost. Change these numbers to change the front door's opinion.
	 */
	const WEIGHTS = array(
		'config_gap'     => 90,
		'near_page_one'  => 70,
		'content_issues' => 60,
		'seen_not_chosen' => 50,
		'never_measured' => 20,
		// The waiting pair sort last among themselves; the tier, not the weight,
		// is what puts them in their own band at the foot of the screen.
		'near_page_one_waiting'   => 15,
		'seen_not_chosen_waiting' => 14,
	);

	/** @var Settings */
	private $settings;

	/** @var array|null Memoized readiness report — several sources read it. */
	private $readiness = null;

	/** @var array|null Memoized score report — read by content_issues() and the worklist finding. */
	private $score_report = null;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The whole front-door payload.
	 *
	 * `findings` is ranked, capped and tiered. `clear` is the passing side —
	 * stated out loud, because an empty list must read as "checked", not
	 * "broken". `failed` names any source that errored, so a missing finding is
	 * visible rather than silent.
	 *
	 * @return array{findings:array<int,array>,clear:array<int,string>,failed:array<int,string>,counts:array<string,int>}
	 */
	public function all() {
		$found  = array();
		$failed = array();

		$sources = array(
			'near_page_one'   => 'near_page_one',
			'seen_not_chosen' => 'seen_not_chosen',
			'split_searches'  => 'split_searches',
			'content_issues'  => 'content_issues',
			'config_gap'      => 'config_gaps',
			'never_measured'  => 'never_measured',
		);

		foreach ( $sources as $id => $method ) {
			try {
				$rows = $this->{$method}();
				foreach ( (array) $rows as $row ) {
					if ( is_array( $row ) && ! empty( $row['title'] ) ) {
						$found[] = $row;
					}
				}
			} catch ( \Throwable $e ) {
				// One broken subsystem costs its own finding, never the screen.
				$failed[] = $id;
			}
		}

		$by_weight = static function ( $a, $b ) {
			$wa = isset( $a['weight'] ) ? (int) $a['weight'] : 0;
			$wb = isset( $b['weight'] ) ? (int) $b['weight'] : 0;
			return $wb <=> $wa;
		};

		// The cap applies to WORK, not to status. A site with twelve open
		// findings would otherwise slice the waiting rows off the end — and the
		// waiting band is exactly what stops a done page from looking undone, so
		// losing it under load defeats the whole thing. Two rows at most reach
		// here anyway (one per search worklist), so the screen's ceiling is
		// still fixed no matter how many pages are involved.
		$waiting = array();
		$open    = array();
		foreach ( $found as $row ) {
			if ( self::WAITING === ( isset( $row['tier'] ) ? $row['tier'] : '' ) ) {
				$waiting[] = $row;
			} else {
				$open[] = $row;
			}
		}
		usort( $open, $by_weight );
		usort( $waiting, $by_weight );
		$found = array_merge( array_slice( $open, 0, self::MAX ), $waiting );

		$counts = array( self::URGENT => 0, self::WORTH => 0, self::LATER => 0, self::WAITING => 0 );
		foreach ( $found as $row ) {
			$tier = isset( $row['tier'] ) ? $row['tier'] : self::WORTH;
			if ( isset( $counts[ $tier ] ) ) {
				++$counts[ $tier ];
			}
		}

		// News, not findings — so it travels beside them rather than among them.
		// A resolution has no action, no tier and no rank; it is read once and
		// expires on its own ({@see Search\Progress::KEEP_DAYS}).
		$resolved = null;
		try {
			$resolved = $this->resolved();
		} catch ( \Throwable $e ) {
			$failed[] = 'resolved';
		}

		$payload = array(
			'findings' => array_values( $found ),
			'resolved' => $resolved,
			'clear'    => $this->clear_lines(),
			'failed'   => $failed,
			'counts'   => $counts,
		);

		/**
		 * The assembled front-door findings. Add a finding from an add-on, drop
		 * one you never want to see, or re-rank by rewriting `weight`.
		 *
		 * @param array    $payload  findings/resolved/clear/failed/counts.
		 * @param Settings $settings Plugin settings.
		 */
		$payload = apply_filters( self::FILTER, $payload, $this->settings );
		return is_array( $payload ) ? $payload : array( 'findings' => array(), 'resolved' => null, 'clear' => array(), 'failed' => array(), 'counts' => $counts );
	}

	/**
	 * Build one finding row.
	 *
	 * @param string $id       Stable id (also the WEIGHTS key).
	 * @param string $tier     URGENT | WORTH | LATER.
	 * @param string $title    The headline, written as a fact.
	 * @param string $why      ONE short line. Anything longer belongs in $points.
	 * @param array  $evidence Short strings the owner can check.
	 * @param array  $action   { label, tab, view?, anchor? } or null.
	 * @param array  $points   Separable facts, one clause each. A finding with
	 *                         three things to say must not say them as a
	 *                         paragraph: nobody reads a paragraph on a worklist,
	 *                         and the third fact is the one that gets skipped.
	 * @return array
	 */
	private function row( $id, $tier, $title, $why, array $evidence = array(), $action = null, array $points = array() ) {
		return array(
			'id'       => $id,
			'tier'     => $tier,
			'weight'   => isset( self::WEIGHTS[ $id ] ) ? self::WEIGHTS[ $id ] : 10,
			'title'    => $title,
			'why'      => $why,
			'points'   => array_values( array_filter( array_map( 'strval', $points ), 'strlen' ) ),
			'evidence' => self::clip_evidence( $evidence ),
			'action'   => $action,
		);
	}

	/**
	 * Trim the evidence chips, and SAY when trimming happened.
	 *
	 * A row headlined "5 clients" that then lists four names has quietly
	 * contradicted itself. The count is the claim; the chips are the proof, and
	 * proof that silently stops short reads as a miscount.
	 *
	 * @param array $evidence Short strings.
	 * @return array<int,string>
	 */
	private static function clip_evidence( array $evidence ) {
		$all  = array_values( array_map( 'strval', $evidence ) );
		$kept = array_slice( $all, 0, self::MAX_EVIDENCE );
		$rest = count( $all ) - count( $kept );
		if ( $rest > 0 ) {
			/* translators: %d: how many further items exist beyond the ones listed. */
			$kept[] = sprintf( __( '+%d more', 'agentimus' ), $rest );
		}
		return $kept;
	}

	/**
	 * Where a finding's button goes.
	 *
	 * The anchor matters as much as the tab: a button that says "Open the
	 * worklist" and lands on the top of a long screen has not opened anything —
	 * the owner still has to find the thing the finding was about.
	 *
	 * @param string $label  Button label.
	 * @param string $tab    Destination tab id.
	 * @param string $view   Optional sub-view (the Visibility screen's inner views).
	 * @param string $anchor Optional DOM id to scroll to on arrival.
	 * @return array
	 */
	private function go( $label, $tab, $view = '', $anchor = '', array $pages = array() ) {
		$out = array( 'label' => $label, 'tab' => $tab, 'view' => $view, 'anchor' => $anchor );
		// The pages this finding actually counted. A finding that says "4 pages"
		// and then hands over thirty rows has made the reader do the counting
		// again; with these, the list arrives showing exactly those four.
		if ( $pages ) {
			$out['pages'] = array_values( array_unique( array_map( 'intval', $pages ) ) );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 *  Sources
	 * ---------------------------------------------------------------------- */

	/**
	 * The search opportunity worklist, read once.
	 *
	 * @return array
	 */
	private function opportunities() {
		return (array) Search\Report::opportunities( $this->settings );
	}

	/**
	 * Pages ranking just off page one. The strongest "already earning, still
	 * losing" signal a site has.
	 *
	 * @return array<int,array>
	 */
	private function near_page_one() {
		$group = $this->opportunities();
		$pages = isset( $group['almostThere'] ) ? (array) $group['almostThere'] : array();
		if ( ! $pages ) {
			return array();
		}

		$split = $this->partition( $pages );
		$capped = $this->cap_note( isset( $group['counts']['almost'] ) ? $group['counts']['almost'] : 0, count( $pages ) );
		$out    = array();

		if ( $split['todo'] ) {
			$todo    = $split['todo'];
			$n       = count( $todo );
			$queries = 0;
			$shown   = 0;
			$clicks  = 0;
			foreach ( $todo as $page ) {
				foreach ( (array) ( isset( $page['queries'] ) ? $page['queries'] : array() ) as $q ) {
					++$queries;
					$shown  += (int) ( isset( $q['impressions'] ) ? $q['impressions'] : 0 );
					$clicks += (int) ( isset( $q['clicks'] ) ? $q['clicks'] : 0 );
				}
			}

			$out[] = $this->row(
				'near_page_one',
				self::WORTH,
				sprintf(
					/* translators: %d: how many pages rank just below page one and still need an edit. */
					_n( '%d page is one push from page one', '%d pages are one push from page one', $n, 'agentimus' ),
					$n
				),
				__( 'Already ranking for real searches, just below the first page.', 'agentimus' ),
				array(
					sprintf( /* translators: %d: number of searches. */ _n( '%d search', '%d searches', $queries, 'agentimus' ), $queries ),
					sprintf( /* translators: %s: formatted impression count. */ __( '%s shown', 'agentimus' ), number_format_i18n( $shown ) ),
					sprintf( /* translators: %s: formatted click count. */ __( '%s visits', 'agentimus' ), number_format_i18n( $clicks ) ),
				),
				// Lands on Search Opportunities, NOT the content worklist (his
				// catch on heera.it): the worklist honestly reports "answered ·
				// nothing else to fix" for a page whose only problem is rank —
				// a dead end wearing a promise. The Opportunities card is BUILT
				// from this finding: same pages, each with its searches, the
				// what-to-do, and the exact edit links.
				$this->go(
					sprintf(
						/* translators: %d: how many pages the finding counted. */
						_n( 'Show me that page', 'Show me those %d pages', $n, 'agentimus' ),
						$n
					),
					'visibility',
					'performance',
					'ar-group-search'
				),
				array_merge(
					array(
						__( 'Use the words people typed in the title they see.', 'agentimus' ),
						__( 'Answer the question directly in one paragraph.', 'agentimus' ),
					),
					$capped
				)
			);
		}

		if ( $split['waiting'] ) {
			$out[] = $this->waiting_row(
				'near_page_one_waiting',
				Search\Opportunities::KIND_NEAR,
				(string) ( isset( $group['source'] ) ? $group['source'] : '' ),
				$split['waiting'],
				sprintf(
					/* translators: %d: how many pages are finished and awaiting a report. */
					_n(
						'%d page is waiting on Google',
						'%d pages are waiting on Google',
						count( $split['waiting'] ),
						'agentimus'
					),
					count( $split['waiting'] )
				),
				__( 'Answered, titled and linked — there is no edit left to make.', 'agentimus' ),
				array_merge(
					array(
						__( 'The next report decides the rank, not another edit.', 'agentimus' ),
						__( 'You will be told here when it moves.', 'agentimus' ),
					),
					$capped
				)
			);
		}

		return $out;
	}

	/**
	 * Searches that several pages are splitting. The engine sends one search
	 * to one page at a time, so competing pages take turns — and every turn a
	 * weaker page takes is a click the strong one loses. One row per split,
	 * heaviest first, capped so the list names the worst rather than all.
	 *
	 * @return array<int,array>
	 */
	private function split_searches() {
		$group      = $this->opportunities();
		$collisions = isset( $group['collisions'] ) ? (array) $group['collisions'] : array();
		if ( ! $collisions ) {
			return array();
		}

		$out = array();
		foreach ( array_slice( $collisions, 0, 3 ) as $i => $collision ) {
			$n     = count( (array) $collision['pages'] );
			$out[] = $this->row(
				'split_search_' . $i,
				self::WORTH,
				sprintf(
					/* translators: %d: how many pages appear for the same search. */
					__( '%d pages are splitting one search', 'agentimus' ),
					$n
				),
				__( 'They all appear for the same search — none of them wins the click.', 'agentimus' ),
				array(
					sprintf(
						/* translators: 1: the search query, 2: formatted impression count, 3: formatted click count. */
						__( '"%1$s" · %2$s shown · %3$s clicks', 'agentimus' ),
						(string) $collision['query'],
						number_format_i18n( (int) $collision['shown'] ),
						number_format_i18n( (int) $collision['clicks'] )
					),
					sprintf(
						/* translators: 1: best average position, 2: worst average position. */
						__( 'best position #%1$s · worst #%2$s', 'agentimus' ),
						number_format_i18n( $collision['best'], 1 ),
						number_format_i18n( $collision['worst'], 1 )
					),
				),
				$this->go(
					sprintf(
						/* translators: %d: how many pages compete for the search. */
						__( 'Show me those %d pages', 'agentimus' ),
						$n
					),
					'visibility',
					'performance',
					'ar-collisions'
				),
				array(
					__( 'The clicks divide, so each ranks lower than one page would.', 'agentimus' ),
					__( 'Keep one page as the answer; point the others at it, or set them apart.', 'agentimus' ),
				)
			);
		}
		return $out;
	}

	/**
	 * Pages already on page one that people scroll past — judged against this
	 * site's own median click rate, never an industry benchmark.
	 *
	 * @return array<int,array>
	 */
	private function seen_not_chosen() {
		$group = $this->opportunities();
		$pages = isset( $group['seenNotClicked'] ) ? (array) $group['seenNotClicked'] : array();
		if ( ! $pages ) {
			return array();
		}

		$split = $this->partition( $pages );
		$capped = $this->cap_note( isset( $group['counts']['seen'] ) ? $group['counts']['seen'] : 0, count( $pages ) );
		$out    = array();

		if ( $split['todo'] ) {
			$n        = count( $split['todo'] );
			$evidence = array();
			foreach ( $split['todo'] as $page ) {
				foreach ( (array) ( isset( $page['queries'] ) ? $page['queries'] : array() ) as $q ) {
					$evidence[] = sprintf( '“%s” · #%s', (string) $q['query'], number_format_i18n( (float) $q['position'], 1 ) );
					break;
				}
			}

			$out[] = $this->row(
				'seen_not_chosen',
				self::WORTH,
				sprintf(
					/* translators: %d: how many page-one pages are rarely clicked. */
					_n( '%d page is seen and not chosen', '%d pages are seen and not chosen', $n, 'agentimus' ),
					$n
				),
				__( 'On page one, and people scroll past.', 'agentimus' ),
				$evidence,
				// Same landing as near_page_one, same reason: the fix for a
				// passed-over page-one result is its title and description,
				// and the card holding those instructions is Opportunities.
				$this->go(
					sprintf(
						/* translators: %d: how many pages the finding counted. */
						_n( 'Show me that page', 'Show me those %d pages', $n, 'agentimus' ),
						$n
					),
					'visibility',
					'performance',
					'ar-group-search'
				),
				array_merge(
					array(
						__( 'Nothing is wrong with the page itself.', 'agentimus' ),
						__( 'The title and description are what they read before deciding.', 'agentimus' ),
					),
					$capped
				)
			);
		}

		if ( $split['waiting'] ) {
			$out[] = $this->waiting_row(
				'seen_not_chosen_waiting',
				Search\Opportunities::KIND_SEEN,
				(string) ( isset( $group['source'] ) ? $group['source'] : '' ),
				$split['waiting'],
				sprintf(
					/* translators: %d: how many rewritten pages await a report. */
					_n(
						'%d page is waiting on searchers',
						'%d pages are waiting on searchers',
						count( $split['waiting'] ),
						'agentimus'
					),
					count( $split['waiting'] )
				),
				__( 'The title and description are both written — the pair they read before deciding.', 'agentimus' ),
				array_merge(
					array(
						__( 'Whether people click is theirs to decide, not yours.', 'agentimus' ),
						__( 'You will be told here when the click rate moves.', 'agentimus' ),
					),
					$capped
				)
			);
		}

		return $out;
	}

	/**
	 * Split one worklist group into the pages that still need an edit and the
	 * pages whose owner-side work is finished.
	 *
	 * The test is {@see \Agentimus\Focus}'s, not a second opinion written here —
	 * the editor panel tells an owner "your side is done" about a specific page,
	 * and the front door has to agree with it about that same page or one of
	 * them is lying.
	 *
	 * A page with no post behind it (a homepage, an archive) can never be judged
	 * done: there is no content to measure and no title field to read. It stays
	 * on the actionable side, because "we cannot tell" must never be shown as
	 * "nothing left to do".
	 *
	 * @param array  $pages Wire cards from {@see Search\Report::opportunities()}.
	 * @param string $kind  Search\Opportunities::KIND_NEAR or ::KIND_SEEN.
	 * @return array{todo:array<int,array>,waiting:array<int,array>}
	 */
	private function partition( array $pages ) {
		// The verdict rides on the card already — Search\Report marks it when it
		// shapes the worklist, so the agent's copy and this one cannot disagree.
		// Asking Standing a second time here would be the same question, answered
		// twice, with two chances to drift.
		$todo    = array();
		$waiting = array();
		foreach ( $pages as $page ) {
			if ( ! empty( $page['waiting'] ) ) {
				$waiting[] = $page;
			} else {
				$todo[] = $page;
			}
		}
		return array( 'todo' => $todo, 'waiting' => $waiting );
	}

	/**
	 * The clause a CAPPED worklist owes its reader.
	 *
	 * The opportunities report ships the six biggest pages per group and states
	 * the true total separately. Without this line a site with sixty qualifying
	 * pages is told "6 pages are one push from page one" — a number that is not
	 * wrong so much as silently partial, and the reader has no way to know the
	 * difference. A cap that does not announce itself reads as completeness.
	 *
	 * @param int $total  How many pages really qualify.
	 * @param int $judged How many the report actually sent.
	 * @return array<int,string> One point, or none when nothing was hidden.
	 */
	private function cap_note( $total, $judged ) {
		if ( (int) $total <= (int) $judged ) {
			return array();
		}
		return array(
			sprintf(
				/* translators: 1: pages judged, 2: pages that qualify in total. */
				__( 'Judged on the %1$s biggest of %2$s pages that qualify.', 'agentimus' ),
				number_format_i18n( (int) $judged ),
				number_format_i18n( (int) $total )
			),
		);
	}

	/**
	 * One waiting row: same shape as any finding, minus the promise of work.
	 *
	 * It keeps an action — the owner should still be able to SEE the page it is
	 * about — but the label says look, not fix. Handing someone a button marked
	 * "Show me those 2 pages" for work that does not exist is the contradiction
	 * this tier was built to remove, one level down.
	 *
	 * @param string $id     Row id (also the WEIGHTS key).
	 * @param string $kind   Search\Opportunities::KIND_NEAR or ::KIND_SEEN.
	 * @param string $source Which engine's report these pages are waiting on —
	 *                       passed in, because the group this row was built from
	 *                       already resolved it. A row builder that re-resolves
	 *                       the active source can answer differently from the
	 *                       list it belongs to.
	 * @param array  $pages  The finished cards.
	 * @param string $title  Headline.
	 * @param string $why    One line.
	 * @param array  $points Separable facts.
	 * @return array
	 */
	private function waiting_row( $id, $kind, $source, array $pages, $title, $why, array $points ) {
		$evidence = array();
		foreach ( $pages as $page ) {
			$page_id = (int) ( isset( $page['postId'] ) ? $page['postId'] : 0 );
			$name    = (string) ( isset( $page['title'] ) ? $page['title'] : '' );
			$since   = '' !== $source
				? Search\Progress::since( $source, $kind, $page_id, (string) ( isset( $page['url'] ) ? $page['url'] : '' ) )
				: 0;
			// The date is only shown when a poll actually recorded one. A page
			// that has been waiting since before this ledger existed has no
			// honest "since", and inventing today's date would restart a clock
			// that has been running for weeks.
			$evidence[] = $since > 0
				? sprintf(
					/* translators: 1: page title, 2: the date it joined the worklist. */
					__( '%1$s · since %2$s', 'agentimus' ),
					$name,
					date_i18n( (string) get_option( 'date_format' ), $since )
				)
				: $name;
		}

		$row = $this->row(
			$id,
			self::WAITING,
			$title,
			$why,
			$evidence,
			// No page list. `pages` sets a filter on the CONTENT WORKLIST, and this
			// button opens the Opportunities card instead — so the ids narrowed a
			// list the owner never asked to see, and the filter was still there
			// when they came back to Findings, showing one row saying "nothing
			// else to fix". Only a finding that actually lands on the worklist
			// hands it pages.
			$this->go(
				_n( 'Look at that page', 'Look at those pages', count( $pages ), 'agentimus' ),
				'visibility',
				'performance',
				'ar-group-search'
			),
			$points
		);
		return $row;
	}

	/**
	 * The engine's own good news, as ONE row.
	 *
	 * Read straight from the ledger and never recomputed here: a resolution is a
	 * fact about two polls, and the only place that can compare them is the one
	 * that saw both ({@see Search\Progress}).
	 *
	 * ONE row, whatever the site's size — the boundary law. A busy site can
	 * resolve a dozen pages in a week, and a dozen green lines stacked above the
	 * work would push the actual findings off the first screen: the reward for
	 * doing well would be losing sight of what is left. So a single win keeps its
	 * whole sentence, and several become a count with the moves as evidence,
	 * clipped by the same rule every other finding's evidence follows.
	 *
	 * @return array|null The row, or null when there is no news.
	 */
	private function resolved() {
		$wins = array();
		foreach ( Search\Progress::resolved() as $win ) {
			$id    = (int) $win['page_id'];
			$title = $id > 0
				? html_entity_decode( wp_strip_all_tags( (string) get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' )
				: (string) wp_parse_url( (string) $win['page_url'], PHP_URL_PATH );
			if ( '' === trim( $title ) ) {
				continue; // A win we cannot name is not a win anyone can read.
			}
			$win['name'] = $title;
			$wins[]      = $win;
		}
		if ( ! $wins ) {
			return null;
		}

		// One win says the whole thing. Nothing is gained by turning a single
		// sentence into a headline plus one chip that repeats it.
		if ( 1 === count( $wins ) ) {
			return array(
				'id'       => 'resolved',
				'title'    => self::win_sentence( $wins[0] ),
				'evidence' => array(),
				'at'       => (int) $wins[0]['at'],
			);
		}

		$moves  = array();
		$newest = 0;
		foreach ( $wins as $win ) {
			$moves[] = sprintf( '%s · %s', self::clip_name( $win['name'] ), self::win_move( $win ) );
			$newest  = max( $newest, (int) $win['at'] );
		}

		return array(
			'id'       => 'resolved',
			'title'    => sprintf(
				/* translators: %s: how many pages improved. */
				__( '%s pages improved', 'agentimus' ),
				number_format_i18n( count( $wins ) )
			),
			'evidence' => self::clip_evidence( $moves ),
			'at'       => $newest,
		);
	}

	/**
	 * One win as a whole sentence, for the case where it is the only news.
	 *
	 * @param array $win A ledger resolution, with `name` resolved.
	 * @return string
	 */
	private static function win_sentence( array $win ) {
		if ( Search\Opportunities::KIND_SEEN === (string) $win['kind'] ) {
			return sprintf(
				/* translators: 1: page title, 2: new click rate, 3: previous click rate. */
				__( '%1$s is being clicked now — %2$s%% of the time, up from %3$s%%.', 'agentimus' ),
				$win['name'],
				number_format_i18n( (float) $win['to'], 1 ),
				number_format_i18n( (float) $win['from'], 1 )
			);
		}
		return sprintf(
			/* translators: 1: page title, 2: new place in results, 3: previous place. */
			__( '%1$s is %2$s in results now — it was %3$s. That is page one.', 'agentimus' ),
			$win['name'],
			Focus::ordinal( (int) max( 1, round( (float) $win['to'] ) ) ),
			Focus::ordinal( (int) max( 1, round( (float) $win['from'] ) ) )
		);
	}

	/**
	 * The same win as a bare move, for a chip beside its page's name.
	 *
	 * @param array $win A ledger resolution.
	 * @return string
	 */
	private static function win_move( array $win ) {
		if ( Search\Opportunities::KIND_SEEN === (string) $win['kind'] ) {
			return sprintf(
				'%1$s%% → %2$s%%',
				number_format_i18n( (float) $win['from'], 1 ),
				number_format_i18n( (float) $win['to'], 1 )
			);
		}
		return sprintf(
			'%1$s → %2$s',
			Focus::ordinal( (int) max( 1, round( (float) $win['from'] ) ) ),
			Focus::ordinal( (int) max( 1, round( (float) $win['to'] ) ) )
		);
	}

	/**
	 * A page title short enough to sit in a chip.
	 *
	 * Post titles have no length limit, and one long one turns a row of chips into
	 * a paragraph — the boundary law fails by the WIDTH of the data as readily as
	 * by its count.
	 *
	 * @param string $name Page title.
	 * @return string
	 */
	private static function clip_name( $name ) {
		$name = trim( (string) $name );
		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $name, 0, 40, '…' ) : $name;
	}

	/**
	 * The readiness report, computed once per request.
	 *
	 * @return array
	 */
	private function readiness() {
		if ( null === $this->readiness ) {
			$this->readiness = (array) ( new Readiness( $this->settings ) )->report();
		}
		return $this->readiness;
	}

	/**
	 * The score report, which carries the content worklist.
	 *
	 * @return array
	 */
	private function score_report() {
		if ( null === $this->score_report ) {
			$this->score_report = (array) ( new Score( $this->settings ) )->report( $this->readiness() );
		}
		return $this->score_report;
	}

	/**
	 * Per-page content issues, summarised as one finding. The individual issues
	 * belong on the page worklist; the front door only needs to say how much
	 * there is and which kind dominates.
	 *
	 * @return array<int,array>
	 */
	private function content_issues() {
		$report = $this->score_report();
		$issues = array();
		foreach ( (array) ( isset( $report['content'] ) ? $report['content'] : array() ) as $issue ) {
			$label = isset( $issue['label'] ) ? (string) $issue['label'] : '';
			$count = (int) ( isset( $issue['count'] ) ? $issue['count'] : 0 );
			if ( '' !== $label && $count > 0 ) {
				$issues[ $label ] = $count;
			}
		}
		if ( ! $issues ) {
			return array();
		}
		arsort( $issues );

		$kinds  = count( $issues );
		$graded = (int) ( isset( $report['graded'] ) ? $report['graded'] : 0 );
		$pages  = max( $issues );

		// Every post named by any issue, deduplicated — a page with three
		// problems is still one page to open, and the button now lands on a
		// list of pages rather than a list of issue kinds.
		$affected = array();
		foreach ( (array) ( isset( $report['content'] ) ? $report['content'] : array() ) as $issue ) {
			// Each row carries `pages` as objects, not bare ids — the Optimize
			// card renders their titles and edit links.
			foreach ( (array) ( isset( $issue['pages'] ) ? $issue['pages'] : array() ) as $page ) {
				$affected[] = (int) ( is_array( $page ) ? ( isset( $page['id'] ) ? $page['id'] : 0 ) : $page );
			}
		}
		$affected = array_values( array_unique( array_filter( $affected ) ) );

		$evidence = array();
		$i        = 0;
		foreach ( $issues as $label => $count ) {
			if ( $i++ >= 3 ) {
				break;
			}
			$evidence[] = sprintf( '%s · %s', $label, number_format_i18n( $count ) );
		}
		if ( $kinds > 3 ) {
			/* translators: %d: how many further issue kinds exist beyond those listed. */
			$evidence[] = sprintf( __( '+%d more', 'agentimus' ), $kinds - 3 );
		}

		return array(
			$this->row(
				'content_issues',
				self::WORTH,
				$graded > 0
					? sprintf(
						// "Pieces", not "pages": the graded sample is posts, pages and
						// anything else published — a blog whose 19 graded items are
						// all posts must not be told about "19 pages".
						/* translators: 1: pieces with the most common issue, 2: pieces graded. */
						_n( 'Up to %1$s of your %2$s graded pieces has something worth fixing', 'Up to %1$s of your %2$s graded pieces have something worth fixing', $pages, 'agentimus' ),
						number_format_i18n( $pages ),
						number_format_i18n( $graded )
					)
					: sprintf(
						/* translators: %s: pieces affected by the most common issue. */
						_n( '%s piece has something worth fixing', '%s pieces have something worth fixing', $pages, 'agentimus' ),
						number_format_i18n( $pages )
					),
				sprintf(
					// This line grounds the headline's "pieces" in plain words.
					/* translators: %d: how many distinct kinds of content issue were found. */
					_n( 'One kind of issue, across your recent posts and pages.', '%d kinds of issue, across your recent posts and pages.', $kinds, 'agentimus' ),
					$kinds
				),
				$evidence,
				$this->go(
					$affected
						? sprintf(
							/* translators: %d: how many pages have an issue. */
							_n( 'Show me that page', 'Show me those %d pages', count( $affected ), 'agentimus' ),
							count( $affected )
						)
						: __( 'See the issues', 'agentimus' ),
					$affected ? 'findings' : 'readiness',
					'',
					$affected ? 'ar-work' : 'ar-group-optimized',
					$affected
				),
				array(
					__( 'Each one is a small edit in the post editor.', 'agentimus' ),
					__( 'Set aside anything that is not meant to be quoted.', 'agentimus' ),
				)
			),
		);
	}

	/**
	 * Configuration checks that are failing. One finding per failing check, so
	 * each keeps its own fix and its own jump-to-the-field action — these are the
	 * findings with an exact remedy, and flattening them would lose it.
	 *
	 * @return array<int,array>
	 */
	/**
	 * A readiness check's call-to-action, translated into a finding's.
	 *
	 * The two subsystems name the same field differently — Readiness calls an
	 * outward destination `href` (its own panel renders `c.action.href`), and a
	 * finding's action carries `url`, which is what the front door opens in a new
	 * tab. Reading only `url` here meant all fifteen `link()` checks arrived with
	 * no destination and fell through to the `tab` default: "View llms.txt" put
	 * you on Settings. `href` wins, `url` is accepted, and a check that has a
	 * destination is not given a tab it never asked for.
	 *
	 * @param array|null $action The check's action, if it has one.
	 * @return array|null A finding action, or null when there is nothing to do.
	 */
	public static function check_action( $action ) {
		if ( ! is_array( $action ) || empty( $action['label'] ) ) {
			return null;
		}
		$url = '';
		foreach ( array( 'href', 'url' ) as $key ) {
			if ( '' === $url && ! empty( $action[ $key ] ) ) {
				$url = (string) $action[ $key ];
			}
		}
		return array(
			'label'  => (string) $action['label'],
			'tab'    => '' !== $url ? '' : ( isset( $action['tab'] ) ? (string) $action['tab'] : 'settings' ),
			'view'   => '',
			'anchor' => isset( $action['anchor'] ) ? (string) $action['anchor'] : '',
			'url'    => $url,
		);
	}

	private function config_gaps() {
		$out = array();
		foreach ( (array) $this->readiness() as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['status'] ) || 'pass' === $check['status'] ) {
				continue;
			}
			$why = '' !== (string) ( isset( $check['fix'] ) ? $check['fix'] : '' )
				? (string) $check['fix']
				: (string) ( isset( $check['detail'] ) ? $check['detail'] : '' );

			$row          = $this->row(
				'config_gap',
				'fail' === $check['status'] ? self::URGENT : self::WORTH,
				(string) $check['label'],
				$why,
				array(),
				self::check_action( isset( $check['action'] ) ? $check['action'] : null )
			);
			$row['check'] = (string) $check['id'];
			// A hard failure outranks a warning within the config band.
			$row['weight'] = self::WEIGHTS['config_gap'] - ( 'fail' === $check['status'] ? 0 : 5 );
			$out[]         = $row;
		}
		return $out;
	}

	/**
	 * Citation measurement never run. Everything else this plugin reports is
	 * about being ready to be cited; this is the only reading that says whether
	 * it worked, so its absence is worth one quiet line.
	 *
	 * @return array<int,array>
	 */
	private function never_measured() {
		$report = $this->score_report();

		// The Cited rung is only BUILT when citation tracking is switched on, so
		// "no rung" and "rung with no score" are both "never measured" — and the
		// first is the more common one. Keying off the rung's presence made this
		// finding disappear in exactly the case it exists to describe.
		foreach ( (array) ( isset( $report['rungs'] ) ? $report['rungs'] : array() ) as $rung ) {
			if ( is_array( $rung )
				&& 'cited' === ( isset( $rung['key'] ) ? $rung['key'] : '' )
				&& null !== ( isset( $rung['score'] ) ? $rung['score'] : null )
			) {
				return array(); // Measured. Nothing to say.
			}
		}

		return array(
			$this->row(
				'never_measured',
				self::LATER,
				__( 'You have never measured whether AI engines cite you', 'agentimus' ),
				__( 'The one reading that says whether any of this worked.', 'agentimus' ),
				array( __( 'Cited · no reading yet', 'agentimus' ) ),
				$this->go( __( 'Set up citation checks', 'agentimus' ), 'visibility', 'settings' ),
				array(
					__( 'Everything else here is about being READY to be cited.', 'agentimus' ),
					__( 'Runs on your own AI keys — nothing is sent anywhere else.', 'agentimus' ),
				)
			),
		);
	}

	/**
	 * What is demonstrably fine, said out loud. Silence is not reassurance: a
	 * screen with no findings must read as "checked", not "not working".
	 *
	 * @return array<int,string>
	 */
	private function clear_lines() {
		$lines = array();
		try {
			$pass  = 0;
			$total = 0;
			foreach ( (array) $this->readiness() as $check ) {
				if ( ! is_array( $check ) || ! isset( $check['status'] ) ) {
					continue;
				}
				++$total;
				if ( 'pass' === $check['status'] ) {
					++$pass;
				}
			}
			if ( $total > 0 && $pass === $total ) {
				$lines[] = sprintf(
					/* translators: %s: number of configuration checks, all passing. */
					__( 'Nothing is blocking agents — all %s setup checks pass.', 'agentimus' ),
					number_format_i18n( $total )
				);
			}
		} catch ( \Throwable $e ) {
			return $lines;
		}
		return $lines;
	}
}
