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

		// Reconcile the schedule with the stored frequency on admin load. Cheap
		// (an option read plus a compare) and self-heals a drifted schedule.
		add_action( 'admin_init', array( $this, 'sync_schedule' ) );
	}

	/**
	 * The cron entry point.
	 */
	public function run_scheduled() {
		( new Runner( $this->settings ) )->run();
	}

	/**
	 * Align the cron schedule with the current frequency setting.
	 */
	public function sync_schedule() {
		self::apply_schedule( (string) $this->settings->get( 'frequency', 'weekly' ) );
	}

	/**
	 * Schedule the recurring run for this site at activation, honouring whatever
	 * frequency is stored (default weekly).
	 */
	public static function schedule() {
		self::apply_schedule( (string) ( new Settings() )->get( 'frequency', 'weekly' ) );
	}

	/**
	 * Clear the recurring run for this site.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Ensure exactly one scheduled event exists at the right recurrence — or none
	 * when the frequency is "manual". No-ops when already correctly scheduled, so
	 * it's safe to call on every admin load.
	 *
	 * @param string $frequency manual | daily | weekly.
	 */
	private static function apply_schedule( $frequency ) {
		if ( 'manual' === $frequency ) {
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
