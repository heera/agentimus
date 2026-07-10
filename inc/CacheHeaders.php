<?php
/**
 * Cache-Control for the agent-facing endpoints — one decision point, so the
 * "keep AI endpoints out of shared caches" switch governs every one of them.
 *
 * By default these read-only docs (llms.txt, the .well-known discovery files, the
 * change feed, robots.txt) are edge-cacheable — `Cache-Control: public, max-age` —
 * because bots fetch them a lot and caching spares the origin. But a shared cache or
 * CDN sitting in front of WordPress means those fetches never reach it: the activity
 * log under-counts real AI traffic, and a freshness-sensitive endpoint (the change
 * feed, a page's .md) can go stale.
 *
 * When the owner can't exclude these paths at their CDN/proxy, this switch makes the
 * endpoints send `no-store` instead, asking every cache NOT to keep them — so each
 * fetch reaches WordPress (counted and current). It only helps a cache that RESPECTS
 * Cache-Control (most do — the plugin's own .md endpoints already rely on it); a cache
 * told to "cache everything" or ignore origin headers still needs a rule set there.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class CacheHeaders {

	/**
	 * Whether the owner asked to keep the agent endpoints out of shared caches. The
	 * Settings switch, overridable from code.
	 *
	 * @return bool
	 */
	public static function bypass() {
		$on = (bool) ( new Settings() )->get( 'bypass_shared_cache', false );
		/**
		 * Force the agent endpoints uncacheable (send `no-store`) so every fetch reaches
		 * WordPress — the activity log counts it and freshness-sensitive docs stay current.
		 *
		 * @param bool $on Whether to keep the AI endpoints out of shared caches.
		 */
		return (bool) apply_filters( 'agentimus_bypass_shared_cache', $on );
	}

	/**
	 * The Cache-Control value for a cacheable endpoint, given the bypass choice. Pure —
	 * `no-store` keeps it out of every cache; otherwise it stays edge-cacheable. Unit-tested.
	 *
	 * @param bool $bypass  Whether to keep it out of shared caches.
	 * @param int  $max_age Seconds a cache may hold it when NOT bypassing.
	 * @return string
	 */
	public static function value( $bypass, $max_age ) {
		return $bypass ? 'no-store, max-age=0' : 'public, max-age=' . max( 0, (int) $max_age );
	}

	/**
	 * Emit the Cache-Control for a cacheable agent endpoint — and, when the switch is on,
	 * a `CDN-Cache-Control: no-store` the edge reads too (Cloudflare, Fastly, Akamai).
	 * Call inside a `! headers_sent()` guard, as every caller already is.
	 *
	 * @param int $max_age Seconds a cache may hold this when NOT bypassing. Default one hour.
	 */
	public static function send( $max_age = 3600 ) {
		$bypass = self::bypass();
		header( 'Cache-Control: ' . self::value( $bypass, $max_age ) );
		if ( $bypass ) {
			// The same `no-store` the .md endpoints already send; CDN-Cache-Control says it
			// at the edge specifically, for a CDN that reads that in preference.
			header( 'CDN-Cache-Control: no-store' );
		}
	}
}
