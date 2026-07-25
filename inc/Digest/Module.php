<?php
/**
 * Weekly digest — the module. Owns the weekly cron event, the send flow, the
 * snapshot the next digest compares against, and the one-click stop endpoint.
 *
 * The stop link is a stored random KEY, deliberately not a nonce: nonces
 * expire within a day, and a digest link gets clicked days after it was sent —
 * "link expired" on an unsubscribe is how a plugin teaches people to
 * deactivate it instead. The key's ONLY power is turning the digest off; it is
 * deleted on use, and a fresh one is minted for the next send.
 *
 * @package Agentimus
 */

namespace Agentimus\Digest;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Module {

	const CRON = 'agentimus_weekly_digest';

	/** Option: the last digest's snapshot ({score, sent_at}). Not autoloaded — read once a week. */
	const SNAPSHOT_OPTION = 'agentimus_digest_last';

	/** Option: the current stop-link key. Not autoloaded — read on send and on a stop click. */
	const STOP_KEY_OPTION = 'agentimus_digest_stop_key';

	/** admin-post action name for the stop link. */
	const STOP_ACTION = 'agentimus_digest_stop';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the cron handler and the stop endpoint, then self-heal the schedule
	 * (both directions: a missed activation gains the event, a disabled digest
	 * loses it — whatever path flipped the setting).
	 */
	public function register() {
		add_action( self::CRON, array( $this, 'send' ) );
		// The key IS the authorization, so the endpoint works logged-out too:
		// the recipient may not be a logged-in admin at click time.
		add_action( 'admin_post_' . self::STOP_ACTION, array( $this, 'handle_stop' ) );
		add_action( 'admin_post_nopriv_' . self::STOP_ACTION, array( $this, 'handle_stop' ) );
		$this->sync_schedule();
	}

	/**
	 * Make the schedule agree with the settings. Cheap (one wp_next_scheduled
	 * lookup), so it runs on every boot — the same self-healing the activity
	 * prune uses, but bidirectional because this one has an off switch.
	 *
	 * Beyond on/off, the scheduled event must sit on the CONFIGURED day and hour:
	 * a slot the owner just changed reschedules on save (Rest calls this), and a
	 * 'weekly' recurrence that drifted off the site-local hour across a DST
	 * change (WP-cron repeats in UTC) heals on the next boot.
	 */
	public function sync_schedule() {
		$enabled = $this->settings->enabled( 'digest_enabled' );
		$next    = wp_next_scheduled( self::CRON );
		if ( ! $enabled ) {
			if ( $next ) {
				self::unschedule();
			}
			return;
		}
		if ( $next && $this->on_slot( $next ) ) {
			return;
		}
		self::unschedule();
		wp_schedule_event( self::first_send_at(), 'weekly', self::CRON );
	}

