<?php
/**
 * What a Cloudflare block for one name would do, before anything is sent.
 *
 * Gathers the two sources {@see EdgeRule} judges from. The site's log says which
 * bots used the name over its retention window but keeps no people; Cloudflare
 * saw everyone, for the last day.
 *
 * Then keeps Agentimus's one rule in step with the owner's decisions. ⭐ Removal
 * is the direction that must not get stuck: a name the owner unblocked and
 * Cloudflare still blocks is the worst state this can leave, so {@see reconcile()}
 * runs on every settings save and every hourly poll until it has gone.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

use Agentimus\Activity\Repository;

defined( 'ABSPATH' ) || exit;

final class EdgeBlock {

	/** @var int How far back to ask Cloudflare, seconds. The Free plan keeps about a day. */
	const EDGE_WINDOW = 86400;

	/** @var int More different clients than this under one name is not one client. */
	const MAX_CLIENTS = 50;

	/** @var array<string,int> Custom rules per plan, from Cloudflare's documentation. */
	const RULE_LIMITS = array( 'free' => 5, 'pro' => 20, 'business' => 100, 'enterprise' => 1000 );

	/** @var Settings */
	private $cloudflare;

	/** @var \Agentimus\Settings */
	private $core;

	/** @var Client */
	private $client;

	/**
	 * @param Settings            $cloudflare The Cloudflare connection store.
	 * @param \Agentimus\Settings $core       The core settings.
	 * @param Client|null         $client     Injectable for tests.
	 */
	public function __construct( Settings $cloudflare, \Agentimus\Settings $core, ?Client $client = null ) {
		$this->cloudflare = $cloudflare;
		$this->core       = $core;
		$this->client     = $client ? $client : new Client();
	}

	/**
	 * Answer for one name.
	 *
	 * `ok` means the name passed the checks. `ready` means it passed AND this
	 * token can reach the rules.
	 *
	 * @param string $token The name.
	 * @return array
	 */
	public function preview( $token ) {
		$token   = strtolower( trim( (string) $token ) );
		$allowed = (array) $this->core->get( 'allowed_agents', array() );
		$listed  = in_array( $token, $this->blocked_names(), true );

		if ( ! $this->cloudflare->connected() ) {
			return $this->answer(
				array(
					'token'        => $token,
					'form'         => '',
					'ok'           => false,
					'otherClients' => array(),
					'refusals'     => array( array( 'code' => 'not_connected', 'message' => __( 'Connect Cloudflare first.', 'agentimus' ) ) ),
				),
				$listed
			);
		}

		// A malformed name is settled without searching anything for it.
		$shape = EdgeRule::check( $token, array(), $allowed );
		if ( in_array( 'not_a_name', array_column( $shape['refusals'], 'code' ), true ) ) {
			return $this->answer( $shape, $listed );
		}

		$seen  = $this->seen();
		$judge = $this->judge( $token, $seen, $allowed );

		$edge            = $seen['edge'];
		$edge['clients'] = $judge['edge'];
		unset( $edge['rows'] );

		return $this->answer( $judge['check'], $listed, $judge['log'], $edge, $this->rules_state() );
	}

	/**
	 * Every blocked name and what can be done with it at Cloudflare. Backs the
	 * Blocked tab of Client Decisions.
	 *
	 * ⭐ One read of the log and one of Cloudflare, however long the list is.
	 *
	 * @return array
	 */
	public function overview() {
		$quiet = array( 'available' => false, 'reason' => '', 'rows' => array(), 'used' => 0, 'capacity' => 0 );
		if ( ! $this->cloudflare->connected() ) {
			$quiet['reason'] = 'not_connected';
			return $quiet;
		}
		if ( ! $this->core->get( 'cf_edge_block', false ) ) {
			$quiet['reason'] = 'switched_off';
			return $quiet;
		}

		$names   = $this->cloudflare->edge_names();
		$allowed = (array) $this->core->get( 'allowed_agents', array() );
		$rules   = $this->rules_state();
		$seen    = $this->seen();

		$rows = array();
		foreach ( (array) $this->core->get( 'blocked_agents', array() ) as $listed ) {
			$listed = (string) $listed;
			$token  = strtolower( trim( $listed ) );
			if ( isset( $names[ $token ] ) ) {
				$rows[] = array( 'token' => $listed, 'status' => 'on', 'reason' => '' );
				continue;
			}
			$check  = $this->judge( $token, $seen, $allowed )['check'];
			$codes  = array_column( $check['refusals'], 'code' );
			$status = 'refused';
			if ( ! empty( $check['ok'] ) ) {
				$status = 'can_add';
			} elseif ( array( 'never_seen' ) === $codes ) {
				$status = 'unseen';
			}
			$rows[] = array(
				'token'  => $listed,
				'status' => $status,
				'reason' => 'refused' === $status && isset( $check['refusals'][0]['message'] ) ? (string) $check['refusals'][0]['message'] : '',
			);
		}

		$limit = $this->rule_limit();
		return array(
			'available'       => true,
			'reason'          => '',
			'rows'            => $rows,
			'used'            => count( $names ),
			'capacity'        => EdgeRule::capacity( $names ),
			'canRead'         => $rules['canRead'],
			'needsPermission' => $rules['needsPermission'],
			'error'           => (string) $this->cloudflare->get( 'edge_error', '' ),
			'cloudflareError' => $rules['error'],
			'edgeChecked'     => $seen['edge']['checked'],
			'slotsFull'       => $rules['canRead'] && ! $rules['ourRule'] && $limit > 0 && $rules['ruleCount'] >= $limit,
		);
	}

	/**
	 * Add one name to the rule, if it passes everything.
	 *
	 * @param string $token        The name.
	 * @param bool   $allow_unseen The owner insists on a name with no recent visits.
	 * @return array { ok: bool, added: string[], skipped: array, refusals: array, error: string }
	 */
	public function add( $token, $allow_unseen = false ) {
		return $this->add_many( array( $token ), $allow_unseen );
	}

	/**
	 * Add these names in one change. What fails its checks stays out and is named,
	 * and the rest still go up.
	 *
	 * @param string[] $tokens       The names.
	 * @param bool     $allow_unseen The owner insists on names with no recent visits.
	 * @return array { ok: bool, added: string[], skipped: array, refusals: array, error: string }
	 */
	public function add_many( array $tokens, $allow_unseen = false ) {
		if ( ! $this->core->get( 'cf_edge_block', false ) ) {
			return $this->not_added( 'switched_off', __( 'Blocking at Cloudflare is turned off.', 'agentimus' ) );
		}
		if ( ! $this->cloudflare->connected() ) {
			return $this->not_added( 'not_connected', __( 'Connect Cloudflare first.', 'agentimus' ) );
		}

		$tokens  = array_values( array_unique( array_map( static function ( $token ) {
			return strtolower( trim( (string) $token ) );
		}, $tokens ) ) );
		$blocked = $this->blocked_names();
		$allowed = (array) $this->core->get( 'allowed_agents', array() );
		$names   = $this->cloudflare->edge_names();
		$seen    = $this->seen();
		$added   = array();
		$skipped = array();
		$skip    = static function ( $token, $code, $message ) use ( &$skipped ) {
			$skipped[] = array( 'token' => $token, 'code' => $code, 'message' => $message );
		};

		foreach ( $tokens as $token ) {
			if ( isset( $names[ $token ] ) ) {
				continue;
			}
			if ( ! in_array( $token, $blocked, true ) ) {
				$skip( $token, 'not_listed', __( 'Block this client in Agentimus first.', 'agentimus' ) );
				continue;
			}
			$check = $this->judge( $token, $seen, $allowed, $allow_unseen )['check'];
			if ( empty( $check['ok'] ) ) {
				// The first reason is enough: one row per name on the owner's list.
				$skip( $token, (string) $check['refusals'][0]['code'], (string) $check['refusals'][0]['message'] );
				continue;
			}
			$try           = $names;
			$try[ $token ] = (string) $check['form'];
			if ( ! EdgeRule::fits( $try ) ) {
				$skip( $token, 'rule_full', __( 'The Cloudflare rule has no room for another name.', 'agentimus' ) );
				continue;
			}
			$names   = $try;
			$added[] = $token;
		}

		$error = '';
		if ( ! empty( $added ) ) {
			$out = $this->write( $names );
			if ( isset( $out['error'] ) ) {
				$error = (string) $out['error'];
				if ( isset( $out['status'] ) && in_array( (int) $out['status'], array( 401, 403 ), true ) ) {
					$error = rtrim( $error, '.' ) . '. ' . __( 'Your Cloudflare token needs the Zone WAF: Edit permission.', 'agentimus' );
				}
				$added = array();
			} else {
				$this->cloudflare->note_edge( $names );
			}
		}

		return array(
			'ok'       => empty( $skipped ) && '' === $error,
			'added'    => $added,
			'skipped'  => $skipped,
			'refusals' => array_map( static function ( $row ) {
				return array( 'code' => $row['code'], 'message' => $row['message'] );
			}, $skipped ),
			'error'    => $error,
		);
	}

	/**
	 * What the log and Cloudflare have seen, read once for any number of names.
	 *
	 * @return array { log: array, edge: { checked, complete, error, rows } }
	 */
	private function seen() {
		$until = time();
		$out   = $this->client->user_agents_seen(
			$this->cloudflare->token(),
			(string) $this->cloudflare->get( 'zone_id' ),
			$until - self::EDGE_WINDOW,
			$until
		);
		$edge = isset( $out['error'] )
			? array( 'checked' => false, 'complete' => false, 'error' => (string) $out['error'], 'rows' => array() )
			: array(
				'checked'  => true,
				// Past the row limit the rarest user agents are the ones not returned.
				'complete' => count( (array) $out['rows'] ) < Client::GRAPHQL_LIMIT,
				'error'    => '',
				'rows'     => (array) $out['rows'],
			);
		return array( 'log' => Repository::ua_index(), 'edge' => $edge );
	}

	/**
	 * Judge one name against what was seen.
	 *
	 * ⭐ Case-insensitive here: Cloudflare's own filter is not known to ignore case,
	 * and these names come in every case.
	 *
	 * @param string $token        The lowercase name.
	 * @param array  $seen         From {@see seen()}.
	 * @param array  $allowed      The owner's allowed names.
	 * @param bool   $allow_unseen The owner insists on a name with no recent visits.
	 * @return array { check: array, log: array, edge: array }
	 */
	private function judge( $token, array $seen, array $allowed, $allow_unseen = false ) {
		$log = array();
		foreach ( $seen['log'] as $row ) {
			if ( '' !== $token && false !== stripos( $row['ua'], $token ) ) {
				$log[] = $row;
			}
		}
		$edge = array();
		foreach ( $seen['edge']['rows'] as $row ) {
			if ( '' !== $token && false !== stripos( (string) $row['ua'], $token ) ) {
				$edge[] = array( 'ua' => (string) $row['ua'], 'hits' => (int) $row['requests'] );
			}
		}

		$check = EdgeRule::check( $token, $this->evidence( $log, $edge ), $allowed, $allow_unseen );

		if ( ! $seen['edge']['checked'] ) {
			$check['ok']         = false;
			$check['refusals'][] = array(
				'code'    => 'edge_unchecked',
				'message' => __( 'Cloudflare could not say who else uses this name, so it stays off Cloudflare for now.', 'agentimus' ),
			);
		}
		if ( count( $log ) > self::MAX_CLIENTS || count( $edge ) > self::MAX_CLIENTS ) {
			$check['ok']         = false;
			$check['refusals'][] = array(
				'code'    => 'too_many_clients',
				'message' => __( 'Too many different clients use this name for it to be one client.', 'agentimus' ),
			);
		}
		return array( 'check' => $check, 'log' => $log, 'edge' => $edge );
	}

	/**
	 * How many custom rules this zone's plan allows, or 0 when not known.
	 *
	 * @return int
	 */
	private function rule_limit() {
		$plan = $this->client->zone_plan( $this->cloudflare->token(), (string) $this->cloudflare->get( 'zone_id' ) );
		return isset( self::RULE_LIMITS[ $plan ] ) ? self::RULE_LIMITS[ $plan ] : 0;
	}

	/**
	 * Remove the whole rule, for a disconnect.
	 *
	 * @return bool Whether it has gone.
	 */
	public function clear() {
		if ( array() === $this->cloudflare->edge_names() ) {
			return true;
		}
		return ! isset( $this->write( array() )['error'] );
	}

	/**
	 * Bring the rule into line with the owner's decisions. Only ever takes names
	 * out: a name goes in through {@see add()} and its checks alone.
	 *
	 * @param bool $look Also read the rule. A rule the owner deleted at Cloudflare
	 *                   is their decision, so the names are forgotten and it is
	 *                   not put back. Costs a request, so the hourly poll does it.
	 * @return void
	 */
	public function reconcile( $look = false ) {
		$names = $this->cloudflare->edge_names();
		if ( array() === $names || ! $this->cloudflare->connected() ) {
			return;
		}

		if ( $look ) {
			$rules = $this->client->custom_rules( $this->cloudflare->token(), (string) $this->cloudflare->get( 'zone_id' ) );
			if ( isset( $rules['error'] ) ) {
				$this->cloudflare->note_edge( $names, (string) $rules['error'] );
				return;
			}
			if ( null === $this->our_rule( (array) $rules['rules'] ) ) {
				$this->cloudflare->note_edge( array() );
				return;
			}
		}

		$wanted = $this->wanted( $names );
		if ( $wanted === $names ) {
			if ( '' !== (string) $this->cloudflare->get( 'edge_error', '' ) ) {
				$this->cloudflare->note_edge( $names );
			}
			return;
		}

		$out = $this->write( $wanted );
		if ( isset( $out['error'] ) ) {
			$this->cloudflare->note_edge( $names, (string) $out['error'] );
			return;
		}
		$this->cloudflare->note_edge( $wanted );
	}

	/**
	 * The names that should still be on the rule.
	 *
	 * @param array<string,string> $names Name => form.
	 * @return array<string,string>
	 */
	private function wanted( array $names ) {
		if ( ! $this->core->get( 'cf_edge_block', false ) ) {
			return array();
		}
		$blocked = $this->blocked_names();
		$allowed = (array) $this->core->get( 'allowed_agents', array() );
		$keep    = array();
		foreach ( $names as $name => $form ) {
			if ( in_array( (string) $name, $blocked, true ) && ! EdgeRule::overlaps( (string) $name, $allowed ) ) {
				$keep[ $name ] = $form;
			}
		}
		return $keep;
	}

	/**
	 * Make the rule hold exactly these names, then read it back.
	 *
	 * ⛔ Only Agentimus's own rule is ever changed. The read-back also checks that
	 * every other rule is still there, in the same order.
	 *
	 * @param array<string,string> $names Name => form. Empty removes the rule.
	 * @return array { ok?: true, error?: string }
	 */
	private function write( array $names ) {
		$token = $this->cloudflare->token();
		$zone  = (string) $this->cloudflare->get( 'zone_id' );

		$before = $this->client->custom_rules( $token, $zone );
		if ( isset( $before['error'] ) ) {
			return $before;
		}

		$ours       = $this->our_rule( (array) $before['rules'] );
		$ruleset_id = (string) $before['ruleset_id'];
		$expression = EdgeRule::expression( $names );

		if ( '' === $expression ) {
			if ( null === $ours ) {
				return array( 'ok' => true );
			}
			$out = $this->client->delete_custom_rule( $token, $zone, $ruleset_id, (string) $ours['id'] );
		} else {
			$rule = array(
				'ref'         => EdgeRule::REF,
				'description' => __( 'Agentimus: clients you blocked. Managed by the plugin, so edits here are replaced.', 'agentimus' ),
				'expression'  => $expression,
				'action'      => EdgeRule::ACTION,
				'enabled'     => true,
			);
			if ( null !== $ours ) {
				$out = $this->client->update_custom_rule( $token, $zone, $ruleset_id, (string) $ours['id'], $rule );
			} elseif ( '' !== $ruleset_id ) {
				$out = $this->client->add_custom_rule( $token, $zone, $ruleset_id, $rule );
			} else {
				$out = $this->client->create_custom_ruleset( $token, $zone, $rule );
			}
		}
		if ( isset( $out['error'] ) ) {
			return $out;
		}

		$after = $this->client->custom_rules( $token, $zone );
		if ( isset( $after['error'] ) ) {
			return $after;
		}
		$now  = $this->our_rule( (array) $after['rules'] );
		$kept = '' === $expression ? null === $now : ( null !== $now && isset( $now['expression'] ) && $expression === $now['expression'] );
		if ( ! $kept || $this->other_ids( (array) $before['rules'] ) !== $this->other_ids( (array) $after['rules'] ) ) {
			return array( 'error' => __( 'Cloudflare did not keep the rules as expected. Check Security → WAF → Custom rules.', 'agentimus' ) );
		}
		return array( 'ok' => true );
	}

	/**
	 * @param array $rules The zone's rules.
	 * @return array|null Agentimus's rule.
	 */
	private function our_rule( array $rules ) {
		foreach ( $rules as $rule ) {
			if ( isset( $rule['ref'] ) && EdgeRule::REF === $rule['ref'] ) {
				return (array) $rule;
			}
		}
		return null;
	}

	/**
	 * @param array $rules The zone's rules.
	 * @return string[] The ids of every rule but ours, in order.
	 */
	private function other_ids( array $rules ) {
		$ids = array();
		foreach ( $rules as $rule ) {
			if ( ! isset( $rule['ref'] ) || EdgeRule::REF !== $rule['ref'] ) {
				$ids[] = isset( $rule['id'] ) ? (string) $rule['id'] : '';
			}
		}
		return $ids;
	}

	/**
	 * @return string[] The block list, lowercase.
	 */
	private function blocked_names() {
		return array_map( 'strtolower', array_map( 'strval', (array) $this->core->get( 'blocked_agents', array() ) ) );
	}

	/**
	 * @param string $code    Stable code.
	 * @param string $message What to tell the owner.
	 * @return array
	 */
	private function not_added( $code, $message ) {
		return array( 'ok' => false, 'added' => array(), 'skipped' => array(), 'refusals' => array( array( 'code' => $code, 'message' => $message ) ), 'error' => '' );
	}

	/**
	 * Every client seen, merged by exact user agent.
	 *
	 * @param array $log  Rows from the site's log.
	 * @param array $edge Rows from Cloudflare.
	 * @return array Rows { ua, hits, verified }.
	 */
	private function evidence( array $log, array $edge ) {
		$merged = array();
		foreach ( array_merge( $log, $edge ) as $row ) {
			$ua = (string) $row['ua'];
			if ( ! isset( $merged[ $ua ] ) ) {
				$merged[ $ua ] = array( 'ua' => $ua, 'hits' => 0, 'verified' => 0 );
			}
			$merged[ $ua ]['hits']     += (int) $row['hits'];
			$merged[ $ua ]['verified'] += isset( $row['verified'] ) ? (int) $row['verified'] : 0;
		}
		return array_values( $merged );
	}

	/**
	 * Whether this token can read the zone's custom rules, and what is there.
	 * Only a count leaves here: the owner's other rules are not ours to report.
	 *
	 * @return array { canRead: bool, needsPermission: bool, error: string, ruleCount: int, ourRule: bool }
	 */
	private function rules_state() {
		$out = $this->client->custom_rules(
			$this->cloudflare->token(),
			(string) $this->cloudflare->get( 'zone_id' )
		);
		if ( isset( $out['error'] ) ) {
			$status = isset( $out['status'] ) ? (int) $out['status'] : 0;
			return array(
				'canRead'         => false,
				'needsPermission' => in_array( $status, array( 401, 403 ), true ),
				'error'           => (string) $out['error'],
				'ruleCount'       => 0,
				'ourRule'         => false,
			);
		}

		$ours = false;
		foreach ( (array) $out['rules'] as $rule ) {
			if ( isset( $rule['ref'] ) && EdgeRule::REF === $rule['ref'] ) {
				$ours = true;
			}
		}
		return array(
			'canRead'         => true,
			'needsPermission' => false,
			'error'           => '',
			'ruleCount'       => count( (array) $out['rules'] ),
			'ourRule'         => $ours,
		);
	}

	/**
	 * @param array      $check  An {@see EdgeRule::check()} result.
	 * @param bool       $listed Whether the name is on the block list.
	 * @param array      $log    Rows from the site's log.
	 * @param array|null $edge   The edge sightings, or null when not asked.
	 * @param array|null $rules  The rules state, or null when not asked.
	 * @return array
	 */
	private function answer( array $check, $listed, array $log = array(), ?array $edge = null, ?array $rules = null ) {
		$ok = ! empty( $check['ok'] );
		$rule = null;
		if ( $ok ) {
			$rule = array(
				'action'     => EdgeRule::ACTION,
				'expression' => EdgeRule::expression( array( $check['token'] => $check['form'] ) ),
			);
		}
		return array(
			'token'        => (string) $check['token'],
			'listed'       => (bool) $listed,
			'ok'           => $ok,
			'ready'        => $ok && null !== $rules && ! empty( $rules['canRead'] ),
			'refusals'     => (array) $check['refusals'],
			'otherClients' => (array) $check['otherClients'],
			'log'          => array( 'clients' => array_slice( $log, 0, self::MAX_CLIENTS ) ),
			'edge'         => null !== $edge
				? array_merge( $edge, array( 'clients' => array_slice( (array) $edge['clients'], 0, self::MAX_CLIENTS ) ) )
				: array( 'checked' => false, 'complete' => false, 'error' => '', 'clients' => array() ),
			'rule'         => $rule,
			'cloudflare'   => null !== $rules
				? $rules
				: array( 'canRead' => false, 'needsPermission' => false, 'error' => '', 'ruleCount' => 0, 'ourRule' => false ),
		);
	}
}
