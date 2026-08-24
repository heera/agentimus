<?php
/**
 * Wires the monitoring engine into WordPress: the recurring cron event and the
 * logic that keeps its cadence in sync with the chosen frequency.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

use Agentimus\Visibility\Settings;

defined( 'ABSPATH' ) || exit;

final class Module {

	/** @var string The cron hook that runs a monitoring pass. */
	const HOOK = 'agentimus_visibility_run';

	/** @var string The cron hook for a one-off "run now" pass, fired in the background. */
	const HOOK_ONCE = 'agentimus_visibility_run_now';

	/** @var string Transient set while a background run is in flight (self-expires). */
	const RUNNING = 'agentimus_visibility_running';

	/** @var int Safety TTL (seconds) on the running flag, so a died job can't wedge the UI. */
	const RUNNING_TTL = 900;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Pro settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the cron callback and keep the schedule aligned with the setting.
	 */
	public function register() {
		// Self-heal the results table on every boot, so a multisite sub-site that
		// missed the activation hook still gets it (a single option read in steady
		// state). Mirrors Activity\Module.
		Table::maybe_install();

		add_action( self::HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::HOOK_ONCE, array( $this, 'run_now_job' ) );

		// Reconcile the schedule with the stored frequency on admin load. Cheap
		// (an option read plus a compare) and self-heals a drifted schedule.
		add_action( 'admin_init', array( $this, 'sync_schedule' ) );
	}

	/**
	 * The recurring cron entry point.
	 */
	public function run_scheduled() {
		( new Runner( $this->settings ) )->run();
	}

	/**
	 * The background "run now" entry point. A run makes many slow HTTP calls (more so
	 * with live web search), which can outlast the web server's gateway timeout — so
	 * the admin queues it here and polls for results instead of waiting on the request.
	 * The running flag is always cleared, even if the run throws.
	 */
	public function run_now_job() {
		try {
			// 'manual': the owner pressed Run check now and is watching the
			// screen fill in. The integrations use this to stay quiet — see the
			// completion hook in Runner::run().
			( new Runner( $this->settings ) )->run( 'manual' );
		} finally {
			delete_transient( self::RUNNING );
		}
	}

	/**
	 * Queue an immediate background run and nudge cron to start it now. Sets the
	 * running flag so the admin can show progress and poll for completion.
	 */
	public function queue_now() {
		set_transient( self::RUNNING, time(), self::RUNNING_TTL );

		if ( ! wp_next_scheduled( self::HOOK_ONCE ) ) {
			wp_schedule_single_event( time(), self::HOOK_ONCE );
		}

		// Fire the loopback now rather than waiting for the next natural cron tick,
		// so "Run now" feels immediate. Guarded so we never recurse inside cron.
		if ( ! defined( 'DOING_CRON' ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Whether a background run is currently in flight.
	 *
	 * @return bool
	 */
	public static function is_running() {
		return (bool) get_transient( self::RUNNING );
	}

	/**
	 * Align the cron schedule with the current settings (master switch + frequency).
	 */
	public function sync_schedule() {
		self::apply_schedule(
			self::should_schedule( $this->settings ),
			(string) $this->settings->get( 'frequency', 'weekly' )
		);
	}

	/**
	 * Schedule the recurring run for this site at activation, honouring whatever
	 * is stored (default: off until configured).
	 */
	public static function schedule() {
		$settings = new Settings();
		self::apply_schedule(
			self::should_schedule( $settings ),
			(string) $settings->get( 'frequency', 'weekly' )
		);
	}

	/**
	 * Whether an automatic run should be scheduled at all: the master switch must be
	 * on, at least one engine must be usable (enabled + keyed), and at least one
	 * active product must have a question. Otherwise a run would do nothing, so we
	 * register no cron — a fresh or half-configured install schedules nothing.
	 *
	 * @param Settings $settings Pro settings.
	 * @return bool
	 */
	private static function should_schedule( Settings $settings ) {
		if ( ! (bool) $settings->get( 'active', false ) ) {
			return false;
		}
		if ( empty( $settings->active_providers() ) ) {
			return false;
		}
		foreach ( (array) $settings->get( 'targets', array() ) as $t ) {
			if ( ( $t['active'] ?? true ) && ! empty( $t['prompts'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clear the recurring run for this site.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Ensure exactly one scheduled event exists at the right recurrence — or none
	 * when automatic checks shouldn't run. No-ops when already correctly scheduled,
	 * so it's safe to call on every admin load.
	 *
	 * @param bool   $enabled   Whether a recurring run should be scheduled at all.
	 * @param string $frequency daily | weekly.
	 */
	private static function apply_schedule( $enabled, $frequency ) {
		if ( ! $enabled || 'manual' === $frequency ) {
			self::unschedule();
			return;
		}

		$recurrence = ( 'daily' === $frequency ) ? 'daily' : 'weekly';

		$event = wp_get_scheduled_event( self::HOOK );
		if ( $event && isset( $event->schedule ) && $event->schedule === $recurrence ) {
			return; // Already scheduled correctly.
		}

		self::unschedule();
		wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, self::HOOK );
	}
}
