<?php
/**
 * Google Search Console API client — the two calls the data source needs:
 * list the properties the service account can see (to match this site), and
 * read query×page search performance.
 *
 * Plain REST with a bearer token from {@see Auth}; every response is
 * normalized so no caller sees Google's envelope shapes.
 *
 * @package Agentimus
 */

namespace Agentimus\Google;

defined( 'ABSPATH' ) || exit;

final class Client {

	/** @var string API base. */
	const API = 'https://www.googleapis.com/webmasters/v3/';

	/** @var int Row cap per report — Google's own per-request maximum. */
	const ROW_LIMIT = 5000;

	/**
	 * The properties this key can read: [ { property, permission } … ].
	 *
	 * @param string $token Bearer token.
	 * @return array { sites?: array, error?: string }
	 */
	public function sites( $token ) {
		$out = $this->request( 'GET', 'sites', $token );
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		$sites = array();
		foreach ( (array) ( $out['data']['siteEntry'] ?? array() ) as $row ) {
			$sites[] = array(
				'property'   => (string) ( $row['siteUrl'] ?? '' ),
				'permission' => (string) ( $row['permissionLevel'] ?? '' ),
			);
		}
		return array( 'sites' => $sites );
	}

	/**
	 * Query×page performance for a window, most impressions first — one row
	 * per (page, query) with clicks, impressions and average position exactly
	 * as Search Console reports them.
	 *
	 * @param string $token    Bearer token.
	 * @param string $property The GSC property (sc-domain:… or a URL prefix).
	 * @param string $start    Y-m-d start date.
	 * @param string $end      Y-m-d end date.
	 * @return array { rows?: array<int,array{page:string,query:string,clicks:int,impressions:int,position:float}>, error?: string }
	 */
	public function search_analytics( $token, $property, $start, $end ) {
		$out = $this->request(
			'POST',
			'sites/' . rawurlencode( (string) $property ) . '/searchAnalytics/query',
			$token,
			array(
				'startDate'  => (string) $start,
				'endDate'    => (string) $end,
				'dimensions' => array( 'page', 'query' ),
				'rowLimit'   => self::ROW_LIMIT,
			)
		);
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		$rows = array();
		foreach ( (array) ( $out['data']['rows'] ?? array() ) as $row ) {
			$keys = (array) ( $row['keys'] ?? array() );
			if ( count( $keys ) < 2 ) {
				continue;
			}
			$rows[] = array(
				'page'        => (string) $keys[0],
				'query'       => (string) $keys[1],
				'clicks'      => (int) round( (float) ( $row['clicks'] ?? 0 ) ),
				'impressions' => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 2 ),
			);
		}
		return array( 'rows' => $rows );
	}

	/**
	 * One authorized request, errors normalized to words.
	 *
	 * @param string     $method 'GET' or 'POST'.
	 * @param string     $path   Path under the API base.
	 * @param string     $token  Bearer token.
	 * @param array|null $body   JSON body for POST.
	 * @return array { data?: array, error?: string }
	 */
	private function request( $method, $path, $token, array $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . (string) $token,
				'Content-Type'  => 'application/json; charset=utf-8',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $json ) && isset( $json['error']['message'] ) ? (string) $json['error']['message'] : '';
			if ( 403 === $code && '' === $message ) {
				$message = __( 'Google refused access — is the service account added to the Search Console property?', 'agentimus' );
			}
			return array( 'error' => '' !== $message ? $message : sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Google answered with status %d.', 'agentimus' ),
				$code
			) );
		}
		return array( 'data' => is_array( $json ) ? $json : array() );
	}
}
