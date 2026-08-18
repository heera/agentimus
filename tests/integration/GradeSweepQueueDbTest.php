<?php
/**
 * The grading sweep's QUEUE, against a real database.
 *
 * Every claim here is about ordering or timing, which means it is about SQL —
 * `(g.post_id IS NULL) DESC` and a datetime comparison against a UTC column are
 * exactly the two things the unit suite's stubbed $wpdb cannot answer. They are
 * also the two things that decide whether a large site is ever fully read.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Grades;
use Agentimus\Settings;
use Agentimus\Worklist;

final class GradeSweepQueueDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
		delete_option( Worklist::SWEEP_LOCK );
	}

	/** A published post of the given type. */
	private function post( $type = 'post' ) {
		return self::factory()->post->create(
			array( 'post_status' => 'publish', 'post_type' => $type, 'post_content' => 'Body text for grading.' )
		);
	}

	/** Write a grade row, optionally stamped in the past. */
	private function grade( $id, $graded_at = null ) {
		global $wpdb;
		Grades::record( (int) $id, array( 'needsWork' => false, 'flags' => 0, 'stake' => 0, 'gradeable' => true, 'points' => 90 ) );
		if ( $graded_at ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'UPDATE ' . Grades::name() . ' SET graded_at = %s WHERE post_id = %d',
				$graded_at,
				(int) $id
			) );
		}
	}

	/**
	 * ⭐ THE STARVATION FIX. A saved post goes back in the queue, and it used to
	 * go in at the FRONT (the order was post_modified DESC), so on a site that
	 * edits all day the pages nobody had ever read were overtaken for ever.
	 */
	public function test_never_graded_content_is_queued_ahead_of_re_grades() {
		$old_never_read = $this->post();
		$recently_edited = $this->post();

		// The edited one is graded, then saved again — the exact shape of a
		// re-grade: a row exists, its verdict was cleared, and it is the most
		// recently modified thing on the site.
		$this->grade( $recently_edited );
		Grades::mark_stale( $recently_edited );
		wp_update_post( array( 'ID' => $recently_edited, 'post_content' => 'Edited just now.' ) );

		$queue = Grades::ungraded( array( 'post' ), 10 );

		$this->assertSame(
			$old_never_read,
			(int) $queue[0],
			'A page nobody has ever read must be queued ahead of a re-grade, however recently the other was edited.'
		);
		$this->assertContains( $recently_edited, array_map( 'intval', $queue ), 'The re-grade still belongs in the queue — just behind.' );
	}

	/** Within the never-graded group the newest is still first: what an owner expects to fill in first. */
	public function test_newest_first_within_the_never_graded_group() {
		$older = $this->post();
		wp_update_post( array( 'ID' => $older, 'post_modified' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 00:00:00' ) );
		$newer = $this->post();

		$queue = array_map( 'intval', Grades::ungraded( array( 'post' ), 10 ) );

		$this->assertSame( $newer, $queue[0] );
		$this->assertSame( $older, $queue[1] );
	}

	/** The horizon only sees verdicts older than it — and reads the oldest first. */
	public function test_the_regrade_horizon_returns_only_stale_verdicts_oldest_first() {
		$fresh   = $this->post();
		$stale   = $this->post();
		$ancient = $this->post();

		$this->grade( $fresh );
		$this->grade( $stale, gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );
		$this->grade( $ancient, gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) );

		$due = array_map( 'intval', Grades::stale_graded( array( 'post' ), 10 ) );

		$this->assertSame( array( $ancient, $stale ), $due, 'Oldest verdict first, and a verdict inside the horizon is not due.' );
		$this->assertNotContains( $fresh, $due );
	}

	/**
	 * ⛔ The horizon must never run while anything is still waiting for its
	 * FIRST read: "still to read" is a promise about content nobody has looked
	 * at, and spending the chunk on a re-read would stall it behind work the
	 * owner cannot see.
	 */
	public function test_first_reads_come_before_any_re_read() {
		$never_read = $this->post();
		$long_ago   = $this->post();
		$this->grade( $long_ago, gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) );

		( new Worklist( new Settings() ) )->sweep( 1 );

		global $wpdb;
		$graded_at = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT graded_at FROM ' . Grades::name() . ' WHERE post_id = %d',
			$never_read
		) );
		$this->assertNotNull( $graded_at, 'The one chunk went to the page that had never been read.' );
	}

	/**
	 * The difference between a background job and a treadmill: the initial fill
	 * chases itself a minute later, the horizon rides the hourly beat and never
	 * books anything.
	 */
	public function test_only_the_initial_fill_books_a_follow_up() {
		$worklist = new Worklist( new Settings() );

		// Nothing waiting, one long-stale verdict → a refresh run.
		$post = $this->post();
		$this->grade( $post, gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) );
		wp_clear_scheduled_hook( Grades::CRON );

		$worklist->sweep_and_continue();
		$this->assertFalse(
			wp_next_scheduled( Grades::CRON ),
			'A site that has read everything must not re-read it at a page a minute for ever.'
		);
	}

	/** Two overlapping cron ticks must never render the same chunk twice. */
	public function test_a_second_run_stands_down_while_one_holds_the_lock() {
		$this->post();
		update_option( Worklist::SWEEP_LOCK, time(), false );

		$done = ( new Worklist( new Settings() ) )->sweep( 5 );

		$this->assertSame( 0, $done, 'A live lock refuses the caller rather than doubling the render load.' );
	}

	/** A lock left behind by a run the host killed must not wedge the sweep. */
	public function test_an_abandoned_lock_is_stolen() {
		$this->post();
		update_option( Worklist::SWEEP_LOCK, time() - ( Worklist::SWEEP_LOCK_TTL + 60 ), false );

		$done = ( new Worklist( new Settings() ) )->sweep( 5 );

		$this->assertGreaterThan( 0, $done, 'A stale lock is stolen, or a killed cron run would stop grading for good.' );
	}
}
