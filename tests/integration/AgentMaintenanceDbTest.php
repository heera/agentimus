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

	/**
	 * ⛔⛔ THE GUARD MUST AGREE WITH THE CHECK THAT RAISES THE ROW.
	 *
	 * heera.it, 2026-08-25: post 2621's featured image carried the file name in
	 * its alt field. check_featured_alt() flagged it — "described only by its
	 * file name … Replace its alt text" — and this tool refused it as already
	 * described, telling the agent the checks were satisfied. The worklist row
	 * could not be closed by the tool built to close it.
	 */
	public function test_a_file_name_alt_is_not_a_description_and_is_written_over() {
		$post_id = $this->post();
		set_post_thumbnail( $post_id, $this->image( 'WordPress-Hidden-Gems' ) );
		$thumb = get_post_thumbnail_id( $post_id );
		update_post_meta( $thumb, '_wp_attachment_image_alt', 'WordPress-Hidden-Gems' );

		// The check and the tool must be looking at the same thing: flagged here…
		$this->assertNotSame( 'pass', $this->alt_row( $post_id )['status'], 'the check must flag a file-name alt' );

		// …and writable there, with no replace=true needed to get past it.
		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $post_id,
				'alt'     => 'The white WordPress logo on a dark blue background.',
			)
		);

		$this->assertNotWPError( $out, 'a file name is not somebody’s writing' );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( 'WordPress-Hidden-Gems', $out['previous'], 'and it still reports what stood there' );
		$this->assertSame( 'pass', $this->alt_row( $post_id )['status'], 'the row the check raised must close' );
	}

	/**
	 * ⭐ And it says so honestly — "Description replaced" over a file name would
	 * report a correction to writing that never existed.
	 */
	public function test_overwriting_a_file_name_does_not_report_a_replacement() {
		$post_id = $this->post();
		set_post_thumbnail( $post_id, $this->image( 'DSC-0091' ) );
		update_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', 'DSC-0091' );

		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $post_id,
				'alt'     => 'A grey cat asleep on a windowsill.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertStringContainsString( 'file name', $out['message'] );
		$this->assertStringNotContainsString( 'replaced', $out['message'] );
	}

	/**
	 * ⛔ THE FALSE POSITIVE THE PREDICATE MUST NOT HAVE. A real sentence that
	 * happens to match the file name is a description, and stays protected —
	 * this is the guard, not a loophole in it.
	 */
	public function test_a_written_sentence_is_still_refused_even_when_it_echoes_the_file_name() {
		$post_id = $this->post();
		set_post_thumbnail( $post_id, $this->image( 'red-fox-in-snow' ) );
		update_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', 'Red fox in snow' );

		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $post_id,
				'alt'     => 'Something else entirely.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_already_described', $out->get_error_code() );
	}

	/**
	 * ⛔ NOT EVERY SITE IS A BLOG. A content type with no featured-image support
	 * is not "missing" one, and must not be told to go and set one.
	 */
	public function test_a_type_without_featured_images_is_told_that_and_not_to_set_one() {
		register_post_type( 'ar_gadget', array( 'public' => true, 'label' => 'Gadget', 'supports' => array( 'title', 'editor' ) ) );
		$gadget = (int) wp_insert_post( array( 'post_type' => 'ar_gadget', 'post_title' => 'A gadget', 'post_status' => 'publish' ) );

		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $gadget,
				'alt'     => 'Never written.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_no_featured_support', $out->get_error_code() );
		$this->assertStringContainsString( 'attachment_id', $out->get_error_message(), 'it must name the way that DOES work' );

		unregister_post_type( 'ar_gadget' );
	}

	/**
	 * ⭐ And a type that CAN hold one but does not still gets the old answer —
	 * the two absences are different, and the messages must be too.
	 */
	public function test_a_type_that_supports_featured_images_still_reports_a_missing_one() {
		$out = ( new MediaWriter() )->describe(
			array(
				'post_id' => $this->post(),
				'alt'     => 'Never written.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_no_featured_image', $out->get_error_code() );
	}

	/* -- describe-content-image ----------------------------------------- */

	/** A post whose body holds images, given as a list of <img> tags. */
	private function post_with_images( array $tags ) {
		$body = '';
		foreach ( $tags as $tag ) {
			$body .= "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $tag . "</figure>\n<!-- /wp:image -->\n\n";
		}
		return $this->post( $body . "<!-- wp:paragraph -->\n<p>" . str_repeat( 'Words about the pictures. ', 40 ) . "</p>\n<!-- /wp:paragraph -->" );
	}

	private function alt_text_row( $post_id ) {
		foreach ( PageCheck::analyze( get_post( $post_id ) ) as $row ) {
			if ( 'alt_text' === $row['id'] ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * ⭐⭐ THE WHOLE POINT, and the reason this is not describe-image: the alt a
	 * reader gets from a published page is the one in the BODY. The check said
	 * the page had an undescribed picture; after one call it does not.
	 */
	public function test_describing_a_content_image_clears_the_check_that_flagged_it() {
		$post_id = $this->post_with_images( array( '<img src="http://example.org/wp-content/uploads/2026/08/kiln.jpg" class="wp-image-77"/>' ) );
		$this->assertNotSame( 'pass', $this->alt_text_row( $post_id )['status'], 'the fixture must start flagged' );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'A potter lifting a fired bowl out of a kiln.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( '', $out['previous'] );
		$this->assertSame( 1, $out['index'] );
		$this->assertSame( 'pass', $this->alt_text_row( $post_id )['status'], 'the row the check raised must close' );
		$this->assertStringContainsString( 'alt="A potter lifting a fired bowl out of a kiln."', $this->body( $post_id ) );
	}

	/**
	 * ⛔ AND IT DISTURBS NOTHING ELSE. The body afterwards is what it was with
	 * one attribute added — block comments, the shortcode, the entity and the
	 * other image all byte-identical.
	 */
	public function test_the_rest_of_the_body_is_untouched() {
		$body = "<!-- wp:image -->\n<figure><img src=\"/one.jpg\"/></figure>\n<!-- /wp:image -->\n"
			. "<!-- wp:paragraph -->\n<p>Fish &amp; chips [gallery ids=\"4,5\"] " . str_repeat( 'and more words. ', 40 ) . "</p>\n<!-- /wp:paragraph -->\n"
			. "<!-- wp:image -->\n<figure><img src=\"/two.jpg\" alt=\"Two\"/></figure>\n<!-- /wp:image -->";
		$post_id = $this->post( $body );
		// ⚠️ WHAT WORDPRESS STORED, not what we asked it to store. Where the
		// connected user has no unfiltered_html — every administrator on
		// MULTISITE, which is why only that run caught this — KSES rewrites the
		// body on the way in, and `<img/>` comes back as `<img />`. Comparing
		// against the fixture string would be measuring core's normalisation
		// rather than this tool's edit.
		$before = $this->body( $post_id );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'index'   => 1,
				'alt'     => 'One.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertSame(
			str_replace( '<img src="/one.jpg"', '<img alt="One." src="/one.jpg"', $before ),
			$this->body( $post_id ),
			'exactly one attribute is different'
		);
	}

	/**
	 * ⛔ alt="" IS A DECISION — the standards marker for a decorative picture,
	 * which the checks deliberately do not flag. A fixing run announcing every
	 * spacer on a site would be damage, not repair.
	 */
	public function test_a_decorative_image_is_refused_without_replace() {
		$post_id = $this->post_with_images( array( '<img src="/divider.png" alt=""/>' ) );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'A decorative line.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_decorative_image', $out->get_error_code() );
		$this->assertStringContainsString( 'alt=""', $this->body( $post_id ), 'and nothing was written' );
	}

	/** ⛔ Neither is a description somebody wrote. */
	public function test_a_described_image_is_refused_without_replace() {
		$post_id = $this->post_with_images( array( '<img src="/kiln.jpg" alt="A potter at work."/>' ) );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'Something else entirely.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_already_described', $out->get_error_code() );
		$this->assertStringContainsString( 'A potter at work.', $out->get_error_message(), 'the refusal quotes what would be lost' );
	}

	/** ⭐ A file name is not a decision, and needs no permission to replace. */
	public function test_a_file_name_alt_in_the_body_is_written_over_freely() {
		$post_id = $this->post_with_images( array( '<img src="/uploads/screen-shot-2016-09-15.png" alt="screen-shot-2016-09-15"/>' ) );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'A settings screen with two toggles switched on.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( 'screen-shot-2016-09-15', $out['previous'] );
	}

	/** ⛔ Two images and no aim is a guess, so it refuses — and says what is there. */
	public function test_it_refuses_to_choose_between_two_images() {
		$post_id = $this->post_with_images( array( '<img src="/one.jpg"/>', '<img src="/two.jpg"/>' ) );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'Never written.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_which_image', $out->get_error_code() );
		$this->assertStringContainsString( 'index 1: one.jpg', $out->get_error_message() );
		$this->assertStringContainsString( 'index 2: two.jpg', $out->get_error_message() );
	}

	/** ⭐ …but one image is not a choice, and demanding one would be friction. */
	public function test_one_image_needs_no_aim() {
		$post_id = $this->post_with_images( array( '<img src="/only.jpg"/>' ) );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'The only picture.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['changed'] );
	}

	/** Aiming by the library id, including when the tag carries no wp-image class. */
	public function test_it_can_be_aimed_by_attachment_id_through_the_file_name() {
		$attachment = $this->image( 'harbour' );
		$url        = wp_get_attachment_url( $attachment );
		$post_id    = $this->post_with_images( array( '<img src="/x.jpg"/>', '<img src="' . esc_url( $url ) . '"/>' ) );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment,
				'alt'           => 'Fishing boats tied up at a harbour wall.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertSame( 2, $out['index'], 'the second image, found by its file' );
	}

	/**
	 * ⭐ THE LIBRARY COPY IS REPORTED, NEVER QUIETLY WRITTEN. Filling it here
	 * would change what featured_alt says on every other page using the picture
	 * without marking one of them for re-reading.
	 */
	public function test_it_reports_that_the_library_copy_still_needs_describing() {
		$attachment = $this->image( 'harbour' );
		$post_id    = $this->post_with_images( array( '<img src="' . esc_url( wp_get_attachment_url( $attachment ) ) . '" class="wp-image-' . $attachment . '"/>' ) );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'Fishing boats at a harbour wall.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['libraryNeedsDescribing'] );
		$this->assertSame( '', $out['libraryAlt'] );
		$this->assertSame(
			'',
			(string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ),
			'and the library was NOT written'
		);
	}

	/** ⛔ A body with no images cannot be the place to fix one. */
	public function test_a_body_with_no_images_is_refused_with_the_reason() {
		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $this->post(),
				'alt'     => 'Never written.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_no_images', $out->get_error_code() );
	}

	/** ⚠️ The autosave trap, on this tool too — the guard is shared, so it must hold. */
	public function test_it_refuses_while_somebody_has_the_post_open() {
		$post_id = $this->post_with_images( array( '<img src="/one.jpg"/>' ) );
		$other   = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_post_meta( $post_id, '_edit_lock', ( time() ) . ':' . $other );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'Never written.',
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_post_locked', $out->get_error_code() );
	}

	/** dry_run answers the same question and writes nothing. */
	public function test_dry_run_shows_the_change_without_making_it() {
		$post_id = $this->post_with_images( array( '<img src="/one.jpg"/>' ) );
		$before  = $this->body( $post_id );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'A picture.',
				'dry_run' => true,
			)
		);

		$this->assertNotWPError( $out );
		$this->assertFalse( $out['changed'] );
		$this->assertTrue( $out['dryRun'] );
		$this->assertStringContainsString( 'alt="A picture."', $out['context'] );
		$this->assertSame( $before, $this->body( $post_id ), 'nothing was written' );
	}

	/** ⭐ read-content NAMES the images, which is how an alt_text row is aimed at all. */
	public function test_read_content_lists_the_images_and_what_they_need() {
		$post_id = $this->post_with_images(
			array(
				'<img src="/uploads/no-alt.jpg"/>',
				'<img src="/uploads/spacer.png" alt=""/>',
				'<img src="/uploads/dsc-0091.jpg" alt="dsc-0091"/>',
				'<img src="/uploads/good.jpg" alt="A described picture."/>',
			)
		);

		$read = ( new ContentEditor() )->read( $post_id );

		$this->assertCount( 4, $read['images'] );
		$this->assertSame( 'no-alt', $read['images'][0]['needs'] );
		$this->assertSame( '', $read['images'][1]['needs'], 'decorative is a decision, not a gap' );
		$this->assertTrue( $read['images'][1]['decorative'] );
		$this->assertSame( 'file-name', $read['images'][2]['needs'] );
		$this->assertSame( '', $read['images'][3]['needs'] );
	}

	/**
	 * ⛔⛔ alt="" + A CAPTION IS A CONTRADICTION THE AUTHOR WROTE THEMSELVES.
	 *
	 * heera.it, 2026-08-25: post 4298 carried real alt on three screenshots of
	 * four and a blank on the fourth; post 4123 had blanks on two screenshots
	 * that both carried captions. The check read every blank as "decorative"
	 * and said "Every image has alt text" over the top of them.
	 */
	public function test_a_captioned_image_marked_decorative_is_flagged() {
		$post_id = $this->post(
			"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"/uploads/edge.png\" alt=\"\"/>"
			. "<figcaption class=\"wp-element-caption\">Seven days at the edge.</figcaption></figure>\n<!-- /wp:image -->\n"
			. "<!-- wp:paragraph -->\n<p>" . str_repeat( 'Words about the picture. ', 40 ) . "</p>\n<!-- /wp:paragraph -->"
		);

		$row = $this->alt_text_row( $post_id );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'decorative', $row['detail'] );
		$this->assertStringContainsString( 'caption', $row['detail'] );
		$this->assertStringContainsString( 'edge.png', $row['detail'], 'and it names WHICH picture' );
	}

	/** ⛔ …while an UNcaptioned blank stays a decision, and stays unflagged. */
	public function test_an_uncaptioned_decorative_image_is_still_not_flagged() {
		$post_id = $this->post(
			"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"/uploads/divider.png\" alt=\"\"/></figure>\n<!-- /wp:image -->\n"
			. "<!-- wp:paragraph -->\n<p>" . str_repeat( 'Words about nothing much. ', 40 ) . "</p>\n<!-- /wp:paragraph -->"
		);

		$this->assertSame( 'pass', $this->alt_text_row( $post_id )['status'] );
	}

	/**
	 * ⭐ THE INVARIANT AGAIN: the guard must agree with the check. A captioned
	 * blank is flagged, so the tool writes it WITHOUT replace — the same law
	 * that the file-name alt bought earlier today.
	 */
	public function test_the_writer_fixes_a_captioned_blank_without_replace() {
		$post_id = $this->post(
			"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"/uploads/edge.png\" alt=\"\"/>"
			. "<figcaption class=\"wp-element-caption\">Seven days at the edge.</figcaption></figure>\n<!-- /wp:image -->\n"
			. "<!-- wp:paragraph -->\n<p>" . str_repeat( 'Words about the picture. ', 40 ) . "</p>\n<!-- /wp:paragraph -->"
		);

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'A seven-day chart of requests Cloudflare answered, served and blocked.',
			)
		);

		$this->assertNotWPError( $out, 'the check flags it, so the tool must be able to close it' );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( 'pass', $this->alt_text_row( $post_id )['status'], 'the row must actually clear' );
	}

	/** ⭐ read-content reports the pair the judgement rests on. */
	public function test_read_content_reports_decorative_and_captioned_separately() {
		$post_id = $this->post(
			"<!-- wp:image -->\n<figure><img src=\"/uploads/edge.png\" alt=\"\"/><figcaption>A caption.</figcaption></figure>\n<!-- /wp:image -->\n"
			. "<!-- wp:image -->\n<figure><img src=\"/uploads/divider.png\" alt=\"\"/></figure>\n<!-- /wp:image -->"
		);

		$images = ( new ContentEditor() )->read( $post_id )['images'];

		$this->assertTrue( $images[0]['decorative'] );
		$this->assertTrue( $images[0]['captioned'] );
		$this->assertSame( 'blank-alt', $images[0]['needs'] );

		$this->assertTrue( $images[1]['decorative'] );
		$this->assertFalse( $images[1]['captioned'] );
		$this->assertSame( '', $images[1]['needs'], 'no caption, so still a decision' );
	}

	/**
	 * ⛔⛔ NOT EVERY SITE IS WRITTEN IN THE BLOCK EDITOR.
	 *
	 * A classic-editor caption is a `[caption]` shortcode. The checks read the
	 * RENDERED body, where `the_content` has already expanded it into a figure,
	 * so they flag the image — and the writer reads the STORED body, where it is
	 * still a shortcode. This test is the two sides agreeing, measured through
	 * PageCheck on one and the tool on the other.
	 */
	public function test_a_classic_captioned_image_is_flagged_and_fixable() {
		$post_id = $this->post(
			'[caption id="attachment_9" align="alignnone" width="600"]<img src="/uploads/kiln.jpg" alt="" class="wp-image-9" /> A potter at the wheel.[/caption]'
			. "\n\n" . str_repeat( 'Words about the picture. ', 40 )
		);

		// The CHECK, on the rendered body.
		$this->assertSame( 'warn', $this->alt_text_row( $post_id )['status'], 'the rendered shortcode is a captioned figure' );

		// The TOOL, on the stored body — and with no replace, because the check flags it.
		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $post_id,
				'alt'     => 'A potter shaping a bowl at the wheel.',
			)
		);

		$this->assertNotWPError( $out, 'the guard must agree with the check on a classic body too' );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( 'pass', $this->alt_text_row( $post_id )['status'] );
		$this->assertStringContainsString( '[caption', $this->body( $post_id ), 'and the shortcode survives the edit' );
	}

	/**
	 * ⛔ NOR IS EVERY SITE A BLOG. A custom type the owner has made
	 * agent-visible gets the same reading and the same fix as a post.
	 */
	public function test_a_custom_post_type_gets_the_same_reading_and_the_same_fix() {
		register_post_type( 'ar_recipe', array( 'public' => true, 'label' => 'Recipe', 'supports' => array( 'title', 'editor', 'thumbnail' ) ) );
		add_filter( 'agentimus_post_types', $keep = static function ( $types ) {
			$types[] = 'ar_recipe';
			return array_values( array_unique( $types ) );
		} );

		$recipe = (int) wp_insert_post(
			array(
				'post_type'    => 'ar_recipe',
				'post_title'   => 'A loaf worth the wait',
				'post_status'  => 'publish',
				'post_author'  => $this->admin,
				'post_content' => "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"/uploads/loaf.jpg\" alt=\"\"/>"
					. "<figcaption class=\"wp-element-caption\">Out of the oven.</figcaption></figure>\n<!-- /wp:image -->\n"
					. "<!-- wp:paragraph -->\n<p>" . str_repeat( 'Words about the loaf. ', 40 ) . "</p>\n<!-- /wp:paragraph -->",
			)
		);

		$read = ( new ContentEditor() )->read( $recipe );
		$this->assertFalse( is_wp_error( $read ), 'a custom type is readable' );
		$this->assertSame( 'blank-alt', $read['images'][0]['needs'] );

		$out = ( new ContentEditor() )->describe_in_content(
			array(
				'post_id' => $recipe,
				'alt'     => 'A dark-crusted loaf cooling on a wire rack.',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertTrue( $out['changed'] );

		remove_filter( 'agentimus_post_types', $keep );
		unregister_post_type( 'ar_recipe' );
	}

	/* -- search-media: the undescribed filter ---------------------------- */

	/**
	 * ⭐⭐ THE MEDIA HALF. describe-image could always write a library item's
	 * alt; nothing could say WHICH items needed one, because the images that
	 * need describing are exactly the ones no search matches.
	 */
	public function test_the_library_filter_finds_images_with_no_description() {
		$bare     = $this->image( 'bare-one' );
		$described = $this->image( 'described-one' );
		update_post_meta( $described, '_wp_attachment_image_alt', 'A described picture.' );

		$found = \Agentimus\Media::undescribed( 20, 'image' );
		$ids   = wp_list_pluck( $found['items'], 'id' );

		$this->assertContains( $bare, $ids );
		$this->assertNotContains( $described, $ids );
	}

	/** ⭐ And a file-name alt counts as undescribed, exactly as the checks count it. */
	public function test_the_library_filter_also_finds_file_name_alts() {
		$slugged = $this->image( 'screen-shot-2016-09-15' );
		update_post_meta( $slugged, '_wp_attachment_image_alt', 'screen-shot-2016-09-15' );

		$found = \Agentimus\Media::undescribed( 20, 'image' );

		$this->assertContains( $slugged, wp_list_pluck( $found['items'], 'id' ) );
		$this->assertGreaterThan( 0, $found['scanned'], 'the file-name pass reports how much it read' );
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
	public function test_the_maintenance_tools_are_advertised_to_agents() {
		$settings = new \Agentimus\Settings();
		$all      = $settings->all(); // Partial updates reset unset settings — merge into all().

		$all['enable_mcp_server']   = true;
		$all['enable_agent_writes'] = false;
		$settings->update( $all );
		$read_only = ( new \Agentimus\Abilities\Registrar( new \Agentimus\Settings() ) )->mcp_abilities();

		$this->assertContains( 'agentimus/read-content', $read_only, 'the body an edit is written against is a READ' );
		$this->assertNotContains( 'agentimus/edit-content', $read_only );
		$this->assertNotContains( 'agentimus/describe-image', $read_only );
		$this->assertNotContains( 'agentimus/describe-content-image', $read_only );

		$all['enable_agent_writes'] = true;
		$settings->update( $all );
		$with_writes = ( new \Agentimus\Abilities\Registrar( new \Agentimus\Settings() ) )->mcp_abilities();

		$this->assertContains( 'agentimus/edit-content', $with_writes );
		$this->assertContains( 'agentimus/describe-image', $with_writes );
		$this->assertContains( 'agentimus/describe-content-image', $with_writes );
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
