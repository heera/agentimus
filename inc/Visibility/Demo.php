<?php
/**
 * Sample-data seeder — populates the results table with a few realistic runs so
 * the Dashboard can be viewed (and screenshotted) fully working before any real
 * API key is connected. Deterministic, so the demo looks the same each time and
 * shows a gently rising visibility trend.
 *
 * Sample data is flagged (see FLAG) so the UI can warn it isn't real; the flag is
 * cleared by a real run, by clearing data, or by uninstall.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

use Agentimus\Visibility\Settings;

defined( 'ABSPATH' ) || exit;

final class Demo {

	/** @var string Option flagging the stored results as sample, not real. */
	const FLAG = 'agentimus_visibility_demo';

	/** @var int How many weekly runs to fabricate for the trend. */
	const RUNS = 4;

	/**
	 * Replace stored results with a fresh set of sample runs.
	 *
	 * @param Settings $settings Pro settings (brand/competitors/prompts seed it).
	 */
	public static function seed( Settings $settings ) {
		$brand = (string) $settings->get( 'brand', '' );
		if ( '' === $brand ) {
			$brand = __( 'Your brand', 'agentimus' );
		}

		$competitors = array_values( (array) $settings->get( 'competitors', array() ) );
		if ( empty( $competitors ) ) {
			$competitors = array( 'Yoast', 'Rank Math', 'SEOPress' );
		}

		$prompts = array_values( (array) $settings->get( 'prompts', array() ) );
		if ( empty( $prompts ) ) {
			$prompts = array(
				'best wordpress ai seo plugin',
				'who is ' . $brand,
				'how do I make my site readable by AI assistants',
			);
		}

		$providers = array();
		foreach ( Settings::catalog() as $id => $meta ) {
			$providers[ $id ] = $meta['model'];
		}

		Store::clear();

		$now = time();
		for ( $r = 0; $r < self::RUNS; $r++ ) {
			// Oldest run first; newest run lands at ~now (so it's the latest run).
			$run_id   = $now - ( ( self::RUNS - 1 - $r ) * 7 * DAY_IN_SECONDS );
			$strength = 0.32 + ( $r * 0.13 ); // Rises run over run → upward trend.

			foreach ( $prompts as $prompt ) {
				foreach ( $providers as $pid => $model ) {
					// Sample each (prompt × provider × run) independently on a fine
					// grid so runs differ smoothly rather than in coarse steps.
					$cell      = ( crc32( $prompt . '|' . $pid . '|' . $r ) & 0x7fffffff ) % 1000 / 1000;
					$mentioned = $cell < $strength;

					// Citation is a fraction of mentions — higher for web-grounded
					// engines that link sources — drawn independently so the citation
					// rate reads realistically rather than mirroring the mention flag.
					$grounded = ( 'perplexity' === $pid );
					$ccell    = ( crc32( 'c' . $prompt . $pid . $r ) & 0x7fffffff ) % 1000 / 1000;
					$cited    = $mentioned && $ccell < ( $grounded ? 0.72 : 0.40 );

					$found = array();
					foreach ( $competitors as $c ) {
						if ( 0 === ( ( crc32( $prompt . $pid . $c ) & 0x7fffffff ) % 3 ) ) {
							$found[] = $c;
						}
					}

					Store::insert(
						array(
							'run_id'      => $run_id,
							'provider'    => $pid,
							'model'       => $model,
							'prompt'      => $prompt,
							'mentioned'   => $mentioned,
							'cited'       => $cited,
							'position'    => $mentioned ? 1 + ( ( crc32( $pid ) & 0x7fffffff ) % 3 ) : 0,
							'competitors' => $found,
							'answer'      => self::excerpt( $brand, $mentioned, $cited, $found ),
							'error'       => '',
						)
					);
				}
			}
		}

		update_option( self::FLAG, 1, false );
		update_option( Runner::LAST_RUN_OPTION, $now, false );
	}

	/**
	 * Remove all results and the sample flag.
	 */
	public static function clear() {
		Store::clear();
		delete_option( self::FLAG );
		delete_option( Runner::LAST_RUN_OPTION );
	}

	/**
	 * Whether the stored results are sample data.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return (bool) get_option( self::FLAG );
	}

	/**
	 * A short, representative answer excerpt for a cell (shown on hover).
	 *
	 * @param string   $brand     Brand name.
	 * @param bool      $mentioned Whether the brand was "mentioned".
	 * @param bool      $cited     Whether it was "cited".
	 * @param string[]  $found     Competitors "present".
	 * @return string
	 */
	private static function excerpt( $brand, $mentioned, $cited, array $found ) {
		if ( $mentioned ) {
			return $cited
				? sprintf( __( '%s is recommended and linked as a source.', 'agentimus' ), $brand )
				: sprintf( __( '%s is mentioned among the options.', 'agentimus' ), $brand );
		}
		if ( ! empty( $found ) ) {
			return sprintf(
				/* translators: 1: competitor list, 2: brand name. */
				__( '%1$s are named; %2$s is not.', 'agentimus' ),
				implode( ', ', $found ),
				$brand
			);
		}
		return __( 'A general answer with no brand named.', 'agentimus' );
	}
}
