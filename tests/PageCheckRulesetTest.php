<?php
/**
 * The fingerprint of the check set — the question a stored grade was answering.
 *
 * A grade is an answer. The store kept the answer and forgot the question, so
 * adding a check or moving a threshold changed what "worth fixing" meant on
 * every page while nothing re-read a single one. 1.37.0 escaped it by accident
 * (the grade table was new that release, so every site swept from empty); the
 * next release to touch a check would have shipped a site full of verdicts from
 * the previous one, with nothing on any screen saying so.
 *
 * ⭐ The point of these tests is that the fingerprint is DERIVED. A constant
 * somebody has to remember to bump is the same failure with an extra step, and
 * forgetting it is invisible — every screen keeps answering, in the old checks'
 * words.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\PageCheck;
use PHPUnit\Framework\TestCase;

final class PageCheckRulesetTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_af_filters']['agentimus_page_ruleset'] );
	}

	public function test_it_is_a_short_stable_fingerprint() {
		$one = PageCheck::ruleset();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $one, 'Short enough for a column, wide enough that two check sets never meet by accident.' );
		$this->assertSame( $one, PageCheck::ruleset(), 'Unstable between calls would re-grade the site for ever.' );
	}

	/**
	 * ⭐ THE ONE THAT MATTERS. Written the way the code builds it, so a change to
	 * the RECIPE fails here and has to be thought about — while a change to a
	 * threshold simply moves both sides, which is the whole point: the site
	 * re-reads itself and nobody had to remember anything.
	 */
	public function test_it_is_built_from_the_check_ids_and_the_thresholds_they_judge_by() {
		$ids = array_keys( PageCheck::issue_labels() );
		sort( $ids );

		$constants = ( new \ReflectionClass( PageCheck::class ) )->getConstants();
		ksort( $constants );
		$scalars = array();
		foreach ( $constants as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$scalars[] = $name . '=' . ( is_bool( $value ) ? (int) $value : (string) $value );
			}
		}

		$expected = substr( md5( implode( ',', $ids ) . '|' . implode( ',', $scalars ) . '|' ), 0, 12 );
		$this->assertSame( $expected, PageCheck::ruleset() );

		// And prove the thresholds are genuinely in the mix: the ids alone are a
		// different answer, so a re-tuned check cannot slip through unnoticed.
		$this->assertNotSame( substr( md5( implode( ',', $ids ) ), 0, 12 ), PageCheck::ruleset() );
	}

	public function test_every_check_the_analyzer_emits_is_inside_the_fingerprint() {
		// ⛔ The fingerprint is built from the label map, so the map has to BE the
		// id list. A check missing from it would be a check the fingerprint
		// cannot notice changing — silently, and in the reassuring direction.
		$post = new \WP_Post(
			array(
				'ID'           => 771,
				'post_title'   => 'A thin page',
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Barely anything here.',
			)
		);
		$emitted = array();
		foreach ( PageCheck::analyze( $post ) as $row ) {
			$emitted[] = (string) $row['id'];
		}
		$emitted = array_values( array_unique( array_filter( $emitted ) ) );
		$this->assertNotEmpty( $emitted, 'The analyzer produced no checks — this guard would pass vacuously.' );

		$known = array_keys( PageCheck::issue_labels() );
		foreach ( $emitted as $id ) {
			$this->assertContains( $id, $known, "The analyzer emits `$id` and the fingerprint has never heard of it." );
		}
	}

	public function test_an_add_on_that_changes_the_checks_can_say_so() {
		$before = PageCheck::ruleset();

		// An add-on appending its own checks through `agentimus_page_checks` has
		// changed what a grade means; this is how it asks for the re-read.
		add_filter( 'agentimus_page_ruleset', static function () {
			return 'pro-checks-v4';
		} );

		$after = PageCheck::ruleset();
		$this->assertNotSame( $before, $after );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $after );
		$this->assertSame( $after, PageCheck::ruleset(), 'Still stable once the add-on has spoken.' );
	}
}
