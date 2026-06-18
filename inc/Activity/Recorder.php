<?php
/**
 * Recorder — logs one agent hit on a discovery/llms endpoint.
 *
 * First-party, local-only (never transmitted anywhere). Stores the endpoint,
 * the classified agent, and a truncated User-Agent — and DELIBERATELY no IP
 * address, so there's no PII/GDPR footprint by default. Called only from the
 * discovery/agent serve paths (low-frequency), so a single INSERT per hit is
 * negligible.
 *
 * @package Agentomatic
 */

namespace Agentomatic\Activity;

use Agentomatic\Settings;

defined( 'ABSPATH' ) || exit;

final class Recorder {

	/** @var bool|null Per-request cache of the enable flag. */
	private static $enabled = null;

	/**
	 * Record a hit on the named endpoint, if logging is enabled.
	 *
	 * @param string $endpoint Short endpoint label (e.g. "discovery.json").
	 */
	public static function record( $endpoint ) {
		if ( ! self::enabled() ) {
			return;
		}

		/**
		 * Skip logging the site owner inspecting their own endpoints — a logged-in
		 * administrator opening discovery.json in a browser is not agent traffic and
		 * would otherwise bury the log in "Browser" self-noise. Filter to false to
		 * log every request regardless.
		 *
		 * @param bool $skip Whether to skip this request. Default true for admins.
		 */
		if ( apply_filters( 'agentomatic_activity_skip_self', is_user_logged_in() && current_user_can( 'manage_options' ) ) ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only logging of a public endpoint hit.

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB -- single insert into our own table.
			Table::name(),
			array(
				'endpoint' => substr( (string) $endpoint, 0, 64 ),
				'agent'    => substr( Classifier::classify( $ua ), 0, 64 ),
				'ua'       => substr( $ua, 0, 255 ),
				'hit_at'   => current_time( 'mysql', true ), // GMT.
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Whether activity logging is on (cached for the request).
	 *
	 * @return bool
	 */
	private static function enabled() {
		if ( null === self::$enabled ) {
			self::$enabled = (bool) ( new Settings() )->enabled( 'enable_activity' );
		}
		return self::$enabled;
	}
}
