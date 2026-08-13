<?php
/**
 * Title Case — the standing rule's exact behaviour, pinned:
 *
 *   1. Sentence case comes UP; small words stay DOWN — except first, last,
 *      and after a colon or dash, where every style guide raises them.
 *   2. A word's own capitals outrank us: PHP, WordPress, iPhone keep their
 *      spelling; so do files, domains and handles (llms.txt, heera.it, @x).
 *   3. An over-capitalized small word ("Of") mid-title comes back down —
 *      but only in its plain Ucfirst form, never an all-caps acronym.
 *   4. The assistant's parse paths run every generated title through it.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Assistant;
use Agentimus\Titles;
use PHPUnit\Framework\TestCase;

final class TitlesTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	public function test_sentence_case_comes_up_and_small_words_stay_down() {
		$this->assertSame(
			'Why the Plugin Is a Door for AI Agents',
			Titles::case( 'why the plugin is a door for AI agents' )
		);
	}

	public function test_first_last_and_post_colon_words_are_always_raised() {
		$this->assertSame( 'The End Is Near: The Sequel', Titles::case( 'the end is near: the sequel' ) );
		$this->assertSame( 'What It Comes To', Titles::case( 'what it comes to' ), 'The last word rises, small or not.' );
		$this->assertSame( 'Dark Mode — The Palette', Titles::case( 'dark mode — the palette' ) );
	}

	public function test_a_words_own_capitals_outrank_us() {
		$this->assertSame(
			'PHP 8.6 and the WordPress iPhone App',
			Titles::case( 'PHP 8.6 and the WordPress iPhone app' )
		);
	}

	public function test_files_domains_and_handles_keep_their_spelling() {
		$this->assertSame( 'Who Reads llms.txt on heera.it', Titles::case( 'who reads llms.txt on heera.it' ) );
	}

	public function test_an_overcapitalized_small_word_comes_back_down() {
		$this->assertSame( 'The Best of Both Worlds', Titles::case( 'The Best Of Both Worlds' ) );
	}

	public function test_hyphenated_words_raise_both_halves() {
		$this->assertSame( 'The Well-Being Audit', Titles::case( 'the well-being audit' ) );
	}

	public function test_empty_stays_empty() {
		$this->assertSame( '', Titles::case( '  ' ) );
	}

	/* -- The assistant's paths wear the rule -------------------------------------- */

	public function test_a_parsed_draft_title_is_title_cased() {
		$draft = Assistant::parse_draft(
			wp_json_encode(
				array(
					'title'   => 'choosing a backup plugin for busy sites',
					'content' => '<h2>One</h2><p>Body.</p>',
				)
			)
		);

		$this->assertSame( 'Choosing a Backup Plugin for Busy Sites', $draft['title'] );
	}

	public function test_a_parsed_outline_title_is_title_cased() {
		$outline = Assistant::parse_outline(
			wp_json_encode(
				array(
					'title'    => 'a field guide to the request log',
					'sections' => array( array( 'heading' => 'What it holds' ) ),
				)
			)
		);

		$this->assertSame( 'A Field Guide to the Request Log', $outline['title'] );
	}
}
