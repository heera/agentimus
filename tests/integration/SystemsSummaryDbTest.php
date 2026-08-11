<?php
/**
 * The Dashboard systems summary: shape, honesty rules, and the cost contract.
 *
 * Locks three promises of the "What your site runs" card's payload:
 *   1. The summary always answers with the full four-group shape, every key
 *      present, on a bare site — a fresh install renders statuses, not holes.
 *   2. security.txt reports its HONEST state: the toggle alone is not
 *      "served" — switched on with no contact, RFC 9116 makes the file
 *      invalid, and the summary must say 'needs-contact', never 'served'.
 *   3. The agent-access numbers come from the rollup's own semantics:
 *      distinct ability-running creds in the window, and all-time run hits —
 *      never an invented per-window hit count the rollup cannot supply.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\AgentAccess\Events;
use Agentimus\AgentAccess\Store;
use Agentimus\AgentAccess\Table;
use Agentimus\Settings;
use Agentimus\Systems;

require_once __DIR__ . '/DbTestCase.php';

final class SystemsSummaryDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() ); // phpcs:ignore WordPress.DB -- test isolation on our own table.
	}

	public function test_summary_answers_the_full_shape_on_a_bare_site() {
		$summary = Systems::summary( new Settings(), array() );

		$this->assertSame( array( 'doors', 'signals', 'search', 'content' ), array_keys( $summary ) );
		foreach ( array( 'mcpOn', 'writesOn', 'tools', 'agents30', 'runsTotal', 'webmcpOn', 'webmcpTools', 'securityTxt' ) as $key ) {
			$this->assertArrayHasKey( $key, $summary['doors'], "doors.$key" );
		}
		foreach ( array( 'indexnowOn', 'indexnowLast', 'sitemapCovered', 'sitemapLabel', 'robotsManaged', 'robotsAi', 'digestOn', 'digestNext', 'cloudflare' ) as $key ) {
			$this->assertArrayHasKey( $key, $summary['signals'], "signals.$key" );
		}
		foreach ( array( 'googleOn', 'bingOn', 'watchTotal', 'watchIndexed', 'checkedAt', 'unknownHosts' ) as $key ) {
			$this->assertArrayHasKey( $key, $summary['search'], "search.$key" );
		}
		$this->assertArrayHasKey( 'focusWith', $summary['content'] );

		// A bare site's honest zeros — not nulls, not missing keys.
		$this->assertSame( 0, $summary['doors']['agents30'] );
		$this->assertSame( 0, $summary['doors']['runsTotal'] );
		$this->assertFalse( $summary['search']['googleOn'] );
		$this->assertNull( $summary['signals']['cloudflare'], 'Never-configured Cloudflare is not a signal this site has.' );
		$this->assertNull( $summary['search']['unknownHosts'], 'The diagnostic is off by default; off is null, not zero.' );
	}

	public function test_security_txt_toggle_without_a_contact_is_not_served() {
		$settings = new Settings();

		update_option( Settings::OPTION, array( 'enable_security_txt' => 1 ) );
		$this->assertSame( 'needs-contact', Systems::security_txt_state( new Settings() ), 'On with no contact serves nothing (RFC 9116) — saying "served" would be the quiet lie this card exists to end.' );

		update_option(
			Settings::OPTION,
			array(
				'enable_security_txt' => 1,
				'identity'            => array( 'contact_email' => 'owner@example.test' ),
			)
		);
		$this->assertSame( 'served', Systems::security_txt_state( new Settings() ) );

		update_option( Settings::OPTION, array( 'enable_security_txt' => 0 ) );
		$this->assertSame( 'off', Systems::security_txt_state( new Settings() ) );
	}

	public function test_agent_access_counts_follow_the_rollup_semantics() {
		global $wpdb;
		$table = Table::name();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$old   = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS );

		// Two creds ran abilities recently (one of them twice-rolled), one cred
		// ran long ago, and an app-password event must not count as a run.
		$rows = array(
			array( Events::KIND_ABILITY_USED, 'cred-a', 'agentimus/read-readiness', 5, $now ),
			array( Events::KIND_ABILITY_USED, 'cred-a', 'agentimus/check-page', 2, $now ),
			array( Events::KIND_ABILITY_USED, 'cred-b', 'agentimus/read-readiness', 1, $now ),
			array( Events::KIND_ABILITY_USED, 'cred-old', 'agentimus/read-readiness', 7, $old ),
			array( Events::KIND_APPPW_USED, 'cred-a', 'application', 9, $now ),
		);
		foreach ( $rows as $i => $r ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB -- seeding our own table.
				$table,
				array(
					'kind'     => $r[0],
					'user_id'  => 1,
					'cred'     => $r[1],
					'subject'  => $r[2],
					'detail'   => '',
					'hits'     => $r[3],
					'first_at' => $r[4],
					'last_at'  => $r[4],
					'seen'     => 1,
				)
			);
		}

		$this->assertSame( 2, Store::agents_active_since( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), 'Distinct creds with a recent ability run — the old cred and the app-password event stay out.' );
		$this->assertSame( 15, Store::ability_runs_total(), 'All ability hits, all time (5+2+1+7); app-password hits never count as runs.' );
	}
}
