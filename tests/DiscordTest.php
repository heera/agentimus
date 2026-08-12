<?php
/**
 * Discord — the fourth service, an embed formatter over the shared pipeline:
 *
 *   1. The FORMATTER. Every event becomes one embed — a titled card with the
 *      event's fact as its description and the site named in the footer.
 *      Shapes pinned per event; the title respects Discord's 256 ceiling.
 *   2. COLOUR DISCIPLINE. Alarm red is reserved for the two events that ARE
 *      alarms (an urgent finding, a caught impostor); everything else wears
 *      the one neutral tone, so colour keeps meaning something.
 *   3. DELIVERY. One POST of { embeds: [...] } to the pasted URL; Discord's
 *      204 No Content counts as delivered, and a failure repeats Discord's
 *      own message ("Unknown Webhook") when it gave one.
 *   4. INERTNESS. Disconnected means wants() refuses and deliver() declines
 *      without a network call; the enabled flag collapses without a URL.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Dispatcher;
use Agentimus\Integrations\Events;
use Agentimus\Integrations\Services\Discord;
use PHPUnit\Framework\TestCase;

final class DiscordTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_cron_events'] = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_cron_events'] );
	}

	/** A connected Discord: switched on with a URL — the URL is the whole credential. */
	private function connect( array $events = null ) {
		$stored                                     = isset( $GLOBALS['_af_options'][ Settings::OPTION ] ) ? $GLOBALS['_af_options'][ Settings::OPTION ] : array();
		$stored['integrations']                     = array_merge(
			isset( $stored['integrations'] ) ? (array) $stored['integrations'] : array(),
			array(
				'discord_enabled' => true,
				'discord_url'     => 'https://discord.test/api/webhooks/1/x',
				'discord_events'  => null === $events ? Events::names() : $events,
			)
		);
		$GLOBALS['_af_options'][ Settings::OPTION ] = $stored;
	}

	private function settings() {
		return new Settings();
	}

	/* ---- the formatter (one embed per event) ------------------------------- */

	public function test_finding_embed_is_tier_title_link_and_alarm_colour() {
		$embed = Discord::embed(
			Events::FINDING_OPENED,
			Events::envelope(
				Events::FINDING_OPENED,
				array(
					'tier'  => 'urgent',
					'title' => 'llms.txt is missing',
					'url'   => 'https://example.test/wp-admin/admin.php?page=agentimus#findings',
				)
			)
		);

		$this->assertSame( 'New finding — urgent', $embed['title'] );
		$this->assertSame( 'llms.txt is missing', $embed['description'] );
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=agentimus#findings', $embed['url'] );
		$this->assertSame( Discord::COLOR_ALERT, $embed['color'], 'Urgent wears the alarm colour.' );
		$this->assertSame( 'example.test', $embed['footer']['text'], 'The footer names the site.' );
	}

	public function test_a_lesser_finding_wears_the_neutral_colour() {
		$embed = Discord::embed(
			Events::FINDING_OPENED,
			Events::envelope( Events::FINDING_OPENED, array( 'tier' => 'worth', 'title' => 'x', 'url' => 'https://example.test/a' ) )
		);
		$this->assertSame( 'New finding — worth knowing', $embed['title'] );
		$this->assertSame( Discord::COLOR_NEUTRAL, $embed['color'] );
	}

	public function test_digest_embed_carries_the_numbers() {
		$embed = Discord::embed(
			Events::DIGEST_SENT,
			Events::envelope(
				Events::DIGEST_SENT,
				array(
					'period'    => array( 'label' => 'Aug 3 – Aug 9', 'days' => 7 ),
					'agents'    => 41,
					'referrals' => 9,
					'impostors' => 2,
					'score'     => array( 'now' => 88, 'prev' => 81 ),
				)
			)
		);

		$this->assertSame( 'Weekly digest — Aug 3 – Aug 9', $embed['title'] );
		$this->assertSame( "41 agent visits, 9 AI referrals, 2 impostors caught.\nScore 88 (was 81)", $embed['description'] );
		$this->assertSame( Discord::COLOR_NEUTRAL, $embed['color'] );
	}

	public function test_digest_embed_omits_a_score_it_does_not_have() {
		$embed = Discord::embed(
			Events::DIGEST_SENT,
			Events::envelope(
				Events::DIGEST_SENT,
				array(
					'period'    => array( 'label' => '', 'days' => 7 ),
					'agents'    => 1,
					'referrals' => 0,
					'impostors' => 0,
					'score'     => array( 'now' => null, 'prev' => null ),
				)
			)
		);

		$this->assertSame( 'Weekly digest', $embed['title'], 'No label, no dash.' );
		$this->assertSame( '1 agent visits, 0 AI referrals, 0 impostors caught.', $embed['description'] );
	}

	public function test_impostor_embed_names_the_client_in_alarm_colour() {
		$embed = Discord::embed(
			Events::IMPOSTOR_FLAGGED,
			Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) )
		);

		$this->assertSame( 'Impostor caught', $embed['title'] );
		$this->assertSame( 'A client calling itself “Googlebot” failed its operator’s check.', $embed['description'] );
		$this->assertSame( Discord::COLOR_ALERT, $embed['color'] );
	}

	public function test_robots_embed_lists_only_the_sides_that_moved() {
		$embed = Discord::embed(
			Events::ROBOTS_CHANGED,
			Events::envelope(
				Events::ROBOTS_CHANGED,
				array( 'added' => array( 'disallow: /private', '+3 more' ), 'removed' => array(), 'at' => 1700000000 )
			)
		);

		$this->assertSame( 'robots.txt policy changed', $embed['title'] );
		$this->assertSame( "Added:\ndisallow: /private\n+3 more", $embed['description'] );
		$this->assertStringNotContainsString( 'Removed:', $embed['description'], 'An untouched side gets no heading.' );
	}

	public function test_citation_embed_carries_the_counts_and_the_cap() {
		$embed = Discord::embed(
			Events::CITATION_RUN_FINISHED,
			Events::envelope( Events::CITATION_RUN_FINISHED, array( 'runId' => 1, 'checks' => 1, 'capped' => true ) )
		);
		$this->assertSame( 'Citation check finished', $embed['title'] );
		$this->assertSame( '1 check run. The run stopped at its cap.', $embed['description'] );
	}

	public function test_wrote_embed_is_action_title_and_tool() {
		$embed = Discord::embed(
			Events::AGENT_WROTE_CONTENT,
			Events::envelope(
				Events::AGENT_WROTE_CONTENT,
				array( 'postId' => 7, 'title' => 'Hello', 'action' => 'update', 'ability' => 'agentimus/update-content' )
			)
		);
		$this->assertSame( 'Content updated by an AI assistant', $embed['title'] );
		$this->assertSame( '“Hello” — via agentimus/update-content', $embed['description'] );

		$created = Discord::embed(
			Events::AGENT_WROTE_CONTENT,
			Events::envelope( Events::AGENT_WROTE_CONTENT, array( 'postId' => 7, 'title' => 'Hello', 'action' => 'create', 'ability' => '' ) )
		);
		$this->assertSame( 'Content created by an AI assistant', $created['title'] );
		$this->assertSame( '“Hello”', $created['description'] );
	}

	public function test_unknown_event_is_named_not_dropped() {
		$embed = Discord::embed( 'addon_event', Events::envelope( 'addon_event', array() ) );
		$this->assertSame( 'addon_event', $embed['title'] );
		$this->assertSame( Discord::COLOR_NEUTRAL, $embed['color'] );
	}

	public function test_title_respects_discords_ceiling() {
		$embed = Discord::embed( str_repeat( 'x', 300 ), Events::envelope( 'x', array() ) );
		$this->assertSame( 256, strlen( $embed['title'] ) );
	}

	/* ---- delivery ----------------------------------------------------------- */

	public function test_deliver_posts_one_embed_and_204_counts_as_delivered() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 204 ), 'body' => '', 'headers' => array() ) );

		$verdict = Discord::deliver(
			Events::IMPOSTOR_FLAGGED,
			Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) )
		);

		$this->assertTrue( $verdict, "204 No Content is Discord's own yes." );
		$last = $GLOBALS['_af_http_last'];
		$this->assertSame( 'https://discord.test/api/webhooks/1/x', $last['url'] );
		$this->assertSame( 5, $last['args']['timeout'] );
		$this->assertSame( 0, $last['args']['redirection'] );

		$body = json_decode( $last['args']['body'], true );
		$this->assertCount( 1, $body['embeds'] );
		$this->assertSame( 'Impostor caught', $body['embeds'][0]['title'] );
	}

	public function test_delivery_failure_repeats_discords_own_message() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 404 ), 'body' => '{"message": "Unknown Webhook", "code": 10015}', 'headers' => array() ),
		);

		$verdict = Discord::deliver( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array() ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'Discord answered 404: Unknown Webhook', $verdict->get_error_message() );
	}

	public function test_delivery_failure_without_a_message_is_just_the_status() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 500 ), 'body' => 'oops', 'headers' => array() ) );

		$verdict = Discord::deliver( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array() ) );

		$this->assertSame( 'Discord answered 500.', $verdict->get_error_message() );
	}

	public function test_drain_routes_a_discord_row_to_discord() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 204 ), 'body' => '', 'headers' => array() ) );

		$dispatcher = new Dispatcher( $this->settings() );
		$this->assertTrue( $dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array() ), Discord::ID ) );
		$dispatcher->drain();

		$this->assertSame( 0, $dispatcher->depth() );
		$this->assertSame( 'https://discord.test/api/webhooks/1/x', $GLOBALS['_af_http_last']['url'] );
		$this->assertGreaterThan( 0, Discord::state()['lastDeliveredAt'] );
	}

	/* ---- inertness + settings ----------------------------------------------- */

	public function test_disconnected_discord_wants_nothing_and_delivers_nothing() {
		$settings = $this->settings();

		$this->assertFalse( Discord::connected( $settings ) );
		$this->assertFalse( Discord::wants( $settings, Events::DIGEST_SENT ) );

		$verdict = Discord::deliver( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array() ) );
		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_discord_unconfigured', $verdict->get_error_code() );
		$this->assertNull( $GLOBALS['_af_http_last'], 'Unconfigured = not one network call.' );
	}

	public function test_sanitize_collapses_enabled_without_a_url() {
		$clean = ( new Settings() )->sanitize(
			array(
				'integrations' => array(
					'discord_enabled' => true,
					'discord_url'     => '',
					'discord_events'  => array( 'digest_sent' ),
				),
			)
		);

		$this->assertFalse( $clean['integrations']['discord_enabled'], 'A connection with no URL cannot exist.' );
	}

	public function test_partial_save_without_the_key_keeps_the_stored_connection() {
		$this->connect();

		$clean = ( new Settings() )->sanitize( array( 'enable_llms_txt' => true ) );

		$this->assertTrue( $clean['integrations']['discord_enabled'] );
		$this->assertSame( 'https://discord.test/api/webhooks/1/x', $clean['integrations']['discord_url'] );
	}

	public function test_all_four_services_fan_out_from_one_emit() {
		// Every service connected, all subscribed to robots changes.
		$stored = array(
			'integrations' => array(
				'webhook_enabled'  => true,
				'webhook_url'      => 'https://hooks.example.test/in',
				'webhook_events'   => array( Events::ROBOTS_CHANGED ),
				'telegram_enabled' => true,
				'telegram_chat'    => '123456789',
				'telegram_events'  => array( Events::ROBOTS_CHANGED ),
				'telegram_tier'    => 'all',
				'slack_enabled'    => true,
				'slack_url'        => 'https://hooks.slack.test/services/T0/B0/x',
				'slack_events'     => array( Events::ROBOTS_CHANGED ),
				'discord_enabled'  => true,
				'discord_url'      => 'https://discord.test/api/webhooks/1/x',
				'discord_events'   => array( Events::ROBOTS_CHANGED ),
			),
		);
		$GLOBALS['_af_options'][ Settings::OPTION ]                                             = $stored;
		$GLOBALS['_af_options']['agentimus_integrations_webhook_secret']                        = 'test-secret-0123456789abcdef';
		$GLOBALS['_af_options'][ \Agentimus\Integrations\Services\Telegram::TOKEN_OPTION ]      = '111:token-abc';

		( new Events( $this->settings() ) )->on_robots_changed( array( 'at' => time(), 'added' => array( 'disallow: /' ), 'removed' => array() ) );

		$dispatcher = new Dispatcher( $this->settings() );
		$this->assertSame( 4, $dispatcher->depth(), 'One moment, four rows — one per connected subscriber.' );
		$this->assertSame( 1, $dispatcher->depth_for( Discord::ID ) );
	}
}
