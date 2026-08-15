<?php
/**
 * Hub — assembles the data the admin "Discovery Hub" screen renders: the live
 * envelope projected into a UI-friendly shape, the built-in adapters and whether
 * they're active, and the validation notices. Shared by the admin bootstrap and
 * the REST re-scan route so both see identical data.
 *
 * @package Agentimus
 */

namespace Agentimus\Discovery;

use Agentimus\Settings;

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

		$endpoints = array(
			'discovery' => home_url( '/.well-known/discovery.json' ),
			'agentCard' => home_url( '/.well-known/agent-card.json' ),
			'agentJson' => home_url( '/.well-known/agent.json' ),
			'mcp'       => home_url( '/.well-known/mcp.json' ),
			'rest'      => rest_url( 'agentimus/v1/discovery' ),
		);
		// The primary MCP server card is served only when a real server is detected
		// (it 404s otherwise), so surface it to the admin only when it's actually live.
		if ( ! empty( $surface['mcp']['available'] ) && ! empty( $surface['mcp']['servers'] ) ) {
			$endpoints['mcpServerCard'] = home_url( '/.well-known/mcp/server-card.json' );
		}

		return array(
			'endpoints'    => $endpoints,
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
			// The Abilities API's own REST listing — the OTHER door a signed-in agent can
			// call (every registered ability, not just the MCP-scoped ones). '' when the
			// Abilities API isn't available on this install.
			'abilitiesEndpoint' => self::abilities_endpoint(),
			'adapters'     => self::adapters(),
			'notices'      => $registry->notices(),
			'counts'       => array(
				'resources'    => count( $envelope['resources'] ),
				// The admin tile shows the REGISTERED number (matching the provider list
				// below it) with the published number as its qualifier — the owner was
				// reading "1 provider" against a 3-row list and rightly asking which lied.
				'resourcesRegistered' => count( $rows ),
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
				// The dashboard tile's public/sign-in split — an endpoint with
				// no auth scheme (or 'none') is an open door; anything else
				// wants a key. The same per-endpoint auth the envelope states.
				'apisPublic'   => count(
					array_filter(
						$envelope['apis'],
						static function ( $api ) {
							return ! isset( $api['auth']['type'] ) || '' === $api['auth']['type'] || 'none' === $api['auth']['type'];
						}
					)
				),
				// `tools` counts what the site HAS, not what it anonymously publishes — this card is
				// the owner's inventory of their own site, and an AUTHORISED agent really can run all
				// of them. Counting only published tools would read 0 the moment abilities stopped
				// being advertised anonymously, which looks like they vanished. `toolsPublished` keeps
				// the published number available, and the Discovery screen flags which rows are held
				// back and why.
				'tools'        => array_sum(
					array_map(
						static function ( $r ) {
							return isset( $r['tools'] ) ? count( self::of_kind( $r['tools'], 'tool' ) ) : 0;
						},
						$builder->all_resources()
					)
				),
				// Documents offered to be attached, counted apart from runnable tools.
				'docs'         => array_sum(
					array_map(
						static function ( $r ) {
							return isset( $r['tools'] ) ? count( self::of_kind( $r['tools'], 'resource' ) ) : 0;
						},
						$builder->all_resources()
					)
				),
				'toolsPublished' => count( self::of_kind( $surface['tools'], 'tool' ) ),
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
	/**
	 * The entries of one kind — 'tool' (runnable) or 'resource' (a document an
	 * assistant attaches). Anything unmarked is a tool: only the abilities
	 * adapter knows about resources, and a third-party provider's plain list
	 * must keep counting the way it always did.
	 *
	 * @param mixed  $items Tool entries.
	 * @param string $kind  'tool' or 'resource'.
	 * @return array
	 */
	private static function of_kind( $items, $kind ) {
		return array_filter(
			(array) $items,
			static function ( $t ) use ( $kind ) {
				$k = ( is_array( $t ) && isset( $t['kind'] ) && 'resource' === $t['kind'] ) ? 'resource' : 'tool';
				return $k === $kind;
			}
		);
	}

	/**
	 * The ids of the plugins Agentimus itself describes — read from the roster,
	 * so a provider that grows past its card is counted here the moment it is
	 * added there, and no name is written twice.
	 *
	 * @return string[]
	 */
	private static function described_ids() {
		$ids = array();
		foreach ( \Agentimus\Integrations\Rest::PLUGINS as $class ) {
			$ids[] = (string) $class::ID;
		}
		return $ids;
	}

	/**
	 * How many PUBLISHED runnable tools only read, or change something.
	 *
	 * ⚠️ Published only, and that is the whole point. These two numbers sit beside
	 * a switch that decides what is announced, so counting tools nobody announces
	 * makes the switch describe work it would not do: on this site the total said
	 * "13 jobs would stop being announced" when the true answer was six.
	 *
	 * @param array $tools     A resource's tools.
	 * @param bool  $read_only Count the reading ones, or the changing ones.
	 * @return int
	 */
	private static function count_kind( $tools, $read_only ) {
		$n = 0;
		foreach ( self::of_kind( (array) $tools, 'tool' ) as $tool ) {
			if ( isset( $tool['public'] ) && ! $tool['public'] ) {
				continue;
			}
			$hint = isset( $tool['annotations']['readOnlyHint'] ) ? (bool) $tool['annotations']['readOnlyHint'] : false;
			if ( $hint === (bool) $read_only ) {
				++$n;
			}
		}
		return $n;
	}

	private static function resource_row( $resource, array $suppressed = array() ) {
		$provider = isset( $resource['provider']['plugin'] ) ? $resource['provider']['plugin'] : '';
		$ours     = function_exists( 'plugin_basename' ) ? plugin_basename( AGENTIMUS_FILE ) : 'agentimus/agentimus.php';
		$mine     = ( '' !== $provider && $provider === $ours );

		// Two very different things wear Agentimus's name as their provider, and
		// telling the owner they are the same thing is a lie either way round:
		// an adapter FOUND something by scanning the site, or a hand-written
		// provider DESCRIBES a plugin Agentimus recognises. Only the plugin
		// roster knows which — a described resource carries a roster id.
		$described = $mine && in_array( (string) $resource['id'], self::described_ids(), true );
		$auto      = $mine && ! $described;
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
			// True when one of Agentimus's own ADAPTERS found it by scanning.
			'auto'         => $auto,
			// True when Agentimus DESCRIBES a plugin it recognises. Neither the
			// plugin declaring itself nor a scan — a third thing, and the one the
			// Plugins tab promises.
			'described'    => $described,
			'engine'       => $engine,
			// True when the owner has suppressed this Resource from served output.
			'suppressed'   => in_array( $resource['id'], $suppressed, true ),
			// True when the PROVIDER kept it out of the served documents because nobody anonymous
			// could use it anyway (see Resource::normalize()'s `public`, and AbilitiesApi). Distinct from
			// `suppressed`, which is the owner's own choice and is theirs to reverse.
			//
			// This flag is not decoration. Without it the admin lists a Resource with no indication
			// that no agent can see it — and a row shown without a caveat reads as "this is live".
			'notPublic'    => isset( $resource['public'] ) && ! $resource['public'],
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
			// Tools and resources counted APART. A document an assistant attaches
			// is not a tool it can run, MCP models them as two lists, and lumping
			// them made every "tools" number on screen overstate itself — the
			// Agentimus group read "25 tools" for 21 tools and 4 documents.
			'tools'        => count( self::of_kind( $resource['tools'], 'tool' ) ),
			// The split an owner needs before deciding: how many of these only
			// look at something, and how many change it. Counted from each tool's
			// own read-only hint, never from its name.
			'reads'        => self::count_kind( $resource['tools'], true ),
			'changes'      => self::count_kind( $resource['tools'], false ),
			'docs'         => count( self::of_kind( $resource['tools'], 'resource' ) ),
			// The names behind those counts. "25 tools" with nothing to open was a
			// dead end: the card counted 42 and listed only the 7 published ones,
			// so the other 35 existed nowhere an owner could look. Name, title and
			// which of the two it is — the published list below carries the detail.
			'toolList'     => array_values(
				array_map(
					static function ( $tool ) {
						return array(
							'name'  => isset( $tool['name'] ) ? (string) $tool['name'] : '',
							'title' => isset( $tool['title'] ) ? (string) $tool['title'] : '',
							'kind'  => ( isset( $tool['kind'] ) && 'resource' === $tool['kind'] ) ? 'resource' : 'tool',
							// Resources carry their public address so the admin can link them.
							'uri'   => isset( $tool['uri'] ) ? (string) $tool['uri'] : '',
						);
					},
					(array) $resource['tools']
				)
			),
			'abilities'    => $resource['abilities'],
		);
	}

	/**
	 * The Abilities API's REST listing URL — '' when the API isn't on this install,
	 * so the admin never renders a dead endpoint.
	 *
	 * @return string
	 */
	private static function abilities_endpoint() {
		foreach ( self::adapters() as $adapter ) {
			if ( isset( $adapter['id'] ) && 'wp-abilities' === $adapter['id'] && ! empty( $adapter['available'] ) ) {
				return esc_url_raw( rest_url( 'wp-abilities/v1/abilities' ) );
			}
		}
		return '';
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
