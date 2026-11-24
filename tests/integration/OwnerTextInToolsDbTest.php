<?php
/**
 * ⛔ The owner's own text in the write tools' descriptions — content guidelines and
 * saved skills — reaches a signed-in agent only.
 *
 * Found 2026-11-22: with "Show the tool list before sign-in" on (1.57.0-dev3), the
 * write tools' descriptions also answer a stranger's tools/list, and they carried the
 * site's content guidelines, which WordPress keeps behind sign-in. Skills are the
 * owner's private rules and get the same rule.
 *
 * His ask the same day: the write tools name the skills that are on, so an agent sees
 * them as it writes, and a site with no skills adds nothing. The server's note to a
 * connecting agent says to check for skills, without naming a tool or this site's
 * skills, since it is public.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\Registrar;
use Agentimus\Abilities\Skills;
use Agentimus\Guidelines;

final class OwnerTextInToolsDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! post_type_exists( Skills::POST_TYPE ) ) {
			Skills::register_post_type();
		}
		$this->guidelines( 'Write warmly. Never use exclamation marks.' );
	}

	public function tear_down(): void {
		$this->guidelines( null );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Seed the guidelines digest the way a site with Content Guidelines has one. */
	private function guidelines( $text ) {
		$p = new \ReflectionProperty( Guidelines::class, 'digest' );
		$p->setAccessible( true );
		$p->setValue( null, $text );
	}

	private function described() {
		$m = new \ReflectionMethod( Registrar::class, 'guided' );
		$m->setAccessible( true );
		return (string) $m->invoke( null, 'Creates a post.' );
	}

	public function test_a_stranger_sees_neither_guidelines_nor_skills() {
		Skills::save_from_owner( 0, 'Writing a blog post', 'Before writing', 'Title Case.', true );
		wp_set_current_user( 0 );
		$out = $this->described();
		$this->assertStringContainsString( 'Creates a post.', $out );
		$this->assertStringNotContainsString( 'Write warmly', $out );
		$this->assertStringNotContainsString( 'Writing a blog post', $out );
	}

	public function test_a_signed_in_agent_sees_both() {
		Skills::save_from_owner( 0, 'Writing a blog post', 'Before writing or editing any post', 'Title Case.', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out = $this->described();
		$this->assertStringContainsString( 'Write warmly', $out );
		$this->assertStringContainsString( '“Writing a blog post” (Before writing or editing any post)', $out );
		$this->assertStringContainsString( 'get-skill', $out );
		$this->assertStringNotContainsString( 'Title Case.', $out, 'Names and when to use them; the steps stay in get-skill.' );
	}

	public function test_a_site_with_no_skills_adds_nothing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( '', Skills::hint() );
		$this->assertStringNotContainsString( 'saved skills', $this->described() );

		Skills::save_from_agent( 'Waiting', '', 'Off until the owner says.' );
		$this->assertSame( '', Skills::hint(), 'A skill that is off is not named.' );
	}

	public function test_ten_skills_are_named_and_the_rest_counted() {
		for ( $i = 1; $i <= 12; $i++ ) {
			Skills::save_from_owner( 0, 'Skill ' . $i, '', 'Steps.', true );
		}
		$hint = Skills::hint();
		$this->assertSame( 10, substr_count( $hint, '• “' ) );
		$this->assertStringContainsString( '…and 2 more in list-skills.', $hint );
	}
}
