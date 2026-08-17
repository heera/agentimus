<?php
/**
 * Google Sheets — the fifth service, a rows-formatter over the shared
 * pipeline, held to the siblings' promises plus the two that are its own:
 *
 *   1. The FORMATTER. Every event becomes one row of five flat columns in one
 *      stable order — When · Event · Level · What happened · Link — and these
 *      tests pin each event's exact row, so a spreadsheet's history changes
 *      shape only by conscious act. Values ride RAW, never as formulas.
 *   2. The BORROWED KEY. There is no credential of its own: connected() also
 *      demands the Google connection's stored key, so a Google disconnect
 *      stands this service down instead of leaving it erroring — and a
 *      keyless deliver declines without one network call.
 *   3. The TWO DISTINCT ERRORS. 404 is the spreadsheet ID, 403 is the
 *      sharing — and the 403 says the service account's EMAIL out loud,
 *      because "share the sheet" without the address is a scavenger hunt.
 *   4. INERTNESS. Disconnected means wants() refuses and deliver() declines;
 *      the enabled flag collapses without a spreadsheet.
 *   5. THE ID's SHAPE. A pasted URL yields its /d/ code, a bare ID passes,
 *      garbage normalizes to '' — stored garbage would fail every delivery.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Google\Auth;
use Agentimus\Google\Settings as GoogleSettings;
use Agentimus\Integrations\Dispatcher;
use Agentimus\Integrations\Events;
use Agentimus\Integrations\Services\Sheets;
use PHPUnit\Framework\TestCase;

final class SheetsTest extends TestCase {

	const SPREADSHEET = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdef';

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_cron_events'] = array();
		// Transients on: the delivery path minting its token from the cached
		// slot keeps these tests off the (stubbed) OAuth wire entirely.
		$GLOBALS['_af_transients_on'] = true;
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_cron_events'] );
	}

	/** A connected Sheets: settings switched on + the Google store holding a key. */
	private function connect( array $events = null ) {
		$stored                                     = isset( $GLOBALS['_af_options'][ Settings::OPTION ] ) ? $GLOBALS['_af_options'][ Settings::OPTION ] : array();
		$stored['integrations']                     = array_merge(
			isset( $stored['integrations'] ) ? (array) $stored['integrations'] : array(),
			array(
				'sheets_enabled'     => true,
				'sheets_spreadsheet' => self::SPREADSHEET,
				'sheets_events'      => null === $events ? Events::names() : $events,
			)
		);
		$GLOBALS['_af_options'][ Settings::OPTION ] = $stored;
		$this->store_google_key();
		$this->cache_token();
	}

	/** The Google connection's slice: a stored key + the email the sheet is shared with. */
	private function store_google_key() {
		$GLOBALS['_af_options'][ GoogleSettings::OPTION ] = array(
			'sa_json'  => '{"type":"service_account","client_email":"agentimus@project.iam.gserviceaccount.com","private_key":"pem"}',
			'sa_email' => 'agentimus@project.iam.gserviceaccount.com',
		);
	}

	/** A Sheets-scope token already minted — Auth answers from its cache slot. */
	private function cache_token() {
		$GLOBALS['_af_transients'][ 'agentimus_google_token_' . substr( md5( Auth::SCOPE_SHEETS ), 0, 16 ) ] = 'sheets-token';
	}

	private function settings() {
		return new Settings();
	}

	/** An envelope with a pinned clock, so the When column is exact. */
	private function envelope( $event, array $data ) {
		$envelope       = Events::envelope( $event, $data );
		$envelope['at'] = 1700000000; // 2023-11-14 22:13:20 UTC (wp_date is gmdate in this suite).
		return $envelope;
	}

	/* ---- the formatter (one exact row per event) --------------------------- */

	public function test_the_header_names_the_five_columns_in_order() {
		$this->assertSame( array( 'When', 'Event', 'Level', 'What happened', 'Link' ), Sheets::HEADER );
	}

	public function test_finding_row_is_when_event_tier_title_url() {
		$row = Sheets::row(
			Events::FINDING_OPENED,
			$this->envelope(
				Events::FINDING_OPENED,
				array(
					'id'    => 'config_gap:llms_txt',
					'tier'  => 'urgent',
					'title' => 'llms.txt is missing',
					'why'   => 'Turn it on.',
					'url'   => 'https://example.test/wp-admin/admin.php?page=agentimus#findings',
				)
			)
		);

		$this->assertSame(
			array(
				'2023-11-14 22:13:20',
				'finding_opened',
				'urgent',
				'llms.txt is missing',
				'https://example.test/wp-admin/admin.php?page=agentimus#findings',
			),
			$row
		);
	}

	public function test_finding_row_speaks_the_tier_in_words() {
		$row = Sheets::row(
			Events::FINDING_OPENED,
			$this->envelope( Events::FINDING_OPENED, array( 'tier' => 'worth', 'title' => 'x', 'url' => 'https://example.test/a' ) )
		);
		$this->assertSame( 'worth knowing', $row[2] );
	}

	public function test_digest_row_carries_the_numbers_and_the_score() {
		$row = Sheets::row(
			Events::DIGEST_SENT,
			$this->envelope(
				Events::DIGEST_SENT,
				array(
					'period'    => array( 'label' => 'Aug 3 – Aug 9', 'days' => 7 ),
					'agents'    => 41,
					'referrals' => 9,
					'impostors' => 2,
					'score'     => array( 'now' => 88, 'prev' => 81 ),
				)
			)
		);

		$this->assertSame(
			array(
				'2023-11-14 22:13:20',
				'digest_sent',
				'',
				'Aug 3 – Aug 9 — 41 agent visits, 9 AI referrals, 2 impostors caught. Score 88 (was 81).',
				'',
			),
			$row
		);
	}

	public function test_digest_row_omits_a_label_and_score_it_does_not_have() {
		$row = Sheets::row(
			Events::DIGEST_SENT,
			$this->envelope(
				Events::DIGEST_SENT,
				array(
					'period'    => array( 'label' => '', 'days' => 7 ),
					'agents'    => 1,
					'referrals' => 0,
					'impostors' => 0,
					'score'     => array( 'now' => null, 'prev' => null ),
				)
			)
		);

		$this->assertSame( '1 agent visits, 0 AI referrals, 0 impostors caught.', $row[3] );
	}

	public function test_impostor_row_names_the_client_and_nothing_else() {
		$row = Sheets::row(
			Events::IMPOSTOR_FLAGGED,
			$this->envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) )
		);

		$this->assertSame( 'impostor_flagged', $row[1] );
		$this->assertSame( '', $row[2], 'The tier is a findings word; inventing one here would be a lie.' );
		$this->assertSame( 'A client calling itself “Googlebot” failed its operator’s check.', $row[3] );
		$this->assertSame( '', $row[4] );
	}

	public function test_robots_row_joins_the_lines_and_links_the_file() {
		$row = Sheets::row(
			Events::ROBOTS_CHANGED,
			$this->envelope(
				Events::ROBOTS_CHANGED,
				array( 'added' => array( 'disallow: /private', '+3 more' ), 'removed' => array(), 'at' => 1700000000 )
			)
		);

		$this->assertSame( 'Added: disallow: /private; +3 more.', $row[3] );
		$this->assertSame( 'https://example.test/robots.txt', $row[4] );
	}

	public function test_citation_row_carries_the_counts_and_the_cap() {
		$capped = Sheets::row(
			Events::CITATION_RUN_FINISHED,
			$this->envelope( Events::CITATION_RUN_FINISHED, array( 'runId' => 1, 'checks' => 1, 'capped' => true ) )
		);
		$this->assertSame( '1 check run. The run stopped at its cap.', $capped[3] );

		$plural = Sheets::row(
			Events::CITATION_RUN_FINISHED,
			$this->envelope( Events::CITATION_RUN_FINISHED, array( 'runId' => 2, 'checks' => 12, 'capped' => false ) )
		);
		$this->assertSame( '12 checks run.', $plural[3] );
	}

	public function test_wrote_row_is_title_action_and_tool() {
		$updated = Sheets::row(
			Events::AGENT_WROTE_CONTENT,
			$this->envelope(
				Events::AGENT_WROTE_CONTENT,
				array( 'postId' => 7, 'title' => 'Hello', 'action' => 'update', 'ability' => 'agentimus/update-content' )
			)
		);
		$this->assertSame( '“Hello” updated by an AI assistant. Via agentimus/update-content.', $updated[3] );

		$created = Sheets::row(
			Events::AGENT_WROTE_CONTENT,
			$this->envelope( Events::AGENT_WROTE_CONTENT, array( 'postId' => 7, 'title' => 'Hello', 'action' => 'create', 'ability' => '' ) )
		);
		$this->assertSame( '“Hello” created by an AI assistant.', $created[3] );
	}

	public function test_unknown_event_keeps_its_name_in_the_event_column() {
		$row = Sheets::row( 'addon_event', $this->envelope( 'addon_event', array() ) );
		$this->assertSame( array( '2023-11-14 22:13:20', 'addon_event', '', '', '' ), $row );
	}

	/* ---- the spreadsheet id ------------------------------------------------- */

	public function test_normalize_takes_a_bare_id_a_pasted_url_and_refuses_garbage() {
		$this->assertSame( self::SPREADSHEET, Sheets::normalize_spreadsheet_id( self::SPREADSHEET ) );
		$this->assertSame(
			self::SPREADSHEET,
			Sheets::normalize_spreadsheet_id( 'https://docs.google.com/spreadsheets/d/' . self::SPREADSHEET . '/edit#gid=0' ),
			'The kinder paste: the whole URL yields its /d/ code.'
		);
		$this->assertSame(
			self::SPREADSHEET,
			Sheets::normalize_spreadsheet_id( 'https://docs.google.com/spreadsheets/u/0/d/' . self::SPREADSHEET . '/edit' )
		);

		$this->assertSame( '', Sheets::normalize_spreadsheet_id( 'not a spreadsheet' ) );
		$this->assertSame( '', Sheets::normalize_spreadsheet_id( 'short' ) );
		$this->assertSame( '', Sheets::normalize_spreadsheet_id( '' ) );
	}

	/* ---- delivery ----------------------------------------------------------- */

	public function test_deliver_appends_one_raw_row_to_the_named_spreadsheet() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ) );

		$verdict = Sheets::deliver(
			Events::IMPOSTOR_FLAGGED,
			$this->envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) )
		);

		$this->assertTrue( $verdict );
		$last = $GLOBALS['_af_http_last'];
		$this->assertSame(
			'https://sheets.googleapis.com/v4/spreadsheets/' . self::SPREADSHEET . '/values/A1:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',
			$last['url'],
			'RAW on purpose: a value beginning with "=" must land as text, never as a formula.'
		);
		$this->assertSame( 'Bearer sheets-token', $last['args']['headers']['Authorization'] );
		$this->assertSame( 5, $last['args']['timeout'] );

		$body = json_decode( $last['args']['body'], true );
		$this->assertCount( 1, $body['values'], 'One event, one row.' );
		$this->assertSame( 'impostor_flagged', $body['values'][0][1] );
	}

	public function test_a_404_is_the_id_and_a_403_is_the_sharing_with_the_email_said() {
		$this->connect();

		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 404 ), 'body' => '{}', 'headers' => array() ) );
		$bad_id                    = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );
		$this->assertSame( 'agentimus_sheets_id', $bad_id->get_error_code() );
		$this->assertStringContainsString( '/d/', $bad_id->get_error_message(), 'The bad-ID sentence teaches where the ID lives in the URL.' );

		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 403 ), 'body' => '{}', 'headers' => array() ) );
		$no_access                 = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );
		$this->assertSame( 'agentimus_sheets_access', $no_access->get_error_code() );
		$this->assertStringContainsString(
			'agentimus@project.iam.gserviceaccount.com',
			$no_access->get_error_message(),
			'"Share the sheet" without the address would be a scavenger hunt.'
		);
	}

	/**
	 * ⛔ THE BUG THIS GUARDS, found by the 2026-08-17 walkthrough on heera.it: a
	 * 403 was read as "the sheet isn't shared" without ever looking at the body.
	 * The sheet WAS shared, correctly, as an Editor — the Sheets API was simply
	 * switched off for the project. The owner spent ten minutes re-sharing a
	 * sheet that needed nothing done to it.
	 *
	 * Google says 403 for at least three different problems and names the real
	 * one in `error.details[].reason`. A wrong diagnosis is worse than none.
	 */
	public function test_a_403_says_which_of_googles_three_refusals_it_was() {
		$this->connect();

		// 1. The API is off. ⛔ Must NOT talk about sharing.
		$disabled = '{"error":{"code":403,"status":"PERMISSION_DENIED",'
			. '"message":"Google Sheets API has not been used in project 42 before or it is disabled. Enable it by visiting https://console.developers.google.com/apis/api/sheets.googleapis.com/overview?project=42 then retry.",'
			. '"details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"SERVICE_DISABLED"}]}}';
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 403 ), 'body' => $disabled, 'headers' => array() ) );
		$err                       = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );
		$this->assertSame( 'agentimus_sheets_api_off', $err->get_error_code() );
		$this->assertStringNotContainsString( 'Share', $err->get_error_message(), 'Sharing is not the problem, so it must not be the instruction.' );
		$this->assertStringContainsString( 'console.developers.google.com', $err->get_error_message(), 'Google’s own message carries the enable link for THIS project.' );

		// 2. The key cannot write to Sheets.
		$scope = '{"error":{"code":403,"status":"PERMISSION_DENIED","message":"Request had insufficient authentication scopes.",'
			. '"details":[{"reason":"ACCESS_TOKEN_SCOPE_INSUFFICIENT"}]}}';
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 403 ), 'body' => $scope, 'headers' => array() ) );
		$err                       = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );
		$this->assertSame( 'agentimus_sheets_scope', $err->get_error_code() );
		$this->assertStringNotContainsString( 'Share', $err->get_error_message() );

		// 3. A reason we have no words for is REPEATED, never guessed at.
		$odd = '{"error":{"code":403,"message":"Something new","details":[{"reason":"BRAND_NEW_REASON"}]}}';
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 403 ), 'body' => $odd, 'headers' => array() ) );
		$err                       = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );
		$this->assertStringContainsString( 'BRAND_NEW_REASON', $err->get_error_message(), 'An unknown reason names itself rather than being filed under a known one.' );
		$this->assertStringContainsString( 'Something new', $err->get_error_message() );

		// 4. A plain permission denial still gets the sharing sentence — the
		//    common case keeps its good error, and is now the ONLY case claiming it.
		$denied = '{"error":{"code":403,"status":"PERMISSION_DENIED","message":"The caller does not have permission"}}';
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 403 ), 'body' => $denied, 'headers' => array() ) );
		$err                       = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );
		$this->assertSame( 'agentimus_sheets_access', $err->get_error_code() );
		$this->assertStringContainsString( 'agentimus@project.iam.gserviceaccount.com', $err->get_error_message() );
	}

	public function test_any_other_failure_repeats_googles_own_words() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 429 ), 'body' => '{"error":{"message":"Quota exceeded"}}', 'headers' => array() ),
		);

		$verdict = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );

		$this->assertSame( 'agentimus_sheets_status', $verdict->get_error_code() );
		$this->assertSame( 'Google Sheets answered 429: Quota exceeded', $verdict->get_error_message() );
	}

	public function test_verify_appends_the_header_and_one_test_row_in_one_call() {
		$this->store_google_key();
		$this->cache_token();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ) );

		$this->assertTrue( Sheets::verify( self::SPREADSHEET ) );

		$body = json_decode( $GLOBALS['_af_http_last']['args']['body'], true );
		$this->assertCount( 2, $body['values'], 'One call proves the whole road: header + test row.' );
		$this->assertSame( Sheets::HEADER, $body['values'][0] );
		$this->assertSame( 'connected', $body['values'][1][1] );
		$this->assertSame( 'https://example.test/', $body['values'][1][4], 'The test row links home.' );
	}

	public function test_drain_routes_a_sheets_row_to_sheets() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ) );

		$dispatcher = new Dispatcher( $this->settings() );
		$this->assertTrue( $dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array() ), Sheets::ID ) );
		$dispatcher->drain();

		$this->assertSame( 0, $dispatcher->depth() );
		$this->assertStringContainsString( 'sheets.googleapis.com', $GLOBALS['_af_http_last']['url'] );
		$this->assertGreaterThan( 0, Sheets::state()['lastDeliveredAt'] );
	}

	/* ---- the borrowed key (the auth-absent state) --------------------------- */

	public function test_without_the_google_key_the_connection_is_down_not_erroring() {
		// Switched on with a spreadsheet — but the Google store is empty (never
		// connected, or disconnected since).
		$GLOBALS['_af_options'][ Settings::OPTION ] = array(
			'integrations' => array(
				'sheets_enabled'     => true,
				'sheets_spreadsheet' => self::SPREADSHEET,
				'sheets_events'      => Events::names(),
			),
		);

		$settings = $this->settings();
		$this->assertFalse( Sheets::connected( $settings ), 'The borrowed key left; the connection stands down with it.' );
		$this->assertFalse( Sheets::wants( $settings, Events::DIGEST_SENT ) );

		$verdict = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );
		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_sheets_unconfigured', $verdict->get_error_code() );
		$this->assertNull( $GLOBALS['_af_http_last'], 'Keyless = not one network call.' );
	}

	public function test_a_cold_token_failure_names_the_key_not_the_sheet() {
		$this->connect();
		// No cached token, and the "key" can't even parse — the mint fails
		// before any Sheets call, with the failure blamed on the key.
		$GLOBALS['_af_options'][ GoogleSettings::OPTION ]['sa_json'] = 'not-json';
		unset( $GLOBALS['_af_transients'][ 'agentimus_google_token_' . substr( md5( Auth::SCOPE_SHEETS ), 0, 16 ) ] );

		$verdict = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_sheets_auth', $verdict->get_error_code() );
	}

	/* ---- inertness + settings ----------------------------------------------- */

	public function test_disconnected_sheets_wants_nothing_and_delivers_nothing() {
		$settings = $this->settings();

		$this->assertFalse( Sheets::connected( $settings ) );
		$this->assertFalse( Sheets::wants( $settings, Events::DIGEST_SENT ) );

		$verdict = Sheets::deliver( Events::DIGEST_SENT, $this->envelope( Events::DIGEST_SENT, array() ) );
		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_sheets_unconfigured', $verdict->get_error_code() );
		$this->assertNull( $GLOBALS['_af_http_last'], 'Unconfigured = not one network call.' );
	}

	public function test_sanitize_collapses_enabled_without_a_spreadsheet() {
		$clean = ( new Settings() )->sanitize(
			array(
				'integrations' => array(
					'sheets_enabled'     => true,
					'sheets_spreadsheet' => '',
					'sheets_events'      => array( 'digest_sent' ),
				),
			)
		);

		$this->assertFalse( $clean['integrations']['sheets_enabled'], 'A connection with no spreadsheet cannot exist.' );
	}

	public function test_sanitize_normalizes_a_pasted_url_into_the_bare_id() {
		$clean = ( new Settings() )->sanitize(
			array(
				'integrations' => array(
					'sheets_enabled'     => true,
					'sheets_spreadsheet' => 'https://docs.google.com/spreadsheets/d/' . self::SPREADSHEET . '/edit',
					'sheets_events'      => array( 'digest_sent' ),
				),
			)
		);

		$this->assertSame( self::SPREADSHEET, $clean['integrations']['sheets_spreadsheet'] );
		$this->assertTrue( $clean['integrations']['sheets_enabled'] );
	}

	public function test_partial_save_without_the_key_keeps_the_stored_connection() {
		$this->connect();

		$clean = ( new Settings() )->sanitize( array( 'enable_llms_txt' => true ) );

		$this->assertTrue( $clean['integrations']['sheets_enabled'] );
		$this->assertSame( self::SPREADSHEET, $clean['integrations']['sheets_spreadsheet'] );
	}
}
