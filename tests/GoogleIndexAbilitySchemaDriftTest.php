<?php
/**
 * read-google-index must DECLARE every key its callback returns.
 *
 * Ability output validates with additionalProperties:false, so an undeclared
 * key makes the tool reject its own honest response for every MCP client while
 * the admin screen (same builder, no schema) keeps working and hides the
 * break. Same guard as {@see SearchAbilitySchemaDriftTest}, same discipline:
 * it runs the REAL producer — Index::view() — in both its states, never a
 * hand-mirrored key list.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Abilities\Registrar;
use Agentimus\Google\Index;
use Agentimus\Google\Settings as GoogleSettings;
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
final class GoogleIndexAbilitySchemaDriftTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

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
			'agentimus/read-google-index',
			$GLOBALS['_af_abilities'],
			'read-google-index did not register — the capture stub or the slug moved.'
		);
		return $GLOBALS['_af_abilities']['agentimus/read-google-index']['output_schema'];
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

	public function test_the_index_payload_is_fully_declared() {
		$schema = $this->output_schema();

		$this->assertFalse(
			$schema['additionalProperties'],
			'Top-level output allows undeclared keys — this test expects the strict mode that makes drift fatal.'
		);

		// The real producer in its disconnected state — every top-level key is
		// present by design (a disconnected source is empty, never absent).
		$view = Index::view( new GoogleSettings() );
		$this->assert_declared( $schema['properties'], $view, 'Index::view() (disconnected)' );
		$this->assert_declared( $schema['properties']['watched']['properties'], $view['watched'], 'watched' );
		$this->assert_declared( $schema['properties']['counts']['properties'], $view['counts'], 'counts' );
		$this->assert_declared( $schema['properties']['site']['properties'], $view['site'], 'site' );
	}

	public function test_the_row_shape_is_fully_declared() {
		$schema = $this->output_schema();

		// A connected fixture with one stored row carrying every field the
		// sweep can write, so view()'s row mapping runs for real.
		$GLOBALS['_af_options']['agentimus_google'] = array(
			'sa_json'  => 'ciphertext-not-empty',
			'property' => 'sc-domain:example.test',
		);
		$full_row = array(
			'url'              => 'https://example.test/a/',
			'post_id'          => 0,
			'reason'           => 'busy',
			'verdict'          => 'PASS',
			'coverage_state'   => 'Submitted and indexed',
			'robots_state'     => 'ALLOWED',
			'indexing_state'   => 'INDEXING_ALLOWED',
			'fetch_state'      => 'SUCCESSFUL',
			'last_crawl'       => 1753840000,
			'google_canonical' => 'https://example.test/a/',
			'crawled_as'       => 'MOBILE',
			'in_sitemap'       => true,
			'gsc_link'         => 'https://search.google.com/search-console/inspect?x=1',
			'rich_types'       => 'Article',
			'rich_issues'      => 0,
			'error'            => '',
			'inspected_at'     => 1753900000,
		);
		$GLOBALS['_af_options'][ Index::OPTION ] = array(
			'rows'       => array( $full_row ),
			// A site-rotation problem, so the problems row shape runs for real.
			'cov'        => array(
				'https://example.test/gone' => array_merge( $full_row, array(
					'url'     => 'https://example.test/gone/',
					'reason'  => 'site',
					'verdict' => 'NEUTRAL',
				) ),
			),
			'checked_at' => 1753900000,
			'error'      => '',
			'quota'      => false,
		);

		$view = Index::view( new GoogleSettings() );
		$this->assertNotEmpty( $view['rows'], 'Fixture produced no rows — the drift check would pass vacuously.' );
		$this->assert_declared(
			$schema['properties']['rows']['items']['properties'],
			$view['rows'][0],
			'Index::view() row'
		);
		$this->assertNotEmpty( $view['site']['problems'], 'Fixture produced no site problems — the drift check would pass vacuously.' );
		$this->assert_declared(
			$schema['properties']['site']['properties']['problems']['items']['properties'],
			$view['site']['problems'][0],
			'Index::view() site problem row'
		);
	}

	/** The optional paging input swaps site.problems for one state's page —
	 * everything else in the payload, counts included, stays complete. */
	public function test_problems_paging_input_swaps_the_problem_rows() {
		$this->ensure_capture_stub();
		$GLOBALS['_af_abilities'] = array();
		( new Registrar( new Settings() ) )->register_abilities();
		$ability = $GLOBALS['_af_abilities']['agentimus/read-google-index'];

		$GLOBALS['_af_options']['agentimus_google'] = array(
			'sa_json'  => 'ciphertext-not-empty',
			'property' => 'sc-domain:example.test',
		);
		$cov = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$cov[ "https://example.test/p$i" ] = array(
				'url'            => "https://example.test/p$i",
				'post_id'        => $i,
				'verdict'        => 'NEUTRAL',
				'coverage_state' => 'Crawled - currently not indexed',
				'inspected_at'   => $i,
			);
		}
		$GLOBALS['_af_options'][ Index::OPTION ] = array( 'cov' => $cov );

		$paged = call_user_func( $ability['execute_callback'], array( 'problemsState' => 'crawled', 'problemsPage' => 2 ) );
		$this->assertCount( 10, $paged['site']['problems'], '60 crawled rows: page 2 of 50 holds the last 10' );
		$this->assertSame( 'https://example.test/p60', $paged['site']['problems'][9]['url'] );
		$this->assertSame( 60, $paged['site']['problemStates']['crawled'], 'counts stay complete beside the slice' );

		$plain = call_user_func( $ability['execute_callback'], array() );
		$this->assertCount( 50, $plain['site']['problems'], 'without the input, the bounded default shape holds' );
	}
}
