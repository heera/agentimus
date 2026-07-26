<?php
/**
 * Internal-link suggestions — the local finder (candidate scoring from shared
 * topics/terms/title-wording), the anchor-phrase search (longest verbatim
 * n-gram, word-bounded, original casing), and the suggest() rows — including
 * that the feature stands entirely on its own when no AI provider exists.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\InternalLinks;
use Agentimus\Settings;
use Agentimus\Topics;
use PHPUnit\Framework\TestCase;

final class InternalLinksTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/**
	 * A published post fixture with optional topics, terms and body.
	 *
	 * @param int    $id     Post ID.
	 * @param string $title  Title.
	 * @param string $body   Content.
	 * @param array  $topics Manual topic list.
	 * @param array  $tags   post_tag term names.
	 */
	private function fixture( $id, $title, $body = '', array $topics = array(), array $tags = array() ) {
		$GLOBALS['_af_posts'][ $id ] = new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $body,
				'post_excerpt' => '',
			)
		);
		if ( $topics ) {
			$GLOBALS['_af_postmeta'][ $id ][ Topics::META_TOPICS ] = $topics;
		}
		if ( $tags ) {
			$GLOBALS['_af_terms'][ $id ]['post_tag'] = $tags;
		}
		return $GLOBALS['_af_posts'][ $id ];
	}

	/* ------------------------------ word/phrase primitives ------------------------------ */

	public function test_meaningful_words_drops_the_noise() {
		$this->assertSame( array( 'fixing', 'missed', 'cron', 'schedules' ), InternalLinks::meaningful_words( 'Fixing the missed WP cron schedules' ) );
	}

	public function test_find_in_text_is_word_bounded_and_keeps_the_bodys_casing() {
		$body = 'Everything about WP-Cron Schedules lives here.';
		$this->assertSame( 'WP-Cron Schedules', InternalLinks::find_in_text( 'wp-cron schedules', $body ) );
		$this->assertSame( '', InternalLinks::find_in_text( 'cron sched', $body ), 'no matches inside words' );
	}

	public function test_anchor_phrase_prefers_the_longest_verbatim_run() {
		$body = 'When your site keeps missing scheduled posts, the missed schedules healer helps.';
		$this->assertSame( 'missed schedules', InternalLinks::anchor_phrase( 'Fixing missed schedules fast', $body ) );
	}

	public function test_anchor_phrase_falls_back_to_one_meaningful_word_or_nothing() {
		$this->assertSame( 'bookbinding', InternalLinks::anchor_phrase( 'Our bookbinding guide', 'A page about bookbinding techniques.' ) );
		$this->assertSame( '', InternalLinks::anchor_phrase( 'The a of', 'Nothing relevant here.' ), 'stop-words alone never anchor' );
		$this->assertSame( '', InternalLinks::anchor_phrase( 'Quantum pottery', 'A page about gardening.' ) );
	}

	/* ------------------------------ candidate scoring ------------------------------ */

	public function test_candidates_rank_shared_signals_and_drop_the_unrelated() {
		$subject = $this->fixture( 1, 'Fixing WP-Cron problems', 'Our post about wp-cron hosting and missed schedules on WordPress.', array( 'wp-cron' ), array( 'cron' ) );
		$strong  = $this->fixture( 2, 'Missed schedules explained', '', array( 'wp-cron' ), array( 'cron' ) );  // topic + tag + title words in body
		$weak    = $this->fixture( 3, 'WordPress hosting basics', '', array(), array() );                        // two title words in body
		$single  = $this->fixture( 4, 'Sourdough hosting', '', array(), array() );                               // ONE generic word — noise
		$noise   = $this->fixture( 5, 'Pottery glazes', '', array( 'baking' ), array() );                        // nothing shared

		$GLOBALS['_af_get_posts'] = array( $strong, $weak, $single, $noise );

		$ranked = InternalLinks::candidates( $subject, 5 );
		$ids    = array_map( static function ( $c ) { return $c['post']->ID; }, $ranked );

		$this->assertSame( array( 2, 3 ), $ids, 'strong first; a single generic word is noise; unrelated dropped entirely' );
		$this->assertContains( 'wp-cron', $ranked[0]['shared'] );
	}

	public function test_candidates_never_suggest_the_post_itself() {
		$subject = $this->fixture( 1, 'Self', 'self reference self', array( 'x' ) );
		$GLOBALS['_af_get_posts'] = array( $subject );

		$this->assertSame( array(), InternalLinks::candidates( $subject, 5 ) );
	}

	/* ------------------------------ suggest() rows ------------------------------ */

	public function test_suggest_builds_full_rows_without_any_ai() {
		$subject = $this->fixture( 1, 'Fixing WP-Cron problems', 'All about missed schedules explained simply.', array( 'wp-cron' ) );
		$target  = $this->fixture( 2, 'Missed schedules explained', '', array( 'wp-cron' ) );
		$GLOBALS['_af_get_posts'] = array( $target );

		$rows = ( new InternalLinks( new Settings() ) )->suggest( $subject, false );

		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $rows[0]['id'] );
		$this->assertSame( 'Missed schedules explained', $rows[0]['title'] );
		$this->assertSame( 'missed schedules explained', $rows[0]['phrase'], 'the verbatim in-text phrase Insert would link' );
		$this->assertStringContainsString( 'wp-cron', $rows[0]['why'] );
		$this->assertNotSame( '', $rows[0]['url'] );
	}

	public function test_suggest_marks_the_append_fallback_with_an_empty_phrase() {
		$subject = $this->fixture( 1, 'Something else entirely', 'No overlap with the target title here.', array( 'shared' ) );
		$target  = $this->fixture( 2, 'Quantum pottery', '', array( 'shared' ) );
		$GLOBALS['_af_get_posts'] = array( $target );

		$rows = ( new InternalLinks( new Settings() ) )->suggest( $subject, false );

		$this->assertCount( 1, $rows );
		$this->assertSame( '', $rows[0]['phrase'], 'no verbatim phrase → the UI offers the "See also" append instead' );
	}

	public function test_suggest_with_ai_requested_but_unavailable_keeps_the_local_rows() {
		// The unit environment has no wp_ai_client_prompt at all — the dressing
		// call errors out and the local rows must stand untouched.
		$subject = $this->fixture( 1, 'Fixing WP-Cron problems', 'All about missed schedules explained simply.', array( 'wp-cron' ) );
		$target  = $this->fixture( 2, 'Missed schedules explained', '', array( 'wp-cron' ) );
		$GLOBALS['_af_get_posts'] = array( $target );

		$rows = ( new InternalLinks( new Settings() ) )->suggest( $subject, true );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'missed schedules explained', $rows[0]['phrase'] );
	}
}
