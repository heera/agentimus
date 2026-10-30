<?php
/**
 * read-settings hands over an allowlist, and nothing else in the settings array.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\AgentSettings;
use Agentimus\Settings;

final class ReadSettingsDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+); the feature self-gates below that.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function read() {
		$ability = wp_get_ability( 'agentimus/read-settings' );
		$this->assertNotNull( $ability, 'agentimus/read-settings did not register.' );
		$out = $ability->execute( array() );
		$this->assertNotWPError( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		return $out;
	}

	public function test_it_returns_the_values_the_site_is_using() {
		$settings = new Settings();
		$all      = $settings->stored();

		$all['enable_sitemap']       = false;
		$all['topics_max']           = 7;
		$all['tdm_policy_url']       = 'https://example.com/policy';
		$all['exposed_extra_paths']  = array( '/private.zip' );
		$all['identity']['about']    = 'Writes about PHP.';
		$all['identity']['same_as']  = array( 'https://github.com/example' );
		$settings->update( $all );

		$out = $this->read();

		$this->assertFalse( $out['settings']['enable_sitemap'] );
		$this->assertTrue( $out['settings']['enable_markdown'] );
		$this->assertSame( 7, $out['settings']['topics_max'] );
		$this->assertSame( 'https://example.com/policy', $out['settings']['tdm_policy_url'] );
		$this->assertSame( array( '/private.zip' ), $out['settings']['exposed_extra_paths'] );
		$this->assertSame( 'Writes about PHP.', $out['identity']['about'] );
		$this->assertSame( array( 'https://github.com/example' ), $out['identity']['same_as'] );
	}

	/** ⛔ Every one of these is in Settings::all(), and none may leave through this tool. */
	public function test_secrets_switches_and_email_stay_out() {
		$settings = new Settings();
		$all      = $settings->stored();

		$all['integrations']['slack_url']         = 'https://hooks.slack.test/SECRET-SLACK';
		$all['integrations']['discord_url']       = 'https://discord.test/SECRET-DISCORD';
		$all['integrations']['webhook_url']       = 'https://hook.test/SECRET-WEBHOOK';
		$all['identity']['contact_email']         = 'SECRET-EMAIL@example.com';
		$all['digest_recipient']                  = 'SECRET-DIGEST@example.com';
		$settings->update( $all );

		// Forced in through the read filter too, so this holds whatever sanitising
		// the save does to those values.
		add_filter(
			'agentimus_settings',
			static function ( $values ) use ( $all ) {
				$values['integrations']              = $all['integrations'];
				$values['identity']['contact_email'] = $all['identity']['contact_email'];
				$values['digest_recipient']          = $all['digest_recipient'];
				return $values;
			}
		);

		$out  = $this->read();
		$json = wp_json_encode( $out );

		$this->assertStringNotContainsString( 'SECRET', $json );
		foreach ( array( 'integrations', 'oauth_auth_server', 'enable_mcp_server', 'enable_agent_writes', 'agent_writes_publish', 'digest_recipient', 'blocked_agents', 'robots_extra' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $out['settings'], "$key must stay out of read-settings." );
		}
		$this->assertArrayNotHasKey( 'contact_email', $out['identity'] );
	}

	/** A key the store doesn't have would read as false or empty, which is a lie about the site. */
	public function test_every_listed_key_is_a_real_setting() {
		$all = ( new Settings() )->stored();
		foreach ( array_merge( AgentSettings::FLAGS, AgentSettings::NUMBERS, AgentSettings::LISTS, AgentSettings::TEXTS ) as $key ) {
			$this->assertArrayHasKey( $key, $all );
		}
		foreach ( array_merge( AgentSettings::IDENTITY_TEXTS, AgentSettings::IDENTITY_LISTS ) as $key ) {
			$this->assertArrayHasKey( $key, $all['identity'] );
		}
	}

	public function test_only_someone_who_manages_the_site_can_read_them() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertWPError( wp_get_ability( 'agentimus/read-settings' )->execute( array() ) );
	}
}
