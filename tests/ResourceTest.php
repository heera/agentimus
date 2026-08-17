<?php
/**
 * Resource — the registration validator/normalizer (spec §04).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Resource;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class ResourceTest extends TestCase {

	public function test_valid_resource_normalizes_with_default_auth() {
		$r = Resource::normalize( array( 'id' => 'acme-bookings', 'title' => 'Acme', 'type' => 'scheduling' ) );
		$this->assertIsArray( $r );
		$this->assertSame( 'acme-bookings', $r['id'] );
		$this->assertSame( 'scheduling', $r['type'] );
		$this->assertSame( array( 'type' => 'none', 'oidc' => '', 'scopes' => array(), 'docs' => '' ), $r['auth'] );
	}

	/** @dataProvider missingRequired */
	public function test_missing_required_fields_are_rejected( array $raw ) {
		$this->assertInstanceOf( WP_Error::class, Resource::normalize( $raw ) );
	}

	public function missingRequired(): array {
		return array(
			'no id'    => array( array( 'title' => 'X', 'type' => 'commerce' ) ),
			'no title' => array( array( 'id' => 'a', 'type' => 'commerce' ) ),
			'no type'  => array( array( 'id' => 'a', 'title' => 'A' ) ),
		);
	}

	public function test_invalid_slug_is_rejected() {
		$this->assertInstanceOf( WP_Error::class, Resource::normalize( array( 'id' => 'Bad_ID', 'title' => 'X', 'type' => 'commerce' ) ) );
	}

	public function test_an_x_extension_is_taken_as_written() {
		$ok = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'x-acme-loyalty' ) );
		$this->assertIsArray( $ok );
		$this->assertSame( 'x-acme-loyalty', $ok['type'] );
	}

	/**
	 * ⭐ HIS CALL. A word we don't know costs a plugin its label, never its row.
	 * This is the FluentCommunity case: it said "community", and the whole
	 * resource was thrown away, so on a live site the plugin simply was not in
	 * the document.
	 */
	public function test_an_undeclared_kind_is_marked_rather_than_thrown_away() {
		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'community', 'endpoints' => array( '/wp-json/acme/v1/things' ) ) );

		$this->assertIsArray( $r, 'The resource survives a word we do not know.' );
		$this->assertSame( 'x-community', $r['type'] );
		$this->assertCount( 1, $r['endpoints'], 'Everything else about it is untouched.' );
	}

	public function test_a_marked_kind_is_written_in_the_token_form() {
		$cases = array(
			'community'    => 'x-community',
			'my_kind'      => 'x-my-kind',      // sanitize_key() keeps underscores; the token form does not.
			'Loyalty'      => 'x-loyalty',
			'x-my_kind'    => 'x-my-kind',      // already marked — never x-x-.
		);
		foreach ( $cases as $asked => $expected ) {
			$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => $asked ) );
			$this->assertIsArray( $r, $asked . ' should still produce a resource.' );
			$this->assertSame( $expected, $r['type'], $asked . ' should be published as ' . $expected );
		}
	}

	/** ⛔ There is nothing to mark and nothing to invent, so this one is refused. */
	public function test_a_type_with_no_usable_letters_is_still_refused() {
		$this->assertInstanceOf( WP_Error::class, Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => '---' ) ) );
		$this->assertInstanceOf( WP_Error::class, Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'x-' ) ) );
	}

	/**
	 * ⭐ HIS CALL: a plugin author — and this plugin, later — must be able to add a
	 * kind of their own. A DECLARED kind is published as written; that is what
	 * separates a new kind from a typo.
	 */
	public function test_a_declared_kind_is_published_as_written() {
		\_af_reset_options();
		add_filter(
			'agentimus_resource_types',
			static function ( $types ) {
				$types[] = 'loyalty';
				return $types;
			}
		);

		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'loyalty' ) );
		$this->assertSame( 'loyalty', $r['type'], 'A declared kind is not marked.' );
		$this->assertContains( 'loyalty', Resource::types() );

		\_af_reset_options();
		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'loyalty' ) );
		$this->assertSame( 'x-loyalty', $r['type'], 'Undeclared again, it is marked again.' );
	}

	/**
	 * ⛔ The filter adds; it can never take a built-in kind away. A plugin that
	 * returned its own list instead of merging would otherwise re-mark every other
	 * resource on the site and change what this site publishes.
	 */
	public function test_the_filter_cannot_remove_a_kind_we_ship() {
		\_af_reset_options();
		add_filter( 'agentimus_resource_types', static function () { return array( 'loyalty' ); } );

		$types = Resource::types();
		foreach ( Resource::TYPES as $built_in ) {
			$this->assertContains( $built_in, $types, $built_in . ' must stay known whatever a filter returns.' );
		}
		$this->assertSame( 'commerce', Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'commerce' ) )['type'] );
		\_af_reset_options();
	}

	public function test_a_declared_kind_is_written_the_way_a_type_is_read() {
		\_af_reset_options();
		add_filter(
			'agentimus_resource_types',
			static function ( $types ) {
				$types[] = 'Loyalty Points'; // Their spelling…
				$types[] = '';               // …and two that leave nothing behind.
				$types[] = '---';
				return $types;
			}
		);

		$this->assertSame(
			array_merge( Resource::TYPES, array( 'loyalty-points' ) ),
			Resource::types(),
			'A declared kind is folded to one token; nothing unusable joins the vocabulary.'
		);

		// ⭐ THE POINT OF FOLDING BOTH SIDES: the author writes the word the same
		// way twice and it matches itself, whatever the spacing.
		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'Loyalty Points' ) );
		$this->assertSame( 'loyalty-points', $r['type'], 'Their own declaration matches their own resource.' );
		\_af_reset_options();
	}

	public function test_string_endpoint_is_coerced_to_rest() {
		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'commerce', 'endpoints' => array( '/wp-json/x' ) ) );
		$this->assertCount( 1, $r['endpoints'] );
		$this->assertSame( '/wp-json/x', $r['endpoints'][0]['url'] );
		$this->assertSame( 'rest', $r['endpoints'][0]['type'] );
	}

	public function test_provider_is_auto_attributed_and_overwrites_author_value() {
		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'commerce', 'provider' => array( 'plugin' => 'evil/evil.php' ) ) );
		$this->assertSame( 'agentimus/agentimus.php', $r['provider']['plugin'] );
	}

	public function test_capabilities_are_preserved_as_list() {
		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'commerce', 'capabilities' => array( 'commerce.products.read', 'commerce.cart.write' ) ) );
		$this->assertSame( array( 'commerce.products.read', 'commerce.cart.write' ), $r['capabilities'] );
	}
}
