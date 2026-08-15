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
		\_af_accessible( $m );
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

	/* -- 4. Two questions, never one ---------------------------------------- */

	/** The vendor's own mark: do they advertise this ability? */
	private function advertised( array $meta ): bool {
		return (bool) $this->call( 'advertised', $this->ability( $meta ) );
	}

	/** What running it takes, from the two facts that decide it. */
	private function auth( bool $advertised, bool $read_only ): string {
		return (string) $this->call( 'auth_for', $advertised, $read_only );
	}

	/**
	 * ⭐ THE RULE (his, 2026-08-15): publication is the VENDOR's call — we never
	 * advertise what they keep back, never hide what they publish. advertised()
	 * answers that and nothing else.
	 */
	public function test_advertising_is_the_vendors_decision_and_only_that() {
		$this->assertTrue( $this->advertised( array( 'mcp' => array( 'public' => true ) ) ) );
		$this->assertFalse( $this->advertised( array( 'mcp' => array( 'public' => false ) ) ) );
		$this->assertFalse( $this->advertised( array() ), 'Unmarked is not advertised — the safe direction.' );
		$this->assertFalse(
			$this->advertised( array( 'annotations' => array( 'readonly' => true ) ) ),
			'Read-only says nothing about whether a vendor wants it listed.'
		);
	}

	/**
	 * ⛔ THE REGRESSION THIS FIXES. A vendor's "public" mark used to become
	 * `auth: none` on its own, so WooCommerce marking product-delete public had
	 * this site publicly advertising five ways to change a shop under the words
	 * "no sign-in needed". A mark meaning "advertise me" is not a promise that
	 * anyone may run it.
	 */
	public function test_an_advertised_tool_that_changes_something_still_needs_a_sign_in() {
		$this->assertSame( 'wp', $this->auth( true, false ) );
	}

	public function test_an_advertised_tool_that_only_reads_is_open_to_anyone() {
		$this->assertSame( 'none', $this->auth( true, true ) );
	}

	public function test_anything_the_vendor_did_not_advertise_needs_a_sign_in() {
		$this->assertSame( 'wp', $this->auth( false, true ), 'Read-only says nothing about WHO may read.' );
		$this->assertSame( 'wp', $this->auth( false, false ) );
	}

	/* -- 5. The owner's veto ------------------------------------------------ */

	/**
	 * Build a namespace's resource the way provide() does, with two abilities the
	 * vendor advertises: one that reads, one that changes something.
	 *
	 * @param bool $hold_back The owner's tick.
	 * @return array
	 */
	private function group( bool $hold_back ): array {
		// Both advertised by their vendor. The read-only one SAYS SO, the way a
		// real plugin does — WooCommerce declares the annotation on its query
		// tools rather than leaving it to be guessed from the name.
		$advertised = array( 'mcp' => array( 'public' => true ), 'show_in_rest' => true );
		$reads      = $advertised + array( 'annotations' => array( 'readonly' => true ) );
		$changes    = $advertised + array( 'annotations' => array( 'readonly' => false ) );
		$items      = array(
			array( 'name' => 'shop/products-query', 'ability' => $this->ability( $reads ) ),
			array( 'name' => 'shop/product-delete', 'ability' => $this->ability( $changes ) ),
		);

		$m = new \ReflectionMethod( AbilitiesApi::class, 'resource_for' );
		\_af_accessible( $m );
		return $m->invoke( new AbilitiesApi(), 'shop', $items, $hold_back );
	}

	/** Which tool names the served document would carry. */
	private function published( array $resource ): array {
		$out = array();
		foreach ( $resource['tools'] as $tool ) {
			if ( ! empty( $tool['public'] ) ) {
				$out[] = $tool['name'];
			}
		}
		return $out;
	}

	public function test_without_the_veto_the_vendors_choice_stands() {
		$resource = $this->group( false );

		$this->assertSame(
			array( 'shop/products-query', 'shop/product-delete' ),
			$this->published( $resource ),
			'The vendor advertised both, so both are published.'
		);
		$this->assertSame( 'basic', $resource['auth']['type'], 'A published tool that changes something means the group needs a sign-in.' );
	}

	/**
	 * ⭐ HIS RULE, the owner's half: publication is the vendor's call, but on
	 * their own site the owner's no outranks a plugin's yes — the same rule
	 * post_types_vetoed already states for content.
	 */
	public function test_the_veto_removes_only_the_jobs_that_change_something() {
		$resource = $this->group( true );

		$this->assertSame(
			array( 'shop/products-query' ),
			$this->published( $resource ),
			'The reading job is untouched; only the changing one leaves.'
		);
		$this->assertSame( 'none', $resource['auth']['type'], 'With nothing changing left, the group is open again — honestly.' );
		$this->assertCount( 2, $resource['tools'], 'The owner still sees both on their own screen; only the served list is trimmed.' );
	}
}
