<?php
/**
 * Exposure — the defensive counterpart to the Discovery layer: opt-in controls
 * that reduce what an ANONYMOUS visitor (crawler, bot, scanner) can read about
 * the site. Agentimus curates the machine surface, so closing the noisy stock
 * leaks that undercut that curation belongs here.
 *
 * Every control is OFF by default and only registers its hooks when the owner
 * turns it on, so a fresh install changes nothing. Each is scoped to logged-out
 * requests — a signed-in admin and the block editor keep full access. The actual
 * decisions live in pure static helpers (unit-tested); the registered callbacks
 * are thin adapters that feed them the live WordPress state.
 *
 * Deliberately NOT here: login lockout, 2FA, malware scanning, firewalls — that
 * is a security plugin, not a discovery layer.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Exposure {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register only the hooks whose toggle is on. Read once here (at boot); a
	 * settings change applies on the next request, like every other setting.
	 */
	public function register() {
		if ( $this->settings->enabled( 'hide_user_enumeration' ) ) {
			add_filter( 'rest_endpoints', array( $this, 'filter_rest_endpoints' ) );
			add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'drop_users_sitemap' ), 10, 2 );
			add_filter( 'oembed_response_data', array( __CLASS__, 'strip_oembed_author' ) );
			add_action( 'template_redirect', array( $this, 'block_enumeration' ), 0 );
		}

		if ( $this->settings->enabled( 'disable_author_archives' ) ) {
			add_action( 'template_redirect', array( $this, 'block_author_archive' ), 0 );
		}

		if ( $this->settings->enabled( 'hide_wp_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
			add_filter( 'style_loader_src', array( $this, 'filter_core_version' ) );
			add_filter( 'script_loader_src', array( $this, 'filter_core_version' ) );
		}

		if ( $this->settings->enabled( 'tidy_head_links' ) ) {
			// Rarely-used auto-generated discovery links — drop from the head AND the
			// Link header. The REST api.w.org link and Agentimus's own discovery links
			// are intentional and deliberately kept.
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( $this->settings->enabled( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'no_xmlrpc_methods' ) );
			add_filter( 'wp_headers', array( __CLASS__, 'drop_pingback_header' ) );
			remove_action( 'wp_head', 'rsd_link' );
		}
	}

	/* -- Registered adapters (thin: live WP state → pure helper) ---------- */

	/**
	 * rest_endpoints: drop the core users routes for anonymous callers.
	 *
	 * @param array $endpoints Route map.
	 * @return array
	 */
	public function filter_rest_endpoints( $endpoints ) {
		return self::strip_users_from_endpoints( (array) $endpoints, is_user_logged_in() );
	}

	/**
	 * style/script_loader_src: strip the core ?ver= so the WP version isn't
	 * advertised on every asset. Plugin/theme asset versions (their own cache
	 * busters) are left intact — only the value matching core is removed.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function filter_core_version( $src ) {
		$ver = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
		return self::strip_core_version( $src, $ver );
	}

	/**
	 * template_redirect: 404 the two anonymous author-enumeration URLs, at priority
	 * 0 so it runs before redirect_canonical / the sitemap renderer:
	 *   - `?author=<n>` — before WP canonical-redirects it to /author/<slug>/ and
	 *     leaks the slug.
	 *   - `/wp-sitemap-users-*.xml` — with the users provider already removed the URL
	 *     no longer maps to a sitemap (so it would otherwise fall through to a page);
	 *     return a clean 404 instead.
	 */
	public function block_enumeration() {
		if ( is_user_logged_in() ) {
			return;
		}
		// sanitize_text_field() is what makes this safe for `?author[]=1`: core's
		// _sanitize_text_fields() returns '' outright for an array, where the old raw value went
		// on to be cast to string and raised an "Array to string conversion" warning — on an
		// anonymous front-end request, the one place a stray PHP notice must never surface.
		$author = isset( $_GET['author'] ) ? sanitize_text_field( wp_unslash( $_GET['author'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public request gate, no state change.
		if ( self::is_author_enumeration( $author ) || 'users' === get_query_var( 'sitemap' ) ) {
			$this->send_404();
		}
	}

	/**
	 * template_redirect: 404 every author archive (/author/<slug>/ and, before its
	 * canonical redirect fires, ?author=<n>) for anonymous visitors — for sites where
	 * author pages aren't a feature and just expose the username slug. Independent of
	 * hide_user_enumeration; enabling both is harmless (the 404 is idempotent).
	 */
	public function block_author_archive() {
		if ( ! is_user_logged_in() && is_author() ) {
			$this->send_404();
		}
	}

	/**
	 * Force the current request to a 404.
	 */
	private function send_404() {
		global $wp_query;
		if ( is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/* -- Pure helpers (unit-tested; no WordPress dependency) -------------- */

	/**
	 * Remove the core users routes for an anonymous request. A signed-in user keeps
	 * them, so the block editor's author picker and legit authenticated REST clients
	 * are untouched.
	 *
	 * @param array $endpoints Route map.
	 * @param bool  $logged_in Whether the current request is authenticated.
	 * @return array
	 */
	public static function strip_users_from_endpoints( array $endpoints, $logged_in ) {
		if ( $logged_in ) {
			return $endpoints;
		}
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		return $endpoints;
	}

	/**
	 * wp_sitemaps_add_provider: drop the `users` provider so the core sitemap stops
	 * enumerating author archive URLs (and its entry leaves the sitemap index).
	 *
	 * @param mixed  $provider The provider instance (or already-false).
	 * @param string $name     Provider name.
	 * @return mixed False to remove the users provider; the provider otherwise.
	 */
	public static function drop_users_sitemap( $provider, $name ) {
		return ( 'users' === $name ) ? false : $provider;
	}

	/**
	 * oembed_response_data: blank the author fields so an oEmbed fetch can't reveal
	 * the author name/URL.
	 *
	 * @param mixed $data Response data (array).
	 * @return mixed
	 */
	public static function strip_oembed_author( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}

	/**
	 * xmlrpc_methods: remove every method — including pingback.ping,
	 * system.multicall and wp.getUsersBlogs — leaving xmlrpc.php inert without
	 * needing server config.
	 *
	 * @param mixed $methods Method map (ignored).
	 * @return array Always empty.
	 */
	public static function no_xmlrpc_methods( $methods ) {
		return array();
	}

	/**
	 * wp_headers: drop the X-Pingback response header so the pingback endpoint isn't
	 * advertised once XML-RPC is disabled.
	 *
	 * @param mixed $headers Header map.
	 * @return mixed
	 */
	public static function drop_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/**
	 * Whether a request's `author` query value is an enumeration probe: a bare
	 * numeric id (the `?author=1` → /author/<slug>/ trick). A non-numeric author
	 * query (a real author archive by slug) is left alone.
	 *
	 * @param mixed $author The raw author query value — anything a query string can carry,
	 *                      including an array (`?author[]=1`), which must not blow up.
	 * @return bool
	 */
	public static function is_author_enumeration( $author ) {
		// A total function: casting a non-scalar to string raises "Array to string conversion",
		// and this is reached straight off an anonymous request. Not an enumeration probe anyway.
		if ( ! is_scalar( $author ) ) {
			return false;
		}
		$author = (string) $author;
		return '' !== $author && ctype_digit( $author );
	}

	/**
	 * Strip a `ver=<core version>` query arg from an asset URL, preserving every
	 * other query arg and any non-core version. Returns the URL unchanged when the
	 * core version isn't present. Uses only native PHP so the rule is testable.
	 *
	 * @param string $src        Asset URL.
	 * @param string $wp_version The running WordPress core version.
	 * @return string
	 */
	public static function strip_core_version( $src, $wp_version ) {
		$src        = (string) $src;
		$wp_version = (string) $wp_version;
		if ( '' === $wp_version || false === strpos( $src, 'ver=' . $wp_version ) ) {
			return $src;
		}
		$q = strpos( $src, '?' );
		if ( false === $q ) {
			return $src;
		}
		$base = substr( $src, 0, $q );
		parse_str( substr( $src, $q + 1 ), $args );
		if ( ! isset( $args['ver'] ) || (string) $args['ver'] !== $wp_version ) {
			return $src; // A different ver (plugin/theme cache-buster) — leave it.
		}
		unset( $args['ver'] );
		$qs = http_build_query( $args );
		return '' === $qs ? $base : $base . '?' . $qs;
	}

	/* ---------------------------------------------------------------------- *
	 *  Exposed-files self-check (list only — the scan runs in the admin browser)
	 * ---------------------------------------------------------------------- */

	/**
	 * The paths the "exposed files" self-check probes for — files/dirs that should NEVER
	 * be publicly readable (config backups, secrets, keys, VCS metadata, DB dumps). These
	 * are common, public attack paths, so nothing in the list is itself sensitive. The
	 * owner's `exposed_extra_paths` (Settings → Exposure) merge in, and the whole list is
	 * filterable.
	 *
	 * This only SUPPLIES the list — the actual probing happens in the admin browser
	 * ({@see livecheck.js}), same-origin, so the plugin makes no outbound request and a
	 * server loopback can't mask a leak the real public URL would reveal.
	 *
	 * @param Settings $settings Settings store (for the owner's extra paths).
	 * @return string[] Root-relative paths, each beginning with "/", deduped.
	 */
	public static function sensitive_paths( Settings $settings ) {
		$defaults = array(
			'/wp-config.php.bak',
			'/wp-config.php.save',
			'/wp-config.php.old',
			'/wp-config.php~',
			'/wp-config.txt',
			'/.env',
			'/.env.bak',
			'/.git/config',
			'/.svn/entries',
			'/.aws/credentials',
			'/id_rsa',
			'/private-key',
			'/privatekey.key',
			'/server.key',
			'/firebase-adminsdk.json',
			'/terraform.tfstate',
			'/backup.sql',
			'/database.sql',
			'/dump.sql',
			'/backup.zip',
			'/.htpasswd',
			'/wp-content/debug.log',
			'/error_log',
			'/composer.lock',
			'/package-lock.json',
		);

		/**
		 * Filter the sensitive-path list the exposed-files self-check probes for.
		 *
		 * @param string[] $defaults Root-relative paths.
		 * @param Settings $settings Settings store.
		 */
		$paths = (array) apply_filters( 'agentimus_exposed_paths', $defaults, $settings );
		$extra = (array) $settings->get( 'exposed_extra_paths', array() );

		// The built-in list is already explicit paths. For the owner's extras: a value that
		// CONTAINS a slash is taken as an explicit path; a bare FILENAME (no slash) is expanded
		// to the site root plus the two folders a stray file usually lands in — so an admin who
		// knows only the name, not the location, still gets it checked in the likely spots.
		$candidates = $paths;
		foreach ( $extra as $raw ) {
			$raw = trim( (string) $raw );
			if ( '' === $raw ) {
				continue;
			}
			if ( false === strpos( $raw, '/' ) ) {
				$candidates[] = '/' . $raw;
				$candidates[] = '/wp-content/' . $raw;
				$candidates[] = '/wp-content/uploads/' . $raw;
			} else {
				$candidates[] = $raw;
			}
		}

		$out  = array();
		$seen = array();
		foreach ( $candidates as $p ) {
			$p = self::normalise_scan_path( (string) $p );
			if ( '' === $p || isset( $seen[ $p ] ) ) {
				continue;
			}
			$seen[ $p ] = true;
			$out[]      = $p;
		}
		return $out;
	}

	/**
	 * Normalise a scan path to root-relative form: trim, keep only the path if a full URL
	 * was entered, force a single leading slash, and cap length. '' for junk.
	 *
	 * @param string $p Raw path or URL.
	 * @return string
	 */
	private static function normalise_scan_path( $p ) {
		$p = trim( $p );
		if ( '' === $p ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $p ) ) {
			$path = wp_parse_url( $p, PHP_URL_PATH );
			$p    = is_string( $path ) ? $path : '';
		}
		if ( '' === $p ) {
			return '';
		}
		return substr( '/' . ltrim( $p, '/' ), 0, 300 );
	}

	/* ---------------------------------------------------------------------- *
	 *  Debug-logging exposure (status for the Exposure tab's warning card)
	 * ---------------------------------------------------------------------- */

	/**
	 * The site's WordPress debug posture, for the Exposure tab's status card. Reads the debug
	 * constants + environment (a pure read — no HTTP request) and returns
	 * { state, message, fix, fixUrl }. Risky combinations are flagged, above all in PRODUCTION:
	 * errors shown to visitors, or a debug.log in a web-reachable folder (which the exposed-
	 * files scan may find downloadable). Off, or any non-production environment, is a pass. The
	 * fix is a wp-config.php edit — Agentimus detects and guides; it never changes that file.
	 *
	 * @return array{state:string,message:string,fix:string,fixUrl:string}
	 */
	public static function debug_status() {
		$env     = self::environment_type();
		$debug   = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$display = ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY; // WP defaults this on when undefined.
		$log_raw = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
		$logging = ( true === $log_raw ) || ( is_string( $log_raw ) && '' !== $log_raw );
		$path    = ( true === $log_raw )
			? ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : '' )
			: ( is_string( $log_raw ) ? $log_raw : '' );
		$web = $logging && '' !== $path && self::path_in_webroot( $path );

		list( $state, $reason ) = self::debug_verdict( 'production' === $env, $debug, (bool) $display, $logging, $web );

		$messages = array(
			'off'         => __( 'Debug logging is off.', 'agentimus' ),
			/* translators: %s: environment type (e.g. "development"). */
			'dev'         => sprintf( __( 'Debug mode is on — normal for a %s environment.', 'agentimus' ), $env ),
			'display'     => __( 'WP_DEBUG_DISPLAY is on in production — PHP errors and file paths (sometimes secrets) may be shown to your visitors.', 'agentimus' ),
			'log_web'     => __( 'Debug logging is on in production and the log sits in a web-reachable folder — it may be publicly downloadable. Run the exposed-files scan to confirm.', 'agentimus' ),
			'log_private' => __( 'Debug logging is on in production (the log is outside the web root, so not downloadable — but production usually shouldn’t log debug output).', 'agentimus' ),
			'on'          => __( 'Debug mode is on in production.', 'agentimus' ),
		);
		$risky = 'pass' !== $state;

		return array(
			'state'   => $state, // pass | warn | fail
			'message' => isset( $messages[ $reason ] ) ? $messages[ $reason ] : '',
			'fix'     => $risky ? __( 'Edit wp-config.php: set WP_DEBUG and WP_DEBUG_DISPLAY to false, or point WP_DEBUG_LOG at a path outside the web root. Agentimus won’t change wp-config.php for you.', 'agentimus' ) : '',
			'fixUrl'  => $risky ? 'https://heera.github.io/agentimus/user-manual/exposure.html#debug-logging-in-production' : '',
		);
	}

	/**
	 * The pure decision behind {@see debug_status()} — state + reason code from the resolved
	 * facts, split out so it unit-tests without touching PHP constants. Worst-first: errors on
	 * screen, then a web-reachable log, then a private log, then bare debug-on. Anything
	 * outside production, or with debug off, passes.
	 *
	 * @param bool $is_production Whether the environment is 'production'.
	 * @param bool $debug         WP_DEBUG is on.
	 * @param bool $display       WP_DEBUG_DISPLAY is on (errors rendered to the page).
	 * @param bool $logging       A debug log is being written.
	 * @param bool $log_web       That log sits inside the web root (potentially downloadable).
	 * @return array{0:string,1:string} [ state (pass|warn|fail), reason code ].
	 */
	public static function debug_verdict( $is_production, $debug, $display, $logging, $log_web ) {
		if ( ! $debug ) {
			return array( 'pass', 'off' );
		}
		if ( ! $is_production ) {
			return array( 'pass', 'dev' );
		}
		if ( $display ) {
			return array( 'fail', 'display' );
		}
		if ( $logging && $log_web ) {
			return array( 'fail', 'log_web' );
		}
		if ( $logging ) {
			return array( 'warn', 'log_private' );
		}
		return array( 'warn', 'on' );
	}

	/**
	 * Whether a filesystem path resolves inside the web root (ABSPATH) — a heuristic for
	 * "an anonymous visitor could reach it over HTTP". Unknown → treated as reachable
	 * (cautious). A plain compare on normalised paths; no filesystem access.
	 *
	 * @param string $path Absolute log path.
	 * @return bool
	 */
	private static function path_in_webroot( $path ) {
		$root = defined( 'ABSPATH' ) ? ABSPATH : '';
		if ( '' === $root || '' === $path ) {
			return true;
		}
		return 0 === strpos( wp_normalize_path( $path ), wp_normalize_path( $root ) );
	}

	/**
	 * The site's environment. Honours an explicitly-declared WP_ENVIRONMENT_TYPE (constant OR
	 * env var) — the owner's word is final, even on an unusual host. Otherwise it auto-detects
	 * LOCAL, but only from signals a public production site cannot have (see {@see host_is_local()});
	 * everything else is production. The bias is deliberate: mistaking production for local would
	 * SILENCE the debug warning, so we downgrade to local only when it's certain.
	 *
	 * @return string 'production' | 'local' | any explicitly-declared type.
	 */
	public static function environment_type() {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) || false !== getenv( 'WP_ENVIRONMENT_TYPE' ) ) {
			return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		}
		return self::is_local_environment() ? 'local' : 'production';
	}

	/**
	 * Whether the site's own address looks local. Reads the canonical home_url() host (not the
	 * spoofable request Host) and defers to {@see host_is_local()}. Filterable.
	 *
	 * @return bool
	 */
	public static function is_local_environment() {
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$local = self::host_is_local( $host );
		/**
		 * Filter whether this is a local/development environment (used only when
		 * WP_ENVIRONMENT_TYPE isn't explicitly declared).
		 *
		 * @param bool   $local Whether the host looks local.
		 * @param string $host  The site's home host.
		 */
		return (bool) apply_filters( 'agentimus_is_local_env', $local, $host );
	}

	/**
	 * Whether a host is unambiguously LOCAL — a value a public production site cannot have.
	 * True only for the loopback names, the RFC-reserved / mDNS TLDs (.test .localhost .local
	 * .example .invalid), the loopback-by-design dev domains (.ddev.site .lndo.site), or a
	 * private/reserved IP literal. Everything else — INCLUDING the real, public .dev TLD —
	 * returns false → production. This is the safety guarantee: no false "local" on a public
	 * site. Pure string logic, so it unit-tests directly.
	 *
	 * @param string $host Host (any case; brackets/port tolerated).
	 * @return bool
	 */
	public static function host_is_local( $host ) {
		$host = trim( strtolower( (string) $host ), " \t[]" );
		if ( '' === $host ) {
			return false; // Unknown host → treat as production (safe).
		}
		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return true;
		}
		$suffixes = array( '.test', '.localhost', '.local', '.example', '.invalid', '.ddev.site', '.lndo.site' );
		/**
		 * Filter the host suffixes that mark a LOCAL environment. Only add suffixes a public
		 * production site can NEVER use, or the debug warning may be wrongly silenced.
		 *
		 * @param string[] $suffixes Local host suffixes (each begins with a dot).
		 */
		$suffixes = (array) apply_filters( 'agentimus_local_host_suffixes', $suffixes );
		foreach ( $suffixes as $s ) {
			$len = strlen( (string) $s );
			if ( $len > 0 && strlen( $host ) > $len && substr( $host, -$len ) === $s ) {
				return true;
			}
		}
		return self::is_private_ip( $host );
	}

	/**
	 * Whether a host is a private / loopback / reserved IP literal (not a public IP, and not a
	 * hostname). Uses PHP's own range validation, so it covers both IPv4 and IPv6.
	 *
	 * @param string $host Host string.
	 * @return bool
	 */
	private static function is_private_ip( $host ) {
		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false; // A hostname, not an IP literal.
		}
		return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}
