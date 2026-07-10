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

	private function settings( array $extra ) {
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION, array() ), $extra ) );
	}

	private function enable_beacon() {
		$this->settings( array( 'enable_referral_beacon' => true ) );
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

	/**
	 * "Last 30 days" must mean thirty calendar days INCLUDING today — not thirty-one.
	 * The cutoff is a whole day (`day >= …`), so subtracting a full window swept in an
	 * extra day and every total read high against the label on the card.
	 */
	public function test_summary_window_is_inclusive_of_today_and_no_wider() {
		$this->seed( gmdate( 'Y-m-d' ), 'ChatGPT', '/today', 1 );
		$this->seed( gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ), 'ChatGPT', '/oldest-in-window', 1 );
		$this->seed( gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), 'ChatGPT', '/one-day-too-old', 1 );

		$s = Referrals::summary( 30 );

		$this->assertSame( 2, $s['totals']['window'], 'the 31st day back is outside a 30-day window' );
		$this->assertCount( 2, $s['daily'] );

		$paths = array_column( $s['topPages'], 'path' ); // Equal hit counts — order is not meaningful.
		sort( $paths );
		$this->assertSame( array( '/oldest-in-window', '/today' ), $paths );
	}

	/** A one-day window is just today. */
	public function test_summary_window_of_one_day_is_today_only() {
		$this->seed( gmdate( 'Y-m-d' ), 'ChatGPT', '/today', 4 );
		$this->seed( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'ChatGPT', '/yesterday', 9 );

		$s = Referrals::summary( 1 );
		$this->assertSame( 4, $s['totals']['window'] );
	}

	/** The "sources" KPI counts every distinct source, not just the ones the top-8 list shows. */
	public function test_summary_counts_all_distinct_sources_beyond_the_leaderboard_cap() {
		$today = gmdate( 'Y-m-d' );
		foreach ( array( 'ChatGPT', 'Perplexity', 'Gemini', 'Copilot', 'Claude', 'You.com', 'Poe', 'Grok', 'Mistral' ) as $i => $source ) {
			$this->seed( $today, $source, "/p$i", 1 );
		}

		$s = Referrals::summary( 30 );
		$this->assertCount( 8, $s['bySource'], 'the leaderboard stays capped' );
		$this->assertSame( 9, $s['sourceCount'], 'the KPI counts them all' );
	}

	/**
	 * The summary carries day TOTALS, never the per-day pairings. It used to select every
	 * (day, source, path) row in the window and fold them in PHP — on a table near its
	 * 50,000-row cap that read ~45,000 rows to keep 360, on every dashboard load.
	 */
	public function test_summary_daily_is_totals_only_and_never_carries_the_day_rows() {
		$today = gmdate( 'Y-m-d' );
		$this->seed( $today, 'ChatGPT', '/a', 5 );
		$this->seed( $today, 'Perplexity', '/b', 2 );

		$day = Referrals::summary( 30 )['daily'][0];

		$this->assertSame( array( 'date', 'hits', 'rowCount' ), array_keys( $day ) );
		$this->assertSame( 7, $day['hits'] );
		$this->assertSame( 2, $day['rowCount'], 'distinct pairings, so the panel can say "+N more"' );
	}

	/* -- The on-demand per-day drill-down -------------------------------- */

	public function test_day_rows_returns_the_busiest_pairings_capped_in_sql() {
		$today = gmdate( 'Y-m-d' );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->seed( $today, 'ChatGPT', "/p$i", $i + 1 ); // /p19 busiest.
		}
		$this->seed( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'Claude', '/yesterday', 999 );

		$day = Referrals::day_rows( $today );

		$this->assertSame( $today, $day['date'] );
		$this->assertSame( 20, $day['rowCount'], 'every pairing counted...' );
		$this->assertCount( Referrals::DAY_TOP, $day['rows'], '...but only DAY_TOP fetched' );
		$this->assertSame( '/p19', $day['rows'][0]['path'], 'busiest first' );
		$this->assertSame( 210, $day['hits'], 'the day total is the whole day, not the top slice' );

		foreach ( $day['rows'] as $r ) {
			$this->assertNotSame( '/yesterday', $r['path'], 'another day never leaks in' );
		}
	}

	public function test_day_rows_is_empty_for_a_day_with_no_visits() {
		$day = Referrals::day_rows( '2020-01-01' );
		$this->assertSame( array(), $day['rows'] );
		$this->assertSame( 0, $day['hits'] );
		$this->assertSame( 0, $day['rowCount'] );
	}

	/* -- The filtered report (AI traffic screen) -------------------------- */

	/**
	 * The reason the report exists: the dashboard is capped at report_days(), but the table
	 * keeps retention_days(). On a site keeping 90 days, 60 of them were stored and
	 * unreachable from anywhere in the UI.
	 */
	public function test_report_reaches_data_the_dashboard_window_cannot_show() {
		$this->settings( array( 'activity_retention_days' => 90, 'activity_auto_prune' => true ) );
		$old = gmdate( 'Y-m-d', time() - 60 * DAY_IN_SECONDS );
		$this->seed( $old, 'ChatGPT', '/old-but-kept', 42 );
		$this->seed( gmdate( 'Y-m-d' ), 'ChatGPT', '/today', 1 );

		$this->assertSame( 1, Referrals::summary( 30 )['totals']['window'], 'the dashboard cannot see it' );

		$report = Referrals::report( array( 'from' => $old, 'to' => gmdate( 'Y-m-d' ) ) );
		$this->assertSame( 43, $report['total'], 'the report can' );
		$this->assertSame( 2, $report['activeDays'] );
	}

	/** A range reaching past retention is clamped: a row of zeros is not a report. */
	public function test_report_clamps_the_range_to_what_retention_could_still_hold() {
		$this->settings( array( 'activity_retention_days' => 30, 'activity_auto_prune' => true ) );

		$report = Referrals::report( array( 'from' => '2020-01-01', 'to' => '2099-01-01' ) );

		$this->assertSame( gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), $report['range']['from'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $report['range']['to'], 'never into the future' );
	}

	/** With auto-delete off nothing ages out on a clock, so no floor applies. */
	public function test_report_has_no_floor_when_auto_delete_is_off() {
		$this->settings( array( 'activity_retention_days' => 30, 'activity_auto_prune' => false ) );
		$ancient = gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS );
		$this->seed( $ancient, 'Claude', '/ancient', 7 );

		$report = Referrals::report( array( 'from' => $ancient, 'to' => gmdate( 'Y-m-d' ) ) );
		$this->assertSame( 7, $report['total'] );
	}

	/** An inverted range collapses rather than returning everything or nothing at random. */
	public function test_report_never_inverts_the_range() {
		$r = Referrals::report( array( 'from' => gmdate( 'Y-m-d' ), 'to' => gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ) ) );
		$this->assertSame( $r['range']['to'], $r['range']['from'] );
	}

	/**
	 * The source × page cross-tab the summary structurally cannot answer: which pages does
	 * one assistant send readers to?
	 */
	public function test_report_filters_by_source() {
		$today = gmdate( 'Y-m-d' );
		$this->seed( $today, 'ChatGPT', '/a', 10 );
		$this->seed( $today, 'Perplexity', '/b', 3 );
		$this->seed( $today, 'Perplexity', '/c', 2 );

		$r = Referrals::report( array( 'source' => 'Perplexity' ) );

		$this->assertSame( 5, $r['total'] );
		$this->assertSame( array( '/b', '/c' ), array_column( $r['topPages'], 'path' ) );
		$this->assertSame( array( 'Perplexity' ), array_column( $r['bySource'], 'label' ) );
		$this->assertSame( 5, $r['daily'][0]['hits'], 'the day totals follow the filter too' );
	}

	/** The path filter is a PREFIX, and a leading slash is optional. */
	public function test_report_filters_by_landing_path_prefix() {
		$today = gmdate( 'Y-m-d' );
		$this->seed( $today, 'ChatGPT', '/blog/one', 4 );
		$this->seed( $today, 'Claude', '/blog/two', 3 );
		$this->seed( $today, 'ChatGPT', '/shop/x', 9 );

		$this->assertSame( 7, Referrals::report( array( 'path' => '/blog' ) )['total'] );
		$this->assertSame( 7, Referrals::report( array( 'path' => 'blog' ) )['total'], 'leading slash optional' );
		$this->assertSame( 2, Referrals::report( array( 'path' => '/blog' ) )['sourceCount'] );
	}

	/** A `%` in the needle is escaped, not treated as a wildcard. */
	public function test_report_path_filter_escapes_like_wildcards() {
		$today = gmdate( 'Y-m-d' );
		$this->seed( $today, 'ChatGPT', '/real', 5 );

		$this->assertSame( 0, Referrals::report( array( 'path' => '%' ) )['total'], 'a bare % must not match everything' );
	}

	/** Filters carry into an opened day, so the drill-down agrees with the cards above it. */
	public function test_day_rows_honours_the_report_filters() {
		$today = gmdate( 'Y-m-d' );
		$this->seed( $today, 'ChatGPT', '/a', 10 );
		$this->seed( $today, 'Perplexity', '/b', 3 );

		$day = Referrals::day_rows( $today, array( 'source' => 'Perplexity' ) );
		$this->assertSame( 3, $day['hits'] );
		$this->assertSame( 1, $day['rowCount'] );
		$this->assertSame( '/b', $day['rows'][0]['path'] );
	}

	/** "Show all" reaches the pairings past DAY_TOP, which were counted but unreachable. */
	public function test_day_rows_full_reaches_past_the_day_top_cap() {
		$today = gmdate( 'Y-m-d' );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->seed( $today, 'ChatGPT', "/p$i", $i + 1 );
		}

		$this->assertCount( Referrals::DAY_TOP, Referrals::day_rows( $today )['rows'] );

		$full = Referrals::day_rows( $today, array( 'full' => true ) );
		$this->assertCount( 20, $full['rows'], 'every pairing, once asked for' );
		$this->assertFalse( $full['capped'] );
	}

	/** The source filter's options come from ALL retained data, not the current range. */
	public function test_facets_span_retention_not_the_current_range() {
		$this->settings( array( 'activity_retention_days' => 90, 'activity_auto_prune' => true ) );
		$this->seed( gmdate( 'Y-m-d' ), 'ChatGPT', '/a', 5 );
		$this->seed( gmdate( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ), 'Grok', '/b', 1 );

		$values = array_column( Referrals::facets(), 'value' );
		sort( $values );
		$this->assertSame( array( 'ChatGPT', 'Grok' ), $values, 'narrowing the range must not delete the option that widens it' );
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

	/**
	 * The owner's own arrival from ChatGPT must not be counted — including through the
	 * beacon, where WordPress has already reset them to "logged out". A nonce-less
	 * cookie request is exactly what `rest_cookie_check_errors()` hands the route: the
	 * cookie is on the request, the current user is 0. Reading `is_user_logged_in()`
	 * there silently counted every visit the site owner made from an AI assistant.
	 */
	public function test_record_from_client_skips_the_owner_behind_a_nonce_less_rest_request() {
		$this->enable_beacon();
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin, time() + HOUR_IN_SECONDS, 'logged_in' );
		wp_set_current_user( 0 ); // What REST does when the cookie carries no nonce.

		$this->assertFalse( Referrals::record_from_client( 'https://chatgpt.com/', '', '/guide' ) );
		$this->assertSame( 0, $this->row_count(), "the owner's own visit is not traffic AI sent" );

		// A subscriber is a real reader, not the owner: still counted.
		$reader                      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $reader, time() + HOUR_IN_SECONDS, 'logged_in' );

		$this->assertTrue( Referrals::record_from_client( 'https://chatgpt.com/', '', '/guide' ) );
		$this->assertSame( 1, $this->row_count() );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_SERVER['HTTP_USER_AGENT'] );
	}

	/** A forged logged-in cookie fails its HMAC, so it can't mark a visit as the owner's. */
	public function test_record_from_client_ignores_an_unverifiable_cookie() {
		$this->enable_beacon();
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'admin|9999999999|forged|deadbeef';
		wp_set_current_user( 0 );

		$this->assertTrue( Referrals::record_from_client( 'https://chatgpt.com/', '', '/guide' ) );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_SERVER['HTTP_USER_AGENT'] );
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
