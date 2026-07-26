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

	/** A request that was turned away at the door — recorded, never served. */
	private function refusal( $endpoint, $agent, $ua, $verdict = 2, $signer = 'chatgpt.com' ) {
		global $wpdb;
		$wpdb->insert(
			Table::name(),
			array(
				'endpoint' => $endpoint,
				'agent'    => $agent,
				'ua'       => $ua,
				'verdict'  => $verdict,
				'signer'   => $signer,
				'refused'  => 1,
				'hit_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/* -- Refusals are recorded but never counted as reads ------------------ */

	public function test_a_refusal_never_inflates_any_read_total() {
		$this->hit( 'llms.txt', 'GPTBot', 'GPTBot/1.0' );
		$this->refusal( 'llms.txt', 'GPTBot', 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)' );

		$stats = Repository::stats( new Settings() );

		// Two rows exist; exactly one of them was READ. Every surface that answers
		// "what did agents fetch" must say one — the log's whole promise.
		$this->assertSame( 2, $this->row_count(), 'the refusal is stored' );
		$this->assertSame( 1, $stats['totals']['today'], 'today counts reads only' );

		$endpoints = array();
		foreach ( $stats['byEndpoint'] as $r ) {
			$endpoints[ $r['label'] ] = $r['hits'];
		}
		$this->assertSame( 1, $endpoints['llms.txt'], 'by-endpoint counts reads only' );

		$agents = array();
		foreach ( $stats['byAgent'] as $r ) {
			$agents[ $r['label'] ] = $r['hits'];
		}
		$this->assertSame( 1, $agents['GPTBot'], 'top clients count reads only' );
	}

	public function test_the_log_shows_a_refusal_and_marks_it() {
		$this->refusal( 'llms.txt', 'GPTBot', 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)' );

		$log = Repository::log( array() );

		$this->assertCount( 1, $log['rows'], 'a refused request is not erased from history' );
		$this->assertTrue( $log['rows'][0]['refused'], 'and it is unmistakably marked' );
		$this->assertSame( 'chatgpt.com', $log['rows'][0]['signer'] );
	}

	public function test_the_log_can_be_filtered_to_refusals_only() {
		$this->hit( 'llms.txt', 'GPTBot', 'GPTBot/1.0' );
		$this->refusal( 'llms.txt', 'GPTBot', 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)' );

		$only = Repository::log( array( 'verdict' => 'refused' ) );

		$this->assertSame( 1, $only['total'] );
		$this->assertTrue( $only['rows'][0]['refused'] );
	}

	public function test_a_refused_impostor_still_reaches_the_review_queue() {
		// The whole point: enforcement must not swallow the owner's security signal.
		$this->refusal( 'llms.txt', 'GPTBot', 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)' );

		$threats = Repository::threats( new Settings() );

		$found = null;
		foreach ( (array) $threats['sources'] as $s ) {
			if ( false !== stripos( $s['ua'], 'gptbot' ) ) {
				$found = $s;
			}
		}
		$this->assertNotNull( $found, 'a refused impostor is still reported to the owner' );
		$this->assertSame( 'spoofed', $found['verdict'] );
		$this->assertTrue( $found['refused'], 'and the card can say it was turned away' );
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

	public function test_week_and_month_windows_are_calendar_days_not_rolling() {
		// The tile windows are CALENDAR days (UTC), the same clock as "today" and the
		// daily chart. They used to be rolling (now - N*86400), which made the numbers
		// visibly SHRINK between midnights as old hits aged out — an owner watching
		// auto-refresh read that as data loss. These two rows pin the boundary:
		$window_start = gmdate( 'Y-m-d 00:00:00', time() - 6 * DAY_IN_SECONDS );

		// 1s BEFORE the 7-calendar-day window opens. A rolling 168h window would ALWAYS
		// count this row (it is at most 6d23h59m59s + 1s old); the calendar window never.
		$this->hit( 'llms.txt', 'GPTBot', 'GPTBot/1.0', gmdate( 'Y-m-d H:i:s', strtotime( $window_start . ' UTC' ) - 1 ) );
		// Exactly AT the boundary (>= cutoff): the oldest instant that still counts.
		$this->hit( 'llms.txt', 'GPTBot', 'GPTBot/1.0', $window_start );
		// And one from right now.
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0' );

		$totals = Repository::stats( new Settings() )['totals'];
		$this->assertSame( 2, $totals['week'], '7 days = today plus the 6 full days before it, nothing older' );
		$this->assertSame( 3, $totals['all'] );
		$this->assertSame( 3, $totals['month'], 'the 30-day window still holds what the week aged out' );
		$this->assertSame( 1, $totals['today'] );
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
	 * The review queue must look back over exactly the span the DASHBOARD reports on —
	 * report_days(), i.e. min(30, retention) — never a hardcoded 30 and never the raw
	 * retention.
	 *
	 * HEAVY_MIN_HITS counts hits "over the whole window". If the queue and the cards
	 * disagreed, the dashboard could show a bot's 1,200 hits while the queue judged it on
	 * 300 and stayed silent (retention > 30), or the queue could count 30 days of data that
	 * a 7-day retention has already deleted.
	 */
	private function threat_uas() {
		return array_map(
			static function ( $s ) { return $s['ua']; },
			Repository::threats( new Settings() )['sources']
		);
	}

	/** Keeping MORE than 30 days must not stretch the queue past the 30 days the cards show. */
	public function test_threats_window_never_exceeds_the_reported_window() {
		$old = gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->hit( 'discovery.json', 'Likely spoof/scanner', self::NOKIA, $old );
		}

		$this->assertNotContains( self::NOKIA, $this->threat_uas(), 'sanity: 45 days is outside the default 30' );

		add_filter( 'agentimus_activity_retention_days', static fn() => 90 );
		$widened = $this->threat_uas();
		$report  = Repository::report_days();
		remove_all_filters( 'agentimus_activity_retention_days' );

		$this->assertSame( 30, $report, 'report window stays capped at 30 when retention is 90' );
		$this->assertNotContains(
			self::NOKIA,
			$widened,
			'a 45-day-old client is retained but NOT reported on — the queue must agree with the cards'
		);
	}

	/** Keeping LESS than 30 days must shrink the queue to match, not look into deleted days. */
	public function test_threats_window_shrinks_with_a_short_retention() {
		// 10 days old: inside a 30-day window, outside a 7-day one.
		$old = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->hit( 'discovery.json', 'Likely spoof/scanner', self::NOKIA, $old );
		}

		$this->assertContains( self::NOKIA, $this->threat_uas(), 'sanity: 10 days is inside the default 30' );

		add_filter( 'agentimus_activity_retention_days', static fn() => 7 );
		$narrowed = $this->threat_uas();
		$report   = Repository::report_days();
		remove_all_filters( 'agentimus_activity_retention_days' );

		$this->assertSame( 7, $report, 'report window follows a retention shorter than 30' );
		$this->assertNotContains( self::NOKIA, $narrowed, 'the queue must not count days the prune has deleted' );
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

	/* -- Retention: what is KEPT vs what is REPORTED ----------------------- */

	/** Set the stored settings the Repository reads (it has no Settings instance on cron). */
	private function settings( array $patch ) {
		$all = get_option( \Agentimus\Settings::OPTION, array() );
		update_option( \Agentimus\Settings::OPTION, array_merge( is_array( $all ) ? $all : array(), $patch ) );
	}

	public function test_report_window_is_capped_at_thirty_days() {
		$this->settings( array( 'activity_retention_days' => 90 ) );

		$this->assertSame( 90, Repository::retention_days(), 'the log keeps 90 days' );
		$this->assertSame( 30, Repository::report_days(), 'the dashboard still reports on 30' );
	}

	/** A 30-day chart over a 7-day retention would draw 23 days of zeros. */
	public function test_report_window_never_exceeds_retention() {
		$this->settings( array( 'activity_retention_days' => 7 ) );

		$this->assertSame( 7, Repository::retention_days() );
		$this->assertSame( 7, Repository::report_days() );
	}

	/** The stored setting is the DEFAULT the filter receives — code still beats UI. */
	public function test_the_filter_overrides_the_stored_setting() {
		$this->settings( array( 'activity_retention_days' => 90 ) );
		add_filter( 'agentimus_activity_retention_days', static fn( $d ) => 14 );
		$days = Repository::retention_days();
		remove_all_filters( 'agentimus_activity_retention_days' );

		$this->assertSame( 14, $days );
	}

	/** An upgrading site has no keys at all and must behave exactly as it did before. */
	public function test_absent_settings_fall_back_to_todays_behaviour() {
		delete_option( \Agentimus\Settings::OPTION );

		$this->assertSame( 30, Repository::retention_days() );
		$this->assertSame( 30, Repository::report_days() );
		$this->assertTrue( Repository::auto_prune() );
		$this->assertSame( 50000, Repository::max_rows() );
	}

	/* -- Auto-delete: off means "kept until the cap", not "kept forever" --- */

	public function test_prune_deletes_aged_rows_when_auto_prune_is_on() {
		$this->settings( array( 'activity_auto_prune' => true, 'activity_retention_days' => 7 ) );
		$this->hit( 'discovery.json', 'Old', 'old', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );
		$this->hit( 'discovery.json', 'New', 'new' );

		Repository::prune();

		$this->assertSame( 1, $this->row_count(), 'the 10-day-old row is past a 7-day retention' );
	}

	public function test_prune_keeps_aged_rows_when_auto_prune_is_off() {
		$this->settings( array( 'activity_auto_prune' => false, 'activity_retention_days' => 7 ) );
		$this->hit( 'discovery.json', 'Old', 'old', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );
		$this->hit( 'discovery.json', 'New', 'new' );

		Repository::prune();

		$this->assertSame( 2, $this->row_count(), 'age-based pruning is off — nothing expires' );
	}

	/**
	 * The cap OUTRANKS the retention. Six rows, all minutes old, a retention of a full year,
	 * and a cap of three: the three oldest go anyway. "Keep records for 1 year" is a ceiling
	 * on age, never a promise that a row survives that long — the size limit is absolute.
	 */
	public function test_the_cap_deletes_rows_that_are_younger_than_the_retention() {
		$this->settings( array( 'activity_auto_prune' => true, 'activity_retention_days' => 365, 'activity_max_rows' => 3 ) );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->hit( 'discovery.json', 'Bot', 'UA-' . $i ); // all "now"
		}

		Repository::trim_to_cap();

		$this->assertSame( 3, $this->row_count() );
		$this->assertSame(
			array( 'UA-5', 'UA-4', 'UA-3' ),
			array_column( Repository::log( array( 'per_page' => 10 ) )['rows'], 'ua' ),
			'the newest survive; age never enters into it'
		);
	}

	/**
	 * The nightly cron must GUARANTEE the cap, not leave it to the sampled insert path.
	 *
	 * Recorder runs trim_to_cap() on roughly 1 insert in 200. That is fine while traffic
	 * flows, but it means lowering the cap on a quiet site does nothing for days, and with
	 * auto-delete off — where the cap is the only thing that collects — a table that stops
	 * receiving hits stays over its ceiling forever. Referrals::prune() already guarantees
	 * its own cap daily; this one didn't.
	 */
	public function test_the_daily_prune_enforces_the_cap_without_any_inserts() {
		$this->settings( array( 'activity_auto_prune' => false, 'activity_max_rows' => 2 ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->hit( 'discovery.json', 'Bot', 'UA-' . $i );
		}
		$this->assertSame( 5, $this->row_count(), 'sanity: the sampled trim did not fire' );

		Repository::prune(); // the cron's only entry point — no inserts follow

		$this->assertSame( 2, $this->row_count(), 'prune() must apply the cap even when nothing expires by age' );
	}

	/** …but the cap still collects, so "off" can never grow the table without bound. */
	public function test_the_row_cap_trims_even_with_auto_prune_off() {
		$this->settings( array( 'activity_auto_prune' => false, 'activity_max_rows' => 3 ) );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->hit( 'discovery.json', 'Bot', 'UA-' . $i );
		}

		Repository::trim_to_cap();

		$this->assertSame( 3, $this->row_count(), 'the cap drops the oldest rows regardless of age' );
		$rows = Repository::log( array( 'per_page' => 10 ) )['rows'];
		$this->assertSame( array( 'UA-5', 'UA-4', 'UA-3' ), array_column( $rows, 'ua' ), 'the NEWEST rows survive' );
	}

	/**
	 * The log's lower bound exists only because the prune deletes below it. With auto-delete
	 * off, rows older than the retention SURVIVE — and clamping the query to a retention
	 * "floor" would hide records the log is the one place you can read.
	 */
	public function test_the_log_reaches_rows_older_than_retention_when_auto_prune_is_off() {
		$this->settings( array( 'activity_auto_prune' => false, 'activity_retention_days' => 7 ) );
		$this->hit( 'discovery.json', 'Ancient', 'ancient', gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS ) );
		$this->hit( 'discovery.json', 'Recent', 'recent' );

		$page = Repository::log();

		$this->assertSame( 2, $page['total'], 'a surviving 45-day-old row must be listed' );
		$this->assertFalse( $page['autoPrune'], 'the payload tells the UI nothing expires by age' );
		$this->assertContains( 'ancient', array_column( $page['rows'], 'ua' ) );
		$this->assertContains( 'Ancient', Repository::log_facets()['agents'], 'and its client is offered as a filter' );
	}

	/** With auto-delete ON, the floor stands: nothing older than the retention can exist. */
	public function test_the_log_clamps_to_the_retention_floor_when_auto_prune_is_on() {
		$this->settings( array( 'activity_auto_prune' => true, 'activity_retention_days' => 7 ) );
		// A row the prune hasn't collected yet — the query must still refuse to show it.
		$this->hit( 'discovery.json', 'Ancient', 'ancient', gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS ) );
		$this->hit( 'discovery.json', 'Recent', 'recent' );

		$page = Repository::log();

		$this->assertSame( 1, $page['total'] );
		$this->assertTrue( $page['autoPrune'] );
		$this->assertSame( array( 'recent' ), array_column( $page['rows'], 'ua' ) );
	}

	/* -- Filter facets (what the dropdowns can offer) --------------------- */

	/** Facets are ordered by how often each value appears, so the common clients lead. */
	public function test_facets_are_ordered_by_frequency() {
		$this->hit( 'discovery.json', 'Rare', 'a' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->hit( 'llms.txt', 'Common', 'b' . $i );
		}

		$facets = Repository::log_facets();

		$this->assertSame( array( 'Common', 'Rare' ), $facets['agents'] );
		$this->assertSame( array( 'llms.txt', 'discovery.json' ), $facets['endpoints'] );
	}

	/** An empty column is not an option — "" would render as a blank row in the dropdown. */
	public function test_facets_skip_empty_values() {
		// network is '' unless "identify every bot" attributed it.
		$this->hit( 'discovery.json', 'GPTBot', 'a' );

		$this->assertSame( array(), Repository::log_facets()['networks'] );
	}

	/** Offering a value that can never match would be a dead option: bound by retention. */
	public function test_facets_ignore_values_outside_the_retained_window() {
		$this->hit( 'discovery.json', 'Ancient', 'old', gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS ) );
		$this->hit( 'discovery.json', 'Current', 'new' );

		$agents = Repository::log_facets()['agents'];

		$this->assertContains( 'Current', $agents );
		$this->assertNotContains( 'Ancient', $agents, 'a client no longer in the log cannot be filtered for' );
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
