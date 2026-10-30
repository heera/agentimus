<?php
/**
 * update-settings reaches its allowlist and nothing else, and only once the owner
 * turned its own switch on.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\AgentSettings;
use Agentimus\Abilities\Registrar;
use Agentimus\AgentAccess\Store;
use Agentimus\AgentAccess\Table;
use Agentimus\Settings;

final class UpdateSettingsDbTest extends DbTestCase {

	/** @var Settings */
	private $settings;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings = new Settings();
		$this->switches( true );
	}

	private function switches( $settings_on ) {
		$all                          = $this->settings->stored();
		$all['enable_mcp_server']     = true;
		$all['enable_agent_writes']   = true;
		$all['agent_writes_settings'] = $settings_on;
		$this->settings->update( $all );
	}

	private function update( array $input ) {
		return ( new AgentSettings( $this->settings ) )->update( $input );
	}

	public function test_it_is_off_until_the_owner_turns_it_on() {
		$this->assertFalse( $this->settings->defaults()['agent_writes_settings'] );

		$this->switches( false );
		$before = $this->settings->stored();
		$result = $this->update( array( 'settings' => array( 'enable_sitemap' => false ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_settings_writes_off', $result->get_error_code() );
		$this->assertSame( $before, $this->settings->stored() );
		$this->assertNotContains( 'agentimus/update-settings', ( new Registrar( $this->settings ) )->mcp_abilities() );

		$this->switches( true );
		$this->assertContains( 'agentimus/update-settings', ( new Registrar( $this->settings ) )->mcp_abilities() );
	}

	public function test_turning_writes_off_turns_it_off() {
		$all                        = $this->settings->stored();
		$all['enable_agent_writes'] = false;
		$this->settings->update( $all );
		$this->assertFalse( $this->settings->stored()['agent_writes_settings'] );
	}

	public function test_a_read_only_grant_treats_it_as_a_write_tool() {
		$this->assertTrue( Registrar::is_write_tool_name( 'agentimus/update-settings' ) );
		$this->assertTrue( Registrar::is_write_tool_name( 'agentimus-update-settings' ) );
	}

	/** ⛔ Settings::update() replaces the option: everything else must come through whole. */
	public function test_it_changes_what_was_sent_and_nothing_else() {
		$all                                = $this->settings->stored();
		$all['integrations']['slack_url']   = 'https://hooks.slack.com/services/T0/B0/keepme';
		$all['blocked_agents']              = array( 'BadBot' );
		$all['identity']['contact_email']   = 'owner@example.com';
		$all['identity']['about']           = 'Keeps this.';
		$this->settings->update( $all );
		$before = $this->settings->stored();

		$result = $this->update(
			array(
				'settings' => array( 'enable_sitemap' => false ),
				'identity' => array( 'role' => 'Engineer' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				array( 'key' => 'enable_sitemap', 'before' => true, 'after' => false ),
				array( 'key' => 'identity.role', 'before' => '', 'after' => 'Engineer' ),
			),
			$result['changed']
		);

		$expected                     = $before;
		$expected['enable_sitemap']   = false;
		$expected['identity']['role'] = 'Engineer';
		$this->assertSame( $expected, $this->settings->stored() );
	}

	/** @return array<string,array{array}> */
	public function refused_keys() {
		return array(
			'its own switch'  => array( array( 'settings' => array( 'agent_writes_settings' => false ) ) ),
			'writes'          => array( array( 'settings' => array( 'enable_agent_writes' => false ) ) ),
			'publish'         => array( array( 'settings' => array( 'agent_writes_publish' => true ) ) ),
			'mcp'             => array( array( 'settings' => array( 'enable_mcp_server' => false ) ) ),
			'connections'     => array( array( 'settings' => array( 'integrations' => array( 'slack_url' => 'https://evil.test' ) ) ) ),
			'oauth server'    => array( array( 'settings' => array( 'oauth_auth_server' => 'https://evil.test' ) ) ),
			'email'           => array( array( 'settings' => array( 'digest_recipient' => 'evil@example.com' ) ) ),
			'make public'     => array( array( 'settings' => array( 'make_public' => true ) ) ),
			'blocking'        => array( array( 'settings' => array( 'blocked_agents' => array() ) ) ),
			'robots'          => array( array( 'settings' => array( 'robots_extra' => 'Allow: /' ) ) ),
			'logs'            => array( array( 'settings' => array( 'enable_activity' => false ) ) ),
			'contact email'   => array( array( 'identity' => array( 'contact_email' => 'evil@example.com' ) ) ),
			'mixed with good' => array( array( 'settings' => array( 'enable_sitemap' => false, 'enable_mcp_server' => false ) ) ),
			'a good key in the wrong group' => array( array( 'identity' => array( 'enable_sitemap' => false ) ) ),
		);
	}

	/** @dataProvider refused_keys */
	public function test_anything_off_the_list_refuses_the_whole_call( array $input ) {
		$before = $this->settings->stored();
		$result = $this->update( $input );
		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_setting_not_allowed', $result->get_error_code() );
		$this->assertSame( $before, $this->settings->stored() );
	}

	public function test_a_wrong_type_is_refused() {
		$before = $this->settings->stored();
		$result = $this->update( array( 'settings' => array( 'enable_sitemap' => 'no' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_setting_bad_type', $result->get_error_code() );
		$this->assertSame( $before, $this->settings->stored() );
	}

	public function test_a_value_the_save_cleans_is_reported_as_adjusted() {
		$result = $this->update( array( 'settings' => array( 'tdm_policy_url' => 'javascript:alert(1)' ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'tdm_policy_url' ), $result['adjusted'] );
		$this->assertStringNotContainsString( 'javascript', $this->settings->stored()['tdm_policy_url'] );
	}

	public function test_sending_the_current_value_writes_nothing_and_costs_nothing() {
		for ( $i = 0; $i < AgentSettings::HOURLY_LIMIT + 2; $i++ ) {
			$result = $this->update( array( 'settings' => array( 'enable_sitemap' => true ) ) );
			$this->assertIsArray( $result );
			$this->assertSame( array(), $result['changed'] );
		}
		$this->assertIsArray( $this->update( array( 'settings' => array( 'enable_sitemap' => false ) ) ) );
	}

	public function test_changes_are_limited_per_connection() {
		for ( $i = 0; $i < AgentSettings::HOURLY_LIMIT; $i++ ) {
			$this->assertIsArray( $this->update( array( 'settings' => array( 'topics_max' => 20 + $i % 2 + ( $i % 2 ? 0 : 2 ) ) ) ) );
		}
		$before = $this->settings->stored();
		$result = $this->update( array( 'settings' => array( 'topics_max' => 5 ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_settings_rate_limited', $result->get_error_code() );
		$this->assertSame( $before, $this->settings->stored() );
	}

	/**
	 * ⭐ Found live on heera.it: 12 → 11 → 12 left one row reading "12 → 11" with two
	 * hits, on a setting then back at 12. The screen shows the subject and not the
	 * detail, and a repeat keeps the first row's text, so each change is its own row.
	 */
	public function test_the_owner_can_read_every_change_in_agent_access() {
		Table::install();
		$this->update( array( 'settings' => array( 'enable_sitemap' => false ) ) );
		$this->update( array( 'settings' => array( 'enable_sitemap' => true ) ) );
		$this->update( array( 'settings' => array( 'enable_sitemap' => false ) ) );

		$rows = array();
		foreach ( Store::recent( 50, 'setting_changed' ) as $row ) {
			$row                     = (array) $row;
			$rows[ $row['subject'] ] = (int) $row['hits'];
		}

		$this->assertSame(
			array(
				'enable_sitemap: true → false' => 2,
				'enable_sitemap: false → true' => 1,
			),
			array(
				'enable_sitemap: true → false' => $rows['enable_sitemap: true → false'] ?? 0,
				'enable_sitemap: false → true' => $rows['enable_sitemap: false → true'] ?? 0,
			)
		);
	}

	public function test_a_long_value_is_cut_in_the_log_and_stays_a_row_of_its_own() {
		Table::install();
		$long = str_repeat( 'A long profile sentence. ', 12 );
		$this->update( array( 'identity' => array( 'about' => $long . 'one' ) ) );
		$this->update( array( 'identity' => array( 'about' => 'Short.' ) ) );

		$subjects = array();
		foreach ( Store::recent( 50, 'setting_changed' ) as $row ) {
			$subjects[] = ( (array) $row )['subject'];
		}
		$this->assertCount( 2, $subjects );
		foreach ( $subjects as $subject ) {
			$this->assertStringStartsWith( 'identity.about: ', $subject );
			$this->assertLessThanOrEqual( 100, mb_strlen( $subject ), 'The table keeps a row per the first 100 characters.' );
		}
	}
}
