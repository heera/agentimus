<?php
/**
 * The footer on Agentimus's own screens: a rating ask until it is answered, then a thank
 * you under the plugin's own name. Other screens keep WordPress's line.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Admin;
use Agentimus\Review;
use Agentimus\Settings;

final class AdminFooterDbTest extends DbTestCase {

	const CORE = 'Thank you for creating with WordPress.';

	public function set_up(): void {
		parent::set_up();
		delete_option( Review::OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function footer( $hook ) {
		$_GET['page'] = 'agentimus';
		set_current_screen( $hook );
		$text = ( new Admin( new Settings() ) )->admin_footer_text( self::CORE );
		unset( $_GET['page'] );
		return $text;
	}

	public function test_it_asks_on_our_screen_until_answered() {
		$text = $this->footer( 'toplevel_page_agentimus' );
		$this->assertStringContainsString( 'please give it a', $text );
		$this->assertStringContainsString( 'agentimus-rating-link', $text );
	}

	public function test_after_the_answer_it_thanks_under_the_plugins_own_name() {
		Review::ack( 'review' );
		$text = $this->footer( 'toplevel_page_agentimus' );
		$this->assertSame( 'Thank you for using <strong>Agentimus</strong>.', $text );
		$this->assertStringNotContainsString( 'rating', $text, 'Never asks again.' );
	}

	public function test_already_reviewed_ends_it_the_same_way() {
		Review::ack( 'done' );
		$this->assertStringContainsString( 'Thank you for using', $this->footer( 'toplevel_page_agentimus' ) );
	}

	public function test_later_only_snoozes_the_prompt_and_the_footer_still_asks() {
		Review::ack( 'later' );
		$this->assertStringContainsString( 'please give it a', $this->footer( 'toplevel_page_agentimus' ) );
	}

	public function test_other_screens_keep_wordpresss_line_either_way() {
		$this->assertSame( self::CORE, ( new Admin( new Settings() ) )->admin_footer_text( self::CORE ) );
		Review::ack( 'review' );
		$this->assertSame( self::CORE, ( new Admin( new Settings() ) )->admin_footer_text( self::CORE ) );
	}
}
