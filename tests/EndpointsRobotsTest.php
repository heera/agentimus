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
		$m->setAccessible( true );
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
}
