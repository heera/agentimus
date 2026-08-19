<?php
/**
 * Agent Access against a real WordPress + a real database.
 *
 * Two halves, and they have deliberately different reach:
 *
 *   - The APPLICATION PASSWORD half must work on every WordPress we support (5.6+, and our
 *     floor is 6.0), so those tests run unconditionally — including on the WP 6.0 leg of the
 *     CI matrix.
 *   - The ABILITY half only exists where the Abilities API does (core 6.9+, or the standalone
 *     feature plugin). Those tests skip cleanly below that, and that skip is itself the point:
 *     the WP 6.0 leg proves the feature is genuinely INERT on an old site rather than fatal.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\AgentAccess\Events;
use Agentimus\AgentAccess\Module;
use Agentimus\AgentAccess\Store;
use Agentimus\AgentAccess\Table;
use WP_Application_Passwords;

final class AgentAccessDbTest extends DbTestCase {

	/** @var int */
	private $admin;

	public function set_up(): void {
		parent::set_up();

		Table::install();
		Store::clear();
		delete_option( Module::HOOKS_OPTION );
		$this->reset_request_state();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down(): void {
		Store::clear();
		delete_option( Module::HOOKS_OPTION );
		parent::tear_down();
	}

	/* -- Credential lifecycle (every supported WordPress) ------------------ */

	public function test_minting_an_application_password_records_exactly_one_event() {
		$created = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'zapier' ) );
		$this->assertNotWPError( $created );

		$events = Store::recent();
		$this->assertCount( 1, $events, 'One created password must produce exactly one event.' );

		$event = $events[0];
		$this->assertSame( Events::KIND_APPPW_CREATED, $event['kind'] );
		$this->assertSame( $this->admin, $event['userId'] );
		$this->assertSame( 'zapier', $event['subject'] );
		$this->assertSame( 1, $event['hits'] );
		$this->assertFalse( $event['seen'], 'A brand-new event must be unseen, so the bell lights up.' );
		$this->assertNotSame( '', $event['cred'], 'The event must name the credential by its UUID.' );
	}

	public function test_the_plaintext_password_is_never_stored_anywhere() {
		// wp_create_application_password hands the callback the PLAINTEXT password. It must never
		// reach the log. This is the single most important negative assertion in the suite: the
		// whole feature is worthless if watching for a stolen credential is what leaks one.
		$created = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'leaky' ) );
		$this->assertNotWPError( $created );

		$plaintext = $created[0];
		$this->assertNotEmpty( $plaintext );

		global $wpdb;
		$table = Table::name();
		$dump  = (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM $table", ARRAY_A ) ); // phpcs:ignore WordPress.DB

		$this->assertStringNotContainsString( $plaintext, $dump, 'The plaintext application password must never be stored.' );
		// Belt and braces: nor with the spaces WordPress displays it with stripped.
		$this->assertStringNotContainsString( str_replace( ' ', '', $plaintext ), $dump );
	}

	public function test_revoking_an_application_password_is_recorded() {
		$created = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'doomed' ) );
		$this->assertNotWPError( $created );
		$uuid = $created[1]['uuid'];

		WP_Application_Passwords::delete_application_password( $this->admin, $uuid );

		$kinds = wp_list_pluck( Store::recent(), 'kind' );
		$this->assertContains( Events::KIND_APPPW_DELETED, $kinds );
	}

	/* -- The hot path ------------------------------------------------------ */

	public function test_repeated_authentication_writes_nothing_after_the_first() {
		// THE regression test for this feature. `application_password_did_authenticate` fires on
		// EVERY successful app-password REST call, so an agent making 200 calls fires it 200 times.
		// This once guarded on wp_cache_get(), which was a real bug: WordPress's default object
		// cache is NON-persistent, so on any site without a Redis/Memcached drop-in — i.e. most
		// sites — the guard started empty every request and every authenticated call fell through
		// to a database write.
		//
		// The guard is now core's own persisted `last_used`, so this test replays core's exact
		// order (wp-includes/user.php:486 then :496) across several "requests".
		$created = WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'busy-agent' ) );
		$this->assertNotWPError( $created );
		$uuid = $created[1]['uuid'];
		$user = get_user_by( 'id', $this->admin );

		$this->assertNull( $created[1]['last_used'], 'A fresh password must start with no last_used — that is the signal we rely on.' );

		for ( $i = 0; $i < 5; $i++ ) {
			// wp_cache_flush() is what gives this test its teeth. The whole suite runs in ONE PHP
			// process, where even a non-persistent object cache survives between iterations — so
			// without this the OLD, broken wp_cache_get() guard would pass too and the test would
			// prove nothing. Flushing reproduces the cold cache each real request actually gets.
			wp_cache_flush();

			$item = $this->app_password( $uuid );
			WP_Application_Passwords::record_application_password_usage( $this->admin, $uuid );
			do_action( 'application_password_did_authenticate', $user, $item );
		}

		$used = Store::recent( 100, Events::KIND_APPPW_USED );
		$this->assertCount( 1, $used, 'Five authenticated requests must produce exactly one event.' );
		$this->assertSame( 1, $used[0]['hits'], 'Only the FIRST use is news; the rest must not write at all.' );
	}

	/**
	 * Module::$hooked records which abilities the global hook fired for during THIS REQUEST. The
	 * whole suite is one PHP process, so without this an earlier test's mark survives into the
	 * next — and the own-callback test would see a stale "the hook fired" and skip recording,
	 * silently passing for the wrong reason.
	 *
	 * @return void
	 */
	private function reset_request_state() {
		$hooked = new \ReflectionProperty( Module::class, 'hooked' );
		\_af_accessible( $hooked );
		$hooked->setValue( null, array() );
	}

	/**
	 * The stored record for one application password, as core hands it to the hook.
	 *
	 * @param string $uuid Application-password UUID.
	 * @return array
	 */
	private function app_password( $uuid ) {
		foreach ( WP_Application_Passwords::get_user_application_passwords( $this->admin ) as $item ) {
			if ( $uuid === $item['uuid'] ) {
				return $item;
			}
		}
		return array();
	}

	/* -- The rollup + alert contract --------------------------------------- */

	public function test_a_repeat_event_rolls_up_and_re_alerts() {
		// The alert contract, as the owner set it: EVERY access re-lights the pill, without exception.
		// A repeat still rolls up into one row (not a second insert) and still reports as NOT-new from
		// record() — the "new" return is a caller convenience — but it resets `seen`, so the unread
		// count climbs again and the owner sees every access, however routine.
		$first = Store::record( Events::KIND_ABILITY_USED, $this->admin, 'cred-1', 'agentimus/read-readiness' );
		$this->assertTrue( $first, 'The first occurrence must report as new.' );

		Store::mark_seen();
		$this->assertSame( 0, Store::unseen_count(), 'Reading the feed clears the count.' );

		$second = Store::record( Events::KIND_ABILITY_USED, $this->admin, 'cred-1', 'agentimus/read-readiness' );
		$this->assertFalse( $second, 'A repeat must still report as NOT-new (that return is unchanged).' );

		$events = Store::recent();
		$this->assertCount( 1, $events, 'A repeat must roll up, not insert a second row.' );
		$this->assertSame( 2, $events[0]['hits'] );
		$this->assertFalse( $events[0]['seen'], 'A rolled-up repeat must reset the seen flag — every access re-alerts.' );
		$this->assertSame( 1, Store::unseen_count(), 'The pill must re-light on any repeat access.' );
	}

	public function test_a_different_credential_using_the_same_ability_is_a_separate_event() {
		Store::record( Events::KIND_ABILITY_USED, $this->admin, 'cred-1', 'agentimus/read-readiness' );
		$fresh = Store::record( Events::KIND_ABILITY_USED, $this->admin, 'cred-2', 'agentimus/read-readiness' );

		$this->assertTrue( $fresh, 'A new credential touching a known ability is genuinely new.' );
		$this->assertCount( 2, Store::recent() );
	}

	public function test_unknown_kinds_are_refused() {
		$this->assertFalse( Store::record( 'not_a_kind', $this->admin, 'c', 's' ) );
		$this->assertCount( 0, Store::recent() );
	}

	public function test_the_cursor_walk_reaches_every_row_exactly_once() {
		// The point of paging over a "show more" button: a button with a ceiling leaves the rest of
		// a large table permanently unreachable — on a flood, precisely when the owner most needs to
		// read it. Walking must therefore be exhaustive AND non-repeating.
		//
		// Ordering is by `last_at DESC, id DESC` (newest activity on top, the owner's rule), which on a
		// rollup table is a MOVING key — so the cursor is composite, "last_at~id", and `id` breaks the
		// (frequent) one-second ties. That composite keyset is exactly what keeps a row from hopping
		// pages mid-walk, so the walk stays exhaustive and non-repeating even though the sort key moves.
		$rows = 250;
		for ( $i = 0; $i < $rows; $i++ ) {
			Store::record( Events::KIND_ABILITY_USED, $this->admin, 'cred-' . ( $i % 5 ), "agentimus/ability-$i" );
		}

		$seen   = array();
		$before = '';
		$pages  = 0;
		do {
			$page = Store::page( 40, null, $before );
			++$pages;
			foreach ( $page['events'] as $event ) {
				$this->assertArrayNotHasKey( $event['id'], $seen, 'A row must never appear on two pages.' );
				$seen[ $event['id'] ] = true;
			}
			$before = $page['cursor'];
		} while ( $page['hasMore'] && $pages < 100 );

		$this->assertCount( $rows, $seen, 'The cursor walk must reach every row in the table.' );
		$this->assertSame( Store::total(), count( $seen ) );
	}

	public function test_the_most_recently_active_row_floats_to_the_top() {
		// The owner's rule: the newest activity is always on top. On a rollup table "newest" is
		// last_at, NOT the insertion id — a row inserted early but touched again just now must
		// out-rank a row inserted later that has been quiet since.
		Store::record( Events::KIND_ABILITY_USED, $this->admin, 'cred-1', 'agentimus/ability-early' );
		Store::record( Events::KIND_ABILITY_USED, $this->admin, 'cred-1', 'agentimus/ability-late' );

		// current_time() has one-second granularity, so all of the above can share a timestamp and the
		// tiebreak would (correctly) fall to id — putting 'late' on top. To prove the SORT keys on
		// last_at rather than id, push both rows into the past, then touch 'early' so its last_at is
		// strictly newer despite its lower id.
		global $wpdb;
		$table = Table::name();
		$past  = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET last_at = %s", $past ) ); // phpcs:ignore WordPress.DB

		Store::record( Events::KIND_ABILITY_USED, $this->admin, 'cred-1', 'agentimus/ability-early' );

		$events = Store::recent();
		$this->assertCount( 2, $events, 'Two distinct abilities, two rows.' );
		$this->assertSame( 'agentimus/ability-early', $events[0]['subject'], 'The most recently ACTIVE row must sort to the top, even with the lower id.' );
		$this->assertSame( 2, $events[0]['hits'], 'The floated row is the one that was touched again.' );
		$this->assertSame( 'agentimus/ability-late', $events[1]['subject'] );
	}

	public function test_the_row_cap_survives_a_flood_written_in_the_same_second() {
		// The cap used to be enforced with `DELETE ... WHERE last_at < cutoff`. last_at comes from
		// current_time('mysql'), which has ONE-SECOND granularity — so a burst (a runaway
		// integration, or a compromised admin minting keys in a loop) writes every row with an
		// IDENTICAL timestamp, nothing is strictly `<` the cutoff, and the delete silently no-ops.
		// The cap held under a trickle and failed under exactly the flood it exists for.
		//
		// Writing all of these inside one test guarantees they share a timestamp, which is what
		// makes this a real regression test rather than a decorative one.
		$over = Store::MAX_ROWS + 50;
		for ( $i = 0; $i < $over; $i++ ) {
			Store::record( Events::KIND_APPPW_CREATED, $this->admin, "flood-$i", "key-$i" );
		}

		Store::prune();

		$this->assertSame( Store::MAX_ROWS, Store::total(), 'The hard row cap must hold even when every row shares one timestamp.' );
	}

	public function test_retention_prune_drops_stale_events() {
		Store::record( Events::KIND_APPPW_USED, $this->admin, 'old-cred', 'ancient' );

		global $wpdb;
		$table = Table::name();
		$stale = gmdate( 'Y-m-d H:i:s', time() - ( ( Store::RETENTION_DAYS + 5 ) * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET last_at = %s", $stale ) ); // phpcs:ignore WordPress.DB

		Store::prune();

		$this->assertCount( 0, Store::recent() );
	}

	/* -- Abilities (only where the API exists) ----------------------------- */

	public function test_executing_one_of_our_abilities_is_recorded() {
		$this->skip_without_abilities();

		$ability = wp_get_ability( 'agentimus/read-readiness' );
		$this->assertNotNull( $ability, 'Our abilities should be registered when the API is present.' );

		// An empty object, not null: the ability declares an object input schema, and core validates
		// the input BEFORE the permission check and before any hook fires.
		$result = $ability->execute( array() );
		$this->assertNotWPError( $result );

		$events = Store::recent( 100, Events::KIND_ABILITY_USED );
		$this->assertCount( 1, $events, 'One ability run must produce exactly one event (never double-counted).' );
		$this->assertSame( 'agentimus/read-readiness', $events[0]['subject'] );
		$this->assertSame( $this->admin, $events[0]['userId'] );
	}

	public function test_running_an_ability_proves_the_execute_hooks_and_reports_full_coverage() {
		$this->skip_without_abilities();

		// Before anything has run we have proven nothing about hook support.
		$this->assertFalse( get_option( Module::HOOKS_OPTION, false ) );

		wp_get_ability( 'agentimus/read-readiness' )->execute( array() );

		// Core (6.9+) emits wp_before_execute_ability, so watching one run PROVES that third-party
		// abilities are observable here. That is the capability probe: we never infer this from a
		// version number, because the standalone abilities-api plugin registered abilities without
		// emitting these hooks until v0.4.0 — and reporting "monitoring active" on such a site
		// would show the owner a permanently empty feed.
		$this->assertSame( '1', get_option( Module::HOOKS_OPTION ) );
		$this->assertSame( Events::COVERAGE_FULL, Module::coverage() );
	}

	public function test_an_abilities_api_that_never_fires_the_hook_still_records_our_own_abilities() {
		$this->skip_without_abilities();

		// THE version-proof-floor guarantee, and the only place it is exercised. abilities-api
		// v0.1-v0.3 register abilities but never emit wp_before_execute_ability, so on those sites
		// the global listener is silent. Removing the listener reproduces exactly that. What must
		// still hold: we own our abilities' execute callbacks, so OUR abilities stay observable —
		// "we always monitor what we exposed" — and the probe must notice the hook never fired.
		// remove_all_actions(), not remove_action(): WordPress matches a callback by object
		// INSTANCE, and the live listener belongs to the Module that Plugin::boot() constructed —
		// which this test has no handle on. Dropping every listener on the hook reproduces what
		// our code actually sees on an old API: the mark is never set.
		remove_all_actions( 'wp_before_execute_ability' );

		wp_get_ability( 'agentimus/read-readiness' )->execute( array() );

		$events = Store::recent( 100, Events::KIND_ABILITY_USED );
		$this->assertCount( 1, $events, 'Our own ability must still be recorded with no global hook.' );
		$this->assertSame( 'agentimus/read-readiness', $events[0]['subject'] );
		$this->assertSame( 'own', $events[0]['detail'], 'It must be recorded via the own-callback path, not the hook.' );

		// And the probe must have PROVEN the hooks are absent, so the UI can say so rather than
		// showing a confident, permanently-incomplete feed.
		$this->assertSame( '0', get_option( Module::HOOKS_OPTION ) );
		$this->assertSame(
			Events::COVERAGE_OWN_ONLY,
			Events::ability_coverage( true, '6.8', false ),
			'A pre-6.9 site whose API never announces an invocation is own-abilities-only.'
		);
	}

	public function test_the_feed_reports_its_own_coverage_honestly() {
		$request  = new \WP_REST_Request( 'GET', '/agentimus/v1/agent-access' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'coverage', $data );
		$this->assertArrayHasKey( 'hasAbilities', $data );
		$this->assertArrayHasKey( 'thirdParty', $data );
		$this->assertArrayHasKey( 'passwords', $data );

		// The payload must always describe what it can and cannot see, so the UI can never render
		// a bare empty table that reads as "all clear" when the truth is "we see nothing here".
		$this->assertContains(
			$data['coverage'],
			array(
				Events::COVERAGE_FULL,
				Events::COVERAGE_OWN_ONLY,
				Events::COVERAGE_PENDING,
				Events::COVERAGE_INSTALLABLE,
				Events::COVERAGE_UNSUPPORTED,
			)
		);
		$this->assertSame( function_exists( 'wp_register_ability' ), $data['hasAbilities'] );
	}

	public function test_the_activity_payload_carries_the_unread_count_for_the_nav_badge() {
		// The nav badge goes live by riding /activity — the payload the review bell already polls —
		// rather than polling its own endpoint every tick. That makes this field a real contract:
		// drop it in some future refactor of /activity and the badge silently stops updating, with
		// nothing else in the suite to notice.
		Store::record( Events::KIND_APPPW_CREATED, $this->admin, 'cred-x', 'Zapier' );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/activity' ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'agentAccessUnseen', $data, '/activity must carry the Agent access unread count.' );
		$this->assertSame( 1, $data['agentAccessUnseen'] );

		Store::mark_seen();
		$after = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/activity' ) )->get_data();
		$this->assertSame( 0, $after['agentAccessUnseen'], 'Reading the feed must clear the badge on the next poll.' );
	}

	public function test_the_feed_is_admin_only() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/agent-access' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The ability half of this feature only exists where the Abilities API does. Skipping is the
	 * correct behaviour below that, and the WP 6.0 leg of CI relies on it: it proves the plugin
	 * is inert on an old site rather than fatal.
	 */
	private function skip_without_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'No Abilities API on this WordPress — the ability half is correctly inert.' );
		}
	}

	/* -- Phase 3: what was TURNED AWAY -------------------------------------- */

	/**
	 * Drive a run request at the real abilities REST surface.
	 *
	 * This works at all only because the listener uses `rest_request_after_callbacks`, which lives
	 * in respond_to_request() and therefore fires under rest_do_request(). `rest_post_dispatch` —
	 * the obvious choice — is applied inside serve_request(), which rest_do_request() never reaches,
	 * so it would have worked against real HTTP attacks and been completely untestable.
	 *
	 * @param string     $name  Ability name (may be one that does not exist).
	 * @param array|null $input Valid input, or null to send none.
	 * @return \WP_REST_Response
	 */
	private function run_ability( $name, $input = null ) {
		$request = new \WP_REST_Request( 'GET', "/wp-abilities/v1/abilities/$name/run" );
		if ( null !== $input ) {
			$request->set_query_params( array( 'input' => $input ) );
		}
		return rest_do_request( $request );
	}

	public function test_an_anonymous_refusal_is_recorded() {
		$this->skip_without_abilities();
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->run_ability( 'agentimus/read-readiness', array() )->get_status() );

		$rows = Store::recent( 100, Events::KIND_ABILITY_REFUSED );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'agentimus/read-readiness', $rows[0]['subject'], 'A refusal names a real ability — the 404 gate fires first, so it must exist.' );
		$this->assertSame( '401', $rows[0]['detail'] );
	}

	public function test_a_logged_in_but_unauthorised_refusal_is_recorded() {
		$this->skip_without_abilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->run_ability( 'agentimus/read-readiness', array() )->get_status() );
		$this->assertCount( 1, Store::recent( 100, Events::KIND_ABILITY_REFUSED ) );
	}

	public function test_an_admins_successful_call_is_never_recorded_as_a_refusal() {
		$this->skip_without_abilities();
		wp_set_current_user( $this->admin );

		$this->assertSame( 200, $this->run_ability( 'agentimus/read-readiness', array() )->get_status() );

		$this->assertCount( 0, Store::recent( 100, Events::KIND_ABILITY_REFUSED ) );
		$this->assertCount( 1, Store::recent( 100, Events::KIND_ABILITY_USED ) );
	}

	public function test_a_no_input_ability_runs_over_rest_without_an_explicit_object() {
		$this->skip_without_abilities();
		wp_set_current_user( $this->admin );

		// REGRESSION (1.20.1): a read-only ability runs over REST as a GET, whose input comes from an
		// `?input` query param — which cannot express an empty object. With no param the value is null,
		// and before no_input() declared a `default` that null failed the input gate with 400, leaving
		// readiness/AI-visibility/exposed-files uncallable by any external MCP or REST agent. A no-arg
		// GET must now run, and record as a genuine use — not die at the input gate.
		$this->assertSame( 200, $this->run_ability( 'agentimus/read-readiness' )->get_status(), 'A no-input ability must run with no `input` at all.' );
		$this->assertCount( 1, Store::recent( 100, Events::KIND_ABILITY_USED ) );
	}

	public function test_an_admins_malformed_request_is_their_bug_not_our_signal() {
		$this->skip_without_abilities();
		wp_set_current_user( $this->admin );

		// A required field is missing -> 400 at the input gate. A developer's broken integration does
		// this all day; logging it would fill the feed with their own mistake. (identify-bot requires
		// `ip`, so an empty object fails validation before permission is ever checked.)
		$this->assertSame( 400, $this->run_ability( 'agentimus/identify-bot', array() )->get_status() );
		$this->assertCount( 0, Store::recent(), 'A logged-in caller\'s bad input must record nothing.' );
	}

	public function test_a_naive_anonymous_scan_still_leaves_a_trace() {
		$this->skip_without_abilities();
		wp_set_current_user( 0 );

		// THE case a "just log the 403s" design would miss entirely. The permission gate sits BEHIND
		// an input gate, so a scanner throwing junk at a real ability that TAKES input gets 400s, never
		// 403s. (A no-input ability now defaults to {} and reaches the permission gate, so use one that
		// requires input — identify-bot — to exercise the input-gate-before-permission path.)
		$this->assertSame( 400, $this->run_ability( 'agentimus/identify-bot' )->get_status() );

		$this->assertCount( 1, Store::recent( 100, Events::KIND_ABILITY_PROBED ), 'An anonymous scan must not be invisible.' );
	}

	public function test_guessing_ability_names_cannot_flood_the_table() {
		$this->skip_without_abilities();
		wp_set_current_user( 0 );

		// THE cardinality guard. The probed name is attacker-supplied. Storing it would let a name
		// enumeration mint one row per guess — the same class of bug as a row cap that only holds
		// under a trickle. They must aggregate into ONE row whose hits count the campaign.
		for ( $i = 0; $i < 200; $i++ ) {
			$this->run_ability( "acme/guess-$i", array() );
		}

		// ⚠️ WHETHER CORE COMPLAINS ABOUT THE GUESSED NAME IS CORE'S BUSINESS, AND IT
		// CHANGED UNDER US. WP 7.0's run controller called wp_get_ability() straight
		// out, so the registry _doing_it_wrong()'d on every unknown name and this test
		// had to declare that it expected one. WP 7.1 asks wp_has_ability() first:
		//
		//     $ability = wp_has_ability( $request['name'] ) ? wp_get_ability( ... ) : null;
		//
		// so the registry is never reached and the notice never fires — which failed the
		// test on the very WordPress it was meant to protect against. (Caught 2026-08-19
		// the day 7.1 shipped: the CI jobs that had picked up 7.1 failed while the ones
		// still on 7.0.3 passed, which reads exactly like a flake and is not one.)
		// ⭐ So: tolerate either. Drop the notice if core raised one, require nothing if
		// it did not. What this test is actually about is the three assertions below —
		// they run identically on both versions.
		unset( $this->caught_doing_it_wrong['WP_Abilities_Registry::get_registered'] );

		$rows = Store::recent( 500, Events::KIND_ABILITY_PROBED );
		$this->assertCount( 1, $rows, '200 guessed names must produce ONE row, not 200.' );
		$this->assertSame( 200, $rows[0]['hits'], 'The hit count is what tells the owner this is a campaign.' );
		$this->assertSame( '', $rows[0]['subject'], 'The attacker-supplied name must never reach `subject`.' );
	}

	public function test_ordinary_rest_traffic_records_nothing() {
		// The listener fires on EVERY REST request the site serves. A successful request must exit on
		// the first line and never touch the database.
		wp_set_current_user( $this->admin );

		for ( $i = 0; $i < 20; $i++ ) {
			rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/types' ) );
		}

		$this->assertSame( 0, Store::total(), 'Ordinary REST traffic must not write to the agent-access log.' );
	}

	/* -- Phase 3 hardening: the flood vector, and the kill switch ----------- */

	public function test_the_non_run_abilities_routes_cannot_flood_with_attacker_subjects() {
		$this->skip_without_abilities();
		wp_set_current_user( 0 );

		// THE fix for a HIGH found in the pre-release red-team. classify() assumes the /run
		// controller's gate order (existence checked before permission). The sibling routes — GET
		// .../abilities [list], GET .../abilities/<name> [item], GET .../categories — gate on a plain
		// current_user_can('read') that core runs BEFORE the callback, so an anonymous request
		// returns 401 for ANY `name`, including a free-form query param. Matching the whole namespace
		// let an unauthenticated attacker write unbounded rows with an attacker-controlled subject.
		// Only /run may be observed; these must record nothing.
		foreach ( array( 'evil-alpha', 'evil-bravo', 'evil-charlie' ) as $name ) {
			$request = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' );
			$request->set_query_params( array( 'name' => $name ) );
			rest_do_request( $request );
		}

		$this->assertSame( 0, Store::total(), 'The list/item/categories routes must never write an event.' );
	}

	public function test_recording_off_records_nothing_even_when_one_of_our_abilities_runs() {
		// Abilities\Registrar wraps EVERY ability's execute callback with observe_own_ability(),
		// unconditionally — before this module's own enabled() gate runs. Without the $active guard a
		// disabled feature still wrote an ability_used row (and corrupted the hook verdict) the moment
		// an admin or an authenticated MCP client ran one of our abilities. Calling observe_own_ability
		// directly isolates that path from the live global-hook listener.
		$settings = new \Agentimus\Settings();
		$settings->update( array_merge( $settings->all(), array( 'agent_access_events' => false ) ) );
		( new Module( $settings ) )->register(); // sets $active = false
		Store::clear();
		delete_option( Module::HOOKS_OPTION );

		Module::observe_own_ability( 'agentimus/read-readiness' );

		$this->assertSame( 0, Store::total(), 'A disabled feature must record nothing.' );
		$this->assertFalse( get_option( Module::HOOKS_OPTION, false ), 'A disabled feature must not write the hook verdict.' );

		// Restore the enabled state for the rest of the suite.
		$settings->update( array_merge( $settings->all(), array( 'agent_access_events' => true ) ) );
		( new Module( $settings ) )->register();
	}

	public function test_shape_resolves_who_from_live_wordpress_data() {
		// "This tells nothing about the visitor" — the WHO fields. They resolve LIVE from
		// the owner's own data: the user's login and the app password's current label.
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'aa_who_admin',
			)
		);
		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'claude-code-mcp' ) );
		$this->assertNotWPError( $created );
		$uuid = $created[1]['uuid'];

		$row = array(
			'kind'    => Events::KIND_ABILITY_USED,
			'user_id' => $user_id,
			'cred'    => $uuid,
			'subject' => 'agentimus/read-readiness',
		);

		$shaped = Events::shape( $row );
		$this->assertSame( 'aa_who_admin', $shaped['user'] );
		$this->assertSame( 'claude-code-mcp', $shaped['credName'] );

		// Live means live: a rename shows the NEW label on old rows…
		\WP_Application_Passwords::update_application_password( $user_id, $uuid, array( 'name' => 'renamed-key' ) );
		$this->assertSame( 'renamed-key', Events::shape( $row )['credName'] );

		// …and a revoked credential resolves to '' (the UI words it as "since revoked"),
		// while the row itself — the fact that it happened — stands untouched.
		\WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		$after = Events::shape( $row );
		$this->assertSame( '', $after['credName'] );
		$this->assertSame( 'aa_who_admin', $after['user'] );
	}
}
