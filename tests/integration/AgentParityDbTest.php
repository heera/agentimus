<?php
/**
 * ⭐ Every admin route says what an agent gets instead: a tool, or a written reason.
 *
 * The owner's goal is that an agent can do what the owner does from the screens. A
 * new route with neither fails here, so a gap is a decision somebody wrote down and
 * never something nobody noticed.
 *
 * A reason starts with why:
 *   secret:   reads or creates keys, tokens, app passwords or OAuth grants.
 *   switches: changes what agents may do. An agent never reaches these.
 *   logs:     clears a record the owner relies on.
 *   person:   needs a human, such as an OAuth return or a sheet shared first.
 *   later:    waits for the approval queue (stage 3): a dialog, money or a public post.
 *   screen:   only the admin screens need it.
 *   public:   open to anyone, so a tool would add nothing.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\Registrar;
use Agentimus\Settings;

final class AgentParityDbTest extends DbTestCase {

	const WHY = array( 'secret', 'switches', 'logs', 'person', 'later', 'screen', 'public' );

	/** Keyed "READ|WRITE|DELETE route". POST, PUT and PATCH on a route are one decision. */
	const ROUTES = array(
		'READ /activity'                      => 'read-clients',
		'DELETE /activity'                    => 'logs: clears the visit log, flagged IPs and review state.',
		'READ /activity/ai-day'               => 'read-ai-traffic',
		'READ /activity/ai-traffic'           => 'read-ai-traffic',
		'READ /activity/ai-traffic/facets'    => 'screen: filter options for a dropdown.',
		'READ /activity/clients'              => 'read-clients',
		'READ /activity/day'                  => 'read-request-log',
		'READ /activity/log'                  => 'read-request-log',
		'READ /activity/log/facets'           => 'screen: filter options for a dropdown.',
		'WRITE /activity/allow'               => 'review-client',
		'WRITE /activity/block'               => 'review-client',
		'WRITE /activity/check-ip'            => 'identify-bot',
		'WRITE /activity/client-remove'       => 'review-client',
		'WRITE /activity/dismiss'             => 'review-client',
		'WRITE /activity/reverify'            => 'recheck-client',
		'WRITE /activity/selfcheck-token'     => 'secret: mints a token.',
		'WRITE /activity/undismiss'           => 'review-client',
		'READ /agent-access'                  => 'read-agent-access',
		'DELETE /agent-access'                => 'logs: empties the record of what agents did.',
		'WRITE /agent-access/seen'            => 'screen: clears an unread count.',
		'WRITE /ai-hit'                       => 'public: the referral beacon visitors\' browsers send.',
		'READ /announcements'                 => 'read-announcements',
		'WRITE /announcements'                => 'retry-announcement',
		'WRITE /assistant/compose'            => 'later: spends the site\'s AI budget.',
		'WRITE /assistant/compose-meta'       => 'later: spends the site\'s AI budget.',
		'WRITE /assistant/compose-section'    => 'later: spends the site\'s AI budget.',
		'WRITE /assistant/create'             => 'create-content',
		'WRITE /assistant/generate-image'     => 'later: spends the site\'s AI budget.',
		'WRITE /assistant/outline'            => 'later: spends the site\'s AI budget.',
		'WRITE /assistant/plan'               => 'later: spends the site\'s AI budget.',
		'READ /assistant/posts'               => 'screen: the writing assistant\'s own list.',
		'WRITE /assistant/refine'             => 'later: spends the site\'s AI budget.',
		'WRITE /assistant/revise-block'       => 'later: spends the site\'s AI budget.',
		'READ /assistant/state'               => 'screen: the writing assistant\'s own state.',
		'READ /bing'                          => 'read-search-visibility',
		'WRITE /bing'                         => 'secret: stores the Bing key.',
		'DELETE /bing'                        => 'secret: forgets the Bing key.',
		'WRITE /bing/code'                    => 'person: the owner pastes it from Bing.',
		'WRITE /bing/refresh'                 => 'refresh-data',
		'READ /bing/summary'                  => 'read-search-visibility',
		'WRITE /bing/url-check'               => 'later: spends Bing\'s API quota, like Google\'s check.',
		'READ /changelog'                     => 'public: the release notes on WordPress.org.',
		'READ /cloudflare'                    => 'read-edge-traffic',
		'WRITE /cloudflare'                   => 'secret: stores the Cloudflare token.',
		'DELETE /cloudflare'                  => 'secret: forgets the Cloudflare token.',
		'WRITE /cloudflare/dismiss'           => 'screen: hides a notice.',
		'DELETE /cloudflare/dismiss'          => 'screen: brings back a hidden notice.',
		'WRITE /cloudflare/edge-block'        => 'later: agents block locally until the Cloudflare rule has proved itself.',
		'READ /cloudflare/edge-block'         => 'later: agents block locally until the Cloudflare rule has proved itself.',
		'WRITE /cloudflare/edge-block/bulk'   => 'later: agents block locally until the Cloudflare rule has proved itself.',
		'READ /cloudflare/edge-block/preview' => 'screen: the owner\'s check before a Cloudflare block; agents block locally only.',
		'WRITE /cloudflare/purge'             => 'purge-edge-cache',
		'WRITE /cloudflare/refresh'           => 'refresh-data',
		'READ /cloudflare/summary'            => 'read-edge-traffic',
		'WRITE /cron/run'                     => 'screen: the admin page nudging overdue jobs.',
		'WRITE /digest/test'                  => 'screen: the owner\'s own test email, ruled out 10-31.',
		'READ /discovery'                     => 'public: the discovery document itself.',
		'READ /discovery/hub'                 => 'screen: the Discovery screen\'s layout of public documents.',
		'READ /findings'                      => 'read-findings',
		'WRITE /findings/seen'                => 'screen: retires notices the owner has read.',
		'WRITE /focus/check'                  => 'check-focus',
		'READ /google'                        => 'read-google-index',
		'WRITE /google'                       => 'secret: stores the service-account key.',
		'DELETE /google'                      => 'secret: forgets the service-account key.',
		'WRITE /google/analytics'             => 'person: access has to be granted in GA4 first.',
		'DELETE /google/analytics'            => 'later: the screen confirms it, and it deletes stored numbers.',
		'WRITE /google/analytics/refresh'     => 'refresh-data',
		'READ /google/index'                  => 'read-google-index',
		'WRITE /google/index'                 => 'later: spends the 2,000-a-day inspection quota.',
		'WRITE /google/index/cancel'          => 'later: only useful once an agent can start a sweep.',
		'WRITE /google/index/check'           => 'later: spends the inspection quota.',
		'READ /google/index/lookup'           => 'read-google-index',
		'WRITE /google/index/opened'          => 'screen: notes the owner opened a row in Search Console.',
		'READ /google/index/problems'         => 'read-google-index',
		'WRITE /google/refresh'               => 'refresh-data',
		'READ /integrations'                  => 'read-integrations',
		'WRITE /integrations'                 => 'secret: webhook URLs and tokens are the power to post. Public posts wait for stage 3.',
		'WRITE /internal-links/suggest'       => 'suggest-internal-links',
		'READ /linkedin/callback'             => 'person: LinkedIn\'s sign-in return.',
		'READ /mcp-status'                    => 'screen: the MCP card\'s own status line.',
		'READ /mcp-token'                     => 'secret: the connection token.',
		'WRITE /mcp-token'                    => 'secret: creates the connection token.',
		'DELETE /mcp-token'                   => 'secret: revokes the connection token.',
		'WRITE /nextsteps-seen'               => 'screen: hides a card.',
		'READ /oauth/grants'                  => 'secret: lists who holds access.',
		'DELETE /oauth/grants'                => 'secret: cuts off an assistant.',
		'WRITE /oauth/register'               => 'public: OAuth client registration.',
		'WRITE /oauth/token'                  => 'public: OAuth\'s code exchange.',
		'WRITE /onboarding'                   => 'person: the owner\'s setup wizard.',
		'WRITE /optimize/ignore'              => 'set-aside-page',
		'WRITE /optimize/ignore-issue'        => 'later: the screen confirms a bulk set-aside.',
		'WRITE /optimize/restore-all'         => 'later: the screen confirms emptying the list.',
		'READ /page-check'                    => 'check-page',
		'READ /post-traffic'                  => 'read-post-traffic',
		'READ /preview/markdown'              => 'preview-markdown',
		'READ /preview/schema'                => 'preview-schema',
		'READ /preview/targets'               => 'screen: the preview screen\'s page picker.',
		'READ /readiness'                     => 'read-readiness',
		'READ /report'                        => 'screen: a summary of what the read tools already return.',
		'WRITE /review-ack'                   => 'person: only the owner can answer the review ask.',
		'READ /score'                         => 'read-readiness',
		'WRITE /search/dismiss'               => 'dismiss-search',
		'WRITE /search/ignore'                => 'set-aside-search-page',
		'READ /search/opportunities'          => 'read-search-opportunities',
		'READ /search/performance'            => 'read-search-performance',
		'READ /settings'                      => 'read-settings',
		'WRITE /settings'                     => 'update-settings',
		'WRITE /settings/reset'               => 'switches: a factory reset includes the agent switches.',
		'WRITE /share-copy/generate'          => 'later: spends the site\'s AI budget.',
		'WRITE /suggest'                      => 'later: spends the site\'s AI budget.',
		'WRITE /suggest-fix'                  => 'later: spends the site\'s AI budget.',
		'READ /validate'                      => 'screen: the owner\'s re-check of public documents.',
		'WRITE /verifier/probe-ranges'        => 'screen: tests a URL the owner is typing.',
		'READ /visibility/answer/(?P<id>\d+)' => 'screen: opens one stored answer. read-ai-visibility holds the results.',
		'WRITE /visibility/clear'             => 'logs: deletes all citation history.',
		'READ /visibility/config'             => 'read-citation-products',
		'WRITE /visibility/config'            => 'save-citation-product',
		'READ /visibility/dashboard'          => 'read-ai-visibility',
		'WRITE /visibility/reveal-key'        => 'secret: returns a provider key.',
		'WRITE /visibility/run'               => 'later: spends the owner\'s AI credit.',
		'WRITE /visibility/suggest'           => 'suggest-citation-questions',
		'WRITE /visibility/suggest-ai'        => 'later: spends the site\'s AI budget.',
		'WRITE /visibility/test'              => 'secret: tests a pasted or stored key.',
		'WRITE /whatsnew-seen'                => 'screen: hides a card.',
		'WRITE /worklist/changed'             => 'read-content-issues',
		'READ /worklist'                      => 'read-content-issues',
		'READ /worklist/rows'                 => 'read-content-issues',
		'READ /x/callback'                    => 'person: X\'s sign-in return.',
	);

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+): agents have no tools below that.' );
		}
	}

	/** @return string[] The plugin's routes as the server holds them, in ROUTES' form. */
	private function registered() {
		$out = array();
		foreach ( rest_get_server()->get_routes( 'agentimus/v1' ) as $route => $handlers ) {
			$path = substr( $route, strlen( '/agentimus/v1' ) );
			// The namespace index, and the MCP server itself when it is on.
			if ( '' === $path || '/mcp' === $path ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( array_filter( (array) $handler['methods'] ) ) as $method ) {
					$group = array(
						'GET'    => 'READ',
						'DELETE' => 'DELETE',
					);
					$out[] = ( isset( $group[ $method ] ) ? $group[ $method ] : 'WRITE' ) . ' ' . $path;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return string[] Every tool an agent could have, with all the owner's switches on. */
	private function tools() {
		$settings = new Settings();
		$all      = $settings->stored();
		foreach ( array( 'enable_mcp_server', 'enable_agent_writes', 'agent_writes_settings', 'agent_writes_maintenance' ) as $key ) {
			$all[ $key ] = true;
		}
		$settings->update( $all );

		return array_map(
			static function ( $name ) {
				return substr( $name, strlen( 'agentimus/' ) );
			},
			( new Registrar( $settings ) )->mcp_abilities()
		);
	}

	public function test_every_route_names_a_tool_or_a_reason() {
		$this->assertSame(
			array(),
			array_values( array_diff( $this->registered(), array_keys( self::ROUTES ) ) ),
			'These routes have no decision. Give each a tool, or a written reason, in ROUTES.'
		);
	}

	public function test_no_decision_outlives_its_route() {
		$this->assertSame(
			array(),
			array_values( array_diff( array_keys( self::ROUTES ), $this->registered() ) ),
			'These routes are gone. Remove them from ROUTES.'
		);
	}

	public function test_every_decision_is_a_real_tool_or_a_known_reason() {
		$tools = $this->tools();
		$bad   = array();
		foreach ( self::ROUTES as $route => $decision ) {
			if ( in_array( $decision, $tools, true ) ) {
				continue;
			}
			$parts = explode( ': ', $decision, 2 );
			if ( 2 !== count( $parts ) || ! in_array( $parts[0], self::WHY, true ) || '' === trim( $parts[1] ) ) {
				$bad[] = "$route => $decision";
			}
		}
		$this->assertSame( array(), $bad, 'Each of these names no tool that exists, and no reason.' );
	}

	/** ⛔ A secret, a switch or a log must never be written down as having a tool. */
	public function test_the_routes_agents_may_never_reach_stay_out() {
		foreach ( array( 'WRITE /mcp-token', 'READ /mcp-token', 'DELETE /oauth/grants', 'WRITE /visibility/reveal-key', 'WRITE /settings/reset', 'DELETE /activity', 'DELETE /agent-access', 'WRITE /visibility/clear' ) as $route ) {
			$this->assertNotContains( self::ROUTES[ $route ], $this->tools(), "$route must have no tool." );
		}
	}
}
