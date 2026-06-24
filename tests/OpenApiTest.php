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

	private function doc( array $resources = null, array $info = null ): array {
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
}
