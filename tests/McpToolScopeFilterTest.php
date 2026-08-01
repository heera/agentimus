<?php
/**
 * The advertised tool list follows the KEY, not just the settings: a read-only
 * connection token or OAuth grant is shown the nine read tools and none of the
 * five write ones. The permission gate already refused those calls — this stops
 * the assistant being told about doors it cannot open.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Abilities\Registrar;
use Agentimus\McpToken;
use Agentimus\Oauth\Server;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

/** A stand-in for the adapter's Tool DTO — the filter only asks for getName(). */
final class FakeMcpTool {
	/** @var string */
	private $name;

	public function __construct( $name ) {
		$this->name = $name;
	}

	public function getName() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter DTO.
		return $this->name;
	}
}

final class McpToolScopeFilterTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		McpToken::reset_request();
		Server::reset_request();
		$GLOBALS['_af_users']   = array( 7 => true );
		$_SERVER['REQUEST_URI'] = '/wp-json/agentimus/v1/mcp';
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}

	protected function tearDown(): void {
		\_af_reset_options();
		McpToken::reset_request();
		Server::reset_request();
		unset( $GLOBALS['_af_users'], $_SERVER['HTTP_AUTHORIZATION'] );
	}

	private function registrar() {
		update_option( Settings::OPTION, array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 1 ) );
		return new Registrar( new Settings() );
	}

	/** Both naming shapes reach the wire, so both must be recognised. */
	private function tools() {
		return array(
			new FakeMcpTool( 'agentimus/read-readiness' ),
			new FakeMcpTool( 'agentimus-check-page' ),
			new FakeMcpTool( 'agentimus/create-content' ),
			new FakeMcpTool( 'agentimus-update-content' ),
			new FakeMcpTool( 'agentimus/apply-fix' ),
		);
	}

	private function names( array $tools ) {
		return array_map(
			static function ( $t ) {
				return $t->getName();
			},
			$tools
		);
	}

	public function test_read_only_token_is_shown_no_write_tools() {
		$token = McpToken::create( 'read', 7 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		McpToken::authenticate( false );

		$names = $this->names( $this->registrar()->filter_tools_for_scope( $this->tools() ) );
		$this->assertSame( array( 'agentimus/read-readiness', 'agentimus-check-page' ), $names );
	}

	public function test_write_token_keeps_the_whole_list() {
		$token = McpToken::create( 'write', 7 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		McpToken::authenticate( false );

		$this->assertCount( 5, $this->registrar()->filter_tools_for_scope( $this->tools() ) );
	}

	/** No token, no grant — an application password answers to the settings alone. */
	public function test_non_token_requests_see_everything() {
		$this->assertCount( 5, $this->registrar()->filter_tools_for_scope( $this->tools() ) );
	}

	/** The shared write-name predicate — the anonymous read-surface narrows with it too. */
	public function test_is_write_tool_name_recognises_both_wire_shapes() {
		$this->assertTrue( Registrar::is_write_tool_name( 'agentimus/create-content' ) );
		$this->assertTrue( Registrar::is_write_tool_name( 'agentimus-apply-fix' ) );
		$this->assertFalse( Registrar::is_write_tool_name( 'agentimus-read-readiness' ) );
		$this->assertFalse( Registrar::is_write_tool_name( 'acme/create-content' ), 'another namespace\'s tool is not ours to hide' );
		$this->assertFalse( Registrar::is_write_tool_name( '' ) );
	}

	public function test_read_only_oauth_grant_is_shown_no_write_tools() {
		$client = Server::register_client(
			array(
				'client_name'   => 'Claude',
				'redirect_uris' => array( 'https://claude.ai/cb' ),
			)
		);
		$req    = Server::validate_authorize_request(
			array(
				'client_id'             => $client['client_id'],
				'redirect_uri'          => 'https://claude.ai/cb',
				'response_type'         => 'code',
				'code_challenge'        => OauthServerTest::RFC_CHALLENGE,
				'code_challenge_method' => 'S256',
				'scope'                 => 'write', // Asked for write…
			)
		);
		$code   = Server::issue_code( $req, 'read', 7 ); // …owner granted read.
		$tokens = Server::exchange_token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client['client_id'],
				'redirect_uri'  => 'https://claude.ai/cb',
				'code_verifier' => OauthServerTest::RFC_VERIFIER,
			)
		);
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		Server::authenticate( false );

		$names = $this->names( $this->registrar()->filter_tools_for_scope( $this->tools() ) );
		$this->assertNotContains( 'agentimus/create-content', $names );
		$this->assertNotContains( 'agentimus-update-content', $names );
		$this->assertNotContains( 'agentimus/apply-fix', $names );
		$this->assertContains( 'agentimus/read-readiness', $names );
	}

	/** An unfamiliar entry is never dropped on a guess — hiding a real tool is worse. */
	public function test_unknown_shapes_survive_the_filter() {
		$token = McpToken::create( 'read', 7 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		McpToken::authenticate( false );

		$out = $this->registrar()->filter_tools_for_scope( array( 'not-a-dto', new \stdClass() ) );
		$this->assertCount( 2, $out );
	}
}
