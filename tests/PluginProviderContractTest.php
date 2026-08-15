<?php
/**
 * The contract every plugin provider keeps — run over the WHOLE roster, so a
 * provider added next month is held to it without anyone remembering to.
 *
 * The Woo provider was written by hand and checked by hand. Seven more like it
 * is seven chances to get the same rule slightly wrong, and the rule that
 * matters is the one nobody sees fail: an authenticated address in a public
 * document sends assistants at a locked door and tells every reader something
 * untrue about the site.
 *
 * So these tests do not care what a provider describes. They care that:
 *
 *   1. an absent plugin says NOTHING — no resource, no post types;
 *   2. every advertised address is a public, read-only GET;
 *   3. ⛔ no address is ever an authenticated management API, whatever the
 *      provider asked for;
 *   4. ids are unique and match the roster;
 *   5. the shape is complete enough for the registry to accept it.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Integrations\Plugins\Provider;
use PHPUnit\Framework\TestCase;

final class PluginProviderContractTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_post_types_exist'] );
	}

	/** Every provider in the roster, as class name => class name. */
	private function roster(): array {
		$out = array();
		foreach ( Provider::ROSTER as $class ) {
			$out[ $class ] = $class;
		}
		return $out;
	}

	public function test_the_roster_is_not_empty() {
		$this->assertNotEmpty( Provider::ROSTER, 'A roster nobody is in describes nothing.' );
	}

	/**
	 * ⭐ The safe state. On a site without the plugin, a provider must say nothing
	 * at all — not an empty resource, not a stray post type. Every provider is
	 * asked here, and none of these plugins is installed in this suite.
	 */
	public function test_an_absent_plugin_is_described_by_silence() {
		foreach ( $this->roster() as $class ) {
			$this->assertFalse( $class::present(), $class . ' must not think it is here.' );
			$this->assertSame( array(), $class::resource(), $class . ' must describe nothing when absent.' );
			$this->assertSame(
				array( 'post' ),
				$class::fold_post_types( array( 'post' ), array( 'post', 'page', 'product', 'fluent-products' ) ),
				$class . ' must not advertise content for a plugin that is not here.'
			);
		}
	}

	public function test_every_provider_has_its_own_id_and_a_card() {
		$seen = array();
		foreach ( $this->roster() as $class ) {
			$card = $class::describe();

			$this->assertNotSame( '', (string) $class::ID, $class . ' needs an id.' );
			$this->assertSame( $class::ID, $card['id'], $class . ': the card and the id must agree.' );
			$this->assertNotSame( '', trim( (string) $card['name'] ), $class . ' needs a name.' );
			$this->assertNotSame( '', trim( (string) $card['blurb'] ), $class . ' needs its one line.' );
			$this->assertArrayHasKey( 'present', $card );

			$this->assertArrayNotHasKey( $class::ID, $seen, 'Two providers share the id ' . $class::ID . '.' );
			$seen[ $class::ID ] = true;
		}
	}

	/**
	 * ⛔ THE RULE. Whatever a provider hands up, what reaches a discovery document
	 * is read-only and needs no login — and an authenticated management API is
	 * never named. Checked against the shape the base class produces, with a
	 * provider that deliberately misbehaves.
	 */
	public function test_the_base_refuses_an_endpoint_that_is_not_public_and_read_only() {
		$resource = TestMisbehavingProvider::resource();

		$urls = array_column( $resource['endpoints'], 'url' );
		$this->assertSame(
			array( '/wp-json/good/v1/things' ),
			$urls,
			'Only the public read-only address survives; the locked one is dropped rather than advertised.'
		);
		foreach ( $resource['endpoints'] as $endpoint ) {
			$this->assertSame( 'none', $endpoint['auth'] );
			$this->assertSame( array( 'GET' ), $endpoint['methods'] );
			$this->assertSame( 'rest', $endpoint['type'] );
		}
	}

	public function test_a_provider_with_no_public_address_registers_nothing() {
		$collector = new class() {
			public $got = array();
			public function register( $resource ) {
				$this->got[] = $resource;
			}
		};

		TestSilentProvider::provide( $collector );

		$this->assertSame( array(), $collector->got, 'Present, but nothing public to say — so it says nothing.' );
	}

	public function test_a_present_provider_folds_in_only_the_types_this_site_really_has() {
		$folded = TestSilentProvider::fold_post_types( array( 'post' ), array( 'post', 'thing' ) );
		$this->assertSame( array( 'post', 'thing' ), $folded, 'The type the site registered joins the list.' );

		$folded = TestSilentProvider::fold_post_types( array( 'post' ), array( 'post' ) );
		$this->assertSame( array( 'post' ), $folded, 'A type this site does not have is never advertised.' );
	}

	public function test_the_registry_accepts_what_a_provider_builds() {
		$normalized = \Agentimus\Discovery\Resource::normalize( TestMisbehavingProvider::resource() );

		$this->assertSame( 'test-misbehaving', $normalized['id'] );
		$this->assertSame( 'commerce', $normalized['type'] );
		$this->assertCount( 1, $normalized['endpoints'] );
	}

	/**
	 * ⚠️ THE ONE THAT BIT ME. FluentCommunity declared type "community", which is
	 * not in the spec's vocabulary, so the registry threw the WHOLE resource away
	 * — correctly, and with a clear message nobody was reading. On a live site it
	 * simply never appeared. A type is one word in one method, which is exactly
	 * the kind of thing a person gets wrong and a test should not let through.
	 */
	public function test_every_provider_declares_a_type_the_registry_will_accept() {
		foreach ( $this->roster() as $class ) {
			$m = new \ReflectionMethod( $class, 'type' );
			\_af_accessible( $m );
			$type = (string) $m->invoke( null );

			$known = in_array( $type, \Agentimus\Discovery\Resource::TYPES, true );
			$this->assertTrue(
				$known || 0 === strpos( $type, 'x-' ),
				$class . ' declares type "' . $type . '", which the registry would refuse. Use a known type or an x-vendor extension.'
			);
		}
	}

	/**
	 * ⛔ A vendor's own voice wins. When a plugin — or an adapter someone wrote
	 * for it — has already advertised an address, we say nothing rather than say
	 * it twice: on his own site both the FluentCart adapter and our provider name
	 * /wp/v2/fluent-products, and a reader seeing one address under two names
	 * cannot tell which to believe.
	 */
	public function test_a_provider_stands_down_when_a_vendor_already_named_the_address() {
		$taken = new class() {
			public $got = array();
			public function register( $resource ) {
				$this->got[] = $resource;
			}
			public function resources() {
				return array(
					'someone-elses' => array(
						'endpoints' => array( array( 'url' => '/wp-json/good/v1/things' ) ),
					),
				);
			}
		};

		TestMisbehavingProvider::provide( $taken );

		$this->assertSame( array(), $taken->got, 'Theirs stands; we add no second voice.' );
	}
}

/** A provider that asks for a locked door alongside a public one. */
final class TestMisbehavingProvider extends Provider {
	const ID = 'test-misbehaving';
	public static function present() {
		return true;
	}
	protected static function name() {
		return 'Misbehaving';
	}
	protected static function blurb() {
		return 'Asks for more than it should.';
	}
	protected static function type() {
		return 'commerce';
	}
	protected static function endpoints() {
		return array(
			array( 'url' => '/wp-json/good/v1/things', 'description' => 'Public.' ),
			array( 'url' => '/wp-json/locked/v3/things', 'auth' => 'basic', 'description' => 'Needs a key.' ),
		);
	}
}

/** Present, with content but no public address — the honest common case. */
final class TestSilentProvider extends Provider {
	const ID = 'test-silent';
	public static function present() {
		return true;
	}
	protected static function name() {
		return 'Silent';
	}
	protected static function blurb() {
		return 'Here, with nothing public to offer.';
	}
	protected static function post_types() {
		return array( 'thing' );
	}
}
