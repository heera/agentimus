<?php
/**
 * A page must not be marked down for failing a question nobody asked it.
 *
 * ⛔ The filter that decided this used to answer only "does this use search
 * operators?" — so a URL pasted into the search box, and somebody's prompt
 * pasted in whole, both arrived at the coverage worklist as searches a page had
 * failed. No edit clears those rows, which is a worklist that cannot be emptied.
 *
 * ⛔⛔ AND THE TWO QUESTIONS MUST STAY APART. The Search screen labels operator
 * impressions "machine traffic" and the MCP payload calls the field `isProbe`.
 * A URL is not evidence of a machine — a person may well have pasted it — so
 * widening THAT predicate would put a claim about the searcher behind evidence
 * that cannot carry it.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Focus;
use Agentimus\Search\Noise;
use PHPUnit\Framework\TestCase;

final class SearchNoiseTest extends TestCase {

	/** Real searches off a real site — every one of these has to survive. */
	public function test_it_keeps_the_searches_people_actually_type() {
		$real = array(
			'wordpress wp_send_json_success documentation',
			'cakephp 2.10 cakerequest ajax detector http_x_requested_with source',
			'illuminate database eloquent collection __tostring echo collection laravel',
			'laravel routes are matched in the order they are defined documentation',
			'engineer title before name',
			'zval',
			'date_query',
		);
		foreach ( $real as $query ) {
			$this->assertSame( '', Noise::kind( $query ), "Dropped a real search: $query" );
		}
	}

	/**
	 * ⭐ THE TRADE THIS MAKES, PINNED. A bare domain is deliberately NOT caught,
	 * because the most-searched strings in this plugin's own subject area are
	 * domain-shaped. Killing a real search to catch a fake one is the wrong way
	 * round, and this test exists so nobody "improves" the rule into doing it.
	 */
	public function test_it_never_mistakes_a_dotted_name_for_a_web_address() {
		foreach ( array( 'vue.js', 'node.js', 'next.js', 'llms.txt', 'json-ld', 'php 8.4', 'schema.org markup' ) as $query ) {
			$this->assertFalse( Noise::is_address( $query ), "$query is a search, not an address." );
			$this->assertSame( '', Noise::kind( $query ) );
		}
	}

	public function test_it_catches_an_address_pasted_into_the_search_box() {
		$this->assertSame( Noise::ADDRESS, Noise::kind( 'https://eat-token.0web.top' ) );
		$this->assertSame( Noise::ADDRESS, Noise::kind( 'http://example.com/a/b' ) );
		$this->assertSame( Noise::ADDRESS, Noise::kind( 'www.example.com/page' ) );
	}

	public function test_it_catches_a_prompt_pasted_in_whole() {
		$prompt = 'rank in-cms assistants that suggest metadata, h1s, and semantic terms while drafting. '
			. 'include controls to disable auto-publishing.';

		$this->assertSame( Noise::PASTE, Noise::kind( $prompt ) );
	}

	/**
	 * ⭐ The cutoff is not invented: it is the cap on the focus field the owner
	 * types into. If they could not enter it as what this page is for, we do not
	 * judge the page against it — one idea, one number.
	 */
	public function test_the_paste_cutoff_is_the_focus_field_the_owner_types_into() {
		$this->assertFalse( Noise::is_paste( str_repeat( 'a', Focus::MAX_LEN ) ) );
		$this->assertTrue( Noise::is_paste( str_repeat( 'a', Focus::MAX_LEN + 1 ) ) );
	}

	public function test_it_still_catches_the_operator_probes_it_always_did() {
		$this->assertSame( Noise::OPERATOR, Noise::kind( 'site:php.net/manual allowed_classes' ) );
		$this->assertSame( Noise::OPERATOR, Noise::kind( 'still the problem tour inurl:article' ) );
		$this->assertTrue( Noise::is_operator( 'intitle:agentimus' ) );
	}

	/**
	 * ⛔⛔ THE SEPARATION. `isProbe` says "a search-operator probe, not a
	 * person's search". An address is noise for judging a page and is NOT
	 * evidence about who typed it — these two answers must differ.
	 */
	public function test_an_address_is_noise_but_is_not_evidence_of_a_machine() {
		$this->assertTrue( Noise::is_noise( 'https://eat-token.0web.top' ) );
		$this->assertFalse(
			Noise::is_operator( 'https://eat-token.0web.top' ),
			'⛔ Widening the operator test would make the Search screen call a person a scraper.'
		);
	}

	/**
	 * ⭐ THE HONEST LIMIT, WRITTEN DOWN. These are real strings a real engine
	 * really reported, and nothing can tell them from a short genuine search
	 * without guessing about a site it knows nothing about. Guessing would hide
	 * real demand. They stay — and the remedy for them is the owner dismissing
	 * the SEARCH, not a cleverer filter.
	 */
	public function test_it_does_not_guess_at_junk_it_cannot_actually_detect() {
		$this->assertSame( '', Noise::kind( 'yes or no' ) );
		$this->assertSame( '', Noise::kind( 'on the website itself' ) );
	}

	/**
	 * ⭐ THE OWNER'S OWN KIND. The two strings above that no rule can detect are
	 * exactly what this is for — and it is kept LAST in kind(), so an automatic
	 * rule that also applies stays the reason shown. Restoring a search that an
	 * operator rule would drop anyway would not bring it back, and a screen
	 * offering that button would be lying about what it does.
	 */
	public function test_a_dismissed_search_is_noise_and_names_itself_as_the_owners_doing() {
		$dismissed = static function () {
			return array( 'yes or no', 'site:example.com thing' );
		};
		add_filter( 'agentimus_settings', $set = static function ( $all ) use ( $dismissed ) {
			$all['search_dismissed'] = $dismissed();
			return $all;
		} );
		Noise::flush();

		$this->assertSame( Noise::DISMISSED, Noise::kind( 'yes or no' ) );
		$this->assertTrue( Noise::is_noise( 'yes or no' ) );
		$this->assertTrue( Noise::is_dismissed( 'YES OR NO' ), 'One spelling, whatever the casing.' );
		$this->assertSame(
			Noise::OPERATOR,
			Noise::kind( 'site:example.com thing' ),
			'⛔ The binding reason wins: restoring this would not bring it back.'
		);

		remove_filter( 'agentimus_settings', $set );
		Noise::flush();
		$this->assertSame( '', Noise::kind( 'yes or no' ), 'And it goes back to being a search when restored.' );
	}

	public function test_an_empty_search_is_not_noise_it_is_nothing() {
		$this->assertFalse( Noise::is_noise( '' ) );
		$this->assertFalse( Noise::is_operator( '' ) );
		$this->assertFalse( Noise::is_address( '   ' ) );
	}
}
