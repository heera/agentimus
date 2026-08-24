<?php
/**
 * The AI-Visibility results table against real MySQL: the check insert, the run-id
 * lookups, per-run reads, the summarize/dashboard aggregation over stored rows, and
 * retention prune — the Visibility module's whole storage layer, previously untested.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Visibility\Runner;
use Agentimus\Visibility\Settings;
use Agentimus\Visibility\Store;
use Agentimus\Visibility\Table;

final class VisibilityStoreDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
	}

	private function insert( $run_id, array $overrides = array() ) {
		Store::insert(
			array_merge(
				array(
					'run_id'      => $run_id,
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
				),
				$overrides
			)
		);
	}

	private function row_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Table::name() );
	}

	public function test_insert_and_rows_for_run_round_trip() {
		$this->insert( 1000 );
		$this->insert( 1000, array( 'provider' => 'perplexity', 'mentioned' => false, 'error' => 'rate limited' ) );

		$rows = Store::rows_for_run( 1000 );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'openai', $rows[0]['provider'] );
		$this->assertSame( '1', (string) $rows[0]['mentioned'] );
		$this->assertSame( 'rate limited', $rows[1]['error'] );
	}

	public function test_latest_and_recent_run_ids() {
		$this->insert( 100 );
		$this->insert( 200 );
		$this->insert( 200 );

		$this->assertSame( 200, Store::latest_run_id() );
		$this->assertSame( array( 200, 100 ), Store::recent_run_ids( 12 ) );
	}

	public function test_summarize_over_stored_rows() {
		// 3 valid checks (2 mentions, 1 citation) + 1 error → score 2/3 = 67%.
		$this->insert( 1, array( 'mentioned' => true, 'cited' => true ) );
		$this->insert( 1, array( 'mentioned' => true, 'cited' => false ) );
		$this->insert( 1, array( 'mentioned' => false, 'cited' => false ) );
		$this->insert( 1, array( 'mentioned' => true, 'error' => 'boom' ) );

		$s = Store::summarize( Store::rows_for_run( 1 ) );
		$this->assertSame( 3, $s['checks'] );
		$this->assertSame( 2, $s['mentions'] );
		$this->assertSame( 1, $s['citations'] );
		$this->assertSame( 1, $s['errors'] );
		$this->assertSame( 67, $s['visibilityScore'] );
	}

	public function test_dashboard_reflects_the_last_completed_run() {
		update_option( Runner::LAST_RUN_OPTION, 500, false );
		$this->insert( 500, array( 'mentioned' => true ) );
		$this->insert( 500, array( 'mentioned' => false ) );

		$dash = Store::dashboard( new Settings() );
		$this->assertTrue( $dash['hasData'] );
		$this->assertSame( 2, $dash['summary']['checks'] );
		$this->assertSame( 1, $dash['summary']['mentions'] );
		$this->assertSame( 50, $dash['summary']['visibilityScore'] );
	}

	public function test_prune_drops_rows_older_than_retention() {
		global $wpdb;
		$this->insert( 1 ); // fresh (checked_at = now).
		$wpdb->insert( // an old row seeded directly, so checked_at can be in the past.
			Table::name(),
			array(
				'run_id' => 2, 'checked_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ),
				'brand' => 'Old', 'provider' => 'openai', 'model' => 'x', 'prompt_hash' => md5( 'x' ),
				'prompt' => 'x', 'mentioned' => 0, 'cited' => 0, 'position' => 0,
				'competitors' => '[]', 'answer_excerpt' => '', 'sources' => '[]', 'error' => '',
			)
		);

		$this->assertSame( 1, Store::prune( 180 ), 'the 400-day-old row is removed' );
		$this->assertSame( 1, $this->row_count() );
	}

	/* -- What a row actually keeps: the excerpt and the cited sources ------- */

	/**
	 * The stored excerpt is cut on a word boundary and says it was cut. It used
	 * to be `substr( $answer, 0, 600 )` — a cut at 600 BYTES, which ends
	 * mid-word ("…products (such as *Fluent"), admits nothing, and splits any
	 * multi-byte character sitting on the boundary into mojibake.
	 */
	/**
	 * The excerpt ends where a SENTENCE ends. A summary that stops mid-thought
	 * ("…and FluentAuth—which…") reads as broken; one that stops where the
	 * writer stopped reads as trimmed, which is what it is.
	 */
	public function test_a_long_answer_ends_on_a_complete_sentence() {
		$answer = str_repeat( 'The name Sheikh Heera refers to a software engineer. ', 40 );
		$this->insert( 1, array( 'answer' => $answer ) );

		$excerpt = Store::rows_for_run( 1 )[0]['answer_excerpt'];
		$this->assertStringEndsWith( '. …', $excerpt, 'the kept text ends on a full stop, and the ellipsis says there was more' );

		$kept = rtrim( mb_substr( $excerpt, 0, -1 ) ); // drop the ellipsis (and its space)
		$this->assertStringStartsWith( $kept, $answer, 'what is kept is a prefix of what was said' );
		$this->assertLessThanOrEqual( Store::EXCERPT_LEN + 2, mb_strlen( $excerpt ) );
	}

	/** No sentence ends near the cut — one long opening — so the word boundary
	 *  still catches it, and no word is split. */
	public function test_an_answer_with_no_sentence_end_falls_back_to_a_word_boundary() {
		$answer = 'Agentimus ' . str_repeat( 'and readable machine legible content ', 40 ) . 'finally ends.';
		$this->insert( 1, array( 'answer' => $answer ) );

		$excerpt = Store::rows_for_run( 1 )[0]['answer_excerpt'];
		$this->assertStringEndsWith( '…', $excerpt );

		$kept = mb_substr( $excerpt, 0, -1 );
		$this->assertStringStartsWith( $kept, $answer );
		$this->assertSame( 1, preg_match( '~^[\s.,;:]~u', mb_substr( $answer, mb_strlen( $kept ) ) ), 'the cut lands between words' );
	}

	/** A multi-byte character straddling the byte boundary survives whole. */
	public function test_a_multibyte_character_on_the_boundary_is_never_split() {
		// 599 bytes of ASCII, then a 3-byte “ — byte 600 falls INSIDE it.
		$answer = str_repeat( 'x', 599 ) . '“quoted” and a long tail ' . str_repeat( 'y', 200 );
		$this->insert( 1, array( 'answer' => $answer ) );

		$this->assertTrue( mb_check_encoding( Store::rows_for_run( 1 )[0]['answer_excerpt'], 'UTF-8' ), 'the excerpt is valid UTF-8' );
		$this->assertFalse( mb_check_encoding( substr( $answer, 0, Store::EXCERPT_LEN ), 'UTF-8' ), 'the byte cut this replaced does split it' );
	}

	public function test_a_short_answer_is_stored_whole() {
		$this->insert( 1, array( 'answer' => 'Acme is great.' ) );
		$this->assertSame( 'Acme is great.', Store::rows_for_run( 1 )[0]['answer_excerpt'] );
	}

	/** One row per cited site: two grounding chunks read from one site are one
	 *  source, while two real pages on one domain stay two. */
	public function test_sources_are_stored_as_records_and_deduped_per_site() {
		$this->insert(
			1,
			array(
				'sources' => array(
					array( 'url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/A', 'label' => 'heera.it' ),
					array( 'url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/B', 'label' => 'heera.it' ),
					'https://example.com/one',
					'https://example.com/two',
					'https://example.com/one',
				),
			)
		);

		$stored = json_decode( Store::rows_for_run( 1 )[0]['sources'], true );
		$this->assertCount( 3, $stored );
		$this->assertSame( array( 'url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/A', 'label' => 'heera.it' ), $stored[0] );
		$this->assertSame( array( 'url' => 'https://example.com/one', 'label' => '' ), $stored[1] );
		$this->assertSame( array( 'url' => 'https://example.com/two', 'label' => '' ), $stored[2] );
	}

	/** Rows written before 1.44 hold bare strings; they read as records with an
	 *  empty label, so old history reads exactly as it always did. */
	public function test_a_row_of_bare_string_sources_reads_back_as_records() {
		global $wpdb;
		update_option( Runner::LAST_RUN_OPTION, 700, false );
		update_option(
			'agentimus_visibility',
			array( 'targets' => array( array( 'name' => 'Acme', 'domain' => 'acme.test', 'active' => true, 'competitors' => array(), 'prompts' => array( 'best acme tools' ) ) ) ),
			false
		);
		$wpdb->insert(
			Table::name(),
			array(
				'run_id' => 700, 'checked_at' => current_time( 'mysql' ), 'brand' => 'Acme',
				'provider' => 'openai', 'model' => 'x', 'prompt_hash' => md5( 'best acme tools' ),
				'prompt' => 'best acme tools', 'mentioned' => 1, 'cited' => 1, 'position' => 1,
				'competitors' => '[]', 'answer_excerpt' => 'old row', 'sources' => '["https://acme.test/a"]', 'error' => '',
			)
		);

		$dash    = Store::dashboard( new Settings() );
		$sources = $dash['products'][0]['prompts'][0]['providers'][0]['sources'];
		$this->assertSame( array( array( 'url' => 'https://acme.test/a', 'label' => '' ) ), $sources );
	}

	/* -- The answer kept whole, read one row at a time --------------------- */

	/** The excerpt is the summary the list carries; the answer is the whole
	 *  thing, and only the one-row read hands it over. */
	public function test_the_whole_answer_is_kept_beside_its_excerpt() {
		$answer = str_repeat( 'Agentimus makes a site legible to assistants. ', 40 );
		$this->insert( 1, array( 'answer' => $answer ) );

		$row = Store::rows_for_run( 1 )[0];
		$this->assertSame( trim( $answer ), $row['answer'], 'the answer is stored in full' );
		$this->assertLessThan( mb_strlen( $answer ), mb_strlen( $row['answer_excerpt'] ), 'the excerpt stays a summary' );

		$full = Store::answer( (int) $row['id'] );
		$this->assertTrue( $full['hasFullAnswer'] );
		$this->assertSame( trim( $answer ), $full['answer'] );
		$this->assertSame( 'best acme tools', $full['prompt'] );
		$this->assertSame( 'openai', $full['provider'] );
	}

	/** A runaway response is bounded — the engines cap themselves, this is the
	 *  backstop if one ever doesn't. */
	public function test_a_runaway_answer_is_bounded() {
		$this->insert( 1, array( 'answer' => str_repeat( 'x', Store::ANSWER_LEN + 5000 ) ) );
		$this->assertSame( Store::ANSWER_LEN, mb_strlen( Store::rows_for_run( 1 )[0]['answer'] ) );
	}

	/**
	 * A row from before the answer column existed keeps only its excerpt, and
	 * says so — the screen shows the opening rather than passing a fragment off
	 * as the whole answer.
	 */
	public function test_a_row_stored_before_answers_were_kept_admits_it() {
		global $wpdb;
		$wpdb->insert(
			Table::name(),
			array(
				'run_id' => 900, 'checked_at' => current_time( 'mysql' ), 'brand' => 'Acme',
				'provider' => 'gemini', 'model' => 'x', 'prompt_hash' => md5( 'q' ), 'prompt' => 'q',
				'mentioned' => 1, 'cited' => 0, 'position' => 1, 'competitors' => '[]',
				'answer_excerpt' => 'The opening only…', 'answer' => '', 'sources' => '[]', 'error' => '',
			)
		);

		$full = Store::answer( (int) $wpdb->insert_id );
		$this->assertFalse( $full['hasFullAnswer'] );
		$this->assertSame( 'The opening only…', $full['excerpt'] );
		$this->assertSame( '', $full['answer'] );
	}

	/** A trimmed error says it was trimmed — Google's rate-limit sentence used to
	 *  stop dead at "To monitor your cu". */
	public function test_a_long_provider_error_is_marked_where_it_was_cut() {
		$this->insert( 1, array( 'error' => 'You exceeded your current quota. ' . str_repeat( 'Check your plan and billing details. ', 12 ) ) );

		$error = Store::rows_for_run( 1 )[0]['error'];
		$this->assertStringEndsWith( '…', $error );
		$this->assertSame( Store::ERROR_LEN, mb_strlen( $error ), 'it uses the whole column, counted in characters' );
	}

	public function test_a_short_provider_error_is_stored_whole() {
		$this->insert( 1, array( 'error' => 'Rate limited.' ) );
		$this->assertSame( 'Rate limited.', Store::rows_for_run( 1 )[0]['error'] );
	}

	public function test_reading_a_check_that_is_gone_returns_null() {
		$this->assertNull( Store::answer( 999999 ) );
	}

	/** The list read carries the row id — it is how the screen asks for one. */
	public function test_the_dashboard_names_each_row_by_id() {
		update_option( Runner::LAST_RUN_OPTION, 800, false );
		update_option(
			'agentimus_visibility',
			array( 'targets' => array( array( 'name' => 'Acme', 'domain' => 'acme.test', 'active' => true, 'competitors' => array(), 'prompts' => array( 'best acme tools' ) ) ) ),
			false
		);
		$this->insert( 800 );

		$dash = Store::dashboard( new Settings() );
		$this->assertGreaterThan( 0, $dash['products'][0]['prompts'][0]['providers'][0]['id'] );
	}

	/* -- What moved: each card carries the run before it ------------------- */

	/** Two products configured, so a card can be told from its neighbour. */
	private function targets() {
		update_option(
			'agentimus_visibility',
			array(
				'targets' => array(
					array( 'name' => 'Acme', 'domain' => 'acme.test', 'active' => true, 'competitors' => array(), 'prompts' => array( 'best acme tools' ) ),
					array( 'name' => 'Beta', 'domain' => 'beta.test', 'active' => true, 'competitors' => array(), 'prompts' => array( 'best beta tools' ) ),
				),
			),
			false
		);
	}

	public function test_a_card_carries_the_run_before_it() {
		$this->targets();
		// Run 1: Acme named in one of two. Run 2: named in both.
		$this->insert( 1, array( 'brand' => 'Acme', 'mentioned' => true ) );
		$this->insert( 1, array( 'brand' => 'Acme', 'mentioned' => false ) );
		$this->insert( 2, array( 'brand' => 'Acme', 'mentioned' => true ) );
		$this->insert( 2, array( 'brand' => 'Acme', 'mentioned' => true ) );
		update_option( Runner::LAST_RUN_OPTION, 2, false );

		$acme = Store::dashboard( new Settings() )['products'][0];
		$this->assertSame( 100, $acme['summary']['visibilityScore'] );
		$this->assertSame( 50, $acme['previous']['visibilityScore'], 'the card knows what it was' );
		$this->assertTrue( $acme['inLatestRun'] );
		$this->assertNotSame( '', $acme['checkedAt'] );
	}

	/** Nothing to compare with on a first run — a movement needs a before. */
	public function test_a_first_run_has_no_previous() {
		$this->targets();
		$this->insert( 5, array( 'brand' => 'Acme' ) );
		update_option( Runner::LAST_RUN_OPTION, 5, false );

		$this->assertNull( Store::dashboard( new Settings() )['products'][0]['previous'] );
	}

	/**
	 * A product paused (or added) since the last run shows older numbers, and
	 * the card has to be able to say so instead of reading as current.
	 */
	public function test_a_product_missing_from_the_last_run_says_when_it_was_last_checked() {
		$this->targets();
		$this->insert( 1, array( 'brand' => 'Beta', 'mentioned' => true ) );
		$this->insert( 2, array( 'brand' => 'Acme', 'mentioned' => true ) );
		update_option( Runner::LAST_RUN_OPTION, 2, false );

		$products = Store::dashboard( new Settings() )['products'];
		$beta     = $products[1];
		$this->assertSame( 'Beta', $beta['name'] );
		$this->assertFalse( $beta['inLatestRun'] );
		$this->assertNotSame( '', $beta['checkedAt'], 'it still knows when it was last looked at' );
	}

	/**
	 * ⭐ THE UPGRADE PATH, on a table that already has rows in it.
	 *
	 * Every site installing this release runs one ALTER on a populated table.
	 * Creating the schema from scratch — which every other test here does —
	 * proves nothing about that: the column is simply there. This drops it,
	 * puts a row in, and asks the installer to do what it will do on a live
	 * site, then checks the row is still there and readable.
	 */
	public function test_the_answer_column_is_added_to_a_table_that_already_has_rows() {
		global $wpdb;
		$table = Table::name();

		$wpdb->query( "ALTER TABLE $table DROP COLUMN answer" ); // phpcs:ignore WordPress.DB
		delete_option( Table::VERSION_OPTION );
		$wpdb->insert(
			$table,
			array(
				'run_id' => 1, 'checked_at' => current_time( 'mysql' ), 'brand' => 'Acme',
				'provider' => 'openai', 'model' => 'x', 'prompt_hash' => md5( 'q' ), 'prompt' => 'q',
				'mentioned' => 1, 'cited' => 0, 'position' => 1, 'competitors' => '[]',
				'answer_excerpt' => 'kept', 'sources' => '[]', 'error' => '',
			)
		);
		$id = (int) $wpdb->insert_id;

		Table::maybe_install();

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table" ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'answer', $columns, 'the upgrade adds the column' );
		$this->assertContains( 'answer_excerpt', $columns, 'and leaves the old one alone' );

		$row = Store::answer( $id );
		$this->assertSame( 'kept', $row['excerpt'], 'the row survived the upgrade' );
		$this->assertFalse( $row['hasFullAnswer'], 'and reads as what it is: stored before answers were kept' );
	}

	public function test_clear_empties_the_table() {
		$this->insert( 1 );
		Store::clear();
		$this->assertSame( 0, $this->row_count() );
	}
}
