<?php
/**
 * Stage 2, step 3: an agent can add an image from a URL and edit an item's words.
 *
 * URL only: no bytes come through this server. The file itself decides what it is, so
 * an SVG named .jpg is refused, because an SVG can carry a script. Size is capped while
 * downloading, so a huge file can't fill the disk first.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\MediaLibrary;
use Agentimus\Abilities\Registrar;
use Agentimus\AgentAccess\Store;
use Agentimus\AgentAccess\Table;
use Agentimus\Media;
use Agentimus\Settings;

final class MediaLibraryDbTest extends DbTestCase {

	const URL = 'https://images.example.org/photos/river.png';

	/** A real 1x1 PNG. */
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	/** @var Settings */
	private $settings;

	/** @var string What the fake remote server answers with. */
	private $body;

	/** @var int Its status. */
	private $code = 200;

	/** @var array[] The arguments of each fetch. */
	private $fetches = array();

	/** @var callable */
	private $http;

	/** @var int[] */
	private $made = array();

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
		$this->body = base64_decode( self::PNG ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		$this->http = function ( $pre, $args, $url ) {
			$this->fetches[] = array( 'url' => $url, 'args' => $args );
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $this->body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			return array(
				'response' => array( 'code' => $this->code, 'message' => '' ),
				'headers'  => array(),
				'cookies'  => array(),
				'body'     => '',
				'filename' => isset( $args['filename'] ) ? $args['filename'] : null,
			);
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->http, 10 );
		foreach ( $this->made as $id ) {
			wp_delete_attachment( $id, true );
		}
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

	private function tool() {
		return new MediaLibrary( $this->settings );
	}

	private function import( array $input = array() ) {
		$out = $this->tool()->import( array_merge( array( 'url' => self::URL ), $input ) );
		if ( is_array( $out ) ) {
			$this->made[] = $out['id'];
		}
		return $out;
	}

	private function attachments() {
		return count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	}

	private function assert_refused( $code, $result ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/* -- switch and permission ------------------------------------------- */

	public function test_the_tools_need_the_switch_and_are_write_tools() {
		foreach ( array( 'import-media', 'update-media' ) as $slug ) {
			$this->switch_on( false );
			$this->assertNotContains( "agentimus/$slug", ( new Registrar( $this->settings ) )->mcp_abilities() );
			$this->switch_on( true );
			$this->assertContains( "agentimus/$slug", ( new Registrar( $this->settings ) )->mcp_abilities() );
			$this->assertTrue( Registrar::is_write_tool_name( "agentimus/$slug" ) );
		}
	}

	public function test_both_are_refused_with_the_switch_off_and_nothing_is_fetched() {
		$id = $this->import();
		$id = $id['id'];
		$this->fetches = array();
		$this->switch_on( false );
		$this->assert_refused( 'agentimus_content_off', $this->tool()->import( array( 'url' => self::URL ) ) );
		$this->assert_refused( 'agentimus_content_off', $this->tool()->update( array( 'attachment_id' => $id, 'title' => 'New' ) ) );
		$this->assertSame( array(), $this->fetches );
	}

	public function test_a_user_who_may_not_upload_is_refused_before_any_fetch() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assert_refused( 'agentimus_cannot_upload', $this->tool()->import( array( 'url' => self::URL ) ) );
		$this->assertSame( array(), $this->fetches );
		$this->assertFalse( ( new Registrar( $this->settings ) )->can_import_media( array() ) );
	}

	public function test_an_author_can_edit_their_own_item_and_nobody_elses() {
		$owner = get_current_user_id();
		$item  = $this->import();
		$this->assertSame( $owner, (int) get_post( $item['id'] )->post_author );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assert_refused( 'agentimus_cannot_edit_media', $this->tool()->update( array( 'attachment_id' => $item['id'], 'title' => 'Mine now' ) ) );
		$this->assertFalse( ( new Registrar( $this->settings ) )->can_update_media( array( 'attachment_id' => $item['id'] ) ) );
		$this->assertNotSame( 'Mine now', get_the_title( $item['id'] ) );

		$own = $this->import();
		$this->assertTrue( ( new Registrar( $this->settings ) )->can_update_media( array( 'attachment_id' => $own['id'] ) ) );
		$this->assertSame( 'Mine now', $this->tool()->update( array( 'attachment_id' => $own['id'], 'title' => 'Mine now' ) )['title'] );
	}

	/* -- import ---------------------------------------------------------- */

	public function test_it_imports_with_no_post_and_all_its_words() {
		$out = $this->import(
			array(
				'title'       => 'The River',
				'caption'     => 'At <em>dawn</em>.',
				'description' => 'Long words.',
				'alt'         => 'A river at sunrise',
			)
		);
		$this->assertIsArray( $out );
		$post = get_post( $out['id'] );
		$this->assertSame( 'attachment', $post->post_type );
		$this->assertSame( 0, (int) $post->post_parent, 'It belongs to no post.' );
		$this->assertSame( 'The River', $post->post_title );
		$this->assertSame( 'At <em>dawn</em>.', $post->post_excerpt );
		$this->assertSame( 'Long words.', $post->post_content );
		$this->assertSame( 'A river at sunrise', get_post_meta( $out['id'], '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'image/png', $out['mime'] );
		$this->assertSame( wp_get_attachment_url( $out['id'] ), $out['url'] );
		$this->assertSame( 1, $out['width'] );
		$this->assertStringContainsString( 'river', basename( $out['url'] ) );
	}

	public function test_a_query_string_does_not_end_up_in_the_file_name() {
		$out = $this->import( array( 'url' => self::URL . '?w=1200&token=abc' ) );
		$this->assertStringNotContainsString( 'token', $out['url'] );
		$this->assertStringEndsWith( '.png', $out['url'] );
	}

	public function test_it_fetches_through_the_guarded_http_path_with_a_size_cap() {
		$this->import();
		$args = $this->fetches[0]['args'];
		$this->assertTrue( $args['reject_unsafe_urls'], 'Internal addresses must be refused.' );
		$this->assertSame( wp_max_upload_size() + 1, $args['limit_response_size'], 'A huge file stops downloading at the cap.' );
	}

	public function test_a_file_over_the_sites_limit_is_refused_and_leaves_nothing() {
		$limit = static function () {
			return 10;
		};
		add_filter( 'upload_size_limit', $limit );
		$before = $this->attachments();
		$out    = $this->tool()->import( array( 'url' => self::URL ) );
		remove_filter( 'upload_size_limit', $limit );
		$this->assert_refused( 'agentimus_media_too_large', $out );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_only_http_addresses_are_fetched() {
		foreach ( array( '', 'file:///etc/passwd', 'ftp://example.org/a.png', '/wp-content/a.png', 'javascript:alert(1)//a.png' ) as $bad ) {
			$this->assert_refused( 'agentimus_bad_media_url', $this->tool()->import( array( 'url' => $bad ) ) );
		}
		$this->assertSame( array(), $this->fetches );
	}

	public function test_a_type_outside_the_four_is_refused_before_any_fetch() {
		foreach ( array( 'a.svg', 'a.svgz', 'a.php', 'a.pdf', 'a.html', 'a.jpg.php', 'noextension' ) as $file ) {
			$this->assert_refused( 'agentimus_bad_media_type', $this->tool()->import( array( 'url' => 'https://example.org/' . $file ) ) );
		}
		$this->assertSame( array(), $this->fetches );
	}

	public function test_each_allowed_extension_passes_the_address_check() {
		foreach ( array( 'a.jpg', 'a.JPEG', 'a.jpe', 'a.png', 'a.gif', 'a.webp' ) as $file ) {
			$this->assertTrue( MediaLibrary::extension_allowed( 'https://example.org/' . $file ), $file );
		}
	}

	public function test_a_filter_widening_core_cannot_let_an_svg_through() {
		$wider = static function ( $list ) {
			$list[] = 'svg';
			return $list;
		};
		add_filter( 'image_sideload_extensions', $wider );
		$this->assert_refused( 'agentimus_bad_media_type', $this->tool()->import( array( 'url' => 'https://example.org/a.svg' ) ) );
		remove_filter( 'image_sideload_extensions', $wider );
	}

	public function test_the_file_itself_decides_so_an_svg_named_jpg_is_refused() {
		$before     = $this->attachments();
		$this->body = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
		$this->assert_refused( 'agentimus_bad_media_type', $this->tool()->import( array( 'url' => 'https://example.org/a.jpg' ) ) );
		$this->body = '<?php echo 1;';
		$this->assert_refused( 'agentimus_bad_media_type', $this->tool()->import( array( 'url' => 'https://example.org/b.png' ) ) );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_a_failed_download_is_refused_and_leaves_nothing() {
		$before     = $this->attachments();
		$this->code = 404;
		$this->assert_refused( 'agentimus_media_fetch_failed', $this->tool()->import( array( 'url' => self::URL ) ) );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_scripts_are_refused_before_anything_is_fetched() {
		foreach ( array( 'title', 'caption', 'description' ) as $field ) {
			$this->assert_refused( 'agentimus_unsafe_markup', $this->tool()->import( array( 'url' => self::URL, $field => '<script>alert(1)</script>' ) ) );
		}
		$this->assertSame( array(), $this->fetches );
	}

	public function test_a_long_alt_is_refused_before_anything_is_fetched() {
		$this->assert_refused( 'agentimus_alt_too_long', $this->tool()->import( array( 'url' => self::URL, 'alt' => str_repeat( 'a', 251 ) ) ) );
		$this->assertSame( array(), $this->fetches );
	}

	public function test_backslashes_survive() {
		$out = $this->import( array( 'caption' => 'Path C:\\temp', 'title' => 'Regex \\d+' ) );
		$this->assertSame( 'Path C:\\temp', get_post( $out['id'] )->post_excerpt );
		$this->assertSame( 'Regex \\d+', get_post( $out['id'] )->post_title );
	}

	/* -- update ---------------------------------------------------------- */

	public function test_only_the_fields_sent_change() {
		$id = $this->import( array( 'title' => 'Title', 'caption' => 'Caption', 'description' => 'Words', 'alt' => 'Alt' ) )['id'];
		$out = $this->tool()->update( array( 'attachment_id' => $id, 'caption' => 'New caption' ) );
		$this->assertSame( 'New caption', $out['caption'] );
		$this->assertSame( 'Title', $out['title'] );
		$this->assertSame( 'Words', $out['description'] );
		$this->assertSame( 'Alt', $out['alt'], 'Alt text belongs to describe-image.' );
		$this->assertSame( 0, (int) get_post( $id )->post_parent );
	}

	public function test_a_caption_and_description_can_be_cleared_but_a_title_cannot() {
		$id = $this->import( array( 'title' => 'Title', 'caption' => 'Caption', 'description' => 'Words' ) )['id'];
		$out = $this->tool()->update( array( 'attachment_id' => $id, 'caption' => '', 'description' => '' ) );
		$this->assertSame( '', $out['caption'] );
		$this->assertSame( '', $out['description'] );
		$this->assert_refused( 'agentimus_no_media_title', $this->tool()->update( array( 'attachment_id' => $id, 'title' => '  ' ) ) );
		$this->assertSame( 'Title', get_the_title( $id ) );
	}

	public function test_it_edits_any_library_item_but_only_a_library_item() {
		$pdf = self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'A PDF' ) );
		$this->assertSame( 'Renamed PDF', $this->tool()->update( array( 'attachment_id' => $pdf, 'title' => 'Renamed PDF' ) )['title'] );

		$post = self::factory()->post->create( array( 'post_title' => 'A Post' ) );
		$this->assert_refused( 'agentimus_not_found', $this->tool()->update( array( 'attachment_id' => $post, 'title' => 'Hijacked' ) ) );
		$this->assertSame( 'A Post', get_the_title( $post ) );
		$this->assert_refused( 'agentimus_not_found', $this->tool()->update( array( 'attachment_id' => 999999, 'title' => 'Ghost' ) ) );
		$this->assertFalse( ( new Registrar( $this->settings ) )->can_update_media( array( 'attachment_id' => $post ) ) );
	}

	public function test_an_empty_call_is_refused() {
		$id = $this->import()['id'];
		$this->assert_refused( 'agentimus_nothing_to_change', $this->tool()->update( array( 'attachment_id' => $id ) ) );
	}

	public function test_an_edit_cannot_add_a_script_but_keeps_what_the_owner_stored() {
		$id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png', 'post_title' => 'Item', 'post_content' => '' ) );
		foreach ( array( 'caption', 'description', 'title' ) as $field ) {
			$this->assert_refused( 'agentimus_unsafe_markup', $this->tool()->update( array( 'attachment_id' => $id, $field => '<img src="x" onerror="alert(1)">' ) ) );
		}
		$this->assertSame( 'Item', get_the_title( $id ) );

		if ( method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			$embed = '<iframe src="https://example.org/a"></iframe>';
			// Stored directly: a multisite admin has no unfiltered_html, so core would strip it.
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => $embed ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
			clean_post_cache( $id );
			$this->assertStringContainsString( '<iframe', get_post( $id )->post_content, 'The fixture must really hold it.' );
			$this->assertIsArray( $this->tool()->update( array( 'attachment_id' => $id, 'description' => $embed . ' More.' ) ) );
		}
	}

	public function test_a_write_that_wordpress_changes_is_named() {
		$rewrite = static function ( $data ) {
			$data['post_excerpt'] = 'Rewritten';
			return $data;
		};
		$id = $this->import()['id'];
		add_filter( 'wp_insert_attachment_data', $rewrite );
		$out = $this->tool()->update( array( 'attachment_id' => $id, 'caption' => 'As sent' ) );
		remove_filter( 'wp_insert_attachment_data', $rewrite );
		$this->assertSame( 'Rewritten', $out['caption'] );
		$this->assertNotSame( '', $out['note'] );

		$this->assertSame( '', $this->tool()->update( array( 'attachment_id' => $id, 'caption' => 'As sent' ) )['note'] );
	}

	/* -- limit, record, read --------------------------------------------- */

	public function test_the_hourly_limit_stops_a_burst_before_it_fetches() {
		for ( $i = 0; $i < MediaLibrary::HOURLY_LIMIT; $i++ ) {
			$this->assertIsArray( $this->import() );
		}
		$this->fetches = array();
		$this->assert_refused( 'agentimus_media_rate_limited', $this->tool()->import( array( 'url' => self::URL ) ) );
		$this->assertSame( array(), $this->fetches );
	}

	public function test_a_refused_call_does_not_spend_the_limit() {
		for ( $i = 0; $i < MediaLibrary::HOURLY_LIMIT + 1; $i++ ) {
			$this->assert_refused( 'agentimus_bad_media_type', $this->tool()->import( array( 'url' => 'https://example.org/a.svg' ) ) );
		}
		$this->assertIsArray( $this->import() );
	}

	public function test_the_owner_can_see_each_change_in_agent_access() {
		$id = $this->import()['id'];
		$this->tool()->update( array( 'attachment_id' => $id, 'title' => 'Logged', 'caption' => 'Too' ) );

		$subjects = array();
		foreach ( Store::recent( 50, 'setting_changed' ) as $row ) {
			$subjects[] = ( (array) $row )['subject'];
		}
		$this->assertContains( "media $id imported", $subjects );
		$this->assertContains( "media $id changed: title, caption", $subjects );
	}

	public function test_search_media_shows_the_words_an_edit_starts_from() {
		$id  = $this->import( array( 'caption' => 'Existing caption', 'description' => 'Existing words' ) )['id'];
		$row = Media::row( $id );
		$this->assertSame( 'Existing caption', $row['caption'] );
		$this->assertSame( 'Existing words', $row['description'] );
	}
}
