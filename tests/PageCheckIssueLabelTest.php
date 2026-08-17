<?php
/**
 * The by-issue list is rebuilt from STORED check ids, so the ids and the words
 * that name them have to stay tied together.
 *
 * The grade store keeps `flag_ids` — `words`, `summary`, `freshness` — and never
 * the labels, deliberately: a site that changes language must not need
 * re-grading to be readable again. That buys correct translation and costs one
 * obligation, which is this file. {@see \Agentimus\PageCheck::issue_label()} has
 * to keep answering for every check {@see \Agentimus\PageCheck::analyze()} can
 * emit; a check whose id drifts away from the map does not fail loudly, it
 * quietly renders its own id as a heading on the Readiness card.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\PageCheck;
use PHPUnit\Framework\TestCase;

final class PageCheckIssueLabelTest extends TestCase {

	/**
	 * Every check id the analyzer produces, read off a REAL analysis rather than
	 * listed by hand — a hand-written list would drift in exactly the same way
	 * the map can, and would then agree with it while both were wrong.
	 *
	 * The post is deliberately threadbare so that as many checks as possible
	 * land on their non-pass branch, but the assertion below is about the id
	 * space, which every row carries whatever its status.
	 *
	 * @return string[]
	 */
	private function analyzed_ids(): array {
		$post = new \WP_Post(
			array(
				'ID'           => 501,
				'post_title'   => 'A thin page',
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Barely anything here.',
			)
		);
		$ids = array();
		foreach ( PageCheck::analyze( $post ) as $row ) {
			$ids[] = (string) $row['id'];
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public function test_every_check_the_analyzer_emits_has_a_name_for_its_problem() {
		$ids = $this->analyzed_ids();
		$this->assertNotEmpty( $ids, 'The analyzer produced no checks — this guard would pass vacuously.' );

		foreach ( $ids as $id ) {
			$label = PageCheck::issue_label( $id );
			$this->assertNotSame(
				$id,
				$label,
				"PageCheck::issue_label() has no entry for `$id`, so the Readiness card would print the raw id as a heading. Add it when you add a check."
			);
			$this->assertNotSame( '', trim( $label ), "`$id` maps to an empty heading, which reads as no issue at all." );
		}
	}

	/**
	 * The other direction. A map entry for a check that no longer exists is dead
	 * weight that reads as coverage — the next person adding a check sees a full
	 * map and assumes it is being maintained.
	 */
	public function test_the_map_names_nothing_the_analyzer_cannot_produce() {
		$ids = $this->analyzed_ids();

		// The map is a local inside the method, so it is probed the only way a
		// closed map can be: every id it answers for must be an id that exists.
		foreach ( array( 'words', 'summary', 'evidence', 'sources', 'headings', 'heading_order', 'passages', 'reading_ease', 'link_density', 'alt_text', 'media', 'featured_image', 'freshness' ) as $id ) {
			$this->assertContains(
				$id,
				$ids,
				"issue_label() answers for `$id`, but analyze() no longer emits it — a stale entry makes the map look maintained when it is not."
			);
		}
	}

	/** An id from an add-on's own check is named after itself, never silently blank. */
	public function test_an_unknown_check_is_named_after_itself_rather_than_left_blank() {
		$this->assertSame( 'acme_tone_of_voice', PageCheck::issue_label( 'acme_tone_of_voice' ) );
		$this->assertSame( '', PageCheck::issue_label( '' ) );
	}
}
