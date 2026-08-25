<?php
/**
 * WordPress Abilities API — Agentimus's OWN abilities.
 *
 * The companion to {@see \Agentimus\Discovery\Adapters\AbilitiesApi}: that adapter
 * READS the abilities registry to advertise every plugin's tools in our discovery
 * envelope; this class WRITES Agentimus's own read/query capabilities into it. Once
 * registered, each ability is reachable — with the same permission checks the plugin's
 * REST routes already enforce — from four surfaces at once:
 *
 *   • the in-admin AI assistant (WP 6.9+),
 *   • client-side JS (`@wordpress/core-abilities`),
 *   • the REST API (`meta.show_in_rest`),
 *   • external AI agents, via the MCP Adapter (see {@see register_mcp_server()}).
 *
 * TWO TIERS, two switches:
 *
 *   READ (always registered on a capable core): each ability wraps a service method
 *   that already returns a structured array (see {@see Score}, {@see Readiness},
 *   {@see Activity\Referrals}, {@see Activity\Repository}, {@see BotVerifier},
 *   {@see PageCheck}, {@see Schema}, {@see Markdown}, {@see Exposure}) and mutates
 *   nothing. External MCP exposure is gated by `enable_mcp_server`.
 *
 *   WRITE (registered ONLY when the owner flips `enable_agent_writes`): create/update
 *   posts and pages, set a page's AI description/topics, and apply a readiness fix
 *   (see {@see ContentWriter}, {@see Fixer}). Off means the write abilities do not
 *   exist on ANY surface — not the MCP server, not the abilities REST API, not the
 *   in-admin assistant — so "read-only" stays literally true until the owner says
 *   otherwise. Going live (status=publish) sits behind a third switch on top
 *   (`agent_writes_publish`). Abilities that spend money (run a visibility check)
 *   remain deliberately unregistered.
 *
 * The whole class no-ops on cores without the Abilities API, so the plugin's 6.3+
 * baseline is unaffected.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Settings;
use Agentimus\Guidelines;
use Agentimus\Assistant;
use Agentimus\Readiness;
use Agentimus\Score;
use Agentimus\Findings;
use Agentimus\Audience;
use Agentimus\Content;
use Agentimus\Media;
use Agentimus\LlmsText;
use Agentimus\Discovery\Envelope;
use Agentimus\Discovery\Registry;
use Agentimus\PageCheck;
use Agentimus\InternalLinks;
use Agentimus\Schema;
use Agentimus\Markdown;
use Agentimus\Exposure;
use Agentimus\Search\Report as SearchReport;
use Agentimus\BotVerifier;
use Agentimus\Activity\Referrals;
use Agentimus\Activity\Repository;
use Agentimus\Activity\Review;
use Agentimus\Activity\Catalog;
use Agentimus\Guard;
use Agentimus\VerifierRegistry;
use Agentimus\AgentAccess\Module as AgentAccess;
use Agentimus\AgentAccess\Store as AgentAccessStore;
use Agentimus\AgentAccess\Events;
use Agentimus\McpToken;
use Agentimus\Oauth;
use Agentimus\Visibility\Store as VisibilityStore;
use Agentimus\Visibility\Settings as VisibilitySettings;
use Agentimus\Cloudflare\Settings as CloudflareSettings;
use Agentimus\Cloudflare\Summary as CloudflareSummary;
use Agentimus\Bing\Settings as BingSettings;
use Agentimus\Bing\Summary as BingSummary;
use Agentimus\Google\Settings as GoogleSettings;
use Agentimus\Google\Index as GoogleIndex;
use Agentimus\Worklist;
use Agentimus\Seo;

defined( 'ABSPATH' ) || exit;

final class Registrar {

	/** The ability category all of Agentimus's abilities live under. */
	const CATEGORY = 'agentimus';

	/**
	 * The write tier's ability slugs — the ONE list both registration
	 * ({@see register_write_abilities()}) and MCP exposure ({@see mcp_abilities()})
	 * must agree on. AgentWritesGateTest re-declares these as literals on purpose,
	 * so renaming one here still fails a test instead of silently changing the
	 * public tool name.
	 */
	const WRITE_SLUGS = array( 'create-content', 'update-content', 'edit-content', 'describe-image', 'describe-content-image', 'write-description', 'write-topics', 'write-search-fields', 'apply-fix', 'set-aside-page', 'retry-announcement', 'review-client', 'recheck-client' );

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Shared settings store (passed the same way every module gets it).
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook registration. Availability is checked at fire-time (the functions below are
	 * WP 6.9+), so this is safe to call unconditionally from Plugin::boot().
	 */
	public function register() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		// Expose the read set to external AI agents when the MCP Adapter is installed.
		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ) );
		// Hide write tools from a read-scoped caller. The server is registered before
		// the request is authenticated, so the tool list can only be narrowed HERE,
		// per request, once we know which key knocked.
		add_filter( 'mcp_adapter_tools_list', array( $this, 'filter_tools_for_scope' ), 10, 1 );
		// The adapter's initialize advertises resources + prompts capabilities
		// unconditionally, whatever the server actually holds. A capability
		// advertised and then empty reads as broken to clients (and to scanners,
		// which score "advertises resources but resources/list has none" as a
		// failure while a tool-only server scores n/a) — so the handshake tells
		// the truth about THIS server, in both directions: prompts always go
		// (we register none), resources go only when there are none to offer.
		// The both-directions part is not pedantry. A client reads capabilities
		// to decide what to ASK for: a server that registers resources and then
		// says it has none is never sent resources/list, and its documents are
		// unreachable no matter how correctly they were registered.
		add_filter( 'mcp_adapter_initialize_response', array( $this, 'trim_initialize_capabilities' ), 10, 2 );
	}

	/**
	 * Make OUR server's advertised capabilities match what it actually holds.
	 * Prompts are never registered, so that capability always goes; resources
	 * go only when this site is offering none. Other servers on the same
	 * adapter are left alone.
	 *
	 * @param object $result The InitializeResult DTO.
	 * @param object $server The MCP server answering.
	 * @return object
	 */
	public function trim_initialize_capabilities( $result, $server ) {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) || 'agentimus' !== (string) $server->get_server_id() ) {
			return $result;
		}
		try {
			$data = $result->toArray();
			unset( $data['capabilities']['prompts'] );
			if ( ! $this->mcp_resources() ) {
				unset( $data['capabilities']['resources'] );
			}
			$class = get_class( $result );
			return $class::fromArray( $data );
		} catch ( \Throwable $e ) {
			return $result; // Adapter DTO drift → the stock answer, never a broken handshake.
		}
	}

	/**
	 * Narrow the advertised tool list to what THIS request may actually run.
	 *
	 * The permission gate already refuses a write from a read-only key, so this
	 * changes no security boundary — it changes what the assistant is told. A
	 * read-only Claude that can see "Create a post" will eventually try it and
	 * collect a "Permission denied"; showing it nine honest tools instead of
	 * fourteen half-open ones is the difference between a scope the owner chose
	 * and a scope the assistant discovers by bumping into walls.
	 *
	 * @param array $tools Tool DTOs the adapter is about to return.
	 * @return array
	 */
	public function filter_tools_for_scope( $tools ) {
		if ( McpToken::request_allows_writes() && Oauth\Server::request_allows_writes() ) {
			return $tools;
		}
		return array_values(
			array_filter(
				(array) $tools,
				static function ( $tool ) {
					if ( ! is_object( $tool ) || ! method_exists( $tool, 'getName' ) ) {
						return true; // Unknown shape — never drop it on a guess.
					}
					return ! self::is_write_tool_name( $tool->getName() );
				}
			)
		);
	}

	/**
	 * Whether a wire-shape tool name is one of OUR write tools — the single
	 * source of truth for every surface that narrows a list to the read tier
	 * (the per-request scope filter above, the anonymous read-surface).
	 *
	 * @param string $name Tool name as it reaches the wire.
	 * @return bool
	 */
	public static function is_write_tool_name( $name ) {
		$name = (string) $name;
		foreach ( self::WRITE_SLUGS as $slug ) {
			// Tool names reach the wire with the category separator normalised.
			if ( self::CATEGORY . '/' . $slug === $name || self::CATEGORY . '-' . $slug === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The single category the admin AI groups our abilities under.
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				// ⭐ His call, 2026-08-16: just the name. It is the heading over our own
				// group of jobs on the Discovery page, beside a badge that already says
				// Agentimus — "Agentimus — AI visibility" there said it twice and read
				// long next to every vendor's short name. The MCP SERVER name keeps the
				// longer form: that one appears in an assistant's list of servers, where
				// a plugin name alone says nothing about what it offers.
				'label'       => __( 'Agentimus', 'agentimus' ),
				'description' => __( 'Read — and, when the owner allows writes, improve — this site’s AI/agent readiness, traffic, bot activity, per-page readability and discovery output.', 'agentimus' ),
			)
		);
	}

	/**
	 * Register every read ability.
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$manage = array( $this, 'can_manage' );

		/* -- Site-level diagnostics ------------------------------------------ */

		$this->add(
			'read-readiness',
			__( 'Get AI readiness & AEO/GEO score', 'agentimus' ),
			'Returns the site’s AI-visibility health: the blended 0–100 AEO/GEO score with its band '
				. '(Findable, Readable, Trusted, Optimized, Cited pillars), the impact-ranked "do this next" '
				. 'action plan, AND the full list of readiness checks with their pass/warn/fail status and the '
				. 'fix for each ("off" = the feature the check measures is switched off; informational only, '
				. 'excluded from the score). Use this to answer "how ready is my site to be found and cited '
				. 'by AI, and what should I fix first?".',
			self::no_input(),
			self::obj(
				array(
					'score'  => self::score_schema(),
					'checks' => self::status_rows_schema( true ),
				)
			),
			function () {
				$readiness = ( new Readiness( $this->settings ) )->report();
				$score     = ( new Score( $this->settings ) )->report( $readiness );
				return array(
					'score'  => $score,
					'checks' => $readiness,
				);
			},
			$manage
		);

		$this->add(
			'read-ai-visibility',
			__( 'Get AI citation results', 'agentimus' ),
			'Returns the latest citation-check run (the Visibility screen\'s Citations tab): whether AI assistants mention and cite each tracked '
				. 'product, the overall visibility score and citation rate, per-product share-of-voice against '
				. 'competitors, and the trend across recent runs. Empty (hasData=false) until a run has completed. '
				. 'Read-only; it does not start a run (running one spends the site’s AI credits).',
			self::no_input(),
			self::obj(
				array(
					'hasData'   => self::b(),
					'lastRunAt' => self::s(),
					'summary'   => self::visibility_summary_schema(),
					'products'  => self::arr(
						array(
							'name'         => self::s(),
							'domain'       => self::s(),
							'rank'         => self::i(),
							'summary'      => self::visibility_summary_schema(),
							'shareOfVoice' => self::arr( null ),
							'prompts'      => self::arr( null ),
							'paused'       => self::b(),
							'hasQuestions' => self::b(),
						)
					),
					'trend'     => self::arr(
						array(
							'runId' => self::i(),
							'at'    => self::s(),
							'score' => self::i(),
						)
					),
				)
			),
			function () {
				return VisibilityStore::dashboard( new VisibilitySettings() );
			},
			$manage
		);

		$this->add(
			'read-ai-traffic',
			__( 'Get AI referral traffic', 'agentimus' ),
			'Returns real visits this site received FROM AI assistants (ChatGPT, Perplexity, Gemini, etc.) '
				. 'over a day range: totals, the per-assistant leaderboard, the landing pages they sent readers to, '
				. 'a daily series, and a diagnostic of unrecognised referrers. Optionally filter to one assistant '
				. 'and/or a landing-path prefix.',
			self::obj(
				array(
					'from'   => self::date( 'Start date (YYYY-MM-DD). Defaults to the retention window.' ),
					'to'     => self::date( 'End date (YYYY-MM-DD). Defaults to today.' ),
					'source' => self::s( 'Narrow to one assistant, by its exact label — the labels are the ones this tool’s own bySource leaderboard returns.' ),
					'path'   => self::s( 'Narrow to a landing-path prefix, e.g. "/blog".' ),
				)
			),
			self::obj(
				array(
					'enabled'     => self::b(),
					'beacon'      => self::b(),
					'range'       => self::obj(
						array(
							'from'      => self::s(),
							'to'        => self::s(),
							'floor'     => self::s(),
							'isDefault' => self::b(),
						)
					),
					'filters'     => self::obj(
						array(
							'source' => self::s(),
							'path'   => self::s(),
						)
					),
					'sourceCount' => self::i(),
					'total'       => self::i(),
					'activeDays'  => self::i(),
					'bySource'    => self::label_hits_schema(),
					'topPages'    => self::arr(
						array(
							'path' => self::s(),
							'hits' => self::i(),
						)
					),
					'daily'       => self::arr(
						array(
							'date'     => self::s(),
							'hits'     => self::i(),
							'rowCount' => self::i(),
						)
					),
					'unknown'     => self::obj(
						array(
							'enabled' => self::b(),
							'hosts'   => self::token_hits_schema(),
							'utm'     => self::token_hits_schema(),
						)
					),
				)
			),
			function ( $input ) {
				return Referrals::report(
					array(
						'from'   => isset( $input['from'] ) ? (string) $input['from'] : '',
						'to'     => isset( $input['to'] ) ? (string) $input['to'] : '',
						'source' => isset( $input['source'] ) ? (string) $input['source'] : '',
						'path'   => isset( $input['path'] ) ? (string) $input['path'] : '',
					)
				);
			},
			$manage
		);

		$this->add(
			'read-request-log',
			__( 'Get the AI request log', 'agentimus' ),
			'Returns individual requests AI crawlers and agents made to this site’s discovery endpoints '
				. '(llms.txt, the .md twins, JSON-LD, sitemaps), newest first and cursor-paginated. Each row has '
				. 'the endpoint, the detected agent, its user-agent, the owning network, and a verdict '
				. '(0=unchecked, 1=verified real engine, 2=spoofed impersonator). Filter by agent, endpoint, '
				. 'network, verdict, or a user-agent prefix.',
			self::obj(
				array(
					'from'     => self::date( 'Start date (YYYY-MM-DD).' ),
					'to'       => self::date( 'End date (YYYY-MM-DD).' ),
					'agent'    => self::slist( 'Exact detected agent name, or a list of them (OR-ed).' ),
					'endpoint' => self::slist( 'Exact endpoint path, or a list of them (OR-ed).' ),
					'network'  => self::slist( 'Exact owning network, or a list of them (OR-ed). Only populated when "identify every bot" is on.' ),
					'verdict'  => array(
						// ⛔ NO enum: it would reject the list form. The handler keeps
						// only 0, 1, 2 and "refused" and drops anything else, so a bad
						// value narrows nothing rather than erroring.
						'type'        => array( 'integer', 'string', 'array' ),
						'items'       => array( 'type' => array( 'integer', 'string' ) ),
						'description' => '0 = unchecked, 1 = verified real engine, 2 = spoofed impersonator, '
							. 'or "refused" = turned away at the door and never served. One value or a list (OR-ed). '
							. '⚠️ "refused" is an OUTCOME, not a verdict, and lives in its own column — mixing it with '
							. 'a verdict in one list is allowed and means exactly what it reads like.',
					),
					'ua'       => self::s( 'User-agent prefix to match.' ),
					'before'   => self::i( 'Pagination cursor: return rows with id below this (use the previous page’s "cursor").' ),
					'per_page' => self::i( 'Rows per page (the store clamps this).' ),
				)
			),
			self::obj(
				array(
					'rows'          => self::arr(
						array(
							'endpoint' => self::s(),
							'agent'    => self::s(),
							'ua'       => self::s(),
							'network'  => self::s(),
							'verdict'  => self::i(),
							'signer'   => self::s( 'Web Bot Auth: who the signature proves (verdict 1) or claimed (verdict 2); empty when the verdict came from DNS/ranges or nothing.' ),
							'refused'  => self::b( 'True = turned away at the door, never served.' ),
							'at'       => self::s(),
						)
					),
					'total'         => self::i(),
					'perPage'       => self::i(),
					'cursor'        => self::nullable_int(),
					'hasMore'       => self::b(),
					'retentionDays' => self::i(),
					'autoPrune'     => self::b(),
					'maxRows'       => self::i(),
					// The two switch states the Status column leans on — declared here so the
					// ability's own output VALIDATES: the adapter rejects undeclared keys, and
					// 1.30.0 shipped exactly that bug (verifyOn arrived, the schema never did).
					'verifyOn'      => self::b( 'Whether Web Bot Auth signature verification is on.' ),
					'identifyOn'    => self::b( 'Whether "identify every bot" (network attribution) is on.' ),
					// ⛔ AND THE SORT, for the same reason — 1.40.0 shipped the same bug a
					// second time: sorting added `sort`/`offset` to the response and this
					// schema did not learn them, so the ability rejected its own output.
					// How these rows are ordered, echoed back so a caller can render its own
					// header state from the answer rather than from what it asked for.
					'sort'          => self::obj(
						array(
							'by'  => self::s( 'Which column the rows are ordered by: at, client, endpoint, ua or status.' ),
							'dir' => self::s( '"asc" or "desc".' ),
						)
					),
					'offset'        => self::i( 'Rows skipped before this page. Always 0 in the default (newest-first) order, which pages by cursor instead.' ),
				)
			),
			function ( $input ) {
				$args = array();
				// Single-value filters, unchanged.
				foreach ( array( 'from', 'to', 'ua' ) as $k ) {
					if ( isset( $input[ $k ] ) && '' !== (string) $input[ $k ] ) {
						$args[ $k ] = (string) $input[ $k ];
					}
				}
				// ⭐ ONE VALUE OR A LIST. The store reads both — a scalar becomes
				//    `col = %s`, a list becomes IN (...) — so this hands the shape
				//    straight through rather than flattening it.
				// ⛔ AN EMPTY LIST IS NOT A FILTER. `IN ()` is a MySQL syntax error,
				//    and "the caller cleared the picker" must mean no narrowing, not
				//    a 500. Dropping empties here keeps that true before the store
				//    ever sees it.
				foreach ( array( 'agent', 'endpoint', 'network' ) as $k ) {
					if ( ! isset( $input[ $k ] ) ) {
						continue;
					}
					if ( is_array( $input[ $k ] ) ) {
						$vals = array_values( array_filter( array_map( 'strval', $input[ $k ] ), 'strlen' ) );
						if ( $vals ) {
							$args[ $k ] = $vals;
						}
						continue;
					}
					if ( '' !== (string) $input[ $k ] ) {
						$args[ $k ] = (string) $input[ $k ];
					}
				}
				// ⭐ THE VERDICT IS A CLOSED SET, so it is normalised here rather than
				//    by an enum — an enum on the property would have rejected the list
				//    form outright. 0/1/2 are verdicts; "refused" is an outcome in
				//    another column, and the store OR's the two inside one group.
				// ⚠️ A scalar still arrives as a scalar, so an existing caller sending
				//    `verdict: 0` gets the identical query it always did.
				if ( isset( $input['verdict'] ) ) {
					$raw  = is_array( $input['verdict'] ) ? $input['verdict'] : array( $input['verdict'] );
					$keep = array();
					foreach ( $raw as $v ) {
						if ( 'refused' === $v ) {
							$keep[] = 'refused';
						} elseif ( is_numeric( $v ) && in_array( (int) $v, array( 0, 1, 2 ), true ) ) {
							$keep[] = (int) $v;
						}
					}
					if ( $keep ) {
						$args['verdict'] = is_array( $input['verdict'] ) ? $keep : $keep[0];
					}
				}
				if ( isset( $input['before'] ) ) {
					$args['before'] = (int) $input['before'];
				}
				if ( isset( $input['per_page'] ) ) {
					$args['per_page'] = (int) $input['per_page'];
				}
				return Repository::log( $args );
			},
			$manage
		);

		$this->add(
			'read-edge-traffic',
			__( 'Get edge traffic from Cloudflare', 'agentimus' ),
			'Returns what Cloudflare saw from AI crawlers BEFORE this server did, when the owner has '
				. 'connected a Cloudflare zone: per-crawler totals (requests at the edge, served from cache, '
				. 'reached the server, blocked at the edge, bytes out), per-company rollups, and any conflicts '
				. 'between the edge\'s observed behaviour and the site\'s declared AI policy — e.g. the edge '
				. 'blocking a crawler the policy welcomes. The request log only sees what reached the server; '
				. 'this is the missing before-the-server half. Returns connected=false when no zone is connected.',
			self::obj(
				array(
					'days' => self::i( 'Window in days, 1-30. Default 7.' ),
				)
			),
			self::obj(
				array(
					'connected'      => self::b( 'False = no Cloudflare zone is connected; the data fields are then absent.' ),
					'zoneName'       => self::s(),
					'connectedAt'    => self::i(),
					'lastPollAt'     => self::i( 'Unix time of the newest numbers; 0 = never polled.' ),
					'lastError'      => self::s( 'The most recent poll failure, empty after a clean poll.' ),
					'lastPurgeAt'    => self::i( 'Unix time of the last edge cache purge; 0 = never purged.' ),
					'lastPurgeError' => self::s( 'The most recent purge failure (e.g. the token lacks the Cache Purge permission), empty after a clean purge. Separate from lastError so healthy numbers cannot hide it.' ),
					'lastPurgeUrls'    => self::i( 'How many URLs the last purge was asked to clear; 0 = never purged, or a purge-everything, which has no list.' ),
					'lastPurgeCleared' => self::i( 'How many of them the edge confirmed it dropped. Lower than lastPurgeUrls means a PARTIAL purge — the list goes in batches and one failed partway, so the pages after it are still serving stale copies.' ),
					'days'           => self::i(),
					'totals'      => self::obj(
						array(
							'requests' => self::i(),
							'cached'   => self::i(),
							'origin'   => self::i(),
							'blocked'  => self::i(),
							'bytes'    => self::i(),
						)
					),
					'crawlers'    => self::arr(
						array(
							'ua'           => self::s( 'Stable crawler token — same vocabulary as the request log.' ),
							'name'         => self::s(),
							'operator'     => self::s( 'The company behind the crawler.' ),
							'requests'     => self::i(),
							'cached'       => self::i(),
							'origin'       => self::i( 'Requests that reached this server.' ),
							'blocked'      => self::i( 'Requests the edge turned away without contacting this server.' ),
							'bytes'        => self::i(),
							'blockedByYou' => self::b( 'Whether the owner deliberately blocks this crawler at the origin.' ),
						)
					),
					'companies'   => self::arr(
						array(
							'operator' => self::s(),
							'requests' => self::i(),
							'bytes'    => self::i(),
						)
					),
					'conflicts'   => self::arr(
						array(
							'id'    => self::s(),
							'level' => self::s( 'warn = the edge contradicts the declared policy; info = a declared preference is not enforced.' ),
							'title' => self::s(),
							'body'  => self::s(),
							'url'   => self::s( 'Cloudflare dashboard deep link where the owner can act.' ),
						)
					),
					'hiddenConflicts' => self::arr(
						array(
							'id'    => self::s(),
							'level' => self::s(),
							'title' => self::s(),
							'body'  => self::s(),
							'url'   => self::s(),
						)
					),
					'dashUrl'     => self::s(),
				)
			),
			function ( $input ) {
				$days = isset( $input['days'] ) ? (int) $input['days'] : 7;
				$days = ( $days >= 1 && $days <= 30 ) ? $days : 7;
				return CloudflareSummary::build( new CloudflareSettings(), $this->settings, $days );
			},
			$manage
		);

		$this->add(
			'read-search-visibility',
			__( 'Get AI-search visibility from Bing', 'agentimus' ),
			'Returns how much of this site sits in Bing\'s index and how cleanly Bing\'s crawler '
				. 'gets in, when the owner has connected Bing Webmaster Tools. Bing\'s index is what '
				. 'ChatGPT search reads today (Microsoft Copilot too), so this is the closest measurable '
				. 'answer to "can AI search find this site": pages in the index (daily trend), pages '
				. 'crawled, crawl errors, robots.txt blocks as Bing sees them, plus conflicts between '
				. 'Bing\'s view and the site\'s declared policy. Returns connected=false when no key is connected.',
			self::obj(
				array(
					'days' => self::i( 'Window in days, 1-90. Default 30.' ),
				)
			),
			self::obj(
				array(
					'connected'      => self::b( 'False = no Bing key is connected; the data fields are then absent.' ),
					'siteUrl'        => self::s( 'The site as Bing Webmaster Tools stores it.' ),
					'hasMsvalidate'  => self::b( 'Whether the site prints the msvalidate verification tag.' ),
					'connectedAt'    => self::i(),
					'lastPollAt'     => self::i( 'Unix time of the newest numbers; 0 = never polled.' ),
					'lastError'      => self::s( 'The most recent poll failure, empty after a clean poll.' ),
					'lastQueryError' => self::s( 'The most recent query-stats poll failure, empty after a clean run. Separate from lastError so healthy crawl numbers cannot hide it.' ),
					'days'           => self::i(),
					'totals'         => self::obj(
						array(
							'inIndex'         => self::i( 'Pages in Bing\'s index on the most recent day.' ),
							'crawledLatest'   => self::i( 'Pages Bing crawled on the most recent day.' ),
							'crawlErrors'     => self::i( '4xx + 5xx answers Bing got over the window.' ),
							'blockedByRobots' => self::i( 'Pages robots.txt closes to Bing, most recent day.' ),
						)
					),
					'trend'          => self::arr(
						array(
							'date'            => self::s( 'Y-m-d.' ),
							'reported'        => self::b( 'FALSE when Bing answered for this date without numbers. ⛔ Every count below is then 0 because Bing said nothing, NOT because the site scored zero — never read such a day as an index collapse or a crawl stopping, and never average it in. The tiles above already skip these days.' ),
							'inIndex'         => self::i(),
							'crawled'         => self::i(),
							'ok'              => self::i( '2xx answers.' ),
							'redirects'       => self::i( '301 + 302 answers.' ),
							'clientErrors'    => self::i( '4xx answers.' ),
							'serverErrors'    => self::i( '5xx answers.' ),
							'blockedByRobots' => self::i(),
						)
					),
					'conflicts'      => self::arr(
						array(
							'id'    => self::s(),
							'level' => self::s(),
							'title' => self::s(),
							'body'  => self::s(),
							'url'   => self::s( 'Where the owner can act.' ),
						)
					),
					'feeds'          => self::arr(
						array(
							'url'        => self::s( 'The sitemap as Bing Webmaster Tools stores it.' ),
							'lastReadAt' => self::s( 'Y-m-d Bing last read it, in Bing\'s own record; empty = never read.' ),
							'urls'       => self::i( 'How many URLs Bing reports the sitemap carried.' ),
						)
					),
					'feedsAt'        => self::i( 'Unix time the sitemap snapshot was fetched; 0 = not fetched yet — an empty feeds list then means "unknown", never "none registered".' ),
					'indexnow'       => self::obj(
						array(
							'enabled'   => self::b( 'Whether the owner turned IndexNow pings on (off by default).' ),
							'lastAt'    => self::i( 'Unix time of the last ping; 0 = never pinged.' ),
							'lastError' => self::s( 'The last ping failure, empty after a clean ping.' ),
							'lastUrls'  => self::i( 'How many URLs the last ping carried.' ),
						)
					),
				)
			),
			function ( $input ) {
				$days = isset( $input['days'] ) ? (int) $input['days'] : 30;
				$days = ( $days >= 1 && $days <= 90 ) ? $days : 30;
				return BingSummary::build( new BingSettings(), $this->settings, $days );
			},
			$manage
		);

		// One row shape, used for the watchlist rows AND the site-sweep problem
		// rows — a single definition so the two can never drift apart.
		$index_row = array(
			'url'              => self::s(),
			'postId'           => self::i( '0 when the URL maps to no post (the homepage, an archive).' ),
			'title'            => self::s(),
			'reason'           => self::s( 'Why this page was checked: "home", "new" (newest posts), "busy" (busiest in Google) or "site" (the whole-site rotation).' ),
			'verdict'          => self::s( '"pass" = on Google, "neutral"/"fail" = not on Google, "" = not checked yet.' ),
			'state'            => self::s( 'Google\'s own coverage sentence, e.g. "Crawled - currently not indexed".' ),
			'lastCrawl'        => self::i( 'Unix time Googlebot last visited; 0 = never.' ),
			'robotsBlocked'    => self::b( 'robots.txt closes this URL to Google.' ),
			'noindex'          => self::b( 'A noindex tag or header asks Google to skip this URL.' ),
			'fetchState'       => self::s( 'How Google\'s last fetch of the page went, e.g. SUCCESSFUL, SOFT_404, NOT_FOUND, SERVER_ERROR; empty when unknown.' ),
			'canonicalDiffers' => self::b( 'Google treats a different URL as this page\'s real address.' ),
			'googleCanonical'  => self::s( 'The canonical Google chose; empty when unknown.' ),
			'inSitemap'        => self::b( 'Whether a sitemap Google knows lists this URL — false on an unindexed page means nobody told Google it exists.' ),
			'referrers'        => self::i( 'Pages Google knows link to this URL (at least — Google caps what it reports); 0 on an unindexed page names the other discovery gap: nothing points here.' ),
			'stateKey'         => self::s( 'The problem bucket this row counts under: error | canonical | unknown | discovered | crawled | blocked | other; groups and site.problemStates share these keys.' ),
			'richIssues'       => self::i( 'Rich-result issues Google reports on this page; 0 = none.' ),
			'richTypes'        => self::s( 'Rich-result types Google detected, comma-separated; empty = none.' ),
			'gscLink'          => self::s( 'Deep link to this URL\'s inspection in Search Console — where "Request indexing" lives (that button has no API).' ),
			'inspectedAt'      => self::i( 'Unix time THIS row was last inspected — older than checkedAt means a quota-cut sweep kept its previous answer.' ),
			'openedAt'         => self::i( 'Unix time the OWNER last opened this URL in Search Console, or 0. A record of their click and nothing more: Google keeps no memory of "indexing requested" and no API exposes a pending state, so never read this as "indexing was requested" or "Google is working on it".' ),
			'healedAt'         => self::i( 'Unix time this page turned from problem to healthy; 0 = not recently healed. Healed pages announce in site.healed for about two days, then go quiet.' ),
			'healedFrom'       => self::s( 'The problem bucket this page healed FROM (same keys as stateKey); empty when not recently healed.' ),
			'error'            => self::s( 'This row\'s own inspection failure, if any.' ),
		);

		$this->add(
			'read-google-index',
			__( 'Get Google index status for this site', 'agentimus' ),
			'Returns whether Google\'s index holds this site\'s pages, when the owner has connected '
				. 'Google Search Console. Google\'s index is what AI Overviews, AI Mode and Gemini '
				. 'grounding read — the Google counterpart of "Bing\'s index is what ChatGPT search '
				. 'reads". Three tiers share Google\'s 2,000-inspections/day budget (there is no bulk '
				. 'index report): a WATCHLIST (homepage, busiest pages, newest posts — every answer in '
				. 'rows) checked daily; PROMOTED PROBLEMS — pages any check found unhealthy join the '
				. 'daily check (stalest first, capped at watched.promotedDaily) until they heal; and a '
				. 'WHOLE-SITE ROTATION walking every published URL in daily slices — healthy pages '
				. 'become the site counts, every problem (watched or not) appears in site.problems, '
				. 'and a page that just healed announces in site.healed for about two days before '
				. 'going quiet. On sites small enough the rotation covers everything every day; '
				. 'site.cycleDays states the honest cadence. site.problems ships a bounded, '
				. 'every-bucket share of the problem rows; to walk ALL of one state\'s pages, pass '
				. 'problemsState (a stateKey) and page with problemsPage — site.problems then holds '
				. 'exactly that page of 50, in a stable order, while every count stays complete '
				. '(pages = ceil(site.problemStates[state] / 50)). Presence only, no traffic '
				. '(read-search-performance has the traffic). Returns connected=false with empty rows '
				. 'when no key is connected.',
			self::obj(
				array(
					'problemsState' => self::s( 'Optional: a problem bucket (error | canonical | unknown | discovered | crawled | blocked | other) — site.problems becomes one page of exactly that state\'s rows.' ),
					'problemsPage'  => self::i( 'Optional: 1-based page of 50 within problemsState; ignored without it. Beyond the last page, site.problems is empty.' ),
					'url'           => self::s( 'Optional: ONE page to answer for, absolute or site-relative. Answers from the stored record only — no live call to Google, no quota spent — so use it to ask "is THIS page in?" without walking rows. Adds `lookup` to the response; the rest of the payload is unchanged.' ),
				)
			),
			self::obj(
				array(
					'connected' => self::b( 'False = Google Search Console is not connected; rows is then empty.' ),
					'property'  => self::s( 'The Search Console property the answers come from.' ),
					'checkedAt' => self::i( 'Unix time of the newest sweep; 0 = never checked.' ),
					'lastError' => self::s( 'The most recent sweep failure, empty after a clean sweep.' ),
					'quotaHit'  => self::b( 'True when Google\'s daily inspection budget ran out mid-sweep — unreached rows keep their last good answers (see each row\'s inspectedAt).' ),
					'pending'   => self::i( 'Pages of the current sweep still waiting — the sweep runs in short budgeted chunks so no web request runs long. 0 = the last sweep finished; rows not yet reached carry their previous answers.' ),
					'pausedAt'  => self::i( 'Unix time the owner stopped the current sweep with Cancel; 0 = nothing is stopped. While this is set the pending pages are waiting on a deliberate restart or the daily check — nothing is working through them.' ),
					'watched'   => self::obj(
						array(
							'busiest'       => self::i( 'How many busiest-in-Google pages the watchlist covers at most.' ),
							'newest'        => self::i( 'How many newest posts the watchlist covers at most.' ),
							'promotedDaily' => self::i( 'How many problem pages join the daily check at most (stalest answer first) — the rest keep the rotation cadence until their turn.' ),
							'rotationDaily' => self::i( 'How many whole-site URLs the rotation inspects per day.' ),
							'dailyCap'      => self::i( 'Google\'s documented inspections-per-day budget for the property.' ),
						)
					),
					'counts'    => self::obj(
						array(
							'checked'          => self::i( 'Watchlist pages with an answer.' ),
							'onGoogle'         => self::i( 'Watchlist pages Google confirms are in its index.' ),
							'notOnGoogle'      => self::i( 'Watchlist pages Google confirms are NOT in its index — each row\'s state says why in Google\'s own words.' ),
							'errors'           => self::i( 'Watchlist pages whose inspection itself failed.' ),
							'canonicalDiffers' => self::i( 'Watchlist pages where Google chose a different canonical URL than the one checked.' ),
						)
					),
					'site'      => self::obj(
						array(
							'totalUrls'   => self::i( 'Every published URL plus the homepage — what "the whole site" means here; 0 until the first rotation ran.' ),
							'checked'     => self::i( 'Distinct site URLs with an answer so far (watchlist included).' ),
							'onGoogle'    => self::i( 'Of those, how many Google confirms are in its index.' ),
							'notOnGoogle' => self::i( 'Of those, how many are not (or failed their check).' ),
							'cycleDays'   => self::i( 'How many days one full pass over the site takes at the daily rotation size — 1 = every page is checked every day. Problem pages are re-checked daily regardless, up to watched.promotedDaily.' ),
							'healed'      => self::arr( $index_row ),
							'healedTotal' => self::i( 'Pages that turned from problem to healthy within the last ~two days — `healed` is capped at 20 rows, this count is not.' ),
							'problems'    => self::arr( $index_row ),
							'problemsTotal' => self::i( 'Problem pages found across the site in total — `problems` is capped at 50 rows, this count is not. Watched pages with problems are included.' ),
							'problemStates' => self::obj(
								array(
									'error'      => self::i( 'Checks that failed.' ),
									'canonical'  => self::i( 'Google chose a different address.' ),
									'unknown'    => self::i( 'Never seen by Google.' ),
									'discovered' => self::i( 'Discovered, not yet crawled.' ),
									'crawled'    => self::i( 'Crawled, but not indexed.' ),
									'blocked'    => self::i( 'Blocked by robots.txt or noindex.' ),
									'other'      => self::i( 'Anything else not on Google.' ),
								)
							),
						)
					),
					'sitemaps'  => self::obj(
						array(
							'checkedAt'  => self::i( 'Unix time the registered-sitemaps list was last read from Search Console; 0 = never looked yet (says nothing about the site).' ),
							'liveUrl'    => self::s( 'The sitemap URL this site actually serves today — the address worth registering.' ),
							'registered' => self::arr(
								array(
									'path'      => self::s( 'The sitemap URL as registered in Search Console — possibly years ago, possibly an address nothing serves anymore.' ),
									'pending'   => self::b( 'Submitted but not yet processed by Google.' ),
									'lastRead'  => self::i( 'Unix time Google last downloaded it; 0 = never. A registration whose live file moved keeps its old date forever — the silent way sitemaps die.' ),
									'errors'    => self::i( 'Errors Google reports for this sitemap\'s contents.' ),
									'warnings'  => self::i( 'Warnings Google reports for this sitemap\'s contents.' ),
									'submitted' => self::i( 'URLs the sitemap declared at Google\'s last read.' ),
								)
							),
						)
					),
					'rows'      => self::arr( $index_row ),
					'lookup'    => self::obj(
						array(
							'status' => self::s( 'Only present when `url` was asked for. found = a stored answer is in `row`; unchecked = this site\'s page, no answer stored yet (site.cycleDays says when the rotation reaches it); foreign = not a URL on this site, so it is never checked here.' ),
							'url'    => self::s( 'The URL as asked, resolved against the site when it was site-relative.' ),
							'row'    => self::obj( $index_row ),
						)
					),
				)
			),
			function ( $input ) {
				$view  = GoogleIndex::view( new GoogleSettings() );
				$state = isset( $input['problemsState'] ) ? (string) $input['problemsState'] : '';
				if ( '' !== $state && in_array( $state, GoogleIndex::state_keys(), true ) ) {
					$paged                     = GoogleIndex::problems_page( $state, isset( $input['problemsPage'] ) ? (int) $input['problemsPage'] : 1 );
					$view['site']['problems']  = $paged['rows'];
				}

				// The twin of the card's own lookup box: the coverage map already
				// remembers every checked page, so answering "is this one page in?"
				// costs a read and nothing else. Deliberately NOT a live check —
				// an agent should not be able to spend the owner's daily
				// inspection budget by asking a question.
				$url = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
				if ( '' !== $url ) {
					$found            = GoogleIndex::lookup( $url );
					$view['lookup']   = array(
						'status' => (string) $found['status'],
						'url'    => '/' === $url[0] ? home_url( $url ) : $url,
						'row'    => $found['row'],
					);
				}

				return $view;
			},
			$manage
		);

		$this->add(
			'read-search-performance',
			__( 'Get classic-search performance', 'agentimus' ),
			'Returns what classic search actually sent this site over the reported window: total times '
				. 'shown, visits, click rate and impression-weighted average rank, plus the top searches '
				. 'people used and the top pages they landed on. Numbers are the engine\'s own — Google '
				. 'Search Console and/or Bing Webmaster Tools, whichever the owner connected — never '
				. 'estimated, and never blended (the two count different searchers). Use it to answer '
				. '"how is this site doing in search?". Returns source="" when no engine has reported yet. '
				. 'When source="bing", totals come from Bing\'s site-wide daily series (sample sums until that '
				. 'series has reported) while topQueries and topPages are its top-N sample counted separately '
				. '— the lists sum to less than the totals, and one busy page can even out-count a tile. '
				. 'Neither is an error: state the split rather than reconciling the numbers.',
			self::obj(
				array(
					'source' => self::s( 'Which engine to read: "google" or "bing". Omit for the richer one that has data.' ),
				)
			),
			self::obj(
				array(
					'source'  => self::s( 'The engine these numbers came from; empty when none has data yet.' ),
					'range'   => self::obj(
						array(
							'start' => self::s( 'Y-m-d, first day of the reported window.' ),
							'end'   => self::s( 'Y-m-d, last day.' ),
						)
					),
					'sources' => self::obj(
						array(
							'google' => self::obj(
								array(
									'connected' => self::b(),
									'hasData'   => self::b(),
									'lastError' => self::s(),
									'pageCap'   => self::i( 'Always 0: no source samples a fixed set of pages any more. Kept so readers that branch on it keep taking their no-sampling path.' ),
									'dropped'   => self::i( 'Rows the last poll could not store, because the snapshot keeps only its busiest N. The engines report clicks-descending, so what is missing is the quiet tail — say so rather than treating the stored set as everything.' ),
								)
							),
							'bing'   => self::obj(
								array(
									'connected' => self::b(),
									'hasData'   => self::b(),
									'lastError' => self::s(),
									'pageCap'   => self::i( 'Always 0 now: Bing is worked through a few pages per poll until every page has been asked about, so no page sits permanently outside anything.' ),
									'dropped'   => self::i( 'Rows the last poll could not store, because the snapshot keeps only its busiest N. What is missing is the quiet tail — say so rather than treating the stored set as everything.' ),
								)
							),
						)
					),
					'totals'  => self::obj(
						array(
							'impressions' => self::i( 'Times a page of this site appeared in results.' ),
							'clicks'      => self::i( 'Visits that came from those appearances.' ),
							'ctr'         => array( 'type' => 'number', 'description' => 'Click rate as a percentage.' ),
							'position'    => array( 'type' => 'number', 'description' => 'Impression-weighted average rank; 1 is the top result.' ),
							'probeShare'  => self::i(
								'Percentage of these impressions that came from search-operator probes (site:, intext:) run by scrapers '
								. 'rather than people. Nothing is subtracted — these are the engine\'s own totals — but a high share means '
								. 'the click rate above understates how people actually respond. Say so before drawing conclusions from it.'
							),
						)
					),
					'counts'     => self::obj(
						array(
							'queries' => self::i( 'Distinct searches in the window — topQueries is only the largest few of these.' ),
							'pages'   => self::i( 'Pages the engine attributed traffic to — topPages is only the largest few.' ),
						)
					),
					'topQueries' => self::arr(
						array(
							'query'       => self::s(),
							'isProbe'     => self::b( 'True when this is a search-operator probe, not a person\'s search.' ),
							'impressions' => self::i(),
							'clicks'      => self::i(),
							'ctr'         => array( 'type' => 'number' ),
							'position'    => array( 'type' => 'number' ),
						)
					),
					'topPages'   => self::arr(
						array(
							'title'       => self::s(),
							'url'         => self::s(),
							'postId'      => self::i( '0 when the URL maps to no post (an archive, a removed page).' ),
							'impressions' => self::i(),
							'clicks'      => self::i(),
							'ctr'         => array( 'type' => 'number' ),
							'position'    => array( 'type' => 'number' ),
						)
					),
					'daily'      => self::arr(
						array(
							'date'        => self::s( 'Y-m-d.' ),
							'impressions' => self::i(),
							'clicks'      => self::i(),
						),
						'Clicks and impressions per day, oldest first — Google from Search Console\'s date split, Bing from its site-wide daily traffic report. History accumulates locally beyond each engine\'s own window; the payload ships the most recent 112 days.'
					),
					'weekly'     => self::obj(
						array(
							'ready'    => self::b( 'True only when 14 days of history exist — before that a week-on-week claim would be a guess, so the numbers below stay zero and mean NOTHING.' ),
							'thisWeek' => self::obj( array( 'impressions' => self::i(), 'clicks' => self::i() ) ),
							'lastWeek' => self::obj( array( 'impressions' => self::i(), 'clicks' => self::i() ) ),
						)
					),
					'updatedAt'  => self::i( 'Unix time the daily series last refreshed successfully; 0 = never. Older than a few days = the trend is last-good data, not current — say so.' ),
					'discover'   => self::obj(
						array(
							'impressions' => self::i( 'Times shown in Google Discover this window — a feed, not a search; most sites honestly sit at 0.' ),
							'clicks'      => self::i(),
						)
					),
				)
			),
			function ( $input ) {
				return SearchReport::performance( $this->settings, isset( $input['source'] ) ? (string) $input['source'] : '' );
			},
			$manage
		);

		$this->add(
			'read-search-opportunities',
			__( 'Get search pages worth improving', 'agentimus' ),
			'Returns the pages that already rank in classic search but under-earn — the worklist behind '
				. 'the Search Opportunities card (Visibility → Search). Two groups: "almostThere" (ranking 8–20, one improvement '
				. 'from page one) and "seenNotClicked" (already on page one, but a click rate well under THIS '
				. 'site\'s own page-one median — never an industry benchmark). Each page carries its searches, '
				. 'its totals, and whether it qualified on a single search or on the page\'s combined demand. '
				. 'Pair it with write-description / update-content to act on what it finds. Returns state '
				. '"not_connected", "collecting" or "too_thin" when no honest verdict is possible.',
			self::obj(
				array(
					'source' => self::s( 'Which engine to read: "google" or "bing". Omit for the richer one that has data.' ),
				)
			),
			self::obj(
				array(
					'state'     => self::s( 'ready | not_connected | collecting | too_thin | clear.' ),
					'source'    => self::s( 'The engine these numbers came from; empty when none has data yet.' ),
					'sources'   => self::obj(
						array(
							'google' => self::obj(
								array(
									'connected' => self::b(),
									'hasData'   => self::b(),
									'lastError' => self::s(),
									'pageCap'   => self::i( 'Always 0: no source samples a fixed set of pages any more. Kept so readers that branch on it keep taking their no-sampling path.' ),
									'dropped'   => self::i( 'Rows the last poll could not store, because the snapshot keeps only its busiest N. The engines report clicks-descending, so what is missing is the quiet tail — say so rather than treating the stored set as everything.' ),
								)
							),
							'bing'   => self::obj(
								array(
									'connected' => self::b(),
									'hasData'   => self::b(),
									'lastError' => self::s(),
									'pageCap'   => self::i( 'Always 0 now: Bing is worked through a few pages per poll until every page has been asked about, so no page sits permanently outside anything.' ),
									'dropped'   => self::i( 'Rows the last poll could not store, because the snapshot keeps only its busiest N. What is missing is the quiet tail — say so rather than treating the stored set as everything.' ),
								)
							),
						)
					),
					'medianCtr' => array(
						'type'        => array( 'number', 'null' ),
						'description' => 'This site\'s own page-one median click rate (percentage) — the bar "seenNotClicked" is measured against. Null when no honest bar can be set, in which case that group stays empty and medianReason says why.',
					),
					'ctrBar'      => array(
						'type'        => array( 'number', 'null' ),
						'description' => 'The click-rate threshold actually applied to "seenNotClicked" (percentage): a page must fall BELOW this, '
							. 'not merely below medianCtr. Quote this, never medianCtr, when stating what "not clicked enough" means.',
					),
					'medianRows'  => self::i( 'How many page-one results carried enough views to measure a click rate.' ),
					'medianNeeds' => self::i( 'How many it takes before a bar is computed at all.' ),
					'medianReason' => self::s(
						'Why there is no bar, when medianCtr is null: "thin" = too few page-one results carry enough views to measure (says nothing about clicking); '
						. '"unclicked" = enough exist and the middle one earned no clicks at all. Empty string when a bar exists. Never report one of these as the other.'
					),
					'noise'     => self::obj(
						array(
							'searches' => self::i( 'Distinct searches discarded as search-operator probes (one search on six pages counts once).' ),
						'examples' => self::arr(
							array(
								'query'       => self::s( 'The discarded search, verbatim.' ),
								'impressions' => self::i(),
							)
						),
							'share'    => self::i(
								'Percentage of the reported views that came from automated site:/intext: probes rather than people. '
								. 'These are excluded from every judgement here (no title rewrite makes a scraper click), which is why '
								. 'read-search-performance — the raw record — reports larger numbers. A high share is the real story on a '
								. 'site with no worklist: state it before concluding anything about how the pages are performing.'
							),
						)
					),
					'counts'    => self::obj(
						array(
							'opportunities' => self::i( 'Pages worth looking at, both groups.' ),
							'almost'        => self::i(),
							'seen'          => self::i(),
							'setAside'      => self::i( 'Pages the owner excused from this worklist.' ),
						)
					),
					'almostThere'    => self::opportunity_rows(),
					'seenNotClicked' => self::opportunity_rows(),
					'collisions'     => self::arr(
						array(
							'query'  => self::s( 'The search several pages are splitting, as the engine reported it.' ),
							'shown'  => self::i( 'Times the search showed any of these pages, summed.' ),
							'clicks' => self::i( 'Clicks the pages earned between them.' ),
							'best'   => self::n( 'Best average position among the competing pages.' ),
							'worst'  => self::n( 'Worst average position among the competing pages.' ),
							'pages'  => self::arr(
								array(
									'title'       => self::s(),
									'url'         => self::s(),
									'postId'      => self::i( '0 when the URL never resolved to a post.' ),
									'editUrl'     => self::s(),
									'clicks'      => self::i(),
									'impressions' => self::i(),
									'position'    => self::n(),
									'share'       => self::n( 'This page\'s fraction of the query\'s showings (0–1).' ),
									'winner'      => self::b( 'True on the one page that earns the click — most clicks, then the better position. Advise keeping this page as the answer and pointing the others at it, or differentiating them; never advise editing the winner.' ),
								)
							),
						),
						'Searches that several pages are splitting: the engine sends one search to one page at a time, so competing pages take turns and every turn a weaker page takes is a click the strong one loses. The heaviest few, ranked by showings.'
					),
					'collisionsTotal' => self::i( 'How many split searches exist in total; the list above carries the heaviest few.' ),
					'outOfScope'      => self::i( 'How many measured PAGES this report deliberately says nothing about, because the owner switched their content type off for checking. Above zero means these lists cover part of the measured site, not all of it — the pages are not missing, they are excluded on purpose. Pages with no post behind them (the home page, archives) are never excluded, since no content type governs them.' ),
				)
			),
			function ( $input ) {
				return SearchReport::opportunities( $this->settings, isset( $input['source'] ) ? (string) $input['source'] : '' );
			},
			$manage
		);

		$this->add(
			'read-findings',
			__( 'Get the ranked findings list', 'agentimus' ),
			'Returns the site’s open findings — the same ranked front door the owner’s Findings screen shows. '
				. 'Every open question the plugin can answer, from every subsystem (setup checks, the content worklist, '
				. 'search opportunities, split searches, the never-run citation measurement), merged into ONE list '
				. 'ranked by what each finding costs the owner and bounded at 12 rows; rows merely waiting on a search '
				. 'engine’s next report ride along uncounted (tier "waiting" — no edit can clear them). `clear` states '
				. 'what is demonstrably fine, `failed` names any source that errored (its findings are MISSING, not '
				. 'absent — never read a failed source as a clean one), and `counts` tallies the rows by tier. Start '
				. 'here to answer "what should be done next on this site?", then use the per-area read tools for detail.',
			self::no_input(),
			self::obj(
				array(
					'findings' => self::arr(
						array(
							'id'       => self::s( 'Stable finding id (split-search findings are numbered: split_search_0…).' ),
							'tier'     => array(
								'type'        => 'string',
								'enum'        => array( 'urgent', 'worth', 'later', 'waiting' ),
								'description' => 'urgent = a decision only the owner can make, or a live trust problem; worth = traffic or citability measurably on the table; later = worth knowing, costs nothing today; waiting = the owner’s side is done and a search engine answers next.',
							),
							'weight'   => self::i( 'Sort weight; higher sorts first.' ),
							'title'    => self::s( 'The headline, written as a fact.' ),
							'why'      => self::s( 'One short line on why it matters.' ),
							'points'   => array(
								'type'        => 'array',
								'items'       => self::s(),
								'description' => 'Separable facts, one clause each.',
							),
							'evidence' => array(
								'type'        => 'array',
								'items'       => self::s(),
								'description' => 'Short checkable strings; a trimmed list says so with a "+N more" tail.',
							),
							'action'   => array(
								'type'                 => array( 'object', 'null' ),
								'description'          => 'Where the fix lives: { label, tab, view, anchor }, plus `url` for an outward destination, `pages` (post ids) when the landing list should show exactly the pages this finding counted, or `open` naming a panel the owner\u2019s screen opens on arrival (e.g. "checkScope"). Null when there is nothing to open.',
								'additionalProperties' => true,
							),
							'check'    => self::s( 'On a config_gap finding only: the readiness check id behind it (feed it to apply-fix).' ),
						)
					),
					'resolved' => array(
						'type'                 => array( 'object', 'null' ),
						'description'          => 'The engine’s own good news, or null when there is none. ONE row whatever the site’s size: a single win keeps its whole sentence, several become a count with the moves as evidence. News, not a finding — it has no action and expires on its own.',
						'properties'           => array(
							'id'       => self::s(),
							'title'    => self::s(),
							'evidence' => array( 'type' => 'array', 'items' => self::s() ),
							'at'       => self::i( 'Unix time of the newest win.' ),
						),
						'additionalProperties' => false,
					),
					'clear'    => array(
						'type'        => 'array',
						'items'       => self::s(),
						'description' => 'What is demonstrably fine, said out loud — an empty findings list must read as "checked", never "broken".',
					),
					'failed'   => array(
						'type'        => 'array',
						'items'       => self::s(),
						'description' => 'Sources that errored. Their findings are missing, not absent.',
					),
					'counts'   => self::obj(
						array(
							'urgent'  => self::i(),
							'worth'   => self::i(),
							'later'   => self::i(),
							'waiting' => self::i( 'Carried but never counted as open work — no edit can clear a waiting row.' ),
						)
					),
					'hidden'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
						'description' => 'Findings the OWNER has put away — same shape as `findings`, and never counted in `counts`. Only "later" rows can be here; urgent and worth ones can never be hidden. ⛔ Do not re-raise these to the owner as if they were new: they are a decision, not an oversight. They travel with the payload rather than being dropped so nothing is ever silently withheld.',
					),
				)
			),
			function () {
				return ( new Findings( $this->settings ) )->all();
			},
			$manage
		);

		$this->add(
			'read-audience',
			__( 'Get the audience: people vs machines', 'agentimus' ),
			'Returns who reached this site in the reporting window, counted as two populations that are NEVER '
				. 'summed — the audience doctrine: a machine fetch is not a human visit, so no combined total exists '
				. 'anywhere in this payload and none should be computed from it. `people` is the human half — clicks '
				. 'the search engines themselves reported, visits AI assistants sent, and, when the owner has '
				. 'connected Google Analytics, the site’s whole analytics audience with the two named routes as '
				. 'shares of it. `machines` is AI crawler/agent fetches of the discovery endpoints, with the '
				. 'impostor count. `limits` states what these numbers cannot say, per half — quote them before '
				. 'generalising about "the audience".',
			self::no_input(),
			self::obj(
				array(
					'window'   => self::i( 'Reporting window, days.' ),
					'people'   => self::obj(
						array(
							'all'     => self::obj(
								array(
									'connected'      => self::b( 'Whether Google Analytics is connected AND has usable numbers.' ),
									'users'          => self::i( 'Active users — the headline metric is PEOPLE, not sessions or views.' ),
									'newUsers'       => self::i(),
									'sessions'       => self::i(),
									'views'          => self::i(),
									'engaged'        => self::i(),
									'engagedPct'     => self::i(),
									'avgSeconds'     => self::i(),
									'perVisit'       => self::n( 'Pages per visit.' ),
									'pages'          => self::arr(
										array(
											'path'  => self::s(),
											'views' => self::i(),
											'users' => self::i(),
										)
									),
									'aiSessions'     => array(
										'type'        => array( 'integer', 'null' ),
										'description' => 'GA4’s own count of sessions AI assistants sent. Null = GA4 has no opinion yet, which is a different answer from zero.',
									),
									'otherSessions'  => array( 'type' => array( 'integer', 'null' ) ),
									'aiBySource'     => self::arr(
										array(
											'source' => self::s(),
											'hits'   => self::i(),
										)
									),
									'engineSessions' => array(
										'type'        => array( 'integer', 'null' ),
										'description' => 'GA4’s per-engine organic arrivals, summed. Null on snapshots that predate the split.',
									),
									'engineBySource' => self::arr(
										array(
											'source' => self::s(),
											'hits'   => self::i(),
										)
									),
									'pending'        => self::b( 'Connected in Settings but never successfully polled — a fetch is what is missing, not the connection.' ),
									'error'          => self::s( 'The last fetch failure, when pending.' ),
									'stale'          => self::b( 'True when the snapshot is over two days old.' ),
									'fetched'        => self::i( 'Unix time of the snapshot; 0 = never.' ),
									'window'         => self::i(),
								)
							),
							'search'  => self::obj(
								array(
									'connected'   => self::b(),
									'source'      => self::s( 'Which engines these clicks came from ("Google", "Bing", "Google + Bing") — quote it, never assume.' ),
									'clicks'      => self::i( 'People, by the engines’ own reports — but the engines fold AI Overview appearances into these figures with no separating dimension.' ),
									'impressions' => self::i(),
									'rows'        => self::i(),
									'start'       => self::s( 'Widest window any connected source reports (YYYY-MM-DD); the engines publish on a delay, so it ends before today.' ),
									'end'         => self::s(),
									'pageCap'     => self::i( 'Always 0 now: no source samples a fixed set of pages. Kept so older readers of this payload keep taking their no-sampling path.' ),
									'waiting'     => self::i( 'Pages Bing has not been asked about yet — it answers one page per request, so page-level detail fills in over days. The site-wide clicks here are whole either way. 0 for Google, which reports every page at once.' ),
								)
							),
							'ai'      => self::obj(
								array(
									'enabled' => self::b(),
									'visits'  => self::i( 'People an AI assistant sent, counted when the visit still carries a recognisable referrer or campaign tag — an assistant that strips both is invisible, so treat this as a minimum.' ),
									'today'   => self::i(),
									'sources' => self::i( 'Distinct assistants seen — the full count, not the capped leaderboard.' ),
									'top'     => self::arr(
										array(
											'source' => self::s(),
											'hits'   => self::i(),
										)
									),
									'pages'   => self::arr(
										array(
											'path' => self::s(),
											'hits' => self::i(),
										),
										'Where those readers landed — the pages earning the citations.'
									),
									'prev'    => self::i( 'The window before this one, same length, no overlap.' ),
									'change'  => self::i(),
									'hasPrev' => self::b( 'False on a first window — never invent a percentage from a zero baseline.' ),
								)
							),
							'arrived' => self::i( 'The human headline: analytics users when connected, otherwise search clicks + AI visits. A sum of PEOPLE only — machines are never in it.' ),
							'whole'   => self::b( 'True when `arrived` is the whole audience (analytics connected); false = the two named routes only, a smaller claim.' ),
						)
					),
					'machines' => self::obj(
						array(
							'enabled'   => self::b(),
							'fetches'   => self::i( 'Requests to the agent-facing endpoints in the window — endpoints (llms.txt, .md twins, discovery documents), never ordinary pages.' ),
							'today'     => self::i(),
							'agents'    => self::i( 'Distinct agents, by the name each declared.' ),
							'impostors' => self::i( 'Clients caught claiming an identity verification did not support.' ),
							'from'      => self::s( 'First day of the window these four numbers cover (YYYY-MM-DD, UTC).' ),
							'to'        => self::s( 'Last day of that window — today, in UTC.' ),
						)
					),
					'limits'   => self::arr(
						array(
							'key'   => self::s(),
							'scope' => self::s( 'Which half the limit is about: humans, machines, or both.' ),
							'text'  => self::s(),
						),
						'What these numbers cannot say — each entry present only when true for THIS site, so never boilerplate.'
					),
				)
			),
			function () {
				return Audience::from_stats( Repository::stats( $this->settings ) );
			},
			$manage
		);

		$this->add(
			'identify-bot',
			__( 'Identify a bot by IP', 'agentimus' ),
			'Given an IP address, resolves who it really belongs to via forward-confirmed reverse DNS: the '
				. 'PTR hostname, the owning network/organisation, whether it maps to a known AI engine, and a '
				. 'verdict (0 = no engine match, 1 = forward-confirmed engine, 2 = forged engine hostname — an '
				. 'impersonator). Use it to answer "is this crawler that claims to be GPTBot actually OpenAI?".',
			self::obj(
				array(
					'ip' => self::s( 'The IPv4 or IPv6 address to check.' ),
				),
				array( 'ip' )
			),
			self::obj(
				array(
					'ip'      => self::s(),
					'host'    => self::s(),
					'network' => self::s(),
					'engine'  => self::s(),
					'verdict' => array(
						'type'        => 'integer',
						'enum'        => array( 0, 1, 2 ),
						'description' => '0 = no engine / no PTR, 1 = forward-confirmed engine, 2 = forged engine hostname.',
					),
					'slow'    => self::b(),
				)
			),
			function ( $input ) {
				$ip = isset( $input['ip'] ) ? trim( (string) $input['ip'] ) : '';
				if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return new \WP_Error( 'agentimus_bad_ip', __( 'A valid IP address is required.', 'agentimus' ), array( 'status' => 400 ) );
				}
				return BotVerifier::identify_ip( $ip );
			},
			$manage
		);

		$this->add(
			'read-clients',
			__( 'List the clients waiting on a decision', 'agentimus' ),
			'Returns who has been fetching this site and what the owner has already decided about them — the '
				. 'review queue first. A queue row is a client the site wants a verdict on: brand new, unusually '
				. 'heavy, or caught claiming an identity that is not its own. `flags` says which of those it is '
				. 'and `severity` how loud, `hits` is the volume behind the row, and `suggestedRule` is the exact '
				. 'user-agent fragment a block or allow would match on — pass it nothing, pass review-client the '
				. '`ua`. Then the standing decisions: `blocked` (turned away at the door), `allowed` (never '
				. 'blocked, never queued again) and `ignored` (a not-now, no policy either way, which returns if '
				. 'the client changes materially). `verifiers` is the separate list of crawlers whose identity '
				. 'CAN be proved by reverse DNS or published IP ranges — recheck-client only works on those. '
				. 'Empty queue plus enabled=true means nothing needs a verdict; enabled=false means the site is '
				. 'not recording visits at all, which is not the same as nobody visiting.',
			self::no_input(),
			self::obj(
				array(
					'enabled'     => self::b( 'Whether this site records machine visits at all. False = the lists below are silence, not evidence.' ),
					'identifyOn'  => self::b( 'Whether "identify every bot" is on, which is what fills in the owning network.' ),
					'review'      => self::arr(
						array(
							'ua'            => self::s( 'The client’s user-agent, as sent. Pass this to review-client and recheck-client.' ),
							'name'          => self::s( 'What it is, when this site recognises it; empty when nothing does.' ),
							'operator'      => self::s( 'Who runs it, when recognised.' ),
							'hits'          => self::i( 'Requests behind this row.' ),
							'recentHits'    => self::i( 'How many of those are recent — the burst that raised it.' ),
							'firstSeen'     => self::s( 'ISO 8601.' ),
							'lastSeen'      => self::s( 'ISO 8601.' ),
							'severity'      => self::i( 'How loud the row is; higher wants attention sooner.' ),
							'isNew'         => self::b( 'First seen recently. Leaves the queue on its own if nothing else is wrong.' ),
							'isHeavy'       => self::b( 'Fetching far more than the rest.' ),
							'isSpoof'       => self::b( 'Claimed an identity the identity check disproved — the one flag that never ages out.' ),
							'verdict'       => self::s( 'What the identity check makes of it: unchecked, verified or spoofed.' ),
							'verifiable'    => self::b( 'Whether its claimed identity CAN be proved — recheck-client only works on these.' ),
							'refused'       => self::b( 'Whether requests from it are already being turned away at the door.' ),
							'blocked'       => self::b( 'Already on the block list.' ),
							'suggestedRule' => self::s( 'The user-agent fragment a block or allow would match on. Empty means no safe rule can be derived, and review-client will refuse block and allow for this row.' ),
						)
					),
					'reviewTotal' => self::i( 'Rows in the queue.' ),
					'blocked'     => self::arr(
						array(
							'rule'      => self::s( 'The user-agent fragment being matched.' ),
							'name'      => self::s( 'What it is, when recognised.' ),
							'decidedAt' => self::i( 'Unix time the owner decided; 0 = the record predates the decision log.' ),
						)
					),
					'allowed'     => self::arr(
						array(
							'rule'      => self::s( 'The user-agent fragment being matched.' ),
							'name'      => self::s( 'What it is, when recognised.' ),
							'decidedAt' => self::i( 'Unix time the owner decided; 0 = the record predates the decision log.' ),
						)
					),
					'ignored'     => self::arr(
						array(
							// ⚠️ The store keys these by a dismissal identity, not by the
							// user-agent — so this names the client the way the owner's own
							// screen does, and there is no `ua` to hand back. Nothing here
							// is meant to be passed to another tool.
							'name' => self::s( 'The client that was set aside, as the owner sees it named.' ),
							'at'   => self::i( 'Unix time it was ignored.' ),
							'hits' => self::i( 'The volume the owner saw when they ignored it — the row returns if the client grows well past this.' ),
						)
					),
					'verifiers'   => self::arr(
						array(
							'token'   => self::s( 'The registry key.' ),
							'label'   => self::s( 'The crawler’s name.' ),
							'ua'      => self::s( 'The user-agent fragment that claims this identity.' ),
							'domains' => array(
								'type'        => 'array',
								'items'       => self::s(),
								'description' => 'The hostnames a genuine one resolves to.',
							),
						)
					),
				)
			),
			function () {
				$settings = $this->settings;
				$stats    = Repository::stats( $settings );
				// ⚠️ `threats` is a WRAPPER — sources / counts / blockingOn / … — and
				// the rows are under `sources`. Iterating the wrapper itself yields
				// one blank row per key and reads as "five clients, all unknown".
				$threats  = isset( $stats['threats']['sources'] ) && is_array( $stats['threats']['sources'] ) ? $stats['threats']['sources'] : array();

				$review = array();
				foreach ( $threats as $row ) {
					$known    = isset( $row['known'] ) && is_array( $row['known'] ) ? $row['known'] : array();
					$flags    = isset( $row['flags'] ) && is_array( $row['flags'] ) ? $row['flags'] : array();
					$review[] = array(
						'ua'            => (string) ( isset( $row['ua'] ) ? $row['ua'] : '' ),
						'name'          => (string) ( isset( $known['name'] ) ? $known['name'] : ( isset( $row['agent'] ) ? $row['agent'] : '' ) ),
						'operator'      => (string) ( isset( $known['operator'] ) ? $known['operator'] : '' ),
						'hits'          => (int) ( isset( $row['hits'] ) ? $row['hits'] : 0 ),
						'recentHits'    => (int) ( isset( $row['recent'] ) ? $row['recent'] : 0 ),
						'firstSeen'     => (string) ( isset( $row['firstSeen'] ) ? $row['firstSeen'] : '' ),
						'lastSeen'      => (string) ( isset( $row['lastSeen'] ) ? $row['lastSeen'] : '' ),
						'severity'      => (int) ( isset( $row['severity'] ) ? $row['severity'] : 0 ),
						'isNew'         => ! empty( $flags['new'] ),
						'isHeavy'       => ! empty( $flags['heavy'] ),
						'isSpoof'       => ! empty( $flags['spoof'] ),
						'verdict'       => (string) ( isset( $row['verdict'] ) ? $row['verdict'] : '' ),
						'verifiable'    => ! empty( $row['verifiable'] ),
						'refused'       => ! empty( $row['refused'] ),
						'blocked'       => ! empty( $row['blocked'] ),
						'suggestedRule' => (string) ( isset( $row['token'] ) ? $row['token'] : '' ),
					);
				}

				$decisions = Settings::decisions();
				$listing   = static function ( $tokens, $dates ) {
					$out = array();
					foreach ( (array) $tokens as $token ) {
						$token = (string) $token;
						$known = Catalog::identify( $token );
						$out[] = array(
							'rule'      => $token,
							'name'      => (string) ( is_array( $known ) && isset( $known['name'] ) ? $known['name'] : '' ),
							'decidedAt' => (int) ( isset( $dates[ strtolower( $token ) ] ) ? $dates[ strtolower( $token ) ] : 0 ),
						);
					}
					return $out;
				};

				$ignored = array();
				foreach ( (array) Repository::dismissals() as $row ) {
					$ignored[] = array(
						'name' => (string) ( isset( $row['label'] ) ? $row['label'] : '' ),
						'at'   => (int) ( isset( $row['at'] ) ? $row['at'] : 0 ),
						'hits' => (int) ( isset( $row['hits'] ) ? $row['hits'] : 0 ),
					);
				}

				$verifiers = array();
				foreach ( VerifierRegistry::entries() as $entry ) {
					$verifiers[] = array(
						'token'   => (string) ( isset( $entry['token'] ) ? $entry['token'] : '' ),
						'label'   => (string) ( isset( $entry['label'] ) ? $entry['label'] : '' ),
						'ua'      => (string) ( isset( $entry['ua'] ) ? $entry['ua'] : '' ),
						'domains' => array_values( array_map( 'strval', (array) ( isset( $entry['domains'] ) ? $entry['domains'] : array() ) ) ),
					);
				}

				return array(
					'enabled'     => (bool) $settings->enabled( 'enable_activity' ),
					'identifyOn'  => (bool) $settings->enabled( 'identify_bots' ),
					'review'      => $review,
					'reviewTotal' => count( $review ),
					'blocked'     => $listing( $settings->get( 'blocked_agents', array() ), $decisions['block'] ),
					'allowed'     => $listing( $settings->get( 'allowed_agents', array() ), $decisions['allow'] ),
					'ignored'     => $ignored,
					'verifiers'   => $verifiers,
				);
			},
			$manage
		);

		$this->add(
			'read-agent-access',
			__( 'Read the agent access record', 'agentimus' ),
			'Returns what has actually been DONE on this site through a key rather than a browser: keys created '
				. 'and used, abilities run, and requests refused. Newest first, cursor-paginated with `before`. '
				. 'It is a record, not a guard — it names the key, never the person. `coverage` is the one field '
				. 'to read before trusting an empty list: it says whether this WordPress can see ability runs at '
				. 'all, so "nothing here" can be told apart from "nothing is being watched". Pair it with '
				. 'read-clients, which answers the other half — who FETCHED pages, signed in or not.',
			self::obj(
				array(
					'before' => self::s( 'Pagination cursor: pass the previous page’s `cursor` for older rows.' ),
				)
			),
			self::obj(
				array(
					'events'        => self::arr(
						array(
							'kind'      => self::s( 'What happened: a key created or used, an ability run, a request refused.' ),
							'user'      => self::s( 'The WordPress login the key acts as.' ),
							'credName'  => self::s( 'Which key, by the name it was given.' ),
							'subject'   => self::s( 'What it acted on — the ability name, for an ability run.' ),
							'detail'    => self::s( 'Anything more the event carries.' ),
							'hits'      => self::i( 'How many times this same event has happened.' ),
							'firstSeen' => self::s( 'ISO 8601.' ),
							'lastSeen'  => self::s( 'ISO 8601.' ),
						)
					),
					'total'         => self::i( 'Events on record, which may exceed the page returned.' ),
					'unseen'        => self::i( 'Events the owner has not looked at yet.' ),
					'hasMore'       => self::b(),
					'cursor'        => self::s( 'Pass as `before` for the next page; empty at the end.' ),
					'coverage'      => self::s( 'Whether ability runs can be watched here at all — read this before treating an empty list as "nothing happened".' ),
					'hasAbilities'  => self::b( 'Whether this WordPress exposes the Abilities API.' ),
					'thirdParty'    => self::b( 'Whether abilities from OTHER plugins are visible too, or only Agentimus’s own.' ),
					'retentionDays' => self::i( 'How long events are kept.' ),
					'maxRows'       => self::i( 'The hard row cap; the oldest go first when it binds.' ),
				)
			),
			function ( $input ) {
				$before = isset( $input['before'] ) ? (string) $input['before'] : '';
				$page   = AgentAccessStore::page( AgentAccessStore::DEFAULT_LIMIT, null, $before );
				$rows   = array();
				foreach ( (array) $page['events'] as $event ) {
					$rows[] = array(
						'kind'      => (string) ( isset( $event['kind'] ) ? $event['kind'] : '' ),
						'user'      => (string) ( isset( $event['user'] ) ? $event['user'] : '' ),
						'credName'  => (string) ( isset( $event['credName'] ) ? $event['credName'] : '' ),
						'subject'   => (string) ( isset( $event['subject'] ) ? $event['subject'] : '' ),
						'detail'    => (string) ( isset( $event['detail'] ) ? $event['detail'] : '' ),
						'hits'      => (int) ( isset( $event['hits'] ) ? $event['hits'] : 0 ),
						'firstSeen' => (string) ( isset( $event['firstSeen'] ) ? $event['firstSeen'] : '' ),
						'lastSeen'  => (string) ( isset( $event['lastSeen'] ) ? $event['lastSeen'] : '' ),
					);
				}
				$coverage = AgentAccess::coverage();
				return array(
					'events'        => $rows,
					'total'         => (int) AgentAccessStore::total(),
					'unseen'        => (int) AgentAccessStore::unseen_count(),
					'hasMore'       => ! empty( $page['hasMore'] ),
					'cursor'        => (string) ( isset( $page['cursor'] ) ? $page['cursor'] : '' ),
					'coverage'      => (string) $coverage,
					'hasAbilities'  => (bool) Events::has_abilities( $coverage ),
					'thirdParty'    => (bool) Events::covers_third_party( $coverage ),
					'retentionDays' => (int) apply_filters( 'agentimus_agent_access_retention_days', AgentAccessStore::RETENTION_DAYS ),
					'maxRows'       => (int) AgentAccessStore::MAX_ROWS,
				);
			},
			$manage
		);

		$this->add(
			'read-terms',
			__( 'List the site’s categories and tags', 'agentimus' ),
			'Returns the categories and tags THIS site already uses, with the exact names create-content and '
				. 'update-content expect. Those tools take terms by NAME, and a name typed from memory does not '
				. 'match one that exists — "New Features" and "New features" are two different categories, and '
				. 'the second one gets created. Read this first and reuse what is here. '
				. '`field` on each group is the input field it maps to (`categories`, `tags`), `count` is how '
				. 'many published items already carry the term, and `total` says how many exist when the list '
				. 'is capped — narrow it with `search` rather than assuming you have seen them all. '
				. '`notSettable` names public taxonomies this content type has that the write tools do NOT '
				. 'touch: they exist on the site, and nothing here can put a page in one.',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => self::s( 'Which content type’s taxonomies to list. Defaults to "post".' ),
					'search'    => self::s( 'Only terms whose name contains this. Use it on a site with hundreds.' ),
					'per'       => self::i( 'Terms per group (default 100, capped at 200).' ),
				),
				'additionalProperties' => false,
				// Every argument is optional, so "no input at all" is the natural
				// ask — and without this default a bare call is refused at the
				// input gate {@see no_input()}.
				'default'              => new \stdClass(),
			),
			self::obj(
				array(
					'postType'    => self::s( 'The content type these groups belong to.' ),
					'taxonomies'  => self::arr(
						array(
							'field'        => self::s( 'The create-content / update-content input field for this group: "categories" or "tags".' ),
							'taxonomy'     => self::s( 'WordPress’s own name for it.' ),
							'label'        => self::s( 'What this site calls it.' ),
							'hierarchical' => self::b( 'TRUE when terms can have parents (categories do; tags do not).' ),
							'total'        => self::i( 'How many terms exist. Larger than the list below means the list was capped.' ),
							'canCreate'    => self::b( 'Whether the connected user may create a NEW term here. FALSE means an unknown name will be REFUSED, not quietly added — send names from this list.' ),
							'terms'        => self::arr(
								array(
									'id'     => self::i(),
									'name'   => self::s( 'Send this string, exactly as it appears here.' ),
									'slug'   => self::s(),
									'count'  => self::i( 'Published items already carrying it.' ),
									'parent' => self::i( '0 when top-level; only meaningful on a hierarchical group.' ),
								)
							),
						)
					),
					'notSettable' => self::arr(
						array(
							'taxonomy' => self::s(),
							'label'    => self::s(),
						),
						'Public taxonomies this content type has that the write tools cannot set. ⛔ Never tell an owner a page was filed in one of these.'
					),
				)
			),
			function ( $input ) {
				$in   = is_array( $input ) ? $input : array();
				$type = isset( $in['post_type'] ) ? sanitize_key( (string) $in['post_type'] ) : 'post';
				if ( ! post_type_exists( $type ) ) {
					return new \WP_Error( 'agentimus_bad_type', __( 'That content type does not exist on this site.', 'agentimus' ), array( 'status' => 400 ) );
				}
				$search = isset( $in['search'] ) ? trim( (string) $in['search'] ) : '';
				$per    = isset( $in['per'] ) ? (int) $in['per'] : 0;
				$per    = $per > 0 ? min( 200, $per ) : 100;

				$groups   = array();
				$settable = array();
				foreach ( ContentWriter::TAXONOMY_FIELDS as $field => $taxonomy ) {
					if ( ! is_object_in_taxonomy( $type, $taxonomy ) ) {
						continue; // This type does not use it — say nothing rather than an empty group.
					}
					$settable[] = $taxonomy;
					$tax        = get_taxonomy( $taxonomy );
					$args       = array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => $per, 'orderby' => 'count', 'order' => 'DESC' );
					if ( '' !== $search ) {
						$args['search'] = $search;
					}
					$terms = get_terms( $args );
					$total = (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );

					$rows = array();
					foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
						$rows[] = array(
							'id'     => (int) $term->term_id,
							'name'   => (string) $term->name,
							'slug'   => (string) $term->slug,
							'count'  => (int) $term->count,
							'parent' => (int) $term->parent,
						);
					}
					$groups[] = array(
						'field'        => (string) $field,
						'taxonomy'     => (string) $taxonomy,
						'label'        => $tax ? (string) $tax->labels->name : (string) $taxonomy,
						'hierarchical' => (bool) ( $tax && $tax->hierarchical ),
						'total'        => '' !== $search ? count( $rows ) : $total,
						// ⭐ Said out loud, because it changes what an agent should
						// DO: without the create cap an unknown name comes back as
						// an error from the write tools, not as a new term.
						'canCreate'    => (bool) ( $tax && current_user_can( $tax->cap->edit_terms ) ),
						'terms'        => $rows,
					);
				}

				$others = array();
				foreach ( get_object_taxonomies( $type, 'objects' ) as $tax ) {
					if ( empty( $tax->public ) || in_array( (string) $tax->name, $settable, true ) ) {
						continue;
					}
					$others[] = array(
						'taxonomy' => (string) $tax->name,
						'label'    => (string) $tax->labels->name,
					);
				}

				return array(
					'postType'    => $type,
					'taxonomies'  => $groups,
					'notSettable' => $others,
				);
			},
			$manage
		);

		/* -- Per-page authoring aids ----------------------------------------- */

		$this->add(
			'read-content-issues',
			__( 'Find the pages worth fixing', 'agentimus' ),
			'Returns the site’s content worklist — WHICH pages need work, and what is wrong with each — ranked '
				. 'over the whole site (pages needing work first, then by what a fix is worth) and paginated. This '
				. 'is the list read-findings summarises when it says "N Posts and Pages are worth fixing": start '
				. 'there for what to do next, come here for the pages themselves. Every row carries the post id, '
				. 'so it is also how you aim the other tools — check-page for a full fresh reading of one page, '
				. 'update-content / write-description / write-topics to fix it. '
				. 'Three exclusive buckets, chosen with `filter`: "fixable" (the default — pages asking for '
				. 'something), "clear" (read, nothing to fix), "setAside" (pages the owner excused; ⛔ never '
				. 'suggest editing these, the exclusion is a decision, not an oversight). `counts` tallies all '
				. 'three over the whole site and is the SAME count the owner’s screen shows. '
				. 'A row asks for one of two different things, and both must be read: `issues` lists the content '
				. 'checks it fails (each with the id check-page uses), while `coverage` says whether it answers '
				. 'the search it is found for — a row with an EMPTY `issues` list and coverage "barely" or '
				. '"missing" is not a clean page, it is a page whose words never answer its search. '
				. 'No page is rendered to build this: every verdict is one the background sweep already measured, '
				. 'which is what makes paging through a whole site affordable. The cost is that a verdict has an '
				. 'age — `stale` is TRUE when the owner saved that page after it was read, so its issues describe '
				. 'the earlier draft, and TRUE when the engines have since moved which search the page is found '
				. 'for, so its `coverage` answers a search other than the one in `search`. ⛔ Never report a '
				. 'stale row as broken or as fixed; re-read that one page '
				. 'with check-page and say what THAT found. `grading` (never read) and `rechecking` (read, then '
				. 'edited) say how much of the site these numbers cannot speak for — above zero, this is a list '
				. 'over part of the site and must be described as one.',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'filter' => array(
						'type'        => 'string',
						'enum'        => array( 'fixable', 'clear', 'setAside' ),
						'description' => 'Which bucket to list. Defaults to "fixable" — the pages asking for something.',
						'default'     => 'fixable',
					),
					'page'   => self::i( '1-based page number. Defaults to 1; `total` and `per` say how many pages exist.' ),
					'per'    => self::i( 'Rows per page (default 20, capped at 30).' ),
					'issue'  => self::s( 'Only pages flagging ONE check — the id from a row’s `issues[].id`, or from read-readiness’s `content[].id` (e.g. "featured_alt"). This is how you walk the pages behind "60 items flag Featured image not described" instead of paging the whole list and re-deriving which sixty. `total` then counts the narrowed set while `counts` still describes the whole bucket — the two disagree on purpose, and `issueLabel` names what the narrowing is. ⛔ An id no check owns matches nothing and returns an empty list; it never silently widens back to everything.' ),
				),
				'additionalProperties' => false,
				// ⚠️ EVERY parameter here is optional, which makes "no input at all"
				// the most natural way to ask — and without this default that call
				// is refused at the input gate ("input is not of type object"),
				// because a read ability runs over REST as a GET and a query string
				// cannot express an empty object. The same trap {@see no_input()}
				// documents for the no-argument abilities; an ability with only
				// optional arguments walks into it too.
				'default'              => new \stdClass(),
			),
			self::obj(
				array(
					'items'        => self::arr(
						array(
							'id'        => self::i( 'Post ID — feed this to check-page, update-content, write-description, write-topics.' ),
							'title'     => self::s(),
							'postType'  => self::s( 'The post type SLUG, for the write tools.' ),
							'typeLabel' => self::s( 'What this site calls one of these ("Post", "Product", "Doc").' ),
							'url'       => self::s( 'The public permalink.' ),
							'editUrl'   => self::s( 'The owner’s edit screen, for handing back a link a person can open.' ),
							'modified'  => self::s( 'When the post was last saved (GMT). Compare with `readAt` to see how far behind the verdict is.' ),
							'needsWork' => self::b( 'Whether this row is asking for something at all — true for every row of the "fixable" bucket.' ),
							'issues'    => self::arr(
								array(
									'id'    => self::s( 'The content check id ("summary", "headings", "reading_ease"…), as check-page reports it.' ),
									'label' => self::s( 'The problem in the site’s own words.' ),
								),
								'EVERY content check this page fails — not a preview. An empty list with needsWork true means the content is fine and the SEARCH half is what is wrong; read `coverage`.'
							),
							'points'    => self::i( 'Citability score 0–100 for this page: the share of content checks it passes. Only meaningful for article-like content.' ),
							'coverage'  => array(
								'type'        => 'string',
								'enum'        => array( 'answered', 'scattered', 'barely', 'missing', '' ),
								'description' => 'How well the page answers the search in `search`. answered = one passage carries the whole search; scattered = the words are all on the page but never together; barely = some of them; missing = none. Empty string when there is no search to judge it against — which is not a verdict, and must never be reported as a bad one.',
							),
							'search'    => array(
								'type'                 => array( 'object', 'null' ),
								'description'          => 'The search this page is judged on, or null when neither the author chose one nor a search engine reported one. `chosen` true = the AUTHOR named it (then `engine` is empty and the numbers are zero unless that search is also reported); false = the search engine named it, and `engine` says which. position/impressions/clicks are that engine’s own figures.',
								'properties'           => array(
									'query'       => self::s(),
									'chosen'      => self::b(),
									'engine'      => self::s(),
									'position'    => self::n(),
									'impressions' => self::i(),
									'clicks'      => self::i(),
								),
								'additionalProperties' => false,
							),
							'stake'     => self::i( 'What fixing this page is worth: impressions already being earned, scaled by how far the page is from answering the search that earns them. The ranking’s tie-breaker — a page that already answers has nothing at stake however busy it is.' ),
							'setAside'  => self::b( 'The owner excused this page from the worklist. ⛔ Never propose editing it unless they ask.' ),
							'stale'     => self::b( 'TRUE when this verdict must not be repeated: the owner saved the page after it was read, the page has not been read yet, the verdict was written by an older version of these checks, or the engines have moved which search the page is found for since it was read — in that last case `coverage` is the answer to a DIFFERENT search than the one in `search`, and saying the page does not answer the search shown would be wrong. In all four cases `issues`, `coverage` and `points` describe something other than the page as it stands. ⛔ Re-read it with check-page and report what THAT found. The background sweep repairs these by itself, usually within a minute.' ),
							'readAt'    => self::s( 'When the sweep last read this page (GMT), or empty when it never has — in which case this row is a place in the ranking, not a verdict.' ),
						),
						'One row per page, in the site’s own ranked order — the same order the owner sees.'
					),
					'filter'       => self::s( 'The bucket these rows came from.' ),
					'page'         => self::i(),
					'per'          => self::i(),
					'total'        => self::i( 'Rows in THIS bucket across the whole site — not the number returned.' ),
					'counts'       => self::obj(
						array(
							'fixable'  => self::i( 'Pages asking for something.' ),
							'clear'    => self::i( 'Pages read with nothing to fix.' ),
							'setAside' => self::i( 'Pages the owner excused.' ),
						)
					),
					'issue'        => self::s( 'The check id this list was narrowed to, or empty for the whole bucket. Echoed back so a caller paging through can be sure it is still walking the same set.' ),
					'issueLabel'   => self::s( 'That check’s problem name in the site’s language ("Featured image not described"), or empty. ⭐ It is why `total` can be smaller than `counts.fixable` without the two contradicting each other.' ),
					'grading'      => self::i( 'Published pages the sweep has NEVER read. Above zero means this ranking covers part of the site — say so rather than presenting it as the whole.' ),
					'rechecking'   => self::i( 'Pages read, then edited by the owner. They keep their place with `stale` true; the sweep re-reads them within about a minute.' ),
					'noSearchData' => self::i( 'How many pages no search engine has reported yet. Their `coverage` is empty for want of a search, not for want of quality — normal for recent posts, and normal for a whole site while a source is still working through it.' ),
					'engine'       => self::s( 'Whose search figures these are ("Google", "Bing"), or empty when no search source is connected — in which case every `search` here is one the AUTHOR chose.' ),
					'types'        => array(
						'type'        => 'array',
						'items'       => self::s(),
						'description' => 'The post-type slugs this list covers — what the owner has chosen to check. A type absent from here is not being checked at all, so its pages are missing from every count above by decision.',
					),
				)
			),
			function ( $input ) {
				// A bare call arrives as the empty object the schema defaults to,
				// never as an array — read it as "no arguments", not as a shape to
				// index into.
				$in       = is_array( $input ) ? $input : array();
				$worklist = new Worklist( $this->settings );
				// One chunk before answering, exactly as the owner's screen does:
				// on a site whose sweep has not run, an empty list would otherwise
				// read as "nothing needs fixing" — the most expensive lie this
				// tool could tell. Time-bounded by the sweep's own budget.
				$worklist->sweep();
				return $worklist->issues(
					isset( $in['filter'] ) ? (string) $in['filter'] : 'fixable',
					isset( $in['page'] ) ? (int) $in['page'] : 1,
					isset( $in['per'] ) ? (int) $in['per'] : 0,
					isset( $in['issue'] ) ? (string) $in['issue'] : ''
				);
			},
			$manage
		);

		$this->add(
			'check-page',
			__( 'Check a page’s AI readability', 'agentimus' ),
			'Checks ONE post/page’s readability for AI: grades how easily an AI can read, section and cite it — '
				. 'word count, an opening summary, concrete figures or cited sources, heading structure, quotable '
				. 'passage length, link density, image alt text, and freshness. Returns a pass/warn/fail row per '
				. 'check plus a tally. Use it when asked to check a page’s readability, or to tell an author '
				. 'exactly what to improve on a specific page.',
			self::obj(
				array(
					'post_id' => self::i( 'The post/page ID to grade.' ),
				),
				array( 'post_id' )
			),
			self::obj(
				array(
					'summary' => self::obj(
						array(
							'pass' => self::i(),
							'warn' => self::i(),
							'fail' => self::i(),
						)
					),
					'checks'  => self::status_rows_schema( false ),
				)
			),
			function ( $input ) {
				$post = get_post( (int) ( $input['post_id'] ?? 0 ) );
				if ( ! $post instanceof \WP_Post ) {
					return new \WP_Error( 'agentimus_not_found', __( 'Post not found.', 'agentimus' ), array( 'status' => 404 ) );
				}
				$rows = PageCheck::analyze( $post );
				return array(
					'summary' => PageCheck::summary( $rows ),
					'checks'  => $rows,
				);
			},
			array( $this, 'can_edit_post' )
		);

		$this->add(
			'read-content',
			__( 'Read a page’s body', 'agentimus' ),
			'Returns ONE post/page’s body EXACTLY as stored — the source an edit has to be written against, '
				. 'block comments, shortcodes, HTML entities and all. ⛔ Not a rendering: preview-markdown '
				. 'returns the .md twin, which is what an AI client reads, and check-page returns a grade; '
				. 'neither can be edited and sent back. This is the FIRST half of changing anything on a page '
				. '— read it here, then send edit-content an exact passage from what you got. '
				. '`format` says whether the body is block markup or classic HTML, because a paragraph is '
				. 'wrapped in comments in one and bare in the other. `editable` is false when this body is not '
				. 'the thing to change: a page builder owns the layout, or somebody has the post open in the '
				. 'editor right now — `note` says which, in words you can repeat to the owner.',
			self::obj(
				array(
					'post_id' => self::i( 'The post/page ID to read.' ),
				),
				array( 'post_id' )
			),
			self::obj(
				array(
					'postId'   => self::i( 'The page read.' ),
					'title'    => self::s( 'Its title.' ),
					'type'     => self::s( 'Post type slug.' ),
					'status'   => self::s( 'publish, draft, pending, private…' ),
					'url'      => self::s( 'Its address.' ),
					'modified' => self::s( 'When it was last saved (GMT). If this moves between your read and your edit, somebody else has been writing.' ),
					'format'   => self::s( '"blocks" when the body is block markup, "classic" when it is plain HTML.' ),
					'builder'  => self::s( 'The page builder that owns this page’s layout, empty when none does. Not empty ⇒ the body below is not what visitors see.' ),
					'lockedBy' => self::s( 'Who has the post open in the editor right now, empty when nobody does. An edit made while somebody is editing gets overwritten by their autosave.' ),
					'editable' => self::b( 'TRUE when edit-content can act on this body — no builder, nobody editing.' ),
					'content'  => self::s( 'The body, byte for byte as stored. Copy anchors for edit-content from HERE.' ),
					'length'   => self::i( 'Its length in characters.' ),
					'images'   => self::arr(
						self::obj(
							array(
								'index'        => self::i( 'Its position among the images on this page, from 1. This is what describe-content-image takes, and the only aim that is never ambiguous.' ),
								'src'          => self::s( 'The address the tag points at.' ),
								'alt'          => self::s( 'Its description as stored, empty when it has none.' ),
								'attachmentId' => self::i( 'Its media library id, 0 when the picture belongs to no attachment — pasted in, or hotlinked. Its alt still needs writing.' ),
								'decorative'   => self::b( 'TRUE when the tag carries alt="" — the standard way of saying the picture holds no meaning a reader needs. ⛔ On its own that is a decision, not a gap: the checks do not flag it and neither should you. Read it with `captioned`.' ),
								'captioned'    => self::b( 'TRUE when the author wrote a caption under it. ⭐ A captioned picture marked decorative is a contradiction the author wrote themselves — that pair is what `needs: "blank-alt"` reports, and it is the one empty alt this tool will write over without replace.' ),
								'needs'        => self::s( '"no-alt" when the tag has no alt attribute at all, "file-name" when its description is only the file name, "blank-alt" when it is marked decorative (alt="") yet carries a caption — which is the author saying it means something — and empty when nothing is wanted. These are the images behind an alt_text row.' ),
							)
						)
					),
					'note'     => self::s( 'Anything that makes this body not the thing to edit, in plain words. Empty when there is nothing to say.' ),
				)
			),
			function ( $input ) {
				$in = is_array( $input ) ? $input : array();
				return ( new ContentEditor() )->read( isset( $in['post_id'] ) ? (int) $in['post_id'] : 0 );
			},
			array( $this, 'can_edit_post' )
		);

		$this->add(
			'suggest-internal-links',
			__( 'Suggest internal links for a page', 'agentimus' ),
			'Suggests which of the site’s OWN posts this post should link to, from local signals only '
				. '(shared topics, categories/tags, and the candidate’s subject appearing in the text) — no AI '
				. 'call is spent. Each suggestion carries the target post, the exact phrase in this post’s text '
				. 'to link (empty when none exists — append a "See also" line instead), and a one-line reason. '
				. 'READ-ONLY: it suggests; to actually insert a link, edit the post through the governed '
				. 'update tool like any other content change.',
			self::obj(
				array(
					'post_id' => self::i( 'The post/page ID to suggest links for.' ),
				),
				array( 'post_id' )
			),
			self::obj(
				array(
					'suggestions' => self::arr(
						array(
							'id'     => self::i(),
							'title'  => self::s(),
							'url'    => self::s(),
							'phrase' => self::s(),
							'why'    => self::s(),
						)
					),
				)
			),
			function ( $input ) {
				$post = get_post( (int) ( $input['post_id'] ?? 0 ) );
				if ( ! $post instanceof \WP_Post ) {
					return new \WP_Error( 'agentimus_not_found', __( 'Post not found.', 'agentimus' ), array( 'status' => 404 ) );
				}
				// Deterministic local path — an agent's read never spends the owner's AI budget.
				return array( 'suggestions' => ( new InternalLinks( $this->settings ) )->suggest( $post, false ) );
			},
			array( $this, 'can_edit_post' )
		);

		$this->add(
			'preview-schema',
			__( 'Preview a page’s JSON-LD', 'agentimus' ),
			'Returns the JSON-LD @graph Agentimus would emit — for a specific post (pass post_id) or the '
				. 'site-wide identity graph (omit post_id / pass 0). Includes both the structured object and a '
				. 'pretty-printed JSON string. Read-only preview: it reflects the current draft, and changes '
				. 'nothing.',
			self::obj(
				array(
					'post_id' => self::i( 'Post/page ID to preview; omit or 0 for the site-wide identity graph.' ),
				)
			),
			self::obj(
				array(
					'graph' => array(
						'type'                 => array( 'object', 'null' ),
						'description'          => 'JSON-LD document { "@context", "@graph": [ …nodes ] }, or null when there is nothing to emit.',
						'additionalProperties' => true,
					),
					'json'  => self::s(),
				)
			),
			function ( $input ) {
				$post_id = (int) ( $input['post_id'] ?? 0 );
				$post    = $post_id > 0 ? get_post( $post_id ) : null;
				$doc     = ( new Schema( $this->settings ) )->build_document( $post, null, true );
				return array(
					'graph' => $doc,
					'json'  => null === $doc ? '' : (string) wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				);
			},
			$manage
		);

		$this->add(
			'preview-markdown',
			__( 'Preview a page’s Markdown twin', 'agentimus' ),
			'Returns the plain-Markdown version of a post — the ".md twin" Agentimus serves to AI clients that '
				. 'prefer clean text over rendered HTML. Read-only preview of a single post; the site-wide index '
				. 'lives at /index.md.',
			self::obj(
				array(
					'post_id' => self::i( 'The post/page ID to render as Markdown.' ),
				),
				array( 'post_id' )
			),
			self::obj(
				array(
					'markdown' => self::s(),
				)
			),
			function ( $input ) {
				$post_id = (int) ( $input['post_id'] ?? 0 );
				if ( $post_id <= 0 || ! get_post( $post_id ) ) {
					return new \WP_Error( 'agentimus_not_found', __( 'Post not found.', 'agentimus' ), array( 'status' => 404 ) );
				}
				return array( 'markdown' => (string) Markdown::post( $post_id ) );
			},
			$manage
		);

		/* -- Site hardening -------------------------------------------------- */

		$this->add(
			'scan-exposed-files',
			__( 'List exposed-file risks & debug posture', 'agentimus' ),
			'Returns the list of sensitive paths the exposed-files self-check probes for (config backups, '
				. '.env, VCS metadata, DB dumps, keys) plus the site’s WordPress debug posture and detected '
				. 'environment. NOTE: this SUPPLIES what to check and flags the debug config — it does not fetch '
				. 'the URLs. The live probe must run same-origin from the admin browser so a server loopback '
				. 'cannot mask a leak the real public URL would reveal.',
			self::no_input(),
			self::obj(
				array(
					'probePaths'  => array(
						'type'  => 'array',
						'items' => self::s(),
					),
					'debugConfig' => self::obj(
						array(
							'state'   => array(
								'type' => 'string',
								'enum' => array( 'pass', 'warn', 'fail' ),
							),
							'message' => self::s(),
							'fix'     => self::s(),
							'fixUrl'  => self::s(),
						)
					),
					'environment' => self::s(),
				)
			),
			function () {
				return array(
					'probePaths'  => Exposure::sensitive_paths( $this->settings ),
					'debugConfig' => Exposure::debug_status(),
					'environment' => Exposure::environment_type(),
				);
			},
			$manage
		);

		$this->add(
			'search-media',
			__( 'Search the media library', 'agentimus' ),
			'Finds images and other attachments ALREADY in this site\'s media library, so an agent can name '
				. 'one by id — most often as the featured_image on create-content or update-content, which '
				. 'accepts an attachment id but had no way to discover one. Searches titles, captions and '
				. 'descriptions AND alt text (alt is where photographs are usually described — a file called '
				. 'IMG_4831.jpg may carry the alt "sunrise over the river", and a title-only search would '
				. 'miss it). An empty query returns the most recent uploads, which is the right way to ask '
				. '"what is in here?". Returns each attachment\'s id, title, alt, url, mime type, pixel '
				. 'dimensions and upload date — the url so a client that can see images may look before it '
				. 'chooses, the dimensions because a 300px logo is not a featured image. '
				. 'READ-ONLY: it uploads nothing and changes nothing. To bring in a picture the library does '
				. 'not have, pass an http(s) image URL as featured_image instead and the write tools import '
				. 'it. There is deliberately no way to upload bytes through this server.',
			self::obj(
				array(
					'query' => self::s( 'Words to look for in titles, captions, descriptions and alt text. Omit or leave empty for the most recent uploads.' ),
					'limit' => self::i( 'How many to return, 1–' . Media::MAX_RESULTS . ' (default ' . Media::DEFAULT_RESULTS . ').' ),
					'mime'  => self::s( 'MIME filter, matched as a prefix — "image" (the default) for pictures, "video", "audio", "application/pdf", or "" for everything in the library.' ),
					'undescribed' => array(
						'type'        => 'boolean',
						'description' => 'TRUE lists the images that have NO description — newest first — instead of searching. This is how you find the media half of the alt-text work: describe-image writes one by id, and nothing else can tell you which ones need it, because the images that need describing are exactly the ones no words match. An alt that is only the file name counts as undescribed here, the same way the content checks count it. `query` is ignored when this is on.',
						'default'     => false,
					),
				)
			),
			self::obj(
				array(
					'total' => self::i( 'How many rows this answer holds. Never more than limit; a full page may mean there are more, so narrow the query rather than assuming this is everything.' ),
					'scanned' => self::i( 'With undescribed: how many DESCRIBED images were read to find file-name alts among them, capped at ' . Media::SCAN_CAP . '. Images with no alt at all are found exactly, in the database, and are never limited by this. A number equal to the cap means the file-name pass saw part of the library, not all of it — say so rather than reporting a complete list. 0 on an ordinary search.' ),
					'items' => self::arr(
						self::obj(
							array(
								'id'     => self::i( 'Attachment ID — this is what featured_image takes.' ),
								'title'  => self::s( 'The attachment title, which is often just the filename.' ),
								'alt'    => self::s( 'Alt text, empty when the owner never wrote any. The most reliable description of what a picture SHOWS.' ),
								'url'    => self::s( 'Public URL of the full-size file.' ),
								'mime'   => self::s( 'e.g. image/jpeg.' ),
								'width'  => self::i( 'Pixel width, 0 when unknown or not an image.' ),
								'height' => self::i( 'Pixel height, 0 when unknown or not an image.' ),
								'date'   => self::s( 'Upload date, YYYY-MM-DD.' ),
							)
						)
					),
				)
			),
			function ( $input ) {
				$limit = isset( $input['limit'] ) ? (int) $input['limit'] : Media::DEFAULT_RESULTS;
				$mime  = isset( $input['mime'] ) ? (string) $input['mime'] : 'image';

				// The undescribed filter answers a different question from a
				// search, so it does not pretend to be one: a query alongside it
				// would have to mean "undescribed AND matching", and the images
				// this finds are precisely the ones no words match.
				if ( ! empty( $input['undescribed'] ) ) {
					$found = Media::undescribed( $limit, $mime );
					return array(
						'total'   => count( $found['items'] ),
						'items'   => $found['items'],
						'scanned' => (int) $found['scanned'],
					);
				}

				$items = Media::search(
					isset( $input['query'] ) ? (string) $input['query'] : '',
					$limit,
					$mime
				);
				return array(
					'total'   => count( $items ),
					'items'   => $items,
					'scanned' => 0,
				);
			},
			// The media library's own capability, not the site-admin bar the
			// reporting tools use: this reads uploads, and upload_files is what
			// WordPress asks for at upload.php. An editor who can see the library
			// in wp-admin can see it here, and nobody else can.
			static function () {
				return current_user_can( 'upload_files' );
			}
		);


		/* -- Announcements & integrations ------------------------------------ */
		$this->add(
			'read-announcements',
			__( 'Read the announcement ledger', 'agentimus' ),
			'Returns what this site has queued, posted or failed to post to its social channels, newest '
				. 'first. `status` is one of queued / sent / failed / cancelled. A FAILED row carries `error` '
				. 'saying what the network answered — that sentence is the whole point of this tool, because '
				. 'it is the only place the reason survives. `retry-announcement` can re-queue a failed row; '
				. 'nothing here can write a new announcement. '
				. '⛔ This lists what was SCHEDULED, not what a network shows now: a post deleted at the '
				. 'network still reads "sent" here, because this is the site’s own record of what it sent.',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'page' => self::i( 'Which page of the ledger (1-based, 20 a page).' ),
				),
				'additionalProperties' => false,
				'default'              => new \stdClass(),
			),
			self::obj(
				array(
					'rows'    => self::arr(
						array(
							'id'          => self::s( 'Pass this to retry-announcement.' ),
							'postId'      => self::i( 'The post being announced — 0 if the row has none.' ),
							'network'     => self::s( 'Where it goes: x, linkedin, telegram, …' ),
							'status'      => self::s( 'queued · sent · failed · cancelled.' ),
							'title'       => self::s( 'The post being announced.' ),
							'scheduledAt' => self::s( 'ISO 8601, UTC. When it goes out, or went.' ),
							'error'       => self::s( 'Why it failed, in the network’s own words. Empty unless status is "failed".' ),
							'canRetry'    => self::b( 'TRUE only on a failed row — retry-announcement refuses anything else.' ),
						)
					),
					'summary' => self::obj(
						array(
							'total'    => self::i( 'Every row the ledger holds.' ),
							'queued'   => self::i( 'Waiting to go out.' ),
							'failed'   => self::i( 'Rows worth telling the owner about — each carries its reason.' ),
							// ⛔ sentWeek, NOT "sent": summary() counts the last seven days
							// only. Calling it `sent` would report an all-time total this
							// site never computes, and the first cut read a `sent` key that
							// does not exist — so it was 0 next to a ledger showing a sent
							// row. Caught on his live site, not here.
							'sentWeek' => self::i( 'Sent in the last 7 days. NOT an all-time count.' ),
						)
					),
				)
			),
			function ( $input ) {
				$in   = is_array( $input ) ? $input : array();
				$page = isset( $in['page'] ) ? max( 1, (int) $in['page'] ) : 1;

				$ann  = new \Agentimus\Integrations\Announcements( $this->settings );
				$list = (array) $ann->rows( $page, 20 );
				$rows = array();
				// ⛔ A STORED ROW HAS NO TITLE — it keeps `post_id` and `body`. Reading
				// a 'title' key off it returns nothing on every row, which is a bug
				// that looks like an empty ledger rather than like a mistake. The name
				// comes from the post, through the one decoder.
				foreach ( (array) ( isset( $list['rows'] ) ? $list['rows'] : array() ) as $r ) {
					$r       = (array) $r;
					$status  = isset( $r['status'] ) ? (string) $r['status'] : '';
					$post_id = isset( $r['post_id'] ) ? (int) $r['post_id'] : 0;
					$rows[]  = array(
						'id'          => (string) ( isset( $r['id'] ) ? $r['id'] : '' ),
						'postId'      => $post_id,
						'network'     => isset( $r['network'] ) ? (string) $r['network'] : '',
						'status'      => $status,
						'title'       => $post_id > 0 ? \Agentimus\Worklist::title_of( $post_id ) : '',
						'scheduledAt' => ! empty( $r['scheduled_at'] ) ? gmdate( 'c', (int) $r['scheduled_at'] ) : '',
						'error'       => isset( $r['error'] ) ? (string) $r['error'] : '',
						'canRetry'    => 'failed' === $status,
					);
				}

				$sum = (array) $ann->summary();
				return array(
					'rows'    => $rows,
					'summary' => array(
						'total'    => (int) ( isset( $sum['total'] ) ? $sum['total'] : 0 ),
						'queued'   => (int) ( isset( $sum['queued'] ) ? $sum['queued'] : 0 ),
						'failed'   => (int) ( isset( $sum['failed'] ) ? $sum['failed'] : 0 ),
						'sentWeek' => (int) ( isset( $sum['sentWeek'] ) ? $sum['sentWeek'] : 0 ),
					),
				);
			},
			$manage
		);

		$this->add(
			'read-integrations',
			__( 'See what this site is connected to', 'agentimus' ),
			'Returns the services this site sends its own reports to (Telegram, Slack, Discord, a Google '
				. 'Sheet, a webhook), and the plugins whose content it describes to AI assistants. Use it to '
				. 'answer "is anything broken?" — `state` and `lastError` say whether a channel is actually '
				. 'delivering, and `queued` how many reports are waiting behind a failure. '
				. '⛔ NO ADDRESS OR CREDENTIAL IS RETURNED, and that is deliberate rather than an oversight: '
				. 'a Slack or Discord webhook URL IS the power to post in that channel, and a Telegram chat '
				. 'id plus the bot token is the same. `connected` tells you whether one is set; nothing here '
				. 'hands over the means to use it. '
				. '⛔ Read-only. Connecting, disconnecting and turning a channel off all ask the owner to '
				. 'confirm in the admin, so no tool here can do them.',
			array(
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
				'default'              => new \stdClass(),
			),
			self::obj(
				array(
					'services' => self::arr(
						array(
							'id'              => self::s( 'webhook · telegram · slack · discord · sheets.' ),
							'connected'       => self::b( 'Whether the owner has set this one up. ⛔ The address itself is never returned.' ),
							'enabled'         => self::b( 'Whether it is switched on. A service can be connected and off.' ),
							// ⛔ A PLAIN STRING LIST. self::arr() takes ITEM PROPERTIES and
							// builds an array of OBJECTS — declaring it that way made the
							// whole ability return a WP_Error the moment any service had an
							// event set, which is every site that uses this feature. It only
							// showed once the field was forced to populate.
							'events'          => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Which moments it receives.',
							),
							'queued'          => self::i( 'Reports waiting to go out. A number that only grows means deliveries are failing.' ),
							'stalledForSecs'  => self::i( 'How long the queue has been stuck, 0 when moving.' ),
							'lastDeliveredAt' => self::s( 'ISO 8601, UTC. Empty when nothing has ever landed.' ),
							'lastError'       => self::s( 'The most recent failure in plain words, empty when healthy. A success clears it.' ),
						)
					),
					'plugins'  => self::arr(
						array(
							'id'        => self::s(),
							'name'      => self::s( 'What the plugin calls itself.' ),
							'installed' => self::b( 'Whether it runs on this site.' ),
							'described' => self::b( 'Whether anything of its content reaches AI assistants. FALSE on an installed plugin means it keeps everything behind a login.' ),
							'home'      => self::s( 'The plugin’s own page, empty when none is held.' ),
						)
					),
				)
			),
			function () {
				$dispatcher = new \Agentimus\Integrations\Dispatcher( $this->settings );
				$services   = array();

				// ⛔ A DELIBERATE PROJECTION, never a reuse of the admin payload:
				// that one carries the webhook/Slack/Discord URLs and the Telegram
				// chat id, and those are credentials in effect — a Slack webhook URL
				// IS the power to post in that channel. Every field below is named on
				// purpose; adding one means deciding it is safe to hand an agent,
				// not noticing it was already in the array.
				// ⚠️ Namespace is …\Integrations\Services, NOT …\Integrations. The
				// first cut had it wrong, is_callable() answered false for all five,
				// and the loop produced an EMPTY list in silence — every site would
				// have read "nothing connected".
				$map = array(
					'webhook'  => '\Agentimus\Integrations\Services\Webhook',
					'telegram' => '\Agentimus\Integrations\Services\Telegram',
					'slack'    => '\Agentimus\Integrations\Services\Slack',
					'discord'  => '\Agentimus\Integrations\Services\Discord',
					'sheets'   => '\Agentimus\Integrations\Services\Sheets',
				);
				foreach ( $map as $id => $klass ) {
					if ( ! class_exists( $klass ) ) {
						continue;
					}
					$cfg   = (array) $klass::config( $this->settings );
					// ⚠️ state() returns an ARRAY (lastDeliveredAt / lastError /
					// lastErrorAt), not a word. Casting it to string yields "Array".
					$state = (array) $klass::state();

					// "Connected" is asked of whatever THIS service needs — the keys
					// differ (url · chat · spreadsheet) — and never of a field we then
					// return. The address stays in here.
					$connected = false;
					foreach ( array( 'url', 'chat', 'spreadsheet' ) as $needle ) {
						if ( ! empty( $cfg[ $needle ] ) ) {
							$connected = true;
						}
					}

					$services[] = array(
						'id'              => (string) $id,
						'connected'       => $connected,
						'enabled'         => ! empty( $cfg['enabled'] ),
						'events'          => array_values( array_map( 'strval', (array) ( isset( $cfg['events'] ) ? $cfg['events'] : array() ) ) ),
						'queued'          => (int) $dispatcher->depth_for( $id ),
						'stalledForSecs'  => (int) $dispatcher->stalled_for( $id ),
						'lastDeliveredAt' => ! empty( $state['lastDeliveredAt'] ) ? gmdate( 'c', (int) $state['lastDeliveredAt'] ) : '',
						'lastError'       => isset( $state['lastError'] ) ? (string) $state['lastError'] : '',
					);
				}

				$plugins  = array();
				$document = \Agentimus\Discovery\Registry::instance()->collect();
				foreach ( \Agentimus\Integrations\Plugins\Provider::ROSTER as $class ) {
					$card      = (array) $class::describe( $document );
					$plugins[] = array(
						'id'        => isset( $card['id'] ) ? (string) $card['id'] : '',
						'name'      => isset( $card['name'] ) ? (string) $card['name'] : '',
						'installed' => ! empty( $card['present'] ),
						'described' => ! empty( $card['describes'] ),
						'home'      => isset( $card['home'] ) ? (string) $card['home'] : '',
					);
				}

				return array( 'services' => $services, 'plugins' => $plugins );
			},
			$manage
		);

		$this->register_resource_abilities();

		// The write tier exists only when the owner deliberately turned it on — AND the
		// MCP server itself is on, because that is where the switch lives in the UI: a
		// sub-toggle the owner can't see (MCP card collapsed) must never still be armed
		// on another surface. The gate sits at REGISTRATION (this hook re-runs per
		// request, reading live settings), so flipping either switch off removes the
		// abilities from every surface at once. Settings::sanitize() additionally
		// cascades the stored flags off, so the state can't outlive its visibility.
		if ( $this->settings->enabled( 'enable_mcp_server' ) && $this->settings->enabled( 'enable_agent_writes' ) ) {
			$this->register_write_abilities( $manage );
		}
	}

	/**
	 * The documents this site publishes for agents, registered as abilities so the
	 * MCP server can offer them as RESOURCES rather than as tools.
	 *
	 * The distinction is the point. A tool is something a model DOES; a resource
	 * is something a client can read and attach on its own, listed with a URI and
	 * a MIME type. Agentimus has spent its life producing exactly this kind of
	 * thing — an llms.txt index, a full-text edition, a discovery document, an
	 * agent card — and until now an agent could only reach them by fetching URLs
	 * over the open web, which assumes the client has web access at all and knows
	 * this site's conventions. Offered here, they are simply listed.
	 *
	 * Nothing is generated twice: each callback asks the same builder the public
	 * endpoint asks, in-process. No loopback HTTP — a request to our own site to
	 * read our own document would be the slowest possible way to answer, and the
	 * one most likely to fail behind auth or a cache.
	 *
	 * Only documents the site ACTUALLY serves are registered. Advertising a
	 * resource for a disabled endpoint would hand an agent a URI that 404s, which
	 * is worse than not offering it.
	 */
	private function register_resource_abilities() {
		// Public documents: anyone on the open web can already read these, so the
		// gate is only "you got through the door", which the server already
		// enforces. A capability check here would be theatre.
		$anyone = static function () {
			return true;
		};

		if ( $this->settings->enabled( 'enable_llms_txt' ) ) {
			$this->add(
				'llms-txt',
				__( 'llms.txt — this site\'s index for AI', 'agentimus' ),
				'The site\'s llms.txt: a plain-text index of what this site is, what it publishes, and where '
					. 'the important pages are, in the llmstxt.org convention. Read this FIRST to understand a '
					. 'site before fetching anything from it — it is the map, written by the owner rather than '
					. 'inferred from crawling.',
				self::obj( array() ),
				self::obj( array( 'content' => self::s( 'The document, as plain text.' ) ) ),
				function () {
					return array( 'content' => ( new LlmsText( $this->settings ) )->llms_txt() );
				},
				$anyone,
				true,
				false,
				array( 'uri' => home_url( '/llms.txt' ), 'mimeType' => 'text/plain' )
			);
		}

		if ( $this->settings->enabled( 'enable_llms_txt' ) && $this->settings->enabled( 'enable_llms_full' ) ) {
			$this->add(
				'llms-full-txt',
				__( 'llms-full.txt — this site\'s full text', 'agentimus' ),
				'The site\'s llms-full.txt: the full text of its recent content in one document, so an agent '
					. 'can ingest the site in a single read instead of crawling page by page. Larger than '
					. 'llms.txt and bounded by the owner\'s size budget — read llms.txt first if you only need '
					. 'the shape of the site.',
				self::obj( array() ),
				self::obj( array( 'content' => self::s( 'The document, as plain text.' ) ) ),
				function () {
					return array( 'content' => ( new LlmsText( $this->settings ) )->llms_full_txt() );
				},
				$anyone,
				true,
				false,
				array( 'uri' => home_url( '/llms-full.txt' ), 'mimeType' => 'text/plain' )
			);
		}

		$this->add(
			'discovery-json',
			__( 'discovery.json — identity, capabilities and APIs', 'agentimus' ),
			'The site\'s /.well-known/discovery.json: one normalized document naming who runs this site, what '
				. 'it can do, which APIs it exposes and which agent cards it publishes. Owner-curated, not '
				. 'inferred — the single predictable place to look before deciding how to work with a site.',
			self::obj( array() ),
			self::obj( array( 'content' => self::s( 'The document, as JSON text.' ) ) ),
			function () {
				return array( 'content' => ( new Envelope( $this->settings, Registry::instance() ) )->discovery_json() );
			},
			$anyone,
			true,
			false,
			array( 'uri' => home_url( '/.well-known/discovery.json' ), 'mimeType' => 'application/json' )
		);

		$this->add(
			'agent-card-json',
			__( 'agent-card.json — this site as an A2A agent', 'agentimus' ),
			'The site\'s /.well-known/agent-card.json: an A2A agent card describing this site as something '
				. 'another agent can talk to — its name, description, skills and endpoints.',
			self::obj( array() ),
			self::obj( array( 'content' => self::s( 'The document, as JSON text.' ) ) ),
			function () {
				return array( 'content' => ( new Envelope( $this->settings, Registry::instance() ) )->agent_card_json() );
			},
			$anyone,
			true,
			false,
			array( 'uri' => home_url( '/.well-known/agent-card.json' ), 'mimeType' => 'application/json' )
		);


	}

	/**
	 * The abilities offered as MCP RESOURCES — the documents, never the reports.
	 *
	 * Kept apart from mcp_abilities() deliberately: a client lists tools and
	 * resources separately and treats them differently, and the same ability in
	 * both lists would be offered twice with two meanings.
	 *
	 * @return string[]
	 */
	public function mcp_resources() {
		$names = array();
		foreach ( array( 'llms-txt', 'llms-full-txt', 'discovery-json', 'agent-card-json' ) as $slug ) {
			// Registered conditionally above, so ask rather than assume: a resource
			// pointing at a switched-off endpoint would 404 for whoever read it.
			if ( function_exists( 'wp_get_ability' ) && wp_get_ability( self::CATEGORY . '/' . $slug ) ) {
				$names[] = self::CATEGORY . '/' . $slug;
			}
		}

		/**
		 * The documents Agentimus offers over MCP as readable resources.
		 *
		 * @param string[] $names Ability names.
		 */
		return (array) apply_filters( 'agentimus_mcp_server_resources', $names );
	}

	/**
	 * Register the write abilities — the opt-in tier (see the class doc).
	 *
	 * @param callable $manage The site-admin permission callback.
	 */
	private function register_write_abilities( $manage ) {
		$summary = self::written_post_schema();

		$this->add(
			'create-content',
			__( 'Create a post or page', 'agentimus' ),
			self::guided(
				'Creates a post or page as the connected user — a DRAFT unless told otherwise — fully dressed '
				. 'in one call: content, categories, tags, featured image (an existing attachment ID or an '
				. 'image URL to import), plus its AI description and Topics for AI. status=publish needs the '
				. 'site’s "agents may publish" switch on top of the user’s own publish permission; when it is '
				. 'off, create a draft (or pending) for the owner to review. Returns the new id and the saved '
				. 'draft’s AI-readability grade — fix anything under `attention`; check-page has the full '
				. 'per-check detail. '
				// You write the content, not us — this tool stores what it is
				// handed. So the house rules for the two shapes have to be stated
				// here, in the contract you read before you write, rather than
				// applied in a prompt we control. The in-admin assistant follows
				// the same two shapes; this keeps the site's content consistent
				// no matter which door it came through.
				. 'HOW TO WRITE EACH TYPE — a page ("page", or any hierarchical type) is a standing part of the '
				. 'site (About, Services, Terms, Contact) that a reader arrives at to get one thing done. Write '
				. 'it short: no article-style opening, headings only where the page truly has parts, no '
				. 'categories or tags, and no images unless the owner asked for one. A post (or any '
				. 'non-hierarchical type) is an article: open with a self-contained paragraph that answers the '
				. 'title, then sections. On a page that states terms or promises — legal, privacy, refunds — '
				. 'state only what the user actually told you; leave a plain [placeholder] wherever a fact is '
				. 'missing rather than inventing a jurisdiction, a period, an address or a guarantee.'
			),
			self::obj(
				array(
					'type'          => array(
						'type'        => 'string',
						'enum'        => Content::post_types(),
						'description' => 'Content type. Defaults to "post".',
					),
					'title'         => self::s( 'The title. Required.' ),
					'content'       => self::s( 'The body, as HTML (Gutenberg block markup welcome). WordPress sanitises it according to the connected user’s capabilities.' ),
					'excerpt'       => self::s( 'Optional manual excerpt.' ),
					'slug'          => self::s( 'Optional URL slug; derived from the title when omitted.' ),
					'status'        => self::status_input_schema(),
					'categories'    => self::terms_input_schema( 'categories' ),
					'tags'          => self::terms_input_schema( 'tags' ),
					'featured_image' => self::featured_image_input_schema(),
					'featured_image_alt' => self::s( 'Alt text for an imported featured image.' ),
					'description'   => self::s( 'The page’s AI description — one plain sentence (≤300 chars). Feeds the JSON-LD description, the .md lead and (when enabled) the meta-description tag.' ),
					'topics'        => self::topics_input_schema(),
					'topics_derive' => array(
						'type'        => 'boolean',
						'description' => 'Whether this page should ALSO derive topics from its tags & categories (omit to follow the site default).',
					),
				),
				array( 'title' )
			),
			$summary,
			function ( $input ) {
				return ( new ContentWriter( $this->settings ) )->create( is_array( $input ) ? $input : array() );
			},
			array( $this, 'can_create_content' ),
			false
		);

		$this->add(
			'update-content',
			__( 'Update a post or page', 'agentimus' ),
			self::guided(
				'Updates a post or page as the connected user — only the fields you pass change; everything '
				. 'else behaves as a normal editor save. Moving something TO publish needs the site’s '
				. '"agents may publish" switch; editing an already-published post follows the user’s normal '
				. 'edit permission. Can set the AI description / Topics for AI, categories, tags and the '
				. 'featured image alongside the content, or on their own. CAUTION: fields REPLACE — passing '
				. 'content replaces the current body (posts and pages keep a revision of the old one, but a '
				. 'content type without revision support does not), and a categories/tags list replaces the '
				. 'current list ([] clears it). The response includes the post’s AI-readability grade after the save. '
				. 'A page a page builder owns (Elementor, Divi, Beaver Builder and similar) refuses a body '
				. 'replacement — its real content lives in the builder, and the tool says so instead of '
				. 'silently changing nothing; every other field still works there. '
				// Same reason as create-content: the words are yours, so the rule
				// has to travel in the contract. Editing carries one rule of its
				// own — the shape is already decided by what you are editing.
				. 'HOW TO WRITE EACH TYPE — keep the shape the target already has: a page ("page", or any '
				. 'hierarchical type) stays a short standing page (no article opening, no invented sections, no '
				. 'categories or tags), and a post stays an article. Do not rewrite a page into an article '
				. 'because it reads thin — a Terms page is not improved by an introduction. On any page that '
				. 'states terms or promises, never introduce a fact the page did not already carry: an invented '
				. 'detail there is a commitment the owner never made.'
			),
			self::obj(
				array(
					'post_id'       => self::i( 'The post/page ID to update.' ),
					'title'         => self::s( 'New title.' ),
					'content'       => self::s( 'New body, as HTML. Replaces the current body — a revision keeps the old one only on content types that support revisions (posts and pages do). Refused on a page a page builder owns.' ),
					'excerpt'       => self::s( 'New manual excerpt.' ),
					'slug'          => self::s( 'New URL slug.' ),
					'status'        => self::status_input_schema(),
					'categories'    => self::terms_input_schema( 'categories' ),
					'tags'          => self::terms_input_schema( 'tags' ),
					'featured_image' => self::featured_image_input_schema(),
					'featured_image_alt' => self::s( 'Alt text for an imported featured image.' ),
					'description'   => self::s( 'The page’s AI description — one plain sentence (≤300 chars). Pass an empty string to clear it (the excerpt then takes over).' ),
					'topics'        => self::topics_input_schema(),
					'topics_derive' => array(
						'type'        => 'boolean',
						'description' => 'Whether this page should ALSO derive topics from its tags & categories.',
					),
					// The two search fields, so one call can finish a page rather
					// than leaving the last step to a tool nobody thinks to look
					// for {@see write-search-fields, which is these two alone}.
					'focus'         => self::s( 'The search this page should answer — what every worklist row measures it against. Comma-separate several; the first is the one it is judged on. Empty string clears the choice. ⚠️ Changing it marks the page’s stored verdict out of date until the sweep reads it again.' ),
					'seo_title'     => self::s( 'The title a search result shows, instead of the post title. Empty string clears it. ⛔ On a site where an SEO plugin owns titles the WHOLE call is refused rather than storing a value nothing serves — omit this field there; every other one still works.' ),
				),
				array( 'post_id' )
			),
			$summary,
			function ( $input ) {
				return ( new ContentWriter( $this->settings ) )->update( is_array( $input ) ? $input : array() );
			},
			array( $this, 'can_edit_post' ),
			false,
			true // A body replacement is unrecoverable on a type without revisions.
		);

		$this->add(
			'edit-content',
			__( 'Change one passage of a page', 'agentimus' ),
			self::guided(
				'Replaces ONE passage of a post/page body, leaving everything else exactly as it was. This is '
				. 'the tool for FIXING a page — adding a source link, splitting a long paragraph, writing the '
				. 'opening sentence a check asked for. ⛔ Reach for update-content only when the whole body is '
				. 'genuinely being rewritten: it replaces everything, and on a page that only needed one '
				. 'sentence that is a rewrite nobody asked for. '
				. 'HOW IT WORKS: read-content first, copy the exact text you mean into `old`, put what should '
				. 'stand in its place in `new`. `old` must appear in the stored body EXACTLY ONCE — not found '
				. 'is refused (the body is not what you think, so nothing is guessed at), found twice is '
				. 'refused with the count (include more surrounding text until it is unique). Anchors come '
				. 'from read-content and nowhere else: the rendered page and the .md twin both differ from '
				. 'the source. '
				. 'THREE THINGS IT WILL NOT DO, whatever it is asked: leave a block comment half-open, empty '
				. 'the page, or write while somebody has the post open in the editor (their autosave would '
				. 'undo it minutes later). Each is refused with the reason and nothing is written. '
				. 'A page a page builder owns is refused too — its content is not in this body. '
				. 'Pass dry_run to see exactly what the edit would produce without writing it. '
				. 'The previous body is kept as a revision, and the answer returns its id.'
			),
			self::obj(
				array(
					'post_id' => self::i( 'The post/page to edit — the `id` from any read-content-issues row.' ),
					'old'     => self::s( 'The exact text to replace, copied from read-content’s `content`. Must appear exactly once.' ),
					'new'     => self::s( 'What stands in its place. An empty string deletes the passage — allowed, as long as the page does not end up empty.' ),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'True checks the edit and returns what it would produce, without writing anything.',
						'default'     => false,
					),
				),
				array( 'post_id', 'old' )
			),
			self::obj(
				array(
					'postId'     => self::i( 'The page acted on.' ),
					'title'      => self::s( 'Its title.' ),
					'changed'    => self::b( 'TRUE only when the body was actually written. A dry run and a no-op both answer false — report that honestly rather than as a change.' ),
					'dryRun'     => self::b( 'TRUE when nothing was written because dry_run was asked for.' ),
					'context'    => self::s( 'The changed passage with the text either side of it, so you can SHOW what landed instead of asserting it.' ),
					'revisionId' => self::i( 'The revision holding the body from before this edit — the way back. 0 when nothing was written.' ),
					'length'     => self::i( 'The body’s length in characters afterwards.' ),
					'message'    => self::s( 'What happened, in the site’s own words.' ),
				)
			),
			function ( $input ) {
				return ( new ContentEditor() )->edit( is_array( $input ) ? $input : array() );
			},
			array( $this, 'can_edit_post' ),
			false,
			// Not destructive in the annotation's sense, and the reason is the
			// guards rather than the intent: the change is one anchored passage,
			// it cannot empty the page or break its blocks, and the body it
			// replaced is kept as a revision. ⚠️ On a content type with no
			// revision support there is no copy — the same caveat update-content
			// is annotated destructive FOR, and the difference is that a passage
			// is not a whole article.
			false
		);

		$this->add(
			'describe-content-image',
			__( 'Describe an image inside a page', 'agentimus' ),
			self::guided(
				'Writes the alt text on an image that sits INSIDE a page’s body — the fix for the '
				. '“Image alt text” check, the in-content twin of describe-image. '
				. '⛔ THE TWO ARE NOT INTERCHANGEABLE, and this is the thing to understand before using '
				. 'either. WordPress copies an image’s library description into the page at the moment it '
				. 'is inserted and never looks at it again, so the alt a reader gets from a published page '
				. 'is the one written in the BODY. describe-image writes the library copy: on a page that '
				. 'already exists that changes nothing at all. This writes the body, which is where the '
				. 'check reads and where readers read. '
				. 'FIRST read-content, whose `images` list names every picture in the body with its index, '
				. 'its file, its current description and a `needs` of "no-alt" or "file-name" — those are '
				. 'the images an alt_text row is about. Then aim this at ONE of them: `index` is what that '
				. 'list gives you and is never ambiguous; `src` and `attachment_id` also work, and naming '
				. 'more than one of the three is refused rather than ranked. On a page with exactly one '
				. 'image you may aim at nothing — there is no choice to make. '
				. '⛔ IT WILL NOT OVERWRITE A DECISION. An image somebody has already described is refused '
				. 'unless you send replace=true, and so is alt="" — that is the standard marker for a '
				. 'decorative picture, the checks deliberately do not flag it, and a fixing run announcing '
				. 'every spacer and divider on a site would be damage rather than repair. A description '
				. 'that is only the file name is not a decision and is written over freely. '
				. '⛔ IT REFUSES WHAT IT CANNOT REALLY FIX: a page a builder owns, a post somebody has open '
				. 'in the editor, and a page whose pictures are drawn by a shortcode, a gallery or the '
				. 'theme rather than written into the body — there is nothing in the body to describe, and '
				. 'the refusal says so instead of writing where it would have no effect. '
				. 'The tag is found and rewritten by position, not by matching text, so nothing else in the '
				. 'body can be disturbed; the previous body is kept as a revision and the save is read '
				. 'back. dry_run shows what it would do. '
				. 'WHAT TO WRITE: one plain sentence saying what is IN the picture, to somebody who cannot '
				. 'see it. Never the file name, never “image of”, and nothing you cannot see in the image '
				. 'itself — a description that guesses is worse than none, because a screen reader reads it '
				. 'as fact.'
			),
			self::obj(
				array(
					'post_id'       => self::i( 'The page holding the image — the `id` from any read-content-issues row.' ),
					'alt'           => self::s( 'The description: one plain sentence, at most ' . MediaWriter::MAX_ALT . ' characters, saying what the picture shows.' ),
					'index'         => self::i( 'Which image, by its position from read-content’s `images` list. The aim that is never ambiguous.' ),
					'src'           => self::s( 'Which image, by its address — a full src or just the file name. Refused when it matches more than one.' ),
					'attachment_id' => self::i( 'Which image, by its media library id. Falls back to matching the file when the tag carries no wp-image class, as images placed in the classic editor do not.' ),
					'replace'       => array(
						'type'        => 'boolean',
						'description' => 'True overwrites a description that is already there, or an alt="" that marks the picture decorative. Only after reading what is there — the refusal you get without this quotes it. Never needed for an image with no description or a file-name one.',
						'default'     => false,
					),
					'dry_run'       => array(
						'type'        => 'boolean',
						'description' => 'True checks everything and returns what it would write, without writing it.',
						'default'     => false,
					),
				),
				array( 'post_id', 'alt' )
			),
			self::obj(
				array(
					'postId'     => self::i( 'The page acted on.' ),
					'title'      => self::s( 'Its title.' ),
					'index'      => self::i( 'Which image was described, by position.' ),
					'src'        => self::s( 'Its address.' ),
					'attachmentId' => self::i( 'Its media library id, 0 when it belongs to no attachment.' ),
					'alt'         => self::s( 'The description now on it in the body.' ),
					'previous'    => self::s( 'What the tag said before, empty when it had no description.' ),
					'changed'     => self::b( 'TRUE only when the body was actually written. A dry run answers false — report that honestly rather than as a change.' ),
					'dryRun'      => self::b( 'TRUE when nothing was written because dry_run was asked for.' ),
					'context'     => self::s( 'The rewritten tag with the body either side of it, so you can SHOW what landed instead of asserting it.' ),
					'revisionId'  => self::i( 'The revision holding the body from before this change — the way back. 0 when nothing was written.' ),
					'length'      => self::i( 'The body’s length in characters afterwards.' ),
					'libraryAlt'  => self::s( 'What the MEDIA LIBRARY copy of this image says, which this tool deliberately does not touch.' ),
					'libraryNeedsDescribing' => self::b( 'TRUE when that library copy is still empty or a file name. ⭐ This page is fixed either way — but the picture will arrive undescribed in the NEXT page it is inserted into, and describe-image is what closes that. Deliberately not done here: writing it would change what featured_alt reports on other pages without marking any of them for re-reading.' ),
					'message'     => self::s( 'What happened, in plain words.' ),
				)
			),
			function ( $input ) {
				return ( new ContentEditor() )->describe_in_content( is_array( $input ) ? $input : array() );
			},
			array( $this, 'can_edit_post' ),
			false,
			false
		);

		$this->add(
			'describe-image',
			__( 'Describe an image', 'agentimus' ),
			'Writes the alt text on an image ALREADY in the media library — the sentence that says what the '
				. 'picture shows, which image search and screen readers read. This is the fix for the '
				. '“Featured image not described” check, and it is usually the commonest row on the whole '
				. 'worklist. '
				. 'Aim it with post_id — the `id` from any read-content-issues row — to describe THAT page’s '
				. 'featured image, which is what a run through the worklist wants; or with attachment_id from '
				. 'search-media for one library item. One or the other, never both. '
				. '⛔ IT WILL NOT SILENTLY REPLACE A DESCRIPTION SOMEBODY WROTE. An image that is already '
				. 'described is refused, and the refusal quotes what is there; if that description is '
				. 'genuinely wrong, read it and send replace=true with the correction. ⭐ A field holding '
				. 'only the file name is NOT a description — the checks flag those too, and this tool writes '
				. 'over them without replace, because nothing is being lost. It cannot blank a description at '
				. 'all — removing one is done in the media library. '
				. 'WHAT TO WRITE: one plain sentence saying what is IN the picture, to somebody who cannot see '
				. 'it. Never the file name, never “image of”, and nothing you cannot see in the image itself — '
				. 'a description that guesses is worse than none, because a screen reader reads it as fact. '
				. 'Alt text lives on the image, not on the page, so writing it does not re-read the page: '
				. '`refreshed` lists the pages now owed a fresh reading, and check-page reads one back now.',
			self::obj(
				array(
					'post_id'       => self::i( 'Describe THIS page’s featured image. The id from any read-content-issues row.' ),
					'attachment_id' => self::i( 'Describe this media library item directly — an id from search-media. Use one of the two, not both.' ),
					'alt'           => self::s( 'The description: one plain sentence, at most 250 characters, saying what the picture shows.' ),
					'replace'       => array(
						'type'        => 'boolean',
						'description' => 'True overwrites a description that is already there. Only after reading it — the refusal you get without this quotes what would be lost. Not needed for an image described only by its file name: that is not a description, and it is written over without asking.',
						'default'     => false,
					),
				),
				array( 'alt' )
			),
			self::obj(
				array(
					'attachmentId' => self::i( 'The image described.' ),
					'postId'       => self::i( 'The page this call was aimed at, 0 when it was aimed at the library item.' ),
					'image'        => self::s( 'The image’s title in the library, for saying which picture this was.' ),
					'url'          => self::s( 'Its address.' ),
					'alt'          => self::s( 'The description stored NOW.' ),
					'previous'     => self::s( 'What was there before, empty when nothing was.' ),
					'changed'      => self::b( 'TRUE only when this call is what wrote it.' ),
					'refreshed'    => array(
						'type'        => 'array',
						'description' => 'Pages using this image that are now owed a fresh reading — their stored verdict is marked out of date, not deleted. ⛔ They have NOT been re-read: say they will be, or read one now with check-page.',
						'items'       => array( 'type' => 'integer' ),
					),
					'message'      => self::s( 'What happened, in the site’s own words.' ),
				)
			),
			function ( $input ) {
				return ( new MediaWriter() )->describe( is_array( $input ) ? $input : array() );
			},
			array( $this, 'can_describe_image' ),
			false
		);

		$this->add(
			'write-description',
			__( 'Set a page’s AI description', 'agentimus' ),
			'Sets ONE post/page’s AI description — the single plain sentence (≤300 chars) that feeds its '
				. 'JSON-LD description, its .md lead, and (when the owner enabled it) the meta-description '
				. 'tag. Pass an empty string to clear it, falling back to the excerpt. A focused fix after '
				. 'check-page flags a weak opening — no need to resend the content.',
			self::obj(
				array(
					'post_id'     => self::i( 'The post/page ID.' ),
					'description' => self::s( 'The description sentence; empty string clears it.' ),
				),
				array( 'post_id', 'description' )
			),
			$summary,
			function ( $input ) {
				$input = is_array( $input ) ? $input : array();
				return ( new ContentWriter( $this->settings ) )->update(
					array(
						'post_id'     => isset( $input['post_id'] ) ? (int) $input['post_id'] : 0,
						'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
					)
				);
			},
			array( $this, 'can_edit_post' ),
			false
		);

		$this->add(
			'write-topics',
			__( 'Set a page’s Topics for AI', 'agentimus' ),
			'Sets ONE post/page’s manual Topics for AI — the short keyword list that becomes its JSON-LD '
				. 'keywords and a line in its .md twin. The list replaces the current manual topics (pass an '
				. 'empty list to clear them); it is deduped, trimmed and capped by the site’s topic settings. '
				. 'Derived tags & categories still merge in when derivation is on.',
			self::obj(
				array(
					'post_id' => self::i( 'The post/page ID.' ),
					'topics'  => self::topics_input_schema(),
					'derive'  => array(
						'type'        => 'boolean',
						'description' => 'Whether this page should ALSO derive topics from its tags & categories (omit to leave the current choice).',
					),
				),
				array( 'post_id', 'topics' )
			),
			$summary,
			function ( $input ) {
				$input = is_array( $input ) ? $input : array();
				$args  = array(
					'post_id' => isset( $input['post_id'] ) ? (int) $input['post_id'] : 0,
					'topics'  => isset( $input['topics'] ) ? $input['topics'] : array(),
				);
				if ( isset( $input['derive'] ) ) {
					$args['topics_derive'] = $input['derive'];
				}
				return ( new ContentWriter( $this->settings ) )->update( $args );
			},
			array( $this, 'can_edit_post' ),
			false
		);

		$this->add(
			'write-search-fields',
			__( 'Set the search a page answers', 'agentimus' ),
			'Sets the two fields that decide how ONE post/page appears in a search result, neither of which '
				. 'lives in its content: the FOCUS — the search the page should answer, which every worklist '
				. 'row and the editor’s own panel measure it against — and the SEO TITLE, the words a result '
				. 'shows instead of the post title. An agent could write, dress and grade a page to full marks '
				. 'and reach neither. '
				. 'Pass either field alone; an empty string clears it (the focus falls back to whatever search '
				. 'engines report for the page, the title falls back to the post title). Several searches go in '
				. 'one comma-separated string, and the FIRST is the one a row is judged on. '
				. '⛔ `seo_title` is refused outright when an SEO plugin owns titles on this site — writing it '
				. 'would store a value nothing serves. '
				. '⚠️ Both change what the page is MEASURED against without changing a word of it, so the '
				. 'stored verdict is marked out of date and the page is read again within about a minute: '
				. 'read-content-issues will show it as `stale` until then.',
			self::obj(
				array(
					'post_id'   => self::i( 'The post/page ID.' ),
					'focus'     => self::s( 'The search this page should answer. Comma-separate several — the first is the one it is judged on. Empty string clears the choice.' ),
					'seo_title' => self::s( 'The title a search result shows. Empty string clears it, falling back to the post title.' ),
				),
				array( 'post_id' )
			),
			$summary,
			function ( $input ) {
				$input = is_array( $input ) ? $input : array();
				if ( isset( $input['seo_title'] ) && ! Seo::title_ui_enabled() ) {
					return new \WP_Error(
						'agentimus_seo_titles_elsewhere',
						__( 'An SEO plugin owns titles on this site, so Agentimus does not set them — writing this field would store a value nothing serves. Set the title in that plugin instead. The focus keyword still works here.', 'agentimus' ),
						array( 'status' => 409 )
					);
				}
				$args = array( 'post_id' => isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
				foreach ( array( 'focus', 'seo_title' ) as $field ) {
					if ( isset( $input[ $field ] ) ) {
						$args[ $field ] = (string) $input[ $field ];
					}
				}
				return ( new ContentWriter( $this->settings ) )->update( $args );
			},
			array( $this, 'can_edit_post' ),
			false
		);

		$this->add(
			'apply-fix',
			__( 'Apply a readiness fix', 'agentimus' ),
			'Enacts ONE readiness check’s own remediation, by the check id read-readiness returned — a '
				. 'closed set of known-safe switches (it can only ENABLE documented features, never loosen a '
				. 'protection). Fixes that need content, judgement or server access come back applied=false '
				. 'with the honest next step. Returns the check’s state after applying, so re-run '
				. 'read-readiness only when you want the refreshed overall score.',
			self::obj(
				array(
					'check_id' => self::s( 'A check id from read-readiness (checks[].id or score.actions[].id), e.g. "llms", "schema", "sitemap".' ),
				),
				array( 'check_id' )
			),
			self::obj(
				array(
					'applied' => self::b(),
					'changed' => array(
						'type'  => 'array',
						'items' => self::s(),
					),
					'message' => self::s(),
					'check'   => array(
						'type'                 => array( 'object', 'null' ),
						'description'          => 'The check’s row after applying (id, label, status, detail, fix, action), or null for an id with no readiness row.',
						'additionalProperties' => true,
					),
				)
			),
			function ( $input ) {
				return ( new Fixer( $this->settings ) )->apply( isset( $input['check_id'] ) ? (string) $input['check_id'] : '' );
			},
			$manage,
			false
		);

		$this->add(
			'set-aside-page',
			__( 'Set a page aside, or put it back', 'agentimus' ),
			'Marks ONE page as not-cited content — a landing page, a utility page, an index — so no content '
				. 'check grades it and it stops appearing on the worklist. Or puts it back, with aside=false. '
				. 'This is the THIRD thing to do with a row from read-content-issues: fix it, leave it, or say '
				. 'it is fine as it is. Use it when a flagged page is not meant to be quoted in the first place, '
				. 'so the flags are describing a page doing its job. '
				. 'Nothing about the page changes — it stays published exactly as it is; what changes is whether '
				. 'it is judged, and therefore the site’s content score. Set-aside pages are not hidden: the '
				. 'owner sees them in their own list with a live count. '
				. '⛔ NOT A WAY TO CLEAR THE LIST. Setting a page aside because fixing it is hard, or to make a '
				. 'number look better, quietly removes real work from the owner’s view — the count they read '
				. 'afterwards is one you changed. Set aside only what the OWNER would call not-cited content, '
				. 'and when unsure, leave the page flagged and say why you are unsure. '
				. 'One page per call, both directions. There is no bulk form: setting aside every page a check '
				. 'flags is a large, quiet action, and it stays with the owner, behind the confirmation their '
				. 'own screen shows them. '
				. 'Returns the page’s state now and the three worklist counts — the same counts '
				. 'read-content-issues reports, so quote these rather than adjusting a number yourself.',
			self::obj(
				array(
					'post_id' => self::i( 'The page to set aside or put back — the `id` from any read-content-issues row.' ),
					'aside'   => array(
						'type'        => 'boolean',
						'description' => 'True (the default) sets the page aside; false puts it back into grading.',
						'default'     => true,
					),
				),
				array( 'post_id' )
			),
			self::obj(
				array(
					'postId'  => self::i( 'The page acted on.' ),
					'title'   => self::s( 'Its title, for saying out loud which page this was.' ),
					'aside'   => self::b( 'Whether it is set aside NOW — the state after the call, not the change.' ),
					'changed' => self::b( 'Whether THIS call is what put it there. False means it was already in that state and nothing was written; report that honestly rather than as a change.' ),
					'message' => self::s( 'What happened, in the site’s own words.' ),
					'counts'  => self::obj(
						array(
							'fixable'  => self::i( 'Pages asking for something.' ),
							'clear'    => self::i( 'Pages read with nothing to fix.' ),
							'setAside' => self::i( 'Pages the owner excused.' ),
						),
						array(),
						false
					),
				)
			),
			function ( $input ) {
				$in = is_array( $input ) ? $input : array();
				return ( new Triage( $this->settings ) )->set_aside(
					isset( $in['post_id'] ) ? (int) $in['post_id'] : 0,
					isset( $in['aside'] ) ? (bool) $in['aside'] : true
				);
			},
			// ⭐ manage_options, NOT can_edit_post — deliberately unlike the authoring
			// tools beside it. This does not edit a page; it changes what the SITE's
			// content score is measured over. The REST route the owner's own click
			// takes ({@see Rest::optimize_ignore}) requires the same capability, so
			// the ability is exactly as reachable as the button, and no more.
			$manage,
			false
		);

		/* -- Announcements (write) ------------------------------------------- */



		$this->add(
			'retry-announcement',
			__( 'Try a failed announcement again', 'agentimus' ),
			'Re-queues ONE announcement that failed, so it goes out on the next dispatch. '
				. '⛔ THIS IS THE ONLY ANNOUNCEMENT ACTION AN AGENT GETS, and the reason is a rule about this '
				. 'plugin, not about this tool: cancel, post-now and remove each ask the owner to confirm in '
				. 'the admin, and an action does not survive losing its human gate. Retry does not ask, '
				. 'because it only re-queues something the owner already scheduled and the network already '
				. 'refused — it cannot invent a post, change its words, or send it anywhere new. '
				. '⚠️ Refused on any row that is not "failed". Read read-announcements first and send an id '
				. 'whose canRetry is true.',
			self::obj( array( 'id' => self::s( 'The row id from read-announcements.' ) ), array( 'id' ) ),
			self::obj(
				array(
					'retried' => self::b( 'TRUE when the row is back in the queue.' ),
					'status'  => self::s( 'What the row says now — "queued" after a successful retry.' ),
					'message' => self::s( 'Plain sentence for the owner.' ),
				)
			),
			function ( $input ) {
				$in = is_array( $input ) ? $input : array();
				$id = isset( $in['id'] ) ? sanitize_text_field( (string) $in['id'] ) : '';
				if ( '' === $id ) {
					return new \WP_Error( 'agentimus_announce_id', __( 'Which announcement? Send an id from read-announcements.', 'agentimus' ), array( 'status' => 400 ) );
				}

				$ann = new \Agentimus\Integrations\Announcements( $this->settings );
				$out = $ann->retry( $id );
				if ( is_wp_error( $out ) ) {
					return $out; // Already says "only a failed announcement can be tried again".
				}

				return array(
					'retried' => true,
					'status'  => 'queued',
					'message' => __( 'Back in the queue — it goes out on the next dispatch.', 'agentimus' ),
				);
			},
			$manage,
			false // Writes.
		);

		/* -- The review queue (write) ---------------------------------------- */

		$this->add(
			'review-client',
			__( 'Decide about a client in the review queue', 'agentimus' ),
			'Files the owner’s verdict on ONE client from read-clients: block it, allow it, ignore it for now, '
				. 'or forget a decision already made. This is the queue’s whole point — a row sits there until '
				. 'somebody judges it. '
				. 'block adds a user-agent rule to the denylist and turns enforcement on: matching clients are '
				. 'refused at the door from then on. allow does the opposite and permanently — an allowed client '
				. 'is never blocked and never queued again. ignore is neither: no policy changes, the row simply '
				. 'leaves, and it comes back if the client grows well past the volume you saw. forget removes an '
				. 'earlier block or allow (say which with `list`), putting the client back to undecided. '
				. '⭐ It matches on a user-agent FRAGMENT, not on one visitor: blocking "SomeBot" blocks every '
				. 'client whose user-agent contains it, now and later. Read `suggestedRule` on the row before '
				. 'you decide — that is the exact fragment, and a row whose suggestedRule is empty cannot be '
				. 'blocked or allowed at all. '
				. '⛔ It cannot clear the log, and it cannot block a protected client — search engines, and '
				. 'anything the owner has allowed, are refused here by the same guard the owner’s own screen '
				. 'uses.',
			self::obj(
				array(
					'ua'       => self::s( 'The client’s user-agent, exactly as read-clients returned it.' ),
					'decision' => array(
						'type'        => 'string',
						'description' => 'block | allow | ignore | forget.',
					),
					'list'     => self::s( 'For forget only: which list to remove it from — "blocked" or "allowed".' ),
				),
				array( 'ua', 'decision' )
			),
			self::obj(
				array(
					'decided' => self::b( 'TRUE when the verdict was filed.' ),
					'rule'    => self::s( 'The user-agent fragment the decision was recorded against; empty for ignore, which records the client itself.' ),
					'message' => self::s( 'Plain sentence for the owner.' ),
				)
			),
			function ( $input ) {
				$in = is_array( $input ) ? $input : array();
				return Review::decide(
					$this->settings,
					isset( $in['ua'] ) ? (string) $in['ua'] : '',
					isset( $in['decision'] ) ? (string) $in['decision'] : '',
					isset( $in['list'] ) ? (string) $in['list'] : ''
				);
			},
			$manage,
			false // Writes.
		);

		$this->add(
			'recheck-client',
			__( 'Re-check a client’s identity', 'agentimus' ),
			'Asks, live, whether ONE client really is the crawler it claims to be — a fresh reverse-DNS lookup '
				. 'against the addresses this site saw it use, falling back to the operator’s published IP '
				. 'ranges. Use it when a row is flagged as an impostor and you want a second answer before '
				. 'deciding, or when a client was flagged long ago and may have been fixed since. '
				. 'Only works on a client claiming an identity from `verifiers` in read-clients: nothing else '
				. 'has anything to check against. A verdict of 0 means the lookup gave no usable answer and '
				. 'the client’s standing verdict is left alone — an inconclusive check never overwrites what '
				. 'was known. '
				. '⚠️ It makes real DNS requests, so it is rate-limited; a burst is refused rather than queued.',
			self::obj( array( 'ua' => self::s( 'The client’s user-agent, exactly as read-clients returned it.' ) ), array( 'ua' ) ),
			self::obj(
				array(
					'status'  => self::s( 'checked = an answer; no-ip = this site kept no address for the client, so there was nothing to look up.' ),
					'verdict' => array(
						'type'        => 'integer',
						'description' => '0 = inconclusive (nothing was changed), 1 = confirmed genuine, 2 = confirmed impostor.',
					),
					'checked' => self::i( 'How many addresses were looked up.' ),
					'message' => self::s( 'Plain sentence for the owner.' ),
				)
			),
			function ( $input ) {
				$in = is_array( $input ) ? $input : array();
				return Review::recheck( $this->settings, isset( $in['ua'] ) ? (string) $in['ua'] : '' );
			},
			$manage,
			false // Writes.
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  MCP — expose the read set to external agents (opt-in, adapter present).
	 * ---------------------------------------------------------------------- */

	/**
	 * Every ability this class registers, fully-qualified. The MCP server exposes
	 * exactly this set (all permission-gated; the write names appear only when the
	 * owner turned the write tier on), filterable so an owner can trim what leaves
	 * the admin boundary.
	 *
	 * Public so the settings card can state how many tools an assistant would
	 * actually get — the same list, never a second hard-coded count.
	 *
	 * @return string[]
	 */
	public function mcp_abilities() {
		$names = array(
			self::CATEGORY . '/read-readiness',
			self::CATEGORY . '/read-ai-visibility',
			self::CATEGORY . '/read-ai-traffic',
			self::CATEGORY . '/read-request-log',
			self::CATEGORY . '/read-edge-traffic',
			self::CATEGORY . '/read-search-visibility',
			self::CATEGORY . '/read-google-index',
			self::CATEGORY . '/read-search-performance',
			self::CATEGORY . '/read-search-opportunities',
			self::CATEGORY . '/identify-bot',
			// The two halves of "who has been here": who FETCHED (read-clients, with
			// the verdicts the owner has already filed) and who ACTED through a key
			// (read-agent-access). Until these, an assistant could identify one bot
			// by IP and read the raw log, and could not see the queue asking for a
			// decision at all.
			self::CATEGORY . '/read-clients',
			self::CATEGORY . '/read-agent-access',
			// ⭐ The finder, listed BEFORE the per-page reading it aims. Until it
			// existed an agent could change a page but never learn which page
			// needed changing: the findings tool named a number and handed back a
			// screen anchor, and check-page wanted an id nothing would give it.
			self::CATEGORY . '/read-content-issues',
			// Read-only, and it exists FOR the write tier — the same reason
			// search-media is here. The write tools take categories and tags by
			// NAME, so an agent without this list types one from memory and mints
			// a duplicate of a term the site already has.
			self::CATEGORY . '/read-terms',
			self::CATEGORY . '/check-page',
			// ⭐ The body an edit is written against. Read-only and gated on the
			// same edit_post capability as check-page, but it exists FOR the write
			// tier the way search-media and read-terms do: edit-content matches an
			// exact passage, and the only honest source for one is this.
			self::CATEGORY . '/read-content',
			self::CATEGORY . '/preview-schema',
			self::CATEGORY . '/preview-markdown',
			self::CATEGORY . '/scan-exposed-files',
			// Promised to agents since 1.30.0 ("connected agents get the same
			// suggestions through a read-only MCP tool") but never added to this
			// list, so the tool the release notes described did not exist on the
			// server. Read-only and permission-gated like the rest.
			self::CATEGORY . '/suggest-internal-links',
			// Read-only, but it exists FOR the write tier: the featured_image
			// parameter takes an attachment id, and until this there was no way
			// for an agent to learn one. Listed unconditionally anyway — knowing
			// what is in the library is a reading question, and it answers
			// "which picture is this?" for anyone, writes or not.
			self::CATEGORY . '/search-media',
			// The two cross-system reads: the Findings front door (one ranked
			// list over every subsystem) and the people/machines audience split.
			self::CATEGORY . '/read-findings',
			self::CATEGORY . '/read-audience',
			// ⭐ The two "is anything broken?" reads. The ledger is the only place a
			// failed announcement's REASON survives — the admin shows it on a
			// tooltip and nowhere else — and read-integrations answers whether a
			// channel is actually delivering. ⛔ read-integrations returns no
			// address and no credential: a Slack webhook URL IS the power to post
			// in that channel.
			self::CATEGORY . '/read-announcements',
			self::CATEGORY . '/read-integrations',
		);
		if ( $this->settings->enabled( 'enable_agent_writes' ) ) {
			foreach ( self::WRITE_SLUGS as $slug ) {
				$names[] = self::CATEGORY . '/' . $slug;
			}
		}
		/**
		 * The abilities Agentimus exposes over its MCP server to external AI agents.
		 * Trim this to narrow what leaves the site (each is still permission-gated).
		 *
		 * @param string[] $names Ability names.
		 */
		return (array) apply_filters( 'agentimus_mcp_server_abilities', $names );
	}

	/**
	 * Register a scoped MCP server carrying our read tools, when the WordPress MCP
	 * Adapter is running AND the owner opted in. Requests are authenticated by the
	 * adapter (application password / OAuth) and then gated by each ability's own
	 * permission_callback — so "the AI can read" always means "an authenticated user
	 * who could read it anyway".
	 *
	 * The adapter's class names are namespaced under WP\MCP\*; they can drift between
	 * adapter versions, so everything is guarded by class_exists and this method simply
	 * returns if the expected classes aren't present.
	 *
	 * @param object $adapter The McpAdapter instance passed by the mcp_adapter_init action.
	 */
	public function register_mcp_server( $adapter ) {
		// The server is opt-in — and the gate lives HERE, not around the hook, so
		// "MCP server: off" stays true even when another plugin booted the adapter
		// and fired mcp_adapter_init for its own reasons.
		if ( ! $this->settings->enabled( 'enable_mcp_server' ) ) {
			return;
		}
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		// Adapter 0.2 shipped Transport\Http\RestTransport; 0.3+ renamed it to
		// Transport\HttpTransport. Prefer the current name, accept a legacy sideload.
		$transport = null;
		foreach ( array( '\WP\MCP\Transport\HttpTransport', '\WP\MCP\Transport\Http\RestTransport' ) as $candidate ) {
			if ( class_exists( $candidate ) ) {
				$transport = $candidate;
				break;
			}
		}
		$error_handler = '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler';
		$observability = '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler';
		if ( null === $transport || ! class_exists( $error_handler ) ) {
			return; // Adapter shape we don't recognise — advertise nothing rather than fatal.
		}

		$adapter->create_server(
			'agentimus',               // Server id.
			'agentimus/v1',            // REST namespace  → /wp-json/agentimus/v1/mcp
			'mcp',                     // REST route.
			__( 'Agentimus — AI visibility', 'agentimus' ),
			// The description an MCP client shows its user — honest about which tier is on.
			$this->settings->enabled( 'enable_agent_writes' )
				? __( 'Read and improve this site’s AI visibility: readiness, AI traffic, bot activity and per-page readability — plus draft/edit content, set AI topics & descriptions, and apply readiness fixes.', 'agentimus' )
				: __( 'Read this site’s AI/agent readiness, referral traffic, bot activity, per-page readability and discovery output.', 'agentimus' ),
			'v' . AGENTIMUS_VERSION,
			array( $transport ),
			$error_handler,
			class_exists( $observability ) ? $observability : null,
			$this->mcp_abilities(),    // Tools.
			$this->mcp_resources(),    // Resources.
			array()                    // Prompts.
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Registration helper + permission callbacks
	 * ---------------------------------------------------------------------- */

	/**
	 * The shape of one opportunity page, shared by both groups.
	 *
	 * @return array
	 */
	private static function opportunity_rows() {
		return self::arr(
			array(
				'title'       => self::s(),
				'url'         => self::s(),
				'postId'      => self::i( '0 when the URL maps to no post.' ),
				'impressions' => self::i( 'The page\'s whole demand in the window.' ),
				'clicks'      => self::i(),
				'ctr'         => array( 'type' => 'number', 'description' => 'Click rate as a percentage.' ),
				'position'    => array( 'type' => 'number', 'description' => 'Impression-weighted average rank.' ),
				'searches'    => self::i( 'How many distinct searches found this page.' ),
				'wholePage'   => self::b( 'True when the PAGE\'s combined demand qualified rather than any single search — the shape an engine produces when one page\'s traffic arrives as many long-tail searches.' ),
				'queries'     => self::arr(
					array(
						'query'       => self::s(),
						'impressions' => self::i(),
						'clicks'      => self::i(),
						'ctr'         => array( 'type' => 'number' ),
						'position'    => array( 'type' => 'number' ),
					)
				),
			)
		);
	}

	/**
	 * Register one ability under our namespace + category, with the readonly
	 * annotation both our own {@see Discovery\Adapters\AbilitiesApi} adapter
	 * (`meta.annotations.readonly`) and the MCP Adapter understand — declared
	 * explicitly either way, so no downstream name-heuristic ever has to guess.
	 *
	 * @param string   $slug         Short name (becomes "agentimus/<slug>").
	 * @param string   $label        Human label.
	 * @param string   $description  LLM-facing description — this is what the AI reads to choose the tool.
	 * @param array    $input_schema JSON Schema for the arguments.
	 * @param array    $output_schema JSON Schema for the return value.
	 * @param callable $execute      Executes the ability; receives the validated input.
	 * @param callable $permission   Permission callback; receives the input.
	 * @param bool     $readonly     Whether the ability mutates nothing (default true; the write tier passes false).
	 * @param bool     $destructive  Whether a call can lose data that nothing keeps a copy of (update-content: a
	 *                               body replacement on a post type without revision support is unrecoverable).
	 */
	private function add( $slug, $label, $description, array $input_schema, array $output_schema, callable $execute, callable $permission, $readonly = true, $destructive = false, array $mcp = array() ) {
		$name = self::CATEGORY . '/' . $slug;

		// Every ability we register funnels through here, which makes this the one place that can
		// observe our own abilities being run — WITHOUT depending on the Abilities API's global
		// execute hooks. That matters: those hooks only exist in core 6.9+ and in abilities-api
		// v0.4.0+, so on an older feature-plugin install they never fire. Wrapping the callback
		// here means "we always monitor what we exposed" holds on every install, and it is also
		// half of the capability probe that tells the UI whether THIRD-PARTY abilities are visible
		// too (see AgentAccess\Module::observe_own_ability).
		$observed = static function ( $input = null ) use ( $execute, $name ) {
			AgentAccess::observe_own_ability( $name );
			return $execute( $input );
		};

		// The scope caps: a read-only connection token or a read-only OAuth
		// grant cannot run ANY write ability, current or future — enforced
		// here so no individual ability ever has to remember it. The two
		// gates compose; every other auth path passes through both untouched.
		if ( ! $readonly ) {
			$permission = McpToken::gate_write_permission( $permission );
			$permission = Oauth\Server::gate_write_permission( $permission );
		}

		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'execute_callback'    => $observed,
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					// Declared TWICE, on purpose, because the adapter's two readers
					// disagree in 0.5.0: RegisterAbilityAsMcpTool reads meta.annotations
					// at the top level and has no fallback, while
					// RegisterAbilityAsMcpResource prefers mcp.annotations and emits a
					// deprecation notice when it finds only the old place. Writing one
					// location breaks tools; writing the other trips a _doing_it_wrong
					// on every resource. Writing both satisfies each reader at its
					// preferred address and warns about nothing — drop the top-level
					// copy when the adapter's tool path learns to look under mcp.
					'annotations'  => array(
						'readonly'    => (bool) $readonly,
						// Declared per ability. Nothing here deletes, but update-content
						// replaces a body — and on an agent-visible post type WITHOUT
						// revision support nothing keeps the old one, so it declares
						// itself destructive rather than promising safety it can't keep.
						'destructive' => (bool) $destructive,
					),
					// Do NOT auto-join the default public MCP server; the scoped server in
					// register_mcp_server() is the single, deliberate external surface.
					// $mcp carries the extras a RESOURCE needs — the adapter reads its
					// uri and mimeType from exactly here, and refuses to register a
					// resource without a uri.
					'mcp'          => array_merge(
						array(
							'public'      => false,
							'annotations' => array(
								'readonly'    => (bool) $readonly,
								'destructive' => (bool) $destructive,
							),
						),
						$mcp
					),
				),
			)
		);
	}

	/**
	 * Site-admin gate — mirrors every Agentimus admin REST route (`can_manage`).
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Per-post gate for the authoring abilities — mirrors the editor's /page-check route.
	 * Receives the validated input, so it checks edit access to the exact target post.
	 *
	 * @param array $input The ability input.
	 * @return bool
	 */
	public function can_edit_post( $input ) {
		$post_id = (int) ( is_array( $input ) && isset( $input['post_id'] ) ? $input['post_id'] : 0 );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Per-image gate for describe-image — the capability that guards the media
	 * library's own alt field, checked on the attachment this call resolves to.
	 *
	 * ⭐ Resolved through {@see MediaWriter::target()}, the same call the write
	 * uses. Re-deriving the image here would let the gate check one picture while
	 * the write lands on another: aiming by post_id means the attachment is not
	 * in the input at all.
	 *
	 * @param mixed $input The ability input.
	 * @return bool
	 */
	public function can_describe_image( $input ) {
		$target = MediaWriter::target( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $target ) ) {
			// A malformed or unresolvable aim is not a permission answer — let the
			// ability run and refuse with the reason, rather than turning "which
			// image?" into "permission denied".
			return current_user_can( 'upload_files' );
		}
		return current_user_can( 'edit_post', $target['attachment'] );
	}

	/**
	 * Gate for create-content: the requested type must be agent-visible AND the
	 * connected user must hold that type's own CREATE capability — the same cap
	 * core's REST controller and wp-admin's "Add New" check (`cap->create_posts`,
	 * which a locked-down CPT maps to something stricter than its edit cap) — so a
	 * key minted for a low-caps user can never create what that user couldn't in
	 * wp-admin. A type outside the agent-visible set fails closed here as a bare
	 * "Permission denied" — deliberate: the tool's input schema already enumerates
	 * the allowed types, so the agent has the list without us confirming what
	 * other types exist.
	 *
	 * @param mixed $input The ability input.
	 * @return bool
	 */
	public function can_create_content( $input ) {
		$type = is_array( $input ) && ! empty( $input['type'] ) ? (string) $input['type'] : 'post';
		if ( ! in_array( $type, Content::post_types(), true ) ) {
			return false;
		}
		$pto = get_post_type_object( $type );
		if ( ! $pto ) {
			return false;
		}
		$cap = ! empty( $pto->cap->create_posts ) ? $pto->cap->create_posts : ( ! empty( $pto->cap->edit_posts ) ? $pto->cap->edit_posts : '' );
		return '' !== $cap && current_user_can( $cap );
	}

	/**
	 * Append the site's Content Guidelines digest to a write-tool description, so a
	 * connected agent sees the site's voice/tone/image rules at the exact moment it
	 * drafts — no extra call, and no unauthenticated surface (descriptions rebuild
	 * per request, only for the authenticated tool list). No-op without guidelines.
	 *
	 * @param string $description The tool description.
	 * @return string
	 */
	private static function guided( $description ) {
		// The readability bars come first and unconditionally: the SAME drafting
		// rules the in-admin assistant writes to (derived live from PageCheck's
		// thresholds), so an external agent sees them at draft time — not only
		// after a check-page call.
		$description .= "\n\n" . Assistant::readability_rules();
		$g = Guidelines::brief();
		if ( '' === $g ) {
			return $description;
		}
		return $description . "\n\nThis site declares content guidelines — align anything you draft or edit with them:\n" . $g;
	}

	/* ---------------------------------------------------------------------- *
	 *  Tiny JSON-Schema builders (keep the ability definitions readable)
	 * ---------------------------------------------------------------------- */

	/** A string property, optionally described. */
	private static function s( $description = '' ) {
		return '' === $description ? array( 'type' => 'string' ) : array( 'type' => 'string', 'description' => $description );
	}

	/**
	 * A filter that takes ONE value or a LIST of them.
	 *
	 * ⭐ HIS CALL, 2026-08-21: the screens have taken lists on these controls since
	 * 1.40.0, and the tool an assistant uses still took a single value — so an
	 * agent could not ask a question the owner's own screen answers.
	 * ⛔ BACKWARD COMPATIBLE BY CONSTRUCTION: `string` stays in the type union and
	 * comes FIRST, so every existing caller passing one value validates and
	 * behaves exactly as before. Activity\Repository::log() has compiled a scalar
	 * to `col = %s` and a list to `IN (...)` since the same release, so the store
	 * needed nothing — this was only ever the declared contract lagging behind.
	 * ⚠️ No top-level `enum` here: an enum would reject the array form outright.
	 * Where the values are closed (verdict) the handler normalises instead, which
	 * is checked by a test rather than by the schema.
	 *
	 * @param string $description Human description.
	 * @return array
	 */
	private static function slist( $description ) {
		return array(
			'type'        => array( 'string', 'array' ),
			'items'       => array( 'type' => 'string' ),
			'description' => $description,
		);
	}

	/** An integer property, optionally described. */
	private static function i( $description = '' ) {
		return '' === $description ? array( 'type' => 'integer' ) : array( 'type' => 'integer', 'description' => $description );
	}

	/** A boolean property, optionally described. */
	private static function b( $description = '' ) {
		return '' === $description ? array( 'type' => 'boolean' ) : array( 'type' => 'boolean', 'description' => $description );
	}

	/** A number (float) property, optionally described. */
	private static function n( $description = '' ) {
		return '' === $description ? array( 'type' => 'number' ) : array( 'type' => 'number', 'description' => $description );
	}

	/** A date string property (YYYY-MM-DD). */
	private static function date( $description ) {
		return array( 'type' => 'string', 'format' => 'date', 'description' => $description );
	}

	/** An integer-or-null property (for a nullable cursor). */
	private static function nullable_int() {
		return array( 'type' => array( 'integer', 'null' ) );
	}

	/**
	 * An object schema.
	 *
	 * @param array    $properties  Property => sub-schema.
	 * @param string[] $required    Required property names.
	 * @param bool     $additional  Allow unlisted properties.
	 */
	private static function obj( array $properties, array $required = array(), $additional = false ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => $additional,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = array_values( $required );
		}
		return $schema;
	}

	/**
	 * An array schema. Pass null for free-form object items (e.g. per-provider rows we
	 * don't fully pin down), or a property map for a typed row.
	 *
	 * @param array|null $item_props Property map for each item, or null for a loose object.
	 */
	private static function arr( $item_props, $description = '' ) {
		$items  = null === $item_props
			? array( 'type' => 'object', 'additionalProperties' => true )
			: self::obj( $item_props, array(), true );
		$schema = array( 'type' => 'array', 'items' => $items );
		if ( '' !== $description ) {
			$schema['description'] = $description;
		}
		return $schema;
	}

	/** The empty-input schema for a no-argument ability. */
	private static function no_input() {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
			// A read-only ability runs over REST as a GET, whose input comes from an `?input` query
			// param — and a query string cannot express an empty object. With no param the value is
			// null, which WP_Ability::normalize_input() only rescues when the schema declares a
			// `default`. Without this, the three no-input abilities (readiness, AI-visibility,
			// exposed-files) 400 at the input gate and are uncallable by any external MCP/REST agent.
			'default'              => new \stdClass(),
		);
	}

	/** A list of { label, hits } rows (source/agent leaderboards). */
	private static function label_hits_schema() {
		return self::arr(
			array(
				'label' => self::s(),
				'hits'  => self::i(),
			)
		);
	}

	/** A list of { token, hits } rows (unknown hosts / utm). */
	private static function token_hits_schema() {
		return self::arr(
			array(
				'token' => self::s(),
				'hits'  => self::i(),
			)
		);
	}

	/**
	 * The shape shared by Readiness rows and PageCheck rows: id/label/status/detail,
	 * plus fix + action on readiness rows.
	 *
	 * @param bool $with_fix Include the readiness-only fix/action keys.
	 */
	private static function status_rows_schema( $with_fix ) {
		$props = array(
			'id'     => self::s(),
			'label'  => self::s(),
			// Readiness rows carry a fourth status PageCheck rows never emit:
			// 'off' — the feature the row measures is switched off, so there is
			// nothing to grade; the row is informational and excluded from the
			// score. Output validates with the declared enum, so leaving 'off'
			// out here would have the ability reject its own honest response
			// (the 1.30.0 verifyOn lesson, as a value instead of a key).
			'status' => array(
				'type' => 'string',
				'enum' => $with_fix
					? array( 'pass', 'warn', 'fail', 'off' )
					: array( 'pass', 'warn', 'fail' ),
			),
			'detail' => self::s(),
		);
		if ( $with_fix ) {
			$props['fix']    = self::s();
			$props['action'] = array( 'type' => array( 'object', 'null' ), 'additionalProperties' => true );
			// Extra doors beyond the one action — e.g. the robots row's
			// per-engine "see what Google/Bing reads" links; [] on most rows.
			$props['links'] = array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			);
			// AgentReady stable requirement ID ('' when the check has none) —
			// agents reading readiness can cite the spec the row evidences.
			$props['ar'] = self::s();
		}
		return self::arr( $props );
	}

	/** The status input shared by create-content and update-content. */
	private static function status_input_schema() {
		return array(
			'type'        => 'string',
			'enum'        => ContentWriter::STATUSES,
			'description' => 'draft (default), pending, or publish. publish needs the site’s "agents may publish" switch plus the user’s publish permission.',
		);
	}

	/** The topics-list input shared by the writing abilities. */
	private static function topics_input_schema() {
		return array(
			'type'        => 'array',
			'items'       => self::s(),
			'description' => 'Manual Topics for AI — short, specific keywords (deduped, trimmed and capped by the site’s topic settings).',
		);
	}

	/**
	 * The categories/tags input shared by create-content and update-content.
	 *
	 * @param string $field 'categories' or 'tags' (for the description).
	 */
	private static function terms_input_schema( $field ) {
		return array(
			'type'        => 'array',
			'items'       => self::s(),
			'description' => sprintf(
				'%s, by name — REPLACES the post’s current %s ([] clears them). Existing names are matched; a new name is created only when the connected user may manage %s (same rule as wp-admin).',
				'categories' === $field ? 'Categories' : 'Tags',
				$field,
				$field
			),
		);
	}

	/** The featured_image input shared by create-content and update-content. */
	private static function featured_image_input_schema() {
		return self::s( 'The featured image: an existing attachment ID (e.g. "123"), or an http(s) image URL to import into the media library (needs the user’s upload permission; the site’s file-type and size rules apply). Empty string removes the featured image.' );
	}

	/** What every write ability returns: the written post as it now stands (see ContentWriter::summarize()). */
	private static function written_post_schema() {
		return self::obj(
			array(
				'id'            => self::i(),
				'type'          => self::s(),
				'status'        => self::s(),
				'title'         => self::s(),
				'url'           => self::s(),
				'editUrl'       => self::s(),
				'description'   => self::s(),
				'readability'   => self::obj(
					array(
						'pass'      => self::i(),
						'warn'      => self::i(),
						'fail'      => self::i(),
						'attention' => array(
							'type'  => 'array',
							'items' => self::obj(
								array(
									'id'     => self::s(),
									'status' => self::s(),
									'label'  => self::s(),
									'detail' => self::s(),
								)
							),
						),
					)
				),
				'topics'        => array(
					'type'  => 'array',
					'items' => self::s(),
				),
				'focus'         => self::s( 'The search this page is measured against — the author’s own choice, empty when none is set and the reported search stands in.' ),
				'seoTitle'      => self::s( 'The title search results show for this page. Empty when none is set, and ALWAYS empty when an SEO plugin owns titles on this site — in which case this field is not the one that decides them.' ),
				'categories'    => array(
					'type'  => 'array',
					'items' => self::s(),
				),
				'tags'          => array(
					'type'  => 'array',
					'items' => self::s(),
				),
				'featuredImage' => array(
					'type'                 => array( 'object', 'null' ),
					'description'          => '{ id, url } of the featured image, or null when none is set.',
					'additionalProperties' => true,
				),
			)
		);
	}

	/** The AEO/GEO score object returned by {@see Score::report()}. */
	private static function score_schema() {
		return self::obj(
			array(
				'score'      => self::i(),
				'band'       => self::s(),
				'blocked'    => self::b(),
				'ready'      => self::b(),
				'measured'   => self::b(),
				'rungs'      => self::arr(
					array(
						'key'    => self::s(),
						'label'  => self::s(),
						'score'  => self::nullable_int(),
						'weight' => self::i(),
						'kind'   => self::s(),
						'pass'   => self::nullable_int(),
						'total'  => self::nullable_int(),
						'note'   => self::s(),
						'to'     => self::s(),
						'view'   => self::s( 'On a signal rung only: which sub-view of the destination tab to open.' ),
					)
				),
				'actions'    => self::arr(
					array(
						'id'       => self::s(),
						'pillar'   => self::s(),
						'title'    => self::s(),
						'why'      => self::s(),
						'severity' => self::s(),
						'action'   => array( 'type' => array( 'object', 'null' ), 'additionalProperties' => true ),
					)
				),
				'graded'     => self::i( 'How many published items the citability grade covers. Which CONTENT TYPES those are is in `scope.graded` — never assume posts and pages; a site can grade Pages and Docs, or have switched Posts off entirely. The WHOLE site, not a sample — it was the 25 most recently edited until 1.37.0.' ),
				'unanswered' => self::i( 'How many published pages are worth fixing for a reason `content` CANNOT show — their words do not answer the search they are found for, which is not a content check and so appears in no group above. ⛔ Never add this to `flagged`: different measurements over different populations. read-content-issues lists these rows (an empty `issues` with a `coverage` of "barely" or "missing").' ),
				'cron'       => self::obj(
					array(
						'lastRun' => self::i( 'Unix time when WordPress’s own scheduled-job pass last reached this plugin. 0 = never seen.' ),
						'due'     => self::i( 'How many of this plugin’s scheduled jobs are past their time right now.' ),
						'overdue' => array( 'type' => 'boolean', 'description' => self::s( 'TRUE when jobs are due AND the pass has not been seen for hours — this site’s background work is not running, usually a host blocking the loopback request to /wp-cron.php. ⛔ Never read a stalled count (`grading`, `rechecking`) as a promise on such a site: nothing is coming to move it unless somebody opens wp-admin.' ) ),
					)
				),
				'flagged'    => self::i( 'How many of the `graded` pages carry at least one content issue — PAGES, one each, however many issues a page has. ⛔ Never add up the `content` group counts to get this: the groups overlap, so a page failing three checks is counted three times; and never take the largest group either, which is this number’s FLOOR. `graded` minus this is how many were read and found clean.' ),
				'grading'    => self::i( 'How many published pages have NEVER been read. Above zero means `graded` and `content` describe part of the site, not all of it.' ),
				'rechecking' => self::i( 'How many of the `graded` pages were edited after they were read. Their rows stay in `content`, each marked `stale`, and the sweep re-reads them within about a minute. ⛔ Never report a page here as fixed: its verdict describes the draft before the owner’s last save, which is exactly the edit they are asking about.' ),
				'scope'      => self::obj(
					array(
						'graded'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'The content types behind `graded` and `content`, by their display names — what the citability grade actually covers on this site.',
						),
						'notGraded' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Content types this site CHECKS but never grades for quoting — products and the like, short by design. They carry no citability verdict here; they are still checked for the searches they are found for. ⛔ Never read an empty citability result for one of these as a bad grade.',
						),
						'off'       => array(
							'type'        => 'boolean',
							'description' => 'TRUE when the owner has switched every content type off. `graded`, `content` and the Optimized pillar then describe the LAST completed reading, not the site today, and nothing new is being read — `grading` is 0 because no sweep is coming, not because the site is finished. ⛔ Never report this state as an up-to-date measurement.',
						),
					)
				),
				// The report shipped these two from the start; the schema did not,
				// and additionalProperties:true let the gap hide — declared keys
				// are the only ones a schema-trusting client knows exist.
				'content'    => self::arr(
					array(
						'id'         => self::s( 'The content check id (e.g. "summary", "freshness").' ),
						'label'      => self::s(),
						'why'        => self::s( 'What to do about it, at the group level.' ),
						'count'      => self::i( 'How many graded pieces flag this check.' ),
						'countLabel' => self::s( 'The count named by real content types ("3 Posts, 1 Page").' ),
						'pages'      => self::arr(
							array(
								'id'    => self::i(),
								'title' => self::s(),
								'url'   => self::s( 'The page’s EDIT link.' ),
								'stale' => array(
									'type'        => 'boolean',
									'description' => 'TRUE when this page was saved after the verdict was measured — the flag describes the draft BEFORE that edit, and the sweep has not read it again yet. Re-read the page (agentimus-check-page) before telling anyone it still has this problem.',
								),
							),
							'The affected pages, capped per issue.'
						),
					),
					'The per-page content worklist behind the Optimized rung, most common issue first.'
				),
				'ignored'    => self::arr(
					array(
						'id'    => self::i(),
						'title' => self::s(),
						'url'   => self::s( 'The page’s EDIT link.' ),
						'flags' => array(
							'type'        => 'array',
							'items'       => self::s(),
							'description' => 'What the page was flagged for when listed — why it was on the worklist.',
						),
					),
					'The set-aside ledger: pages the owner excused from citability grading, each restorable.'
				),
			)
		);
	}

	/** The headline numbers from {@see VisibilityStore::summarize()}. */
	private static function visibility_summary_schema() {
		return self::obj(
			array(
				'checks'          => self::i(),
				'mentions'        => self::i(),
				'citations'       => self::i(),
				'errors'          => self::i(),
				'visibilityScore' => self::i(),
				'citationRate'    => self::i(),
			)
		);
	}
}
