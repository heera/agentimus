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
			// The daily "do these doors really open" run, so the owner can see it is
			// happening — and, when it is not, that the addresses below are
			// unverified rather than verified.
			'reachability' => self::reachability(),
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
	 * The state of the daily check itself.
	 *
	 * @return array{checkedAt:int,error:string,skipped:int,stale:bool,checked:int,refused:int,unknown:int}
	 */
	private static function reachability() {
		$data  = Reachability::data();
		$rows  = (array) $data['addresses'];
		$count = static function ( $state ) use ( $rows ) {
			return count(
				array_filter(
					$rows,
					static function ( $r ) use ( $state ) {
						return isset( $r['state'] ) && $state === $r['state'];
					}
				)
			);
		};

		return array(
			'checkedAt' => (int) $data['checked_at'],
			'error'     => (string) $data['error'],
			'skipped'   => (int) $data['skipped'],
			'stale'     => Reachability::is_stale(),
			'checked'   => count( $rows ),
			'open'      => $count( 'public' ),
			'refused'   => $count( 'refused' ),
			'unknown'   => $count( 'unknown' ),
		);
	}

	/**
	 * One declared address, as something the owner can actually open.
	 *
	 * ⭐ RESOLVED THE WAY THE BACKGROUND CHECK RESOLVES IT — `home_url()` on the
	 * registered path, the same call Reachability::ask_over_http() makes — so the
	 * address the owner clicks is byte-for-byte the address we probed and reported
	 * on. Two surfaces describing one address may not compute it two ways.
	 *
	 * ⚠️ In practice the Envelope has already absolutized the address before the
	 * Hub reads it, so the site-relative branch below rarely fires on a registered
	 * provider. It is a floor, not the mechanism: this method's real job is to
	 * answer "is this openable at all?" with an address or with ''.
	 *
	 * ⛔ An already-absolute address is passed through untouched, including one
	 * pointing off this site: that is somebody else's server, we never check it,
	 * and rewriting it here would be inventing a claim about a host we know
	 * nothing about.
	 *
	 * ⛔ Anything else returns '' rather than a guess. The panel renders a row
	 * with no resolved address as plain text — which is what it did for every row
	 * before this existed, so an address we cannot resolve is no worse off.
	 *
	 * @param string $url The address as registered: site-relative or absolute.
	 * @return string An absolute address, or '' when it cannot be resolved.
	 */
	private static function openable( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			return esc_url_raw( home_url( $url ) );
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return esc_url_raw( $url );
		}
		return '';
	}

	/**
	 * What we know about whether one advertised address really opens for a
	 * stranger — and, when it does not, the sentence the owner needs.
	 *
	 * ⭐ THE POINT OF THE `label`. An address that fails its check leaves the
	 * served document; if the admin screen said nothing, the owner would see a
	 * plugin they installed simply not appear and have no way to find out why.
	 * ⛔ A silence must name itself.
	 *
	 * @param array $endpoint One endpoint.
	 * @param array $resource Its resource.
	 * @return array{state:string,why:string,label:string,published:bool,checkedAt:int}
	 */
	private static function openness( $endpoint, array $resource ) {
		$url = isset( $endpoint['url'] ) ? (string) $endpoint['url'] : '';

		// We are not claiming this one is open, so there is nothing to prove and
		// nothing to say.
		if ( ! Reachability::claims_to_be_open( $endpoint, $resource ) ) {
			return array( 'state' => 'not-claimed', 'why' => '', 'label' => '', 'published' => true, 'checkedAt' => 0 );
		}

		$record = Reachability::record( $url );
		if ( null === $record ) {
			return array(
				'state'     => 'unchecked',
				'why'       => '',
				'label'     => __( 'Not checked yet. It is published meanwhile — not having looked is not a reason to hide it.', 'agentimus' ),
				'published' => true,
				'checkedAt' => 0,
			);
		}

		$sentences = array(
			'declared-open'               => __( 'Open to anyone — the plugin registered this route as needing no sign-in.', 'agentimus' ),
			'answered'                    => __( 'Open to anyone — a real request with no login got through.', 'agentimus' ),
			'allowed-a-stranger'          => __( 'Open to anyone — the plugin’s own permission check let a stranger in.', 'agentimus' ),
			'refused-a-stranger'          => __( 'A request with no login is refused here.', 'agentimus' ),
			'gone'                        => __( 'Nothing answers at this address any more.', 'agentimus' ),
			'no-route'                    => __( 'This site has nothing registered at this address.', 'agentimus' ),
			'could-not-reach'             => __( 'The check could not reach this address at all.', 'agentimus' ),
			'no-permission-check'         => __( 'This route has no permission check, so nobody can say who may read it.', 'agentimus' ),
			'unreadable-permission-check' => __( 'This route’s permission check cannot be run, so it cannot be trusted.', 'agentimus' ),
			'permission-check-errored'    => __( 'The plugin’s own permission check errored when it was asked.', 'agentimus' ),
		);

		/**
		 * ⭐ WHAT IT MEANS FOR THE OWNER — the half the verdict was missing.
		 * "Nothing answers at this address any more" states a finding and leaves a
		 * person holding it: is my site broken, is this my job to fix, does it come
		 * back? Every one of those has an answer, and all three fit in a sentence.
		 * ⭐ The last clause matters most: this heals itself. Nobody has to come back
		 * and press anything.
		 */
		// ⭐ PLAIN WORDS, SHORT SENTENCES. These were written in idiom — "puts it
		// back by itself", "we stopped pointing assistants at it", "a door that
		// turns them away" — which a reader whose English is a second language has
		// to decode before they can act. Same facts, said directly, and every one
		// still ends with what happens next.
		$means = array(
			'refused-a-stranger'          => __( 'That is correct if this content is private. We do not list it, so assistants are not sent to a page that will refuse them. If it opens to visitors later, the next daily check lists it again.', 'agentimus' ),
			'gone'                        => __( 'Nothing on your site is broken. The plugin still offers this address, but it does not answer, so we do not send assistants to it. If it answers again, the next daily check lists it again.', 'agentimus' ),
			'no-route'                    => __( 'Nothing on your site is broken. The plugin offers this address but never registered it, so there is nothing there to read. If that changes, the next daily check lists it again.', 'agentimus' ),
			'could-not-reach'             => __( 'This may be a problem with the check, not with the address: your site could not be reached at all. We try again every day.', 'agentimus' ),
			'no-permission-check'         => __( 'We do not list it, because we cannot tell who is allowed to read it. The plugin author needs to fix this. We check again every day.', 'agentimus' ),
			'unreadable-permission-check' => __( 'We do not list it, because we cannot tell who is allowed to read it. The plugin author needs to fix this. We check again every day.', 'agentimus' ),
			'permission-check-errored'    => __( 'We do not list it, because we cannot tell who is allowed to read it. The plugin author needs to fix this. We check again every day.', 'agentimus' ),
		);

		$why = isset( $record['why'] ) ? (string) $record['why'] : '';

		return array(
			'state'     => isset( $record['state'] ) ? (string) $record['state'] : 'unknown',
			'why'       => $why,
			'label'     => isset( $sentences[ $why ] ) ? $sentences[ $why ] : __( 'This address could not be shown to be readable without a sign-in.', 'agentimus' ),
			'means'     => isset( $means[ $why ] ) ? $means[ $why ] : '',
			// The status a real anonymous request came back with, when there was one:
			// the difference between an opinion and a measurement.
			'code'      => isset( $record['code'] ) ? (int) $record['code'] : 0,
			'published' => Reachability::may_advertise( $url ),
			'checkedAt' => isset( $record['checked_at'] ) ? (int) $record['checked_at'] : 0,
		);
	}

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
		foreach ( \Agentimus\Integrations\Plugins\Provider::ROSTER as $class ) {
			$ids[] = (string) $class::ID;
		}
		return $ids;
	}

	/**
	 * How many runnable tools only read, or change something.
	 *
	 * ⚠️⚠️ TWO DIFFERENT QUESTIONS SHARE THIS METHOD, and answering both with one
	 * number put a visible lie on the screen: a group of 4 jobs read
	 * "4 jobs · 1 only read, 1 change something", because only 2 of the 4 were
	 * announced and the counts were announced-only while the total was not.
	 *
	 * ⭐ WHICH ONE YOU WANT DEPENDS ON WHAT THE NUMBER SITS BESIDE:
	 *   • $announced_only TRUE — beside the "never publish jobs that change
	 *     something" switch. That switch can only ever un-announce something
	 *     already announced, so counting the rest made it describe work it would
	 *     not do ("13 jobs would stop being announced" when the answer was six).
	 *   • $announced_only FALSE — beside a group's own total, where the reader is
	 *     being told what is IN the group. Every job in it counts, announced or
	 *     not, or the parts do not add up to the whole three words earlier.
	 *
	 * @param array $tools          A resource's tools.
	 * @param bool  $read_only      Count the reading ones, or the changing ones.
	 * @param bool  $announced_only Only tools the vendor asked to have listed.
	 * @return int
	 */
	private static function count_kind( $tools, $read_only, $announced_only = true ) {
		$n = 0;
		foreach ( self::of_kind( (array) $tools, 'tool' ) as $tool ) {
			if ( $announced_only && isset( $tool['public'] ) && ! $tool['public'] ) {
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
			// Agentimus's OWN surface, told apart from everyone else's — his call,
			// 2026-08-15: we never mix ourselves in with the plugins we describe.
			// Read from the abilities namespace we register under, not a literal,
			// so renaming it cannot leave this behind.
			'own'          => ( 'abilities-' . \Agentimus\Abilities\Registrar::CATEGORY ) === (string) $resource['id'],
			// The site's OWN content. It has no switch here on purpose — Content
			// types steers it — so the row says where it is steered from instead
			// of leaving an absence for the owner to puzzle over.
			'siteContent'  => 'wordpress-core' === (string) $resource['id'],
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
				static function ( $endpoint ) use ( $resource ) {
					return array(
						'url'  => $endpoint['url'],
						// The same address as something the panel can offer as a link
						// (his ask, 2026-08-22).
						// ⚠️ NOT a second resolution of `url`: the Envelope has already
						// absolutized every resource by the time we read it, with the same
						// home_url() call, so on a registered provider these two normally
						// hold the identical string. What this field adds is the GUARANTEE
						// — it is either an openable http(s) address or '', and the panel
						// renders plain text on ''. Linking `url` directly would hand an
						// href to whatever Resource::url() let through, a site-relative
						// path or an unresolvable scheme included.
						'href' => self::openable( $endpoint['url'] ),
						'type' => $endpoint['type'],
						'auth' => '' !== $endpoint['auth'] ? $endpoint['auth'] : 'none',
						// ⭐ Why an address the owner can see here may not be in the
						// served document. An address dropped for failing its check
						// would otherwise just be missing, and a silence the owner
						// cannot account for is worse than the wrong row.
						'open' => self::openness( $endpoint, $resource ),
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
			// ⭐ ALL of them, so `reads + changes === tools` on every row. The row's
			// own line puts the three numbers in one sentence, and two of them
			// counting a smaller set than the third printed "4 jobs · 1 only read,
			// 1 change something".
			'reads'        => self::count_kind( $resource['tools'], true, false ),
			'changes'      => self::count_kind( $resource['tools'], false, false ),
			// ⚠️ ANNOUNCED ONLY, and kept apart on purpose: this one sits beside the
			// "never publish jobs that change something" switch, which can only
			// un-announce what is announced.
			'announcedChanges' => self::count_kind( $resource['tools'], false, true ),
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
							// ⭐ Per job, not just per group. The row already counts how many
							// change something; now that the jobs are listed ON that row, the
							// owner can see WHICH ones — the one question they weigh before
							// announcing a group. Read from the tool's own hint, the same
							// source the counts beside it come from, so a row and its total
							// can never disagree.
							// ⚠️ A MISSING hint means "changes", exactly as count_kind() reads
							// it two methods up. Defaulting the other way would make one job
							// count as a read in the total and print as a change on the row.
							'reads' => isset( $tool['annotations']['readOnlyHint'] ) && (bool) $tool['annotations']['readOnlyHint'],
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
