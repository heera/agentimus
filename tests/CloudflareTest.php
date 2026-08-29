<?php
/**
 * Cloudflare edge source — the pure halves: conflict detection, the raw-row
 * aggregation, crawler-token matching, the client's response normalization,
 * and the token's at-rest encryption.
 *
 * @package Agentimus\Tests
 */

use Agentimus\Cloudflare\Client;
use Agentimus\Cloudflare\Conflicts;
use Agentimus\Cloudflare\Module;
use Agentimus\Cloudflare\Purge;
use Agentimus\Cloudflare\Settings;
use Agentimus\Cloudflare\SpoofCheck;
use Agentimus\Visibility\Crypto;
use PHPUnit\Framework\TestCase;

final class CloudflareTest extends TestCase {

	protected function setUp(): void {
		_af_reset_options();
	}

	// ── Conflicts::detect ───────────────────────────────────────────────────

	private function crawler( $ua, $operator, $requests, $cached, $origin, $blocked ) {
		return array(
			'ua'       => $ua,
			'name'     => $ua,
			'operator' => $operator,
			'requests' => $requests,
			'cached'   => $cached,
			'origin'   => $origin,
			'blocked'  => $blocked,
		);
	}

	public function test_edge_blocking_an_allowed_operator_is_a_warn_conflict() {
		// The real July 2026 case: Bot Fight Mode challenging ChatGPT-User while
		// the site policy allowed AI reading.
		$out = Conflicts::detect(
			array( $this->crawler( 'chatgpt-user', 'OpenAI', 486, 0, 274, 212 ) ),
			array( 'ai_input' => true, 'ai_train' => true, 'blocked_agents' => array() ),
			7
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 'warn', $out[0]['level'] );
		$this->assertStringContainsString( 'OpenAI', $out[0]['title'] );
		$this->assertSame( 'bots', $out[0]['link'] );
	}

	public function test_no_conflict_when_the_owner_disallows_ai_reading() {
		// ai-input=no means the edge blocking readers AGREES with the owner.
		$out = Conflicts::detect(
			array( $this->crawler( 'chatgpt-user', 'OpenAI', 486, 0, 274, 212 ) ),
			array( 'ai_input' => false, 'ai_train' => true, 'blocked_agents' => array() ),
			7
		);
		$this->assertSame( array(), $out );
	}

	public function test_no_conflict_for_a_crawler_the_owner_blocks_deliberately() {
		$out = Conflicts::detect(
			array( $this->crawler( 'bytespider', 'ByteDance', 400, 0, 0, 400 ) ),
			array( 'ai_input' => true, 'ai_train' => true, 'blocked_agents' => array( 'bytespider' ) ),
			7
		);
		$this->assertSame( array(), $out );
	}

	public function test_one_blocked_fetcher_is_not_diluted_by_the_operators_clean_crawler() {
		// The exact wpftest-staging case that caught this: GPTBot passes with
		// 1,200 clean requests while ChatGPT-User is 44% blocked. An
		// operator-level share test would hide the breakage; the per-crawler
		// threshold must still fire.
		$out = Conflicts::detect(
			array(
				$this->crawler( 'gptbot', 'OpenAI', 1200, 900, 300, 0 ),
				$this->crawler( 'chatgpt-user', 'OpenAI', 486, 0, 273, 213 ),
			),
			array( 'ai_input' => true, 'ai_train' => true, 'blocked_agents' => array() ),
			7
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 'warn', $out[0]['level'] );
		$this->assertSame( 'edge-blocks-openai', $out[0]['id'] );
	}

	public function test_a_fixed_conflict_stops_firing_within_a_day() {
		// The week's totals stay significant, but the owner fixed the cause
		// yesterday (e.g. turned Bot Fight Mode off) — no blocked requests in the
		// recent window means the warning must retire, not nag for seven days.
		$out = Conflicts::detect(
			array( $this->crawler( 'perplexitybot', 'Perplexity', 107, 7, 72, 28 ) ),
			array( 'ai_input' => true, 'ai_train' => true, 'blocked_agents' => array() ),
			7,
			array( 'perplexitybot' => array( 'blocked' => 0, 'passed' => 12 ) )
		);
		$this->assertSame( array(), $out );
	}

	public function test_a_still_happening_conflict_keeps_firing() {
		$out = Conflicts::detect(
			array( $this->crawler( 'perplexitybot', 'Perplexity', 107, 7, 72, 28 ) ),
			array( 'ai_input' => true, 'ai_train' => true, 'blocked_agents' => array() ),
			7,
			array( 'perplexitybot' => array( 'blocked' => 4, 'passed' => 3 ) )
		);
		$this->assertCount( 1, $out );
		$this->assertSame( 'warn', $out[0]['level'] );
	}

	public function test_the_training_notice_retires_once_the_edge_enforces() {
		// heera.it's exact evening: 93 trainer passes THIS WEEK, but the owner set
		// Training to Block — zero passes in the recent window, so the notice goes.
		$out = Conflicts::detect(
			array( $this->crawler( 'gptbot', 'OpenAI', 93, 20, 73, 0 ) ),
			array( 'ai_input' => true, 'ai_train' => false, 'blocked_agents' => array() ),
			7,
			array( 'gptbot' => array( 'blocked' => 6, 'passed' => 0 ) )
		);
		$this->assertSame( array(), $out );
	}

