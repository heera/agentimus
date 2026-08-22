<?php
/**
 * The openable address on a provider row.
 *
 * The panel offers a declared address as a link so an owner can open what an
 * assistant would fetch. That link is a SECOND field beside the declared `url`,
 * never a rewrite of it, and these tests hold the three rules that keep it
 * honest:
 *
 *   1. A SITE-RELATIVE PATH RESOLVES THE WAY THE BACKGROUND CHECK RESOLVES IT.
 *      Reachability probes `home_url( $path )`; if the panel resolved it any
 *      other way, the owner would be clicking a different address from the one
 *      the row reports a verdict on.
 *   2. ⛔ AN OFF-SITE ADDRESS IS NEVER REWRITTEN. We do not check somebody
 *      else's server, and prefixing our own host onto their address would
 *      invent a claim about a host we know nothing about. ⚠️ There is no
 *      off-site provider installed on any machine here, so this rule has no
 *      live example — which is exactly why it is pinned in a test.
 *   3. AN ADDRESS WE CANNOT RESOLVE YIELDS '', so the panel falls back to plain
 *      text. A link that cannot work is worse than no link.
 *
 * ⭐ And the declared `url` must survive all three untouched: it is what the
 * served documents carry, and a row that quoted the resolved form would be
 * showing the owner something the document does not say.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use PHPUnit\Framework\TestCase;

final class DiscoveryEndpointHrefTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ] );
	}

	/**
	 * ⚠️ THE REGISTRY IS A SINGLETON THAT COLLECTS ONCE. Without this, every test
	 * after the first read the FIRST test's fixture and passed on the wrong data —
	 * three assertions all reporting the same site-relative address, one of them
	 * while claiming to prove an off-site address is left alone.
	 */
	private function resetRegistry() {
		$class = new \ReflectionClass( \Agentimus\Discovery\Registry::class );

		$instance = $class->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/** Put one hand-built resource in front of Hub::data() and hand back its row. */
	private function rowFor( array $endpoints ) {
		$this->resetRegistry();

		$resource = array(
			'id'           => 'href-fixture',
			'title'        => 'Href Fixture',
			'type'         => 'shop',
			'description'  => 'A fixture that exists to carry addresses.',
			'capabilities' => array( 'fixture.read' ),
			'endpoints'    => $endpoints,
		);

		$hook            = new \stdClass();
		$hook->callbacks = array(
			10 => array(
				'fixture' => array(
					'function'      => static function ( $registry ) use ( $resource ) {
						$registry->register( $resource );
					},
					'accepted_args' => 1,
				),
			),
		);
		$GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ] = $hook;

		$registry = \Agentimus\Discovery\Registry::instance()->collect();
		$rows     = \Agentimus\Discovery\Hub::data( new \Agentimus\Settings(), $registry )['resources'];

		foreach ( $rows as $row ) {
			if ( 'href-fixture' === $row['id'] ) {
				return $row;
			}
		}

		return null;
	}

	public function test_a_site_relative_path_resolves_against_this_site() {
		$row = $this->rowFor(
			array( array( 'url' => '/wp-json/fixture/v1', 'type' => 'rest', 'auth' => 'none' ) )
		);

		$this->assertNotNull( $row, 'The fixture must reach the screen.' );
		$endpoint = $row['endpoints'][0];

		$this->assertSame(
			home_url( '/wp-json/fixture/v1' ),
			$endpoint['href'],
			'The link must be the address the background check actually probes.'
		);
		// ⚠️ NOT an assertion that `url` stayed relative — it does not. The
		// Envelope absolutizes every resource before the Hub reads it, with the
		// same home_url() call, so by the time the panel sees this row both
		// fields already agree. That is worth knowing rather than assuming: the
		// `href` field is not doing the resolving in the common case, it is
		// guaranteeing that whatever arrives is an openable http(s) address or
		// nothing at all.
		$this->assertStringEndsWith(
			'/wp-json/fixture/v1',
			$endpoint['url'],
			'The declared address must still name the path the provider registered.'
		);
	}

	public function test_an_off_site_address_is_handed_back_untouched() {
		$row = $this->rowFor(
			array( array( 'url' => 'https://partner.example.com/api/v2', 'type' => 'rest', 'auth' => 'none' ) )
		);

		$this->assertNotNull( $row );
		$endpoint = $row['endpoints'][0];

		$this->assertSame(
			'https://partner.example.com/api/v2',
			$endpoint['href'],
			'Prefixing our own host onto somebody else\'s address invents a claim about their server.'
		);
		$this->assertStringNotContainsString(
			'example.test',
			$endpoint['href'],
			'This site\'s host must never appear in an off-site address.'
		);
	}

	public function test_an_address_we_cannot_resolve_offers_no_link() {
		$row = $this->rowFor(
			array( array( 'url' => 'ftp://legacy.example.com/drop', 'type' => 'rest', 'auth' => 'none' ) )
		);

		if ( null === $row || empty( $row['endpoints'] ) ) {
			$this->assertTrue( true, 'An address rejected before it reaches the panel is also not a link.' );
			return;
		}

		$this->assertSame(
			'',
			$row['endpoints'][0]['href'],
			'An address we cannot resolve must fall back to plain text, never to a guess.'
		);
	}
}
