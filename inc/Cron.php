<?php
/**
 * Background work on a site whose background jobs never run.
 *
 * ⭐⭐ THE PROBLEM, measured on his own site 2026-08-19: WordPress fires its
 * scheduled jobs by asking ITSELF for `/wp-cron.php` on the next page view. A
 * host that blocks that request — a firewall, a proxy, a page cache answering
 * before PHP, a server that cannot resolve its own name — leaves every
 * scheduled job in the queue for ever, and nothing on any screen says so. The
 * grade sweep sat at "75 still to read" for an evening; the digest, the search
 * polls, the reachability check and the theme probe were all equally dead.
 *
 * ⛔⛔ THE ONE THING THAT CANNOT FIX IT IS CALLING `/wp-cron.php` FROM PHP.
 * `spawn_cron()` is exactly that request, and it is the request being blocked —
 * asking again from our own code asks the same blocked question, more often.
 *
 * ⭐⭐ SO THE BROWSER DOES THE ASKING. The owner is signed in and looking at
 * wp-admin; their browser can reach this site (it is how the page they are
 * reading arrived). One small request from that page runs the jobs that were
 * due, in a request of their own, and nothing about the page they are reading
 * gets slower. A blocked loopback cannot block a real visitor.
 *
 * ⛔ OURS ONLY. This runs hooks beginning with `agentimus_` and nothing else.
 * Running another plugin's scheduled job out of our endpoint would be us
 * deciding, on their behalf, that now is a good time to send their newsletter.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Cron {

	/** Only hooks with this prefix are ever run from here. */
	const PREFIX = 'agentimus_';

	/**
	 * When WordPress's own cron pass last reached this plugin.
	 *
	 * ⭐ Autoloaded ON PURPOSE, against the usual rule. The sweep's lock is
	 * autoload=false because only cron reads it; this one is read on admin page
	 * loads to decide whether anything needs nudging, and an un-autoloaded
	 * option read on every one of those would be the query this saves.
	 */
	const LAST_RUN = 'agentimus_cron_last_run';

	/** One runner at a time, across tabs and across requests. */
	const LOCK = 'agentimus_cron_lock';

	/** The floor between two nudges, so ten open tabs are still one runner. */
	const ATTEMPT = 'agentimus_cron_attempt';

	/**
	 * Silence longer than this means the host is not running jobs.
	 *
	 * The shortest thing this plugin schedules is hourly, so two hours is late
	 * beyond argument while still forgiving a slow or bunched-up cron pass.
	 * ⛔ Never used alone: a quiet site with nothing due is not a broken one
	 * {@see is_overdue()}.
	 */
	const OVERDUE_AFTER = 2 * HOUR_IN_SECONDS;

	/**
	 * Seconds after which no FURTHER job is started.
	 *
	 * ⚠️ NOT a promise about how long the request takes, and it must not be
	 * described as one. Nothing here can interrupt a job already running — the
	 * host's own `max_execution_time` is the only real wall — so the honest
	 * ceiling is this budget PLUS the slowest single job. Measured on a real
	 * site: two due jobs, 11.5s. What that buys is a bound on the QUEUE: a
	 * backlog is never walked in one request, however many jobs are due.
	 */
	const BUDGET = 8.0;

	/** Seconds a held lock is treated as abandoned (a run killed mid-job). */
	const LOCK_TTL = 120;

	/** Least time between two nudges from the same site. */
	const THROTTLE = 30;

	/**
	 * WordPress's OWN cron lock — the transient `spawn_cron()` reads before it
	 * fires wp-cron.php and `wp-cron.php` sets while it works.
	 *
	 * ⭐⭐ WE TAKE PART IN IT RATHER THAN INVENTING A SECOND ONE. A host whose
	 * cron works perfectly can start a real pass in the same second as a nudge,
	 * and `wp_unschedule_event()` is NOT a claim — core simply unsets the entry
	 * and reports success whether or not it was still there, so "unschedule then
	 * run" leaves both runners believing they own the job. Holding the lock
	 * WordPress already uses is what makes the two exclusive: a pass sees ours
	 * and stands down, and we see a pass and stand down.
	 */
	const WP_LOCK = 'doing_cron';

	/**
	 * The heartbeat THIS request wrote, if it wrote one.
	 *
	 * ⚠️ MEASURED, not assumed: a `get_option()` immediately after the matching
	 * `update_option()` came back with the OLD value inside a REST request on a
	 * real site — the run answered "I ran a job" beside a heartbeat three hours
	 * stale, which is a payload contradicting itself. Whatever the option cache
	 * decides to hand back, a caller in this request is owed what we just did,
	 * so we keep it. ⛔ Never a substitute for the write: the next request has
	 * only the option.
	 *
	 * @var int|null
	 */
	private static $wrote = null;

	/**
	 * @return void
	 */
	public static function register() {
		// Priority 1: before anything scheduled can run, so a pass that dies
		// half way through still leaves the heartbeat it arrived with.
		add_action( 'init', array( __CLASS__, 'observe' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_nudge' ) );
	}

	/**
	 * Record that WordPress's own cron pass reached us.
	 *
	 * ⭐ THE HEARTBEAT IS "wp-cron.php RAN", not "one of our jobs ran". A site
	 * whose queue is empty runs no jobs of ours for hours and is perfectly
	 * healthy; a site whose loopback is blocked runs none either, and is not.
	 * Only the pass itself tells those two apart.
	 *
	 * @return void
	 */
	public static function observe() {
		if ( ! wp_doing_cron() ) {
			return;
		}
		// A busy site's cron passes arrive every few seconds; the heartbeat is
		// read against a two-hour window, so a write a minute is plenty.
		if ( time() - self::last_run() < MINUTE_IN_SECONDS ) {
			return;
		}
		self::mark_ran();
	}

	/**
	 * When the site's scheduled work last actually happened.
	 *
	 * @return int Unix time, 0 when it never has.
	 */
	public static function last_run() {
		return null !== self::$wrote ? self::$wrote : (int) get_option( self::LAST_RUN, 0 );
	}

	/**
	 * Record that it just did.
	 *
	 * @return void
	 */
	private static function mark_ran() {
		self::$wrote = time();
		update_option( self::LAST_RUN, self::$wrote, true );
	}

	/**
	 * The site's scheduled-job health, for a screen that has to explain itself.
	 *
	 * @return array{lastRun:int,due:int,overdue:bool}
	 */
	public static function health() {
		$due = self::due();
		return array(
			'lastRun' => self::last_run(),
			'due'     => count( $due ),
			'overdue' => self::is_overdue( $due ),
		);
	}

	/**
	 * Is this site's cron demonstrably not running?
	 *
	 * ⛔ BOTH HALVES, ALWAYS. Silence proves nothing on its own — a site can go
	 * quiet because there is nothing to do, which is the healthy case and by far
	 * the common one. Only silence WITH work sitting due is evidence, and it is
	 * also the only case where a nudge would do anything.
	 *
	 * @param array|null $due Pre-read due list, when the caller already has one.
	 * @return bool
	 */
	public static function is_overdue( $due = null ) {
		$due = null === $due ? self::due() : (array) $due;
		if ( ! $due ) {
			return false;
		}
		$last = self::last_run();
		// Never recorded AND work is due: either a site whose cron has not run
		// since this version arrived, or one whose cron does not run at all. The
		// nudge is harmless in the first case and the whole point in the second.
		return $last < 1 || ( time() - $last ) > self::OVERDUE_AFTER;
	}

	/**
	 * This plugin's scheduled events that are past their time.
	 *
	 * @return array<int,array{timestamp:int,hook:string,schedule:string|false,args:array}>
	 */
	public static function due() {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return array();
		}
		$now = time();
		$out = array();
		foreach ( $crons as $timestamp => $hooks ) {
			if ( (int) $timestamp > $now ) {
				break; // The array is ordered by time; nothing after this is due.
			}
			foreach ( (array) $hooks as $hook => $events ) {
				if ( 0 !== strpos( (string) $hook, self::PREFIX ) ) {
					continue; // ⛔ Not ours to run.
				}
				foreach ( (array) $events as $event ) {
					$out[] = array(
						'timestamp' => (int) $timestamp,
						'hook'      => (string) $hook,
						'schedule'  => isset( $event['schedule'] ) ? $event['schedule'] : false,
						'args'      => isset( $event['args'] ) ? (array) $event['args'] : array(),
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Run this plugin's due jobs, here, now, in this request.
	 *
	 * The same steps `wp-cron.php` takes for each event, in the same order:
	 * a recurring event is booked forward FIRST and only then removed, so a
	 * failure between the two leaves the schedule intact rather than silently
	 * ending it. Everything WordPress does around that — the doing_cron
	 * transient, the loopback, the whole-queue walk — belongs to that file and
	 * is deliberately not copied here.
	 *
	 * ⚠️ Budgeted, because a job may make a network call. What does not fit is
	 * left due, and the next nudge takes it: the queue drains across page views
	 * instead of one page view carrying the whole backlog.
	 *
	 * @param float $budget Seconds of work to attempt.
	 * @return array{ran:int,remaining:int,skipped:string}
	 */
	public static function run_due( $budget = self::BUDGET ) {
		// ⛔ We ARE the cron pass. Everything due is about to run anyway, and
		// running it twice from inside itself is the one collision no lock can
		// undo afterwards.
		if ( wp_doing_cron() ) {
			return array( 'ran' => 0, 'remaining' => count( self::due() ), 'skipped' => 'cron' );
		}
		// ⛔ A REAL PASS IS IN FLIGHT on a host whose cron works. It will run
		// these same jobs seconds from now; standing down is the whole of our
		// job here.
		if ( self::pass_in_flight() ) {
			return array( 'ran' => 0, 'remaining' => count( self::due() ), 'skipped' => 'busy' );
		}
		if ( ! self::lock() ) {
			// Another tab of ours got here first.
			return array( 'ran' => 0, 'remaining' => count( self::due() ), 'skipped' => 'busy' );
		}
		// And now the other direction: while we work, a real pass must see the
		// site as busy, exactly as it would while another pass ran. ⚠️ This
		// holds up OTHER plugins' due jobs for the few seconds we take — the
		// same contract every cron pass has always had, and the price of not
		// running the same job twice.
		$held = self::hold_pass();

		$ran      = 0;
		// ⛔ No floor under the budget. It used to be max(1.0, …), which quietly
		// made any smaller budget mean one second — and a caller asking for a
		// tighter wall got a wider one without being told. Progress is
		// guaranteed by the first-job rule below, not by a minimum here.
		$deadline = microtime( true ) + (float) $budget;
		try {
			foreach ( self::due() as $event ) {
				// ⛔ BEFORE the job, never after. Checking afterwards only stops
				// the run once the overrun has already happened — a second slow
				// job could still be started at 7.9s, and the "8 second budget"
				// bought nothing. Never START work past the wall; what is
				// already running cannot be interrupted from here at all.
				// ⭐ …but the FIRST job always runs. A budget spent before the
				// loop begins (a caller passing a tiny one, a clock that moved)
				// would otherwise leave a site making no progress at all, which
				// is the one outcome worse than a slow request.
				if ( $ran > 0 && microtime( true ) >= $deadline ) {
					break;
				}

				$hook = $event['hook'];
				$args = $event['args'];

				// ⛔ THE LAST-INSTANT CLAIM. `wp_unschedule_event()` unsets and
				// reports success whether or not the entry was still there, so
				// it can never tell us we lost a race. This can: the list was
				// read before the first job ran, and a job — ours or anyone's —
				// may have cleared what comes after it.
				if ( ! self::still_scheduled( $event ) ) {
					continue;
				}

				if ( $event['schedule'] ) {
					$booked = wp_reschedule_event( $event['timestamp'], (string) $event['schedule'], $hook, $args, true );
					if ( is_wp_error( $booked ) ) {
						continue; // Leave it due rather than run something we cannot re-book.
					}
				}
				$cleared = wp_unschedule_event( $event['timestamp'], $hook, $args, true );
				if ( is_wp_error( $cleared ) ) {
					continue; // ⛔ Never run an event we could not claim: two runners would both run it.
				}

				/** This is the scheduled job itself, run in a request instead of a cron pass. */
				do_action_ref_array( $hook, $args );
				++$ran;
			}
		} finally {
			self::release_pass( $held );
			self::unlock();
		}

		// A job that ran IS the site's background work happening, whoever asked
		// for it — so the heartbeat moves. Without this the screen would go on
		// calling a site broken while this very mechanism kept it healthy.
		if ( $ran > 0 ) {
			self::mark_ran();
		}

		return array( 'ran' => $ran, 'remaining' => count( self::due() ), 'skipped' => '' );
	}

	/**
	 * Put the nudge on an admin page — but only on a site that needs it.
	 *
	 * ⛔ A healthy site loads nothing: no script, no request, no cost. The gate
	 * is two autoloaded option reads, which is cheaper than the query a badge
	 * count already costs on the same page.
	 *
	 * ⚠️ Any admin screen, not just ours. The owner of a site with no working
	 * cron is far more likely to be writing a post than reading our card, and a
	 * self-healing mechanism that only heals while you watch it is a button with
	 * extra steps.
	 *
	 * @return void
	 */
	public static function maybe_nudge() {
		if ( wp_doing_ajax() || ! is_admin() ) {
			return;
		}
		// The nudge acts as this user; a user who cannot run the plugin's own
		// routes must not be handed work that will only 403.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::is_overdue() ) {
			return;
		}
		// ⛔ A real pass is already running: it is about to do exactly this
		// work. One transient read here saves a whole request that would only
		// stand down at the other end.
		if ( self::pass_in_flight() ) {
			return;
		}
		// Ten tabs open on a slow morning is still one runner a minute.
		$last_try = (int) get_transient( self::ATTEMPT );
		if ( $last_try > 0 ) {
			return;
		}
		set_transient( self::ATTEMPT, time(), self::THROTTLE );

		wp_register_script( 'agentimus-cron-nudge', false, array(), AGENTIMUS_VERSION, true );
		wp_enqueue_script( 'agentimus-cron-nudge' );
		wp_localize_script(
			'agentimus-cron-nudge',
			'AgentimusCron',
			array(
				'url'    => esc_url_raw( rest_url( 'agentimus/v1/cron/run' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				// A ceiling on how much one page view will chase. The queue is
				// drained across visits by design — a page that keeps firing
				// requests until a backlog clears is a page that never settles.
				'rounds' => 3,
			)
		);
		wp_add_inline_script( 'agentimus-cron-nudge', self::script() );
	}

	/**
	 * The nudge itself.
	 *
	 * ⭐ Fired when the page is idle, never during load: the owner's screen
	 * finishes drawing first, and the work happens in a request of its own.
	 * ⛔ Silent by design — this is the plugin repairing itself, not an errand
	 * for the owner. What it cannot repair is said on the card instead.
	 *
	 * @return string
	 */
	private static function script() {
		return <<<'JS'
(function () {
	var d = window.AgentimusCron;
	if (!d || !window.fetch) { return; }
	var left = d.rounds;
	function run() {
		fetch(d.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': d.nonce },
			body: '{}'
		}).then(function (r) {
			return r.ok ? r.json() : null;
		}).then(function (j) {
			// More still due and rounds left: come back for the next chunk.
			if (j && j.remaining > 0 && --left > 0) { setTimeout(run, 1500); }
		}).catch(function () { /* a nudge that fails changes nothing */ });
	}
	if (window.requestIdleCallback) { requestIdleCallback(run, { timeout: 4000 }); } else { setTimeout(run, 2000); }
}());
JS;
	}

	/**
	 * Is one of this event's entries still in the schedule?
	 *
	 * The same three keys core stores it under — timestamp, hook, and the hash
	 * of its arguments.
	 *
	 * @param array $event One {@see due()} row.
	 * @return bool
	 */
	private static function still_scheduled( array $event ) {
		$crons = _get_cron_array();
		$key   = md5( serialize( $event['args'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- core's own key for a scheduled event; it must match byte for byte.
		return isset( $crons[ $event['timestamp'] ][ $event['hook'] ][ $key ] );
	}

	/**
	 * Is WordPress running a cron pass right now?
	 *
	 * Core's own test, to the letter {@see spawn_cron()}: the lock is a
	 * microtime string, and it is stale once WP_CRON_LOCK_TIMEOUT has passed —
	 * a pass that died mid-run must never wedge the site.
	 *
	 * @return bool
	 */
	private static function pass_in_flight() {
		$lock = (float) get_transient( self::WP_LOCK );
		if ( $lock <= 0 ) {
			return false;
		}
		$timeout = defined( 'WP_CRON_LOCK_TIMEOUT' ) ? (int) WP_CRON_LOCK_TIMEOUT : MINUTE_IN_SECONDS;
		// A lock stamped far in the future is a broken value, not a running pass.
		if ( $lock > microtime( true ) + 10 * MINUTE_IN_SECONDS ) {
			return false;
		}
		return ( $lock + $timeout ) > microtime( true );
	}

	/**
	 * Take WordPress's cron lock for the length of this run.
	 *
	 * @return string The value written, for {@see release_pass()}.
	 */
	private static function hold_pass() {
		$value = sprintf( '%.22F', microtime( true ) );
		set_transient( self::WP_LOCK, $value );
		return $value;
	}

	/**
	 * Give it back — ⛔ only if it is still ours. A pass that started after us
	 * owns the lock now, and deleting it would let a third runner in beside it.
	 * (wp-cron.php ends the same way, for the same reason.)
	 *
	 * @param string $value What {@see hold_pass()} wrote.
	 * @return void
	 */
	private static function release_pass( $value ) {
		if ( (string) get_transient( self::WP_LOCK ) === (string) $value ) {
			delete_transient( self::WP_LOCK );
		}
	}

	/**
	 * A lock that survives across requests.
	 *
	 * ⛔ NOT {@see Cache::acquire_lock()}, which is `wp_cache_add` — without a
	 * persistent object cache that is a per-request memory array, so every
	 * request would win it and two tabs would run the same job. An option is
	 * the one store every site has.
	 *
	 * @return bool True to the single caller that should run.
	 */
	private static function lock() {
		$held = (int) get_option( self::LOCK, 0 );
		if ( $held > 0 && ( time() - $held ) < self::LOCK_TTL ) {
			return false;
		}
		update_option( self::LOCK, time(), false );
		return true;
	}

	/**
	 * @return void
	 */
	private static function unlock() {
		delete_option( self::LOCK );
	}
}
