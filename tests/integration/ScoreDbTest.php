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

	public function test_adversarial_post_markup_never_crashes_the_report() {
		// Unclosed tags, nested lists, a script and a bare img — the DOM parse must
		// tolerate it, and the whole report must still return rather than fatal.
		$this->post( '<div><p>unclosed <b>bold <ul><li>item</div></p> <script>1 < 2</script> <img src=x onerror=alert(1)>' );
		delete_transient( Cache::OPTIMIZE );

		$r = ( new Score( new Settings() ) )->report();
		$this->assertIsInt( $r['score'] ); // Reached the end — no fatal on messy markup.
	}
}
