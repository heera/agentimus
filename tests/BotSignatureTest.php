<?php
/**
 * Web Bot Auth verifier — REAL Ed25519 round trips (sodium generates a keypair,
 * signs the exact base the verifier must rebuild, and the verdict has to come
 * out right), plus the fail-open doctrine on every edge: unsigned is never
 * penalized, anything murky is indeterminate, and only clean crypto — or
 * conclusively broken crypto — earns a hard verdict.
 *
 * The directory loader and the clock are injected; no test touches the network.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\BotSignature;
use PHPUnit\Framework\TestCase;

final class BotSignatureTest extends TestCase {

	const NOW    = 1753600000;
	const SIGNER = 'https://signer.example.com';

	protected function setUp(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'sodium unavailable' );
		}
		\_af_reset_options();
		$GLOBALS['_af_transients_on'] = true; // Nonce-replay + directory caching ride transients.
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/**
	 * A fully signed request + its signer directory, with override points.
	 *
	 * @param array $over Keys: created, expires, tag, components (array), nonce,
	 *                    host_for_base, agent_header, drop_agent, keyid.
	 * @return array{headers:array,loader:callable,secret:string}
	 */
	private function signed_request( array $over = array() ) {
		$keypair = sodium_crypto_sign_keypair();
		$secret  = sodium_crypto_sign_secretkey( $keypair );
		$public  = sodium_crypto_sign_publickey( $keypair );
		$x       = BotSignature::b64url_encode( $public );
		$kid     = isset( $over['keyid'] ) ? $over['keyid'] : BotSignature::jwk_thumbprint( $x );

		$created    = isset( $over['created'] ) ? $over['created'] : self::NOW - 30;
		$expires    = isset( $over['expires'] ) ? $over['expires'] : self::NOW + 300;
		$tag        = isset( $over['tag'] ) ? $over['tag'] : 'web-bot-auth';
		$components = isset( $over['components'] ) ? $over['components'] : array( '@authority', 'signature-agent' );
		$nonce      = isset( $over['nonce'] ) ? $over['nonce'] : 'n-' . uniqid();

		$inner      = implode( ' ', array_map( static function ( $c ) { return '"' . $c . '"'; }, $components ) );
		$params_raw = '(' . $inner . ');created=' . $created . ';expires=' . $expires
			. ';keyid="' . $kid . '";tag="' . $tag . '";nonce="' . $nonce . '"';

		$agent_header = isset( $over['agent_header'] ) ? $over['agent_header'] : 'sig="' . self::SIGNER . '"';
		$headers      = array(
			'host'            => 'example.com',
			'signature-agent' => $agent_header,
		);
		if ( ! empty( $over['drop_agent'] ) ) {
			unset( $headers['signature-agent'] );
		}

		// Build the base EXACTLY as a compliant signer would (which is also exactly
		// what the verifier must reproduce).
		$lines = array();
		foreach ( $components as $component ) {
			if ( '@authority' === $component ) {
				$lines[] = '"@authority": ' . ( isset( $over['host_for_base'] ) ? $over['host_for_base'] : 'example.com' );
			} else {
				$lines[] = '"' . $component . '": ' . $headers[ $component ];
			}
		}
		$lines[] = '"@signature-params": ' . $params_raw;
		$base    = implode( "\n", $lines );

		$sig = sodium_crypto_sign_detached( $base, $secret );

		$headers['signature-input'] = 'sig1=' . $params_raw;
		$headers['signature']       = 'sig1=:' . base64_encode( $sig ) . ':';

		$directory = array(
			'keys' => array(
				array(
					'kty' => 'OKP',
					'crv' => 'Ed25519',
					'kid' => BotSignature::jwk_thumbprint( $x ),
					'x'   => $x,
				),
			),
		);
		$loader    = static function ( $origin ) use ( $directory ) {
			return self::SIGNER === $origin ? $directory : null;
		};

		return array(
			'headers' => $headers,
			'loader'  => $loader,
			'secret'  => $secret,
		);
	}

	/* ------------------------------ the happy path ------------------------------ */

	public function test_a_genuinely_signed_request_verifies() {
		$req     = $this->signed_request();
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'verified', $verdict['state'] );
		$this->assertSame( self::SIGNER, $verdict['signer'], 'verification names WHO signed — identity, not just validity' );
	}

	public function test_google_style_dictionary_agent_header_verifies_too() {
		$req     = $this->signed_request( array( 'agent_header' => 'g="' . self::SIGNER . '"' ) );
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'verified', $verdict['state'] );
	}

	/* ------------------------------ conclusive failure ------------------------------ */

	public function test_a_tampered_request_fails_conclusively() {
		// Signed for example.com, replayed against a different authority — the
		// classic stolen-signature move. The math must catch it.
		$req            = $this->signed_request();
		$req['headers']['host'] = 'victim.example.net';
		$verdict        = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'failed', $verdict['state'] );
		$this->assertSame( self::SIGNER, $verdict['signer'], 'the forged claim still names the origin it claimed' );
	}

	public function test_garbage_signature_bytes_fail_conclusively() {
		$req                          = $this->signed_request();
		$req['headers']['signature']  = 'sig1=:' . base64_encode( str_repeat( 'x', 64 ) ) . ':';
		$verdict                      = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'failed', $verdict['state'] );
	}

	/* ------------------------------ unsigned is never punished ------------------------------ */

	public function test_no_signature_headers_is_plain_unsigned() {
		$verdict = BotSignature::inspect_from( array( 'host' => 'example.com' ), static function () { return null; }, self::NOW );

		$this->assertSame( 'unsigned', $verdict['state'] );
		$this->assertSame( '', $verdict['reason'] );
	}

	public function test_another_protocols_signature_is_not_our_business() {
		// A request signed for some OTHER message-signature use (different tag)
		// is not a Web Bot Auth claim — treating it as one would punish a bot
		// for speaking a protocol we don't understand.
		$req     = $this->signed_request( array( 'tag' => 'some-other-protocol' ) );
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'unsigned', $verdict['state'] );
	}

	/* ------------------------------ fail-open middle ground ------------------------------ */

	public function test_expired_signature_is_indeterminate_not_failed() {
		$req     = $this->signed_request( array( 'created' => self::NOW - 900, 'expires' => self::NOW - 600 ) );
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'indeterminate', $verdict['state'] );
		$this->assertStringContainsString( 'expired', $verdict['reason'] );
	}

	public function test_future_created_is_indeterminate() {
		$req     = $this->signed_request( array( 'created' => self::NOW + 900 ) );
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'indeterminate', $verdict['state'] );
	}

	public function test_validity_window_over_24h_is_indeterminate() {
		$req     = $this->signed_request( array( 'created' => self::NOW - 30, 'expires' => self::NOW + 2 * DAY_IN_SECONDS ) );
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'indeterminate', $verdict['state'] );
	}

	public function test_signature_not_covering_authority_is_indeterminate() {
		$req     = $this->signed_request( array( 'components' => array( 'signature-agent' ) ) );
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'indeterminate', $verdict['state'] );
	}

	public function test_agent_header_present_but_unsigned_is_indeterminate() {
		// The draft requires a present Signature-Agent to be a signed component —
		// otherwise a middlebox could swap the directory out from under the signature.
		$req     = $this->signed_request( array( 'components' => array( '@authority' ) ) );
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'indeterminate', $verdict['state'] );
	}

	public function test_unreachable_directory_fails_open() {
		$req     = $this->signed_request();
		$verdict = BotSignature::inspect_from( $req['headers'], static function () { return null; }, self::NOW );

		$this->assertSame( 'indeterminate', $verdict['state'] );
		$this->assertStringContainsString( 'directory', $verdict['reason'] );
	}

	public function test_unknown_keyid_fails_open_not_closed() {
		$req     = $this->signed_request( array( 'keyid' => 'not-a-key-this-signer-publishes' ) );
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'indeterminate', $verdict['state'] );
	}

	public function test_missing_signature_agent_is_indeterminate() {
		$req     = $this->signed_request( array( 'drop_agent' => true, 'components' => array( '@authority' ) ) );
		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'indeterminate', $verdict['state'] );
	}

	public function test_a_replayed_nonce_never_mints_a_second_verdict() {
		$req = $this->signed_request( array( 'nonce' => 'fixed-nonce-1' ) );

		$first  = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );
		$second = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'verified', $first['state'] );
		$this->assertSame( 'indeterminate', $second['state'] );
		$this->assertStringContainsString( 'replayed', $second['reason'] );
	}

	/** A signature that FAILS must not consume the nonce — else a forged flood could
	 * pre-burn nonces, and (the real cost) each attempt would write an unbounded
	 * transient before any crypto ran. The nonce is recorded only on a verified pass. */
	public function test_a_failing_signature_does_not_consume_the_nonce() {
		$bad = $this->signed_request( array( 'nonce' => 'shared-nonce' ) );
		$bad['headers']['signature'] = 'sig1=:' . base64_encode( str_repeat( 'x', 64 ) ) . ':';
		$first = BotSignature::inspect_from( $bad['headers'], $bad['loader'], self::NOW );
		$this->assertSame( 'failed', $first['state'] );

		// A genuine request reusing the SAME nonce still verifies — the failed
		// attempt left no record. (nonce_key = signer|nonce, independent of the key.)
		$good   = $this->signed_request( array( 'nonce' => 'shared-nonce' ) );
		$second = BotSignature::inspect_from( $good['headers'], $good['loader'], self::NOW );
		$this->assertSame( 'verified', $second['state'] );
	}

	/* ------------------------------ signer-origin hygiene (SSRF surface) ------------------------------ */

	public function test_signer_origin_accepts_only_clean_https_origins() {
		$this->assertSame( self::SIGNER, BotSignature::signer_origin( 'sig="' . self::SIGNER . '"' ) );
		$this->assertSame( self::SIGNER, BotSignature::signer_origin( '"' . self::SIGNER . '"' ) );
		$this->assertSame( self::SIGNER, BotSignature::signer_origin( self::SIGNER ) );
		$this->assertSame( 'https://agent.bot.goog', BotSignature::signer_origin( 'g="https://agent.bot.goog"' ) );

		$this->assertSame( '', BotSignature::signer_origin( 'sig="http://signer.example.com"' ), 'plain http never' );
		$this->assertSame( '', BotSignature::signer_origin( 'sig="https://signer.example.com/path"' ), 'no paths' );
		$this->assertSame( '', BotSignature::signer_origin( 'sig="https://signer.example.com:8443"' ), 'no port games' );
		$this->assertSame( '', BotSignature::signer_origin( 'sig="https://user:pw@signer.example.com"' ), 'no userinfo' );
		$this->assertSame( '', BotSignature::signer_origin( 'sig="https://10.0.0.8"' ), 'no IP literals' );
		$this->assertSame( '', BotSignature::signer_origin( 'sig="https://localhost"' ), 'no single-label hosts' );
	}

	/* ------------------------------ parsing details ------------------------------ */

	public function test_our_signature_is_found_among_other_tagged_members() {
		$req   = $this->signed_request();
		$alien = 'other=("@authority");created=1;expires=2;keyid="k";tag="not-ours"';

		$req['headers']['signature-input'] = $alien . ', ' . $req['headers']['signature-input'];
		$req['headers']['signature']       = 'other=:' . base64_encode( 'xx' ) . ':, ' . $req['headers']['signature'];

		$verdict = BotSignature::inspect_from( $req['headers'], $req['loader'], self::NOW );

		$this->assertSame( 'verified', $verdict['state'], 'the web-bot-auth member is picked out of a multi-signature header' );
	}

	public function test_find_key_matches_by_thumbprint_and_skips_expired_keys() {
		$keypair = sodium_crypto_sign_keypair();
		$x       = BotSignature::b64url_encode( sodium_crypto_sign_publickey( $keypair ) );
		$thumb   = BotSignature::jwk_thumbprint( $x );

		$directory = array(
			'keys' => array(
				array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $x, 'exp' => self::NOW - 999 ),
				array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $x ),
			),
		);

		$this->assertNotNull( BotSignature::find_key( $directory, $thumb, self::NOW ), 'thumbprint match without kid' );

		$only_expired = array( 'keys' => array( array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $x, 'exp' => self::NOW - 999 ) ) );
		$this->assertNull( BotSignature::find_key( $only_expired, $thumb, self::NOW ), 'an expired key never verifies anything' );
	}

	public function test_known_signers_ships_google_and_normalises_filter_entries() {
		$known = BotSignature::known_signers();
		$this->assertArrayHasKey( 'https://agent.bot.goog', $known );

		add_filter(
			'agentimus_known_signature_agents',
			static function ( $list ) {
				$list['HTTPS://Custom.Example.com/'] = 'Custom';
				$list['http://insecure.example.com'] = 'Nope';
				return $list;
			}
		);
		$known = BotSignature::known_signers();
		$this->assertArrayHasKey( 'https://custom.example.com', $known, 'filter entries are normalised' );
		$this->assertArrayNotHasKey( 'http://insecure.example.com', $known, 'non-https entries are dropped' );
	}
}
