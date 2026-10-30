<?php
/**
 * Empty the site's whole Cloudflare cache, the same purge as the owner's button.
 *
 * The limit counts the SITE, not the connection. Refreshing spends a quota, and
 * each connection can only spend its share of it. A purge leaves every visitor
 * hitting the origin until the cache rebuilds, so several assistants each within
 * their own share would still hold the site cold.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Cloudflare\Purge;
use Agentimus\Cloudflare\Settings as CloudflareSettings;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class EdgePurge {

	/** Purges per hour, for the whole site. */
	const HOURLY_LIMIT = 2;

	const COUNT_TRANSIENT = 'agentimus_agent_edge_purges';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return array|\WP_Error { purged: bool, zone: string, purgedAt: int, message: string }.
	 */
	public function purge() {
		if ( ! $this->settings->enabled( 'agent_writes_maintenance' ) ) {
			return new \WP_Error(
				'agentimus_maintenance_off',
				__( 'Agents may not purge the cache on this site. The owner can allow it under MCP Server, with “Let agents refresh data and purge the cache”. Nothing was purged.', 'agentimus' ),
				array( 'status' => 403 )
			);
		}

		$cloudflare = new CloudflareSettings();
		if ( ! $cloudflare->connected() ) {
			return new \WP_Error(
				'agentimus_refresh_not_connected',
				__( 'Cloudflare is not connected on this site, so there is no cache to purge. Connecting it is up to the owner.', 'agentimus' ),
				array( 'status' => 409 )
			);
		}

		$count = (int) get_transient( self::COUNT_TRANSIENT );
		if ( $count >= self::HOURLY_LIMIT ) {
			return new \WP_Error(
				'agentimus_purge_rate_limited',
				sprintf(
					/* translators: %d: purges allowed per hour. */
					__( 'Agents have purged this site’s Cloudflare cache %d times in the last hour, which is the limit. Nothing was purged. Try again later.', 'agentimus' ),
					self::HOURLY_LIMIT
				),
				array( 'status' => 429 )
			);
		}
		set_transient( self::COUNT_TRANSIENT, $count + 1, HOUR_IN_SECONDS );

		$out = Purge::purge_all( $cloudflare );
		if ( empty( $out['ok'] ) ) {
			return new \WP_Error(
				'agentimus_purge_failed',
				(string) ( isset( $out['error'] ) ? $out['error'] : __( 'Cloudflare did not confirm the purge.', 'agentimus' ) ),
				array( 'status' => 502 )
			);
		}

		$view = $cloudflare->public_view();
		return array(
			'purged'   => true,
			'zone'     => (string) $view['zoneName'],
			'purgedAt' => (int) $view['lastPurgeAt'],
			'message'  => __( 'Cloudflare’s cache for this site is empty. It rebuilds as visitors return, and the site may be slower until then.', 'agentimus' ),
		);
	}
}
