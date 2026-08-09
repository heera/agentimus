<?php
/**
 * Back-compat shim. The at-rest secrets helper moved to {@see \Agentimus\Crypto}
 * because it is the house-wide credential encryptor — used by the Cloudflare, Bing
 * and Google connection stores, not only Visibility, so its old namespace lied.
 * This alias keeps `Agentimus\Visibility\Crypto` resolving for any reference that
 * still uses the historical name (including the tests, which is how this shim is
 * exercised). The ciphertext format and key derivation are unchanged, so stored
 * secrets keep decrypting.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

defined( 'ABSPATH' ) || exit;

class_alias( \Agentimus\Crypto::class, __NAMESPACE__ . '\\Crypto' );
