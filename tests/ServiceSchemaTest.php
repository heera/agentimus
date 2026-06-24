<?php
/**
 * Service schema + FAQPage node assembly, and the services sanitiser.
 *
 * Services are opt-in (never guessed): the sanitiser drops nameless rows and
 * cleans the rest; Schema emits a provider-linked Service node per row. The
 * FAQPage node only forms with two or more Q&A pairs.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Schema;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ServiceSchemaTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* -- The sanitiser ---------------------------------------------------- */

	public function test_sanitize_drops_nameless_rows_and_cleans_the_rest() {
		$clean = ( new Settings() )->sanitize( array(
			'identity' => array(
				'services' => array(
					array( 'name' => 'Plugin development', 'description' => 'Custom plugins.', 'url' => 'https://heera.it/dev' ),
					array( 'name' => '', 'description' => 'no name → dropped' ),
					array( 'description' => 'also nameless → dropped' ),
				),
			),
		) );
		$services = $clean['identity']['services'];
		$this->assertCount( 1, $services );
		$this->assertSame( 'Plugin development', $services[0]['name'] );
		$this->assertSame( 'Custom plugins.', $services[0]['description'] );
		$this->assertSame( 'https://heera.it/dev', $services[0]['url'] );
	}

	public function test_sanitize_defaults_optional_fields_to_empty() {
		$clean = ( new Settings() )->sanitize( array(
			'identity' => array( 'services' => array( array( 'name' => 'Consulting' ) ) ),
		) );
		$this->assertSame( array( 'name' => 'Consulting', 'description' => '', 'url' => '' ), $clean['identity']['services'][0] );
	}

	/* -- Service nodes ---------------------------------------------------- */

	/** Reflection-call a private Schema builder. */
	private function build( string $method, ...$args ) {
		$m = new \ReflectionMethod( Schema::class, $method );
		$m->setAccessible( true );
		return $m->invoke( new Schema( new Settings() ), ...$args );
	}

	private function set_services( array $services ): void {
		update_option( Settings::OPTION, array( 'identity' => array( 'services' => $services ) ) );
	}

	public function test_service_node_links_provider_to_entity() {
		$this->set_services( array(
			array( 'name' => 'Plugin development', 'description' => 'Custom plugins.', 'url' => 'https://heera.it/dev' ),
		) );
		$nodes = $this->build( 'service_nodes' );
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'Service', $nodes[0]['@type'] );
		$this->assertSame( 'Plugin development', $nodes[0]['name'] );
		$this->assertStringContainsString( '#identity', $nodes[0]['provider']['@id'] );
		$this->assertSame( 'https://heera.it/dev', $nodes[0]['url'] );
	}

	public function test_no_services_no_nodes() {
		$this->assertSame( array(), $this->build( 'service_nodes' ) );
	}

	/* -- FAQPage node ----------------------------------------------------- */

	public function test_faq_node_forms_with_two_pairs() {
		$post = (object) array(
			'ID'           => 7,
			'post_content' => '<h2>What is it?</h2><p>A layer.</p><h2>Is it free?</h2><p>Yes.</p>',
		);
		$node = $this->build( 'faq_node', $post );
		$this->assertIsArray( $node );
		$this->assertSame( 'FAQPage', $node['@type'] );
		$this->assertCount( 2, $node['mainEntity'] );
		$this->assertSame( 'Question', $node['mainEntity'][0]['@type'] );
		$this->assertSame( 'Answer', $node['mainEntity'][0]['acceptedAnswer']['@type'] );
	}

	public function test_faq_node_null_with_one_pair() {
		$post = (object) array(
			'ID'           => 8,
			'post_content' => '<h2>What is it?</h2><p>Only one question, so not an FAQ.</p>',
		);
		$this->assertNull( $this->build( 'faq_node', $post ) );
	}
}
