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
 * Every ability here is READ-ONLY: it wraps a service method that already returns a
 * structured array (see {@see Score}, {@see Readiness}, {@see Activity\Referrals},
 * {@see Activity\Repository}, {@see BotVerifier}, {@see PageCheck}, {@see Schema},
 * {@see Markdown}, {@see Exposure}) and mutates nothing. Write abilities (set a page's
 * AI description/topics, purge caches, run a visibility check) are intentionally NOT
 * here — they carry cost or data loss and belong behind a deliberate, separate opt-in.
 *
 * The whole class no-ops on cores without the Abilities API, so the plugin's 6.3+
 * baseline is unaffected.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Settings;
use Agentimus\Readiness;
use Agentimus\Score;
use Agentimus\PageCheck;
use Agentimus\Schema;
use Agentimus\Markdown;
use Agentimus\Exposure;
use Agentimus\BotVerifier;
use Agentimus\Activity\Referrals;
use Agentimus\Activity\Repository;
use Agentimus\AgentAccess\Module as AgentAccess;
use Agentimus\Visibility\Store as VisibilityStore;
use Agentimus\Visibility\Settings as VisibilitySettings;

defined( 'ABSPATH' ) || exit;

final class Registrar {

	/** The ability category all of Agentimus's abilities live under. */
	const CATEGORY = 'agentimus';

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
				'description' => __( 'Read this site’s AI/agent readiness, traffic, bot activity, per-page readability and discovery output.', 'agentimus' ),
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
			'Grades ONE post/page for how easily an AI can read, section and cite it: word count, an opening '
				. 'summary, concrete figures or cited sources, heading structure, quotable passage length, link '
				. 'density, image alt text, and freshness. Returns a pass/warn/fail row per check plus a tally. '
				. 'Use it to tell an author exactly what to improve on a specific page.',
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
	}

	/* ---------------------------------------------------------------------- *
	 *  MCP — expose the read set to external agents (opt-in, adapter present).
	 * ---------------------------------------------------------------------- */

	/**
	 * Every ability this class registers, fully-qualified. The MCP server exposes
	 * exactly this set (all read-only, all permission-gated), filterable so an owner
	 * can trim what leaves the admin boundary.
	 *
	 * @return string[]
	 */
	private function mcp_abilities() {
		$names = array(
			self::CATEGORY . '/read-readiness',
			self::CATEGORY . '/read-ai-visibility',
			self::CATEGORY . '/read-ai-traffic',
			self::CATEGORY . '/read-request-log',
			self::CATEGORY . '/identify-bot',
			self::CATEGORY . '/check-page',
			self::CATEGORY . '/preview-schema',
			self::CATEGORY . '/preview-markdown',
			self::CATEGORY . '/scan-exposed-files',
		);
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
	 * Adapter is installed. Requests are authenticated by the adapter (application
	 * password / OAuth) and then gated by each ability's own permission_callback — so
	 * "the AI can read" always means "an authenticated user who could read it anyway".
	 *
	 * The adapter's class names are namespaced under WP\MCP\*; they can drift between
	 * adapter versions, so everything is guarded by class_exists and this method simply
	 * returns if the expected classes aren't present.
	 *
	 * @param object $adapter The McpAdapter instance passed by the mcp_adapter_init action.
	 */
	public function register_mcp_server( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		$transport     = '\WP\MCP\Transport\Http\RestTransport';
		$error_handler = '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler';
		$observability = '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler';
		if ( ! class_exists( $transport ) || ! class_exists( $error_handler ) ) {
			return; // Adapter shape we don't recognise — advertise nothing rather than fatal.
		}

		$adapter->create_server(
			'agentimus',               // Server id.
			'agentimus/v1',            // REST namespace  → /wp-json/agentimus/v1/mcp
			'mcp',                     // REST route.
			__( 'Agentimus — AI visibility', 'agentimus' ),
			__( 'Read this site’s AI/agent readiness, referral traffic, bot activity, per-page readability and discovery output.', 'agentimus' ),
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
	 * Register one read ability under our namespace + category, with the read-only
	 * annotations both our own {@see Discovery\Adapters\AbilitiesApi} adapter
	 * (`meta.annotations.readonly`) and the MCP Adapter understand.
	 *
	 * @param string   $slug         Short name (becomes "agentimus/<slug>").
	 * @param string   $label        Human label.
	 * @param string   $description  LLM-facing description — this is what the AI reads to choose the tool.
	 * @param array    $input_schema JSON Schema for the arguments.
	 * @param array    $output_schema JSON Schema for the return value.
	 * @param callable $execute      Executes the ability; receives the validated input.
	 * @param callable $permission   Permission callback; receives the input.
	 */
	private function add( $slug, $label, $description, array $input_schema, array $output_schema, callable $execute, callable $permission ) {
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
						'readonly'    => true,
						'destructive' => false,
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

	/** A boolean property. */
	private static function b() {
		return array( 'type' => 'boolean' );
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
	private static function arr( $item_props ) {
		$items = null === $item_props
			? array( 'type' => 'object', 'additionalProperties' => true )
			: self::obj( $item_props, array(), true );
		return array( 'type' => 'array', 'items' => $items );
	}

	/** The empty-input schema for a no-argument ability. */
	private static function no_input() {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
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
		}
		return self::arr( $props );
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
