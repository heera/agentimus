<?php
/**
 * Fill the gaps — REST surface for the admin screen. Owner-only
 * (`manage_options`): unlike the editor's per-post draft buttons, a bulk run
 * spends real money across the whole site, so it belongs to the person who
 * pays the AI bill.
 *
 * Field names are validated IN the callbacks, never via an args `enum` — WP
 * validates enums before the permission callback runs, which would 400 a
 * subscriber that must be 403'd (the lesson the 1.27.1 patch paid for).
 *
 * @package Agentimus
 */

namespace Agentimus\Bulk;

use Agentimus\Assist;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'agentimus/v1';

	/** Review-list rows per page. */
	const PAGE_SIZE = 20;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook route registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Define the routes.
	 */
	public function routes() {
		$manage = static function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			self::NS,
			'/bulk/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NS,
			'/bulk/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => $manage,
				'args'                => array(
					'field' => array(
						'type'     => 'string',
						'required' => true,
					),
					// The exact items to draft — the owner's picks, never a server guess.
					'ids'   => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/bulk/items',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'items' ),
				'permission_callback' => $manage,
				'args'                => array(
					'field' => array(
						'type'     => 'string',
						'required' => true,
					),
					'page'  => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/bulk/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => $manage,
				'args'                => $this->act_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/bulk/reject',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reject' ),
				'permission_callback' => $manage,
				'args'                => $this->act_args(),
			)
		);
	}

	/**
	 * Shared args for apply/reject: the field, and optionally WHICH items — no
	 * ids means "every proposal this field currently holds".
	 *
	 * @return array
	 */
	private function act_args() {
		return array(
			'field' => array(
				'type'     => 'string',
				'required' => true,
			),
			'ids'   => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * GET /bulk/overview — the census the screen opens with.
	 *
	 * @return \WP_REST_Response
	 */
	public function overview() {
		return rest_ensure_response(
			array(
				'fields'    => ( new Scanner() )->counts(),
				'ai'        => Assist::ai_available(),
				'batchSize' => Scanner::BATCH_SIZE,
				'pageSize'  => self::PAGE_SIZE,
			)
		);
	}

	/**
	 * POST /bulk/generate — draft the exact items the owner picked.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate( \WP_REST_Request $request ) {
		$field = (string) $request['field'];
		$check = $this->usable( $field );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! Assist::ai_available() ) {
			return new \WP_Error( 'agentimus_ai_unavailable', __( 'No AI provider is configured. Add one under Settings → AI.', 'agentimus' ), array( 'status' => 503 ) );
		}

		$ids = is_array( $request['ids'] ) ? $request['ids'] : array();
		if ( empty( $ids ) ) {
			return new \WP_Error( 'rest_invalid_param', __( 'Pick at least one item to draft.', 'agentimus' ), array( 'status' => 400 ) );
		}
		$runner = new Runner( new Assist( $this->settings ), new Scanner() );
		return rest_ensure_response( $runner->run( $field, $ids ) );
	}

	/**
	 * GET /bulk/items — one page of the transparent scan list: every item missing
	 * this field, with its draft when one is parked.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function items( \WP_REST_Request $request ) {
		$field = (string) $request['field'];
		$check = $this->usable( $field );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$scanner = new Scanner();
		$page    = max( 1, (int) $request['page'] );
		$total   = $scanner->missing_count( $field );
		$rows    = array();
		foreach ( $scanner->items_page( $field, $page, self::PAGE_SIZE ) as $id ) {
			$rows[] = Proposals::item_row( $id, $field );
		}

		return rest_ensure_response(
			array(
				'rows'  => $rows,
				'total' => $total,
				'pages' => (int) ceil( $total / self::PAGE_SIZE ),
			)
		);
	}

	/**
	 * POST /bulk/apply — write approved proposals into the real fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply( \WP_REST_Request $request ) {
		return $this->act( $request, 'apply' );
	}

	/**
	 * POST /bulk/reject — discard proposals.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reject( \WP_REST_Request $request ) {
		return $this->act( $request, 'reject' );
	}

	/**
	 * Shared apply/reject walk: the named ids, or every current proposal of the
	 * field when none are named ("Use all" / "Dismiss all").
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $action  'apply' or 'reject'.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function act( \WP_REST_Request $request, $action ) {
		$field = (string) $request['field'];
		$check = $this->usable( $field );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$ids = $request['ids'];
		$ids = is_array( $ids ) && ! empty( $ids )
			? array_values( array_filter( array_map( 'absint', $ids ) ) )
			: ( new Scanner() )->proposed_ids( $field );

		$applied = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			if ( 'apply' === $action ) {
				$result = Proposals::apply( $id, $field );
				if ( 'applied' === $result ) {
					++$applied;
				} elseif ( 'skipped_filled' === $result ) {
					++$skipped;
				}
			} else {
				Proposals::reject( $id, $field );
			}
		}

		return rest_ensure_response(
			array(
				'applied' => $applied,
				'skipped' => $skipped,
				'counts'  => ( new Scanner() )->counts(),
			)
		);
	}

	/**
	 * Whether a field id names a real, currently-enabled field — the callbacks'
	 * shared validation (deliberately not an args enum, see the file docblock).
	 *
	 * @param string $field Field id.
	 * @return true|\WP_Error
	 */
	private function usable( $field ) {
		if ( ! Scanner::valid_field( $field ) ) {
			return new \WP_Error( 'rest_invalid_param', __( 'Unknown field.', 'agentimus' ), array( 'status' => 400 ) );
		}
		if ( ! Scanner::field_enabled( $field ) ) {
			return new \WP_Error( 'agentimus_field_off', __( 'That feature is turned off.', 'agentimus' ), array( 'status' => 400 ) );
		}
		return true;
	}
}