	/**
	 * Whether a timestamp falls on the configured send slot, site time.
	 *
	 * @param int $timestamp Unix timestamp (a scheduled event's).
	 * @return bool
	 */
	private function on_slot( $timestamp ) {
		$at = ( new \DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( wp_timezone() );
		return (int) $this->settings->get( 'digest_day', 1 ) === (int) $at->format( 'N' )
			&& (int) $this->settings->get( 'digest_hour', 8 ) === (int) $at->format( 'G' )
			&& 0 === (int) $at->format( 'i' );
	}

	/**
	 * Activation hook: put the event in place. If the digest is actually
	 * disabled, the next boot's {@see sync_schedule()} clears it again — and
	 * {@see send()} refuses on its own anyway.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( self::first_send_at(), 'weekly', self::CRON );
		}
	}

	/** Deactivation hook: clear the event. */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * The first send: the next occurrence of the configured day and hour, site
	 * time (defaults: Monday, 8). If that slot is still ahead today, today
	 * qualifies. The weekly recurrence keeps the cadence from there.
	 *
	 * @return int Unix timestamp.
	 */
	public static function first_send_at() {
		$settings = new Settings();
		return self::next_occurrence(
			(int) $settings->get( 'digest_day', 1 ),
			(int) $settings->get( 'digest_hour', 8 ),
			new \DateTimeImmutable( 'now', wp_timezone() )
		);
	}

	/**
	 * The next strictly-future occurrence of an ISO day + hour, seen from $now.
	 * Pure (no clock, no settings) so a test can pin "now" anywhere in the week.
	 *
	 * @param int                $day  ISO-8601 day: 1 = Monday … 7 = Sunday.
	 * @param int                $hour Hour, 0–23, in $now's timezone.
	 * @param \DateTimeImmutable $now  The reference moment.
	 * @return int Unix timestamp.
	 */
	public static function next_occurrence( $day, $hour, \DateTimeImmutable $now ) {
		$next = $now->setTime( $hour, 0 );
		while ( $day !== (int) $next->format( 'N' ) || $next <= $now ) {
			$next = $next->modify( '+1 day' )->setTime( $hour, 0 );
		}
		return $next->getTimestamp();
	}

	/* -- Sending --------------------------------------------------------- */

	/** The cron handler: collect, gate, send, snapshot. */
	public function send() {
		if ( ! $this->settings->enabled( 'digest_enabled' ) ) {
			return;
		}
		$this->deliver( $this->collect() );
	}

	/**
	 * A test send, for the settings button: same data, same renderer, but the
	 * quiet-week gate is bypassed (a test must always arrive) and the snapshot
	 * is left alone (a test must not eat next week's comparison).
	 *
	 * @return array{sent:bool,to:string}
	 */
	public function send_test() {
		$to = $this->recipient();
		return array(
			'sent' => $this->deliver( $this->collect(), true ),
			'to'   => $to,
		);
	}

	/** Collect the digest data, stop link included. */
	private function collect() {
		$snapshot                            = get_option( self::SNAPSHOT_OPTION, null );
		$data                                = Data::collect( $this->settings, is_array( $snapshot ) ? $snapshot : null );
		$data['links']['stop'] = self::stop_url();
		return $data;
	}

	/**
	 * Render and send one digest. Split from {@see send()} so a unit test can
	 * hand it a fixture array without a database.
	 *
	 * @param array $data Collected digest data.
	 * @param bool  $test Test send: bypass the quiet-week gate, skip the snapshot.
	 * @return bool Whether an email was handed to wp_mail successfully.
	 */
	public function deliver( array $data, $test = false ) {
		if ( ! $test && ! Renderer::should_send( $data ) ) {
			return false;
		}
		$to = $this->recipient();
		if ( '' === $to ) {
			return false;
		}

		$subject = Renderer::subject( $data );
		if ( $test ) {
			/* translators: %s: the digest's normal subject line. */
			$subject = sprintf( __( 'Test: %s', 'agentimus' ), $subject );
		}

		$sent = (bool) wp_mail( $to, $subject, Renderer::html( $data ), array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( $sent && ! $test ) {
			// What the NEXT digest compares against — stored only when this one
			// actually went out, so "up from 78" always means "up from what we
			// told you", even across a failed week.
			update_option(
				self::SNAPSHOT_OPTION,
				array(
					'score'   => isset( $data['score']['now'] ) ? $data['score']['now'] : null,
					'sent_at' => time(),
				),
				false
			);
		}
		return $sent;
	}

	/**
	 * Where the digest goes: the owner's override when it is a real address,
	 * the site admin email otherwise.
	 *
	 * @return string
	 */
	public function recipient() {
		$to = (string) $this->settings->get( 'digest_recipient', '' );
		if ( '' !== $to && is_email( $to ) ) {
			return $to;
		}
		return (string) get_option( 'admin_email', '' );
	}

	/* -- The stop link ---------------------------------------------------- */

	/**
	 * The one-click stop URL. Mints and stores the key on first use.
	 *
	 * @return string
	 */
	public static function stop_url() {
		$key = get_option( self::STOP_KEY_OPTION, '' );
		if ( ! is_string( $key ) || '' === $key ) {
			$key = wp_generate_password( 24, false, false );
			update_option( self::STOP_KEY_OPTION, $key, false );
		}
		return add_query_arg(
			array(
				'action' => self::STOP_ACTION,
				'k'      => rawurlencode( $key ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The stop endpoint. Key match = flip the digest off, kill the used key,
	 * clear the schedule, say so in plain words. Nothing else — this endpoint
	 * must never grow a second power.
	 */
	public function handle_stop() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deliberately key-authorized, not nonce-authorized: nonces expire within a day and digest links are clicked later. The stored key is single-use and only ever disables the digest.
		$sent = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['k'] ) ) : '';
		$key  = (string) get_option( self::STOP_KEY_OPTION, '' );
		if ( '' === $key || '' === $sent || ! hash_equals( $key, $sent ) ) {
			wp_die(
				esc_html__( 'This stop link is not valid. You can turn the weekly email off in Agentimus settings.', 'agentimus' ),
				esc_html__( 'Weekly email', 'agentimus' ),
				array( 'response' => 403 )
			);
		}

		$all                   = $this->settings->all();
		$all['digest_enabled'] = false;
		$this->settings->update( $all );
		delete_option( self::STOP_KEY_OPTION ); // The used link dies; re-enabling mints a fresh one.
		self::unschedule();

		wp_die(
			esc_html__( 'Done — the weekly email is off. You can turn it back on any time in Agentimus settings.', 'agentimus' ),
			esc_html__( 'Weekly email stopped', 'agentimus' ),
			array( 'response' => 200 )
		);
	}
}
