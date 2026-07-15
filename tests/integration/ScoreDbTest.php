<?php
/**
 * The AEO/GEO Score engine against real WP: it runs the full Readiness report, samples
 * real posts and parses their HTML (PageCheck), and reads the visibility store — none
 * of which the unit suite exercises (no $wpdb, no real DOM over real content). Also
 * pins the resilience contract: adversarial post markup must never crash the report.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Cache;
use Agentimus\Score;
use Agentimus\Settings;
use Agentimus\Visibility\Runner;
use Agentimus\Visibility\Settings as VisibilitySettings;
use Agentimus\Visibility\Store;
use Agentimus\Visibility\Table;

final class ScoreDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_transient( Cache::OPTIMIZE ); // Force a fresh content sample per test.
		delete_transient( Cache::LLMS_WORDS );
	}

	private function post( $content ) {
		return self::factory()->post->create(
			array( 'post_status' => 'publish', 'post_content' => $content )
		);
	}

	/** Turn on citation tracking so the Cited rung is part of the report. */
	private function enable_visibility() {
		$s   = new Settings();
		$all = $s->all();
		$all['enable_visibility'] = true;
		$s->update( $all );
	}

	public function test_report_has_the_five_rung_shape_over_real_posts() {
		$this->enable_visibility(); // The full five-rung ladder needs citation tracking on.
		// A substantial, citable post (figures + a cited source) and a thin one.
		$this->post( str_repeat( 'A concrete point backed by a real figure: 42% in 2024. ', 30 ) . '<a href="https://example.org/study">source</a>' );
		$this->post( 'Too short to cite.' );
		delete_transient( Cache::OPTIMIZE );

		$r = ( new Score( new Settings() ) )->report();

		$this->assertIsInt( $r['score'] );
		$this->assertGreaterThanOrEqual( 0, $r['score'] );
		$this->assertLessThanOrEqual( 100, $r['score'] );
		$this->assertIsBool( $r['ready'] );
		$this->assertIsBool( $r['blocked'] );

		$keys = array_map(
			static function ( $g ) {
				return $g['key'];
			},
			$r['rungs']
		);
		$this->assertSame( array( 'findable', 'readable', 'trusted', 'optimized', 'cited' ), $keys );

		// With published posts present, the Optimize rung graded real content (not null).
		$optimized = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'optimized' === $g['key'] ) {
				$optimized = $g;
			}
		}
		$this->assertNotNull( $optimized['score'] );
		$this->assertGreaterThanOrEqual( 0, $optimized['score'] );
		$this->assertLessThanOrEqual( 100, $optimized['score'] );
	}

	public function test_commerce_products_are_excluded_from_citability() {
		// Register a commerce type and make it agent-visible, so it WOULD be sampled by the
		// Optimize pillar unless it's dropped as commerce. Capture the final graded set.
		register_post_type( 'product', array( 'public' => true ) );
		$agentize = static function ( $t ) {
			$t[] = 'product';
			return array_values( array_unique( $t ) );
		};
		add_filter( 'agentimus_post_types', $agentize );

		$captured = null;
		$capture  = static function ( $types ) use ( &$captured ) {
			$captured = $types;
			return $types;
		};
		add_filter( 'agentimus_citability_post_types', $capture );

		delete_transient( Cache::OPTIMIZE );
		( new Score( new Settings() ) )->report();

		remove_filter( 'agentimus_post_types', $agentize );
		remove_filter( 'agentimus_citability_post_types', $capture );
		unregister_post_type( 'product' );

		$this->assertIsArray( $captured );
		$this->assertContains( 'post', $captured, 'articles must still be graded' );
		$this->assertNotContains( 'product', $captured, 'commerce products must be excluded from citability grading' );
	}

	public function test_content_worklist_lists_issues_with_editor_links() {
		// A thin post trips the "thin content" check. The worklist links to editors, so
		// it only populates for a user who can edit — mirror the admin-boot context.
		$this->post( 'Too short.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( Cache::OPTIMIZE );

		$r = ( new Score( new Settings() ) )->report();

		$this->assertNotEmpty( $r['content'], 'a thin post should surface a content worklist entry' );
		$issue = $r['content'][0];
		$this->assertArrayHasKey( 'label', $issue );
		$this->assertNotSame( '', $issue['why'], 'each issue carries a plain what-to-do' );
		$this->assertGreaterThanOrEqual( 1, $issue['count'] );
		$this->assertNotEmpty( $issue['pages'] );
		$this->assertArrayHasKey( 'title', $issue['pages'][0] );
		$this->assertStringContainsString( 'post.php', (string) $issue['pages'][0]['url'] );
	}

	public function test_empty_and_structural_pages_are_excluded_from_grading() {
		// An empty page — theme-rendered front page, a page-builder/form page, a
		// placeholder: 0 extractable words, so not an article to grade.
		$empty = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '' ) );
		// A Posts-page container that even carries some text is still structural — the
		// theme renders the loop, so it's excluded regardless of the words gate.
		$blog = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'Latest posts from the blog appear below in the loop.' ) );
		update_option( 'page_for_posts', (int) $blog );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( Cache::OPTIMIZE );

		$r = ( new Score( new Settings() ) )->report();
		update_option( 'page_for_posts', 0 );

		$urls = '';
		foreach ( $r['content'] as $issue ) {
			foreach ( $issue['pages'] as $p ) {
				$urls .= ' ' . $p['url'];
			}
		}
		$this->assertStringNotContainsString( 'post=' . $empty . '&', $urls, 'empty pages must not be graded (false "thin content")' );
		$this->assertStringNotContainsString( 'post=' . $blog . '&', $urls, 'the Posts-page container must not be graded' );
	}

	public function test_commerce_structural_pages_are_excluded_from_grading() {
		// Each carries a few real words, so WITHOUT the exclusion they'd pass the
		// empty-page gate and grade as "thin content". The designation OPTIONS are
		// the signal, deliberately readable without the commerce plugin loaded.
		$cart = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Cart', 'post_content' => 'Your cart is currently empty.' ) );
		update_option( 'woocommerce_cart_page_id', (int) $cart );
		// FluentCart designates pages inside its store settings — nested, with a
		// string id, so this also exercises the recursive `*_page_id` scan.
		$account = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'My account', 'post_content' => 'Sign in to see your orders.' ) );
		update_option( 'fluent_cart_store_settings', array( 'checkout_settings' => array( 'registration_page_id' => (string) $account ) ) );
		// Control: an ordinary thin page must still be graded — proving the test
		// would catch an exclusion that fires too broadly.
		$thin = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Ordinary thin page', 'post_content' => 'A very short page indeed.' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( Cache::OPTIMIZE );

		$r = ( new Score( new Settings() ) )->report();
		delete_option( 'woocommerce_cart_page_id' );
		delete_option( 'fluent_cart_store_settings' );

		$urls = '';
		foreach ( $r['content'] as $issue ) {
			foreach ( $issue['pages'] as $p ) {
				$urls .= ' ' . $p['url'];
			}
		}
		$this->assertStringNotContainsString( 'post=' . $cart . '&', $urls, 'a WooCommerce-designated page must not be graded as an article' );
		$this->assertStringNotContainsString( 'post=' . $account . '&', $urls, 'a FluentCart-designated page must not be graded as an article' );
		$this->assertStringContainsString( 'post=' . $thin . '&', $urls, 'an ordinary thin page must still be graded' );
	}

	public function test_container_pages_of_unknown_plugins_are_excluded_from_grading() {
		// No designation option anywhere — an unknown plugin's page, detected purely
		// by SHAPE: a shortcode container with no authored prose. (Unregistered
		// shortcodes render as literal text, so without the structural gate this
		// would pass the words check and grade as "thin content".)
		$shortcode = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bookings', 'post_content' => '[acme_bookings_calendar view="month"]' ) );
		// Same fact in block form: a NAMESPACED plugin block wrapping skeleton markup.
		$block = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Members', 'post_content' => '<!-- wp:acme/members-area --><div class="acme-members is-loading">Sign in below.</div><!-- /wp:acme/members-area -->' ) );
		// Control: a shortcode page WITH real authored prose is still an article —
		// the gate must key on missing prose, not on the mere presence of a widget.
		$mixed = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About our bookings', 'post_content' => '[acme_bookings_calendar] We take bookings all year round for the workshop, the studio and the annexe. Slots open thirty days ahead and close two days before each event, so plan ahead if you need a weekend.' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( Cache::OPTIMIZE );

		$r = ( new Score( new Settings() ) )->report();

		$urls = '';
		foreach ( $r['content'] as $issue ) {
			foreach ( $issue['pages'] as $p ) {
				$urls .= ' ' . $p['url'];
			}
		}
		$this->assertStringNotContainsString( 'post=' . $shortcode . '&', $urls, 'a shortcode-only container page must not be graded' );
		$this->assertStringNotContainsString( 'post=' . $block . '&', $urls, 'a namespaced-block container page must not be graded' );
		$this->assertStringContainsString( 'post=' . $mixed . '&', $urls, 'a widget page with real authored prose must still be graded' );
	}

	public function test_set_aside_post_is_excluded_and_listed() {
		// A thin post that would be graded and flagged...
		$id = $this->post( 'Too short.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// ...until the owner sets it aside as "not cited content".
		$s   = new Settings();
		$all = $s->all();
		$all['optimize_ignored'] = array( $id );
		$s->update( $all );

		$r = ( new Score( new Settings() ) )->report();

		// Gone from the worklist, present in the visible "set aside" list.
		$inWork = false;
		foreach ( $r['content'] as $issue ) {
			foreach ( $issue['pages'] as $p ) {
				if ( (int) $p['id'] === (int) $id ) {
					$inWork = true;
				}
			}
		}
		$this->assertFalse( $inWork, 'a set-aside post must leave the worklist' );

		$ignoredIds = array_map( static function ( $p ) {
			return (int) $p['id'];
		}, $r['ignored'] );
		$this->assertContains( (int) $id, $ignoredIds, 'a set-aside post must show in the visible list' );
	}

	public function test_cited_is_not_measured_once_ai_visibility_is_disabled() {
		$this->enable_visibility(); // Tracking is on; it's the provider key that's gone.
		// "Set up, ran, then removed the key": a completed run with a mention sits in the
		// store (so it WOULD score), but no provider is configured now.
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		Store::insert(
			array(
				'run_id'      => 5000,
				'brand'       => 'Acme',
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'prompt'      => 'best acme tools',
				'mentioned'   => true,
				'cited'       => false,
				'position'    => 1,
				'competitors' => array(),
				'answer'      => 'Acme is great.',
				'sources'     => array(),
				'error'       => '',
			)
		);
		update_option( Runner::LAST_RUN_OPTION, 5000 );

		$r = ( new Score( new Settings() ) )->report();
		delete_option( Runner::LAST_RUN_OPTION );

		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		$this->assertNull( $cited['score'], 'a stale run must not surface as Cited once AI Visibility has no active provider' );
		$this->assertFalse( $r['measured'], 'measured is false when nothing is currently being measured' );
	}

	public function test_cited_drops_a_run_older_than_the_staleness_cutoff() {
		$this->enable_visibility();
		// An active provider (a plaintext key passes through Crypto) + a completed run
		// well past the 90-day cutoff — recent enough to score would be wrong.
		update_option(
			VisibilitySettings::OPTION,
			array( 'providers' => array( 'openai' => array( 'key' => 'sk-plaintext-test', 'enabled' => true, 'model' => 'gpt-4o-mini', 'web_search' => false ) ) )
		);
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		$old = time() - 200 * DAY_IN_SECONDS;
		Store::insert(
			array(
				'run_id'      => $old,
				'brand'       => 'Acme',
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'prompt'      => 'best acme tools',
				'mentioned'   => true,
				'cited'       => false,
				'position'    => 1,
				'competitors' => array(),
				'answer'      => 'Acme is great.',
				'sources'     => array(),
				'error'       => '',
			)
		);
		update_option( Runner::LAST_RUN_OPTION, $old );

		$r = ( new Score( new Settings() ) )->report();
		delete_option( Runner::LAST_RUN_OPTION );
		delete_option( VisibilitySettings::OPTION );

		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		$this->assertNull( $cited['score'], 'a run past the staleness cutoff must not drive the score' );
		// The stale note (not the "add a key" note) — proves we reached the cutoff, i.e.
		// the provider was active and the run was simply too old.
		$this->assertStringContainsStringIgnoringCase( 'run a check', (string) $cited['note'] );
	}

	public function test_cited_all_error_run_reads_as_failing_not_never_run() {
		$this->enable_visibility();
		// Set up and run, but the key was expired/rate-limited: a completed run where EVERY
		// check errored. The bug: this used to read as "never run" — a null score AND an
		// "info: set up AI Visibility" action, telling the owner to set up what they already
		// pay for. It must instead read as a failure and be flagged at warn.
		update_option(
			VisibilitySettings::OPTION,
			array( 'providers' => array( 'openai' => array( 'key' => 'sk-plaintext-test', 'enabled' => true, 'model' => 'gpt-4o-mini', 'web_search' => false ) ) )
		);
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		$run = time() - DAY_IN_SECONDS; // Recent — well within the freshness window.
		foreach ( array( 'best acme tools', 'acme reviews' ) as $prompt ) {
			Store::insert(
				array(
					'run_id'      => $run,
					'brand'       => 'Acme',
					'provider'    => 'openai',
					'model'       => 'gpt-4o-mini',
					'prompt'      => $prompt,
					'mentioned'   => false,
					'cited'       => false,
					'position'    => 0,
					'competitors' => array(),
					'answer'      => '',
					'sources'     => array(),
					'error'       => 'HTTP 401 Unauthorized',
				)
			);
		}
		update_option( Runner::LAST_RUN_OPTION, $run );

		// Pass a clean (all-pass) readiness so this test isolates the citation path: a bare
		// install's many readiness fails would otherwise fill the 8-action cap and hide the
		// (correctly lower-ranked) warn. On the real, well-configured site this bug targets —
		// AI Visibility set up, key later expired — readiness is clean and the warn surfaces.
		$clean = array( array( 'id' => 'public', 'status' => 'pass', 'label' => 'Public', 'fix' => '', 'detail' => '' ) );
		$r     = ( new Score( new Settings() ) )->report( $clean );
		delete_option( Runner::LAST_RUN_OPTION );
		delete_option( VisibilitySettings::OPTION );

		// The Cited rung stays null (an all-error run measured nothing to score)...
		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		$this->assertNull( $cited['score'], 'an all-error run has no successful check to score' );
		$this->assertFalse( $r['measured'], 'a failed run is not a measurement' );
		// ...and the note names the provider key rather than reading as "never run".
		$this->assertStringContainsStringIgnoringCase( 'key', (string) $cited['note'] );
		$this->assertStringNotContainsStringIgnoringCase( 'run a check to measure', (string) $cited['note'] );

		// The action plan flags the failure at warn — and the old wrong "set up" invite
		// (info) is gone, since the feature IS set up.
		$failing = null;
		$ids     = array();
		foreach ( $r['actions'] as $a ) {
			$ids[] = $a['id'];
			if ( 'visibility_failing' === $a['id'] ) {
				$failing = $a;
			}
		}
		$this->assertNotNull( $failing, 'an all-error run must surface a "checks are failing" action' );
		$this->assertSame( 'warn', $failing['severity'], 'a broken paid integration outranks content nits' );
		$this->assertNotContains( 'measure_setup', $ids, 'a set-up-and-failing site must not be told to set up' );
	}

	public function test_cited_partial_run_keeps_its_score_and_flags_what_failed() {
		$this->enable_visibility();
		// A run where one check succeeded and one errored. Nulling this would redistribute
		// Cited's weight and hide the problem — the very bug. Keep the (smaller-sample)
		// score, but say a check didn't finish.
		update_option(
			VisibilitySettings::OPTION,
			array( 'providers' => array( 'openai' => array( 'key' => 'sk-plaintext-test', 'enabled' => true, 'model' => 'gpt-4o-mini', 'web_search' => false ) ) )
		);
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		$run = time() - DAY_IN_SECONDS;
		Store::insert(
			array(
				'run_id'      => $run,
				'brand'       => 'Acme',
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'prompt'      => 'best acme tools',
				'mentioned'   => true,
				'cited'       => false,
				'position'    => 1,
				'competitors' => array(),
				'answer'      => 'Acme is great.',
				'sources'     => array(),
				'error'       => '',
			)
		);
		Store::insert(
			array(
				'run_id'      => $run,
				'brand'       => 'Acme',
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'prompt'      => 'acme reviews',
				'mentioned'   => false,
				'cited'       => false,
				'position'    => 0,
				'competitors' => array(),
				'answer'      => '',
				'sources'     => array(),
				'error'       => 'HTTP 429 Too Many Requests',
			)
		);
		update_option( Runner::LAST_RUN_OPTION, $run );

		$r = ( new Score( new Settings() ) )->report();
		delete_option( Runner::LAST_RUN_OPTION );
		delete_option( VisibilitySettings::OPTION );

		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		// The successful check measured 100% (1 of 1 mentioned) — a real, kept score.
		$this->assertSame( 100, $cited['score'], 'a partial run keeps its smaller-sample score' );
		$this->assertTrue( $r['measured'], 'a partial run is still a measurement' );
		$this->assertStringContainsStringIgnoringCase( 'didn', (string) $cited['note'], 'the note must say a check did not finish' );

		// A partial (still-measured) run is neither a failure nor a set-up gap → no
		// cited action at all; the rung note carries the "1 check didn't finish" signal.
		$ids = array_map( static function ( $a ) {
			return $a['id'];
		}, $r['actions'] );
		$this->assertNotContains( 'visibility_failing', $ids );
		$this->assertNotContains( 'measure_setup', $ids );
	}

	public function test_cited_rung_appears_only_when_tracking_is_on() {
		// Explicitly assert the off-state (option state can carry over between tests via
		// the options cache, so don't rely on the schema default here).
		$reset = new Settings();
		$all   = $reset->all();
		$all['enable_visibility'] = false;
		$reset->update( $all );

		$keys = static function ( $r ) {
			return array_map(
				static function ( $g ) {
					return $g['key'];
				},
				$r['rungs']
			);
		};

		// Default (off): a clean four-rung ladder, no Cited.
		$off = ( new Score( new Settings() ) )->report();
		$this->assertNotContains( 'cited', $keys( $off ), 'Cited is hidden until tracking is turned on' );
		$this->assertCount( 4, $off['rungs'] );

		// On: Cited joins the ladder.
		$this->enable_visibility();
		$on = ( new Score( new Settings() ) )->report();
		$this->assertContains( 'cited', $keys( $on ), 'Cited appears once tracking is on' );
	}

	public function test_cited_rung_routes_to_settings_when_setup_incomplete() {
		$this->enable_visibility();
		// Fresh: no engine key and no questions → a check can't run yet → the Cited rung
		// deep-links to the AI Visibility *Settings* sub-view so the owner can finish setup.
		$r     = ( new Score( new Settings() ) )->report();
		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		$this->assertSame( 'settings', $cited['view'] );
	}

	public function test_adversarial_post_markup_never_crashes_the_report() {
		// Unclosed tags, nested lists, a script and a bare img — the DOM parse must
		// tolerate it, and the whole report must still return rather than fatal.
		$this->post( '<div><p>unclosed <b>bold <ul><li>item</div></p> <script>1 < 2</script> <img src=x onerror=alert(1)>' );
		delete_transient( Cache::OPTIMIZE );

		$r = ( new Score( new Settings() ) )->report();
		$this->assertIsInt( $r['score'] ); // Reached the end — no fatal on messy markup.
	}
}
