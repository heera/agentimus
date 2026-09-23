<?php
/**
 * The edge conflict badge names the KIND of card, not just its level.
 *
 * heera.it 2026-09-23: the spoof check stood "Cloudflare is blocking OpenAI"
 * down to "Cloudflare is blocking impostors using OpenAI's name" — and the
 * badge, reading the level alone, called that card "Not enforced" while the
 * edge was enforcing correctly against the impostors.
 *
 * @package Agentimus\Tests
 */

use PHPUnit\Framework\TestCase;

final class EdgeBadgeTest extends TestCase {

	private function source( $path ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $path );
	}

	/** Every screen that draws an edge pin picks its word through the one helper. */
	public function test_both_screens_pick_the_badge_through_the_shared_helper() {
		foreach ( array( 'resources/admin/App.vue', 'resources/admin/components/CloudflareCard.vue' ) as $path ) {
			$vue = $this->source( $path );
			$this->assertStringContainsString( '<span class="ar-edge-pin__badge">{{ edgeBadge(c) }}</span>', $vue, "$path draws the badge without edgeBadge()" );
			$this->assertStringNotContainsString( "'Not enforced'", $vue, "$path still decides the badge on its own" );
		}
	}

	/**
	 * The helper itself, run for real: warn is a conflict, a stood-down blocking
	 * warning is impostors, and only the training notice is "not enforced".
	 */
	public function test_a_stood_down_blocking_warning_is_badged_impostors() {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		$this->assertNotSame( '', $node, 'node is needed to run the badge helper' );

		$helper = dirname( __DIR__ ) . '/resources/admin/js/edgeBadge.js';
		$script = 'import(' . json_encode( 'file://' . $helper ) . ').then(m => console.log(JSON.stringify(['
			. 'm.edgeBadge({ id: "edge-blocks-openai", level: "warn" }),'
			. 'm.edgeBadge({ id: "edge-blocks-openai", level: "info" }),'
			. 'm.edgeBadge({ id: "train-not-enforced", level: "info" })'
			. '])))';
		$out = shell_exec( escapeshellarg( $node ) . ' --input-type=module -e ' . escapeshellarg( $script ) . ' 2>&1' );

		$this->assertSame( array( 'Conflict', 'Impostors', 'Not enforced' ), json_decode( trim( (string) $out ), true ), (string) $out );
	}
}
