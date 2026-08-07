<?php
/**
 * Google connection store — the Search Console data-source configuration: the
 * service-account key (encrypted at rest), the matched property, and the poll
 * bookkeeping. The mirror of {@see \Agentimus\Bing\Settings}, one option of
 * its own, so connecting or disconnecting can't disturb anything else.
 *
 * The key is write-only across the REST boundary: public_view() reports the
 * service-account EMAIL (the owner needs it to grant Search Console access)
 * but never the key material.
 *
 * @package Agentimus
 */

namespace Agentimus\Google;

use Agentimus\Visibility\Crypto;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** @var string Option key. */
	const OPTION = 'agentimus_google';

	/** @var array|null Lazily-loaded, defaults-merged settings. */
	private $cache = null;

	/**
	 * Every stored field with its empty default.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'sa_json'      => '', // The service-account key file, ciphertext at rest; '' when disconnected.
			'sa_email'     => '', // client_email from the key — plaintext, it's what the owner grants in GSC.
			'property'     => '', // The matched Search Console property (sc-domain:… or URL prefix).
			// GA4, deliberately SEPARATE from the Search Console property above: the
			// same key can serve both, but connecting one must never be taken as
			// consent to read the other. Empty = the analytics half is simply off,
			// and every screen that would show it says so rather than showing zero.
			'ga4_property' => '', // The numeric GA4 property ID.
			'ga4_error'    => '', // Last GA4 failure, in Google's words.
			'ga4_poll_at'  => 0,
			'connected_at' => 0,
			'last_poll_at' => 0,
			'last_error'   => '',
		);
	}

	/**
	 * The resolved, defaults-merged settings.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );
		}
		return $this->cache;
	}

	/**
	 * One resolved value.
	 *
	 * @param string $key      Field name.
	 * @param mixed  $fallback Returned when the field is unknown.
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Whether a key and a matched property are stored.
	 *
	 * @return bool
	 */
	public function connected() {
		$all = $this->all();
		return '' !== (string) $all['sa_json'] && '' !== (string) $all['property'];
	}

	/**
	 * Whether GA4 can be read: the same key, plus a property ID for it.
	 *
	 * Separate from {@see connected()} on purpose — a site can have Search
	 * Console working and no analytics, or (once a key is pasted) analytics and
	 * no verified Search Console property. Neither implies the other, and a
	 * screen that assumes it will show an empty panel with no explanation.
	 *
	 * @return bool
	 */
	public function analytics_connected() {
		$all = $this->all();
		return '' !== (string) $all['sa_json'] && '' !== (string) $all['ga4_property'];
	}

	/**
	 * Store (or clear) the GA4 property ID. Digits only — the numeric property
	 * ID, never the G-XXXX measurement ID people reach for first.
	 *
	 * @param string $property The property ID, or '' to disconnect analytics.
	 * @return void
	 */
	public function set_ga4_property( $property ) {
		$all = $this->all();
		// Strict: '' clears, digits store, anything else is refused rather than
		// stripped down into a plausible wrong ID. {@see Analytics::clean_property}.
		$all['ga4_property'] = Analytics::clean_property( $property );
		$all['ga4_error']    = '';
		$this->persist( $all );
	}

	/**
	 * Record a GA4 poll outcome — '' on success.
	 *
	 * @param string $error Google's words, or ''.
	 * @return void
	 */
	public function record_ga4_poll( $error = '' ) {
		$all                = $this->all();
		$all['ga4_error']   = sanitize_text_field( (string) $error );
		$all['ga4_poll_at'] = time();
		$this->persist( $all );
	}

	/**
	 * The decrypted service-account JSON, or '' when none is stored (or it
	 * can't be decrypted — e.g. the site's salts were rotated).
	 *
	 * @return string
	 */
	public function sa_json() {
		return Crypto::decrypt( (string) $this->get( 'sa_json', '' ) );
	}

	/**
	 * Store a working connection. The key is encrypted at this single
	 * persistence point, so it is only ever STORED as ciphertext.
	 *
	 * @param string $sa_json  The service-account key JSON, plaintext.
	 * @param string $email    The service-account email from the key.
	 * @param string $property The matched GSC property.
	 * @return void
	 */
	public function connect( $sa_json, $email, $property ) {
		$all                 = $this->all();
		$all['sa_json']      = Crypto::encrypt_if_needed( (string) $sa_json );
		$all['sa_email']     = sanitize_email( (string) $email );
		$all['property']     = sanitize_text_field( (string) $property );
		$all['connected_at'] = time();
		$all['last_poll_at'] = 0;
		$all['last_error']   = '';
		$this->persist( $all );
		Auth::forget();
	}

	/**
	 * Forget the connection. Stored aggregates are the owner's history and stay.
	 *
	 * @return void
	 */
	public function disconnect() {
		$this->persist( $this->defaults() );
		Auth::forget();
	}

	/**
	 * Record the outcome of a poll.
	 *
	 * @param string $error Human-readable failure, or '' for a clean poll.
	 * @return void
	 */
	public function record_poll( $error = '' ) {
		$all                 = $this->all();
		$all['last_poll_at'] = time();
		$all['last_error']   = sanitize_text_field( (string) $error );
		$this->persist( $all );
	}

	/**
	 * The connection state as the admin UI may see it — the key never crosses
	 * the REST boundary in either direction; the email does, because granting
	 * it Search Console access IS the setup step.
	 *
	 * @return array
	 */
	public function public_view() {
		$all = $this->all();
		return array(
			'connected'   => $this->connected(),
			'property'    => (string) $all['property'],
			'saEmail'     => (string) $all['sa_email'],
			'connectedAt' => (int) $all['connected_at'],
			'lastPollAt'  => (int) $all['last_poll_at'],
			'lastError'   => (string) $all['last_error'],
			// The analytics half. The property ID is not a secret (it appears in
			// every GA4 URL the owner already sees), unlike the key beside it,
			// which never leaves the server in any form.
			'analytics'   => array(
				'connected' => $this->analytics_connected(),
				'property'  => (string) $all['ga4_property'],
				'lastPollAt' => (int) $all['ga4_poll_at'],
				'lastError' => (string) $all['ga4_error'],
			),
		);
	}

	/**
	 * Persist and refresh the cache.
	 *
	 * @param array $all The full settings array.
	 * @return void
	 */
	private function persist( array $all ) {
		update_option( self::OPTION, $all, false ); // autoload OFF — read on demand only.
		$this->cache = $all;
	}
}
