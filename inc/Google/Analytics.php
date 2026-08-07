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
	 * @return array { totals?: array, error?: string }
	 */
	public function totals( $token, $property, $start, $end ) {
		// Order matters — the response returns values positionally, so this list
		// and the reads below have to be kept in step. Named in the array so the
		// pairing is checkable by eye rather than by counting.
		$metrics = array(
			'users'      => 'activeUsers',
			'newUsers'   => 'newUsers',
			'sessions'   => 'sessions',
			'views'      => 'screenPageViews',
			'engaged'    => 'engagedSessions',
			'avgSeconds' => 'averageSessionDuration',
		);

		$out = $this->run_report(
			$token,
			$property,
			array(
				'dateRanges' => array( array( 'startDate' => (string) $start, 'endDate' => (string) $end ) ),
				'metrics'    => array_map(
					static function ( $name ) {
						return array( 'name' => $name );
					},
					array_values( $metrics )
				),
			)
		);
		if ( isset( $out['error'] ) ) {
			return $out;
		}

		$row = (array) ( ( $out['data']['rows'] ?? array() )[0] ?? array() );
		$mv  = (array) ( $row['metricValues'] ?? array() );

		$totals = array();
		$i      = 0;
		foreach ( $metrics as $key => $unused ) {
			$raw            = (float) ( $mv[ $i ]['value'] ?? 0 );
			$totals[ $key ] = 'avgSeconds' === $key ? (int) round( $raw ) : (int) round( $raw );
			$i++;
		}

		// Derived here rather than asked for: GA4's own engagementRate is a
		// percentage of sessions, and computing it from the two counts we already
		// have keeps the numbers on screen consistent with each other.
		$totals['engagedPct'] = $totals['sessions'] > 0
			? (int) round( ( $totals['engaged'] / $totals['sessions'] ) * 100 )
			: 0;
		$totals['perVisit'] = $totals['sessions'] > 0
			? round( $totals['views'] / $totals['sessions'], 1 )
			: 0.0;

		return array( 'totals' => $totals );
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
	 * Hosts that mean "an AI assistant sent this reader", as GA4 spells them.
	 *
	 * GA4 reports the referring HOST in `sessionSource`, so this is a host list,
	 * not the referrer-matching logic in {@see \Agentimus\Activity\Referrals}.
	 * The two are deliberately independent: they are meant to be two separate
	 * measurements of the same thing, and sharing a list would make them agree
	 * for the wrong reason.
	 *
	 * @var array<int,string>
	 */
	const AI_SOURCES = array(
		'chatgpt.com',
		'chat.openai.com',
		'openai.com',
		'perplexity.ai',
		'www.perplexity.ai',
		'gemini.google.com',
		'bard.google.com',
		'claude.ai',
		'copilot.microsoft.com',
		'bing.com/chat',
		'grok.com',
		'x.ai',
		'duckduckgo.com/aichat',
		'chat.deepseek.com',
		'deepseek.com',
		'you.com',
		'phind.com',
		'poe.com',
		'meta.ai',
		'mistral.ai',
		'chat.mistral.ai',
	);

	/**
	 * GA4's own count of readers an assistant sent, beside its total.
	 *
	 * The point of asking Google this when the plugin already counts referrals
	 * itself: the two disagree, and the disagreement is informative. GA4 misses
	 * every reader who declined its script; the local count misses every reader
	 * whose referrer was stripped in transit. Neither is wrong — they are
	 * different instruments — and a screen showing both, with the reason, tells
	 * an owner more than either number pretending to be the truth.
	 *
	 * @param string $token    Bearer token.
	 * @param string $property The GA4 property ID.
	 * @param string $start    Y-m-d start date.
	 * @param string $end      Y-m-d end date.
	 * @return array { split?: array{ai:int,other:int,total:int,bySource:array}, error?: string }
	 */
	public function ai_split( $token, $property, $start, $end ) {
		$out = $this->run_report(
			$token,
			$property,
			array(
				'dateRanges' => array( array( 'startDate' => (string) $start, 'endDate' => (string) $end ) ),
				'dimensions' => array( array( 'name' => 'sessionSource' ) ),
				'metrics'    => array( array( 'name' => 'sessions' ) ),
				'orderBys'   => array( array( 'desc' => true, 'metric' => array( 'metricName' => 'sessions' ) ) ),
				// Generous: the tail is summed into "other", so a low cap would
				// quietly shrink the total this split is measured against.
				'limit'      => 250,
			)
		);
		if ( isset( $out['error'] ) ) {
			return $out;
		}

		$ai        = 0;
		$total     = 0;
		$by_source = array();

		foreach ( (array) ( $out['data']['rows'] ?? array() ) as $row ) {
			$source   = strtolower( (string) ( ( (array) ( $row['dimensionValues'] ?? array() ) )[0]['value'] ?? '' ) );
			$sessions = (int) round( (float) ( ( (array) ( $row['metricValues'] ?? array() ) )[0]['value'] ?? 0 ) );
			$total   += $sessions;

			if ( '' === $source || ! self::is_ai_source( $source ) ) {
				continue;
			}
			$ai                  += $sessions;
			$by_source[ $source ] = ( isset( $by_source[ $source ] ) ? $by_source[ $source ] : 0 ) + $sessions;
		}

		arsort( $by_source );

		return array(
			'split' => array(
				'ai'       => $ai,
				'other'    => max( 0, $total - $ai ),
				'total'    => $total,
				'bySource' => $by_source,
			),
		);
	}

	/**
	 * Whether a GA4 `sessionSource` value names an AI assistant.
	 *
	 * Matched on the host, and on a host BOUNDARY — a plain substring test would
	 * count `notchatgpt.com.example` as ChatGPT. Filterable, because the list of
	 * assistants changes faster than any release cycle.
	 *
	 * @param string $source Lowercased sessionSource value.
	 * @return bool
	 */
	public static function is_ai_source( $source ) {
		/**
		 * Filter the GA4 session sources counted as AI assistants.
		 *
		 * @param array  $hosts  Host strings.
		 * @param string $source The value being tested.
		 */
		$hosts = (array) apply_filters( 'agentimus_ga4_ai_sources', self::AI_SOURCES, $source );

		foreach ( $hosts as $host ) {
			$host = strtolower( (string) $host );
			if ( '' === $host ) {
				continue;
			}
			if ( $source === $host ) {
				return true;
			}
			// A subdomain of a listed host, or a listed path prefix like
			// bing.com/chat — never a bare substring.
			if ( substr( $source, -( strlen( $host ) + 1 ) ) === '.' . $host ) {
				return true;
			}
			if ( false !== strpos( $host, '/' ) && 0 === strpos( $source, $host ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A GA4 property ID, or '' if the input isn't one.
	 *
	 * ⚠️ REJECTS rather than salvages. Stripping non-digits looks tidy and is
	 * wrong here: the mistake people actually make is pasting the measurement ID
	 * (`G-ABC123`), and stripping turns that into `123` — a real, valid-looking
	 * property ID belonging to somebody else. A confusing permission error hours
	 * later is the BEST case; the worst is that it answers. Anything that isn't
	 * already digits is refused, and the message names the mistake.
	 *
	 * @param string $property Raw input.
	 * @return string Digits, or ''.
	 */
	public static function clean_property( $property ) {
		$property = trim( (string) $property );
		return preg_match( '/^[0-9]+$/', $property ) ? $property : '';
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
		$property = self::clean_property( $property );
		if ( '' === $property ) {
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
