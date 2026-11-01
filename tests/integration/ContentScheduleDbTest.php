<?php
/**
 * Stage 2, step 1: an agent can schedule a post and see what is waiting.
 *
 * Scheduling is publishing with a delay: nobody reviews the page at the moment it
 * goes live. So the time goes through the publish rule as well as its own switch,
 * and the traps here are WordPress's own: a past date on a scheduled post goes live
 * at once, a future date on a live post takes it offline, and status=publish on a
 * scheduled post quietly stays scheduled.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\ContentList;
use Agentimus\Abilities\ContentWriter;
use Agentimus\Abilities\Registrar;
use Agentimus\McpToken;
use Agentimus\Settings;

final class ContentScheduleDbTest extends DbTestCase {

	/** @var Settings */
	private $settings;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings = new Settings();
		$this->switches( true, true );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function switches( $content, $publish ) {
		$all                         = $this->settings->stored();
		$all['enable_mcp_server']    = true;
		$all['enable_agent_writes']  = true;
		$all['agent_writes_content'] = $content;
		$all['agent_writes_publish'] = $publish;
		$this->settings->update( $all );
	}

	private function writer() {
		return new ContentWriter( $this->settings );
	}

	/** An exact time, with an offset, some way ahead. */
	private function ahead( $seconds = DAY_IN_SECONDS, $offset = '+06:00' ) {
		$zone = new \DateTimeZone( $offset );
		return ( new \DateTimeImmutable( '@' . ( time() + $seconds ) ) )->setTimezone( $zone )->format( 'Y-m-d\TH:i:sP' );
	}

	private function post( $status = 'draft', array $extra = array() ) {
		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_title'  => 'A Post',
					'post_status' => $status,
				),
				$extra
			)
		);
	}

	private function assert_refused( $code, $result ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/* -- switches -------------------------------------------------------- */

	public function test_the_switch_is_off_by_default_and_falls_with_the_write_switch() {
		$this->assertFalse( $this->settings->defaults()['agent_writes_content'] );

		$all                        = $this->settings->stored();
		$all['enable_agent_writes'] = false;
		$this->settings->update( $all );
		$this->assertFalse( $this->settings->stored()['agent_writes_content'] );
	}

	public function test_list_content_needs_the_switch_and_a_write_scope() {
		$this->switches( false, true );
		$this->assertNotContains( 'agentimus/list-content', ( new Registrar( $this->settings ) )->mcp_abilities() );

		$this->switches( true, true );
		$this->assertContains( 'agentimus/list-content', ( new Registrar( $this->settings ) )->mcp_abilities() );
		$this->assertTrue( Registrar::is_write_tool_name( 'agentimus/list-content' ) );
	}

	public function test_a_read_only_token_cannot_list_even_though_the_tool_writes_nothing() {
		$registrar = new Registrar( $this->settings );
		$this->assertTrue( $registrar->can_list_content( array() ), 'The control: an admin with no token may list.' );

		// A token only counts on the MCP route.
		$uri                           = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$_SERVER['REQUEST_URI']        = '/wp-json/agentimus/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . McpToken::create( 'read', get_current_user_id() );
		McpToken::authenticate( false );
		$used      = McpToken::used_this_request();
		$read_only = $registrar->can_list_content( array() );

		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		McpToken::reset_request();
		if ( null === $uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $uri;
		}

		$this->assertTrue( $used, 'The token must really have authenticated, or this proves nothing.' );
		$this->assertFalse( $read_only );
	}

	public function test_scheduling_is_refused_with_the_content_switch_off() {
		$this->switches( false, true );
		$result = $this->writer()->create(
			array(
				'title'      => 'Refused',
				'publish_at' => $this->ahead(),
			)
		);
		$this->assert_refused( 'agentimus_content_off', $result );
		$this->assertSame( array(), get_posts( array( 'title' => 'Refused', 'post_status' => 'any' ) ), 'Nothing is written.' );

		$id = $this->post();
		$this->assert_refused( 'agentimus_content_off', $this->writer()->update( array( 'post_id' => $id, 'publish_at' => $this->ahead() ) ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_scheduling_is_refused_with_the_publish_switch_off() {
		$this->switches( true, false );
		$id = $this->post();
		$this->assert_refused( 'agentimus_publish_off', $this->writer()->update( array( 'post_id' => $id, 'publish_at' => $this->ahead() ) ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_scheduling_is_refused_without_the_publish_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assert_refused(
			'agentimus_cannot_publish',
			$this->writer()->create(
				array(
					'title'      => 'Refused',
					'publish_at' => $this->ahead(),
				)
			)
		);
	}

	/* -- scheduling ------------------------------------------------------ */

	public function test_create_schedules_at_the_exact_moment_sent() {
		$when   = $this->ahead();
		$result = $this->writer()->create(
			array(
				'title'      => 'Later',
				'publish_at' => $when,
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'future', $result['status'] );

		$post = get_post( $result['id'] );
		$this->assertSame( strtotime( $when ), strtotime( $post->post_date_gmt . ' UTC' ) );
		$this->assertSame( strtotime( $when ), strtotime( $result['scheduledFor'] ), 'The answer names the same moment.' );
		$this->assertSame( strtotime( $when ), (int) wp_next_scheduled( 'publish_future_post', array( $result['id'] ) ), 'WordPress holds a job to publish it.' );
	}

	public function test_a_scheduled_time_uses_the_site_timezone_for_the_local_date() {
		update_option( 'timezone_string', 'Asia/Dhaka' );
		$when   = $this->ahead( DAY_IN_SECONDS, '-04:00' );
		$result = $this->writer()->create(
			array(
				'title'      => 'Local',
				'publish_at' => $when,
			)
		);
		$post = get_post( $result['id'] );
		$this->assertSame( wp_date( 'Y-m-d H:i:s', strtotime( $when ) ), $post->post_date );
		$this->assertStringEndsWith( '+06:00', $result['scheduledFor'], 'The answer reads in the site’s own time.' );
		update_option( 'timezone_string', '' );
	}

	public function test_update_schedules_a_draft_and_moves_a_schedule() {
		$id     = $this->post();
		$first  = $this->ahead( DAY_IN_SECONDS );
		$result = $this->writer()->update( array( 'post_id' => $id, 'publish_at' => $first ) );
		$this->assertSame( 'future', $result['status'] );
		$this->assertSame( strtotime( $first ), (int) wp_next_scheduled( 'publish_future_post', array( $id ) ) );

		$second = $this->ahead( 2 * DAY_IN_SECONDS );
		$result = $this->writer()->update( array( 'post_id' => $id, 'publish_at' => $second ) );
		$this->assertSame( 'future', $result['status'] );
		$this->assertSame( strtotime( $second ), (int) wp_next_scheduled( 'publish_future_post', array( $id ) ), 'The old job is gone, the new time holds.' );
	}

	public function test_a_page_can_be_scheduled_too() {
		$result = $this->writer()->create(
			array(
				'type'       => 'page',
				'title'      => 'A Page',
				'publish_at' => $this->ahead(),
			)
		);
		$this->assertSame( 'future', $result['status'] );
		$this->assertSame( 'page', $result['type'] );
	}

	public function test_status_publish_alongside_a_time_means_schedule() {
		$result = $this->writer()->create(
			array(
				'title'      => 'Both',
				'status'     => 'publish',
				'publish_at' => $this->ahead(),
			)
		);
		$this->assertSame( 'future', $result['status'] );
	}

	public function test_scheduled_for_is_empty_when_nothing_is_scheduled() {
		$result = $this->writer()->create( array( 'title' => 'Plain draft' ) );
		$this->assertSame( '', $result['scheduledFor'] );
	}

	public function test_the_answer_declares_the_field_or_the_adapter_would_lose_it() {
		$method = new \ReflectionMethod( Registrar::class, 'written_post_schema' );
		$method->setAccessible( true );
		$this->assertArrayHasKey( 'scheduledFor', $method->invoke( null )['properties'] );
	}

	/* -- refusals -------------------------------------------------------- */

	public function test_a_past_or_immediate_time_is_refused_and_never_publishes() {
		$id = $this->post();
		foreach ( array( -DAY_IN_SECONDS, 0, 30 ) as $seconds ) {
			$this->assert_refused( 'agentimus_schedule_past', $this->writer()->update( array( 'post_id' => $id, 'publish_at' => $this->ahead( $seconds ) ) ) );
			$this->assertSame( 'draft', get_post_status( $id ), "$seconds seconds must not move the post." );
		}
	}

	public function test_a_time_without_an_offset_or_that_does_not_exist_is_refused() {
		$id = $this->post();
		foreach ( array( '', 'tomorrow', '2030-01-05 09:00:00', '2030-01-05T09:00:00', '2030-02-31T09:00:00+06:00', '2030-13-01T09:00:00Z', '2030-01-05T25:00:00Z' ) as $bad ) {
			$this->assert_refused( 'agentimus_bad_publish_at', $this->writer()->update( array( 'post_id' => $id, 'publish_at' => $bad ) ) );
			$this->assertSame( 'draft', get_post_status( $id ), "'$bad' must not move the post." );
		}
	}

	public function test_both_offset_spellings_and_minutes_only_are_accepted() {
		foreach ( array( '2099-01-05T09:00:00Z', '2099-01-05T09:00Z', '2099-01-05T15:00:00+06:00', '2099-01-05T15:00+06:00' ) as $good ) {
			$this->assertSame( gmmktime( 9, 0, 0, 1, 5, 2099 ), ContentWriter::parse_publish_at( $good ), $good );
		}
	}

	public function test_a_live_post_is_never_scheduled_because_that_takes_it_offline() {
		foreach ( array( 'publish', 'private' ) as $status ) {
			$id = $this->post( $status );
			$this->assert_refused( 'agentimus_schedule_live', $this->writer()->update( array( 'post_id' => $id, 'publish_at' => $this->ahead() ) ) );
			$this->assertSame( $status, get_post_status( $id ) );
		}
	}

	public function test_a_trashed_post_is_not_scheduled() {
		$id = $this->post();
		wp_trash_post( $id );
		$this->assert_refused( 'agentimus_schedule_live', $this->writer()->update( array( 'post_id' => $id, 'publish_at' => $this->ahead() ) ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	public function test_a_time_with_a_status_that_contradicts_it_is_refused() {
		foreach ( array( 'draft', 'pending' ) as $status ) {
			$id = $this->post();
			$this->assert_refused( 'agentimus_schedule_status', $this->writer()->update( array( 'post_id' => $id, 'status' => $status, 'publish_at' => $this->ahead() ) ) );
			$this->assertSame( 'draft', get_post_status( $id ) );
		}
	}

	public function test_a_schedule_that_does_not_hold_is_not_reported_as_scheduled() {
		$hold = static function ( $data ) {
			if ( 'future' === $data['post_status'] ) {
				$data['post_status'] = 'draft';
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $hold );
		$result = $this->writer()->create(
			array(
				'title'      => 'Held back',
				'publish_at' => $this->ahead(),
			)
		);
		remove_filter( 'wp_insert_post_data', $hold );
		$this->assert_refused( 'agentimus_schedule_failed', $result );
	}

	/* -- leaving a schedule ---------------------------------------------- */

	public function test_status_publish_on_a_scheduled_post_goes_live_now() {
		$id     = $this->post( 'future', array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$result = $this->writer()->update( array( 'post_id' => $id, 'status' => 'publish' ) );
		$this->assertSame( 'publish', $result['status'], 'Asked to publish; WordPress alone would keep it scheduled.' );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertLessThanOrEqual( time() + 5, strtotime( get_post( $id )->post_date_gmt . ' UTC' ) );
		$this->assertFalse( wp_next_scheduled( 'publish_future_post', array( $id ) ), 'No job left to fire later.' );
		$this->assertSame( '', $result['scheduledFor'] );
	}

	public function test_going_live_now_still_needs_the_publish_switch() {
		$this->switches( true, false );
		$id = $this->post( 'future', array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$this->assert_refused( 'agentimus_publish_off', $this->writer()->update( array( 'post_id' => $id, 'status' => 'publish' ) ) );
		$this->assertSame( 'future', get_post_status( $id ) );
	}

	public function test_a_draft_left_with_a_future_date_also_goes_live_now() {
		$id = $this->post( 'future', array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$this->writer()->update( array( 'post_id' => $id, 'status' => 'draft' ) );
		$this->assertSame( 'draft', get_post_status( $id ), 'Moving to draft is how a schedule is cancelled.' );

		$result = $this->writer()->update( array( 'post_id' => $id, 'status' => 'publish' ) );
		$this->assertSame( 'publish', $result['status'] );
	}

	public function test_editing_a_scheduled_post_keeps_its_time() {
		$when   = $this->ahead();
		$id     = $this->writer()->create( array( 'title' => 'Keep', 'publish_at' => $when ) )['id'];
		$result = $this->writer()->update( array( 'post_id' => $id, 'title' => 'Kept' ) );
		$this->assertSame( 'future', $result['status'] );
		$this->assertSame( strtotime( $when ), strtotime( $result['scheduledFor'] ) );
	}

	/* -- list-content ---------------------------------------------------- */

	private function lister() {
		return new ContentList( $this->settings );
	}

	public function test_listing_is_refused_with_the_switch_off() {
		$this->switches( false, true );
		$this->assert_refused( 'agentimus_content_off', $this->lister()->read( array() ) );
	}

	public function test_it_lists_what_is_not_live_and_nothing_that_is() {
		$draft   = $this->post( 'draft' );
		$pending = $this->post( 'pending' );
		$trashed = $this->post( 'publish' );
		wp_trash_post( $trashed );
		$future = $this->writer()->create( array( 'title' => 'Later', 'publish_at' => $this->ahead() ) )['id'];
		$live   = $this->post( 'publish' );
		$secret = $this->post( 'private' );

		$ids = wp_list_pluck( $this->lister()->read( array() )['items'], 'id' );
		foreach ( array( $draft, $pending, $trashed, $future ) as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertNotContains( $live, $ids );
		$this->assertNotContains( $secret, $ids );
	}

	public function test_it_narrows_to_one_status() {
		$draft  = $this->post( 'draft' );
		$future = $this->writer()->create( array( 'title' => 'Later', 'publish_at' => $this->ahead() ) )['id'];

		$items = $this->lister()->read( array( 'status' => 'future' ) )['items'];
		$this->assertSame( array( $future ), wp_list_pluck( $items, 'id' ) );
		$this->assertNotContains( $draft, wp_list_pluck( $items, 'id' ) );
	}

	public function test_a_status_it_does_not_list_is_refused() {
		$this->assert_refused( 'agentimus_bad_status', $this->lister()->read( array( 'status' => 'publish' ) ) );
	}

	public function test_scheduled_items_come_soonest_first_and_carry_their_time() {
		$later  = $this->writer()->create( array( 'title' => 'Later', 'publish_at' => $this->ahead( 3 * DAY_IN_SECONDS ) ) );
		$sooner = $this->writer()->create( array( 'title' => 'Sooner', 'publish_at' => $this->ahead( DAY_IN_SECONDS ) ) );

		$items = $this->lister()->read( array( 'status' => 'future' ) )['items'];
		$this->assertSame( array( $sooner['id'], $later['id'] ), wp_list_pluck( $items, 'id' ) );
		$this->assertSame( $sooner['scheduledFor'], $items[0]['scheduledFor'] );
		$this->assertFalse( $items[0]['missedSchedule'] );
	}

	public function test_a_scheduled_post_past_its_time_says_it_missed() {
		global $wpdb;
		$id = $this->writer()->create( array( 'title' => 'Late', 'publish_at' => $this->ahead() ) )['id'];
		// phpcs:ignore WordPress.DB
		$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'ID' => $id ) );
		clean_post_cache( $id );

		$items = $this->lister()->read( array( 'status' => 'future' ) )['items'];
		$this->assertTrue( $items[0]['missedSchedule'], 'A dead cron leaves it here. The list must say so.' );
	}

	public function test_a_new_scheduled_post_does_not_claim_a_save_in_the_future() {
		// WordPress stamps a NEW post's modified time from its date, so a post
		// scheduled at creation reads as last saved on the day it goes live.
		$this->writer()->create( array( 'title' => 'Later', 'publish_at' => $this->ahead( 30 * DAY_IN_SECONDS ) ) );

		$items = $this->lister()->read( array( 'status' => 'future' ) )['items'];
		$this->assertLessThanOrEqual( time() + 5, strtotime( $items[0]['modified'] . ' UTC' ), 'Nothing can have been saved in the future.' );
	}

	public function test_a_real_save_time_is_reported_unchanged() {
		global $wpdb;
		$id = $this->post( 'draft' );
		// phpcs:ignore WordPress.DB
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2020-03-04 05:06:07' ), array( 'ID' => $id ) );
		clean_post_cache( $id );

		$items = $this->lister()->read( array( 'status' => 'draft' ) )['items'];
		$this->assertSame( '2020-03-04 05:06:07', $items[0]['modified'] );
	}

	public function test_paging_and_totals_are_honest() {
		foreach ( range( 1, 3 ) as $ignored ) {
			$this->post( 'draft' );
		}
		$out = $this->lister()->read( array( 'status' => 'draft', 'per' => 2, 'page' => 2 ) );
		$this->assertSame( 3, $out['total'] );
		$this->assertSame( 2, $out['pages'] );
		$this->assertSame( 2, $out['page'] );
		$this->assertCount( 1, $out['items'] );
	}

	public function test_per_is_capped() {
		$this->assertSame( ContentList::MAX_PER, $this->lister()->read( array( 'per' => 5000 ) )['per'] );
	}

	public function test_a_type_not_shared_with_agents_is_refused() {
		$this->assert_refused( 'agentimus_bad_type', $this->lister()->read( array( 'type' => 'attachment' ) ) );
	}

	public function test_an_author_sees_their_own_items_and_nobody_elses() {
		$mine   = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$own    = $this->post( 'draft', array( 'post_author' => $mine ) );
		$theirs = $this->post( 'draft', array( 'post_author' => $other ) );

		wp_set_current_user( $mine );
		$ids = wp_list_pluck( $this->lister()->read( array( 'status' => 'draft' ) )['items'], 'id' );
		$this->assertContains( $own, $ids );
		$this->assertNotContains( $theirs, $ids );
		$this->assertSame( 1, $this->lister()->read( array( 'status' => 'draft' ) )['total'], 'The total must not count what the list hides.' );
	}
}
