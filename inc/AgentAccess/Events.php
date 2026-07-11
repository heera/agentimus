<?php
/**
 * Events — the pure decision layer for Agent Access. No WordPress, no database:
 * every function here is a total function of its arguments, so the rules that
 * decide WHAT we may honestly claim are unit-tested in isolation (the same shape
 * as {@see \Agentimus\Guard::denies()} and {@see \Agentimus\Activity\Repository::analyze_threats()}).
 *
 * The interesting logic is {@see ability_coverage()}. "Can we see ability
 * invocations on this site?" has four answers, not two, and getting it wrong is
 * not a cosmetic bug: reporting "monitoring active" on a site where no hook ever
 * fires would show the owner a permanently empty feed and let them conclude that
 * nothing is touching their site. For a feature like this, false reassurance is
 * the worst possible failure — worse than saying nothing at all.
 *
 * @package Agentimus
 */

namespace Agentimus\AgentAccess;

defined( 'ABSPATH' ) || exit;

final class Events {

	/* -- Event kinds ------------------------------------------------------- */

	/** An application password was minted. The one that matters most: it survives a password reset. */
	const KIND_APPPW_CREATED = 'apppw_created';

	/** An application password authenticated a request for the FIRST time — a dormant key woke up. */
	const KIND_APPPW_USED = 'apppw_used';

	/** An application password was revoked/removed. */
	const KIND_APPPW_DELETED = 'apppw_deleted';

	/** An existing application password was renamed / had its record updated. */
	const KIND_APPPW_RENAMED = 'apppw_renamed';

	/** An ability was executed. Rolls up: we alert on the FIRST use, then count. */
	const KIND_ABILITY_USED = 'ability_used';

	/* -- Ability-coverage states (see ability_coverage) --------------------- */

	/** Every ability is observable — ours and other plugins'. */
	const COVERAGE_FULL = 'full';

	/** The Abilities API is present but emits no execute hooks (feature plugin < v0.4.0),
	 *  so only the abilities Agentimus itself registered are observable. */
	const COVERAGE_OWN_ONLY = 'own_only';

	/** API present, pre-6.9, and no ability has run yet — so we have not yet PROVEN whether
	 *  the installed API emits execute hooks. Honest "not yet known", never "all clear". */
	const COVERAGE_PENDING = 'pending';

	/** No Abilities API, but this WordPress is new enough to install the feature plugin. */
	const COVERAGE_INSTALLABLE = 'installable';

	/** No Abilities API and too old to add one. */
	const COVERAGE_UNSUPPORTED = 'unsupported';

	/**
	 * First WordPress release that ships the Abilities API in core. Core at or above this
	 * always emits `wp_before_execute_ability` (the hooks are tagged `@since 6.9.0`), so a
	 * core-provided API needs no probe.
	 */
	const CORE_ABILITIES_VERSION = '6.9';

	/**
	 * Minimum WordPress the standalone `WordPress/abilities-api` feature plugin supports
	 * ("Requires at least: 6.8"). Below this, a site cannot have abilities at all.
	 */
	const PLUGIN_ABILITIES_VERSION = '6.8';

	/**
	 * Every kind we record.
	 *
	 * @return string[]
	 */
	public static function kinds() {
		return array(
			self::KIND_APPPW_CREATED,
			self::KIND_APPPW_USED,
			self::KIND_APPPW_DELETED,
			self::KIND_APPPW_RENAMED,
			self::KIND_ABILITY_USED,
		);
	}

	/**
	 * @param string $kind Candidate kind.
	 * @return bool
	 */
	public static function is_kind( $kind ) {
		return in_array( (string) $kind, self::kinds(), true );
	}

