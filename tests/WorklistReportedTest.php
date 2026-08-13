<?php
/**
 * The worklist's honesty about WHOSE search a row is judged against.
 *
 * Choosing a focus keyword used to hide the engine's own verdict: the row said
 * "You chose X" and the only trace of what the page is really found for was a
 * "+3 more searches" count. A page aimed at the wrong words looked exactly like
 * one aimed at the right ones — which is the single question a focus decision
 * needs answered.
 *
 * So a chosen row now carries the reported search alongside the choice, under
 * three rules pinned here: it speaks only when the two DIFFER, it stays silent
 * when nothing was reported or nothing was chosen, and it names the engine from
 * the row's own source rather than assuming Google (a Bing-only site must never
 * be told Google said it).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Worklist;
use PHPUnit\Framework\TestCase;

final class WorklistReportedTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** The engine's reported searches for a page, best first. */
	private function rows() {
		return array(
			array( 'query' => 'open knowledge format', 'position' => 4.2, 'impressions' => 120, 'clicks' => 3 ),
			array( 'query' => 'okf json', 'position' => 9.1, 'impressions' => 12, 'clicks' => 0 ),
		);
	}

	/**
	 * @param array $chosen { chosen: bool }.
	 * @param array $rows   Reported searches.
	 * @param string $shown The query the row already shows.
	 * @return array|null
	 */
	private function reported( array $chosen, array $rows, $shown ) {
		$method = new \ReflectionMethod( Worklist::class, 'reported_against' );
		$method->setAccessible( true );
		$list = ( new \ReflectionClass( Worklist::class ) )->newInstanceWithoutConstructor();
		return $method->invoke( $list, $chosen, $rows, $shown );
	}

	public function test_a_chosen_focus_carries_what_the_engine_actually_shows() {
		$out = $this->reported( array( 'chosen' => true ), $this->rows(), 'okf bundle' );

		$this->assertIsArray( $out );
		$this->assertSame( 'open knowledge format', $out['query'], 'The engine’s own top search, not the second one.' );
		$this->assertSame( 4.2, $out['position'] );
		$this->assertSame( 120, $out['impressions'] );
		$this->assertSame( 3, $out['clicks'] );
		$this->assertArrayHasKey( 'engine', $out, 'The row names its source rather than assuming one.' );
	}

	public function test_it_says_nothing_when_the_choice_and_the_report_agree() {
		$this->assertNull(
			$this->reported( array( 'chosen' => true ), $this->rows(), 'open knowledge format' ),
			'Agreement is not news — a second line saying the same phrase is noise.'
		);
	}

	public function test_a_row_with_no_chosen_focus_says_nothing() {
		// Without a choice the row's phrase IS the reported search, so there is
		// no second thing to report.
		$this->assertNull( $this->reported( array( 'chosen' => false ), $this->rows(), 'open knowledge format' ) );
	}

	public function test_a_chosen_focus_with_nothing_reported_says_nothing() {
		$this->assertNull(
			$this->reported( array( 'chosen' => true ), array(), 'okf bundle' ),
			'A page no engine has reported has no reality to contradict the choice with.'
		);
	}
}