	public function test_edge_blocking_a_trainer_under_ai_train_no_is_agreement_not_conflict() {
		// heera.it's live case: Cloudflare's Training policy set to Block enforces
		// the declared ai-train=no — including for trainers the owner never named
		// in the block list. A reading fetcher blocked the same way still warns.
		$out = Conflicts::detect(
			array(
				$this->crawler( 'ccbot', 'Common Crawl', 100, 0, 0, 100 ),
				$this->crawler( 'chatgpt-user', 'OpenAI', 100, 0, 50, 50 ),
			),
			array( 'ai_input' => true, 'ai_train' => false, 'blocked_agents' => array() ),
			7
		);

		$warns = array_values( array_filter( $out, static function ( $c ) {
			return 'warn' === $c['level'];
		} ) );
		$this->assertCount( 1, $warns );
		$this->assertSame( 'edge-blocks-openai', $warns[0]['id'] );
	}

	public function test_owner_blocks_match_case_insensitively() {
		// The block list stores names as typed ("Bytespider"); rows carry
		// lowercase tokens. A deliberate block must still suppress the conflict.
		$out = Conflicts::detect(
			array( $this->crawler( 'bytespider', 'ByteDance', 400, 0, 0, 400 ) ),
			array( 'ai_input' => true, 'ai_train' => true, 'blocked_agents' => array( 'Bytespider' ) ),
			7
		);
		$this->assertSame( array(), $out );
	}

	public function test_blips_below_the_thresholds_stay_quiet() {
		// 9 blocked (below MIN_BLOCKED), and 50/1000 (below the 20% share).
		$out = Conflicts::detect(
			array(
				$this->crawler( 'gptbot', 'OpenAI', 100, 50, 41, 9 ),
				$this->crawler( 'claudebot', 'Anthropic', 1000, 700, 250, 50 ),
			),
			array( 'ai_input' => true, 'ai_train' => true, 'blocked_agents' => array() ),
			7
		);
		$this->assertSame( array(), $out );
	}

	public function test_unenforced_no_training_line_is_an_info_conflict() {
		$out = Conflicts::detect(
			array( $this->crawler( 'gptbot', 'OpenAI', 200, 150, 50, 0 ) ),
			array( 'ai_input' => true, 'ai_train' => false, 'blocked_agents' => array() ),
			7
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 'info', $out[0]['level'] );
		$this->assertSame( 'train-not-enforced', $out[0]['id'] );
		$this->assertSame( 'ai-crawlers', $out[0]['link'] );
	}

	public function test_warns_come_before_infos() {
		$out = Conflicts::detect(
			array(
				$this->crawler( 'chatgpt-user', 'OpenAI', 486, 0, 274, 212 ),
				$this->crawler( 'gptbot', 'OpenAI', 200, 150, 50, 0 ),
			),
			array( 'ai_input' => true, 'ai_train' => false, 'blocked_agents' => array() ),
			7
		);

		$this->assertCount( 2, $out );
		$this->assertSame( 'warn', $out[0]['level'] );
		$this->assertSame( 'info', $out[1]['level'] );
	}

	// ── Module::aggregate ───────────────────────────────────────────────────

	private function raw( $ua, $cache, $edge, $origin, $requests, $bytes = 0, $hour = '2026-07-30T14:00:00Z' ) {
		return array(
			'hour'          => $hour,
			'ua'            => $ua,
			'cache_status'  => $cache,
			'edge_status'   => $edge,
			'origin_status' => $origin,
			'requests'      => $requests,
			'bytes'         => $bytes,
		);
	}

	public function test_aggregate_partitions_each_request_into_exactly_one_bucket() {
		$rows = Module::aggregate( array(
			// Cache-served.
			$this->raw( 'Mozilla/5.0 GPTBot/1.2', 'hit', 200, 0, 900, 1000 ),
			// Reached the origin.
			$this->raw( 'Mozilla/5.0 GPTBot/1.2', 'dynamic', 200, 200, 300, 500 ),
			// Blocked AT THE EDGE: 403 with the origin never contacted.
			$this->raw( 'Mozilla/5.0 GPTBot/1.2', 'unknown', 403, 0, 50, 10 ),
		) );

		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( 'gptbot', $row['ua'] );
		$this->assertSame( '2026-07-30 14:00:00', $row['hour_at'] );
		$this->assertSame( 1250, $row['requests'] );
		$this->assertSame( 900, $row['cached'] );
		$this->assertSame( 300, $row['origin'] );
		$this->assertSame( 50, $row['blocked'] );
		$this->assertSame( 1510, $row['bytes'] );
	}

