<?php
/**
 * Runs a monitoring pass: for every tracked prompt, ask every active provider,
 * analyze each answer, and store the result. Also powers the "test this key"
 * check in the settings screen.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

use Agentimus\Visibility\Settings;
use Agentimus\Visibility\Providers\Provider;

defined( 'ABSPATH' ) || exit;

final class Runner {

	/** @var string Option storing the unix time of the last completed run. */
	const LAST_RUN_OPTION = 'agentimus_visibility_last_run';

	/** @var int Milliseconds to pause between checks, so a burst can't trip a per-minute limit. */
	const PACE_MS = 300;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Pro settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Execute a full run.
	 *
	 * @return array {
	 *     @type bool   $ran    Whether any checks were performed.
	 *     @type string $reason Why it didn't run (when $ran is false).
	 *     @type int    $runId  The run identifier (unix time of the run).
	 *     @type int    $checks Number of (prompt × provider) checks stored.
	 * }
	 */
	public function run() {
		$prompts   = array_values( (array) $this->settings->get( 'prompts', array() ) );
		$providers = $this->settings->active_providers();

		if ( empty( $prompts ) ) {
			return array( 'ran' => false, 'reason' => 'no_prompts' );
		}
		if ( empty( $providers ) ) {
			return array( 'ran' => false, 'reason' => 'no_providers' );
		}

		// A run makes many sequential HTTP calls; give it room rather than risking
		// a mid-run timeout on hosts that allow lifting the limit.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// If the table currently holds sample data, drop it so real results don't
		// share a trend with fabricated runs.
		if ( Demo::is_active() ) {
			Store::clear();
		}

		$brand       = (string) $this->settings->get( 'brand', '' );
		$domain      = (string) $this->settings->get( 'domain', '' );
		$competitors = (array) $this->settings->get( 'competitors', array() );

		$run_id = time();
		$checks = 0;

		foreach ( $prompts as $prompt ) {
			foreach ( $providers as $pid => $cfg ) {
				$provider = Provider::make( $pid );
				if ( ! $provider ) {
					continue;
				}

				// Smooth out bursts (helps the single-engine case most, where every
				// check hits the same provider back to back). Skip before the first.
				if ( $checks > 0 && self::PACE_MS > 0 ) {
					usleep( self::PACE_MS * 1000 );
				}

				$result   = $provider->query( $prompt, $cfg['key'], $cfg['model'], ! empty( $cfg['web_search'] ) );
				$analysis = Analyzer::analyze( $result, $brand, $domain, $competitors );

				Store::insert(
					array(
						'run_id'      => $run_id,
						'provider'    => $pid,
						'model'       => $cfg['model'],
						'prompt'      => $prompt,
						'mentioned'   => $analysis['mentioned'],
						'cited'       => $analysis['cited'],
						'position'    => $analysis['position'],
						'competitors' => $analysis['competitors'],
						'answer'      => $result['text'],
						'error'       => $result['error'],
					)
				);
				$checks++;
			}
		}

		Store::prune( (int) $this->settings->get( 'retention_days', 180 ) );
		update_option( self::LAST_RUN_OPTION, $run_id, false );
		delete_option( Demo::FLAG ); // Real results now — no longer a sample.

		return array(
			'ran'    => true,
			'runId'  => $run_id,
			'checks' => $checks,
		);
	}

	/**
	 * Verify a single provider key/model with one cheap round-trip. Uses the
	 * passed-in credentials so a key can be tested before it is saved.
	 *
	 * @param string $id    Provider id.
	 * @param string $key   API key to test.
	 * @param string $model Model id.
	 * @return array { ok: bool, error?: string, sample?: string }
	 */
	public function test( $id, $key, $model ) {
		$provider = Provider::make( $id );
		if ( ! $provider ) {
			return array( 'ok' => false, 'error' => __( 'Unknown provider.', 'agentimus' ) );
		}
		if ( '' === trim( (string) $key ) ) {
			return array( 'ok' => false, 'error' => __( 'No API key provided.', 'agentimus' ) );
		}

		$result = $provider->query( __( 'Reply with the single word: OK', 'agentimus' ), $key, $model );
		if ( '' !== $result['error'] ) {
			return array( 'ok' => false, 'error' => $result['error'] );
		}
		return array( 'ok' => true, 'sample' => substr( (string) $result['text'], 0, 120 ) );
	}
}
