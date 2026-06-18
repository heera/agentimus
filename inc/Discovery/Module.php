<?php
/**
 * Discovery module — wires the registry, the /.well-known front controller, the
 * advertising Link header, and a CI-friendly validation endpoint. This is the
 * single seam Plugin::boot() turns on.
 *
 * @package HeeraAgentDiscovery
 */

namespace HeeraAgentDiscovery\Discovery;

use HeeraAgentDiscovery\Settings;

defined( 'ABSPATH' ) || exit;

final class Module {

	/** @var Settings */
	private $settings;

	/** @var Registry */
	private $registry;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->registry = Registry::instance();
	}

	/**
	 * Register every discovery hook.
	 */
	public function register() {
		( new WellKnown( $this->settings, $this->registry ) )->register();

		// Built-in adapters: generic, zero-config auto-discovery only — no
		// plugin-specific knowledge. They read WordPress's own registries (REST
		// namespaces, post types, the Abilities API) and register through the same
		// public hook a third-party would use.
		( new Adapters\RestApi() )->register();
		( new Adapters\AbilitiesApi() )->register();

		// Opt-in well-known generators that fill a gap WITHOUT overriding a real
		// on-disk file or another plugin's document (each class owns its precedence).
		( new SecurityTxt( $this->settings ) )->register();

		add_action( 'send_headers', array( $this, 'link_header' ) );
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );
	}

	/**
	 * Advertise the discovery index on every front-end response so an agent can
	 * find it from a single header without guessing.
	 */
	public function link_header() {
		if ( is_admin() ) {
			return;
		}
		$url = esc_url_raw( home_url( '/.well-known/discovery.json' ) );
		// Advertise with a REGISTERED relation (RFC 8631 `service-desc`, a
		// machine-readable description of the service) so standards-aware clients
		// recognise the entry point, plus the protocol's own `discovery` rel for
		// WP_Discovery-aware agents.
		header( 'Link: <' . $url . '>; rel="service-desc"; type="application/json"', false );
		header( 'Link: <' . $url . '>; rel="discovery"; type="application/json"', false );
	}

	/**
	 * Read-only REST surface:
	 *   GET /wp-json/heera-agent-discovery/v1/discovery  → the live envelope
	 *   GET /wp-json/heera-agent-discovery/v1/validate   → validation notices for CI
	 */
	public function rest_routes() {
		$envelope = new Envelope( $this->settings, $this->registry );

		register_rest_route(
			'heera-agent-discovery/v1',
			'/discovery',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () use ( $envelope ) {
					\HeeraAgentDiscovery\Activity\Recorder::record( 'rest:discovery' );
					return rest_ensure_response( $envelope->build() );
				},
			)
		);

		register_rest_route(
			'heera-agent-discovery/v1',
			'/discovery/hub',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function () {
					return rest_ensure_response( Hub::data( $this->settings, $this->registry ) );
				},
			)
		);

		register_rest_route(
			'heera-agent-discovery/v1',
			'/validate',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function () {
					$this->registry->collect();
					$notices = $this->registry->notices();
					return rest_ensure_response(
						array(
							'ok'        => empty( array_filter( $notices, static function ( $n ) {
								return 'error' === $n['level'];
							} ) ),
							'resources' => count( $this->registry->resources() ),
							'notices'   => $notices,
						)
					);
				},
			)
		);
	}
}
