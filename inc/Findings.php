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
	);

	/** @var Settings */
	private $settings;

	/** @var array|null Memoized readiness report — several sources read it. */
	private $readiness = null;

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

		usort(
			$found,
			static function ( $a, $b ) {
				$wa = isset( $a['weight'] ) ? (int) $a['weight'] : 0;
				$wb = isset( $b['weight'] ) ? (int) $b['weight'] : 0;
				return $wb <=> $wa;
			}
		);
		$found = array_slice( $found, 0, self::MAX );

		$counts = array( self::URGENT => 0, self::WORTH => 0, self::LATER => 0 );
		foreach ( $found as $row ) {
			$tier = isset( $row['tier'] ) ? $row['tier'] : self::WORTH;
			if ( isset( $counts[ $tier ] ) ) {
				++$counts[ $tier ];
			}
		}

		$payload = array(
			'findings' => array_values( $found ),
			'clear'    => $this->clear_lines(),
			'failed'   => $failed,
			'counts'   => $counts,
		);

		/**
		 * The assembled front-door findings. Add a finding from an add-on, drop
		 * one you never want to see, or re-rank by rewriting `weight`.
		 *
		 * @param array    $payload  findings/clear/failed/counts.
		 * @param Settings $settings Plugin settings.
		 */
		$payload = apply_filters( self::FILTER, $payload, $this->settings );
		return is_array( $payload ) ? $payload : array( 'findings' => array(), 'clear' => array(), 'failed' => array(), 'counts' => $counts );
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

		$queries = 0;
		$shown   = 0;
		$clicks  = 0;
		foreach ( $pages as $page ) {
			foreach ( (array) ( isset( $page['queries'] ) ? $page['queries'] : array() ) as $q ) {
				++$queries;
				$shown  += (int) ( isset( $q['impressions'] ) ? $q['impressions'] : 0 );
				$clicks += (int) ( isset( $q['clicks'] ) ? $q['clicks'] : 0 );
			}
		}
		$n = count( $pages );

		return array(
			$this->row(
				'near_page_one',
				self::WORTH,
				sprintf(
					/* translators: %d: how many pages rank just below page one. */
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
				array(
					__( 'Use the words people typed in the title they see.', 'agentimus' ),
					__( 'Answer the question directly in one paragraph.', 'agentimus' ),
				)
			),
		);
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
		$n = count( $pages );

		$evidence = array();
		foreach ( $pages as $page ) {
			foreach ( (array) ( isset( $page['queries'] ) ? $page['queries'] : array() ) as $q ) {
				$evidence[] = sprintf( '“%s” · #%s', (string) $q['query'], number_format_i18n( (float) $q['position'], 1 ) );
				break;
			}
		}

		return array(
			$this->row(
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
				array(
					__( 'Nothing is wrong with the page itself.', 'agentimus' ),
					__( 'The title and description are what they read before deciding.', 'agentimus' ),
				)
			),
		);
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
		return (array) ( new Score( $this->settings ) )->report( $this->readiness() );
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
	private function config_gaps() {
		$out = array();
		foreach ( (array) $this->readiness() as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['status'] ) || 'pass' === $check['status'] ) {
				continue;
			}
			$why = '' !== (string) ( isset( $check['fix'] ) ? $check['fix'] : '' )
				? (string) $check['fix']
				: (string) ( isset( $check['detail'] ) ? $check['detail'] : '' );

			$action = null;
			if ( ! empty( $check['action']['label'] ) ) {
				$action = array(
					'label'  => (string) $check['action']['label'],
					'tab'    => isset( $check['action']['tab'] ) ? (string) $check['action']['tab'] : 'settings',
					'view'   => '',
					'anchor' => isset( $check['action']['anchor'] ) ? (string) $check['action']['anchor'] : '',
					'url'    => isset( $check['action']['url'] ) ? (string) $check['action']['url'] : '',
				);
			}

			$row          = $this->row(
				'config_gap',
				'fail' === $check['status'] ? self::URGENT : self::WORTH,
				(string) $check['label'],
				$why,
				array(),
				$action
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
