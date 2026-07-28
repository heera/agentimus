<?php
/**
 * The robots watch ({@see RobotsWatch}) — policy-line normalization, the
 * observe/baseline/change cycle (a change is recorded once and expires), the
 * readiness row it powers, and the digest surfaces. The scenario under test is
 * the real one: a plugin activates and silently rewrites robots.txt through
 * core's `robots_txt` filter.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Digest\Renderer;
use Agentimus\Readiness;
use Agentimus\RobotsWatch;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class RobotsWatchTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* ---- normalize -------------------------------------------------------- */

	/** Only policy directives survive; comments, blanks and prose fall away. */
	public function test_normalize_keeps_policy_lines_only() {
		$text = "# managed by ExampleCorp\n\nUser-Agent:   GPTBot\nDisallow: /\nContent-Signal: ai-train=no\nSitemap: https://x.test/sitemap.xml\nsome stray prose\nHost: x.test\n";
		$this->assertSame(
			array(
				'user-agent: GPTBot',
				'disallow: /',
				'content-signal: ai-train=no',
				'sitemap: https://x.test/sitemap.xml',
			),
			RobotsWatch::normalize( $text )
		);
	}

	public function test_excerpt_caps_and_counts_the_rest() {
		$lines = array( 'a: 1', 'b: 2', 'c: 3', 'd: 4', 'e: 5', 'f: 6' );
		$this->assertSame( 'a: 1; b: 2; c: 3; d: 4 (+2 more)', RobotsWatch::excerpt( $lines ) );
		$this->assertSame( 'a: 1', RobotsWatch::excerpt( array( 'a: 1' ) ) );
	}

	/* ---- observe / change ------------------------------------------------- */

	/** First look remembers silently; the same content never becomes news. */
	public function test_first_observe_is_a_silent_baseline() {
		RobotsWatch::observe();
		$this->assertNull( RobotsWatch::change() );
		RobotsWatch::observe();
		$this->assertNull( RobotsWatch::change() );
	}

	/** The ThinkRank scenario: a filter rewrites robots — the change is caught. */
	public function test_a_filter_takeover_records_added_and_removed_lines() {
		RobotsWatch::observe(); // Baseline: core default rules.

		\add_filter( 'robots_txt', function () {
			return "User-agent: *\nContent-Signal: ai-train=no\nDisallow: /wp-admin/\n";
		} );
		RobotsWatch::observe();

		$change = RobotsWatch::change();
		$this->assertNotNull( $change );
		$this->assertContains( 'content-signal: ai-train=no', $change['added'] );
		$this->assertContains( 'allow: /wp-admin/admin-ajax.php', $change['removed'] );
	}

	/** A comment-only edit is not a policy change — no news, baseline moves on. */
	public function test_comment_only_changes_are_not_news() {
		RobotsWatch::observe();
		\add_filter( 'robots_txt', function ( $text ) {
			return "# a new comment\n" . $text;
		} );
		RobotsWatch::observe();
		$this->assertNull( RobotsWatch::change() );
	}

	/** Old news clears itself — and the stored event is deleted, not replayed. */
	public function test_change_expires_and_clears() {
		RobotsWatch::observe();
		\add_filter( 'robots_txt', function () {
			return "User-agent: GPTBot\nDisallow: /\n";
		} );
		RobotsWatch::observe();
		$this->assertNotNull( RobotsWatch::change() );

		$state                 = \get_option( RobotsWatch::OPTION );
		$state['change']['at'] = time() - RobotsWatch::EXPIRE_AFTER - 60;
		\update_option( RobotsWatch::OPTION, $state, false );

		$this->assertNull( RobotsWatch::change() );
		$this->assertNull( \get_option( RobotsWatch::OPTION )['change'] ); // Cleared, not just hidden.
	}

	/* ---- the readiness row ------------------------------------------------ */

	private function robots_change_row() {
		$m = new \ReflectionMethod( Readiness::class, 'check_robots_change' );
		$m->setAccessible( true );
		return $m->invoke( new Readiness( new Settings() ) );
	}

	/** No change on record: no row at all. */
	public function test_row_absent_without_a_change() {
		$this->assertNull( $this->robots_change_row() );
	}

	/** With a change: a warn that shows the lines and never blames the owner. */
	public function test_row_warns_with_the_lines_and_neutral_wording() {
		\update_option(
			RobotsWatch::OPTION,
			array(
				'hash'     => 'x',
				'lines'    => array(),
				'taken_at' => time(),
				'change'   => array(
					'at'      => time(),
					'added'   => array( 'content-signal: ai-train=no' ),
					'removed' => array( 'allow: /wp-admin/admin-ajax.php' ),
				),
			),
			false
		);
		$row = $this->robots_change_row();
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'robots_change', $row['id'] );
		$this->assertStringContainsString( 'content-signal: ai-train=no', $row['detail'] );
		$this->assertStringContainsString( 'Gone: allow: /wp-admin/admin-ajax.php', $row['detail'] );
		$this->assertStringContainsString( 'If you made this change, everything is fine', $row['fix'] );
	}

	/* ---- the digest surfaces ---------------------------------------------- */

	/** A robots change alone makes a quiet week worth sending. */
	public function test_should_send_on_a_quiet_week_with_a_robots_change() {
		$quiet = array(
			'agents'    => array( 'total' => 0 ),
			'referrals' => array( 'total' => 0 ),
			'access'    => array( 'events' => 0 ),
			'score'     => array( 'now' => 80, 'prev' => 80 ),
			'robots'    => array( 'change' => null ),
		);
		$this->assertFalse( Renderer::should_send( $quiet ) );

		$quiet['robots']['change'] = array( 'at' => time(), 'added' => array( 'disallow: /' ), 'removed' => array() );
		$this->assertTrue( Renderer::should_send( $quiet ) );
	}

	/** The digest section names the date and the counts; absent when null. */
	public function test_digest_section_renders_the_change() {
		$m = new \ReflectionMethod( Renderer::class, 'section_robots' );
		$m->setAccessible( true );

		$this->assertSame( '', $m->invoke( null, array( 'robots' => array( 'change' => null ) ) ) );

		$html = $m->invoke( null, array(
			'robots' => array(
				'change' => array( 'at' => time(), 'added' => array( 'a: 1', 'b: 2' ), 'removed' => array( 'c: 3' ) ),
			),
		) );
		$this->assertStringContainsString( '2 lines added, 1 removed', $html );
		$this->assertStringContainsString( 'check your recently activated plugins', $html );
	}
}
