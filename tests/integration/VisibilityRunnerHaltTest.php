<?php
/**
 * A run stops asking an engine that has said no for the rest of the run.
 *
 * ⭐ Found in the 1.53.4 audit: a wrong or revoked key (401/403) or a spent
 * quota (429 after the one Retry-After wait) failed every remaining question
 * the same way, one paid round-trip each, until the run's ceiling. The first
 * refusal is the answer for that engine; the other engines keep going.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Crypto;
use Agentimus\Visibility\Runner;
use Agentimus\Visibility\Settings;
use Agentimus\Visibility\Table;

final class VisibilityRunnerHaltTest extends DbTestCase {

	/** @var array<string,int> Calls made, per engine host. */
	private $calls = array();

	/** @var array<string,int> HTTP status each host answers with. */
	private $answers = array();

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		delete_option( Runner::LOCK_OPTION );
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		parent::tear_down();
	}

	public function answer( $pre, $args, $url ) {
		$host                 = (string) wp_parse_url( $url, PHP_URL_HOST );
		$this->calls[ $host ] = ( $this->calls[ $host ] ?? 0 ) + 1;
		$code                 = $this->answers[ $host ] ?? 200;
		$body                 = 200 === $code
			? array( 'choices' => array( array( 'message' => array( 'content' => 'Acme is fine.' ) ) ) )
			: array( 'error' => array( 'message' => 'Refused (' . $code . ')' ) );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function configure( array $providers ) {
		$cfg = array();
		foreach ( $providers as $id => $model ) {
			$cfg[ $id ] = array( 'enabled' => true, 'key' => Crypto::encrypt( 'key-' . $id ), 'model' => $model );
		}
		update_option(
			Settings::OPTION,
			array(
				'targets'   => array(
					array(
						'name'    => 'Acme',
						'domain'  => 'acme.test',
						'active'  => true,
						'prompts' => array( 'best tools one', 'best tools two', 'best tools three', 'best tools four' ),
					),
				),
				'providers' => $cfg,
			)
		);
	}

	private function errors_for( $provider ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT error FROM ' . Table::name() . ' WHERE provider = %s', $provider ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_a_refused_key_is_asked_once_per_run() {
		$this->configure( array( 'openai' => 'gpt-4o-mini' ) );
		$this->answers['api.openai.com'] = 401;

		$result = ( new Runner( new Settings() ) )->run( 'manual' );

		$this->assertTrue( $result['ran'] );
		$this->assertSame( 1, $this->calls['api.openai.com'], 'one refusal answers for the whole run' );
		$this->assertCount( 1, $this->errors_for( 'openai' ), 'only the call that was made is recorded' );
	}

	public function test_a_spent_quota_is_asked_once_per_run() {
		$this->configure( array( 'openai' => 'gpt-4o-mini' ) );
		$this->answers['api.openai.com'] = 429;

		( new Runner( new Settings() ) )->run( 'manual' );

		$this->assertSame( 1, $this->calls['api.openai.com'] );
	}

	public function test_a_passing_engine_keeps_going_beside_a_refused_one() {
		$this->configure( array( 'openai' => 'gpt-4o-mini', 'perplexity' => 'sonar' ) );
		$this->answers['api.openai.com'] = 403;

		( new Runner( new Settings() ) )->run( 'manual' );

		$this->assertSame( 1, $this->calls['api.openai.com'] );
		$this->assertSame( 4, $this->calls['api.perplexity.ai'] );
	}

	public function test_a_manual_run_reports_itself_as_manual() {
		// run() never handed its trigger to the run body, so every run read an
		// undefined variable and said "scheduled" — and the integrations, which
		// announce only scheduled runs, announced the owner's own clicks.
		$this->configure( array( 'openai' => 'gpt-4o-mini' ) );
		$fired = array();
		$hook  = function ( $result ) use ( &$fired ) {
			$fired[] = $result['trigger'];
		};
		add_action( 'agentimus_citation_run_finished', $hook );

		$manual    = ( new Runner( new Settings() ) )->run( 'manual' );
		$scheduled = ( new Runner( new Settings() ) )->run();

		remove_action( 'agentimus_citation_run_finished', $hook );
		$this->assertSame( 'manual', $manual['trigger'] );
		$this->assertSame( 'scheduled', $scheduled['trigger'] );
		$this->assertSame( array( 'manual', 'scheduled' ), $fired );
	}

	public function test_a_server_error_does_not_stop_the_engine() {
		// A 500 is one bad answer, not a verdict on the key: keep asking.
		$this->configure( array( 'openai' => 'gpt-4o-mini' ) );
		$this->answers['api.openai.com'] = 500;

		( new Runner( new Settings() ) )->run( 'manual' );

		$this->assertSame( 4, $this->calls['api.openai.com'] );
	}
}