	/**
	 * Decide, honestly, what we can see of ability invocations on this site.
	 *
	 * The Abilities API reaches a site by two different roads, and they do NOT behave the
	 * same. Core has shipped it since 6.9 and has always emitted the execute hooks. The
	 * standalone feature plugin (which is how a 6.8 site gets abilities) only started
	 * emitting them in v0.4.0 — v0.1 through v0.3 register abilities and announce nothing.
	 * The plugin exposes no version constant, so there is nothing cheap to interrogate.
	 *
	 * Hence: we never infer hook support from a version number. We PROVE it — the caller
	 * passes what it has actually observed ($hooks_seen), and until an ability has run we
	 * answer PENDING rather than guessing. See Module::observe_own_ability().
	 *
	 * @param bool        $api_present Whether the Abilities API exists at all (wp_register_ability).
	 * @param string      $wp_version  The running WordPress version.
	 * @param bool|null   $hooks_seen  TRUE  = we have watched a global execute hook fire.
	 *                                 FALSE = an ability ran and the hook demonstrably did NOT fire.
	 *                                 NULL  = not yet observed.
	 * @return string One of the COVERAGE_* constants.
	 */
	public static function ability_coverage( $api_present, $wp_version, $hooks_seen = null ) {
		$wp = self::numeric_version( $wp_version );

		if ( ! $api_present ) {
			return version_compare( $wp, self::PLUGIN_ABILITIES_VERSION, '>=' )
				? self::COVERAGE_INSTALLABLE
				: self::COVERAGE_UNSUPPORTED;
		}

		// Core provides the API (and therefore the execute hooks) from 6.9 on. Nothing to prove.
		if ( version_compare( $wp, self::CORE_ABILITIES_VERSION, '>=' ) ) {
			return self::COVERAGE_FULL;
		}

		// Pre-6.9 the API can only be the feature plugin, whose older releases are silent.
		if ( true === $hooks_seen ) {
			return self::COVERAGE_FULL;
		}
		if ( false === $hooks_seen ) {
			return self::COVERAGE_OWN_ONLY;
		}
		return self::COVERAGE_PENDING;
	}

	/**
	 * Whether a coverage state means third-party abilities (i.e. abilities Agentimus did not
	 * register) are being watched. Drives the UI's "what you are NOT seeing" copy.
	 *
	 * @param string $coverage A COVERAGE_* constant.
	 * @return bool
	 */
	public static function covers_third_party( $coverage ) {
		return self::COVERAGE_FULL === $coverage;
	}

	/**
	 * Whether the site has abilities at all. In the two "no API" states our own Registrar
	 * registers nothing either (it is function_exists-guarded), so there is genuinely nothing
	 * to monitor — "unavailable" is the truth, not a shortcoming.
	 *
	 * @param string $coverage A COVERAGE_* constant.
	 * @return bool
	 */
	public static function has_abilities( $coverage ) {
		return ! in_array( $coverage, array( self::COVERAGE_INSTALLABLE, self::COVERAGE_UNSUPPORTED ), true );
	}

	/**
	 * Normalise a WordPress version to its leading numeric part, so a pre-release like
	 * "6.9-beta1" compares as 6.9 rather than sorting BELOW it (version_compare treats a
	 * "-beta" suffix as older). Without this a 6.9 beta would be misread as pre-6.9.
	 *
	 * @param string $version Raw version string.
	 * @return string Numeric version, or '0' when unparseable.
	 */
	private static function numeric_version( $version ) {
		return preg_match( '/^\d+(?:\.\d+)*/', (string) $version, $m ) ? $m[0] : '0';
	}

	/**
	 * Shape one stored row for the REST/admin payload. Field names match the camelCase the
	 * Vue app already consumes elsewhere; timestamps become ISO-8601 UTC the same way
	 * {@see \Agentimus\Activity\FlaggedIps::for_keys()} does it.
	 *
	 * @param array $row Raw DB row.
	 * @return array
	 */
	public static function shape( array $row ) {
		return array(
			'id'        => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'kind'      => isset( $row['kind'] ) ? (string) $row['kind'] : '',
			'userId'    => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'cred'      => isset( $row['cred'] ) ? (string) $row['cred'] : '',
			'subject'   => isset( $row['subject'] ) ? (string) $row['subject'] : '',
			'detail'    => isset( $row['detail'] ) ? (string) $row['detail'] : '',
			'hits'      => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			'firstSeen' => self::iso( isset( $row['first_at'] ) ? $row['first_at'] : '' ),
			'lastSeen'  => self::iso( isset( $row['last_at'] ) ? $row['last_at'] : '' ),
			'seen'      => ! empty( $row['seen'] ),
		);
	}

	/**
	 * A stored GMT datetime as ISO-8601.
	 *
	 * @param string $mysql_gmt MySQL datetime in GMT.
	 * @return string
	 */
	private static function iso( $mysql_gmt ) {
		$mysql_gmt = (string) $mysql_gmt;
		if ( '' === $mysql_gmt ) {
			return '';
		}
		$ts = strtotime( $mysql_gmt . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : '';
	}
}
