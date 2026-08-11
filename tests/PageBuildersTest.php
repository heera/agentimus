<?php
/**
 * Page-builder ownership: the detector, the write guard, and the read-side provider.
 *
 * Locks the three promises the builder work makes:
 *   1. A body replacement on a page a builder owns is REFUSED with the builder's
 *      name and the honest reason — never a success response for an edit no
 *      visitor would see (or, on a content-storage builder, a destroyed design).
 *      Every non-body field stays writable on the same page.
 *   2. The read seam (`agentimus_markdown_source`) receives the builder's real
 *      rendered body — but an explicit third-party override always wins, and an
 *      unowned post stays on the normal content path.
 *   3. The description fallback reads the builder render (cached by layout
 *      fingerprint, one render per builder save), not the stale post_content.
 *
 * The real builder table needs Elementor/Beaver/Divi loaded, so these tests
 * teach the table a fake builder through the `agentimus_page_builders` filter —
 * which doubles as the lock on that filter being a real extension point.
 *
 * @package Agentimus\Tests
 */

namespace {
	// ContentWriter::update()'s write path — not in the shared bootstrap; guarded
	// so a future stub there wins. Identity/echo behavior is all the guard tests need.
	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'wp_update_post' ) ) {
		function wp_update_post( $postarr, $wp_error = false ) {
			$GLOBALS['_af_updated_posts'][] = $postarr;
			$id = (int) ( is_array( $postarr ) ? ( $postarr['ID'] ?? 0 ) : 0 );
			if ( isset( $GLOBALS['_af_posts'][ $id ] ) && is_array( $postarr ) ) {
				foreach ( $postarr as $k => $v ) {
					if ( 'ID' !== $k ) {
						$GLOBALS['_af_posts'][ $id ]->$k = $v;
					}
				}
			}
			return $id;
		}
	}
	if ( ! function_exists( 'get_edit_post_link' ) ) {
		function get_edit_post_link( $id = 0, $context = 'display' ) {
			return 'http://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
		}
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $cap, ...$args ) {
			return true;
		}
	}
	if ( ! function_exists( 'get_post_type_object' ) ) {
		function get_post_type_object( $type ) {
			return (object) array(
				'cap'    => (object) array(
					'edit_posts'    => 'edit_posts',
					'publish_posts' => 'publish_posts',
				),
				'labels' => (object) array(
					'name'          => ucfirst( $type ) . 's',
					'singular_name' => ucfirst( $type ),
				),
			);
		}
	}
}

namespace Agentimus\Tests {

