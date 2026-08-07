<?php
/**
 * Google Analytics 4 — the Data API client.
 *
 * The half of the audience the plugin could never see. Search Console reports
 * clicks it sent, and the referral store counts readers an assistant sent, but
 * everything else — direct, social, email, links from other sites — was simply
 * absent, which made "People" a partial number wearing a whole number's clothes.
 *
 * ⚠️ This talks to the property DIRECTLY, with the owner's own service-account
 * key, exactly as the Search Console client does. There is no Agentimus server
 * anywhere in the token flow and never will be: a hosted OAuth proxy makes the
 * vendor a permanent dependency of every customer's data, and the day that
 * vendor's infrastructure stops, so does everybody's reporting.
 *
 * ⚠️ GA4 is a SAMPLED, cookie-dependent, consent-dependent product. Its numbers
 * are not the request log's numbers and must never be presented as if they were:
 * a visitor who declines cookies is invisible here and present in the log. The
 * screens using this say so.
 *
 * @package Agentimus
 */

namespace Agentimus\Google;

defined( 'ABSPATH' ) || exit;

final class Analytics {

	/** @var string The Data API v1beta base. */
	const API = 'https://analyticsdata.googleapis.com/v1beta/';

	/** @var int Rows per report — top-pages lists are short by design. */
	const ROW_LIMIT = 25;

	/**
	 * Site totals for a window: people, their visits, and the pages they opened.
	 *
	 * One request, three metrics. `activeUsers` is the honest headline — sessions
	 * double-count a reader who comes back after lunch, and page views triple-count
	 * anyone who clicks through a site at all.
	 *
	 * @param string $token    Bearer token minted for the analytics scope.
	 * @param string $property The GA4 property ID (digits).
	 * @param string $start    Y-m-d start date.
	 * @param string $end      Y-m-d end date.
	 * @return array { totals?: array{users:int,sessions:int,views:int}, error?: string }
	 */
	public function totals( $token, $property, $start, $end ) {
		$out = $this->run_report(
			$token,
			$property,
			array(
				'dateRanges' => array( array( 'startDate' => (string) $start, 'endDate' => (string) $end ) ),
				'metrics'    => array(
					array( 'name' => 'activeUsers' ),
					array( 'name' => 'sessions' ),
					array( 'name' => 'screenPageViews' ),
				),
			)
		);
		if ( isset( $out['error'] ) ) {
			return $out;
		}

		$row = (array) ( ( $out['data']['rows'] ?? array() )[0] ?? array() );
		$mv  = (array) ( $row['metricValues'] ?? array() );

		return array(
			'totals' => array(
				'users'    => (int) round( (float) ( $mv[0]['value'] ?? 0 ) ),
				'sessions' => (int) round( (float) ( $mv[1]['value'] ?? 0 ) ),
				'views'    => (int) round( (float) ( $mv[2]['value'] ?? 0 ) ),
			),
		);
	}

	/**
	 * The busiest pages in a window — path, views, users.
	 *
	 * @param string $token    Bearer token.
	 * @param string $property The GA4 property ID.
	 * @param string $start    Y-m-d start date.
	 * @param string $end      Y-m-d end date.
	 * @param int    $limit    Rows to ask for.
	 * @return array { pages?: array<int,array{path:string,views:int,users:int}>, error?: string }
	 */
	public function top_pages( $token, $property, $start, $end, $limit = self::ROW_LIMIT ) {
		$out = $this->run_report(
			$token,
			$property,
			array(
				'dateRanges' => array( array( 'startDate' => (string) $start, 'endDate' => (string) $end ) ),
				'dimensions' => array( array( 'name' => 'pagePath' ) ),
				'metrics'    => array( array( 'name' => 'screenPageViews' ), array( 'name' => 'activeUsers' ) ),
				'orderBys'   => array( array( 'desc' => true, 'metric' => array( 'metricName' => 'screenPageViews' ) ) ),
				'limit'      => max( 1, min( 200, (int) $limit ) ),
			)
		);
		if ( isset( $out['error'] ) ) {
			return $out;
		}

		$pages = array();
		foreach ( (array) ( $out['data']['rows'] ?? array() ) as $row ) {
			$path = (string) ( ( (array) ( $row['dimensionValues'] ?? array() ) )[0]['value'] ?? '' );
			if ( '' === $path ) {
				continue;
			}
			$mv      = (array) ( $row['metricValues'] ?? array() );
			$pages[] = array(
				'path'  => $path,
				'views' => (int) round( (float) ( $mv[0]['value'] ?? 0 ) ),
				'users' => (int) round( (float) ( $mv[1]['value'] ?? 0 ) ),
			);
		}
		return array( 'pages' => $pages );
	}

	/**
	 * Confirm a property ID actually answers for this key, before it is saved.
	 *
	 * A property the key cannot read fails at REPORT time otherwise — hours later,
	 * on a cron, where the owner never sees it and the screen just stays empty.
	 *
	 * @param string $token    Bearer token.
	 * @param string $property The GA4 property ID.
	 * @return array { ok?: true, error?: string }
	 */
	public function verify( $token, $property ) {
		$today = gmdate( 'Y-m-d' );
		$out   = $this->totals( $token, $property, gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), $today );
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		return array( 'ok' => true );
	}

	/**
	 * POST a runReport body and normalise the transport/API failure modes.
	 *
	 * @param string $token    Bearer token.
	 * @param string $property GA4 property ID (digits only — anything else is refused here).
	 * @param array  $body     The runReport request body.
	 * @return array { data?: array, error?: string }
	 */
	private function run_report( $token, $property, array $body ) {
		$property = preg_replace( '/[^0-9]/', '', (string) $property );
		if ( '' === (string) $property ) {
			return array( 'error' => __( 'That GA4 property ID doesn’t look right — it’s the numeric ID from Admin → Property details, not the measurement ID that starts with G-.', 'agentimus' ) );
		}

		$response = wp_remote_post(
			self::API . 'properties/' . rawurlencode( $property ) . ':runReport',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . (string) $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			// Google's own words where it has any — an owner can act on "caller does
			// not have permission" and can do nothing at all with "HTTP 403".
			$detail = is_array( $json ) && isset( $json['error']['message'] ) ? (string) $json['error']['message'] : '';
			if ( '' === $detail && 403 === $code ) {
				$detail = __( 'Google refused the request. The usual cause: the service account isn’t added to this GA4 property as a viewer yet.', 'agentimus' );
			}
			return array( 'error' => '' !== $detail ? $detail : sprintf( /* translators: %d: HTTP status code. */ __( 'Google Analytics answered with status %d.', 'agentimus' ), $code ) );
		}
		if ( ! is_array( $json ) ) {
			return array( 'error' => __( 'Google Analytics returned something that isn’t JSON.', 'agentimus' ) );
		}
		return array( 'data' => $json );
	}
}
