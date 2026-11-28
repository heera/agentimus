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

	/**
	 * A live post, created with the switch on and the creation ping drained, so
	 * every test below sees only what its own change sends.
	 *
	 * @param array $args Extra post fields.
	 * @return int Post ID.
	 */
	private function live_post( array $args = array() ) {
		$this->turn_on();
		$id = self::factory()->post->create( array_merge( array( 'post_status' => 'publish' ), $args ) );
		IndexNow::flush();
		$this->sent = array();
		return $id;
	}

	/** @return string[] Every URL sent this test, across all pings. */
	private function sent_urls() {
		$urls = array();
		foreach ( $this->sent as $ping ) {
			$urls = array_merge( $urls, (array) $ping['body']['urlList'] );
		}
		return $urls;
	}

	/**
	 * The gap found on heera.it 2026-11-28: ten descriptions written through
	 * write-description changed every page's meta description, and Bing was
	 * told about none of them — a meta-only write never fires
	 * transition_post_status.
	 */
	public function test_a_description_change_on_a_live_post_pings_it() {
		$id = $this->live_post();

		update_post_meta( $id, \Agentimus\Description::META, 'A new description a search engine shows.' );
		IndexNow::flush();

		$this->assertContains( get_permalink( $id ), $this->sent_urls() );
	}

	public function test_a_search_title_change_on_a_live_post_pings_it() {
		$id = $this->live_post();

		update_post_meta( $id, \Agentimus\Seo::META_TITLE, 'A new search title' );
		IndexNow::flush();

		$this->assertContains( get_permalink( $id ), $this->sent_urls() );
	}

	public function test_a_cover_image_swap_on_a_live_post_pings_it() {
		$id    = $this->live_post();
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/a2-small.jpg' );

		set_post_thumbnail( $id, $image );
		IndexNow::flush();

		$this->assertContains( get_permalink( $id ), $this->sent_urls() );
	}

	/**
	 * Alt text lives on the image, not the page: describing a cover image
	 * changes every live page that wears it, and only those.
	 */
	public function test_new_alt_text_pings_every_live_post_that_uses_the_image() {
		$a     = $this->live_post();
		$b     = $this->live_post();
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/a2-small.jpg' );
		foreach ( array( $a, $b, $draft ) as $post ) {
			set_post_thumbnail( $post, $image );
		}
		IndexNow::flush();
		$this->sent = array();

		update_post_meta( $image, '_wp_attachment_image_alt', 'Five perfume bottles on brown velvet.' );
		IndexNow::flush();

		$urls = $this->sent_urls();
		$this->assertContains( get_permalink( $a ), $urls );
		$this->assertContains( get_permalink( $b ), $urls );
		$this->assertNotContains( get_permalink( $draft ), $urls, 'a draft has no public page to recrawl' );
	}

	public function test_editing_a_category_pings_its_archive() {
		$this->turn_on();
		$cat = self::factory()->category->create( array( 'name' => 'Perfumery' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		IndexNow::flush();
		$this->sent = array();

		wp_update_term( $cat, 'category', array( 'description' => 'Fragrance notes and reviews.' ) );
		IndexNow::flush();

		$this->assertContains( get_term_link( $cat, 'category' ), $this->sent_urls() );
	}

	public function test_page_meta_on_a_draft_pings_nothing() {
		$this->turn_on();
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		IndexNow::flush();
		$this->sent = array();

		update_post_meta( $id, \Agentimus\Description::META, 'Not public yet.' );
		IndexNow::flush();

		$this->assertSame( array(), $this->sent, 'nothing public changed' );
	}

	public function test_meta_that_never_reaches_the_page_pings_nothing() {
		$id = $this->live_post();

		update_post_meta( $id, '_edit_lock', time() . ':1' );
		update_post_meta( $id, 'some_other_plugins_counter', 42 );
		IndexNow::flush();

		$this->assertSame( array(), $this->sent, 'only meta that changes what an engine reads counts' );
	}

	public function test_page_meta_stays_silent_when_the_switch_is_off() {
		$id       = $this->live_post();
		$settings = new Settings();
		$all      = $settings->all();
		$all['indexnow_enabled'] = false;
		$settings->update( $all );

		update_post_meta( $id, \Agentimus\Description::META, 'Changed while off.' );
		IndexNow::flush();

		$this->assertSame( array(), $this->sent, 'no outbound by default is the standing promise' );
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
