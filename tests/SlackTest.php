<?php
/**
 * Slack — the third service, a Block Kit formatter over the shared pipeline:
 *
 *   1. The FORMATTER. Every event becomes one Block Kit message — a section
 *      that says the thing, a context line that names the site — plus the
 *      plain fallback Slack shows in notifications. Shapes pinned per event.
 *   2. ESCAPING. Everything interpolated from site data rides through mrkdwn
 *      escaping (&, <, >), so a title containing an angle bracket can never
 *      become a link or an injected command.
 *   3. DELIVERY. One POST of { text, blocks } to the pasted URL; a non-2xx
 *      failure repeats Slack's own one-word verdict when it gave one.
 *   4. INERTNESS. Disconnected means wants() refuses and deliver() declines
 *      without a network call; the enabled flag collapses without a URL.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Dispatcher;
use Agentimus\Integrations\Events;
use Agentimus\Integrations\Services\Slack;
use PHPUnit\Framework\TestCase;

final class SlackTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_cron_events'] = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_cron_events'] );
	}

	/** A connected Slack: switched on with a URL — the URL is the whole credential. */
	private function connect( array $events = null ) {
		$stored                                     = isset( $GLOBALS['_af_options'][ Settings::OPTION ] ) ? $GLOBALS['_af_options'][ Settings::OPTION ] : array();
		$stored['integrations']                     = array_merge(
			isset( $stored['integrations'] ) ? (array) $stored['integrations'] : array(),
			array(
				'slack_enabled' => true,
				'slack_url'     => 'https://hooks.slack.test/services/T0/B0/x',
				'slack_events'  => null === $events ? Events::names() : $events,
			)
		);
		$GLOBALS['_af_options'][ Settings::OPTION ] = $stored;
	}

	private function settings() {
		return new Settings();
	}

	/** The last block of every message: the context line naming the site. */
	private function assertContextNamesTheSite( array $blocks ) {
		$context = end( $blocks );
		$this->assertSame( 'context', $context['type'] );
		$this->assertSame( 'example.test — Agentimus', $context['elements'][0]['text'] );
	}

	/* ---- the formatter (blocks per event) ---------------------------------- */

	public function test_finding_blocks_are_tier_title_and_a_link() {
		$blocks = Slack::blocks(
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

		$this->assertCount( 2, $blocks );
		$this->assertSame( 'section', $blocks[0]['type'] );
		$this->assertSame(
			"*New finding — urgent*\n" .
			"llms.txt is missing\n" .
			'<https://example.test/wp-admin/admin.php?page=agentimus#findings|Open the Findings screen>',
			$blocks[0]['text']['text']
		);
		$this->assertSame( 'mrkdwn', $blocks[0]['text']['type'] );
		$this->assertContextNamesTheSite( $blocks );
	}

	public function test_digest_blocks_carry_the_numbers_as_fields() {
		$blocks = Slack::blocks(
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

		$this->assertSame( '*Weekly digest — Aug 3 – Aug 9*', $blocks[0]['text']['text'] );
		$fields = $blocks[0]['fields'];
		$this->assertSame(
			array( "*Agent visits*\n41", "*AI referrals*\n9", "*Impostors caught*\n2", "*Score*\n88 (was 81)" ),
			array_column( $fields, 'text' )
		);
		$this->assertContextNamesTheSite( $blocks );
	}

	public function test_digest_blocks_omit_a_score_they_do_not_have() {
		$blocks = Slack::blocks(
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

		$this->assertSame( '*Weekly digest*', $blocks[0]['text']['text'], 'No label, no dash.' );
		$this->assertCount( 3, $blocks[0]['fields'], 'No score field without a score.' );
	}

	public function test_impostor_blocks_name_the_client() {
		$blocks = Slack::blocks(
			Events::IMPOSTOR_FLAGGED,
			Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) )
		);

		$this->assertSame(
			"*Impostor caught*\nA client calling itself “Googlebot” failed its operator’s check.",
			$blocks[0]['text']['text']
		);
	}

	public function test_robots_blocks_render_lines_as_code_and_skip_untouched_sides() {
		$blocks = Slack::blocks(
			Events::ROBOTS_CHANGED,
			Events::envelope(
				Events::ROBOTS_CHANGED,
				array( 'added' => array( 'disallow: /private', '+3 more' ), 'removed' => array(), 'at' => 1700000000 )
			)
		);

		$this->assertSame(
			"*robots.txt policy changed*\nAdded:\n`disallow: /private`\n`+3 more`",
			$blocks[0]['text']['text']
		);
	}

	public function test_citation_blocks_carry_the_counts_and_the_cap() {
		$capped = Slack::blocks(
			Events::CITATION_RUN_FINISHED,
			Events::envelope( Events::CITATION_RUN_FINISHED, array( 'runId' => 1, 'checks' => 1, 'capped' => true ) )
		);
		$this->assertSame(
			"*Citation check finished*\n1 check run. The run stopped at its cap.",
			$capped[0]['text']['text']
		);
	}

	public function test_wrote_blocks_are_action_title_and_tool() {
		$blocks = Slack::blocks(
			Events::AGENT_WROTE_CONTENT,
			Events::envelope(
				Events::AGENT_WROTE_CONTENT,
				array( 'postId' => 7, 'title' => 'Hello', 'action' => 'update', 'ability' => 'agentimus/update-content' )
			)
		);
		$this->assertSame(
			"*Content updated by an AI assistant*\n“Hello” — via agentimus/update-content",
			$blocks[0]['text']['text']
		);

		$created = Slack::blocks(
			Events::AGENT_WROTE_CONTENT,
			Events::envelope( Events::AGENT_WROTE_CONTENT, array( 'postId' => 7, 'title' => 'Hello', 'action' => 'create', 'ability' => '' ) )
		);
		$this->assertSame( "*Content created by an AI assistant*\n“Hello”", $created[0]['text']['text'] );
	}

	public function test_unknown_event_is_named_not_dropped() {
		$blocks = Slack::blocks( 'addon_event', Events::envelope( 'addon_event', array() ) );
		$this->assertSame( '*addon_event*', $blocks[0]['text']['text'] );
		$this->assertContextNamesTheSite( $blocks );
	}

	public function test_site_data_is_mrkdwn_escaped() {
		$blocks = Slack::blocks(
			Events::AGENT_WROTE_CONTENT,
			Events::envelope( Events::AGENT_WROTE_CONTENT, array( 'postId' => 7, 'title' => 'A <b> & B', 'action' => 'create', 'ability' => '' ) )
		);
		$this->assertStringContainsString( 'A &lt;b&gt; &amp; B', $blocks[0]['text']['text'], 'An angle bracket in a title must never become markup.' );
	}

	/* ---- the fallback ------------------------------------------------------- */

	public function test_fallback_is_one_plain_line_per_event() {
		$this->assertSame(
			'New finding (urgent): llms.txt is missing',
			Slack::fallback( Events::FINDING_OPENED, Events::envelope( Events::FINDING_OPENED, array( 'tier' => 'urgent', 'title' => 'llms.txt is missing' ) ) )
		);
		$this->assertSame(
			'Weekly digest: 41 agent visits, 9 AI referrals, 2 impostors.',
			Slack::fallback( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array( 'agents' => 41, 'referrals' => 9, 'impostors' => 2 ) ) )
		);
		$this->assertSame(
			'Impostor caught: Googlebot',
			Slack::fallback( Events::IMPOSTOR_FLAGGED, Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) ) )
		);
		$this->assertSame(
			'robots.txt policy changed',
			Slack::fallback( Events::ROBOTS_CHANGED, Events::envelope( Events::ROBOTS_CHANGED, array() ) )
		);
		$this->assertSame(
			'Citation check finished: 12 checks',
			Slack::fallback( Events::CITATION_RUN_FINISHED, Events::envelope( Events::CITATION_RUN_FINISHED, array( 'checks' => 12 ) ) )
		);
		$this->assertSame(
			'An AI assistant updated “Hello”',
			Slack::fallback( Events::AGENT_WROTE_CONTENT, Events::envelope( Events::AGENT_WROTE_CONTENT, array( 'title' => 'Hello', 'action' => 'update' ) ) )
		);
		$this->assertSame( 'addon_event', Slack::fallback( 'addon_event', Events::envelope( 'addon_event', array() ) ) );
	}

	/* ---- delivery ----------------------------------------------------------- */

	public function test_deliver_posts_text_and_blocks_to_the_url() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 200 ), 'body' => 'ok', 'headers' => array() ) );

		$verdict = Slack::deliver(
			Events::IMPOSTOR_FLAGGED,
			Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) )
		);

		$this->assertTrue( $verdict );
		$last = $GLOBALS['_af_http_last'];
		$this->assertSame( 'https://hooks.slack.test/services/T0/B0/x', $last['url'] );
		$this->assertSame( 5, $last['args']['timeout'] );
		$this->assertSame( 0, $last['args']['redirection'] );

		$body = json_decode( $last['args']['body'], true );
		$this->assertSame( 'Impostor caught: Googlebot', $body['text'] );
		$this->assertSame( 'section', $body['blocks'][0]['type'] );
	}

	public function test_delivery_failure_repeats_slacks_one_word_verdict() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 404 ), 'body' => 'no_service', 'headers' => array() ) );

		$verdict = Slack::deliver( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array() ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'Slack answered 404: no_service', $verdict->get_error_message() );
	}

	public function test_delivery_failure_with_an_unquotable_body_is_just_the_status() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 500 ), 'body' => '<html>Server Error</html>', 'headers' => array() ) );

		$verdict = Slack::deliver( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array() ) );

		$this->assertSame( 'Slack answered 500.', $verdict->get_error_message(), 'Markup never rides into the honesty line.' );
	}

	public function test_drain_routes_a_slack_row_to_slack() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 200 ), 'body' => 'ok', 'headers' => array() ) );

		$dispatcher = new Dispatcher( $this->settings() );
		$this->assertTrue( $dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array() ), Slack::ID ) );
		$dispatcher->drain();

		$this->assertSame( 0, $dispatcher->depth() );
		$this->assertSame( 'https://hooks.slack.test/services/T0/B0/x', $GLOBALS['_af_http_last']['url'] );
		$this->assertGreaterThan( 0, Slack::state()['lastDeliveredAt'] );
	}

	/* ---- inertness + settings ----------------------------------------------- */

	public function test_disconnected_slack_wants_nothing_and_delivers_nothing() {
		$settings = $this->settings();

		$this->assertFalse( Slack::connected( $settings ) );
		$this->assertFalse( Slack::wants( $settings, Events::DIGEST_SENT ) );

		$verdict = Slack::deliver( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array() ) );
		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_slack_unconfigured', $verdict->get_error_code() );
		$this->assertNull( $GLOBALS['_af_http_last'], 'Unconfigured = not one network call.' );
	}

	public function test_sanitize_collapses_enabled_without_a_url() {
		$clean = ( new Settings() )->sanitize(
			array(
				'integrations' => array(
					'slack_enabled' => true,
					'slack_url'     => '',
					'slack_events'  => array( 'digest_sent' ),
				),
			)
		);

		$this->assertFalse( $clean['integrations']['slack_enabled'], 'A connection with no URL cannot exist.' );
	}

	public function test_partial_save_without_the_key_keeps_the_stored_connection() {
		$this->connect();

		$clean = ( new Settings() )->sanitize( array( 'enable_llms_txt' => true ) );

		$this->assertTrue( $clean['integrations']['slack_enabled'] );
		$this->assertSame( 'https://hooks.slack.test/services/T0/B0/x', $clean['integrations']['slack_url'] );
	}
}
