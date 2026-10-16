<?php
/**
 * A tag or category page an engine reports is NAMED, not shown as a path.
 *
 * ⛔ CAUGHT LIVE on heera.it, 2026-10-16: "navigator locks" was split between
 * /tag/navigator-locks — a tag holding ONE post — and that very post. The tag
 * page won 75% of the showings, and every surface named it "/tag/navigator-locks",
 * offered no door, and the split row crowned it "Earns the click" with no
 * decision to make. The owner was told to keep a list of posts as the answer
 * and point the post at it.
 *
 * Pinned here: the one namer ({@see Pages::title()}), the archive flag on a
 * split row, the card's term door, and that the agent's schema declares the flag
 * (an undeclared key vanishes on the way to the client).
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Search\Collisions;
use Agentimus\Search\Pages;
use Agentimus\Search\Rest;
use Agentimus\Settings;
use WP_UnitTestCase;

final class SearchArchivePagesDbTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies(); // Term permastructs are built at registration — rebuild them under the new structure, as core's own tests do.
		Pages::reset();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_a_tag_page_is_named_by_its_tag() {
		self::factory()->tag->create( array( 'name' => 'navigator.locks', 'slug' => 'navigator-locks' ) );

		$this->assertSame( 'Tag: navigator.locks', Pages::title( 0, home_url( '/tag/navigator-locks' ) ) );
		Pages::reset();
		$this->assertSame( 'Tag: navigator.locks', Pages::title( 0, home_url( '/tag/navigator-locks/page/2/' ) ), 'page 2 of an archive is still that archive' );
	}

	public function test_a_child_category_is_named_through_its_full_path() {
		$parent = self::factory()->category->create( array( 'name' => 'Web', 'slug' => 'web' ) );
		self::factory()->category->create( array( 'name' => 'Browsers & Tabs', 'slug' => 'browsers', 'parent' => $parent ) );

		$this->assertSame( 'Category: Browsers & Tabs', Pages::title( 0, home_url( '/category/web/browsers/' ) ), 'decoded — the admin escapes text itself' );
	}

	public function test_a_slug_that_only_looks_like_a_term_is_not_one() {
		self::factory()->tag->create( array( 'slug' => 'gone-post' ) );

		$this->assertNull( Pages::term( home_url( '/gone-post/' ) ), 'the tag lives at /tag/gone-post, not here' );
		$this->assertSame( '/gone-post/', Pages::title( 0, home_url( '/gone-post/' ) ), 'an unknown address keeps its path' );
	}

	public function test_the_site_root_is_the_homepage_and_a_post_keeps_its_title() {
		$post = self::factory()->post->create( array( 'post_title' => 'Cross-Tab Locking' ) );

		$this->assertSame( 'Homepage', Pages::title( 0, home_url( '/' ) ) );
		$this->assertSame( 'Cross-Tab Locking', Pages::title( $post, get_permalink( $post ) ) );
	}

	public function test_a_split_row_marks_the_tag_page_as_an_archive_and_names_it() {
		self::factory()->tag->create( array( 'name' => 'navigator.locks', 'slug' => 'navigator-locks' ) );
		$post = self::factory()->post->create( array( 'post_title' => 'Cross-Tab Locking' ) );

		$wire = Collisions::wire(
			array(
				'query'  => 'navigator locks',
				'shown'  => 63,
				'clicks' => 0,
				'best'   => 8.0,
				'worst'  => 10.2,
				'pages'  => array(
					array( 'url' => home_url( '/tag/navigator-locks' ), 'page_id' => 0, 'clicks' => 0, 'impressions' => 47, 'position' => 8.0, 'share' => 0.746, 'winner' => true ),
					array( 'url' => get_permalink( $post ), 'page_id' => $post, 'clicks' => 0, 'impressions' => 16, 'position' => 10.2, 'share' => 0.254, 'winner' => false ),
				),
			)
		);

		$this->assertSame( 'Tag: navigator.locks', $wire['pages'][0]['title'] );
		$this->assertTrue( $wire['pages'][0]['archive'] );
		$this->assertFalse( $wire['pages'][1]['archive'], 'a post is never an archive' );
	}

	public function test_a_tag_card_gets_the_tags_own_door() {
		$tag  = self::factory()->tag->create( array( 'name' => 'navigator.locks', 'slug' => 'navigator-locks' ) );
		$card = $this->enrich( home_url( '/tag/navigator-locks' ) );

		$this->assertSame( 'term', $card['doorless'] );
		$this->assertSame( 'Tag: navigator.locks', $card['title'] );
		$this->assertSame( 'Tag', $card['term_label'] );
		$this->assertSame( 'navigator.locks', $card['term_name'] );
		$this->assertSame( get_edit_term_link( $tag, 'post_tag' ), $card['term_url'] );
	}

	public function test_the_performance_page_list_names_the_tag_page_too() {
		self::factory()->tag->create( array( 'name' => 'navigator.locks', 'slug' => 'navigator-locks' ) );
		$method = new \ReflectionMethod( Rest::class, 'name_page' );
		$method->setAccessible( true );
		$row = $method->invoke( new Rest( new Settings() ), array( 'page_id' => 0, 'page_url' => home_url( '/tag/navigator-locks/' ) ) );

		$this->assertSame( 'Tag: navigator.locks', $row['title'] );
		$this->assertSame( '/tag/navigator-locks/', $row['path'], 'the path still rides along beneath the name' );
	}

	public function test_a_gone_address_stays_generic() {
		$card = $this->enrich( home_url( '/no-such-page/' ) );

		$this->assertSame( 'generic', $card['doorless'] );
		$this->assertArrayNotHasKey( 'term_url', $card );
	}

	public function test_the_agent_schema_declares_the_archive_flag() {
		if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( 'agentimus/read-search-opportunities' ) ) {
			$this->markTestSkipped( 'read-search-opportunities is not registered on this core.' );
		}
		$schema = wp_get_ability( 'agentimus/read-search-opportunities' )->get_output_schema();
		$page   = $schema['properties']['collisions']['items']['properties']['pages']['items']['properties'];

		$this->assertArrayHasKey( 'archive', $page, 'an undeclared key vanishes before any agent sees it' );
	}

	/** Rest::enrich() over one unmapped card — the admin card's own dressing. */
	private function enrich( $url ) {
		$method = new \ReflectionMethod( Rest::class, 'enrich' );
		$method->setAccessible( true );
		return $method->invoke( new Rest( new Settings() ), array( 'page_id' => 0, 'page_url' => $url ) );
	}
}