	use Agentimus\Abilities\ContentWriter;
	use Agentimus\Description;
	use Agentimus\PageBuilders;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	final class PageBuildersTest extends TestCase {

		protected function setUp(): void {
			\_af_reset_options();
			$this->reset_state();
		}

		protected function tearDown(): void {
			$this->reset_state();
		}

		private function reset_state() {
			PageBuilders::flush_runtime_cache();
			$GLOBALS['_af_posts']    = array();
			$GLOBALS['_af_postmeta'] = array();
			unset(
				$GLOBALS['_af_filters']['agentimus_page_builders'],
				$GLOBALS['_af_filters']['agentimus_markdown_source'],
				$GLOBALS['_af_fake_active'],
				$GLOBALS['_af_fake_render'],
				$GLOBALS['_af_fake_hash'],
				$GLOBALS['_af_fake_render_calls'],
				$GLOBALS['_af_updated_posts']
			);
		}

		/**
		 * Teach the table a fake builder (marker meta `_fake_owned`), overridable
		 * per-test through globals: _af_fake_active, _af_fake_render, _af_fake_hash.
		 *
		 * @param string $storage 'meta' or 'content'.
		 */
		private function teach_fake_builder( $storage = 'meta' ) {
			add_filter(
				'agentimus_page_builders',
				static function ( $builders ) use ( $storage ) {
					$builders['fakebuilder'] = array(
						'name'    => 'FakeBuilder',
						'storage' => $storage,
						'active'  => static function () {
							return $GLOBALS['_af_fake_active'] ?? true;
						},
						'owns'    => static function ( \WP_Post $post ) {
							return (bool) get_post_meta( $post->ID, '_fake_owned', true );
						},
						'render'  => static function ( \WP_Post $post ) {
							$GLOBALS['_af_fake_render_calls'] = ( $GLOBALS['_af_fake_render_calls'] ?? 0 ) + 1;
							return $GLOBALS['_af_fake_render'] ?? "<h2>Real</h2>\n<p>builder   body text</p>";
						},
						'hash'    => static function ( \WP_Post $post ) {
							return (string) ( $GLOBALS['_af_fake_hash'] ?? 'h1' );
						},
					);
					return $builders;
				}
			);
		}

		/** A published WP_Post fixture the instanceof checks accept. */
		private function fixture( $id, array $post = array(), array $meta = array() ) {
			$GLOBALS['_af_posts'][ $id ] = new \WP_Post(
				array_merge(
					array(
						'ID'           => $id,
						'post_type'    => 'post',
						'post_status'  => 'publish',
						'post_title'   => 'A Post',
						'post_content' => '<p>Stale body no visitor sees.</p>',
						'post_excerpt' => '',
					),
					$post
				)
			);
			$GLOBALS['_af_postmeta'][ $id ] = $meta;
			return $GLOBALS['_af_posts'][ $id ];
		}

		/* ---- detection ------------------------------------------------------ */

		public function test_plain_post_has_no_owner() {
			$post = $this->fixture( 10 );
			$this->assertNull( PageBuilders::owner( $post ) );
		}

		public function test_marked_post_is_owned_by_the_taught_builder() {
			$this->teach_fake_builder();
			$post  = $this->fixture( 11, array(), array( '_fake_owned' => '1' ) );
			$owner = PageBuilders::owner( $post );

			$this->assertSame( 'fakebuilder', $owner['id'] );
			$this->assertSame( 'FakeBuilder', $owner['name'] );
			$this->assertSame( 'meta', $owner['storage'] );
		}

		public function test_marker_meta_alone_is_not_ownership_when_the_builder_is_inactive() {
			// Builder deactivated ⇒ WordPress renders post_content again; the
			// leftover meta is history, and the body belongs to this plugin's writes.
			$this->teach_fake_builder();
			$GLOBALS['_af_fake_active'] = false;
			$post                       = $this->fixture( 12, array(), array( '_fake_owned' => '1' ) );

			$this->assertNull( PageBuilders::owner( $post ) );
		}

		public function test_active_builder_without_marker_does_not_own() {
			$this->teach_fake_builder();
			$post = $this->fixture( 13 );
			$this->assertNull( PageBuilders::owner( $post ) );
		}

		/* ---- the read-side provider ----------------------------------------- */

		public function test_registers_on_the_markdown_source_seam() {
			( new PageBuilders() )->register();
			$this->assertNotEmpty( $GLOBALS['_af_filters']['agentimus_markdown_source'] );
		}

		public function test_provider_supplies_the_builder_render() {
			$this->teach_fake_builder();
			$post = $this->fixture( 20, array(), array( '_fake_owned' => '1' ) );

			$this->assertSame(
				"<h2>Real</h2>\n<p>builder   body text</p>",
				PageBuilders::filter_markdown_source( null, $post )
			);
		}

		public function test_an_earlier_override_always_wins() {
			$this->teach_fake_builder();
			$post = $this->fixture( 21, array(), array( '_fake_owned' => '1' ) );

			$this->assertSame(
				'<p>third-party render</p>',
				PageBuilders::filter_markdown_source( '<p>third-party render</p>', $post ),
				'An explicit agentimus_markdown_source provider is final; the built-in one only speaks when nobody else has.'
			);
		}

		public function test_provider_stays_silent_for_unowned_posts() {
			$this->teach_fake_builder();
			$post = $this->fixture( 22 );
			$this->assertNull( PageBuilders::filter_markdown_source( null, $post ) );
		}

		public function test_empty_render_falls_back_to_the_normal_path() {
			// A render door that answers nothing must not blank the body — null
			// lets markdown_source() fall through to post_content, where a stale
			// body still beats no body.
			$this->teach_fake_builder();
			$GLOBALS['_af_fake_render'] = '   ';
			$post                       = $this->fixture( 23, array(), array( '_fake_owned' => '1' ) );

			$this->assertNull( PageBuilders::filter_markdown_source( null, $post ) );
		}

		/* ---- the write guard ------------------------------------------------- */

		private function update( array $input ) {
			return ( new ContentWriter( new Settings() ) )->update( $input );
		}

		public function test_body_replacement_on_an_owned_page_is_refused_with_the_reason() {
			$this->teach_fake_builder();
			$this->fixture( 30, array(), array( '_fake_owned' => '1' ) );

			$out = $this->update( array( 'post_id' => 30, 'content' => '<p>New body.</p>' ) );

			$this->assertInstanceOf( \WP_Error::class, $out );
			$this->assertSame( 'agentimus_builder_page', $out->get_error_code() );
			$this->assertStringContainsString( 'FakeBuilder', $out->get_error_message(), 'The refusal names the builder.' );
			$this->assertStringContainsString( 'nothing visitors see', $out->get_error_message(), 'Meta-storage: the silent no-op is the stated reason.' );
			$this->assertSame( array(), $GLOBALS['_af_updated_posts'] ?? array(), 'Nothing may reach wp_update_post.' );
		}

		public function test_content_storage_builder_refusal_states_the_design_would_be_destroyed() {
			$this->teach_fake_builder( 'content' );
			$this->fixture( 31, array(), array( '_fake_owned' => '1' ) );

			$out = $this->update( array( 'post_id' => 31, 'content' => '<p>New body.</p>' ) );

			$this->assertInstanceOf( \WP_Error::class, $out );
			$this->assertSame( 'agentimus_builder_page', $out->get_error_code() );
			$this->assertStringContainsString( 'destroy', $out->get_error_message() );
		}

		public function test_a_plain_post_still_takes_body_replacements() {
			$this->teach_fake_builder();
			$this->fixture( 32 );

			$out = $this->update( array( 'post_id' => 32, 'content' => '<p>New body.</p>' ) );

			$this->assertNotInstanceOf( \WP_Error::class, $out, 'No builder ⇒ the guard must not fire.' );
			$this->assertCount( 1, $GLOBALS['_af_updated_posts'] );
		}

		public function test_non_body_fields_still_work_on_an_owned_page() {
			// The guard is about the BODY. Title, description, topics, excerpt,
			// slug, status all remain writable on a builder page.
			$this->teach_fake_builder();
			$this->fixture( 33, array(), array( '_fake_owned' => '1' ) );

			$out = $this->update( array( 'post_id' => 33, 'title' => 'Sharper title' ) );

			$this->assertNotInstanceOf( \WP_Error::class, $out );
			$this->assertSame( 'Sharper title', $GLOBALS['_af_updated_posts'][0]['post_title'] ?? null );
		}

		/* ---- the description fallback ---------------------------------------- */

		public function test_summary_text_is_the_stripped_render_cached_by_fingerprint() {
			$this->teach_fake_builder();
			$post = $this->fixture( 40, array(), array( '_fake_owned' => '1' ) );

			$this->assertSame( 'Real builder body text', PageBuilders::summary_text( $post ) );
			$this->assertSame( 'Real builder body text', PageBuilders::summary_text( $post ) );
			$this->assertSame( 1, $GLOBALS['_af_fake_render_calls'], 'Same fingerprint ⇒ one render, then the cache answers.' );

			$GLOBALS['_af_fake_hash']   = 'h2';
			$GLOBALS['_af_fake_render'] = '<p>Edited in the builder.</p>';
			$this->assertSame( 'Edited in the builder.', PageBuilders::summary_text( $post ), 'A new layout fingerprint invalidates the cached text.' );
			$this->assertSame( 2, $GLOBALS['_af_fake_render_calls'] );
		}

		public function test_summary_text_is_null_for_unowned_posts() {
			$this->teach_fake_builder();
			$post = $this->fixture( 41 );
			$this->assertNull( PageBuilders::summary_text( $post ) );
		}

		public function test_description_fallback_reads_the_builder_render_not_the_stale_body() {
			$this->teach_fake_builder();
			$post = $this->fixture( 42, array( 'post_content' => '<p>Original WordPress content before the builder took over.</p>' ), array( '_fake_owned' => '1' ) );

			$this->assertSame( 'Real builder body text', Description::excerpt( $post ) );
		}

		public function test_manual_excerpt_still_beats_the_builder_render() {
			$this->teach_fake_builder();
			$post = $this->fixture( 43, array( 'post_excerpt' => 'The owner wrote this line.' ), array( '_fake_owned' => '1' ) );

			$this->assertSame( 'The owner wrote this line.', Description::excerpt( $post ) );
		}

		public function test_description_fallback_unchanged_for_plain_posts() {
			$this->teach_fake_builder();
			$post = $this->fixture( 44, array( 'post_content' => '<p>An ordinary body.</p>' ) );

			$this->assertSame( 'An ordinary body.', Description::excerpt( $post ) );
		}
	}
}
