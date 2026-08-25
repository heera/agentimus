<?php
/**
 * The worklist must not fill with rows nothing can close.
 *
 * ⛔⛔ TWO WAYS THAT HAPPENED, both fixed here and both invisible on an
 * English-language test fixture:
 *
 *   1. A string that is not a question this site can answer — a URL pasted into
 *      the search box, a prompt pasted in whole — was judged against a page and
 *      failed it. The filter guarding this asked only "does this use search
 *      OPERATORS?", so it caught `site:` and let a bare URL through.
 *
 *   2. A search written in any non-Latin script yielded no words the grader
 *      could look for, and that came back MISSING — "None of it is on the page"
 *      — about pages that answer perfectly. On a Russian, Greek, Japanese,
 *      Arabic, Hindi or Chinese site that is EVERY page with a reported search:
 *      the whole site permanently on the worklist, permanently accused, and
 *      unfixable by writing anything {@see \Agentimus\Search\Coverage::UNREADABLE}.
 *
 * ⭐ Both are the same law: a page may only be marked down for failing a
 * question that was actually put to it and actually measured.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Grades;
use Agentimus\Search\Coverage;
use Agentimus\Search\Table;
use Agentimus\Settings;
use Agentimus\Worklist;

final class WorklistNoiseDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
		delete_option( Table::VERSION_OPTION );
		Table::install();
		$this->reset();
		( new \Agentimus\Google\Settings() )->connect( '{"type":"service_account"}', 'bot@example.com', 'sc-domain:example.com' );
	}

	public function tear_down(): void {
		$this->reset();
		parent::tear_down();
	}

	/** ⚠️ DELETE, never TRUNCATE — TRUNCATE commits and ends the test's transaction. */
	private function reset() {
		global $wpdb;
		foreach ( array( Grades::name(), Table::name(), $wpdb->postmeta, $wpdb->posts ) as $table ) {
			$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
		}
	}

	private function post( $title, $content ) {
		return (int) self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		) );
	}

	private function reported( $post_id, $query, $impressions = 200 ) {
		return array(
			'query'       => $query,
			'page_url'    => get_permalink( $post_id ),
			'page_id'     => (int) $post_id,
			'clicks'      => 0,
			'impressions' => (int) $impressions,
			'position'    => 6.0,
			'range_start' => '2026-07-01',
			'range_end'   => '2026-07-28',
		);
	}

	private function coverage_of( $post_id ) {
		return ( new Worklist( new Settings() ) )->coverage_for( get_post( $post_id ) );
	}

	/* --------------------------------------------------------- not a question */

	public function test_a_url_pasted_into_search_never_becomes_a_verdict_about_a_page() {
		$id = $this->post( 'Cross-tab locking', '<p>A named lock keeps two tabs from running one job twice.</p>' );
		Table::replace( 'google', array( $this->reported( $id, 'https://eat-token.0web.top' ) ) );

		$cov = $this->coverage_of( $id );

		$this->assertFalse( $cov['measured'], '⛔ Judged against a URL, this page used to come back "missing".' );
		$this->assertSame( '', $cov['query'], 'The URL must not even be named as this page’s search.' );
		$this->assertSame( 'no_search', $cov['reason'] );
	}

	public function test_a_prompt_pasted_whole_never_becomes_a_verdict_about_a_page() {
		$id     = $this->post( 'Cross-tab locking', '<p>A named lock keeps two tabs from running one job twice.</p>' );
		$prompt = 'rank in-cms assistants that suggest metadata, h1s, and semantic terms while drafting. '
			. 'include controls to disable auto-publishing.';
		Table::replace( 'google', array( $this->reported( $id, $prompt ) ) );

		$this->assertFalse( $this->coverage_of( $id )['measured'] );
	}

	/** The real search still wins when both were reported — noise is dropped, not preferred. */
	public function test_a_real_search_alongside_noise_is_the_one_the_page_is_judged_on() {
		$id = $this->post( 'Cross-tab locking', '<p>A named lock keeps two tabs from running one job twice.</p>' );
		Table::replace(
			'google',
			array(
				$this->reported( $id, 'https://eat-token.0web.top', 900 ),
				$this->reported( $id, 'named lock two tabs', 50 ),
			)
		);

		$cov = $this->coverage_of( $id );

		$this->assertTrue( $cov['measured'] );
		$this->assertSame( 'named lock two tabs', $cov['query'], '⛔ Noise outranked it on impressions and must still lose.' );
	}

	/* ------------------------------------------------- not a language we read */

	/**
	 * ⭐⭐ THE ONE THAT MATTERED. This page answers its search. In Russian.
	 */
	public function test_a_site_in_another_alphabet_is_not_told_its_pages_answer_nothing() {
		$id = $this->post(
			'Как заблокировать роботов',
			'<p>Как заблокировать поисковых роботов через robots.txt на WordPress.</p>'
		);
		Table::replace( 'google', array( $this->reported( $id, 'как заблокировать роботов' ) ) );

		$cov = $this->coverage_of( $id );

		$this->assertNotSame(
			Coverage::MISSING,
			$cov['state'],
			'⛔⛔ This page answers its search and used to be told none of it was there.'
		);
		$this->assertFalse( $cov['measured'], 'Nothing was compared, so nothing may be claimed either way.' );

		$worklist = new Worklist( new Settings() );
		$this->assertFalse(
			$worklist->needs_work( array( 'flags' => array(), 'coverage' => array( 'state' => $cov['state'] ) ) ),
			'⛔ …and it must not be counted as work no edit could ever clear.'
		);
	}

	/**
	 * ⛔ ONE PAGE, ONE WORD. The worklist row stores the coverage state and the
	 * per-page tool measures it live; if those two ever print different words
	 * for the same page, an agent reading both cannot tell which to believe.
	 */
	public function test_the_per_page_tool_and_the_worklist_row_say_the_same_thing() {
		$id = $this->post(
			'Как заблокировать роботов',
			'<p>Как заблокировать поисковых роботов через robots.txt на WordPress.</p>'
		);
		update_post_meta( $id, \Agentimus\Focus::META, 'как заблокировать роботов' );
		Table::replace( 'google', array() );

		$worklist = new Worklist( new Settings() );
		$worklist->sweep( 50 );

		$row = null;
		foreach ( array( 'fixable', 'clear' ) as $bucket ) {
			foreach ( $worklist->issues( $bucket, 1, 30 )['items'] as $item ) {
				if ( (int) $item['id'] === $id ) {
					$row = $item;
				}
			}
		}
		$this->assertNotNull( $row, 'The page has to be on one of the lists for the two to be comparable.' );

		$cov = $this->coverage_of( $id );

		$this->assertSame( Coverage::UNREADABLE, $row['coverage'], 'The stored row names the state.' );
		$this->assertSame( $row['coverage'], $cov['state'], '⛔ …and the per-page tool must name the same one.' );
		$this->assertFalse( $cov['measured'], 'Named, but still not a verdict.' );
		$this->assertSame( 'unreadable', $cov['reason'] );

		// ⚠️ This short fixture DOES carry content flags, so the row asks for
		// work — for those. What must not happen is coverage adding a demand of
		// its own: strip the flags and the same verdict asks for nothing.
		$this->assertNotSame( array(), $row['issues'], 'FIXTURE: this page is expected to flag on content.' );
		$this->assertFalse(
			$worklist->needs_work( array( 'flags' => array(), 'coverage' => array( 'state' => $row['coverage'] ) ) ),
			'⛔ The coverage half must contribute no demand no edit could satisfy.'
		);
	}

	/**
	 * ⛔ A change to what coverage SAYS has to move the key that decides whether
	 * a stored verdict is re-read, or the fix above reaches no existing install.
	 */
	public function test_the_stored_ruleset_covers_the_coverage_rules_too() {
		$this->assertNotSame(
			\Agentimus\PageCheck::ruleset(),
			Grades::ruleset(),
			'⛔ The stored key must not be the content checks alone — coverage is half of what a row says.'
		);

		// Prove the coupling rather than asserting the shape of it: change what
		// coverage judges by, and the key that re-reads stored rows must move.
		$before = Grades::ruleset();
		$widen  = static function ( $stop ) {
			return array_merge( (array) $stop, array( 'wordpress' ) );
		};
		add_filter( 'agentimus_coverage_stopwords', $widen );
		$after = Grades::ruleset();
		remove_filter( 'agentimus_coverage_stopwords', $widen );

		$this->assertNotSame(
			$before,
			$after,
			'⛔ Coverage rules changed and nothing would be re-read — correct code nothing re-reads under is code never written.'
		);
		$this->assertSame( $before, Grades::ruleset(), 'And it settles again, or the site re-grades for ever.' );
	}
}
