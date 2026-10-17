<?php
/**
 * Visits — the request log read as visits instead of rows.
 *
 * A visit is every request one visitor made with no more than {@see GAP} seconds
 * between one and the next. "One visitor" is the same client name, network and
 * user-agent: the log keeps no addresses, so two people asking the same assistant
 * at the same moment from the same network fold into one visit. The screen says so.
 *
 * Rows arrive newest first. A row joins its visitor's open visit when it is within
 * the gap of that visit's oldest row so far; otherwise it starts a new visit (the
 * new visit's newest row is its HEAD). The definition has no direction, so a visit
 * comes out the same wherever a page boundary falls.
 *
 * ⭐ A PAGE NEVER SPLITS A VISIT. Once the page has its visits it keeps reading until
 * every one of them is closed — the scan has passed its oldest row by more than the
 * gap — and the next page starts at the first visit it did not take. Rows of this
 * page's visits that lie below that point are recognised on the next page by
 * {@see seed()}: the caller hands back the rows just above the cursor, and any row
 * that chains onto them belongs to a visit already shown.
 *
 * Counting mode ($keep = false) remembers only where each visit starts, so one
 * pass over the whole filtered log can number the pages without holding it.
 *
 * Pure — no database. {@see Repository::visits()} feeds it.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

defined( 'ABSPATH' ) || exit;

final class Visits {

	/** Seconds of silence that end a visit. */
	const GAP = 120;

	/** Requests a visit carries in full; the rest are counted, not listed. */
	const ROW_CAP = 50;

	/** @var int Visits this page takes. */
	private $limit;

	/** @var array<int,array> The page's visits, newest head first. */
	private $visits = array();

	/**
	 * Each visitor's open visit: its index in $visits (null when it belongs to an
	 * earlier page) and the time of its oldest row so far.
	 *
	 * @var array<string,array{index:?int,oldest:int}>
	 */
	private $open = array();

	/** @var int|null Id of the first visit this page did not take. */
	private $next = null;

	/** @var bool Whether visits keep their rows, or only their head's id. */
	private $keep;

	/**
	 * @param int  $limit Visits per page.
	 * @param bool $keep  False to count: each visit keeps only its head's id.
	 */
	public function __construct( $limit, $keep = true ) {
		$this->limit = max( 1, (int) $limit );
		$this->keep  = (bool) $keep;
	}

	/**
	 * Who made a request, as far as the log can tell.
	 *
	 * @param array $row Raw log row.
	 * @return string
	 */
	public static function visitor( array $row ) {
		return $row['agent'] . "\n" . $row['network'] . "\n" . $row['ua'];
	}

	/**
	 * Teach the page the visits an earlier page already showed: a row just above
	 * the cursor. Call with those rows newest first, before any {@see add()}.
	 *
	 * @param array $row Raw log row.
	 * @return void
	 */
	public function seed( array $row ) {
		$key = self::visitor( $row );
		$at  = self::time( $row );
		if ( isset( $this->open[ $key ] ) && $this->open[ $key ]['oldest'] - $at <= self::GAP ) {
			$this->open[ $key ]['oldest'] = min( $this->open[ $key ]['oldest'], $at );
			return;
		}
		$this->open[ $key ] = array( 'index' => null, 'oldest' => $at );
	}

	/**
	 * Read the next row, newest first.
	 *
	 * @param array $row Raw log row.
	 * @return bool True once the page is full and every visit on it is closed.
	 */
	public function add( array $row ) {
		$key = self::visitor( $row );
		$at  = self::time( $row );

		if ( isset( $this->open[ $key ] ) && $this->open[ $key ]['oldest'] - $at <= self::GAP ) {
			$this->open[ $key ]['oldest'] = min( $this->open[ $key ]['oldest'], $at );
			$index                        = $this->open[ $key ]['index'];
			if ( null !== $index && $this->keep ) {
				$this->join( $index, $row, $at );
			}
		} elseif ( ! $this->keep ) {
			$this->visits[]     = (int) $row['id'];
			$this->open[ $key ] = array( 'index' => null, 'oldest' => $at );
		} elseif ( count( $this->visits ) < $this->limit ) {
			$this->visits[]     = array(
				'head'  => $row,
				'rows'  => array( $row ),
				'count' => 1,
				'first' => $at,
				'last'  => $at,
			);
			$this->open[ $key ] = array( 'index' => count( $this->visits ) - 1, 'oldest' => $at );
		} else {
			if ( null === $this->next ) {
				$this->next = (int) $row['id'];
			}
			// Tracked so its later rows are not mistaken for yet another visit.
			$this->open[ $key ] = array( 'index' => null, 'oldest' => $at );
		}

		return null !== $this->next && $this->all_closed( $at );
	}

	/**
	 * The page: its visits, newest first, and where the next page starts. In
	 * counting mode, `visits` is every visit's head id, newest first.
	 *
	 * @return array{visits:array<int,array|int>,next:?int}
	 */
	public function result() {
		return array(
			'visits' => $this->visits,
			'next'   => $this->next,
		);
	}

	/**
	 * Add a row to one of this page's visits.
	 *
	 * @param int   $index Visit index.
	 * @param array $row   Raw log row.
	 * @param int   $at    Its time.
	 * @return void
	 */
	private function join( $index, array $row, $at ) {
		$visit = &$this->visits[ $index ];
		++$visit['count'];
		$visit['first'] = min( $visit['first'], $at );
		$visit['last']  = max( $visit['last'], $at );
		if ( count( $visit['rows'] ) < self::ROW_CAP ) {
			$visit['rows'][] = $row;
		}
	}

	/**
	 * Whether no visit on this page can take another row older than $at.
	 *
	 * @param int $at Time of the row just read.
	 * @return bool
	 */
	private function all_closed( $at ) {
		foreach ( $this->open as $state ) {
			if ( null !== $state['index'] && $state['oldest'] - $at <= self::GAP ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A row's time as a Unix timestamp (the log stores UTC).
	 *
	 * @param array $row Raw log row.
	 * @return int
	 */
	private static function time( array $row ) {
		return (int) strtotime( $row['hit_at'] . ' UTC' );
	}
}
