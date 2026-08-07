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

	/**
	 * @var int Rows per request — Google's actual per-request maximum.
	 *
	 * This said 5,000 and called 5,000 the API maximum. It never was: the
	 * documented ceiling is 25,000, and the difference is not academic, because
	 * rows come back sorted by clicks descending. A low cap does not sample the
	 * window, it removes the quiet end of it — the pages earning a few clicks
	 * each, which are the ones the worklist exists to find.
	 */
	const ROW_LIMIT = 25000;

	/**
	 * @var int Requests per report before we stop asking.
	 *
	 * 4 × 25,000 = 100,000 page/query pairs, far beyond any site this plugin
	 * runs on, and a hard backstop against a paging loop that never ends
	 * because a property keeps answering. Paging STOPS EARLY the moment a
	 * response comes back short, which is the normal exit.
	 */
	const MAX_PAGES = 4;

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
	 * The sitemaps REGISTERED for this property in Search Console, with
	 * Google's own fetch bookkeeping. The on-site file can be perfect while
	 * the registration is dead (a moved path, an old plugin's address) — a
	 * silent failure no on-site check can see; only this list can.
	 *
	 * @param string $token    Bearer token.
	 * @param string $property The GSC property.
	 * @return array { sitemaps?: array<int,array{path:string,pending:bool,last_downloaded:int,errors:int,warnings:int,submitted:int}>, error?: string }
	 */
	public function sitemaps( $token, $property ) {
		$out = $this->request( 'GET', 'sites/' . rawurlencode( (string) $property ) . '/sitemaps', $token );
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		$list = array();
		foreach ( (array) ( $out['data']['sitemap'] ?? array() ) as $row ) {
			$submitted = 0;
			foreach ( (array) ( $row['contents'] ?? array() ) as $content ) {
				$submitted += (int) ( $content['submitted'] ?? 0 );
			}
			$down   = isset( $row['lastDownloaded'] ) ? (int) strtotime( (string) $row['lastDownloaded'] ) : 0;
			$list[] = array(
				'path'            => (string) ( $row['path'] ?? '' ),
				'pending'         => ! empty( $row['isPending'] ),
				'last_downloaded' => $down > 0 ? $down : 0,
				'errors'          => (int) ( $row['errors'] ?? 0 ),
				'warnings'        => (int) ( $row['warnings'] ?? 0 ),
				'submitted'       => $submitted,
			);
		}
		return array( 'sitemaps' => $list );
	}

	/**
	 * Query×page performance for a window, most impressions first — one row
	 * per (page, query) with clicks, impressions and average position exactly
	 * as Search Console reports them.
	 *
	 * Paged: Search Console caps one response at ROW_LIMIT rows and hands the
	 * rest back through startRow. A single request therefore returned only the
	 * loudest slice of the window and said nothing about the rest — so the walk
	 * continues until a response comes back SHORT, which is how the API says
	 * "that was the last of them".
	 *
	 * A page that errors mid-walk does NOT discard the pages already collected:
	 * a partial window is still true about the rows it holds, and the caller
	 * replaces its snapshot with something rather than nothing. The error is
	 * only returned when the FIRST request fails, i.e. when there is nothing.
	 *
	 * @param string $token    Bearer token.
	 * @param string $property The GSC property (sc-domain:… or a URL prefix).
	 * @param string $start    Y-m-d start date.
	 * @param string $end      Y-m-d end date.
	 * @return array { rows?: array<int,array{page:string,query:string,clicks:int,impressions:int,position:float}>, error?: string }
	 */
	public function search_analytics( $token, $property, $start, $end ) {
		$rows = array();

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$out = $this->request(
				'POST',
				'sites/' . rawurlencode( (string) $property ) . '/searchAnalytics/query',
				$token,
				array(
					'startDate'  => (string) $start,
					'endDate'    => (string) $end,
					'dimensions' => array( 'page', 'query' ),
					'rowLimit'   => self::ROW_LIMIT,
					'startRow'   => $page * self::ROW_LIMIT,
				)
			);
			if ( isset( $out['error'] ) ) {
				if ( 0 === $page ) {
					return $out;
				}
				break;
			}

			$batch = (array) ( $out['data']['rows'] ?? array() );
			foreach ( $batch as $row ) {
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

			// Short answer = the end of the window. The overwhelming majority of
			// sites exit here on the first pass, having made exactly one call.
			if ( count( $batch ) < self::ROW_LIMIT ) {
				break;
			}
		}

		return array( 'rows' => $rows );
	}

	/**
	 * Clicks and impressions PER DAY for a window — the series behind
	 * "this week vs last". One row per date, exactly as Search Console
	 * reports it; no query/page split, so the row count stays tiny.
	 *
	 * @param string $token    Bearer token.
	 * @param string $property The GSC property.
	 * @param string $start    Y-m-d start date.
	 * @param string $end      Y-m-d end date.
	 * @return array { days?: array<int,array{date:string,clicks:int,impressions:int}>, error?: string }
	 */
	public function search_analytics_daily( $token, $property, $start, $end ) {
		$out = $this->request(
			'POST',
			'sites/' . rawurlencode( (string) $property ) . '/searchAnalytics/query',
			$token,
			array(
				'startDate'  => (string) $start,
				'endDate'    => (string) $end,
				'dimensions' => array( 'date' ),
				'rowLimit'   => self::ROW_LIMIT,
			)
		);
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		$days = array();
		foreach ( (array) ( $out['data']['rows'] ?? array() ) as $row ) {
			$keys = (array) ( $row['keys'] ?? array() );
			if ( empty( $keys[0] ) ) {
				continue;
			}
			$days[] = array(
				'date'        => (string) $keys[0],
				'clicks'      => (int) round( (float) ( $row['clicks'] ?? 0 ) ),
				'impressions' => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
			);
		}
		return array( 'days' => $days );
	}

	/**
	 * Google Discover totals for a window — the feed surface most owners never
	 * know they appear on. No dimensions: one aggregate row (Discover has no
	 * queries, and position means nothing there).
	 *
	 * @param string $token    Bearer token.
	 * @param string $property The GSC property.
	 * @param string $start    Y-m-d start date.
	 * @param string $end      Y-m-d end date.
	 * @return array { totals?: array{impressions:int,clicks:int}, error?: string }
	 */
	public function discover_totals( $token, $property, $start, $end ) {
		$out = $this->request(
			'POST',
			'sites/' . rawurlencode( (string) $property ) . '/searchAnalytics/query',
			$token,
			array(
				'startDate' => (string) $start,
				'endDate'   => (string) $end,
				'type'      => 'discover',
			)
		);
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		$row = (array) ( ( $out['data']['rows'] ?? array() )[0] ?? array() );
		return array(
			'totals' => array(
				'impressions' => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
				'clicks'      => (int) round( (float) ( $row['clicks'] ?? 0 ) ),
			),
		);
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
		// 15s, not the default 20: inspections run in budgeted chunks, so one
		// hung call has to leave a chunk's worst case bounded — but Google
		// routinely takes close to 10s over a real answer, and a tighter cap
		// cut those off mid-flight ("cURL error 28" every few URLs).
		$out = $this->request( 'POST', self::INSPECT_API, $token, array(
			'inspectionUrl' => (string) $url,
			'siteUrl'       => (string) $property,
		), 15 );
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
				// How many pages Google knows link to this URL. Zero + not
				// indexed names the OTHER discovery lever: nothing points here.
				// (Google itself caps the list it reports — treat the count as
				// "at least", never print it as exhaustive.)
				'referrers'        => count( (array) ( $isr['referringUrls'] ?? array() ) ),
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
	private function request( $method, $path, $token, ?array $body = null, $timeout = 20 ) {
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
