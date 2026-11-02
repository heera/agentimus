<?php
/**
 * Stage 2, step 4: an agent can move one item to the trash and bring one back.
 *
 * Never permanent delete, and no empty-the-trash. It takes a live page down, so it is
 * one item at a time, ten an hour across the site, and it won't touch the homepage or
 * the blog page. Restore always comes back as a draft: going live again is the publish
 * rule's decision, even on a site whose filter would restore the old status.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\ContentList;
use Agentimus\Abilities\ContentTrash;
use Agentimus\Abilities\Registrar;
use Agentimus\AgentAccess\Store;
use Agentimus\AgentAccess\Table;
use Agentimus\Settings;

final class ContentTrashDbTest extends DbTestCase {

	/** @var Settings */
	private $settings;

	/** @var int */
	private $admin;

	/** ⚠️ Once per class: installing inside a test can commit its transaction. */
	public static function wpSetUpBeforeClass() {
		Table::install();
	}

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->settings = new Settings();
		$this->switch_on( true );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function switch_on( $on ) {
		$all                         = $this->settings->stored();
		$all['enable_mcp_server']    = true;
		$all['enable_agent_writes']  = true;
		$all['agent_writes_content'] = $on;
		$this->settings->update( $all );
	}

	private function tool() {
		return new ContentTrash( $this->settings );
	}

	private function post( $status = 'publish', array $extra = array() ) {
		return (int) self::factory()->post->create( array_merge( array( 'post_title' => 'A Post', 'post_status' => $status ), $extra ) );
	}

	private function assert_refused( $code, $result ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/* -- switch and permission ------------------------------------------- */

	public function test_the_tools_need_the_switch_and_are_write_tools() {
		foreach ( array( 'trash-content', 'restore-content' ) as $slug ) {
			$this->switch_on( false );
			$this->assertNotContains( "agentimus/$slug", ( new Registrar( $this->settings ) )->mcp_abilities() );
			$this->switch_on( true );
			$this->assertContains( "agentimus/$slug", ( new Registrar( $this->settings ) )->mcp_abilities() );
			$this->assertTrue( Registrar::is_write_tool_name( "agentimus/$slug" ) );
		}
	}

	public function test_both_are_refused_with_the_switch_off() {
		$live    = $this->post();
		$trashed = $this->post();
		wp_trash_post( $trashed );
		$this->switch_on( false );
		$this->assert_refused( 'agentimus_content_off', $this->tool()->trash( array( 'post_id' => $live ) ) );
		$this->assert_refused( 'agentimus_content_off', $this->tool()->restore( array( 'post_id' => $trashed ) ) );
		$this->assertSame( 'publish', get_post_status( $live ) );
		$this->assertSame( 'trash', get_post_status( $trashed ) );
	}

	public function test_a_user_who_may_not_delete_it_is_refused() {
		$theirs = $this->post();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assert_refused( 'agentimus_cannot_trash', $this->tool()->trash( array( 'post_id' => $theirs ) ) );
		$this->assertSame( 'publish', get_post_status( $theirs ) );
		$this->assertFalse( ( new Registrar( $this->settings ) )->can_trash_content( array( 'post_id' => $theirs ) ) );

		wp_set_current_user( $this->admin );
		wp_trash_post( $theirs );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assert_refused( 'agentimus_cannot_trash', $this->tool()->restore( array( 'post_id' => $theirs ) ) );
		$this->assertSame( 'trash', get_post_status( $theirs ) );
	}

	public function test_the_permission_check_fails_closed() {
		$registrar = new Registrar( $this->settings );
		$this->assertTrue( $registrar->can_trash_content( array( 'post_id' => $this->post() ) ) );
		$this->assertFalse( $registrar->can_trash_content( array( 'post_id' => 999999 ) ) );
		$this->assertFalse( $registrar->can_trash_content( array() ) );
	}

	/* -- trash ------------------------------------------------------------ */

	public function test_it_moves_one_live_post_to_the_trash_and_can_never_delete() {
		$id  = $this->post();
		$out = $this->tool()->trash( array( 'post_id' => $id ) );
		$this->assertIsArray( $out );
		$this->assertSame( 'trash', $out['status'] );
		$this->assertSame( 'publish', $out['previousStatus'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertNotNull( get_post( $id ), 'It is still there.' );
		$this->assertNotSame( '', $out['message'] );
	}

	public function test_a_list_of_ids_is_not_accepted() {
		$one = $this->post();
		$two = $this->post();
		$this->assert_refused( 'agentimus_not_found', $this->tool()->trash( array( 'post_id' => array( $one, $two ) ) ) );
		$this->assertSame( 'publish', get_post_status( $one ) );
		$this->assertSame( 'publish', get_post_status( $two ) );
	}

	public function test_the_homepage_and_the_blog_page_are_refused() {
		$home = $this->post( 'publish', array( 'post_type' => 'page' ) );
		$blog = $this->post( 'publish', array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home );
		update_option( 'page_for_posts', $blog );
		foreach ( array( $home, $blog ) as $id ) {
			$this->assert_refused( 'agentimus_trash_front_page', $this->tool()->trash( array( 'post_id' => $id ) ) );
			$this->assertSame( 'publish', get_post_status( $id ) );
		}
	}

	public function test_a_page_left_in_the_option_while_the_site_shows_posts_is_a_normal_page() {
		$page = $this->post( 'publish', array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', $page );
		$this->assertIsArray( $this->tool()->trash( array( 'post_id' => $page ) ) );
	}

	public function test_a_post_someone_has_open_is_refused() {
		$id    = $this->post();
		$other = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Open Editor' ) );
		update_post_meta( $id, '_edit_lock', time() . ':' . $other );
		$result = $this->tool()->trash( array( 'post_id' => $id ) );
		$this->assert_refused( 'agentimus_post_locked', $result );
		$this->assertStringContainsString( 'Open Editor', $result->get_error_message() );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_what_is_already_in_the_trash_is_refused() {
		$id = $this->post();
		wp_trash_post( $id );
		$this->assert_refused( 'agentimus_already_trashed', $this->tool()->trash( array( 'post_id' => $id ) ) );
	}

	public function test_only_content_shared_with_agents_is_reachable() {
		$block = $this->post( 'publish', array( 'post_type' => 'wp_block' ) );
		$this->assert_refused( 'agentimus_bad_type', $this->tool()->trash( array( 'post_id' => $block ) ) );
		$this->assertSame( 'publish', get_post_status( $block ) );
		$media = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png' ) );
		$this->assert_refused( 'agentimus_bad_type', $this->tool()->trash( array( 'post_id' => $media ) ) );
		$this->assert_refused( 'agentimus_not_found', $this->tool()->trash( array( 'post_id' => 999999 ) ) );
	}

	public function test_a_trash_that_does_not_happen_is_not_reported_as_done() {
		$id   = $this->post();
		$stop = static function () {
			return false;
		};
		add_filter( 'pre_trash_post', $stop );
		$result = $this->tool()->trash( array( 'post_id' => $id ) );
		remove_filter( 'pre_trash_post', $stop );
		$this->assert_refused( 'agentimus_trash_failed', $result );
	}

	public function test_ten_an_hour_across_every_connection_and_refusals_spend_nothing() {
		$home = $this->post( 'publish', array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home );
		foreach ( range( 1, ContentTrash::HOURLY_LIMIT + 1 ) as $ignored ) {
			$this->assert_refused( 'agentimus_trash_front_page', $this->tool()->trash( array( 'post_id' => $home ) ) );
		}

		foreach ( range( 1, ContentTrash::HOURLY_LIMIT ) as $ignored ) {
			$this->assertIsArray( $this->tool()->trash( array( 'post_id' => $this->post() ) ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$next = $this->post();
		$this->assert_refused( 'agentimus_trash_rate_limited', $this->tool()->trash( array( 'post_id' => $next ) ) );
		$this->assertSame( 'publish', get_post_status( $next ), 'Another user is still the same site.' );
	}

	/* -- restore ---------------------------------------------------------- */

	public function test_a_live_post_comes_back_as_a_draft_and_says_what_it_was() {
		$id = $this->post();
		$this->tool()->trash( array( 'post_id' => $id ) );
		$out = $this->tool()->restore( array( 'post_id' => $id ) );
		$this->assertIsArray( $out );
		$this->assertSame( 'draft', $out['status'] );
		$this->assertSame( 'publish', $out['previousStatus'] );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertStringContainsString( 'publish', $out['message'] );
		$this->assertStringNotContainsString( '__trashed', get_post( $id )->post_name, 'Its slug comes back too.' );
	}

	public function test_a_site_filter_cannot_put_it_back_live_past_the_publish_switch() {
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		$id = $this->post();
		wp_trash_post( $id );
		$out = $this->tool()->restore( array( 'post_id' => $id ) );
		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		$this->assertSame( 'draft', $out['status'] );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_the_draft_rule_ends_with_the_call() {
		$id = $this->post();
		wp_trash_post( $id );
		$this->tool()->restore( array( 'post_id' => $id ) );

		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		$other = $this->post();
		wp_trash_post( $other );
		wp_untrash_post( $other );
		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		$this->assertSame( 'publish', get_post_status( $other ), 'The owner’s own restore is not ours to change.' );
	}

	public function test_only_what_is_in_the_trash_can_be_restored() {
		$id = $this->post();
		$this->assert_refused( 'agentimus_not_trashed', $this->tool()->restore( array( 'post_id' => $id ) ) );
		$this->assert_refused( 'agentimus_not_found', $this->tool()->restore( array( 'post_id' => 999999 ) ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_a_burst_of_trash_does_not_stop_the_restore_that_undoes_it() {
		$ids = array();
		foreach ( range( 1, ContentTrash::HOURLY_LIMIT ) as $ignored ) {
			$id = $this->post();
			$this->tool()->trash( array( 'post_id' => $id ) );
			$ids[] = $id;
		}
		foreach ( $ids as $id ) {
			$this->assertSame( 'draft', $this->tool()->restore( array( 'post_id' => $id ) )['status'] );
		}
	}

	/* -- the list and the record ------------------------------------------ */

	public function test_list_content_shows_what_a_trashed_item_was() {
		$id = $this->post();
		$this->tool()->trash( array( 'post_id' => $id ) );
		$items = ( new ContentList( $this->settings ) )->read( array( 'status' => 'trash' ) )['items'];
		$this->assertSame( array( $id ), wp_list_pluck( $items, 'id' ) );
		$this->assertSame( 'publish', $items[0]['statusBeforeTrash'] );

		$draft = $this->post( 'draft' );
		$items = ( new ContentList( $this->settings ) )->read( array( 'status' => 'draft' ) )['items'];
		$this->assertSame( array( $draft ), wp_list_pluck( $items, 'id' ) );
		$this->assertSame( '', $items[0]['statusBeforeTrash'] );
	}

	public function test_the_owner_can_see_each_one_in_agent_access() {
		$id = $this->post();
		$this->tool()->trash( array( 'post_id' => $id ) );
		$this->tool()->restore( array( 'post_id' => $id ) );

		$subjects = array();
		foreach ( Store::recent( 50, 'setting_changed' ) as $row ) {
			$subjects[] = ( (array) $row )['subject'];
		}
		$this->assertContains( "post $id trashed, was publish", $subjects );
		$this->assertContains( "post $id restored as draft", $subjects );
	}

	public function test_it_announces_through_the_same_seam_as_every_agent_write() {
		$seen = array();
		$spy  = static function ( $post_id, $action ) use ( &$seen ) {
			$seen[] = array( $post_id, $action );
		};
		add_action( 'agentimus_agent_wrote_content', $spy, 10, 2 );
		$id = $this->post();
		$this->tool()->trash( array( 'post_id' => $id ) );
		$this->tool()->restore( array( 'post_id' => $id ) );
		remove_action( 'agentimus_agent_wrote_content', $spy, 10 );
		$this->assertSame( array( array( $id, 'trash' ), array( $id, 'restore' ) ), $seen );
	}
}
