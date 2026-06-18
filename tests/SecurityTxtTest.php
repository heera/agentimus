<?php
/**
 * SecurityTxt — the opt-in, gap-filling /.well-known/security.txt generator (RFC 9116).
 *
 * Locks the three behaviours that make it a good /.well-known citizen:
 *   - the precedence guards (registers ONLY when enabled, contactful, and not
 *     shadowed by a real on-disk file),
 *   - Contact normalisation (email→mailto, explicit scheme required, deduped), and
 *   - the Expires clamp (RFC 9116: keep it under a year).
 * Plus a smoke test of the emitted document.
 *
 * @package HeeraAgentDiscovery\Tests
 */

namespace HeeraAgentDiscovery\Tests;

use HeeraAgentDiscovery\Discovery\Registry;
use HeeraAgentDiscovery\Discovery\SecurityTxt;
use HeeraAgentDiscovery\Settings;
use PHPUnit\Framework\TestCase;

final class SecurityTxtTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		\_af_reset_registry();
		$this->remove_real_file();
	}

	protected function tearDown(): void {
		$this->remove_real_file();
	}

	/* -- Helpers ---------------------------------------------------------- */

	/** Persist a settings array and return a fresh generator over it. */
	private function generator( array $settings = array() ): SecurityTxt {
		update_option( Settings::OPTION, $settings );
		return new SecurityTxt( new Settings() );
	}

	/** Settings with the feature on and one identity contact email. */
	private function enabled_with_contact( array $extra = array() ): array {
		return array_merge(
			array(
				'enable_security_txt' => true,
				'identity'            => array( 'contact_email' => 'security@example.com' ),
			),
			$extra
		);
	}

	private function real_file_path(): string {
		return ABSPATH . '.well-known/' . SecurityTxt::NAME;
	}

	private function write_real_file(): void {
		$dir = ABSPATH . '.well-known';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $this->real_file_path(), "Contact: mailto:real@example.com\n" );
	}

	private function remove_real_file(): void {
		if ( is_file( $this->real_file_path() ) ) {
			unlink( $this->real_file_path() );
		}
		if ( is_dir( ABSPATH . '.well-known' ) ) {
			@rmdir( ABSPATH . '.well-known' );
		}
	}

	/** Days between now and an ISO-8601 Expires value. */
	private function days_until( string $expires ): float {
		return ( strtotime( $expires ) - time() ) / DAY_IN_SECONDS;
	}

	/* -- Precedence guards (provide) -------------------------------------- */

	public function test_registers_when_enabled_with_a_contact() {
		$registry = Registry::instance();
		$this->generator( $this->enabled_with_contact() )->provide( $registry );
		$this->assertArrayHasKey( SecurityTxt::NAME, $registry->well_known() );
	}

	public function test_skips_when_disabled_by_default() {
		$registry = Registry::instance();
		$this->generator()->provide( $registry ); // default settings: feature off
		$this->assertArrayNotHasKey( SecurityTxt::NAME, $registry->well_known() );
	}

	public function test_skips_when_enabled_but_no_contact() {
		$registry = Registry::instance();
		$this->generator( array( 'enable_security_txt' => true ) )->provide( $registry );
		$this->assertArrayNotHasKey( SecurityTxt::NAME, $registry->well_known() );
	}

	public function test_skips_when_a_real_file_is_on_disk() {
		$this->write_real_file();
		$registry = Registry::instance();
		$this->generator( $this->enabled_with_contact() )->provide( $registry );
		$this->assertArrayNotHasKey(
			SecurityTxt::NAME,
			$registry->well_known(),
			'A real /.well-known/security.txt must win; the generator must not register.'
		);
	}

	/* -- Contact normalisation (contacts) --------------------------------- */

	public function test_identity_email_seeds_the_first_contact_as_mailto() {
		$contacts = $this->generator( $this->enabled_with_contact() )->contacts();
		$this->assertSame( array( 'mailto:security@example.com' ), $contacts );
	}

	public function test_contacts_normalise_reject_and_dedupe() {
		$contacts = $this->generator(
			$this->enabled_with_contact(
				array(
					'security' => array(
						'contacts' => array(
							'https://example.com/report',
							'tel:+15550100',
							'not-a-uri',              // no scheme → rejected
							'security@example.com',   // duplicate of the identity email → deduped
						),
					),
				)
			)
		)->contacts();

		$this->assertSame(
			array( 'mailto:security@example.com', 'https://example.com/report', 'tel:+15550100' ),
			$contacts
		);
	}

	/* -- Expires clamp (expires) ------------------------------------------ */

	public function test_expires_is_iso8601_utc_and_in_the_future() {
		$expires = $this->generator( $this->enabled_with_contact() )->expires();
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $expires );
		$this->assertGreaterThan( time(), strtotime( $expires ) );
	}

	public function test_expires_clamps_above_one_year() {
		$expires = $this->generator( $this->enabled_with_contact( array( 'security' => array( 'expires_days' => 9999 ) ) ) )->expires();
		$days    = $this->days_until( $expires );
		$this->assertGreaterThan( 364, $days );
		$this->assertLessThan( 366, $days );
	}

	public function test_expires_clamps_below_one_day() {
		$expires = $this->generator( $this->enabled_with_contact( array( 'security' => array( 'expires_days' => 0 ) ) ) )->expires();
		$days    = $this->days_until( $expires );
		$this->assertGreaterThan( 0.9, $days );
		$this->assertLessThan( 1.1, $days );
	}

	/* -- Document smoke test (body) --------------------------------------- */

	public function test_body_has_the_required_fields_and_canonical() {
		$body = $this->generator( $this->enabled_with_contact() )->body();
		$this->assertStringContainsString( 'Contact: mailto:security@example.com', $body );
		$this->assertStringContainsString( 'Expires: ', $body );
		$this->assertStringContainsString( 'Canonical: https://example.test/.well-known/security.txt', $body );
		// Falls back to the site locale (en-US → en) when no language is set.
		$this->assertStringContainsString( 'Preferred-Languages: en', $body );
	}

	public function test_body_emits_optional_urls_only_when_set() {
		$without = $this->generator( $this->enabled_with_contact() )->body();
		$this->assertStringNotContainsString( 'Policy:', $without );

		$with = $this->generator(
			$this->enabled_with_contact( array( 'security' => array( 'policy' => 'https://example.com/policy' ) ) )
		)->body();
		$this->assertStringContainsString( 'Policy: https://example.com/policy', $with );
	}
}
