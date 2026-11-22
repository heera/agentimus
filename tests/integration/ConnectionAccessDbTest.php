<?php
/**
 * ⭐ Each connection's own access: Everything, or Only some things.
 *
 * His ruling, 2026-11-22: the approval screen's one button, Allow, gives
 * Everything; "Allow less" opens the boxes. Each connection on the card has
 * Change access. The site's switches stay the ceiling. A connection from before
 * gains nothing: write became Everything, read became nothing ticked.
 *
 * Connections are seated the way a real request seats them: a bearer header on the
 * MCP address, through the authenticators.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\ConnectionAccess;
use Agentimus\Abilities\ContentWriter;
use Agentimus\Abilities\Registrar;
use Agentimus\McpToken;
use Agentimus\Oauth\Consent;
use Agentimus\Oauth\Server;
use Agentimus\Settings;

/** The adapter's tool DTO, as far as the tool-list filter looks at it. */
final class AccessTestTool {
	/** @var string */
	private $name;

	public function __construct( $name ) {
		$this->name = $name;
	}

	public function getName() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter DTO.
		return $this->name;
	}
}

final class ConnectionAccessDbTest extends DbTestCase {

	const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	/** @var Settings */
	private $settings;

	/** @var int */
	private $admin;

	/** @var array */
	private $server_before;

	public function set_up(): void {
		parent::set_up();
		$this->server_before = $_SERVER;
		// Start signed out of every connection, whatever an earlier test left seated.
		$this->sign_out();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$this->admin         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->settings = new Settings();
		$all            = $this->settings->all();
		$all['enable_mcp_server']        = true;
		$all['enable_agent_writes']      = true;
		$all['agent_writes_publish']     = true;
		$all['agent_writes_content']     = true;
		$all['agent_writes_settings']    = true;
		$all['agent_writes_maintenance'] = true;
		$this->settings->update( $all );
		delete_option( 'agentimus_oauth' );
		delete_option( McpToken::OPTION );
	}

	public function tear_down(): void {
		$this->sign_out();
		$_SERVER = $this->server_before;
		parent::tear_down();
	}

	/* -- helpers ---------------------------------------------------------- */

