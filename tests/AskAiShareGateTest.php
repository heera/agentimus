<?php
/**
 * The release gates of the two authoring-round features.
 *
 * Ask-AI bar: `enable_ask_ai` (ON by default — plain links, no script) must
 * actually stand the bar down when off, and the bar must render on the happy
 * path when on. Share tab: `enable_share_copy` (ON by default — editor-only)
 * AND the `agentimus_share_copy_enabled` filter must both be able to turn the
 * tab off — off means off everywhere, including the REST route (which reuses
 * is_enabled() in its permission callback).
 *
 * @package Agentimus\Tests
 */

namespace {
	// The loop guards AskAi::append() checks before rendering. Not in the shared
	// bootstrap; guarded so a future stub there wins.
	if ( ! function_exists( 'in_the_loop' ) ) {
		function in_the_loop() { return ! empty( $GLOBALS['_af_in_the_loop'] ); }
	}
	if ( ! function_exists( 'is_main_query' ) ) {
		function is_main_query() { return ! isset( $GLOBALS['_af_is_main_query'] ) || $GLOBALS['_af_is_main_query']; }
	}
	if ( ! function_exists( 'post_password_required' ) ) {
		function post_password_required( $p = null ) { return ! empty( $GLOBALS['_af_post_password_required'] ); }
	}
}

namespace Agentimus\Tests {

	use Agentimus\AskAi;
	use Agentimus\Settings;
	use Agentimus\ShareCopy;
	use PHPUnit\Framework\TestCase;

	final class AskAiShareGateTest extends TestCase {

		protected function setUp(): void {
			\_af_reset_options();
			unset( $GLOBALS['_af_in_the_loop'], $GLOBALS['_af_is_main_query'], $GLOBALS['_af_post_password_required'] );
		}

		protected function tearDown(): void {
			\_af_reset_options();
			unset( $GLOBALS['_af_in_the_loop'], $GLOBALS['_af_is_main_query'], $GLOBALS['_af_post_password_required'] );
		}

		/** Stand up the happy path: a singular post render in the main loop. */
		private function on_a_singular_post(): void {
			$GLOBALS['_af_is_singular']     = true;
			$GLOBALS['_af_in_the_loop']     = true;
			$GLOBALS['_af_is_main_query']   = true;
			$GLOBALS['_af_posts'][7]        = (object) array(
				'ID'         => 7,
				'post_title' => 'A post',
				'post_type'  => 'post',
			);
			$GLOBALS['_af_current_post_id'] = 7;
		}

		private function set( array $overrides ): Settings {
			$settings = new Settings();
			update_option( Settings::OPTION, array_merge( $settings->all(), $overrides ) );
			return new Settings();
		}

		/* -- Ask-AI ------------------------------------------------------- */

		public function test_ask_ai_defaults_on() {
			$this->assertTrue( ( new Settings() )->enabled( 'enable_ask_ai' ) );
		}

		public function test_ask_ai_renders_on_a_singular_post_when_on() {
			$this->on_a_singular_post();
			$out = ( new AskAi( new Settings() ) )->append( '<p>Body.</p>' );
			$this->assertStringContainsString( 'agentimus-ask-ai', $out );
			$this->assertStringContainsString( '<p>Body.</p>', $out );
		}

		public function test_ask_ai_stands_down_when_off() {
			$this->on_a_singular_post();
			$settings = $this->set( array( 'enable_ask_ai' => false ) );
			$this->assertSame( '<p>Body.</p>', ( new AskAi( $settings ) )->append( '<p>Body.</p>' ) );
		}

		/* -- The policy filter: don't offer a button the site itself breaks -- */

		public function test_google_button_hides_under_the_default_trainer_blocklist() {
			// Google-Extended ships blocked by default, and Google made that one
			// token govern AI-Mode READING too — so a default site must not show
			// a Google button that can only ever fail. Watched live 2026-07-27.
			$this->on_a_singular_post();
			$out = ( new AskAi( new Settings() ) )->append( '<p>Body.</p>' );
			$this->assertStringNotContainsString( 'Google AI Mode', $out );
		}

		public function test_blocking_training_crawlers_never_hides_the_reading_buttons() {
			// GPTBot and ClaudeBot are ALSO blocked by default — but reader-clicked
			// fetches come from ChatGPT-User / Claude-User, separate agents a
			// trainer block never touches. The buttons stay.
			$this->on_a_singular_post();
			$out = ( new AskAi( new Settings() ) )->append( '<p>Body.</p>' );
			$this->assertStringContainsString( 'ChatGPT', $out );
			$this->assertStringContainsString( 'Claude', $out );
			$this->assertStringContainsString( 'Perplexity', $out );
			$this->assertStringContainsString( 'Grok', $out );
		}

		public function test_a_custom_block_of_a_reading_agent_hides_its_button() {
			$this->on_a_singular_post();
			$settings = $this->set( array( 'blocked_agents' => array( 'Claude-User' ) ) );
			$out      = ( new AskAi( $settings ) )->append( '<p>Body.</p>' );
			$this->assertStringNotContainsString( 'Claude', $out );
			$this->assertStringContainsString( 'ChatGPT', $out );
		}

		public function test_the_always_allow_list_beats_a_block() {
			// Same doctrine as the Guard: an explicit allow is never blocked.
			$this->on_a_singular_post();
			$settings = $this->set( array( 'allowed_agents' => array( 'Google-Extended' ) ) );
			$out      = ( new AskAi( $settings ) )->append( '<p>Body.</p>' );
			$this->assertStringContainsString( 'Google AI Mode', $out );
		}

		/* -- Share tab ----------------------------------------------------- */

		public function test_share_copy_defaults_on() {
			$this->assertTrue( ( new ShareCopy( new Settings() ) )->is_enabled() );
		}

		public function test_share_copy_stands_down_when_the_setting_is_off() {
			$settings = $this->set( array( 'enable_share_copy' => false ) );
			$this->assertFalse( ( new ShareCopy( $settings ) )->is_enabled() );
		}

		public function test_share_copy_filter_overrides_an_on_setting() {
			$GLOBALS['_af_filters']['agentimus_share_copy_enabled'][] = static function () {
				return false;
			};
			$this->assertFalse( ( new ShareCopy( new Settings() ) )->is_enabled() );
		}
	}
}
