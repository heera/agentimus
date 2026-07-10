<?php
/**
 * Owner — is this request the site owner reading their own site?
 *
 * Both halves of the activity log skip them: an administrator opening discovery.json
 * is not agent traffic, and an administrator clicking through from ChatGPT is not "a
 * visitor AI sent". One rule, so the two directions can never disagree.
 *
 * It cannot simply ask `is_user_logged_in()`, because the referral beacon posts to a
 * REST route: `rest_cookie_check_errors()` calls `wp_set_current_user( 0 )` on any
 * cookie-authenticated request that arrives without a nonce, so by the time a route
 * callback runs, the owner looks logged out. The beacon sends no nonce on purpose — it
 * has to survive a full-page cache, which would bake in a stale one — so the cookie is
 * re-read here, HMAC-verified, rather than trusted from the request.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

defined( 'ABSPATH' ) || exit;

final class Owner {

	/**
	 * Whether this request should be left out of the activity log.
	 *
	 * @return bool
	 */
	public static function skip() {
		/**
		 * Skip logging the site owner inspecting their own site — a logged-in
		 * administrator opening discovery.json in a browser is not agent traffic, and
		 * would otherwise bury the log in "Browser" self-noise. Filter to false to log
		 * every request regardless.
		 *
		 * @param bool $skip Whether to skip this request. Default true for admins.
		 */
		return (bool) apply_filters( 'agentimus_activity_skip_self', self::is_owner() );
	}

	/**
	 * Whether the request carries the credentials of a user who can manage the site.
	 *
	 * @return bool
	 */
	private static function is_owner() {
		if ( is_user_logged_in() ) {
			return current_user_can( 'manage_options' );
		}
		// No current user — which on a REST request means only that no nonce came with
		// the cookie, not that nobody is signed in. Ask the cookie directly.
		$uid = self::user_id_from_cookie();
		return $uid > 0 && user_can( $uid, 'manage_options' );
	}

	/**
	 * The user id proven by the logged-in cookie, or 0. The cookie is verified
	 * (`wp_validate_auth_cookie()` checks the HMAC and the session token), so a forged
	 * one buys nothing but its own exclusion from the log.
	 *
	 * @return int
	 */
	private static function user_id_from_cookie() {
		// A crawler — and every logged-out visitor — sends no such cookie: the cheap exit,
		// taken on essentially every request that this log exists to record.
		if ( ! defined( 'LOGGED_IN_COOKIE' ) || empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return 0;
		}
		$cookie = wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by wp_validate_auth_cookie() below; never stored or output.
		return (int) wp_validate_auth_cookie( $cookie, 'logged_in' );
	}
}
