<?php
/**
 * ⭐ Saved skills: the owner's rules every agent reads before a task.
 *
 * His ruling, 2026-11-22: a skill the owner writes is on at once; a skill an agent
 * saves waits off until the owner turns it on, and an agent that changes its own
 * skill turns it off again; an agent never changes the owner's skill, turns one off
 * or deletes one; 50 at most.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\ConnectionAccess;
use Agentimus\Abilities\Registrar;
use Agentimus\Abilities\Skills;
use Agentimus\McpToken;
use Agentimus\Oauth\Server;
use Agentimus\Settings;

final class SkillsDbTest extends DbTestCase {

	/** @var int */
	private $admin;

	/** @var array */
	private $server_before;

	/** @var string|null */
	private $pagenow_before;

	public function set_up(): void {
		parent::set_up();
		$this->server_before = $_SERVER;
		McpToken::reset_request();
		Server::reset_request();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		// An earlier test can leave a rest_route behind, which a token reads as its address.
		unset( $_GET['rest_route'], $_POST['rest_route'] );
		// A real request is served by index.php, the one door a token is honoured on;
		// an earlier test can leave this unset.
		$this->pagenow_before = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
		$GLOBALS['pagenow']   = 'index.php';
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		delete_option( McpToken::OPTION );
		if ( ! post_type_exists( Skills::POST_TYPE ) ) {
			Skills::register_post_type();
		}
	}

	public function tear_down(): void {
		$this->end_call();
		McpToken::reset_request();
		Server::reset_request();
		$_SERVER = $this->server_before;
		if ( null === $this->pagenow_before ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->pagenow_before;
		}
		parent::tear_down();
	}

	/** @var bool */
	private $in_call = false;

	private function start_call() {
		apply_filters( 'rest_request_before_callbacks', null, array(), new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' ) );
		$this->in_call = true;
	}

	private function end_call() {
		if ( $this->in_call ) {
			apply_filters( 'rest_request_after_callbacks', null, array(), new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' ) );
			$this->in_call = false;
		}
	}

	private function by_name( $name ) {
		foreach ( Skills::all() as $row ) {
			if ( $row['name'] === $name ) {
				return $row;
			}
		}
		return null;
	}

	/* -- who writes, and on or off ---------------------------------------- */

	public function test_the_owners_skill_is_on_at_once_and_agents_read_it() {
		$saved = Skills::save_from_owner( 0, 'Publishing a post', 'Before publishing', "1. Add a featured image.\n2. One category.", true );
		$this->assertNotWPError( $saved );
		$this->assertTrue( $saved['on'] );
		$this->assertSame( 'person', $saved['by'] );
		$this->assertSame( array( array( 'name' => 'Publishing a post', 'when' => 'Before publishing' ) ), Skills::on() );
		$this->assertSame( "1. Add a featured image.\n2. One category.", Skills::get_on( 'publishing a post' )['steps'], 'Found by name, any case; line breaks kept.' );
	}

	public function test_an_agents_skill_waits_off_and_agents_cannot_read_it() {
		$this->start_call();
		$out = Skills::save_from_agent( 'Weekend rule', 'Before publishing', 'Never publish on weekends.' );
		$this->end_call();
		$this->assertNotWPError( $out );
		$this->assertFalse( $out['on'] );
		$row = $this->by_name( 'Weekend rule' );
		$this->assertFalse( $row['on'] );
		$this->assertSame( 'agent', $row['by'] );
		$this->assertSame( array(), Skills::on() );
		$this->assertNull( Skills::get_on( 'Weekend rule' ) );

		Skills::set_on( $row['id'], true );
		$this->assertSame( 'Never publish on weekends.', Skills::get_on( 'Weekend rule' )['steps'], 'Once the owner turns it on, agents read it.' );
	}

	public function test_an_agent_changing_its_own_skill_turns_it_off_again() {
		Skills::save_from_agent( 'Weekend rule', '', 'Never publish on weekends.' );
		Skills::set_on( $this->by_name( 'Weekend rule' )['id'], true );

		$this->start_call();
		$out = Skills::save_from_agent( 'Weekend rule', '', 'Publish whenever you like.' );
		$this->end_call();
		$this->assertTrue( $out['changed'] );
		$this->assertFalse( $this->by_name( 'Weekend rule' )['on'] );
		$this->assertNull( Skills::get_on( 'Weekend rule' ), 'The changed text is not followed until the owner looks.' );
	}

	public function test_an_agent_cannot_change_the_owners_skill() {
		Skills::save_from_owner( 0, 'Publishing a post', '', 'Add a featured image.', true );
		$this->start_call();
		$out = Skills::save_from_agent( 'PUBLISHING A POST', '', 'Skip the image.' );
		$this->end_call();
		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_skill_person', $out->get_error_code() );
		$this->assertSame( 'Add a featured image.', Skills::get_on( 'Publishing a post' )['steps'] );
	}

	public function test_skills_are_plain_text() {
		Skills::save_from_owner( 0, 'Tags <b>bold</b>', '', "Use <script>alert(1)</script>short paragraphs.\nKeep this line.", true );
		$skill = Skills::get_on( 'Tags bold' );
		$this->assertNotNull( $skill );
		$this->assertStringNotContainsString( '<script', $skill['steps'] );
		$this->assertStringContainsString( "\nKeep this line.", $skill['steps'] );
	}

	public function test_a_skill_needs_a_name_and_steps_within_limits() {
		$this->assertWPError( Skills::save_from_agent( '', '', 'Steps.' ) );
		$this->assertWPError( Skills::save_from_agent( 'Name', '', '' ) );
		$this->assertWPError( Skills::save_from_agent( str_repeat( 'n', 81 ), '', 'Steps.' ) );
		$this->assertWPError( Skills::save_from_agent( 'Name', '', str_repeat( 's', 8001 ) ) );
		$this->assertSame( array(), Skills::all() );
	}

	public function test_fifty_at_most() {
		for ( $i = 1; $i <= Skills::MAX; $i++ ) {
			$this->assertNotWPError( Skills::save_from_agent( 'Skill ' . $i, '', 'Steps.' ) );
		}
		$out = Skills::save_from_agent( 'One more', '', 'Steps.' );
		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_skill_full', $out->get_error_code() );
		$this->assertNotWPError( Skills::save_from_agent( 'Skill 3', '', 'Changed steps.' ), 'Changing one of its own still works when full.' );
	}

	/* -- an agent call, by any path --------------------------------------- */

	public function test_an_agent_call_cannot_turn_a_skill_on_pass_it_off_as_the_owners_or_delete_one() {
		Skills::save_from_owner( 0, 'Owner rule', '', 'Steps.', true );
		Skills::save_from_agent( 'Agent rule', '', 'Steps.' );
		$owner = $this->by_name( 'Owner rule' );
		$agent = $this->by_name( 'Agent rule' );

		$this->start_call();
		wp_update_post( array( 'ID' => $agent['id'], 'post_status' => 'publish' ) );
		update_post_meta( $agent['id'], Skills::BY, 'person' );
		wp_delete_post( $owner['id'], true );
		wp_update_post( array( 'ID' => $owner['id'], 'post_status' => 'draft' ) );
		$this->end_call();

		$agent = $this->by_name( 'Agent rule' );
		$this->assertFalse( $agent['on'], 'Still off.' );
		$this->assertSame( 'agent', $agent['by'], 'Still marked as the agent’s.' );
		$this->assertNotNull( $this->by_name( 'Owner rule' ), 'Not deleted.' );
	}

	/* -- the tools -------------------------------------------------------- */

	public function test_save_skill_is_a_write_tool_in_its_own_box() {
		$this->assertContains( 'save-skill', Registrar::WRITE_SLUGS );
		$this->assertSame( 'skills', ConnectionAccess::box_of( 'save-skill' ) );
		$this->assertNull( ConnectionAccess::box_of( 'list-skills' ), 'Reading skills needs no box.' );
		$this->assertContains( 'skills', array_column( ConnectionAccess::choices(), 'key' ) );

		$token = McpToken::create( 'write', $this->admin );
		McpToken::set_access( ConnectionAccess::some( array( 'posts' ) ) );
		$_SERVER['REQUEST_URI']        = '/wp-json/agentimus/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->assertSame( $this->admin, McpToken::authenticate( false ) );
		$gate = ConnectionAccess::gate( 'save-skill', '__return_true' );
		$this->assertFalse( $gate( array() ), 'Without the box, an agent can’t save skills.' );
	}

	public function test_the_read_tools_are_on_the_server_at_every_level() {
		$settings = new Settings();
		$all      = $settings->stored();
		$all['enable_mcp_server']   = true;
		$all['enable_agent_writes'] = false;
		$settings->update( $all );
		$names = ( new Registrar( $settings ) )->mcp_abilities();
		$this->assertContains( 'agentimus/list-skills', $names );
		$this->assertContains( 'agentimus/get-skill', $names );
		$this->assertNotContains( 'agentimus/save-skill', $names, 'Saving needs the Write level.' );
	}

	public function test_the_tools_run_within_their_own_schemas() {
		if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( 'agentimus/list-skills' ) ) {
			$this->markTestSkipped( 'No Abilities API on this WordPress.' );
		}
		Skills::save_from_owner( 0, 'Publishing a post', 'Before publishing', 'Add a featured image.', true );
		$list = wp_get_ability( 'agentimus/list-skills' )->execute( array() );
		$this->assertNotWPError( $list );
		$this->assertSame( 'Publishing a post', $list['skills'][0]['name'] );
		$one = wp_get_ability( 'agentimus/get-skill' )->execute( array( 'name' => 'Publishing a post' ) );
		$this->assertNotWPError( $one );
		$this->assertWPError( wp_get_ability( 'agentimus/get-skill' )->execute( array( 'name' => 'Nope' ) ) );
		$save = wp_get_ability( 'agentimus/save-skill' );
		if ( $save ) {
			$out = $save->execute(
				array(
					'name'  => 'From the tool',
					'steps' => 'Steps.',
				)
			);
			$this->assertNotWPError( $out );
			$this->assertFalse( $out['on'] );
		}
	}

	/* -- the owner's screen ----------------------------------------------- */

	public function test_the_screen_adds_edits_switches_and_deletes() {
		$add = new \WP_REST_Request( 'POST', '/agentimus/v1/skills' );
		$add->set_body_params( array( 'name' => 'Rule', 'when' => 'Always', 'steps' => 'Be brief.' ) );
		$res = rest_do_request( $add );
		$this->assertSame( 200, $res->get_status() );
		$id = $res->get_data()['skills'][0]['id'];
		$this->assertTrue( $res->get_data()['skills'][0]['on'] );

		$edit = new \WP_REST_Request( 'PUT', '/agentimus/v1/skills/' . $id );
		$edit->set_body_params( array( 'name' => 'Rule', 'when' => 'Always', 'steps' => 'Be briefer.' ) );
		$this->assertSame( 'Be briefer.', rest_do_request( $edit )->get_data()['skills'][0]['steps'] );

		$off = new \WP_REST_Request( 'PUT', '/agentimus/v1/skills/' . $id );
		$off->set_body_params( array( 'on' => false ) );
		$this->assertFalse( rest_do_request( $off )->get_data()['skills'][0]['on'] );

		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'DELETE', '/agentimus/v1/skills/' . $id ) )->get_status() );
		$this->assertSame( array(), Skills::all() );
	}

	public function test_editing_an_agents_skill_makes_it_the_owners() {
		Skills::save_from_agent( 'Agent rule', '', 'Steps.' );
		$row   = $this->by_name( 'Agent rule' );
		$saved = Skills::save_from_owner( $row['id'], 'Agent rule', '', 'Better steps.', true );
		$this->assertSame( 'person', $saved['by'] );
		$this->assertTrue( $saved['on'] );
	}

	public function test_who_may_not_use_the_screen() {
		$body = array( 'name' => 'Sneaky', 'steps' => 'Do it.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$req = new \WP_REST_Request( 'POST', '/agentimus/v1/skills' );
		$req->set_body_params( $body );
		$this->assertContains( rest_do_request( $req )->get_status(), array( 401, 403 ), 'An editor may not.' );

		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_application_password_uuid'] = 'test-uuid';
		$req = new \WP_REST_Request( 'POST', '/agentimus/v1/skills' );
		$req->set_body_params( $body );
		$status = rest_do_request( $req )->get_status();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		if ( function_exists( 'rest_get_authenticated_app_password' ) ) {
			$this->assertContains( $status, array( 401, 403 ), 'An application password may not.' );
		}

		$token = McpToken::create( 'write', $this->admin );
		$_SERVER['REQUEST_URI']        = '/wp-json/agentimus/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->assertSame( $this->admin, McpToken::authenticate( false ), 'The token must really sign in, or this proves nothing.' );
		$req = new \WP_REST_Request( 'POST', '/agentimus/v1/skills' );
		$req->set_body_params( $body );
		$this->assertSame( 403, rest_do_request( $req )->get_status(), 'An agent may not.' );

		$this->assertSame( array(), Skills::all() );
	}

	public function test_skills_are_private() {
		$type = get_post_type_object( Skills::POST_TYPE );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->show_in_rest );
		$this->assertFalse( $type->publicly_queryable );
	}
}
