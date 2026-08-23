<?php
/**
 * The clients an assistant can finally see — and decide about.
 *
 * Until these four abilities, a connected assistant could identify ONE bot by IP
 * and read the raw request log, and could not see the queue of clients asking
 * the owner for a verdict at all, let alone answer one.
 *
 * ⛔ THESE RUN THE ABILITIES, not their producers. Ability output is validated
 * against the declared schema at execute, so calling the real thing is the only
 * way a projection that quietly lies gets caught — and it caught three tonight:
 * the review rows come from `threats['sources']` and not `threats` (iterating the
 * wrapper produced five blank rows and a green test), `severity` is a number,
 * and a dismissal has no `ua` to hand back.
 *
 * ⚠️ And they run against a POPULATED fixture, deliberately. On an empty site
 * every list here is `[]`, every assertion passes, and nothing is proven —
 * exactly the shape that hid five bugs the last time this subsystem was touched.
 *
 * ⚠️ The two WRITE abilities are driven through {@see Review}, not through
 * wp_get_ability(), for the reason SetAsideDbTest gives: the write tier only
 * registers while the owner's switch is on and the registry is built ONCE per
 * process, so in a full-suite run an earlier test has already built it with
 * writes off. The tools are thin wrappers over these calls; their gate is
 * asserted separately below, which is the half that actually needs proving.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\Registrar;
use Agentimus\Activity\Recorder;
use Agentimus\Activity\Review;
use Agentimus\Activity\Repository;
use Agentimus\Activity\Table;
use Agentimus\Settings;

final class ClientAbilitiesDbTest extends DbTestCase {

	/** @var string A client with a safe, derivable block rule. */
	private const UA = 'PretendBot/2.0 (+https://example.com/bot)';

	/** @var string A search engine — protected, so no rule can be derived for it. */
	private const ENGINE_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+); the feature self-gates below that.' );
		}
		Table::maybe_install();
		$this->wipe();

		update_option(
			Settings::OPTION,
			array_merge(
				(array) get_option( Settings::OPTION, array() ),
				array(
					'enable_activity'     => 1,
					'enable_mcp_server'   => 1,
					// ⛔ Without this the two write abilities are never registered and
					// every test below SKIPS its way to green. A skip is not a pass.
					'enable_agent_writes' => 1,
					'blocked_agents'      => array( 'BadBot' ),
					'allowed_agents'      => array( 'FriendBot' ),
				)
			)
		);

		// A real visit from a real client, so the review queue has something in it.
		// ⚠️ Recorded while nobody is signed in, and the order is the whole point:
		// the recorder deliberately skips the OWNER inspecting their own endpoints,
		// so seeding this after wp_set_current_user() logs nothing and leaves an
		// empty queue that every assertion below would pass straight through.
		$_SERVER['HTTP_USER_AGENT'] = self::UA;
		Recorder::record( 'llms.txt' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_USER_AGENT'] );
		$this->wipe();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** ⚠️ DELETE, not TRUNCATE — TRUNCATE commits and breaks test isolation. */
	private function wipe(): void {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Run an ability the way an assistant does, so its output meets its schema.
	 *
	 * @param string $slug  Ability slug.
	 * @param array  $input Input.
	 * @return array|\WP_Error
	 */
	private function ability( string $slug, array $input = array() ) {
		$ability = wp_get_ability( 'agentimus/' . $slug );
		$this->assertNotNull( $ability, "agentimus/$slug is not registered — check the write switch and WRITE_SLUGS." );
		return $ability->execute( $input );
	}

	/* ------------------------------------------------------------------ reads */

	public function test_the_queue_arrives_with_its_rows_filled_in() {
		$out = $this->ability( 'read-clients' );
		$this->assertNotWPError( $out );

		$this->assertTrue( $out['enabled'], 'The site really is recording — an empty queue here would mean nothing otherwise.' );
		$this->assertNotEmpty( $out['review'], 'The fixture made a real visit; the queue must hold it.' );

		$row = $out['review'][0];
		$this->assertSame( self::UA, $row['ua'], 'The ua is what review-client and recheck-client are passed.' );
		$this->assertGreaterThan( 0, $row['hits'], 'A row with no hits behind it is the wrapper bug all over again.' );
		$this->assertSame( 'pretendbot', $row['suggestedRule'], 'The exact fragment a block would match on.' );
		$this->assertIsInt( $row['severity'] );
		$this->assertIsBool( $row['verifiable'] );
	}

	public function test_the_standing_decisions_and_the_verifiable_list_come_too() {
		Repository::dismiss( self::UA, 7 );

		$out = $this->ability( 'read-clients' );

		$this->assertSame( 'BadBot', $out['blocked'][0]['rule'] );
		$this->assertSame( 'FriendBot', $out['allowed'][0]['rule'] );
		$this->assertNotEmpty( $out['ignored'], 'A dismissal is a decision too — an assistant that cannot see it would re-raise the row.' );
		$this->assertNotEmpty( $out['ignored'][0]['name'], 'Named the way the owner sees it named.' );
		$this->assertSame( 7, $out['ignored'][0]['hits'] );
		$this->assertNotEmpty( $out['verifiers'], 'The built-in registry is never empty; recheck-client only works on these.' );
		$this->assertNotEmpty( $out['verifiers'][0]['domains'] );
	}

	/**
	 * ⛔ Silence is not evidence. With recording off there are no rows for a
	 * reason that has nothing to do with who visited, and the answer has to say
	 * so or an assistant will report a quiet site.
	 */
	public function test_recording_switched_off_says_so_rather_than_reporting_a_quiet_site() {
		$all                    = (array) get_option( Settings::OPTION, array() );
		$all['enable_activity'] = 0;
		update_option( Settings::OPTION, $all );

		$out = $this->ability( 'read-clients' );

		$this->assertFalse( $out['enabled'] );
	}

	public function test_the_access_record_reads_and_names_its_own_blind_spot() {
		$out = $this->ability( 'read-agent-access' );
		$this->assertNotWPError( $out );

		$this->assertNotEmpty( $out['coverage'], 'Coverage is what tells an empty list apart from a blind one.' );
		$this->assertIsInt( $out['total'] );
		$this->assertIsBool( $out['hasAbilities'] );
		$this->assertGreaterThan( 0, $out['retentionDays'] );
	}

	/* ----------------------------------------------------------------- writes */

	public function test_block_then_forget_puts_the_client_back_to_undecided() {
		$blocked = Review::decide( new Settings(), self::UA, 'block' );
		$this->assertTrue( $blocked['decided'] );
		$this->assertSame( 'pretendbot', $blocked['rule'] );
		$this->assertContains( 'pretendbot', (array) ( new Settings() )->get( 'blocked_agents', array() ) );

		$forgotten = Review::decide( new Settings(), self::UA, 'forget', 'blocked' );
		$this->assertTrue( $forgotten['decided'] );
		$this->assertNotContains( 'pretendbot', (array) ( new Settings() )->get( 'blocked_agents', array() ) );

		// ⛔⛔ AND NOTHING ELSE MOVED. Settings::update() REPLACES the option, so a
		// partial write resets every key it omits to its default — forgetting one
		// block rule turned off the MCP server, the visit log and every other
		// switch on the site. It really happened, on a real site, on 2026-08-23,
		// and no test saw it: a screenshot did.
		$this->assertTrue( (bool) ( new Settings() )->get( 'enable_mcp_server', false ), 'A decision about one client must not touch an unrelated switch.' );
		$this->assertTrue( (bool) ( new Settings() )->get( 'enable_agent_writes', false ) );
		$this->assertContains( 'FriendBot', (array) ( new Settings() )->get( 'allowed_agents', array() ), 'And the OTHER list is untouched.' );
	}

	/**
	 * ⭐ The volume is read from the ROW, never from the caller. That number is
	 * what decides whether the client ever comes back, so an assistant must not
	 * be able to set it — and reading it from the wrong place silently files
	 * every dismissal at zero.
	 */
	public function test_ignore_files_the_volume_the_owner_would_have_seen() {
		$out = Review::decide( new Settings(), self::UA, 'ignore' );

		$this->assertTrue( $out['decided'] );
		$dismissals = Repository::dismissals();
		$this->assertNotEmpty( $dismissals );
		$this->assertGreaterThan( 0, (int) $dismissals[0]['hits'], 'Filed at zero means the row returns on its very next hit.' );
	}

	/** A search engine cannot be blocked here, exactly as on the owner's screen. */
	public function test_a_protected_client_is_refused_with_its_reason() {
		$out = Review::decide( new Settings(), self::ENGINE_UA, 'block' );

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_no_safe_token', $out->get_error_code() );
	}

	public function test_a_decision_that_is_not_one_says_which_are() {
		$out = Review::decide( new Settings(), self::UA, 'nonsense' );

		$this->assertWPError( $out );
		$this->assertStringContainsString( 'block, allow, ignore or forget', $out->get_error_message() );
	}

	/** Forgetting a decision nobody made is a 404, not a silent success. */
	public function test_forgetting_a_decision_that_was_never_made() {
		$out = Review::decide( new Settings(), self::UA, 'forget', 'allowed' );

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_review_absent', $out->get_error_code() );
	}

	/** Nothing to check an identity against is an answer, not an error. */
	public function test_rechecking_a_client_that_claims_nothing_verifiable() {
		$out = Review::recheck( new Settings(), self::UA );

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_not_verifiable', $out->get_error_code() );
	}

	/**
	 * ⛔ The gate the write tools depend on: deciding about a client changes what
	 * this site refuses at the door, so neither tool may exist until the owner
	 * has turned agent writes on. The reads are there either way.
	 */
	public function test_the_two_write_tools_appear_only_behind_the_owners_switch() {
		$all = (array) get_option( Settings::OPTION, array() );

		$all['enable_agent_writes'] = false;
		update_option( Settings::OPTION, $all );
		$off = ( new Registrar( new Settings() ) )->mcp_abilities();
		$this->assertNotContains( 'agentimus/review-client', $off, 'Off must mean it does not exist on any surface.' );
		$this->assertNotContains( 'agentimus/recheck-client', $off );
		$this->assertContains( 'agentimus/read-clients', $off, 'Reading who has been here is not a write.' );

		$all['enable_agent_writes'] = true;
		update_option( Settings::OPTION, $all );
		$on = ( new Registrar( new Settings() ) )->mcp_abilities();
		$this->assertContains( 'agentimus/review-client', $on );
		$this->assertContains( 'agentimus/recheck-client', $on );
	}
}
