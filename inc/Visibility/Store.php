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

	/** @var int Characters of the model's answer kept for display. */
	const EXCERPT_LEN = 600;

	/** @var int Cited source URLs kept per check, for display. */
	const MAX_SOURCES = 8;

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
	 *     @type string $answer
	 *     @type string $error
	 * }
	 * @return void
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$answer = (string) ( $row['answer'] ?? '' );
		if ( strlen( $answer ) > self::EXCERPT_LEN ) {
			$answer = substr( $answer, 0, self::EXCERPT_LEN );
		}

		// Web-search sources the engine cited, deduped and capped so the row stays
		// small. Empty for engines that answered from memory (no live search).
		$sources = array_slice( array_values( array_unique( array_filter(
			array_map( 'strval', (array) ( $row['sources'] ?? array() ) )
		) ) ), 0, self::MAX_SOURCES );

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
				'answer_excerpt' => $answer,
				'sources'       => wp_json_encode( $sources ),
				'error'         => substr( (string) ( $row['error'] ?? '' ), 0, 191 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
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
		$by_brand = array();
		foreach ( $latest as $r ) {
			$by_brand[ (string) ( $r['brand'] ?? '' ) ][] = $r;
		}

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
				'provider'    => $r['provider'],
				'model'       => $r['model'],
				'mentioned'   => (bool) $r['mentioned'],
				'cited'       => (bool) $r['cited'],
				'competitors' => json_decode( (string) $r['competitors'], true ) ?: array(),
				'excerpt'     => $r['answer_excerpt'],
				'sources'     => json_decode( (string) ( $r['sources'] ?? '' ), true ) ?: array(),
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
