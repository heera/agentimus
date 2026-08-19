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

	/** @var array<int,string>|null Memoized dismissed ids. */
	private $dismissed = null;

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

	/** @var array|null Memoized opportunities report — three sources read it. */
	private $opportunities = null;

	/** @var array|null Memoized score report — read by never_measured() for the Cited rung. */
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
			'checking_off'    => 'checking_off',
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

		// ⛔ HIDDEN ONES COME OUT FIRST, and only the tier that may be hidden.
		// A dismissible urgent finding is a way to bury a live trust problem, so
		// LATER — "worth knowing, costs nothing today" — is the only tier this
		// applies to. That is not a convention, it is the definition of the tier.
		$hidden = array();
		$keep   = array();
		foreach ( $open as $row ) {
			if ( self::LATER === $row['tier'] && in_array( (string) $row['id'], $this->dismissed_ids(), true ) ) {
				$hidden[] = $row;
				continue;
			}
			$keep[] = $row;
		}
		$open  = $keep;
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
			// ⭐ NEVER A HIDDEN LEDGER. The rows the owner put away travel WITH
			// the ones they kept, so the screen can carry a visible count and
			// hand any of them back. The same rule the set-aside list already
			// follows: a plugin that quietly stops mentioning something has
			// stopped being trustworthy about everything else it does not
			// mention either.
			'hidden'   => array_values( $hidden ),
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
	 * The kinds of content this site checks, named the way a sentence needs them.
	 *
	 * ⭐ Read from the live scope, never written down. Any fixed phrase here is
	 * wrong on some site: "posts and pages" on a shop, "pages" the moment posts
	 * come back. Past three kinds it stops listing and says "pieces of content" —
	 * a sentence that names six content types has stopped being a sentence.
	 *
	 * Labels are used as the post type declares them (WordPress capitalises them),
	 * which is how the by-issue rows already print "3 Posts, 1 Page".
	 *
	 * @param int $n How many items — decides singular or plural.
	 * @return string
	 */
	private static function scope_noun( $n ) {
		$types = Content::check_post_types();
		if ( ! $types ) {
			return _n( 'piece of content', 'pieces of content', (int) $n, 'agentimus' );
		}
		if ( count( $types ) > 3 ) {
			return _n( 'piece of content', 'pieces of content', (int) $n, 'agentimus' );
		}

		$names = array();
		foreach ( $types as $type ) {
			$names[] = 1 === (int) $n ? Content::singular( $type ) : Content::label( $type );
		}

		// ⚠️ Two plugins can label a type identically — WooCommerce and FluentCart
		// both call theirs "Products" — and the sentence then stutters without
		// saying which is which. The vendor's name is added ONLY where the label
		// collides; on every other site this changes nothing.
		$counts = array_count_values( $names );
		foreach ( $names as $i => $name ) {
			if ( $counts[ $name ] > 1 ) {
				$source = Content::source( $types[ $i ] );
				if ( '' !== $source ) {
					$names[ $i ] = sprintf( '%1$s (%2$s)', $name, $source );
				}
			}
		}

		if ( 1 === count( $names ) ) {
			return $names[0];
		}
		$last = array_pop( $names );
		return sprintf(
			/* translators: 1: comma-separated list of content types, 2: the last one. */
			__( '%1$s and %2$s', 'agentimus' ),
			implode( ', ', $names ),
			$last
		);
	}

	/**
	 * Findings the owner has put away.
	 *
	 * @return array<int,string>
	 */
	private function dismissed_ids() {
		if ( null === $this->dismissed ) {
			$raw             = (array) $this->settings->get( 'findings_dismissed', array() );
			$this->dismissed = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', $raw ) ) ) );
		}
		return $this->dismissed;
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
	private function go( $label, $tab, $view = '', $anchor = '', array $pages = array(), $open = '' ) {
		$out = array( 'label' => $label, 'tab' => $tab, 'view' => $view, 'anchor' => $anchor );
		// ⭐ A door, not a direction. Some findings are answered in a dialog, and
		// scrolling somebody to the card that holds the control — then leaving
		// them to spot a 28px gear — is a button that stops one step short of
		// what it promised. `open` names the panel to open on arrival.
		if ( '' !== (string) $open ) {
			$out['open'] = (string) $open;
		}
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
	 * Memoized for the instance's lifetime, like readiness() and score_report():
	 * near_page_one(), seen_not_chosen() and split_searches() each read it during
	 * one all() pass, and Report::opportunities() re-shapes the whole stored
	 * snapshot per call — the same answer, paid for three times.
	 *
	 * @return array
	 */
	private function opportunities() {
		if ( null === $this->opportunities ) {
			$this->opportunities = (array) Search\Report::opportunities( $this->settings );
		}
		return $this->opportunities;
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
				// ⛔ NOT "none of them wins the click" — the card this row opens
				// crowns a winner, by clicks then position, and states the rule.
				// A front door contradicting the screen behind it teaches an
				// owner to trust neither.
				__( 'The engine shows one of them at a time, so they take turns — and the weaker turns cost clicks.', 'agentimus' ),
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
		// ⚠️ The scope is applied on the way OUT as well as on the way in. The
		// tracker only records pages in scope, but a page can go out of scope
		// after its baseline was taken — and a win announced for a page the owner
		// has since switched off is the same contradiction one step later.
		$checked = Content::check_post_types();
		foreach ( Search\Progress::resolved() as $win ) {
			$id    = (int) $win['page_id'];
			if ( $id > 0 ) {
				$type = get_post_type( $id );
				if ( $type && ! in_array( (string) $type, $checked, true ) ) {
					continue;
				}
			}
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
	 * The content worklist, summarised as one finding. The individual rows
	 * belong on Your Content; the front door only needs to say how much there
	 * is and what kind of work it is.
	 *
	 * ⭐ It counts the WORKLIST now, not a sample of its own. It used to read
	 * Score's optimize sample — the 25 most recently modified posts, graded on
	 * content flags only — while the Worth Fixing chip counted every published
	 * item and included pages that do not answer their search. On a site whose
	 * recent 25 are clean the front door therefore said "Nothing needs your
	 * attention right now" directly above a list of thirty-six rows.
	 *
	 * Both numbers were true of what they measured, which is exactly the
	 * problem: an owner cannot be asked to hold two populations in their head
	 * to reconcile one screen. One count, read by both.
	 *
	 * @return array<int,array>
	 */
	/**
	 * The owner has switched every content type off.
	 *
	 * ⚠️ This row exists because of what happens WITHOUT it. `content_issues()`
	 * reads the live scope, so with nothing checked it returns nothing and the
	 * worklist finding simply disappears — while the Readiness card next door
	 * goes on showing the same pages from the last reading, because the score is
	 * deliberately held at its last known value so switching checking off cannot
	 * raise it. Two surfaces, one subject, opposite answers.
	 *
	 * ⛔ It does NOT re-list the old findings here. A to-do list that cannot be
	 * refreshed is worse than an honest gap: the pages may have been fixed, and
	 * nothing is watching them. One row, saying the one true thing, with the way
	 * to undo it.
	 *
	 * @return array<int,array>
	 */
	private function checking_off() {
		// Only when the site HAS content it could check. A site with no eligible
		// content types is not a site that switched anything off.
		if ( ! Content::checkable() || Content::check_post_types() ) {
			return array();
		}

		return array(
			$this->row(
				'checking_off',
				self::LATER,
				__( 'Nothing about your content is being checked', 'agentimus' ),
				__( 'Every kind of content is switched off, so no page is being read.', 'agentimus' ),
				array( __( 'Your Content · nothing selected', 'agentimus' ) ),
				$this->go( __( 'Choose what to check', 'agentimus' ), 'findings', '', 'ar-work', array(), 'checkScope' ),
				array(
					__( 'Your grades are kept — switch a type back on and its rows come straight back.', 'agentimus' ),
					__( 'Until then, anything about your pages here is from the last reading.', 'agentimus' ),
				)
			),
		);
	}

	private function content_issues() {
		$tally = ( new Worklist( $this->settings ) )->tally();

		$n = (int) $tally['fixable'];
		if ( $n < 1 ) {
			return array();
		}

		// The bucket in its own terms, and only the halves that exist — a chip
		// reading "0 do not answer their search" teaches nothing and makes the
		// finding look padded.
		$evidence = array();
		if ( $tally['flagged'] > 0 ) {
			$evidence[] = sprintf(
				/* translators: %s: how many posts and pages have content issues to edit. */
				_n( '%s needs an edit', '%s need an edit', (int) $tally['flagged'], 'agentimus' ),
				number_format_i18n( (int) $tally['flagged'] )
			);
		}
		if ( $tally['unanswered'] > 0 ) {
			$evidence[] = sprintf(
				/* translators: %s: how many posts and pages do not answer the search they are found for. */
				_n( '%s does not answer its search', '%s do not answer their search', (int) $tally['unanswered'], 'agentimus' ),
				number_format_i18n( (int) $tally['unanswered'] )
			);
		}

		$points = array(
			__( 'Each one is a small edit, or a passage that answers the search.', 'agentimus' ),
			__( 'Set aside anything that is not meant to be quoted.', 'agentimus' ),
		);
		if ( $tally['grading'] > 0 ) {
			// ⚠️ Said out loud, in the finding's own voice: a count taken over
			// part of the site is a different claim from the finished one, and
			// the number WILL move when the sweep catches up.
			$points[] = sprintf(
				/* translators: %s: how many published items have not been read yet. */
				_n(
					'Still reading your content — %s item has not been looked at yet, so this number may move.',
					'Still reading your content — %s items have not been looked at yet, so this number may move.',
					(int) $tally['grading'],
					'agentimus'
				),
				number_format_i18n( (int) $tally['grading'] )
			);
		}

		return array(
			$this->row(
				'content_issues',
				self::WORTH,
				sprintf(
					// The chip on Your Content says "Worth Fixing" over this exact
					// number. Same words for the same count, deliberately: the two
					// screens disagreeing about how much work there is was the
					// whole fault.
					//
					// ⚠️ The NOUN is the checked scope, never the words "posts and
					// pages". That pair was hardcoded here, so a site that had
					// switched Posts off was told how many "posts and pages" needed
					// fixing over a count containing no posts at all — the same
					// fault as the all-clear on the Readiness card, in its twin.
					/* translators: 1: how many items are worth fixing, 2: the kinds of content checked, e.g. "Pages" or "Posts and Products". */
					_n( '%1$s %2$s is worth fixing', '%1$s %2$s are worth fixing', $n, 'agentimus' ),
					number_format_i18n( $n ),
					self::scope_noun( $n )
				),
				__( 'Either the page needs an edit, or it does not answer the search it is found for.', 'agentimus' ),
				$evidence,
				// No page handover. This finding counts the WHOLE list, and the
				// list's own Worth Fixing tab already holds exactly these rows —
				// handing over a subset would put a shorter list under a longer
				// number, which is the fault one level down.
				$this->go( __( 'Look at my content', 'agentimus' ), 'findings', '', 'ar-work' ),
				$points
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
			// 'off' sits with 'pass' here: an off row is a switched-off feature's
			// informational shadow, and the feature's own warn row is already in
			// this loop — one switch must never surface as two findings.
			if ( ! is_array( $check ) || ! isset( $check['status'] ) || 'pass' === $check['status'] || 'off' === $check['status'] ) {
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

		// ⛔ SWITCHED OFF IS AN ANSWER. Citation checks are opt-in, and this row
		// asked a site that had them off to go and set them up — for ever, with
		// no way to say no.
		//
		// ⚠️ This replaced two cleverer gates, and the simpler rule is the right
		// one. The first waited for the readiness rungs to be clear, on the
		// theory that "have you measured?" is premature on a half-configured
		// site. The second remembered the moment somebody switched the feature
		// off. Both missed the case that actually matters: an owner who turned it
		// off BEFORE either gate existed has no recorded transition and no
		// perfect readiness score, so both gates let the nag through — which is
		// every site that had already made the decision.
		//
		// Read the current value and the ambiguity stops mattering: whether they
		// declined or never looked, they are not asking for this. The feature
		// introduces itself on the Visibility screen, in onboarding and on the
		// About page — a front-door row is not what makes it discoverable, it is
		// only what makes it unavoidable.
		//
		// What is left is a finding that means exactly what its title says: the
		// checks are ON and no reading has ever been taken.
		if ( ! $this->settings->get( 'enable_visibility', false ) ) {
			return array();
		}

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
				__( 'You have never measured whether AI assistants cite you', 'agentimus' ),
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
				// An 'off' row is not a check that can pass — leaving it in the
				// total would silence "all N setup checks pass" on a site whose
				// only non-pass rows are switched-off features' shadows.
				if ( 'off' === $check['status'] ) {
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
					__( 'Nothing is blocking AI assistants — all %s setup checks pass.', 'agentimus' ),
					number_format_i18n( $total )
				);
			}
		} catch ( \Throwable $e ) {
			return $lines;
		}
		return $lines;
	}
}
