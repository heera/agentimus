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

	/**
	 * Persist one (prompt × provider) check.
	 *
	 * @param array $row {
	 *     @type int    $run_id
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

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Table::name(),
			array(
				'run_id'        => (int) ( $row['run_id'] ?? 0 ),
				'checked_at'    => current_time( 'mysql' ),
				'provider'      => substr( (string) ( $row['provider'] ?? '' ), 0, 32 ),
				'model'         => substr( (string) ( $row['model'] ?? '' ), 0, 96 ),
				'prompt_hash'   => md5( (string) ( $row['prompt'] ?? '' ) ),
				'prompt'        => (string) ( $row['prompt'] ?? '' ),
				'mentioned'     => empty( $row['mentioned'] ) ? 0 : 1,
				'cited'         => empty( $row['cited'] ) ? 0 : 1,
				'position'      => (int) ( $row['position'] ?? 0 ),
				'competitors'   => wp_json_encode( array_values( (array) ( $row['competitors'] ?? array() ) ) ),
				'answer_excerpt' => $answer,
				'error'         => substr( (string) ( $row['error'] ?? '' ), 0, 191 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
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
		return (int) $wpdb->get_var( "SELECT MAX(run_id) FROM $table" ); // phpcs:ignore WordPress.DB
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
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT run_id FROM $table ORDER BY run_id DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB
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
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE run_id = %d ORDER BY id ASC", (int) $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
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
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE checked_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Wipe all results (used by uninstall / a manual reset).
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Assemble the full dashboard payload: the latest run scored and broken down
	 * by prompt/provider, share-of-voice against competitors, and a visibility
	 * trend across recent runs.
	 *
	 * @param Settings $settings Pro settings (for brand/competitor labels).
	 * @return array
	 */
	public static function dashboard( Settings $settings ) {
		$brand       = (string) $settings->get( 'brand', '' );
		$competitors = (array) $settings->get( 'competitors', array() );

		$latest_id = self::latest_run_id();
		$latest    = $latest_id ? self::rows_for_run( $latest_id ) : array();

		$summary = self::summarize( $latest );

		// Per-prompt breakdown: for each prompt, the per-provider result.
		$prompts = array();
		foreach ( $latest as $r ) {
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
				'error'       => $r['error'],
			);
		}

		// Share of voice: brand mentions vs each competitor's mentions in the run.
		$brand_hits = 0;
		$comp_hits  = array();
		foreach ( $competitors as $c ) {
			$comp_hits[ $c ] = 0;
		}
		foreach ( $latest as $r ) {
			if ( ! empty( $r['mentioned'] ) ) {
				$brand_hits++;
			}
			$found = json_decode( (string) $r['competitors'], true );
			if ( is_array( $found ) ) {
				foreach ( $found as $c ) {
					// Count configured competitors (so zero-mention ones still show)
					// and any other competitor a result actually named.
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
				'name'     => '' !== $brand ? $brand : __( 'Your site', 'agentimus' ),
				'mentions' => $brand_hits,
				'isBrand'  => true,
				'share'    => $total_voice > 0 ? (int) round( $brand_hits / $total_voice * 100 ) : 0,
			),
		);
		foreach ( $comp_hits as $name => $hits ) {
			$voice[] = array(
				'name'     => $name,
				'mentions' => $hits,
				'isBrand'  => false,
				'share'    => $total_voice > 0 ? (int) round( $hits / $total_voice * 100 ) : 0,
			);
		}

		// Trend: visibility score for each recent run, oldest → newest.
		$trend = array();
		foreach ( array_reverse( self::recent_run_ids( 12 ) ) as $rid ) {
			$s       = self::summarize( self::rows_for_run( $rid ) );
			$trend[] = array(
				'runId' => $rid,
				'at'    => $rid ? gmdate( 'c', $rid ) : '',
				'score' => $s['visibilityScore'],
			);
		}

		return array(
			'hasData'       => $latest_id > 0,
			'lastRunAt'     => $latest_id ? gmdate( 'c', $latest_id ) : '',
			'summary'       => $summary,
			'prompts'       => array_values( $prompts ),
			'shareOfVoice'  => $voice,
			'trend'         => $trend,
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
