<?php
/**
 * What a group of jobs is CALLED on screen and in the document.
 *
 * ⭐ HIS RULE: never hide what a vendor advertises. Ability categories are
 * registered with a human label — AIOSEO publishes "AIOSEO — Posts", WooCommerce
 * "WooCommerce", FluentBoards "FluentBoards" — and this screen was printing the
 * raw slug instead, so five perfectly well-named AIOSEO groups read as five
 * lowercase slugs and looked like a bug. An earlier note in the adapter claimed
 * no honest source existed for a vendor's capitalisation. One does. It was
 * simply not looked at.
 *
 * ⛔ And the other half: a name is used only when it is THEIRS and it is
 * unambiguous. Every case where a single name would be a guess falls back to the
 * quoted slug — WordPress core's two abilities sit under "Site" and "User" at
 * once, and neither is true of the pair.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Adapters\AbilitiesApi;
use PHPUnit\Framework\TestCase;

final class AbilityGroupTitleTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_ability_categories'] );
	}

	/** One group member, in the shape resource_for() receives. */
	private function item( $name, $category ) {
		return array(
			'name'    => $name,
			'ability' => new class( $category ) {
				private $c;
				public function __construct( $c ) {
					$this->c = $c;
				}
				public function get_category() {
					return $this->c;
				}
				public function get_meta() {
					return array();
				}
			},
		);
	}

	private function title( $namespace, array $items ) {
		$m = new \ReflectionMethod( AbilitiesApi::class, 'group_title' );
		\_af_accessible( $m );
		return (string) $m->invoke( null, $namespace, $items );
	}

	/**
	 * ⭐ THE FIX. AIOSEO registers five categories and names every one of them.
	 * The heading is their name, not our transliteration of their slug.
	 */
	public function test_a_group_wears_the_name_its_vendor_registered() {
		$GLOBALS['_af_ability_categories'] = array( 'aioseo-posts' => 'AIOSEO — Posts' );

		$this->assertSame(
			'Jobs from AIOSEO — Posts',
			$this->title( 'aioseo-posts', array(
				$this->item( 'aioseo-posts/seo-data-get', 'aioseo-posts' ),
				$this->item( 'aioseo-posts/list-missing-seo', 'aioseo-posts' ),
			) )
		);
	}

	/**
	 * ⚠️ THE CASE THAT KEEPS THE SLUG. WordPress core's abilities are all named
	 * `core/…` but belong to two different categories, "Site" and "User". Picking
	 * either would be inventing a name for the other half.
	 */
	public function test_a_group_spanning_two_categories_keeps_the_quoted_slug() {
		$GLOBALS['_af_ability_categories'] = array( 'site' => 'Site', 'user' => 'User' );

		$this->assertSame(
			'Jobs from “core”',
			$this->title( 'core', array(
				$this->item( 'core/site-info', 'site' ),
				$this->item( 'core/user-info', 'user' ),
			) )
		);
	}

	public function test_a_category_registered_without_a_label_keeps_the_slug() {
		$GLOBALS['_af_ability_categories'] = array( 'shop' => '' );

		$this->assertSame( 'Jobs from “shop”', $this->title( 'shop', array( $this->item( 'shop/x', 'shop' ) ) ) );
	}

	public function test_a_category_nobody_registered_keeps_the_slug() {
		$GLOBALS['_af_ability_categories'] = array( 'something-else' => 'Something Else' );

		$this->assertSame( 'Jobs from “shop”', $this->title( 'shop', array( $this->item( 'shop/x', 'shop' ) ) ) );
	}

	public function test_an_ability_with_no_category_keeps_the_slug() {
		$GLOBALS['_af_ability_categories'] = array( 'shop' => 'The Shop' );

		$this->assertSame(
			'Jobs from “shop”',
			$this->title( 'shop', array(
				$this->item( 'shop/x', 'shop' ),
				$this->item( 'shop/y', '' ),
			) ),
			'One member with no category means the group has no agreed name.'
		);
	}

	/** ⛔ Never ucfirst() a slug: that is how "Woocommerce" and "Ai" happened. */
	public function test_no_category_registry_at_all_still_names_the_group_honestly() {
		unset( $GLOBALS['_af_ability_categories'] );

		$this->assertSame(
			'Jobs from “woocommerce”',
			$this->title( 'woocommerce', array( $this->item( 'woocommerce/orders-query', 'woocommerce' ) ) )
		);
	}
}
