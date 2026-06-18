<?php
/**
 * Hub — assembles the data the admin "Discovery Hub" screen renders: the live
 * envelope projected into a UI-friendly shape, the built-in adapters and whether
 * they're active, and the validation notices. Shared by the admin bootstrap and
 * the REST re-scan route so both see identical data.
 *
 * @package Agentomatic
 */

namespace Agentomatic\Discovery;

use Agentomatic\Settings;

defined( 'ABSPATH' ) || exit;

final class Hub {

	/**
	 * Built-in adapters — first-party callers of the public registration hook.
	 * Each MUST expose a static info(): {id,title,available}.
	 *
	 * @var string[]
	 */
	const ADAPTERS = array(
		Adapters\RestApi::class,
		Adapters\AbilitiesApi::class,
	);

	/**
	 * Build the Discovery Hub payload.
	 *
	 * @param Settings $settings Settings store.
	 * @param Registry $registry Collector (collected as a side effect).
	 * @return array
	 */
	public static function data( Settings $settings, Registry $registry ) {
		$builder  = new Envelope( $settings, $registry );
		$envelope = $builder->build();
		// tools + mcp are not part of the public discovery.json core; pull them from
		// the builder for the admin screen and the mcp.json document.
		$surface  = $builder->mcp_surface();

		// The admin lists EVERY Resource (suppressed ones flagged, not dropped) so
		// the owner can re-enable them — unlike the served envelope, which excludes
		// them. apis[]/capabilities/counts below stay from the filtered envelope, so
		// they reflect what is actually published.
		$suppressed = $builder->suppressed_ids();
		$rows       = array_map(
			static function ( $resource ) use ( $suppressed ) {
				return self::resource_row( $resource, $suppressed );
			},
			$builder->all_resources()
		);

		return array(
			'endpoints'    => array(
				'discovery' => home_url( '/.well-known/discovery.json' ),
				'agentCard' => home_url( '/.well-known/agent-card.json' ),
				'agentJson' => home_url( '/.well-known/agent.json' ),
				'mcp'       => home_url( '/.well-known/mcp.json' ),
				'rest'      => rest_url( 'agentomatic/v1/discovery' ),
			),
			'site'         => $envelope['site'],
			'resources'    => $rows,
			'capabilities' => $envelope['capabilities'],
			'apis'         => $envelope['apis'],
			'agents'       => array_map(
				static function ( $agent ) {
					return array(
						'id'     => isset( $agent['id'] ) ? $agent['id'] : '',
						'name'   => isset( $agent['name'] ) ? $agent['name'] : '',
						'skills' => isset( $agent['skills'] ) ? count( (array) $agent['skills'] ) : 0,
					);
				},
				$envelope['agents']
			),
			'wellKnown'    => $envelope['well_known'],
			'tools'        => $surface['tools'],
			'mcp'          => $surface['mcp'],
			'adapters'     => self::adapters(),
			'notices'      => $registry->notices(),
			'counts'       => array(
				'resources'    => count( $envelope['resources'] ),
				'suppressed'   => count(
					array_filter(
						$rows,
						static function ( $r ) {
							return ! empty( $r['suppressed'] );
						}
					)
				),
				'capabilities' => count( $envelope['capabilities'] ),
				'apis'         => count( $envelope['apis'] ),
				'tools'        => count( $surface['tools'] ),
				'errors'       => count(
					array_filter(
						$registry->notices(),
						static function ( $n ) {
							return 'error' === $n['level'];
						}
					)
				),
			),
		);
	}

	/**
	 * Trim a full envelope resource to what the UI shows.
	 *
	 * @param array    $resource   Envelope resource.
	 * @param string[] $suppressed Owner-suppressed Resource ids.
	 * @return array
	 */
	private static function resource_row( $resource, array $suppressed = array() ) {
		$provider = isset( $resource['provider']['plugin'] ) ? $resource['provider']['plugin'] : '';
		$ours     = function_exists( 'plugin_basename' ) ? plugin_basename( AGENTOMATIC_FILE ) : 'agentomatic/agentomatic.php';
		$auto     = ( '' !== $provider && $provider === $ours );
		// Which built-in engine found an auto resource — so the UI can show
		// "Found via the REST API / Abilities API" and link it to the engine status.
		// The AbilitiesApi adapter mints ids as `abilities-<ns>`; everything else
		// auto comes from the REST adapter (wordpress-core + namespace stubs).
		$engine = '';
		if ( $auto ) {
			$engine = ( 0 === strpos( (string) $resource['id'], 'abilities-' ) ) ? 'Abilities API' : 'REST API';
		}
		return array(
			'id'           => $resource['id'],
			'title'        => $resource['title'],
			'type'         => $resource['type'],
			'description'  => $resource['description'],
			'version'      => $resource['version'],
			'provider'     => $provider,
			// True when Agentomatic's own adapter registered it (auto-discovery), not a third-party plugin declaring itself.
			'auto'         => $auto,
			'engine'       => $engine,
			// True when the owner has suppressed this Resource from served output.
			'suppressed'   => in_array( $resource['id'], $suppressed, true ),
			'capabilities' => $resource['capabilities'],
			'endpoints'    => array_map(
				static function ( $endpoint ) {
					return array(
						'url'  => $endpoint['url'],
						'type' => $endpoint['type'],
						'auth' => '' !== $endpoint['auth'] ? $endpoint['auth'] : 'none',
					);
				},
				$resource['endpoints']
			),
			'hasAgent'     => ! empty( $resource['agent'] ),
			'tools'        => count( $resource['tools'] ),
			'abilities'    => $resource['abilities'],
		);
	}

	/**
	 * Built-in adapter descriptors.
	 *
	 * @return array[]
	 */
	private static function adapters() {
		$out = array();
		foreach ( self::ADAPTERS as $class ) {
			if ( is_callable( array( $class, 'info' ) ) ) {
				$out[] = call_user_func( array( $class, 'info' ) );
			}
		}
		return $out;
	}
}
