<?php
/**
 * Every button that promises a destination has to arrive at one.
 *
 * A finding, a readiness row and a score rung all say the same kind of thing —
 * "the fix is over there" — and each hands the SPA a target: a tab, sometimes a
 * DOM id to scroll to, sometimes an outward URL. Nothing at runtime complains
 * when a target is wrong. A dead anchor scrolls nowhere and the owner lands at
 * the top of a long screen; a field the consumer does not read leaves the action
 * with no destination at all, which is how "View llms.txt" came to open
 * Settings. Both failures look like a working button.
 *
 * So the seam is checked here, where a rename shows up as a red test instead of
 * as a screen that quietly stops going anywhere.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use PHPUnit\Framework\TestCase;

final class NavigationTargetsTest extends TestCase {

	/** @return string Every PHP source that can emit a navigation target. */
	private function php() {
		static $src = null;
		if ( null === $src ) {
			$src = '';
			foreach ( array( 'Readiness.php', 'Findings.php', 'Score.php' ) as $file ) {
				$src .= (string) file_get_contents( dirname( __DIR__ ) . '/inc/' . $file );
			}
		}
		return $src;
	}

	/** @return string Every admin source that can render one. */
	private function vue() {
		static $src = null;
		if ( null === $src ) {
			$src = (string) file_get_contents( dirname( __DIR__ ) . '/resources/admin/App.vue' );
			foreach ( (array) glob( dirname( __DIR__ ) . '/resources/admin/components/*.vue' ) as $file ) {
				$src .= (string) file_get_contents( $file );
			}
		}
		return $src;
	}

	/**
	 * Every DOM id a PHP subsystem asks to scroll to exists in the admin app.
	 *
	 * Readiness's nav() rows are the bulk of these, and most only render when
	 * their check is failing — a site in good shape never shows them, so a typo
	 * here can sit unnoticed for releases and only greet the one owner whose
	 * setup is broken.
	 */
	public function test_every_anchor_a_finding_points_at_exists() {
		$anchors = array();
		preg_match_all( "/\\\$this->nav\(.*?'agentimus'\s*\)\s*,\s*'([A-Za-z0-9_-]+)'\s*\)/", $this->php(), $m );
		$anchors = array_merge( $anchors, $m[1] );
		preg_match_all( "/'anchor'\s*=>\s*'([A-Za-z0-9_-]+)'/", $this->php(), $m );
		$anchors = array_merge( $anchors, $m[1] );
		// go( label, tab, view, anchor ). Ternaries make the fourth argument's
		// position unreliable, but an anchor is the only thing in that call that
		// is spelled ar-*, so read them by name rather than by position.
		$php = $this->php();
		$at  = 0;
		while ( false !== ( $at = strpos( $php, '$this->go(', $at ) ) ) {
			$at += 10;
			preg_match_all( "/'(ar-[A-Za-z0-9_-]+)'/", substr( $php, $at, 400 ), $m );
			$anchors = array_merge( $anchors, $m[1] );
		}

		$anchors = array_values( array_unique( array_filter( $anchors ) ) );
		$this->assertNotEmpty( $anchors, 'the extraction itself must keep working' );

		$vue      = $this->vue();
		$settings = ( new \Agentimus\Settings() )->all();
		foreach ( $anchors as $anchor ) {
			// Feature toggles are rendered by a v-for, so their id is BUILT
			// ('ar-feat-' + f.key) and never appears literally. Two halves to
			// check instead: the app still builds ids that way, and the key is a
			// real setting — a renamed setting is what would break the jump.
			if ( 0 === strpos( $anchor, 'ar-feat-' ) ) {
				$key = substr( $anchor, strlen( 'ar-feat-' ) );
				if ( false !== strpos( $vue, 'id="' . $anchor . '"' ) ) {
					continue; // Some are written out by hand; those are their own proof.
				}
				$this->assertStringContainsString( "'ar-feat-' + f.key", $vue, 'the app still builds feature ids' );
				$this->assertArrayHasKey( $key, $settings, "'$key' is not a setting, so '$anchor' can never render" );
				continue;
			}
			$this->assertStringContainsString( 'id="' . $anchor . '"', $vue, "nothing in the admin app answers to '$anchor'" );
		}
	}

	/**
	 * Every routing word a finding uses names a real screen or a real sub-view.
	 *
	 * A tab id no panel matches is a blank screen wearing a real URL — App.vue
	 * carries a legacy map for renamed ones precisely because that happened. The
	 * two kinds of word are checked together on purpose: go() takes a tab and a
	 * view positionally, ternaries move both, and a typo in either is the same
	 * failure to the reader.
	 */
	public function test_every_routing_word_names_something_real() {
		$screens = array( 'dashboard', 'readiness', 'discovery', 'findings', 'visibility', 'visitors', 'log', 'activity', 'settings', 'about' );
		// VisibilityPanel::openView's vocabulary. 'settings' and 'results' are
		// the citations pair; 'performance' and 'aisearch' are the top-level two.
		$views = array( 'performance', 'aisearch', 'results', 'settings' );

		$words = array( 'settings' ); // check_action()'s fallback.
		$php   = $this->php();
		$at    = 0;
		while ( false !== ( $at = strpos( $php, '$this->go(', $at ) ) ) {
			$at += 10;
			// Every lowercase quoted word in the call — tab and view alike. The
			// label's own strings are prose; the only bare word inside one is the
			// text domain, and anchors are all spelled ar-*.
			preg_match_all( "/'([a-z]+)'/", substr( $php, $at, 400 ), $m );
			foreach ( $m[1] as $word ) {
				if ( 'agentimus' !== $word ) {
					$words[] = $word;
				}
			}
		}
		preg_match_all( "/'tab'\s*=>\s*'([a-z]+)'/", $php, $m );
		$words = array_values( array_unique( array_merge( $words, $m[1] ) ) );
		$this->assertNotEmpty( $words );

		$known = array_merge( $screens, $views );
		foreach ( $words as $word ) {
			$this->assertContains( $word, $known, "'$word' is neither a screen nor a sub-view this app has" );
		}
	}

	/**
	 * The two names for one destination stay bridged.
	 *
	 * Readiness calls an outward link `href` and its own panel renders that;
	 * a finding's action calls it `url` and the front door opens that. The
	 * translation lives in one place, and both spellings have to survive it —
	 * this is the exact break that sent "View llms.txt" to Settings.
	 */
	public function test_the_two_spellings_of_a_destination_stay_bridged() {
		$this->assertStringContainsString( "'href'", $this->php(), 'Readiness still speaks href' );
		$this->assertStringContainsString( 'action.href', $this->vue(), 'a panel still renders href' );
		$this->assertStringContainsString( 'action.url', $this->vue(), 'the front door still opens url' );

		$bridged = \Agentimus\Findings::check_action( array( 'label' => 'x', 'href' => 'https://example.test/a' ) );
		$this->assertSame( 'https://example.test/a', $bridged['url'] );
	}
}
