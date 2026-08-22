<?php
/**
 * The three abilities that gave Announcements and Integrations their first
 * agent reach — and the two rules that decided their shape.
 *
 * ⛔ RULE ONE (his, 2026-08-22): an agent gets the actions the owner's own UI
 * does NOT confirm. Cancel, post-now and remove each raise a confirm dialog in
 * the admin, so none of them is an ability. Retry does not ask — it only
 * re-queues something the owner already scheduled and the network already
 * refused — so retry is the one write here.
 *
 * ⛔⛔ RULE TWO: read-integrations returns NO address and NO credential. A Slack
 * or Discord webhook URL is not a setting, it IS the power to post in that
 * channel; a Telegram chat id is the same next to a bot token. The admin
 * payload carries all of them, which is exactly why this ability is a
 * deliberate projection and never a reuse of it.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class AnnouncementAbilitiesDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		// ⛔ The write tier is registered only when BOTH switches are on, so
		// without this retry-announcement does not exist and its test SKIPS —
		// which is how the success path went unrun the first time round. A skip
		// is not a pass.
		// ⚠️ stored(), not all(): a partial update resets unset settings, and
		// all() would hand back anything a filter is overriding.
		$settings = new \Agentimus\Settings();
		$all      = $settings->stored();
		$all['enable_mcp_server']   = true;
		$all['enable_agent_writes'] = true;
		$settings->update( $all );
		// ⛔ NOT do_action( 'wp_abilities_api_init' ) — the hook already fired for
		// this request and re-firing double-registers every ability. The write
		// tier is therefore verified the way SetAsideDbTest does it: its BEHAVIOUR
		// through the class it wraps, and its EXPOSURE through mcp_abilities().
	}

	private function skip_without_abilities() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'No Abilities API on this WordPress — the ability half is correctly inert.' );
		}
	}

	private function as_admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
	}

	public function test_the_two_reads_are_registered_and_run() {
		$this->skip_without_abilities();
		$this->as_admin();

		foreach ( array( 'read-announcements', 'read-integrations' ) as $slug ) {
			$ability = wp_get_ability( 'agentimus/' . $slug );
			$this->assertNotNull( $ability, $slug . ' must be registered without the write switch.' );

			$out = $ability->execute( array() );
			$this->assertNotWPError( $out, $slug . ' must run for a site admin.' );
			$this->assertIsArray( $out );
		}
	}

	/**
	 * ⛔ A CONNECTED SERVICE MUST READ BACK WHOLE. Every field here was wrong in
	 * the first cut and every one of them failed SILENTLY, which is why this test
	 * connects something instead of asserting shape on an empty site:
	 *   · the class namespace was …\Integrations, not …\Integrations\Services,
	 *     so is_callable() said no five times and `services` came back EMPTY;
	 *   · state() returns an ARRAY, and was being cast to a string;
	 *   · lastError was read off config(), which has no such key;
	 *   · and `events` was declared as an array of OBJECTS, so the whole ability
	 *     returned a WP_Error the moment one service had an event set.
	 * An empty ledger and an unconnected site hide all four.
	 */
	public function test_a_connected_service_reads_back_whole() {
		$this->skip_without_abilities();
		$this->as_admin();

		$settings = new \Agentimus\Settings();
		$all      = $settings->stored();
		$all['integrations'] = array_merge(
			(array) ( isset( $all['integrations'] ) ? $all['integrations'] : array() ),
			array(
				'slack_url'     => 'https://hooks.slack.com/services/T00/B00/XXXSECRETXXX',
				'slack_enabled' => true,
				'slack_events'  => array( 'finding_opened' ),
			)
		);
		$settings->update( $all );
		\Agentimus\Integrations\Services\Slack::record_failure( 'Slack said: channel_not_found' );

		$out = wp_get_ability( 'agentimus/read-integrations' )->execute( array() );
		$this->assertNotWPError( $out, 'A populated events list must not fail output validation.' );

		$slack = null;
		foreach ( $out['services'] as $svc ) {
			if ( 'slack' === $svc['id'] ) {
				$slack = $svc;
			}
		}
		$this->assertNotNull( $slack, 'All five services must be listed — an empty list means the class map is wrong.' );
		$this->assertCount( 5, $out['services'] );

		$this->assertTrue( $slack['connected'], 'A URL is set, so this is connected.' );
		$this->assertTrue( $slack['enabled'] );
		$this->assertSame( array( 'finding_opened' ), $slack['events'] );
		$this->assertSame( 'Slack said: channel_not_found', $slack['lastError'], 'The failure reason is the point of this tool.' );
	}

	/**
	 * ⛔⛔ THE ONE THAT MATTERS. If this ever fails, an agent is being handed the
	 * means to post into someone's channel.
	 */
	public function test_read_integrations_never_returns_an_address_or_credential() {
		$this->skip_without_abilities();
		$this->as_admin();

		// Set a webhook URL and a Telegram chat, the way an owner would.
		$settings = new \Agentimus\Settings();
		$all      = $settings->stored();
		$all['webhook_url']  = 'https://hooks.example.com/services/SECRET-TOKEN-123';
		$all['telegram_chat'] = '-1001234567890';
		$settings->update( $all );

		$out  = wp_get_ability( 'agentimus/read-integrations' )->execute( array() );
		$json = (string) wp_json_encode( $out );

		$this->assertStringNotContainsString( 'SECRET-TOKEN-123', $json, 'A webhook URL reached an agent.' );
		$this->assertStringNotContainsString( 'hooks.example.com', $json, 'A webhook host reached an agent.' );
		$this->assertStringNotContainsString( '-1001234567890', $json, 'A Telegram chat id reached an agent.' );

		// It must still be USEFUL: the shape has to say whether things are set up.
		$this->assertArrayHasKey( 'services', $out );
		$this->assertArrayHasKey( 'plugins', $out );
		foreach ( $out['services'] as $svc ) {
			$this->assertArrayHasKey( 'connected', $svc );
			$this->assertArrayNotHasKey( 'url', $svc, 'The projection grew a url key.' );
			$this->assertArrayNotHasKey( 'chat', $svc, 'The projection grew a chat key.' );
			$this->assertArrayNotHasKey( 'token', $svc );
		}
	}

	/**
	 * Seed one FAILED row the way a real dispatch failure leaves it.
	 *
	 * @param int $post_id The post being announced.
	 * @return int The row id.
	 */
	private function seed_failed_row( $post_id ) {
		$rows = array(
			7 => array(
				'id'           => 7,
				'post_id'      => (int) $post_id,
				'network'      => 'telegram',
				'body'         => 'A body the owner already approved.',
				'scheduled_at' => time() - 600,
				'created_at'   => time() - 900,
				'status'       => 'failed',
				'sent_at'      => 0,
				'failed_at'    => time() - 300,
				'error'        => 'Telegram said: chat not found',
			),
		);
		update_option( \Agentimus\Integrations\Announcements::OPTION, $rows );
		return 7;
	}

	/**
	 * ⛔ THE PATH THAT MATTERED AND HAD NEVER RUN. The first cut of this ability
	 * read a 'title' key off the stored row — a row keeps `post_id` and `body`
	 * and has NO title — so every row would have come back titled "". An empty
	 * ledger hid it; only a seeded row shows it.
	 */
	public function test_a_failed_row_reads_back_whole_and_retries() {
		$this->skip_without_abilities();
		$this->as_admin();

		$post_id = self::factory()->post->create(
			array( 'post_title' => 'Php Dynamic Getter &#038; Setter', 'post_status' => 'publish' )
		);
		$this->seed_failed_row( $post_id );

		$out = wp_get_ability( 'agentimus/read-announcements' )->execute( array() );
		$this->assertNotWPError( $out );
		$this->assertCount( 1, $out['rows'], 'The seeded row must come back.' );

		$row = $out['rows'][0];
		$this->assertSame( 'telegram', $row['network'] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertTrue( $row['canRetry'], 'A failed row is the one thing retry accepts.' );
		$this->assertSame( 'Telegram said: chat not found', $row['error'], 'The reason is the whole point of this tool.' );
		$this->assertSame( (int) $post_id, $row['postId'] );
		// ⭐ Decoded through the one owner, so an agent never repeats `&#038;`.
		$this->assertSame( 'Php Dynamic Getter & Setter', $row['title'] );
		$this->assertNotSame( '', $row['scheduledAt'] );
		// ⛔ EVERY summary key asserted, not just the one that happened to be real.
		// `sent` was read off a key summary() does not have, so it sat at 0 beside a
		// ledger showing a sent row — and this test passed anyway, because it only
		// ever checked `failed`. Caught on his live site.
		$this->assertSame( 1, $out['summary']['failed'] );
		$this->assertSame( 1, $out['summary']['total'], 'The ledger holds one row.' );
		$this->assertSame( 0, $out['summary']['queued'] );
		$this->assertArrayHasKey( 'sentWeek', $out['summary'], 'summary() has sentWeek, never "sent".' );
		$this->assertArrayNotHasKey( 'sent', $out['summary'], 'An all-time "sent" is a number this site never computes.' );

		// The write the ability wraps, driven directly — the ability's body is a
		// thin call onto this plus a message.
		$done = ( new \Agentimus\Integrations\Announcements( new \Agentimus\Settings() ) )->retry( (string) $row['id'] );
		$this->assertNotWPError( $done, 'A failed row must be retryable.' );

		// ⭐ AND THE LEDGER AGREES AFTERWARDS. This is the assertion that would have
		// caught the title bug too: it reads the row back through the ability
		// rather than trusting what the write returned.
		$after = wp_get_ability( 'agentimus/read-announcements' )->execute( array() );
		$this->assertSame( 'queued', $after['rows'][0]['status'] );
		$this->assertFalse( $after['rows'][0]['canRetry'], 'A re-queued row must not offer retry again.' );
		$this->assertSame( 0, $after['summary']['failed'] );
		$this->assertSame( 1, $after['summary']['queued'], 'The retried row is now queued.' );
		$this->assertSame( 'Php Dynamic Getter & Setter', $after['rows'][0]['title'], 'The title must survive the write too.' );
	}

	/** ⛔ Retry refuses anything that is not a failed row — including nonsense ids. */
	public function test_retry_refuses_a_row_that_did_not_fail() {
		$this->skip_without_abilities();
		$this->as_admin();

		$ann = new \Agentimus\Integrations\Announcements( new \Agentimus\Settings() );
		$this->assertWPError( $ann->retry( 'no-such-row' ), 'An unknown id must be refused, never silently succeed.' );

		// ⭐ And the ability itself is EXPOSED to the write tier, not the read one —
		// a read-scoped key must never see it.
		$tools = ( new \Agentimus\Abilities\Registrar( new \Agentimus\Settings() ) )->mcp_abilities();
		$this->assertContains( 'agentimus/retry-announcement', $tools, 'Retry must reach the MCP surface when writes are on.' );
		$this->assertContains( 'agentimus/read-announcements', $tools );
		$this->assertContains( 'agentimus/read-integrations', $tools );
	}

	/**
	 * ⛔ THE LAW, pinned: the three confirmed actions must have no ability at all.
	 * If one appears here, someone gave an agent something the owner's own UI
	 * stops to ask about.
	 */
	public function test_the_confirmed_actions_have_no_ability() {
		$this->skip_without_abilities();

		// ⚠️ Asked of the REGISTERED LIST, not wp_get_ability(): looking up a name
		// that does not exist raises _doing_it_wrong, and the test case turns that
		// notice into a failure — so the honest check would fail for being right.
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'No ability registry to enumerate on this WordPress.' );
		}
		$names = array();
		foreach ( (array) wp_get_abilities() as $ability ) {
			$names[] = (string) $ability->get_name();
		}

		foreach ( array( 'cancel-announcement', 'remove-announcement', 'send-announcement', 'post-announcement' ) as $slug ) {
			$this->assertNotContains(
				'agentimus/' . $slug,
				$names,
				$slug . ' exists — but the admin confirms that action, so it must not be an ability.'
			);
		}

		// ⭐ And the positive half, so this test cannot pass by the registry simply
		// being empty: the ones that SHOULD be there are.
		$this->assertContains( 'agentimus/read-announcements', $names );
		$this->assertContains( 'agentimus/read-integrations', $names );
	}
}
