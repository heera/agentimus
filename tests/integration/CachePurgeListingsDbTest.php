<?php
/**
 * What a publish asks the EDGE to clear.
 *
 * ⚠️ THE BUG THIS PINS, and it is the one Heera kept working around by hand:
 * the purge list held the post, `/`, and the agent files — so on a site whose
 * article index is a separate page, `/blog` was never in it. Cloudflare caches
 * every page for hours, so after every publish his own index went on showing
 * the old list until its TTL ran out. The machinery was healthy the whole time;
 * the URL LIST was the defect.
 *
 * ⛔ These need real WordPress, and they need a POPULATED fixture. A post with
 * no terms, a site with posts on the front page and no sitemap purges nothing
 * extra and passes green while proving nothing — the exact shape that hid five
 * bugs last time ([[green-on-empty-fixture-proves-nothing]]). Every test here
 * forces the state it is asking about to exist.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\CachePurge;
use Agentimus\Cloudflare\Settings as CfSettings;
use Agentimus\Sitemap;

final class CachePurgeListingsDbTest extends DbTestCase {

	/** @var int */
	private $post_id = 0;

	public function set_up(): void {
		parent::set_up();

		// A REAL post: two categories, two tags, on a site whose front page is
		// a static page and whose article index lives at its own address.
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'A published post' ) );
		wp_set_object_terms( $this->post_id, array( 'Alpha', 'Beta' ), 'category' );
		wp_set_object_terms( $this->post_id, array( 'one', 'two' ), 'post_tag' );

		$front = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home' ) );
		$blog  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Blog' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $blog );

		// ⚠️ The queue is a static that outlives the test. Building the fixture
		// above fires save_post four times, and without this each test measures
		// the previous test's posts as well as its own — the failure reads as
		// "the purge set is wrong" when the set is fine and the fixture leaked.
		$this->flush_and_capture();
	}

	public function tear_down(): void {
		remove_all_filters( 'agentimus_cloudflare_armed_for_test' );
		update_option( 'show_on_front', 'posts' );
		delete_option( 'page_on_front' );
		delete_option( 'page_for_posts' );
		( new CfSettings() )->disconnect();
		parent::tear_down();
	}

	/** Arm the edge purge for real: connected, switch on, no standing refusal. */
	private function arm_edge(): void {
		( new CfSettings() )->connect( 'cf-token-not-real', 'zone123', 'example.org' );
	}

	/* ------------------------------------------------------------- the list */

	/**
	 * ⭐ THE ONE HE NOTICES. His index is not the front page, so purging '/'
	 * never reached it.
	 */
	public function test_the_posts_page_is_in_the_set_when_the_front_page_is_static() {
		$this->arm_edge();
		$urls = CachePurge::site_listing_urls();

		$blog = get_permalink( (int) get_option( 'page_for_posts' ) );
		$this->assertContains( $blog, $urls, 'The article index is a separate page — purging / never touches it.' );
		$this->assertContains( home_url( '/' ), $urls, 'And the front page is still in the set.' );
	}

	/** With posts ON the front page there is no second address to purge. */
	public function test_no_duplicate_when_the_front_page_is_the_index() {
		update_option( 'show_on_front', 'posts' );
		$this->arm_edge();

		$urls = CachePurge::site_listing_urls();

		$this->assertSame(
			array( home_url( '/' ) ),
			array_values( array_filter( $urls, static function ( $u ) {
				return false === strpos( $u, 'feed' ) && false === strpos( $u, 'sitemap' );
			} ) ),
			'One address for the index, not the same page twice.'
		);
	}

	public function test_the_feed_and_the_sitemap_are_in_the_set() {
		$this->arm_edge();
		$urls = CachePurge::site_listing_urls();

		$this->assertContains( get_feed_link(), $urls, 'A feed is a list of posts, cached like any other page.' );
		foreach ( Sitemap::public_urls() as $sitemap ) {
			$this->assertContains( $sitemap, $urls, 'The sitemap is rebuilt on every publish and then left cached.' );
		}
		$this->assertNotEmpty( Sitemap::public_urls(), 'The fixture really does serve a sitemap — an empty list would prove nothing.' );
	}

	/**
	 * Every archive the post appears on, and no cap on how many.
	 *
	 * ⚠️ The fifth URL is not a stray: `get_post_type_archive_link('post')` IS
	 * the posts page, so it repeats the address {@see CachePurge::site_listing_urls()}
	 * already carries. Harmless — the queue dedupes before anything is sent —
	 * and the call still earns its place for custom post types, whose archive
	 * nothing else in the set names.
	 */
	public function test_a_posts_own_archives_are_all_in_the_set() {
		$this->arm_edge();

		$expected = array( get_post_type_archive_link( 'post' ) );
		foreach ( array( 'category' => array( 'Alpha', 'Beta' ), 'post_tag' => array( 'one', 'two' ) ) as $taxonomy => $names ) {
			foreach ( $names as $name ) {
				$expected[] = get_term_link( get_term_by( 'name', $name, $taxonomy ) );
			}
		}
		sort( $expected );

		$actual = CachePurge::post_listing_urls( $this->post_id );
		sort( $actual );

		$this->assertSame( $expected, $actual, 'Four archives plus the post archive, none dropped: the set is deliberately uncapped.' );
	}

	/** A private taxonomy has no public archive, so it has nothing to purge. */
	public function test_a_private_taxonomy_contributes_nothing() {
		$this->arm_edge();
		$before = CachePurge::post_listing_urls( $this->post_id );

		register_taxonomy( 'ar_secret', 'post', array( 'public' => false ) );
		wp_set_object_terms( $this->post_id, array( 'hidden' ), 'ar_secret' );

		$after = CachePurge::post_listing_urls( $this->post_id );

		$this->assertSame( $before, $after, 'A taxonomy with no public archive has nothing that can go stale.' );
		$this->assertNotEmpty( wp_get_object_terms( $this->post_id, 'ar_secret' ), 'The secret term really is on the post — an unset one would prove nothing.' );
		unregister_taxonomy( 'ar_secret' );
	}

	/* ------------------------------------------------- who gets told, and when */

	/**
	 * ⛔ THE LINE THESE MUST NOT CROSS. Listings are the EDGE's problem: every
	 * caching plugin already clears its own archives, and asking it twice is
	 * waste. With no edge connected, the set goes back to exactly what it was.
	 */
	public function test_nothing_extra_is_purged_when_no_edge_is_connected() {
		$before = CachePurge::site_urls();

		CachePurge::queue_site_files();
		CachePurge::queue_post( $this->post_id );
		$queued = $this->flush_and_capture();

		$this->assertSame(
			array(),
			array_diff( $queued, array_merge( $before, array( untrailingslashit( get_permalink( $this->post_id ) ) . '.md' ) ) ),
			'No edge, no listing URLs — the local caches already handle their own.'
		);
	}

	/** And with one connected, a publish carries the whole picture. */
	public function test_a_publish_with_an_edge_connected_carries_the_listings() {
		$this->arm_edge();

		CachePurge::queue_site_files();
		CachePurge::queue_post( $this->post_id );
		$queued = $this->flush_and_capture();

		$blog = get_permalink( (int) get_option( 'page_for_posts' ) );
		$this->assertContains( $blog, $queued );
		$this->assertContains( get_feed_link(), $queued );
		$this->assertContains( get_term_link( get_term_by( 'name', 'Alpha', 'category' ) ), $queued );
		$this->assertContains( home_url( '/llms.txt' ), $queued, 'And the agent files it always carried.' );
		$this->assertContains( untrailingslashit( get_permalink( $this->post_id ) ) . '.md', $queued );
	}

	/**
	 * Capture the request's queued set without touching the network: the purge
	 * filter is the last stop before any adapter is called.
	 *
	 * @return string[]
	 */
	private function flush_and_capture(): array {
		$seen = array();
		$grab = static function ( $urls ) use ( &$seen ) {
			$seen = (array) $urls;
			return array(); // Nothing goes further — no adapter, no HTTP.
		};
		add_filter( 'agentimus_purge_urls', $grab, 99 );
		CachePurge::flush_queue();
		remove_filter( 'agentimus_purge_urls', $grab, 99 );
		return $seen;
	}
}
