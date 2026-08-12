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

	/** @var string Option holding the unix time the in-progress run took the lock. */
	const LOCK_OPTION = 'agentimus_visibility_lock';

	/** @var int Seconds after which a lock left behind by a crashed run is treated as
	 * stale and may be stolen — a ceiling on one run, so monitoring can never wedge. */
	const LOCK_TTL = HOUR_IN_SECONDS;

	/** @var int Re-stamp the lock every this-many checks so a genuinely long run
	 * (many checks × up to the web timeout each) stays fresh well inside LOCK_TTL and
	 * isn't judged stale and stolen mid-flight — which would start a second, paid run. */
	const LOCK_HEARTBEAT_EVERY = 10;

	/** @var int Default hard ceiling on (prompt × provider) checks per run — a spend
	 * backstop above the structural product/prompt/provider caps. Filterable. */
	const DEFAULT_MAX_CHECKS = 1000;

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
		$reason = $this->blocking_reason();
		if ( '' !== $reason ) {
			return array( 'ran' => false, 'reason' => $reason );
		}

		// A run spends real money on every (prompt × provider) check, so two runs must
		// never overlap — a second "Run now", or a scheduled tick firing while a
		// previous run is still going, would double the bill. Take a durable, cross-
		// process lock (an option, so it holds across the separate cron/loopback
		// processes a run spans, even with no object cache) and bail if a run already
		// holds it. A crashed run's lock self-heals after LOCK_TTL, so monitoring can
		// never wedge permanently.
		if ( ! self::acquire_run_lock() ) {
			return array( 'ran' => false, 'reason' => 'already_running' );
		}
		try {
			return $this->do_run();
		} finally {
			self::release_run_lock();
		}
	}

	/**
	 * The run body, executed under the run lock (see run()). Loops every active
	 * product × prompt × provider, stores each result, and stops early if the per-run
	 * check ceiling is reached.
	 *
	 * @return array
	 */
	private function do_run() {
		$targets   = array_values( (array) $this->settings->get( 'targets', array() ) );
		$providers = $this->settings->active_providers();

		// A run makes many sequential HTTP calls; give it room rather than risking
		// a mid-run timeout on hosts that allow lifting the limit.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- a run makes many sequential HTTP calls; a mid-run timeout would lose the whole run.
		}

		$run_id     = time();
		$checks     = 0;
		$capped     = false;
		$max_checks = $this->max_checks_per_run();

		// Each product is checked on its own name, website and competitors, against
		// its own list of questions. Paused products are skipped.
		foreach ( $targets as $t ) {
			if ( ! ( $t['active'] ?? true ) ) {
				continue;
			}
			$name        = (string) ( $t['name'] ?? '' );
			$domain      = (string) ( $t['domain'] ?? '' );
			$competitors = (array) ( $t['competitors'] ?? array() );
			$prompts     = array_values( (array) ( $t['prompts'] ?? array() ) );

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
					$analysis = Analyzer::analyze( $result, $name, $domain, $competitors );

					Store::insert(
						array(
							'run_id'      => $run_id,
							'brand'       => $name,
							'provider'    => $pid,
							'model'       => $cfg['model'],
							'prompt'      => $prompt,
							'mentioned'   => $analysis['mentioned'],
							'cited'       => $analysis['cited'],
							'position'    => $analysis['position'],
							'competitors' => $analysis['competitors'],
							'answer'      => $result['text'],
							'sources'     => $result['citations'],
							'error'       => $result['error'],
						)
					);
					$checks++;

					// Keep our own lock fresh so a long run isn't judged stale (LOCK_TTL)
					// and stolen by a scheduled tick mid-flight — which would start a
					// second, money-spending run. We hold the lock; this only re-stamps it.
					if ( 0 === $checks % self::LOCK_HEARTBEAT_EVERY ) {
						update_option( self::LOCK_OPTION, time(), false );
					}

					// Spend backstop: stop the whole run once the ceiling is hit,
					// rather than keep spending past it.
					if ( $checks >= $max_checks ) {
						$capped = true;
						break 3;
					}
				}
			}
		}

		Store::prune( (int) $this->settings->get( 'retention_days', 180 ) );
		update_option( self::LAST_RUN_OPTION, $run_id, false );

		$result = array(
			'ran'    => true,
			'runId'  => $run_id,
			'checks' => $checks,
			'capped' => $capped,
		);

		/**
		 * Fires when a citation monitoring run completed and its results are
		 * stored. Integrations relay the summary counts from here.
		 *
		 * @param array $result { ran, runId, checks, capped }.
		 */
		do_action( 'agentimus_citation_run_finished', $result );

		return $result;
	}

	/**
	 * The hard ceiling on (prompt × provider) checks a single run performs — a spend
	 * backstop above the structural caps (products × prompts × providers), in case a
	 * filter injects extra targets or those caps change. Each check's cost is itself
	 * bounded by the providers' max-output-token caps, so this bounds a run's total
	 * spend; lower it via the filter to cap monitoring spend more tightly.
	 *
	 * @return int
	 */
	public function max_checks_per_run() {
		/**
		 * Filter the per-run check ceiling.
		 *
		 * @param int $max Default ceiling (self::DEFAULT_MAX_CHECKS).
		 */
		$max = (int) apply_filters( 'agentimus_visibility_max_checks_per_run', self::DEFAULT_MAX_CHECKS );
		return $max > 0 ? $max : self::DEFAULT_MAX_CHECKS;
	}

	/**
	 * Acquire the run lock, or false if a run already holds it. Backed by an option so
	 * it is durable across the separate cron/loopback processes a run spans (a
	 * transient/object-cache lock would not survive them without a persistent cache).
	 * A lock older than LOCK_TTL is stale — left by a crashed run — and is stolen, so
	 * monitoring can never be wedged permanently. Public for the run-lock test.
	 *
	 * @return bool True if this caller acquired the lock and should run.
	 */
	public static function acquire_run_lock() {
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 ) {
			if ( ( time() - $held ) < self::LOCK_TTL ) {
				return false; // A live run holds it.
			}
			// Stale — a crashed run. Steal it (best effort; the money-losing race is
			// the free-lock one below, which add_option makes atomic).
			update_option( self::LOCK_OPTION, time(), false );
			return true;
		}
		// Free: acquire atomically. add_option is a single INSERT that fails when the
		// row already exists, so if two runs start at once (a second "Run now", or a
		// cron tick racing a manual run) exactly one wins — the loser does not run and
		// does not spend a second round of API calls. update_option's get-then-set
		// could let both through.
		return add_option( self::LOCK_OPTION, time(), '', 'no' );
	}

	/**
	 * Release the run lock. Call in a `finally` so a run that throws frees it at once.
	 *
	 * @return void
	 */
	public static function release_run_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Why a run can't do anything yet, or '' when it can. A run needs at least one
	 * active product with a question, and at least one usable engine.
	 *
	 * @return string '' | 'no_prompts' | 'no_providers'
	 */
	public function blocking_reason() {
		$has_prompts = false;
		foreach ( (array) $this->settings->get( 'targets', array() ) as $t ) {
			if ( ( $t['active'] ?? true ) && ! empty( $t['prompts'] ) ) {
				$has_prompts = true;
				break;
			}
		}
		if ( ! $has_prompts ) {
			return 'no_prompts';
		}
		if ( empty( $this->settings->active_providers() ) ) {
			return 'no_providers';
		}
		return '';
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
