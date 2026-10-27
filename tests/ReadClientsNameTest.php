<?php
/**
 * read-clients names a client by the owner's queue-card rule, so an agent and the
 * owner never call the same row two different things.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Abilities\Registrar;
use PHPUnit\Framework\TestCase;

final class ReadClientsNameTest extends TestCase {

	public function test_a_recognised_client_reads_its_catalog_name() {
		$this->assertSame( 'ShapBot', Registrar::client_name( array( 'known' => array( 'name' => 'ShapBot' ), 'guide' => null, 'agent' => 'Other bot' ) ) );
	}

	/** heera.it 10-27: the screen said INITE-AtlasSweep, the tool said "Browser". */
	public function test_an_unrecognised_client_reads_the_name_it_declares() {
		$row = array(
			'known' => null,
			'guide' => array( 'name' => 'INITE-AtlasSweep', 'url' => 'https://inite.ai', 'host' => 'inite.ai', 'lookup' => '' ),
			'agent' => 'Browser',
		);
		$this->assertSame( 'INITE-AtlasSweep', Registrar::client_name( $row ) );
	}

	public function test_with_no_name_anywhere_the_log_label_stands() {
		$this->assertSame( 'Unrecognized', Registrar::client_name( array( 'known' => null, 'guide' => array( 'name' => '' ), 'agent' => 'Unrecognized' ) ) );
	}
}