	/** Approve an assistant with this access and return a live access token. */
	private function approve( $access, $wants = 'write' ) {
		$client = Server::register_client(
			array(
				'client_name'   => 'Claude',
				'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ),
			)
		);
		$req    = Server::validate_authorize_request(
			array(
				'client_id'             => $client['client_id'],
				'redirect_uri'          => 'https://claude.ai/api/mcp/auth_callback',
				'response_type'         => 'code',
				'code_challenge'        => self::CHALLENGE,
				'code_challenge_method' => 'S256',
				'scope'                 => $wants,
				'state'                 => 's',
			)
		);
		$this->assertIsArray( $req );
		$code   = Server::issue_code( $req, ConnectionAccess::scope_for( ConnectionAccess::normalize( $access ) ), $this->admin, $access );
		$tokens = Server::exchange_token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client['client_id'],
				'redirect_uri'  => 'https://claude.ai/api/mcp/auth_callback',
				'code_verifier' => self::VERIFIER,
			)
		);
		$this->assertIsArray( $tokens );
		return array( $client['client_id'], $tokens['access_token'] );
	}

	/** Seat a bearer credential on the MCP address, as a real request would. */
	private function sign_in( $bearer ) {
		$this->sign_out();
		$_SERVER['REQUEST_URI']        = '/wp-json/agentimus/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
		$seated                        = McpToken::authenticate( false );
		$seated                        = $seated ? $seated : Server::authenticate( false );
		$this->assertSame( $this->admin, $seated, 'The credential must really have signed in, or this proves nothing.' );
	}

	private function sign_out() {
		McpToken::reset_request();
		Server::reset_request();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}

	/** The write tools this request's tool list keeps. */
	private function listed_write_tools() {
		$tools = array();
		foreach ( Registrar::WRITE_SLUGS as $slug ) {
			$tools[] = new AccessTestTool( 'agentimus-' . $slug );
		}
		$tools[] = new AccessTestTool( 'agentimus-read-readiness' );
		$kept    = ( new Registrar( $this->settings ) )->filter_tools_for_scope( $tools );
		$names   = array_map(
			static function ( $t ) {
				return substr( $t->getName(), strlen( 'agentimus-' ) );
			},
			$kept
		);
		$this->assertContains( 'read-readiness', $names, 'Reading is always kept.' );
		return array_values( array_diff( $names, array( 'read-readiness' ) ) );
	}

	/** Whether a write tool's call would pass this connection's boxes. */
	private function call_allowed( $slug ) {
		$gate = ConnectionAccess::gate( $slug, '__return_true' );
		return (bool) $gate( array() );
	}

	/* -- the boxes -------------------------------------------------------- */

	public function test_every_write_tool_belongs_to_exactly_one_box() {
		$seen = array();
		foreach ( ConnectionAccess::BOXES as $box => $slugs ) {
			foreach ( $slugs as $slug ) {
				$this->assertContains( $slug, Registrar::WRITE_SLUGS, "{$box} names {$slug}, which is no write tool." );
				$this->assertArrayNotHasKey( $slug, $seen, "{$slug} is in two boxes." );
				$seen[ $slug ] = $box;
			}
		}
		foreach ( Registrar::WRITE_SLUGS as $slug ) {
			$this->assertArrayHasKey( $slug, $seen, "{$slug} is in no box, so Only some things could not hold it back." );
		}
		$this->assertSame( array_keys( ConnectionAccess::BOXES ), array_column( ConnectionAccess::choices(), 'key' ), 'The screens show every box, in order.' );
	}

	/* -- Everything and Only some things ---------------------------------- */

	public function test_everything_lists_and_runs_every_write_tool() {
		list( , $token ) = $this->approve( ConnectionAccess::everything() );
		$this->sign_in( $token );
		$this->assertEqualsCanonicalizing( Registrar::WRITE_SLUGS, $this->listed_write_tools() );
		foreach ( Registrar::WRITE_SLUGS as $slug ) {
			$this->assertTrue( $this->call_allowed( $slug ), $slug );
		}
	}

	public function test_only_some_things_lists_and_runs_only_the_ticked_boxes() {
		list( , $token ) = $this->approve( ConnectionAccess::some( array( 'posts', 'maintenance' ) ) );
		$this->sign_in( $token );
		$expected = array_merge( ConnectionAccess::BOXES['posts'], ConnectionAccess::BOXES['maintenance'] );
		$this->assertEqualsCanonicalizing( $expected, $this->listed_write_tools() );
		foreach ( Registrar::WRITE_SLUGS as $slug ) {
			$this->assertSame( in_array( $slug, $expected, true ), $this->call_allowed( $slug ), $slug );
		}
	}

	public function test_nothing_ticked_is_read_only() {
		list( , $token ) = $this->approve( ConnectionAccess::some( array() ) );
		$this->sign_in( $token );
		$this->assertSame( array(), $this->listed_write_tools() );
		$this->assertFalse( $this->call_allowed( 'update-settings' ) );
	}

	public function test_publishing_needs_the_publish_box_even_with_posts_ticked() {
		list( , $token ) = $this->approve( ConnectionAccess::some( array( 'posts' ) ) );
		$this->sign_in( $token );
		$result = ( new ContentWriter( $this->settings ) )->create(
			array(
				'title'   => 'Not live',
				'content' => '<p>Body.</p>',
				'status'  => 'publish',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_publish_not_allowed', $result->get_error_code() );
		$this->assertSame( array(), get_posts( array( 'title' => 'Not live', 'post_status' => 'any' ) ), 'Nothing is written.' );

		$draft = ( new ContentWriter( $this->settings ) )->create(
			array(
				'title'   => 'A draft',
				'content' => '<p>Body.</p>',
			)
		);
		$this->assertNotWPError( $draft, 'A draft needs only Write and edit posts.' );
	}

	public function test_scheduling_needs_the_content_box() {
		list( , $token ) = $this->approve( ConnectionAccess::some( array( 'posts', 'publish' ) ) );
		$this->sign_in( $token );
		$result = ( new ContentWriter( $this->settings ) )->create(
			array(
				'title'      => 'Later',
				'content'    => '<p>Body.</p>',
				'publish_at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_content_not_allowed', $result->get_error_code() );
	}

	public function test_the_site_switches_stay_the_ceiling_for_everything() {
		$all                         = $this->settings->all();
		$all['agent_writes_publish'] = false;
		$this->settings->update( $all );
		list( , $token ) = $this->approve( ConnectionAccess::everything() );
		$this->sign_in( $token );
		$result = ( new ContentWriter( $this->settings ) )->create(
			array(
				'title'   => 'Ceiling',
				'content' => '<p>Body.</p>',
				'status'  => 'publish',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_publish_off', $result->get_error_code() );
	}

	/* -- the shared token ------------------------------------------------- */

	public function test_the_shared_token_has_access_too() {
		$token = McpToken::create( 'write', $this->admin );
		$this->assertSame( ConnectionAccess::everything(), McpToken::status()['access'], 'A write token is Everything.' );
		McpToken::set_access( ConnectionAccess::some( array( 'settings' ) ) );
		$this->sign_in( $token );
		$this->assertEqualsCanonicalizing( ConnectionAccess::BOXES['settings'], $this->listed_write_tools() );

		$this->sign_out();
		McpToken::set_access( ConnectionAccess::some( array() ) );
		$this->assertSame( 'read', get_option( McpToken::OPTION )['scope'], 'Nothing ticked makes it a read token.' );
		$this->sign_in( $token );
		$this->assertSame( array(), $this->listed_write_tools() );
	}

	/* -- older connections gain nothing ----------------------------------- */

	public function test_an_older_write_grant_is_everything_and_an_older_read_grant_reads() {
		list( $write_id, $write_token ) = $this->approve( null );
		$state = get_option( 'agentimus_oauth' );
		$state['clients'][ $write_id ]['scope'] = 'write';
		foreach ( $state['tokens'] as $hash => $t ) {
			$state['tokens'][ $hash ]['scope'] = 'write';
		}
		unset( $state['clients'][ $write_id ]['access'] );
		update_option( 'agentimus_oauth', $state );

		$this->sign_in( $write_token );
		$this->assertSame( ConnectionAccess::everything(), ConnectionAccess::current() );

		list( $read_id, $read_token ) = $this->approve( ConnectionAccess::some( array() ), 'read' );
		$state = get_option( 'agentimus_oauth' );
		unset( $state['clients'][ $read_id ]['access'] );
		update_option( 'agentimus_oauth', $state );
		$this->sign_in( $read_token );
		$this->assertSame( ConnectionAccess::some( array() ), ConnectionAccess::current() );
		$this->assertSame( array(), $this->listed_write_tools() );
	}

	public function test_an_older_shared_token_reads_from_its_scope() {
		$token = McpToken::create( 'read', $this->admin );
		$state = get_option( McpToken::OPTION );
		unset( $state['access'] );
		update_option( McpToken::OPTION, $state );
		$this->sign_in( $token );
		$this->assertSame( ConnectionAccess::some( array() ), ConnectionAccess::current() );
	}

	public function test_an_application_password_or_browser_follows_the_site_switches() {
		$this->assertNull( ConnectionAccess::current() );
		$this->assertTrue( $this->call_allowed( 'update-settings' ) );
		$this->assertTrue( ConnectionAccess::allows( 'publish' ) );
	}

	/* -- changing access -------------------------------------------------- */

	public function test_changing_access_holds_on_the_next_call() {
		list( $client_id, $token ) = $this->approve( ConnectionAccess::everything() );
		Server::set_access( $client_id, ConnectionAccess::some( array( 'content' ) ) );
		$this->sign_in( $token );
		$this->assertEqualsCanonicalizing( ConnectionAccess::BOXES['content'], $this->listed_write_tools() );

		$this->sign_out();
		Server::set_access( $client_id, ConnectionAccess::some( array() ) );
		$this->sign_in( $token );
		$this->assertSame( array(), $this->listed_write_tools(), 'Nothing ticked makes the live token read-only.' );

		$this->sign_out();
		Server::set_access( $client_id, ConnectionAccess::everything() );
		$this->sign_in( $token );
		$this->assertEqualsCanonicalizing( Registrar::WRITE_SLUGS, $this->listed_write_tools(), 'And back to Everything on the same token.' );
	}

	public function test_the_card_lists_each_connections_access_and_the_boxes() {
		list( $client_id ) = $this->approve( ConnectionAccess::some( array( 'posts' ) ) );
		$response          = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/oauth/grants' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( ConnectionAccess::some( array( 'posts' ) ), $data['grants'][0]['access'] );
		$this->assertSame( $client_id, $data['grants'][0]['clientId'] );
		$this->assertSame( array_keys( ConnectionAccess::BOXES ), array_column( $data['choices'], 'key' ) );
	}

	public function test_an_admin_in_the_browser_can_change_access() {
		list( $client_id ) = $this->approve( ConnectionAccess::everything() );
		$request           = new \WP_REST_Request( 'PUT', '/agentimus/v1/connection-access' );
		$request->set_body_params(
			array(
				'kind'     => 'grant',
				'clientId' => $client_id,
				'access'   => array(
					'preset' => 'some',
					'boxes'  => array( 'posts', 'not-a-box' ),
				),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( ConnectionAccess::some( array( 'posts' ) ), $response->get_data()['grants'][0]['access'], 'Unknown boxes are dropped.' );
	}

	public function test_who_may_not_change_access() {
		list( $client_id ) = $this->approve( ConnectionAccess::some( array() ) );
		$body              = array(
			'kind'     => 'grant',
			'clientId' => $client_id,
			'access'   => array( 'preset' => 'everything' ),
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new \WP_REST_Request( 'PUT', '/agentimus/v1/connection-access' );
		$request->set_body_params( $body );
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ), 'An editor may not.' );

		// An application password reaches every REST route; it must not reach this one.
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_application_password_uuid'] = 'test-uuid';
		$request = new \WP_REST_Request( 'PUT', '/agentimus/v1/connection-access' );
		$request->set_body_params( $body );
		$status = rest_do_request( $request )->get_status();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		if ( function_exists( 'rest_get_authenticated_app_password' ) ) {
			$this->assertContains( $status, array( 401, 403 ), 'An application password may not.' );
		}

		// An agent's own credential is confined to the MCP address.
		$_SERVER['REQUEST_URI'] = '/wp-json/agentimus/v1/mcp';
		list( , $token )        = $this->approve( ConnectionAccess::everything() );
		$this->sign_in( $token );
		$request = new \WP_REST_Request( 'PUT', '/agentimus/v1/connection-access' );
		$request->set_body_params( $body );
		$this->assertSame( 403, rest_do_request( $request )->get_status(), 'An agent may not.' );
		$this->sign_out();

		$this->assertSame( ConnectionAccess::some( array() ), Server::grants()[0]['access'], 'Nothing changed.' );
	}

	/* -- the approval screen's click -------------------------------------- */

	public function test_one_click_allow_is_everything_and_allow_less_is_the_ticked_boxes() {
		$this->assertSame( ConnectionAccess::everything(), Consent::access_from( array( 'decision' => 'approve' ), true ) );
		$this->assertSame(
			ConnectionAccess::some( array( 'posts', 'publish' ) ),
			Consent::access_from(
				array(
					'decision' => 'approve_some',
					'boxes'    => array( 'publish', 'posts', 'nope' ),
				),
				true
			)
		);
		$this->assertSame( ConnectionAccess::some( array() ), Consent::access_from( array( 'decision' => 'approve_some' ), true ), 'All unticked is read-only.' );
	}

	public function test_an_assistant_that_asked_to_read_gets_reading_whatever_the_form_says() {
		$this->assertSame( ConnectionAccess::some( array() ), Consent::access_from( array( 'decision' => 'approve' ), false ) );
		$this->assertSame(
			ConnectionAccess::some( array() ),
			Consent::access_from(
				array(
					'decision' => 'approve_some',
					'boxes'    => array( 'settings' ),
				),
				false
			)
		);
	}

	public function test_approving_stores_the_access_and_the_scope_it_needs() {
		list( $client_id ) = $this->approve( ConnectionAccess::some( array( 'reviews' ) ) );
		$client            = get_option( 'agentimus_oauth' )['clients'][ $client_id ];
		$this->assertSame( ConnectionAccess::some( array( 'reviews' ) ), $client['access'] );
		$this->assertSame( 'write', $client['scope'] );

		list( $read_id ) = $this->approve( ConnectionAccess::some( array() ) );
		$this->assertSame( 'read', get_option( 'agentimus_oauth' )['clients'][ $read_id ]['scope'] );
	}
}
