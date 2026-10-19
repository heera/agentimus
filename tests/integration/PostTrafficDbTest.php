<?php
/**
 * PostTraffic — one post's traffic, read from where it already lands.
 *
 * ⭐ What these pin: each record names its post when it is written; rows written
 * before that are matched by path; a read never counts what is not a read
 * (refused, proven fake, another surface, outside the window); and the lists
 * can order by any of the three numbers.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\PageViews;
use Agentimus\Activity\Recorder;
use Agentimus\Activity\Referrals;
use Agentimus\Activity\Table as HitTable;
use Agentimus\PostTraffic;
use Agentimus\Search\Table as SearchTable;
use Agentimus\Settings;

final class PostTrafficDbTest extends DbTestCase {

	const BOT_UA     = 'SomeCrawler/1.0 (+https://example.test/bot)';
	const BROWSER_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

	/** @var array $_SERVER as we found it — borrowed, never unset. */
	private $server = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( HitTable::VERSION_OPTION );
		HitTable::install();
		delete_option( Referrals::VERSION_OPTION );
		Referrals::install();
		delete_option( SearchTable::VERSION_OPTION );
		SearchTable::install();
		$this->reset();
		update_option( Settings::OPTION, array( 'enable_activity' => true, 'log_page_views' => true ) );
		PageViews::reset_cache();
		wp_set_current_user( 0 );
		$this->server              = $_SERVER;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->reset_buckets();
	}

	public function tear_down(): void {
		PageViews::reset_cache();
		$_SERVER = $this->server;
		$this->reset_buckets();
		$this->reset();
		parent::tear_down();
	}

	/** ⚠️ DELETE, never TRUNCATE — TRUNCATE commits and ends the test's transaction. */
	private function reset() {
		global $wpdb;
		foreach ( array( HitTable::name(), Referrals::name(), SearchTable::name() ) as $table ) {
			$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
		}
		delete_option( PostTraffic::BACKFILL_OPTION );
	}

	private function reset_buckets(): void {
		$win = (int) floor( time() / Recorder::FLOOD_WINDOW );
		foreach ( array( 'a', 'u', 'pa', 'pu' ) as $bucket ) {
			delete_transient( Recorder::RATE_PREFIX . md5( $bucket ) . '_' . $win );
		}
	}

	private function post() {
		return (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	private function hit( $post_id, array $over = array() ) {
		global $wpdb;
		$wpdb->insert( HitTable::name(), array_merge( array(
			'endpoint' => 'page',
			'agent'    => 'GPTBot (OpenAI)',
			'ua'       => 'GPTBot/1.0',
			'verdict'  => 1,
			'refused'  => 0,
			'path'     => '',
			'post_id'  => $post_id,
			'hit_at'   => gmdate( 'Y-m-d H:i:s' ),
		), $over ) ); // phpcs:ignore WordPress.DB
	}

	private function referral( $post_id, $source, $hits, array $over = array() ) {
		global $wpdb;
		$wpdb->insert( Referrals::name(), array_merge( array(
			'day'     => gmdate( 'Y-m-d' ),
			'source'  => $source,
			'path'    => '/p-' . $post_id . '-' . $source,
			'post_id' => $post_id,
			'hits'    => $hits,
		), $over ) ); // phpcs:ignore WordPress.DB
	}

	/* -- Each record names its post when it is written ------------------------ */

	public function test_a_bot_reading_a_post_is_recorded_against_that_post() {
		$id                         = $this->post();
		$_SERVER['HTTP_USER_AGENT'] = self::BOT_UA;

		$this->go_to( get_permalink( $id ) );
		PageViews::maybe_record();

		global $wpdb;
		$this->assertSame( (string) $id, $wpdb->get_var( 'SELECT post_id FROM ' . HitTable::name() ) ); // phpcs:ignore WordPress.DB
	}

	public function test_an_archive_read_belongs_to_no_post() {
		$this->post();
		$_SERVER['HTTP_USER_AGENT'] = self::BOT_UA;

		$this->go_to( home_url( '/' ) );
		PageViews::maybe_record();

		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( 'SELECT post_id FROM ' . HitTable::name() ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_visit_an_assistant_sent_is_counted_against_the_post_it_landed_on() {
		$id                         = $this->post();
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		$_SERVER['HTTP_REFERER']    = 'https://chatgpt.com/';

		$this->go_to( get_permalink( $id ) );
		$_SERVER['REQUEST_URI'] = '/?p=' . $id; // Plain permalinks: the stored path is "/", so only the queried post can name it.
		Referrals::maybe_record();

		$this->assertSame( 1, PostTraffic::for_post( $id )['ai']['total'] );
	}

	public function test_the_browser_beacon_resolves_its_path_to_the_post() {
		$id = $this->post();
		update_option( Settings::OPTION, array( 'enable_activity' => true, 'enable_referral_beacon' => true ) );
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		// A stored path drops the query, so under plain permalinks ("/?p=N") it could
		// never name the post — the beacon is handed a pretty path instead.
		$this->set_permalink_structure( '/%postname%/' );
		$path = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );

		// Older-row matching would find this by path too; switched off, the row must name its post itself.
		update_option( PostTraffic::BACKFILL_OPTION, array( 'hits' => 'done', 'referrals' => 'done' ) );

		$this->assertTrue( Referrals::record_from_client( 'https://www.perplexity.ai/', '', $path ) );
		$this->assertSame( array( array( 'label' => 'Perplexity', 'hits' => 1 ) ), PostTraffic::for_post( $id )['ai']['bySource'] );
	}

	/* -- What counts as a read ------------------------------------------------- */

	public function test_bot_reads_count_pages_and_markdown_but_not_refusals_fakes_or_other_surfaces() {
		$id = $this->post();
		$this->hit( $id );                                                       // counts
		$this->hit( $id, array( 'endpoint' => 'markdown', 'agent' => 'ClaudeBot (Anthropic)' ) ); // counts
		$this->hit( $id, array( 'verdict' => 0, 'agent' => 'CCBot (Common Crawl)' ) );           // counts, unchecked
		$this->hit( $id, array( 'refused' => 1 ) );                              // turned away, not read
		$this->hit( $id, array( 'verdict' => 2 ) );                              // proven fake
		$this->hit( $id, array( 'endpoint' => 'llms.txt' ) );                    // another surface
		$this->hit( $id, array( 'hit_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) ); // outside the window
		$this->hit( $this->post() );                                             // another post
		$this->hit( $id, array( 'agent' => 'Script/tool', 'verdict' => 0 ) );    // a script, not a bot reading
		$this->hit( $id, array( 'agent' => 'WordPress', 'verdict' => 0 ) );      // a WordPress site (its own loopback)

		$bots = PostTraffic::for_post( $id )['bots'];

		$this->assertSame( 3, $bots['total'] );
		$this->assertSame( 2, $bots['notReaders'], 'scripts and WordPress sites are counted apart, not silently gone' );
		$this->assertSame( 3, PostTraffic::totals_for( array( $id ) )[ $id ]['bots'], 'the list and the detail agree' );
		$this->assertSame( 2, $bots['leftOut'], 'the refused and the fake are named as left out, not silently gone' );
		$by = array();
		foreach ( $bots['byBot'] as $b ) {
			$by[ $b['label'] ] = $b;
		}
		$this->assertTrue( $by['GPTBot (OpenAI)']['verified'] );
		$this->assertFalse( $by['CCBot (Common Crawl)']['verified'], 'an unchecked bot is counted, and says it was not checked' );
	}

	public function test_ai_visits_stay_inside_the_window() {
		$id = $this->post();
		$this->referral( $id, 'ChatGPT', 4 );
		$this->referral( $id, 'Perplexity', 9, array( 'day' => gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) );

		$this->assertSame( 4, PostTraffic::for_post( $id )['ai']['total'] );
	}

	/* -- Rows older than the column -------------------------------------------- */

	public function test_older_rows_are_matched_to_their_post_by_path() {
		$id = $this->post();
		$this->set_permalink_structure( '/%postname%/' );
		$path = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
		$this->hit( 0, array( 'path' => $path ) );
		$this->hit( 0, array( 'path' => '/no-such-page/' ) );
		$this->referral( 0, 'ChatGPT', 3, array( 'path' => $path ) );

		$this->assertFalse( PostTraffic::backfill_done() );
		$traffic = PostTraffic::for_post( $id );

		$this->assertSame( 1, $traffic['bots']['total'] );
		$this->assertSame( 3, $traffic['ai']['total'] );
		$this->assertFalse( $traffic['matching'], 'one step finishes a small log' );
	}

	public function test_matching_says_so_until_every_older_path_is_walked() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->hit( 0, array( 'path' => "/old-$i/" ) );
		}

		$this->assertFalse( PostTraffic::backfill( 2 ), 'two of three paths walked' );
		$this->assertTrue( PostTraffic::backfill( 2 ) );
	}

	/* -- Search ------------------------------------------------------------------ */

	public function test_search_is_summed_per_post_with_an_impression_weighted_position() {
		$id = $this->post();
		( new \Agentimus\Google\Settings() )->connect( '{"type":"service_account"}', 'bot@example.com', 'sc-domain:example.com' );
		$row = static function ( $q, $clicks, $impr, $pos ) use ( $id ) {
			return array( 'query' => $q, 'page_url' => get_permalink( $id ), 'page_id' => $id, 'clicks' => $clicks, 'impressions' => $impr, 'position' => $pos, 'range_start' => '2026-09-01', 'range_end' => '2026-09-28' );
		};
		SearchTable::replace( 'google', array( $row( 'a', 10, 300, 2.0 ), $row( 'b', 1, 100, 10.0 ) ) );

		$search = PostTraffic::for_post( $id )['search'];

		$this->assertSame( 11, $search['clicks'] );
		$this->assertSame( 400, $search['impressions'] );
		$this->assertSame( 4.0, $search['position'], '(2×300 + 10×100) / 400' );
		$this->assertSame( 'Google', $search['engine'] );
		$this->assertNull( PostTraffic::for_post( $this->post() )['search'], 'nothing reported is null, not zero' );
	}

	/* -- Many posts at once, and ordering ---------------------------------------- */

	public function test_totals_for_many_posts_match_the_single_post_read() {
		$a = $this->post();
		$b = $this->post();
		$this->referral( $a, 'ChatGPT', 5 );
		$this->hit( $b );
		$this->hit( $b );

		$totals = PostTraffic::totals_for( array( $a, $b ) );

		$this->assertSame( array( 'ai' => 5, 'bots' => 0, 'clicks' => null ), $totals[ $a ] );
		$this->assertSame( PostTraffic::for_post( $b )['bots']['total'], $totals[ $b ]['bots'] );
	}

	public function test_the_join_orders_posts_by_the_chosen_number() {
		global $wpdb;
		$quiet = $this->post();
		$busy  = $this->post();
		$this->referral( $busy, 'ChatGPT', 7 );
		$this->referral( $quiet, 'ChatGPT', 1 );

		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p" . PostTraffic::join_sql( 'ai', 'p.ID' ) .
			$wpdb->prepare( ' WHERE p.ID IN (%d, %d) ORDER BY COALESCE(pt.n, 0) DESC', $quiet, $busy )
		); // phpcs:ignore WordPress.DB

		$this->assertSame( array( (string) $busy, (string) $quiet ), $ids );
	}

	/* -- The agent's door ------------------------------------------------------- */

	/**
	 * ⛔ An undeclared key makes the tool reject its own answer for every MCP
	 * client. Validated against the REAL registered schema, on a full payload
	 * (every list populated, search present) and an empty one (search null).
	 */
	public function test_read_post_traffic_survives_its_own_output_schema() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'No Abilities API on this core.' );
		}
		$ability = wp_get_ability( 'agentimus/read-post-traffic' );
		if ( ! $ability ) {
			$this->markTestSkipped( 'read-post-traffic is not registered on this core.' );
		}
		$schema = $ability->get_output_schema();

		$id = $this->post();
		$this->referral( $id, 'ChatGPT', 2 );
		$this->hit( $id );
		( new \Agentimus\Google\Settings() )->connect( '{"type":"service_account"}', 'bot@example.com', 'sc-domain:example.com' );
		SearchTable::replace( 'google', array( array( 'query' => 'q', 'page_url' => get_permalink( $id ), 'page_id' => $id, 'clicks' => 1, 'impressions' => 9, 'position' => 3.0, 'range_start' => '2026-09-01', 'range_end' => '2026-09-28' ) ) );

		foreach ( array( 'full' => $id, 'empty' => $this->post() ) as $name => $post ) {
			$out = PostTraffic::for_post( $post );
			if ( 'full' === $name ) {
				$this->assertNotEmpty( $out['ai']['bySource'] );
				$this->assertNotEmpty( $out['bots']['byBot'] );
				$this->assertNotNull( $out['search'] );
			}
			$valid = rest_validate_value_from_schema( $out, $schema, 'read-post-traffic' );
			$this->assertNotWPError( $valid, "read-post-traffic rejected its own `$name` answer: " . ( is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
		}
	}

	/* -- WordPress's own posts list --------------------------------------------- */

	public function test_the_posts_list_column_sorts_by_ai_visits() {
		$quiet = $this->post();
		$busy  = $this->post();
		$none  = $this->post();
		$this->referral( $busy, 'ChatGPT', 7 );
		$this->referral( $quiet, 'ChatGPT', 1 );

		$column = new \Agentimus\PostTrafficColumn( new Settings() );
		add_filter( 'posts_clauses', array( $column, 'order' ), 10, 2 );
		$saved = $GLOBALS['wp_the_query'];
		$q     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $q; // The column orders the screen's main list only.
		$ids   = $q->query( array( 'post__in' => array( $quiet, $busy, $none ), 'orderby' => \Agentimus\PostTrafficColumn::COLUMN, 'order' => 'DESC', 'fields' => 'ids' ) );
		$GLOBALS['wp_the_query'] = $saved;
		remove_filter( 'posts_clauses', array( $column, 'order' ), 10 );

		$this->assertSame( array( $busy, $quiet, $none ), array_map( 'intval', $ids ) );
	}

	public function test_a_failed_read_never_marks_matching_finished() {
		global $wpdb;
		$hits = HitTable::name();
		$wpdb->query( "RENAME TABLE $hits TO {$hits}_away" ); // phpcs:ignore WordPress.DB
		$suppress = $wpdb->suppress_errors( true );
		$done     = PostTraffic::backfill();
		$wpdb->suppress_errors( $suppress );
		$wpdb->query( "RENAME TABLE {$hits}_away TO $hits" ); // phpcs:ignore WordPress.DB

		$this->assertFalse( $done );
		$this->assertFalse( PostTraffic::backfill_done() );
	}

	/* -- The detail an opened row shows ------------------------------------------ */

	public function test_the_daily_series_covers_every_day_of_the_window_quiet_ones_included() {
		$id = $this->post();
		$this->referral( $id, 'ChatGPT', 3 );
		$this->hit( $id, array( 'hit_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );

		$daily = PostTraffic::for_post( $id )['daily'];

		$this->assertCount( PostTraffic::window(), $daily );
		$this->assertSame( array( 'date' => gmdate( 'Y-m-d' ), 'ai' => 3, 'bots' => 0 ), end( $daily ) );
		$this->assertSame( 1, $daily[ count( $daily ) - 3 ]['bots'] );
		$this->assertSame( 0, $daily[0]['ai'] + $daily[0]['bots'], 'a quiet day is a zero, not a gap' );
	}

	public function test_each_bot_says_when_it_last_read_the_page() {
		$id = $this->post();
		$this->hit( $id, array( 'hit_at' => '2026-10-01 08:00:00' ) );
		$this->hit( $id, array( 'hit_at' => gmdate( 'Y-m-d' ) . ' 09:30:00' ) );

		$this->assertSame( gmdate( 'Y-m-d' ) . ' 09:30:00', PostTraffic::for_post( $id )['bots']['byBot'][0]['last'] );
	}

	public function test_the_searches_that_brought_people_are_listed_most_clicks_first() {
		$id = $this->post();
		( new \Agentimus\Google\Settings() )->connect( '{"type":"service_account"}', 'bot@example.com', 'sc-domain:example.com' );
		$row = static function ( $q, $clicks ) use ( $id ) {
			return array( 'query' => $q, 'page_url' => get_permalink( $id ), 'page_id' => $id, 'clicks' => $clicks, 'impressions' => 50, 'position' => 4.0, 'range_start' => '2026-09-01', 'range_end' => '2026-09-28' );
		};
		SearchTable::replace( 'google', array( $row( 'few', 1 ), $row( 'most', 9 ) ) );

		$queries = PostTraffic::for_post( $id )['search']['queries'];

		$this->assertSame( array( 'most', 'few' ), wp_list_pluck( $queries, 'query' ) );
	}

	/** ⭐ "See every read" lands on exactly the rows the count was made from — plus refusals and fakes, which say what they are. */
	public function test_the_request_log_narrows_to_one_post() {
		$id = $this->post();
		$this->hit( $id );
		$this->hit( $id, array( 'endpoint' => 'markdown' ) );
		$this->hit( $this->post() );

		$rows = \Agentimus\Activity\Repository::log( array( 'post' => $id ) )['rows'];

		$this->assertCount( 2, $rows );
	}

	public function test_the_posts_list_column_says_when_its_numbers_may_still_rise() {
		$column = new \Agentimus\PostTrafficColumn( new Settings() );
		$this->hit( 0, array( 'path' => '/an-older-row/' ) );

		$this->assertStringContainsString( 'Still matching older traffic', $column->columns( array() )[ \Agentimus\PostTrafficColumn::COLUMN ] );

		PostTraffic::backfill();
		$this->assertSame( 'Readers From AI', $column->columns( array() )[ \Agentimus\PostTrafficColumn::COLUMN ], 'the note goes once matching is done' );
	}

	/* -- The Request Log's Pages view --------------------------------------------- */

	public function test_the_pages_view_lists_busiest_first_with_the_same_counts_as_one_post() {
		$quiet = $this->post();
		$busy  = $this->post();
		$ai    = $this->post();
		$this->hit( $quiet );
		$this->hit( $busy );
		$this->hit( $busy, array( 'agent' => 'ClaudeBot (Anthropic)' ) );
		$this->hit( $busy, array( 'agent' => 'Script/tool' ) ); // not a bot reading
		$this->referral( $ai, 'ChatGPT', 5 );                   // AI visits only, no bot read

		$out = PostTraffic::pages();

		$this->assertSame( array( $busy, $quiet, $ai ), wp_list_pluck( $out['pages'], 'postId' ) );
		$this->assertSame( 3, $out['pageTotal'] );
		$this->assertSame( PostTraffic::for_post( $busy )['bots']['total'], $out['pages'][0]['bots'] );
		$this->assertSame( 2, $out['pages'][0]['botKinds'] );
		$this->assertSame( 5, $out['pages'][2]['ai'] );
		$this->assertSame( '', $out['pages'][2]['last'], 'a page only AI sent readers to has no last bot read' );
	}

	public function test_reads_that_belong_to_no_post_are_counted_not_lost() {
		$this->hit( 0, array( 'path' => '/category/news/' ) );
		update_option( PostTraffic::BACKFILL_OPTION, array( 'hits' => 'done', 'referrals' => 'done' ) );

		$this->assertSame( 1, PostTraffic::pages()['unattributed'] );
	}

	public function test_the_pages_view_keeps_to_its_dates_and_pages() {
		$id = $this->post();
		$this->hit( $id, array( 'hit_at' => '2026-10-01 10:00:00' ) );
		$this->hit( $id, array( 'hit_at' => '2026-10-05 10:00:00' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->hit( $this->post(), array( 'hit_at' => '2026-10-05 11:00:00' ) );
		}

		$this->assertSame( 1, PostTraffic::pages( array( 'from' => '2026-10-01', 'to' => '2026-10-01' ) )['pages'][0]['bots'] );
		$two = PostTraffic::pages( array( 'from' => '2026-10-01', 'to' => '2026-10-05', 'per_page' => 2, 'offset' => 2 ) );
		$this->assertCount( 2, $two['pages'] );
		$this->assertSame( 4, $two['pageTotal'] );
	}

	public function test_read_request_log_pages_survives_its_own_output_schema() {
		if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( 'agentimus/read-request-log' ) ) {
			$this->markTestSkipped( 'No Abilities API on this core.' );
		}
		$id = $this->post();
		$this->hit( $id );
		$this->referral( $id, 'ChatGPT', 1 );
		$this->hit( 0, array( 'path' => '/tag/x/' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out   = wp_get_ability( 'agentimus/read-request-log' )->execute( array( 'group' => 'pages' ) );
		wp_set_current_user( 0 );
		$valid = rest_validate_value_from_schema( $out, wp_get_ability( 'agentimus/read-request-log' )->get_output_schema(), 'read-request-log' );

		$this->assertNotWPError( $out );
		$this->assertNotEmpty( $out['pages'] );
		$this->assertNotWPError( $valid, is_wp_error( $valid ) ? $valid->get_error_message() : '' );
	}

	/* -- What cannot be placed on a page ---------------------------------------- */

	/** ⛔ heera.it, 2026-10-19: the blog index came out as a page called "Blog". */
	public function test_the_blog_index_is_never_one_page_and_earlier_matches_are_undone() {
		$this->set_permalink_structure( '/%postname%/' );
		$index = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'blog' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_for_posts', $index );

		$this->assertSame( 0, PostTraffic::post_for_path( '/blog/' ) );

		// A row an earlier build matched to it, on a site that had finished matching.
		$this->hit( $index, array( 'path' => '/blog/' ) );
		update_option( PostTraffic::BACKFILL_OPTION, array( 'hits' => 'done', 'referrals' => 'done' ) );

		$this->assertSame( array(), wp_list_pluck( PostTraffic::pages()['pages'], 'postId' ) );
		$this->assertSame( 1, PostTraffic::pages()['unattributed'] );
	}

	/** ⛔ Reads logged before the log named their page cannot be placed — and the screens must say so. */
	public function test_reads_logged_without_their_page_are_named_and_date_the_window() {
		$id = $this->post();
		$this->hit( 0, array( 'path' => '', 'hit_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );
		$this->hit( $id, array( 'path' => '/p/', 'hit_at' => gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) . ' 08:00:00' ) );
		update_option( PostTraffic::BACKFILL_OPTION, array( 'hits' => 'done', 'referrals' => 'done', 'blog' => 'done' ) );

		$out = PostTraffic::pages();

		$this->assertSame( 1, $out['noAddress'] );
		$this->assertSame( 0, $out['unattributed'], 'a read with no address is not an archive read' );
		$this->assertSame( gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ), $out['knownSince'] );
		$this->assertSame( gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ), PostTraffic::for_post( $id )['knownSince'] );
	}

	public function test_a_window_where_every_read_has_its_page_claims_nothing() {
		$id = $this->post();
		$this->hit( $id, array( 'path' => '/p/' ) );

		$this->assertSame( '', PostTraffic::for_post( $id )['knownSince'] );
	}
}
