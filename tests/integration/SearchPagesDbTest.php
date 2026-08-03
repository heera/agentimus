<?php
/**
 * Pages::resolve() against a real WordPress — locks the site-root behavior the
 * homepage card depends on, which is CORE'S OWN: url_to_postid() maps the bare
 * home URL to page_on_front when a static front page is set (present since
 * before our 6.0 floor), and correctly answers 0 when the front shows latest
 * posts — that second case is the one URL-keyed set-aside exists for.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Search\Pages;
use WP_UnitTestCase;

final class SearchPagesDbTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Pages::reset(); // The per-request memo would let one test's answer leak into the next.
	}

	public function test_the_site_root_resolves_to_the_static_front_page() {
		$front = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Front' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );

		$this->assertSame( $front, Pages::resolve( home_url( '/' ) ) );
		Pages::reset();
		$this->assertSame( $front, Pages::resolve( untrailingslashit( home_url( '/' ) ) ), 'engines report both trailing-slash spellings' );
	}

	public function test_the_site_root_stays_unmapped_when_the_front_shows_latest_posts() {
		update_option( 'show_on_front', 'posts' );

		$this->assertSame( 0, Pages::resolve( home_url( '/' ) ), 'no post exists to name — the card keys by URL instead' );
	}
}
