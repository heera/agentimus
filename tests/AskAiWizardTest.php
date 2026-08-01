<?php
/**
 * The wizard's proof-screen surface of AskAi: the site-level assistant links
 * (same catalog and policy gate as the front-end bar) and the public-host test
 * the screen picks its honest branch with — an assistant can never fetch
 * wpftest.test, so watching the log for that visit would wait forever.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\AskAi;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class AskAiWizardTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* ---- wizard_links ------------------------------------------------------ */

	public function test_links_carry_the_encoded_prompt_and_reader_tokens() {
		$prompt = 'Read https://example.org/ and tell me who is behind this site.';
		$links  = ( new AskAi( new Settings() ) )->wizard_links( $prompt );

		$this->assertNotEmpty( $links );
		foreach ( $links as $link ) {
			$this->assertStringContainsString( rawurlencode( $prompt ), $link['href'] );
			$this->assertIsArray( $link['agents'] );
			$this->assertNotSame( '', $link['label'] );
		}
	}

	/**
	 * Google's one-token trap: the default settings block Google-Extended for
	 * training, and that same token is what would do the READING — so the
	 * wizard gets exactly the four buttons whose readers the site permits.
	 */
	public function test_default_policy_yields_the_four_reader_buttons() {
		$labels = array_column( ( new AskAi( new Settings() ) )->wizard_links( 'q' ), 'label' );
		$this->assertSame( array( 'ChatGPT', 'Claude', 'Perplexity', 'Grok' ), $labels );
	}

	/* ---- host_is_public ---------------------------------------------------- */

	public function test_public_hosts_pass() {
		foreach ( array( 'heera.it', 'www.example-shop.co.uk', '8.8.8.8' ) as $host ) {
			$this->assertTrue( AskAi::host_is_public( $host ), $host );
		}
	}

	public function test_private_and_unreachable_hosts_fail() {
		$hosts = array(
			'wpftest.test',
			'mysite.local',
			'localhost',
			'dev.localhost',
			'intranet',      // no dot — a LAN name, not a public address
			'127.0.0.1',
			'192.168.1.10',
			'10.0.0.5',
			'app.internal',
			'router.home.arpa',
			'foo.example',
			'bad.invalid',
			'',
		);
		foreach ( $hosts as $host ) {
			$this->assertFalse( AskAi::host_is_public( $host ), '' === $host ? '(empty)' : $host );
		}
	}
}
