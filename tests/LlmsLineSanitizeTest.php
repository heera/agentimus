<?php
/**
 * LlmsText::text() — the single choke point that turns a title / category name / identity field
 * into a SINGLE-LINE value for llms.txt. A newline here forges a fake "- [..](..)" list entry (or
 * breaks the About block) in a public, machine-readable document AI crawlers consume.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\LlmsText;
use PHPUnit\Framework\TestCase;

final class LlmsLineSanitizeTest extends TestCase {

	private function text( $input ): string {
		$ref = new \ReflectionClass( LlmsText::class );
		$m   = $ref->getMethod( 'text' );
		$m->setAccessible( true );
		return (string) $m->invoke( $ref->newInstanceWithoutConstructor(), $input );
	}

	public function test_a_newline_in_a_title_cannot_forge_a_list_entry() {
		$out = $this->text( "Real Title\n- [Fake Page](http://evil.test): injected\nmore" );
		$this->assertStringNotContainsString( "\n", $out, 'text() must collapse newlines so a title cannot break the line structure.' );
	}

	public function test_entity_encoded_and_carriage_return_newlines_are_also_collapsed() {
		// Decoding runs before the collapse, so an entity-encoded newline is caught too.
		$this->assertStringNotContainsString( "\n", $this->text( "A&#10;B\r\nC\tD" ) );
	}

	public function test_multibyte_content_is_preserved() {
		// `\s` matches only ASCII whitespace bytes, never a UTF-8 continuation byte, so the collapse
		// is multibyte-safe without the /u flag.
		$out = $this->text( "Café ☕ 日本語\ttab" );
		$this->assertStringContainsString( 'Café', $out );
		$this->assertStringContainsString( '日本語', $out );
		$this->assertSame( 'Café ☕ 日本語 tab', $out );
	}

	public function test_tags_are_still_stripped() {
		$this->assertSame( 'bold text', $this->text( '<b>bold</b> text' ) );
	}
}
