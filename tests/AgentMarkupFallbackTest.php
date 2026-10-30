<?php
/**
 * Below WP 6.5 core has no HTML parser to read a write with, and this suite runs
 * without one too. Then anything kses would change is refused, and what the page
 * already holds buys nothing, since nothing can tell it from what was added.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Abilities\AgentMarkup;
use PHPUnit\Framework\TestCase;

final class AgentMarkupFallbackTest extends TestCase {

	public function test_markup_kses_would_change_is_refused() {
		$this->assertFalse( method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) );

		$refusal = AgentMarkup::refusal( '<p>x</p><script>alert(1)</script>' );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'agentimus_unsafe_markup', $refusal->get_error_code() );
	}

	public function test_the_same_markup_on_the_page_already_does_not_pass_it() {
		$script = '<script>alert(1)</script>';
		$this->assertInstanceOf( \WP_Error::class, AgentMarkup::refusal( $script, $script ) );
	}

	public function test_what_kses_leaves_alone_goes_through() {
		$this->assertNull( AgentMarkup::refusal( '<p>A <strong>plain</strong> line.</p>' ) );
	}
}
