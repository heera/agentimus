<?php
/**
 * Failure isolation on the HUMAN-facing front end.
 *
 * Schema::output() and Description::buffer_start()/buffer_end() both run on `wp_head` for
 * every visitor, and both hand control to third-party code — block/shortcode callbacks via
 * do_blocks(), and the `agentimus_schema_for_post`, `agentimus_schema_graph`,
 * `agentimus_faq_pairs`, `agentimus_topic_links`, `agentimus_post_description` and
 * `agentimus_emit_meta_description` filters. A single throwing callback anywhere in that
 * set must cost the page its JSON-LD (or its description tag) and nothing more — never a
 * fatal, and never a blanked `<head>`.
 *
 * The blanked-head risk is real rather than theoretical: Description brackets the whole head
 * in an output buffer (priorities -99999 / +99999) and Schema::output() runs INSIDE it at
 * priority 1, so an uncaught Throwable there takes the buffered head down with the request.
 *
 * Markdown::post() has honoured this contract on the agent-facing side from the start; these
 * tests pin the same guarantee on the side humans actually see.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Description;
use Agentimus\Schema;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class FrontEndFailureIsolationTest extends TestCase {

	/** Any throwing third-party callback. */
	const BOOM = 'Third-party callback exploded.';

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** A published, singular post fixture — the state both surfaces require to act. */
	private function fixture( $id = 1, array $meta = array() ) {
		$GLOBALS['_af_posts'][ $id ] = (object) array(
			'ID'            => $id,
			'post_status'   => 'publish',
			'post_password' => '',
			'post_type'     => 'post',
			'post_title'    => 'A Post',
			'post_content'  => '<p>Body.</p>',
			'post_excerpt'  => 'An excerpt that could serve as a description.',
			'post_author'   => 1,
			'post_date_gmt' => '2026-01-01 00:00:00',
			'post_modified_gmt' => '2026-01-01 00:00:00',
		);
		foreach ( $meta as $k => $v ) {
			$GLOBALS['_af_postmeta'][ $id ][ $k ] = $v;
		}
		$GLOBALS['_af_current_post_id'] = $id;
		$GLOBALS['_af_is_singular']     = true;
	}

	/** Register a filter that throws when applied. */
	private function explode_on( $tag ) {
		\add_filter(
			$tag,
			static function () {
				throw new \RuntimeException( self::BOOM );
			}
		);
	}

	/** Capture Schema::output() without letting an exception escape the buffer. */
	private function render_schema(): string {
		\ob_start();
		( new Schema( new Settings() ) )->output();
		return (string) \ob_get_clean();
	}

	// ---- Schema::output() ---------------------------------------------------

	/**
	 * Each third-party hook inside the document build, one at a time: the page must render
	 * with no JSON-LD rather than fatal.
	 *
	 * @dataProvider schema_hooks
	 * @param string $tag Filter tag that throws.
	 */
	public function test_throwing_schema_filter_emits_nothing_and_does_not_fatal( $tag ) {
		$this->fixture();
		$this->explode_on( $tag );

		$out = $this->render_schema();

		$this->assertSame( '', $out, "A throwing `$tag` must suppress the graph, not the page." );
	}

	public function schema_hooks(): array {
		return array(
			'per-post node' => array( 'agentimus_schema_for_post' ),
			'whole graph'   => array( 'agentimus_schema_graph' ),
			'faq pairs'     => array( 'agentimus_faq_pairs' ),
		);
	}

	/**
	 * The suppression must not be sticky. A transient error (a filter that throws once, then
	 * is removed) must not leave the page permanently without structured data — which is what
	 * caching the empty graph inside the catch would have caused.
	 */
	public function test_schema_recovers_once_the_throwing_filter_is_gone() {
		$this->fixture();
		$this->explode_on( 'agentimus_schema_graph' );
		$this->assertSame( '', $this->render_schema() );

		\remove_all_filters( 'agentimus_schema_graph' );

		$this->assertStringContainsString( 'WebSite', $this->render_schema() );
	}

	/** A healthy page still emits its graph — the guard must not suppress the happy path. */
	public function test_schema_still_emits_without_a_throwing_filter() {
		$this->fixture();

		$this->assertStringContainsString( 'WebSite', $this->render_schema() );
	}

	// ---- Description head buffer --------------------------------------------

	/**
	 * The load-bearing one. buffer_end() calls ob_get_clean() BEFORE filter_head(), so once
	 * the description filter throws, the head exists only as a local string. It must still
	 * reach the browser.
	 */
	public function test_throwing_description_filter_preserves_the_head() {
		$this->fixture( 2, array( Description::META => 'A description.' ) );
		$this->explode_on( 'agentimus_post_description' );

		$desc  = new Description( new Settings() );
		$depth = \ob_get_level();

		\ob_start();
		$desc->buffer_start();
		echo '<title>Keep me</title>';
		$desc->buffer_end();
		$out = (string) \ob_get_clean();

		$this->assertStringContainsString( '<title>Keep me</title>', $out, 'The theme head must survive a throwing description filter.' );
		$this->assertStringNotContainsString( 'name="description"', $out, 'No description tag when the resolver threw.' );
		$this->assertSame( $depth, \ob_get_level(), 'buffer_end() must not leak an open output buffer.' );
	}

	/** A throw in should_emit() (buffer_start) must not fatal, and must not swallow the head. */
	public function test_throwing_emit_filter_leaves_the_head_untouched() {
		$this->fixture( 3, array( Description::META => 'A description.' ) );
		$this->explode_on( 'agentimus_emit_meta_description' );

		$desc  = new Description( new Settings() );
		$depth = \ob_get_level();

		\ob_start();
		$desc->buffer_start();
		echo '<title>Keep me</title>';
		$desc->buffer_end();
		$out = (string) \ob_get_clean();

		$this->assertStringContainsString( '<title>Keep me</title>', $out );
		$this->assertStringNotContainsString( 'name="description"', $out );
		$this->assertSame( $depth, \ob_get_level(), 'A throw in buffer_start() must not leave a buffer open.' );
	}

	/** The happy path still injects the tag — the guards must not disable the feature. */
	public function test_head_buffer_still_injects_the_description_when_nothing_throws() {
		$this->fixture( 4, array( Description::META => 'A crisp summary.' ) );

		$desc = new Description( new Settings() );

		\ob_start();
		$desc->buffer_start();
		echo '<title>Keep me</title>';
		$desc->buffer_end();
		$out = (string) \ob_get_clean();

		$this->assertStringContainsString( '<title>Keep me</title>', $out );
		$this->assertStringContainsString( 'name="description"', $out );
		$this->assertStringContainsString( 'A crisp summary.', $out );
	}
}
