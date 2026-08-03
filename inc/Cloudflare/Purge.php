<?php
/**
 * Cloudflare edge purge — the write half of the edge integration, and the one
 * cache layer no WordPress caching plugin purges for you.
 *
 * Every caching plugin drops the pages an edit touches from ITS cache; none of
 * them can reach Cloudflare's. So an edited post keeps serving stale from the
 * edge until Cloudflare's own TTL runs out — the owner republishes, checks the
 * page, and sees the old copy. This module closes that gap two ways: the
 * automatic path rides {@see \Agentimus\CachePurge}'s content-change flow and
 * drops exactly the changed URLs, and the manual path (the edge panel's button)
 * drops everything for the zone.
 *
 * Purging needs one token permission beyond analytics reads: Zone → Cache
 * Purge → Purge. A token without it is refused by Cloudflare; the refusal is
 * recorded on the connection ({@see Settings::record_purge()}) and shown in the
 * edge panel's rail in words — the automatic path never nags, never retries in
 * a loop, and never breaks the content save it rode along with.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

defined( 'ABSPATH' ) || exit;

final class Purge {

	/**
	 * Whether the edge purge can run at all — a connected zone is the gate.
	 * (Whether the token may PURGE is Cloudflare's call, made per request and
	 * surfaced in words when refused.)
	 *
	 * @param Settings|null $settings Injectable for tests.
	 * @return bool
	 */
	public static function available( Settings $settings = null ) {
		$settings = $settings ? $settings : new Settings();
		return $settings->connected();
	}

	/**
	 * Drop specific URLs from the edge cache. Quiet no-op when the edge source
	 * isn't connected; otherwise the outcome — clean or failed — is recorded on
	 * the connection so the panel's rail can tell the owner without a nag.
	 *
	 * @param string[]      $urls     Absolute URLs.
	 * @param Settings|null $settings Injectable for tests.
	 * @param Client|null   $client   Injectable for tests.
	 * @return void
	 */
	public static function purge_urls( array $urls, Settings $settings = null, Client $client = null ) {
		$settings = $settings ? $settings : new Settings();
		if ( ! $settings->connected() || empty( $urls ) ) {
			return;
		}
		$client = $client ? $client : new Client();
		$out    = $client->purge_urls( $settings->token(), (string) $settings->get( 'zone_id' ), $urls );
		$settings->record_purge( isset( $out['error'] ) ? (string) $out['error'] : '' );
	}

	/**
	 * Drop everything the edge holds for the zone — the owner's button, for the
	 * "this page keeps serving stale" moment. Outcome recorded the same way.
	 *
	 * @param Settings|null $settings Injectable for tests.
	 * @param Client|null   $client   Injectable for tests.
	 * @return array { ok: bool, error?: string }
	 */
	public static function purge_all( Settings $settings = null, Client $client = null ) {
		$settings = $settings ? $settings : new Settings();
		if ( ! $settings->connected() ) {
			return array( 'ok' => false, 'error' => __( 'Connect Cloudflare first.', 'agentimus' ) );
		}
		$client = $client ? $client : new Client();
		$out    = $client->purge_all( $settings->token(), (string) $settings->get( 'zone_id' ) );
		$error  = isset( $out['error'] ) ? (string) $out['error'] : '';
		$settings->record_purge( $error );
		return '' === $error ? array( 'ok' => true ) : array( 'ok' => false, 'error' => $error );
	}
}
