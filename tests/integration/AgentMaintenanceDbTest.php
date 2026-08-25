<?php
/**
 * The three abilities that let an agent MAINTAIN a site rather than rewrite it.
 *
 * ⭐⭐ FOUND BY USING THE TOOLS on a real site, 2026-08-25. Asked to find and fix
 * everything wrong with heera.it, the agent surface found all 67 rows and could
 * act on 11 — and none of the 54 that were one missing sentence of alt text.
 * Worse, the 11 it could reach were reachable only by replacing a whole
 * published article, because nothing returned a body to edit and the only writer
 * replaced everything.
 *
 * These tests are about the REFUSALS as much as the writes. A tool an owner can
 * point at their whole site has to be one that fails loudly on a guess.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\ContentEditor;
use Agentimus\Abilities\MediaWriter;
use Agentimus\Grades;
use Agentimus\PageCheck;

final class AgentMaintenanceDbTest extends DbTestCase {

	/** @var int */
	private $admin;

	public function set_up(): void {
		parent::set_up();
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	/* -- fixtures ------------------------------------------------------- */

	/** A post whose body is real block markup, the way the editor stores it. */
	private function post( $body = null ) {
		$body = null === $body
			? "<!-- wp:paragraph -->\n<p>Retrieval has legitimate uses.</p>\n<!-- /wp:paragraph -->\n\n"
				. "<!-- wp:paragraph -->\n<p>The tool should be replaceable. You should not.</p>\n<!-- /wp:paragraph -->"
			: $body;
		return (int) self::factory()->post->create(
			array(
				'post_title'   => 'Tools Oriented Programming',
				'post_content' => $body,
				'post_status'  => 'publish',
				'post_author'  => $this->admin,
			)
		);
	}

	/**
	 * An image in the library. Built by hand rather than through the attachment
	 * factory: its signature moved between the WordPress versions this suite runs
	 * on, and wp_attachment_is_image() needs both the mime type and an attached
	 * file, which this gives it on every one of them.
	 */
	private function image( $title = 'sunrise' ) {
		$id = (int) wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_title'     => $title,
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $id, '_wp_attached_file', '2026/08/' . $title . '.jpg' );
		return $id;
	}

	/** A post with a featured image and no description on it — the commonest row. */
	private function post_with_undescribed_image() {
		$post_id = $this->post();
		set_post_thumbnail( $post_id, $this->image() );
		return $post_id;
	}

	private function alt_row( $post_id ) {
		foreach ( PageCheck::analyze( get_post( $post_id ) ) as $row ) {
			if ( 'featured_alt' === $row['id'] ) {
				return $row;
			}
		}
		return null;
	}

	private function body( $post_id ) {
		return (string) get_post_field( 'post_content', $post_id );
	}

	/* -- describe-image ------------------------------------------------- */

	/**
	 * ⭐ THE WHOLE POINT. The check said "not described"; after one call it says
	 * described — measured through PageCheck, not by reading back the meta we
	 * just wrote.
	 */
	public function test_describing_a_featured_image_clears_the_check_that_flagged_it() {
		$post_id = $this->post_with_undescribed_image();
		$this->assertNotSame( 'pass', $this->alt_row( $post_id )['status'], 'the fixture must start flagged' );

		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $post_id,
				'alt'     => 'A sunrise over the river, seen from the bridge.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( '', $out['previous'], 'nothing was there before' );
		$this->assertSame( 'A sunrise over the river, seen from the bridge.', $out['alt'] );
		$this->assertSame( 'pass', $this->alt_row( $post_id )['status'], 'the check must actually clear' );
	}

	/**
	 * ⛔ THE GUARD THE TOOL IS BUILT AROUND: a description somebody wrote is not
	 * an agent's to paraphrase, least of all in a run over a whole site.
	 */
	public function test_it_refuses_to_overwrite_a_description_that_is_already_there() {
		$post_id = $this->post_with_undescribed_image();
		update_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', 'The bridge at dawn.' );

		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $post_id,
				'alt'     => 'Something else entirely.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_already_described', $out->get_error_code() );
		$this->assertStringContainsString( 'The bridge at dawn.', $out->get_error_message(), 'the refusal quotes what would have been lost' );
		$this->assertSame(
			'The bridge at dawn.',
			get_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', true ),
			'and nothing was written'
		);
	}

	/** Asked for by name, a correction goes through. */
	public function test_replace_overwrites_when_it_is_asked_for() {
		$post_id = $this->post_with_undescribed_image();
		update_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', 'IMG_4831' );

		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $post_id,
				'alt'     => 'A sunrise over the river.',
				'replace' => true,
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( 'IMG_4831', $out['previous'] );
	}

	/** ⛔ It can add a description or correct one. It can never blank one. */
	public function test_an_empty_description_is_refused_rather_than_stored() {
		$post_id = $this->post_with_undescribed_image();
		update_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', 'The bridge at dawn.' );

		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $post_id,
				'alt'     => '   ',
				'replace' => true,
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_empty_alt', $out->get_error_code() );
		$this->assertSame( 'The bridge at dawn.', get_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', true ) );
	}

	/**
	 * ⭐ Alt text lives on the image, so writing it does not save the page and
	 * nothing would otherwise mark the page's stored reading out of date — the
	 * worklist would go on reporting a flag that is fixed.
	 */
	public function test_the_pages_using_the_image_are_marked_for_re_reading() {
		$post_id = $this->post_with_undescribed_image();
		$other   = $this->post();
		set_post_thumbnail( $other, get_post_thumbnail_id( $post_id ) );

		// Both pages start with a stored reading, as the sweep would leave them:
		// graded, gradeable, and fingerprinted with the body they were read from.
		foreach ( array( $post_id, $other ) as $id ) {
			Grades::record(
				$id,
				array(
					// ⚠️ A row with flags but no flagged ids and no points is the
					// CONTRADICTION the store treats as unreadable and re-queues
					// on sight ({@see Grades::UNREADABLE_SQL}) — which would make
					// this fixture start in the very state it is here to detect.
					'needsWork' => false,
					'flags'     => 0,
					'points'    => 100,
					'gradeable' => true,
					'hash'      => Grades::hash( get_post( $id ) ),
				)
			);
		}
		$this->assertSame( 0, Grades::rechecking( array( 'post' ) ), 'both readings start current' );

		$out = ( new MediaWriter() )->describe(
			array(
				'attachment_id' => get_post_thumbnail_id( $post_id ),
				'alt'           => 'A sunrise over the river.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertContains( $post_id, $out['refreshed'] );
		$this->assertContains( $other, $out['refreshed'], 'every page using the image, not only the one aimed at' );
		$this->assertSame( 0, $out['postId'], 'aimed at the library item, not a page' );
		// ⛔ And the ids are not a promise: the stored readings really are aged,
		// so the owner's own screen counts them as owed a re-read.
		$this->assertSame( 2, Grades::rechecking( array( 'post' ) ) );
	}

	/** A page with no featured image is told what its flag actually is. */
	public function test_a_page_with_no_featured_image_is_told_so() {
		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $this->post(),
				'alt'     => 'A sunrise over the river.',
			)
		);
		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_no_featured_image', $out->get_error_code() );
	}

	/** Two aims can name two pictures, so the tool refuses to pick one. */
	public function test_both_aims_at_once_is_refused_rather_than_guessed() {
		$post_id = $this->post_with_undescribed_image();
		$out     = ( new MediaWriter() )->describe(
			array(
				'post_id'       => $post_id,
				'attachment_id' => $this->image( 'other' ),
				'alt'           => 'A sunrise over the river.',
			)
		);
		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_two_targets', $out->get_error_code() );
	}

	/** The gate and the write must resolve the SAME image. */
	public function test_the_permission_gate_resolves_the_same_image_the_write_would() {
		$post_id = $this->post_with_undescribed_image();
		$target  = MediaWriter::target( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $target );
		$this->assertSame( (int) get_post_thumbnail_id( $post_id ), $target['attachment'] );
	}

	/* -- read-content --------------------------------------------------- */

	/** ⛔ The SOURCE, byte for byte — anything else is an anchor that cannot match. */
	public function test_read_content_returns_the_body_exactly_as_stored() {
		$post_id = $this->post();
		$out     = ( new ContentEditor() )->read( $post_id );

		$this->assertNotWPError( $out );
		$this->assertSame( $this->body( $post_id ), $out['content'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $out['content'], 'block comments and all' );
		$this->assertSame( 'blocks', $out['format'] );
		$this->assertTrue( $out['editable'] );
		$this->assertSame( '', $out['note'] );
	}

	/** Somebody in the editor is a fact the reader needs before it edits. */
	public function test_read_content_names_whoever_has_the_post_open() {
		$post_id = $this->post();
		$editor  = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Rumana' ) );
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $editor );

		$out = ( new ContentEditor() )->read( $post_id );

		$this->assertSame( 'Rumana', $out['lockedBy'] );
		$this->assertFalse( $out['editable'] );
		$this->assertStringContainsString( 'autosave', $out['note'] );
	}

	/* -- edit-content --------------------------------------------------- */

	/** One passage changes. Everything else is byte-identical. */
	public function test_one_passage_changes_and_the_rest_of_the_body_does_not() {
		$post_id = $this->post();
		$before  = $this->body( $post_id );

		$out = ( new ContentEditor() )->edit(
			array(
				'post_id' => $post_id,
				'old'     => 'Retrieval has legitimate uses.',
				'new'     => '<a href="https://arxiv.org/abs/2005.11401">Retrieval</a> has legitimate uses.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['changed'] );
		$this->assertGreaterThan( 0, $out['revisionId'], 'the way back is handed over' );
		$this->assertSame(
			str_replace(
				'Retrieval has legitimate uses.',
				'<a href="https://arxiv.org/abs/2005.11401">Retrieval</a> has legitimate uses.',
				$before
			),
			$this->body( $post_id )
		);
		$this->assertStringContainsString( 'arxiv.org', $out['context'], 'the answer SHOWS what landed' );
		$this->assertStringContainsString( 'The tool should be replaceable', $this->body( $post_id ), 'the untouched paragraph is untouched' );
	}

	/** ⛔ A miss is a refusal, never a guess. */
	public function test_text_that_is_not_there_changes_nothing() {
		$post_id = $this->post();
		$before  = $this->body( $post_id );

		$out = ( new ContentEditor() )->edit(
			array(
				'post_id' => $post_id,
				'old'     => 'a sentence this post does not contain',
				'new'     => 'anything at all',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_no_match', $out->get_error_code() );
		$this->assertSame( $before, $this->body( $post_id ) );
	}

	/** ⛔ Two matches is a coin toss, and a coin toss is not a fix. */
	public function test_an_ambiguous_anchor_is_refused_with_the_count() {
		$post_id = $this->post(
			"<!-- wp:paragraph -->\n<p>It depends.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>It depends.</p>\n<!-- /wp:paragraph -->"
		);
		$before  = $this->body( $post_id );

		$out = ( new ContentEditor() )->edit(
			array(
				'post_id' => $post_id,
				'old'     => 'It depends.',
				'new'     => 'It does not depend.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_many_matches', $out->get_error_code() );
		$this->assertStringContainsString( '2 times', $out->get_error_message() );
		$this->assertSame( $before, $this->body( $post_id ) );
	}

	/** ⛔ An edit may not leave block markup the editor cannot open. */
	public function test_an_edit_that_would_orphan_a_block_comment_is_refused() {
		$post_id = $this->post();
		$before  = $this->body( $post_id );

		$out = ( new ContentEditor() )->edit(
			array(
				'post_id' => $post_id,
				'old'     => "<!-- wp:paragraph -->\n<p>Retrieval has legitimate uses.</p>",
				'new'     => '<p>Retrieval has legitimate uses.</p>',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_broken_blocks', $out->get_error_code() );
		$this->assertSame( $before, $this->body( $post_id ) );
	}

	/** A whole, balanced block may be added — the guard is about pairs, not size. */
	public function test_a_whole_block_may_be_added() {
		$post_id = $this->post();

		$out = ( new ContentEditor() )->edit(
			array(
				'post_id' => $post_id,
				'old'     => "<!-- wp:paragraph -->\n<p>Retrieval has legitimate uses.</p>\n<!-- /wp:paragraph -->",
				'new'     => "<!-- wp:paragraph -->\n<p>Retrieval has legitimate uses.</p>\n<!-- /wp:paragraph -->\n\n"
					. "<!-- wp:paragraph -->\n<p>So does a plain file.</p>\n<!-- /wp:paragraph -->",
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['changed'] );
		$this->assertStringContainsString( 'So does a plain file.', $this->body( $post_id ) );
	}

	/** ⛔ "Fixing" a page must never be able to empty it. */
	public function test_an_edit_that_would_empty_the_page_is_refused() {
		$body    = "<!-- wp:paragraph -->\n<p>All of it.</p>\n<!-- /wp:paragraph -->";
		$post_id = $this->post( $body );

		$out = ( new ContentEditor() )->edit(
			array(
				'post_id' => $post_id,
				'old'     => $body,
				'new'     => '',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_would_empty', $out->get_error_code() );
		$this->assertSame( $body, $this->body( $post_id ) );
	}

	/**
	 * ⚠️ THE AUTOSAVE TRAP, closed. An editor left open sends the body as it was
	 * when the tab loaded, minutes after an API write, and silently undoes it.
	 */
	public function test_it_will_not_write_while_somebody_has_the_post_open() {
		$post_id = $this->post();
		$before  = $this->body( $post_id );
		$editor  = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Rumana' ) );
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $editor );

		$out = ( new ContentEditor() )->edit(
			array(
				'post_id' => $post_id,
				'old'     => 'Retrieval has legitimate uses.',
				'new'     => 'Retrieval is useful.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_post_locked', $out->get_error_code() );
		$this->assertStringContainsString( 'Rumana', $out->get_error_message() );
		$this->assertSame( $before, $this->body( $post_id ) );
	}

	/** ⭐ Look before you leap: the same answer, with nothing written. */
	public function test_a_dry_run_shows_the_change_and_writes_nothing() {
		$post_id = $this->post();
		$before  = $this->body( $post_id );

		$out = ( new ContentEditor() )->edit(
			array(
				'post_id' => $post_id,
				'old'     => 'Retrieval has legitimate uses.',
				'new'     => 'Retrieval has narrow, real uses.',
				'dry_run' => true,
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['dryRun'] );
		$this->assertFalse( $out['changed'] );
		$this->assertStringContainsString( 'narrow, real uses', $out['context'], 'it shows what WOULD land' );
		$this->assertSame( $before, $this->body( $post_id ), 'and nothing was written' );
		$this->assertSame( 0, $out['revisionId'] );
	}

	/* -- reachable, not merely written --------------------------------- */

	/**
	 * ⚠️ REGISTERED ≠ ADVERTISED. A tool that exists in PHP and is not on the
	 * MCP server's list is a tool no agent will ever call, and this project has
	 * shipped that exact shape before — suggest-internal-links was promised in a
	 * release note and missing from this list for six versions.
	 */
	public function test_the_three_new_tools_are_advertised_to_agents() {
		$settings = new \Agentimus\Settings();
		$all      = $settings->all(); // Partial updates reset unset settings — merge into all().

		$all['enable_mcp_server']   = true;
		$all['enable_agent_writes'] = false;
		$settings->update( $all );
		$read_only = ( new \Agentimus\Abilities\Registrar( new \Agentimus\Settings() ) )->mcp_abilities();

		$this->assertContains( 'agentimus/read-content', $read_only, 'the body an edit is written against is a READ' );
		$this->assertNotContains( 'agentimus/edit-content', $read_only );
		$this->assertNotContains( 'agentimus/describe-image', $read_only );

		$all['enable_agent_writes'] = true;
		$settings->update( $all );
		$with_writes = ( new \Agentimus\Abilities\Registrar( new \Agentimus\Settings() ) )->mcp_abilities();

		$this->assertContains( 'agentimus/edit-content', $with_writes );
		$this->assertContains( 'agentimus/describe-image', $with_writes );
		$this->assertContains( 'agentimus/read-content', $with_writes );
	}

	/** Sending the text back unchanged is a no-op that says so. */
	public function test_an_edit_that_changes_nothing_says_so_instead_of_saving() {
		$post_id = $this->post();

		$out = ( new ContentEditor() )->edit(
			array(
				'post_id' => $post_id,
				'old'     => 'Retrieval has legitimate uses.',
				'new'     => 'Retrieval has legitimate uses.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertFalse( $out['changed'] );
		$this->assertSame( 0, $out['revisionId'] );
	}
}
