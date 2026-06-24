<?php
/**
 * OpenAPI generator — emits an OpenAPI 3.1 document DESCRIBING the site's
 * existing public read API: WordPress's REST API for the agent-indexed content
 * types. It adds no endpoints and changes no behaviour — it's a machine-readable
 * contract pointing at the REST that already exists, so agent tooling can consume
 * the site without a human explaining it. Served at /.well-known/openapi.json and
 * advertised from discovery.json + the api-catalog.
 *
 * @package Agentimus
 */

namespace Agentimus\Discovery;

use Agentimus\Content;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class OpenApi {

	const OPENAPI_VERSION = '3.1.0';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The OpenAPI document as pretty JSON. Served as application/json, so slashes
	 * are left unescaped for readability.
	 *
	 * @return string
	 */
	public function json() {
		return (string) wp_json_encode(
			$this->document(),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);
	}

	/**
	 * Build the document from the live site: one read collection per content type
	 * that is both agent-indexed and actually exposed by the REST API.
	 *
	 * @return array
	 */
	public function document() {
		$resources = array();
		foreach ( Content::post_types() as $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj || empty( $obj->show_in_rest ) ) {
				continue; // Only types the REST API really serves can be described.
			}
			$namespace = ! empty( $obj->rest_namespace ) ? $obj->rest_namespace : 'wp/v2';
			$base      = ! empty( $obj->rest_base ) ? $obj->rest_base : $type;
			$label     = Content::label( $type );

			$resources[] = array(
				'path'   => '/' . trim( (string) $namespace, '/' ) . '/' . $base,
				'type'   => $type,
				'label'  => $label,
				'single' => self::singular( $label ),
			);
		}

		$name = (string) $this->settings->identity( 'name', get_bloginfo( 'name' ) );
		$name = '' !== $name ? $name : get_bloginfo( 'name' );

		return self::build(
			$resources,
			array(
				/* translators: %s: site name. */
				'title'       => sprintf( __( '%s — content API', 'agentimus' ), $name ),
				'version'     => defined( 'AGENTIMUS_VERSION' ) ? AGENTIMUS_VERSION : '1.0',
				'description' => __( 'Read-only access to this site’s published content via the WordPress REST API. This document describes the API that already exists; it does not add new endpoints.', 'agentimus' ),
				'server'      => rest_url(),
			)
		);
	}

	/**
	 * Pure builder: assemble an OpenAPI 3.1 array from read collections and doc
	 * metadata. WP-free so it can be unit-tested directly.
	 *
	 * @param array $resources [ { path, label, single }, … ].
	 * @param array $info      { title, version, description, server }.
	 * @return array
	 */
	public static function build( array $resources, array $info ) {
		$doc = array(
			'openapi' => self::OPENAPI_VERSION,
			'info'    => array(
				'title'       => (string) ( isset( $info['title'] ) ? $info['title'] : 'Content API' ),
				'version'     => (string) ( isset( $info['version'] ) ? $info['version'] : '1.0' ),
				'description' => (string) ( isset( $info['description'] ) ? $info['description'] : '' ),
			),
			'servers' => array(
				array( 'url' => rtrim( (string) ( isset( $info['server'] ) ? $info['server'] : '' ), '/' ) ),
			),
			'paths'      => array(),
			'components' => array( 'schemas' => array( 'ContentItem' => self::content_item_schema() ) ),
		);

		foreach ( $resources as $r ) {
			$path  = '/' . ltrim( (string) $r['path'], '/' );
			$label = isset( $r['label'] ) ? (string) $r['label'] : 'items';
			$one   = isset( $r['single'] ) ? (string) $r['single'] : $label;

			// GET /{namespace}/{base} — list.
			$doc['paths'][ $path ] = array(
				'get' => array(
					'tags'       => array( $label ),
					'summary'    => sprintf( 'List %s', $label ),
					'parameters' => array(
						self::query_param( 'page', 'integer', 'Page of the result set.' ),
						self::query_param( 'per_page', 'integer', 'Items per page (1–100).' ),
						self::query_param( 'search', 'string', 'Limit results to those matching a string.' ),
					),
					'responses'  => array(
						'200' => self::json_response(
							sprintf( 'A page of %s.', $label ),
							array(
								'type'  => 'array',
								'items' => array( '$ref' => '#/components/schemas/ContentItem' ),
							)
						),
					),
				),
			);

			// GET /{namespace}/{base}/{id} — single.
			$doc['paths'][ $path . '/{id}' ] = array(
				'get' => array(
					'tags'       => array( $label ),
					'summary'    => sprintf( 'Get a single %s by id', $one ),
					'parameters' => array(
						array(
							'name'     => 'id',
							'in'       => 'path',
							'required' => true,
							'schema'   => array( 'type' => 'integer' ),
						),
					),
					'responses'  => array(
						'200' => self::json_response( sprintf( 'The requested %s.', $one ), array( '$ref' => '#/components/schemas/ContentItem' ) ),
						'404' => array( 'description' => 'Not found.' ),
					),
				),
			);
		}

		return $doc;
	}

	/**
	 * A query parameter object.
	 *
	 * @param string $name Parameter name.
	 * @param string $type JSON Schema type.
	 * @param string $desc Description.
	 * @return array
	 */
	private static function query_param( $name, $type, $desc ) {
		return array(
			'name'        => $name,
			'in'          => 'query',
			'required'    => false,
			'description' => $desc,
			'schema'      => array( 'type' => $type ),
		);
	}

	/**
	 * A 200-style application/json response with the given schema.
	 *
	 * @param string $description Response description.
	 * @param array  $schema      JSON Schema (object or $ref).
	 * @return array
	 */
	private static function json_response( $description, array $schema ) {
		return array(
			'description' => $description,
			'content'     => array(
				'application/json' => array( 'schema' => $schema ),
			),
		);
	}

	/**
	 * A small subset of the WP REST post object — the fields an agent reads. Not
	 * exhaustive (the live API returns more); enough to be a useful contract.
	 *
	 * @return array
	 */
	private static function content_item_schema() {
		$rendered = array(
			'type'       => 'object',
			'properties' => array( 'rendered' => array( 'type' => 'string' ) ),
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'date'     => array( 'type' => 'string', 'format' => 'date-time' ),
				'modified' => array( 'type' => 'string', 'format' => 'date-time' ),
				'slug'     => array( 'type' => 'string' ),
				'status'   => array( 'type' => 'string' ),
				'link'     => array( 'type' => 'string', 'format' => 'uri' ),
				'title'    => $rendered,
				'excerpt'  => $rendered,
				'content'  => $rendered,
			),
		);
	}

	/**
	 * Crude singularisation for a summary string ("Posts" → "Post"). Cosmetic only.
	 *
	 * @param string $label Plural label.
	 * @return string
	 */
	private static function singular( $label ) {
		return (string) preg_replace( '/s$/i', '', (string) $label );
	}
}
