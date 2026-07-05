<?php
/**
 * The AI-Visibility results table against real MySQL: the check insert, the run-id
 * lookups, per-run reads, the summarize/dashboard aggregation over stored rows, and
 * retention prune — the Visibility module's whole storage layer, previously untested.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Visibility\Runner;
use Agentimus\Visibility\Settings;
use Agentimus\Visibility\Store;
use Agentimus\Visibility\Table;

final class VisibilityStoreDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
	}

	private function insert( $run_id, array $overrides = array() ) {
		Store::insert(
			array_merge(
				array(
					'run_id'      => $run_id,
					'brand'       => 'Acme',
					'provider'    => 'openai',
					'model'       => 'gpt-4o-mini',
					'prompt'      => 'best acme tools',
					'mentioned'   => true,
					'cited'       => false,
					'position'    => 1,
					'competitors' => array(),
					'answer'      => 'Acme is great.',
					'sources'     => array(),
					'error'       => '',
				),
				$overrides
			)
		);
	}

	private function row_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Table::name() );
	}

	public function test_insert_and_rows_for_run_round_trip() {
		$this->insert( 1000 );
		$this->insert( 1000, array( 'provider' => 'perplexity', 'mentioned' => false, 'error' => 'rate limited' ) );

		$rows = Store::rows_for_run( 1000 );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'openai', $rows[0]['provider'] );
		$this->assertSame( '1', (string) $rows[0]['mentioned'] );
		$this->assertSame( 'rate limited', $rows[1]['error'] );
	}

	public function test_latest_and_recent_run_ids() {
		$this->insert( 100 );
		$this->insert( 200 );
		$this->insert( 200 );

		$this->assertSame( 200, Store::latest_run_id() );
		$this->assertSame( array( 200, 100 ), Store::recent_run_ids( 12 ) );
	}

	public function test_summarize_over_stored_rows() {
		// 3 valid checks (2 mentions, 1 citation) + 1 error → score 2/3 = 67%.
		$this->insert( 1, array( 'mentioned' => true, 'cited' => true ) );
		$this->insert( 1, array( 'mentioned' => true, 'cited' => false ) );
		$this->insert( 1, array( 'mentioned' => false, 'cited' => false ) );
		$this->insert( 1, array( 'mentioned' => true, 'error' => 'boom' ) );

		$s = Store::summarize( Store::rows_for_run( 1 ) );
		$this->assertSame( 3, $s['checks'] );
		$this->assertSame( 2, $s['mentions'] );
		$this->assertSame( 1, $s['citations'] );
		$this->assertSame( 1, $s['errors'] );
		$this->assertSame( 67, $s['visibilityScore'] );
	}

	public function test_dashboard_reflects_the_last_completed_run() {
		update_option( Runner::LAST_RUN_OPTION, 500, false );
		$this->insert( 500, array( 'mentioned' => true ) );
		$this->insert( 500, array( 'mentioned' => false ) );

		$dash = Store::dashboard( new Settings() );
		$this->assertTrue( $dash['hasData'] );
		$this->assertSame( 2, $dash['summary']['checks'] );
		$this->assertSame( 1, $dash['summary']['mentions'] );
		$this->assertSame( 50, $dash['summary']['visibilityScore'] );
	}

	public function test_prune_drops_rows_older_than_retention() {
		global $wpdb;
		$this->insert( 1 ); // fresh (checked_at = now).
		$wpdb->insert( // an old row seeded directly, so checked_at can be in the past.
			Table::name(),
			array(
				'run_id' => 2, 'checked_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ),
				'brand' => 'Old', 'provider' => 'openai', 'model' => 'x', 'prompt_hash' => md5( 'x' ),
				'prompt' => 'x', 'mentioned' => 0, 'cited' => 0, 'position' => 0,
				'competitors' => '[]', 'answer_excerpt' => '', 'sources' => '[]', 'error' => '',
			)
		);

		$this->assertSame( 1, Store::prune( 180 ), 'the 400-day-old row is removed' );
		$this->assertSame( 1, $this->row_count() );
	}

	public function test_clear_empties_the_table() {
		$this->insert( 1 );
		Store::clear();
		$this->assertSame( 0, $this->row_count() );
	}
}
