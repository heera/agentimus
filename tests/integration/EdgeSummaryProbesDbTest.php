<?php
/**
 * The edge summary totals its scanner probes, so the headline "Blocked by
 * Cloudflare" can say how much of itself is a scanner.
 *
 * ⭐ WHY THIS EXISTS. On heera.it (2026-10-27) the panel showed 1,741 blocked in
 * amber and every crawler's Blocked count in red; 1,431 of them were probes for
 * .env-style files under AI names. The per-crawler rows carried `probes` but the
 * totals did not, so the headline could not tell the owner.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Cloudflare\Settings;
use Agentimus\Cloudflare\Summary;
use Agentimus\Cloudflare\Table;

final class EdgeSummaryProbesDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		Table::install();
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		( new Settings() )->connect( 'cf-token', 'zone1', 'example.org' );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		( new Settings() )->disconnect();
		parent::tear_down();
	}

	public function test_totals_carry_the_probes_inside_blocked() {
		$hour = gmdate( 'Y-m-d H:00:00', time() - HOUR_IN_SECONDS );
		Table::upsert( array(
			array( 'hour_at' => $hour, 'ua' => 'oai-searchbot', 'requests' => 626, 'cached' => 118, 'origin' => 424, 'blocked' => 84, 'probes' => 84, 'served' => 314, 'bytes' => 0 ),
			array( 'hour_at' => $hour, 'ua' => 'claudebot', 'requests' => 313, 'cached' => 0, 'origin' => 64, 'blocked' => 249, 'probes' => 159, 'served' => 0, 'bytes' => 0 ),
		) );

		$totals = Summary::build( new Settings(), new \Agentimus\Settings(), 7 )['totals'];

		$this->assertSame( 333, $totals['blocked'] );
		$this->assertSame( 243, $totals['probes'] );
	}
}
