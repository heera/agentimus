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
use Agentimus\Content;
use Agentimus\Media;
use Agentimus\PageCheck;
use Agentimus\InternalLinks;
use Agentimus\Schema;
use Agentimus\Markdown;
use Agentimus\Exposure;
use Agentimus\Search\Report as SearchReport;
use Agentimus\BotVerifier;
use Agentimus\Activity\Referrals;
use Agentimus\Activity\Repository;
use Agentimus\AgentAccess\Module as AgentAccess;
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
	const WRITE_SLUGS = array( 'create-content', 'update-content', 'write-description', 'write-topics', 'apply-fix' );

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
		// unconditionally; this server registers neither. A capability advertised
		// and then empty reads as broken to clients (and to scanners, which score
		// "advertises resources but resources/list has none" as a failure while a
		// tool-only server scores n/a) — so the handshake tells the truth.
		add_filter( 'mcp_adapter_initialize_response', array( $this, 'trim_initialize_capabilities' ), 10, 2 );
	}

	/**
	 * Drop the resources/prompts capabilities from OUR server's initialize answer —
	 * it exposes tools only. Other servers on the same adapter are left alone.
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
			unset( $data['capabilities']['resources'], $data['capabilities']['prompts'] );
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
				'label'       => __( 'Agentimus — AI visibility', 'agentimus' ),
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
				. 'fix for each. Use this to answer "how ready is my site to be found and cited by AI, and what '
				. 'should I fix first?".',
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
			__( 'Get AI Visibility results', 'agentimus' ),
			'Returns the latest AI Visibility run: whether AI assistants mention and cite each tracked '
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
					'source' => self::s( 'Narrow to one assistant, by its exact label (see read-ai-traffic facets).' ),
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
					'agent'    => self::s( 'Exact detected agent name.' ),
					'endpoint' => self::s( 'Exact endpoint path.' ),
					'network'  => self::s( 'Exact owning network (only populated when "identify every bot" is on).' ),
					'verdict'  => array(
						'type'        => 'integer',
						'enum'        => array( 0, 1, 2 ),
						'description' => '0 = unchecked, 1 = verified real engine, 2 = spoofed impersonator.',
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
				)
			),
			function ( $input ) {
				$args = array();
				foreach ( array( 'from', 'to', 'agent', 'endpoint', 'network', 'ua' ) as $k ) {
					if ( isset( $input[ $k ] ) && '' !== (string) $input[ $k ] ) {
						$args[ $k ] = (string) $input[ $k ];
					}
				}
				if ( isset( $input['verdict'] ) && '' !== (string) $input['verdict'] ) {
					$args['verdict'] = (int) $input['verdict'];
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
				. 'When source="bing", totals and topPages come from two separate Bing reports counted '
				. 'differently — they never quite reconcile, and one page can show more clicks than '
				. 'totals.clicks. Neither is an error: state the split rather than reconciling the numbers.',
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
									'pageCap'   => self::i( '0 — Google reports query x page directly, so no page-level sampling applies.' ),
								)
							),
							'bing'   => self::obj(
								array(
									'connected' => self::b(),
									'hasData'   => self::b(),
									'lastError' => self::s(),
									'pageCap'   => self::i( 'Page-level figures cover only this many of the busiest pages — Bing has no query x page report, so the poll samples. State this scope before generalising about "the site".' ),
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
						'Clicks and impressions per day, oldest first — Google only (Bing\'s API has no daily split; empty there). History accumulates locally beyond Google\'s own window; the payload ships the most recent 112 days.'
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
				. 'the Search Opportunities screen. Two groups: "almostThere" (ranking 8–20, one improvement '
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
									'pageCap'   => self::i( '0 — Google reports query x page directly, so no page-level sampling applies.' ),
								)
							),
							'bing'   => self::obj(
								array(
									'connected' => self::b(),
									'hasData'   => self::b(),
									'lastError' => self::s(),
									'pageCap'   => self::i( 'Page-level figures cover only this many of the busiest pages — Bing has no query x page report, so the poll samples. State this scope before generalising about "the site".' ),
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
				)
			),
			function ( $input ) {
				return SearchReport::opportunities( $this->settings, isset( $input['source'] ) ? (string) $input['source'] : '' );
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

		/* -- Per-page authoring aids ----------------------------------------- */

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
				)
			),
			self::obj(
				array(
					'total' => self::i( 'How many rows this answer holds. Never more than limit; a full page may mean there are more, so narrow the query rather than assuming this is everything.' ),
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
				$items = Media::search(
					isset( $input['query'] ) ? (string) $input['query'] : '',
					isset( $input['limit'] ) ? (int) $input['limit'] : Media::DEFAULT_RESULTS,
					isset( $input['mime'] ) ? (string) $input['mime'] : 'image'
				);
				return array(
					'total' => count( $items ),
					'items' => $items,
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
					'content'       => self::s( 'New body, as HTML. Replaces the current body — a revision keeps the old one only on content types that support revisions (posts and pages do).' ),
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
			self::CATEGORY . '/check-page',
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
			array(),                   // Resources.
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
	private function add( $slug, $label, $description, array $input_schema, array $output_schema, callable $execute, callable $permission, $readonly = true, $destructive = false ) {
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
					'mcp'          => array( 'public' => false ),
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

	/** An integer property, optionally described. */
	private static function i( $description = '' ) {
		return '' === $description ? array( 'type' => 'integer' ) : array( 'type' => 'integer', 'description' => $description );
	}

	/** A boolean property, optionally described. */
	private static function b( $description = '' ) {
		return '' === $description ? array( 'type' => 'boolean' ) : array( 'type' => 'boolean', 'description' => $description );
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
			'status' => array(
				'type' => 'string',
				'enum' => array( 'pass', 'warn', 'fail' ),
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
				'score'    => self::i(),
				'band'     => self::s(),
				'blocked'  => self::b(),
				'ready'    => self::b(),
				'measured' => self::b(),
				'rungs'    => self::arr(
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
					)
				),
				'actions'  => self::arr(
					array(
						'id'       => self::s(),
						'pillar'   => self::s(),
						'title'    => self::s(),
						'why'      => self::s(),
						'severity' => self::s(),
						'action'   => array( 'type' => array( 'object', 'null' ), 'additionalProperties' => true ),
					)
				),
				'graded'   => self::i(),
			),
			array(),
			true
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
