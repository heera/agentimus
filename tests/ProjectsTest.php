<?php
/**
 * Projects — the software an owner lists as theirs, published three ways.
 *
 * Projects are opt-in (never guessed): the sanitiser drops nameless rows and
 * cleans the rest; Schema emits an author-linked SoftwareApplication per row;
 * llms.txt gains a `## Projects` block; discovery.json carries them under
 * `identity.projects`. A site that lists none publishes exactly what it did.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Envelope;
use Agentimus\Discovery\Registry;
use Agentimus\LlmsText;
use Agentimus\Schema;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ProjectsTest extends TestCase {

	private const AGENTIMUS = array(
		'name'        => 'Agentimus',
		'description' => 'Makes a WordPress site legible to AI assistants.',
		'url'         => 'https://heera.it/agentimus',
		'repo'        => 'https://github.com/heera/agentimus',
		'download'    => 'https://wordpress.org/plugins/agentimus/',
		'version'     => '1.52.0',
	);

	protected function setUp(): void {
		\_af_reset_registry();
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function set_projects( array $projects ): void {
		update_option( Settings::OPTION, array( 'identity' => array( 'projects' => $projects ) ) );
	}

	/** Reflection-call a private builder on a fresh instance of $class. */
	private function call( string $class, string $method ) {
		$m = new \ReflectionMethod( $class, $method );
		\_af_accessible( $m );
		return $m->invoke( new $class( new Settings() ) );
	}

	/* -- The sanitiser ---------------------------------------------------- */

	public function test_sanitize_drops_nameless_rows_and_keeps_every_field() {
		$clean = ( new Settings() )->sanitize( array(
			'identity' => array(
				'projects' => array(
					self::AGENTIMUS,
					array( 'name' => '', 'repo' => 'https://github.com/x/y' ),
					array( 'description' => 'nameless → dropped' ),
				),
			),
		) );
		$this->assertSame( array( self::AGENTIMUS ), $clean['identity']['projects'] );
	}

	public function test_sanitize_defaults_optional_fields_to_empty() {
		$clean = ( new Settings() )->sanitize( array(
			'identity' => array( 'projects' => array( array( 'name' => 'Tool' ) ) ),
		) );
		$this->assertSame(
			array( 'name' => 'Tool', 'description' => '', 'url' => '', 'repo' => '', 'download' => '', 'version' => '' ),
			$clean['identity']['projects'][0]
		);
	}

	public function test_sanitize_keeps_projects_out_of_services() {
		$clean = ( new Settings() )->sanitize( array(
			'identity' => array( 'projects' => array( self::AGENTIMUS ) ),
		) );
		$this->assertSame( array(), $clean['identity']['services'] );
	}

	/* -- SoftwareApplication nodes --------------------------------------- */

	public function test_project_node_names_the_entity_as_author() {
		$this->set_projects( array( self::AGENTIMUS ) );
		$nodes = $this->call( Schema::class, 'project_nodes' );
		$this->assertSame(
			array(
				'@type'           => 'SoftwareApplication',
				'@id'             => 'https://example.test/#project-agentimus',
				'name'            => 'Agentimus',
				'author'          => array( '@id' => 'https://example.test/#identity' ),
				'description'     => 'Makes a WordPress site legible to AI assistants.',
				'softwareVersion' => '1.52.0',
				'url'             => 'https://heera.it/agentimus',
				'downloadUrl'     => 'https://wordpress.org/plugins/agentimus/',
				'sameAs'          => 'https://github.com/heera/agentimus',
			),
			$nodes[0]
		);
	}

	public function test_a_name_only_project_carries_no_empty_properties() {
		$this->set_projects( array( array( 'name' => 'Tool' ) ) );
		$this->assertSame(
			array( '@type', '@id', 'name', 'author' ),
			array_keys( $this->call( Schema::class, 'project_nodes' )[0] )
		);
	}

	public function test_site_graph_carries_projects_and_a_post_graph_does_not() {
		$this->set_projects( array( self::AGENTIMUS ) );
		$schema = new Schema( new Settings() );
		$types  = static function ( $doc ) {
			return array_column( $doc['@graph'], '@type' );
		};
		$this->assertContains( 'SoftwareApplication', $types( $schema->build_document( null ) ) );
		$this->assertNotContains( 'SoftwareApplication', $types( $schema->build_document( null, false ) ) );
	}

	public function test_no_projects_no_nodes() {
		$this->assertSame( array(), $this->call( Schema::class, 'project_nodes' ) );
	}

	/* -- llms.txt -------------------------------------------------------- */

	public function test_llms_block_lists_each_project_on_one_line() {
		$this->set_projects( array( self::AGENTIMUS, array( 'name' => 'Tool' ) ) );
		$this->assertSame(
			"\n## Projects\n\n"
			. '- [Agentimus](https://heera.it/agentimus): Makes a WordPress site legible to AI assistants.'
			. ' · Version 1.52.0 · Source: https://github.com/heera/agentimus'
			. " · Download: https://wordpress.org/plugins/agentimus/\n"
			. "- Tool\n",
			$this->call( LlmsText::class, 'projects_block' )
		);
	}

	public function test_a_newline_in_a_description_cannot_forge_a_list_entry() {
		$this->set_projects( array( array( 'name' => 'Tool', 'description' => "Real.\n- [Fake](http://evil.test)" ) ) );
		$this->assertSame(
			"\n## Projects\n\n- Tool: Real. - [Fake](http://evil.test)\n",
			$this->call( LlmsText::class, 'projects_block' )
		);
	}

	public function test_no_projects_no_llms_block() {
		$this->assertSame( '', $this->call( LlmsText::class, 'projects_block' ) );
	}

	/* -- discovery.json -------------------------------------------------- */

	private function identity(): array {
		return ( new Envelope( new Settings(), Registry::instance() ) )->build()['identity'];
	}

	public function test_discovery_identity_lists_projects_with_filled_fields_only() {
		$this->set_projects( array( self::AGENTIMUS, array( 'name' => 'Tool' ) ) );
		$this->assertSame(
			array(
				array(
					'name'        => 'Agentimus',
					'description' => 'Makes a WordPress site legible to AI assistants.',
					'version'     => '1.52.0',
					'url'         => 'https://heera.it/agentimus',
					'repo'        => 'https://github.com/heera/agentimus',
					'download'    => 'https://wordpress.org/plugins/agentimus/',
				),
				array( 'name' => 'Tool' ),
			),
			$this->identity()['projects']
		);
	}

	public function test_discovery_identity_unchanged_without_projects() {
		$this->assertArrayNotHasKey( 'projects', $this->identity() );
	}
}
