<?php
/**
 * Cloudflare connection store — the edge data-source configuration: the API
 * token (encrypted at rest), the detected zone, and the poll bookkeeping.
 *
 * Kept in its own option (never mixed into the core settings) so connecting or
 * disconnecting the edge source can't disturb anything else. The token is
 * write-only across the REST boundary: public_view() reports only WHETHER a
 * token is stored, never the token — there is deliberately no reveal endpoint,
 * because the owner can always mint a fresh token in the Cloudflare dashboard.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

use Agentimus\ConnectionStore;
use Agentimus\Crypto;

defined( 'ABSPATH' ) || exit;

final class Settings {

	use ConnectionStore;

	/** @var string Option key. */
	const OPTION = 'agentimus_cloudflare';

	/**
	 * Every stored field with its empty default.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'token'        => '', // Ciphertext at rest (see Crypto); '' when disconnected.
			'zone_id'      => '',
			'zone_name'    => '',
			'connected_at' => 0,
			'last_poll_at' => 0,
			'last_error'   => '', // The most recent poll failure, '' after a clean poll.
			// Purge bookkeeping, kept apart from the poll's: a failed purge must
			// not smear "poll failed" over perfectly good numbers, and vice versa.
			'last_purge_at'    => 0,
			'last_purge_error' => '',
			// What the last purge was ASKED to clear and what it actually
			// cleared. Two numbers because they can differ: the edge takes the
			// list in batches, and a failure partway leaves the earlier batches
			// cleared and the rest still stale. One number could only tell that
			// story by lying about half of it. Both 0 for a purge-everything,
			// which has no list.
			'last_purge_urls'    => 0,
			'last_purge_cleared' => 0,
			// A 401/403 refusal stands the AUTOMATIC purge down (the token can't
			// purge, and retrying on every save would be a nag plus a wasted
			// request). Cleared by any clean purge — the manual button always
			// attempts, so fixing the token and pressing Purge once re-arms —
			// and by reconnecting.
			'purge_denied'     => false,
			// Conflicts the owner chose to hide: { conflict id => dismissed-at }.
			// A dismissal covers that ONGOING situation only — prune_dismissed()
			// drops the record once the conflict stops firing, so a later
			// recurrence shows again instead of staying silenced forever.
			'dismissed'    => array(),
			// The poll-time spoof check's verdicts: { operator => { at, sampled,
			// verified, spoofed, unknown } } — whether the blocked traffic behind
			// a warn conflict actually came from the operator's own published
			// addresses ({@see SpoofCheck}). Replaced wholesale each successful
			// run, so a verdict dies with its conflict; a failed run keeps the
			// old map, which then ages out ({@see SpoofCheck::FRESH_SECONDS})
			// rather than vanishing.
			'spoof_checks' => array(),
			// When each currently-firing conflict was FIRST seen: { id => unix }.
			// ⭐⭐ WHY THIS EXISTS. The conflicts are recomputed from observed
			// behaviour on every read and stored nowhere, so a card could say "in
			// the last 7 days" and nothing could say WHEN IT STARTED — his words,
			// 2026-08-28: "I don't know since when these are there." A warning
			// with no age reads as either brand new or ancient, and the two call
			// for different reactions.
			// ⛔ Same lifecycle as `dismissed` above, deliberately: the record
			// dies with the situation ({@see note_first_seen}), so a conflict that
			// ends and returns is correctly dated from its RETURN, not from the
			// first time it ever happened.
			'first_seen'   => array(),
		);
	}

	/**
	 * Hide one conflict.
	 *
	 * @param string $id The conflict id (e.g. edge-blocks-openai).
	 * @return void
	 */
	public function dismiss( $id ) {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return;
		}
		$all                       = $this->all();
		$all['dismissed'][ $id ] = time();
		$this->persist( $all );
	}

	/**
	 * Bring one hidden conflict back — the owner revisited their "accepted"
	 * and wants the pin on screen again.
	 *
	 * @param string $id The conflict id.
	 * @return void
	 */
	public function undismiss( $id ) {
		$id  = sanitize_key( $id );
		$all = $this->all();
		if ( '' === $id || ! array_key_exists( $id, (array) $all['dismissed'] ) ) {
			return;
		}
		unset( $all['dismissed'][ $id ] );
		$this->persist( $all );
	}

	/**
	 * The stored spoof-check verdicts, operator => tally ({@see SpoofCheck}).
	 *
	 * @return array<string,array>
	 */
	public function spoof_checks() {
		return (array) $this->get( 'spoof_checks', array() );
	}

	/**
	 * Store the spoof check's verdicts — the whole map at once, replacing the
	 * previous run's, so operators no longer in conflict drop out with it.
	 *
	 * @param array<string,array> $map Operator => tally.
	 * @return void
	 */
	public function note_spoof_checks( array $map ) {
		$all = $this->all();
		if ( (array) $all['spoof_checks'] === $map ) {
			return; // The common hourly case: nothing changed, write nothing.
		}
		$all['spoof_checks'] = $map;
		$this->persist( $all );
	}

	/**
	 * The ids the owner has hidden.
	 *
	 * @return string[]
	 */
	public function dismissed_ids() {
		return array_map( 'strval', array_keys( (array) $this->get( 'dismissed', array() ) ) );
	}

	/**
	 * Forget dismissals for conflicts that are no longer firing — the situation
	 * ended, so the hide has done its job; a NEW occurrence must show again.
	 *
	 * @param string[] $active_ids The ids currently detected.
	 * @return void
	 */
	public function prune_dismissed( array $active_ids ) {
		$all   = $this->all();
		$kept  = array();
		foreach ( (array) $all['dismissed'] as $id => $at ) {
			if ( in_array( (string) $id, $active_ids, true ) ) {
				$kept[ $id ] = $at;
			}
		}
		if ( $kept !== $all['dismissed'] ) {
			$all['dismissed'] = $kept;
			$this->persist( $all );
		}
	}

	/**
	 * Stamp the conflicts firing right now, and forget the ones that stopped.
	 *
	 * Returns the whole map so the caller can date each conflict without a second
	 * read. Ids already known keep their original stamp — that is the entire
	 * point — and ids no longer firing are dropped, so a situation that ends and
	 * later returns is dated from the return.
	 *
	 * ⭐ WRITES ONLY WHEN THE SET CHANGES, exactly as {@see prune_dismissed()}
	 * does. On a site whose conflicts are steady — which is every site, most days
	 * — this costs one option read and no write at all, which is what makes it
	 * safe on the read paths that call it (the screen, the MCP tool, and the
	 * findings list, whose own rule is that it must not do real work on an admin
	 * page load).
	 *
	 * @param string[] $active_ids The ids currently detected.
	 * @return array<string,int> id => first-seen unix timestamp.
	 */
	public function note_first_seen( array $active_ids ) {
		$all   = $this->all();
		$known = (array) ( isset( $all['first_seen'] ) ? $all['first_seen'] : array() );
		$now   = time();
		$kept  = array();
		foreach ( $active_ids as $id ) {
			$id          = (string) $id;
			$kept[ $id ] = isset( $known[ $id ] ) ? (int) $known[ $id ] : $now;
		}
		if ( $kept !== $known ) {
			$all['first_seen'] = $kept;
			$this->persist( $all );
		}
		return $kept;
	}

	/**
	 * Whether a token and a zone are stored — the gate every poll and every
	 * summary read sits behind.
	 *
	 * @return bool
	 */
	public function connected() {
		$all = $this->all();
		return '' !== (string) $all['token'] && '' !== (string) $all['zone_id'];
	}

	/**
	 * The decrypted API token, or '' when none is stored (or it can't be
	 * decrypted — e.g. the site's salts were rotated). The only supported way to
	 * read the token back out; the stored form is ciphertext.
	 *
	 * @return string
	 */
	public function token() {
		return $this->secret( 'token' );
	}

	/**
	 * Store a verified connection. The token is encrypted at this single
	 * persistence point, so it is only ever STORED as ciphertext.
	 *
	 * @param string $token     The Cloudflare API token, plaintext.
	 * @param string $zone_id   The zone tag the token was verified against.
	 * @param string $zone_name The zone's human name (e.g. example.com).
	 * @return void
	 */
	public function connect( $token, $zone_id, $zone_name ) {
		$all                 = $this->all();
		$all['token']        = Crypto::encrypt_if_needed( sanitize_text_field( (string) $token ) );
		$all['zone_id']      = sanitize_text_field( (string) $zone_id );
		$all['zone_name']    = sanitize_text_field( (string) $zone_name );
		$all['connected_at'] = time();
		$all['last_poll_at'] = 0;
		$all['last_error']   = '';
		// A new token is a new question — forget the old one's purge verdicts.
		$all['last_purge_at']      = 0;
		$all['last_purge_error']   = '';
		$all['last_purge_urls']    = 0;
		$all['last_purge_cleared'] = 0;
		$all['purge_denied']       = false;
		$this->persist( $all );
	}

	/**
	 * Forget the connection entirely. Stored aggregates are kept — history is
	 * the owner's data, and reconnecting resumes where it left off.
	 *
	 * @return void
	 */
	public function disconnect() {
		$this->persist( $this->defaults() );
	}

	/**
	 * Record the outcome of a cache purge — a timestamp always, an error only
	 * when one happened (a clean purge clears the previous error AND re-arms
	 * the automatic path).
	 *
	 * ⭐ And how much of it landed. A URL purge goes to the edge in batches, so
	 * a failure partway is a PARTIAL outcome — some pages fresh, the rest still
	 * serving yesterday — and recording only "it failed" leaves the owner no way
	 * to know which. Both counts are 0 for a purge-everything: that call has no
	 * list, and inventing one would be a number nobody measured.
	 *
	 * @param string $error     Human-readable failure, or '' for a clean purge.
	 * @param bool   $denied    Whether the failure was a permission refusal
	 *                          (401/403) — stands the automatic path down.
	 * @param int    $attempted URLs this purge was asked to clear.
	 * @param int    $cleared   URLs the edge confirmed it dropped.
	 * @return void
	 */
	public function record_purge( $error = '', $denied = false, $attempted = 0, $cleared = 0 ) {
		$all                       = $this->all();
		$all['last_purge_at']      = time();
		$all['last_purge_error']   = sanitize_text_field( (string) $error );
		$all['last_purge_urls']    = max( 0, (int) $attempted );
		$all['last_purge_cleared'] = max( 0, min( (int) $cleared, (int) $attempted ) );
		$all['purge_denied']       = '' === (string) $error ? false : (bool) $denied;
		$this->persist( $all );
	}

	/**
	 * Whether the automatic purge is standing down after a permission refusal.
	 *
	 * @return bool
	 */
	public function purge_denied() {
		return (bool) $this->get( 'purge_denied', false );
	}

	/**
	 * The connection state as the admin UI may see it — everything EXCEPT the
	 * token, which never crosses the REST boundary in either direction.
	 *
	 * @return array
	 */
	public function public_view() {
		$all = $this->all();
		return array(
			'connected'      => $this->connected(),
			'zoneName'       => (string) $all['zone_name'],
			'connectedAt'    => (int) $all['connected_at'],
			'lastPollAt'     => (int) $all['last_poll_at'],
			'lastError'      => (string) $all['last_error'],
			'lastPurgeAt'    => (int) $all['last_purge_at'],
			'lastPurgeError' => (string) $all['last_purge_error'],
			'lastPurgeUrls'    => (int) $all['last_purge_urls'],
			'lastPurgeCleared' => (int) $all['last_purge_cleared'],
		);
	}
}
