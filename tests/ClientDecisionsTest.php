<?php
/**
 * The client manager's storage layer: decision dates recorded through the one
 * seam every list edit shares (Settings::update), and the review-queue
 * dismissals listed / forgotten by Repository. Backward compatibility is the
 * contract under test — the lists stay plain string arrays, and dismissal
 * entries written before `label` existed must still render.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Activity\Module;
use Agentimus\Activity\Repository;
use PHPUnit\Framework\TestCase;

final class ClientDecisionsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_af_options'] = array();
	}

	/* -- Decision dates (Settings) ----------------------------------------- */

	public function test_adding_a_token_records_its_decision_date() {
		$s = new Settings();
		$s->allow_agent( 'FriendlyBot' );

		$d = Settings::decisions();
		$this->assertArrayHasKey( 'friendlybot', $d['allow'], 'Dates are keyed by lowercased token.' );
		$this->assertGreaterThan( 0, $d['allow']['friendlybot'] );
		$this->assertSame( array(), $d['block'] );
	}

	public function test_removing_a_token_forgets_its_date() {
		$s = new Settings();
		$s->block_agent( 'BadBot' );
		$this->assertArrayHasKey( 'badbot', Settings::decisions()['block'] );

		$s->remove_agent_token( 'BADBOT', 'blocked_agents' );
		$this->assertArrayNotHasKey( 'badbot', Settings::decisions()['block'] );
		$this->assertNotContains( 'BadBot', $s->get( 'blocked_agents', array() ) );
	}

	public function test_remove_agent_token_matches_case_insensitively_and_keeps_others() {
		$s = new Settings();
		$s->allow_agent( 'KeepMe' );
		$s->allow_agent( 'DropMe' );

		$s->remove_agent_token( 'dropme', 'allowed_agents' );
		$list = $s->get( 'allowed_agents', array() );
		$this->assertContains( 'KeepMe', $list );
		$this->assertNotContains( 'DropMe', $list );
	}

	/* -- The manager payload's robots.txt contradiction flag ---------------- */

	public function test_a_trusted_client_on_the_robots_trainer_list_is_flagged() {
		// Trusted at the door AND told "Disallow: /" in robots.txt — maybe
		// deliberate, but the Allowed row must say it. Exact name match,
		// case-insensitive: the robots.txt line is per exact User-agent name.
		$s = new Settings();
		$s->allow_agent( 'bytespider' );
		$s->allow_agent( 'ChatGPT-User' );
		$s->block_agent( 'BadBot' );
		$all                     = $s->stored();
		$all['blocked_trainers'] = array( 'Bytespider' );
		$s->update( $all );

		$out     = ( new Module( new Settings() ) )->clients();
		$allowed = array_column( $out['allowed'], 'robots', 'token' );
		$this->assertTrue( $allowed['bytespider'], 'Same name on the trainer list → flagged, case-insensitively.' );
		$this->assertFalse( $allowed['ChatGPT-User'], 'A name robots.txt never mentions is not flagged.' );
		foreach ( $out['blocked'] as $row ) {
			$this->assertFalse( $row['robots'], 'Blocked rows never carry the flag — the note is about TRUST contradicting robots.txt.' );
		}
	}

	public function test_unblocking_one_token_does_not_disarm_blocking() {
		$s = new Settings();
		$s->block_agent( 'BadBot' ); // block_agent arms the master switch.
		$s->remove_agent_token( 'BadBot', 'blocked_agents' );
		$this->assertTrue( $s->enabled( 'block_agents' ), 'Un-listing one client is not a decision about enforcement.' );
	}

	public function test_lists_written_before_the_dates_option_have_no_date() {
		// A pre-1.21 install: lists exist, the decisions option does not.
		$GLOBALS['_af_options'][ Settings::OPTION ] = array( 'allowed_agents' => array( 'OldBot' ) );
		$d = Settings::decisions();
		$this->assertSame( array(), $d['allow'], 'No invented dates for entries that predate tracking.' );
	}

	/* -- Dismissals (Repository) -------------------------------------------- */

	public function test_dismiss_stores_a_display_label() {
		Repository::dismiss( 'ethicrawl/0.1 (http://ethicrawl.ai/crawler)', 12 );
		$rows = Repository::dismissals();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ethicrawl', $rows[0]['label'] );
		$this->assertSame( 12, $rows[0]['hits'] );
	}

	public function test_legacy_dismissals_without_label_still_list() {
		// Entries written before `label` existed: a token key names itself, a
		// hash key yields '' (the UI shows "Unnamed client"). Nothing breaks.
		$GLOBALS['_af_options']['agentimus_review_dismissed'] = array(
			'tok:semrushbot' => array( 'at' => 1000, 'hits' => 5 ),
			'ua:abc123'      => array( 'at' => 2000, 'hits' => 9 ),
		);
		$rows = Repository::dismissals();
		$this->assertSame( 'ua:abc123', $rows[0]['key'], 'Newest first.' );
		$this->assertSame( '', $rows[0]['label'] );
		$this->assertSame( 'semrushbot', $rows[1]['label'], 'A token key names itself.' );
	}

	public function test_undismiss_forgets_only_the_given_key() {
		Repository::dismiss( 'SomeBot/1.0', 3 );
		Repository::dismiss( 'OtherBot/2.0', 4 );
		$rows = Repository::dismissals();
		$this->assertCount( 2, $rows );

		$this->assertTrue( Repository::undismiss( $rows[0]['key'] ) );
		$this->assertFalse( Repository::undismiss( $rows[0]['key'] ), 'Second removal reports the key as gone.' );
		$this->assertCount( 1, Repository::dismissals() );
	}
}
