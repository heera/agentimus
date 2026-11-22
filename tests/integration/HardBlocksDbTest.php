<?php
/**
 * ⛔ What an agent can never do while it works through the Agentimus MCP address,
 * even signed in as an administrator.
 *
 * An agent call is any request on /agentimus/v1/mcp, however it signed in. Tools
 * other plugins add to our server run inside it, so these hold for them too.
 *
 * The test drives the two REST hooks a real dispatch fires around the route's
 * callbacks, and asks WordPress itself (current_user_can, update_option, the
 * REST server) what is allowed in between. It names no Agentimus class, so on code
 * without the blocks every assertion fails rather than erroring.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class HardBlocksDbTest extends DbTestCase {

	/** @var int */
	private $admin;

	/** @var int */
	private $other_admin;

	/** @var string */
	private $self_file;

	public function set_up(): void {
		parent::set_up();
		$this->admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->self_file   = plugin_basename( AGENTIMUS_FILE );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
	}

	public function tear_down(): void {
		// A failed assertion mid-call must not leave the blocks on for the next test.
		while ( $this->in_call > 0 ) {
			$this->end_call();
		}
		parent::tear_down();
	}

	/** @var int */
	private $in_call = 0;

	private function start_call( $route = '/agentimus/v1/mcp' ) {
		$request = new \WP_REST_Request( 'POST', $route );
		apply_filters( 'rest_request_before_callbacks', null, array(), $request );
		++$this->in_call;
		return $request;
	}

	private function end_call( $route = '/agentimus/v1/mcp' ) {
		$request = new \WP_REST_Request( 'POST', $route );
		apply_filters( 'rest_request_after_callbacks', null, array(), $request );
		--$this->in_call;
	}

	public function test_an_agent_call_loses_the_powers_no_agent_may_use() {
		$this->start_call();
		foreach ( array( 'edit_plugins', 'edit_themes', 'edit_files', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'update_core', 'unfiltered_upload', 'edit_theme_options', 'import', 'export', 'upload_plugins', 'upload_themes', 'manage_network', 'manage_network_options', 'create_sites' ) as $power ) {
			$this->assertFalse( current_user_can( $power ), "An agent call must not have {$power}." );
		}
	}

	public function test_an_agent_call_keeps_what_its_tools_and_his_ruling_allow() {
		$this->start_call();
		$allowed = array( 'manage_options', 'edit_posts', 'publish_posts', 'delete_posts', 'upload_files', 'manage_categories', 'moderate_comments', 'list_users', 'unfiltered_html', 'install_plugins', 'activate_plugins', 'update_plugins', 'delete_plugins', 'install_themes', 'switch_themes', 'update_themes', 'delete_themes' );
		if ( is_multisite() ) {
			// Multisite keeps file-changing powers for the network; that is core's rule, not ours.
			$allowed = array( 'manage_options', 'edit_posts', 'publish_posts', 'upload_files', 'list_users' );
		}
		foreach ( $allowed as $power ) {
			$this->assertTrue( current_user_can( $power ), "An agent call must keep {$power}." );
		}
	}

	public function test_no_user_can_be_edited_and_no_login_password_made_for_anyone() {
		$this->start_call();
		foreach ( array( $this->other_admin, $this->admin ) as $user ) {
			foreach ( array( 'edit_user', 'delete_user', 'promote_user', 'remove_user', 'create_app_password', 'list_app_passwords', 'read_app_password', 'edit_app_password', 'delete_app_password', 'delete_app_passwords', 'edit_user_meta' ) as $check ) {
				$this->assertFalse( current_user_can( $check, $user ), "An agent call must not pass {$check} on user {$user}." );
			}
		}
	}

	public function test_the_rest_api_refuses_an_application_password_for_another_admin() {
		if ( ! class_exists( 'WP_REST_Application_Passwords_Controller' ) ) {
			$this->markTestSkipped( 'Application passwords need WordPress 5.6.' );
		}
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$this->start_call();
		$request = new \WP_REST_Request( 'POST', '/wp/v2/users/' . $this->other_admin . '/application-passwords' );
		$request->set_body_params( array( 'name' => 'agent-made' ) );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ), 'Minting a login password for another admin must be refused.' );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $this->other_admin ) );
	}

	public function test_another_admins_email_cannot_be_changed_through_rest() {
		$this->start_call();
		$before  = get_userdata( $this->other_admin )->user_email;
		$request = new \WP_REST_Request( 'POST', '/wp/v2/users/' . $this->other_admin );
		$request->set_body_params( array( 'email' => 'taken@example.org' ) );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		clean_user_cache( $this->other_admin );
		$this->assertSame( $before, get_userdata( $this->other_admin )->user_email );
	}

	public function test_protected_options_keep_their_value_through_update_and_delete() {
		global $wpdb;
		$roles_option = $wpdb->get_blog_prefix() . 'user_roles';
		$names        = array( 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'auth_key', 'nonce_salt', $roles_option );
		update_option( 'auth_key', 'k-before' );
		update_option( 'nonce_salt', 's-before' );
		$before = array();
		foreach ( $names as $name ) {
			$before[ $name ] = get_option( $name );
		}

		$this->start_call();
		foreach ( $names as $name ) {
			update_option( $name, $roles_option === $name ? array() : 'changed-by-agent' );
			$this->assertSame( $before[ $name ], get_option( $name ), "{$name} must keep its value when an agent updates it." );
			delete_option( $name );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( $name, 'options' );
			$this->assertSame( $before[ $name ], get_option( $name ), "{$name} must come back when an agent deletes it." );
		}
	}

	public function test_agentimus_cannot_be_turned_off_but_other_plugins_can() {
		update_option( 'active_plugins', array( $this->self_file, 'hello.php' ) );
		$this->start_call();

		$this->assertFalse( current_user_can( 'deactivate_plugin', $this->self_file ), 'Agentimus must not be turned off by an agent.' );
		if ( ! is_multisite() ) {
			$this->assertTrue( current_user_can( 'deactivate_plugin', 'hello.php' ), 'Other plugins can be turned off.' );
		}

		update_option( 'active_plugins', array() );
		$this->assertSame( array( $this->self_file ), array_values( (array) get_option( 'active_plugins' ) ), 'A direct write keeps Agentimus on and lets the other plugin go.' );
	}

	public function test_deactivating_or_uninstalling_agentimus_stops_before_its_hooks_run() {
		$this->start_call();
		foreach ( array( 'deactivate_plugin', 'pre_uninstall_plugin', 'delete_plugin' ) as $hook ) {
			$stopped = false;
			try {
				do_action( $hook, $this->self_file, false );
			} catch ( \WPDieException $e ) {
				$stopped = true;
			}
			$this->assertTrue( $stopped, "{$hook} on Agentimus must stop during an agent call." );
		}
		// Another plugin's own hooks run as normal.
		do_action( 'deactivate_plugin', 'hello.php', false );
		$this->addToAssertionCount( 1 );
	}

	public function test_installs_come_only_from_wordpress_org_and_updates_from_anywhere() {
		$this->start_call();
		$install = array(
			'type'   => 'plugin',
			'action' => 'install',
		);
		$refused = apply_filters( 'upgrader_pre_download', false, 'https://evil.example/plugin.zip', null, $install );
		$this->assertInstanceOf( \WP_Error::class, $refused, 'An install from another address must be refused.' );
		$this->assertInstanceOf( \WP_Error::class, apply_filters( 'upgrader_pre_download', false, '/tmp/uploaded.zip', null, $install ), 'A zip on disk must be refused.' );
		$this->assertInstanceOf( \WP_Error::class, apply_filters( 'upgrader_pre_download', false, 'http://downloads.wordpress.org/plugin/x.zip', null, $install ), 'Plain http is not WordPress.org.' );
		$this->assertInstanceOf( \WP_Error::class, apply_filters( 'upgrader_pre_download', false, 'https://downloads.wordpress.org.evil.example/x.zip', null, $install ), 'A look-alike host is not WordPress.org.' );
		$this->assertFalse( apply_filters( 'upgrader_pre_download', false, 'https://downloads.wordpress.org/plugin/akismet.5.3.zip', null, $install ) );
		$this->assertFalse( apply_filters( 'upgrader_pre_download', false, 'https://downloads.wordpress.org/theme/twentytwentyfive.1.0.zip', null, array( 'type' => 'theme', 'action' => 'install' ) ) );

		// Updates come from where the installed plugin says, such as a vendor's server.
		$this->assertFalse( apply_filters( 'upgrader_pre_download', false, 'https://vendor.example/pro.zip', null, array( 'plugin' => 'pro/pro.php' ) ) );
		$this->assertFalse( apply_filters( 'upgrader_pre_download', false, 'https://vendor.example/theme.zip', null, array( 'theme' => 'pro-theme' ) ) );
		// Something that names neither an install nor an update is treated as an install.
		$this->assertInstanceOf( \WP_Error::class, apply_filters( 'upgrader_pre_download', false, 'https://evil.example/x.zip', null, array() ) );
	}

	public function test_the_agent_access_switches_and_sign_in_data_keep_their_value() {
		$settings                         = (array) get_option( 'agentimus_settings', array() );
		$settings['enable_mcp_server']    = true;
		$settings['enable_agent_writes']  = false;
		$settings['agent_writes_content'] = false;
		$settings['mcp_public_tool_list'] = false;
		$settings['llms_full_posts']      = 10;
		update_option( 'agentimus_settings', $settings );
		$token = array(
			'hash'         => 'h-before',
			'scope'        => 'read',
			'user_id'      => $this->admin,
			'created_at'   => 1,
			'last_used_at' => null,
			'last_used_ua' => '',
		);
		update_option( 'agentimus_mcp_token', $token );
		$oauth = array(
			'clients' => array( 'c1' => array( 'name' => 'App', 'last_used' => 1 ) ),
			'codes'   => array(),
			'tokens'  => array( 't1' => array( 'client_id' => 'c1', 'scope' => 'read', 'user_id' => $this->admin, 'expires' => time() + 3600 ) ),
			'refresh' => array(),
		);
		update_option( 'agentimus_oauth', $oauth );

		$this->start_call();

		$changed                         = $settings;
		$changed['enable_agent_writes']  = true;
		$changed['agent_writes_content'] = true;
		$changed['mcp_public_tool_list'] = true;
		$changed['enable_mcp_server']    = false;
		$changed['llms_full_posts']      = 25;
		update_option( 'agentimus_settings', $changed );
		$stored = get_option( 'agentimus_settings' );
		$this->assertFalse( $stored['enable_agent_writes'] );
		$this->assertFalse( $stored['agent_writes_content'] );
		$this->assertFalse( $stored['mcp_public_tool_list'] );
		$this->assertTrue( $stored['enable_mcp_server'] );
		$this->assertSame( 25, $stored['llms_full_posts'], 'A setting outside agent access still changes.' );

		$used                 = $token;
		$used['scope']        = 'write';
		$used['hash']         = 'h-agent';
		$used['last_used_at'] = 50;
		$used['last_used_ua'] = 'Claude';
		update_option( 'agentimus_mcp_token', $used );
		$stored = get_option( 'agentimus_mcp_token' );
		$this->assertSame( 'read', $stored['scope'] );
		$this->assertSame( 'h-before', $stored['hash'] );
		$this->assertSame( 50, $stored['last_used_at'], 'The "last used" time still updates.' );

		$grown                               = $oauth;
		$grown['tokens']['t2']               = array( 'client_id' => 'c1', 'scope' => 'write', 'user_id' => $this->admin, 'expires' => time() + 3600 );
		$grown['tokens']['t1']['scope']      = 'write';
		$grown['clients']['c1']['last_used'] = 99;
		update_option( 'agentimus_oauth', $grown );
		$stored = get_option( 'agentimus_oauth' );
		$this->assertSame( array( 't1' ), array_keys( $stored['tokens'] ) );
		$this->assertSame( 'read', $stored['tokens']['t1']['scope'] );
		$this->assertSame( 99, $stored['clients']['c1']['last_used'], 'The "last used" time still updates.' );

		foreach ( array( 'agentimus_settings', 'agentimus_mcp_token', 'agentimus_oauth' ) as $name ) {
			delete_option( $name );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( $name, 'options' );
			$this->assertNotFalse( get_option( $name ), "{$name} must come back when an agent deletes it." );
		}
	}

	public function test_everything_comes_back_when_the_call_ends() {
		$this->start_call();
		$this->assertFalse( current_user_can( 'edit_users' ) );
		$this->end_call();

		$this->assertTrue( current_user_can( 'edit_users' ) );
		$this->assertTrue( current_user_can( 'edit_user', $this->other_admin ) );
		// default_role, not users_can_register: multisite reads that one from the network.
		update_option( 'default_role', 'editor' );
		$this->assertSame( 'editor', get_option( 'default_role' ) );
	}

	public function test_a_rest_call_inside_an_agent_call_keeps_the_blocks_until_the_agent_call_ends() {
		$this->start_call();
		$this->start_call( '/wp/v2/posts' );
		$this->end_call( '/wp/v2/posts' );
		$this->assertFalse( current_user_can( 'edit_users' ), 'An inner REST call must not end the agent call\'s blocks.' );
		$this->end_call();
		$this->assertTrue( current_user_can( 'edit_users' ) );
	}

	public function test_other_routes_are_not_agent_calls() {
		foreach ( array( '/wp/v2/users', '/agentimus/v1/mcp-token', '/agentimus/v1/settings' ) as $route ) {
			$this->start_call( $route );
			$this->assertTrue( current_user_can( 'edit_users' ), "{$route} is not an agent call." );
			$this->end_call( $route );
		}
	}
}
