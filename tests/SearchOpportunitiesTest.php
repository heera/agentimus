<?php
/**
 * The opportunity engine — pure math over a query snapshot.
 *
 * Locks the honesty rules: the "seen, not chosen" bar is THIS SITE'S OWN
 * page-one median (and the whole group refuses to run without enough rows to
 * compute one); page-less rows inform the median but never form a card; the
 * shared set-aside excludes AND counts; caps count what they hide.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Search\Opportunities;
use Agentimus\Search\Performance;
use PHPUnit\Framework\TestCase;

final class SearchOpportunitiesTest extends TestCase {

	private function row( $query, $pos, $impr, $clicks, $page_id = 1, $url = 'https://example.test/a/' ) {
		return array(
			'query'       => $query,
			'page_url'    => $url,
			'page_id'     => $page_id,
			'clicks'      => $clicks,
			'impressions' => $impr,
			'position'    => $pos,
			'range_start' => '2026-06-01',
			'range_end'   => '2026-07-27',
		);
	}

	/** Five page-one rows at 5% CTR — enough for an honest median of 0.05. */
	private function median_bed() {
		$rows = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$rows[] = $this->row( "solid query $i", 3.0, 1000, 50, 100 + $i, "https://example.test/solid-$i/" );
		}
		return $rows;
	}

	public function test_page_two_queries_group_as_almost_there() {
		$report = Opportunities::build( array(
			$this->row( 'php throwable', 9.4, 2000, 40, 7, 'https://example.test/throwable/' ),
			$this->row( 'catch throwable', 12.1, 600, 6, 7, 'https://example.test/throwable/' ),
		) );

		$this->assertCount( 1, $report['almost_there'], 'two queries, one page, one card' );
		$card = $report['almost_there'][0];
		$this->assertSame( 7, $card['page_id'] );
		$this->assertSame( 2600, $card['impressions'] );
		$this->assertSame( 'php throwable', $card['queries'][0]['query'], 'queries sort by impressions' );
		// Counted in PAGES: one page to open and fix, whatever the search count.
		$this->assertSame( 1, $report['counts']['almost'] );
		$this->assertSame( 1, $report['counts']['opportunities'] );
	}

	/**
	 * HIS SCREEN, 2026-08-19: "4 pages are one push from page one" counted a page
	 * sitting at #13.8 for a search its own sibling held at #6.1 — while the
	 * split row two rows below said to point that page AT the sibling. Following
	 * the first makes the second worse, and both were on one screen.
	 */
	public function test_a_search_a_page_is_losing_to_its_sibling_is_not_a_climb() {
		$rows = array(
			// The split: one search, two pages of this site, neither owning it.
			$this->row( 'block gptbot wordpress', 6.1, 1890, 41, 11, 'https://example.test/winner/' ),
			$this->row( 'block gptbot wordpress', 13.8, 520, 6, 12, 'https://example.test/loser/' ),
			// The loser's OWN search, split with nobody.
			$this->row( 'robots txt content signals', 11.2, 380, 6, 12, 'https://example.test/loser/' ),
		);

		$report = Opportunities::build( $rows );

		$cards = array();
		foreach ( $report['almost_there'] as $card ) {
			$cards[ (int) $card['page_id'] ] = array_column( $card['queries'], 'query' );
		}

		$this->assertArrayHasKey( 12, $cards, 'The page keeps its place — it still has a search of its own.' );
		$this->assertSame( array( 'robots txt content signals' ), $cards[12], 'The lost search leaves the climb list; the split row owns it.' );
		// ⚠️ And the page's numbers are rebuilt from what is left, or the card
		// would quote demand it has just been told to give up.
		foreach ( $report['almost_there'] as $card ) {
			if ( 12 === (int) $card['page_id'] ) {
				$this->assertSame( 380, (int) $card['impressions'] );
			}
		}
	}

	public function test_a_page_whose_only_search_is_a_lost_one_leaves_the_worklist() {
		$rows = array(
			$this->row( 'block gptbot wordpress', 6.1, 1890, 41, 11, 'https://example.test/winner/' ),
			$this->row( 'block gptbot wordpress', 13.8, 520, 6, 12, 'https://example.test/loser/' ),
		);

		$report = Opportunities::build( $rows );
		$ids    = array_column( $report['almost_there'], 'page_id' );

		$this->assertNotContains( 12, $ids, 'Nothing left to advise, so no card.' );
		// ⛔ And the count must move with the list — a header counting a page the
		// list does not show is the contradiction this whole change removes.
		$this->assertSame( count( $report['almost_there'] ), (int) $report['counts']['almost'] );
	}

	public function test_thin_and_off_band_rows_are_not_opportunities() {
		// One page each: this is about judging a search on its own merits, and
		// rows sharing a page would (correctly) be judged as that page's total.
		$report = Opportunities::build( array(
			$this->row( 'too thin', 12.0, 49, 1, 61, 'https://example.test/1/' ),           // under the impressions floor
			$this->row( 'page three', 21.0, 5000, 2, 62, 'https://example.test/2/' ),       // past the band
			$this->row( 'page one healthy', 3.0, 5000, 400, 63, 'https://example.test/3/' ), // ranks fine, clicks fine
		) );
		$this->assertSame( array(), $report['almost_there'] );
		$this->assertSame( array(), $report['seen_not_chosen'] );
	}

	/* -- page totals: the Bing shape, where demand arrives in tiny pieces ---- */

	public function test_a_page_qualifies_on_its_totals_when_no_single_search_does() {
		// THE REAL CASE (heera.it's Bing data): one page, many small searches.
		// Nothing clears the floor alone — 6 × 12 impressions — but the page
		// carries 72 at rank 9, which is exactly an "almost there".
		$rows = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$rows[] = $this->row( "long tail $i", 9.0, 12, 0, 71, 'https://example.test/ajax/' );
		}
		$report = Opportunities::build( $rows );

		$this->assertCount( 1, $report['almost_there'] );
		$card = $report['almost_there'][0];
		$this->assertTrue( $card['whole_page'], 'the PAGE qualified, not one search' );
		$this->assertSame( 72, $card['impressions'], 'the card carries the page’s whole demand' );
		$this->assertSame( 9.0, $card['position'] );
		$this->assertSame( 6, $card['searches'] );
		$this->assertTrue( $report['judged'] );
	}

	public function test_a_qualifying_search_wins_over_the_page_total() {
		// One real search plus noise: the card is about that search, and says so.
		$rows = array(
			$this->row( 'the real one', 11.0, 400, 4, 72, 'https://example.test/p/' ),
			$this->row( 'noise', 3.0, 5, 0, 72, 'https://example.test/p/' ),
		);
		$report = Opportunities::build( $rows );
		$card   = $report['almost_there'][0];

		$this->assertFalse( $card['whole_page'] );
		$this->assertCount( 1, $card['queries'], 'only the qualifying search is listed' );
		$this->assertSame( 'the real one', $card['queries'][0]['query'] );
	}

	public function test_a_page_of_tiny_searches_still_below_the_floor_stays_unjudged() {
		// Three 10-impression searches = 30 for the page: under the floor either
		// way, so the honest answer remains "not enough to judge".
		$rows = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$rows[] = $this->row( "specks $i", 9.0, 10, 0, 73, 'https://example.test/q/' );
		}
		$report = Opportunities::build( $rows );

		$this->assertSame( 0, $report['counts']['opportunities'] );
		$this->assertFalse( $report['judged'] );
	}

	public function test_the_median_falls_back_to_whole_pages_when_searches_are_too_small() {
		// Five pages, each built from small searches no query-level median could
		// use — the bar is still computable from the pages themselves, so the
		// "seen, not clicked" group can run instead of silently refusing.
		$rows = array();
		for ( $p = 1; $p <= 5; $p++ ) {
			for ( $i = 1; $i <= 10; $i++ ) {
				// 10 × 20 impressions = 200 per page, 5% click rate.
				$rows[] = $this->row( "p$p q$i", 3.0, 20, 1, 80 + $p, "https://example.test/m$p/" );
			}
		}
		// A sixth page with the same demand but almost no clicks.
		for ( $i = 1; $i <= 10; $i++ ) {
			$rows[] = $this->row( "starved $i", 4.0, 30, 0, 90, 'https://example.test/starved/' );
		}
		$report = Opportunities::build( $rows );

		$this->assertSame( 5.0, $report['median_ctr'], 'the median came from whole pages' );
		$this->assertCount( 1, $report['seen_not_chosen'] );
		$this->assertSame( 90, $report['seen_not_chosen'][0]['page_id'] );
		$this->assertTrue( $report['seen_not_chosen'][0]['whole_page'] );
	}

	public function test_seen_not_chosen_needs_the_sites_own_median() {
		// Page-one row with terrible CTR — but only 3 page-one rows total: no
		// honest median exists, so the group must refuse to run.
		$rows   = array(
			$this->row( 'q1', 4.0, 1000, 50, 11 ),
			$this->row( 'q2', 5.0, 1000, 50, 12 ),
			$this->row( 'starved', 4.2, 3000, 10, 13 ),
		);
		$report = Opportunities::build( $rows );
		$this->assertNull( $report['median_ctr'] );
		$this->assertSame( array(), $report['seen_not_chosen'] );

		// With the median bed present, the starved page-one query qualifies:
		// 10/3000 = 0.33% against a 5% median × 0.6 bar.
		$report = Opportunities::build( array_merge( $this->median_bed(), array(
			$this->row( 'starved', 4.2, 3000, 10, 13, 'https://example.test/starved/' ),
		) ) );
		$this->assertSame( 5.0, $report['median_ctr'] );
		$this->assertCount( 1, $report['seen_not_chosen'] );
		$this->assertSame( 13, $report['seen_not_chosen'][0]['page_id'] );
	}

	public function test_pageless_rows_feed_the_median_but_never_a_card() {
		// Five page-less page-one rows (Bing's site-wide stats) build the median;
		// a starved attributed row then qualifies — but no page-less card exists.
		$bed = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$bed[] = $this->row( "sitewide $i", 3.0, 1000, 50, 0, '' );
		}
		$bed[] = $this->row( 'sitewide starved', 4.0, 3000, 10, 0, '' ); // qualifies on numbers, has no page
		$bed[] = $this->row( 'attributed starved', 4.0, 3000, 10, 21, 'https://example.test/p/' );

		$report = Opportunities::build( $bed );
		$this->assertSame( 5.0, $report['median_ctr'] );
		$this->assertCount( 1, $report['seen_not_chosen'], 'only the attributed row forms a card' );
		$this->assertSame( 21, $report['seen_not_chosen'][0]['page_id'] );
	}

	public function test_set_aside_pages_are_excluded_and_counted() {
		$report = Opportunities::build( array(
			$this->row( 'kept', 10.0, 500, 5, 31, 'https://example.test/kept/' ),
			$this->row( 'aside', 10.0, 900, 5, 32, 'https://example.test/aside/' ),
		), array( 32 ) );

		$this->assertCount( 1, $report['almost_there'] );
		$this->assertSame( 31, $report['almost_there'][0]['page_id'] );
		$this->assertSame( 1, $report['counts']['set_aside'], 'the hidden page is counted visibly' );
	}

	public function test_a_page_with_no_post_can_be_set_aside_by_url() {
		$rows = array(
			$this->row( 'heera', 11.0, 300, 0, 0, 'https://example.test/' ),
		);

		$report = Opportunities::build( $rows );
		$this->assertSame( 1, $report['counts']['almost'], 'an unmapped page still earns its card' );

		// Hidden and counted — whichever trailing-slash spelling the ledger stored.
		$report = Opportunities::build( $rows, array(), array( 'https://example.test' ) );
		$this->assertSame( 0, $report['counts']['almost'] );
		$this->assertSame( 1, $report['counts']['set_aside'], 'the URL-hidden page is counted visibly' );
	}

	public function test_a_url_entry_keeps_holding_back_a_page_that_gained_a_post_id() {
		// Set aside while unmapped; a later poll resolves the same URL to a post.
		// The decision was about the page, not about how we happened to key it.
		$rows   = array( $this->row( 'heera', 11.0, 300, 0, 42, 'https://example.test/' ) );
		$report = Opportunities::build( $rows, array(), array( 'https://example.test/' ) );

		$this->assertSame( 0, $report['counts']['almost'] );
		$this->assertSame( 1, $report['counts']['set_aside'] );
	}

	public function test_page_counts_expose_what_the_display_cap_hides() {
		// Eight qualifying pages, six shown: the report must still say eight, or
		// the screen would present a cap as the whole picture.
		$rows = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$rows[] = $this->row( "query $i", 11.0, 500 + $i, 5, 100 + $i, "https://example.test/p$i/" );
		}
		$report = Opportunities::build( $rows );

		$this->assertCount( 6, $report['almost_there'], 'display capped at six pages' );
		$this->assertSame( 8, $report['page_counts']['almost'], 'the count sees all of them' );
		$this->assertSame( 0, $report['page_counts']['seen'] );
	}

	public function test_query_display_caps_but_counts_everything() {
		$rows = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$rows[] = $this->row( "variant $i", 11.0, 500 + $i, 5, 41, 'https://example.test/hub/' );
		}
		$report = Opportunities::build( $rows );
		$card   = $report['almost_there'][0];
		$this->assertCount( 3, $card['queries'], 'display capped' );
		$this->assertSame( 2, $card['more'], 'the fold names what it hides' );
		$this->assertSame( 1, $report['counts']['almost'], 'five searches, one page, one job' );
	}

	/* -- search-operator noise: machines, not people ------------------------ */

	public function test_operator_queries_never_form_or_inflate_an_opportunity() {
		// heera.it's real Bing data is full of these: scrapers running `site:`
		// probes. They collect impressions no human will ever click, and
		// "improve the title" is no answer to a machine.
		$rows = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$rows[] = $this->row( "request /php= site:it", 10.0, 20, 0, 55, 'https://example.test/spam/' );
		}
		$report = Opportunities::build( $rows );
		$this->assertSame( array(), $report['almost_there'], 'a page built purely of operator probes is not an opportunity' );
		$this->assertFalse( $report['judged'], 'nor does it count as measurable traffic' );

		$this->assertTrue( Opportunities::is_operator_query( 'site:example.com' ) );
		$this->assertTrue( Opportunities::is_operator_query( 'wordpress intext:"foo"' ) );
		$this->assertTrue( Opportunities::is_operator_query( 'INURL:admin' ) );
		$this->assertFalse( Opportunities::is_operator_query( 'php throwable vs exception' ) );
		$this->assertFalse( Opportunities::is_operator_query( 'website design' ), 'a word merely containing "site" is fine' );

		// ⛔⛔ A NEGATED OPERATOR IS STILL AN OPERATOR. The test used to be
		// "starts the string, or follows a space", and in `-site:reddit.com` the
		// character before the operator is a hyphen — so the commonest machine
		// probe of all, the long exclusion list an SEO tool appends to every
		// query, was counted as a person's search. Found on heera.it,
		// 2026-08-25: a post was being judged against one of these while the
		// site's probe share read 0% across 17,919 impressions.
		$this->assertTrue(
			Opportunities::is_operator_query( '"ai answer engines ai user agents" -site:reddit.com -site:twitter.com -site:x.com' ),
			'the exclusion list a scraper appends is a probe, not a question'
		);
		$this->assertTrue( Opportunities::is_operator_query( 'php tips -site:stackoverflow.com' ) );
		$this->assertTrue( Opportunities::is_operator_query( '+intitle:laravel routing' ), 'required, as well as excluded' );
		$this->assertTrue( Opportunities::is_operator_query( '"inurl:wp-admin"' ), 'and quoted' );

		// ⛔ THE OTHER DIRECTION, which is the one that would be silent: a filter
		// that ate real questions would delete demand from the worklist and
		// nothing on screen would say so.
		$this->assertFalse( Opportunities::is_operator_query( 'how to cache a query in laravel' ) );
		$this->assertFalse( Opportunities::is_operator_query( 'wordpress cache clearing' ) );
		$this->assertFalse( Opportunities::is_operator_query( 'what is a website: a primer' ) );
	}

	public function test_thin_data_is_reported_as_unjudgeable_not_as_all_clear() {
		// THE REAL CASE (heera.it's Bing data): every query is single or double
		// digit impressions. Nothing qualifies — but "no opportunities" must not
		// be reported as "your pages are earning their clicks", which is praise
		// the numbers cannot support.
		$rows = array(
			$this->row( 'tiny one', 4.0, 20, 0, 5, 'https://example.test/a/' ),
			$this->row( 'tiny two', 8.5, 10, 0, 6, 'https://example.test/b/' ),
			$this->row( 'tiny three', 12.0, 9, 0, 7, 'https://example.test/c/' ),
		);
		$report = Opportunities::build( $rows );

		$this->assertSame( 0, $report['counts']['opportunities'] );
		$this->assertFalse( $report['judged'], 'no row cleared the impressions floor, so nothing was judged' );
	}

	public function test_real_traffic_counts_as_judged_even_with_no_opportunities() {
		// Healthy page-one rows with real volume: nothing to fix, and the screen
		// may honestly say so.
		$report = Opportunities::build( $this->median_bed() );
		$this->assertSame( 0, $report['counts']['opportunities'] );
		$this->assertTrue( $report['judged'] );
	}

	public function test_an_all_zero_median_is_unknowable_not_a_bar_of_zero() {
		// Five page-one results, none ever clicked: a median of 0 would pass every
		// page as "at or above average" and congratulate a site nobody clicks.
		$rows = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$rows[] = $this->row( "unclicked $i", 3.0, 1000, 0, 40 + $i, "https://example.test/z$i/" );
		}
		$rows[] = $this->row( 'also unclicked', 4.0, 3000, 0, 49, 'https://example.test/z9/' );

		$report = Opportunities::build( $rows );
		$this->assertNull( $report['median_ctr'], 'no bar exists to compare against' );
		$this->assertSame( array(), $report['seen_not_chosen'], 'so the group refuses to run' );
		$this->assertTrue( $report['judged'], 'the traffic itself was real — only click-worthiness is unknowable' );
		$this->assertSame( 'unclicked', $report['median_reason'], 'the results exist and are not clicked — that is the fact to state' );
	}

	public function test_a_missing_bar_names_which_absence_it_is() {
		// Enough traffic to be judged, but only ONE page-one result carries enough
		// views to measure — nowhere near the five the median needs. The honest
		// sentence is "not enough reported yet", NOT "nobody clicks you": saying
		// the second would tell an owner something untrue about their own pages.
		$rows = array(
			$this->row( 'one good result', 3.0, 900, 90, 61, 'https://example.test/t1/' ),
			$this->row( 'tiny a', 4.0, 10, 0, 62, 'https://example.test/t2/' ),
			$this->row( 'tiny b', 5.0, 12, 0, 63, 'https://example.test/t3/' ),
		);

		$report = Opportunities::build( $rows );
		$this->assertNull( $report['median_ctr'] );
		$this->assertSame( 'thin', $report['median_reason'] );
		$this->assertTrue( $report['judged'], 'one 900-impression result is real traffic' );

		// And when a bar DOES exist, no absence is claimed at all.
		$clicked = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$clicked[] = $this->row( "clicked $i", 3.0, 1000, 50, 70 + $i, "https://example.test/c$i/" );
		}
		$this->assertSame( '', Opportunities::build( $clicked )['median_reason'] );
	}

	public function test_operator_noise_is_measured_not_just_discarded() {
		// heera.it's real Bing shape: a page ranking on page two with enough views
		// to act on — except every one of those views is a scraper running `site:`
		// probes. Dropping them silently leaves an empty worklist beside a Search
		// Performance screen showing 69 impressions at rank 9.1, and the owner
		// can only read that as a bug. The share is the explanation.
		$rows = array(
			$this->row( 'intext:wp_insert_user..php site:.it', 9.1, 40, 0, 80, 'https://example.test/ajax/' ),
			$this->row( 'list.php?partidx= buy site:it', 9.1, 25, 0, 80, 'https://example.test/ajax/' ),
			$this->row( 'detect ajax request', 9.1, 4, 0, 80, 'https://example.test/ajax/' ),
		);

		$report = Opportunities::build( $rows );
		$this->assertSame( array(), $report['almost_there'], 'a scraper is not an audience' );
		$this->assertSame( 2, $report['noise']['searches'] );
		$this->assertSame( 94, $report['noise']['share'], '65 of 69 views were probes' );
		$this->assertFalse( $report['judged'], 'what survived is 4 impressions' );
	}

	public function test_the_noise_share_ignores_the_site_wide_duplicate_of_the_same_traffic() {
		// An engine reports one window twice: a site-wide query list AND per-page
		// breakdowns. Averaging the share over both divides by a double-counted
		// total, so the percentage describes nothing real. Here the site-wide half
		// is all probes and the page half is all human: a blended share would say
		// 50%, when the honest answer about this worklist's own rows is 0%.
		$rows = array(
			$this->row( 'intext:whatever site:.it', 4.0, 100, 0, 0, '' ),
			$this->row( 'real search', 4.0, 100, 5, 90, 'https://example.test/real/' ),
		);

		$report = Opportunities::build( $rows );
		$this->assertSame( 0, $report['noise']['share'], 'the page rows carried no probes' );
		$this->assertSame( 0, $report['noise']['searches'] );
	}

	public function test_discarded_searches_are_shown_verbatim_biggest_first() {
		// The filter has to be auditable: if it ever swallows a real search, the
		// owner can only notice by reading what it took.
		$rows = array(
			$this->row( 'small intext:a site:.it', 4.0, 5, 0, 91, 'https://example.test/x/' ),
			$this->row( 'big intext:b site:.it', 4.0, 500, 0, 91, 'https://example.test/x/' ),
			$this->row( 'a real search', 4.0, 200, 4, 91, 'https://example.test/x/' ),
		);

		$examples = Opportunities::build( $rows )['noise']['examples'];
		$this->assertCount( 2, $examples, 'only the discarded ones, and the human search is not among them' );
		$this->assertSame( 'big intext:b site:.it', $examples[0]['query'], 'biggest first' );
		$this->assertSame( 500, $examples[0]['impressions'] );
	}

	public function test_one_search_landing_on_many_pages_counts_once() {
		// An engine reports a query once per page it matched. Counting rows would
		// claim three searches were dropped when there was one, and would print
		// the same string three times in the sample.
		$probe = 'intext:wp_insert_user site:.it';
		$rows  = array(
			$this->row( $probe, 4.0, 10, 0, 101, 'https://example.test/a/' ),
			$this->row( $probe, 4.0, 10, 0, 102, 'https://example.test/b/' ),
			$this->row( $probe, 4.0, 10, 0, 103, 'https://example.test/c/' ),
			$this->row( 'inurl:other site:.it', 4.0, 5, 0, 104, 'https://example.test/d/' ),
		);

		$noise = Opportunities::build( $rows )['noise'];
		$this->assertSame( 2, $noise['searches'], 'two distinct searches, not four rows' );
		$this->assertCount( 2, $noise['examples'], 'and the sample shows each once' );
		$this->assertSame( $probe, $noise['examples'][0]['query'] );
		$this->assertSame( 30, $noise['examples'][0]['impressions'], 'its views across all three pages' );
	}

	public function test_a_missing_bar_says_how_many_it_had_and_how_many_it_needs() {
		$rows = array(
			$this->row( 'one good result', 3.0, 900, 90, 61, 'https://example.test/t1/' ),
			$this->row( 'another', 4.0, 800, 40, 62, 'https://example.test/t2/' ),
			$this->row( 'tiny', 5.0, 10, 0, 63, 'https://example.test/t3/' ),
		);

		$report = Opportunities::build( $rows );
		$this->assertSame( 'thin', $report['median_reason'] );
		// Two pages cleared the bar, not "too few" — a number the owner can check.
		$this->assertSame( 2, $report['median_rows'] );
		$this->assertSame( 5, $report['median_needs'] );
	}

	public function test_a_clean_site_reports_no_noise() {
		$report = Opportunities::build( $this->median_bed() );
		$this->assertSame( 0, $report['noise']['share'] );
		$this->assertSame( 0, $report['noise']['searches'] );
	}

	public function test_a_page_in_both_groups_is_still_one_page_to_look_at() {
		// A page can rank 8–20 on one search AND under-earn on another, so it
		// legitimately appears in both groups. The header counts PAGES — summing
		// the two group counts would send the owner looking for two.
		$rows = $this->median_bed(); // Five page-one results at a real CTR.
		$rows[] = $this->row( 'page two search', 12.0, 300, 0, 42, 'https://example.test/both/' );
		$rows[] = $this->row( 'page one search', 3.0, 500, 5, 42, 'https://example.test/both/' );

		$report = Opportunities::build( $rows );
		$this->assertCount( 1, $report['almost_there'], 'listed as almost there' );
		$this->assertCount( 1, $report['seen_not_chosen'], 'and as seen but not clicked' );
		$this->assertSame( 1, $report['counts']['opportunities'], 'but it is ONE page to open' );
	}

	public function test_the_stated_click_bar_is_the_bar_actually_applied() {
		// The median is what the bar is DERIVED from, not the bar: a page must
		// fall clearly under it. Printing the median beside "not clicked enough
		// means below…" would state a threshold the engine never used.
		$rows = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$rows[] = $this->row( "bed $i", 3.0, 1000, 100, 200 + $i, "https://example.test/bed$i/" );
		}

		$report = Opportunities::build( $rows );
		$this->assertSame( 10.0, $report['median_ctr'], 'the site\'s own page-one median' );
		$this->assertSame( 6.0, $report['ctr_bar'], 'and the bar is 60% of it' );

		// A page at 7% is below the median but above the bar — and must NOT be listed,
		// which is exactly why printing the median as the threshold would be a lie.
		$rows[] = $this->row( 'below median, above bar', 3.0, 1000, 70, 77, 'https://example.test/seven/' );
		$this->assertSame( array(), Opportunities::build( $rows )['seen_not_chosen'] );
	}

	public function test_median_is_a_true_median() {
		// CTRs 1..7 % — median 4%.
		$rows = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$rows[] = $this->row( "m$i", 3.0, 1000, $i * 10, 50 + $i );
		}
		$this->assertSame( 0.04, Opportunities::page_one_median_ctr( $rows ) );
	}

	public function test_setting_every_page_aside_is_not_reported_as_thin_data() {
		// "Not enough search traffic to judge yet" is a claim about the DATA. An
		// owner who set every page aside still has the traffic they had — telling
		// them otherwise blames the engine for their own choice.
		$rows = array(
			$this->row( 'a real search', 4.0, 800, 40, 55, 'https://example.test/one/' ),
			$this->row( 'another one', 5.0, 700, 30, 56, 'https://example.test/two/' ),
		);

		$report = Opportunities::build( $rows, array( 55, 56 ) );
		$this->assertTrue( $report['judged'], 'the traffic did not vanish when the pages were set aside' );
		$this->assertSame( 2, $report['counts']['set_aside'] );
	}
	public function test_a_card_reports_the_same_page_total_search_performance_does() {
		// The card lists only the searches that QUALIFIED, so its rows sum to far
		// less than the page earned. The card therefore states the page's whole
		// demand — and that figure must equal what Search Performance shows for the
		// same page, or the two screens contradict each other on one number.
		$url  = 'https://example.test/perfume/';
		$rows = array(
			$this->row( 'big page-one search', 6.2, 3170, 11, 42, $url ), // never an "almost"
			$this->row( 'page two a', 10.0, 291, 5, 42, $url ),
			$this->row( 'page two b', 9.4, 282, 3, 42, $url ),
			$this->row( 'page two c', 9.1, 242, 0, 42, $url ),
			$this->row( 'page two d', 9.9, 120, 0, 42, $url ),
			$this->row( 'too small', 4.0, 10, 0, 42, $url ),
		);

		$card = Opportunities::build( $rows )['almost_there'][0];
		$page = Performance::build( $rows )['top_pages'][0];

		$this->assertSame( (int) $page['impressions'], (int) $card['impressions'], 'the two screens must agree on this page' );
		$this->assertSame( (int) $page['clicks'], (int) $card['clicks'] );
		$this->assertSame( (float) $page['position'], (float) $card['position'] );

		// And the card's own rows really are a small subset — the reason the line exists.
		$listed = 0;
		foreach ( $card['queries'] as $q ) {
			$listed += (int) $q['impressions'];
		}
		$this->assertLessThan( $card['impressions'], $listed, 'the listed searches are only part of the page' );

		// "the N biggest of M searches where this page sits on page two": M is the
		// qualifying count (4 here), NOT the page's 6 distinct searches.
		$this->assertSame( 6, $card['searches'], 'every surviving search on the page' );
		$this->assertSame( 4, count( $card['queries'] ) + $card['more'], 'only the page-two ones' );
	}

	public function test_a_probe_makes_the_card_total_differ_from_performance_by_exactly_its_views() {
		// Search Performance keeps probes; the worklist drops them. So a page's two
		// totals differ by exactly the probe views on that page — and the screen has
		// to offer some way to reconcile that, even when the share is far too small
		// to trip the "X% weren't people" note (here it rounds to 0%).
		$url  = 'https://example.test/unserialize/';
		$rows = array(
			$this->row( 'php unserialize', 9.1, 481, 2, 44, $url ),
			$this->row( 'unserialize risk', 9.2, 1218, 0, 44, $url ),
			$this->row( 'unserialized', 9.4, 294, 0, 44, $url ),
			$this->row( 'unserialize', 9.9, 146, 0, 44, $url ),
			$this->row( 'unserialize site:php.net', 9.0, 5, 0, 44, $url ), // the probe
		);

		$report = Opportunities::build( $rows );
		$card   = $report['almost_there'][0];
		$page   = Performance::build( $rows )['top_pages'][0];

		$this->assertSame( 5, (int) $page['impressions'] - (int) $card['impressions'], 'exactly the probe’s views' );
		$this->assertSame( 0, $report['noise']['share'], 'too small to trip the note threshold' );
		// …so the audit list must still have something to show, or the gap is silent.
		$this->assertSame( 1, $report['noise']['searches'] );
		$this->assertNotEmpty( $report['noise']['examples'], 'the disclosure has to render below the note threshold too' );
	}

}

