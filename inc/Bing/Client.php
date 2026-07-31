<?php
/**
 * Bing Webmaster Tools API client — the four calls the data source needs:
 * list the account's sites, add this one, ask Bing to verify it, and read
 * the daily crawl statistics.
 *
 * The API is Microsoft's legacy WCF-JSON service: GET reads and POST writes
 * against ssl.bing.com/webmaster/api.svc/json/{Method}?apikey=…, responses
 * wrapped in a "d" envelope, dates in the /Date(ms)/ form. Everything here
 * normalizes that away so no caller ever sees WCF.
 *
 * @package Agentimus
 */

namespace Agentimus\Bing;

defined( 'ABSPATH' ) || exit;

final class Client {

	/** @var string Service base. */
	const API = 'https://ssl.bing.com/webmaster/api.svc/json/';

	/**
	 * The account's sites: [ { url, verified } … ].
	 *
	 * @param string $api_key API key, plaintext.
	 * @return array { sites?: array, error?: string }
	 */
	public function user_sites( $api_key ) {
		$out = $this->get( 'GetUserSites', $api_key, array() );
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		$sites = array();
		foreach ( (array) $out['d'] as $row ) {
			$sites[] = array(
				'url'      => (string) ( isset( $row['Url'] ) ? $row['Url'] : '' ),
				'verified' => ! empty( $row['IsVerified'] ),
			);
		}
		return array( 'sites' => $sites );
	}

	/**
	 * Add a site to the account (unverified until VerifySite succeeds).
	 *
	 * @param string $api_key  API key.
	 * @param string $site_url Site URL.
	 * @return array { ok?: true, error?: string }
	 */
	public function add_site( $api_key, $site_url ) {
		$out = $this->post( 'AddSite', $api_key, array( 'siteUrl' => (string) $site_url ) );
		return isset( $out['error'] ) ? $out : array( 'ok' => true );
	}

	/**
	 * Ask Bing to verify the site now — it fetches the page and looks for the
	 * msvalidate tag this plugin prints.
	 *
	 * @param string $api_key  API key.
	 * @param string $site_url Site URL.
	 * @return array { verified?: bool, error?: string }
	 */
	public function verify_site( $api_key, $site_url ) {
		$out = $this->post( 'VerifySite', $api_key, array( 'siteUrl' => (string) $site_url ) );
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		return array( 'verified' => ! empty( $out['d'] ) );
	}

	/**
	 * Daily crawl statistics, oldest first: one row per day with the crawl
	 * counts, index size and robots blocks — the panel's raw material.
	 *
	 * @param string $api_key  API key.
	 * @param string $site_url Site URL.
	 * @return array { rows?: array<int,array>, error?: string }
	 */
	public function crawl_stats( $api_key, $site_url ) {
		$out = $this->get( 'GetCrawlStats', $api_key, array( 'siteUrl' => (string) $site_url ) );
		if ( isset( $out['error'] ) ) {
			return $out;
		}

		$rows = array();
		foreach ( (array) $out['d'] as $row ) {
			$date = self::wcf_date( isset( $row['Date'] ) ? (string) $row['Date'] : '' );
			if ( '' === $date ) {
				continue;
			}
			$rows[] = array(
				'date_at'        => $date,
				'crawled'        => (int) ( isset( $row['CrawledPages'] ) ? $row['CrawledPages'] : 0 ),
				'in_index'       => (int) ( isset( $row['InIndex'] ) ? $row['InIndex'] : 0 ),
				'in_links'       => (int) ( isset( $row['InLinks'] ) ? $row['InLinks'] : 0 ),
				'blocked_robots' => (int) ( isset( $row['BlockedByRobotsTxt'] ) ? $row['BlockedByRobotsTxt'] : 0 ),
				'code_2xx'       => (int) ( isset( $row['Code2xx'] ) ? $row['Code2xx'] : 0 ),
				'code_301'       => (int) ( isset( $row['Code301'] ) ? $row['Code301'] : 0 ),
				'code_302'       => (int) ( isset( $row['Code302'] ) ? $row['Code302'] : 0 ),
				'code_4xx'       => (int) ( isset( $row['Code4xx'] ) ? $row['Code4xx'] : 0 ),
				'code_5xx'       => (int) ( isset( $row['Code5xx'] ) ? $row['Code5xx'] : 0 ),
			);
		}
		usort( $rows, static function ( $a, $b ) {
			return strcmp( $a['date_at'], $b['date_at'] );
		} );

		return array( 'rows' => $rows );
	}

	/**
	 * A WCF "/Date(1690761600000)/" (optionally with a zone suffix) as
	 * Y-m-d, or '' when unparseable.
	 *
	 * @param string $raw The WCF date string.
	 * @return string
	 */
	public static function wcf_date( $raw ) {
		if ( ! preg_match( '/\/Date\((\-?\d+)/', (string) $raw, $m ) ) {
			return '';
		}
		$seconds = (int) floor( ( (float) $m[1] ) / 1000 );
		return $seconds > 0 ? gmdate( 'Y-m-d', $seconds ) : '';
	}

	/**
	 * One GET read.
	 *
	 * @param string $method  API method name.
	 * @param string $api_key API key.
	 * @param array  $params  Query params (besides apikey).
	 * @return array { d?: mixed, error?: string }
	 */
	private function get( $method, $api_key, array $params ) {
		$url = self::API . $method . '?' . http_build_query( array_merge( array( 'apikey' => (string) $api_key ), $params ) );
		return $this->handle( wp_remote_get( $url, array( 'timeout' => 15 ) ) );
	}

	/**
	 * One POST write.
	 *
	 * @param string $method  API method name.
	 * @param string $api_key API key.
	 * @param array  $body    JSON body.
	 * @return array { d?: mixed, error?: string }
	 */
	private function post( $method, $api_key, array $body ) {
		$url = self::API . $method . '?' . http_build_query( array( 'apikey' => (string) $api_key ) );
		return $this->handle( wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( $body ),
		) ) );
	}

	/**
	 * Shared response handling: transport errors, HTTP status words, and the
	 * WCF "d" envelope.
	 *
	 * @param array|\WP_Error $response The wp_remote_* result.
	 * @return array { d?: mixed, error?: string }
	 */
	private function handle( $response ) {
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			// Bing wraps errors as {"ErrorCode":n,"Message":"…"}; surface the words.
			$message = is_array( $json ) && isset( $json['Message'] ) ? (string) $json['Message'] : '';
			return array( 'error' => '' !== $message ? $message : sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Bing answered with status %d.', 'agentimus' ),
				$code
			) );
		}
		if ( ! is_array( $json ) || ! array_key_exists( 'd', $json ) ) {
			return array( 'error' => __( 'Bing returned an unexpected response shape.', 'agentimus' ) );
		}
		return array( 'd' => $json['d'] );
	}
}
