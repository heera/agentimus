<?php
/**
 * The identity probe ({@see IdentityProbe}) — the three states and which
 * responses reach them, the read path's promise never to fetch, the bounded
 * cadence (one look per URL per week, one host per run), and the store's caps.
 *
 * The state that matters most here is UNREACHED: it must swallow everything
 * inconclusive, because a wrong "this crawler is lying" is worse than no answer.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Catalog;
use Agentimus\Activity\IdentityProbe;
use PHPUnit\Framework\TestCase;

final class IdentityProbeTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** One 200 response for the queue. */
	private function ok( $code = 200 ) {
		return array( 'response' => array( 'code' => $code ), 'body' => 'ok', 'headers' => array() );
	}

	/** Record a URL and run the probe against a queued response. */
	private function probe_once( $url, $response ) {
		IdentityProbe::observe( array( $url ) );
		$GLOBALS['_af_http_queue'][] = $response;
		IdentityProbe::run();
		return IdentityProbe::state( $url );
	}

	/**
	 * WordPress actions and filters share ONE hook namespace. If the cron hook
	 * ever equals the store's filter tag, apply_filters() would invoke the
	 * cron-registered run(), which reads the store, and the recursion kills the
	 * process with no output. RouteProbe learned this the hard way.
	 */
	public function test_cron_hook_and_filter_tag_never_collide() {
		$this->assertNotSame( IdentityProbe::FILTER, IdentityProbe::CRON );
	}

	/* ---- classify: which responses are conclusive --------------------------- */

	public function test_a_page_that_answers_is_conclusive() {
		$this->assertSame( IdentityProbe::ANSWERS, IdentityProbe::classify( $this->ok() )['state'] );
		$this->assertSame( IdentityProbe::ANSWERS, IdentityProbe::classify( $this->ok( 204 ) )['state'] );
	}

	/** The server is there and says there is no such page — the real finding. */
	public function test_404_and_410_are_missing() {
		$this->assertSame( IdentityProbe::MISSING, IdentityProbe::classify( $this->ok( 404 ) )['state'] );
		$this->assertSame( IdentityProbe::MISSING, IdentityProbe::classify( $this->ok( 410 ) )['state'] );
		$this->assertSame( 404, IdentityProbe::classify( $this->ok( 404 ) )['code'] );
	}

	/**
	 * A 403 may be a firewall turning US away and a 5xx may be a bad afternoon.
	 * Neither says anything about the crawler, so neither may read as missing.
	 */
	public function test_403_429_and_5xx_say_nothing() {
		foreach ( array( 401, 403, 429, 500, 502, 503 ) as $code ) {
			$this->assertSame(
				IdentityProbe::UNREACHED,
				IdentityProbe::classify( $this->ok( $code ) )['state'],
				"HTTP $code must stay inconclusive"
			);
		}
	}

	/** A timeout on a host that exists is our problem, not evidence. */
	public function test_transport_error_on_a_live_host_says_nothing() {
		$error = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertSame( IdentityProbe::UNREACHED, IdentityProbe::classify( $error, true )['state'] );
	}

	/** A host that resolves to nothing is as conclusive as a 404. */
	public function test_transport_error_on_a_host_that_does_not_resolve_is_missing() {
		$error = new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		$this->assertSame( IdentityProbe::MISSING, IdentityProbe::classify( $error, false )['state'] );
		$this->assertSame( 0, IdentityProbe::classify( $error, false )['code'] );
	}

	/* ---- normalize: what we are willing to fetch ---------------------------- */

	public function test_normalize_lowercases_scheme_and_host_and_drops_the_fragment() {
		$this->assertSame(
			'https://scenter.app/bot',
			IdentityProbe::normalize( 'HTTPS://Scenter.App/bot#who-we-are' )
		);
	}

	/** No path means the home page, and a query is part of the address. */
	public function test_normalize_defaults_the_path_and_keeps_a_query() {
		$this->assertSame( 'https://example.com/', IdentityProbe::normalize( 'https://example.com' ) );
		$this->assertSame( 'https://example.com/b?id=7', IdentityProbe::normalize( 'https://example.com/b?id=7' ) );
	}

	/**
	 * The path is part of the key: two crawlers can declare different pages on
	 * one host, and answering for the wrong one would be an invented result.
	 */
	public function test_two_pages_on_one_host_are_two_records() {
		$this->assertNotSame(
			IdentityProbe::normalize( 'https://example.com/bot' ),
			IdentityProbe::normalize( 'https://example.com/other' )
		);
	}

	public function test_normalize_rejects_what_we_will_not_fetch() {
		$this->assertSame( '', IdentityProbe::normalize( 'ftp://example.com/bot' ) );
		$this->assertSame( '', IdentityProbe::normalize( 'javascript:alert(1)' ) );
		$this->assertSame( '', IdentityProbe::normalize( 'https://user:pass@example.com/' ) );
		$this->assertSame( '', IdentityProbe::normalize( 'https://localhost/bot' ) ); // Single-label host.
		$this->assertSame( '', IdentityProbe::normalize( 'https://exa mple.com/' ) );
		$this->assertSame( '', IdentityProbe::normalize( '' ) );
		$this->assertSame( '', IdentityProbe::normalize( 'https://example.com/' . str_repeat( 'a', 400 ) ) );
	}

	/* ---- the read path never fetches ---------------------------------------- */

	/**
	 * observe() runs behind the review queue and the admin bell, on every admin
	 * page load. It records and queues; the request happens in cron or not at all.
	 */
	public function test_observe_records_but_never_fetches() {
		IdentityProbe::observe( array( 'https://scenter.app/bot' ) );

		$this->assertNull( $GLOBALS['_af_http_last'], 'the read path must not make a request' );
		$this->assertNull(
			IdentityProbe::state( 'https://scenter.app/bot' ),
			'an unprobed URL has no result — callers render that as "not checked"'
		);
	}

	/** Garbage in the option, and URLs we would never fetch, are simply dropped. */
	public function test_observe_ignores_unfetchable_urls() {
		IdentityProbe::observe( array( '', 'not a url', 'ftp://example.com/x' ) );
		IdentityProbe::run();
		$this->assertSame( array(), $GLOBALS['_af_http_calls'] );
	}

	/* ---- the run ------------------------------------------------------------ */

	public function test_run_probes_a_recorded_url_and_stores_the_result() {
		$state = $this->probe_once( 'https://scenter.app/bot', $this->ok( 404 ) );

		$this->assertSame( IdentityProbe::MISSING, $state['state'] );
		$this->assertSame( 404, $state['code'] );
		$this->assertNotSame( '', $state['at'] );
		$this->assertSame( 'https://scenter.app/bot', $GLOBALS['_af_http_calls'][0]['url'] );
	}

	/** wp_safe_remote_get, always: these URLs come from a stranger's header. */
	public function test_the_request_is_safe_capped_and_named() {
		$this->probe_once( 'https://example.com/bot', $this->ok() );

		$args = $GLOBALS['_af_http_calls'][0]['args'];
		$this->assertSame( IdentityProbe::MAX_BYTES, $args['limit_response_size'] );
		$this->assertSame( IdentityProbe::TIMEOUT, $args['timeout'] );
		$this->assertStringContainsString( 'Agentimus/', $args['user-agent'] );
		$this->assertStringContainsString( '+https://wordpress.org/plugins/agentimus/', $args['user-agent'] );
	}

	/** The DNS seam decides the failed-request case, and is only consulted there. */
	public function test_a_dead_domain_reads_as_missing_through_the_dns_seam() {
		\add_filter( 'agentimus_identity_probe_resolves', function () {
			return false;
		} );
		$state = $this->probe_once( 'https://nobody.example/bot', new \WP_Error( 'http_request_failed', 'no host' ) );

		$this->assertSame( IdentityProbe::MISSING, $state['state'] );
	}

	/* ---- cadence and caps --------------------------------------------------- */

	/** A fresh result stands for a week — a second run makes no request. */
	public function test_a_fresh_result_is_not_probed_again() {
		$this->probe_once( 'https://example.com/bot', $this->ok() );
		IdentityProbe::run();

		$this->assertCount( 1, $GLOBALS['_af_http_calls'] );
	}

	/** Once the result is older than a week, it is looked at again. */
	public function test_a_week_old_result_is_probed_again() {
		$this->probe_once( 'https://example.com/bot', $this->ok() );

		$store = \get_option( IdentityProbe::OPTION );
		$store['https://example.com/bot']['at'] = time() - IdentityProbe::RECHECK_AFTER - 1;
		\update_option( IdentityProbe::OPTION, $store );

		$GLOBALS['_af_http_queue'][] = $this->ok( 404 );
		IdentityProbe::run();

		$this->assertCount( 2, $GLOBALS['_af_http_calls'] );
		$this->assertSame( IdentityProbe::MISSING, IdentityProbe::state( 'https://example.com/bot' )['state'] );
	}

	/**
	 * Never two requests to one host in a run: a crawler that declares several
	 * pages must not turn into a burst against a stranger's server.
	 */
	public function test_one_host_gets_one_request_per_run() {
		IdentityProbe::observe(
			array( 'https://example.com/a', 'https://example.com/b', 'https://other.test/c' )
		);
		IdentityProbe::run();

		$hosts = array();
		foreach ( $GLOBALS['_af_http_calls'] as $call ) {
			$hosts[] = \wp_parse_url( $call['url'], PHP_URL_HOST );
		}
		$this->assertSame( $hosts, array_unique( $hosts ) );
		$this->assertCount( 2, $hosts );
	}

	/** A site that has just met many new crawlers spreads them over runs. */
	public function test_a_run_is_bounded() {
		$urls = array();
		for ( $i = 0; $i < IdentityProbe::PER_RUN + 4; $i++ ) {
			$urls[] = "https://bot{$i}.test/about";
		}
		IdentityProbe::observe( $urls );
		IdentityProbe::run();

		$this->assertCount( IdentityProbe::PER_RUN, $GLOBALS['_af_http_calls'] );
	}

	/* ---- prune -------------------------------------------------------------- */

	public function test_prune_forgets_urls_nobody_declares_any_more() {
		$now   = time();
		$store = array(
			'https://live.test/bot' => array( 'state' => 'answers', 'code' => 200, 'at' => $now, 'seen' => $now ),
			'https://gone.test/bot' => array( 'state' => 'answers', 'code' => 200, 'at' => $now, 'seen' => $now - IdentityProbe::FORGET_AFTER - 1 ),
		);
		$kept = IdentityProbe::prune( $store, $now );

		$this->assertArrayHasKey( 'https://live.test/bot', $kept );
		$this->assertArrayNotHasKey( 'https://gone.test/bot', $kept );
	}

	/** A flood of invented URLs cannot grow the option without bound. */
	public function test_prune_caps_the_store_and_keeps_the_most_recent() {
		$now   = time();
		$store = array();
		for ( $i = 0; $i < IdentityProbe::MAX_URLS + 10; $i++ ) {
			$store[ "https://bot{$i}.test/x" ] = array( 'state' => '', 'code' => 0, 'at' => 0, 'seen' => $now - $i );
		}
		$kept = IdentityProbe::prune( $store, $now );

		$this->assertCount( IdentityProbe::MAX_URLS, $kept );
		$this->assertArrayHasKey( 'https://bot0.test/x', $kept );   // Newest seen.
		$this->assertArrayNotHasKey( 'https://bot69.test/x', $kept ); // Oldest seen.
	}

	/**
	 * A flood of user-agents all naming different paths on ONE host would
	 * otherwise turn this site into a slow drip of requests against that host.
	 * Two per host is kept; the rest are dropped, newest declaration first.
	 */
	public function test_prune_holds_each_host_to_a_couple_of_urls() {
		$now   = time();
		$store = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$store[ "https://victim.test/p{$i}" ] = array( 'state' => '', 'code' => 0, 'at' => 0, 'seen' => $now - $i );
		}
		$store['https://other.test/bot'] = array( 'state' => '', 'code' => 0, 'at' => 0, 'seen' => $now );

		$kept = IdentityProbe::prune( $store, $now );

		$this->assertCount( IdentityProbe::MAX_PER_HOST + 1, $kept );
		$this->assertArrayHasKey( 'https://victim.test/p0', $kept );  // Most recently declared.
		$this->assertArrayNotHasKey( 'https://victim.test/p9', $kept );
		$this->assertArrayHasKey( 'https://other.test/bot', $kept );  // A different host is untouched.
	}

	/** An idle admin session must not write the option on every page load. */
	public function test_observe_does_not_rewrite_the_option_when_nothing_changed() {
		IdentityProbe::observe( array( 'https://example.com/bot' ) );
		$before = \get_option( IdentityProbe::OPTION );

		IdentityProbe::observe( array( 'https://example.com/bot' ) );

		$this->assertSame( $before, \get_option( IdentityProbe::OPTION ) );
	}

	/* ---- end to end, from a real User-Agent --------------------------------- */

	/**
	 * The URL the review queue shows comes out of Catalog::self_declared(). It
	 * must survive normalization, or the probe would silently check nothing.
	 */
	public function test_a_url_parsed_from_a_user_agent_is_probeable() {
		$ua       = 'ScenterBot/1.0 (+https://scenter.app/bot)';
		$declared = Catalog::self_declared( $ua );

		$this->assertSame( 'https://scenter.app/bot', IdentityProbe::normalize( $declared['url'] ) );

		$state = $this->probe_once( $declared['url'], $this->ok( 404 ) );
		$this->assertSame( IdentityProbe::MISSING, $state['state'] );
	}
}