	public function test_an_origin_403_is_not_an_edge_block() {
		// The Guard refusing a trainer at the ORIGIN also shows as a 403 — but the
		// origin was contacted, so it must count as origin traffic, not edge-blocked.
		$rows = Module::aggregate( array(
			$this->raw( 'Bytespider/1.0', 'unknown', 403, 403, 40 ),
		) );

		$this->assertSame( 0, $rows[0]['blocked'] );
		$this->assertSame( 40, $rows[0]['origin'] );
	}

	public function test_aggregate_drops_unknown_and_non_ai_user_agents() {
		$rows = Module::aggregate( array(
			$this->raw( 'Mozilla/5.0 (Windows NT 10.0) Chrome/126', 'hit', 200, 0, 5000 ),
			$this->raw( 'SemrushBot/7~bl', 'hit', 200, 0, 800 ),        // seo kind
			$this->raw( 'Mozilla/5.0 AhrefsBot/7.0', 'miss', 200, 200, 700 ), // seo kind
			$this->raw( 'ClaudeBot/1.0', 'hit', 200, 0, 100 ),          // the one AI crawler
		) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'claudebot', $rows[0]['ua'] );
	}

	public function test_crawler_token_matches_ai_only() {
		$this->assertSame( 'gptbot', Module::crawler_token( 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)' ) );
		$this->assertSame( 'chatgpt-user', Module::crawler_token( 'ChatGPT-User/2.0' ) );
		$this->assertSame( '', Module::crawler_token( 'Mozilla/5.0 (compatible; SemrushBot/7~bl)' ) );
		$this->assertSame( '', Module::crawler_token( '' ) );
		$this->assertSame( '', Module::crawler_token( 'Some ordinary browser' ) );
	}

	// ── Client response normalization ───────────────────────────────────────

	public function test_hourly_traffic_normalizes_the_graphql_shape() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => json_encode( array(
				'data' => array( 'viewer' => array( 'zones' => array( array(
					'httpRequestsAdaptiveGroups' => array(
						array(
							'count'      => 12,
							'sum'        => array( 'edgeResponseBytes' => 3456 ),
							'dimensions' => array(
								'datetimeHour'         => '2026-07-30T14:00:00Z',
								'userAgent'            => 'GPTBot/1.2',
								'cacheStatus'          => 'HIT',
								'edgeResponseStatus'   => 200,
								'originResponseStatus' => 0,
							),
						),
					),
				) ) ) ),
			) ),
		);

		$out = ( new Client() )->hourly_traffic( 'tok', 'zone1', 0, 3600 );

