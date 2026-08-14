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

use Agentimus\ConnectionStore;
use Agentimus\Crypto;

defined( 'ABSPATH' ) || exit;

final class Settings {

	use ConnectionStore;

	/** @var string Option key. */
	const OPTION = 'agentimus_bing';

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
			'feeds'        => array(), // Bing's own sitemap record (GetFeeds): url, lastReadAt (Y-m-d, Bing's date), urls. Kept on feed-poll failure — Bing's dates stay honest without our own age line.
			'feeds_at'     => 0, // When the feeds snapshot was last refreshed.
			'traffic_at'   => 0, // When the daily traffic series last refreshed SUCCESSFULLY — the trend's staleness line reads this, so a failing poll ages it honestly.
			// Rows the last poll could not store because the snapshot cap was
			// reached. 0 on a poll that fitted — see ConnectionStore::record_dropped().
			'dropped_rows' => 0,
			// How many pages the next poll may ask Bing about. Paces ITSELF,
			// because Bing publishes no rate limit to pace against: it halves the
			// moment Bing refuses, and climbs back one step a DAY — not one step
			// a poll, or the Refresh button would walk it to the ceiling in a
			// minute.
			'ask_batch'    => \Agentimus\Bing\Module::ASK_BATCH_START,
			// The day the batch last moved, so growth can be rationed per day
			// however many polls (cron, connect, Refresh) run inside it.
			'ask_batch_at' => '',
		);
	}

	/**
	 * Stamp a successful traffic-series poll. Success only: last-good data on
	 * a blip is right, but a trend quietly stale for a week must be able to
	 * say so on screen instead of posing as current — the same rule Google's
	 * daily_at follows ({@see \Agentimus\Google\Module}).
	 *
	 * @return void
	 */
	public function record_traffic_poll() {
		$all               = $this->all();
		$all['traffic_at'] = time();
		$this->persist( $all );
	}

	/**
	 * Store the sitemap snapshot Bing reported. A failed fetch never lands
	 * here — the previous snapshot (with Bing's own dates) beats an empty one.
	 *
	 * @param array $rows Feed rows from {@see Client::feeds()}.
	 * @return void
	 */
	public function record_feeds( array $rows ) {
		$all             = $this->all();
		$all['feeds']    = array_slice( $rows, 0, 20 );
		$all['feeds_at'] = time();
		$this->persist( $all );
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
		return $this->secret( 'api_key' );
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
	 * Record the query-stats half of the poll — its own field, so a failure
	 * here is never hidden behind healthy crawl numbers.
	 *
	 * @param string $error Human-readable failure, or '' for a clean run.
	 * @return void
	 */
	/**
	 * Let the batch climb one step — at most once a calendar day.
	 *
	 * Clamped to the ceiling here rather than trusted from a stored value, so a
	 * number that somehow went strange cannot turn into a burst of requests at
	 * somebody else's expense.
	 *
	 * @return void
	 */
	public function grow_ask_batch() {
		$all   = $this->all();
		$today = gmdate( 'Y-m-d' );

		// ⚠️ ONCE A DAY, however many polls run. The ladder was written as
		// "grows slowly on clean days" but stepped on clean RUNS, and the
		// Refresh button is a poll — so four clicks took it from 10 to 30 and a
		// fifth sat it at the ceiling, firing fifty requests at an API that
		// publishes no rate limit. A person with a button is much faster than a
		// day, and the pacing has to mean what it says.
		if ( (string) $all['ask_batch_at'] === $today ) {
			return;
		}

		$all['ask_batch']    = (int) min( Module::ASK_BATCH_MAX, max( 1, (int) $all['ask_batch'] ) + Module::ASK_BATCH_STEP );
		$all['ask_batch_at'] = $today;
		$this->persist( $all );
	}

	/**
	 * Halve the batch, now — Bing refused.
	 *
	 * ⚠️ Deliberately NOT rationed by the day the way growth is: backing off is
	 * the safety half, and it has to take effect on the run that earned it.
	 * Stamping today's date on the way down also stops the batch climbing again
	 * within the same day it was refused.
	 *
	 * @return void
	 */
	public function halve_ask_batch() {
		$all                 = $this->all();
		$all['ask_batch']    = (int) max( 1, floor( max( 1, (int) $all['ask_batch'] ) / 2 ) );
		$all['ask_batch_at'] = gmdate( 'Y-m-d' );
		$this->persist( $all );
	}

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
}
