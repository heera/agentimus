<?php
/**
 * Google Search Console API client — the three calls the data source needs:
 * list the properties the service account can see (to match this site), read
 * query×page search performance, and inspect one URL's standing in Google's
 * index.
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

	/** @var string The URL Inspection API lives on a different host than the v3 reports. */
	const INSPECT_API = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

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
	 * One URL's standing in Google's index, via the URL Inspection API.
	 *
	 * Errors carry their HTTP status so the caller can tell the three failure
	 * classes apart: quota=true (today's inspection budget is spent — a state,
	 * not a fault), status 400 (this one URL's own problem, e.g. outside the
	 * property), anything else (token/transport — every further call would fail
	 * the same way).
	 *
	 * @param string $token    Bearer token.
	 * @param string $property The Search Console property owning the URL.
	 * @param string $url      The URL to inspect.
	 * @return array { result?: array, error?: string, status?: int, quota?: bool }
	 */
	public function inspect_url( $token, $property, $url ) {
		// 10s, not the default 20: inspections run in budgeted chunks, and one
		// hung call must not be able to double a chunk's worst case.
		$out = $this->request( 'POST', self::INSPECT_API, $token, array(
			'inspectionUrl' => (string) $url,
			'siteUrl'       => (string) $property,
		), 10 );
		if ( isset( $out['error'] ) ) {
			$status = isset( $out['status'] ) ? (int) $out['status'] : 0;
			return array(
				'error'  => $out['error'],
				'status' => $status,
				'quota'  => 429 === $status,
			);
		}

		$ir  = isset( $out['data']['inspectionResult'] ) && is_array( $out['data']['inspectionResult'] ) ? $out['data']['inspectionResult'] : array();
		$isr = isset( $ir['indexStatusResult'] ) && is_array( $ir['indexStatusResult'] ) ? $ir['indexStatusResult'] : array();

		// Google reports "never crawled" as the epoch; both become 0 here.
		$crawl_ts = isset( $isr['lastCrawlTime'] ) ? (int) strtotime( (string) $isr['lastCrawlTime'] ) : 0;

		// Rich results: the owner cares about the count of ISSUES and the types
		// detected; the full per-item tree is Search Console's job.
		$rich        = isset( $ir['richResultsResult'] ) && is_array( $ir['richResultsResult'] ) ? $ir['richResultsResult'] : array();
		$rich_types  = array();
		$rich_issues = 0;
		foreach ( (array) ( $rich['detectedItems'] ?? array() ) as $detected ) {
			if ( ! empty( $detected['richResultType'] ) ) {
				$rich_types[] = (string) $detected['richResultType'];
			}
			foreach ( (array) ( $detected['items'] ?? array() ) as $item ) {
				$rich_issues += count( (array) ( $item['issues'] ?? array() ) );
			}
		}

		return array(
			'result' => array(
				'verdict'          => strtoupper( (string) ( $isr['verdict'] ?? '' ) ),
				'coverage_state'   => (string) ( $isr['coverageState'] ?? '' ),
				'robots_state'     => strtoupper( (string) ( $isr['robotsTxtState'] ?? '' ) ),
				'indexing_state'   => strtoupper( (string) ( $isr['indexingState'] ?? '' ) ),
				'fetch_state'      => strtoupper( (string) ( $isr['pageFetchState'] ?? '' ) ),
				'last_crawl'       => $crawl_ts > 0 ? $crawl_ts : 0,
				'google_canonical' => (string) ( $isr['googleCanonical'] ?? '' ),
				'crawled_as'       => strtoupper( (string) ( $isr['crawledAs'] ?? '' ) ),
				// The sitemaps Google knows reference this URL — empty + not
				// indexed is the "nobody told Google" signal.
				'in_sitemap'       => ! empty( $isr['sitemap'] ),
				// Deep link to THIS URL's inspection in Search Console — where
				// "Request indexing" lives (that button has no API; the link is
				// the honest version of it).
				'gsc_link'         => (string) ( $ir['inspectionResultLink'] ?? '' ),
				'rich_types'       => implode( ', ', $rich_types ),
				'rich_issues'      => $rich_issues,
			),
		);
	}

	/**
	 * One authorized request, errors normalized to words.
	 *
	 * @param string     $method  'GET' or 'POST'.
	 * @param string     $path    Path under the API base, or an absolute https URL.
	 * @param string     $token   Bearer token.
	 * @param array|null $body    JSON body for POST.
	 * @param int        $timeout Per-request timeout, seconds.
	 * @return array { data?: array, error?: string, status?: int }
	 */
	private function request( $method, $path, $token, array $body = null, $timeout = 20 ) {
		$args = array(
			'method'  => $method,
			'timeout' => (int) $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . (string) $token,
				'Content-Type'  => 'application/json; charset=utf-8',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$url      = 0 === strpos( $path, 'https://' ) ? $path : self::API . $path;
		$response = wp_remote_request( $url, $args );
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
			return array(
				'status' => $code,
				'error'  => '' !== $message ? $message : sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Google answered with status %d.', 'agentimus' ),
					$code
				),
			);
		}
		return array( 'data' => is_array( $json ) ? $json : array() );
	}
}
