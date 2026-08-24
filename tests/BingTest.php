<?php
/**
 * Bing search source — the pure halves: WCF date parsing, host matching, and
 * the key's at-rest encryption with the verification code's survival across a
 * disconnect.
 *
 * @package Agentimus\Tests
 */

use Agentimus\Bing\Client;
use Agentimus\Bing\Rest;
use Agentimus\Bing\Settings;
use PHPUnit\Framework\TestCase;

final class BingTest extends TestCase {

	protected function setUp(): void {
		_af_reset_options();
	}

	// ── Client::wcf_date ────────────────────────────────────────────────────

	public function test_wcf_dates_parse_to_utc_days() {
		// 2026-07-01T00:00:00Z in milliseconds.
		$this->assertSame( '2026-07-01', Client::wcf_date( '/Date(1782864000000)/' ) );
		// A zone suffix must not break the parse — the day is taken from UTC.
		$this->assertSame( '2026-07-01', Client::wcf_date( '/Date(1782864000000-0700)/' ) );
	}

	public function test_garbage_dates_become_empty_not_wrong() {
		$this->assertSame( '', Client::wcf_date( '' ) );
		$this->assertSame( '', Client::wcf_date( '2026-07-01' ) );
		$this->assertSame( '', Client::wcf_date( '/Date(abc)/' ) );
	}

	// ── Rest::hosts_match ───────────────────────────────────────────────────

	public function test_hosts_match_tolerates_www_and_scheme() {
		$this->assertTrue( Rest::hosts_match( 'http://www.example.com/', 'https://example.com/' ) );
		$this->assertTrue( Rest::hosts_match( 'https://example.com', 'https://example.com/sub/' ) );
		$this->assertFalse( Rest::hosts_match( 'https://example.com/', 'https://example.org/' ) );
		// A subdomain is a DIFFERENT site — www is the only tolerated prefix.
		$this->assertFalse( Rest::hosts_match( 'https://blog.example.com/', 'https://example.com/' ) );
		$this->assertFalse( Rest::hosts_match( '', '' ) );
	}

	// ── Settings ────────────────────────────────────────────────────────────

	public function test_key_is_stored_encrypted_and_never_in_public_view() {
		$settings = new Settings();
		$settings->connect( 'plain-api-key-123', 'https://example.com/' );

		$stored = get_option( Settings::OPTION );
		$this->assertNotSame( 'plain-api-key-123', $stored['api_key'], 'the key must not sit in the option as plaintext' );
		$this->assertSame( 'plain-api-key-123', $settings->api_key(), 'decryption must round-trip' );

		$view = $settings->public_view();
		$this->assertTrue( $view['connected'] );
		$this->assertArrayNotHasKey( 'api_key', $view );
		$this->assertArrayNotHasKey( 'msvalidate', $view, 'the raw code stays server-side; the view carries only hasMsvalidate' );
	}

	public function test_disconnect_forgets_the_key_but_keeps_the_verification_code() {
		$settings = new Settings();
		$settings->set_msvalidate( 'ABC123' );
		$settings->connect( 'plain-api-key-123', 'https://example.com/' );

		$settings->disconnect();

		$this->assertFalse( $settings->connected() );
		$this->assertSame( '', $settings->api_key() );
		// The printed tag is what makes reconnecting one paste — it survives.
		$this->assertSame( 'ABC123', $settings->get( 'msvalidate' ) );
		$view = $settings->public_view();
		$this->assertTrue( $view['hasMsvalidate'] );
	}

	// ── Summary::conflicts — the crawl-errors advice matches the error kind ──

	/**
	 * Run the private conflicts() on synthetic rows.
	 *
	 * @param array $rows Window rows carrying code_4xx / code_5xx.
	 * @return array The conflicts list.
	 */
	private function conflicts_for( array $rows ): array {
		$errors = 0;
		foreach ( $rows as $row ) {
			$errors += $row['code_4xx'] + $row['code_5xx'];
		}
		$totals = array( 'crawlErrors' => $errors, 'blockedByRobots' => 0 );
		$m      = new \ReflectionMethod( \Agentimus\Bing\Summary::class, 'conflicts' );
		\_af_accessible( $m );
		return $m->invoke( null, $totals, $rows, new \Agentimus\Settings() );
	}

