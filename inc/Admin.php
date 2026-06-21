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
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
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
			'onboarded'   => $this->is_onboarded(),
			'llmsFullEstimate' => Content::estimate_full_size( $this->settings ),
		);
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
