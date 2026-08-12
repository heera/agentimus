<?php
/**
 * The private feed — the one service that never calls anybody:
 *
 *   1. THE TOKEN. Minted like the MCP connection token: plaintext returned
 *      once, only a SHA-256 fingerprint stored, rotation kills every old URL,
 *      the check is constant-time.
 *   2. THE DOOR. No token, a wrong token and a feed that is off all get the
 *      SAME bare 401 — never a hint the feed exists behind it.
 *   3. THE DOCUMENTS. RSS 2.0 parses and carries the channel + item shapes;
 *      JSON Feed 1.1 carries version, title and per-item id + content_text.
 *      Site data is escaped, so a title can never become markup.
 *   4. THE RING. Bounded at RING_MAX, oldest rows yield to the newest, and
 *      deliver() is an option write — ZERO network, connected or not.
 *   5. THE LAW. Own-site report data only: a payload key the formatter never
 *      learned (an IP, a UA) can never ride into either document.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Dispatcher;
use Agentimus\Integrations\Events;
use Agentimus\Integrations\Rest;
use Agentimus\Integrations\Services\Feed;
use PHPUnit\Framework\TestCase;

final class FeedTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_cron_events'] = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_cron_events'] );
	}

	/** A connected feed: switched on with a minted token. Returns the plaintext. */
	private function connect( array $events = null ) {
		$stored                                     = isset( $GLOBALS['_af_options'][ Settings::OPTION ] ) ? $GLOBALS['_af_options'][ Settings::OPTION ] : array();
		$stored['integrations']                     = array_merge(
			isset( $stored['integrations'] ) ? (array) $stored['integrations'] : array(),
			array(
				'feed_enabled' => true,
				'feed_events'  => null === $events ? Events::names() : $events,
			)
		);
		$GLOBALS['_af_options'][ Settings::OPTION ] = $stored;
		return Feed::mint_token();
	}

	private function settings() {
		return new Settings();
	}

	private function rest() {
		return new Rest( new Settings() );
	}

	private function feed_request( $token = null, $format = null ) {
		$request = new \WP_REST_Request( 'GET', '/agentimus/v1/integrations/feed' );
		if ( null !== $token ) {
			$request->set_param( 'token', $token );
		}
		if ( null !== $format ) {
			$request->set_param( 'format', $format );
		}
		return $request;
	}

	/** Pin the findings the handler folds in, so the fetch tests are exact. */
	private function pin_findings( array $rows ) {
		add_filter(
			'agentimus_findings',
			static function () use ( $rows ) {
				return array(
					'findings' => $rows,
					'resolved' => null,
					'clear'    => array(),
					'failed'   => array(),
					'counts'   => array(),
				);
			}
		);
	}

	/** Serve the RSS branch's body the way the REST server would. */
	private function serve_rss() {
		ob_start();
		apply_filters( 'rest_pre_serve_request', false );
		return (string) ob_get_clean();
	}

	/* ---- the token ---------------------------------------------------------- */

	public function test_token_is_prefixed_shown_once_and_stored_hashed() {
		$token = Feed::mint_token();

		$this->assertMatchesRegularExpression( '/^agfeed_[0-9a-f]{40}$/', $token, 'The prefix makes leaked-secret scanning possible.' );

		$stored = $GLOBALS['_af_options'][ Feed::TOKEN_OPTION ];
		$this->assertSame( hash( 'sha256', $token ), $stored['hash'] );
		$this->assertStringNotContainsString( $token, wp_json_encode( $stored ), 'The plaintext is never stored.' );

		$this->assertTrue( Feed::has_token() );
		$this->assertTrue( Feed::verify_token( $token ) );
		$this->assertFalse( Feed::verify_token( 'agfeed_' . str_repeat( '0', 40 ) ) );
		$this->assertFalse( Feed::verify_token( '' ), 'An empty presentation never matches anything.' );
	}

	public function test_rotation_kills_the_old_token_and_keeps_the_fetch_history() {
		$old = Feed::mint_token();
		Feed::record_fetch();
		$fetched = Feed::last_fetched_at();
		$this->assertGreaterThan( 0, $fetched );

		$new = Feed::mint_token();

		$this->assertFalse( Feed::verify_token( $old ), 'Every reader holding the old URL is out at once.' );
		$this->assertTrue( Feed::verify_token( $new ) );
		$this->assertSame( $fetched, Feed::last_fetched_at(), 'Rotation changes the key, not the feed\'s history.' );
	}

	public function test_the_url_carries_the_token_to_the_feed_route() {
		$this->assertSame(
			'https://example.test/wp-json/agentimus/v1/integrations/feed?token=agfeed_ab12',
			Feed::url( 'agfeed_ab12' )
		);
	}

	/* ---- the door ----------------------------------------------------------- */

	public function test_every_wrong_way_in_gets_the_same_bare_401() {
		$token = $this->connect();

		$no_token    = $this->rest()->feed( $this->feed_request() );
		$wrong_token = $this->rest()->feed( $this->feed_request( 'agfeed_' . str_repeat( '0', 40 ) ) );

		// Off switch thrown, the once-right token still presented.
		$stored = $GLOBALS['_af_options'][ Settings::OPTION ];
		$stored['integrations']['feed_enabled'] = false;
		$GLOBALS['_af_options'][ Settings::OPTION ] = $stored;
		$feed_off = $this->rest()->feed( $this->feed_request( $token ) );

		foreach ( array( 'no token' => $no_token, 'wrong token' => $wrong_token, 'feed off' => $feed_off ) as $way => $verdict ) {
			$this->assertInstanceOf( \WP_Error::class, $verdict, $way );
			$this->assertSame( 'agentimus_unauthorized', $verdict->get_error_code(), $way );
			$this->assertSame( 'Unauthorized.', $verdict->get_error_message(), $way . ' — one sentence for every refusal, no hint beyond it.' );
			$this->assertSame( array( 'status' => 401 ), $verdict->get_error_data(), $way );
		}

		$this->assertSame( 0, Feed::last_fetched_at(), 'A refused knock is not a fetch.' );
	}

	/* ---- the authorized fetch ----------------------------------------------- */

	public function test_authorized_fetch_renders_rss_and_records_the_fetch() {
		$token = $this->connect();
		Feed::deliver( Events::IMPOSTOR_FLAGGED, Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) ) );
		$this->pin_findings(
			array(
				array( 'id' => 'llms_missing', 'tier' => 'urgent', 'title' => 'llms.txt is missing', 'why' => 'Assistants look for it first.' ),
				array( 'id' => 'search_wait', 'tier' => 'waiting', 'title' => 'Waiting on the next crawl', 'why' => '' ),
			)
		);

		$response = $this->rest()->feed( $this->feed_request( $token ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$headers = $response->get_headers();
		$this->assertSame( 'application/rss+xml; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'private, no-store', $headers['Cache-Control'], 'The URL is a key: no shared cache may keep what it unlocks.' );

		$xml = $this->serve_rss();
		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc, 'The document parses.' );
		$this->assertSame( '2.0', (string) $doc['version'] );
		$this->assertSame( 'example.test — Agentimus', (string) $doc->channel->title );
		$this->assertSame( 'https://example.test/', (string) $doc->channel->link );
		$this->assertNotSame( '', (string) $doc->channel->description );

		$items = $doc->channel->item;
		$this->assertCount( 2, $items, 'One event, one OPEN finding — the waiting row is the tier built for finished work.' );

		$this->assertSame( 'A client calling itself “Googlebot” failed its operator’s check.', (string) $items[0]->title );
		$this->assertSame( 'impostor_flagged', (string) $items[0]->category );
		$this->assertSame( 'false', (string) $items[0]->guid['isPermaLink'] );
		$this->assertNotSame( '', (string) $items[0]->pubDate, 'An event carries its moment.' );

		$this->assertSame( 'llms.txt is missing', (string) $items[1]->title );
		$this->assertSame( 'Assistants look for it first.', (string) $items[1]->description );
		$this->assertSame( 'finding:llms_missing', (string) $items[1]->guid, 'Stable id — a reader that has seen it keeps it read.' );
		$this->assertCount( 0, $items[1]->pubDate, 'A finding is a state, not a moment — no invented date.' );
		$this->assertSame( array( 'finding', 'urgent' ), array( (string) $items[1]->category[0], (string) $items[1]->category[1] ) );
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=agentimus#findings', (string) $items[1]->link );

		$this->assertGreaterThan( 0, Feed::last_fetched_at(), 'The honesty line records the fetch.' );
	}

	public function test_json_feed_answers_the_1_1_shape() {
		$token = $this->connect();
		Feed::deliver(
			Events::ROBOTS_CHANGED,
			Events::envelope( Events::ROBOTS_CHANGED, array( 'added' => array( 'disallow: /private' ), 'removed' => array(), 'at' => 1700000000 ) )
		);
		$this->pin_findings( array( array( 'id' => 'llms_missing', 'tier' => 'urgent', 'title' => 'llms.txt is missing', 'why' => 'Assistants look for it first.' ) ) );

		$response = $this->rest()->feed( $this->feed_request( $token, 'json' ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 'application/feed+json; charset=utf-8', $response->get_headers()['Content-Type'] );

		$doc = $response->data;
		$this->assertSame( 'https://jsonfeed.org/version/1.1', $doc['version'] );
		$this->assertSame( 'example.test — Agentimus', $doc['title'] );
		$this->assertSame( 'https://example.test/', $doc['home_page_url'] );
		$this->assertCount( 2, $doc['items'] );

		$event = $doc['items'][0];
		$this->assertSame( 'robots.txt policy changed. Added: disallow: /private.', $event['title'] );
		$this->assertSame( $event['title'], $event['content_text'], 'content_text is required by the spec.' );
		$this->assertSame( 'https://example.test/robots.txt', $event['url'] );
		$this->assertSame( array( 'robots_policy_changed' ), $event['tags'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event['date_published'] );

		$finding = $doc['items'][1];
		$this->assertSame( 'finding:llms_missing', $finding['id'] );
		$this->assertSame( 'Assistants look for it first.', $finding['content_text'] );
		$this->assertSame( array( 'finding', 'urgent' ), $finding['tags'] );
		$this->assertArrayNotHasKey( 'date_published', $finding );
	}

	/* ---- the ring ----------------------------------------------------------- */

	public function test_the_ring_is_bounded_and_keeps_the_newest() {
		$this->connect();

		for ( $i = 1; $i <= Feed::RING_MAX + 10; $i++ ) {
			Feed::deliver( Events::IMPOSTOR_FLAGGED, Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Bot ' . $i ) ) );
		}

		$ring = Feed::ring();
		$this->assertCount( Feed::RING_MAX, $ring );
		$this->assertSame( 'Bot 11', $ring[0]['envelope']['data']['client'], 'The oldest rows yielded.' );
		$this->assertSame( 'Bot 60', $ring[ Feed::RING_MAX - 1 ]['envelope']['data']['client'], 'The newest survive.' );
	}

	public function test_items_put_the_newest_event_first() {
		$this->connect();
		Feed::deliver( Events::IMPOSTOR_FLAGGED, Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'First' ) ) );
		Feed::deliver( Events::IMPOSTOR_FLAGGED, Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Second' ) ) );

		$items = Feed::items( Feed::ring(), array() );

		$this->assertStringContainsString( 'Second', $items[0]['title'], 'A feed\'s front is its present.' );
		$this->assertStringContainsString( 'First', $items[1]['title'] );
	}

	public function test_drain_routes_a_feed_row_into_the_ring() {
		$this->connect();

		$dispatcher = new Dispatcher( $this->settings() );
		$this->assertTrue( $dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array() ), Feed::ID ) );
		$dispatcher->drain();

		$this->assertSame( 0, $dispatcher->depth() );
		$this->assertCount( 1, Feed::ring() );
		$this->assertGreaterThan( 0, Feed::state()['lastDeliveredAt'] );
	}

	/* ---- inertness + zero outbound ------------------------------------------ */

	public function test_disconnected_feed_wants_nothing_and_remembers_nothing() {
		$settings = $this->settings();

		$this->assertFalse( Feed::connected( $settings ) );
		$this->assertFalse( Feed::wants( $settings, Events::DIGEST_SENT ) );

		$verdict = Feed::deliver( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array() ) );
		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_feed_unconfigured', $verdict->get_error_code() );
		$this->assertSame( array(), Feed::ring(), 'Disconnected = not one remembered row.' );
	}

	public function test_enabled_without_a_token_is_not_connected() {
		$stored = array( 'integrations' => array( 'feed_enabled' => true, 'feed_events' => Events::names() ) );
		$GLOBALS['_af_options'][ Settings::OPTION ] = $stored;

		$this->assertFalse( Feed::connected( $this->settings() ), 'An "on" feed nobody could ever read is not a connection.' );
	}

	public function test_the_feed_never_makes_a_network_call() {
		$token = $this->connect();
		$this->pin_findings( array() );

		Feed::deliver( Events::IMPOSTOR_FLAGGED, Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) ) );
		$this->rest()->feed( $this->feed_request( $token ) );
		$this->serve_rss();
		$this->rest()->feed( $this->feed_request( $token, 'json' ) );

		$this->assertNull( $GLOBALS['_af_http_last'], 'PULL, not push: zero outbound, delivering and serving alike.' );
	}

	/* ---- the content law ---------------------------------------------------- */

	public function test_a_payload_key_the_formatter_never_learned_cannot_ride_into_the_feed() {
		$this->connect();
		// A hostile or buggy emitter smuggles visitor data beside the contract.
		Feed::deliver(
			Events::IMPOSTOR_FLAGGED,
			Events::envelope(
				Events::IMPOSTOR_FLAGGED,
				array(
					'client' => 'Googlebot',
					'ip'     => '203.0.113.9',
					'ua'     => 'Mozilla/5.0 (visitor fingerprint)',
				)
			)
		);

		$items = Feed::items( Feed::ring(), array() );
		$rss   = Feed::rss_document( $items );
		$json  = wp_json_encode( Feed::json_document( $items ) );

		$this->assertStringContainsString( 'Googlebot', $rss, 'The contract key rides.' );
		foreach ( array( '203.0.113.9', 'visitor fingerprint' ) as $smuggled ) {
			$this->assertStringNotContainsString( $smuggled, $rss, 'The formatter is explicit per event — unknown keys never leave.' );
			$this->assertStringNotContainsString( $smuggled, $json );
		}
	}

	public function test_site_data_is_xml_escaped_and_cannot_become_markup() {
		$this->connect();
		Feed::deliver(
			Events::AGENT_WROTE_CONTENT,
			Events::envelope(
				Events::AGENT_WROTE_CONTENT,
				array( 'postId' => 7, 'title' => 'A <script>alert("x")</script> & B', 'action' => 'create', 'ability' => '' )
			)
		);

		$rss = Feed::rss_document( Feed::items( Feed::ring(), array() ) );

		$this->assertStringNotContainsString( '<script>', $rss );
		$doc = simplexml_load_string( $rss );
		$this->assertNotFalse( $doc, 'Hostile titles still leave a parseable document.' );
		$this->assertStringContainsString( 'A <script>alert("x")</script> & B', (string) $doc->channel->item[0]->title, 'Escaped in transit, intact on arrival.' );
	}

	public function test_an_unknown_event_is_named_not_blank() {
		$this->connect();
		Feed::deliver( 'addon_event', Events::envelope( 'addon_event', array() ) );

		$items = Feed::items( Feed::ring(), array() );

		$this->assertSame( 'addon_event', $items[0]['title'], 'A feed item has no Event column beside it — the name is the only truth on hand.' );
		$this->assertSame( array( 'addon_event' ), $items[0]['categories'] );
	}

	/* ---- settings ----------------------------------------------------------- */

	public function test_partial_save_without_the_key_keeps_the_stored_connection() {
		$this->connect();

		$clean = ( new Settings() )->sanitize( array( 'enable_llms_txt' => true ) );

		$this->assertTrue( $clean['integrations']['feed_enabled'] );
		$this->assertSame( Events::names(), $clean['integrations']['feed_events'] );
	}

	public function test_event_names_are_validated_against_the_catalog() {
		$clean = ( new Settings() )->sanitize(
			array(
				'integrations' => array(
					'feed_enabled' => true,
					'feed_events'  => array( 'digest_sent', 'invented_event' ),
				),
			)
		);

		$this->assertSame( array( 'digest_sent' ), $clean['integrations']['feed_events'], 'An invented name can\'t linger.' );
	}
}
