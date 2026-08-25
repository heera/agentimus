<?php
/**
 * REST controller backing the Vue admin: read/save settings and fetch the
 * readiness report. All routes require `manage_options` and the standard REST
 * nonce (apiFetch / X-WP-Nonce).
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NAMESPACE = 'agentimus/v1';

	/**
	 * How many targets of one post type the preview picker loads per batch. A type
	 * with fewer than this returns in full (no "Load more"); a bigger one (posts,
	 * products, any CPT) paginates via `offset`. Pages are few, so they load whole.
	 */
	const PREVIEW_PAGE_SIZE = 25;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		// The review ask's "really used it" signal, from one seam instead of
		// per-endpoint bookkeeping: any successful state-changing call to the
		// plugin's namespace by a managing user counts — see Review::use_worthy().
		add_filter( 'rest_request_after_callbacks', array( $this, 'count_use' ), 10, 3 );
	}

	/**
	 * Count a successful plugin write as real use, for the review ask's gates.
	 * Filter passthrough — the response is never altered.
	 *
	 * @param mixed            $response Result to send (response or error).
	 * @param array            $handler  Route handler (unused).
	 * @param \WP_REST_Request $request  The dispatched request.
	 * @return mixed The response, untouched.
	 */
	public function count_use( $response, $handler, $request ) {
		if ( ! is_wp_error( $response )
			&& Review::use_worthy( $request->get_route(), $request->get_method() )
			&& current_user_can( 'manage_options' ) ) {
			Review::used();
		}
		return $response;
	}

	/**
	 * Define the routes.
	 */
	public function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		// The Report screen: what AI did here between two dates. One read for a
		// whole window — the screens each own their own numbers, and this asks
		// the same producers for the same window so the page, the dashboard's
		// Today line and the weekly email can never disagree.
		register_rest_route(
			self::NAMESPACE,
			'/report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'from' => array(
						'type'        => 'string',
						'description' => 'First day of the window (YYYY-MM-DD). Defaults to today.',
					),
					'to'   => array(
						'type'        => 'string',
						'description' => 'Last day of the window, inclusive (YYYY-MM-DD). Defaults to today.',
					),
					'live' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Return only the blocks that can change minute to minute (reads, visits, impostors, assistant actions) — no score, search or citations. What the dashboard\'s Today line polls.',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/reset',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reset_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/onboarding',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'complete_onboarding' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/mcp-token',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'mcp_token_status' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mcp_token_create' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'mcp_token_revoke' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/changelog',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'changelog' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/nextsteps-seen',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'nextsteps_seen' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/whatsnew-seen',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'whatsnew_seen' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/review-ack',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'review_ack' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					// No enum here: schema validation runs BEFORE the permission
					// callback, so an enum would answer 400 to an anonymous probe
					// (and to the permission matrix's dummy value) before the gate
					// gets to say 403. The value set is enforced in the callback.
					'answer' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/verifier/probe-ranges',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'probe_ranges' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/findings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_findings' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/findings/seen',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_findings_seen' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/worklist',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_worklist' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					// Which tab. Validated in the handler rather than by enum:
					// an enum is checked BEFORE the permission callback, so a
					// bad value would answer 400 to a logged-out stranger and
					// confirm the route exists.
					'filter' => array( 'type' => 'string', 'required' => false ),
					'page'   => array( 'type' => 'integer', 'required' => false ),
					'per'    => array( 'type' => 'integer', 'required' => false ),
					// One check's pages, for the by-issue hand-off. Same reason as
					// `filter` for not using an enum here: it would be checked
					// before the permission callback.
					'issue'  => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		// The nudge {@see \Agentimus\Cron}: run this plugin's overdue scheduled
		// jobs, in a request of the owner's browser's making, on a site whose
		// own loopback to /wp-cron.php never arrives. ⛔ Ours only, budgeted,
		// and one runner at a time — the endpoint is a door onto work that was
		// already due, never a way to make work happen sooner.
		register_rest_route(
			self::NAMESPACE,
			'/cron/run',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'run_cron' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		// Rows for named posts, whatever the shortlist holds. A finding hands
		// over the exact pages it counted, but the worklist ships only its 30
		// most-worth-looking-at rows — a handed-over page below that line
		// filtered down to "Nothing in this view", which read as the button
		// lying. This door fetches precisely the named rows instead.
		register_rest_route(
			self::NAMESPACE,
			'/worklist/rows',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'worklist_rows' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'ids' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);

		// Which of these rows are older than the posts they describe, and the
		// rebuild for just those. Editing happens in another tab; without this
		// the screen either shows a stale verdict or re-reads every page to
		// discover that one of them changed.
		register_rest_route(
			self::NAMESPACE,
			'/worklist/changed',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'worklist_changed' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		// Put a suggestion away, or bring it back. ⛔ Only LATER-tier findings can
		// be hidden — the filter that enforces it lives in Findings::all(), so a
		// crafted request naming an urgent id stores a dismissal that never hides
		// anything rather than burying a live problem.
		register_rest_route(
			self::NAMESPACE,
			'/findings/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_finding' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id'     => array( 'type' => 'string', 'required' => true ),
					'hidden' => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/readiness',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_readiness' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/score',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_score' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		// JSON-LD preview: the exact @graph the front end would emit for the site
		// or a chosen post, so an owner can inspect and validate it without viewing
		// page source. `post` = 0/absent → the site-wide identity graph.
		register_rest_route(
			self::NAMESPACE,
			'/preview/schema',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema_preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// The Markdown twin of a page/post — what an agent receives when it requests
		// the page as text/markdown. The site target (post = 0) previews the index
		// Markdown served at the home URL and /llms.txt.
		register_rest_route(
			self::NAMESPACE,
			'/preview/markdown',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_markdown_preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// The pickable targets for the preview: the in-scope pages & posts, most
		// recently edited first, optionally filtered by a title search. Grouped by
		// post type and paginated per group — `type` + `offset` fetch a group's next
		// batch for the "Load more" button (see get_schema_targets()).
		register_rest_route(
			self::NAMESPACE,
			'/preview/targets',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema_targets' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'offset' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /optimize/ignore — set aside (or restore) a page from citability grading.
		register_rest_route(
			self::NAMESPACE,
			'/optimize/ignore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize_ignore' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post'    => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'ignored' => array(
						'type'              => 'boolean',
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		// POST /optimize/restore-all — bring every set-aside page back into grading.
		register_rest_route(
			self::NAMESPACE,
			'/optimize/restore-all',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize_restore_all' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		// POST /optimize/ignore-issue — set aside EVERY page a content check flags,
		// in one action. The check id's validity is judged in the callback, after the
		// permission gate (an args enum would answer 400 before the 401/403).
		register_rest_route(
			self::NAMESPACE,
			'/optimize/ignore-issue',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize_ignore_issue' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'issue' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

	}

	/**
	 * Permission gate.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /settings.
	 *
	 * @return \WP_REST_Response
	 */
	/**
	 * GET /report — one window's worth of what AI did here.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_report( \WP_REST_Request $request ) {
		// ⛔ The GMT day, the one this data is stamped and counted in — see
		// Report\Data::today_gmt(). The screen asks with no dates for "today"
		// and takes the answer's own range back, so the browser's clock never
		// gets a vote either.
		$today = gmdate( 'Y-m-d' );
		$from  = (string) $request->get_param( 'from' );
		$to    = (string) $request->get_param( 'to' );
		$from  = '' === $from ? $today : $from;
		$to    = '' === $to ? $today : $to;

		// ⭐ The live slice, for the dashboard's Today line on its 15-second
		// clock: the same collector and the same window, minus the score,
		// search and citation work — none of which can move between two ticks,
		// and all of which cost real queries. {@see Report\Data::live()}.
		if ( $request->get_param( 'live' ) ) {
			return rest_ensure_response( \Agentimus\Report\Data::live( $from, $to ) );
		}

		return rest_ensure_response(
			\Agentimus\Report\Data::collect( $this->settings, $from, $to )
		);
	}

	public function get_settings() {
		return rest_ensure_response(
			array(
				'settings'     => $this->settings->all(),
				'readiness'    => ( new Readiness( $this->settings ) )->report(),
				// The freshly-expanded exposed-files probe list, so the admin's
				// browser-side scan reflects a just-edited custom path without a reload.
				'exposedPaths' => Exposure::sensitive_paths( $this->settings ),
			)
		);
	}

	/**
	 * POST /settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = (array) $request->get_param( 'settings' );
		}
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$input = $input['settings'];
		}

		// The wizard's guided fix: the owner explicitly ticked "switch on Search
		// engine visibility", so flip the core option BEFORE the readiness and
		// score runs below — the response must already show the site open. Only
		// ever an explicit tick; the key is not a setting and is never stored.
		if ( ! empty( $input['make_public'] ) ) {
			update_option( 'blog_public', 1 );
		}
		unset( $input['make_public'] );

		$saved     = $this->settings->update( (array) $input );
		$readiness = ( new Readiness( $this->settings ) )->report();

		// The weekly-digest cron must agree with the (possibly just-flipped)
		// digest_enabled switch without waiting for the next boot's self-heal.
		( new Digest\Module( $this->settings ) )->sync_schedule();

		return rest_ensure_response(
			array(
				'settings'     => $saved,
				'readiness'    => $readiness,
				// The recomputed score, so the rail card (e.g. the Cited rung when
				// citation tracking is toggled) updates live without a page reload.
				'score'        => $this->score_report( $readiness ),
				'exposedPaths' => Exposure::sensitive_paths( $this->settings ),
				'saved'        => true,
			)
		);
	}

	/**
	 * The AEO/GEO score, computed fail-open (a throw degrades to null rather than failing
	 * the settings save).
	 *
	 * @param array<int,array<string,mixed>> $readiness Precomputed readiness rows.
	 * @return array|null
	 */
	private function score_report( array $readiness ) {
		try {
			return ( new Score( $this->settings ) )->report( $readiness );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * POST /optimize/ignore — set aside (ignored=true) or restore (false) a page from
	 * content-citability grading. Returns the recomputed score so the worklist, the
	 * set-aside list, and the counts update without a reload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function optimize_ignore( \WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'post' ) );
		if ( $id < 1 ) {
			return new \WP_Error( 'agentimus_bad_post', __( 'Invalid post.', 'agentimus' ), array( 'status' => 400 ) );
		}
		$ignore = (bool) $request->get_param( 'ignored' );

		$all  = $this->settings->stored();
		$list = ( isset( $all['optimize_ignored'] ) && is_array( $all['optimize_ignored'] ) ) ? array_map( 'intval', $all['optimize_ignored'] ) : array();
		$list = array_values( array_diff( $list, array( $id ) ) );
		if ( $ignore ) {
			$list[] = $id;
		}
		$all['optimize_ignored'] = $list;
		$this->settings->update( $all ); // Sanitises + busts the OPTIMIZE cache.

		return rest_ensure_response(
			array(
				'score' => ( new Score( new Settings() ) )->report(),
				'saved' => true,
			)
		);
	}

	/**
	 * POST /optimize/restore-all — empty the set-aside list, returning every parked
	 * page to grading. The mirror of ignore-issue; same recomputed-score response.
	 *
	 * @return \WP_REST_Response
	 */
	public function optimize_restore_all() {
		$all = $this->settings->stored();
		$all['optimize_ignored'] = array();
		$this->settings->update( $all ); // Sanitises + busts the OPTIMIZE cache.

		return rest_ensure_response(
			array(
				'score' => ( new Score( new Settings() ) )->report(),
				'saved' => true,
			)
		);
	}

	/**
	 * POST /optimize/ignore-issue — set aside every sampled page a content check
	 * currently flags (the full set, not the worklist's capped preview). Returns the
	 * recomputed score, like the per-page route, so the screen updates in place.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function optimize_ignore_issue( \WP_REST_Request $request ) {
		$issue = sanitize_key( (string) $request->get_param( 'issue' ) );
		$ids   = ( new Score( $this->settings ) )->issue_post_ids( $issue );
		if ( empty( $ids ) ) {
			// Unknown check id, or nothing left to set aside — either way the
			// worklist has moved on under this click.
			return new \WP_Error( 'agentimus_no_pages', __( 'Nothing to set aside for that check.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$all  = $this->settings->stored();
		$list = ( isset( $all['optimize_ignored'] ) && is_array( $all['optimize_ignored'] ) ) ? array_map( 'intval', $all['optimize_ignored'] ) : array();
		$list = array_values( array_unique( array_merge( $list, $ids ) ) );

		$all['optimize_ignored'] = $list;
		$this->settings->update( $all ); // Sanitises + busts the OPTIMIZE cache.

		return rest_ensure_response(
			array(
				'score' => ( new Score( new Settings() ) )->report(),
				'saved' => true,
				'count' => count( $ids ),
			)
		);
	}

	/**
	 * POST /settings/reset — restore factory defaults.
	 *
	 * @return \WP_REST_Response
	 */
	public function reset_settings() {
		$defaults = $this->settings->reset();

		return rest_ensure_response(
			array(
				'settings'     => $defaults,
				'readiness'    => ( new Readiness( $this->settings ) )->report(),
				'exposedPaths' => Exposure::sensitive_paths( $this->settings ),
				'reset'        => true,
			)
		);
	}

	/**
	 * GET /mcp-token — the connection token's safe metadata: enough for the
	 * screen, never the secret.
	 *
	 * @return \WP_REST_Response
	 */
	public function mcp_token_status() {
		return rest_ensure_response( array( 'token' => McpToken::status() ) );
	}

	/**
	 * POST /mcp-token — create (or rotate — same act) the connection token. The
	 * plaintext rides this one response and is never available again.
	 *
	 * @param \WP_REST_Request $request { scope: 'read'|'write' }.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mcp_token_create( \WP_REST_Request $request ) {
		$scope = 'write' === (string) $request->get_param( 'scope' ) ? 'write' : 'read';
		// A "write" token makes no promise the walls don't back: refuse the
		// mismatch loudly instead of minting a key that can't open anything.
		if ( 'write' === $scope && ! $this->settings->enabled( 'enable_agent_writes' ) ) {
			return new \WP_Error(
				'agentimus_writes_off',
				__( 'Turn on “Let connected agents write” first — a write token can’t do anything while writing is off.', 'agentimus' ),
				array( 'status' => 409 )
			);
		}
		$plaintext = McpToken::create( $scope, get_current_user_id() );
		return rest_ensure_response(
			array(
				'plaintext' => $plaintext,
				'token'     => McpToken::status(),
			)
		);
	}

	/**
	 * DELETE /mcp-token — revoke: disconnect everything at once.
	 *
	 * @return \WP_REST_Response
	 */
	public function mcp_token_revoke() {
		McpToken::revoke();
		return rest_ensure_response( array( 'token' => null ) );
	}

	/**
	 * POST /onboarding — mark the first-run setup wizard complete (or skipped) so
	 * it never shows again. Stored as its own option, not inside settings, so a
	 * factory reset doesn't re-trigger the wizard.
	 *
	 * @return \WP_REST_Response
	 */
	public function complete_onboarding( \WP_REST_Request $request ) {
		// Skipping is not abandonment: with `seed`, empty identity fields are
		// filled from what the site already says about itself (the tagline), so
		// even a skipper's llms.txt opens with a real sentence, not a blank.
		// Fields the owner has set are never overwritten.
		if ( ! empty( $request['seed'] ) ) {
			$tagline = Plugin::real_tagline();
			if ( '' !== $tagline && '' === trim( (string) $this->settings->identity( 'about', '' ) ) ) {
				// Full-shape update: a partial array would reset every unset toggle.
				$all                      = $this->settings->stored();
				$all['identity']['about'] = $tagline;
				$this->settings->update( $all );
			}
		}
		update_option( 'agentimus_onboarded', AGENTIMUS_VERSION );
		// Queue the dashboard's "Worth a look next" card — the map to the rooms
		// (Visibility, Cloudflare, MCP) a new owner would otherwise never
		// find. It stays until the owner dismisses it; navigation is theirs.
		if ( 'done' !== get_option( 'agentimus_next_steps', '' ) ) {
			update_option( 'agentimus_next_steps', 'show' );
		}
		// A fresh install has no past: stamp this version as "seen" so the
		// What's New card doesn't stack release notes on top of the map card.
		// It debuts with the site's first real update instead.
		if ( '' === (string) get_option( 'agentimus_whatsnew_seen', '' ) ) {
			update_option( 'agentimus_whatsnew_seen', AGENTIMUS_VERSION );
		}
		return rest_ensure_response( array( 'onboarded' => true ) );
	}

	/**
	 * POST /nextsteps-seen — dismiss the dashboard's post-setup "Worth a look
	 * next" card, for good ('done' also blocks a later re-onboarding from
	 * resurrecting it).
	 *
	 * @return \WP_REST_Response
	 */
	public function nextsteps_seen() {
		update_option( 'agentimus_next_steps', 'done' );
		return rest_ensure_response( array( 'seen' => true ) );
	}

	/**
	 * GET /changelog — the full changelog for the in-admin dialog, parsed from the
	 * BUNDLED readme.txt. Deliberately not fetched from WordPress.org: the changelog
	 * wp.org displays IS this file, so a remote fetch would add an outbound call (and
	 * a failure mode, and a disclosure) to receive what's already on disk — and the
	 * local copy always matches the installed version exactly. Notes are escaped
	 * first, then given minimal formatting (bold/code/links), so the payload is safe
	 * to render as HTML.
	 *
	 * @return \WP_REST_Response
	 */
	public function changelog() {
		$readme = (string) file_get_contents( AGENTIMUS_DIR . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- our own bundled file.

		$entries = array();
		if ( preg_match( '/^== Changelog ==\s*$(.*?)(?=^== |\z)/ms', $readme, $m ) ) {
			// Split into "= 1.24.0 =" blocks; [0] is pre-block junk (blank), then
			// alternating heading / body pairs.
			$parts = preg_split( '/^= (.+?) =\s*$/m', trim( $m[1] ), -1, PREG_SPLIT_DELIM_CAPTURE );
			$count = count( $parts );
			for ( $i = 1; $i < $count; $i += 2 ) {
				$notes = array();
				foreach ( preg_split( '/\R/u', trim( isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '' ) ) as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$notes[] = $this->format_note( preg_replace( '/^\*\s*/', '', $line ) );
				}
				if ( $notes ) {
					$entries[] = array(
						'version' => sanitize_text_field( $parts[ $i ] ),
						'notes'   => $notes,
					);
				}
			}
		}
		return rest_ensure_response( array( 'entries' => $entries ) );
	}

	/**
	 * Escape one changelog line, then apply the readme's minimal formatting:
	 * **bold**, `code`, and bare https URLs become links. Escapes FIRST, so the
	 * result is safe for v-html.
	 *
	 * @param string $line Raw readme line.
	 * @return string Safe HTML.
	 */
	private function format_note( $line ) {
		$out = esc_html( $line );
		$out = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $out );
		// Single-asterisk emphasis (readme *word*) — after ** so bold never half-matches.
		$out = preg_replace( '/\*([^*\s][^*]*)\*/', '<em>$1</em>', $out );
		$out = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $out );
		$out = preg_replace(
			'#(https://[^\s<]+[^\s<.,)])#',
			'<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
			$out
		);
		return $out;
	}

	/**
	 * POST /whatsnew-seen — dismiss the current release's "What's new" card. Stores
	 * the version, so the card returns exactly once per release, never per session.
	 *
	 * @return \WP_REST_Response
	 */
	public function whatsnew_seen() {
		update_option( 'agentimus_whatsnew_seen', AGENTIMUS_VERSION );
		return rest_ensure_response( array( 'seen' => AGENTIMUS_VERSION ) );
	}

	/**
	 * POST /review-ack — record the owner's answer to the Dashboard's review
	 * ask. 'later' snoozes it for a month; 'review' and 'done' close it for
	 * good (and the admin footer's rating line goes quiet with it).
	 *
	 * @param \WP_REST_Request $request Request carrying the 'answer' param.
	 * @return \WP_REST_Response
	 */
	public function review_ack( $request ) {
		$answer = (string) $request['answer'];
		if ( ! in_array( $answer, array( 'review', 'done', 'later' ), true ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'answer must be one of: review, done, later.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		return rest_ensure_response( array( 'state' => Review::ack( $answer ) ) );
	}

	/**
	 * POST /verifier/probe-ranges — the settings form's "Add bot" check: fetch the
	 * candidate IP-ranges URL once (same bounded rules as the daily refresh) and
	 * confirm it actually parses as a range file, so an owner can't save a URL that
	 * could never verify anyone. Admin-clicked and capability-gated; the fetch is
	 * bounded (5s, size-capped, no private hosts) so there's nothing to amplify.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function probe_ranges( \WP_REST_Request $request ) {
		$result = BotRanges::probe( (string) $request->get_param( 'url' ) );
		if ( ! empty( $result['ok'] ) ) {
			return rest_ensure_response(
				array(
					'ok'       => true,
					'prefixes' => (int) $result['prefixes'],
				)
			);
		}
		$messages = array(
			'not-https'        => __( 'The ranges URL must start with https://.', 'agentimus' ),
			'unreachable'      => __( 'That URL didn’t answer — check it’s correct and publicly reachable.', 'agentimus' ),
			'not-a-range-file' => __( 'That URL doesn’t serve a range file — expected the operator’s prefixes JSON (like googlebot.json) or a plain JSON list of ranges.', 'agentimus' ),
		);
		$reason   = isset( $result['reason'] ) ? (string) $result['reason'] : 'not-a-range-file';
		return rest_ensure_response(
			array(
				'ok'      => false,
				'message' => isset( $messages[ $reason ] ) ? $messages[ $reason ] : $messages['not-a-range-file'],
			)
		);
	}

	/**
	 * GET /worklist/rows — rows for exactly these posts.
	 *
	 * The findings hand-off's other half: the worklist's own payload is its
	 * shortlist, and a finding may name pages outside it. Missing posts
	 * (deleted, unpublished) drop out of the answer — the screen says what
	 * that means rather than inventing rows for them.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function worklist_rows( \WP_REST_Request $request ) {
		$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) ) );
		if ( ! $ids ) {
			return rest_ensure_response( array( 'rows' => array() ) );
		}
		$worklist = new Worklist( $this->settings );
		return rest_ensure_response( array( 'rows' => $worklist->rows_for( $ids ) ) );
	}

	/**
	 * POST /worklist/changed — reconcile a held list against the database.
	 *
	 * Takes { id: modified } as the caller last saw it. Answers with rebuilt
	 * rows for the ones that have moved on, and nothing at all when none have —
	 * which is the common case and costs one indexed query.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	/**
	 * Hide a suggestion, or bring it back.
	 *
	 * Returns the rebuilt findings payload rather than an acknowledgement: the
	 * screen has to show the ledger count change in the same beat as the row
	 * leaving, and a client that had to re-fetch could show one without the other.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function dismiss_finding( \WP_REST_Request $request ) {
		$id     = sanitize_key( (string) $request->get_param( 'id' ) );
		$hidden = (bool) $request->get_param( 'hidden' );
		if ( '' === $id ) {
			return new \WP_REST_Response( array( 'message' => __( 'No finding was named.', 'agentimus' ) ), 400 );
		}

		// ⚠️ Merged into the FULL settings array: update() sanitises what it is
		// given and writes the result whole, so a partial body would reset every
		// unset boolean on the site.
		$all  = $this->settings->stored();
		$list = isset( $all['findings_dismissed'] ) && is_array( $all['findings_dismissed'] )
			? array_map( 'sanitize_key', array_map( 'strval', $all['findings_dismissed'] ) )
			: array();

		$list = $hidden
			? array_values( array_unique( array_merge( $list, array( $id ) ) ) )
			: array_values( array_diff( $list, array( $id ) ) );

		$all['findings_dismissed'] = $list;
		$this->settings->update( $all );

		return new \WP_REST_Response(
			array( 'findings' => ( new Findings( $this->settings ) )->all() ),
			200
		);
	}

	public function worklist_changed( \WP_REST_Request $request ) {
		$seen = (array) $request->get_param( 'seen' );
		if ( ! $seen ) {
			return rest_ensure_response( array( 'rows' => array() ) );
		}

		$worklist = new Worklist( $this->settings );
		$stamps   = $worklist->stamps( array_keys( $seen ) );

		$changed = array();
		foreach ( $stamps as $id => $modified ) {
			$had = isset( $seen[ $id ] ) ? (string) $seen[ $id ] : '';
			if ( $had !== $modified ) {
				$changed[] = (int) $id;
			}
		}
		// A post that has been deleted or unpublished since is reported too, so
		// the row can leave rather than linger as a verdict about nothing.
		$gone = array_values( array_diff( array_map( 'intval', array_keys( $seen ) ), array_map( 'intval', array_keys( $stamps ) ) ) );

		return rest_ensure_response(
			array(
				'rows' => $changed ? $worklist->rows_for( $changed ) : array(),
				'gone' => $gone,
			)
		);
	}

	/**
	 * GET /readiness.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_readiness() {
		return rest_ensure_response( ( new Readiness( $this->settings ) )->report() );
	}

	/**
	 * GET /findings — every open finding across every subsystem, ranked.
	 *
	 * The Findings screen's refresh. It arrives with the boot payload too; this
	 * route is how the screen catches up after the owner acts on something (a
	 * Block, an Ignore, a settings save) without a full page reload.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_findings() {
		return rest_ensure_response( ( new Findings( $this->settings ) )->all() );
	}

	/**
	 * POST /findings/seen — the owner has read the good news.
	 *
	 * A resolution expires on its own after a week, which is right for a win
	 * nobody looked at and wrong for one they read on day one: a whole week of a
	 * fixture they have finished with is a notice that trains them to skim past
	 * that part of the screen. This is the way out — and it takes the whole
	 * batch, because "seen it" is about the news, not about each line of it.
	 *
	 * Returns the refreshed payload, so the screen redraws from the server's
	 * answer rather than guessing what dismissing did.
	 *
	 * @return \WP_REST_Response
	 */
	public function mark_findings_seen() {
		Search\Progress::mark_seen();
		return rest_ensure_response( ( new Findings( $this->settings ) )->all() );
	}

	/**
	 * GET /worklist — one row per piece of content, with the search it is found
	 * for, whether it answers it, and what the content checks flagged.
	 *
	 * Deliberately NOT in the boot payload: every row parses a page, so shipping
	 * it with the admin bootstrap would slow every page load for a section the
	 * owner may not scroll to. The screen asks for it when it needs it.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_worklist( \WP_REST_Request $request ) {
		$worklist = new Worklist( $this->settings );

		// Grading happens on cron, but a screen that has just been opened on a
		// fresh site would sit empty waiting for it. One chunk here means the
		// first look already has rows, and the sweep carries on behind.
		$swept = (int) $worklist->sweep();

		$out = $worklist->page(
			(string) $request->get_param( 'filter' ),
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per' ),
			(string) $request->get_param( 'issue' )
		);

		// ⚠️⚠️ HOW MANY PAGES THIS VERY REQUEST RE-READ — and it is not a
		// statistic, it is a warning to the screen. The chunk above runs BEFORE
		// the counts below are taken, so on any site with a backlog these counts
		// are newer than the finding sitting above this list on the same screen.
		// His site, 2026-08-19: "69 Posts and Pages are worth fixing" over chips
		// reading 68, because one page had just been re-read and cleared. Above
		// zero means: whatever else is showing these numbers is now out of date.
		$out['swept'] = $swept;

		return rest_ensure_response( $out );
	}

	/**
	 * POST /cron/run — run this plugin's overdue scheduled jobs here and now.
	 *
	 * ⭐ The answer says what happened and what is left, because the caller is a
	 * loop: one page view drains a few chunks and the next takes the rest. A
	 * bare "ok" would leave the browser guessing whether to come back.
	 *
	 * @return \WP_REST_Response
	 */
	public function run_cron() {
		$out = Cron::run_due();

		return rest_ensure_response(
			array(
				'ran'       => (int) $out['ran'],
				'remaining' => (int) $out['remaining'],
				// "busy" is not a failure: another tab or a real cron pass holds
				// the lock, which is the mechanism working, not refusing.
				'skipped'   => (string) $out['skipped'],
				'health'    => Cron::health(),
			)
		);
	}

	/**
	 * GET /score — the composite AEO/GEO score, recomputed.
	 *
	 * The score card used to arrive only two ways: with the page's initial bootstrap, or in
	 * the reply to a settings save. So an edit made anywhere else — publishing a post in
	 * another tab, which is the normal case — left an open dashboard showing a stale score
	 * and a "next step" pointing at a page the owner had already fixed, with no way back but
	 * a full page reload. This route gives the admin a way to ask for the current score.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_score() {
		$readiness = ( new Readiness( $this->settings ) )->report();
		return rest_ensure_response(
			array(
				'score'     => $this->score_report( $readiness ),
				// The report the score was graded FROM rides along, so the app's
				// tab-return refresh updates the readiness rows too — a fix made
				// in another tab (Customizer, Reading settings) shows on return.
				'readiness' => $readiness,
			)
		);
	}

	/**
	 * GET /preview/schema — the JSON-LD document for the site or a chosen post.
	 *
	 * Returns the graph regardless of whether the front end is currently emitting
	 * it (schema disabled, or an SEO plugin owns it): the point of a preview is to
	 * show what WOULD ship. The `active`/`reason` fields let the UI explain when the
	 * live `<head>` is empty. An unpublished post (draft/pending/scheduled/private)
	 * is previewed with its would-be per-post node — flagged by `postNote` as not yet
	 * live — so the owner sees what publishing will emit. A password-protected post
	 * is the one exception: its body never ships as schema, so it still yields only
	 * the site-level nodes. `livePublic` marks whether the target is reachable at a
	 * public URL right now, gating the URL-based validators.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_schema_preview( \WP_REST_Request $request ) {
		$schema  = new Schema( $this->settings );
		$seo     = $schema->seo_plugin_active();
		$enabled = $this->settings->enabled( 'enable_schema' );
		$post_id = absint( $request->get_param( 'post' ) );

		$post          = null;
		$post_included = true;
		$post_note     = '';
		$live_public   = true; // The site view is always reachable at a public URL.
		$target        = array(
			'type'  => 'site',
			'id'    => 0,
			'label' => __( 'Site-wide identity', 'agentimus' ),
			'url'   => home_url( '/' ),
		);

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
				return new \WP_Error(
					'agentimus_preview_not_found',
					__( 'That content is not available to preview.', 'agentimus' ),
					array( 'status' => 404 )
				);
			}
			$target = array(
				'type'  => 'post',
				'id'    => (int) $post->ID,
				'label' => $this->preview_label( $post ),
				'url'   => (string) get_permalink( $post ),
			);
			// Only a published, non-gated post is reachable at a public URL — the
			// precondition for the URL-based validators (a draft URL would 404).
			$live_public = ( 'publish' === $post->post_status && '' === (string) $post->post_password );
			if ( '' !== (string) $post->post_password ) {
				// Never previewed: a gated body stays private even once published.
				$post_included = false;
				$post_note     = __( 'This is password-protected, so its content is never exposed as schema — only the site-wide identity below.', 'agentimus' );
			} elseif ( 'publish' !== $post->post_status ) {
				// Preview the node this post WILL emit once it's published — clearly
				// flagged as not-yet-live so the owner doesn't mistake it for live output.
				$post_note = __( 'Not published yet — this is a preview of the per-post schema that will ship once you publish. It isn’t in your live page’s <head> yet.', 'agentimus' );
			}
		}

		$doc = ( null === $post )
			? $schema->build_document( null, true )
			: $schema->build_document( $post, false, true );

		return rest_ensure_response(
			array(
				'active'       => (bool) ( $enabled && ! $seo ),
				'reason'       => ! $enabled ? 'disabled' : ( $seo ? 'seo_plugin' : 'ok' ),
				'seoPlugin'    => (bool) $seo,
				'target'       => $target,
				'postIncluded' => (bool) $post_included,
				'postNote'     => $post_note,
				'livePublic'   => (bool) $live_public,
				'graph'        => $doc,
				// Pretty, slash-unescaped JSON for reading/validating. The live <head>
				// escapes slashes to stay safe inside <script>; the data is identical.
				'json'         => null === $doc
					? ''
					: (string) wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			)
		);
	}

	/**
	 * GET /preview/markdown — the Markdown a page/post is served as (`.md` / the
	 * `Accept: text/markdown` twin). The site target (post = 0) previews the index
	 * Markdown (`index_markdown()`), served at the home URL (`.md`) and /llms.txt.
	 * Mirrors the front-end privacy guard — a draft or password-protected
	 * post yields nothing, with `postNote` saying why. `active`/`reason` report
	 * whether Markdown delivery is switched on, so the UI can preview it either way.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_markdown_preview( \WP_REST_Request $request ) {
		$enabled = $this->settings->enabled( 'enable_markdown' );
		$post_id = absint( $request->get_param( 'post' ) );

		if ( $post_id <= 0 ) {
			// The site target DOES have a Markdown twin: the index Agentimus serves at
			// the home URL (append `.md`) and at /llms.txt — the exact bytes
			// index_markdown() ships (which reuses the llms.txt index). It's built from
			// the site identity + the page/post list, not a single page's body, so it's
			// noted as such rather than presented as a per-page document.
			$llms = new LlmsText( $this->settings );
			return rest_ensure_response(
				array(
					'active'       => (bool) $enabled,
					'reason'       => $enabled ? 'ok' : 'disabled',
					'target'       => array(
						'type'  => 'site',
						'id'    => 0,
						'label' => __( 'Site-wide identity', 'agentimus' ),
						'url'   => home_url( '/' ),
					),
					'postIncluded' => true,
					'postNote'     => __( 'The site index — built from your identity and the list of pages & posts, not a single page’s body.', 'agentimus' ),
					'livePublic'   => true,
					'markdown'     => (string) $llms->index_markdown(),
					'mdUrl'        => home_url( '/index.md' ),
				)
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return new \WP_Error(
				'agentimus_preview_not_found',
				__( 'That content is not available to preview.', 'agentimus' ),
				array( 'status' => 404 )
			);
		}

		$target = array(
			'type'  => 'post',
			'id'    => (int) $post->ID,
			'label' => $this->preview_label( $post ),
			'url'   => (string) get_permalink( $post ),
		);

		$post_included = true;
		$post_note     = '';
		$markdown      = '';
		$md_url        = '';
		// Markdown is genuine served content (no "would-be" preview), so a public URL
		// exists only for a published, non-gated post — same rule as the schema path.
		$live_public = ( 'publish' === $post->post_status && '' === (string) $post->post_password );

		if ( 'publish' !== $post->post_status ) {
			$post_included = false;
			$post_note     = __( 'This isn’t published, so no Markdown is served for it yet.', 'agentimus' );
		} elseif ( '' !== (string) $post->post_password ) {
			$post_included = false;
			$post_note     = __( 'This is password-protected, so its content is never served as Markdown.', 'agentimus' );
		} else {
			$markdown  = Markdown::post( $post->ID );
			$permalink = (string) get_permalink( $post );
			// Mirrors Endpoints::markdown_alternate_url(): the permalink with `.md`.
			$md_url = '' !== $permalink ? untrailingslashit( $permalink ) . '.md' : '';
		}

		return rest_ensure_response(
			array(
				'active'       => (bool) $enabled,
				'reason'       => $enabled ? 'ok' : 'disabled',
				'target'       => $target,
				'postIncluded' => (bool) $post_included,
				'postNote'     => $post_note,
				'livePublic'   => (bool) $live_public,
				'markdown'     => (string) $markdown,
				'mdUrl'        => $md_url,
			)
		);
	}

	/**
	 * GET /preview/targets — the in-scope content the preview can describe, grouped
	 * by post type (Pages, Posts, then CPTs), most-recently-modified first, filtered
	 * by an optional title search.
	 *
	 * Each group carries its full `total` and a batch of up to PREVIEW_PAGE_SIZE
	 * `items`, so a small type (pages) arrives whole while a large one (posts,
	 * products) paginates: pass `type` + `offset` to fetch that group's next batch
	 * for the "Load more" button. Response: `{ groups: [ { type, typeLabel,
	 * typeSource, total, items, offset, hasMore } ] }`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_schema_targets( \WP_REST_Request $request ) {
		$search    = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$only_type = sanitize_key( (string) $request->get_param( 'type' ) );
		$offset    = absint( $request->get_param( 'offset' ) );

		$scoped = Content::post_types();

		// A "Load more" request names one type and an offset; the initial load walks
		// every in-scope type from the top.
		if ( '' !== $only_type && in_array( $only_type, $scoped, true ) ) {
			$types = array( $only_type );
		} else {
			$types  = $scoped;
			$offset = 0; // offset only makes sense for a single-type batch.
		}

		// Pages first, then Posts, then any CPTs (alphabetically) — mirrors the picker.
		$rank = static function ( $t ) {
			return 'page' === $t ? 0 : ( 'post' === $t ? 1 : 2 );
		};
		usort(
			$types,
			static function ( $a, $b ) use ( $rank ) {
				$d = $rank( $a ) - $rank( $b );
				return 0 !== $d ? $d : strcmp( $a, $b );
			}
		);

		$groups = array();
		foreach ( $types as $type ) {
			$args = array(
				'post_type'           => $type,
				'post_status'         => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page'      => self::PREVIEW_PAGE_SIZE,
				'offset'              => $offset,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			);
			if ( '' !== $search ) {
				$args['s'] = $search;
			}

			$query = new \WP_Query( $args );

			$items = array();
			foreach ( $query->posts as $post ) {
				$items[] = array(
					'id'     => (int) $post->ID,
					'type'   => $type,
					'label'  => $this->preview_label( $post ),
					'status' => $post->post_status,
					'url'    => (string) get_permalink( $post ),
				);
			}

			$total = (int) $query->found_posts;

			// An empty type is simply absent from the initial list; a Load-more request
			// still returns its (empty) group so the client can settle its state.
			if ( 0 === $total && '' === $only_type ) {
				continue;
			}

			$obj = get_post_type_object( $type );

			$groups[] = array(
				'type'       => $type,
				'typeLabel'  => ( $obj && ! empty( $obj->labels->name ) ) ? $obj->labels->name : ucfirst( $type ),
				// Vendor that registered the type, so a generic "Products" can be attributed (FluentCart vs WooCommerce vs …).
				'typeSource' => Content::source( $type ),
				'total'      => $total,
				'items'      => $items,
				'offset'     => $offset,
				'hasMore'    => ( $offset + count( $items ) ) < $total,
			);
		}

		return rest_ensure_response( array( 'groups' => $groups ) );
	}

	/**
	 * A human label for a previewable post — its title, or a stable placeholder for
	 * an untitled draft so the picker never shows a blank row.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function preview_label( $post ) {
		// Decode entities (get_the_title() returns e.g. &#8220;…&#8221;) so the label
		// reads as clean text, not raw HTML entities.
		$title = trim( Worklist::decode_title( get_the_title( $post ) ) );
		if ( '' === $title ) {
			/* translators: %d: post ID. */
			$title = sprintf( __( '(untitled #%d)', 'agentimus' ), (int) $post->ID );
		}
		return $title;
	}
}
