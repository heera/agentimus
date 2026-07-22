<?php
/**
 * The review ask's state machine — the earned-it gates (days present, visits,
 * real actions taken, readiness score), the snooze/close transitions, and the
 * promise that an answered ask stays answered. All pure functions plus the option-backed
 * wrappers over the test bootstrap's in-memory options.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Review;
use PHPUnit\Framework\TestCase;

final class ReviewAskTest extends TestCase {

	/** A fixed "now" so every gate computes on known arithmetic. */
	const NOW = 1700000000;

	protected function setUp(): void {
		$GLOBALS['_af_options'] = array();
	}

	/** State that clears every gate — tests below break one gate at a time. */
	private function ripe() {
		return array(
			'since'   => self::NOW - ( Review::MIN_DAYS + 1 ) * DAY_IN_SECONDS,
			'visits'  => Review::MIN_VISITS,
			'actions' => Review::MIN_ACTIONS,
			'state'   => '',
			'until'   => 0,
		);
	}

	/* -- eligible(): the earned-it gates --------------------------------- */

	public function test_ripe_state_with_passing_score_is_eligible() {
		$this->assertTrue( Review::eligible( $this->ripe(), Review::MIN_SCORE, self::NOW ) );
	}

	public function test_fresh_install_is_not_eligible() {
		$this->assertFalse( Review::eligible( array(), 100, self::NOW ) );
	}

	public function test_six_days_is_too_soon() {
		$state          = $this->ripe();
		$state['since'] = self::NOW - ( Review::MIN_DAYS * DAY_IN_SECONDS - 1 );
		$this->assertFalse( Review::eligible( $state, 100, self::NOW ) );
	}

	public function test_looking_without_doing_is_not_enough() {
		// A week present, plenty of visits, perfect score — but the owner never
		// changed anything through the plugin. The ask stays quiet.
		$state            = $this->ripe();
		$state['actions'] = Review::MIN_ACTIONS - 1;
		$state['visits']  = 20;
		$this->assertFalse( Review::eligible( $state, 100, self::NOW ) );
	}

	public function test_two_visits_are_too_few() {
		$state           = $this->ripe();
		$state['visits'] = Review::MIN_VISITS - 1;
		$this->assertFalse( Review::eligible( $state, 100, self::NOW ) );
	}

	public function test_score_below_the_bar_is_not_eligible() {
		$this->assertFalse( Review::eligible( $this->ripe(), Review::MIN_SCORE - 1, self::NOW ) );
	}

	public function test_unmeasured_score_is_not_eligible() {
		$this->assertFalse( Review::eligible( $this->ripe(), null, self::NOW ) );
	}

	public function test_closed_never_shows_again_whatever_the_numbers() {
		$state          = $this->ripe();
		$state['state'] = 'closed';
		$this->assertFalse( Review::eligible( $state, 100, self::NOW + 100 * DAY_IN_SECONDS ) );
	}

	public function test_snooze_holds_until_it_runs_out() {
		$state          = $this->ripe();
		$state['state'] = 'later';
		$state['until'] = self::NOW + DAY_IN_SECONDS;
		$this->assertFalse( Review::eligible( $state, 100, self::NOW ) );
		$this->assertTrue( Review::eligible( $state, 100, self::NOW + DAY_IN_SECONDS ) );
	}

	public function test_corrupt_state_string_reads_as_unanswered() {
		$state          = $this->ripe();
		$state['state'] = 'nonsense';
		$this->assertTrue( Review::eligible( $state, 100, self::NOW ) );
	}

	/* -- touched(): the visit counter ------------------------------------ */

	public function test_first_touch_seeds_the_clock_and_counts_the_visit() {
		$state = Review::touched( array(), self::NOW );
		$this->assertSame( self::NOW, $state['since'] );
		$this->assertSame( 1, $state['visits'] );
	}

	public function test_second_touch_keeps_the_original_clock() {
		$state = Review::touched( Review::touched( array(), self::NOW ), self::NOW + 100 );
		$this->assertSame( self::NOW, $state['since'] );
		$this->assertSame( 2, $state['visits'] );
	}

	public function test_visits_stop_counting_at_the_cap() {
		$state           = $this->ripe();
		$state['visits'] = Review::VISIT_CAP;
		$this->assertSame( Review::VISIT_CAP, Review::touched( $state, self::NOW )['visits'] );
	}

	/* -- acted() and use_worthy(): the real-use signal --------------------- */

	public function test_acted_counts_and_caps() {
		$state = Review::acted( array() );
		$this->assertSame( 1, $state['actions'] );
		$state['actions'] = Review::VISIT_CAP;
		$this->assertSame( Review::VISIT_CAP, Review::acted( $state )['actions'] );
	}

	public function test_plugin_writes_are_use_worthy() {
		$this->assertTrue( Review::use_worthy( '/agentimus/v1/settings', 'POST' ) );
		$this->assertTrue( Review::use_worthy( '/agentimus/v1/optimize/ignore', 'POST' ) );
	}

	public function test_reads_other_plugins_and_the_ask_itself_are_not_use() {
		$this->assertFalse( Review::use_worthy( '/agentimus/v1/settings', 'GET' ), 'reading is not use' );
		$this->assertFalse( Review::use_worthy( '/wp/v2/posts', 'POST' ), 'other namespaces are not ours' );
		$this->assertFalse( Review::use_worthy( '/agentimus/v1/review-ack', 'POST' ), 'answering the ask must not feed its own gates' );
	}

	/* -- answered(): the transitions -------------------------------------- */

	public function test_later_snoozes_for_thirty_days() {
		$state = Review::answered( $this->ripe(), 'later', self::NOW );
		$this->assertSame( 'later', $state['state'] );
		$this->assertSame( self::NOW + Review::SNOOZE_DAYS * DAY_IN_SECONDS, $state['until'] );
	}

	public function test_review_and_done_close_for_good() {
		foreach ( array( 'review', 'done' ) as $answer ) {
			$this->assertSame( 'closed', Review::answered( $this->ripe(), $answer, self::NOW )['state'] );
		}
	}

	public function test_unknown_answer_changes_nothing() {
		$this->assertSame( $this->ripe(), Review::answered( $this->ripe(), 'delete-everything', self::NOW ) );
	}

	public function test_a_final_answer_is_final_even_against_a_stale_client() {
		// Two admins: one closes the ask, the other's already-open dashboard
		// still shows the card. Their "Maybe later" must not reopen it.
		$state          = $this->ripe();
		$state['state'] = 'closed';
		$this->assertSame( 'closed', Review::answered( $state, 'later', self::NOW )['state'] );
	}

	/* -- the option-backed wrappers --------------------------------------- */

	public function test_touch_persists_and_ack_closes() {
		Review::touch();
		$this->assertSame( 1, Review::state()['visits'] );
		$this->assertFalse( Review::closed() );

		$this->assertSame( 'closed', Review::ack( 'done' ) );
		$this->assertTrue( Review::closed() );
	}

	public function test_ack_later_persists_the_snooze() {
		$this->assertSame( 'later', Review::ack( 'later' ) );
		$this->assertGreaterThan( time(), Review::state()['until'] );
		$this->assertFalse( Review::closed() );
	}
}
