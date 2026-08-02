<?php
/**
 * Bing connection store — the search data-source configuration: the API key
 * (encrypted at rest), the verification code the site prints, the matched
 * WMT site URL, and the poll bookkeeping.
 *
 * Its own option, never mixed into core settings, so connecting or
 * disconnecting can't disturb anything else. The key is write-only across the
 * REST boundary: public_view() reports only WHETHER a key is stored.
 *
 * @package Agentimus
 */

namespace Agentimus\Bing;

use Agentimus\Visibility\Crypto;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** @var string Option key. */
	const OPTION = 'agentimus_bing';

	/** @var array|null Lazily-loaded, defaults-merged settings. */
	private $cache = null;

	/**
	 * Every stored field with its empty default.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'api_key'      => '', // Ciphertext at rest (see Crypto); '' when disconnected.
			'site_url'     => '', // The WMT site URL the key was matched against (e.g. https://example.com/).
			'msvalidate'   => '', // The msvalidate.01 content the site prints in <head>; kept across disconnects.
			'connected_at' => 0,
			'last_poll_at' => 0,
			'last_error'   => '', // The most recent poll failure, '' after a clean poll.
			'last_query_error' => '', // The query-stats side of the poll fails on its own line — crawl data staying fresh must not mask it, or vice versa.
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
	 * Whether a key and a matched site are stored — the gate every poll and
	 * every summary read sits behind.
	 *
	 * @return bool
	 */
	public function connected() {
		$all = $this->all();
		return '' !== (string) $all['api_key'] && '' !== (string) $all['site_url'];
	}

	/**
	 * The decrypted API key, or '' when none is stored (or it can't be
	 * decrypted — e.g. the site's salts were rotated).
	 *
	 * @return string
	 */
	public function api_key() {
		return Crypto::decrypt( (string) $this->get( 'api_key', '' ) );
	}

	/**
	 * Store the verification code the site will print. Saved on its own —
	 * verification and the key are different steps with different failures.
	 *
	 * @param string $code The msvalidate.01 content value.
	 * @return void
	 */
	public function set_msvalidate( $code ) {
		$all               = $this->all();
		$all['msvalidate'] = sanitize_text_field( (string) $code );
		$this->persist( $all );
	}

	/**
	 * Store a verified connection. The key is encrypted at this single
	 * persistence point, so it is only ever STORED as ciphertext.
	 *
	 * @param string $api_key  The Bing Webmaster API key, plaintext.
	 * @param string $site_url The WMT site URL the key was matched against.
	 * @return void
	 */
	public function connect( $api_key, $site_url ) {
		$all                 = $this->all();
		$all['api_key']      = Crypto::encrypt_if_needed( sanitize_text_field( (string) $api_key ) );
		$all['site_url']     = esc_url_raw( (string) $site_url );
		$all['connected_at'] = time();
		$all['last_poll_at'] = 0;
		$all['last_error']   = '';
		$this->persist( $all );
	}

	/**
	 * Forget the connection. The verification code is deliberately KEPT — the
	 * printed tag is what makes reconnecting painless — and stored aggregates
	 * are the owner's history.
	 *
	 * @return void
	 */
	public function disconnect() {
		$all = $this->all();
		$msv = (string) $all['msvalidate'];

		$fresh               = $this->defaults();
		$fresh['msvalidate'] = $msv;
		$this->persist( $fresh );
	}

	/**
	 * Record the outcome of a poll — a timestamp always, an error only when
	 * one happened (a clean poll clears the previous error).
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
	 * Record the query-stats half of the poll — its own field, so a failure
	 * here is never hidden behind healthy crawl numbers.
	 *
	 * @param string $error Human-readable failure, or '' for a clean run.
	 * @return void
	 */
	public function record_query_poll( $error = '' ) {
		$all                      = $this->all();
		$all['last_query_error']  = sanitize_text_field( (string) $error );
		$this->persist( $all );
	}

	/**
	 * The connection state as the admin UI may see it — everything EXCEPT the
	 * key, which never crosses the REST boundary in either direction.
	 *
	 * @return array
	 */
	public function public_view() {
		$all = $this->all();
		return array(
			'connected'     => $this->connected(),
			'siteUrl'       => (string) $all['site_url'],
			'hasMsvalidate' => '' !== (string) $all['msvalidate'],
			'connectedAt'   => (int) $all['connected_at'],
			'lastPollAt'    => (int) $all['last_poll_at'],
			'lastError'     => (string) $all['last_error'],
			'lastQueryError' => (string) $all['last_query_error'],
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
