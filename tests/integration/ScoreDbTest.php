<?php
/**
 * The AEO/GEO Score engine against real WP: it runs the full Readiness report, samples
 * real posts and parses their HTML (PageCheck), and reads the visibility store — none
 * of which the unit suite exercises (no $wpdb, no real DOM over real content). Also
 * pins the resilience contract: adversarial post markup must never crash the report.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Cache;
use Agentimus\Grades;
use Agentimus\Score;
use Agentimus\Settings;
use Agentimus\Worklist;
use Agentimus\Visibility\Runner;
use Agentimus\Visibility\Settings as VisibilitySettings;
use Agentimus\Visibility\Store;
use Agentimus\Visibility\Table;

final class ScoreDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		// ⭐ The Optimized pillar reads SWEPT grades now — it no longer parses a
		// sample inside the request — so this suite needs the store to exist and
		// {@see regrade()} to have run before any score means anything.
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
		delete_transient( Cache::OPTIMIZE ); // Force a fresh content sample per test.
		delete_transient( Cache::LLMS_WORDS );
	}

	/**
	 * Read every published page and drop the cached score.
	 *
	 * ⚠️ Publishing a post no longer changes the score on its own. The pillar
	 * averages what the SWEEP has read, so a test that publishes and asks
	 * immediately is asking about pages nobody has looked at — and correctly
	 * gets "no data yet". In production the cron does this; here it is said out
	 * loud, which is also the honest picture of when a score actually moves.
	 */
	private function regrade() {
		( new Worklist( new Settings() ) )->sweep( 200 );
		delete_transient( Cache::OPTIMIZE );
	}

	private function post( $content ) {
		return self::factory()->post->create(
			array( 'post_status' => 'publish', 'post_content' => $content )
		);
	}

	/** Turn on citation tracking so the Cited rung is part of the report. */
	private function enable_visibility() {
		$s   = new Settings();
		$all = $s->all();
		$all['enable_visibility'] = true;
		$s->update( $all );
	}

	public function test_report_has_the_five_rung_shape_over_real_posts() {
		$this->enable_visibility(); // The full five-rung ladder needs citation tracking on.
		// A substantial, citable post (figures + a cited source) and a thin one.
		$this->post( str_repeat( 'A concrete point backed by a real figure: 42% in 2024. ', 30 ) . '<a href="https://example.org/study">source</a>' );
		$this->post( 'Too short to cite.' );
		$this->regrade();

		$r = ( new Score( new Settings() ) )->report();

		$this->assertIsInt( $r['score'] );
		$this->assertGreaterThanOrEqual( 0, $r['score'] );
		$this->assertLessThanOrEqual( 100, $r['score'] );
		$this->assertIsBool( $r['ready'] );
		$this->assertIsBool( $r['blocked'] );

		$keys = array_map(
			static function ( $g ) {
				return $g['key'];
			},
			$r['rungs']
		);
		$this->assertSame( array( 'findable', 'readable', 'trusted', 'optimized', 'cited' ), $keys );

		// With published posts present, the Optimize rung graded real content (not null).
		$optimized = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'optimized' === $g['key'] ) {
				$optimized = $g;
			}
		}
		$this->assertNotNull( $optimized['score'] );
		$this->assertGreaterThanOrEqual( 0, $optimized['score'] );
		$this->assertLessThanOrEqual( 100, $optimized['score'] );
	}

	public function test_commerce_products_are_excluded_from_citability() {
		// Register a commerce type and make it agent-visible, so it WOULD be sampled by the
		// Optimize pillar unless it's dropped as commerce. Capture the final graded set.
		register_post_type( 'product', array( 'public' => true ) );
		$agentize = static function ( $t ) {
			$t[] = 'product';
			return array_values( array_unique( $t ) );
		};
		add_filter( 'agentimus_post_types', $agentize );

		$captured = null;
		$capture  = static function ( $types ) use ( &$captured ) {
			$captured = $types;
			return $types;
		};
		add_filter( 'agentimus_citability_post_types', $capture );

		$this->regrade();
		( new Score( new Settings() ) )->report();

		remove_filter( 'agentimus_post_types', $agentize );
		remove_filter( 'agentimus_citability_post_types', $capture );
		unregister_post_type( 'product' );

		$this->assertIsArray( $captured );
		$this->assertContains( 'post', $captured, 'articles must still be graded' );
		$this->assertNotContains( 'product', $captured, 'commerce products must be excluded from citability grading' );
	}

	public function test_content_worklist_lists_issues_with_editor_links() {
		// A thin post trips the "thin content" check. The worklist links to editors, so
		// it only populates for a user who can edit — mirror the admin-boot context.
		$this->post( 'Too short.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->regrade();

		$r = ( new Score( new Settings() ) )->report();

		$this->assertNotEmpty( $r['content'], 'a thin post should surface a content worklist entry' );
		$issue = $r['content'][0];
		$this->assertArrayHasKey( 'label', $issue );
		$this->assertNotSame( '', $issue['why'], 'each issue carries a plain what-to-do' );
		$this->assertGreaterThanOrEqual( 1, $issue['count'] );
		$this->assertNotEmpty( $issue['pages'] );
		$this->assertArrayHasKey( 'title', $issue['pages'][0] );
		$this->assertStringContainsString( 'post.php', (string) $issue['pages'][0]['url'] );
	}

	/**
	 * ⭐ HIS REPORT, 2026-08-19 — the whole round trip, the way he drove it.
	 *
	 * Open a flagged page from Readiness → Optimize Your Content, fix one thing,
	 * leave the editor open, come back. The card dropped the page the instant it
	 * was saved: every complaint about it gone, nothing said, and the Optimized
	 * pillar moved because a page had left the average. A while later the sweep
	 * read it again and the same page came back, with the issues nobody had
	 * touched still on it.
	 *
	 * This walks the real hooks — wp_update_post fires save_post, which busts the
	 * cache and marks the grade out of date — so it fails if the store ever goes
	 * back to treating "edited since" as "never read".
	 */
	public function test_a_page_you_just_edited_keeps_its_place_and_says_it_is_being_re_read() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = $this->post( 'Too short.' );
		$this->regrade();

		$before = ( new Score( new Settings() ) )->report();
		$this->assertNotEmpty( $before['content'], 'a thin post is flagged to begin with' );
		$this->assertSame( $id, (int) $before['content'][0]['pages'][0]['id'] );
		$this->assertFalse( $before['content'][0]['pages'][0]['stale'] );
		$this->assertSame( 0, (int) $before['rechecking'] );
		$graded = (int) $before['graded'];
		$score  = $before['rungs'][3]['score'];

		// He fixes ONE of the things the page is flagged for and saves. Still thin,
		// so the page still belongs on this card — which is the point.
		wp_update_post( array( 'ID' => $id, 'post_content' => 'Still too short, but now it opens with a summary line.' ) );

		$after = ( new Score( new Settings() ) )->report();
		$this->assertNotEmpty( $after['content'], '⛔ the page must not leave the card on a save' );
		$this->assertSame( $id, (int) $after['content'][0]['pages'][0]['id'] );
		$this->assertTrue( $after['content'][0]['pages'][0]['stale'], 'the row has to say the verdict predates the edit' );
		$this->assertSame( 1, (int) $after['rechecking'] );
		$this->assertSame( 0, (int) $after['grading'], 'it has been read — it is being re-read, which is a different sentence' );
		$this->assertSame( $graded, (int) $after['graded'], 'an edit is not a page leaving the graded set' );
		$this->assertSame( $score, $after['rungs'][3]['score'], 'the pillar must not move because a row dropped out of the average' );

		// A reading is booked rather than waiting for the hourly beat.
		$this->assertNotFalse( wp_next_scheduled( Grades::CRON ), 'saving a post books the sweep that answers "did I fix it?"' );

		// …and once the sweep has read it, the mark clears itself.
		$this->regrade();
		$done = ( new Score( new Settings() ) )->report();
		$this->assertSame( 0, (int) $done['rechecking'] );
		$this->assertFalse( $done['content'][0]['pages'][0]['stale'] );
	}

	/**
	 * ⚠️⚠️ TWO NUMBERS SIDE BY SIDE MUST COUNT THE SAME POPULATION.
	 *
	 * Caught on heera.it minutes after 1.38.0 went up: the Optimize card read
	 * "75 graded · 88 being read again". `graded` counts only what is gradeable
	 * for quoting; the re-read count was counting every checked page. Both were
	 * true of what they measured, and together they said more pages were being
	 * re-read than existed.
	 */
	public function test_being_read_again_never_exceeds_the_pages_it_is_counted_against() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$article = $this->post( str_repeat( 'A plain sentence about a plain thing. ', 30 ) );
		// A page with nothing to grade for quoting — structural, not an article.
		$empty = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '' ) );
		$this->regrade();

		$before = ( new Score( new Settings() ) )->report();
		$this->assertGreaterThan( 0, (int) $before['graded'] );

		// Everything the site checks goes back in the queue.
		Grades::mark_stale( $article );
		Grades::mark_stale( $empty );
		delete_transient( Cache::OPTIMIZE );

		$after = ( new Score( new Settings() ) )->report();
		$this->assertLessThanOrEqual(
			(int) $after['graded'],
			(int) $after['rechecking'],
			'⛔ More pages being read again than the card says it graded is an impossible pair, whatever each number means on its own.'
		);
	}

	/**
	 * ⚠️⚠️ HIS CARD, TWICE: "75 graded · 86 being read again" — and 86 was 75 plus
	 * the 11 pages he had set aside. Excusing a page from the score excuses it
	 * from every number the score prints, not only from the average.
	 */
	public function test_a_page_set_aside_is_not_counted_as_being_read_again() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$keep  = $this->post( str_repeat( 'A plain sentence about a plain thing. ', 30 ) );
		$aside = $this->post( str_repeat( 'Another plain sentence about another plain thing. ', 30 ) );
		$this->regrade();

		$settings = new Settings();
		$all      = $settings->all(); // Partial updates reset unset settings — merge into all().
		$all['optimize_ignored'] = array( $aside );
		$settings->update( $all );

		Grades::mark_stale( $keep );
		Grades::mark_stale( $aside );
		delete_transient( Cache::OPTIMIZE );

		$r = ( new Score( new Settings() ) )->report();
		$this->assertSame( 1, (int) $r['rechecking'], 'The set-aside page is out of this count, like every other number here.' );
		$this->assertLessThanOrEqual( (int) $r['graded'], (int) $r['rechecking'] );
	}

	/**
	 * ⚠️ HIS CARD, 2026-08-19: "Featured image not described · 6 Posts" printed
	 * directly above "Showing 6 of 22". The chip named the count from the six
	 * rows the card happens to SHOW, not from the twenty-two pages the check
	 * flags — two numbers for one thing, with the smaller one first and in
	 * bigger type.
	 */
	public function test_the_issue_label_counts_every_flagged_page_not_the_sample() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// More thin posts than the card samples per issue (six).
		for ( $i = 0; $i < 9; $i++ ) {
			$this->post( 'Too short.' );
		}
		$this->regrade();

		$r     = ( new Score( new Settings() ) )->report();
		$issue = null;
		foreach ( $r['content'] as $row ) {
			if ( 'words' === $row['id'] ) {
				$issue = $row;
			}
		}
		$this->assertNotNull( $issue, 'Nine thin posts should flag the substance check.' );

		$this->assertSame( 9, (int) $issue['count'] );
		$this->assertLessThan( 9, count( $issue['pages'] ), 'The card samples fewer than it counts — which is the whole trap.' );
		$this->assertStringContainsString( '9', (string) $issue['countLabel'], 'The label names the twenty-two, never the six.' );
	}

	/**
	 * HIS CARD, 2026-08-19: "up to 65 of your 103 graded pieces have something
	 * worth fixing" — while 84 of them did. Summing the issue groups was rightly
	 * refused (a page failing three checks is in three groups), but the largest
	 * group was then printed as a ceiling, and it is the FLOOR.
	 */
	public function test_the_pages_with_something_wrong_are_counted_as_pages() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// A described featured image on every post, so the universal flags stay
		// out of the way and each post is flagged for its OWN failing.
		$image = self::factory()->attachment->create_object(
			array( 'file' => 'described.jpg', 'post_mime_type' => 'image/jpeg' ),
			0,
			array( 'post_title' => 'Described' )
		);
		update_post_meta( $image, '_wp_attachment_image_alt', 'A picture with a description of its own.' );

		$thin  = $this->post( 'Too short.' );
		$bare  = $this->post( str_repeat( 'A plain sentence about a plain thing that cites nothing at all. ', 40 ) );
		foreach ( array( $thin, $bare ) as $id ) {
			set_post_thumbnail( $id, $image );
		}
		$this->regrade();

		$r       = ( new Score( new Settings() ) )->report();
		$biggest = 0;
		foreach ( $r['content'] as $issue ) {
			$biggest = max( $biggest, (int) $issue['count'] );
		}

		$this->assertSame( 2, (int) $r['flagged'], 'Two pages have something wrong — however many checks each fails.' );
		$this->assertGreaterThan( $biggest, (int) $r['flagged'], 'The largest issue group is the floor of this number, never the whole of it.' );
		$this->assertLessThanOrEqual( (int) $r['graded'], (int) $r['flagged'], 'It can never exceed what was read.' );
	}

	/**
	 * HIS QUESTION, 2026-08-19, and it was a fair one: he fixed the featured
	 * image this card asked about, watched the page leave Readiness, and found
	 * it still listed as worth fixing next door — for a search problem he had
	 * not touched. Nothing was broken: this card lists CONTENT issues, and a
	 * page whose only problem is that its words do not answer its search has
	 * none, so it is simply absent. What was broken is that the card never said
	 * so, and used the front door's exact phrase for a smaller number.
	 */
	public function test_the_card_can_name_the_pages_it_is_not_showing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post( 'Thin', 'Too short.' ); // A content issue: this card's business.
		$this->regrade();

		$r = ( new Score( new Settings() ) )->report();

		$this->assertArrayHasKey( 'unanswered', $r, 'Without it the card cannot admit what it leaves out.' );
		$this->assertSame( 0, (int) $r['unanswered'], 'No search data here, so nothing is in that half.' );
		// ⛔ And it is never folded into the count this card DOES show — two
		// measurements over two populations added together is a third number
		// that reconciles with neither.
		$this->assertGreaterThan( 0, (int) $r['flagged'] );
	}

	public function test_empty_and_structural_pages_are_excluded_from_grading() {
		// An empty page — theme-rendered front page, a page-builder/form page, a
		// placeholder: 0 extractable words, so not an article to grade.
		$empty = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '' ) );
		// A Posts-page container that even carries some text is still structural — the
		// theme renders the loop, so it's excluded regardless of the words gate.
		$blog = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'Latest posts from the blog appear below in the loop.' ) );
		update_option( 'page_for_posts', (int) $blog );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->regrade();

		$r = ( new Score( new Settings() ) )->report();
		update_option( 'page_for_posts', 0 );

		$urls = '';
		foreach ( $r['content'] as $issue ) {
			foreach ( $issue['pages'] as $p ) {
				$urls .= ' ' . $p['url'];
			}
		}
		$this->assertStringNotContainsString( 'post=' . $empty . '&', $urls, 'empty pages must not be graded (false "thin content")' );
		$this->assertStringNotContainsString( 'post=' . $blog . '&', $urls, 'the Posts-page container must not be graded' );
	}

	public function test_commerce_structural_pages_are_excluded_from_grading() {
		// Each carries a few real words, so WITHOUT the exclusion they'd pass the
		// empty-page gate and grade as "thin content". The designation OPTIONS are
		// the signal, deliberately readable without the commerce plugin loaded.
		$cart = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Cart', 'post_content' => 'Your cart is currently empty.' ) );
		update_option( 'woocommerce_cart_page_id', (int) $cart );
		// FluentCart designates pages inside its store settings — nested, with a
		// string id, so this also exercises the recursive `*_page_id` scan.
		$account = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'My account', 'post_content' => 'Sign in to see your orders.' ) );
		update_option( 'fluent_cart_store_settings', array( 'checkout_settings' => array( 'registration_page_id' => (string) $account ) ) );
		// Control: an ordinary thin page must still be graded — proving the test
		// would catch an exclusion that fires too broadly.
		$thin = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Ordinary thin page', 'post_content' => 'A very short page indeed.' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->regrade();

		$r = ( new Score( new Settings() ) )->report();
		delete_option( 'woocommerce_cart_page_id' );
		delete_option( 'fluent_cart_store_settings' );

		$urls = '';
		foreach ( $r['content'] as $issue ) {
			foreach ( $issue['pages'] as $p ) {
				$urls .= ' ' . $p['url'];
			}
		}
		$this->assertStringNotContainsString( 'post=' . $cart . '&', $urls, 'a WooCommerce-designated page must not be graded as an article' );
		$this->assertStringNotContainsString( 'post=' . $account . '&', $urls, 'a FluentCart-designated page must not be graded as an article' );
		$this->assertStringContainsString( 'post=' . $thin . '&', $urls, 'an ordinary thin page must still be graded' );
	}

	public function test_container_pages_of_unknown_plugins_are_excluded_from_grading() {
		// No designation option anywhere — an unknown plugin's page, detected purely
		// by SHAPE: a shortcode container with no authored prose. (Unregistered
		// shortcodes render as literal text, so without the structural gate this
		// would pass the words check and grade as "thin content".)
		$shortcode = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bookings', 'post_content' => '[acme_bookings_calendar view="month"]' ) );
		// Same fact in block form: a NAMESPACED plugin block wrapping skeleton markup.
		$block = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Members', 'post_content' => '<!-- wp:acme/members-area --><div class="acme-members is-loading">Sign in below.</div><!-- /wp:acme/members-area -->' ) );
		// Control: a shortcode page WITH real authored prose is still an article —
		// the gate must key on missing prose, not on the mere presence of a widget.
		$mixed = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About our bookings', 'post_content' => '[acme_bookings_calendar] We take bookings all year round for the workshop, the studio and the annexe. Slots open thirty days ahead and close two days before each event, so plan ahead if you need a weekend.' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->regrade();

		$r = ( new Score( new Settings() ) )->report();

		$urls = '';
		foreach ( $r['content'] as $issue ) {
			foreach ( $issue['pages'] as $p ) {
				$urls .= ' ' . $p['url'];
			}
		}
		$this->assertStringNotContainsString( 'post=' . $shortcode . '&', $urls, 'a shortcode-only container page must not be graded' );
		$this->assertStringNotContainsString( 'post=' . $block . '&', $urls, 'a namespaced-block container page must not be graded' );
		$this->assertStringContainsString( 'post=' . $mixed . '&', $urls, 'a widget page with real authored prose must still be graded' );
	}

	public function test_set_aside_post_is_excluded_and_listed() {
		// A thin post that would be graded and flagged...
		$id = $this->post( 'Too short.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// ...until the owner sets it aside as "not cited content".
		$s   = new Settings();
		$all = $s->all();
		$all['optimize_ignored'] = array( $id );
		$s->update( $all );
		$this->regrade();

		$r = ( new Score( new Settings() ) )->report();

		// Gone from the worklist, present in the visible "set aside" list.
		$inWork = false;
		foreach ( $r['content'] as $issue ) {
			foreach ( $issue['pages'] as $p ) {
				if ( (int) $p['id'] === (int) $id ) {
					$inWork = true;
				}
			}
		}
		$this->assertFalse( $inWork, 'a set-aside post must leave the worklist' );

		$ignoredIds = array_map( static function ( $p ) {
			return (int) $p['id'];
		}, $r['ignored'] );
		$this->assertContains( (int) $id, $ignoredIds, 'a set-aside post must show in the visible list' );
	}

	public function test_set_aside_rows_carry_their_flags() {
		// A thin post keeps, in the aside list, the flags that put it on the worklist.
		$id = $this->post( 'Too short.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$s   = new Settings();
		$all = $s->all();
		$all['optimize_ignored'] = array( $id );
		$s->update( $all );
		$this->regrade();

		$row = null;
		foreach ( ( new Score( new Settings() ) )->report()['ignored'] as $p ) {
			if ( (int) $p['id'] === (int) $id ) {
				$row = $p;
			}
		}
		$this->assertNotNull( $row, 'the set-aside row must be listed' );
		$this->assertArrayHasKey( 'flags', $row, 'an aside row says what it was flagged for' );
		$this->assertNotEmpty( $row['flags'], 'a thin post trips at least one check' );
		foreach ( $row['flags'] as $flag ) {
			$this->assertIsString( $flag );
			$this->assertNotSame( '', $flag );
		}
	}

	public function test_issue_post_ids_reaches_past_the_worklist_cap() {
		// Eight thin posts: two more than the worklist shows per issue.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$ids = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$ids[] = $this->post( 'Too short.' );
		}

		$this->regrade();

		$score = new Score( new Settings() );
		$issue = null;
		foreach ( $score->report()['content'] as $c ) {
			if ( 'words' === (string) $c['id'] ) {
				$issue = $c;
			}
		}
		$this->assertNotNull( $issue, 'thin posts must flag the substance check' );
		$this->assertGreaterThanOrEqual( 8, (int) $issue['count'] );
		$this->assertLessThanOrEqual( 6, count( $issue['pages'] ), 'the on-screen preview stays capped' );

		// The set-all action must see every flagged page, not the capped preview.
		$uncapped = array_map( 'intval', $score->issue_post_ids( 'words' ) );
		foreach ( $ids as $id ) {
			$this->assertContains( (int) $id, $uncapped, 'the full set includes pages past the preview cap' );
		}
	}

	/**
	 * ⭐⭐ THE PILLAR COVERS THE WHOLE SITE, NOT THE LAST 25 EDITS.
	 *
	 * His catch, 2026-08-18: Readiness said "every graded post and page is ready
	 * for AI to quote" over 18 items while the content list, one screen away,
	 * held pages with content flags. The card was reading a sample of the 25 most
	 * recently modified posts and describing it in words that spoke for the site.
	 *
	 * The fixture is built to fail under that sample and pass under the store:
	 * the thin pages are published FIRST, so twenty-six later posts push them
	 * clean out of any recency window. If this ever goes green with a sampled
	 * score again, it will be because the sample got bigger — not because the
	 * measurement got honest.
	 */
	public function test_the_optimized_pillar_grades_pages_the_old_recency_sample_could_never_see() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The offenders, oldest of all.
		$old_thin = array( $this->post( 'Too short.' ), $this->post( 'Also too short.' ) );

		// Twenty-six later posts — one more than the sample ever took, so the two
		// above are unreachable by recency.
		for ( $i = 0; $i < 26; $i++ ) {
			$this->post( str_repeat( 'A concrete point backed by a real figure: 42% in 2024. ', 30 ) . '<a href="https://example.org/study">source</a>' );
		}

		$this->regrade();
		$r = ( new Score( new Settings() ) )->report();

		$this->assertSame( 28, (int) $r['graded'], 'every published page is graded, not a window of them' );
		$this->assertSame( 0, (int) $r['grading'], 'the sweep read everything, so no page is outstanding' );

		$words = null;
		foreach ( $r['content'] as $c ) {
			if ( 'words' === (string) $c['id'] ) {
				$words = $c;
			}
		}
		$this->assertNotNull( $words, 'the thin pages must reach the report at all' );

		$flagged = array_map( 'intval', $words['pages'] ? wp_list_pluck( $words['pages'], 'id' ) : array() );
		foreach ( $old_thin as $id ) {
			$this->assertContains( (int) $id, $flagged, 'a page outside the last 25 edits still counts against the score' );
		}

		// And the number itself is an average over all 28, so two thin pages in
		// twenty-eight cannot read as a perfect site.
		$optimized = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'optimized' === $g['key'] ) {
				$optimized = $g;
			}
		}
		$this->assertNotNull( $optimized );
		$this->assertLessThan( 100, (int) $optimized['score'], 'flagged pages must cost the pillar something' );
		$this->assertStringNotContainsString( 'recently edited', (string) $optimized['note'], 'the note must not describe a sample the score no longer takes' );
	}

	/**
	 * A page nobody has read yet is not a page that scored zero.
	 *
	 * ⚠️ This is the failure mode the migration guards against: dbDelta fills the
	 * new columns with defaults, and averaging those in would hand a site a
	 * cellar-full of zeroes for pages the sweep had simply not reached.
	 */
	public function test_an_unswept_site_reports_no_grade_rather_than_a_bad_one() {
		$this->post( str_repeat( 'A concrete point backed by a real figure: 42% in 2024. ', 30 ) );
		delete_transient( Cache::OPTIMIZE ); // Deliberately NOT regrade() — nothing swept.

		$r = ( new Score( new Settings() ) )->report();

		$this->assertSame( 0, (int) $r['graded'], 'nothing has been read' );
		$this->assertGreaterThan( 0, (int) $r['grading'], 'and the report says how much is outstanding' );

		$optimized = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'optimized' === $g['key'] ) {
				$optimized = $g;
			}
		}
		$this->assertNotNull( $optimized );
		$this->assertNull( $optimized['score'], 'unread is N/A, never a zero — blend() redistributes the weight' );
	}

	/**
	 * Reading more of the site has to CHANGE the score.
	 *
	 * ⚠️ The sweep became an input to the cached Optimized pillar the moment
	 * that pillar stopped parsing its own sample. Nothing used to bust that
	 * cache on a grade write — because nothing needed to — so a finished sweep
	 * would visibly change nothing until the transient expired, and `graded`
	 * could sit next to a freshly-read "0 still to read" contradicting it.
	 *
	 * Deliberately does NOT call regrade(): that clears the transient, which is
	 * the very thing under test.
	 */
	public function test_a_finished_sweep_is_visible_without_waiting_for_the_cache() {
		$this->post( str_repeat( 'A concrete point backed by a real figure: 42% in 2024. ', 30 ) );
		$this->post( str_repeat( 'Another concrete point, with a figure: 17% in 2025. ', 30 ) );

		// Warm the cache while nothing has been read.
		$before = ( new Score( new Settings() ) )->report();
		$this->assertSame( 0, (int) $before['graded'], 'precondition: nothing swept yet' );
		$this->assertSame( 2, (int) $before['grading'], 'precondition: both pages outstanding' );

		( new Worklist( new Settings() ) )->sweep( 200 );

		$after = ( new Score( new Settings() ) )->report();
		$this->assertSame( 2, (int) $after['graded'], 'the sweep must reach the score without a cache flush' );
		$this->assertSame( 0, (int) $after['grading'], 'and nothing is left outstanding' );
	}

	/**
	 * The two numbers describe ONE moment.
	 *
	 * `graded` comes from the cached grade read and `grading` used to be taken
	 * fresh in report(), so mid-sweep the card could print "11 graded · 0 still
	 * to read" while sixty-four were graded — both true, neither of the same
	 * instant. Seen on wpftest before it was fixed.
	 */
	public function test_graded_and_still_to_read_are_measured_at_the_same_instant() {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->post( str_repeat( 'A concrete point backed by a real figure: 42% in 2024. ', 30 ) );
		}

		( new Worklist( new Settings() ) )->sweep( 2 ); // Half the site, on purpose.

		$r = ( new Score( new Settings() ) )->report();
		$this->assertGreaterThan( 0, (int) $r['grading'], 'a half-swept site has pages outstanding' );
		$this->assertSame(
			4,
			(int) $r['graded'] + (int) $r['grading'],
			'graded + still-to-read must account for every published page, or the two were read at different times'
		);
	}

	public function test_cited_is_not_measured_once_ai_visibility_is_disabled() {
		$this->enable_visibility(); // Tracking is on; it's the provider key that's gone.
		// "Set up, ran, then removed the key": a completed run with a mention sits in the
		// store (so it WOULD score), but no provider is configured now.
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		Store::insert(
			array(
				'run_id'      => 5000,
				'brand'       => 'Acme',
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'prompt'      => 'best acme tools',
				'mentioned'   => true,
				'cited'       => false,
				'position'    => 1,
				'competitors' => array(),
				'answer'      => 'Acme is great.',
				'sources'     => array(),
				'error'       => '',
			)
		);
		update_option( Runner::LAST_RUN_OPTION, 5000 );

		$r = ( new Score( new Settings() ) )->report();
		delete_option( Runner::LAST_RUN_OPTION );

		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		$this->assertNull( $cited['score'], 'a stale run must not surface as Cited once AI Visibility has no active provider' );
		$this->assertFalse( $r['measured'], 'measured is false when nothing is currently being measured' );
	}

	public function test_cited_drops_a_run_older_than_the_staleness_cutoff() {
		$this->enable_visibility();
		// An active provider (a plaintext key passes through Crypto) + a completed run
		// well past the 90-day cutoff — recent enough to score would be wrong.
		update_option(
			VisibilitySettings::OPTION,
			array( 'providers' => array( 'openai' => array( 'key' => 'sk-plaintext-test', 'enabled' => true, 'model' => 'gpt-4o-mini', 'web_search' => false ) ) )
		);
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		$old = time() - 200 * DAY_IN_SECONDS;
		Store::insert(
			array(
				'run_id'      => $old,
				'brand'       => 'Acme',
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'prompt'      => 'best acme tools',
				'mentioned'   => true,
				'cited'       => false,
				'position'    => 1,
				'competitors' => array(),
				'answer'      => 'Acme is great.',
				'sources'     => array(),
				'error'       => '',
			)
		);
		update_option( Runner::LAST_RUN_OPTION, $old );

		$r = ( new Score( new Settings() ) )->report();
		delete_option( Runner::LAST_RUN_OPTION );
		delete_option( VisibilitySettings::OPTION );

		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		$this->assertNull( $cited['score'], 'a run past the staleness cutoff must not drive the score' );
		// The stale note (not the "add a key" note) — proves we reached the cutoff, i.e.
		// the provider was active and the run was simply too old.
		$this->assertStringContainsStringIgnoringCase( 'run a check', (string) $cited['note'] );
	}

	public function test_cited_all_error_run_reads_as_failing_not_never_run() {
		$this->enable_visibility();
		// Set up and run, but the key was expired/rate-limited: a completed run where EVERY
		// check errored. The bug: this used to read as "never run" — a null score AND an
		// "info: set up AI Visibility" action, telling the owner to set up what they already
		// pay for. It must instead read as a failure and be flagged at warn.
		update_option(
			VisibilitySettings::OPTION,
			array( 'providers' => array( 'openai' => array( 'key' => 'sk-plaintext-test', 'enabled' => true, 'model' => 'gpt-4o-mini', 'web_search' => false ) ) )
		);
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		$run = time() - DAY_IN_SECONDS; // Recent — well within the freshness window.
		foreach ( array( 'best acme tools', 'acme reviews' ) as $prompt ) {
			Store::insert(
				array(
					'run_id'      => $run,
					'brand'       => 'Acme',
					'provider'    => 'openai',
					'model'       => 'gpt-4o-mini',
					'prompt'      => $prompt,
					'mentioned'   => false,
					'cited'       => false,
					'position'    => 0,
					'competitors' => array(),
					'answer'      => '',
					'sources'     => array(),
					'error'       => 'HTTP 401 Unauthorized',
				)
			);
		}
		update_option( Runner::LAST_RUN_OPTION, $run );

		// Pass a clean (all-pass) readiness so this test isolates the citation path: a bare
		// install's many readiness fails would otherwise fill the 8-action cap and hide the
		// (correctly lower-ranked) warn. On the real, well-configured site this bug targets —
		// AI Visibility set up, key later expired — readiness is clean and the warn surfaces.
		$clean = array( array( 'id' => 'public', 'status' => 'pass', 'label' => 'Public', 'fix' => '', 'detail' => '' ) );
		$r     = ( new Score( new Settings() ) )->report( $clean );
		delete_option( Runner::LAST_RUN_OPTION );
		delete_option( VisibilitySettings::OPTION );

		// The Cited rung stays null (an all-error run measured nothing to score)...
		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		$this->assertNull( $cited['score'], 'an all-error run has no successful check to score' );
		$this->assertFalse( $r['measured'], 'a failed run is not a measurement' );
		// ...and the note names the provider key rather than reading as "never run".
		$this->assertStringContainsStringIgnoringCase( 'key', (string) $cited['note'] );
		$this->assertStringNotContainsStringIgnoringCase( 'run a check to measure', (string) $cited['note'] );

		// The action plan flags the failure at warn — and the old wrong "set up" invite
		// (info) is gone, since the feature IS set up.
		$failing = null;
		$ids     = array();
		foreach ( $r['actions'] as $a ) {
			$ids[] = $a['id'];
			if ( 'visibility_failing' === $a['id'] ) {
				$failing = $a;
			}
		}
		$this->assertNotNull( $failing, 'an all-error run must surface a "checks are failing" action' );
		$this->assertSame( 'warn', $failing['severity'], 'a broken paid integration outranks content nits' );
		$this->assertNotContains( 'measure_setup', $ids, 'a set-up-and-failing site must not be told to set up' );
	}

	public function test_cited_partial_run_keeps_its_score_and_flags_what_failed() {
		$this->enable_visibility();
		// A run where one check succeeded and one errored. Nulling this would redistribute
		// Cited's weight and hide the problem — the very bug. Keep the (smaller-sample)
		// score, but say a check didn't finish.
		update_option(
			VisibilitySettings::OPTION,
			array( 'providers' => array( 'openai' => array( 'key' => 'sk-plaintext-test', 'enabled' => true, 'model' => 'gpt-4o-mini', 'web_search' => false ) ) )
		);
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
		$run = time() - DAY_IN_SECONDS;
		Store::insert(
			array(
				'run_id'      => $run,
				'brand'       => 'Acme',
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'prompt'      => 'best acme tools',
				'mentioned'   => true,
				'cited'       => false,
				'position'    => 1,
				'competitors' => array(),
				'answer'      => 'Acme is great.',
				'sources'     => array(),
				'error'       => '',
			)
		);
		Store::insert(
			array(
				'run_id'      => $run,
				'brand'       => 'Acme',
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'prompt'      => 'acme reviews',
				'mentioned'   => false,
				'cited'       => false,
				'position'    => 0,
				'competitors' => array(),
				'answer'      => '',
				'sources'     => array(),
				'error'       => 'HTTP 429 Too Many Requests',
			)
		);
		update_option( Runner::LAST_RUN_OPTION, $run );

		$r = ( new Score( new Settings() ) )->report();
		delete_option( Runner::LAST_RUN_OPTION );
		delete_option( VisibilitySettings::OPTION );

		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		// The successful check measured 100% (1 of 1 mentioned) — a real, kept score.
		$this->assertSame( 100, $cited['score'], 'a partial run keeps its smaller-sample score' );
		$this->assertTrue( $r['measured'], 'a partial run is still a measurement' );
		$this->assertStringContainsStringIgnoringCase( 'didn', (string) $cited['note'], 'the note must say a check did not finish' );

		// A partial (still-measured) run is neither a failure nor a set-up gap → no
		// cited action at all; the rung note carries the "1 check didn't finish" signal.
		$ids = array_map( static function ( $a ) {
			return $a['id'];
		}, $r['actions'] );
		$this->assertNotContains( 'visibility_failing', $ids );
		$this->assertNotContains( 'measure_setup', $ids );
	}

	public function test_cited_rung_appears_only_when_tracking_is_on() {
		// Explicitly assert the off-state (option state can carry over between tests via
		// the options cache, so don't rely on the schema default here).
		$reset = new Settings();
		$all   = $reset->all();
		$all['enable_visibility'] = false;
		$reset->update( $all );

		$keys = static function ( $r ) {
			return array_map(
				static function ( $g ) {
					return $g['key'];
				},
				$r['rungs']
			);
		};

		// Default (off): a clean four-rung ladder, no Cited.
		$off = ( new Score( new Settings() ) )->report();
		$this->assertNotContains( 'cited', $keys( $off ), 'Cited is hidden until tracking is turned on' );
		$this->assertCount( 4, $off['rungs'] );

		// On: Cited joins the ladder.
		$this->enable_visibility();
		$on = ( new Score( new Settings() ) )->report();
		$this->assertContains( 'cited', $keys( $on ), 'Cited appears once tracking is on' );
	}

	public function test_cited_rung_routes_to_settings_when_setup_incomplete() {
		$this->enable_visibility();
		// Fresh: no engine key and no questions → a check can't run yet → the Cited rung
		// deep-links to the AI Visibility *Settings* sub-view so the owner can finish setup.
		$r     = ( new Score( new Settings() ) )->report();
		$cited = null;
		foreach ( $r['rungs'] as $g ) {
			if ( 'cited' === $g['key'] ) {
				$cited = $g;
			}
		}
		$this->assertSame( 'settings', $cited['view'] );
	}

	public function test_adversarial_post_markup_never_crashes_the_report() {
		// Unclosed tags, nested lists, a script and a bare img — the DOM parse must
		// tolerate it, and the whole report must still return rather than fatal.
		$this->post( '<div><p>unclosed <b>bold <ul><li>item</div></p> <script>1 < 2</script> <img src=x onerror=alert(1)>' );
		$this->regrade();

		$r = ( new Score( new Settings() ) )->report();
		$this->assertIsInt( $r['score'] ); // Reached the end — no fatal on messy markup.
	}
}
