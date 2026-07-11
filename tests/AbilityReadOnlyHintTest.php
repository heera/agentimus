<?php
/**
 * AbilitiesApi readOnlyHint resolution. The hint is derived from the strongest
 * signal — declared annotation, then resource-type, then a GUARDED name heuristic
 * — never from a bare name verb (which can mark a mutating "get-…" as safe).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Adapters\AbilitiesApi;
use PHPUnit\Framework\TestCase;

final class AbilityReadOnlyHintTest extends TestCase {

	/** Reflection-call a private static method on AbilitiesApi. */
	private function call( string $method, ...$args ) {
		$m = new \ReflectionMethod( AbilitiesApi::class, $method );
		$m->setAccessible( true );
		return $m->invoke( null, ...$args );
	}

	/** A stand-in ability exposing get_meta(), like WP_Ability. */
	private function ability( array $meta ) {
		return new class( $meta ) {
			private $m;
			public function __construct( array $m ) {
				$this->m = $m;
			}
			public function get_meta(): array {
				return $this->m;
			}
		};
	}

	private function hint( array $meta, string $name ): bool {
		return (bool) $this->call( 'read_only_hint', $this->ability( $meta ), $name );
	}

	/* -- 1. Declared annotation wins ------------------------------------- */

	public function test_declared_true_wins_over_mutation_name() {
		$this->assertTrue( $this->hint( array( 'annotations' => array( 'readonly' => true ) ), 'ns/delete-thing' ) );
	}

	public function test_declared_false_wins_over_read_verb() {
		// A "get-" name must NOT override an explicit readonly=false.
		$this->assertFalse( $this->hint( array( 'annotations' => array( 'readonly' => false ) ), 'ns/get-orders' ) );
	}

	public function test_null_annotation_is_treated_as_undeclared() {
		// null (the Abilities API default) ⇒ fall through to the name heuristic.
		$this->assertTrue( $this->hint( array( 'annotations' => array( 'readonly' => null ) ), 'ns/get-orders' ) );
		$this->assertFalse( $this->hint( array( 'annotations' => array( 'readonly' => null ) ), 'ns/contribution-guide' ) );
	}

	/* -- 2. Resource type ⇒ read-only by definition --------------------- */

	public function test_resource_is_read_only_even_without_a_read_verb() {
		$meta = array( 'uri' => 'mcp://x/guide', 'mimeType' => 'text/markdown' );
		$this->assertTrue( $this->hint( $meta, 'ns/contribution-guide' ) );
	}

	public function test_resource_uri_alone_suffices() {
		$this->assertTrue( $this->hint( array( 'uri' => 'mcp://x/y' ), 'ns/anything' ) );
	}

	/* -- 3. Guarded name heuristic -------------------------------------- */

	public function test_read_verb_without_mutation_is_read_only() {
		$this->assertTrue( $this->call( 'looks_read_only', 'ns/get-orders' ) );
		$this->assertTrue( $this->call( 'looks_read_only', 'ns/list-products' ) );
	}

	public function test_read_verb_with_mutation_token_is_not_read_only() {
		$this->assertFalse( $this->call( 'looks_read_only', 'ns/get-and-delete' ) );
		$this->assertFalse( $this->call( 'looks_read_only', 'ns/get-or-create-token' ) );
	}

	public function test_no_read_verb_is_not_read_only() {
		$this->assertFalse( $this->call( 'looks_read_only', 'ns/contribution-guide' ) );
		$this->assertFalse( $this->call( 'looks_read_only', 'ns/process-payment' ) );
	}

	public function test_mutation_substrings_are_not_falsely_flagged() {
		// "settings"/"output" embed set/put but are not mutation tokens.
		$this->assertTrue( $this->call( 'looks_read_only', 'ns/get-settings' ) );
		$this->assertTrue( $this->call( 'looks_read_only', 'ns/get-output' ) );
	}

	/* -- 4. auth_for(): the discovery document must not lie about auth ----- */

	private function auth( array $meta ): string {
		return (string) $this->call( 'auth_for', $this->ability( $meta ) );
	}

	/**
	 * THE REGRESSION. auth_for() used to be
	 *   (bool) read( $ability, 'get_permission_callback' ) ? 'wp' : 'none'
	 * and it returned 'none' for EVERY ability — core's WP_Ability exposes no
	 * get_permission_callback(), so read()'s method_exists() check always failed, returned '', and
	 * cast to false. The public /.well-known/discovery.json therefore told every agent on the
	 * internet that manage_options-gated tools needed no authentication.
	 *
	 * This ability object deliberately has NO get_permission_callback(), exactly like core's — the
	 * old code returns 'none' here and the test fails.
	 */
	public function test_an_ability_that_cannot_be_introspected_is_reported_as_gated() {
		$this->assertSame( 'wp', $this->auth( array() ), 'With nothing to introspect, the safe answer is "authentication required".' );
	}

	public function test_our_own_abilities_are_reported_as_gated() {
		// What Abilities\Registrar actually sets: mcp.public = false, precisely to keep these off a
		// public surface. The discovery document must agree with the Registrar, not contradict it.
		$meta = array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => true ),
			'mcp'          => array( 'public' => false ),
		);
		$this->assertSame( 'wp', $this->auth( $meta ) );
	}

	public function test_an_ability_that_declares_itself_public_is_reported_as_open() {
		// The one honest way out: an ability says so explicitly. Under-claiming costs an agent one
		// unnecessary auth header; over-claiming walks it into a 401 and misinforms every reader.
		$this->assertSame( 'none', $this->auth( array( 'mcp' => array( 'public' => true ) ) ) );
	}

	public function test_readonly_does_not_imply_public() {
		// Read-only says nothing about WHO may read. All nine of ours are read-only AND admin-only.
		$this->assertSame( 'wp', $this->auth( array( 'annotations' => array( 'readonly' => true ) ) ) );
	}
}
