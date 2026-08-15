<?php
/**
 * Shared storage for the data-source connection stores.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * The Google, Bing and Cloudflare data sources each keep their configuration in
 * one autoload-off option: a credential (ciphertext at rest), a matched target,
 * and poll bookkeeping. The parts that are identical across all three live here
 * — the lazy defaults-merged read, single-value access, the standard poll stamp,
 * the secret accessor, and the single persistence point — so the credential and
 * cache discipline is written once rather than copied per source.
 *
 * The using class supplies the OPTION constant, defaults(), and its own
 * connected()/connect()/disconnect()/public_view() shape.
 */
trait ConnectionStore {

	/** @var array|null Lazily-loaded, defaults-merged settings. */
	private $cache = null;

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
	 * Record the outcome of a poll — a timestamp always, an error only when one
	 * happened (a clean poll clears the previous error).
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
	 * How many rows the last poll had to leave out.
	 *
	 * The store holds the busiest rows up to a cap. The engines hand their
	 * reports back busiest-first, so what falls off the end is the quiet tail —
	 * the pages an owner is most likely to be looking for. Dropping them is
	 * defensible; dropping them silently is not, which is why the number is kept
	 * and said out loud rather than inferred from a total that looks fine.
	 *
	 * Written on EVERY poll, including clean ones — a poll that dropped nothing
	 * must clear a previous poll's number, or the screen keeps apologising for
	 * something that is no longer true.
	 *
	 * @param int $dropped Rows the cap left out.
	 * @return void
	 */
	public function record_dropped( $dropped = 0 ) {
		$all                 = $this->all();
		$all['dropped_rows'] = max( 0, (int) $dropped );
		$this->persist( $all );
	}

	/**
	 * Decrypt a stored credential field, or '' when none is stored (or it can't
	 * be decrypted — e.g. the site's salts were rotated). The stored form is
	 * always ciphertext; this is the only supported way to read it back out.
	 *
	 * @param string $key The field holding the ciphertext.
	 * @return string
	 */
	protected function secret( $key ) {
		return \Agentimus\Crypto::decrypt( (string) $this->get( $key, '' ) );
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
