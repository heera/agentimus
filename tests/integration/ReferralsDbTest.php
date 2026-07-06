<?php
/**
 * Referrals table against real MySQL: the daily upsert (INSERT … ON DUPLICATE KEY),
 * the summary aggregates, retention prune, and — the highest-value target — the
 * trim_to_cap anti-join added in P1 (`DELETE t … LEFT JOIN (…) keep … WHERE keep.id
 * IS NULL`), which had never executed against a real database until now.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Referrals;
use Agentimus\Settings;

final class ReferralsDbTest extends DbTestCase {

	/** A realistic desktop-browser UA (classifies as "Browser", which the beacon requires). */
	const BROWSER_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

	private function enable_beacon() {
		update_option(
			Settings::OPTION,
			array_merge( (array) get_option( Settings::OPTION, array() ), array( 'enable_referral_beacon' => true ) )
		);
	}

	public function set_up(): void {
		parent::set_up();
		Referrals::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Referrals::name() ); // isolate each test.
	}

	private function seed( $day, $source, $path, $hits ) {
		global $wpdb;
		$wpdb->insert(
			Referrals::name(),
			array( 'day' => $day, 'source' => $source, 'path' => $path, 'hits' => $hits ),
			array( '%s', '%s', '%s', '%d' )
		);
	}

	private function row_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Referrals::name() );
	}

	/* -- The P1 anti-join trim ------------------------------------------- */

	public function test_trim_to_cap_keeps_the_busiest_rows_and_drops_the_long_tail() {
		$day = '2026-07-01';
		$this->seed( $day, 'ChatGPT', '/popular-a', 100 );
		$this->seed( $day, 'ChatGPT', '/popular-b', 80 );
		$this->seed( $day, 'Perplexity', '/popular-c', 60 );
		for ( $i = 0; $i < 25; $i++ ) {
			$this->seed( $day, 'ChatGPT', "/noise-$i", 1 ); // a spoofed-referrer path flood.
		}
		$this->assertSame( 28, $this->row_count() );

		add_filter( 'agentimus_referrals_max_rows', static function () { return 3; } );
		Referrals::trim_to_cap();

		global $wpdb;
		$paths = $wpdb->get_col( 'SELECT path FROM ' . Referrals::name() . ' ORDER BY hits DESC' );
		$this->assertSame( array( '/popular-a', '/popular-b', '/popular-c' ), $paths, 'only the busiest rows survive' );
	}

	public function test_trim_to_cap_is_a_noop_under_the_cap() {
		$this->seed( '2026-07-01', 'ChatGPT', '/a', 5 );
		add_filter( 'agentimus_referrals_max_rows', static function () { return 50; } );
		Referrals::trim_to_cap();
		$this->assertSame( 1, $this->row_count() );
	}

	/* -- The daily upsert (UNIQUE KEY + ON DUPLICATE KEY UPDATE) ---------- */

	public function test_increment_upserts_the_daily_counter() {
		$increment = new \ReflectionMethod( Referrals::class, 'increment' );
		$increment->setAccessible( true );
		$increment->invoke( null, 'ChatGPT', '/landing' );
		$increment->invoke( null, 'ChatGPT', '/landing' ); // same (day,source,path) → +1, not a new row.
		$increment->invoke( null, 'ChatGPT', '/other' );

		global $wpdb;
		$table = Referrals::name();
		$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM `$table` WHERE path = %s", '/landing' ) ) );
		$this->assertSame( 2, $this->row_count(), 'two distinct rows' );
	}

	/* -- Aggregates + prune ---------------------------------------------- */

	public function test_summary_aggregates_by_source_and_page() {
		$today = gmdate( 'Y-m-d' );
		$this->seed( $today, 'ChatGPT', '/a', 5 );
		$this->seed( $today, 'ChatGPT', '/b', 3 );
		$this->seed( $today, 'Perplexity', '/a', 2 );

		$s = Referrals::summary( 30 );
		$this->assertSame( 10, $s['totals']['today'] );
		$this->assertSame( 10, $s['totals']['window'] );

		$by = array();
		foreach ( $s['bySource'] as $r ) {
			$by[ $r['label'] ] = $r['hits'];
		}
		$this->assertSame( 8, $by['ChatGPT'] );
		$this->assertSame( 2, $by['Perplexity'] );
	}

	public function test_prune_drops_rows_older_than_retention() {
		$this->seed( gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ), 'ChatGPT', '/old', 1 );
		$this->seed( gmdate( 'Y-m-d' ), 'ChatGPT', '/new', 1 );

		Referrals::prune();

		global $wpdb;
		$this->assertSame( array( '/new' ), $wpdb->get_col( 'SELECT path FROM ' . Referrals::name() ) );
	}

	/* -- Browser beacon ("CDN mode") ------------------------------------- */

	public function test_record_from_client_only_counts_when_beacon_mode_is_on() {
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;

		// OFF by default → the client path is inert (server-side is the source of truth).
		$this->assertFalse( Referrals::record_from_client( 'https://chatgpt.com/', '', '/guide' ) );
		$this->assertSame( 0, $this->row_count() );

		$this->enable_beacon();

		// A ChatGPT referrer now counts...
		$this->assertTrue( Referrals::record_from_client( 'https://chatgpt.com/', '', '/guide' ) );
		// ...a non-AI referrer does not...
		$this->assertFalse( Referrals::record_from_client( 'https://example.com/', '', '/guide' ) );
		// ...and utm_source is caught even when the referrer was stripped.
		$this->assertTrue( Referrals::record_from_client( '', 'chatgpt.com', '/guide' ) );

		global $wpdb;
		$table = Referrals::name();
		$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM `$table` WHERE source = %s AND path = %s", 'ChatGPT', '/guide' ) ) );
		$this->assertSame( 1, $this->row_count(), 'both AI hits fold into one (day, source, path) row' );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/** The stored landing path is the path only — any query string (and UTM/PII in it) is dropped. */
	public function test_record_from_client_stores_path_without_query() {
		$this->enable_beacon();
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;

		Referrals::record_from_client( 'https://perplexity.ai/', '', '/deals?utm_source=perplexity.ai&x=1' );

		global $wpdb;
		$this->assertSame( '/deals', $wpdb->get_var( 'SELECT path FROM ' . Referrals::name() ), 'the query string must not be stored' );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/** A non-browser UA (a bot arriving with a referrer) is not a "visitor AI sent". */
	public function test_record_from_client_ignores_non_browser_agents() {
		$this->enable_beacon();
		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1.0';

		$this->assertFalse( Referrals::record_from_client( 'https://chatgpt.com/', '', '/guide' ) );
		$this->assertSame( 0, $this->row_count() );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}
}
