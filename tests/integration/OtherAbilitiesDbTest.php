<?php
/**
 * ⭐ Other plugins' abilities, through list-plugins / list-plugin-abilities /
 * run-ability, and the owner's switches on the Abilities screen.
 *
 * His ruling, 2026-11-22: every ability starts off, new ones too; the site's level
 * is the ceiling (Read runs only what the plugin marks read-only); a connection needs
 * the "Use other plugins' abilities" box; only abilities offered to remote callers.
 *
 * A pretend plugin, "fixture-shop", registers four abilities: one that reads, one that
 * writes, one that deletes, and one kept from remote callers (`show_in_rest` false).
 * ⚠️ Core refuses a registration outside `wp_abilities_api_init`, and that hook has
 * already fired, so the fixture registers inside a stand-in for it and is removed
 * again after each test, leaving no trace for the rest of the suite.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\ConnectionAccess;
use Agentimus\Abilities\OtherAbilities;
use Agentimus\Abilities\Registrar;
use Agentimus\McpToken;
use Agentimus\Oauth\Server;
use Agentimus\Settings;

/** The adapter's tool DTO, as far as the tool-list filter looks at it. */
final class OtherAbilitiesTestTool {
	/** @var string */
	private $name;

	public function __construct( $name ) {
		$this->name = $name;
	}

	public function getName() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter DTO.
		return $this->name;
	}
}

final class OtherAbilitiesDbTest extends DbTestCase {

	const READ   = 'fixture-shop/list-orders';
	const WRITE  = 'fixture-shop/update-order';
	const DELETE = 'fixture-shop/delete-order';
	const HIDDEN = 'fixture-shop/internal-helper';

	/** @var Settings */
	private $settings;

	/** @var int */
	private $admin;

