<?php
/**
 * The agent-hit log's read + maintenance SQL against real MySQL: the dashboard
 * aggregates (GROUP BY agent/endpoint, and the ua-indexed threats GROUP BY added in
 * P1), the row-cap trim (OFFSET), the day-requests half-open range, and retention
 * prune. None of this runs in the unit suite (no $wpdb, no MySQL).
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Repository;
use Agentimus\Activity\Table;
use Agentimus\Settings;

final class RepositoryDbTest extends DbTestCase {

	const NOKIA = 'Nokia6630/1.0 (2.3.129) SymbianOS/8.0 Series60/2.6 Profile/MIDP-2.0 Configuration/CLDC-1.1';

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
	}

	private function hit( $endpoint, $agent, $ua, $at = null ) {
		global $wpdb;
		$wpdb->insert(
			Table::name(),
			array( 'endpoint' => $endpoint, 'agent' => $agent, 'ua' => $ua, 'hit_at' => $at ?: gmdate( 'Y-m-d H:i:s' ) ),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	private function row_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Table::name() );
	}

	/* -- Dashboard aggregates -------------------------------------------- */

	public function test_stats_totals_and_group_counts() {
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0' );
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->hit( 'llms.txt', 'Googlebot', 'Googlebot/2.1' );

		$stats = Repository::stats( new Settings() );
		$this->assertSame( 3, $stats['totals']['all'] );

		$agents = array();
		foreach ( $stats['byAgent'] as $r ) {
			$agents[ $r['label'] ] = $r['hits'];
		}
		$this->assertSame( 2, $agents['GPTBot'] );
		$this->assertSame( 1, $agents['Googlebot'] );

		$endpoints = array();
		foreach ( $stats['byEndpoint'] as $r ) {
			$endpoints[ $r['label'] ] = $r['hits'];
		}
		$this->assertSame( 2, $endpoints['discovery.json'] );
	}

	/** The ua-indexed threats GROUP BY, plus is_spoof flagging, end-to-end. */
	public function test_threats_surface_a_spoofed_legacy_device() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->hit( 'discovery.json', 'Likely spoof/scanner', self::NOKIA );
		}
		$threats = Repository::threats( new Settings() );
		$this->assertIsArray( $threats['sources'] );

		$uas = array_map( static function ( $s ) { return $s['ua']; }, $threats['sources'] );
		$this->assertContains( self::NOKIA, $uas, 'a spoofed legacy-device UA must be flagged' );
	}

	/**
	 * The review queue must look back over the RETAINED span, not a hardcoded 30 days.
	 * HEAVY_MIN_HITS counts hits "over the whole window", so a site that extends retention
	 * would otherwise see the dashboard report a client's full history while the queue
	 * silently judged it on the last 30 days only.
	 */
	public function test_threats_window_follows_the_retention_filter() {
		$old = gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->hit( 'discovery.json', 'Likely spoof/scanner', self::NOKIA, $old );
		}

		$uas = static function ( $t ) {
			return array_map( static function ( $s ) { return $s['ua']; }, $t['sources'] );
		};

		// Default retention (30 days): a 45-day-old client is outside the window.
		$this->assertNotContains(
			self::NOKIA,
			$uas( Repository::threats( new Settings() ) ),
			'a client older than the retained window must not surface'
		);

		// Retain 90 days and the same client is inside the window the dashboard reports on.
		add_filter( 'agentimus_activity_retention_days', static fn() => 90 );
		$widened = $uas( Repository::threats( new Settings() ) );
		remove_all_filters( 'agentimus_activity_retention_days' );

		$this->assertContains(
			self::NOKIA,
			$widened,
			'threats() must span the filtered retention, not the raw WINDOW_DAYS default'
		);
	}

	/* -- The filtered, keyset-paged request log --------------------------- */

	/** Walk every page and collect the UA of each row, in order. */
	private function walk_log( array $args ) {
		$seen   = array();
		$before = 0;
		$pages  = 0;
		do {
			$page = Repository::log( $before ? $args + array( 'before' => $before ) : $args );
			foreach ( $page['rows'] as $r ) {
				$seen[] = $r['ua'];
			}
			$before = $page['cursor'];
			++$pages;
			$this->assertLessThan( 20, $pages, 'paging failed to terminate' );
		} while ( $page['hasMore'] );

		return $seen;
	}

	/** Keyset paging must yield every row exactly once, newest first, across page borders. */
	public function test_log_pages_every_row_exactly_once() {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->hit( 'discovery.json', 'GPTBot', 'UA-' . $i );
		}

		$seen = $this->walk_log( array( 'per_page' => 2 ) );

		$this->assertSame( array( 'UA-5', 'UA-4', 'UA-3', 'UA-2', 'UA-1' ), $seen );
		$this->assertSame( 5, count( array_unique( $seen ) ), 'no row may repeat across pages' );
	}

	/** `total` counts the whole filtered set; the cursor narrows only the page. */
	public function test_log_total_is_the_filtered_set_not_the_page() {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->hit( 'discovery.json', 'GPTBot', 'UA-' . $i );
		}

		$first = Repository::log( array( 'per_page' => 2 ) );
		$this->assertCount( 2, $first['rows'] );
		$this->assertSame( 5, $first['total'] );
		$this->assertTrue( $first['hasMore'] );

		$second = Repository::log( array( 'per_page' => 2, 'before' => $first['cursor'] ) );
		$this->assertSame( 5, $second['total'], 'total must not shrink as you page' );
	}

	/** The last page reports no cursor and no more rows. */
	public function test_log_last_page_terminates() {
		$this->hit( 'discovery.json', 'GPTBot', 'only' );

		$page = Repository::log( array( 'per_page' => 10 ) );

		$this->assertCount( 1, $page['rows'] );
		$this->assertFalse( $page['hasMore'] );
		$this->assertNull( $page['cursor'] );
	}

	public function test_log_filters_by_agent_and_endpoint() {
		$this->hit( 'discovery.json', 'GPTBot', 'a' );
		$this->hit( 'llms.txt', 'GPTBot', 'b' );
		$this->hit( 'discovery.json', 'Googlebot', 'c' );

		$this->assertSame( 2, Repository::log( array( 'agent' => 'GPTBot' ) )['total'] );
		$this->assertSame( 2, Repository::log( array( 'endpoint' => 'discovery.json' ) )['total'] );
		$this->assertSame(
			1,
			Repository::log( array( 'agent' => 'GPTBot', 'endpoint' => 'discovery.json' ) )['total'],
			'filters must combine with AND — this is the cross-tab the rollups cannot answer'
		);
	}

	/** ua is a PREFIX match: KEY ua(191) can serve LIKE 'x%' but never LIKE '%x%'. */
	public function test_log_ua_matches_by_prefix_only() {
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0' );
		$this->hit( 'discovery.json', 'Other', 'MyGPTBot/2.0' );

		$hit = Repository::log( array( 'ua' => 'GPTBot' ) );
		$this->assertSame( 1, $hit['total'] );
		$this->assertSame( 'GPTBot/1.0', $hit['rows'][0]['ua'], 'a mid-string match must NOT be returned' );
	}

	/**
	 * A wildcard in the needle is a literal, not a pattern — esc_like() must escape it.
	 *
	 * The needle deliberately LEADS with '%'. Escaped, it's the literal prefix "%alpha",
	 * which nothing here starts with, so the answer is zero. Unescaped it would become
	 * LIKE '%alpha%' — a contains-search matching both rows, and a way for a caller to
	 * turn an indexed prefix scan into a full table scan.
	 */
	public function test_log_ua_wildcards_are_literal_not_patterns() {
		$this->hit( 'discovery.json', 'Odd', 'alpha' );
		$this->hit( 'discovery.json', 'Odd', 'zzzalpha' );

		$this->assertSame(
			0,
			Repository::log( array( 'ua' => '%alpha' ) )['total'],
			"a leading '%' must match itself, not turn the search into LIKE '%alpha%'"
		);

		// And '_' must not act as a single-character wildcard either.
		$this->assertSame(
			0,
			Repository::log( array( 'ua' => 'alph_' ) )['total'],
			"'_' must match itself, not any character"
		);
	}

	public function test_log_filters_by_verdict() {
		global $wpdb;
		$this->hit( 'discovery.json', 'Googlebot', 'real' );
		$this->hit( 'discovery.json', 'Googlebot', 'fake' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Table::name() . ' SET verdict = %d WHERE ua = %s', 2, 'fake' ) );

		$this->assertSame( 1, Repository::log( array( 'verdict' => 2 ) )['total'] );
		$this->assertSame( 'fake', Repository::log( array( 'verdict' => 2 ) )['rows'][0]['ua'] );
	}

	/** The date window is half-open [from, to+1day), so `to` includes its own day. */
	public function test_log_date_range_includes_the_end_day() {
		$today     = gmdate( 'Y-m-d' );
		$five_back = gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS );
		$this->hit( 'discovery.json', 'GPTBot', 'old', gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) );
		$this->hit( 'discovery.json', 'GPTBot', 'new' );

		$this->assertSame( 1, Repository::log( array( 'from' => $today ) )['total'] );
		$this->assertSame( 2, Repository::log( array( 'from' => $five_back ) )['total'] );
		$this->assertSame(
			1,
			Repository::log( array( 'to' => $five_back ) )['total'],
			'a `to` date must include hits recorded on that day'
		);
	}

	/** Nothing older than the retained window is reachable, whatever `from` says. */
	public function test_log_cannot_reach_past_the_retention_floor() {
		$this->hit( 'discovery.json', 'GPTBot', 'ancient', gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS ) );
		$this->hit( 'discovery.json', 'GPTBot', 'recent' );

		$asked = Repository::log( array( 'from' => gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ) ) );

		$this->assertSame( 1, $asked['total'], 'the retention floor clamps an over-wide `from`' );
		$this->assertSame( 'recent', $asked['rows'][0]['ua'] );
		$this->assertSame( 30, $asked['retentionDays'], 'the payload must tell the UI what it cannot show' );
	}

	/* -- Configurable breakdown limits ----------------------------------- */

	public function test_breakdown_row_limits_are_filterable() {
		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $a ) {
			$this->hit( 'discovery.json', $a, $a . '/1.0' );
		}
		// Default cap (8) shows all 5 distinct clients.
		$this->assertCount( 5, Repository::stats( new Settings() )['byAgent'] );

		// A filter tightens it — and is clamped to at least 1.
		add_filter( 'agentimus_activity_clients_limit', static function () { return 2; } );
		$this->assertCount( 2, Repository::stats( new Settings() )['byAgent'], 'the clients limit filter must apply' );
	}

	/* -- Maintenance: OFFSET trim, day range, prune ---------------------- */

	public function test_trim_to_cap_keeps_only_the_newest_rows() {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->hit( 'discovery.json', 'Bot', "Bot/$i" );
		}
		add_filter( 'agentimus_activity_max_rows', static function () { return 4; } );
		Repository::trim_to_cap();
		$this->assertSame( 4, $this->row_count(), 'the table is capped to the newest N rows' );
	}

	public function test_day_requests_returns_that_days_hits() {
		$noon = gmdate( 'Y-m-d' ) . ' 12:00:00';
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0', $noon );
		$this->hit( 'llms.txt', 'Googlebot', 'Googlebot/2.1', $noon );

		$day = Repository::day_requests( gmdate( 'Y-m-d' ), 100 );
		$this->assertSame( gmdate( 'Y-m-d' ), $day['date'] );
		$this->assertSame( 2, $day['total'] );
		$this->assertCount( 2, $day['rows'] );
	}

	public function test_prune_drops_hits_older_than_retention() {
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0', gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) );
		$this->hit( 'llms.txt', 'Googlebot', 'Googlebot/2.1' );
		Repository::prune();
		$this->assertSame( 1, $this->row_count() );
	}

	public function test_clear_empties_the_table() {
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0' );
		Repository::clear();
		$this->assertSame( 0, $this->row_count() );
	}
}
