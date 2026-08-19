<?php
/**
 * The nudge: running this plugin's overdue jobs from a real request, on a site
 * whose loopback to /wp-cron.php never arrives.
 *
 * These need real WordPress: the cron array is an option, and rescheduling is
 * core's own bookkeeping — a stub would be testing the stub.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Cron;

final class CronNudgeDbTest extends DbTestCase {

	/** @var array<int,string> Hooks fired during a test. */
	private $fired = array();

	public function set_up(): void {
		parent::set_up();
		$this->fired = array();
		delete_option( Cron::LAST_RUN );
		delete_option( Cron::LOCK );
		delete_transient( 'doing_cron' );
		_set_cron_array( array() );
		$this->reset_memo();

		foreach ( array( 'agentimus_test_job', 'agentimus_test_other', 'someone_elses_job' ) as $hook ) {
			add_action(
				$hook,
				function () use ( $hook ) {
					$this->fired[] = $hook;
				}
			);
		}
	}

	public function tear_down(): void {
		_set_cron_array( array() );
		$this->reset_memo();
		parent::tear_down();
	}

	/** The per-request heartbeat memo is static; each test starts from the option. */
	private function reset_memo(): void {
		$p = new \ReflectionProperty( Cron::class, 'wrote' );
		\_af_accessible( $p );
		$p->setValue( null, null );
	}

	/* ------------------------------------------------------------- what runs */

	/**
	 * ⛔ THE LINE THIS ENDPOINT MUST NEVER CROSS. Running another plugin's due
	 * job out of our route would be us deciding, for the owner, that now is a
	 * good time to send their newsletter.
	 */
	public function test_it_runs_our_jobs_and_never_anybody_elses() {
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );
		wp_schedule_single_event( time() - 60, 'someone_elses_job' );

		$out = Cron::run_due();

		$this->assertSame( array( 'agentimus_test_job' ), $this->fired );
		$this->assertSame( 1, (int) $out['ran'] );
		$this->assertNotFalse(
			wp_next_scheduled( 'someone_elses_job' ),
			'Their job is left exactly where it was — still due, still theirs.'
		);
	}

	public function test_a_job_that_is_not_due_yet_is_left_alone() {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'agentimus_test_job' );

		$out = Cron::run_due();

		$this->assertSame( array(), $this->fired );
		$this->assertSame( 0, (int) $out['ran'] );
		$this->assertNotFalse( wp_next_scheduled( 'agentimus_test_job' ) );
	}

	/**
	 * The order core itself uses: book the next one BEFORE letting go of this
	 * one, so a failure between the two leaves the schedule intact rather than
	 * quietly ending a recurring job.
	 */
	public function test_a_recurring_job_is_booked_forward_and_a_single_one_is_not() {
		wp_schedule_event( time() - 60, 'hourly', 'agentimus_test_job' );
		wp_schedule_single_event( time() - 60, 'agentimus_test_other' );

		Cron::run_due();

		$next = wp_next_scheduled( 'agentimus_test_job' );
		$this->assertNotFalse( $next, 'An hourly job must still be scheduled after it runs.' );
		$this->assertGreaterThan( time(), (int) $next );
		$this->assertFalse( wp_next_scheduled( 'agentimus_test_other' ), 'A one-off is done when it has run.' );
		$this->assertContains( 'agentimus_test_job', $this->fired );
		$this->assertContains( 'agentimus_test_other', $this->fired );
	}

	/* ------------------------------------------------------- when it applies */

	/**
	 * ⛔ BOTH HALVES. A quiet site is the common case and a healthy one — most
	 * sites have nothing due most of the time. Calling that "your cron is
	 * broken" would put a false alarm on almost every screen.
	 */
	public function test_silence_alone_is_not_a_broken_cron() {
		update_option( Cron::LAST_RUN, time() - WEEK_IN_SECONDS, true );

		$this->assertFalse( Cron::is_overdue(), 'Nothing due, so nothing is late.' );
		$this->assertSame( 0, (int) Cron::health()['due'] );
	}

	public function test_silence_with_work_sitting_due_is_a_broken_cron() {
		update_option( Cron::LAST_RUN, time() - ( 3 * HOUR_IN_SECONDS ), true );
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );

		$this->assertTrue( Cron::is_overdue() );

		$health = Cron::health();
		$this->assertTrue( $health['overdue'] );
		$this->assertSame( 1, (int) $health['due'] );
	}

	public function test_a_cron_that_ran_an_hour_ago_is_not_late_yet() {
		update_option( Cron::LAST_RUN, time() - HOUR_IN_SECONDS, true );
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );

		$this->assertFalse( Cron::is_overdue(), 'The shortest schedule here is hourly; one hour is not evidence.' );
	}

	/* --------------------------------------------------------- the heartbeat */

	/**
	 * ⚠️ HIS SITE, 2026-08-19: the run answered "I ran a job" beside a heartbeat
	 * three hours stale, because the option read straight back after the write
	 * came from a cache holding the older value. A payload that contradicts
	 * itself is the bug this whole file exists to stop shipping.
	 */
	public function test_the_heartbeat_it_just_wrote_is_the_one_it_reports() {
		update_option( Cron::LAST_RUN, time() - ( 5 * HOUR_IN_SECONDS ), true );
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );

		$out    = Cron::run_due();
		$health = Cron::health();

		$this->assertSame( 1, (int) $out['ran'] );
		$this->assertLessThanOrEqual( 2, time() - (int) $health['lastRun'], 'A job ran in this request; the heartbeat says so.' );
		$this->assertFalse( $health['overdue'], 'And the site is no longer late — it just did the work.' );
	}

	public function test_a_run_that_does_nothing_leaves_the_heartbeat_alone() {
		$stale = time() - ( 4 * HOUR_IN_SECONDS );
		update_option( Cron::LAST_RUN, $stale, true );

		$out = Cron::run_due();

		$this->assertSame( 0, (int) $out['ran'] );
		$this->assertSame( $stale, (int) Cron::health()['lastRun'], 'Nothing happened, so nothing is claimed.' );
	}

	/**
	 * THE PATH IT ACTUALLY FAILED ON — a REST request, not a function call.
	 *
	 * ⚠️ His site, 2026-08-19: the route answered `ran: 1` beside a heartbeat
	 * three hours stale. The database had the new value; the read straight after
	 * the write did not. The in-process test above could never have caught it,
	 * because in-process was the case that worked.
	 *
	 * Two things are pinned here. The CONTRACT: an answer that says it ran a job
	 * carries the heartbeat that job produced, whatever the option layer hands
	 * back. And the DIAGNOSIS: a plain write-then-read, performed inside that
	 * same request, recorded so the next person knows whether the platform
	 * behaviour is still there.
	 */
	public function test_the_rest_answer_never_contradicts_itself() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$read_back = null;
		add_action(
			'agentimus_test_job',
			function () use ( &$read_back ) {
				update_option( 'agentimus_probe_write_read', 4242, true );
				$read_back = get_option( 'agentimus_probe_write_read' );
			}
		);
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );
		update_option( Cron::LAST_RUN, time() - ( 5 * HOUR_IN_SECONDS ), true );

		$res  = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/agentimus/v1/cron/run' ) );
		$data = $res->get_data();
		delete_option( 'agentimus_probe_write_read' );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, (int) $data['ran'] );
		$this->assertLessThanOrEqual(
			2,
			time() - (int) $data['health']['lastRun'],
			'It says it ran a job — the heartbeat beside that has to be the one it just wrote.'
		);
		$this->assertFalse( $data['health']['overdue'], 'A site that just did its work is not late.' );
		$this->assertSame( 4242, (int) $read_back, 'A plain write-then-read inside a REST request: recorded, so a future change of platform behaviour shows up here.' );
	}

	/* -------------------------------------------------------------- the lock */

	public function test_a_second_runner_stands_down_rather_than_running_it_twice() {
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );
		update_option( Cron::LOCK, time(), false ); // Another request is mid-run.

		$out = Cron::run_due();

		$this->assertSame( array(), $this->fired, 'One runner, or a job runs twice.' );
		$this->assertSame( 'busy', $out['skipped'] );
		$this->assertSame( 1, (int) $out['remaining'], 'And it says the work is still there.' );
	}

	/* --------------------------------------------------------- the time wall */

	/**
	 * ⛔ The budget stops the QUEUE, not the job. Checking it after each job
	 * meant a second slow one could still be started a hair under the wall — so
	 * "8 seconds" bought nothing on the case it existed for. The first job always
	 * runs; nothing else starts once the wall is passed.
	 */
	public function test_no_further_job_starts_once_the_budget_is_spent() {
		add_action(
			'agentimus_test_job',
			function () {
				usleep( 120000 ); // 0.12s — longer than the budget below.
			}
		);
		wp_schedule_single_event( time() - 120, 'agentimus_test_job' );
		wp_schedule_single_event( time() - 60, 'agentimus_test_other' );

		$out = Cron::run_due( 0.05 );

		$this->assertSame( array( 'agentimus_test_job' ), $this->fired, 'The first job runs; the second is left for the next nudge.' );
		$this->assertSame( 1, (int) $out['ran'] );
		$this->assertSame( 1, (int) $out['remaining'], 'And what it did not start is still due.' );
		$this->assertNotFalse( wp_next_scheduled( 'agentimus_test_other' ) );
	}

	/* ------------------------------- a host whose cron works perfectly well */

	/**
	 * ⛔⛔ THE COLLISION THAT MATTERS. On a healthy host a real pass can start in
	 * the same second as a nudge, and `wp_unschedule_event()` cannot arbitrate:
	 * core unsets the entry and reports success whether or not it was still
	 * there, so both runners would believe they own the job. The lock WordPress
	 * itself uses is what makes them exclusive.
	 */
	public function test_it_stands_down_while_wordpress_is_running_its_own_pass() {
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );
		set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) ) );

		$out = Cron::run_due();

		$this->assertSame( array(), $this->fired, 'The real pass is about to run this; twice is the bug.' );
		$this->assertSame( 'busy', $out['skipped'] );
		$this->assertNotFalse( wp_next_scheduled( 'agentimus_test_job' ), 'And the job is left for it.' );
	}

	public function test_a_pass_that_died_mid_run_never_wedges_the_site() {
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );
		$timeout = defined( 'WP_CRON_LOCK_TIMEOUT' ) ? WP_CRON_LOCK_TIMEOUT : MINUTE_IN_SECONDS;
		set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) - ( $timeout + 30 ) ) );

		Cron::run_due();

		$this->assertSame( array( 'agentimus_test_job' ), $this->fired, 'A stale lock is not a running pass.' );
	}

	/**
	 * The other direction: while we work, a real pass must see the site as busy
	 * — otherwise the collision simply happens the other way round.
	 */
	public function test_it_holds_wordpresss_own_lock_while_it_works_and_hands_it_back() {
		$seen = null;
		add_action(
			'agentimus_test_job',
			function () use ( &$seen ) {
				$seen = get_transient( 'doing_cron' );
			}
		);
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );

		Cron::run_due();

		$this->assertNotEmpty( $seen, 'A real pass starting mid-run must find the site locked.' );
		$this->assertFalse( get_transient( 'doing_cron' ), 'And the lock goes back the moment we are done.' );
	}

	public function test_a_lock_taken_by_somebody_else_mid_run_is_not_deleted() {
		add_action(
			'agentimus_test_job',
			function () {
				// A real pass starts while our job runs and takes the lock over.
				set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) ) . '-theirs' );
			}
		);
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );

		Cron::run_due();

		$this->assertStringEndsWith( '-theirs', (string) get_transient( 'doing_cron' ), 'Never hand back a lock that stopped being ours.' );
	}

	/**
	 * ⚠️ The due list is read once, before the first job runs — and a job can
	 * clear what comes after it. Each event is claimed again at the last
	 * instant, because core's unschedule can never report that we lost.
	 */
	public function test_an_event_taken_while_we_worked_is_not_run() {
		add_action(
			'agentimus_test_job',
			function () {
				wp_clear_scheduled_hook( 'agentimus_test_other' ); // Somebody else took it.
			}
		);
		wp_schedule_single_event( time() - 120, 'agentimus_test_job' );
		wp_schedule_single_event( time() - 60, 'agentimus_test_other' );

		$out = Cron::run_due();

		$this->assertSame( array( 'agentimus_test_job' ), $this->fired );
		$this->assertSame( 1, (int) $out['ran'], 'Only what it actually ran is counted.' );
	}

	/**
	 * ⚠️ Through core's own filter, never `define( 'DOING_CRON' )` — a constant
	 * cannot be undefined, so one test would leave every test after it running
	 * inside an imaginary cron pass. (It did, once, and the failure landed three
	 * tests away from the cause.)
	 */
	public function test_it_adds_nothing_inside_a_real_cron_pass() {
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );
		add_filter( 'wp_doing_cron', '__return_true' );

		$out = Cron::run_due();
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertSame( array(), $this->fired, 'Everything due is already about to run.' );
		$this->assertSame( 'cron', $out['skipped'] );
	}

	public function test_an_abandoned_lock_never_wedges_the_site() {
		wp_schedule_single_event( time() - 60, 'agentimus_test_job' );
		update_option( Cron::LOCK, time() - ( Cron::LOCK_TTL + 60 ), false ); // A run killed mid-job.

		Cron::run_due();

		$this->assertSame( array( 'agentimus_test_job' ), $this->fired );
	}
}
