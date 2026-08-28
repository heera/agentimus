<?php
/**
 * Web Bot Auth — verifying SIGNED crawler requests (RFC 9421 HTTP Message
 * Signatures, profile per draft-meunier-web-bot-auth-architecture).
 *
 * The other half of {@see Discovery\Signer}: that class signs what this site
 * serves; this one verifies who is asking. A bot that signs carries three
 * headers — `Signature-Input` (what was signed, with parameters), `Signature`
 * (the bytes) and `Signature-Agent` (the https origin whose published key
 * directory vouches for it). We fetch that origin's JWKS directory from
 * `/.well-known/http-message-signatures-directory` (cached, budgeted), rebuild
 * the covered signature base, and check the Ed25519 math with the same sodium
 * primitive the Signer uses.
 *
 * What a verdict MEANS (encode this in every consumer):
 * - `verified` proves the request came from the operator of the signer origin —
 *   identity, not reputation. Whether that origin is a KNOWN agent (OpenAI,
 *   Google's agent…) is the caller's classification, not this class's.
 * - `failed` means the crypto conclusively did not check out — a forged claim.
 * - `unsigned` means the request doesn't speak this protocol at all. Most
 *   crawlers don't yet; an unsigned request is NEVER penalized for it.
 * - `indeterminate` covers everything in between (malformed headers, an
 *   unreachable directory, a spent budget, a replayed nonce): fail OPEN, the
 *   same doctrine as the FCrDNS verifier — a broken lookup must never cost a
 *   real crawler its standing.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class BotSignature {

	/** The tag every Web Bot Auth signature MUST carry — other tags are other protocols. */
	const TAG = 'web-bot-auth';

	/** The signer's key directory path (per draft-meunier-http-message-signatures-directory; Google serves this live). */
	const DIRECTORY_PATH = '/.well-known/http-message-signatures-directory';

	/** Directory cache (per signer origin). */
	const DIR_CACHE_PREFIX = 'agentimus_wba_dir_';
	const DIR_CACHE_TTL    = 6 * HOUR_IN_SECONDS;

	/** Per-window budget for NEW directory fetches — an attacker naming a fresh
	 *  bogus signer origin per request must not turn us into their HTTP cannon. */
	const BUDGET_KEY    = 'agentimus_wba_budget';
	const BUDGET_WINDOW = 60;
	const BUDGET_MAX    = 10;

	/** Replay window: a nonce seen twice within its validity is not re-verified. */
	const NONCE_PREFIX = 'agentimus_wba_nonce_';

	/** Clock-skew allowance on created/expires (seconds). */
	const SKEW = 120;

	/** The spec recommends signature validity of no more than 24 hours. */
	const MAX_VALIDITY = DAY_IN_SECONDS;

	/** Directory response size cap — a key set is small; megabytes is an attack. */
	const DIR_MAX_BYTES = 65536;

	/** @var array|null Per-request verdict memo — Guard and the Recorder share one inspection. */
	private static $memo = null;

	/**
	 * The current request's verdict, computed once per request. Both consumers
	 * (Guard on the deny path, the Recorder on the log path) ask; the crypto and
	 * any directory fetch happen at most once.
	 *
	 * @return array{state:string,signer:string,reason:string}
	 */
	public static function current() {
		if ( null === self::$memo ) {
			self::$memo = self::inspect();
		}
		return self::$memo;
	}

	/**
	 * Test seam: plant (or with null, clear) the per-request memo.
	 *
	 * @internal
	 * @param array|null $verdict A verdict array, or null to reset.
	 */
	public static function prime_memo( $verdict ) {
		self::$memo = $verdict;
	}

	/**
	 * Whether the current request's signature CONCLUSIVELY failed — a forged
	 * claim, the same deception class as a spoofed engine UA.
	 *
	 * @return bool
	 */
	public static function conclusively_failed() {
		return 'failed' === self::current()['state'];
	}

	/**
	 * The label of the KNOWN signer this request verifiably came from, or ''.
	 * Verified-but-unknown origins return '' — the math proved who signed, but
	 * an identity nobody recognises earns no standing from it.
	 *
	 * @return string
	 */
	public static function verified_known_label() {
		$verdict = self::current();
		if ( 'verified' !== $verdict['state'] ) {
			return '';
		}
		$known = self::known_signers();
		return isset( $known[ $verdict['signer'] ] ) ? $known[ $verdict['signer'] ] : '';
	}

	/**
	 * The face a signature verdict shows in the UI: a short display name for WHO
	 * the signature speaks for. A verified KNOWN agent shows its label ("OpenAI
	 * agent"); a verified UNRECOGNISED origin shows its bare host
	 * ("agents.acme.example"); a failed claim shows the host it claimed
	 * ("chatgpt.com") — naming the victim of the impersonation, exactly what the
	 * review card needs. '' for everything that earns no face (unsigned,
	 * indeterminate).
	 *
	 * ⛔⛔ A FACE IS NOT STANDING, and the two must never be collapsed into one
	 * answer. {@see verified_known_label()} alone decides what a signature is
	 * WORTH — Guard's allow, the log's verdict 1 — and an origin nobody
	 * recognises still earns nothing from it. This decides only what is SHOWN.
	 * Before they were separated, a valid signature from any operator that is
	 * not Google or OpenAI returned '' here and so was recorded NOWHERE: the
	 * maths passed and the log said nothing had happened. An absence must name
	 * itself; a signature that leaves no trace cannot.
	 *
	 * @return string
	 */
	public static function face() {
		$verdict = self::current();
		if ( 'verified' === $verdict['state'] ) {
			$known = self::verified_known_label();
			return '' !== $known ? $known : self::bare_host( $verdict['signer'] );
		}
		if ( 'failed' === $verdict['state'] ) {
			return self::bare_host( $verdict['signer'] );
		}
		return '';
	}

	/**
	 * A signer origin as the owner reads it — `https://chatgpt.com` → `chatgpt.com`.
	 *
	 * @param string $origin Signer origin, or ''.
	 * @return string
	 */
	private static function bare_host( $origin ) {
		return (string) preg_replace( '#^https://#', '', (string) $origin );
	}

	/**
	 * Inspect the CURRENT request. Thin wrapper over {@see inspect_from()}, which
	 * carries all the logic and is the test seam.
	 *
	 * @return array{state:string,signer:string,reason:string}
	 */
	public static function inspect() {
		$headers = array();
		foreach ( array( 'signature', 'signature-input', 'signature-agent', 'host' ) as $name ) {
			$key = 'HTTP_' . str_replace( '-', '_', strtoupper( $name ) );
			if ( isset( $_SERVER[ $key ] ) ) {
				$headers[ $name ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw header inspected under our own strict parser below.
			}
		}
		return self::inspect_from( $headers, null, time() );
	}

	/**
	 * Inspect one request's signature headers. Everything injectable for tests:
	 * headers, the directory loader, and the clock.
	 *
	 * @param array         $headers Lowercase-keyed subset: signature, signature-input, signature-agent, host.
	 * @param callable|null $loader  fn( string $origin ) → array|null (decoded directory JSON). Null = HTTP loader.
	 * @param int           $now     Unix time.
	 * @return array{state:string,signer:string,reason:string}
	 */
	public static function inspect_from( array $headers, $loader, $now ) {
		$sig_header   = isset( $headers['signature'] ) ? (string) $headers['signature'] : '';
		$input_header = isset( $headers['signature-input'] ) ? (string) $headers['signature-input'] : '';

		if ( '' === $sig_header && '' === $input_header ) {
			return self::verdict( 'unsigned', '', '' );
		}
		if ( '' === $sig_header || '' === $input_header ) {
			return self::verdict( 'indeterminate', '', 'half-signed: one of Signature/Signature-Input is missing' );
		}

		// Find the (first) signature whose tag says it speaks OUR protocol. A request
		// carrying only other-tagged message signatures is not a Web Bot Auth claim
		// at all — that's plain `unsigned`, never a mark against the sender.
		$input = self::parse_signature_input( $input_header );
		if ( false === $input ) {
			return self::verdict( 'unsigned', '', '' );
		}
		if ( null === $input ) {
			return self::verdict( 'indeterminate', '', 'unparseable Signature-Input' );
		}

		$sig_bytes = self::parse_signature_bytes( $sig_header, $input['label'] );
		if ( null === $sig_bytes ) {
			return self::verdict( 'indeterminate', '', 'no signature bytes for label "' . $input['label'] . '"' );
		}

		// Parameter sanity, per the architecture draft.
		if ( '' === $input['keyid'] || 0 === $input['created'] || 0 === $input['expires'] ) {
			return self::verdict( 'indeterminate', '', 'missing created/expires/keyid' );
		}
		if ( $input['created'] > $now + self::SKEW ) {
			return self::verdict( 'indeterminate', '', 'created is in the future' );
		}
		if ( $input['expires'] < $now - self::SKEW ) {
			return self::verdict( 'indeterminate', '', 'signature expired' );
		}
		if ( $input['expires'] - $input['created'] > self::MAX_VALIDITY + self::SKEW ) {
			return self::verdict( 'indeterminate', '', 'validity window over 24h' );
		}
		$covered = array_map( 'strtolower', $input['components'] );
		if ( ! in_array( '@authority', $covered, true ) && ! in_array( '@target-uri', $covered, true ) ) {
			return self::verdict( 'indeterminate', '', 'signature does not cover @authority' );
		}

		$signer = self::signer_origin( isset( $headers['signature-agent'] ) ? (string) $headers['signature-agent'] : '' );
		if ( '' === $signer ) {
			// Without a Signature-Agent there is no key directory to check against —
			// out-of-band keyids are a later tier, not a failure.
			return self::verdict( 'indeterminate', '', 'no usable Signature-Agent' );
		}
		if ( isset( $headers['signature-agent'] ) && ! in_array( 'signature-agent', $covered, true ) ) {
			return self::verdict( 'indeterminate', '', 'Signature-Agent present but not signed' );
		}

		// Replay: the draft leaves nonce policy to the verifier. Ours: within one
		// validity window the same nonce never mints a second fresh VERIFIED verdict.
		// The check is here (a cheap read), but the record is WRITTEN only after the
		// signature verifies (below) — otherwise a flood of forged signatures with
		// random nonces would write an unbounded transient each, before any crypto ran.
		$nonce_key = '' !== $input['nonce'] ? self::NONCE_PREFIX . md5( $signer . '|' . $input['nonce'] ) : '';
		if ( '' !== $nonce_key && get_transient( $nonce_key ) ) {
			return self::verdict( 'indeterminate', $signer, 'nonce replayed' );
		}

		$directory = null === $loader ? self::load_directory( $signer ) : call_user_func( $loader, $signer );
		if ( null === $directory ) {
			return self::verdict( 'indeterminate', $signer, 'key directory unavailable' );
		}

		$public_key = self::find_key( $directory, $input['keyid'], $now );
		if ( null === $public_key ) {
			return self::verdict( 'indeterminate', $signer, 'keyid not in the signer directory' );
		}

		$base = self::signature_base( $covered, $input['params_raw'], $headers );
		if ( null === $base ) {
			return self::verdict( 'indeterminate', $signer, 'covered component unavailable' );
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return self::verdict( 'indeterminate', $signer, 'sodium unavailable' );
		}
		try {
			$ok = sodium_crypto_sign_verify_detached( $sig_bytes, $base, $public_key );
		} catch ( \Throwable $e ) {
			return self::verdict( 'indeterminate', $signer, 'verify errored: ' . $e->getMessage() );
		}

		if ( ! $ok ) {
			return self::verdict( 'failed', $signer, 'signature does not verify against the signer key' );
		}
		// Record the nonce now that the signature is proven — a replay of THIS
		// verified request inside its validity window is caught by the check above.
		if ( '' !== $nonce_key ) {
			set_transient( $nonce_key, 1, max( 60, $input['expires'] - $now ) );
		}
		return self::verdict( 'verified', $signer, '' );
	}

	/**
	 * The https origins this site recognises as named agents. Verification proves
	 * "the operator of {origin} sent this"; THIS list is what upgrades that to
	 * "and that origin is the agent it presents as". Google's is the one origin
	 * its docs commit to; others land here as their operators publish stable
	 * directories (and owners can add their own via the filter).
	 *
	 * @return array<string,string> origin (lowercase, no trailing slash) => label.
	 */
	public static function known_signers() {
		// Both entries confirmed against LIVE production directories (2026-07-27):
		// Google documents agent.bot.goog; OpenAI's directory at chatgpt.com even
		// names itself in its own signature_agent member.
		$known = array(
			'https://agent.bot.goog' => 'Google Agent',
			'https://chatgpt.com'    => 'OpenAI agent',
		);

		/**
		 * Filter the signature-agent origins recognised as named agents.
		 *
		 * @param array<string,string> $known origin => human label.
		 */
		$known = (array) apply_filters( 'agentimus_known_signature_agents', $known );

		$out = array();
		foreach ( $known as $origin => $label ) {
			$origin = untrailingslashit( strtolower( (string) $origin ) );
			if ( 0 === strpos( $origin, 'https://' ) ) {
				$out[ $origin ] = (string) $label;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 *  Parsing — a STRICT, NARROW profile of RFC 8941 structured fields:
	 *  exactly what Web Bot Auth senders emit, nothing more. Anything outside
	 *  the profile parses to null and fails OPEN upstream.
	 * ---------------------------------------------------------------------- */

	/**
	 * Parse Signature-Input and return the first member tagged for our protocol.
	 *
	 * Accepted shape (one or more comma-separated members):
	 *   label=("@authority" "signature-agent");created=1700000000;expires=1700000600;keyid="…";tag="web-bot-auth";nonce="…"
	 *
	 * @param string $header Raw header value.
	 * @return array{label:string,components:string[],created:int,expires:int,keyid:string,nonce:string,tag:string,params_raw:string}|false|null
	 *         The web-bot-auth member; FALSE when members parsed but none carry our
	 *         tag (another protocol — not our business); NULL when nothing parses.
	 */
	public static function parse_signature_input( $header ) {
		$saw_member = false;
		// Members: label=( …quoted strings… );params — split on commas OUTSIDE quotes/parens.
		foreach ( self::split_members( $header ) as $member ) {
			if ( ! preg_match( '/^\s*([a-z0-9_.*-]+)\s*=\s*(\(([^)]*)\)(.*))$/is', $member, $m ) ) {
				continue;
			}
			$saw_member = true;
			$label      = strtolower( trim( $m[1] ) );
			$params_raw = trim( $m[2] ); // The inner list WITH its params, verbatim — the base needs these bytes.
			$inner      = $m[3];
			$param_str  = $m[4];

			$components = array();
			if ( preg_match_all( '/"([^"]*)"/', $inner, $cm ) ) {
				$components = $cm[1];
			}

			$params = array(
				'created' => 0,
				'expires' => 0,
				'keyid'   => '',
				'nonce'   => '',
				'tag'     => '',
			);
			if ( preg_match_all( '/;\s*([a-z0-9_-]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;"\s]+)/i', $param_str, $pm, PREG_SET_ORDER ) ) {
				foreach ( $pm as $p ) {
					$key   = strtolower( $p[1] );
					$value = $p[2];
					if ( '"' === substr( $value, 0, 1 ) ) {
						$value = stripcslashes( substr( $value, 1, -1 ) );
					}
					if ( 'created' === $key || 'expires' === $key ) {
						$params[ $key ] = (int) $value;
					} elseif ( array_key_exists( $key, $params ) ) {
						$params[ $key ] = (string) $value;
					}
				}
			}

			if ( self::TAG !== $params['tag'] ) {
				continue; // Another protocol's signature — keep looking for ours.
			}

			return array(
				'label'      => $label,
				'components' => $components,
				'created'    => $params['created'],
				'expires'    => $params['expires'],
				'keyid'      => $params['keyid'],
				'nonce'      => $params['nonce'],
				'tag'        => $params['tag'],
				'params_raw' => $params_raw,
			);
		}
		return $saw_member ? false : null;
	}

	/**
	 * Pull the raw signature bytes for one label out of the Signature header
	 * (a dictionary of byte sequences: `label=:base64:`).
	 *
	 * @param string $header Raw Signature header.
	 * @param string $label  The member to extract.
	 * @return string|null Raw bytes (Ed25519 = 64), or null.
	 */
	public static function parse_signature_bytes( $header, $label ) {
		if ( ! preg_match( '/(?:^|,)\s*' . preg_quote( $label, '/' ) . '\s*=\s*:([A-Za-z0-9+\/=]+):/', $header, $m ) ) {
			return null;
		}
		$bytes = base64_decode( $m[1], true );
		return ( false === $bytes || '' === $bytes ) ? null : $bytes;
	}

	/**
	 * The signer origin out of Signature-Agent. Accepts the dictionary form
	 * (`sig="https://signer.example"`, Google's `g="https://agent.bot.goog"`) and
	 * the bare quoted-string form. Only a clean https origin survives: no path,
	 * no port games, no userinfo — this URL gets FETCHED, so it is an SSRF
	 * surface and is validated as one (scheme + shape here, wp_safe_remote_get's
	 * private-range rejection at fetch time).
	 *
	 * @param string $header Raw Signature-Agent value.
	 * @return string Lowercased origin without trailing slash, or ''.
	 */
	public static function signer_origin( $header ) {
		$header = trim( (string) $header );
		if ( '' === $header ) {
			return '';
		}
		$url = '';
		if ( preg_match( '/"(https:\/\/[^"]+)"/i', $header, $m ) ) {
			$url = $m[1];
		} elseif ( 0 === stripos( $header, 'https://' ) ) {
			$url = $header;
		}
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		if ( isset( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] ) {
			return '';
		}
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return '';
		}
		$host = strtolower( $parts['host'] );
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) || false === strpos( $host, '.' ) ) {
			return ''; // Malformed or single-label hosts.
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return ''; // Never an IP literal — a key directory lives on a name.
		}
		return 'https://' . $host;
	}

	/* ---------------------------------------------------------------------- *
	 *  Key directory
	 * ---------------------------------------------------------------------- */

	/**
	 * The signer's JWKS directory, cached per origin, budget-bounded. Returns the
	 * decoded array or null (unavailable → the caller fails open).
	 *
	 * @param string $origin Validated https origin.
	 * @return array|null
	 */
	private static function load_directory( $origin ) {
		$cache_key = self::DIR_CACHE_PREFIX . md5( $origin );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! self::spend_fetch() ) {
			return null; // Budget spent → fail open, never fetch-storm on a flood.
		}

		$response = wp_safe_remote_get(
			$origin . self::DIRECTORY_PATH,
			array(
				'timeout'             => 3,
				'redirection'         => 1,
				'limit_response_size' => self::DIR_MAX_BYTES,
				'user-agent'          => 'Agentimus/' . AGENTIMUS_VERSION . ' (Web Bot Auth verifier; +https://wordpress.org/plugins/agentimus/)',
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the MISS briefly so a dead signer origin isn't re-fetched per request.
			set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['keys'] ) || ! is_array( $body['keys'] ) ) {
			set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( $cache_key, $body, self::DIR_CACHE_TTL );
		return $body;
	}

	/**
	 * Find the Ed25519 key matching a keyid in a directory. The draft's keyid is
	 * the JWK thumbprint (RFC 8037 A.3); real directories also carry `kid`, so
	 * both are accepted. Honors per-key nbf/exp when present.
	 *
	 * @param array  $directory Decoded JWKS.
	 * @param string $keyid     The signature's keyid.
	 * @param int    $now       Unix time.
	 * @return string|null Raw 32-byte public key.
	 */
	public static function find_key( array $directory, $keyid, $now ) {
		$keys = isset( $directory['keys'] ) && is_array( $directory['keys'] ) ? $directory['keys'] : array();
		// Tolerate a single-key object where a list is expected.
		if ( isset( $keys['kty'] ) ) {
			$keys = array( $keys );
		}

		foreach ( $keys as $key ) {
			if ( ! is_array( $key ) || 'OKP' !== ( $key['kty'] ?? '' ) || 'Ed25519' !== ( $key['crv'] ?? '' ) || empty( $key['x'] ) ) {
				continue;
			}
			if ( isset( $key['nbf'] ) && (int) $key['nbf'] > $now + self::SKEW ) {
				continue;
			}
			if ( isset( $key['exp'] ) && (int) $key['exp'] < $now - self::SKEW ) {
				continue;
			}

			$kid        = isset( $key['kid'] ) ? (string) $key['kid'] : '';
			$thumbprint = self::jwk_thumbprint( (string) $key['x'] );
			if ( ! hash_equals( $keyid, $kid ) && ! hash_equals( $keyid, $thumbprint ) ) {
				continue;
			}

			$raw = self::b64url_decode( (string) $key['x'] );
			if ( is_string( $raw ) && defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' ) && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $raw ) ) {
				return $raw;
			}
			if ( is_string( $raw ) && 32 === strlen( $raw ) ) {
				return $raw;
			}
		}
		return null;
	}

	/**
	 * RFC 8037 A.3 JWK thumbprint for an Ed25519 key: SHA-256 over the canonical
	 * JSON {"crv":"Ed25519","kty":"OKP","x":"…"}, base64url.
	 *
	 * @param string $x The key's x member (base64url).
	 * @return string
	 */
	public static function jwk_thumbprint( $x ) {
		return self::b64url_encode( hash( 'sha256', '{"crv":"Ed25519","kty":"OKP","x":"' . $x . '"}', true ) );
	}

	/* ---------------------------------------------------------------------- *
	 *  Signature base (RFC 9421 §2.5, narrowed to our profile)
	 * ---------------------------------------------------------------------- */

	/**
	 * Rebuild the signed byte string: one `"component": value` line per covered
	 * component, then the `"@signature-params"` line carrying the Signature-Input
	 * member VERBATIM — re-serializing it would be a byte-for-byte gamble.
	 *
	 * @param string[] $covered    Covered component names (lowercased).
	 * @param string   $params_raw The inner list + params exactly as received.
	 * @param array    $headers    Lowercase-keyed request headers (+ host).
	 * @return string|null Null when a covered component can't be produced.
	 */
	public static function signature_base( array $covered, $params_raw, array $headers ) {
		$lines = array();
		foreach ( $covered as $component ) {
			$value = self::component_value( $component, $headers );
			if ( null === $value ) {
				return null;
			}
			$lines[] = '"' . $component . '": ' . $value;
		}
		$lines[] = '"@signature-params": ' . $params_raw;
		return implode( "\n", $lines );
	}

	/**
	 * One component's canonical value. Derived components come from the request
	 * context; anything else is a header field (trimmed, inner whitespace
	 * collapsed — the RFC 9421 field rules for the simple cases WBA uses).
	 *
	 * @param string $component Lowercased component name.
	 * @param array  $headers   Lowercase-keyed request headers (+ host, target-uri…).
	 * @return string|null
	 */
	private static function component_value( $component, array $headers ) {
		if ( '@authority' === $component ) {
			$host = isset( $headers['host'] ) ? strtolower( trim( (string) $headers['host'] ) ) : '';
			if ( '' === $host && function_exists( 'wp_parse_url' ) ) {
				$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			}
			$host = preg_replace( '/:443$/', '', $host );
			return '' === $host ? null : $host;
		}
		if ( '@target-uri' === $component ) {
			return isset( $headers['@target-uri'] ) ? (string) $headers['@target-uri'] : null;
		}
		if ( '@method' === $component ) {
			return isset( $headers['@method'] ) ? strtoupper( (string) $headers['@method'] ) : null;
		}
		if ( '@path' === $component ) {
			return isset( $headers['@path'] ) ? (string) $headers['@path'] : null;
		}
		if ( '@scheme' === $component ) {
			return 'https';
		}
		if ( '@' === substr( $component, 0, 1 ) ) {
			return null; // A derived component outside our profile — can't rebuild it faithfully.
		}
		if ( ! isset( $headers[ $component ] ) ) {
			return null;
		}
		return trim( preg_replace( '/[ \t]+/', ' ', (string) $headers[ $component ] ) );
	}

	/* ---------------------------------------------------------------------- *
	 *  Small utilities
	 * ---------------------------------------------------------------------- */

	/**
	 * Split a structured-field header into top-level members on commas outside
	 * quotes and parentheses.
	 *
	 * @param string $header Raw header.
	 * @return string[]
	 */
	private static function split_members( $header ) {
		$members = array();
		$depth   = 0;
		$quoted  = false;
		$current = '';
		$length  = strlen( $header );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $header[ $i ];
			if ( '"' === $char && ( 0 === $i || '\\' !== $header[ $i - 1 ] ) ) {
				$quoted = ! $quoted;
			} elseif ( ! $quoted && '(' === $char ) {
				$depth++;
			} elseif ( ! $quoted && ')' === $char ) {
				$depth--;
			} elseif ( ! $quoted && 0 === $depth && ',' === $char ) {
				$members[] = $current;
				$current   = '';
				continue;
			}
			$current .= $char;
		}
		if ( '' !== trim( $current ) ) {
			$members[] = $current;
		}
		return $members;
	}

	/**
	 * Spend one unit of the per-window directory-fetch budget.
	 *
	 * @return bool False when the window's budget is gone.
	 */
	private static function spend_fetch() {
		/**
		 * Filter the per-minute budget for NEW signer-directory fetches. 0 disables fetching.
		 *
		 * @param int $max Fetches per window.
		 */
		$max = (int) apply_filters( 'agentimus_wba_fetch_budget', self::BUDGET_MAX );
		if ( $max < 1 ) {
			return false;
		}
		$key   = self::BUDGET_KEY . '_' . (int) floor( time() / self::BUDGET_WINDOW );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, self::BUDGET_WINDOW * 2 );
		return true;
	}

	/**
	 * @param string $state  Verdict state.
	 * @param string $signer Signer origin ('' when unknown).
	 * @param string $reason Short diagnostic ('' when clean).
	 * @return array{state:string,signer:string,reason:string}
	 */
	private static function verdict( $state, $signer, $reason ) {
		return array(
			'state'  => $state,
			'signer' => $signer,
			'reason' => $reason,
		);
	}

	/**
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * @param string $data Base64url.
	 * @return string|false
	 */
	public static function b64url_decode( $data ) {
		return base64_decode( strtr( $data, '-_', '+/' ), true );
	}
}
