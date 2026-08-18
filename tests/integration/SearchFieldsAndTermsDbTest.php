<?php
/**
 * The last two steps the write path stopped short of.
 *
 * ⭐⭐ BOTH FOUND BY USING THE TOOLS. An agent wrote, dressed and graded a post
 * to full marks and could not fill the two fields that decide how it appears in
 * a search result — the focus it is measured against, and the title a result
 * shows. And to file that post correctly it had to type category names from
 * memory, because nothing would list the ones the site already has: "New
 * Features" and "New features" are two categories, and the second one gets
 * created.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Focus;
use Agentimus\Grades;
use Agentimus\Seo;
use Agentimus\Settings;
use Agentimus\Worklist;

final class SearchFieldsAndTermsDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->allow_writes();
	}

	/** The owner's two switches: the server, and the write tier. */
	private function allow_writes() {
		$settings = new Settings();
		$all      = $settings->all(); // Partial updates reset unset settings — merge into all().
		$all['enable_mcp_server']   = true;
		$all['enable_agent_writes'] = true;
		$settings->update( $all );
	}

	private function ability( $name ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+); the feature self-gates below that.' );
		}
		$ability = wp_get_ability( 'agentimus/' . $name );
		$this->assertNotNull( $ability, "agentimus/$name did not register." );
		return $ability;
	}

	/**
	 * Run the search-fields write.
	 *
	 * ⚠️ Through the WRITER, not the ability. The write tier only registers while
	 * the owner's switch is on and the abilities registry is built once per
	 * process, so in a full-suite run an earlier test has already built it with
	 * writes off — asking for the ability there is not a failure, it is the
	 * feature. The tool is a thin wrapper over this call, and the guard it states
	 * in friendlier words lives here too. The registration itself is pinned by
	 * {@see \Agentimus\Tests\SearchFieldsAbilityTest} and by the MCP-list test
	 * below.
	 *
	 * @param array $args post_id + focus / seo_title.
	 * @return array|\WP_Error
	 */
	private function write_fields( array $args ) {
		return ( new \Agentimus\Abilities\ContentWriter( new Settings() ) )->update( $args );
	}

	private function post( $title = 'A post' ) {
		return (int) self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => str_repeat( 'A plain sentence about a plain thing. ', 30 ),
		) );
	}

	/* ------------------------------------------------- the search fields */

	public function test_an_agent_can_set_the_search_a_page_answers() {
		$id = $this->post( 'Choosing ink in bulk' );

		$out = $this->write_fields( array(
			'post_id'   => $id,
			'focus'     => 'bulk ink cartridges',
			'seo_title' => 'Buying ink in bulk: what actually saves money',
		) );

		$this->assertNotWPError( $out );
		$this->assertSame( 'bulk ink cartridges', get_post_meta( $id, Focus::META, true ) );
		$this->assertSame( 'Buying ink in bulk: what actually saves money', get_post_meta( $id, Seo::META_TITLE, true ) );
		// ⭐ The answer reads the fields BACK, so a caller sees what stood rather
		// than assuming its own input took.
		$this->assertSame( 'bulk ink cartridges', $out['focus'] );
		$this->assertSame( 'Buying ink in bulk: what actually saves money', $out['seoTitle'] );
	}

	/**
	 * ⚠️ Neither field changes a word of the page, and both change what the page
	 * is MEASURED against — the focus IS the search a row is judged on, and the
	 * SEO title is the title that measurement reads. A meta-only write never
	 * fires save_post, so without this the screens would go on judging the page
	 * against the search it used to be for.
	 */
	public function test_changing_the_focus_sends_the_page_back_to_be_read() {
		$id = $this->post( 'Graded before the focus moved' );
		Grades::record( $id, array(
			'needsWork' => true, 'flags' => 1, 'stake' => 10, 'coverage' => 'answered',
			'hasFocus'  => true, 'points' => 80, 'flagIds' => array( 'summary' ),
			'gradeable' => true, 'hash' => 'h' . $id,
		) );
		$this->assertSame( array(), Grades::ungraded( array( 'post' ), 10 ) );

		$this->write_fields( array( 'post_id' => $id, 'focus' => 'something else entirely' ) );

		$this->assertSame( array( $id ), Grades::ungraded( array( 'post' ), 10 ), 'It is measured against a different search now.' );
		$this->assertTrue( Grades::stored( array( $id ) )[ $id ]['stale'] );
		$this->assertNotFalse( wp_next_scheduled( Grades::CRON ), 'And the reading is booked, not left to the hour.' );
	}

	public function test_an_empty_string_clears_a_field_rather_than_storing_one() {
		$id = $this->post();
		update_post_meta( $id, Focus::META, 'the old search' );

		$this->write_fields( array( 'post_id' => $id, 'focus' => '' ) );

		$this->assertSame( '', (string) get_post_meta( $id, Focus::META, true ) );
	}

	/**
	 * ⛔ Refused, never quietly dropped. A write that reports success for a field
	 * it discarded is the shape of every "I set it and nothing happened" bug.
	 */
	public function test_the_seo_title_is_refused_where_an_seo_plugin_owns_titles() {
		$id = $this->post();
		// The owner's own switch is the honest stand-in for "an SEO plugin owns
		// titles here": Seo::title_ui_enabled() is that setting AND solo mode.
		$settings = new Settings();
		$all      = $settings->all();
		$all['enable_seo_titles'] = false;
		$settings->update( $all );

		$out = $this->write_fields( array(
			'post_id'   => $id,
			'seo_title' => 'A title nothing would serve',
		) );

		$this->assertWPError( $out );
		$this->assertSame( '', (string) get_post_meta( $id, Seo::META_TITLE, true ), 'Nothing was stored.' );
	}

	/* ------------------------------------------------------- the terms */

	public function test_it_lists_the_terms_the_write_tools_expect_by_name() {
		$id = $this->post();
		wp_set_object_terms( $id, array( 'New features' ), 'category' );
		wp_set_object_terms( $id, array( 'ink', 'printers' ), 'post_tag' );

		$out = $this->ability( 'read-terms' )->execute( null );

		$this->assertNotWPError( $out, 'Every argument is optional, so a bare call has to work.' );
		$this->assertSame( 'post', $out['postType'] );

		$byField = array_column( $out['taxonomies'], null, 'field' );
		$this->assertArrayHasKey( 'categories', $byField );
		$this->assertArrayHasKey( 'tags', $byField );

		// ⭐ The NAME, exactly as create-content and update-content expect it —
		// the whole reason this tool exists. "New Features" would make a second
		// category beside this one.
		$names = array_column( $byField['categories']['terms'], 'name' );
		$this->assertContains( 'New features', $names );
		$this->assertSame( array( 'ink', 'printers' ), array_values( array_diff( array_column( $byField['tags']['terms'], 'name' ), array() ) ) );

		$this->assertTrue( $byField['categories']['hierarchical'] );
		$this->assertFalse( $byField['tags']['hierarchical'] );
		$this->assertTrue( $byField['categories']['canCreate'], 'An administrator may add one.' );
	}

	public function test_a_search_narrows_the_list_on_a_site_with_many() {
		$id = $this->post();
		wp_set_object_terms( $id, array( 'ink', 'paper', 'printers' ), 'post_tag' );

		$out     = $this->ability( 'read-terms' )->execute( array( 'search' => 'ink' ) );
		$byField = array_column( $out['taxonomies'], null, 'field' );

		$this->assertSame( array( 'ink' ), array_column( $byField['tags']['terms'], 'name' ) );
	}

	/**
	 * ⛔ A taxonomy the write tools cannot set is still ON the site. Leaving it
	 * out entirely invites an agent to believe it filed a page somewhere it
	 * never could.
	 */
	public function test_a_taxonomy_the_write_tools_cannot_set_is_named_rather_than_hidden() {
		register_taxonomy( 'series', 'post', array( 'public' => true, 'label' => 'Series' ) );

		$out = $this->ability( 'read-terms' )->execute( null );

		// ⚠️ `post_format` belongs here too — it is public, it is on posts, and
		// the write tools cannot set it either. Naming it is the honest answer.
		$this->assertContains( 'series', array_column( $out['notSettable'], 'taxonomy' ) );
		$this->assertNotContains( 'series', array_column( $out['taxonomies'], 'taxonomy' ) );

		unregister_taxonomy( 'series' );
	}

	public function test_an_unknown_content_type_is_an_error_not_an_empty_answer() {
		$out = $this->ability( 'read-terms' )->execute( array( 'post_type' => 'no_such_thing' ) );

		$this->assertWPError( $out, 'Empty groups would read as “this type has no categories”.' );
	}

	public function test_both_tools_are_on_the_mcp_server_with_the_write_tier_on() {
		$tools = ( new \Agentimus\Abilities\Registrar( new Settings() ) )->mcp_abilities();

		$this->assertContains( 'agentimus/read-terms', $tools );
		$this->assertContains( 'agentimus/write-search-fields', $tools );
		// ⛔ …and the write half must disappear with the owner's switch.
		$settings = new Settings();
		$all      = $settings->all();
		$all['enable_agent_writes'] = false;
		$settings->update( $all );

		$off = ( new \Agentimus\Abilities\Registrar( new Settings() ) )->mcp_abilities();
		$this->assertNotContains( 'agentimus/write-search-fields', $off );
		$this->assertContains( 'agentimus/read-terms', $off, 'Reading what the site already calls things is not a write.' );
	}
}
