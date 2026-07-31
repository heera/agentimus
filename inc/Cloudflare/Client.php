<?php
/**
 * Cloudflare API client — the three calls the edge panel needs: verify a
 * token, find the site's zone, and read hourly HTTP totals from the GraphQL
 * Analytics API. Plain wp_remote_*, no SDK.
 *
 * Every method returns a normalized array and never throws: `{ ok/…data }` on
 * success, `{ error: "…" }` on any failure — the fail-open doctrine. A 200
 * whose expected container is ABSENT is surfaced as an error, not recorded as
 * real empty data, so a changed response format can't silently poison the
 * stored aggregates.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

defined( 'ABSPATH' ) || exit;

final class Client {

	/** @var string REST base. */
	const API = 'https://api.cloudflare.com/client/v4';

	/** @var int Per-request timeout (seconds) — an analytics read, not a chat call. */
	const TIMEOUT = 15;

	/** @var int Rows per GraphQL window; ordered busiest-first so the tail is what's shed. */
	const GRAPHQL_LIMIT = 5000;

	/**
	 * Check a token is valid at all (any scope) — Cloudflare's own verify call.
	 *
	 * @param string $token API token, plaintext.
	 * @return array { ok?: true, error?: string }
	 */
	public function verify_token( $token ) {
		$out = $this->get_json( self::API . '/user/tokens/verify', $token );
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		if ( empty( $out['json']['success'] ) ) {
			return array( 'error' => $this->api_error( $out['json'] ) );
		}
		return array( 'ok' => true );
	}

	/**
	 * Find the zone this site lives in. Tries the site's own host first, then
	 * walks up the labels (www.blog.example.com → blog.example.com →
	 * example.com), because the zone is usually the registrable domain while
	 * the site may sit on a subdomain.
	 *
	 * @param string $token API token, plaintext.
	 * @param string $host  The site's host name.
	 * @return array { id?: string, name?: string, error?: string }
	 */
	public function find_zone( $token, $host ) {
		$host   = strtolower( trim( (string) $host ) );
		$labels = array_filter( explode( '.', $host ) );
		$tried  = array();

		while ( count( $labels ) >= 2 ) {
			$candidate = implode( '.', $labels );
			$tried[]   = $candidate;
			$out       = $this->get_json(
				self::API . '/zones?' . http_build_query( array( 'name' => $candidate, 'per_page' => 1 ) ),
				$token
			);
			if ( isset( $out['error'] ) ) {
				return $out; // A transport/auth failure — walking further up can't fix it.
			}
			if ( ! empty( $out['json']['result'][0]['id'] ) ) {
				return array(
					'id'   => (string) $out['json']['result'][0]['id'],
					'name' => (string) $out['json']['result'][0]['name'],
				);
			}
			array_shift( $labels );
		}

		return array(
			'error' => sprintf(
				/* translators: %s: the host names that were tried. */
				__( 'No Cloudflare zone matches this site (tried %s). Check the token can read this zone.', 'agentimus' ),
				implode( ', ', $tried )
			),
		);
	}

	/**
	 * Hourly HTTP request rows for a time window, one row per distinct
	 * (hour, user agent, cache status, edge status, origin status) — the raw
	 * material the Module aggregates into per-crawler numbers.
	 *
	 * @param string $token   API token, plaintext.
	 * @param string $zone_id Zone tag.
	 * @param int    $since   Window start, Unix UTC.
	 * @param int    $until   Window end, Unix UTC.
	 * @return array { rows?: array<int,array>, error?: string }
	 */
	public function hourly_traffic( $token, $zone_id, $since, $until ) {
		$query = 'query ($zone: String!, $since: Time!, $until: Time!) {
			viewer {
				zones(filter: { zoneTag: $zone }) {
					httpRequestsAdaptiveGroups(
						limit: ' . self::GRAPHQL_LIMIT . ',
						filter: { datetime_geq: $since, datetime_lt: $until, requestSource: "eyeball" },
						orderBy: [count_DESC]
					) {
						count
						sum { edgeResponseBytes }
						dimensions { datetimeHour userAgent cacheStatus edgeResponseStatus originResponseStatus }
					}
				}
			}
		}';

		$out = $this->graphql( $token, $query, array(
			'zone'  => (string) $zone_id,
			'since' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $since ),
			'until' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $until ),
		) );
		if ( isset( $out['error'] ) ) {
			return $out;
		}

		// The expected container, or it's an error — never "real empty data".
		if ( ! isset( $out['json']['data']['viewer']['zones'][0]['httpRequestsAdaptiveGroups'] ) ) {
			return array( 'error' => __( 'Cloudflare returned an unexpected response shape.', 'agentimus' ) );
		}
		$groups = (array) $out['json']['data']['viewer']['zones'][0]['httpRequestsAdaptiveGroups'];

		$rows = array();
		foreach ( $groups as $g ) {
			if ( ! isset( $g['dimensions'] ) ) {
				continue;
			}
			$d      = (array) $g['dimensions'];
			$rows[] = array(
				'hour'          => (string) ( isset( $d['datetimeHour'] ) ? $d['datetimeHour'] : '' ),
				'ua'            => (string) ( isset( $d['userAgent'] ) ? $d['userAgent'] : '' ),
				'cache_status'  => strtolower( (string) ( isset( $d['cacheStatus'] ) ? $d['cacheStatus'] : '' ) ),
				'edge_status'   => (int) ( isset( $d['edgeResponseStatus'] ) ? $d['edgeResponseStatus'] : 0 ),
				'origin_status' => (int) ( isset( $d['originResponseStatus'] ) ? $d['originResponseStatus'] : 0 ),
				'requests'      => (int) ( isset( $g['count'] ) ? $g['count'] : 0 ),
				'bytes'         => (int) ( isset( $g['sum']['edgeResponseBytes'] ) ? $g['sum']['edgeResponseBytes'] : 0 ),
			);
		}

		return array( 'rows' => $rows );
	}

	/**
	 * POST one GraphQL query.
	 *
	 * @param string $token     API token, plaintext.
	 * @param string $query     GraphQL document.
	 * @param array  $variables Query variables.
	 * @return array { json?: array, error?: string }
	 */
	private function graphql( $token, $query, array $variables ) {
		$response = wp_remote_post( self::API . '/graphql', array(
			'timeout'            => self::TIMEOUT,
			'reject_unsafe_urls' => true,
			'headers'            => array(
				'authorization' => 'Bearer ' . $token,
				'content-type'  => 'application/json',
			),
			'body'               => wp_json_encode( array( 'query' => $query, 'variables' => $variables ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return array( 'error' => $this->api_error( $json, $code ) );
		}
		// GraphQL reports failures as 200 + an errors list.
		if ( ! empty( $json['errors'][0]['message'] ) ) {
			return array( 'error' => (string) $json['errors'][0]['message'] );
		}
		return array( 'json' => is_array( $json ) ? $json : array() );
	}

	/**
	 * GET one REST endpoint with the bearer token.
	 *
	 * @param string $url   Full URL.
	 * @param string $token API token, plaintext.
	 * @return array { json?: array, error?: string }
	 */
	private function get_json( $url, $token ) {
		$response = wp_remote_get( $url, array(
			'timeout'            => self::TIMEOUT,
			'redirection'        => 2,
			'reject_unsafe_urls' => true,
			'headers'            => array( 'authorization' => 'Bearer ' . $token ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return array( 'error' => $this->api_error( $json, $code ) );
		}
		return array( 'json' => is_array( $json ) ? $json : array() );
	}

	/**
	 * Pull a human-readable message out of a Cloudflare error body, falling
	 * back to the HTTP status.
	 *
	 * @param mixed $json Decoded body (may be null).
	 * @param int   $code HTTP status.
	 * @return string
	 */
	private function api_error( $json, $code = 0 ) {
		if ( is_array( $json ) && ! empty( $json['errors'][0]['message'] ) ) {
			return (string) $json['errors'][0]['message'];
		}
		if ( $code > 0 ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Cloudflare request failed (HTTP %d).', 'agentimus' ), $code );
		}
		return __( 'Cloudflare request failed.', 'agentimus' );
	}
}
