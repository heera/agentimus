<?php
/**
 * The option-backed run lock shared by the polling modules.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * Each data source polls its provider on a cron slot, and a run must never
 * overlap itself (a second run would double the provider's bill and could
 * interleave a snapshot rewrite). The guard is one option holding the start
 * time: a live lock refuses the caller, a stale one (its job died) is stolen so
 * the poll can never wedge forever.
 *
 * The using module supplies the LOCK_OPTION and LOCK_TTL constants.
 */
trait PollLock {

	/**
	 * Take the run lock, stealing a stale one. Returns false when a live run
	 * already holds it.
	 *
	 * @return bool Whether this caller now holds the lock.
	 */
	private static function acquire_lock() {
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && ( time() - $held ) < self::LOCK_TTL ) {
			return false;
		}
		update_option( self::LOCK_OPTION, time(), false );
		return true;
	}

	/**
	 * Release the run lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Run one poll inline (a connect-time or manual "refresh now") under the SAME
	 * lock the cron uses. If a run already holds the lock, skip quietly — the REST
	 * caller then returns the current stored data instead of racing a second
	 * snapshot rewrite (a DELETE + chunked INSERT) over the first, which would leave
	 * a mixed or doubled snapshot. The using class provides run_poll().
	 *
	 * @return void
	 */
	public function poll_now() {
		if ( ! self::acquire_lock() ) {
			return;
		}
		try {
			$this->run_poll();
		} finally {
			self::release_lock();
		}
	}
}
