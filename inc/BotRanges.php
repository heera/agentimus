<?php
/**
 * BotRanges — verification against an operator's PUBLISHED IP-range file.
 *
 * The second verification method next to reverse DNS: operators like OpenAI
 * (gptbot.json) and Perplexity publish the exact address ranges their crawlers
 * use, in the `{"prefixes":[{"ipv4Prefix":…}]}` format Google introduced. A
 * visitor claiming such a bot either arrives from inside those ranges or it
 * doesn't — no DNS involved.
 *
 * SAFETY MODEL — nothing here can break a request:
 *  - The serve path NEVER fetches. Range files are pulled by a daily cron (plus
 *    a one-off catch-up event when a file is missing); a hit is only ever
 *    compared against the local cache. An unreachable publisher costs zero
 *    request latency.
 *  - Verdicts are ASYMMETRIC on staleness. A match verifies (1) against any
 *    cached copy — ranges are near-append-only, so an old file's positive is
 *    still sound. A NON-match condemns (2) only while the copy is fresh
 *    ({@see FRESH_TTL}); past that it's 0 (unchecked), because "not in a stale
 *    list" may just mean the operator added addresses we haven't fetched yet.
 *    Publisher down → files go stale → verdicts fade to unchecked. Fail open,
 *    never false accusation.
 *  - Fetches are bounded: https only, WP's unsafe-URL rejection (no private
 *    hosts), response size capped, prefix count capped.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class BotRanges {

	/** Cached range files: token => { url, fetched_at, prefixes[] }. Autoload off. */
	const OPTION = 'agentimus_bot_ranges';

	/** Daily refresh cron + the transient guarding the one-off catch-up event. */
	const CRON        = 'agentimus_refresh_bot_ranges';
	const PENDING_KEY = 'agentimus_ranges_pending';

	/** A non-match may condemn only while the cached copy is at most this old. */
	const FRESH_TTL = 2 * DAY_IN_SECONDS;

	/** Fetch bounds: response size and prefix-count caps. */
	const MAX_BYTES    = 262144;
	const MAX_PREFIXES = 5000;

	/**
	 * Hook the cron handler and self-heal its schedule (mirrors the Activity module's
	 * pattern — activation-only scheduling misses new multisite sites).
	 */
	public static function boot() {
		add_action( self::CRON, array( self::class, 'refresh' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Clear both scheduled events (deactivation).
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Serve-path verdict for a client claiming registry entry $token, from the CACHE
	 * only — no HTTP, ever. Missing or superseded cache rows queue a background
	 * catch-up fetch and answer 0 (unchecked) meanwhile.
	 *
	 * @param string $token Registry entry token.
	 * @param string $ip    Source IP.
	 * @return int 0 = unchecked/inconclusive, 1 = verified, 2 = outside published ranges.
	 */
	public static function verdict( $token, $ip ) {
		$entry = VerifierRegistry::entry( $token );
		if ( ! $entry || '' === (string) $entry['url'] || '' === (string) $ip ) {
			return 0;
		}
		$rows = get_option( self::OPTION, array() );
		$row  = ( is_array( $rows ) && isset( $rows[ $token ] ) ) ? $rows[ $token ] : null;
		// No cached copy yet, or the entry's URL changed since it was fetched (an owner
		// edit) — the copy no longer represents this entry. Queue a fetch, stay unchecked.
		if ( ! is_array( $row ) || ( isset( $row['url'] ) ? (string) $row['url'] : '' ) !== (string) $entry['url'] ) {
			self::request_refresh();
			return 0;
		}
		$verdict = self::verdict_from_row( $row, (string) $ip, time() );
		if ( 0 === $verdict ) {
			// Stale or empty — try to get fresher data for next time, in the background.
			self::request_refresh();
		}
		return $verdict;
	}

	/**
	 * Admin "Re-check" verdict: same comparison, but an admin click may afford one
	 * inline fetch when the cached copy is missing/stale (it's capability-gated and
	 * already throttled by the caller's DNS budget — not the serve-path threat model).
	 *
	 * @param string $token Registry entry token.
	 * @param string $ip    Source IP.
	 * @return int 0|1|2 as {@see verdict()}.
	 */
	public static function recheck( $token, $ip ) {
		$entry = VerifierRegistry::entry( $token );
		if ( ! $entry || '' === (string) $entry['url'] || '' === (string) $ip ) {
			return 0;
		}
		$rows = get_option( self::OPTION, array() );
		$row  = ( is_array( $rows ) && isset( $rows[ $token ] ) ) ? $rows[ $token ] : null;
		$now  = time();

		$have_fresh = is_array( $row )
			&& ( isset( $row['url'] ) ? (string) $row['url'] : '' ) === (string) $entry['url']
			&& ( $now - (int) $row['fetched_at'] ) <= self::FRESH_TTL;

		if ( ! $have_fresh ) {
			$fetched = self::fetch( (string) $entry['url'] );
			if ( null !== $fetched ) {
				$row = array(
					'url'        => (string) $entry['url'],
					'fetched_at' => $now,
					'prefixes'   => $fetched,
				);
				self::store_row( $token, $row );
			}
		}
		return is_array( $row ) ? self::verdict_from_row( $row, (string) $ip, $now ) : 0;
	}

	/**
	 * PURE verdict from one cached row — the staleness asymmetry lives here, testable
	 * without WP. Match → 1 whatever the age (ranges are near-append-only, an old
	 * positive is still sound). Non-match → 2 only while fresh; a stale copy may
	 * simply predate newly added addresses, so it reads 0, never a false "spoofed".
	 *
	 * @param array  $row Cached row { fetched_at, prefixes[] }.
	 * @param string $ip  Source IP.
	 * @param int    $now Current GMT unix time (injected for tests).
	 * @return int 0|1|2.
	 */
	public static function verdict_from_row( $row, $ip, $now ) {
		$prefixes = isset( $row['prefixes'] ) ? (array) $row['prefixes'] : array();
		if ( empty( $prefixes ) || '' === (string) $ip ) {
			return 0;
		}
		foreach ( $prefixes as $cidr ) {
			if ( ClientIp::in_range( (string) $ip, (string) $cidr ) ) {
				return 1;
			}
		}
		$age = $now - ( isset( $row['fetched_at'] ) ? (int) $row['fetched_at'] : 0 );
		return ( $age >= 0 && $age <= self::FRESH_TTL ) ? 2 : 0;
	}

	/**
	 * Cron: fetch every registry entry's range file. A failed fetch KEEPS the previous
	 * copy (stale positives still verify; the verdict asymmetry handles the rest) —
	 * a publisher outage degrades to "unchecked", never to an error anywhere.
	 * Skipped entirely when nothing would consume the data.
	 */
	public static function refresh() {
		$settings = new Settings();
		if ( ! $settings->enabled( 'verify_bots' ) && ! $settings->enabled( 'identify_bots' ) ) {
			return;
		}
		$rows = get_option( self::OPTION, array() );
		$rows = is_array( $rows ) ? $rows : array();
		$seen = array();

		foreach ( VerifierRegistry::entries() as $token => $entry ) {
			$url = (string) $entry['url'];
			if ( '' === $url ) {
				continue;
			}
			$seen[ $token ] = true;
			$fetched        = self::fetch( $url );
			if ( null === $fetched ) {
				// Keep an existing copy for the SAME url (stale-open); drop one that
				// belonged to a different url — it no longer describes this entry.
				if ( isset( $rows[ $token ] ) && ( (string) $rows[ $token ]['url'] ) !== $url ) {
					unset( $rows[ $token ] );
				}
				continue;
			}
			$rows[ $token ] = array(
				'url'        => $url,
				'fetched_at' => time(),
				'prefixes'   => $fetched,
			);
		}

		// Rows whose token left the registry (entry removed/disabled) are dead weight.
		foreach ( array_keys( $rows ) as $token ) {
			if ( ! isset( $seen[ $token ] ) ) {
				unset( $rows[ $token ] );
			}
		}
		self::store_all( $rows );
	}

	/**
	 * Queue ONE near-term refresh (first use after enabling, an owner edit, or a stale
	 * cache) without ever fetching inline on the serve path. Transient-guarded so a
	 * burst of hits queues a single event, and the guard outlives the event slightly
	 * so a failing fetch can't be re-queued in a tight loop.
	 */
	public static function request_refresh() {
		if ( get_transient( self::PENDING_KEY ) ) {
			return;
		}
		set_transient( self::PENDING_KEY, 1, 15 * MINUTE_IN_SECONDS );
		if ( ! wp_next_scheduled( self::CRON ) || wp_next_scheduled( self::CRON ) > time() + MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
	}

	/**
	 * PURE parser for a range-file body. Accepts the shared publisher format
	 * (`{"prefixes":[{"ipv4Prefix":"…"},{"ipv6Prefix":"…"}]}`) and, for owner-added
	 * URLs, a plain JSON array of CIDR strings. Every value is validated as an
	 * IP/CIDR; bad values are dropped, the count is capped. Null = unusable body.
	 *
	 * @param string $body Raw response body.
	 * @return string[]|null CIDR list, or null when the body isn't a range file.
	 */
	public static function parse_prefixes( $body ) {
		$data = json_decode( (string) $body, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$raw = array();
		if ( isset( $data['prefixes'] ) && is_array( $data['prefixes'] ) ) {
			foreach ( $data['prefixes'] as $p ) {
				if ( isset( $p['ipv4Prefix'] ) ) {
					$raw[] = (string) $p['ipv4Prefix'];
				}
				if ( isset( $p['ipv6Prefix'] ) ) {
					$raw[] = (string) $p['ipv6Prefix'];
				}
			}
		} else {
			foreach ( $data as $p ) {
				if ( is_string( $p ) ) {
					$raw[] = $p;
				}
			}
		}

		$out = array();
		foreach ( $raw as $cidr ) {
			$cidr = trim( $cidr );
			$ip   = ( false !== strpos( $cidr, '/' ) ) ? substr( $cidr, 0, (int) strpos( $cidr, '/' ) ) : $cidr;
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			$out[] = $cidr;
			if ( count( $out ) >= self::MAX_PREFIXES ) {
				break;
			}
		}
		return empty( $out ) ? null : $out;
	}

	/**
	 * Admin probe of a candidate range-file URL — the settings form's "Add bot" check,
	 * so an owner can't save a URL that could never verify anyone (any https address
	 * would otherwise pass, including a homepage). One bounded fetch + parse, with the
	 * failure named honestly: unreachable is not the same problem as wrong format.
	 *
	 * @param string $url Candidate URL.
	 * @return array{ok:bool,prefixes?:int,reason?:string} reason: 'not-https' | 'unreachable' | 'not-a-range-file'.
	 */
	public static function probe( $url ) {
		$url = trim( (string) $url );
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'not-https',
			);
		}
		$body = self::get_body( $url );
		if ( null === $body ) {
			return array(
				'ok'     => false,
				'reason' => 'unreachable',
			);
		}
		$prefixes = self::parse_prefixes( $body );
		if ( null === $prefixes ) {
			return array(
				'ok'     => false,
				'reason' => 'not-a-range-file',
			);
		}
		return array(
			'ok'       => true,
			'prefixes' => count( $prefixes ),
		);
	}

	/**
	 * One bounded https fetch → validated prefix list, or null on any failure.
	 *
	 * @param string $url Range-file URL.
	 * @return string[]|null
	 */
	private static function fetch( $url ) {
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return null;
		}
		$body = self::get_body( $url );
		return null === $body ? null : self::parse_prefixes( $body );
	}

	/**
	 * The shared bounded GET: https only, WP's unsafe-URL rejection (no private hosts),
	 * capped size. Null unless the server answered 200.
	 *
	 * @param string $url URL to fetch.
	 * @return string|null Response body, or null.
	 */
	private static function get_body( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 2,
				'reject_unsafe_urls'  => true, // No private hosts — an owner-typed URL can't probe the LAN.
				'limit_response_size' => self::MAX_BYTES,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Persist one row (autoload off — this is bot-path data, not page-load data).
	 *
	 * @param string $token Registry token.
	 * @param array  $row   Row to store.
	 */
	private static function store_row( $token, array $row ) {
		$rows = get_option( self::OPTION, array() );
		$rows = is_array( $rows ) ? $rows : array();

		$rows[ $token ] = $row;
		self::store_all( $rows );
	}

	/**
	 * Persist the full map, creating the option with autoload OFF.
	 *
	 * @param array $rows Token => row.
	 */
	private static function store_all( array $rows ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $rows, '', 'no' );
			return;
		}
		update_option( self::OPTION, $rows, false );
	}
}