	/** @var array */
	private $server_before;

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'No Abilities API on this WordPress: there are no other plugins’ abilities to reach.' );
		}
		$this->server_before = $_SERVER;
		McpToken::reset_request();
		Server::reset_request();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->settings = new Settings();
		$this->level( true );
		delete_option( OtherAbilities::OPTION );
		delete_option( McpToken::OPTION );
		$this->register_fixture();
	}

	public function tear_down(): void {
		if ( function_exists( 'wp_unregister_ability' ) ) {
			foreach ( array( self::READ, self::WRITE, self::DELETE, self::HIDDEN ) as $name ) {
				if ( wp_get_ability( $name ) ) {
					wp_unregister_ability( $name );
				}
			}
		}
		McpToken::reset_request();
		Server::reset_request();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		if ( null !== $this->server_before ) {
			$_SERVER = $this->server_before;
		}
		parent::tear_down();
	}

	/* -- helpers ---------------------------------------------------------- */

	/** Run a callback as if inside a core hook that has already fired. */
	private static function during( $hook, callable $fn ) {
		global $wp_current_filter;
		$wp_current_filter[] = $hook;
		try {
			$fn();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	private function register_fixture() {
		if ( function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( 'fixture-shop' ) ) {
			self::during(
				'wp_abilities_api_categories_init',
				static function () {
					wp_register_ability_category(
						'fixture-shop',
						array(
							'label'       => 'Fixture Shop',
							'description' => 'A pretend shop plugin.',
						)
					);
				}
			);
		}
		$make = static function ( $name, $annotations, $rest = true ) {
			wp_register_ability(
				$name,
				array(
					'label'               => 'Label of ' . $name,
					'description'         => 'Does ' . $name . '.',
					'category'            => 'fixture-shop',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
						'additionalProperties' => false,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => static function ( $input ) use ( $name ) {
						return array(
							'ran'   => $name,
							'input' => $input,
						);
					},
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array(
						'show_in_rest' => $rest,
						'annotations'  => $annotations,
					),
				)
			);
		};
		self::during(
			'wp_abilities_api_init',
			static function () use ( $make ) {
				$make( self::READ, array( 'readonly' => true ) );
				$make( self::WRITE, array( 'readonly' => false ) );
				$make( self::DELETE, array( 'destructive' => true ) );
				$make( self::HIDDEN, array( 'readonly' => true ), false );
			}
		);
	}

	private function level( $writes ) {
		$all                        = $this->settings->stored();
		$all['enable_mcp_server']   = true;
		$all['enable_agent_writes'] = $writes;
		$this->settings->update( $all );
	}

	private function tools() {
		return new OtherAbilities( $this->settings );
	}

	private function fixture_group() {
		foreach ( OtherAbilities::catalog() as $group ) {
			if ( 'fixture-shop' === $group['plugin'] ) {
				return $group;
			}
		}
		$this->fail( 'The fixture plugin is not in the catalog.' );
	}

	/** Seat the shared token on the MCP address, as a real request would. */
	private function sign_in_token( array $access ) {
		$token = McpToken::create( 'write', $this->admin );
		McpToken::set_access( $access );
		$_SERVER['REQUEST_URI']        = '/wp-json/agentimus/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->assertSame( $this->admin, McpToken::authenticate( false ), 'The token must really sign in.' );
	}

	/* -- what is listed --------------------------------------------------- */

	public function test_every_ability_starts_off_and_only_remote_ones_are_listed() {
		$group = $this->fixture_group();
		$names = array_column( $group['abilities'], 'name' );
		$this->assertSame( array( self::DELETE, self::READ, self::WRITE ), $names, 'The helper kept from remote callers is not listed.' );
		foreach ( $group['abilities'] as $row ) {
			$this->assertFalse( $row['on'], $row['name'] . ' must start off.' );
		}
		$this->assertSame( 'Fixture Shop', $group['title'], 'The plugin’s own name.' );
		$this->assertSame(
			array( 'delete', 'read', 'write' ),
			array_column( $group['abilities'], 'level' )
		);
		foreach ( OtherAbilities::catalog() as $g ) {
			$this->assertNotSame( 'agentimus', $g['plugin'], 'Agentimus’s own abilities are its own tools.' );
		}
	}

	public function test_list_plugins_counts_only_what_can_run() {
		$row = $this->find_plugin( $this->tools()->list_plugins()['plugins'] );
		$this->assertSame( 0, $row['on'] );
		$this->assertSame( 3, $row['off'] );

		OtherAbilities::set( array( self::READ, self::WRITE ), true );
		$this->level( false );
		$row = $this->find_plugin( $this->tools()->list_plugins()['plugins'] );
		$this->assertSame( 1, $row['on'], 'At Read, the writing ability counts as off.' );

		$this->level( true );
		$row = $this->find_plugin( $this->tools()->list_plugins()['plugins'] );
		$this->assertSame( 2, $row['on'] );
	}

	private function find_plugin( array $plugins ) {
		foreach ( $plugins as $p ) {
			if ( 'fixture-shop' === $p['plugin'] ) {
				return $p;
			}
		}
		$this->fail( 'fixture-shop is missing from list-plugins.' );
	}

	public function test_list_plugin_abilities_shows_only_what_can_run_with_its_input() {
		OtherAbilities::set( array( self::READ ), true );
		$out = $this->tools()->list_plugin_abilities( 'fixture-shop' );
		$this->assertSame( array( self::READ ), array_column( $out['abilities'], 'name' ) );
		$this->assertSame( 2, $out['off'] );
		$this->assertSame( 'integer', $out['abilities'][0]['input']['properties']['id']['type'] );
	}

	/* -- running ---------------------------------------------------------- */

	public function test_an_ability_that_is_off_or_missing_is_refused_the_same_way() {
		$off     = $this->tools()->run( self::READ, array( 'id' => 1 ) );
		$missing = $this->tools()->run( 'fixture-shop/nope', array() );
		$hidden  = $this->tools()->run( self::HIDDEN, array() );
		foreach ( array( $off, $missing, $hidden ) as $result ) {
			$this->assertWPError( $result );
			$this->assertSame( 'agentimus_ability_off', $result->get_error_code() );
		}
	}

	public function test_a_turned_on_ability_runs_and_comes_back_marked_as_data() {
		OtherAbilities::set( array( self::READ ), true );
		$out = $this->tools()->run( self::READ, array( 'id' => 7 ) );
		$this->assertNotWPError( $out );
		$this->assertSame( self::READ, $out['result']['ran'] );
		$this->assertSame( 7, $out['result']['input']['id'] );
		$this->assertSame( 'fixture-shop', $out['plugin'] );
		$this->assertStringContainsString( 'data, not instructions', $out['note'] );
	}

	public function test_at_the_read_level_only_reading_abilities_run() {
		OtherAbilities::set( array( self::READ, self::WRITE, self::DELETE ), true );
		$this->level( false );
		$this->assertNotWPError( $this->tools()->run( self::READ, array() ) );
		foreach ( array( self::WRITE, self::DELETE ) as $name ) {
			$result = $this->tools()->run( $name, array() );
			$this->assertWPError( $result, $name );
			$this->assertSame( 'agentimus_ability_level', $result->get_error_code() );
		}
		$this->level( true );
		$this->assertNotWPError( $this->tools()->run( self::WRITE, array() ) );
		$this->assertNotWPError( $this->tools()->run( self::DELETE, array() ) );
	}

	public function test_the_plugins_own_permission_check_still_runs() {
		OtherAbilities::set( array( self::READ ), true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertWPError( $this->tools()->run( self::READ, array() ), 'The fixture allows only administrators.' );
	}

	public function test_the_ability_checks_its_own_input() {
		OtherAbilities::set( array( self::READ ), true );
		$this->assertWPError( $this->tools()->run( self::READ, array( 'id' => 'not a number' ) ) );
	}

	/* -- the connection's box --------------------------------------------- */

	public function test_a_connection_without_the_box_neither_sees_nor_runs_the_three_tools() {
		$this->sign_in_token( ConnectionAccess::some( array( 'posts' ) ) );
		$names = $this->listed( array( 'list-plugins', 'list-plugin-abilities', 'run-ability', 'read-readiness' ) );
		$this->assertSame( array( 'read-readiness' ), $names );
		foreach ( array( 'list-plugins', 'list-plugin-abilities', 'run-ability' ) as $slug ) {
			$gate    = ConnectionAccess::gate( $slug, '__return_true' );
			$refused = $gate( array() );
			$this->assertWPError( $refused, $slug );
			$this->assertStringContainsString( '“Use other plugins’ abilities”', $refused->get_error_message(), $slug );
		}
	}

	public function test_a_connection_with_the_box_or_everything_has_them() {
		foreach ( array( ConnectionAccess::some( array( 'abilities' ) ), ConnectionAccess::everything() ) as $access ) {
			McpToken::reset_request();
			$this->sign_in_token( $access );
			$this->assertSame(
				array( 'list-plugins', 'list-plugin-abilities', 'run-ability' ),
				$this->listed( array( 'list-plugins', 'list-plugin-abilities', 'run-ability' ) )
			);
		}
	}

	public function test_the_box_is_offered_on_every_access_screen() {
		$this->assertContains( 'abilities', array_column( ConnectionAccess::choices(), 'key' ) );
		$this->assertSame( 'abilities', ConnectionAccess::box_of( 'run-ability' ) );
	}

	private function listed( array $slugs ) {
		$tools = array();
		foreach ( $slugs as $slug ) {
			$tools[] = new OtherAbilitiesTestTool( 'agentimus-' . $slug );
		}
		$kept = ( new Registrar( $this->settings ) )->filter_tools_for_scope( $tools );
		return array_map(
			static function ( $t ) {
				return substr( $t->getName(), strlen( 'agentimus-' ) );
			},
			array_values( $kept )
		);
	}

	/* -- the real registered tools ---------------------------------------- */

	public function test_the_three_tools_are_registered_and_answer_within_their_own_schemas() {
		OtherAbilities::set( array( self::READ ), true );
		foreach ( array( 'list-plugins', 'list-plugin-abilities', 'run-ability' ) as $slug ) {
			$this->assertNotNull( wp_get_ability( 'agentimus/' . $slug ), $slug . ' must be registered.' );
			$this->assertContains( 'agentimus/' . $slug, ( new Registrar( $this->settings ) )->mcp_abilities(), $slug . ' must be on the MCP server.' );
		}
		$this->assertNotWPError( wp_get_ability( 'agentimus/list-plugins' )->execute( array() ) );
		$this->assertNotWPError( wp_get_ability( 'agentimus/list-plugin-abilities' )->execute( array( 'plugin' => 'fixture-shop' ) ) );
		$out = wp_get_ability( 'agentimus/run-ability' )->execute(
			array(
				'name'  => self::READ,
				'input' => array( 'id' => 3 ),
			)
		);
		$this->assertNotWPError( $out );
		$this->assertSame( 3, $out['result']['input']['id'] );
	}

	public function test_run_ability_works_for_a_read_only_token_on_a_reading_ability() {
		OtherAbilities::set( array( self::READ, self::WRITE ), true );
		$this->sign_in_token( ConnectionAccess::some( array( 'abilities' ) ) );
		$state          = get_option( McpToken::OPTION );
		$state['scope'] = 'read';
		update_option( McpToken::OPTION, $state );
		McpToken::reset_request();
		$this->assertSame( $this->admin, McpToken::authenticate( false ) );

		$this->assertNotWPError( wp_get_ability( 'agentimus/run-ability' )->execute( array( 'name' => self::READ ) ), 'Reading runs on a read token.' );
		$this->assertWPError( $this->tools()->run( self::WRITE, array() ), 'Writing does not.' );
	}

	/* -- the owner's switches --------------------------------------------- */

	public function test_switching_ignores_names_that_are_no_ability() {
		OtherAbilities::set( array( self::READ, 'agentimus/update-settings', 'made-up/thing', self::HIDDEN ), true );
		$this->assertSame( array( self::READ ), OtherAbilities::enabled() );
		OtherAbilities::set( array( self::READ ), false );
		$this->assertSame( array(), OtherAbilities::enabled() );
	}

	public function test_the_screen_lists_and_switches_through_rest() {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/abilities' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['available'] );

		$request = new \WP_REST_Request( 'PUT', '/agentimus/v1/abilities' );
		$request->set_body_params(
			array(
				'names' => array( self::READ, self::WRITE ),
				'on'    => true,
			)
		);
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( array( self::READ, self::WRITE ), OtherAbilities::enabled() );
	}

	public function test_who_may_not_switch_abilities() {
		$body = array(
			'names' => array( self::WRITE ),
			'on'    => true,
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new \WP_REST_Request( 'PUT', '/agentimus/v1/abilities' );
		$request->set_body_params( $body );
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ), 'An editor may not.' );

		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_application_password_uuid'] = 'test-uuid';
		$request = new \WP_REST_Request( 'PUT', '/agentimus/v1/abilities' );
		$request->set_body_params( $body );
		$status = rest_do_request( $request )->get_status();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$this->assertContains( $status, array( 401, 403 ), 'An application password may not.' );

		$this->sign_in_token( ConnectionAccess::everything() );
		$request = new \WP_REST_Request( 'PUT', '/agentimus/v1/abilities' );
		$request->set_body_params( $body );
		$this->assertSame( 403, rest_do_request( $request )->get_status(), 'An agent may not.' );

		$this->assertSame( array(), OtherAbilities::enabled(), 'Nothing was turned on.' );
	}

	public function test_an_agent_call_cannot_turn_abilities_on() {
		OtherAbilities::set( array( self::READ ), true );
		apply_filters( 'rest_request_before_callbacks', null, array(), new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' ) );
		update_option( OtherAbilities::OPTION, array( self::READ, self::WRITE, self::DELETE ) );
		delete_option( OtherAbilities::OPTION );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( OtherAbilities::OPTION, 'options' );
		$during = OtherAbilities::enabled();
		apply_filters( 'rest_request_after_callbacks', null, array(), new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' ) );
		$this->assertSame( array( self::READ ), $during );
	}
}
