<?php
/**
 * MCP RESOURCES — the documents this site publishes, offered to be read rather
 * than called.
 *
 * The contract that matters is the adapter's: it reads a resource's URI from
 * `meta.mcp.uri` and REFUSES to register one without it. A resource ability
 * missing that key doesn't error loudly — it silently never appears in the
 * client's resource list, which is exactly the kind of failure nobody notices
 * until an agent asks why the site offers nothing to read.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	/**
	 * Own process, for the same reason as the schema-drift tests: this defines a
	 * global wp_register_ability(), and other tests assert the unit environment
	 * has no Abilities API.
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class McpResourcesTest extends TestCase {

		private function ensure_stubs(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
			if ( ! function_exists( 'wp_get_ability' ) ) {
				eval( 'function wp_get_ability( $name ) { return isset( $GLOBALS["_af_abilities"][ $name ] ) ? (object) array( "name" => $name ) : null; }' );
			}
		}

		private function register( array $settings = array() ): Registrar {
			$this->ensure_stubs();
			$GLOBALS['_af_abilities'] = array();
			\_af_reset_options();
			if ( $settings ) {
				update_option( Settings::OPTION, $settings );
			}
			$registrar = new Registrar( new Settings() );
			$registrar->register_abilities();
			return $registrar;
		}

		public function test_every_offered_resource_declares_the_uri_the_adapter_demands() {
			$registrar = $this->register();

			$offered = $registrar->mcp_resources();
			$this->assertNotEmpty( $offered, 'A server with no resources is the state this feature exists to end.' );

			foreach ( $offered as $name ) {
				$meta = $GLOBALS['_af_abilities'][ $name ]['meta']['mcp'];
				$this->assertArrayHasKey( 'uri', $meta, "$name would be dropped by the adapter — no meta.mcp.uri." );
				// RFC 3986 with a scheme: the adapter validates this and refuses
				// anything relative, so a bare "/llms.txt" would vanish silently.
				$this->assertMatchesRegularExpression( '#^https?://#', $meta['uri'], "$name must carry an absolute URI." );
				$this->assertArrayHasKey( 'mimeType', $meta, "$name must say what it is." );
				$this->assertFalse( $meta['public'], 'A resource must not auto-join the default public server either.' );
			}
		}

		public function test_the_documents_offered_are_the_ones_the_site_actually_serves() {
			$registrar = $this->register();
			$names     = $registrar->mcp_resources();

			$this->assertContains( 'agentimus/llms-txt', $names );
			$this->assertContains( 'agentimus/llms-full-txt', $names );
			$this->assertContains( 'agentimus/discovery-json', $names );
			$this->assertContains( 'agentimus/agent-card-json', $names );
		}

		public function test_a_switched_off_endpoint_is_never_offered_as_a_resource() {
			// Advertising a resource whose URL 404s is worse than offering nothing:
			// the agent reads a promise, follows it, and finds the owner turned it
			// off months ago.
			$registrar = $this->register( array( 'enable_llms_txt' => false ) );
			$names     = $registrar->mcp_resources();

			$this->assertNotContains( 'agentimus/llms-txt', $names );
			$this->assertNotContains( 'agentimus/llms-full-txt', $names, 'The full edition is an extension of the index — without the index it has no home.' );
			$this->assertContains( 'agentimus/discovery-json', $names, 'The discovery documents are unaffected by the llms.txt switch.' );

			// The full edition has its own switch too.
			$only_index = $this->register( array( 'enable_llms_txt' => true, 'enable_llms_full' => false ) );
			$this->assertContains( 'agentimus/llms-txt', $only_index->mcp_resources() );
			$this->assertNotContains( 'agentimus/llms-full-txt', $only_index->mcp_resources() );
		}

		public function test_resources_and_tools_are_separate_lists() {
			$registrar = $this->register();

			// A client lists tools and resources separately and treats them
			// differently; the same ability in both would be offered twice with
			// two meanings.
			$this->assertSame(
				array(),
				array_intersect( $registrar->mcp_resources(), $registrar->mcp_abilities() ),
				'No ability may be both a tool and a resource.'
			);
		}

		/**
		 * The handshake must ADMIT to the resources. Registering them is only
		 * half the job: a client reads `capabilities` to decide what to ask
		 * for, so a server that holds four documents and answers "no resources
		 * capability" is never sent resources/list, and those documents are
		 * unreachable however correctly they were registered. Caught on a live
		 * site (heera.it, 1.35.0-dev4), not by any test that existed then — the
		 * trim filter predated resources and was still stripping the capability
		 * it had been written to strip when there genuinely were none.
		 */
		public function test_the_handshake_admits_to_the_resources_it_holds() {
			$registrar = $this->register();
			$this->assertNotEmpty( $registrar->mcp_resources(), 'Precondition: this site offers documents.' );

			$caps = $this->advertised_capabilities( $registrar );

			$this->assertArrayHasKey( 'resources', $caps, 'Resources are registered but not advertised — no client will ask for them.' );
			$this->assertArrayHasKey( 'tools', $caps );
			$this->assertArrayNotHasKey( 'prompts', $caps, 'No prompts are registered, so the capability would be a promise of nothing.' );
		}

		public function test_a_site_offering_no_documents_advertises_no_resources() {
			// The original reason the filter exists: an advertised-then-empty
			// capability reads as broken to clients and scores as a failure to
			// scanners, where a tool-only server scores n/a.
			// Emptied through the documented filter rather than by guessing which
			// combination of switches leaves nothing — a site CAN reach zero
			// (every endpoint off, or this filter), and that is the case the
			// original trim was written for.
			$registrar = $this->register();
			add_filter(
				'agentimus_mcp_server_resources',
				static function () {
					return array();
				}
			);
			$this->assertSame( array(), $registrar->mcp_resources(), 'Precondition: this site offers nothing.' );

			$this->assertArrayNotHasKey( 'resources', $this->advertised_capabilities( $registrar ) );
		}

		/**
		 * Run the initialize DTO through our filter and return the capabilities
		 * it would put on the wire. A stand-in DTO, because the adapter's own is
		 * only loadable with the full adapter present — the filter's contract is
		 * toArray()/fromArray(), and that is what is exercised here.
		 *
		 * @param Registrar $registrar Registrar under test.
		 * @return array
		 */
		private function advertised_capabilities( Registrar $registrar ): array {
			$dto = new class() {
				/** @var array */
				public static $data = array();

				public function toArray(): array {
					return self::$data;
				}

				public static function fromArray( array $data ): self {
					self::$data = $data;
					return new self();
				}
			};
			// What the adapter hands us: all three, unconditionally.
			$dto::$data = array(
				'capabilities' => array(
					'prompts'   => array( 'listChanged' => false ),
					'resources' => array( 'subscribe' => false, 'listChanged' => false ),
					'tools'     => array( 'listChanged' => false ),
				),
			);

			$server = new class() {
				public function get_server_id(): string {
					return 'agentimus';
				}
			};

			$out = $registrar->trim_initialize_capabilities( $dto, $server );
			return $out->toArray()['capabilities'];
		}

		public function test_a_resource_is_read_only_and_needs_no_input() {
			$registrar = $this->register();

			foreach ( $registrar->mcp_resources() as $name ) {
				$args = $GLOBALS['_af_abilities'][ $name ];
				$this->assertTrue( $args['meta']['annotations']['readonly'], "$name must be readonly — a document is read, never run." );
				$this->assertFalse( $args['meta']['annotations']['destructive'] );
				$this->assertSame( array(), $args['input_schema']['properties'], "$name takes no arguments: reading a document is not a query." );

				// Both locations, because the adapter's two readers disagree: the
				// tool path reads meta.annotations with no fallback, the resource
				// path prefers mcp.annotations and fires a deprecation notice when
				// it finds only the old one. Dropping either breaks one of them.
				$this->assertSame(
					$args['meta']['annotations'],
					$args['meta']['mcp']['annotations'],
					"$name must declare annotations in BOTH places while the adapter reads two."
				);
			}
		}
	}
}
