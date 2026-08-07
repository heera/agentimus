<?php
/**
 * Bing REST controller — the verification code, connect / status /
 * disconnect, the summary the Found by AI Search card renders, and an
 * on-demand refresh.
 *
 * Everything is manage_options-gated. The API key goes IN through the
 * connect call and never comes back out in any response.
 *
 * @package Agentimus
 */

namespace Agentimus\Bing;

defined( 'ABSPATH' ) || exit;

final class Rest {

	/** @var string REST namespace. */
	const NS = 'agentimus/v1';

	/** @var int Longest summary window the UI may ask for (days). */
	const MAX_DAYS = 90;

	/** @var Settings */
	private $bing;

	/** @var \Agentimus\Settings Core settings — the declared policy the conflicts compare against. */
	private $core;

	/** @var Client */
	private $client;

	/**
	 * @param Settings            $bing   The Bing connection store.
	 * @param \Agentimus\Settings $core   The core settings instance.
	 * @param Client|null         $client Injectable for tests.
	 */
	public function __construct( Settings $bing, \Agentimus\Settings $core, ?Client $client = null ) {
		$this->bing   = $bing;
		$this->core   = $core;
		$this->client = $client ? $client : new Client();
	}

	/**
	 * Hooks only.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route( self::NS, '/bing', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					// No format validation: schema validation runs BEFORE the
					// permission callback. Value checks live in the callback.
					'key' => array( 'type' => 'string', 'required' => true ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		) );

		register_rest_route( self::NS, '/bing/code', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'save_code' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array(
				'code' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( self::NS, '/bing/summary', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'summary' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'required' => false ),
			),
		) );

		register_rest_route( self::NS, '/bing/refresh', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'refresh' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'required' => false ),
			),
		) );
	}

	/**
	 * Gate: site owners only.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The connection state, key excluded.
	 *
	 * @return array
	 */
	public function status() {
		return $this->bing->public_view();
	}

	/**
	 * Step 1: store the verification code. The tag prints on the very next
	 * page view — that is the whole step.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array|\WP_Error
	 */
	public function save_code( $request ) {
		$code = trim( (string) $request->get_param( 'code' ) );
		if ( '' === $code ) {
			return new \WP_Error( 'agentimus_bing_code', __( 'Paste the verification code first.', 'agentimus' ), array( 'status' => 400 ) );
		}
		// Owners paste either the bare code or the whole meta tag — accept both.
		if ( preg_match( '/content\s*=\s*["\']([^"\']+)["\']/', $code, $m ) ) {
			$code = $m[1];
		}
		$this->bing->set_msvalidate( $code );
		return $this->status();
	}

	/**
	 * Step 2: verify the key against Bing, find this site among the account's
	 * sites — adding and verifying it when needed — then store the connection
	 * and run a first poll inline so the card has numbers right away.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array|\WP_Error
	 */
	public function connect( $request ) {
		$key = trim( (string) $request->get_param( 'key' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'agentimus_bing_key', __( 'Paste a Bing Webmaster API key first.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$account = $this->client->user_sites( $key );
		if ( isset( $account['error'] ) ) {
			return new \WP_Error( 'agentimus_bing_verify', (string) $account['error'], array( 'status' => 400 ) );
		}

		$home = home_url( '/' );
		$site = null;
		foreach ( (array) $account['sites'] as $candidate ) {
			if ( self::hosts_match( $candidate['url'], $home ) ) {
				$site = $candidate;
				break;
			}
		}

		// Not in the account yet: add it — the tag we print makes verification
		// possible without leaving this screen.
		if ( null === $site ) {
			$added = $this->client->add_site( $key, $home );
			if ( isset( $added['error'] ) ) {
				return new \WP_Error( 'agentimus_bing_add', (string) $added['error'], array( 'status' => 400 ) );
			}
			$site = array( 'url' => $home, 'verified' => false );
		}

		if ( empty( $site['verified'] ) ) {
			if ( '' === (string) $this->bing->get( 'msvalidate', '' ) ) {
				return new \WP_Error(
					'agentimus_bing_unverified',
					__( 'Bing has not verified this site yet. Paste the verification code in step 1 first — Agentimus prints the tag for you — then connect again.', 'agentimus' ),
					array( 'status' => 400 )
				);
			}
			$verified = $this->client->verify_site( $key, (string) $site['url'] );
			if ( isset( $verified['error'] ) ) {
				return new \WP_Error( 'agentimus_bing_verifysite', (string) $verified['error'], array( 'status' => 400 ) );
			}
			if ( empty( $verified['verified'] ) ) {
				return new \WP_Error(
					'agentimus_bing_unverified',
					__( 'Bing could not verify the site yet. The tag is printed — give it a minute, or press Verify in Bing Webmaster Tools, then connect again.', 'agentimus' ),
					array( 'status' => 400 )
				);
			}
		}

		$this->bing->connect( $key, (string) $site['url'] );
		Module::schedule();

		// First numbers now, not tomorrow. A failure here is recorded on the
		// connection (last_error) rather than failing the connect.
		( new Module( $this->bing, $this->client ) )->run_poll();

		return $this->status();
	}

	/**
	 * Forget the key and stand the cron down. Stored aggregates are kept, and
	 * so is the verification code — the printed tag makes reconnecting easy.
	 *
	 * @return array
	 */
	public function disconnect() {
		$this->bing->disconnect();
		Module::unschedule();
		return $this->status();
	}

	/**
	 * The summary the Found by AI Search card renders.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array
	 */
	public function summary( $request ) {
		$days = (int) $request->get_param( 'days' );
		$days = ( $days >= 1 && $days <= self::MAX_DAYS ) ? $days : 30;

		return Summary::build( $this->bing, $this->core, $days );
	}

	/**
	 * Refresh: run one poll inline — the same read the daily cron does — then
	 * hand back the fresh summary in the same response. A poll failure lands
	 * in last_error (shown on the card), never as an HTTP error.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array|\WP_Error
	 */
	public function refresh( $request ) {
		if ( ! $this->bing->connected() ) {
			return new \WP_Error( 'agentimus_bing_off', __( 'Connect Bing first.', 'agentimus' ), array( 'status' => 400 ) );
		}

		( new Module( $this->bing, $this->client ) )->run_poll();

		return $this->summary( $request );
	}

	/**
	 * Whether two URLs name the same site, tolerating the www prefix and
	 * scheme differences — Bing stores whatever form the site was added in.
	 *
	 * @param string $a One URL.
	 * @param string $b Another URL.
	 * @return bool
	 */
	public static function hosts_match( $a, $b ) {
		$ha = strtolower( (string) wp_parse_url( (string) $a, PHP_URL_HOST ) );
		$hb = strtolower( (string) wp_parse_url( (string) $b, PHP_URL_HOST ) );
		$ha = 0 === strpos( $ha, 'www.' ) ? substr( $ha, 4 ) : $ha;
		$hb = 0 === strpos( $hb, 'www.' ) ? substr( $hb, 4 ) : $hb;
		return '' !== $ha && $ha === $hb;
	}
}