	public function test_all_4xx_errors_get_the_dead_pages_advice_not_the_server_advice() {
		$conflicts = $this->conflicts_for( array(
			array( 'code_4xx' => 50, 'code_5xx' => 0 ),
			array( 'code_4xx' => 6, 'code_5xx' => 0 ),
		) );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'bing-crawl-errors', $conflicts[0]['id'] );
		$this->assertStringContainsString( 'no longer exist', $conflicts[0]['body'] );
		// "Check your server" over a pile of 404s sends the owner to the wrong room.
		$this->assertStringNotContainsString( 'Check your server', $conflicts[0]['body'] );
	}

	public function test_all_5xx_errors_get_the_server_advice_not_the_dead_pages_advice() {
		$conflicts = $this->conflicts_for( array(
			array( 'code_4xx' => 0, 'code_5xx' => 30 ),
			array( 'code_4xx' => 0, 'code_5xx' => 2 ),
		) );

		$this->assertCount( 1, $conflicts );
		$this->assertStringContainsString( 'Check your server and any firewall', $conflicts[0]['body'] );
		$this->assertStringNotContainsString( 'no longer exist', $conflicts[0]['body'] );
	}

	public function test_mixed_errors_name_both_kinds_with_their_own_counts() {
		$conflicts = $this->conflicts_for( array(
			array( 'code_4xx' => 40, 'code_5xx' => 15 ),
			array( 'code_4xx' => 0, 'code_5xx' => 1 ),
		) );

		$this->assertCount( 1, $conflicts );
		$body = $conflicts[0]['body'];
		$this->assertStringContainsString( '40', $body );
		$this->assertStringContainsString( '16', $body );
		$this->assertStringContainsString( 'no longer exist', $body );
		$this->assertStringContainsString( 'check your server and any firewall', $body );
	}

	public function test_a_clean_most_recent_day_keeps_the_warning_quiet() {
		// The currency rule: a big week fires only while the site still errors.
		// ⚠️ The clean day carries REAL crawl numbers. A row with nothing in it
		// at all is Bing saying nothing about that date, not a day it crawled
		// cleanly {@see \Agentimus\Bing\Table::reported()} — and the two must
		// never be written the same way, here least of all.
		$conflicts = $this->conflicts_for( array(
			array( 'code_4xx' => 60, 'code_5xx' => 0 ),
			array( 'code_4xx' => 0, 'code_5xx' => 0, 'crawled' => 49, 'code_2xx' => 260, 'in_index' => 229 ),
		) );

		$this->assertSame( array(), $conflicts );
	}

	/**
	 * ⭐⭐ A DAY BING SAID NOTHING ABOUT IS NOT A DAY THE ERRORS STOPPED.
	 *
	 * Bing answers for dates it has no numbers for, and every counter arrives as
	 * zero. Read as a reading, that silences this warning on a site that is
	 * still erroring — the currency rule defeated by a non-answer instead of by
	 * a fix. The last day Bing actually reported is the one that decides.
	 */
	public function test_a_silent_most_recent_day_does_not_quiet_the_warning() {
		$conflicts = $this->conflicts_for( array(
			array( 'code_4xx' => 60, 'code_5xx' => 0, 'crawled' => 49, 'code_2xx' => 260, 'in_index' => 229 ),
			array( 'code_4xx' => 0, 'code_5xx' => 0 ), // Bing answered; Bing said nothing.
		) );

		$this->assertCount( 1, $conflicts, 'Nothing has been fixed, so nothing may go quiet.' );
		$this->assertSame( 'bing-crawl-errors', $conflicts[0]['id'] );
	}

	public function test_errors_under_the_floor_stay_a_fact_not_a_warning() {
		$conflicts = $this->conflicts_for( array(
			array( 'code_4xx' => 20, 'code_5xx' => 0 ),
			array( 'code_4xx' => 4, 'code_5xx' => 0 ),
		) );

		$this->assertSame( array(), $conflicts );
	}
}
