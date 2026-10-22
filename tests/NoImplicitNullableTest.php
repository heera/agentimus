<?php
/**
 * PHP 8.4 deprecates a typed parameter that defaults to null without saying
 * it is nullable (`Client $c = null`); every file holding one logs a notice
 * as it loads. `?Client $c = null` means the same and works on PHP 7.4.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use PHPUnit\Framework\TestCase;

final class NoImplicitNullableTest extends TestCase {

	public function test_no_typed_parameter_defaults_to_null_without_being_nullable() {
		$root  = dirname( __DIR__ ) . '/inc';
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( file( $file->getPathname() ) as $n => $line ) {
				// A type with no leading `?`, then $name = null.
				if ( preg_match( '/[(,]\s*(?<!\?)(array|callable|[A-Z][A-Za-z0-9_\\\\]*)\s+\$\w+\s*=\s*null\b/', $line ) ) {
					$found[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . ( $n + 1 );
				}
			}
		}
		$this->assertSame( array(), $found );
	}
}
