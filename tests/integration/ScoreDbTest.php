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

	public function test_report_has_the_five_rung_shape_over_real_posts() {
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

	public function test_adversarial_post_markup_never_crashes_the_report() {
		// Unclosed tags, nested lists, a script and a bare img — the DOM parse must
		// tolerate it, and the whole report must still return rather than fatal.
		$this->post( '<div><p>unclosed <b>bold <ul><li>item</div></p> <script>1 < 2</script> <img src=x onerror=alert(1)>' );
		delete_transient( Cache::OPTIMIZE );

		$r = ( new Score( new Settings() ) )->report();
		$this->assertIsInt( $r['score'] ); // Reached the end — no fatal on messy markup.
	}
}
