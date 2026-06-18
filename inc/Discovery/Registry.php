<?php
/**
 * Registry — the collector every provider registers with.
 *
 * Fires the canonical `wpdiscovery_register` action (and the back-compat
 * `agentomatic_register` alias) exactly once per request, passing itself
 * so providers can call `$registry->register()`. Also drains the static queue
 * filled by the global `Agentomatic_Discovery::register()` facade, so it doesn't
 * matter whether an author registers via the hook or the facade, or in what
 * order. Validation runs synchronously; rejects land in `notices()` for the
 * admin Validation screen.
 *
 * @package Agentomatic
 */

namespace Agentomatic\Discovery;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @var Registry|null */
	private static $instance = null;

	/** @var array<string,array> Normalized resources, keyed by id. */
	private $resources = array();

	/** @var array<string,array> Well-known providers, keyed by name. */
	private $well_known = array();

	/** @var array<int,array{level:string,message:string}> Validation notices. */
	private $notices = array();

	/** @var bool Guard so the collect hook fires only once. */
	private $collected = false;

	/**
	 * Singleton accessor — both the action callback and the facade reference
	 * this one instance.
	 *
	 * @return Registry
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* ---------------------------------------------------------------------- *
	 *  Public registration API (the standard surface)
	 * ---------------------------------------------------------------------- */

	/**
	 * Register a discovery resource. The canonical method named by the protocol
	 * spec (§8): `$registry->register( [...] )`.
	 *
	 * @param array $raw Resource definition (see Resource).
	 * @return true|\WP_Error True on success, WP_Error describing a fatal field.
	 */
	public function register( $raw ) {
		return $this->add( $raw );
	}

	/**
	 * Register a discovery resource. Validates immediately. Retained alias of
	 * register() for call-site brevity.
	 *
	 * @param array $raw Resource definition (see Resource).
	 * @return true|\WP_Error True on success, WP_Error describing a fatal field.
	 */
	public function add( $raw ) {
		$resource = Resource::normalize( $raw );
		if ( is_wp_error( $resource ) ) {
			$this->notices[] = array( 'level' => 'error', 'message' => $resource->get_error_message() );
			return $resource;
		}

		$id = $resource['id'];
		if ( isset( $this->resources[ $id ] ) ) {
			$this->notices[] = array(
				'level'   => 'warning',
				/* translators: %s: resource id. */
				'message' => sprintf( __( 'Duplicate resource id "%s" — the later registration won.', 'agentomatic' ), $id ),
			);
		}
		$this->resources[ $id ] = $resource;
		return true;
	}

	/**
	 * Register a well-known document this site should serve (or index). One of
	 * `callback`, `redirect` or `file` must be present; the front controller
	 * always lets a real on-disk file win over any of these.
	 *
	 * @param array $def {
	 *     @type string   $name         Resource name, e.g. "security.txt" (no slash).
	 *     @type string   $content_type Optional MIME type. Default "text/plain".
	 *     @type callable $callback     Returns the body string.
	 *     @type string   $redirect     A path/URL to 302 to.
	 *     @type string   $file         Absolute path to a static file to stream.
	 * }
	 * @return true|\WP_Error
	 */
	public function add_well_known( $def ) {
		$name = isset( $def['name'] ) ? ltrim( sanitize_file_name( (string) $def['name'] ), '/' ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'wpd_wk_name', __( 'A well-known document needs a name.', 'agentomatic' ) );
		}
		if ( empty( $def['callback'] ) && empty( $def['redirect'] ) && empty( $def['file'] ) ) {
			/* translators: %s: well-known document name, e.g. "security.txt". */
			return new \WP_Error( 'wpd_wk_source', sprintf( __( 'Well-known "%s" needs a callback, redirect or file.', 'agentomatic' ), $name ) );
		}
		if ( isset( $this->well_known[ $name ] ) ) {
			$this->notices[] = array(
				'level'   => 'warning',
				/* translators: %s: well-known name. */
				'message' => sprintf( __( 'Well-known "%s" already claimed — first provider kept.', 'agentomatic' ), $name ),
			);
			return new \WP_Error( 'wpd_wk_conflict', __( 'Already claimed.', 'agentomatic' ) );
		}

		$this->well_known[ $name ] = array(
			'name'         => $name,
			'content_type' => isset( $def['content_type'] ) ? (string) $def['content_type'] : 'text/plain',
			'callback'     => isset( $def['callback'] ) ? $def['callback'] : null,
			'redirect'     => isset( $def['redirect'] ) ? Resource::url( $def['redirect'] ) : '',
			'file'         => isset( $def['file'] ) ? (string) $def['file'] : '',
			'source'       => 'managed',
		);
		return true;
	}

	/* ---------------------------------------------------------------------- *
	 *  Collection
	 * ---------------------------------------------------------------------- */

	/**
	 * Fire the registration hook (once) and drain the facade queue. Idempotent:
	 * safe to call from every output endpoint.
	 *
	 * @return Registry $this
	 */
	public function collect() {
		if ( $this->collected ) {
			return $this;
		}
		$this->collected = true;

		// Drain the facade queue FIRST, so resources registered through the global
		// facade are already present when the hook fires. The late-priority (99)
		// auto-discovery adapter can then treat them, too, as already-described
		// namespaces and skip its generic stub for them.
		if ( class_exists( '\Agentomatic_Discovery' ) ) {
			foreach ( \Agentomatic_Discovery::drain() as $queued ) {
				$this->add( $queued );
			}
		}

		/**
		 * The public registration hook. Providers do:
		 *
		 *     add_action( 'wpdiscovery_register', function ( $registry ) {
		 *         $registry->register( [ 'id' => '…', 'title' => '…', 'type' => '…' ] );
		 *     } );
		 *
		 * If no WP_Discovery engine is active the action simply never fires — no
		 * guard needed. We fire the canonical `wpdiscovery_register` first, then
		 * the product-branded `agentomatic_register` alias for back-compat;
		 * a provider SHOULD hook only the canonical name.
		 *
		 * @param Registry $registry The collector.
		 */
		do_action( AGENTOMATIC_CANONICAL_HOOK, $this );
		do_action( AGENTOMATIC_ALIAS_HOOK, $this );

		return $this;
	}

	/* ---------------------------------------------------------------------- *
	 *  Readers
	 * ---------------------------------------------------------------------- */

	/** @return array<string,array> */
	public function resources() {
		return $this->resources;
	}

	/** @return array<string,array> */
	public function well_known() {
		return $this->well_known;
	}

	/** @return array<int,array{level:string,message:string}> */
	public function notices() {
		return $this->notices;
	}
}
