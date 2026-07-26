<?php
/**
 * Registry — the collector every provider registers with.
 *
 * Fires the canonical `wpdiscovery_register` action (and the back-compat
 * `agentimus_register` alias) exactly once per request, passing itself
 * so providers can call `$registry->register()`. Also drains the static queue
 * filled by the global `Agentimus_Discovery::register()` facade, so it doesn't
 * matter whether an author registers via the hook or the facade, or in what
 * order. Validation runs synchronously; rejects land in `notices()` for the
 * admin Validation screen.
 *
 * @package Agentimus
 */

namespace Agentimus\Discovery;

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
				'message' => sprintf( __( 'Duplicate resource id "%s" — the later registration won.', 'agentimus' ), $id ),
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
			return new \WP_Error( 'wpd_wk_name', __( 'A well-known document needs a name.', 'agentimus' ) );
		}
		if ( empty( $def['callback'] ) && empty( $def['redirect'] ) && empty( $def['file'] ) ) {
			/* translators: %s: well-known document name, e.g. "security.txt". */
			return new \WP_Error( 'wpd_wk_source', sprintf( __( 'Well-known "%s" needs a callback, redirect or file.', 'agentimus' ), $name ) );
		}
		if ( isset( $this->well_known[ $name ] ) ) {
			$this->notices[] = array(
				'level'   => 'warning',
				/* translators: %s: well-known name. */
				'message' => sprintf( __( 'Well-known "%s" already claimed — first provider kept.', 'agentimus' ), $name ),
			);
			return new \WP_Error( 'wpd_wk_conflict', __( 'Already claimed.', 'agentimus' ) );
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
		if ( class_exists( '\Agentimus_Discovery' ) ) {
			foreach ( \Agentimus_Discovery::drain() as $queued ) {
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
		 * the product-branded `agentimus_register` alias for back-compat;
		 * a provider SHOULD hook only the canonical name.
		 *
		 * @param Registry $registry The collector.
		 */
		$this->fire( AGENTIMUS_CANONICAL_HOOK );
		$this->fire( AGENTIMUS_ALIAS_HOOK );

		return $this;
	}

	/**
	 * Fire one registration hook with PER-CALLBACK failure isolation. A provider
	 * callback that throws must not take down the whole discovery surface —
	 * discovery.json, the REST route and the admin Hub all drain through collect()
	 * — and it must not take down the OTHER providers either. `do_action()` gives
	 * only per-hook isolation: the first thrower aborts the dispatch, so every
	 * provider registered at a later priority silently vanishes (a real incident:
	 * a demo provider throwing at priority 6 wiped a site's priority-10 providers
	 * to zero). So we dispatch the hook's callbacks ourselves, each in its own
	 * guard: a thrower skips only itself, is named on the Validation screen, and
	 * everyone else still registers.
	 *
	 * The manual dispatch preserves the `do_action()` semantics providers can
	 * observe: priority order; callbacks ADDED during the run at the current or a
	 * later priority still run (an earlier priority is already past, exactly like
	 * core); callbacks REMOVED during the run don't; `accepted_args` 0 is
	 * honoured; `current_action()` reports the hook; `did_action()` counts the
	 * firing. If the filter table ever stops looking like WP's (core refactor,
	 * exotic test double), we fall back to plain `do_action()` under the old
	 * whole-hook guard — degraded isolation, never a fatal.
	 *
	 * The two hooks (canonical + alias) are fired separately, so this isolation
	 * story holds across them as well as within them.
	 *
	 * @param string $hook Hook name to fire, passing $this as the sole argument.
	 * @return void
	 */
	private function fire( $hook ) {
		global $wp_filter;

		$groups = null;
		if ( isset( $wp_filter[ $hook ] ) ) {
			if ( is_object( $wp_filter[ $hook ] ) && isset( $wp_filter[ $hook ]->callbacks ) && is_array( $wp_filter[ $hook ]->callbacks ) ) {
				$groups = true; // Real WP_Hook — dispatch manually below.
			} elseif ( is_array( $wp_filter[ $hook ] ) ) {
				$groups = true; // Legacy/plain-array shape (old cores, test harnesses).
			}
		}

		if ( true !== $groups ) {
			// Nothing registered, or an unrecognisable filter table: plain dispatch,
			// with the original whole-hook guard as the safety net. Degraded (per-hook)
			// isolation is still infinitely better than a fatal discovery surface.
			try {
				// The sniff cannot resolve a variable hook name; every value $hook can hold is one of
				// our own `agentimus_*` / `wp_discovery_*` registration hooks (see the callers).
				do_action( $hook, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook is always one of our own registration hooks.
			} catch ( \Throwable $e ) {
				$this->note_provider_failure( $hook, '', $e );
			}
			return;
		}

		// Mirror do_action()'s observable bookkeeping: the run counts for
		// did_action(), and current_action()/doing_action() name us while we run.
		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions'][ $hook ] = isset( $GLOBALS['wp_actions'][ $hook ] ) ? (int) $GLOBALS['wp_actions'][ $hook ] + 1 : 1;
		if ( ! isset( $GLOBALS['wp_current_filter'] ) || ! is_array( $GLOBALS['wp_current_filter'] ) ) {
			$GLOBALS['wp_current_filter'] = array();
		}
		$GLOBALS['wp_current_filter'][] = $hook;

		$ran   = array();
		$floor = null; // Priorities already passed — a callback added BELOW this never runs, like core.
		try {
			// Multiple passes so a provider that add_action()s another provider at the
			// current or a later priority still gets it run — core behaves the same.
			// The pass cap only bounds a pathological add-forever loop.
			for ( $pass = 0; $pass < 25; $pass++ ) {
				$pending = $this->pending_callbacks( $hook, $ran, $floor );
				if ( empty( $pending ) ) {
					break;
				}
				foreach ( $pending as $key => $entry ) {
					$floor       = max( (int) $entry['priority'], (int) $floor );
					$ran[ $key ] = true;

					// Re-read at execution time: a callback an earlier provider just
					// removed must not run — the snapshot must not resurrect it.
					if ( null === $this->live_callback( $hook, $entry['priority'], $entry['idx'] ) ) {
						continue;
					}

					try {
						if ( (int) $entry['accepted_args'] >= 1 ) {
							call_user_func( $entry['function'], $this );
						} else {
							call_user_func( $entry['function'] );
						}
					} catch ( \Throwable $e ) {
						$this->note_provider_failure( $hook, self::callback_label( $entry['function'] ), $e );
					}
				}
			}
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
	}

	/**
	 * The hook's not-yet-run callbacks, in priority order, keyed uniquely — a fresh
	 * snapshot each pass so registrations made during the run are seen.
	 *
	 * @param string   $hook  Hook name.
	 * @param array    $ran   Keys already executed.
	 * @param int|null $floor Lowest priority still eligible (null = all).
	 * @return array<string,array{priority:int,idx:string,function:callable,accepted_args:int}>
	 */
	private function pending_callbacks( $hook, array $ran, $floor ) {
		global $wp_filter;

		$groups = array();
		if ( isset( $wp_filter[ $hook ] ) ) {
			if ( is_object( $wp_filter[ $hook ] ) && isset( $wp_filter[ $hook ]->callbacks ) ) {
				$groups = (array) $wp_filter[ $hook ]->callbacks;
			} elseif ( is_array( $wp_filter[ $hook ] ) ) {
				$groups = $wp_filter[ $hook ];
			}
		}
		ksort( $groups, SORT_NUMERIC );

		$pending = array();
		foreach ( $groups as $priority => $callbacks ) {
			if ( null !== $floor && (int) $priority < (int) $floor ) {
				continue;
			}
			foreach ( (array) $callbacks as $idx => $entry ) {
				$key = $priority . '|' . $idx;
				if ( isset( $ran[ $key ] ) || ! isset( $entry['function'] ) || ! is_callable( $entry['function'] ) ) {
					continue;
				}
				$pending[ $key ] = array(
					'priority'      => (int) $priority,
					'idx'           => (string) $idx,
					'function'      => $entry['function'],
					'accepted_args' => isset( $entry['accepted_args'] ) ? (int) $entry['accepted_args'] : 1,
				);
			}
		}
		return $pending;
	}

	/**
	 * The callback as currently registered — null when it has been removed since
	 * the snapshot was taken.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $priority Priority bucket.
	 * @param string $idx      WP's unique callback id within the bucket.
	 * @return array|null
	 */
	private function live_callback( $hook, $priority, $idx ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return null;
		}
		$groups = is_object( $wp_filter[ $hook ] ) && isset( $wp_filter[ $hook ]->callbacks )
			? (array) $wp_filter[ $hook ]->callbacks
			: (array) $wp_filter[ $hook ];

		return isset( $groups[ $priority ][ $idx ] ) ? $groups[ $priority ][ $idx ] : null;
	}

	/**
	 * Record one provider failure for the Validation screen, naming the callable
	 * when we can — "which plugin broke" is the first thing the owner needs.
	 *
	 * @param string     $hook     Hook name.
	 * @param string     $callback Human label of the failing callback ('' = unknown).
	 * @param \Throwable $e        What it threw.
	 * @return void
	 */
	private function note_provider_failure( $hook, $callback, \Throwable $e ) {
		$this->notices[] = array(
			'level'   => 'error',
			'message' => '' !== $callback
				? sprintf(
					/* translators: 1: callback name, 2: registration hook name, 3: PHP error message. */
					__( 'A discovery provider (%1$s) errored on "%2$s" and was skipped — every other provider still registered: %3$s', 'agentimus' ),
					$callback,
					$hook,
					$e->getMessage()
				)
				: sprintf(
					/* translators: 1: registration hook name, 2: PHP error message. */
					__( 'A discovery provider errored on "%1$s" and was skipped: %2$s', 'agentimus' ),
					$hook,
					$e->getMessage()
				),
		);
	}

	/**
	 * A human-readable label for a hook callback, for the failure notice.
	 *
	 * @param callable $function The callback.
	 * @return string
	 */
	private static function callback_label( $function ) {
		if ( is_string( $function ) ) {
			return $function;
		}
		if ( is_array( $function ) && 2 === count( $function ) ) {
			$class = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
			return $class . '::' . (string) $function[1];
		}
		if ( $function instanceof \Closure ) {
			return 'closure';
		}
		return is_object( $function ) ? get_class( $function ) : 'callable';
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
