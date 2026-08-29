<?php
/**
 * The read-edge-traffic ability must DECLARE every key Summary::build() can
 * return — ability output validates with additionalProperties:false, so an
 * undeclared key makes the tool reject its own honest response (the 1.30.0
 * verifyOn bug, guarded for read-request-log in AbilityOutputSchemaDriftTest).
 *
 * The connected payload needs a database, so its key lists are MIRRORS of
 * Summary::build()'s return literals; the not-connected path and the conflict
 * producer are pure and run for real.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Cloudflare\Conflicts;
	use Agentimus\Cloudflare\Settings as CloudflareSettings;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	/**
	 * Separate processes for the same reason as AbilityOutputSchemaDriftTest:
	 * the capture stub defines a global wp_register_ability().
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class EdgeAbilitySchemaDriftTest extends TestCase {

		/** Define the capture stub at RUN time (never at file load). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		/** Mirror of Summary::build()'s connected return literal. */
		private const SUMMARY_KEYS = array(
			'connected',
			'zoneName',
			'connectedAt',
			'lastPollAt',
			'lastError',
			'days',
			'totals',
			'crawlers',
			'companies',
			'conflicts',
			'hiddenConflicts',
			'dashUrl',
		);

		/** Mirror of one crawler row: Table::summary() columns + the decoration. */
		private const CRAWLER_KEYS = array( 'ua', 'requests', 'cached', 'origin', 'blocked', 'bytes', 'name', 'operator', 'blockedByYou' );

		private function registered(): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey(
				'agentimus/read-edge-traffic',
				$GLOBALS['_af_abilities'],
				'read-edge-traffic did not register — the capture stub or the slug moved.'
			);
			return $GLOBALS['_af_abilities']['agentimus/read-edge-traffic'];
		}

		public function test_the_tool_is_on_the_mcp_roster() {
			// The 1.30.0 suggest-internal-links lesson: a registered ability the
			// roster never lists is a tool the release notes describe but the
			// server does not serve.
			$this->assertContains(
				'agentimus/read-edge-traffic',
				( new Registrar( new Settings() ) )->mcp_abilities()
			);
		}

		public function test_every_summary_key_is_declared_in_the_output_schema() {
			$schema = $this->registered()['output_schema'];

			$this->assertFalse(
				$schema['additionalProperties'],
				'Top-level output allows undeclared keys — this test expects the strict mode that made the 1.30.0 bug possible.'
			);
			foreach ( self::SUMMARY_KEYS as $key ) {
				$this->assertArrayHasKey(
					$key,
					$schema['properties'],
					"Summary::build() returns `$key` but the output schema does not declare it — the ability will reject its own response."
				);
			}
		}

		public function test_every_crawler_and_company_key_is_declared() {
			$schema = $this->registered()['output_schema'];

			$crawler = $schema['properties']['crawlers']['items']['properties'];
			foreach ( self::CRAWLER_KEYS as $key ) {
				$this->assertArrayHasKey( $key, $crawler, "crawler rows carry `$key` but the schema does not declare it." );
			}

			$company = $schema['properties']['companies']['items']['properties'];
			foreach ( array( 'operator', 'requests', 'bytes' ) as $key ) {
				$this->assertArrayHasKey( $key, $company, "company rows carry `$key` but the schema does not declare it." );
			}
		}

		public function test_every_conflict_key_is_declared_using_the_real_producer() {
			$schema = $this->registered()['output_schema'];

			// Conflicts::detect() is pure — produce a real firing warn, then apply
			// the same link→url rewrite Summary::build() performs.
			$out = Conflicts::detect(
				array(
					array(
						'ua'       => 'chatgpt-user',
						'name'     => 'ChatGPT-User',
						'operator' => 'OpenAI',
						'requests' => 100,
						'cached'   => 0,
						'origin'   => 50,
						'blocked'  => 50,
					),
				),
				array( 'ai_input' => true, 'ai_train' => true, 'blocked_agents' => array() ),
				7
			);
			$this->assertNotEmpty( $out );
			$conflict = $out[0];
			unset( $conflict['link'] );
			$conflict['url'] = 'https://example.test';
			// Summary::build() also decorates a warn with the spoof check's
			// verdict when one is stored — the same shape, declared or rejected.
			$conflict['checked'] = array( 'at' => 1, 'sampled' => 1, 'verified' => 0, 'spoofed' => 1, 'unknown' => 0 );

			$declared = $schema['properties']['conflicts']['items']['properties'];
			foreach ( array_keys( $conflict ) as $key ) {
				$this->assertArrayHasKey( $key, $declared, "conflicts carry `$key` but the schema does not declare it." );
			}

			// Hidden conflicts are the SAME rows behind a display choice — the
			// schema must declare the identical shape.
			$hidden = $schema['properties']['hiddenConflicts']['items']['properties'];
			foreach ( array_keys( $conflict ) as $key ) {
				$this->assertArrayHasKey( $key, $hidden, "hiddenConflicts carry `$key` but the schema does not declare it." );
			}
		}

		public function test_the_not_connected_payload_fits_the_schema_keys() {
			$schema = $this->registered()['output_schema'];

			// The real producer for the disconnected path — no database involved.
			$view = ( new CloudflareSettings() )->public_view();
			$view['days'] = 7;
			foreach ( array_keys( $view ) as $key ) {
				$this->assertArrayHasKey(
					$key,
					$schema['properties'],
					"the not-connected payload carries `$key` but the schema does not declare it."
				);
			}
		}
	}
}
