<?php
/**
 * Apply-fix decision logic — the CLOSED vocabulary must stay closed.
 *
 * Locks {@see \Agentimus\Abilities\Fixer::plan()}: which readiness checks are
 * automatable and exactly what each one flips; that every automatable fix only
 * ever ENABLES a documented feature (never switches a protection off); and that
 * content/judgement/server fixes honestly refuse with a manual next step.
 * plan() is the pure half of the split (apply() executes and re-checks), so it
 * is testable here without standing up the WP-heavy readiness pipeline.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Abilities\Fixer;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class FixerPlanTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	private function fixer( array $settings = array() ): Fixer {
		update_option( Settings::OPTION, $settings );
		return new Fixer( new Settings() );
	}

	/** Every simple toggle fix flips exactly its documented switch(es), ON. */
	public function test_simple_toggle_fixes_flip_only_their_own_switches() {
		$fixer = $this->fixer();
		$map   = array(
			'llms'      => array( 'enable_llms_txt' ),
			'llms_full' => array( 'enable_llms_full' ),
			'schema'    => array( 'enable_schema' ),
			'sitemap'   => array( 'enable_sitemap' ),
			'ai_usage'  => array( 'enable_ai_header', 'enable_tdmrep' ),
		);
		foreach ( $map as $check_id => $expected_keys ) {
			$plan = $fixer->plan( $check_id );
			$this->assertTrue( $plan['automatable'], "$check_id must be automatable" );
			$this->assertSame( $expected_keys, array_keys( $plan['flips'] ), "$check_id flips exactly its own switches" );
			foreach ( $plan['flips'] as $key => $value ) {
				$this->assertTrue( $value, "a fix only ever ENABLES ($check_id → $key)" );
			}
		}
	}

	public function test_public_check_fixes_the_core_reading_option() {
		$plan = $this->fixer()->plan( 'public' );
		$this->assertTrue( $plan['automatable'] );
		$this->assertSame( 'blog_public', $plan['option']['key'] );
		$this->assertSame( '1', $plan['option']['value'] );
	}

	/** topics: three branches — feature off, derive-default off, nothing left to flip. */
	public function test_topics_branches() {
		$off = $this->fixer( array( 'enable_topics' => 0 ) )->plan( 'topics' );
		$this->assertSame( array( 'enable_topics' => true ), $off['flips'] );

		$derive_off = $this->fixer( array( 'enable_topics' => 1, 'topics_derive_default' => 0 ) )->plan( 'topics' );
		$this->assertSame( array( 'topics_derive_default' => true ), $derive_off['flips'] );

		$nothing = $this->fixer( array( 'enable_topics' => 1, 'topics_derive_default' => 1 ) )->plan( 'topics' );
		$this->assertFalse( $nothing['automatable'] );
		$this->assertStringContainsString( 'write-topics', $nothing['reason'], 'points at the per-post tool' );
	}

	/** security_txt: flipping the feature on is automatable; the contact never is. */
	public function test_security_txt_branches() {
		$off = $this->fixer()->plan( 'security_txt' );
		$this->assertSame( array( 'enable_security_txt' => true ), $off['flips'] );

		$needs_contact = $this->fixer( array( 'enable_security_txt' => 1 ) )->plan( 'security_txt' );
		$this->assertFalse( $needs_contact['automatable'], 'a contact is a human decision' );
	}

	/** robots_sitemap, downstream of "Search engine visibility": fix THAT switch. */
	public function test_robots_sitemap_defers_to_blog_public_when_the_site_is_hidden() {
		update_option( 'blog_public', 0 );
		$plan = $this->fixer()->plan( 'robots_sitemap' );
		$this->assertTrue( $plan['automatable'] );
		$this->assertSame( 'blog_public', $plan['option']['key'] );
	}

	/** Content, judgement and server fixes refuse — with a reason, not silence. */
	public function test_manual_fixes_refuse_with_a_reason() {
		$fixer = $this->fixer();
		foreach ( array( 'permalinks', 'robots', 'llms_words', 'llms_full_size', 'about', 'expertise', 'same_as', 'entity_role', 'entity_image', 'post_types' ) as $check_id ) {
			$plan = $fixer->plan( $check_id );
			$this->assertFalse( $plan['automatable'], "$check_id must not be automatable" );
			$this->assertNotSame( '', trim( (string) $plan['reason'] ), "$check_id must explain the manual next step" );
		}
	}

	/** Score's non-readiness action ids get pointed at the right tool. */
	public function test_score_action_ids_are_redirected_not_executed() {
		$fixer = $this->fixer();

		$content = $fixer->plan( 'content_123' );
		$this->assertFalse( $content['automatable'] );
		$this->assertStringContainsString( 'check-page', $content['reason'] );

		foreach ( array( 'visibility_failing', 'measure_setup' ) as $id ) {
			$plan = $fixer->plan( $id );
			$this->assertFalse( $plan['automatable'], "$id spends the owner's credits — never automatable" );
		}
	}

	public function test_unknown_ids_are_flagged_unknown() {
		$plan = $this->fixer()->plan( 'no-such-check' );
		$this->assertFalse( $plan['automatable'] );
		$this->assertTrue( ! empty( $plan['unknown'] ) );
	}
}