		$this->assertArrayNotHasKey( 'error', $out );
		$this->assertCount( 1, $out['rows'] );
		$this->assertSame( 'hit', $out['rows'][0]['cache_status'] ); // lower-cased
		$this->assertSame( 12, $out['rows'][0]['requests'] );
		$this->assertSame( 3456, $out['rows'][0]['bytes'] );
	}

	public function test_a_missing_container_is_an_error_not_empty_data() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => '{"data":{"viewer":{}}}',
		);
		$out = ( new Client() )->hourly_traffic( 'tok', 'zone1', 0, 3600 );
		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_graphql_errors_surface_even_on_http_200() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => '{"errors":[{"message":"zone not authorized"}]}',
		);
		$out = ( new Client() )->hourly_traffic( 'tok', 'zone1', 0, 3600 );
		$this->assertSame( 'zone not authorized', $out['error'] );
	}

	public function test_find_zone_walks_up_the_labels() {
		// www.blog.example.com misses twice, example.com hits.
		$miss = array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '{"result":[]}' );
		$GLOBALS['_af_http_queue'][] = $miss;
		$GLOBALS['_af_http_queue'][] = $miss;
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => '{"result":[{"id":"z9","name":"example.com"}]}',
		);

		$out = ( new Client() )->find_zone( 'tok', 'www.blog.example.com' );
		$this->assertSame( 'z9', $out['id'] );
		$this->assertSame( 'example.com', $out['name'] );
	}

	// ── Settings: the token at rest ─────────────────────────────────────────

	public function test_token_is_stored_encrypted_and_never_in_public_view() {
		$settings = new Settings();
		$settings->connect( 'cf-secret-token', 'zone1', 'example.com' );

		$stored = $GLOBALS['_af_options'][ Settings::OPTION ]['token'];
		if ( Crypto::available() ) {
			$this->assertNotSame( 'cf-secret-token', $stored );
			$this->assertStringStartsWith( 'enc:v1:', $stored );
		}
		$this->assertSame( 'cf-secret-token', $settings->token() );

		$view = $settings->public_view();
		$this->assertTrue( $view['connected'] );
		$this->assertArrayNotHasKey( 'token', $view );
		$this->assertStringNotContainsString( 'cf-secret-token', wp_json_encode( $view ) );
	}

	public function test_dismissed_conflicts_reset_once_the_situation_ends() {
		$settings = new Settings();
		$settings->dismiss( 'edge-blocks-openai' );
		$this->assertSame( array( 'edge-blocks-openai' ), $settings->dismissed_ids() );

		// The conflict is still firing: the hide holds.
		$settings->prune_dismissed( array( 'edge-blocks-openai', 'train-not-enforced' ) );
		$this->assertSame( array( 'edge-blocks-openai' ), $settings->dismissed_ids() );

		// The conflict stopped firing: the dismissal is forgotten, so a future
		// recurrence shows again instead of staying silenced forever.
		$settings->prune_dismissed( array( 'train-not-enforced' ) );
		$this->assertSame( array(), $settings->dismissed_ids() );
	}

	public function test_undismiss_brings_one_hidden_conflict_back() {
		$settings = new Settings();
		$settings->dismiss( 'edge-blocks-openai' );
		$settings->dismiss( 'train-not-enforced' );

		$settings->undismiss( 'edge-blocks-openai' );
		$this->assertSame( array( 'train-not-enforced' ), $settings->dismissed_ids() );

		// Unknown id: a quiet no-op, never an error.
		$settings->undismiss( 'never-dismissed' );
		$this->assertSame( array( 'train-not-enforced' ), $settings->dismissed_ids() );
	}

	public function test_disconnect_forgets_everything() {
		$settings = new Settings();
		$settings->connect( 'cf-secret-token', 'zone1', 'example.com' );
		$settings->disconnect();

		$this->assertFalse( $settings->connected() );
		$this->assertSame( '', $settings->token() );
	}

	// ── Purge: the write half of the edge integration ───────────────────────

	private function purge_ok() {
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}', 'headers' => array() );
	}

	private function purge_denied() {
		// What a token without Zone → Cache Purge → Purge gets back.
		return array( 'response' => array( 'code' => 403 ), 'body' => '{"success":false,"errors":[{"message":"Unauthorized to access requested resource"}]}', 'headers' => array() );
	}

	public function test_purge_urls_batches_at_cloudflares_limit() {
		$urls = array();
		for ( $i = 0; $i < 35; $i++ ) {
			$urls[] = "https://example.test/p$i/";
		}
		$GLOBALS['_af_http_queue'] = array( $this->purge_ok(), $this->purge_ok() );

		$out = ( new Client() )->purge_urls( 'tok', 'zone1', $urls );
		$this->assertTrue( $out['ok'] );
		$this->assertSame( 35, $out['purged'] );
		$this->assertEmpty( $GLOBALS['_af_http_queue'], 'exactly two calls went out — 30 + 5' );
		$body = json_decode( (string) $GLOBALS['_af_http_last']['args']['body'], true );
		$this->assertCount( 5, $body['files'], 'the second call carried the remainder' );
	}

	public function test_a_purge_refusal_surfaces_in_words_with_honest_progress() {
		$GLOBALS['_af_http_queue'] = array( $this->purge_ok(), $this->purge_denied() );
		$urls                      = array();
		for ( $i = 0; $i < 31; $i++ ) {
			$urls[] = "https://example.test/p$i/";
		}

		$out = ( new Client() )->purge_urls( 'tok', 'zone1', $urls );
		$this->assertSame( 'Unauthorized to access requested resource', $out['error'] );
		$this->assertSame( 30, $out['purged'], 'the count already purged rides along — never all-or-nothing' );
	}

	public function test_purge_all_maps_success_and_refusal() {
		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		$this->assertTrue( ( new Client() )->purge_all( 'tok', 'zone1' )['ok'] );
		$body = json_decode( (string) $GLOBALS['_af_http_last']['args']['body'], true );
		$this->assertTrue( $body['purge_everything'] );

		$GLOBALS['_af_http_queue'][] = $this->purge_denied();
		$this->assertSame( 'Unauthorized to access requested resource', ( new Client() )->purge_all( 'tok', 'zone1' )['error'] );
	}

	public function test_purge_module_is_a_quiet_noop_when_disconnected() {
		$GLOBALS['_af_http_last'] = null;
		Purge::purge_urls( array( 'https://example.test/a/' ) );
		$this->assertNull( $GLOBALS['_af_http_last'], 'no call went out' );
		$this->assertFalse( Purge::purge_all()['ok'] );
	}

	public function test_purge_outcome_is_recorded_on_the_connection() {
		$settings = new Settings();
		$settings->connect( 'tok', 'zone1', 'example.com' );

		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		Purge::purge_urls( array( 'https://example.test/a/' ), $settings );
		$view = $settings->public_view();
		$this->assertGreaterThan( 0, $view['lastPurgeAt'] );
		$this->assertSame( '', $view['lastPurgeError'] );

		$GLOBALS['_af_http_queue'][] = $this->purge_denied();
		Purge::purge_all( $settings );
		$this->assertSame( 'Unauthorized to access requested resource', $settings->public_view()['lastPurgeError'] );

		// A clean purge clears the failure — the rail line must not outlive it.
		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		Purge::purge_all( $settings );
		$this->assertSame( '', $settings->public_view()['lastPurgeError'] );
	}

	/**
	 * ⛔ A PARTIAL PURGE MUST NOT READ AS A FAILED ONE. The list goes to the edge
	 * in batches of 30; when one breaks partway the batches before it really are
	 * cleared and the rest really are still stale. The client has always worked
	 * that number out — {@see Client::purge_urls()} returns `purged` "so the
	 * caller can record honest progress" — and nobody was reading it, so 30 of 47
	 * cleared and 0 of 47 cleared told the owner exactly the same story.
	 */
	public function test_a_purge_that_breaks_partway_records_what_it_did_clear() {
		$settings = new Settings();
		$settings->connect( 'tok', 'zone1', 'example.com' );

		$urls = array();
		for ( $i = 0; $i < 47; $i++ ) {
			$urls[] = 'https://example.test/page-' . $i . '/';
		}
		// Batch one lands, batch two is refused.
		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		$GLOBALS['_af_http_queue'][] = $this->purge_denied();

		Purge::purge_urls( $urls, $settings );

		$view = $settings->public_view();
		$this->assertSame( 47, $view['lastPurgeUrls'], 'what it was asked to clear' );
		$this->assertSame( 30, $view['lastPurgeCleared'], 'what actually landed before the break' );
		$this->assertNotSame( '', $view['lastPurgeError'], 'and it is still a failure, not a success' );
	}

	public function test_a_clean_purge_records_the_whole_list_as_cleared() {
		$settings = new Settings();
		$settings->connect( 'tok', 'zone1', 'example.com' );

		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		Purge::purge_urls( array( 'https://example.test/a/', 'https://example.test/b/' ), $settings );

		$view = $settings->public_view();
		$this->assertSame( 2, $view['lastPurgeUrls'] );
		$this->assertSame( 2, $view['lastPurgeCleared'] );
		$this->assertSame( '', $view['lastPurgeError'] );
	}

	/** A purge-everything has no list, so it must not invent one. */
	public function test_purge_everything_records_no_counts() {
		$settings = new Settings();
		$settings->connect( 'tok', 'zone1', 'example.com' );

		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		Purge::purge_all( $settings );

		$view = $settings->public_view();
		$this->assertSame( 0, $view['lastPurgeUrls'], 'no list was named, so no number is claimed' );
		$this->assertSame( 0, $view['lastPurgeCleared'] );
	}

	public function test_a_refusal_stands_the_automatic_path_down_and_a_clean_manual_purge_rearms_it() {
		$settings = new Settings();
		$settings->connect( 'tok', 'zone1', 'example.com' );

		// First automatic attempt: refused (401/403 = the token cannot purge).
		$GLOBALS['_af_http_queue'][] = $this->purge_denied();
		Purge::purge_urls( array( 'https://example.test/a/' ), $settings );
		$this->assertTrue( $settings->purge_denied() );

		// Every save after that: SILENT — no request, no re-recorded nag.
		$GLOBALS['_af_http_last'] = null;
		Purge::purge_urls( array( 'https://example.test/b/' ), $settings );
		$this->assertNull( $GLOBALS['_af_http_last'], 'a known-refused token is not asked again on every save' );

		// The manual button always attempts — a clean purge re-arms the
		// automatic path in the same stroke.
		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		Purge::purge_all( $settings );
		$this->assertFalse( $settings->purge_denied() );

		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		Purge::purge_urls( array( 'https://example.test/c/' ), $settings );
		$this->assertNotNull( $GLOBALS['_af_http_last'], 'the automatic path is back' );
	}

	public function test_a_transient_failure_never_stands_the_automatic_path_down() {
		$settings = new Settings();
		$settings->connect( 'tok', 'zone1', 'example.com' );

		// A 5xx (or a timeout) deserves a fresh attempt on the next occasion.
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 503 ), 'body' => '{}', 'headers' => array() );
		Purge::purge_urls( array( 'https://example.test/a/' ), $settings );
		$this->assertNotSame( '', $settings->public_view()['lastPurgeError'] );
		$this->assertFalse( $settings->purge_denied(), 'a blip is not a refusal' );

		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		$GLOBALS['_af_http_last']    = null;
		Purge::purge_urls( array( 'https://example.test/b/' ), $settings );
		$this->assertNotNull( $GLOBALS['_af_http_last'], 'the next save attempts again' );
	}

	public function test_the_owner_switch_turns_only_the_automatic_path_off() {
		$settings = new Settings();
		$settings->connect( 'tok', 'zone1', 'example.com' );
		$GLOBALS['_af_options']['agentimus_settings'] = array( 'cf_purge_on_change' => false );

		$GLOBALS['_af_http_last'] = null;
		Purge::purge_urls( array( 'https://example.test/a/' ), $settings );
		$this->assertNull( $GLOBALS['_af_http_last'], 'switch off = no automatic attempt' );

		// The manual button ignores the switch — pressing it IS the consent.
		$GLOBALS['_af_http_queue'][] = $this->purge_ok();
		$this->assertTrue( Purge::purge_all( $settings )['ok'] );
	}

	public function test_reconnecting_forgets_the_old_tokens_purge_verdicts() {
		$settings = new Settings();
		$settings->connect( 'tok', 'zone1', 'example.com' );
		$GLOBALS['_af_http_queue'][] = $this->purge_denied();
		Purge::purge_all( $settings );
		$this->assertTrue( $settings->purge_denied() );

		// A new token is a new question.
		$settings->connect( 'tok2', 'zone1', 'example.com' );
		$this->assertFalse( $settings->purge_denied() );
		$this->assertSame( '', $settings->public_view()['lastPurgeError'] );
	}

	public function test_a_failed_purge_never_smears_the_poll_and_vice_versa() {
		$settings = new Settings();
		$settings->connect( 'tok', 'zone1', 'example.com' );
		$settings->record_poll( '' );

		$GLOBALS['_af_http_queue'][] = $this->purge_denied();
		Purge::purge_all( $settings );

		$view = $settings->public_view();
		$this->assertSame( '', $view['lastError'], 'the poll stays clean' );
		$this->assertNotSame( '', $view['lastPurgeError'], 'the purge wears its own failure' );
	}

	/* ---- when a conflict started: the half that answers "since when?" ------- */

	/**
	 * ⭐⭐ THE COMPLAINT THIS ANSWERS, in his words on 2026-08-28: "I don't know
	 * since when these are there." The conflicts are recomputed from observed
	 * behaviour on every read and stored nowhere, so nothing could date them —
	 * and a warning of unknown age reads as either brand new or ancient.
	 */
	public function test_a_conflict_keeps_the_moment_it_was_first_seen() {
		$store = new Settings();

		$first = $store->note_first_seen( array( 'edge-blocks-openai' ) );
		$this->assertArrayHasKey( 'edge-blocks-openai', $first );
		$this->assertGreaterThan( 0, $first['edge-blocks-openai'] );

		// ⚠️⚠️ PLANTED IN THE PAST, and that is not decoration. Calling the method
		// twice in a row proves nothing: both calls land in the same second, so
		// the assertion passes just as happily against a version that restamps on
		// every read — which is exactly what the control run showed. A week-old
		// stamp can only survive if the code genuinely preserves it.
		$week_ago                                   = time() - 604800; // a week
		$stored                                     = (array) \get_option( Settings::OPTION, array() );
		$stored['first_seen']['edge-blocks-openai'] = $week_ago;
		\update_option( Settings::OPTION, $stored );

		// ⚠️ A FRESH INSTANCE. ConnectionStore memoizes all() for the object's
		// lifetime, so reusing $store here would read its own cache and never see
		// what was planted — which is also the truthful shape of the thing being
		// tested: the stamp has to survive to the NEXT page load, not to the next
		// line of this test.
		$again = ( new Settings() )->note_first_seen( array( 'edge-blocks-openai' ) );
		$this->assertSame(
			$week_ago,
			$again['edge-blocks-openai'],
			'seeing it again does not restart its clock'
		);
	}

	public function test_a_new_conflict_is_stamped_without_disturbing_the_older_one() {
		$store = new Settings();
		$first = $store->note_first_seen( array( 'edge-blocks-openai' ) );

		$both = $store->note_first_seen( array( 'edge-blocks-openai', 'train-not-enforced' ) );

		$this->assertSame( $first['edge-blocks-openai'], $both['edge-blocks-openai'] );
		$this->assertArrayHasKey( 'train-not-enforced', $both );
	}

	/**
	 * ⛔ Same lifecycle as a dismissal, and deliberately so: the record dies with
	 * the situation, so a conflict that ends and later returns is dated from its
	 * RETURN. Carrying the original date forward would tell an owner a problem
	 * they fixed in March has been running since March.
	 */
	public function test_a_conflict_that_stops_is_forgotten_and_redated_if_it_returns() {
		$store = new Settings();
		$store->note_first_seen( array( 'edge-blocks-openai' ) );

		$this->assertSame( array(), $store->note_first_seen( array() ), 'it stopped: the record goes with it' );

		$back = $store->note_first_seen( array( 'edge-blocks-openai' ) );
		$this->assertArrayHasKey( 'edge-blocks-openai', $back, 'and a recurrence is dated afresh' );
	}

	/**
	 * ⭐ The numbers travel as numbers. Every other surface — the findings row,
	 * the weekly digest — needs the same two figures in a shorter sentence, and
	 * re-deriving them from the card's prose would make that copy load-bearing.
	 */
	public function test_a_conflict_carries_its_figures_not_only_its_sentence() {
		$warn = Conflicts::detect(
			array( $this->crawler( 'oai-searchbot', 'OpenAI', 100, 0, 10, 90 ) ),
			array(),
			7
		);
		$this->assertSame( 90, $warn[0]['counts']['blocked'] );
		$this->assertSame( 100, $warn[0]['counts']['requests'] );
	}

	/* ---- onset: when it BEGAN, not when we noticed --------------------------- */

	/** A day of per-crawler totals, as Table::daily() hands them over. */
	private function day( $ua, $requests, $blocked, $passed = 0 ) {
		return array( $ua => array( 'requests' => $requests, 'blocked' => $blocked, 'passed' => $passed ) );
	}

	/**
	 * ⭐⭐ THE REASON THIS EXISTS. The recorded stamp can only date a conflict from
	 * the moment the plugin first looked, so installing the feature today reports
	 * today for a problem that started days ago. On the real site OpenAI's crawler
	 * sat around 12% blocked for three weeks — noise, under the bar — and then
	 * jumped to 98%. The conflict began at the jump, and dating it from the noise
	 * would be as wrong as dating it from the install.
	 */
	public function test_the_onset_is_the_day_the_condition_started_holding() {
		$daily = array(
			'2026-08-23' => $this->day( 'oai-searchbot', 100, 12 ),  // 12% — under the bar.
			'2026-08-24' => $this->day( 'oai-searchbot', 100, 11 ),
			'2026-08-25' => $this->day( 'oai-searchbot', 100, 13 ),
			'2026-08-26' => $this->day( 'oai-searchbot', 248, 200 ), // 81% — the event.
			'2026-08-27' => $this->day( 'oai-searchbot', 1546, 1527 ),
			'2026-08-28' => $this->day( 'oai-searchbot', 1537, 1518 ),
		);

		$onset = Conflicts::onset( $daily, 'edge-blocks-openai', array( 'oai-searchbot' => 'OpenAI' ) );

		$this->assertSame( '2026-08-26', $onset['at'] );
		$this->assertFalse( $onset['bounded'], 'a quiet day before it is proof the run started there' );
	}

	/**
	 * ⚠️⚠️ AN ABSENCE MUST NAME ITSELF. When the condition holds on every day we
	 * still keep, we do NOT know when it began — only that it is at least that
	 * old. Reporting that day as the start would invent a fact out of a retention
	 * limit, so the flag makes the caller word it differently.
	 */
	public function test_a_run_older_than_the_kept_history_says_so_instead_of_guessing() {
		$daily = array(
			'2026-08-26' => $this->day( 'oai-searchbot', 100, 90 ),
			'2026-08-27' => $this->day( 'oai-searchbot', 100, 95 ),
		);

		$onset = Conflicts::onset( $daily, 'edge-blocks-openai', array( 'oai-searchbot' => 'OpenAI' ) );

		$this->assertSame( '2026-08-26', $onset['at'] );
		$this->assertTrue( $onset['bounded'], 'we ran out of data, we did not find a beginning' );
	}

	/** No history at all: nothing to derive, and nothing invented. */
	public function test_no_history_yields_no_date() {
		$onset = Conflicts::onset( array(), 'edge-blocks-openai' );

		$this->assertSame( '', $onset['at'] );
		$this->assertFalse( $onset['bounded'] );
	}

	/**
	 * ⭐ A GAP IS NOT A CONTRADICTION. A day the poll never ran records nothing,
	 * and treating that silence as "the conflict stopped" would cut the run short
	 * and date a three-week problem to yesterday.
	 */
	public function test_a_day_with_no_rows_does_not_break_the_run() {
		$daily = array(
			'2026-08-24' => $this->day( 'oai-searchbot', 100, 5 ),   // under the bar: a real end.
			'2026-08-25' => $this->day( 'oai-searchbot', 200, 180 ),
			'2026-08-26' => array(),                                  // the poll did not run.
			'2026-08-27' => $this->day( 'oai-searchbot', 200, 190 ),
		);

		$onset = Conflicts::onset( $daily, 'edge-blocks-openai', array( 'oai-searchbot' => 'OpenAI' ) );

		$this->assertSame( '2026-08-25', $onset['at'] );
	}

	public function test_the_training_notice_is_dated_from_the_first_day_one_got_through() {
		$daily = array(
			'2026-08-25' => $this->day( 'gptbot', 10, 10, 0 ),   // all blocked: enforced.
			'2026-08-26' => $this->day( 'gptbot', 10, 0, 10 ),   // through.
			'2026-08-27' => $this->day( 'gptbot', 10, 0, 10 ),
		);

		$onset = Conflicts::onset( $daily, 'train-not-enforced' );

		$this->assertSame( '2026-08-26', $onset['at'] );
		$this->assertFalse( $onset['bounded'] );
	}

	// ── SpoofCheck: a User-Agent is not an operator ─────────────────────────

	private function blocked_row( $ip, $ua, $op, $n ) {
		return array( 'ip' => $ip, 'ua' => $ua, 'operator' => $op, 'requests' => $n );
	}

	/**
	 * The live 2026-08-29 case: every blocked "OAI-SearchBot" request came from
	 * a rented cloud box outside OpenAI's published ranges — a scanner probing
	 * /.env paths wearing the name. The tally must call that spoofed, per
	 * operator, without ever asking twice about one address.
	 */
	public function test_classify_tallies_requests_by_the_source_addresses_verdict() {
		$asked   = array();
		$verdict = function ( $ua, $ip ) use ( &$asked ) {
			$asked[] = $ip;
			$map     = array( '18.209.9.61' => 2, '34.138.50.25' => 2, '172.182.193.225' => 1 );
			return isset( $map[ $ip ] ) ? $map[ $ip ] : 0;
		};

		$out = SpoofCheck::classify( array(
			$this->blocked_row( '18.209.9.61', 'OAI-SearchBot/1.0', 'OpenAI', 500 ),
			$this->blocked_row( '18.209.9.61', 'OAI-SearchBot/1.1', 'OpenAI', 100 ), // same box, second UA
			$this->blocked_row( '34.138.50.25', 'OAI-SearchBot/1.0', 'OpenAI', 400 ),
			$this->blocked_row( '172.182.193.225', 'GPTBot/1.2', 'OpenAI', 25 ),
			$this->blocked_row( '9.9.9.9', 'PerplexityBot/1.0', 'Perplexity', 30 ),
		), $verdict );

		$this->assertSame( 1025, $out['OpenAI']['sampled'] );
		$this->assertSame( 1000, $out['OpenAI']['spoofed'] );
		$this->assertSame( 25, $out['OpenAI']['verified'] );
		$this->assertSame( 0, $out['OpenAI']['unknown'] );
		$this->assertSame( 30, $out['Perplexity']['unknown'] ); // 9.9.9.9 answered 0.
		// One question per address: 18.209.9.61 appears twice, is asked once.
		$this->assertSame( array( '18.209.9.61', '34.138.50.25', '172.182.193.225', '9.9.9.9' ), $asked );
	}

	/**
	 * ⭐ THE CAP TRIMS THE TAIL, NOT THE STORY. Addresses past the lookup budget
	 * count as undetermined — never as either verdict — so a capped run can
	 * only ever weaken the case for standing the warning down.
	 */
	public function test_classify_counts_addresses_past_the_cap_as_unknown() {
		$rows = array(
			$this->blocked_row( '1.1.1.1', 'GPTBot', 'OpenAI', 10 ),
			$this->blocked_row( '2.2.2.2', 'GPTBot', 'OpenAI', 9 ),
			$this->blocked_row( '3.3.3.3', 'GPTBot', 'OpenAI', 8 ),
		);
		$out  = SpoofCheck::classify( $rows, function () {
			return 2;
		}, 2 );

		$this->assertSame( 27, $out['OpenAI']['sampled'] );
		$this->assertSame( 19, $out['OpenAI']['spoofed'] );
		$this->assertSame( 8, $out['OpenAI']['unknown'] );
	}

	public function test_a_fresh_all_spoofed_sample_stands_the_warning_down() {
		$now = 1788000000;
		$this->assertTrue( SpoofCheck::stands_down(
			array( 'at' => $now - HOUR_IN_SECONDS, 'sampled' => 1000, 'verified' => 0, 'spoofed' => 1000, 'unknown' => 0 ),
			$now
		) );
	}

	/** Any verified request keeps the warn: real blocking is the actionable fact. */
	public function test_one_verified_request_keeps_the_warning() {
		$now = 1788000000;
		$this->assertFalse( SpoofCheck::stands_down(
			array( 'at' => $now, 'sampled' => 1000, 'verified' => 1, 'spoofed' => 999, 'unknown' => 0 ),
			$now
		) );
	}

	/** "Could not say" is not "not them" — an undetermined sample keeps the warn. */
	public function test_a_mostly_undetermined_sample_keeps_the_warning() {
		$now = 1788000000;
		$this->assertFalse( SpoofCheck::stands_down(
			array( 'at' => $now, 'sampled' => 1000, 'verified' => 0, 'spoofed' => 300, 'unknown' => 700 ),
			$now
		) );
	}

	/** ⭐ A VERDICT AGES: last week's sample must not explain away today's blocking. */
	public function test_a_stale_verdict_no_longer_stands_anything_down() {
		$now = 1788000000;
		$this->assertFalse( SpoofCheck::stands_down(
			array( 'at' => $now - 3 * DAY_IN_SECONDS, 'sampled' => 1000, 'verified' => 0, 'spoofed' => 1000, 'unknown' => 0 ),
			$now
		) );
	}

	/** A handful of proven fakes is a blip, not a campaign — same bar as the conflict's own. */
	public function test_low_spoofed_volume_keeps_the_warning() {
		$now = 1788000000;
		$this->assertFalse( SpoofCheck::stands_down(
			array( 'at' => $now, 'sampled' => 9, 'verified' => 0, 'spoofed' => 9, 'unknown' => 0 ),
			$now
		) );
	}

	public function test_blocked_sources_normalizes_the_graphql_shape() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => json_encode( array(
				'data' => array( 'viewer' => array( 'zones' => array( array(
					'httpRequestsAdaptiveGroups' => array(
						array(
							'count'      => 500,
							'dimensions' => array( 'clientIP' => '18.209.9.61', 'userAgent' => 'OAI-SearchBot/1.0' ),
						),
					),
				) ) ) ),
			) ),
		);

		$out = ( new Client() )->blocked_sources( 'tok', 'zone1', 0, 3600 );

		$this->assertArrayNotHasKey( 'error', $out );
		$this->assertSame( array( array( 'ip' => '18.209.9.61', 'ua' => 'OAI-SearchBot/1.0', 'requests' => 500 ) ), $out['rows'] );
	}

	public function test_blocked_sources_missing_container_is_an_error() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array() ) ) ) ),
		);

		$out = ( new Client() )->blocked_sources( 'tok', 'zone1', 0, 3600 );

		$this->assertArrayHasKey( 'error', $out );
	}
}
