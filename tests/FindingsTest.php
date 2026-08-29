<?php
/**
 * The front-door findings aggregator ({@see Findings}).
 *
 * What matters here is not that each source produces the right sentence — each
 * subsystem has its own tests for that — but that the SCREEN survives. This is
 * the plugin's first screen, so a single broken subsystem must cost its own
 * finding and nothing else, an empty list must be distinguishable from a broken
 * one, and the ranking must be a stated contract rather than an accident of
 * which source ran first.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Findings;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class FindingsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function payload() {
		return ( new Findings( new Settings() ) )->all();
	}

	/* ---- the screen always renders ----------------------------------------- */

	/**
	 * No database, no tables, no search connection — every source throws. The
	 * payload must still come back whole, because a front door that a single bad
	 * query can blank is worse than no front door.
	 */
	public function test_the_payload_survives_every_source_failing() {
		$out = $this->payload();

		$this->assertArrayHasKey( 'findings', $out );
		$this->assertArrayHasKey( 'clear', $out );
		$this->assertArrayHasKey( 'failed', $out );
		$this->assertArrayHasKey( 'counts', $out );
		$this->assertIsArray( $out['findings'] );
		$this->assertIsArray( $out['failed'] );
	}

	/** A source that blew up is NAMED, so a missing finding is visible not silent. */
	public function test_a_broken_source_is_reported_rather_than_hidden() {
		$out = $this->payload();

		// In unit land there is no wpdb, so the review-queue sources cannot run.
		$this->assertNotEmpty( $out['failed'], 'a source that throws must be listed' );
		$this->assertContainsOnly( 'string', $out['failed'] );
	}

	/** Tier counts describe the rows actually returned, never a source's ambition. */
	public function test_counts_match_the_rows_returned() {
		$out    = $this->payload();
		$counts = array( Findings::URGENT => 0, Findings::WORTH => 0, Findings::LATER => 0, Findings::WAITING => 0 );
		foreach ( $out['findings'] as $row ) {
			++$counts[ $row['tier'] ];
		}
		$this->assertSame( $counts, $out['counts'] );
	}

	/**
	 * The nav badge is urgent + worth, and NOTHING else.
	 *
	 * A count in a nav promises that doing the work makes it go down, and the
	 * waiting tier is exactly the set of findings no edit can clear — a page
	 * ranking 8th stays 8th until a later report says otherwise. Counting them
	 * left the badge stuck on 1 while the post's own editor panel said his side
	 * was done. If a future tier is added, this test is where it has to declare
	 * whether it is work.
	 */
	public function test_the_waiting_tier_is_carried_but_never_counted_as_work() {
		$out = $this->payload();

		$this->assertArrayHasKey( Findings::WAITING, $out['counts'] );
		$this->assertNotContains(
			Findings::WAITING,
			array( Findings::URGENT, Findings::WORTH ),
			'the badge sums urgent + worth; waiting must not be either'
		);
	}

	/**
	 * Resolutions travel beside the findings, not among them — and as at most
	 * ONE row.
	 *
	 * The boundary law: a busy site can resolve a dozen pages in a week, and a
	 * dozen green lines above the list would push the actual work off the first
	 * screen. Doing well must never cost you sight of what is left, so several
	 * wins collapse into a count with the moves as clipped evidence.
	 */
	public function test_the_payload_carries_at_most_one_resolved_row() {
		$out = $this->payload();

		$this->assertArrayHasKey( 'resolved', $out );
		$this->assertTrue( null === $out['resolved'] || is_array( $out['resolved'] ) );
		if ( is_array( $out['resolved'] ) ) {
			$this->assertArrayHasKey( 'title', $out['resolved'], 'one row, not a list of them' );
			$this->assertLessThanOrEqual( self::MAX_EVIDENCE_SHOWN, count( $out['resolved']['evidence'] ) );
		}
		foreach ( $out['findings'] as $row ) {
			$this->assertNotSame( 'resolved', $row['tier'], 'news is not a finding' );
		}
	}

	/** The evidence cap plus the one "+N more" line the clipper appends. */
	const MAX_EVIDENCE_SHOWN = Findings::MAX_EVIDENCE + 1;

	/* ---- the ranking is a contract ----------------------------------------- */

	/**
	 * The order the front door argues for: a decision only the owner can make
	 * outranks a chore, and traffic already being earned and lost outranks a
	 * site-wide clean-up. Locked here so re-ranking is a deliberate edit.
	 */
	public function test_the_ranking_contract_holds() {
		$w = Findings::WEIGHTS;

		$this->assertGreaterThan( $w['content_issues'], $w['config_gap'], 'a broken setup outranks a chore' );
		$this->assertGreaterThan( $w['content_issues'], $w['near_page_one'], 'traffic already earned outranks clean-up' );
		$this->assertGreaterThan( $w['never_measured'], $w['seen_not_chosen'], 'a lost click outranks an unrun report' );
	}

	/**
	 * The review queue is NOT a finding at all (his call, 2026-08-09). The nav
	 * bell already carries its live count and opens the queue itself, so a row
	 * on the front door was the same information twice — each copy pointing at
	 * the other. Same reasoning that earlier collapsed three rows into one.
	 */
	public function test_the_review_queue_is_not_a_finding() {
		foreach ( array( 'review_queue', 'forged_identity', 'waiting_clients', 'dead_identity_url' ) as $gone ) {
			$this->assertArrayNotHasKey( $gone, Findings::WEIGHTS, "$gone must not be a front-door row" );
		}
	}

	/** Every weight is distinct, so the sort can never be decided by source order. */
	public function test_no_two_findings_share_a_weight() {
		$w = array_values( Findings::WEIGHTS );
		$this->assertSame( count( $w ), count( array_unique( $w ) ) );
	}

	/** Sorted highest-weight first, whatever order the sources ran in. */
	public function test_findings_come_back_ranked() {
		\add_filter( 'agentimus_findings', function ( $payload ) {
			return $payload;
		} );
		$rows    = $this->payload()['findings'];
		$weights = array_map( function ( $r ) { return (int) $r['weight']; }, $rows );
		$sorted  = $weights;
		rsort( $sorted );
		$this->assertSame( $sorted, $weights );
	}

	/* ---- shape of a row ---------------------------------------------------- */

	/** Every row a screen has to render carries the fields the screen needs. */
	public function test_every_row_is_renderable() {
		\add_filter( 'agentimus_findings', function ( $p ) {
			$p['findings'][] = array(
				'id'       => 'x',
				'tier'     => Findings::WORTH,
				'weight'   => 1,
				'title'    => 'A thing',
				'why'      => 'Because.',
				'evidence' => array( 'a' ),
				'action'   => array( 'label' => 'Go', 'tab' => 'settings', 'view' => '' ),
			);
			return $p;
		} );
		foreach ( $this->payload()['findings'] as $row ) {
			$this->assertArrayHasKey( 'id', $row );
			$this->assertArrayHasKey( 'tier', $row );
			$this->assertArrayHasKey( 'title', $row );
			$this->assertArrayHasKey( 'why', $row );
			$this->assertIsArray( $row['evidence'] );
			$this->assertContains( $row['tier'], array( Findings::URGENT, Findings::WORTH, Findings::LATER ) );
		}
	}

	/* ---- the filter -------------------------------------------------------- */

	/** An add-on can contribute a finding to the front door. */
	public function test_the_filter_can_add_a_finding() {
		\add_filter( 'agentimus_findings', function ( $p ) {
			$p['findings'][] = array(
				'id'     => 'acme_thing',
				'tier'   => Findings::URGENT,
				'weight' => 999,
				'title'  => 'Acme needs a look',
				'why'    => 'It does.',
				'evidence' => array(),
				'action' => null,
			);
			return $p;
		} );

		$ids = array_map( function ( $r ) { return $r['id']; }, $this->payload()['findings'] );
		$this->assertContains( 'acme_thing', $ids );
	}

	/** Silencing the front door entirely is a supported thing to want. */
	public function test_the_filter_can_empty_the_list() {
		\add_filter( 'agentimus_findings', function ( $p ) {
			$p['findings'] = array();
			return $p;
		} );
		$this->assertSame( array(), $this->payload()['findings'] );
	}

	/** A filter returning nonsense falls back to an empty payload, never a fatal. */
	public function test_a_filter_returning_garbage_cannot_break_the_screen() {
		\add_filter( 'agentimus_findings', function () {
			return 'not-an-array';
		} );
		$out = $this->payload();
		$this->assertIsArray( $out );
		$this->assertSame( array(), $out['findings'] );
	}

	/* ---- a check's action survives becoming a finding's --------------------- */

	/**
	 * Readiness says `href`; a finding says `url`. Reading only `url` here sent
	 * every one of the fifteen outward-pointing checks to the `tab` fallback, so
	 * "View llms.txt" opened Settings. Both names are read, `href` wins.
	 */
	public function test_an_outward_check_keeps_its_destination() {
		$out = Findings::check_action( array( 'label' => 'View llms.txt', 'href' => 'https://example.test/llms.txt' ) );

		$this->assertSame( 'https://example.test/llms.txt', $out['url'] );
		$this->assertSame( 'View llms.txt', $out['label'] );
	}

	/** A destination is not also a tab — one action, one meaning. */
	public function test_a_destination_is_not_given_a_tab() {
		$out = Findings::check_action( array( 'label' => 'View llms.txt', 'href' => 'https://example.test/llms.txt' ) );

		$this->assertSame( '', $out['tab'], 'a URL opens in a new tab; the SPA route is not consulted' );
	}

	/** `url` is still honoured, so any other producer keeps working. */
	public function test_url_is_accepted_as_well_as_href() {
		$out = Findings::check_action( array( 'label' => 'Go', 'url' => 'https://example.test/x' ) );

		$this->assertSame( 'https://example.test/x', $out['url'] );
	}

	/** An in-app check still routes by tab and anchor, and defaults to Settings. */
	public function test_an_in_app_check_still_routes_by_tab() {
		$out = Findings::check_action( array( 'label' => 'Open discovery', 'tab' => 'settings', 'anchor' => 'ar-set-discovery' ) );

		$this->assertSame( '', $out['url'] );
		$this->assertSame( 'settings', $out['tab'] );
		$this->assertSame( 'ar-set-discovery', $out['anchor'] );

		$bare = Findings::check_action( array( 'label' => 'Open it' ) );
		$this->assertSame( 'settings', $bare['tab'], 'no destination and no tab still lands somewhere' );
	}

	/** No label, no button — a row with nothing to press is not an action. */
	public function test_a_labelless_action_is_no_action() {
		$this->assertNull( Findings::check_action( array( 'href' => 'https://example.test/' ) ) );
		$this->assertNull( Findings::check_action( null ) );
		$this->assertNull( Findings::check_action( 'nonsense' ) );
	}

	/* ---- caps -------------------------------------------------------------- */

	/** The front door is a front door, not the whole worklist. */
	public function test_the_list_is_capped() {
		$this->assertLessThanOrEqual( Findings::MAX, count( $this->payload()['findings'] ) );
		$this->assertGreaterThan( 0, Findings::MAX );
	}

	/* ---- the edge conflicts, and the all-clear that used to overstate ------- */

	/**
	 * Most sites have no Cloudflare zone connected, so the commonest outcome by
	 * far is "nothing to say" — and it must be silent rather than a failure.
	 * `failed` naming this source would tell every owner without a CDN that part
	 * of their front door is broken.
	 */
	public function test_the_edge_source_is_silent_and_unbroken_without_a_connected_zone() {
		$out = $this->payload();

		$this->assertNotContains( 'edge_conflicts', (array) $out['failed'] );
		$ids = array_map( static function ( $r ) {
			return $r['id'];
		}, (array) $out['findings'] );
		$this->assertNotContains( 'edge_conflict', $ids );
		$this->assertNotContains( 'edge_unenforced', $ids );
	}

	/**
	 * ⭐ THE RANKING IS A CONTRACT. A setup gap is something the owner fixes here
	 * in a minute; the edge turning an assistant away is a live trust problem
	 * they can only fix in somebody else's dashboard, and until they do, none of
	 * the work ranked below it reaches the assistant it was written for.
	 */
	public function test_a_blocking_edge_conflict_outranks_every_other_finding() {
		$weights = Findings::WEIGHTS;

		$this->assertGreaterThan( $weights['config_gap'], $weights['edge_conflict'] );
		$this->assertGreaterThan( $weights['content_issues'], $weights['edge_conflict'] );
		$this->assertLessThan(
			$weights['never_measured'],
			$weights['edge_unenforced'],
			'an unenforced wish is worth knowing, not worth interrupting for'
		);
	}

	/**
	 * ⛔⛔ A CLEAR LINE MAY NOT CLAIM MORE THAN IT MEASURED. This read "Nothing is
	 * blocking AI assistants — all N setup checks pass", and on his site on
	 * 2026-08-28 it sat on the front door while Cloudflare turned away 3,289 of
	 * 3,466 requests from OpenAI's crawler. Every setup check really did pass:
	 * the configuration was perfect and the assistant still got an error, because
	 * what blocks an assistant is not only what this plugin configures.
	 *
	 * Asserted on the source because the sentence is the whole point and the
	 * branch that emits it needs a live readiness report to reach — the same
	 * reason {@see NavigationTargetsTest} reads files rather than rendering.
	 */
	public function test_the_all_clear_no_longer_claims_nothing_is_blocking_assistants() {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Findings.php' );

		$this->assertStringNotContainsString(
			"'Nothing is blocking AI assistants",
			$source,
			'a false all-clear is worse than no line: it stops the owner looking'
		);
		$this->assertStringContainsString( "'All %s setup checks pass.'", $source );
	}

	/**
	 * ⭐ COLLAPSED CARDS STILL COUNT — his reversal, 2026-08-29, superseding the
	 * first cut's "visible conflicts only". A pin on the Request Log can only
	 * be FOLDED to a slim row, never hidden; folding tidies that screen, it
	 * does not end the conflict, and a finding that vanished with the card let
	 * one screen's tidiness silently clear the front door's most urgent row.
	 * Asserted on the source (like the date test below) because a live
	 * conflict needs a connected zone to build.
	 */
	public function test_a_collapsed_conflict_still_becomes_a_finding() {
		$src  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Findings.php' );
		$from = strpos( $src, 'private function edge_conflicts()' );
		$this->assertNotFalse( $from, 'the source exists' );
		$edge = substr( $src, $from, strpos( $src, 'private function clear_lines()' ) - $from );

		$this->assertStringContainsString(
			'hiddenConflicts',
			$edge,
			'edge_conflicts() must read the stored-as-dismissed (collapsed) conflicts too — a fold on the log is tidiness, not a resolution.'
		);

		// And the log keeps a landing for the row's deep link: a collapsed pin
		// renders as a slim row with the way back open, never as nothing.
		$app = (string) file_get_contents( dirname( __DIR__ ) . '/resources/admin/App.vue' );
		$this->assertStringContainsString( 'ar-edge-pin--collapsed', $app, 'the folded card keeps its seat as a slim row' );
		$this->assertStringContainsString( 'toggleEdgeConflict', $app, 'and the head row toggles it back open' );
		$this->assertStringNotContainsString( 'Hide this warning', $app, 'the old vanishing Hide is gone' );

		// The weekly email tells the same story: its edge section reads the
		// folded conflicts too, or the week's mail would claim a clear edge
		// while the front door counts an active conflict.
		$dsrc  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Digest/Data.php' );
		$dfrom = strpos( $dsrc, 'private static function edge_conflicts(' );
		$this->assertNotFalse( $dfrom, 'the digest edge builder exists' );
		$dedge = substr( $dsrc, $dfrom, strpos( $dsrc, 'public static function collect(' ) - $dfrom );
		$this->assertStringContainsString(
			'hiddenConflicts',
			$dedge,
			'Digest\\Data::edge_conflicts() must read the folded conflicts too — one owner, one story, every surface.'
		);
	}

	/**
	 * ⛔⛔ ONE CLOCK NAMES THE DAY. The first cut of this feature let the browser
	 * format the conflict's start date for the card while PHP formatted it for
	 * the findings row and the weekly email. On a site set to UTC, read from a
	 * UTC+6 laptop, the two surfaces printed different days for the same fact —
	 * caught on screen, not by a test. The label is now built once, server-side,
	 * in the site's timezone, and every surface prints that string.
	 */
	public function test_the_conflict_date_is_never_formatted_twice() {
		// ⚠️ SCOPED TO THE METHOD, not the file. Findings.php legitimately formats
		// another date elsewhere — the "waiting on Google" row's own since-stamp —
		// and a file-wide ban on date formatting would fail on an innocent
		// bystander, which is exactly what the first version of this test did.
		$src  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Findings.php' );
		$from = strpos( $src, 'private function edge_conflicts()' );
		$this->assertNotFalse( $from, 'the source exists' );
		$edge = substr( $src, $from, strpos( $src, 'private function clear_lines()' ) - $from );

		// Same scoping for the digest: section_robots() formats its own date, and
		// rightly so — it is a different fact with one surface.
		$dsrc  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Digest/Renderer.php' );
		$dfrom = strpos( $dsrc, 'private static function section_edge(' );
		$this->assertNotFalse( $dfrom, 'the digest section exists' );
		$digest = substr( $dsrc, $dfrom, strpos( $dsrc, 'private static function section_robots(' ) - $dfrom );

		$app = (string) file_get_contents( dirname( __DIR__ ) . '/resources/admin/App.vue' );

		foreach ( array( 'edge_conflicts()' => $edge, 'section_edge()' => $digest ) as $where => $code ) {
			$this->assertStringContainsString( 'sinceText', $code, "$where reads the shared phrase" );
			$this->assertStringNotContainsString( 'date_i18n(', $code, "$where does not re-format it" );
			$this->assertStringNotContainsString( 'wp_date(', $code, "$where does not re-format it" );
		}
		$this->assertStringContainsString( 'c.sinceText', $app, 'the card prints the server phrase' );
		$this->assertStringNotContainsString( 'conflictSince(', $app, 'and formats no date of its own' );
	}

	/**
	 * ⛔ AN INFO CONFLICT IS NOT ALWAYS THE TRAINING NOTICE. The day the spoof
	 * check first demoted a blocking warn on the live site, the impostor row
	 * came out reading "you asked AI companies not to train on your content" —
	 * the training notice's copy under an impersonation title. The kind is in
	 * the conflict's id, not its level, and each of the three kinds carries its
	 * own words.
	 */
	public function test_an_impostor_conflict_gets_its_own_copy_not_the_training_notices() {
		$src  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Findings.php' );
		$from = strpos( $src, 'private function edge_conflicts()' );
		$this->assertNotFalse( $from, 'the source exists' );
		$edge = substr( $src, $from, strpos( $src, 'private function clear_lines()' ) - $from );

		$this->assertStringContainsString( "'edge_impostors'", $edge, 'the demoted conflict has its own finding id' );
		$this->assertStringContainsString( "'edge-blocks-'", $edge, 'told apart by the id prefix, never by level alone' );
		$this->assertStringContainsString( 'confirmed impostors', $edge, 'its chip counts the proven fakes, not trainer passes' );
		$this->assertStringContainsString( 'Nothing needs allowing', $edge, 'its copy asks nothing of the owner' );
	}

	/** The impostor note asks nothing of the owner — it ranks below even the training notice. */
	public function test_the_impostor_note_ranks_below_the_training_notice() {
		$weights = \Agentimus\Findings::WEIGHTS;
		$this->assertLessThan( $weights['edge_unenforced'], $weights['edge_impostors'] );
		$this->assertGreaterThan( $weights['near_page_one_waiting'], $weights['edge_impostors'] );
	}
}
