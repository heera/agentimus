<?php
/**
 * One decode for every title this plugin says out loud.
 *
 * ⛔ WHY THIS EXISTS. Seven files each carried their own
 * `html_entity_decode( wp_strip_all_tags( get_the_title(...) ) )`, and they had
 * already drifted: one omitted the charset, one had ENT_HTML5 and the rest did
 * not. A title that decodes on one screen and not another is the bug that bit
 * live on 2026-08-22 — an agent read a row, repeated the raw title into prose,
 * and named the same post two ways.
 *
 * ⚠️ These tests pin the two halves APART on purpose: the decode has no opinion
 * about emptiness, and the fallback is a separate decision some callers make
 * differently (Findings uses the URL path, Rest a "Post %d" label).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Worklist;
use PHPUnit\Framework\TestCase;

final class TitleDecodeTest extends TestCase {

	/** The entity that made ENT_HTML5 necessary, and the one that started it all. */
	public function test_it_decodes_entities_a_reader_should_never_see() {
		$this->assertSame(
			'Php Dynamic Getter & Setter',
			Worklist::decode_title( 'Php Dynamic Getter &#038; Setter' ),
			'The numeric ampersand is the case caught live on 2026-08-22.'
		);
		$this->assertSame(
			"It\xE2\x80\x99s here",
			Worklist::decode_title( 'It&#8217;s here' )
		);
	}

	/**
	 * ⛔ THE REASON THE FLAGS ARE ENT_QUOTES | ENT_HTML5 AND NOT ENT_QUOTES.
	 * `&apos;` is HTML5-only: under ENT_QUOTES alone this string decodes to
	 * itself, and the literal `&apos;` reaches whatever repeats it.
	 */
	public function test_it_decodes_the_html5_only_apostrophe() {
		$this->assertSame( "Tom's guide", Worklist::decode_title( 'Tom&apos;s guide' ) );
		$this->assertNotSame(
			html_entity_decode( 'Tom&apos;s guide', ENT_QUOTES, 'UTF-8' ),
			Worklist::decode_title( 'Tom&apos;s guide' ),
			'If these ever match, ENT_HTML5 has been dropped.'
		);
	}

	/** Tags come out; a title is text, and an agent repeats text. */
	public function test_it_strips_tags() {
		$this->assertSame( 'Bold title', Worklist::decode_title( '<b>Bold</b> title' ) );
	}

	/**
	 * ⛔ The decode must NOT invent a fallback. Callers that have a better one
	 * than "(untitled)" — a URL path, a "Post %d" label — rely on getting the
	 * empty string back so their own branch can run.
	 */
	public function test_the_decode_leaves_emptiness_to_the_caller() {
		$this->assertSame( '', Worklist::decode_title( '' ) );
		$this->assertSame( '', Worklist::decode_title( '<span></span>' ) );
	}

	/** ⭐ Idempotent: decoding an already-decoded title must not change it. */
	public function test_decoding_twice_changes_nothing() {
		$once = Worklist::decode_title( 'Php Dynamic Getter &#038; Setter' );
		$this->assertSame( $once, Worklist::decode_title( $once ) );
	}
}
