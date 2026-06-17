<?php
/**
 * Admin screen: a top-level menu that mounts the Vue 3 app, plus the data the
 * app needs (REST root, nonce, settings, entity types, endpoint URLs).
 *
 * @package Agentify
 */

namespace Agentify;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG   = 'agentify';
	const HANDLE = 'agentify-admin';

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
		add_filter( 'plugin_action_links_' . plugin_basename( AGENTIFY_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_init', array( $this, 'maybe_activation_redirect' ) );
	}

	/**
	 * Register the top-level menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'Agentify', 'agentify' ),
			__( 'Agentify', 'agentify' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-superhero',
			81
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'agentify' ) . '</a>' );
		return $links;
	}

	/**
	 * One-time redirect to the plugin screen right after a fresh activation, so a
	 * non-technical admin lands on the setup wizard instead of the Plugins list.
	 * Guarded against AJAX, network admin and bulk activation; the transient is
	 * one-shot.
	 */
	public function maybe_activation_redirect() {
		if ( ! get_transient( 'agentify_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'agentify_activation_redirect' );

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
		if ( false !== get_option( 'agentify_onboarded', false ) ) {
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

		$js  = AGENTIFY_DIR . 'assets/admin/app.js';
		$css = AGENTIFY_DIR . 'assets/admin/app.css';

		if ( is_readable( $js ) ) {
			wp_enqueue_script(
				self::HANDLE,
				AGENTIFY_URL . 'assets/admin/app.js',
				array(),
				$this->asset_version( $js ),
				true
			);
			wp_localize_script( self::HANDLE, 'AgentifyData', $this->bootstrap_data() );
		}

		if ( is_readable( $css ) ) {
			wp_enqueue_style(
				self::HANDLE,
				AGENTIFY_URL . 'assets/admin/app.css',
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
			'entityTypes'   => array( 'Person', 'Organization' ),
			'postTypes'     => $this->available_post_types(),
			'knownTrainers' => Settings::known_trainers(),
			'endpoints'   => array(
				'llms'     => home_url( '/llms.txt' ),
				'llmsFull' => home_url( '/llms-full.txt' ),
				'robots'   => home_url( '/robots.txt' ),
			),
			'version'     => AGENTIFY_VERSION,
			'onboarded'   => $this->is_onboarded(),
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
		echo '<div class="wrap"><div id="agentify-app">';

		if ( ! is_readable( AGENTIFY_DIR . 'assets/admin/app.js' ) ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'The admin interface has not been built yet. Run "npm install && npm run build" in the plugin directory.', 'agentify' ) .
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
		return AGENTIFY_VERSION;
	}
}
