<?php
/**
 * The Google Search Console data source: service-account key parsing, the
 * RS256 JWT (signed with a real throwaway keypair and verified back), the
 * API client's response normalization, property matching, and the settings
 * store's encryption boundary.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Google\Auth;
use Agentimus\Google\Client;
use Agentimus\Google\Module;
use Agentimus\Google\Settings;
use PHPUnit\Framework\TestCase;

final class GoogleSourceTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_http_queue']   = array();
		$GLOBALS['_af_transients']   = array();
		$GLOBALS['_af_transients_on'] = true;
	}

	protected function tearDown(): void {
		\_af_reset_options();
		$GLOBALS['_af_http_queue'] = array();
		unset( $GLOBALS['_af_transients_on'], $GLOBALS['_af_http_last'] );
	}

	/* -- key parsing -------------------------------------------------------- */

	public function test_parse_key_rejects_non_service_account_files() {
		$this->assertArrayHasKey( 'error', Auth::parse_key( 'not json' ) );
		$this->assertArrayHasKey( 'error', Auth::parse_key( '{"type":"authorized_user"}' ), 'OAuth client files are the classic wrong paste' );
		$this->assertArrayHasKey( 'error', Auth::parse_key( '{"type":"service_account"}' ), 'missing email/key' );
	}

	public function test_parse_key_accepts_a_real_shape() {
		$out = Auth::parse_key( (string) wp_json_encode( array(
			'type'         => 'service_account',
			'client_email' => 'agentimus@project.iam.gserviceaccount.com',
			'private_key'  => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n",
		) ) );
		$this->assertSame( 'agentimus@project.iam.gserviceaccount.com', $out['email'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $out['token_uri'], 'token_uri defaults to Google\'s endpoint' );
	}

	/* -- the JWT ------------------------------------------------------------ */

	// A throwaway RSA keypair generated once for this test — never used anywhere
	// real. Embedded because PHP's openssl_pkey_new() needs an openssl.cnf many
	// CLI builds don't ship; signing and verifying need no config at all.
	private const TEST_PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDU19XaG9jPoGWM
oQht00WXFb2xhX/g4ZCwBl6n9SZ46zKRwpxg4sEiqiNWLYHKWyS1wC7XWCA7pV6V
REi/G/+ZIdX68F+m9ImyPtdyAwFFUICTW6oh2iWwb/RiTQnU76nvi8lvGN9jXiqQ
uhfkyMij/2AiHZPEg1kBTmtiIzS4SPCEHS/NjfVhR5JcWsZ9SFjtaF2PFU++8sfi
Gv2dKrzDJjTYZTE2PRDiMIb34lSSx2FhUAV3svutApOBvs2gkDvXkLJDWOw+oJzk
RtmQeDWVtaGWkiBnfQ4kXRVcTd8p8FrIwaYSJND07T/hrrmoVymCw9WVvVPnTyLC
l0qdPBt5AgMBAAECggEAAvUiKkRgyuHLshyQEBaeIkSnEiRswSSL7NDp185MQ+SL
cK288TZgFf855nn+EmBvLidcKe82OMife9YrEfIYUc8VOMYbo3zvvOOqqBGVSZqe
GW4YpcfGO6Fn9UW6G+oIo89+yRBrajy7aATDTZE3WIVf7JlvSDfFsNhKHbRSMzf/
F42PyVttoyX6wfHoGdE7meHph4TxTPrNuYm7e8IBtvuEAr16lHcUZ2JJVoET0OTV
daJvMb4vYFj6hkXcIE6CLY5NRru1IB/CLlVmt4A3RSZEw/wP4vhiNL1VhD1p0vFR
ZdvsxqbgtbmqR3IfcSR5ibCw4TvqYGH9hGw3Dqf6AQKBgQDv/dT+fbwpz8YuQKeL
G0dNq4wbium0VmQvJaz5vrORLrImS9U0ChlZUYTlgOIR1wFKLzgwyRxcc42AR5fB
5iMVyI/CXTVnNPjZ4DMFED6VdPUcl/tLWYCnwo2w4Sp45rcRbkyoomS0eEqaLkKM
OZmzRPjekPItR8y2+rhIQyA9kQKBgQDjCmic0DpG6JIYyo2My7POyR5kGNcK8DYh
EspOyc52o9mKWqr6wHmuQHP0c7LFXYMWIIGrxvBDbpTgdmPXZPohiu7mPNHZazvq
iIFbLnCxvD5LY46fWl9lURKZoytwqEpusQrs+Ef5dZiGp1DA8TMmi+C7QapGxgMy
4T8byDOraQKBgQDDk3ggbRcKe+hliQsAshpJkaN8Tphl/oFmaq2sWVy80/EahHIb
Fp/Ryj0jSwTwxOaoLhL8rugN751BDRb/TS0Kc4e0PYFnuiOSasMpPTPDWKznwHNp
1GakUEhn/Rc/r8VAz0Jpqu2mpOEnBMv2unonPe4ScszpWbna5DeJrCp6AQKBgFCA
8lMYKnHWKWeM+t//osQh4BrSC/4e6rKTfRSuzfyXYJ2ERLgg44R76iG1nKAK5l5E
LHaCVdMzNKraj9BiR6b5IniU/DvBoD8rI+L29pKvEs+cf4fVfZnpQ1Ui3FZX9fyF
3j/kUXEM14Z3cVTYsbBrTMZZJE1wDNZPtBbfyCJxAoGAbjVroenz1T1A3vk8F/hQ
8wc/5owOFrhETTkTQhbYPUIwgtYSsvK1LzCMFcqRugZqB7wQEce7nVpWhx3+4w/1
pvU1Kh7NAw4t900FqdOrfNbfEYXPxVLNJZ2fxErVtYnnl8Bl2y/njNoYV3TCm7vy
iJSi3w3PGKyQcySDYmMp9VY=
-----END PRIVATE KEY-----
PEM;

	private const TEST_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA1NfV2hvYz6BljKEIbdNF
lxW9sYV/4OGQsAZep/UmeOsykcKcYOLBIqojVi2BylsktcAu11ggO6VelURIvxv/
mSHV+vBfpvSJsj7XcgMBRVCAk1uqIdolsG/0Yk0J1O+p74vJbxjfY14qkLoX5MjI
o/9gIh2TxINZAU5rYiM0uEjwhB0vzY31YUeSXFrGfUhY7WhdjxVPvvLH4hr9nSq8
wyY02GUxNj0Q4jCG9+JUksdhYVAFd7L7rQKTgb7NoJA715CyQ1jsPqCc5EbZkHg1
lbWhlpIgZ30OJF0VXE3fKfBayMGmEiTQ9O0/4a65qFcpgsPVlb1T508iwpdKnTwb
eQIDAQAB
-----END PUBLIC KEY-----
PEM;

	public function test_jwt_signs_verifiably_and_claims_the_readonly_scope() {
		$pem    = self::TEST_PRIVATE_KEY;
		$public = self::TEST_PUBLIC_KEY;

		$jwt = Auth::jwt( 'sa@test.iam', $pem, 'https://oauth2.googleapis.com/token' );
		$this->assertNotSame( '', $jwt );

		list( $header, $claims, $signature ) = explode( '.', $jwt );
		$b64d = static function ( $s ) {
			return base64_decode( strtr( $s, '-_', '+/' ) );
		};

		$this->assertSame( 1, openssl_verify( "$header.$claims", $b64d( $signature ), $public, OPENSSL_ALGO_SHA256 ), 'the signature must verify against the keypair' );

		$decoded = json_decode( $b64d( $claims ), true );
		$this->assertSame( 'sa@test.iam', $decoded['iss'] );
		$this->assertSame( Auth::SCOPE, $decoded['scope'], 'only ever the read-only scope' );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $decoded['aud'] );
	}

	public function test_a_garbage_private_key_fails_closed_not_fatally() {
		$this->assertSame( '', Auth::jwt( 'sa@test.iam', 'not a pem', 'https://x' ) );
	}

	/* -- client normalization ----------------------------------------------- */

	public function test_search_analytics_rows_normalize() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => array(
				array( 'keys' => array( 'https://example.test/a/', 'query one' ), 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.035, 'position' => 9.42 ),
				array( 'keys' => array( 'https://example.test/a/' ), 'clicks' => 1 ), // malformed: one key — dropped
			) ) ),
		);
		$out = ( new Client() )->search_analytics( 'tok', 'sc-domain:example.test', '2026-06-01', '2026-07-27' );
		$this->assertCount( 1, $out['rows'] );
		$this->assertSame( 'query one', $out['rows'][0]['query'] );
		$this->assertSame( 9.42, $out['rows'][0]['position'] );
		// The request itself carried the auth header and both dimensions.
		$this->assertStringContainsString( 'Bearer tok', $GLOBALS['_af_http_last']['args']['headers']['Authorization'] );
		$this->assertStringContainsString( '"dimensions":["page","query"]', (string) $GLOBALS['_af_http_last']['args']['body'] );
	}

	/**
	 * One short answer must end the walk. Nearly every site lands here, and a
	 * second request against a window Google has already finished reporting is
	 * quota spent on nothing.
	 */
	public function test_search_analytics_stops_after_a_short_page() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => array(
				array( 'keys' => array( 'https://example.test/a/', 'q' ), 'clicks' => 3, 'impressions' => 9, 'position' => 4.0 ),
			) ) ),
		);
		$out = ( new Client() )->search_analytics( 'tok', 'sc-domain:example.test', '2026-06-01', '2026-07-27' );

		$this->assertCount( 1, $out['rows'] );
		$this->assertSame( array(), $GLOBALS['_af_http_queue'], 'a short page must not trigger a second request' );
		$this->assertStringContainsString( '"startRow":0', (string) $GLOBALS['_af_http_last']['args']['body'] );
	}

	/**
	 * A FULL page means Google is holding more, and the next request must ask
	 * from where the last one stopped. This is the whole point of the change:
	 * rows arrive clicks-descending, so everything past the first page is the
	 * quiet end of the site — the pages the worklist exists to find.
	 */
	public function test_search_analytics_pages_until_the_rows_run_out() {
		$full = array();
		for ( $i = 0; $i < Client::ROW_LIMIT; $i++ ) {
			$full[] = array( 'keys' => array( 'https://example.test/p' . $i . '/', 'q' . $i ), 'clicks' => 1, 'impressions' => 2, 'position' => 5.0 );
		}
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => (string) wp_json_encode( array( 'rows' => $full ) ) );
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => array(
				array( 'keys' => array( 'https://example.test/tail/', 'the quiet one' ), 'clicks' => 1, 'impressions' => 4, 'position' => 30.0 ),
			) ) ),
		);
		$out = ( new Client() )->search_analytics( 'tok', 'sc-domain:example.test', '2026-06-01', '2026-07-27' );

		$this->assertCount( Client::ROW_LIMIT + 1, $out['rows'] );
		$this->assertSame( 'the quiet one', $out['rows'][ Client::ROW_LIMIT ]['query'], 'the second page has to be appended, not replace the first' );
		$this->assertStringContainsString( '"startRow":' . Client::ROW_LIMIT, (string) $GLOBALS['_af_http_last']['args']['body'] );
	}

	/**
	 * A blip on page two keeps page one. A partial window is still true about
	 * the rows it holds, and discarding it would replace a real snapshot with
	 * nothing over a transport hiccup.
	 */
	public function test_search_analytics_keeps_what_it_already_has_when_a_later_page_fails() {
		$full = array();
		for ( $i = 0; $i < Client::ROW_LIMIT; $i++ ) {
			$full[] = array( 'keys' => array( 'https://example.test/p' . $i . '/', 'q' . $i ), 'clicks' => 1, 'impressions' => 2, 'position' => 5.0 );
		}
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => (string) wp_json_encode( array( 'rows' => $full ) ) );
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 500 ), 'body' => '{"error":{"message":"Backend error"}}' );
		$out = ( new Client() )->search_analytics( 'tok', 'sc-domain:example.test', '2026-06-01', '2026-07-27' );

		$this->assertArrayNotHasKey( 'error', $out );
		$this->assertCount( Client::ROW_LIMIT, $out['rows'] );
	}

	/** But a failure on the FIRST page is a real failure — there is nothing to keep. */
	public function test_search_analytics_reports_a_first_page_failure() {
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 403 ), 'body' => '{"error":{"message":"User does not have sufficient permission"}}' );
		$out = ( new Client() )->search_analytics( 'tok', 'sc-domain:example.test', '2026-06-01', '2026-07-27' );

		$this->assertSame( 'User does not have sufficient permission', $out['error'] );
		$this->assertArrayNotHasKey( 'rows', $out );
	}

	public function test_api_errors_surface_googles_words() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 403 ),
			'body'     => '{"error":{"message":"User does not have sufficient permission"}}',
		);
		$out = ( new Client() )->sites( 'tok' );
		$this->assertSame( 'User does not have sufficient permission', $out['error'] );
	}

	/* -- property matching --------------------------------------------------- */

	public function test_property_matching_prefers_the_exact_origin() {
		// home_url in the unit env is https://example.test/
		$property = Module::match_property( array(
			array( 'property' => 'sc-domain:example.test', 'permission' => 'siteOwner' ),
			array( 'property' => 'https://example.test/', 'permission' => 'siteFullUser' ),
		) );
		$this->assertSame( 'https://example.test/', $property, 'the exact origin claim beats the domain property' );
	}

	public function test_property_matching_falls_back_to_the_domain_property() {
		$this->assertSame( 'sc-domain:example.test', Module::match_property( array(
			array( 'property' => 'https://other.example/', 'permission' => 'siteOwner' ),
			array( 'property' => 'sc-domain:example.test', 'permission' => 'siteRestrictedUser' ),
		) ) );
	}

	public function test_unverified_permission_never_matches() {
		$this->assertSame( '', Module::match_property( array(
			array( 'property' => 'https://example.test/', 'permission' => 'siteUnverifiedUser' ),
		) ) );
	}

	/* -- the settings store's encryption boundary ----------------------------- */

	public function test_key_is_stored_encrypted_and_never_in_public_view() {
		$settings = new Settings();
		$settings->connect( '{"type":"service_account"}', 'sa@p.iam.gserviceaccount.com', 'sc-domain:example.test' );

		$raw = get_option( Settings::OPTION );
		$this->assertNotSame( '{"type":"service_account"}', $raw['sa_json'], 'the key must not rest in plaintext' );
		$this->assertSame( '{"type":"service_account"}', $settings->sa_json(), 'and must decrypt back' );

		$view = $settings->public_view();
		$this->assertArrayNotHasKey( 'sa_json', $view );
		$this->assertSame( 'sa@p.iam.gserviceaccount.com', $view['saEmail'], 'the email IS shown — granting it access is the setup step' );
		$this->assertTrue( $view['connected'] );
	}

	public function test_disconnect_forgets_everything() {
		$settings = new Settings();
		$settings->connect( '{"type":"service_account"}', 'sa@p.iam', 'sc-domain:example.test' );
		$settings->disconnect();
		$this->assertFalse( $settings->connected() );
		$this->assertSame( '', $settings->sa_json() );
	}

	/* -- the Analytics connect ---------------------------------------------- */

	/**
	 * Connecting Analytics must fill the Readers screen NOW, not tomorrow —
	 * the key connect and the Bing connect both poll inline, and this route
	 * once forgot to (found the day the flow first ran against real Google:
	 * a clean verify, then an empty screen until the next daily cron).
	 */
	public function test_connecting_analytics_polls_inline_instead_of_waiting_for_cron() {
		$sa_json = (string) wp_json_encode( array(
			'type'         => 'service_account',
			'client_email' => 'agentimus@project.iam.gserviceaccount.com',
			'private_key'  => self::TEST_PRIVATE_KEY,
		) );
		$settings = new Settings();
		$settings->connect( $sa_json, 'agentimus@project.iam.gserviceaccount.com', 'sc-domain:example.test' );

		// The token mint, then the verify report. Everything the inline poll
		// asks after that meets the queue's default empty answer — zero rows,
		// which is fine: what this test pins is that the poll RAN.
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"access_token":"tok-analytics","expires_in":3600}',
		);
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => array(
				array( 'metricValues' => array(
					array( 'value' => '10' ), array( 'value' => '5' ), array( 'value' => '12' ),
					array( 'value' => '20' ), array( 'value' => '8' ), array( 'value' => '60' ),
				) ),
			) ) ),
		);

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/google/analytics' );
		$request->set_param( 'property', '382790047' );
		$out = ( new \Agentimus\Google\Rest( $settings, new Client() ) )->connect_analytics( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $out, 'the connect must verify cleanly' );
		$this->assertTrue( $settings->analytics_connected() );

		$snap = get_option( Module::GA4_OPTION );
		$this->assertIsArray( $snap, 'the GA4 snapshot must exist the moment connect returns' );
		$this->assertGreaterThan( 0, (int) $snap['fetched'], 'stamped by the inline poll, not left for the cron' );
	}
}
