<?php
/**
 * OpenApi::build — the pure assembler for the OpenAPI 3.1 description of the
 * existing REST read API. Exercised directly (the live document() needs WP post
 * types); this locks the document shape: version, info, servers, list + item
 * paths per resource, and the shared ContentItem schema.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\OpenApi;
use PHPUnit\Framework\TestCase;

final class OpenApiTest extends TestCase {

	private function doc( ?array $resources = null, ?array $info = null ): array {
		$resources = $resources ?? array(
			array( 'path' => '/wp/v2/posts', 'label' => 'Posts', 'single' => 'Post' ),
		);
		$info = $info ?? array(
			'title'   => 'Test Site — content API',
			'version' => '1.3.0',
			'server'  => 'https://example.test/wp-json/',
		);
		return OpenApi::build( $resources, $info );
	}

	public function test_valid_3_1_skeleton() {
		$doc = $this->doc();
		$this->assertSame( '3.1.0', $doc['openapi'] );
		$this->assertSame( 'Test Site — content API', $doc['info']['title'] );
		$this->assertSame( '1.3.0', $doc['info']['version'] );
		$this->assertArrayHasKey( 'ContentItem', $doc['components']['schemas'] );
	}

	public function test_server_url_trailing_slash_trimmed() {
		$doc = $this->doc();
		$this->assertSame( 'https://example.test/wp-json', $doc['servers'][0]['url'] );
	}

	public function test_emits_list_and_item_paths() {
		$doc   = $this->doc();
		$paths = $doc['paths'];
		$this->assertArrayHasKey( '/wp/v2/posts', $paths );
		$this->assertArrayHasKey( '/wp/v2/posts/{id}', $paths );

		// List: a GET with the standard query params and an array response.
		$list = $paths['/wp/v2/posts']['get'];
		$names = array_column( $list['parameters'], 'name' );
		$this->assertSame( array( 'page', 'per_page', 'search' ), $names );
		$this->assertSame( 'array', $list['responses']['200']['content']['application/json']['schema']['type'] );

		// Item: a required integer path param and a 404.
		$item = $paths['/wp/v2/posts/{id}']['get'];
		$this->assertSame( 'id', $item['parameters'][0]['name'] );
		$this->assertSame( 'path', $item['parameters'][0]['in'] );
		$this->assertTrue( $item['parameters'][0]['required'] );
		$this->assertArrayHasKey( '404', $item['responses'] );
		$this->assertSame(
			'#/components/schemas/ContentItem',
			$item['responses']['200']['content']['application/json']['schema']['$ref']
		);
	}

	public function test_multiple_resources_each_get_two_paths() {
		$doc = $this->doc( array(
			array( 'path' => '/wp/v2/posts', 'label' => 'Posts', 'single' => 'Post' ),
			array( 'path' => '/wp/v2/pages', 'label' => 'Pages', 'single' => 'Page' ),
		) );
		$this->assertCount( 4, $doc['paths'] );
	}

	public function test_empty_resources_still_valid() {
		$doc = $this->doc( array() );
		$this->assertSame( '3.1.0', $doc['openapi'] );
		$this->assertSame( array(), $doc['paths'] );
	}

	public function test_error_responses_are_typed_and_operations_described() {
		// Agents branch on a machine-readable error code, not prose — so 4xx
		// responses reference the WordPress REST error shape the API really
		// returns, and every operation carries a description beside its summary.
		$doc = $this->doc();

		$err = $doc['components']['schemas']['Error'];
		$this->assertSame( 'string', $err['properties']['code']['type'] );
		$this->assertContains( 'code', $err['required'] );

		$list = $doc['paths']['/wp/v2/posts']['get'];
		$this->assertNotEmpty( $list['description'] );
		$this->assertSame( '#/components/schemas/Error', $list['responses']['400']['content']['application/json']['schema']['$ref'] );

		$item = $doc['paths']['/wp/v2/posts/{id}']['get'];
		$this->assertNotEmpty( $item['description'] );
		$this->assertSame( '#/components/schemas/Error', $item['responses']['404']['content']['application/json']['schema']['$ref'] );
	}

	public function test_every_operation_carries_a_unique_operation_id() {
		// operationId is what function-calling toolchains name the tool after; a
		// spec without them graded "0/N operationIds" on agent-compatibility
		// scanners. Derived from the literal path, so ids are unique iff paths are.
		$doc = $this->doc( array(
			array( 'path' => '/wp/v2/posts', 'label' => 'Posts', 'single' => 'Post' ),
			array( 'path' => '/wp/v2/pages', 'label' => 'Pages', 'single' => 'Page' ),
		) );

		$this->assertSame( 'list_wp_v2_posts', $doc['paths']['/wp/v2/posts']['get']['operationId'] );
		$this->assertSame( 'get_wp_v2_posts_id', $doc['paths']['/wp/v2/posts/{id}']['get']['operationId'] );

		$ids = array();
		foreach ( $doc['paths'] as $ops ) {
			foreach ( $ops as $op ) {
				$this->assertArrayHasKey( 'operationId', $op );
				$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $op['operationId'] );
				$ids[] = $op['operationId'];
			}
		}
		$this->assertSame( $ids, array_values( array_unique( $ids ) ), 'operationIds must be unique across the document.' );
	}
}
