<?php
/**
 * Schema::build_document() — the graph assembler shared by the front-end output
 * and the admin JSON-LD preview. Proves the two target shapes (site vs post), the
 * services-placement rule, that the per-post privacy guard still holds when a post
 * is built directly (the preview path), and that the preview flag relaxes only the
 * publish-status half of that guard (draft → would-be node) while password gating
 * still holds.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Schema;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class SchemaPreviewTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function schema(): Schema {
		return new Schema( new Settings() );
	}

	/** Every node @type in a built document (an @type may be a string or array). */
	private function types( $doc ): array {
		$out = array();
		foreach ( (array) ( $doc['@graph'] ?? array() ) as $node ) {
			if ( isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $t ) {
					$out[] = $t;
				}
			}
		}
		return $out;
	}

	private function post( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'ID'            => 11,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
				'post_title'    => 'Hello World',
				'post_content'  => '<p>Just a paragraph.</p>',
			),
			$overrides
		);
	}

	private function with_services(): void {
		update_option(
			Settings::OPTION,
			array( 'identity' => array( 'services' => array( array( 'name' => 'Consulting' ) ) ) )
		);
	}

	/* -- Site graph ------------------------------------------------------- */

	public function test_site_graph_has_identity_and_services_but_no_post_nodes() {
		$this->with_services();
		$types = $this->types( $this->schema()->build_document( null ) );

		$this->assertContains( 'WebSite', $types );
		$this->assertContains( 'Person', $types );   // default entity type
		$this->assertContains( 'Service', $types );
		$this->assertNotContains( 'BlogPosting', $types );
		$this->assertNotContains( 'BreadcrumbList', $types );
	}

	/* -- Post graph ------------------------------------------------------- */

	public function test_post_graph_has_article_and_breadcrumb_not_services() {
		$this->with_services();
		$types = $this->types( $this->schema()->build_document( $this->post(), false ) );

		$this->assertContains( 'WebSite', $types );
		$this->assertContains( 'BlogPosting', $types );      // post → BlogPosting
		$this->assertContains( 'BreadcrumbList', $types );
		$this->assertNotContains( 'Service', $types );        // services are site-level
	}

	public function test_page_type_maps_to_webpage() {
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_type' => 'page' ) ), false ) );
		$this->assertContains( 'WebPage', $types );
	}

	public function test_faq_content_adds_faqpage_node() {
		$faq   = '<h2>What is it?</h2><p>A layer.</p><h2>Is it free?</h2><p>Yes.</p>';
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_content' => $faq ) ), false ) );
		$this->assertContains( 'FAQPage', $types );
	}

	/** A title with HTML entities (get_the_title() returns &#8220;…&#8221;) must
	 * reach the graph as real characters — entities aren't decoded inside a
	 * <script type="application/ld+json">, so emitting them would corrupt the value. */
	public function test_entities_in_title_are_decoded_in_the_graph() {
		$doc      = $this->schema()->build_document( $this->post( array( 'post_title' => 'The &#8220;AI Tax&#8221;' ) ), false );
		$headline = '';
		foreach ( (array) $doc['@graph'] as $node ) {
			if ( isset( $node['headline'] ) ) {
				$headline = $node['headline'];
				break;
			}
		}
		$this->assertSame( "The \u{201C}AI Tax\u{201D}", $headline );
		$this->assertStringNotContainsString( '&#8220;', $headline );
	}

	/* -- Privacy guard on the direct (preview) path ----------------------- */

	public function test_draft_post_yields_site_nodes_only() {
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_status' => 'draft' ) ), false ) );
		$this->assertContains( 'WebSite', $types );
		$this->assertNotContains( 'BlogPosting', $types );
		$this->assertNotContains( 'BreadcrumbList', $types );
	}

	public function test_password_protected_post_yields_site_nodes_only() {
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_password' => 'secret' ) ), false ) );
		$this->assertContains( 'WebSite', $types );
		$this->assertNotContains( 'BlogPosting', $types );
	}

	/* -- Preview relaxes ONLY the publish-status guard -------------------- */

	public function test_preview_includes_would_be_node_for_draft() {
		// preview = true → the admin preview shows the per-post node a draft WILL emit
		// once published, so the owner can check it before publishing.
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_status' => 'draft' ) ), false, true ) );
		$this->assertContains( 'BlogPosting', $types );
		$this->assertContains( 'BreadcrumbList', $types );
	}

	public function test_preview_still_excludes_password_protected_post() {
		// The password guard is never relaxed — a gated body stays private even once
		// published, so the preview must not fabricate a would-be node for it.
		$types = $this->types( $this->schema()->build_document( $this->post( array( 'post_password' => 'secret' ) ), false, true ) );
		$this->assertContains( 'WebSite', $types );
		$this->assertNotContains( 'BlogPosting', $types );
	}

	/* -- The services-placement default ----------------------------------- */

	public function test_include_services_defaults_to_true_for_site_false_for_post() {
		$this->with_services();
		// Null post → site view → services included by default.
		$this->assertContains( 'Service', $this->types( $this->schema()->build_document( null ) ) );
		// A post with the default (unspecified) flag → services omitted.
		$this->assertNotContains( 'Service', $this->types( $this->schema()->build_document( $this->post() ) ) );
	}

	/* -- FAQ size ceiling (bounds the per-request DOM cost) --------------- */

	private const FAQ_HTML = '<details><summary>What is it?</summary><p>A discovery layer.</p></details>'
		. '<details><summary>Is it free?</summary><p>Yes, fully.</p></details>';

	public function test_a_normal_faq_post_still_emits_a_faqpage_node() {
		$doc = $this->schema()->build_document( $this->post( array( 'post_content' => self::FAQ_HTML ) ), false );
		$this->assertContains( 'FAQPage', $this->types( $doc ) );
	}

	public function test_faq_detection_is_skipped_above_the_size_ceiling() {
		// Force a tiny ceiling so even this small FAQ post exceeds it — the expensive
		// do_blocks + DOM parse must be skipped on an oversized body.
		add_filter( 'agentimus_faq_max_bytes', static function () { return 10; } );
		$doc = $this->schema()->build_document( $this->post( array( 'post_content' => self::FAQ_HTML ) ), false );
		$this->assertNotContains( 'FAQPage', $this->types( $doc ) );
	}
}
