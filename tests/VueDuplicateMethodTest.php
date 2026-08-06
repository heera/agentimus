<?php
/**
 * Duplicate keys inside ONE Vue options object (methods, computed).
 *
 * JavaScript does not consider this an error. The later definition silently
 * replaces the earlier one, and whatever owned the name first simply stops
 * working — no console warning, no build failure, no test failing anywhere
 * near it.
 *
 * It cost us once already: a row-level `checkNow(url)` was added to the Google
 * index panel and quietly replaced the card's whole-watchlist `checkNow()`
 * sweep. The sweep's button binds `@click="checkNow"` with no parentheses, so
 * Vue passed the click EVENT as the url — and the card's main button answered
 * "Invalid parameter(s): url" instead of sweeping. Nothing pointed at the
 * collision; the visible symptom was a REST validation error.
 *
 * Scoped to one block at a time on purpose. A `watch:` entry sharing a name
 * with the `computed:` it watches is correct, idiomatic Vue — a scan that
 * flagged that would be noise, and noise gets deleted.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use PHPUnit\Framework\TestCase;

final class VueDuplicateMethodTest extends TestCase {

	/** @return array<string,array{0:string}> */
	public function componentProvider(): array {
		$root = dirname( __DIR__ ) . '/resources/admin';
		$out  = array();
		foreach ( array_merge( (array) glob( $root . '/*.vue' ), (array) glob( $root . '/components/*.vue' ) ) as $file ) {
			if ( is_string( $file ) ) {
				$out[ basename( $file ) ] = array( $file );
			}
		}
		return $out;
	}

	/**
	 * One options block's source, by the house formatting: opened at two-space
	 * indent, closed by the first `  },` at that same indent.
	 */
	private function block( string $src, string $name ): string {
		if ( ! preg_match( '/^  ' . preg_quote( $name, '/' ) . ':\s*\{$/m', $src, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$start = (int) $m[0][1];
		$end   = preg_match( '/^  \},?$/m', $src, $e, PREG_OFFSET_CAPTURE, $start ) ? (int) $e[0][1] : strlen( $src );
		return substr( $src, $start, $end - $start );
	}

	/**
	 * @dataProvider componentProvider
	 * @param string $file Component path.
	 */
	public function test_no_key_is_defined_twice_in_one_options_block( string $file ): void {
		$src = (string) file_get_contents( $file );
		// A component with neither block still proves the provider handed us a
		// real file — otherwise a broken glob would pass as silence.
		$this->assertNotSame( '', $src, basename( $file ) . ' is empty or unreadable.' );

		foreach ( array( 'methods', 'computed' ) as $option ) {
			$block = $this->block( $src, $option );
			if ( '' === $block ) {
				continue;
			}

			// Members sit at exactly four spaces; anything inside one of them is
			// deeper, so control-flow keywords can never reach this indent here.
			preg_match_all( '/^    (?:async )?([A-Za-z_$][\w$]*)\s*\(/m', $block, $m );

			$dupes = array_keys(
				array_filter(
					array_count_values( $m[1] ),
					static function ( $n ) {
						return $n > 1;
					}
				)
			);

			$this->assertSame(
				array(),
				$dupes,
				basename( $file ) . " defines " . implode( ', ', $dupes ) . " twice in {$option}. "
					. 'JS keeps the LAST one and drops the rest silently — rename, never rely on order.'
			);
		}
	}
}
