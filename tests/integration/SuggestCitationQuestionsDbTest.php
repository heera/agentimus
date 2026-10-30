<?php
/**
 * suggest-citation-questions hands over the templates the owner's screen offers. It
 * saves nothing and calls nobody.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Visibility\Settings as VisibilitySettings;

final class SuggestCitationQuestionsDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+); the feature self-gates below that.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function suggest( array $input ) {
		$ability = wp_get_ability( 'agentimus/suggest-citation-questions' );
		$this->assertNotNull( $ability, 'agentimus/suggest-citation-questions did not register.' );
		return $ability->execute( $input );
	}

	public function test_it_builds_questions_from_the_product() {
		$out = $this->suggest( array( 'name' => 'Agentimus', 'category' => 'WordPress SEO plugin', 'competitors' => array( 'Yoast' ) ) );

		$this->assertNotWPError( $out );
		$this->assertContains( 'What is the best WordPress SEO plugin?', $out['questions'] );
		$this->assertContains( 'Agentimus vs Yoast', $out['questions'] );
	}

	public function test_a_question_already_tracked_is_left_out() {
		$out = $this->suggest(
			array(
				'name'     => 'Agentimus',
				'category' => 'WordPress SEO plugin',
				'prompts'  => array( 'What is the best WordPress SEO plugin?' ),
			)
		);
		$this->assertNotContains( 'What is the best WordPress SEO plugin?', $out['questions'] );
	}

	/** ⛔ With no category it must not invent a market: a suggestion becomes a graded question. */
	public function test_without_a_category_it_asks_only_about_the_brand() {
		$out = $this->suggest( array( 'name' => 'Agentimus' ) );

		$this->assertNotEmpty( $out['questions'] );
		foreach ( $out['questions'] as $question ) {
			$this->assertStringContainsString( 'Agentimus', $question );
		}
	}

	public function test_it_saves_nothing_and_calls_nobody() {
		$calls = 0;
		$http  = static function ( $pre ) use ( &$calls ) {
			++$calls;
			return $pre;
		};
		add_filter( 'pre_http_request', $http );
		$before = get_option( VisibilitySettings::OPTION );

		$this->suggest( array( 'name' => 'Agentimus', 'category' => 'WordPress SEO plugin' ) );

		remove_filter( 'pre_http_request', $http );
		$this->assertSame( 0, $calls );
		$this->assertSame( $before, get_option( VisibilitySettings::OPTION ) );
	}

	public function test_only_someone_who_manages_the_site_can_use_it() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertWPError( $this->suggest( array( 'name' => 'Agentimus' ) ) );
	}
}
