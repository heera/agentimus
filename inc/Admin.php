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

	/**
	 * Perceived-luminance ceiling for the rail score card's surface. The card is
	 * light text on a dark ground, so any admin-scheme colour it adopts is scaled
	 * down to at most this luma — deep enough that the 50%-alpha cream text and
	 * the green/amber status tones keep their contrast on every core scheme.
	 */
	const CARD_LUMA = 0.14;

	/**
	 * Hand-picked dark-surface ink per core admin colour scheme. The core
	 * schemes are a fixed set (unchanged since WP 3.8), so an explicit map
	 * beats deriving them: each value is exact, reviewable, and individually
	 * tunable. Values sit as CLOSE to the scheme's real menu colour as
	 * legibility allows — an earlier, darker pass (luma-clamped to 0.14) made
	 * every scheme converge on "basically black" (Heera, 2026-07-13). The
	 * binding constraint is the card's green rung text (#5cc08a), kept at
	 * ~4:1 contrast or better; where the raw menu colour passes that bar it is
	 * used EXACTLY (coffee, ectoplasm, midnight), otherwise it's deepened only
	 * as far as the bar demands. "fresh" (the default) is deliberately absent:
	 * it keeps the designed warm ink. Third-party schemes aren't listed and
	 * fall back to the {@see scheme_ink()} derivation.
	 */
	const SCHEME_INKS = array(
		'light'     => '#333333', // neutral grey scheme → neutral dark (5:1)
		'modern'    => '#1e1e1e', // its own menu colour, already card-depth
		'blue'      => '#07485f', // #096484 deepened just past the bar (4.3:1)
		'coffee'    => '#46403c', // EXACT menu colour (4.5:1)
		'ectoplasm' => '#413256', // EXACT menu colour (5.2:1)
		'midnight'  => '#25282b', // EXACT menu colour (6.7:1)
		'ocean'     => '#3a4a4f', // #627c83 deepened to the bar (4.1:1)
		'sunrise'   => '#7e2a27', // #b43c38 deepened to the bar (4.2:1)
	);

	/**
	 * The elements that adopt the scheme ink — Heera's call (2026-07-13): the
	 * theme flavour goes on the DARK SURFACES only (score card, buttons,
	 * editable chips), while the rest of the app keeps its own teal-on-cream
	 * design language. Scoping the --ar-ink override to these selectors re-inks
	 * just them; class selectors also reach UI teleported to <body> (modals).
	 */
	const SCHEME_SCOPE = '.ar-btn,.ar-tags__chip,.ar-rail-card.ar-rail-card--readiness';

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

		// Keep our own screen clean: WordPress prints every other plugin's
		// admin_notices on every admin page. On the Agentimus screen ONLY, clear
		// those queues before they render (same scoped convention as above).
		add_action( 'in_admin_header', array( $this, 'suppress_foreign_notices' ), 1 );
	}

	/**
	 * On the Agentimus admin screen, remove all queued admin notices so other
	 * plugins' banners don't intrude on our app-like UI. Runs on `in_admin_header`,
	 * before WordPress prints the notices, and is a no-op on every other screen.
	 * Agentimus registers no admin_notices of its own, so nothing of ours is lost.
	 */
	public function suppress_foreign_notices() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::SLUG !== $screen->id ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
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
	 * A meta-box title wearing the brand tile — the plugin header's own mark
	 * (dark rounded square, paper "A", amber crossbar, teal ring) — so every
	 * Agentimus box is recognisable at a glance. The ONE copy of this SVG:
	 * every meta box title routes through here. WordPress echoes meta-box
	 * titles as raw HTML; the icon is decorative (aria-hidden), the text still
	 * labels the box.
	 *
	 * @param string $text The plain-text title (already translated).
	 * @return string
	 */
	public static function brand_title( $text ) {
		$icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" style="flex:none">'
			. '<rect x="1.2" y="1.2" width="21.6" height="21.6" rx="6" fill="#1b1913" stroke="#146b64" stroke-width="1.5"/>'
			. '<path d="M7.35 17.3 12 6.7 16.65 17.3" stroke="#f3f0e7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
			. '<path d="M9.5 13H14.5" stroke="#ad7b18" stroke-width="1.9" stroke-linecap="round"/></svg>';
		return '<span style="display:inline-flex;align-items:center;gap:5px;white-space:nowrap">' . $icon . esc_html( $text ) . '</span>';
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
			__( 'If you like %1$s please give it a %2$s rating. A huge thanks in advance!', 'agentimus' ),
			$name,
			$stars
		);
	}

	/**
	 * Right admin-footer text on our own screens: the plugin version alongside the
	 * running WordPress version (priority 15 so it wins over core's WP-version line).
	 * Off our screens core's default version/update text is left intact.
	 *
	 * @param string $text Default upgrade/version text.
	 * @return string
	 */
	public function admin_footer_version( $text ) {
		if ( ! $this->is_plugin_screen() ) {
			return $text;
		}

		// The "|" is a decorative, letterpress-styled separator (see .agentimus-footer-sep
		// in app.css); aria-hidden so screen readers skip the glyph. The version values are
		// escaped; the span is our own static markup, and update_footer output is not
		// auto-escaped by core (it renders HTML, as the star-rating link above does).
		$sep = '<span class="agentimus-footer-sep" aria-hidden="true">|</span>';

		return sprintf(
			/* translators: 1: Agentimus plugin version, 2: separator glyph, 3: WordPress core version. */
			esc_html__( 'Agentimus - %1$s %2$s WordPress - %3$s', 'agentimus' ),
			esc_html( AGENTIMUS_VERSION ),
			$sep,
			esc_html( get_bloginfo( 'version' ) )
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

			$scheme_css = $this->scheme_css();
			if ( '' !== $scheme_css ) {
				wp_add_inline_style( self::HANDLE, $scheme_css );
			}
		}
	}

	/**
	 * Inline CSS giving the app's dark surfaces the admin colour scheme's
	 * flavour — the score card, the buttons and the editable chips (see
	 * SCHEME_SCOPE), nothing else. Those all paint with var(--ar-ink), so
	 * re-declaring that token ON JUST THOSE SELECTORS re-inks them while the
	 * rest of the app (headings, toggles, links, the teal accent, the
	 * green/amber status tones) keeps the designed palette. Ink values come
	 * from the curated SCHEME_INKS map for the core schemes and the
	 * {@see scheme_ink()} derivation for third-party ones. The stock "fresh"
	 * scheme emits nothing — the designed palette IS the fresh look.
	 *
	 * @return string CSS custom-property rule, or '' (default scheme / opted out).
	 */
	private function scheme_css() {
		/**
		 * Filter whether the app adopts the admin colour scheme.
		 *
		 * @param bool $match Default true.
		 */
		if ( ! apply_filters( 'agentimus_match_admin_scheme', true ) ) {
			return '';
		}

		$scheme = get_user_option( 'admin_color' );
		if ( ! is_string( $scheme ) ) {
			return '';
		}

		global $_wp_admin_css_colors;
		$base = isset( $_wp_admin_css_colors[ $scheme ]->colors[0] ) ? $_wp_admin_css_colors[ $scheme ]->colors[0] : '';
		$ink  = self::card_ink_for( $scheme, $base );

		return '' === $ink ? '' : self::SCHEME_SCOPE . '{--ar-ink:' . $ink . '}';
	}

	/**
	 * The card surface for a scheme: the curated map for the core schemes
	 * (exact, hand-picked), the {@see scheme_ink()} derivation for anything
	 * else (a third-party scheme the map can't know about), and '' for the
	 * default "fresh" — which keeps the designed warm ink.
	 *
	 * @param string $scheme Scheme slug from the user's profile, e.g. "coffee".
	 * @param string $base   The scheme's registered base colour (colors[0]),
	 *                       used only for the unlisted-scheme fallback.
	 * @return string Card-depth hex, or '' (default scheme / nothing usable).
	 */
	public static function card_ink_for( $scheme, $base ) {
		$scheme = (string) $scheme;
		if ( '' === $scheme || 'fresh' === $scheme ) {
			return '';
		}
		if ( isset( self::SCHEME_INKS[ $scheme ] ) ) {
			return self::SCHEME_INKS[ $scheme ];
		}

		return self::scheme_ink( (string) $base );
	}

	/**
	 * Turn a scheme colour into a card-surface tint: the scheme's HUE at card
	 * depth, saturated enough to actually read as that colour. Three moves:
	 *
	 *  1. Lightness clamps to 0.13 — a bright base ("Blue" #096484) becomes a
	 *     deep tint of itself; an already-darker base passes through.
	 *  2. Saturation gets a 0.30 floor — the near-neutral bases (Coffee's warm
	 *     grey, Midnight's slate) otherwise collapse into a dark that is
	 *     indistinguishable from the default ink, i.e. "matching" nobody can
	 *     see. True greys (Light's #e5e5e5, saturation ~0) stay neutral: a grey
	 *     has no hue, and boosting one would invent a colour the scheme never had.
	 *  3. The CARD_LUMA ceiling still applies as a guard — perceived luma and
	 *     HSL lightness disagree most for yellow-green hues, which would
	 *     otherwise come out too bright for the muted text.
	 *
	 * @param string $hex Scheme colour, e.g. "#096484" (also accepts #abc).
	 * @return string Card-depth 6-digit hex, or '' when $hex isn't parseable.
	 */
	public static function scheme_ink( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/\A[0-9a-fA-F]{6}\z/', $hex ) ) {
			return '';
		}

		$rgb = array_map( 'hexdec', str_split( $hex, 2 ) );

		// RGB → HSL.
		$r   = $rgb[0] / 255;
		$g   = $rgb[1] / 255;
		$b   = $rgb[2] / 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$d   = $max - $min;
		$s   = 0.0;
		$h   = 0.0;
		if ( $d > 0 ) {
			$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
			if ( $max === $r ) {
				$h = fmod( ( $g - $b ) / $d, 6 );
			} elseif ( $max === $g ) {
				$h = ( $b - $r ) / $d + 2;
			} else {
				$h = ( $r - $g ) / $d + 4;
			}
			$h = fmod( $h * 60 + 360, 360 );
		}

		$l = min( $l, 0.13 );
		if ( $s > 0.02 ) {
			$s = max( $s, 0.30 );
		}

		// HSL → RGB.
		$c   = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x   = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m   = $l - $c / 2;
		$map = array(
			array( $c, $x, 0 ),
			array( $x, $c, 0 ),
			array( 0, $c, $x ),
			array( 0, $x, $c ),
			array( $x, 0, $c ),
			array( $c, 0, $x ),
		);
		list( $r, $g, $b ) = $map[ min( 5, (int) floor( $h / 60 ) ) ];
		$rgb               = array(
			(int) round( ( $r + $m ) * 255 ),
			(int) round( ( $g + $m ) * 255 ),
			(int) round( ( $b + $m ) * 255 ),
		);

		$luma = ( 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2] ) / 255;
		if ( $luma > self::CARD_LUMA ) {
			$scale = self::CARD_LUMA / $luma;
			foreach ( $rgb as $i => $channel ) {
				$rgb[ $i ] = (int) round( $channel * $scale );
			}
		}

		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}

	/**
	 * Data handed to the Vue app at boot.
	 *
	 * @return array
	 */
	/**
	 * The AEO/GEO score, computed fail-open. It samples posts (HTML parsing) and reads
	 * the visibility store — more moving parts than the rest of the boot payload — so a
	 * throw here must degrade to "no score card" (null), never take down the whole admin
	 * screen. The rail's card is guarded on this value, so null just hides it.
	 *
	 * @param array<int,array<string,mixed>> $readiness Precomputed readiness rows.
	 * @return array|null
	 */
	private function aeo_score( $readiness ) {
		try {
			return ( new Score( $this->settings ) )->report( $readiness );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The "What's new" card payload: this release's curated highlights, and whether to
	 * show them. Highlights are hand-written per release — 3–4 plain-English items, the
	 * changelog's headlines, not its whole text. Shown once: dismiss stores the version
	 * in `agentimus_whatsnew_seen`. A site that has never completed onboarding is a
	 * fresh install — it gets the wizard, not release notes.
	 *
	 * @return array{version:string,show:bool,items:array<int,array{title:string,text:string}>}
	 */
	private function whats_new() {
		$seen = (string) get_option( 'agentimus_whatsnew_seen', '' );
		$show = false !== get_option( 'agentimus_onboarded', false )
			&& 0 !== version_compare( $seen, AGENTIMUS_VERSION );

		return array(
			'version' => AGENTIMUS_VERSION,
			'show'    => $show,
			'items'   => array(
				array(
					'title' => 'Write posts with AI, right here',
					'text'  => 'The quill button opens the new writing assistant: describe a post, edit the outline it proposes, preview the complete draft, and create it as a draft in the editor. Nothing is saved until you say so, and it never publishes. Needs agent writes on plus an AI provider under Settings → AI.',
				),
				array(
					'title' => 'It revises existing posts too',
					'text'  => 'Pick a post, describe the change, review the revision before applying it. Content changes; a post’s status never does — and WordPress revisions keep every prior version.',
				),
				array(
					'title' => 'Images where you write',
					'text'  => 'Drafts arrive with alt-filled image placeholders: Generate turns the alt text into an image on any image block, a Featured image (AI) panel drafts the hero, and Ask AI rewrites or extends any text block.',
				),
				array(
					'title' => 'AI errors now speak plainly',
					'text'  => 'A quota wall names the fix, a provider error without details still becomes a human sentence, and the Cited rung updates the moment a visibility check finishes.',
				),
			),
		);
	}

	private function bootstrap_data() {
		return array(
			'restUrl'     => esc_url_raw( rest_url( Rest::NAMESPACE ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'settings'    => $this->settings->all(),
			'defaults'    => $this->settings->defaults(), // Powers the reset-preview.
			'readiness'   => $readiness = ( new Readiness( $this->settings ) )->report(),
			'score'       => $this->aeo_score( $readiness ), // AEO/GEO score + action plan, from the same readiness run.
			'discovery'   => Discovery\Hub::data( $this->settings, Discovery\Registry::instance() ),
			'restNamespacesDetected' => Discovery\Adapters\RestApi::detected(),
			'entityTypes'   => $this->settings->entity_types(),
			// The retention/cap dropdowns render from these, and Settings::sanitize() snaps to
			// the same constants — so the form can never offer a value the sanitiser rejects.
			'retentionChoices' => Settings::RETENTION_CHOICES,
			'maxRowsChoices'   => Settings::MAX_ROWS_CHOICES,
			'postTypes'     => $this->available_post_types(),
			'categories'    => $this->category_options(),
			'knownTrainers' => Settings::known_trainers(),
			'knownScanners' => Settings::known_scanners(),
			'knownAllowed'  => Settings::known_allowed(),
			'defaultAllowed' => Guard::default_allowed(),
			// Built-in verified-bot registry entries, for the Settings manager. The
			// owner's edits (disabled built-ins + custom entries) live in settings.
			'verifierBuiltins' => array_values( VerifierRegistry::builtins() ),
			'endpoints'   => array(
				'llms'     => home_url( '/llms.txt' ),
				'llmsFull' => home_url( '/llms-full.txt' ),
				'robots'   => home_url( '/robots.txt' ),
			),
			'version'     => AGENTIMUS_VERSION,
			// The nav-bar quill's state: live when both prerequisites hold, dimmed with
			// guidance otherwise (the guidance popover names the missing one).
			'assistant'   => ( new Assistant( $this->settings ) )->state(),
			// The once-per-version "What's new" card (plugin Dashboard only — never a
			// site-wide notice, never a redirect). Shown when this version's notes
			// haven't been dismissed AND the site isn't a fresh install (a first-run
			// site gets the wizard; release notes would be noise about a past it
			// never had). Dismiss stores the version — see Rest::whatsnew_seen().
			'whatsNew'    => $this->whats_new(),
			// Surfaced on the About tab so the protocol facts mirror the code,
			// not hand-copied strings that can drift.
			'protocol'    => array(
				'name'      => 'WP_Discovery',
				'version'   => Discovery\Envelope::SPEC_VERSION,
				'hook'      => \AGENTIMUS_CANONICAL_HOOK,
				'specUrl'   => 'https://github.com/heera/wp-discovery-protocol',
				'schemaUrl' => Discovery\Envelope::schema_url(),
			),
			// The MCP-server card's facts. NOTE: never read did_action('mcp_adapter_init')
			// here — the adapter only initialises on rest_api_init, so it is always false
			// during an admin page load and would render a lying "not running" badge.
			'mcpServer'   => array(
				'endpoint'           => esc_url_raw( rest_url( 'agentimus/v1/mcp' ) ),
				// false → the card explains "needs WP 6.9+ (Abilities API)".
				'abilitiesAvailable' => function_exists( 'wp_register_ability' ),
				// false → a git checkout without `composer install`; the toggle would no-op.
				'adapterAvailable'   => class_exists( '\WP\MCP\Core\McpAdapter' )
					|| file_exists( AGENTIMUS_DIR . 'vendor/autoload_packages.php' ),
				// The connect helper builds ready-to-paste client configs from these.
				// The username is the signed-in owner's login name — Basic auth needs
				// exactly that, and a wrong username is the #1 connection failure
				// (WordPress silently ignores a login for a name that doesn't exist,
				// which reads as a server problem).
				'username'           => wp_get_current_user()->user_login,
				'appPasswords'       => array(
					// false → the site can't mint application passwords (usually: no
					// HTTPS on a non-local host, or a security plugin turned them off);
					// the helper explains instead of offering a button that would fail.
					'available'  => function_exists( 'wp_is_application_passwords_available_for_user' )
						&& wp_is_application_passwords_available_for_user( wp_get_current_user() ),
					// Core's own endpoint for minting a key for THIS user. It lives in
					// wp/v2, not our namespace — the wp_rest nonce the app already
					// carries authenticates it; no plugin proxy route needed.
					'endpoint'   => esc_url_raw( rest_url( 'wp/v2/users/me/application-passwords' ) ),
					'profileUrl' => esc_url_raw( admin_url( 'profile.php#application-passwords-section' ) ),
				),
				// The card's "last AI activity" line. `known:false` means Agent access
				// can't see ability runs on this site (module off / no Abilities API
				// hooks) — the card must then say nothing rather than claim "never".
				'lastToolCall'       => $this->last_agent_tool_call(),
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
			// Sensitive paths the browser-side "exposed files" scan probes for (built-in
			// list + the owner's extra paths). The list is public attack paths; the scan
			// itself runs in the browser, same-origin — the server makes no request.
			'exposedPaths' => Exposure::sensitive_paths( $this->settings ),
			// WordPress debug posture (read-only) for the Exposure tab's status card:
			// warns when debug logging/display is left on in production. No request.
			'debug'        => Exposure::debug_status(),
			// Whether this looks like a local/dev site (host-based, never a false positive
			// on a public site) — softens the exposed-files scan from "publicly downloadable"
			// to a deploy-time heads-up.
			'isLocal'      => Exposure::is_local_environment(),
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
	 * The MCP card's "last AI activity" fact: the most recent ability run that was
	 * authenticated with an application password — i.e. an external client, not
	 * someone clicking around wp-admin (cookie sessions carry no credential).
	 *
	 * Why ability_used and not apppw_used: the sign-in event only records a key's
	 * FIRST use (a deliberate hot-path guard), and core throttles its own
	 * last_used to day granularity — ability_used is the only per-call timestamp.
	 *
	 * @return array{known:bool,call:?array{at:string,key:string,user:string,ability:string}}
	 */
	private function last_agent_tool_call() {
		try {
			$coverage = AgentAccess\Module::coverage();
			if ( ! AgentAccess\Events::has_abilities( $coverage ) ) {
				return array( 'known' => false, 'call' => null );
			}
			// A short recent window is enough: credentialed runs and admin-AI runs
			// interleave, and we only need the newest credentialed one.
			foreach ( AgentAccess\Store::recent( 20, AgentAccess\Events::KIND_ABILITY_USED ) as $e ) {
				if ( empty( $e['cred'] ) ) {
					continue;
				}
				return array(
					'known' => true,
					'call'  => array(
						'at'      => isset( $e['lastSeen'] ) ? (string) $e['lastSeen'] : '',
						// Resolves live and goes '' once the key is revoked — the UI
						// words that as "a since-revoked key" rather than inventing one.
						'key'     => ! empty( $e['credName'] ) ? (string) $e['credName'] : '',
						'user'    => isset( $e['user'] ) ? (string) $e['user'] : '',
						'ability' => isset( $e['subject'] ) ? (string) $e['subject'] : '',
					),
				);
			}
			return array( 'known' => true, 'call' => null );
		} catch ( \Throwable $e ) {
			return array( 'known' => false, 'call' => null );
		}
	}

	/**
	 * Available public post types as { slug, label } for the settings UI.
	 *
	 * @return array
	 */
	private function available_post_types() {
		$out = array();
		foreach ( Content::available() as $slug ) {
			$obj = get_post_type_object( $slug );
			// Mirror RestApi::content_capabilities(): only a public, REST-enabled type
			// advertises a read capability, and its public REST-enabled taxonomies ride
			// along — so the settings screen can preview EXACTLY what ticking a type
			// adds to the advertised capabilities the Discovery hub counts.
			$advertises = $obj && ! empty( $obj->public ) && ! empty( $obj->show_in_rest );
			$taxes      = array();
			if ( $advertises ) {
				foreach ( (array) get_object_taxonomies( $slug, 'objects' ) as $tax ) {
					if ( $tax && ! empty( $tax->public ) && ! empty( $tax->show_in_rest ) ) {
						$taxes[] = sanitize_key( (string) ( ! empty( $tax->rest_base ) ? $tax->rest_base : $tax->name ) );
					}
				}
			}
			$out[] = array(
				'slug'       => $slug,
				'label'      => Content::label( $slug ),
				'source'     => Content::source( $slug ),
				'restBase'   => $advertises ? sanitize_key( (string) ( ! empty( $obj->rest_base ) ? $obj->rest_base : $obj->name ) ) : '',
				'taxonomies' => array_values( array_unique( $taxes ) ),
			);
		}
		return $out;
	}

	/**
	 * The site's categories (id + name), for the "evergreen categories" picker in
	 * Settings. Bounded, most-used first, so a huge taxonomy can't bloat the payload.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function category_options() {
		$terms = get_categories(
			array( 'hide_empty' => false, 'number' => 200, 'orderby' => 'count', 'order' => 'DESC' )
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array( 'id' => (int) $t->term_id, 'name' => $t->name );
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
	 * Cache-busting version: the asset's own file mtime, so a rebuilt bundle is
	 * always served fresh (no plugin-version bump or WP_DEBUG needed). Falls back to
	 * the plugin version only if the file can't be read.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	private function asset_version( $path ) {
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $mtime ? (string) $mtime : AGENTIMUS_VERSION;
	}
}
