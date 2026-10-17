<?php
/**
 * The Cloudflare poll re-reads its whole backfill window once after probes
 * began to be counted, so hours stored before stop calling a scanner a reader.
 *
 * ⭐ WHY THIS EXISTS. On heera.it (2026-10-17) 1.51.9-dev1 counted probes from
 * the next poll on — but a poll only re-reads the last two hours, and the
 * morning's 18 blocked ".env" requests in Claude-User's name sat in an hour
 * stored before the update with no probe count. "Cloudflare is blocking
 * Anthropic" would have stood for another day and the week's figure for seven.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Cloudflare\Module;
use Agentimus\Cloudflare\Settings;
use Agentimus\Cloudflare\Table;

final class EdgeProbeBackfillDbTest extends DbTestCase {

	const UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +claudebot@anthropic.com)';

	/** @var callable|null */
	private $http = null;

	/** @var string[] The `since` of every hourly-traffic query, in order. */
	private $sinces = array();

	/** @var string The morning hour the scanner ran, RFC3339. */
	private $morning;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		Table::install();
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		delete_option( Table::PROBES_OPTION );

		$this->morning = gmdate( 'Y-m-d\TH:00:00\Z', time() - 9 * HOUR_IN_SECONDS );

		// As heera.it stood after the update: the morning stored by the old
		// code (18 blocked, no probe count), and a newer hour after it.
		Table::upsert( array(
			array( 'hour_at' => gmdate( 'Y-m-d H:00:00', strtotime( $this->morning ) ), 'ua' => 'claude-user', 'requests' => 18, 'cached' => 0, 'origin' => 0, 'blocked' => 18, 'served' => 0, 'bytes' => 0 ),
			array( 'hour_at' => gmdate( 'Y-m-d H:00:00', time() - HOUR_IN_SECONDS ), 'ua' => 'claude-user', 'requests' => 2, 'cached' => 0, 'origin' => 2, 'blocked' => 0, 'served' => 2, 'bytes' => 0 ),
		) );

		( new Settings() )->connect( 'cf-token', 'zone1', 'example.org' );
		$this->fake_cloudflare();
	}

	public function tear_down(): void {
		global $wpdb;
		if ( $this->http ) {
			remove_filter( 'pre_http_request', $this->http, 10 );
			$this->http = null;
		}
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		delete_option( Table::PROBES_OPTION );
		( new Settings() )->disconnect();
		parent::tear_down();
	}

	/** Cloudflare's three reads, answering with the morning's scanner whatever the window. */
	private function fake_cloudflare() {
		$this->http = function ( $pre, $args, $url ) {
			if ( false === strpos( (string) $url, 'api.cloudflare.com' ) ) {
				return $pre;
			}
			$body  = json_decode( (string) $args['body'], true );
			$query = (string) $body['query'];
			$h     = $this->morning;
			// Cloudflare answers only for the window asked about.
			$in = strtotime( $body['variables']['since'] ) <= strtotime( $h ) && strtotime( $h ) < strtotime( $body['variables']['until'] );

			if ( false !== strpos( $query, 'cacheStatus' ) ) {
				$this->sinces[] = (string) $body['variables']['since'];
			}
			if ( ! $in ) {
				$groups = array();
			} elseif ( false !== strpos( $query, 'cacheStatus' ) ) {
				$groups = array(
					array(
						'count'      => 18,
						'sum'        => array( 'edgeResponseBytes' => 0 ),
						'dimensions' => array( 'datetimeHour' => $h, 'userAgent' => self::UA, 'cacheStatus' => 'unknown', 'edgeResponseStatus' => 403, 'originResponseStatus' => 0 ),
					),
				);
			} elseif ( false !== strpos( $query, 'clientRequestPath }' ) ) {
				$groups = array(
					array( 'count' => 15, 'dimensions' => array( 'datetimeHour' => $h, 'userAgent' => self::UA, 'clientRequestPath' => '/settings%2F.env' ) ),
					array( 'count' => 3, 'dimensions' => array( 'datetimeHour' => $h, 'userAgent' => self::UA, 'clientRequestPath' => '/@fs/.env' ) ),
				);
			} else {
				$groups = array();
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'httpRequestsAdaptiveGroups' => $groups ) ) ) ) ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	private function morning_probes() {
		$day = gmdate( 'Y-m-d', strtotime( $this->morning ) );
		return Table::daily( 7 )[ $day ]['claude-user']['probes'];
	}

	public function test_the_first_poll_rereads_the_hours_stored_before_probes_were_counted() {
		$this->assertSame( 0, $this->morning_probes() );

		( new Module( new Settings() ) )->run_poll();

		$this->assertSame( 18, $this->morning_probes(), 'the morning hour was re-read and its probes counted' );
		$this->assertSame( 0, Table::recent( 24 )['claude-user']['blocked'] - Table::recent( 24 )['claude-user']['probes'] );
		$this->assertSame( '1', (string) get_option( Table::PROBES_OPTION ) );
	}

	public function test_the_reread_happens_once() {
		$module = new Module( new Settings() );
		$module->run_poll();
		$this->sinces = array();

		$module->run_poll();

		$this->assertGreaterThan(
			strtotime( $this->morning ),
			strtotime( $this->sinces[0] ),
			'the second poll resumes from the latest stored hour, not the whole window'
		);
	}
}
