<?php
/**
 * The review ask — one quiet Dashboard card that asks for a WordPress.org
 * review, shown only after the plugin has demonstrably earned it: a week
 * present, a few visits to its screens, at least one real action taken
 * through it, and a readiness score worth being proud of. Never a site-wide
 * notice, never a redirect, no incentives, and nothing is sent anywhere —
 * the button is a plain link to the review form.
 *
 * Manners contract: the card asks once. "Maybe later" brings it back in a
 * month; any other answer closes it for good — and quiets the admin footer's
 * rating line with it, so "never asks again" holds everywhere in the plugin.
 *
 * The state machine is pure (state array in, state array out, $now injected)
 * so every gate is unit-testable without WordPress time or options.
 *
 * @package Agentimus
 */

namespace Agentimus;

/**
 * Site-wide review-ask state and eligibility.
 */
class Review {

	/** Option holding the state array — site-wide, like the What's-New dismissal. */
	const OPTION = 'agentimus_review_ask';

	/** The WordPress.org review form. */
	const URL = 'https://wordpress.org/support/plugin/agentimus/reviews/#new-post';

	/** The plugin must have been present this long before it may ask. */
	const MIN_DAYS = 7;

	/** ... and the owner must have opened its screens this many times. */
	const MIN_VISITS = 3;

	/**
	 * ... and the owner must have DONE something with it at least this often —
	 * saved settings, applied a fix, set topics, run the assistant. Any
	 * state-changing call to the plugin's own REST namespace by a managing
	 * user counts (see use_worthy()); merely looking at screens does not.
	 */
	const MIN_ACTIONS = 1;

	/** ... and the readiness score must say the plugin is doing its job. */
	const MIN_SCORE = 80;

	/** "Maybe later" puts the card away for this long. */
	const SNOOZE_DAYS = 30;

	/**
	 * Visits and actions stop counting here: past the gates the numbers prove
	 * nothing, and capping them means a long-lived site stops writing the
	 * option on every load and every save.
	 */
	const VISIT_CAP = 30;

	/**
	 * One plugin-screen load: seed the clock on first sight, count the visit.
	 * Pure — the caller persists the result if it changed.
	 *
	 * @param array $state Stored state (any shape; missing keys default).
	 * @param int   $now   Current Unix time.
	 * @return array Updated state.
	 */
	public static function touched( array $state, $now ) {
		$state = self::shape( $state );
		if ( 0 === $state['since'] ) {
			$state['since'] = (int) $now;
		}
		if ( $state['visits'] < self::VISIT_CAP ) {
			++$state['visits'];
		}
		return $state;
	}

	/**
	 * Whether the card may show: unanswered (or the snooze has run out), the
	 * plugin has been present a week, the owner has come back a few times AND
	 * actually used it at least once, and the readiness score clears the bar.
	 * Pure.
	 *
	 * @param array    $state Stored state.
	 * @param int|null $score Current readiness score, null when unmeasured.
	 * @param int      $now   Current Unix time.
	 * @return bool
	 */
	public static function eligible( array $state, $score, $now ) {
		$state = self::shape( $state );
		if ( 'closed' === $state['state'] ) {
			return false;
		}
		if ( 'later' === $state['state'] && $now < $state['until'] ) {
			return false;
		}
		if ( 0 === $state['since'] || ( $now - $state['since'] ) < self::MIN_DAYS * DAY_IN_SECONDS ) {
			return false;
		}
		if ( $state['visits'] < self::MIN_VISITS || $state['actions'] < self::MIN_ACTIONS ) {
			return false;
		}
		return is_numeric( $score ) && (int) $score >= self::MIN_SCORE;
	}

	/**
	 * One real action: the owner changed something through the plugin. Pure —
	 * the caller persists the result if it changed.
	 *
	 * @param array $state Stored state.
	 * @return array Updated state.
	 */
	public static function acted( array $state ) {
		$state = self::shape( $state );
		if ( $state['actions'] < self::VISIT_CAP ) {
			++$state['actions'];
		}
		return $state;
	}

	/**
	 * Whether a REST call counts as "really using the plugin": a state-changing
	 * method on the plugin's own namespace — except answering the review ask
	 * itself, which must not feed the gates it serves. Pure.
	 *
	 * @param string $route  The dispatched route, e.g. '/agentimus/v1/settings'.
	 * @param string $method HTTP method.
	 * @return bool
	 */
	public static function use_worthy( $route, $method ) {
		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return false;
		}
		$ns = '/' . Rest::NAMESPACE . '/';
		if ( 0 !== strpos( (string) $route, $ns ) ) {
			return false;
		}
		return $ns . 'review-ack' !== $route;
	}

	/**
	 * Record the owner's answer. 'later' snoozes for a month; 'review' and
	 * 'done' close the ask for good. An unknown answer changes nothing — a
	 * malformed request must never close (or reopen) the ask. Pure.
	 *
	 * @param array  $state  Stored state.
	 * @param string $answer 'review' | 'done' | 'later'.
	 * @param int    $now    Current Unix time.
	 * @return array Updated state.
	 */
	public static function answered( array $state, $answer, $now ) {
		$state = self::shape( $state );
		if ( 'closed' === $state['state'] ) {
			return $state; // A final answer is final — a stale tab's click can't reopen the ask.
		}
		if ( 'later' === $answer ) {
			$state['state'] = 'later';
			$state['until'] = (int) $now + self::SNOOZE_DAYS * DAY_IN_SECONDS;
		} elseif ( 'review' === $answer || 'done' === $answer ) {
			$state['state'] = 'closed';
			$state['until'] = 0;
		}
		return $state;
	}

	/* -- Thin WordPress wrappers over the pure core. ----------------------- */

	/** The stored state, normalised. */
	public static function state() {
		return self::shape( (array) get_option( self::OPTION, array() ) );
	}

	/** Count a plugin-screen load; writes only when something changed. */
	public static function touch() {
		$before = self::state();
		$after  = self::touched( $before, time() );
		if ( $after !== $before ) {
			update_option( self::OPTION, $after );
		}
	}

	/** Count a real action; writes only when something changed. */
	public static function used() {
		$before = self::state();
		$after  = self::acted( $before );
		if ( $after !== $before ) {
			update_option( self::OPTION, $after );
		}
	}

	/**
	 * Persist an answer and return the resulting state string.
	 *
	 * @param string $answer 'review' | 'done' | 'later'.
	 * @return string '' | 'later' | 'closed'.
	 */
	public static function ack( $answer ) {
		$state = self::answered( self::state(), (string) $answer, time() );
		update_option( self::OPTION, $state );
		return $state['state'];
	}

	/** Whether the ask has been answered for good (footer softening reads this). */
	public static function closed() {
		$state = self::state();
		return 'closed' === $state['state'];
	}

	/**
	 * Normalise any stored value to the canonical shape — unknown keys drop,
	 * missing ones default, and a corrupt state string becomes "unanswered".
	 *
	 * @param array $state Raw stored value.
	 * @return array{since:int,visits:int,state:string,until:int}
	 */
	private static function shape( array $state ) {
		$status = isset( $state['state'] ) ? (string) $state['state'] : '';
		return array(
			'since'   => isset( $state['since'] ) ? (int) $state['since'] : 0,
			'visits'  => isset( $state['visits'] ) ? (int) $state['visits'] : 0,
			'actions' => isset( $state['actions'] ) ? (int) $state['actions'] : 0,
			'state'   => in_array( $status, array( '', 'later', 'closed' ), true ) ? $status : '',
			'until'   => isset( $state['until'] ) ? (int) $state['until'] : 0,
		);
	}
}
