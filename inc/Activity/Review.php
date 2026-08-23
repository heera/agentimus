<?php
/**
 * The owner's verdict on one client — the review queue's decisions, as callable
 * code rather than as a button.
 *
 * The queue exists because some clients need judging: a brand-new crawler, one
 * fetching far more than the rest, one caught claiming an identity that is not
 * its own. Every row sits there until somebody says block, allow, or not-now.
 *
 * ⭐ Why this is a class and not a handler. The owner's screen reaches these
 * decisions through REST, and a connected assistant reaches them through the
 * ability tier — {@see \Agentimus\Abilities\Registrar}. Two doors, one set of
 * rules: which clients are protected, what a "safe rule" is, and where the
 * dismissal volume comes from. A second copy of that behind the second door is
 * how the two drift apart.
 *
 * ⛔ THE LINE. These are the actions the owner's own UI performs on ONE CLICK,
 * with no confirmation — which is exactly why an assistant may run them. Clear
 * log does put up a dialog on both screens, so it stays human and is not here.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

use Agentimus\Guard;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Review {

	/** @var string[] The verdicts a caller may file. */
	const DECISIONS = array( 'block', 'allow', 'ignore', 'forget' );

	/**
	 * File a verdict on one client.
	 *
	 * @param Settings $settings The site settings.
	 * @param string   $ua       The client's user-agent, as the queue reported it.
	 * @param string   $decision block | allow | ignore | forget.
	 * @param string   $list     For forget only: 'blocked' or 'allowed'.
	 * @return array|\WP_Error { decided: bool, rule: string, message: string }
	 */
	public static function decide( Settings $settings, $ua, $decision, $list = '' ) {
		$ua       = trim( (string) $ua );
		$decision = strtolower( trim( (string) $decision ) );

		if ( '' === $ua ) {
			return new \WP_Error( 'agentimus_review_ua', __( 'Which client? Send the ua from the review queue.', 'agentimus' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $decision, self::DECISIONS, true ) ) {
			return new \WP_Error( 'agentimus_review_decision', __( 'decision must be block, allow, ignore or forget.', 'agentimus' ), array( 'status' => 400 ) );
		}

		if ( 'ignore' === $decision ) {
			return self::ignore( $settings, $ua );
		}
		if ( 'forget' === $decision ) {
			return self::forget( $settings, $ua, $list );
		}
		return self::list_it( $settings, $ua, $decision );
	}

	/**
	 * A not-now: the row leaves, no policy changes either way.
	 *
	 * ⭐ The volume is read from the ROW, never taken from the caller. That
	 * number is what decides whether this client ever comes back — dismiss it at
	 * zero and it returns on its very next request — so it is not an input.
	 *
	 * @param Settings $settings The site settings.
	 * @param string   $ua       The client.
	 * @return array
	 */
	private static function ignore( Settings $settings, $ua ) {
		$hits = 0;
		// ⚠️ `threats` is a WRAPPER (sources / counts / blockingOn / …) and the
		// rows live under `sources`. Reading the wrapper as a row list yields one
		// blank row per key, and every dismissal files at zero.
		$stats = Repository::stats( $settings );
		$rows  = isset( $stats['threats']['sources'] ) ? (array) $stats['threats']['sources'] : array();
		foreach ( $rows as $row ) {
			if ( isset( $row['ua'] ) && (string) $row['ua'] === $ua ) {
				$hits = (int) ( isset( $row['hits'] ) ? $row['hits'] : 0 );
				break;
			}
		}

		Repository::dismiss( $ua, $hits );
		return array(
			'decided' => true,
			'rule'    => '',
			'message' => __( 'Set aside for now. It comes back if this client changes materially.', 'agentimus' ),
		);
	}

	/**
	 * Undo a standing block or allow, putting the client back to undecided.
	 *
	 * @param Settings $settings The site settings.
	 * @param string   $ua       The client, or the rule itself — both name the same decision.
	 * @param string   $list     'blocked' or 'allowed'.
	 * @return array|\WP_Error
	 */
	private static function forget( Settings $settings, $ua, $list ) {
		$list = strtolower( trim( (string) $list ) );
		if ( ! in_array( $list, array( 'blocked', 'allowed' ), true ) ) {
			return new \WP_Error( 'agentimus_review_list', __( 'For forget, say which list: "blocked" or "allowed".', 'agentimus' ), array( 'status' => 400 ) );
		}

		$key    = 'blocked' === $list ? 'blocked_agents' : 'allowed_agents';
		$kept   = array();
		$found  = '';
		foreach ( array_values( (array) $settings->get( $key, array() ) ) as $token ) {
			$token = (string) $token;
			if ( strtolower( $token ) === strtolower( $ua ) || ( '' !== $token && false !== stripos( $ua, $token ) ) ) {
				$found = $token;
				continue;
			}
			$kept[] = $token;
		}
		if ( '' === $found ) {
			return new \WP_Error( 'agentimus_review_absent', __( 'Nothing on that list matches this client — there is no decision to forget.', 'agentimus' ), array( 'status' => 404 ) );
		}

		// ⛔ FULL read-modify-write. Settings::update() REPLACES the option with
		// what it is handed — a partial array resets every key it omits back to
		// its default, so `update( array( 'blocked_agents' => … ) )` quietly turns
		// off the MCP server, the visit log, the digest and every other switch on
		// the site. Caught 2026-08-23 by a screenshot: the settings frame came out
		// with everything off. {@see Settings::block_agent()}, which says the same
		// thing in its own docblock.
		$all         = $settings->stored();
		$all[ $key ] = $kept;
		$settings->update( $all );
		return array(
			'decided' => true,
			'rule'    => $found,
			'message' => sprintf(
				/* translators: %s: the user-agent rule that was removed. */
				__( 'Removed “%s” — this client is undecided again.', 'agentimus' ),
				$found
			),
		);
	}

	/**
	 * Block or allow: both need a rule that is safe to match on, and the
	 * derivation is the guard's — it refuses protected clients (search engines,
	 * anything already allowed) exactly as it does for the owner's own click.
	 *
	 * @param Settings $settings The site settings.
	 * @param string   $ua       The client.
	 * @param string   $decision 'block' or 'allow'.
	 * @return array|\WP_Error
	 */
	private static function list_it( Settings $settings, $ua, $decision ) {
		$token = Guard::suggest_token( $ua );
		if ( '' === $token ) {
			return new \WP_Error(
				'agentimus_no_safe_token',
				__( 'No safe rule can be derived for this client, so it cannot be blocked or allowed.', 'agentimus' ),
				array( 'status' => 422 )
			);
		}

		if ( 'block' === $decision ) {
			$settings->block_agent( $token );
			return array(
				'decided' => true,
				'rule'    => $token,
				'message' => sprintf(
					/* translators: %s: the user-agent rule now blocked. */
					__( 'Blocked “%s” — clients matching it are refused from now on.', 'agentimus' ),
					$token
				),
			);
		}

		$settings->allow_agent( $token );
		return array(
			'decided' => true,
			'rule'    => $token,
			'message' => sprintf(
				/* translators: %s: the user-agent rule now allowed. */
				__( 'Allowed “%s” — it is never blocked and never queued again.', 'agentimus' ),
				$token
			),
		);
	}

	/**
	 * Ask, live, whether one client really is what it claims to be.
	 *
	 * ⭐ Straight through the owner's own route object rather than a copy of its
	 * body: the DNS budget, the "does it even claim a verifiable bot" refusal and
	 * the rule that only a CONCLUSIVE verdict is stored all live in there, and a
	 * second implementation would drift from them.
	 *
	 * @param Settings $settings The site settings.
	 * @param string   $ua       The client.
	 * @return array|\WP_Error { status: string, verdict: int, checked: int, message: string }
	 */
	public static function recheck( Settings $settings, $ua ) {
		$ua = trim( (string) $ua );
		if ( '' === $ua ) {
			return new \WP_Error( 'agentimus_recheck_ua', __( 'Which client? Send the ua from the review queue.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/activity/reverify' );
		$request->set_param( 'ua', $ua );
		$out = ( new Module( $settings ) )->reverify( $request );
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$data    = $out->get_data();
		$status  = (string) ( isset( $data['status'] ) ? $data['status'] : '' );
		$verdict = (int) ( isset( $data['verdict'] ) ? $data['verdict'] : 0 );
		$per_ip  = isset( $data['perIp'] ) && is_array( $data['perIp'] ) ? $data['perIp'] : array();

		if ( 'no-ip' === $status ) {
			$message = __( 'No address is on record for this client, so there was nothing to look up.', 'agentimus' );
		} elseif ( 2 === $verdict ) {
			$message = __( 'Confirmed impostor — the addresses do not belong to the crawler it claims to be.', 'agentimus' );
		} elseif ( 1 === $verdict ) {
			$message = __( 'Confirmed genuine — the addresses check out.', 'agentimus' );
		} else {
			$message = __( 'No usable answer this time; the client keeps the verdict it already had.', 'agentimus' );
		}

		return array(
			'status'  => '' === $status ? 'checked' : $status,
			'verdict' => $verdict,
			'checked' => count( $per_ip ),
			'message' => $message,
		);
	}
}
