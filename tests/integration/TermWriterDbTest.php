<?php
/**
 * Stage 2, step 2: an agent can create a category or tag, and rename or describe one.
 *
 * No delete and no merge. A slug is set once, at creation: changing it breaks the old
 * address. Two terms with one name are refused, because the write tools take terms by
 * name and could not tell them apart. A description renders as HTML, and admins hold
 * unfiltered_html, so it goes through the same script check as a post body.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\Registrar;
use Agentimus\Abilities\TermWriter;
use Agentimus\AgentAccess\Store;
use Agentimus\AgentAccess\Table;
use Agentimus\Settings;

final class TermWriterDbTest extends DbTestCase {

	/** @var Settings */
	private $settings;

	/**
	 * ⚠️ Once per class, before any test's transaction. Installing runs DDL, which can
	 * commit the open transaction, and that saved this class's "MCP server on" setting
	 * for every later test (OauthRestTest on WP 6.0).
	 */
	public static function wpSetUpBeforeClass() {
		Table::install();
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings = new Settings();
		$this->switch_on( true );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function switch_on( $on ) {
		$all                         = $this->settings->stored();
		$all['enable_mcp_server']    = true;
		$all['enable_agent_writes']  = true;
		$all['agent_writes_content'] = $on;
		$this->settings->update( $all );
	}

	private function writer() {
		return new TermWriter( $this->settings );
	}

	private function category( $name = 'Engineering', array $args = array() ) {
		$made = wp_insert_term( $name, 'category', $args );
		return (int) $made['term_id'];
	}

	private function assert_refused( $code, $result ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/* -- switch and permission ------------------------------------------- */

	public function test_the_tools_need_the_switch_and_are_write_tools() {
		foreach ( array( 'create-term', 'update-term' ) as $slug ) {
			$this->switch_on( false );
			$this->assertNotContains( "agentimus/$slug", ( new Registrar( $this->settings ) )->mcp_abilities() );
			$this->switch_on( true );
			$this->assertContains( "agentimus/$slug", ( new Registrar( $this->settings ) )->mcp_abilities() );
			$this->assertTrue( Registrar::is_write_tool_name( "agentimus/$slug" ) );
		}
	}

	public function test_both_are_refused_with_the_switch_off() {
		$id = $this->category();
		$this->switch_on( false );
		$this->assert_refused( 'agentimus_content_off', $this->writer()->create( array( 'taxonomy' => 'category', 'name' => 'New One' ) ) );
		$this->assertEmpty( term_exists( 'New One', 'category' ) );
		$this->assert_refused( 'agentimus_content_off', $this->writer()->update( array( 'term_id' => $id, 'name' => 'Renamed' ) ) );
		$this->assertSame( 'Engineering', get_term( $id )->name );
	}

	public function test_a_user_who_may_not_manage_terms_is_refused() {
		$id = $this->category();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assert_refused( 'agentimus_cannot_manage_terms', $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'Nope' ) ) );
		$this->assert_refused( 'agentimus_cannot_manage_terms', $this->writer()->update( array( 'term_id' => $id, 'name' => 'Nope' ) ) );

		$registrar = new Registrar( $this->settings );
		$this->assertFalse( $registrar->can_write_term( array( 'taxonomy' => 'post_tag' ) ) );
		$this->assertFalse( $registrar->can_write_term( array( 'term_id' => $id ) ) );
	}

	public function test_the_permission_check_lets_an_admin_through_and_fails_closed() {
		$registrar = new Registrar( $this->settings );
		$this->assertTrue( $registrar->can_write_term( array( 'taxonomy' => 'category' ) ) );
		$this->assertTrue( $registrar->can_write_term( array( 'term_id' => $this->category() ) ) );
		$this->assertFalse( $registrar->can_write_term( array( 'taxonomy' => 'nav_menu' ) ) );
		$this->assertFalse( $registrar->can_write_term( array() ) );
	}

	/* -- create ---------------------------------------------------------- */

	public function test_it_creates_a_category_with_everything() {
		$parent = $this->category( 'Parent Topic' );
		$out    = $this->writer()->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Child Topic',
				'slug'        => 'kid',
				'description' => 'What this <em>section</em> covers.',
				'parent'      => $parent,
			)
		);
		$this->assertIsArray( $out );
		$term = get_term( $out['id'], 'category' );
		$this->assertSame( 'Child Topic', $term->name );
		$this->assertSame( 'kid', $term->slug );
		$this->assertSame( 'What this <em>section</em> covers.', $term->description );
		$this->assertSame( $parent, (int) $term->parent );
		$this->assertSame( 'kid', $out['slug'] );
		$this->assertSame( 'category', $out['taxonomy'] );
		$this->assertSame( get_term_link( $term ), $out['url'] );
	}

	public function test_it_creates_a_tag() {
		$out = $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'Vue' ) );
		$this->assertSame( 'post_tag', $out['taxonomy'] );
		$this->assertInstanceOf( \WP_Term::class, get_term( $out['id'], 'post_tag' ) );
	}

	public function test_backslashes_survive_in_name_and_description() {
		$out  = $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'C:\\Path', 'description' => 'Regex \\d+' ) );
		$term = get_term( $out['id'], 'post_tag' );
		$this->assertSame( 'C:\\Path', $term->name );
		$this->assertSame( 'Regex \\d+', $term->description );
	}

	public function test_only_categories_and_tags_can_be_written() {
		$this->assert_refused( 'agentimus_bad_taxonomy', $this->writer()->create( array( 'taxonomy' => 'nav_menu', 'name' => 'Menu' ) ) );
		$menu = wp_insert_term( 'A Menu', 'nav_menu' );
		$this->assert_refused( 'agentimus_bad_taxonomy', $this->writer()->update( array( 'term_id' => $menu['term_id'], 'name' => 'Renamed' ) ) );
		$this->assertSame( 'A Menu', get_term( $menu['term_id'] )->name );
	}

	public function test_a_name_is_required_and_markup_leaves_no_name_behind() {
		$this->assert_refused( 'agentimus_no_term_name', $this->writer()->create( array( 'taxonomy' => 'category', 'name' => '   ' ) ) );
		$this->assert_refused( 'agentimus_no_term_name', $this->writer()->create( array( 'taxonomy' => 'category', 'name' => '<script></script>' ) ) );
	}

	public function test_a_second_term_with_the_same_name_is_refused_even_under_another_parent() {
		$parent = $this->category( 'Parent Topic' );
		$first  = $this->category( 'Shared Name' );
		foreach ( array( 'Shared Name', 'shared name' ) as $name ) {
			$result = $this->writer()->create( array( 'taxonomy' => 'category', 'name' => $name, 'parent' => $parent ) );
			$this->assert_refused( 'agentimus_term_exists', $result );
			$this->assertStringContainsString( (string) $first, $result->get_error_message(), 'The refusal names the one to reuse.' );
		}
	}

	public function test_the_same_name_in_the_other_taxonomy_is_fine() {
		$this->category( 'Laravel' );
		$out = $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'Laravel' ) );
		$this->assertIsArray( $out );
	}

	public function test_a_taken_slug_is_refused_rather_than_changed_quietly() {
		$this->category( 'Other', array( 'slug' => 'taken' ) );
		$this->assert_refused( 'agentimus_slug_taken', $this->writer()->create( array( 'taxonomy' => 'category', 'name' => 'New One', 'slug' => 'taken' ) ) );
		$this->assertEmpty( term_exists( 'New One', 'category' ) );
	}

	public function test_a_parent_is_refused_on_tags_and_when_it_does_not_exist() {
		$tag = wp_insert_term( 'A Tag', 'post_tag' );
		$this->assert_refused( 'agentimus_bad_term_parent', $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'B Tag', 'parent' => $tag['term_id'] ) ) );
		$this->assert_refused( 'agentimus_bad_term_parent', $this->writer()->create( array( 'taxonomy' => 'category', 'name' => 'No Parent', 'parent' => 999999 ) ) );
		$this->assert_refused( 'agentimus_bad_term_parent', $this->writer()->create( array( 'taxonomy' => 'category', 'name' => 'Wrong Parent', 'parent' => $tag['term_id'] ) ) );
	}

	/* -- update ---------------------------------------------------------- */

	public function test_rename_keeps_the_slug_so_the_address_holds() {
		$id  = $this->category( 'Old Name', array( 'description' => 'Kept.' ) );
		$out = $this->writer()->update( array( 'term_id' => $id, 'name' => 'New Name' ) );
		$this->assertSame( 'New Name', $out['name'] );
		$this->assertSame( 'old-name', get_term( $id )->slug );
		$this->assertSame( 'Kept.', get_term( $id )->description, 'Only the field sent changes.' );
	}

	public function test_a_slug_sent_to_update_is_refused() {
		$id = $this->category( 'Old Name' );
		$this->assert_refused( 'agentimus_slug_fixed', $this->writer()->update( array( 'term_id' => $id, 'slug' => 'moved' ) ) );
		$this->assertSame( 'old-name', get_term( $id )->slug );
	}

	public function test_describing_and_clearing_a_description() {
		$id = $this->category();
		$this->assertSame( 'Covers it.', $this->writer()->update( array( 'term_id' => $id, 'description' => 'Covers it.' ) )['description'] );
		$this->assertSame( '', $this->writer()->update( array( 'term_id' => $id, 'description' => '' ) )['description'] );
		$this->assertSame( 'Engineering', get_term( $id )->name );
	}

	public function test_renaming_onto_another_term_is_refused_and_changing_only_case_is_not() {
		$this->category( 'Taken Name' );
		$id = $this->category( 'Mine' );
		$this->assert_refused( 'agentimus_term_exists', $this->writer()->update( array( 'term_id' => $id, 'name' => 'Taken Name' ) ) );
		$this->assertSame( 'Mine', get_term( $id )->name );
		$this->assertSame( 'MINE', $this->writer()->update( array( 'term_id' => $id, 'name' => 'MINE' ) )['name'] );
	}

	public function test_moving_under_a_parent_and_back_to_the_top() {
		$parent = $this->category( 'Parent Topic' );
		$id     = $this->category( 'Child Topic' );
		$this->assertSame( $parent, $this->writer()->update( array( 'term_id' => $id, 'parent' => $parent ) )['parent'] );
		$this->assertSame( 0, $this->writer()->update( array( 'term_id' => $id, 'parent' => 0 ) )['parent'] );
	}

	public function test_a_term_cannot_sit_under_itself_or_its_own_child() {
		$top   = $this->category( 'Top Topic' );
		$child = $this->category( 'Lower Topic', array( 'parent' => $top ) );
		$this->assert_refused( 'agentimus_bad_term_parent', $this->writer()->update( array( 'term_id' => $top, 'parent' => $top ) ) );
		$this->assert_refused( 'agentimus_bad_term_parent', $this->writer()->update( array( 'term_id' => $top, 'parent' => $child ) ) );
		$this->assertSame( 0, (int) get_term( $top )->parent );
	}

	public function test_a_missing_term_and_an_empty_call_are_refused() {
		$this->assert_refused( 'agentimus_not_found', $this->writer()->update( array( 'term_id' => 999999, 'name' => 'Ghost' ) ) );
		$this->assert_refused( 'agentimus_nothing_to_change', $this->writer()->update( array( 'term_id' => $this->category() ) ) );
	}

	public function test_a_blank_name_on_update_is_refused() {
		$id = $this->category();
		$this->assert_refused( 'agentimus_no_term_name', $this->writer()->update( array( 'term_id' => $id, 'name' => '  ' ) ) );
		$this->assertSame( 'Engineering', get_term( $id )->name );
	}

	/* -- the script check ------------------------------------------------ */

	public function test_a_description_cannot_add_anything_that_runs() {
		$id = $this->category();
		foreach ( array( '<script>alert(1)</script>', '<img src="x" onerror="alert(1)">', '<a href="javascript:alert(1)">x</a>' ) as $bad ) {
			$this->assert_refused( 'agentimus_unsafe_markup', $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'Bad One', 'description' => $bad ) ) );
			$this->assert_refused( 'agentimus_unsafe_markup', $this->writer()->update( array( 'term_id' => $id, 'description' => $bad ) ) );
		}
		$this->assertEmpty( term_exists( 'Bad One', 'post_tag' ) );
		$this->assertSame( '', get_term( $id )->description );
	}

	public function test_what_wordpress_removes_is_named_rather_than_hidden() {
		$id  = $this->category();
		$out = $this->writer()->update( array( 'term_id' => $id, 'description' => '<p>Plain <em>words</em>.</p>' ) );
		$this->assertSame( 'Plain <em>words</em>.', trim( $out['description'] ), 'WordPress removes the p tag for everyone.' );
		$this->assertNotSame( '', $out['note'] );

		$created = $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'Noted', 'description' => '<p>Text.</p>' ) );
		$this->assertNotSame( '', $created['note'] );
	}

	public function test_a_description_stored_as_sent_carries_no_note() {
		$id = $this->category();
		$this->assertSame( '', $this->writer()->update( array( 'term_id' => $id, 'description' => 'Plain <em>words</em>.' ) )['note'] );
		$this->assertSame( '', $this->writer()->update( array( 'term_id' => $id, 'name' => 'Renamed' ) )['note'], 'No description sent, nothing to say.' );
	}

	public function test_markup_stored_outside_core_filter_does_not_block_an_agent_edit() {
		if ( ! method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			$this->markTestSkipped( 'Below WP 6.5 only the in-admin assistant writes; AgentMarkupFallbackTest covers it.' );
		}
		remove_filter( 'pre_term_description', 'wp_filter_kses' );
		$id = $this->category( 'Embedded', array( 'description' => '<iframe src="https://example.org/a"></iframe>' ) );
		add_filter( 'pre_term_description', 'wp_filter_kses' );
		$this->assertStringContainsString( '<iframe', get_term( $id )->description, 'The fixture must really hold it.' );

		$out = $this->writer()->update( array( 'term_id' => $id, 'description' => '<iframe src="https://example.org/a"></iframe> More.' ) );
		$this->assertIsArray( $out );
		$this->assertNotSame( '', $out['note'], 'WordPress drops the iframe on save, and the answer must say so.' );
	}

	/* -- limit and record ------------------------------------------------ */

	public function test_the_hourly_limit_stops_a_burst_and_says_nothing_was_written() {
		for ( $i = 0; $i < TermWriter::HOURLY_LIMIT; $i++ ) {
			$this->assertIsArray( $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => "Burst $i" ) ) );
		}
		$this->assert_refused( 'agentimus_terms_rate_limited', $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'One Too Many' ) ) );
		$this->assertEmpty( term_exists( 'One Too Many', 'post_tag' ) );
	}

	public function test_a_refused_call_does_not_spend_the_limit() {
		for ( $i = 0; $i < TermWriter::HOURLY_LIMIT + 1; $i++ ) {
			$this->assert_refused( 'agentimus_no_term_name', $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => '' ) ) );
		}
		$this->assertIsArray( $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'Still Fine' ) ) );
	}

	public function test_the_owner_can_see_each_change_in_agent_access() {
		$id = $this->writer()->create( array( 'taxonomy' => 'post_tag', 'name' => 'Logged' ) )['id'];
		$this->writer()->update( array( 'term_id' => $id, 'name' => 'Logged Too', 'description' => 'Yes.' ) );

		$subjects = array();
		foreach ( Store::recent( 50, 'setting_changed' ) as $row ) {
			$subjects[] = ( (array) $row )['subject'];
		}
		$this->assertContains( 'post_tag created: Logged', $subjects );
		$this->assertContains( "post_tag $id changed: name, description", $subjects );
	}

	public function test_read_terms_shows_the_description_an_edit_starts_from() {
		$id     = $this->category( 'Described', array( 'description' => 'What is here now.' ) );
		$method = new \ReflectionMethod( Registrar::class, 'term_row' );
		$method->setAccessible( true );
		$this->assertSame( 'What is here now.', $method->invoke( null, get_term( $id ) )['description'] );
	}
}
