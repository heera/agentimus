<?php
/**
 * Agent Access — the pure decision layer.
 *
 * The heart of this is {@see Events::ability_coverage()}, which decides what we may
 * honestly CLAIM to see on a given site. It has to be right for a reason that goes beyond
 * correctness: if it reports "monitoring active" on a site whose Abilities API never emits
 * an execute hook, the owner sees a permanently empty feed and reasonably concludes that
 * nothing is touching their site. False reassurance is the one failure mode this feature
 * must not have, so every rung of the ladder is pinned here.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\AgentAccess\Events;
use PHPUnit\Framework\TestCase;

final class AgentAccessEventsTest extends TestCase {

	/* -- The ladder -------------------------------------------------------- */

	public function test_core_69_and_up_is_always_full_coverage() {
		// Core has emitted wp_before_execute_ability since 6.9 (the hooks are @since 6.9.0),
		// so a core-provided API needs no probe — $hooks_seen is irrelevant here.
		$this->assertSame( Events::COVERAGE_FULL, Events::ability_coverage( true, '6.9', null ) );
		$this->assertSame( Events::COVERAGE_FULL, Events::ability_coverage( true, '7.0.1', null ) );
		$this->assertSame( Events::COVERAGE_FULL, Events::ability_coverage( true, '7.0.1', false ) );
	}

	public function test_prerelease_core_is_not_mistaken_for_older() {
		// version_compare() sorts "6.9-beta1" BELOW "6.9", which would misclassify a 6.9 beta
		// as a pre-6.9 site and send it down the feature-plugin branch. The leading-numeric
		// normalisation in Events prevents that.
		$this->assertSame( Events::COVERAGE_FULL, Events::ability_coverage( true, '6.9-beta1', null ) );
		$this->assertSame( Events::COVERAGE_FULL, Events::ability_coverage( true, '6.9-RC2', null ) );
	}

	public function test_feature_plugin_with_working_hooks_is_full_coverage() {
		// Pre-6.9 the API can only be the standalone plugin. Once we have WATCHED a global
		// execute hook fire, third-party abilities really are observable.
		$this->assertSame( Events::COVERAGE_FULL, Events::ability_coverage( true, '6.8', true ) );
	}

	public function test_feature_plugin_that_never_fires_hooks_is_own_only() {
		// abilities-api v0.1-v0.3 register abilities but emit no execute hooks. We can still see
		// OUR abilities (we own their execute callbacks) but not anyone else's — and we must say so.
		$this->assertSame( Events::COVERAGE_OWN_ONLY, Events::ability_coverage( true, '6.8', false ) );
		$this->assertFalse( Events::covers_third_party( Events::COVERAGE_OWN_ONLY ) );
	}

	public function test_feature_plugin_before_any_ability_ran_is_pending_not_full() {
		// The critical case. On a pre-6.9 site we have NOT yet proven whether the installed API
		// announces invocations, because no ability has run. Answering FULL here is exactly the
		// false-reassurance bug; the honest answer is "not yet known".
		$coverage = Events::ability_coverage( true, '6.8', null );
		$this->assertSame( Events::COVERAGE_PENDING, $coverage );
		$this->assertNotSame( Events::COVERAGE_FULL, $coverage );
		$this->assertFalse( Events::covers_third_party( $coverage ) );
	}

	public function test_no_api_but_installable_when_wp_supports_the_plugin() {
		// The abilities-api plugin requires WP 6.8+. At or above that, the owner has an
		// actionable path, so the UI must offer it rather than just saying "unavailable".
		$this->assertSame( Events::COVERAGE_INSTALLABLE, Events::ability_coverage( false, '6.8', null ) );
		$this->assertSame( Events::COVERAGE_INSTALLABLE, Events::ability_coverage( false, '6.8.2', null ) );
	}

	public function test_no_api_and_too_old_to_add_one() {
		$this->assertSame( Events::COVERAGE_UNSUPPORTED, Events::ability_coverage( false, '6.0', null ) );
		$this->assertSame( Events::COVERAGE_UNSUPPORTED, Events::ability_coverage( false, '6.7.1', null ) );
	}

	public function test_only_full_coverage_claims_third_party_visibility() {
		$this->assertTrue( Events::covers_third_party( Events::COVERAGE_FULL ) );
		foreach ( array( Events::COVERAGE_OWN_ONLY, Events::COVERAGE_PENDING, Events::COVERAGE_INSTALLABLE, Events::COVERAGE_UNSUPPORTED ) as $partial ) {
			$this->assertFalse( Events::covers_third_party( $partial ), "$partial must not claim third-party coverage" );
		}
	}

	public function test_states_without_an_api_have_no_abilities_to_monitor() {
		// In these two states our own Registrar registers nothing either (it is function_exists
		// guarded), so there is genuinely nothing to watch — "unavailable" is the truth, not a
		// shortcoming, and the UI copy depends on the difference.
		$this->assertFalse( Events::has_abilities( Events::COVERAGE_INSTALLABLE ) );
		$this->assertFalse( Events::has_abilities( Events::COVERAGE_UNSUPPORTED ) );
		$this->assertTrue( Events::has_abilities( Events::COVERAGE_FULL ) );
		$this->assertTrue( Events::has_abilities( Events::COVERAGE_OWN_ONLY ) );
		$this->assertTrue( Events::has_abilities( Events::COVERAGE_PENDING ) );
	}

	/* -- Kinds ------------------------------------------------------------- */

	public function test_known_kinds_are_accepted_and_junk_is_not() {
		$this->assertTrue( Events::is_kind( Events::KIND_APPPW_CREATED ) );
		$this->assertTrue( Events::is_kind( Events::KIND_APPPW_USED ) );
		$this->assertTrue( Events::is_kind( Events::KIND_ABILITY_USED ) );
		$this->assertFalse( Events::is_kind( 'nonsense' ) );
		$this->assertFalse( Events::is_kind( '' ) );
	}

	/* -- Shaping ----------------------------------------------------------- */

	public function test_shape_casts_types_and_renders_utc_timestamps() {
		$out = Events::shape(
			array(
				'id'       => '7',
				'kind'     => Events::KIND_APPPW_CREATED,
				'user_id'  => '3',
				'cred'     => 'abcd-uuid',
				'subject'  => 'zapier',
				'detail'   => '',
				'hits'     => '1',
				'first_at' => '2026-07-12 08:30:00',
				'last_at'  => '2026-07-12 08:30:00',
				'seen'     => '0',
			)
		);

		$this->assertSame( 7, $out['id'] );
		$this->assertSame( 3, $out['userId'] );
		$this->assertSame( 1, $out['hits'] );
		$this->assertSame( 'zapier', $out['subject'] );
		$this->assertFalse( $out['seen'] );
		$this->assertSame( '2026-07-12T08:30:00+00:00', $out['firstSeen'] );
	}

	public function test_shape_survives_a_partial_row() {
		$out = Events::shape( array( 'kind' => Events::KIND_ABILITY_USED ) );
		$this->assertSame( 0, $out['id'] );
		$this->assertSame( '', $out['firstSeen'] );
		$this->assertFalse( $out['seen'] );
	}

	public function test_shape_exposes_exactly_the_agreed_fields_and_no_personal_data() {
		// A standing guarantee, not a style point: this feature stores no personal data, which is
		// what keeps it out of the privacy declaration and out of alert-fatigue territory (every
		// noisy false positive a feature like this produces comes from location-newness). Pinning
		// the exact key set means adding an IP, a hostname or a location field fails loudly here
		// rather than quietly shipping.
		//
		// `user` and `credName` were added deliberately (2026-07-14, "this tells nothing about
		// the visitor"): both are the OWNER's own data — their user login and their password
		// label — resolved live at render time and never stored, so the guarantee above stands.
		$out = Events::shape(
			array(
				'id'      => 1,
				'kind'    => Events::KIND_APPPW_USED,
				'user_id' => 1,
			)
		);

		$this->assertSame(
			array( 'id', 'kind', 'userId', 'cred', 'user', 'credName', 'subject', 'detail', 'hits', 'firstSeen', 'lastSeen', 'seen' ),
			array_keys( $out )
		);
	}

	public function test_shape_who_fields_degrade_to_empty_when_unresolvable() {
		// The unit env has no get_user_by / WP_Application_Passwords — exactly the shape of a
		// row whose user or credential no longer exists. The fields must be '' (the UI words
		// that case), never a fatal, never an invented value.
		$out = Events::shape(
			array(
				'kind'    => Events::KIND_ABILITY_USED,
				'user_id' => 42,
				'cred'    => 'dead-beef-uuid',
			)
		);
		$this->assertSame( '', $out['user'] );
		$this->assertSame( '', $out['credName'] );
	}

	/* -- classify(): what a FAILED abilities request means -------------------- */

	/**
	 * Verified against WP 7.0.1. The gate order inside check_ability_permissions() is
	 * not-found -> method -> INPUT -> permissions, so the permission gate is LAST.
	 */
	public function test_a_refused_request_is_the_sharp_signal() {
		// Anonymous, well-formed request for a real ability.
		$this->assertSame( Events::KIND_ABILITY_REFUSED, Events::classify( 401, 0 ) );
		// Logged in, not permitted.
		$this->assertSame( Events::KIND_ABILITY_REFUSED, Events::classify( 403, 231 ) );
	}

	public function test_guessing_ability_names_is_a_probe() {
		$this->assertSame( Events::KIND_ABILITY_PROBED, Events::classify( 404, 0 ) );
		$this->assertSame( Events::KIND_ABILITY_PROBED, Events::classify( 404, 231 ) );
	}

	public function test_an_anonymous_malformed_request_is_the_scanner_signature() {
		// THIS is the case that would be missed by a naive "log the 403s" design. The permission
		// gate is behind an INPUT gate, so a scanner throwing junk at ability endpoints produces
		// 400s and 404s and never a 403. Drop these and a naive scan leaves no trace at all, while
		// the screen goes on reporting an all-clear.
		$this->assertSame( Events::KIND_ABILITY_PROBED, Events::classify( 400, 0 ) );
		$this->assertSame( Events::KIND_ABILITY_PROBED, Events::classify( 405, 0 ) );
	}

	public function test_a_logged_in_callers_malformed_request_is_their_bug_not_our_signal() {
		// A developer's broken integration sends bad input all day. Recording it would fill the feed
		// with their own mistake — the alert fatigue the rollup exists to prevent.
		$this->assertNull( Events::classify( 400, 231 ) );
		$this->assertNull( Events::classify( 405, 231 ) );
	}

	public function test_a_successful_request_is_never_a_refusal() {
		$this->assertNull( Events::classify( 200, 1 ) );
		$this->assertNull( Events::classify( 0, 1 ) );
	}

	/* -- The cardinality guard ---------------------------------------------- */

	public function test_only_a_real_ability_may_be_named_in_subject() {
		// A refusal implies the ability EXISTS (the 404 gate fires first), so the name is one of a
		// bounded set. A probe's name is ATTACKER-SUPPLIED — storing it would let ten thousand
		// guessed names mint ten thousand rows. Bound the cardinality at the point of insert; never
		// hope the row cap catches it later.
		$this->assertTrue( Events::names_a_real_ability( Events::KIND_ABILITY_REFUSED ) );
		$this->assertTrue( Events::names_a_real_ability( Events::KIND_ABILITY_USED ) );
		$this->assertFalse( Events::names_a_real_ability( Events::KIND_ABILITY_PROBED ) );
	}
}
