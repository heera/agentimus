<?php
/**
 * Read/write access to the visibility results table, plus the aggregation that
 * turns raw per-check rows into the numbers the dashboard shows: a visibility
 * score, a citation rate, share-of-voice against competitors, and a trend over
 * recent runs.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

use Agentimus\Visibility\Settings;

defined( 'ABSPATH' ) || exit;

final class Store {

	/** @var int Characters of the model's answer kept as the list-level summary. */
	const EXCERPT_LEN = 600;

	/** @var int Characters of the whole answer kept for the one-row read. Every
	 * engine is capped at 1024 output tokens (~4k characters), so this is a
	 * backstop against a runaway response, not a working limit. */
	const ANSWER_LEN = 8000;

	/** @var int Cited source URLs kept per check, for display. */
	const MAX_SOURCES = 8;

	/** @var int Characters of a provider's error kept — the column's own width. */
	const ERROR_LEN = 191;

	/**
	 * Persist one (prompt × provider) check.
	 *
	 * @param array $row {
	 *     @type int    $run_id
	 *     @type string $brand       The product/brand this check is for.
	 *     @type string $provider
	 *     @type string $model
	 *     @type string $prompt
	 *     @type bool   $mentioned
	 *     @type bool   $cited
	 *     @type int    $position
	 *     @type array  $competitors Names detected in the answer.
	 *     @type array  $sources     Cited sources: URL strings and/or { url, label } records.
	 *     @type string $answer
	 *     @type string $error
	 * }
	 * @return void
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$answer  = trim( (string) ( $row['answer'] ?? '' ) );
		$full    = mb_strlen( $answer ) > self::ANSWER_LEN ? mb_substr( $answer, 0, self::ANSWER_LEN ) : $answer;
		$excerpt = self::excerpt( $answer );

		// Web-search sources the engine cited, deduped and capped so the row stays
		// small. Empty for engines that answered from memory (no live search).
		$sources = self::dedupe( Sources::normalize( (array) ( $row['sources'] ?? array() ) ) );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Table::name(),
			array(
				'run_id'        => (int) ( $row['run_id'] ?? 0 ),
				'checked_at'    => current_time( 'mysql' ),
				'brand'         => substr( (string) ( $row['brand'] ?? '' ), 0, 191 ),
				'provider'      => substr( (string) ( $row['provider'] ?? '' ), 0, 32 ),
				'model'         => substr( (string) ( $row['model'] ?? '' ), 0, 96 ),
				'prompt_hash'   => md5( (string) ( $row['prompt'] ?? '' ) ),
				'prompt'        => (string) ( $row['prompt'] ?? '' ),
				'mentioned'     => empty( $row['mentioned'] ) ? 0 : 1,
				'cited'         => empty( $row['cited'] ) ? 0 : 1,
				'position'      => (int) ( $row['position'] ?? 0 ),
				'competitors'   => wp_json_encode( array_values( (array) ( $row['competitors'] ?? array() ) ) ),
				'answer_excerpt' => $excerpt,
				'answer'        => $full,
				'sources'       => wp_json_encode( $sources ),
				'error'         => self::error_line( (string) ( $row['error'] ?? '' ) ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * The kept slice of the model's answer, cut on a word boundary and ending in
	 * an ellipsis that says a cut happened.
	 *
	 * ⚠️ This was `substr( $answer, 0, 600 )` — a cut at 600 BYTES. That ends
	 * mid-word ("…products (such as *Fluent"), says nothing about having been
	 * cut, and lands mid-CHARACTER on any multi-byte glyph sitting on the
	 * boundary, where half a curly quote or an emoji is stored as mojibake.
	 * `mb_substr` counts characters (WordPress polyfills it), and the walk back
	 * to a space is a byte offset ON PURPOSE: a space is ASCII, so cutting there
	 * can never split a character.
	 *
	 * @param string $answer The model's full answer.
	 * @return string
	 */
	private static function excerpt( $answer ) {
		$answer = trim( (string) $answer );
		if ( '' === $answer || mb_strlen( $answer ) <= self::EXCERPT_LEN ) {
			return $answer;
		}

		$cut = mb_substr( $answer, 0, self::EXCERPT_LEN );

		// Prefer the last COMPLETE SENTENCE: a summary that stops mid-thought
		// ("…and FluentAuth—which…") reads as broken, while one that ends where
		// a sentence ends reads as trimmed, which is what it is. His call,
		// 2026-08-24. Falls back to a word boundary when no sentence ends near
		// enough — a bulleted answer, or one long opening sentence.
		$floor = (int) ( strlen( $cut ) * 0.6 );
		$at    = self::last_sentence_end( $cut );
		if ( $at < $floor ) {
			// Only honour a word boundary that is actually near the end — a single
			// unbroken run (a long URL, or a script that writes without spaces) must
			// not lose most of the excerpt to it.
			$space = strrpos( $cut, ' ' );
			$at    = ( false !== $space && $space >= $floor ) ? $space : -1;
		}
		if ( $at > 0 ) {
			$cut = substr( $cut, 0, $at );
		}

		// A cut that landed on a sentence keeps its full stop and stands the
		// ellipsis off on its own — "applications. …" reads as "there was more",
		// where "applications.…" just looks like a typo.
		if ( preg_match( '~[.!?][\"”’\)\]]*$~u', $cut ) ) {
			return $cut . ' …';
		}

		// ⛔ Trailing punctuation is trimmed with a /u pattern, never rtrim(): rtrim
		// matches BYTES, so a charlist holding an em dash's bytes can shave one byte
		// off an unrelated multi-byte character and leave the same mojibake this
		// method exists to prevent.
		$trimmed = preg_replace( '~[\s,;:.\-–—]+$~u', '', $cut );

		return ( null === $trimmed ? $cut : $trimmed ) . '…';
	}

	/**
	 * The byte offset just past the last sentence-ending punctuation in a string,
	 * or -1. `.`, `!` or `?` (plus any closing quote or bracket) followed by
	 * whitespace — a full stop with no space after it is a decimal, a version
	 * number or an abbreviation mid-flow, not the end of a thought.
	 *
	 * ⭐ The offset is in BYTES and always lands after ASCII punctuation, so
	 * cutting there can never split a multi-byte character.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function last_sentence_end( $text ) {
		if ( ! preg_match_all( '~[.!?][\"”’\)\]]*(?=\s)~u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return -1;
		}
		$last = end( $m[0] );
		return (int) $last[1] + strlen( $last[0] );
	}

	/**
	 * A provider's error message, trimmed to the column and marked when it was.
	 * `substr( $msg, 0, 191 )` cut Google's rate-limit sentence mid-word ("To
	 * monitor your cu") and said nothing about it, and it counted BYTES against
	 * a column measured in CHARACTERS — so a multi-byte glyph on the boundary
	 * was both split and shorter than the space actually available.
	 *
	 * @param string $message Provider message.
	 * @return string
	 */
	private static function error_line( $message ) {
		$message = trim( $message );
		if ( mb_strlen( $message ) <= self::ERROR_LEN ) {
			return $message;
		}
		return mb_substr( $message, 0, self::ERROR_LEN - 1 ) . '…';
	}

	/**
	 * One row per site cited: keeps the first of each {@see Sources::key()} and
	 * caps the list. Three grounding chunks read from one site are one source to
	 * a reader, while two real page URLs on one domain stay two.
	 *
	 * @param array[] $sources Normalized { url, label } records.
	 * @return array[]
	 */
	private static function dedupe( array $sources ) {
		$seen = array();
		$out  = array();
		foreach ( $sources as $source ) {
			$key = Sources::key( $source );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $source;
		}
		return array_slice( $out, 0, self::MAX_SOURCES );
	}

	/**
	 * The most recent run's id (0 when there are no results yet).
	 *
	 * @return int
	 */
	public static function latest_run_id() {
		global $wpdb;
		$table = Table::name();
		return (int) $wpdb->get_var( "SELECT MAX(run_id) FROM $table" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
	}

	/**
	 * The N most recent run ids, newest first.
	 *
	 * @param int $limit How many.
	 * @return int[]
	 */
	public static function recent_run_ids( $limit = 12 ) {
		global $wpdb;
		$table = Table::name();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT run_id FROM $table ORDER BY run_id DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * All rows for one run.
	 *
	 * @param int $run_id Run id.
	 * @return array[] Assoc rows.
	 */
	public static function rows_for_run( $run_id ) {
		global $wpdb;
		$table = Table::name();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE run_id = %d ORDER BY id ASC", (int) $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete rows older than the retention window.
	 *
	 * @param int $days Retention window in days.
	 * @return int Rows removed.
	 */
	public static function prune( $days ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$table = Table::name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE checked_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
	}

	/**
	 * One check, whole: what was asked, who answered, how it was graded, every
	 * source it cited and the answer in full.
	 *
	 * Read one row at a time, on demand — the answer is the evidence behind a
	 * verdict and an owner is entitled to read it, but putting every answer in
	 * the dashboard payload would make every list read (and every MCP read of
	 * it) carry them all.
	 *
	 * @param int $id Row id.
	 * @return array|null Null when no such row.
	 */
	public static function answer( $id ) {
		global $wpdb;
		$table = Table::name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
		if ( ! is_array( $row ) ) {
			return null;
		}

		$answer = (string) ( $row['answer'] ?? '' );

		return array(
			'id'        => (int) $row['id'],
			'brand'     => (string) $row['brand'],
			'prompt'    => (string) $row['prompt'],
			'provider'  => (string) $row['provider'],
			'model'     => (string) $row['model'],
			'checkedAt' => (string) $row['checked_at'],
			'mentioned' => ! empty( $row['mentioned'] ),
			'cited'     => ! empty( $row['cited'] ),
			'sources'   => Sources::normalize( (array) ( json_decode( (string) ( $row['sources'] ?? '' ), true ) ?: array() ) ),
			'excerpt'   => (string) $row['answer_excerpt'],
			'answer'    => $answer,
			// Rows written before the answer column existed kept the excerpt only.
			// The screen says so rather than passing a fragment off as the answer.
			'hasFullAnswer' => '' !== $answer,
			'error'     => (string) $row['error'],
		);
	}

	/**
	 * Wipe all results (used by uninstall / a manual reset).
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
	}

	/**
	 * Assemble the full dashboard payload: an overall headline for the latest run,
	 * one self-contained section per tracked product (its score, rank, questions and
	 * share of voice against its own competitors), and an overall visibility trend.
	 *
	 * @param Settings $settings Pro settings (for the product list).
	 * @return array
	 */
	public static function dashboard( Settings $settings ) {
		$targets = (array) $settings->get( 'targets', array() );

		// Reflect the last COMPLETED run, not an in-progress one. A background run
		// inserts its rows under a new run_id as it goes, so keying off MAX(run_id)
		// would show a half-finished run with jumping numbers (e.g. 100% after the
		// first check, settling once the rest land). LAST_RUN_OPTION is written only
		// when a run finishes, so mid-run we keep showing the previous complete
		// results — matching the "Last run" timestamp, which uses the same source.
		$latest_id = (int) get_option( Runner::LAST_RUN_OPTION, 0 );
		$latest    = $latest_id ? self::rows_for_run( $latest_id ) : array();

		$overall = self::summarize( $latest );

		// Group the latest run's rows by the product each check was for.
		$by_brand = self::by_brand( $latest );

		// ⭐ The run BEFORE this one, grouped the same way. A card that is folded
		// shows what MOVED rather than a number with no history — and a movement
		// needs something to have moved from. One extra read of one run.
		$previous_id   = self::previous_run_id( $latest_id );
		$previous_rows = $previous_id ? self::by_brand( self::rows_for_run( $previous_id ) ) : array();

		// When each product was last looked at, across every run — the answer to
		// "is this card in step with the run above it, or is it older than that?"
		$last_seen = self::last_checked_by_brand();

		$products = array();
		foreach ( $targets as $t ) {
			$name       = (string) ( $t['name'] ?? '' );
			$rows       = isset( $by_brand[ $name ] ) ? $by_brand[ $name ] : array();
			$product    = self::product_dashboard(
				$name,
				(string) ( $t['domain'] ?? '' ),
				(array) ( $t['competitors'] ?? array() ),
				$rows
			);
			$product['paused']       = ! ( $t['active'] ?? true );
			// What this product looked like in the run before — null when there is
			// no earlier run, or none that checked this product.
			$product['previous']     = isset( $previous_rows[ $name ] ) ? self::summarize( $previous_rows[ $name ] ) : null;
			// Its own last check, and whether that was this run. A product added
			// or resumed since the last run is NOT in step with it, and a card
			// showing older numbers has to say so rather than read as current.
			$product['inLatestRun']  = ! empty( $rows );
			$product['checkedAt']    = isset( $last_seen[ $name ] ) ? $last_seen[ $name ] : '';
			// Whether a question is configured now — distinct from whether the last run
			// produced rows. Lets the UI say "run a check" (has a question, not yet run)
			// instead of the wrong "add a question" when a question was added after a run.
			$product['hasQuestions'] = ! empty( (array) ( $t['prompts'] ?? array() ) );
			$products[]              = $product;
		}

		// Overall trend: visibility score for each recent COMPLETED run, oldest → newest.
		// Exclude any in-progress run (run_id above the last completed one) so the line
		// doesn't gain a half-finished point while a check is running.
		$completed = array_filter(
			self::recent_run_ids( 12 ),
			static function ( $rid ) use ( $latest_id ) {
				return (int) $rid <= $latest_id;
			}
		);
		$trend = array();
		foreach ( array_reverse( $completed ) as $rid ) {
			$s       = self::summarize( self::rows_for_run( $rid ) );
			$trend[] = array(
				'runId' => $rid,
				'at'    => $rid ? gmdate( 'c', $rid ) : '',
				'score' => $s['visibilityScore'],
			);
		}

		return array(
			'hasData'   => $latest_id > 0,
			'lastRunAt' => $latest_id ? gmdate( 'c', $latest_id ) : '',
			'summary'   => $overall,
			'products'  => $products,
			'trend'     => $trend,
		);
	}

	/**
	 * Build one product's section from its own rows in a run: headline numbers, its
	 * average rank, the per-prompt breakdown, and share of voice against its rivals.
	 *
	 * @param string   $name        Product/brand name.
	 * @param string   $domain      Product website (bare host).
	 * @param string[] $competitors The product's competitor names.
	 * @param array[]  $rows        This product's rows from the run.
	 * @return array
	 */
	private static function product_dashboard( $name, $domain, array $competitors, array $rows ) {
		$summary = self::summarize( $rows );

		// Average rank against competitors, over the answers that named this product.
		$pos_sum = 0;
		$pos_n   = 0;
		foreach ( $rows as $r ) {
			if ( '' === (string) $r['error'] && ! empty( $r['mentioned'] ) && ! empty( $r['position'] ) ) {
				$pos_sum += (int) $r['position'];
				$pos_n++;
			}
		}
		$rank = $pos_n > 0 ? (int) round( $pos_sum / $pos_n ) : 0;

		// Per-prompt breakdown: for each of this product's questions, the per-provider result.
		$prompts = array();
		foreach ( $rows as $r ) {
			$key = $r['prompt_hash'];
			if ( ! isset( $prompts[ $key ] ) ) {
				$prompts[ $key ] = array(
					'prompt'    => $r['prompt'],
					'providers' => array(),
				);
			}
			$prompts[ $key ]['providers'][] = array(
				// The row's own id: the list carries the SUMMARY, and this is how
				// the screen asks for the one answer someone opened.
				'id'          => (int) $r['id'],
				'provider'    => $r['provider'],
				'model'       => $r['model'],
				'mentioned'   => (bool) $r['mentioned'],
				'cited'       => (bool) $r['cited'],
				'competitors' => json_decode( (string) $r['competitors'], true ) ?: array(),
				'excerpt'     => $r['answer_excerpt'],
				// Rows written before 1.44 hold bare URL strings; they normalize into
				// the same { url, label } pair with an empty label, so old history
				// reads exactly as it always did.
				'sources'     => Sources::normalize( (array) ( json_decode( (string) ( $r['sources'] ?? '' ), true ) ?: array() ) ),
				'error'       => $r['error'],
			);
		}

		// Share of voice: this product's mentions against each of its competitors'.
		$brand_hits = 0;
		$comp_hits  = array();
		foreach ( $competitors as $c ) {
			$comp_hits[ $c ] = 0;
		}
		foreach ( $rows as $r ) {
			if ( ! empty( $r['mentioned'] ) ) {
				$brand_hits++;
			}
			$found = json_decode( (string) $r['competitors'], true );
			if ( is_array( $found ) ) {
				foreach ( $found as $c ) {
					if ( ! isset( $comp_hits[ $c ] ) ) {
						$comp_hits[ $c ] = 0;
					}
					$comp_hits[ $c ]++;
				}
			}
		}
		$total_voice = $brand_hits + array_sum( $comp_hits );
		$voice       = array(
			array(
				'name'     => '' !== $name ? $name : __( 'This product', 'agentimus' ),
				'mentions' => $brand_hits,
				'isBrand'  => true,
				'share'    => $total_voice > 0 ? (int) round( $brand_hits / $total_voice * 100 ) : 0,
			),
		);
		foreach ( $comp_hits as $cname => $hits ) {
			$voice[] = array(
				'name'     => $cname,
				'mentions' => $hits,
				'isBrand'  => false,
				'share'    => $total_voice > 0 ? (int) round( $hits / $total_voice * 100 ) : 0,
			);
		}

		return array(
			'name'         => $name,
			'domain'       => $domain,
			'summary'      => $summary,
			'rank'         => $rank,
			'prompts'      => array_values( $prompts ),
			'shareOfVoice' => $voice,
		);
	}

	/**
	 * Group check rows by the product each was for.
	 *
	 * @param array[] $rows Rows.
	 * @return array<string,array[]>
	 */
	private static function by_brand( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (string) ( $r['brand'] ?? '' ) ][] = $r;
		}
		return $out;
	}

	/**
	 * The completed run before a given one, or 0.
	 *
	 * @param int $latest_id The run being shown.
	 * @return int
	 */
	private static function previous_run_id( $latest_id ) {
		global $wpdb;
		$table = Table::name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(run_id) FROM $table WHERE run_id < %d", (int) $latest_id ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
	}

	/**
	 * The most recent check time for each product, across every run kept.
	 *
	 * @return array<string,string> Brand → 'Y-m-d H:i:s'.
	 */
	private static function last_checked_by_brand() {
		global $wpdb;
		$table = Table::name();
		$rows  = $wpdb->get_results( "SELECT brand, MAX(checked_at) AS at FROM $table GROUP BY brand", ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['brand'] ] = (string) $r['at'];
		}
		return $out;
	}

	/**
	 * Reduce a set of check rows to the headline numbers.
	 *
	 * @param array[] $rows Rows from one run.
	 * @return array { checks, mentions, citations, errors, visibilityScore, citationRate }
	 */
	public static function summarize( array $rows ) {
		$checks    = 0;
		$mentions  = 0;
		$citations = 0;
		$errors    = 0;

		foreach ( $rows as $r ) {
			if ( '' !== (string) $r['error'] ) {
				$errors++;
				continue;
			}
			$checks++;
			if ( ! empty( $r['mentioned'] ) ) {
				$mentions++;
			}
			if ( ! empty( $r['cited'] ) ) {
				$citations++;
			}
		}

		return array(
			'checks'          => $checks,
			'mentions'        => $mentions,
			'citations'       => $citations,
			'errors'          => $errors,
			'visibilityScore' => $checks > 0 ? (int) round( $mentions / $checks * 100 ) : 0,
			'citationRate'    => $checks > 0 ? (int) round( $citations / $checks * 100 ) : 0,
		);
	}
}
