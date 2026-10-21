<?php
/**
 * "One push from page one" names the push that is left.
 *
 * ⛔ CAUGHT ON heera.it (1.53.1): two pages ranking 8th and 9th were answered
 * and titled, and lacked only a link from another post. The Findings row told
 * the owner "use the words in the title, answer in one paragraph" — both done —
 * and never mentioned the link, the one edit that would have finished them.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Findings;
use Agentimus\Search\Table;
use Agentimus\Settings;

final class NearPageOneGapsDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		delete_option( Table::VERSION_OPTION );
		Table::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		( new \Agentimus\Google\Settings() )->connect( '{"type":"service_account"}', 'bot@example.com', 'sc-domain:example.com' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		// ⚠️ Put plain links back: WordPress 6.0's test case does not, and the
		// leak turned PageViewsDbTest's "/?p=251" into "/a-page/" on that cell.
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/** An answered, titled page ranking 9th — everything done but the link. */
	private function seed_page() {
		$id = self::factory()->post->create(
			array(
				'post_title'   => 'PHP Clamp Explained',
				'post_name'    => 'php-clamp-explained',
				'post_content' => '<!-- wp:paragraph --><p>PHP clamp keeps a number inside a range, so you never write min and max by hand again.</p><!-- /wp:paragraph -->',
			)
		);
		Table::replace(
			'google',
			array(
				array(
					'query'       => 'php clamp',
					'page_url'    => get_permalink( $id ),
					'page_id'     => $id,
					'clicks'      => 0,
					'impressions' => 200,
					'position'    => 9.0,
					'range_start' => '2026-07-01',
					'range_end'   => '2026-07-28',
				),
			)
		);
		return $id;
	}

	private function finding( $id ) {
		foreach ( ( new Findings( new Settings() ) )->all()['findings'] as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}
		return null;
	}

	public function test_the_row_asks_for_the_link_and_only_the_link() {
		$this->seed_page();

		$row = $this->finding( 'near_page_one' );
		$this->assertNotNull( $row, 'FIXTURE: the page must qualify as one push from page one.' );
		$this->assertSame(
			array( '1 has no link from your other posts — link to it from a related one.' ),
			$row['points']
		);
	}

	/**
	 * The chips count the PAGES' whole demand, not the three searches each card
	 * happens to list. heera.it read "6 searches · 228 shown · 3 visits" over two
	 * pages with 34 searches, 576 impressions and 5 clicks.
	 */
	public function test_the_evidence_counts_every_search_not_the_listed_three() {
		$id   = $this->seed_page();
		$rows = array();
		foreach ( array( 'php clamp', 'php 8.6 clamp', 'clamp function php', 'php clamp example', 'php 8.6 new features' ) as $i => $q ) {
			$rows[] = array(
				'query'       => $q,
				'page_url'    => get_permalink( $id ),
				'page_id'     => $id,
				'clicks'      => 1,
				'impressions' => 100 - $i,
				'position'    => 9.0,
				'range_start' => '2026-07-01',
				'range_end'   => '2026-07-28',
			);
		}
		Table::replace( 'google', $rows );

		$row = $this->finding( 'near_page_one' );
		$this->assertNotNull( $row, 'FIXTURE: the page must qualify.' );
		$this->assertSame( array( '5 searches', '490 shown', '5 visits' ), $row['evidence'] );
	}

	public function test_the_card_names_what_is_missing() {
		$id = $this->seed_page();

		$cards = \Agentimus\Search\Report::opportunities( new Settings() )['almostThere'];
		$this->assertSame( $id, $cards[0]['postId'] );
		$this->assertSame( array( 'link' ), $cards[0]['missing'] );
	}

	public function test_the_link_moves_it_to_waiting() {
		$id = $this->seed_page();
		self::factory()->post->create(
			array(
				'post_title'   => 'Another post',
				'post_content' => '<p>See <a href="' . esc_url( get_permalink( $id ) ) . '">clamp</a>.</p>',
			)
		);

		$this->assertNull( $this->finding( 'near_page_one' ), 'Nothing is left to ask for.' );
		$this->assertNotNull( $this->finding( 'near_page_one_waiting' ) );
	}
}
