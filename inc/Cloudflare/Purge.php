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

	/** @var int Automatic-path timeout, seconds — a save's tail must stay short. */
	const AUTO_TIMEOUT = 5;

	/**
	 * Whether the edge purge can run at all — a connected zone is the gate.
	 * (Whether the token may PURGE is Cloudflare's call, made per request and
	 * surfaced in words when refused.)
	 *
	 * @param Settings|null $settings Injectable for tests.
	 * @return bool
	 */
	public static function available( ?Settings $settings = null ) {
		$settings = $settings ? $settings : new Settings();
		return $settings->connected();
	}

	/**
	 * Whether the AUTOMATIC content-change purge should run: connected, the
	 * owner's switch is on, and no permission refusal is standing it down.
	 * The manual button deliberately ignores all but the connection — pressing
	 * it IS the consent, and a fresh attempt is how a fixed token re-arms.
	 *
	 * @param Settings|null $settings Injectable for tests.
	 * @return bool
	 */
	public static function armed( ?Settings $settings = null ) {
		$settings = $settings ? $settings : new Settings();
		return $settings->connected()
			&& (bool) ( new \Agentimus\Settings() )->get( 'cf_purge_on_change', true )
			&& ! $settings->purge_denied();
	}

	/**
	 * Drop specific URLs from the edge cache — the AUTOMATIC path. Quiet no-op
	 * unless {@see armed()}; otherwise the outcome — clean or failed — is
	 * recorded on the connection so the panel's rail can tell the owner without
	 * a nag: a permission refusal (401/403) records ONCE and stands the
	 * automatic path down until a reconnect or a clean manual purge re-arms it.
	 *
	 * @param string[]      $urls     Absolute URLs.
	 * @param Settings|null $settings Injectable for tests.
	 * @param Client|null   $client   Injectable for tests.
	 * @return void
	 */
	public static function purge_urls( array $urls, ?Settings $settings = null, ?Client $client = null ) {
		$settings = $settings ? $settings : new Settings();
		if ( ! self::armed( $settings ) || empty( $urls ) ) {
			return;
		}
		$client = $client ? $client : new Client();
		$out    = $client->purge_urls( $settings->token(), (string) $settings->get( 'zone_id' ), $urls, self::AUTO_TIMEOUT );
		$settings->record_purge(
			isset( $out['error'] ) ? (string) $out['error'] : '',
			self::is_refusal( $out )
		);
	}

	/**
	 * A permission refusal, as opposed to a transient fault: 401/403 means the
	 * token cannot purge and every retry would fail identically; anything else
	 * (a timeout, a 5xx) deserves a fresh attempt on the next occasion.
	 *
	 * @param array $out A Client purge result.
	 * @return bool
	 */
	private static function is_refusal( array $out ) {
		$status = (int) ( isset( $out['status'] ) ? $out['status'] : 0 );
		return isset( $out['error'] ) && in_array( $status, array( 401, 403 ), true );
	}

	/**
	 * Drop everything the edge holds for the zone — the owner's button, for the
	 * "this page keeps serving stale" moment. Outcome recorded the same way.
	 *
	 * @param Settings|null $settings Injectable for tests.
	 * @param Client|null   $client   Injectable for tests.
	 * @return array { ok: bool, error?: string }
	 */
	public static function purge_all( ?Settings $settings = null, ?Client $client = null ) {
		$settings = $settings ? $settings : new Settings();
		if ( ! $settings->connected() ) {
			return array( 'ok' => false, 'error' => __( 'Connect Cloudflare first.', 'agentimus' ) );
		}
		$client = $client ? $client : new Client();
		$out    = $client->purge_all( $settings->token(), (string) $settings->get( 'zone_id' ) );
		$error  = isset( $out['error'] ) ? (string) $out['error'] : '';
		// A clean manual purge re-arms the automatic path (record_purge clears
		// the denial); a refused one stands it down — same verdict either way.
		$settings->record_purge( $error, self::is_refusal( $out ) );
		return '' === $error ? array( 'ok' => true ) : array( 'ok' => false, 'error' => $error );
	}
}
