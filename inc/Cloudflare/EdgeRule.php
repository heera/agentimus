<?php
/**
 * The checks a blocked name must pass before it may reach Cloudflare, and the
 * rule text it would become. Pure: no database, no network.
 *
 * ⭐ WHY A USER AGENT ALONE IS NOT ENOUGH. Agentimus's own block answers a 403 on
 * the AI files it generates. A Cloudflare rule turns the client away from every
 * page, so a name that also fits something else costs far more there. Anyone can
 * also send any name, so a name proves nothing about who is asking. The answer
 * to both: only a name that is demonstrably one client's own product name goes
 * up, it matches only at the start of a word, and the rule itself leaves out what
 * Cloudflare has verified.
 *
 * ⛔ Cloudflare's regular expressions need a Business plan, so the word start is
 * spelled out with `contains` and `starts_with`, which every plan has.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

use Agentimus\Guard;

defined( 'ABSPATH' ) || exit;

final class EdgeRule {

	/** @var string Marks the one rule Agentimus owns, so no other rule is ever touched. */
	const REF = 'agentimus_edge_blocks';

	/**
	 * @var string A person who somehow matches gets through a challenge; a bot does not.
	 */
	const ACTION = 'managed_challenge';

	/** @var string The name appears as `name/1.2`. */
	const FORM_VERSIONED = 'versioned';

	/** @var string The name appears as `compatible; name`, with no version. */
	const FORM_COMPATIBLE = 'compatible';

	/** @var string The client writes it both ways, or has not been seen. */
	const FORM_BOTH = 'both';

	/** @var int The rule matches at a word start, so four letters are specific enough. */
	const MIN_LENGTH = 4;

	/** @var int Cloudflare's limit is 4,096 characters; this leaves a margin. */
	const MAX_EXPRESSION = 4000;

	/**
	 * Names a site depends on: its own software, certificates, payments and the
	 * search engines. No check of the log can prove these safe to block, because
	 * the harm arrives from a client that has not visited yet.
	 *
	 * @var string[]
	 */
	const DEPENDS_ON = array(
		'wordpress', 'agentimus', 'cloudflare', 'letsencrypt', 'jetpack',
		'woocommerce', 'stripe', 'paypal', 'google', 'bing', 'msnbot',
		'applebot', 'duckduck', 'yandex',
	);

	/**
	 * Check one name against everything known about who uses it.
	 *
	 * ⭐ The question is what the RULE would catch, not which user agents contain
	 * the text. `NotSQLSpider` contains "sqlspider" and a word-start match leaves
	 * it alone, so it is no reason to refuse. Asking the wrong question refused
	 * real names on heera.it, 11-01.
	 *
	 * @param string $token        The name, as it stands on the block list.
	 * @param array  $evidence     Rows { ua, hits, verified } of every client seen
	 *                             with this name inside its user agent.
	 * @param array  $allowed      The owner's allowed names.
	 * @param bool   $allow_unseen The owner insists on a name with no visits to
	 *                             judge by. Lifts `never_seen` and nothing else.
	 * @return array { ok: bool, token: string, form: string, unseen: bool, refusals: array, otherClients: string[] }
	 */
	public static function check( $token, array $evidence, array $allowed = array(), $allow_unseen = false ) {
		$token    = strtolower( trim( (string) $token ) );
		$refusals = array();
		$others   = array();

		if ( ! self::is_name( $token ) ) {
			$refusals[] = self::refusal( 'not_a_name', __( 'Only a plain name can go to Cloudflare, not a pattern or wildcard.', 'agentimus' ) );
			return self::result( $token, '', $refusals, $others );
		}

		if ( strlen( $token ) < self::MIN_LENGTH ) {
			$refusals[] = self::refusal(
				'too_short',
				sprintf(
					/* translators: %d: the shortest name allowed. */
					__( 'Names under %d characters are too likely to fit something else.', 'agentimus' ),
					self::MIN_LENGTH
				)
			);
		}

		if ( self::overlaps( $token, self::DEPENDS_ON ) ) {
			$refusals[] = self::refusal( 'depends_on', __( 'This name belongs to, or looks like, a service your site depends on.', 'agentimus' ) );
		}

		if ( self::overlaps( $token, $allowed ) ) {
			$refusals[] = self::refusal( 'touches_allowed', __( 'This name overlaps a client you have allowed.', 'agentimus' ) );
		}

		$versioned  = false;
		$compatible = false;
		$unspelled  = false;
		$verified   = 0;
		$rest       = array();
		$own        = false;
		foreach ( $evidence as $row ) {
			$ua = (string) $row['ua'];
			if ( ! self::is_own( $token, $ua ) ) {
				$rest[] = $row;
				continue;
			}
			$own = true;
			$v   = self::matches( $token, self::FORM_VERSIONED, $ua );
			$c   = self::matches( $token, self::FORM_COMPATIBLE, $ua );

			$versioned  = $versioned || $v;
			$compatible = $compatible || $c;
			$unspelled  = $unspelled || ( ! $v && ! $c );
			$verified  += isset( $row['verified'] ) ? (int) $row['verified'] : 0;
		}

		$unseen = ! $own;
		if ( $unseen ) {
			if ( ! $allow_unseen ) {
				$refusals[] = self::refusal( 'never_seen', __( 'No recent visits, so nothing shows what the rule would catch.', 'agentimus' ) );
			}
			// How an unseen client writes its name is not known, so both are covered.
			// Checked below as well: insisting must not catch a client that was seen.
			$versioned  = true;
			$compatible = true;
		}

		$form = '';
		if ( $versioned && $compatible ) {
			$form = self::FORM_BOTH;
		} elseif ( $versioned ) {
			$form = self::FORM_VERSIONED;
		} elseif ( $compatible ) {
			$form = self::FORM_COMPATIBLE;
		}

		foreach ( $rest as $row ) {
			if ( '' !== $form && self::matches( $token, $form, (string) $row['ua'] ) ) {
				$others[]  = (string) $row['ua'];
				$verified += isset( $row['verified'] ) ? (int) $row['verified'] : 0;
			}
		}

		if ( $verified > 0 ) {
			$refusals[] = self::refusal( 'proved_genuine', __( 'Requests with this name were proved to come from a genuine crawler.', 'agentimus' ) );
		}
		if ( ! empty( $others ) ) {
			$refusals[] = self::refusal( 'other_clients', __( 'The rule would also catch other clients using this name.', 'agentimus' ) );
		}
		if ( $unspelled || '' === $form ) {
			$refusals[] = self::refusal( 'no_safe_form', __( 'This client writes its name in a way the rule cannot match exactly.', 'agentimus' ) );
		}

		$out           = self::result( $token, $form, $refusals, $others );
		$out['unseen'] = $unseen;
		return $out;
	}

	/**
	 * The one rule's expression for every name on it.
	 *
	 * Always leaves out verified bots, `robots.txt` (a crawler may still read the
	 * polite rules) and certificate checks.
	 *
	 * @param array<string,string> $names Name => form.
	 * @return string '' when no name is valid: no names, no rule.
	 */
	public static function expression( array $names ) {
		$matches = array();
		foreach ( $names as $token => $form ) {
			$token = strtolower( (string) $token );
			if ( ! self::is_name( $token ) ) {
				continue; // Nothing but a checked name may ever reach the rule text.
			}
			if ( self::FORM_COMPATIBLE !== $form ) {
				$matches[] = 'lower(http.user_agent) contains " ' . $token . '/"';
				$matches[] = 'lower(http.user_agent) contains "(' . $token . '/"';
				$matches[] = 'starts_with(lower(http.user_agent), "' . $token . '/")';
			}
			if ( self::FORM_VERSIONED !== $form ) {
				foreach ( self::compatible_texts( $token ) as $text ) {
					$matches[] = 'lower(http.user_agent) contains "' . $text . '"';
				}
			}
		}
		if ( empty( $matches ) ) {
			return '';
		}
		return '(' . implode( ' or ', $matches ) . ')'
			. ' and not cf.client.bot'
			. ' and http.request.uri.path ne "/robots.txt"'
			. ' and not starts_with(http.request.uri.path, "/.well-known/acme-challenge/")';
	}

	/**
	 * Whether these names fit in one rule.
	 *
	 * @param array<string,string> $names Name => form.
	 * @return bool
	 */
	public static function fits( array $names ) {
		return strlen( self::expression( $names ) ) <= self::MAX_EXPRESSION;
	}

	/**
	 * About how many names the rule can hold, given what is on it. An estimate,
	 * since it depends on how long the next names are; {@see fits()} decides.
	 *
	 * @param array<string,string> $names Name => form.
	 * @return int
	 */
	public static function capacity( array $names ) {
		$one  = strlen( self::expression( array( 'abcdefghijkl' => self::FORM_VERSIONED ) ) );
		$two  = strlen( self::expression( array( 'abcdefghijkl' => self::FORM_VERSIONED, 'mnopqrstuvwx' => self::FORM_VERSIONED ) ) );
		$per  = $two - $one;
		$used = empty( $names ) ? $one - $per : strlen( self::expression( $names ) );
		return count( $names ) + (int) floor( max( 0, self::MAX_EXPRESSION - $used ) / $per );
	}

	/**
	 * Whether this user agent is this client's own: the name is its product name,
	 * and it is not a browser that happens to carry the name.
	 *
	 * @param string $token The lowercase name.
	 * @param string $ua    The user agent.
	 * @return bool
	 */
	private static function is_own( $token, $ua ) {
		// One definition of "product name": a protected engine and a generic tool answer something else here.
		if ( Guard::suggest_token( $ua ) !== $token ) {
			return false;
		}
		$ua = strtolower( $ua );
		// Crawlers that copy a browser's user agent say `compatible;`. A browser does not.
		return false !== strpos( $ua, 'compatible;' )
			|| 1 !== preg_match( '#(?:chrome|firefox|edg|crios|fxios)/|version/\d.*safari/#', $ua );
	}

	/**
	 * What the rule would do with this user agent: the same test as
	 * {@see expression()}, in PHP.
	 *
	 * @param string $token The lowercase name.
	 * @param string $form  A FORM_ constant.
	 * @param string $ua    The user agent.
	 * @return bool
	 */
	private static function matches( $token, $form, $ua ) {
		$ua = strtolower( $ua );
		if ( self::FORM_COMPATIBLE !== $form
			&& ( false !== strpos( $ua, ' ' . $token . '/' ) || false !== strpos( $ua, '(' . $token . '/' ) || 0 === strpos( $ua, $token . '/' ) ) ) {
			return true;
		}
		if ( self::FORM_VERSIONED !== $form ) {
			foreach ( self::compatible_texts( $token ) as $text ) {
				if ( false !== strpos( $ua, $text ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * How a client with no version writes its name. The end of the name is part
	 * of the match, so `compatible; name` cannot also catch `compatible; name-two`.
	 *
	 * @param string $token The lowercase name.
	 * @return string[]
	 */
	private static function compatible_texts( $token ) {
		return array(
			'compatible; ' . $token . ';',
			'compatible; ' . $token . ')',
			'compatible;' . $token . ';',
			'compatible;' . $token . ')',
		);
	}

	/**
	 * Whether a name is letters, digits and the few marks a product name uses.
	 * This is also what keeps quotes out of the rule text.
	 *
	 * @param string $token The lowercase name.
	 * @return bool
	 */
	private static function is_name( $token ) {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9._+-]*$/', $token );
	}

	/**
	 * Whether a name is inside one of these, or one of these is inside it.
	 *
	 * @param string   $token The lowercase name.
	 * @param string[] $names Names, any case.
	 * @return bool
	 */
	public static function overlaps( $token, array $names ) {
		foreach ( $names as $name ) {
			$name = strtolower( trim( (string) $name ) );
			if ( '' !== $name && ( false !== strpos( $token, $name ) || false !== strpos( $name, $token ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $code    Stable code for callers and tests.
	 * @param string $message What to tell the owner.
	 * @return array
	 */
	private static function refusal( $code, $message ) {
		return array( 'code' => $code, 'message' => $message );
	}

	/**
	 * @param string   $token    The lowercase name.
	 * @param string   $form     The form, or ''.
	 * @param array    $refusals The refusals.
	 * @param string[] $others   Other clients the name also fits.
	 * @return array
	 */
	private static function result( $token, $form, array $refusals, array $others ) {
		return array(
			'ok'           => empty( $refusals ) && '' !== $form,
			'token'        => $token,
			'form'         => $form,
			'unseen'       => false,
			'refusals'     => $refusals,
			'otherClients' => $others,
		);
	}
}
