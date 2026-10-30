<?php
/**
 * Agents can read citation products and add to them, and can take nothing away.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\CitationProducts;
use Agentimus\Abilities\Registrar;
use Agentimus\AgentAccess\Store;
use Agentimus\AgentAccess\Table;
use Agentimus\Settings;
use Agentimus\Visibility\Settings as VisibilitySettings;

final class CitationProductsDbTest extends DbTestCase {

	/** @var Settings */
	private $settings;

	/** @var VisibilitySettings */
	private $visibility;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings   = new Settings();
		$this->visibility = new VisibilitySettings();
		$this->switch_to( true );
		$this->visibility->update(
			array(
				'targets' => array(
					array(
						'name'        => 'Agentimus',
						'category'    => 'WordPress plugin',
						'domain'      => 'example.org',
						'active'      => false,
						'competitors' => array( 'Yoast' ),
						'prompts'     => array( 'What is Agentimus?' ),
					),
				),
			)
		);
	}

	private function switch_to( $on ) {
		$all                          = $this->settings->stored();
		$all['enable_mcp_server']     = true;
		$all['enable_agent_writes']   = true;
		$all['agent_writes_settings'] = $on;
		$this->settings->update( $all );
	}

	private function tool() {
		return new CitationProducts( $this->settings, $this->visibility );
	}

	private function product( $name = 'Agentimus' ) {
		foreach ( (array) $this->visibility->get( 'targets' ) as $target ) {
			if ( $target['name'] === $name ) {
				return $target;
			}
		}
		return null;
	}

	public function test_it_needs_the_settings_switch() {
		$this->switch_to( false );
		$before = $this->visibility->get( 'targets' );
		$result = $this->tool()->save( array( 'name' => 'Agentimus', 'add_prompts' => array( 'New?' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_settings_writes_off', $result->get_error_code() );
		$this->assertSame( $before, $this->visibility->get( 'targets' ) );
		$this->assertNotContains( 'agentimus/save-citation-product', ( new Registrar( $this->settings ) )->mcp_abilities() );

		$this->switch_to( true );
		$this->assertContains( 'agentimus/save-citation-product', ( new Registrar( $this->settings ) )->mcp_abilities() );
		$this->assertTrue( Registrar::is_write_tool_name( 'agentimus/save-citation-product' ) );
	}

	public function test_reading_shows_products_and_no_keys() {
		$this->visibility->update( array( 'providers' => array( 'openai' => array( 'enabled' => true, 'api_key' => 'sk-SECRET-KEY' ) ) ) );

		$out = $this->tool()->read();

		$this->assertSame( 'Agentimus', $out['products'][0]['name'] );
		$this->assertSame( 1, $out['questions'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $out ) );
		$this->assertArrayNotHasKey( 'providers', $out );
	}

	public function test_it_adds_a_new_product() {
		$out = $this->tool()->save(
			array(
				'name'            => 'Second',
				'category'        => 'Backup plugin',
				'domain'          => 'https://second.test',
				'add_competitors' => array( 'Rival' ),
				'add_prompts'     => array( 'What is the best backup plugin?' ),
			)
		);

		$this->assertIsArray( $out );
		$this->assertTrue( $out['created'] );
		$this->assertSame( 1, $out['addedQuestions'] );
		$this->assertSame( 2, $out['questionsNow'] );
		$this->assertSame( 'Backup plugin', $this->product( 'Second' )['category'] );
		$this->assertSame( array( 'Rival' ), $this->product( 'Second' )['competitors'] );
	}

	public function test_it_adds_to_an_existing_product_and_keeps_what_it_had() {
		$out = $this->tool()->save(
			array(
				'name'            => 'agentimus',
				'add_prompts'     => array( 'Is Agentimus good?', 'WHAT IS AGENTIMUS?' ),
				'add_competitors' => array( 'Rank Math' ),
			)
		);

		$this->assertFalse( $out['created'] );
		$this->assertSame( 1, $out['addedQuestions'], 'A repeat in any case is not a new question.' );
		$product = $this->product();
		$this->assertSame( array( 'What is Agentimus?', 'Is Agentimus good?' ), $product['prompts'] );
		$this->assertSame( array( 'Yoast', 'Rank Math' ), $product['competitors'] );
		$this->assertSame( 'WordPress plugin', $product['category'] );
		$this->assertCount( 1, $this->visibility->get( 'targets' ) );
	}

	/** ⛔ Nothing here can take anything away: the owner's screen confirms a removal. */
	public function test_nothing_can_be_removed() {
		$before = $this->product();

		$out = $this->tool()->save(
			array(
				'name'            => 'Agentimus',
				'category'        => '',
				'domain'          => '   ',
				'add_prompts'     => array(),
				'add_competitors' => array(),
				'prompts'         => array(),
				'competitors'     => array(),
				'active'          => true,
				'targets'         => array(),
			)
		);

		$this->assertFalse( $out['changed'] );
		$this->assertSame( $before, $this->product() );
	}

	public function test_paused_stays_paused() {
		$this->tool()->save( array( 'name' => 'Agentimus', 'add_prompts' => array( 'New?' ) ) );
		$this->assertFalse( $this->product()['active'] );
	}

	public function test_the_schedule_and_provider_keys_are_left_alone() {
		$this->visibility->update( array( 'scheduleActive' => false, 'frequency' => 'weekly', 'providers' => array( 'openai' => array( 'enabled' => true, 'api_key' => 'sk-keep' ) ) ) );
		$before = $this->visibility->all();

		$this->tool()->save( array( 'name' => 'Agentimus', 'add_prompts' => array( 'New?' ) ) );

		$after            = $this->visibility->all();
		$before['targets'] = null;
		$after['targets']  = null;
		$this->assertSame( $before, $after );
	}

	public function test_going_past_a_cap_is_refused_whole() {
		$prompts = array();
		for ( $i = 0; $i <= VisibilitySettings::MAX_PROMPTS; $i++ ) {
			$prompts[] = "Question $i?";
		}
		$before = $this->visibility->get( 'targets' );
		$result = $this->tool()->save( array( 'name' => 'Agentimus', 'add_prompts' => $prompts ) );

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_products_full', $result->get_error_code() );
		$this->assertSame( $before, $this->visibility->get( 'targets' ) );

		for ( $i = 2; $i <= VisibilitySettings::MAX_TARGETS; $i++ ) {
			$this->assertIsArray( $this->tool()->save( array( 'name' => "Product $i" ) ) );
		}
		$full = $this->tool()->save( array( 'name' => 'One too many' ) );
		$this->assertWPError( $full );
		$this->assertSame( 'agentimus_products_full', $full->get_error_code() );
	}

	public function test_a_name_is_required() {
		$result = $this->tool()->save( array( 'name' => '  ' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_product_name', $result->get_error_code() );
	}

	public function test_changes_are_limited_per_connection() {
		for ( $i = 0; $i < CitationProducts::HOURLY_LIMIT; $i++ ) {
			$this->assertIsArray( $this->tool()->save( array( 'name' => 'Agentimus', 'category' => "Kind $i" ) ) );
		}
		$before = $this->visibility->get( 'targets' );
		$result = $this->tool()->save( array( 'name' => 'Agentimus', 'category' => 'One more' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_citation_rate_limited', $result->get_error_code() );
		$this->assertSame( $before, $this->visibility->get( 'targets' ) );
	}

	/** Typed in another case, it is still the owner's product, under the owner's spelling. */
	public function test_the_answer_and_the_log_use_the_stored_name() {
		Table::install();
		$out = $this->tool()->save( array( 'name' => 'AGENTIMUS', 'add_prompts' => array( 'One?' ) ) );

		$this->assertSame( 'Agentimus', $out['name'] );
		$subjects = array();
		foreach ( Store::recent( 50, 'setting_changed' ) as $row ) {
			$subjects[] = ( (array) $row )['subject'];
		}
		$this->assertContains( 'citation product Agentimus: +1 questions, +0 rivals', $subjects );
	}

	public function test_the_owner_can_see_what_was_added() {
		Table::install();
		$this->tool()->save( array( 'name' => 'Agentimus', 'add_prompts' => array( 'One?', 'Two?' ), 'add_competitors' => array( 'Rival' ) ) );

		$subjects = array();
		foreach ( Store::recent( 50, 'setting_changed' ) as $row ) {
			$subjects[] = ( (array) $row )['subject'];
		}
		$this->assertContains( 'citation product Agentimus: +2 questions, +1 rivals', $subjects );
	}
}
