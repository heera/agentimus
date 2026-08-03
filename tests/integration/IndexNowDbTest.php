<?php
/**
 * IndexNow against a real WordPress — locks the promises the readme makes:
 * OFF by default sends nothing; ON, a publish/edit/removal queues exactly the
 * changed public URL and one flush ships it to api.indexnow.org with the
 * locally-minted key; a trashed post pings its PUBLIC URL, not the __trashed
 * spelling. The HTTP layer is short-circuited with pre_http_request — no test
 * ever leaves the machine.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\IndexNow;
use Agentimus\Settings;
use WP_UnitTestCase;

final class IndexNowDbTest extends WP_UnitTestCase {

	/** @var array<int,array{url:string,body:array}> Requests captured this test. */
	private $sent = array();

	public function set_up(): void {
		parent::set_up();
		IndexNow::reset();
		delete_option( IndexNow::OPTION );
		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	public function capture( $preempt, $args, $url ) {
		$this->sent[] = array(
			'url'  => (string) $url,
			'body' => json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true ),
		);
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	}

	private function turn_on() {
		$settings = new Settings();
		$all      = $settings->all(); // Partial updates reset unset settings — always merge into all().
		$all['indexnow_enabled'] = true;
		$settings->update( $all );
	}

	public function test_off_by_default_a_publish_sends_nothing() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		IndexNow::flush();

		$this->assertSame( array(), $this->sent, 'no outbound by default is the standing promise' );
	}

	public function test_a_publish_pings_the_permalink_with_the_minted_key() {
		$this->turn_on();
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		IndexNow::flush();

		$this->assertCount( 1, $this->sent, 'one flush, one ping' );
		$ping = $this->sent[0];
		$this->assertStringContainsString( 'api.indexnow.org', $ping['url'] );
		$this->assertContains( get_permalink( $id ), $ping['body']['urlList'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{32}$/', $ping['body']['key'], 'key is minted locally, 32 alnum' );
		$this->assertSame( home_url( '/' . $ping['body']['key'] . '.txt' ), $ping['body']['keyLocation'] );
		$this->assertSame( wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $ping['body']['host'] );

		$state = IndexNow::state();
		$this->assertSame( '', $state['lastError'] );
		$this->assertSame( 1, $state['lastUrls'] );
		$this->assertGreaterThan( 0, $state['lastAt'] );
	}

	public function test_a_trashed_post_pings_its_public_url_not_the_trashed_spelling() {
		$this->turn_on();
		$id        = self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'goodbye-post' ) );
		$permalink = get_permalink( $id );
		IndexNow::reset();
		$this->sent = array();

		wp_trash_post( $id );
		IndexNow::flush();

		$this->assertCount( 1, $this->sent );
		$urls = $this->sent[0]['body']['urlList'];
		$this->assertContains( $permalink, $urls, 'the dying PUBLIC URL is announced' );
		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( '__trashed', $url );
		}
	}

	public function test_a_draft_save_pings_nothing() {
		$this->turn_on();
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		IndexNow::flush();

		$this->assertSame( array(), $this->sent, 'nothing public changed' );
	}

	public function test_the_key_survives_and_is_reused() {
		$this->turn_on();
		$first = IndexNow::key();
		$this->assertSame( $first, IndexNow::key(), 'minted once, stable after' );
	}

	public function test_the_announce_action_queues_custom_table_content() {
		$this->turn_on();
		do_action( 'agentimus_announce_url', home_url( '/shop/handmade-attar/' ) );
		IndexNow::flush();

		$this->assertCount( 1, $this->sent, 'plugins with custom-table content ride the same queue' );
		$this->assertContains( home_url( '/shop/handmade-attar/' ), $this->sent[0]['body']['urlList'] );
	}

	public function test_the_announce_action_refuses_foreign_hosts_and_stays_off_when_disabled() {
		$this->turn_on();
		do_action( 'agentimus_announce_url', 'https://evil.example/not-ours/' );
		IndexNow::flush();
		$this->assertSame( array(), $this->sent, 'this site\'s key never vouches for another host' );

		$settings = new Settings();
		$all      = $settings->all();
		$all['indexnow_enabled'] = false;
		$settings->update( $all );
		do_action( 'agentimus_announce_url', home_url( '/shop/handmade-attar/' ) );
		IndexNow::flush();
		$this->assertSame( array(), $this->sent, 'the switch gates the seam too' );
	}
}
