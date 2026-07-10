<?php
/**
 * UnknownSources — the opt-in diagnostic that records what the AI-source map could not
 * name. Exercised against real MySQL: the opt-in gate, the own-host exclusion (without
 * which ordinary internal navigation would fill the table), the two-signal recording,
 * the aggregates, and the prune/cap.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Referrals;
use Agentimus\Activity\UnknownSources;
use Agentimus\Settings;

final class UnknownSourcesDbTest extends DbTestCase {

	const BROWSER_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

	public function set_up(): void {
		parent::set_up();
		UnknownSources::install();
		Referrals::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . UnknownSources::name() );
		$wpdb->query( 'TRUNCATE TABLE ' . Referrals::name() );
	}

	private function settings( array $extra ) {
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION, array() ), $extra ) );
	}

	/** Turn the diagnostic on, plus the beacon so record_from_client() is live. */
	private function enable_all() {
		$this->settings(
			array(
				'enable_activity'       => true,
				'enable_referral_beacon' => true,
				'log_unknown_referrers' => true,
			)
		);
	}

	private function rows() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT kind, token, hits FROM ' . UnknownSources::name() . ' ORDER BY kind, token', ARRAY_A );
	}

	/* -- The opt-in gate -------------------------------------------------- */

	public function test_records_nothing_while_the_diagnostic_is_off() {
		$this->settings( array( 'enable_activity' => true, 'log_unknown_referrers' => false ) );
		UnknownSources::note( 'https://news.example.com/story', 'somefeed' );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_summary_is_a_disabled_shell_while_off_and_costs_no_query() {
		$this->settings( array( 'log_unknown_referrers' => false ) );
		$s = UnknownSources::summary( 30 );
		$this->assertFalse( $s['enabled'] );
		$this->assertSame( array(), $s['hosts'] );
		$this->assertSame( array(), $s['utm'] );
	}

	/* -- What it records -------------------------------------------------- */

	public function test_records_the_external_host_and_the_utm_token_as_separate_signals() {
		$this->enable_all();
		UnknownSources::note( 'https://news.example.com/story', 'somefeed' );

		$this->assertSame(
			array(
				array( 'kind' => 'host', 'token' => 'news.example.com', 'hits' => '1' ),
				array( 'kind' => 'utm', 'token' => 'somefeed', 'hits' => '1' ),
			),
			$this->rows()
		);
	}

	/** www is stripped, so one site is one row however its links are written. */
	public function test_host_is_normalised() {
		$this->enable_all();
		UnknownSources::note( 'https://www.Example.COM/a', '' );
		UnknownSources::note( 'https://example.com/b', '' );

		$this->assertSame( array( array( 'kind' => 'host', 'token' => 'example.com', 'hits' => '2' ) ), $this->rows() );
	}

	/**
	 * Internal navigation carries a referrer on every single click. Recording it would
	 * bury the diagnostic in the site's own hostname and nothing else.
	 */
	public function test_the_sites_own_pages_are_never_recorded_as_a_referrer() {
		$this->enable_all();
		UnknownSources::note( home_url( '/some-post/' ), '' );
		UnknownSources::note( 'https://sub.' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/x', '' );

		$this->assertSame( array(), $this->rows(), 'own host and its subdomains are internal' );
	}

	public function test_a_utm_token_is_length_capped_and_stripped_of_markup() {
		$this->enable_all();
		UnknownSources::note( '', '<script>x</script>' . str_repeat( 'a', 200 ) );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'utm', $rows[0]['kind'] );
		$this->assertLessThanOrEqual( UnknownSources::MAX_TOKEN, strlen( $rows[0]['token'] ) );
		$this->assertStringNotContainsString( '<', $rows[0]['token'] );
	}

	/* -- The wiring: an unattributed real visit reaches the diagnostic ----- */

	public function test_an_unrecognised_referral_is_noted_while_a_known_one_is_counted() {
		$this->enable_all();
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;

		// A known assistant: counted as a referral, never noted as unknown.
		$this->assertTrue( Referrals::record_from_client( 'https://chatgpt.com/', '', '/guide' ) );
		$this->assertSame( array(), $this->rows() );

		// An assistant we don't know yet: not counted, but now visible in the diagnostic.
		$this->assertFalse( Referrals::record_from_client( 'https://brand-new-ai.example/', '', '/guide' ) );
		$this->assertSame(
			array( array( 'kind' => 'host', 'token' => 'brand-new-ai.example', 'hits' => '1' ) ),
			$this->rows()
		);

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/** A crawler's referrer is not a reader's: the UA gate runs before the diagnostic. */
	public function test_a_bot_is_never_noted() {
		$this->enable_all();
		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1.0';

		Referrals::record_from_client( 'https://news.example.com/', '', '/guide' );
		$this->assertSame( array(), $this->rows() );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/* -- Aggregates + maintenance ----------------------------------------- */

	public function test_summary_ranks_each_kind_by_hits_over_the_window() {
		$this->enable_all();
		global $wpdb;
		$today = gmdate( 'Y-m-d' );
		$old   = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
		foreach ( array( array( 'host', 'a.example', 5, $today ), array( 'host', 'b.example', 9, $today ), array( 'utm', 'newsletter', 3, $today ), array( 'host', 'ancient.example', 99, $old ) ) as $r ) {
			$wpdb->insert( UnknownSources::name(), array( 'kind' => $r[0], 'token' => $r[1], 'hits' => $r[2], 'day' => $r[3] ) );
		}

		$s = UnknownSources::summary( 30 );
		$this->assertSame( array( 'b.example', 'a.example' ), array_column( $s['hosts'], 'token' ), 'busiest first, out-of-window excluded' );
		$this->assertSame( array( 'newsletter' ), array_column( $s['utm'], 'token' ) );
	}

	public function test_trim_to_cap_keeps_the_busiest_rows() {
		global $wpdb;
		$day = gmdate( 'Y-m-d' );
		$wpdb->insert( UnknownSources::name(), array( 'kind' => 'host', 'token' => 'busy.example', 'hits' => 500, 'day' => $day ) );
		for ( $i = 0; $i < 10; $i++ ) {
			$wpdb->insert( UnknownSources::name(), array( 'kind' => 'utm', 'token' => "junk-$i", 'hits' => 1, 'day' => $day ) );
		}

		add_filter( 'agentimus_unknown_sources_max_rows', static function () { return 1; } );
		UnknownSources::trim_to_cap();

		$this->assertSame( array( array( 'kind' => 'host', 'token' => 'busy.example', 'hits' => '500' ) ), $this->rows() );
	}

	public function test_prune_drops_rows_older_than_retention() {
		global $wpdb;
		$wpdb->insert( UnknownSources::name(), array( 'kind' => 'host', 'token' => 'old.example', 'hits' => 1, 'day' => gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) );
		$wpdb->insert( UnknownSources::name(), array( 'kind' => 'host', 'token' => 'new.example', 'hits' => 1, 'day' => gmdate( 'Y-m-d' ) ) );

		UnknownSources::prune();

		$this->assertSame( array( 'new.example' ), array_column( $this->rows(), 'token' ) );
	}
}
