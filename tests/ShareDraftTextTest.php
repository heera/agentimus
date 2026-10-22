<?php
/**
 * Share drafts are plain text bound for X, Facebook and LinkedIn — no HTML
 * codes may reach them.
 *
 * ⛔ Found in the 2026-10-23 audit: WordPress hands titles out with `&#038;`
 * and curly-quote entities (wptexturize), and an excerpt can carry `&nbsp;`
 * straight from the editor. The drafts posted them literally: "Getter &#038;
 * Setter … In&nbsp;Php&nbsp;there are".
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\ShareCopy;
use PHPUnit\Framework\TestCase;

final class ShareDraftTextTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_posts'][1024] = new \WP_Post(
			array(
				'ID'           => 1024,
				'post_title'   => 'Php Dynamic Getter &#038; Setter &#8211; it can&rsquo;t fail',
				'post_excerpt' => 'In&nbsp;Php&nbsp;there are magic methods &amp; more.',
			)
		);
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	public function test_every_draft_is_plain_text() {
		$m = new \ReflectionMethod( ShareCopy::class, 'local_drafts' );
		$m->setAccessible( true );
		$drafts = $m->invoke( new ShareCopy( new Settings() ), get_post( 1024 ) );

		$this->assertNotEmpty( $drafts );
		foreach ( $drafts as $network => $text ) {
			$this->assertDoesNotMatchRegularExpression( '/&(#\d+|#x[0-9a-f]+|[a-z]+);/i', $text, "$network draft carries an HTML code" );
			$this->assertStringNotContainsString( "\u{00A0}", $text, "$network draft carries a no-break space" );
		}
		$this->assertStringContainsString( 'Php Dynamic Getter & Setter – it can’t fail', $drafts['x'] );
		$this->assertStringContainsString( 'In Php there are magic methods & more.', $drafts['linkedin'] );
	}
}
