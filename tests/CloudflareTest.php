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
use Agentimus\Cloudflare\Settings;
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
}
