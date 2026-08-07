<?php
/**
 * The two search abilities must DECLARE every key their callbacks return.
 *
 * Ability output validates with additionalProperties:false, so an undeclared key
 * doesn't get quietly dropped — the tool rejects its own honest response for
 * every MCP client, while the admin screen (same builder, no schema) keeps
 * working and hides the break. 1.30.0 shipped exactly that bug with
 * read-request-log's verifyOn ({@see AbilityOutputSchemaDriftTest}); this file
 * is the same guard for read-search-performance and read-search-opportunities.
 *
 * It runs the REAL producers — Report::performance(), Report::opportunities()
 * and, through a fixture snapshot, the card shaper — rather than mirroring their
 * key lists by hand, so a new key in Report cannot pass by being copied here too.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Search\Opportunities;
	use Agentimus\Search\Performance;
	use Agentimus\Search\Report;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	/**
	 * Each test runs in its OWN process: the capture stub defines a global
	 * wp_register_ability(), and AdapterBootstrapTest's "unit env has no
	 * Abilities API" precondition must keep holding in the main process.
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class SearchAbilitySchemaDriftTest extends TestCase {

		/** Define the capture stub at RUN time (never at file load — see class doc). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		/**
		 * One ability's registered output schema.
		 *
		 * @param string $slug Ability slug without the namespace.
		 * @return array
		 */
		private function output_schema( string $slug ): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey(
				"agentimus/$slug",
				$GLOBALS['_af_abilities'],
				"$slug did not register — the capture stub or the slug moved."
			);
			return $GLOBALS['_af_abilities'][ "agentimus/$slug" ]['output_schema'];
		}

		/**
		 * Assert every key an actual payload carries is declared.
		 *
		 * @param array  $declared Schema properties map.
		 * @param array  $actual   A real payload (or row).
		 * @param string $where    Human name for the failure message.
		 * @return void
		 */
		private function assert_declared( array $declared, array $actual, string $where ): void {
			foreach ( array_keys( $actual ) as $key ) {
				$this->assertArrayHasKey(
					$key,
					$declared,
					"$where returns `$key` but the output schema does not declare it — with additionalProperties:false the ability rejects its own response."
				);
			}
		}

		/** Rows one page's worth of demand, strong enough to survive the engine's floors. */
		private function rows(): array {
			$rows = array();
			// A page ranking 12th on real demand — the "almost there" shape.
			$rows[] = array(
				'query'       => 'agent readiness plugin',
				'page_url'    => 'https://example.test/a/',
				'page_id'     => 0,
				'clicks'      => 2,
				'impressions' => 600,
				'position'    => 12.4,
				'range_start' => '2026-06-01',
				'range_end'   => '2026-07-27',
			);
			// Page-one rows that earn clicks, so the median CTR bar exists.
			for ( $i = 0; $i < 6; $i++ ) {
				$rows[] = array(
					'query'       => "well ranked $i",
					'page_url'    => "https://example.test/p$i/",
					'page_id'     => 0,
					'clicks'      => 15,
					'impressions' => 300,
					'position'    => 3.0,
					'range_start' => '2026-06-01',
					'range_end'   => '2026-07-27',
				);
			}
			return $rows;
		}

		public function test_the_performance_payload_is_fully_declared() {
			$schema = $this->output_schema( 'read-search-performance' );

			$this->assertFalse(
				$schema['additionalProperties'],
				'Top-level output allows undeclared keys — this test expects the strict mode that makes drift fatal.'
			);

			// The real builder, in its no-data state: the top-level shape is the same
			// either way, and this needs no database.
			$this->assert_declared(
				$schema['properties'],
				Report::performance( new Settings(), '' ),
				'Report::performance()'
			);

			// The row shapes come from the pure producer, run on a real snapshot.
			$perf = Performance::build( $this->rows() );
			$this->assert_declared( $schema['properties']['totals']['properties'], $perf['totals'], 'Performance totals' );
			$this->assertNotEmpty( $perf['top_queries'], 'Fixture produced no top queries — the drift check would pass vacuously.' );

			// Through the REAL shapers, not the raw rows: the wire renames keys
			// (is_probe → isProbe), so checking the producer's own output would
			// verify a shape that never reaches a client.
			$wq = new \ReflectionMethod( Report::class, 'wire_query' );
			\_af_accessible( $wq );
			$this->assert_declared(
				$schema['properties']['topQueries']['items']['properties'],
				$wq->invoke( null, $perf['top_queries'][0] ),
				'A top-query row'
			);

			$this->assertNotEmpty( $perf['top_pages'], 'Fixture produced no top pages — the drift check would pass vacuously.' );
			$wp = new \ReflectionMethod( Report::class, 'wire_page' );
			\_af_accessible( $wp );
			$this->assert_declared(
				$schema['properties']['topPages']['items']['properties'],
				$wp->invoke( null, $perf['top_pages'][0] ),
				'A top-page row'
			);
		}

		public function test_the_opportunities_payload_is_fully_declared() {
			$schema = $this->output_schema( 'read-search-opportunities' );

			$this->assertFalse( $schema['additionalProperties'] );

			$payload = Report::opportunities( new Settings(), '' );
			$this->assert_declared( $schema['properties'], $payload, 'Report::opportunities()' );
			$this->assert_declared( $schema['properties']['counts']['properties'], $payload['counts'], 'Opportunity counts' );
		}

		public function test_an_opportunity_card_is_fully_declared() {
			$schema = $this->output_schema( 'read-search-opportunities' );
			$report = Opportunities::build( $this->rows(), array() );
			$this->assertNotEmpty(
				$report['almost_there'],
				'Fixture produced no opportunity — the card drift check would pass vacuously.'
			);

			// wire_card() is pure and private; run the REAL shaper on a REAL card.
			$m = new \ReflectionMethod( Report::class, 'wire_card' );
			\_af_accessible( $m );
			$card = $m->invoke( null, $report['almost_there'][0] );

			$declared = $schema['properties']['almostThere']['items']['properties'];
			$this->assert_declared( $declared, $card, 'An opportunity card' );

			$this->assertNotEmpty( $card['queries'], 'The card carried no searches — the query drift check would pass vacuously.' );
			$this->assert_declared(
				$declared['queries']['items']['properties'],
				$card['queries'][0],
				'A card search row'
			);
		}

		public function test_both_tools_are_exposed_on_the_mcp_server() {
			// Registered is not the same as reachable: an ability the MCP list forgets
			// exists only inside wp-admin.
			$tools = ( new Registrar( new Settings() ) )->mcp_abilities();

			$this->assertContains( 'agentimus/read-search-performance', $tools );
			$this->assertContains( 'agentimus/read-search-opportunities', $tools );
		}
	}
}
