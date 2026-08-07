<?php
/**
 * FlaggedIps against real WordPress + MySQL: dbDelta creates the store (with its unique
 * (ckey, ip) key), records dedupe and count hits, for_keys is client-scoped, and prune /
 * clear actually empty it — the DB behaviour the unit suite (no MySQL) can't verify.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\FlaggedIps;

final class FlaggedIpsDbTest extends DbTestCase {

	/** A guaranteed-fresh install of the store. */
	private function fresh(): void {
		global $wpdb;
		$table = FlaggedIps::name();
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
		delete_option( FlaggedIps::VERSION_OPTION );
		FlaggedIps::install();
	}

	public function test_dbdelta_creates_the_table_with_the_unique_client_ip_key() {
		$this->fresh();
		global $wpdb;
		$table = FlaggedIps::name();

		$this->assertSame(
			$table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
			'dbDelta must create the flagged-IP table'
		);
		$idx = $wpdb->get_results( "SHOW INDEX FROM `$table` WHERE Key_name = 'ckey_ip'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertNotEmpty( $idx, 'the unique (ckey, ip) key must exist so repeats dedupe' );
		$this->assertSame( FlaggedIps::VERSION, get_option( FlaggedIps::VERSION_OPTION ) );
	}

	public function test_record_dedupes_by_client_and_ip_and_counts_hits() {
		$this->fresh();
		FlaggedIps::record( 'ua:abc', '203.0.113.9' );
		FlaggedIps::record( 'ua:abc', '203.0.113.9' ); // same IP again → one row, hits=2
		FlaggedIps::record( 'ua:abc', '203.0.113.10' );

		$map = FlaggedIps::for_keys( array( 'ua:abc' ) );
		$this->assertArrayHasKey( 'ua:abc', $map );
		$this->assertCount( 2, $map['ua:abc'], 'two DISTINCT IPs, not three rows' );

		$hits = array();
		foreach ( $map['ua:abc'] as $r ) {
			$hits[ $r['ip'] ] = $r['hits'];
		}
		$this->assertSame( 2, $hits['203.0.113.9'] );
		$this->assertSame( 1, $hits['203.0.113.10'] );
	}

	public function test_for_keys_is_scoped_to_the_requested_clients() {
		$this->fresh();
		FlaggedIps::record( 'tok:evilbot', '198.51.100.1' );
		FlaggedIps::record( 'ua:other', '198.51.100.2' );

		$map = FlaggedIps::for_keys( array( 'tok:evilbot' ) );
		$this->assertArrayHasKey( 'tok:evilbot', $map );
		$this->assertArrayNotHasKey( 'ua:other', $map, 'another client’s IPs must not leak in' );
	}

	public function test_prune_drops_old_ips_and_clear_empties_the_store() {
		$this->fresh();
		global $wpdb;
		$table = FlaggedIps::name();

		$old = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ); // beyond any retention
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "INSERT INTO `$table` (ckey, ip, hits, first_at, last_at) VALUES (%s, %s, 1, %s, %s)", 'ua:old', '203.0.113.1', $old, $old )
		);
		FlaggedIps::record( 'ua:new', '203.0.113.2' ); // fresh

		FlaggedIps::prune();
		$this->assertEmpty( FlaggedIps::for_keys( array( 'ua:old' ) ), 'the old IP is pruned' );
		$this->assertNotEmpty( FlaggedIps::for_keys( array( 'ua:new' ) ), 'the fresh IP is kept' );

		FlaggedIps::clear();
		$this->assertEmpty( FlaggedIps::for_keys( array( 'ua:new' ) ), 'clear empties the store' );
	}

	public function test_the_per_client_cap_survives_ips_recorded_in_the_same_second() {
		// The cap was enforced with `DELETE ... WHERE last_at < cutoff`, and last_at has one-second
		// granularity — so a client presenting more than PER_CLIENT_MAX addresses inside a single
		// second had every row tie with the cutoff, matched nothing, and blew straight past the cap.
		// A distributed scan hitting from many IPs at once is exactly the traffic that gets flagged,
		// so the cap failed precisely when it mattered. Writing these in one loop guarantees the
		// shared timestamp that reproduces it.
		$ckey = 'ua:' . md5( 'flood-scanner' );
		$over = FlaggedIps::PER_CLIENT_MAX + 15;
		for ( $i = 0; $i < $over; $i++ ) {
			FlaggedIps::record( $ckey, '203.0.113.' . $i );
		}

		// cap_client() is private and normally fires opportunistically (~1 in 8 inserts), which would
		// make this test flaky. Reflection calls it deterministically rather than adding a
		// test-only seam to production code.
		$cap = new \ReflectionMethod( FlaggedIps::class, 'cap_client' );
		\_af_accessible( $cap );
		$cap->invoke( null, $ckey );

		global $wpdb;
		$table = FlaggedIps::name();
		$kept  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE ckey = %s", $ckey ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( FlaggedIps::PER_CLIENT_MAX, $kept, 'The per-client IP cap must hold even when every row shares one timestamp.' );
	}
}
