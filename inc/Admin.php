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

	/** The colour maths moved to {@see SchemeInk}; these aliases keep the historical
	 *  Admin:: surface for the callers and tests that referenced the constants. */
	const CARD_LUMA   = SchemeInk::CARD_LUMA;
	const SCHEME_INKS = SchemeInk::SCHEME_INKS;

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
		add_filter( 'admin_body_class', array( $this, 'scheme_body_class' ) );
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
			// The menu title carries the review-queue count bubble, so the queue is
			// visible from every admin screen — not only when inside Agentimus.
			ReviewBadge::decorate( __( 'Agentimus', 'agentimus' ), $this->settings ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			$this->menu_icon_uri(),
			/**
			 * Where the menu sits. Just above Posts — his call: this is a screen
			 * an owner opens daily, and at 81 it sat below Settings where nobody
			 * looks twice.
			 *
			 * ⚠️ A STRING, and fractional on purpose. Menu positions are array
			 * keys: a plain integer that another plugin already used REPLACES it,
			 * and the loser is whichever registered second — a silent way to
			 * delete somebody else's menu. A fraction nobody else is likely to
			 * pick avoids the collision entirely.
			 *
			 * @param string $position add_menu_page position.
			 */
			(string) apply_filters( 'agentimus_menu_position', '4.9127' )
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
		// ⚠️ Stroke 2.1, not 1.7. Its neighbours in a real sidebar are SOLID
		// glyphs, and a hairline outline beside them has roughly a third of the
		// ink — it reads as disabled rather than as quiet, whatever colour it is
		// given (his catch, 2026-08-18, with FluentCart sitting above it).
		//
		// ⛔ The crossbar is NOT in here. This shape is masked, so it can only
		// ever be one colour; the gold bar is painted separately by
		// {@see menu_icon_style()} so the brand keeps one note of its own.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><rect x="2.2" y="2.2" width="19.6" height="19.6" rx="5.6"/><path d="M8.1 17 12 7.4 15.9 17"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * The brand's gold crossbar, painted over the masked mark.
	 *
	 * One colour that survives every scheme and every state, so the item is
	 * findable at a glance without opting out of the sidebar's own language.
	 *
	 * @return string
	 */
	private function menu_bar_uri() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M9.4 13.2H14.6" stroke="#e0b24c" stroke-width="2.1" stroke-linecap="round"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * A meta-box title wearing the brand tile. Delegate — {@see Brand::title()}.
	 *
	 * @param string $text The plain-text title (already translated).
	 * @return string
	 */
	public static function brand_title( $text ) {
		return Brand::title( $text );
	}

	/**
	 * The Agentimus mark as a standalone SVG. Delegate — {@see Brand::icon()}.
	 *
	 * @param int    $size  Pixel size (square).
	 * @param string $style Optional inline style for the root element.
	 * @return string
	 */
	public static function brand_icon( $size = 16, $style = '' ) {
		return Brand::icon( $size, $style );
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

		$bar = $this->menu_bar_uri();

		// ⚠️⚠️ `currentColor`, NEVER a literal. This used to hardcode
		// rgba(240,246,252,.6) at rest and #fff on hover — two values invented
		// for the default scheme and applied to all nine. On a light admin scheme
		// a 60%-white icon is very nearly invisible, and on every scheme the
		// hover/current transition was ours rather than WordPress's.
		//
		// WordPress already sets `color` on this exact pseudo-element, per scheme
		// AND per state, for its own dashicons. Inheriting it means the mark
		// dims, brightens and recolours in step with every other icon in the
		// sidebar for free — which is what makes it look native rather than
		// switched off. ⛔ Do not "fix" this back to a colour value.
		$css = $sel . ' .wp-menu-image{background-image:none!important;position:relative}'
			. $sel . ' .wp-menu-image::before{content:"";position:absolute;inset:0;background-color:currentColor;-webkit-mask:url("' . $uri . '") center/21px no-repeat;mask:url("' . $uri . '") center/21px no-repeat}'
			// The gold bar rides on top, painted rather than masked, so it keeps
			// its colour while everything under it follows the scheme.
			. $sel . ' .wp-menu-image::after{content:"";position:absolute;inset:0;background:url("' . $bar . '") center/21px no-repeat}';

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

		// Once the Dashboard's review ask has been answered for good, this line
		// stops asking too — "never asks again" holds everywhere, and someone
		// who already reviewed isn't asked on every screen forever. Core's
		// default footer text stands in.
		if ( Review::closed() ) {
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

		// One plugin-screen visit, for the review ask's "has it earned it yet"
		// gates (a week present, 3+ visits, 1+ real action) — see Review::eligible().
		Review::touch();

		// The media modal, for the "Default share image" picker in Search basics —
		// our screen only (the gate above), so no other admin page pays for it.
		wp_enqueue_media();

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
	/**
	 * Stamp the active colour scheme's OWN surface colour onto <body>, as
	 * `agentimus-scheme-363b3f`.
	 *
	 * A dark dialect in schemes.css can then key off the colour WordPress
	 * actually serves instead of off a slug that outlives its palette. The
	 * owner has both Midnights in front of him: heera.it's older WordPress
	 * registers #363b3f, WP 7.1 registers #333c42, and the right dark for one
	 * is the wrong dark for the other.
	 *
	 * A dialect that no longer matches simply stops applying and the base dark
	 * takes over — which is the correct outcome for an unknown palette, and
	 * means a future retune degrades quietly instead of painting a night that
	 * was mixed for a colour nobody is wearing any more.
	 *
	 * @param string $classes Space-separated classes from core.
	 * @return string
	 */
	public function scheme_body_class( $classes ) {
		$hex = SchemeInk::active_surface();

		return '' === $hex ? $classes : trim( $classes . ' agentimus-scheme-' . substr( $hex, 1 ) );
	}

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
		if ( '' === $ink ) {
			return '';
		}

		// LIGHT MODE ONLY. This re-declared --ar-ink is a scheme-tinted DARK for
		// the light theme's ink surfaces; the dark theme keeps the whole token
		// set to itself — without this guard, a non-default scheme painted dark
		// ink onto dark buttons (found on every scheme except fresh, which
		// emits nothing and so never showed it).
		$light = ':root:not([data-ar-theme="dark"]) ';
		$scope = $light . implode( ',' . $light, explode( ',', self::SCHEME_SCOPE ) );

		return $scope . '{--ar-ink:' . $ink . '}';
	}

	/**
	 * The card surface for a scheme. Delegate — {@see SchemeInk::card_ink_for()}.
	 *
	 * @param string $scheme Scheme slug from the user's profile, e.g. "coffee".
	 * @param string $base   The scheme's registered base colour (colors[0]).
	 * @return string Card-depth hex, or '' (default scheme / nothing usable).
	 */
	public static function card_ink_for( $scheme, $base ) {
		return SchemeInk::card_ink_for( $scheme, $base );
	}

	/**
	 * Turn a scheme colour into a card-surface tint. Delegate — {@see SchemeInk::scheme_ink()}.
	 *
	 * @param string $hex Scheme colour, e.g. "#096484" (also accepts #abc).
	 * @return string Card-depth 6-digit hex, or '' when $hex isn't parseable.
	 */
	public static function scheme_ink( $hex ) {
		return SchemeInk::scheme_ink( $hex );
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
					'title' => 'The Page You Half-Fixed Stays on the List',
					'text'  => 'Fix one thing on a page and the whole page used to disappear from Optimize Your Content, as though nothing were wrong with it — then turn up again later with the untouched issues still there. Saving a post erased what Agentimus knew about it. The page keeps its place now and says it is being read again, and that reading happens within about a minute of your save.',
				),
				array(
					'title' => 'A Verdict Now Remembers Which Checks Made It',
					'text'  => 'A grade is an answer to a question, and Agentimus was keeping the answer while forgetting the question — so a page graded by last month\'s checks kept that verdict for good. Every verdict now records the checks behind it, and when a release changes them your content is read again, keeping what it last said on screen while it waits. Expect your counts to move once after this upgrade — that is the old answer being corrected, not new problems appearing.',
				),
				array(
					'title' => 'The Featured Image Is Judged on the Page You Serve',
					'text'  => 'That picture is drawn by your theme, so nothing Agentimus could read told it how the picture reaches a reader. It now reads a couple of your own pages in the background, once per theme. An image served with no description at all is named as the failure it is; a theme that stands the post title in for a missing one says so — and if your theme ignores the description you wrote, that is named as the thing to fix, instead of your content being blamed for a picture you had already described.',
				),
				array(
					'title' => 'An Assistant Can Find the Work, Not Just Be Told a Number',
					'text'  => 'A connected assistant could rewrite a page but had no way to learn which page — the findings tool named a number and handed back a link to a screen. There is a tool for the list itself now: which pages need work, everything each is flagged for, whether it answers the search it is found for, and how old that reading is. It can also set the two fields that decide how a page appears in a search result, and read the categories and tags your site already uses, so it stops inventing a second "New features".',
				),
				array(
					'title' => 'The Content Checks Say Who They Are For',
					'text'  => 'Twelve of the fourteen are the classic on-page work a search engine and a screen reader need — headings, alt text, thin content, reading ease, freshness — and only the editor panel said so. Readiness, your score and the manual say it now. Naming one audience invited you to weigh the work against your opinion of AI, when most of it pays either way. The reading-ease row also stopped asking for work you had already done: it names the half actually holding the score down, instead of advising shorter sentences at a page whose sentences are already short.',
				),
			),
		);
	}

	/**
	 * Thumbnail URL of the saved default share image, '' when unset or the
	 * attachment is gone (a stale ID must degrade to "none", same as emission).
	 *
	 * @return string
	 */
	private function social_default_image_url() {
		$id = (int) $this->settings->get( 'social_default_image', 0 );
		if ( $id < 1 || ! function_exists( 'wp_get_attachment_image_src' ) ) {
			return '';
		}
		$src = wp_get_attachment_image_src( $id, 'thumbnail' );
		return ( is_array( $src ) && ! empty( $src[0] ) ) ? (string) $src[0] : '';
	}

	private function bootstrap_data() {
		// ⭐ Before anything below reads the checking scope. A content type this
		// site has never decided about gets its default here — on, unless it is
		// large enough that reading it should be the owner's own call — so the
		// panel, the readiness card and the score all describe one settled state
		// rather than three views of a decision still being made. Runs an
		// array_diff on each load of THIS screen and nothing more; the counts
		// behind the size guard are taken only for a genuinely new type.
		Content::note_new_checkable_types( $this->settings );

		return array(
			'restUrl'     => esc_url_raw( rest_url( Rest::NAMESPACE ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			// The plugin's own admin page, built from the menu SLUG here so the
			// app's brand button can hard-navigate to it without hardcoding it.
			'pageUrl'     => esc_url_raw( admin_url( 'admin.php?page=' . self::SLUG ) ),
			'settings'    => $this->settings->all(),
			'defaults'    => $this->settings->defaults(), // Powers the reset-preview.
			// The owner's Settings → General display formats, so every date the app
			// prints reads like the rest of this admin (see wpDate.js for the renderer).
			'dateFormat'  => get_option( 'date_format' ) ? get_option( 'date_format' ) : 'F j, Y',
			'timeFormat'  => get_option( 'time_format' ) ? get_option( 'time_format' ) : 'g:i a',
			// Where the weekly email actually goes when its override is empty — shown
			// as the recipient field's placeholder, so "the site admin email" is a real
			// address on screen, not a riddle. Admin-only payload; it's the owner's own.
			'adminEmail'  => sanitize_email( (string) get_option( 'admin_email', '' ) ),
			// The solo/coexist verdict ({mode, plugin}) — the dashboard companion
			// card and the wizard summary word themselves around it.
			'seo'         => SeoContext::resolve(),
			// Thumbnail of the saved default share image, so the picker previews
			// on load without a REST round trip (the setting itself is only an ID).
			'socialDefaultImageUrl' => $this->social_default_image_url(),
			'readiness'   => $readiness = ( new Readiness( $this->settings ) )->report(),
			'score'       => $score = $this->aeo_score( $readiness ), // AEO/GEO score + action plan, from the same readiness run.
			// Every open finding across every subsystem, ranked by what it costs —
			// what the Findings screen renders. Shipped with the boot payload so the
			// plugin's first screen answers "is anything wrong?" with no round trip.
			'findings'    => ( new Findings( $this->settings ) )->all(),
			// The bell's number at first paint — the same cached count the WP
			// sidebar bubble shows. Without it the badge waited for the activity
			// fetch and popped in a beat after its neighbour, whose findings ride
			// this same payload.
			'reviewCount' => ReviewBadge::count( $this->settings ),
			// Counts only — no page is parsed — so the content section can open
			// knowing something true about the site rather than an empty panel
			// with a button on it.
			'worklistPreview' => ( new Worklist( $this->settings ) )->preview(),
			// What the gear on Your Content opens: which kinds of content are
			// read for the owner, and which cannot be.
			'checkTypes'  => $this->check_post_type_cards(),
			// The two doors out: Get Help (the forum) and Report an Issue
			// (GitHub). Composed here rather than fetched, so the dialogs open
			// with the facts already true — and so the block the owner READS is
			// the same string the URL carries.
			'support'     => Support::payload(),
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
			// The wizard's proof screen: Ask-AI links carrying a site-level question
			// (the assistant has to fetch the site to answer — that fetch is what the
			// screen watches the request log for), plus whether the public internet
			// can reach this site at all (on .test/localhost the watch would never end).
			'askAi'       => array(
				'assistants' => ( new AskAi( $this->settings ) )->wizard_links(
					sprintf( 'Read %s and tell me in two sentences who is behind this site and what it covers.', home_url( '/' ) )
				),
				'public'     => AskAi::host_is_public( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ),
			),
			// What WordPress already knows, offered to the wizard as prefills — a
			// new owner should edit a suggestion, not stare at a blank field.
			'suggest'     => array(
				'about' => Plugin::real_tagline(),
			),
			// The post-setup "Worth a look next" dashboard card: queued by
			// finishing (or skipping) the wizard, gone for good once dismissed.
			'nextSteps'   => array(
				'show' => 'show' === get_option( 'agentimus_next_steps', '' ),
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
			// The IndexNow verification file's address — the Settings hint links
			// it so the owner can open the half an engine checks. Minted here on
			// first need; served only while the switch is on.
			'indexnowKeyUrl' => IndexNow::key_url(),
			// The review ask (Dashboard only, same family): shows once the plugin
			// has earned it — see Review::eligible() for the gates. Answers land
			// at Rest::review_ack(); a final answer also quiets the footer's line.
			'reviewAsk'   => array(
				'show' => Review::eligible( Review::state(), isset( $score['score'] ) ? $score['score'] : null, time() ),
				'url'  => Review::URL,
			),
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
					// How many tools an assistant would actually get — counted off the
					// same list the server publishes, so the card can never advertise a
					// number the server doesn't serve.
					'toolCount'          => count( ( new Abilities\Registrar( $this->settings ) )->mcp_abilities() ),
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
	 * The same fact, for the /mcp-status poll — so a card left open on screen can
	 * refresh what it says instead of freezing at the page-load value.
	 *
	 * @return array{known:bool,call:?array}
	 */
	public static function last_tool_call() {
		return ( new self( new Settings() ) )->last_agent_tool_call();
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
		// What the OWNER chose, before any plugin had a say. Anything active that
		// is not in here was forced on through the `agentimus_post_types` filter,
		// and its tickbox is not a control — ticking it changes nothing, and
		// unticking it changes nothing either, because the filter runs last.
		// Drawn unticked, it read as "off" for a type that was demonstrably on.
		$chosen = (array) $this->settings->get( 'post_types', array() );
		$vetoed = (array) $this->settings->get( 'post_types_vetoed', array() );
		// What a plugin ASKED for, before the owner's veto removed anything —
		// otherwise a vetoed type looks like one nobody ever offered, and the
		// card loses the only clue that switching it back on is even possible.
		$offered = array_values( array_unique( array_merge( Content::post_types(), $vetoed ) ) );

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
				// On, but not by the owner's hand. The screen shows these as
				// enabled-and-locked rather than as an empty box nobody can fill.
				// Offered by a plugin rather than picked by the owner.
				'forced'     => in_array( $slug, $offered, true ) && ! in_array( $slug, $chosen, true ),
				// …and the owner said no. Only meaningful alongside `forced`.
				'vetoed'     => in_array( $slug, $vetoed, true ),
				'restBase'   => $advertises ? sanitize_key( (string) ( ! empty( $obj->rest_base ) ? $obj->rest_base : $obj->name ) ) : '',
				'taxonomies' => array_values( array_unique( $taxes ) ),
			);
		}
		return $out;
	}

	/**
	 * The CHECKING scope, for the gear on Your Content: every content type this
	 * site could read for the owner, whether it is being read, and how much of it
	 * there is.
	 *
	 * ⚠️ Two different questions live on two screens and must not be confused.
	 * Settings → Content Types curates what LEAVES the site. This decides what
	 * the plugin READS for the owner, publishes nothing, and therefore defaults
	 * to everything eligible. {@see Content::check_post_types()}
	 *
	 * `blocked` types are shown with their reason rather than omitted: a type
	 * that is simply missing reads as a bug, and the one thing an owner cannot
	 * do is ask about something they were never shown.
	 *
	 * @return array<int,array{slug:string,label:string,source:string,count:int,on:bool,blocked:string}>
	 */
	private function check_post_type_cards() {
		$eligible = Content::checkable();
		$on       = Content::check_post_types();
		$out      = array();

		foreach ( $eligible as $slug ) {
			$is_on = in_array( $slug, $on, true );
			$out[] = array(
				'slug'    => $slug,
				'label'   => Content::label( $slug ),
				'source'  => Content::source( $slug ),
				'count'   => Content::published_count( $slug ),
				'on'      => $is_on,
				// Why this one starts off — a big catalogue, or a type its own
				// plugin keeps out of site search. Only ever shown on an unticked
				// card: an owner who has ticked it anyway has settled the question
				// and does not need it re-argued every time they open the panel.
				'note'    => $is_on ? '' : Content::check_default_off_reason( $slug ),
				'blocked' => '',
			);
		}

		// Public, reachable, and still not checkable. Only attachments land here
		// — shown rather than omitted, because "why isn't my media in this list"
		// is a question the panel should answer before it is asked.
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $slug ) {
			if ( in_array( (string) $slug, $eligible, true ) ) {
				continue;
			}
			$out[] = array(
				'slug'    => (string) $slug,
				'label'   => Content::label( (string) $slug ),
				'source'  => Content::source( (string) $slug ),
				'count'   => 0,
				'on'      => false,
				'note'    => '',
				'blocked' => 'attachment' === (string) $slug
					? __( 'Media files have no page of their own to read.', 'agentimus' )
					: __( 'This kind of content has no page an answer engine could read.', 'agentimus' ),
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
				esc_html__( 'The plugin’s admin screens are missing from this install — usually a broken or partial upload. Re-install the plugin from a fresh zip. (Developers running from source: npm install && npm run build.)', 'agentimus' ) .
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
