<?php
/**
 * Admin screen: a top-level menu that mounts the Vue 3 app, plus the data the
 * app needs (REST root, nonce, settings, entity types, endpoint URLs).
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG   = 'agentimus';
	const HANDLE = 'agentimus-admin';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the menu, assets and the plugin-list shortcut.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'menu_icon_style' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AGENTIMUS_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_init', array( $this, 'maybe_activation_redirect' ) );

		// Quick-access node in the toolbar (front-end + admin), with its icon.
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_node' ), 80 );
		add_action( 'wp_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_bar_style' ) );

		// Native admin-footer text + version, on our own screens ONLY (the
		// scoped WordPress convention WooCommerce and others follow — we never
		// touch the global admin footer or any unrelated screen).
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ) );
		add_filter( 'update_footer', array( $this, 'admin_footer_version' ), 15 );
	}

	/**
	 * Register the top-level menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'Agentimus', 'agentimus' ),
			__( 'Agentimus', 'agentimus' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			$this->menu_icon_uri(),
			81
		);
	}

	/**
	 * The brand "A" monogram as a single-colour SVG data URI for the admin-menu
	 * icon — the same mark as the in-app logo and the wp.org icon, in the line form
	 * a menu icon needs. The stroke colour is a sensible idle fallback; menu_icon_style()
	 * recolours it per state (idle grey -> white on hover/current) via a CSS mask.
	 *
	 * @return string
	 */
	private function menu_icon_uri() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a7aaad" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2.6" y="2.6" width="18.8" height="18.8" rx="5.4"/><path d="M8.4 16.6 12 8 15.6 16.6"/><path d="M9.9 13.6H14.1"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Recolour the SVG menu icon to match a native dashicon: masked by the "A", filled
	 * with the menu's per-state icon colour (idle grey, white when hovered/current),
	 * so it adapts across admin colour schemes instead of being a fixed-colour image.
	 *
	 * Attached to a src-less registered handle via wp_add_inline_style() — the menu is
	 * present on every admin screen, so this runs on all of them, independent of the
	 * plugin app enqueued in assets(). The mask URL is a static, plugin-generated SVG
	 * data URI (no user input), so it is safe to inline verbatim.
	 */
	public function menu_icon_style() {
		$uri    = $this->menu_icon_uri();
		$sel    = '#adminmenu #toplevel_page_' . self::SLUG;
		$handle = self::HANDLE . '-menu';

		$css = $sel . ' .wp-menu-image{background-image:none!important;position:relative}'
			. $sel . ' .wp-menu-image::before{content:"";position:absolute;inset:0;background-color:rgba(240,246,252,.6);-webkit-mask:url("' . $uri . '") center/21px no-repeat;mask:url("' . $uri . '") center/21px no-repeat}'
			. $sel . ':hover .wp-menu-image::before,'
			. $sel . '.current .wp-menu-image::before,'
			. $sel . '.wp-has-current-submenu .wp-menu-image::before,'
			. $sel . '.opensub .wp-menu-image::before{background-color:#fff}';

		wp_register_style( $handle, false, array(), AGENTIMUS_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );
	}

	/**
	 * Quick-access node in the WordPress toolbar so Agentimus is one click away
	 * from anywhere — the front-end bar and every other admin screen.
	 *
	 * Hidden on the plugin's own screen (you're already there) and shown only to
	 * users who can actually open it. The child nodes deep-link into the SPA's
	 * tabs, which route off the URL hash (see action_links()).
	 *
	 * Placed in the right-hand `top-secondary` group; at this hook priority (80)
	 * it falls between the account node (priority 0) and the search node (9999),
	 * so the float:right layout renders it immediately to the left of "Howdy".
	 *
	 * @param \WP_Admin_Bar $bar The toolbar being built.
	 */
	public function admin_bar_node( $bar ) {
		if ( ! current_user_can( 'manage_options' ) || $this->is_plugin_screen() ) {
			return;
		}

		$base = admin_url( 'admin.php?page=' . self::SLUG );

		$bar->add_node( array(
			'id'     => self::SLUG,
			'parent' => 'top-secondary',
			'title'  => '<span class="ab-icon" aria-hidden="true"></span>' . esc_html__( 'Agentimus', 'agentimus' ),
			'href'   => esc_url( $base ),
			'meta'   => array( 'title' => esc_attr__( 'Open Agentimus', 'agentimus' ) ),
		) );

		$tabs = array(
			'dashboard' => __( 'Dashboard', 'agentimus' ),
			'settings'  => __( 'Settings', 'agentimus' ),
			'readiness' => __( 'Readiness', 'agentimus' ),
			'discovery' => __( 'Discovery', 'agentimus' ),
		);
		foreach ( $tabs as $tab => $label ) {
			$bar->add_node( array(
				'parent' => self::SLUG,
				'id'     => self::SLUG . '-' . $tab,
				'title'  => $label,
				'href'   => esc_url( $base . '#' . $tab ),
			) );
		}
	}

	/**
	 * Whether the current request is the Agentimus admin screen — used to hide
	 * the toolbar shortcut when it would just point at the page you're on.
	 *
	 * @return bool
	 */
	private function is_plugin_screen() {
		if ( ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && 'toplevel_page_' . self::SLUG === $screen->id ) {
				return true;
			}
		}
		// Fallback for hooks that fire before the screen object is set.
		return isset( $_GET['page'] ) && self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no state change.
	}

	/**
	 * Left admin-footer text on our own screens: a polite, optional rating
	 * request. Off our screens we return WordPress's default text untouched, so
	 * the global admin experience is never altered (a wp.org guideline). The
	 * name and the star link are pre-escaped; the translatable string carries
	 * only placeholders.
	 *
	 * @param string $text Default footer text.
	 * @return string
	 */
	public function admin_footer_text( $text ) {
		if ( ! $this->is_plugin_screen() ) {
			return $text;
		}

		$name  = '<strong>' . esc_html__( 'Agentimus', 'agentimus' ) . '</strong>';
		$stars = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener" aria-label="%2$s" class="agentimus-rating-link">&#9733;&#9733;&#9733;&#9733;&#9733;</a>',
			esc_url( 'https://wordpress.org/support/plugin/agentimus/reviews/?rate=5#new-post' ),
			esc_attr__( 'Rate Agentimus five stars on WordPress.org (opens in a new tab)', 'agentimus' )
		);

		return sprintf(
			/* translators: 1: plugin name (bold), 2: five-star rating link. */
			__( 'If you like %1$s please give this plugin a %2$s rating. A huge thanks in advance!', 'agentimus' ),
			$name,
			$stars
		);
	}

	/**
	 * Right admin-footer text on our own screens: the plugin version (priority 15
	 * so it wins over core's WP-version line). Off our screens core's default
	 * version/update text is left intact.
	 *
	 * @param string $text Default upgrade/version text.
	 * @return string
	 */
	public function admin_footer_version( $text ) {
		if ( ! $this->is_plugin_screen() ) {
			return $text;
		}

		return sprintf(
			/* translators: %s: plugin version number. */
			esc_html__( 'Version %s', 'agentimus' ),
			esc_html( AGENTIMUS_VERSION )
		);
	}

	/**
	 * Style the toolbar node's brand monogram. Reuses the menu icon's SVG as a
	 * CSS mask filled with `currentColor`, so the "A" tracks the toolbar's own
	 * text colour in every admin scheme and on the front-end bar alike. Enqueued
	 * on both front and admin because the toolbar shows in both.
	 */
	public function admin_bar_style() {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$uri    = $this->menu_icon_uri();
		$sel    = '#wpadminbar #wp-admin-bar-' . self::SLUG;
		$handle = self::HANDLE . '-adminbar';

		$css = $sel . ' .ab-icon::before{content:"";display:inline-block;width:16px;height:16px;'
			. 'margin:0 2px 0 0;vertical-align:middle;background-color:currentColor;'
			. '-webkit-mask:url("' . $uri . '") center/contain no-repeat;'
			. 'mask:url("' . $uri . '") center/contain no-repeat}';

		wp_register_style( $handle, false, array(), AGENTIMUS_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		// Deep-link to the Settings tab: the SPA reads the URL hash on load, so
		// "#settings" lands there instead of the default Dashboard.
		$url = admin_url( 'admin.php?page=' . self::SLUG ) . '#settings';
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'agentimus' ) . '</a>' );
		return $links;
	}

	/**
	 * One-time redirect to the plugin screen right after a fresh activation, so a
	 * non-technical admin lands on the setup wizard instead of the Plugins list.
	 * Guarded against AJAX, network admin and bulk activation; the transient is
	 * one-shot.
	 */
	public function maybe_activation_redirect() {
		if ( ! get_transient( 'agentimus_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'agentimus_activation_redirect' );

		// Never hijack a bulk activation or a network-admin context.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading WordPress's own bulk-activation marker, no state change.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Whether the setup wizard is done — or shouldn't appear because the install
	 * is clearly already configured. The explicit flag is set when the wizard is
	 * finished or skipped (and on activation for pre-existing installs). The
	 * heuristic additionally suppresses the wizard for installs that updated via
	 * wordpress.org without re-running the activation hook: any sign of prior
	 * configuration — a profile sentence, or a content selection that differs
	 * from the fresh-install default — counts as onboarded.
	 *
	 * @return bool
	 */
	private function is_onboarded() {
		if ( false !== get_option( 'agentimus_onboarded', false ) ) {
			return true;
		}
		if ( '' !== trim( (string) $this->settings->identity( 'about', '' ) ) ) {
			return true;
		}
		$selected = (array) $this->settings->get( 'post_types', array() );
		$default  = Settings::default_post_types();
		sort( $selected );
		sort( $default );
		return $selected !== $default;
	}

	/**
	 * Enqueue the built Vue app on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$js  = AGENTIMUS_DIR . 'assets/admin/app.js';
		$css = AGENTIMUS_DIR . 'assets/admin/app.css';

		if ( is_readable( $js ) ) {
			wp_enqueue_script(
				self::HANDLE,
				AGENTIMUS_URL . 'assets/admin/app.js',
				array(),
				$this->asset_version( $js ),
				true
			);
			wp_localize_script( self::HANDLE, 'AgentimusData', $this->bootstrap_data() );
		}

		if ( is_readable( $css ) ) {
			wp_enqueue_style(
				self::HANDLE,
				AGENTIMUS_URL . 'assets/admin/app.css',
				array(),
				$this->asset_version( $css )
			);

			// Gold, underline-free stars for the admin-footer rating link (the
			// footer sits outside the Vue app, so it needs a global selector).
			wp_add_inline_style(
				self::HANDLE,
				'.agentimus-rating-link{color:#e0a32e;text-decoration:none}.agentimus-rating-link:hover,.agentimus-rating-link:focus{color:#c8881d}'
			);
		}
	}

	/**
	 * Data handed to the Vue app at boot.
	 *
	 * @return array
	 */
	private function bootstrap_data() {
		return array(
			'restUrl'     => esc_url_raw( rest_url( Rest::NAMESPACE ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'settings'    => $this->settings->all(),
			'defaults'    => $this->settings->defaults(), // Powers the reset-preview.
			'readiness'   => ( new Readiness( $this->settings ) )->report(),
			'discovery'   => Discovery\Hub::data( $this->settings, Discovery\Registry::instance() ),
			'restNamespacesDetected' => Discovery\Adapters\RestApi::detected(),
			'entityTypes'   => $this->settings->entity_types(),
			'postTypes'     => $this->available_post_types(),
			'knownTrainers' => Settings::known_trainers(),
			'knownScanners' => Settings::known_scanners(),
			'endpoints'   => array(
				'llms'     => home_url( '/llms.txt' ),
				'llmsFull' => home_url( '/llms-full.txt' ),
				'robots'   => home_url( '/robots.txt' ),
			),
			'version'     => AGENTIMUS_VERSION,
			// Surfaced on the About tab so the protocol facts mirror the code,
			// not hand-copied strings that can drift.
			'protocol'    => array(
				'name'      => 'WP_Discovery',
				'version'   => Discovery\Envelope::SPEC_VERSION,
				'hook'      => \AGENTIMUS_CANONICAL_HOOK,
				'specUrl'   => 'https://github.com/heera/wp-discovery-protocol',
				'schemaUrl' => Discovery\Envelope::schema_url(),
			),
			// Every registered WebMCP tool (baseline + provider-added), so the
			// Settings panel can list them with a per-tool expose/hide toggle.
			'webmcpTools' => array_map(
				static function ( $t ) {
					return array(
						'name'        => $t['name'],
						'description' => isset( $t['description'] ) ? $t['description'] : '',
					);
				},
				( new WebMcp( $this->settings ) )->registered_tools()
			),
			'onboarded'   => $this->is_onboarded(),
			'llmsFullEstimate' => Content::estimate_full_size( $this->settings ),
			// A real published, in-scope permalink for the live self-check to probe
			// (markdown + its advertised Link). '' when the site has no such post yet.
			'samplePost'  => $this->sample_post_url(),
		);
	}

	/**
	 * The most recent published permalink among the agent-visible post types — the
	 * page the live self-check fetches to confirm markdown delivery and the
	 * advertised `.md` Link work end to end. Empty when there's nothing to probe.
	 *
	 * @return string
	 */
	private function sample_post_url() {
		$types = Content::post_types();
		if ( empty( $types ) ) {
			return '';
		}
		$posts = get_posts( array(
			'post_type'        => $types,
			'post_status'      => 'publish',
			'numberposts'      => 1,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		) );
		return empty( $posts ) ? '' : (string) get_permalink( $posts[0] );
	}

	/**
	 * Available public post types as { slug, label } for the settings UI.
	 *
	 * @return array
	 */
	private function available_post_types() {
		$out = array();
		foreach ( Content::available() as $slug ) {
			$out[] = array(
				'slug'   => $slug,
				'label'  => Content::label( $slug ),
				'source' => Content::source( $slug ),
			);
		}
		return $out;
	}

	/**
	 * Mount point (and a graceful notice if the app hasn't been built yet).
	 */
	public function render() {
		echo '<div class="wrap"><div id="agentimus-app">';

		if ( ! is_readable( AGENTIMUS_DIR . 'assets/admin/app.js' ) ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'The admin interface has not been built yet. Run "npm install && npm run build" in the plugin directory.', 'agentimus' ) .
				'</p></div>';
		}

		echo '</div></div>';
	}

	/**
	 * Cache-busting version: plugin version in production, mtime in debug.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	private function asset_version( $path ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $mtime ) {
				return (string) $mtime;
			}
		}
		return AGENTIMUS_VERSION;
	}
}
