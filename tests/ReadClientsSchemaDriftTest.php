<?php
/**
 * read-clients must DECLARE every key its review rows carry — and carry every
 * key it declares. Same failure class AbilityOutputSchemaDriftTest pins for the
 * request log: an undeclared payload key is rejected/stripped for MCP clients
 * while the admin screen (no schema) masks the break, and a declared-but-never-
 * emitted key documents a field no agent will ever see.
 *
 * Also pins 2026-08-29's queue-parity fix at the contract level: the row schema
 * must NOT declare `blocked` — an already-blocked client is handled and never
 * listed (the owner's bell and popup both exclude it; the ability now does too,
 * so `reviewTotal` matches the count the owner's own screen shows).
 *
 * The emitted keys are READ OUT OF THE SOURCE, never hand-listed here — a
 * mirror only catches the schema falling behind the mirror (see the request-log
 * drift test's history for how that shipped a bug twice).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Abilities\Registrar;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs in its OWN process: the capture stub below defines a global
 * wp_register_ability(), and AdapterBootstrapTest's "unit env has no
 * Abilities API" precondition must keep holding in the main process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ReadClientsSchemaDriftTest extends TestCase {

	/** Define the capture stub at RUN time (never at file load — see class doc). */
	private function ensure_capture_stub(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
		}
	}

	private function output_schema(): array {
		$this->ensure_capture_stub();
		$GLOBALS['_af_abilities'] = array();
		( new Registrar( new Settings() ) )->register_abilities();
		$this->assertArrayHasKey(
			'agentimus/read-clients',
			$GLOBALS['_af_abilities'],
			'read-clients did not register — the capture stub or the slug moved.'
		);
		return $GLOBALS['_af_abilities']['agentimus/read-clients']['output_schema'];
	}

	/**
	 * Every key the callback's `$review[] = array(` literal emits, derived from
	 * the source so a key added there is a key this test demands.
	 */
	private function emitted_row_keys(): array {
		$src = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Registrar.php' );
		$this->assertNotFalse( $src, 'Could not read Registrar.php to derive the review row keys.' );

		$body = strstr( $src, '$review[] = array(' );
		$this->assertNotFalse( $body, 'read-clients no longer builds its rows in a `$review[] = array(` literal.' );
		$end = strpos( $body, "\n\t\t\t\t\t);" );
		$this->assertNotFalse( $end, 'The `$review[]` literal no longer closes at the expected indentation.' );
		$body = substr( $body, 0, $end );

		preg_match_all( "/^\t{6}'([a-zA-Z]+)'\s*=>/m", $body, $m );
		$keys = $m[1];

		// A parse that finds nothing must FAIL, not quietly pass everything.
		$this->assertGreaterThan(
			10,
			count( $keys ),
			'Derived suspiciously few keys from the `$review[]` literal — it was reformatted and this test is no longer reading it.'
		);
		return $keys;
	}

	public function test_emitted_row_keys_and_declared_item_schema_agree_exactly() {
		$schema   = $this->output_schema();
		$declared = array_keys( $schema['properties']['review']['items']['properties'] );
		$emitted  = $this->emitted_row_keys();

		sort( $declared );
		sort( $emitted );
		$this->assertSame(
			$emitted,
			$declared,
			'The review row payload and its declared item schema drifted apart — an undeclared key is invisible to MCP clients, a never-emitted one is a false promise.'
		);
	}

	public function test_a_blocked_row_flag_is_gone_from_the_contract() {
		// Handled clients never appear in the queue any more, so a `blocked` row flag
		// would always be false — worse than absent, it implies blocked rows may show.
		$schema = $this->output_schema();
		$this->assertArrayNotHasKey(
			'blocked',
			$schema['properties']['review']['items']['properties'],
			'The review row schema declares `blocked` again — already-blocked clients are filtered out, so the flag is a dead promise.'
		);
	}

	public function test_the_split_and_ips_fields_are_declared() {
		$schema   = $this->output_schema();
		$declared = $schema['properties']['review']['items']['properties'];
		foreach ( array( 'spoofHits', 'spoofLastSeen', 'verifiedHits', 'ips' ) as $key ) {
			$this->assertArrayHasKey( $key, $declared, "`$key` must be declared, or MCP clients never see the population split / the addresses a CDN block needs." );
		}
		// The ips items carry exactly what FlaggedIps::for_keys() serves per address.
		$ip_props = $declared['ips']['items']['properties'];
		$this->assertSame( array( 'ip', 'hits', 'lastSeen' ), array_keys( $ip_props ) );
	}
}
