<?php
/**
 * Fetch a data source's numbers now, instead of at its next scheduled poll.
 *
 * ⛔ IT CALLS poll_now(), NEVER poll(). Google's poll() also runs an index sweep,
 * which spends the site's 2,000-a-day inspection quota. That stays out of an
 * agent's reach until there is an approval queue.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\AgentAccess\Module as AgentAccess;
use Agentimus\Bing;
use Agentimus\Cloudflare;
use Agentimus\Google;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class DataRefresh {

	const SOURCES = array( 'cloudflare', 'bing', 'google', 'analytics' );

	/** Refreshes per source, per connection, per hour. These spend the owner's API limits. */
	const HOURLY_LIMIT = 4;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param string $source One of {@see SOURCES}.
	 * @return array|\WP_Error { source: string, refreshed: bool, lastPollAt: int, message: string }.
	 */
	public function refresh( $source ) {
		if ( ! $this->settings->enabled( 'agent_writes_maintenance' ) ) {
			return new \WP_Error(
				'agentimus_maintenance_off',
				__( 'Agents may not refresh data on this site. The owner can allow it under MCP Server, with “Let agents refresh data”. Nothing was fetched.', 'agentimus' ),
				array( 'status' => 403 )
			);
		}

		$source = (string) $source;
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			return new \WP_Error(
				'agentimus_refresh_source',
				sprintf(
					/* translators: %s: comma-separated list of data sources. */
					__( 'source must be one of: %s.', 'agentimus' ),
					implode( ', ', self::SOURCES )
				),
				array( 'status' => 400 )
			);
		}

		$google = new Google\Settings();
		$runs   = array(
			'cloudflare' => function () {
				$store = new Cloudflare\Settings();
				return array( $store->connected(), array( new Cloudflare\Module( $store ), 'poll_now' ), array( $store, 'public_view' ) );
			},
			'bing'       => function () {
				$store = new Bing\Settings();
				return array( $store->connected(), array( new Bing\Module( $store ), 'poll_now' ), array( $store, 'public_view' ) );
			},
			'google'     => function () use ( $google ) {
				return array( $google->connected(), array( new Google\Module( $google ), 'poll_now' ), array( $google, 'public_view' ) );
			},
			'analytics'  => function () use ( $google ) {
				return array(
					$google->analytics_connected(),
					array( new Google\Module( $google ), 'run_analytics_poll' ),
					static function () use ( $google ) {
						return array(
							'lastPollAt' => (int) $google->get( 'ga4_poll_at', 0 ),
							'lastError'  => (string) $google->get( 'ga4_error', '' ),
						);
					},
				);
			},
		);

		list( $connected, $poll, $state ) = $runs[ $source ]();

		if ( ! $connected ) {
			return new \WP_Error(
				'agentimus_refresh_not_connected',
				sprintf(
					/* translators: %s: data source. */
					__( '“%s” is not connected on this site, so there is nothing to fetch. Connecting it is up to the owner.', 'agentimus' ),
					$source
				),
				array( 'status' => 409 )
			);
		}

		$limited = self::over_limit( $source );
		if ( null !== $limited ) {
			return $limited;
		}

		$before = (int) $state()['lastPollAt'];
		$poll();
		$after = $state();

		if ( '' !== (string) $after['lastError'] ) {
			return new \WP_Error( 'agentimus_refresh_failed', (string) $after['lastError'], array( 'status' => 502 ) );
		}

		// A poll already running holds the lock, and poll_now() then skips quietly.
		// A timestamp that did not move is that skip, or a same-second repeat.
		$refreshed = (int) $after['lastPollAt'] > $before;

		return array(
			'source'     => $source,
			'refreshed'  => $refreshed,
			'lastPollAt' => (int) $after['lastPollAt'],
			'message'    => $refreshed
				? __( 'Fetched. The read tools now answer from these numbers.', 'agentimus' )
				: __( 'Nothing new was fetched: another refresh is probably running. The read tools answer from the last numbers stored.', 'agentimus' ),
		);
	}

	/**
	 * Count a refresh against this connection's hourly limit for the source.
	 *
	 * @param string $source Data source.
	 * @return \WP_Error|null
	 */
	private static function over_limit( $source ) {
		$key   = 'agentimus_refresh_' . md5( $source . '|' . get_current_user_id() . '|' . AgentAccess::current_credential() );
		$count = (int) get_transient( $key );
		if ( $count >= self::HOURLY_LIMIT ) {
			return new \WP_Error(
				'agentimus_refresh_rate_limited',
				sprintf(
					/* translators: 1: data source, 2: refreshes allowed per hour. */
					__( 'This connection has refreshed “%1$s” %2$d times in the last hour, which is the limit. Nothing was fetched. Read the stored numbers, or try again later.', 'agentimus' ),
					$source,
					self::HOURLY_LIMIT
				),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return null;
	}
}
