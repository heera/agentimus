<?php
/**
 * Endpoints — the robots.txt body assembler behind the 200 fallback.
 *
 * Agentimus serves /robots.txt itself when WordPress's virtual one can 404; this
 * locks the baseline it builds (the allow-all group for a public site, a full
 * Disallow for a non-public one) before the robots_txt filter layers directives on.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Endpoints;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class EndpointsRobotsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function body(): string {
		$m = new \ReflectionMethod( Endpoints::class, 'robots_body' );
		\_af_accessible( $m );
		return (string) $m->invoke( new Endpoints( new Settings() ) );
	}

	public function test_public_site_gets_the_allow_all_group() {
		$out = $this->body();
		$this->assertStringContainsString( 'User-agent: *', $out );
		$this->assertStringContainsString( 'Disallow: /wp-admin/', $out );
		$this->assertStringContainsString( 'Allow: /wp-admin/admin-ajax.php', $out );
	}

	public function test_non_public_site_disallows_everything() {
		\update_option( 'blog_public', 0 );
		$this->assertStringContainsString( "Disallow: /\n", $this->body() );
	}

	/* -- Owner's extra rules (append-only, the whole editor) ---------------- */

	private function filtered( string $base = "User-agent: *\nDisallow:\n" ): string {
		return (string) ( new Endpoints( new Settings() ) )->robots_txt( $base, true );
	}

	public function test_extra_rules_are_appended_verbatim_at_the_end() {
		$s   = new Settings();
		$all = $s->stored();
		$all['robots_extra'] = "User-agent: FooBot\nDisallow: /private/";
		$s->update( $all );

		$out = $this->filtered();
		$this->assertStringContainsString( "\n\nUser-agent: FooBot\nDisallow: /private/\n", $out );
		// APPEND-only: the generated group is still there, before the owner's block.
		$this->assertLessThan(
			strpos( $out, 'FooBot' ),
			strpos( $out, 'User-agent: *' ),
			'The generated rules lead; the owner’s rules follow — an edit can never rewrite them.'
		);
	}

	public function test_an_empty_box_appends_nothing() {
		$base = "User-agent: *\nDisallow:\n";
		$this->assertStringNotContainsString( "\n\n\n", $this->filtered( $base ) );
	}

	public function test_an_owner_sitemap_line_suppresses_the_generated_one() {
		// The extra block lands BEFORE the sitemap guard, so an owner writing
		// their own Sitemap: line wins instead of the file carrying two.
		$s   = new Settings();
		$all = $s->stored();
		$all['robots_extra'] = 'Sitemap: https://example.test/custom-map.xml';
		$s->update( $all );

		$out = $this->filtered();
		$this->assertSame( 1, substr_count( strtolower( $out ), 'sitemap:' ) );
	}

	public function test_sanitize_bounds_and_cleans_the_extra_rules() {
		$s   = new Settings();
		$all = $s->stored();
		$all['robots_extra'] = "User-agent: A\x07Bot\r\nDisallow: /x\r\n" . str_repeat( 'y', 5000 );
		$s->update( $all );

		$stored = (string) ( new Settings() )->get( 'robots_extra', '' );
		$this->assertStringContainsString( "User-agent: ABot\nDisallow: /x", $stored, 'CRLF normalised, control characters dropped, text kept.' );
		$this->assertLessThanOrEqual( 4000, strlen( $stored ), 'Bounded.' );
	}
}
