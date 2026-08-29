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
	/**
	 * ⭐ THE THREE TOOLTIPS JOINED ON 2026-08-21 — his catch, on the Light
	 * scheme: "also the tooltip". They are ink surfaces by the same test as
	 * everything else here — each paints `background: var(--ar-ink)` with its
	 * text in --ar-surface — and they were the only ones left out, so a bubble
	 * came up in the app's own near-black beside a button wearing the scheme's
	 * colour. That was true on every scheme, not just his; the Light room is
	 * simply where a near-black bubble on a grey page is impossible to miss.
	 * ⚠️ The carets are CHILDREN (.ar-act-tip__caret, .ar-act-uatip__caret) and
	 * paint from the same token, so they follow without being named.
	 * ⛔ .ar__mark, .ar-wiz__welcome-mark--brand and .ar-about-snippet are ink
	 * grounds too and stay OUT, each for its own written reason: the two marks
	 * are chrome that must not change with the mode (schemes.css restates them),
	 * and the snippet is dressed per-scheme by hand.
	 */
	const SCHEME_SCOPE = '.ar-btn,.ar-tags__chip,.ar-rail-card.ar-rail-card--readiness,.ar-srcpick__btn.is-on,.ar-act-tip,.ar-act-uatip,.ar-tip';

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
	 * {@see scheme_ink()} derivation for third-party ones.
	 *
	 * ⭐ THE STOCK "fresh" SCHEME LENDS BUT DOES NOT ADOPT (his call 2026-08-21):
	 * it emits an ink like any other scheme — #262d31, the designed night card —
	 * and nothing else, because the designed palette IS the fresh look and has
	 * nothing to take from it. See the $accent gate below.
	 *
	 * @return string CSS custom-property rule, or '' (no scheme / opted out).
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

		// ⚠️ colors[0] — the FIRST swatch, used purely as a stable key. Verified
		// against WordPress's own colors.scss: the menu is colors[1] for six core
		// schemes, colors[2] for classic blue and colors[0] for light and modern,
		// so there is no slot that always holds the menu. colors[0] is the slot
		// that is stable and unique per scheme-and-generation.
		// ⛔ Not active_surface(): that answers "which colour is this scheme
		// wearing" for the body stamp, and on classic blue it returns #4796b3,
		// which is neither the menu nor a key here.
		global $_wp_admin_css_colors;
		$base = isset( $_wp_admin_css_colors[ $scheme ]->colors[0] )
			? (string) $_wp_admin_css_colors[ $scheme ]->colors[0]
			: '';
		$ink  = self::card_ink_for( $scheme, $base );
		if ( '' === $ink ) {
			return '';
		}

		// ⭐⭐ colors[2] — WORDPRESS'S OWN HIGHLIGHT, and his idea, 2026-08-20. He
		// pointed at the admin menu's CURRENT item — the one WordPress paints to
		// say "you are here" — and asked for that colour in the app's links, tab
		// underlines, numbers and everywhere a green used to be. It is the right
		// source: every other value here is derived, and this one is the scheme's
		// author saying which colour means "this one".
		//
		// ⚠️ Only where it can carry TEXT on the page. Seven of the nine core
		// schemes clear 4.5:1 on the cream card — ectoplasm 5.50, fresh 5.08,
		// coffee 4.88, ocean 4.83, light 4.82, sunrise 4.51, blue 4.50 — and two
		// do not: midnight's #69a8bb is 2.61:1 and modern's #7b90ff 2.84:1, both
		// mixed to sit on a DARK menu, not on paper. Those keep the derived ink,
		// which is what they have today, so the fallback is not a downgrade.
		// ⛔ Not on a bright-menu scheme. There the accent is the menu colour by
		// his earlier call, and a third colour would undo it.
		$highlight = isset( $_wp_admin_css_colors[ $scheme ]->colors[2] )
			? (string) $_wp_admin_css_colors[ $scheme ]->colors[2]
			: '';

		$light = ':root:not([data-ar-theme="dark"]) ';
		$scope = $light . implode( ',' . $light, explode( ',', self::SCHEME_SCOPE ) );

		// ⛔ #e8e8e8 AND NOT #ffffff, for two reasons that are really one. The
		// score block is a button — the gauge and the band word jump to the
		// readiness report — and app.css announces that with
		// `filter: brightness(1.08)`. brightness() MULTIPLIES, so on pure white it
		// is a no-op: 255 x 1.08 clips back to 255 and the whole row reacts to the
		// pointer by doing nothing. His catch.
		// ⭐ #e8e8e8 lifts to #fbfbfb — a 1.18:1 change, which reads — and is the
		// calmer face he asked for in the same breath. A multiplicative filter
		// needs headroom, and a value at the end of its range has none.
		$face       = '#e8e8e8';
		// ⚠️ AND --rail-read:100%. The rungs are drawn as --rail-face behind a 74%
		//    veil, and that veil was HIERARCHY while the kicker and the band word
		//    were a green: two colours, one of them quieter. Once those went to
		//    the same face, the veil stopped separating anything and just made
		//    the rung rows look dimmed beside every other line on the card — his
		//    catch. With one face for the card, the rungs read at full strength.
		// ⛔ AND THE HOVER NEEDS SOMEWHERE TO GO. app.css lifts a rung on hover by
		//    dropping its veil — `color: var(--rail-face)` — which only reads while
		//    the resting state IS veiled. --rail-read:100% makes the resting state
		//    already --rail-face, so that rule resolves to the colour it started
		//    from and the row stops answering the pointer. A value at the end of
		//    its range has no headroom left — the same lesson as the pure-white
		//    face. ⭐ So the lift goes ABOVE the face, to white.
		// ⭐ AND THE SCORE BLOCK WITH THEM — his call, 2026-08-20: "make sure
		//    rails each line use same bright hover color including the Excellent
		//    line". That row lifted by `filter: brightness(1.08)` instead, which
		//    took #e8e8e8 to #fbfbfb — a different white from the rungs' #ffffff,
		//    on the same card, under the same pointer. Naming the colour makes
		//    every line answer identically; the filter still runs underneath and
		//    is a no-op on a value already at 255.
		// ⛔ The gold is deliberately NOT in here. "N to fix" is the one thing on
		//    the card that means something, and it stays its own colour whether or
		//    not the row is under the pointer — his words: it "should remain
		//    bright".
		// ⚠️ (0,6,0) — app.css's own hover rule is (0,5,0).
		$rug_hover = $light . '.ar-rail-card--readiness'
			. ' .ar-rung__btn:not(.ar-rung__btn--static):hover :is(.ar-rung__name,.ar-rung__count),'
			. $light . '.ar-rail-card--readiness'
			. ' .ar-rail-readiness--link:hover :is(.ar-rail-tier__name,.ar-rail-gauge__num)';

		// ⭐ HIS PATTERN, 2026-08-20, after watching me chase a card border, then a
		//    chart bar, then a picker, one at a time: they all read --ar-accent.
		//    Re-key the TOKEN and every one of them follows — 234 references in
		//    app.css, plus --ar-accent-wash and --ar-accent-soft, which are mixed
		//    from it. Stop patching elements.
		//
		// ⚠️ LIGHT MODE ONLY, and that is what makes it safe. In light,
		//    --ar-good / --ar-warn / --ar-bad carry their OWN values (#2f7a4c,
		//    #ad7b18, #b93c2b), so success stays green and a warning stays gold.
		//    At night they are ALIASED to --ar-accent, and doing this there would
		//    turn every PASS badge and every ring the scheme's colour.
		//
		// ⚠️ THE INK on a dark menu, THE SURFACE on a bright one — $accent_hue
		//    below. His call, 2026-08-20, and it replaced mine: I had both blues
		//    taking the deep ink so the accent would read as text, and the result
		//    was a bright button beside a dark link with nothing to explain the
		//    difference. One colour per scheme, on the surfaces AND the accent,
		//    is what he asked for: "same as classic blue theme in light mode".
		//    ⚠️ The number, on the table and overruled twice: #52accc is 2.54:1
		//    on the cream card, under the 4.5 floor for text and the 3 for a
		//    graphic. What it buys is one colour where there were two.
		// ⚠️ Declared at :root, not on the app. --ar-accent-wash and
		//    --ar-accent-soft are color-mixes of --ar-accent written in the :root
		//    block, so they resolve against :root's value: overriding the accent
		//    further down left the washes teal while everything else went blue.
		//    Re-keying at the same level fixes all three in one declaration.
		// ⚠️ AND the two washes, explicitly. In light mode app.css writes them as
		//    hand-picked teal LITERALS (#f0f6f4 / #e3efec), not as mixes of the
		//    accent — only the dark blocks derive them — so re-keying the accent
		//    alone left every wash teal while its own colour went blue. Same 12%
		//    and 20% formulas the dark blocks use, against whatever surface is in
		//    force.
		// The colour every accent-driven thing takes: WordPress's own highlight
		// where that reads on paper, and the ink otherwise — see $highlight above.
		// ⚠️ THERE USED TO BE A THIRD CASE HERE, the "bright menu" surface, and it
		// is gone with the branch that consumed it (2026-08-21). See the note on
		// the return below.
		$accent_hue = SchemeInk::carries_text( $highlight ) ? $highlight : $ink;

		// ⭐⭐ THE GREEN ITSELF, not a list of the things wearing it. His call,
		//    2026-08-20: "just apply this color where a green color was before,
		//    including All registrations are valid text."
		//
		//    This replaces a $verdict block that named six selectors — the rule
		//    beside a check, the group rung and its count, the totals dot and its
		//    number, the rail's registration card — and still missed the up-delta
		//    arrows, the live dot and the PASS pills, which he could see and I
		//    could not until he pointed. Every one of them paints --ar-good. Re-key
		//    the token and the list stops existing. His own law, from earlier the
		//    same day: stop patching elements.
		//
		// ⛔ ONLY WHERE THE COLOUR CAN CARRY THE MEANING — verdict_can_take() is
		//    unchanged and is what makes this safe. Sunrise's highlight is hue 29,
		//    eleven degrees from --ar-warn; light's is hue 20; coffee's 27. On
		//    those a passing check would paint as a warning, so they keep the
		//    designed green. Ocean's #567958 is hue 123 and 17% saturated: a green
		//    that means what green means.
		// ⚠️ AND THE TWO MIXES WITH IT. --ar-good-wash and --ar-good-tint are the
		//    pill's ground and border; left behind they would frame the new colour
		//    in the old one. Same 10% / 26% formulas the dark block uses.
		// ⛔ --ar-good-strong and --ar-good-deep are deliberately NOT re-keyed:
		//    they are emphasis steps picked by hand against the designed green,
		//    and deriving them here would be inventing two values to fix a problem
		//    nobody has reported. Worth his eye if a screen ever shows all four.
		$good = SchemeInk::verdict_can_take( $accent_hue )
			? '--ar-good:' . $accent_hue . ';'
				. '--ar-good-wash:color-mix(in srgb,' . $accent_hue . ' 10%,var(--ar-surface));'
				. '--ar-good-tint:color-mix(in srgb,' . $accent_hue . ' 26%,var(--ar-surface));'
			: '';

		// ⛔ AND NOTHING IS RE-KEYED IF IT CANNOT CARRY TEXT. His catch 2026-08-20
		//    on the Light scheme: its highlight (#c64606) misses the floor, so the
		//    line above fell back to $ink — and on THIS scheme the ink is a near
		//    white. The app was emitting --ar-accent:#e5e5e5, an accent nothing
		//    could read, on links, tab underlines, numbers and chart bars.
		// ⭐ The fallback is to emit nothing at all, which leaves app.css's own
		//    #146b64 standing — his value for this scheme, and the designed teal
		//    every unmeasured scheme already gets. A guard, not a special case:
		//    any future scheme whose ink is bright lands the same way.
		// ⚠️ $good keeps its own gate (verdict_can_take) and is inside this
		//    string, so it falls with it — correct, since a verdict mark nothing
		//    can read is worse than a green one.
		// ⛔ AND NOT ON A SCHEME WHOSE INK IS BRIGHT — the Light scheme, which
		//    schemes.css dresses by hand and which already exempts itself from the
		//    hover re-key below for the same reason. Its highlight is #c64606, a
		//    strong orange that DOES carry text, so the rule above would have
		//    keyed the whole app to it: links, tab underlines, numbers, chart
		//    bars. His call 2026-08-20: this scheme wears the designed teal
		//    #146b64 in both modes, the value its night block already restates,
		//    and the one thing its mode changes is the rug.
		// ⛔ AND NOTHING IS RE-KEYED IF IT IS A GREY. His catch 2026-08-21, on the
		//    DEFAULT scheme — the one most people never change. `modern` registers
		//    #1e1e1e; its highlight (#7b90ff) misses the text floor, so the line
		//    above fell back to $ink, and the app emitted --ar-accent:#1e1e1e. The
		//    traffic chart's bars, the links, the tab underlines, the numbers and
		//    the readiness ring all went BLACK, and the app lost its colour on the
		//    scheme that ships switched on.
		// ⚠️ The guard beside this one could not catch it, and the reason is worth
		//    keeping: carries_text() asks whether a colour can be READ, and a
		//    near-black on cream reads at 18:1. It passed the test PRECISELY
		//    because it has no colour in it. Readable and coloured are two
		//    questions, and the accent needs both answered yes.
		// ⭐ Emitting nothing leaves app.css's designed teal standing — the same
		//    fallback the Light scheme takes, and the right one here: a scheme
		//    whose own colour is a grey has no flavour to lend, so it lends none.
		//    ⚠️ This also takes MIDNIGHT back to the teal (its ink is 12.6%
		//    saturated, under the floor his ocean tuning set). Both generations of
		//    it, by the same reasoning.
		// ⛔ AND NOT ON FRESH, WHICH LENDS BUT DOES NOT ADOPT — his call 2026-08-21,
		//    when he gave that scheme a light ink (#262d31) and nothing else.
		//    ⭐ It is not a special case, it is the base case: the teal, the green
		//       and the cream were all mixed ON fresh's charcoal. A scheme has
		//       nothing to adopt from the palette that was designed against it, so
		//       fresh lends its slabs a depth and takes no colour back.
		//    ⚠️ WITHOUT THIS the app would go WordPress blue on the default-until-
		//       7.1 scheme: fresh's highlight #2271b1 clears the text floor at
		//       5.08:1 AND carries colour, so it would key --ar-accent, --ar-good
		//       and both washes, and $rug_white would follow it. Measured, not
		//       assumed. He asked for an ink; this keeps the ask to an ink.
		//    ⛔ Keyed by SLUG on purpose, against this file's usual rule. Every
		//       other lookup here asks "what colour is this scheme", which slugs
		//       go stale about; this one asks "is this the scheme the palette was
		//       drawn on", which is an identity and cannot be retuned away.
		// ⛔⛔ AND NOT ON "light" EITHER, BY NAME — and this replaced a booby trap.
		//    That scheme was held out by `! is_bright( $ink )` alone, which was
		//    true only while its ink happened to sit above 0.42. On 2026-08-21 he
		//    asked for #656363 (0.3922) and the guard would have silently opened:
		//    its highlight is #c64606, a strong orange that DOES carry text, so
		//    the whole app would have keyed to it the moment the ink went a shade
		//    deeper. ⭐ A brightness number was never the right question. "This is
		//    the scheme schemes.css dresses by hand, in both modes" is an
		//    IDENTITY — the same reasoning as fresh's above — so it is stated as
		//    one and the ink is free to be any depth he likes.
		//    ⚠️ is_bright() stays in the condition: it still guards any FUTURE
		//    scheme whose ink is too bright to carry these, which is a different
		//    question from this one.
		$accent = ( 'fresh' !== $scheme && 'light' !== $scheme && SchemeInk::carries_text( $accent_hue ) && SchemeInk::carries_colour( $accent_hue ) && ! self::is_bright( $ink ) )
			? ':root:not([data-ar-theme="dark"]){'
				. $good
				. '--ar-accent:' . $accent_hue . ';'
				. '--ar-accent-wash:color-mix(in srgb,' . $accent_hue . ' 12%,var(--ar-surface));'
				. '--ar-accent-soft:color-mix(in srgb,' . $accent_hue . ' 20%,var(--ar-surface))'
				. '}'
			: '';

		// ⭐ THE RUG WEARS WHITE ON EVERY SCHEME-COLOURED GROUND — his call,
		//    2026-08-20, pointing at the classic ocean's rug: "apply this straight,
		//    use same color on lines and same hover effect."
		//
		//    This landed in three steps and the middle one was mine to get wrong.
		//    It began as SCHEME_RAIL_WHITE, a named list holding both blues. I read
		//    his next screenshot as "make the classic ones green" and deleted the
		//    list; he meant the opposite — that the treatment the brighter rooms
		//    had should reach the classic ones too. So: no list, no branch, no
		//    ground test. A rug that has taken a scheme's colour wears white.
		//    ⛔ The ONE rug that keeps the green is the default's, because "fresh"
		//    returns before any of this and never reaches a re-key at all.
		//
		// ⚠️ --rail-read:100% IS THE HALF THAT IS EASY TO MISS. A rug's rung names
		//    are drawn as --rail-face behind a 76% veil, mixed for a DARK card.
		//    Painting the face white and leaving the veil on composites it back
		//    down: on ocean's brighter ground the names came out at 2.84:1 while
		//    the band word beside them sat at full strength — one colour at two
		//    brightnesses, which reads as "dimmed" rather than as hierarchy. At
		//    100% the whole card speaks once. His words: "use same color on lines".
		//
		// ⛔ #e8e8e8 AND NOT #ffffff, for the reason he caught on blue: the score
		//    block announces itself with `filter: brightness(1.08)`, which
		//    MULTIPLIES, so on pure white it is a no-op and the row stops answering
		//    the pointer. #e8e8e8 lifts to #fbfbfb and still reads as white.
		//
		// ⚠️ What it measures. On the dark inks it is comfortable — 8.2:1 and up.
		//    On a bright surface it is not: 2.87:1 on sunrise's #cf4944, where the
		//    green it replaces could not reach a floor either. Raised with him with
		//    the numbers; his call. (Ocean's #738e96 and blue's #52accc measured
		//    3.48 and 2.58 here before 2026-08-20 retired both.)
		// ⛔ AND NONE OF IT ON A BRIGHT INK — his catch 2026-08-20, hovering
		//    "Findable" on the Light scheme: the row went WHITE and vanished. Both
		//    halves above are drawn for a rug that is DARK. The face re-key is
		//    already outranked by that scheme's own block, but the hover is not —
		//    it names elements app.css alone would have handled, and #ffffff on a
		//    #ebeaea slab is 1.1:1.
		// ⭐ Emitting nothing restores app.css's own rung hover, `color:
		//    var(--rail-face)`, which drops the 82% veil to the full #1e1e1e —
		//    the same "come forward" gesture, correct on a light ground because
		//    the face it lifts to is dark. The score block above it already
		//    deepens by filter for the same reason.
		// ⛔ AND THE RUG FOLLOWS THE ACCENT, not the brightness of the ink. His
		//    note above says it plainly — "a rug that has taken a SCHEME'S COLOUR
		//    wears white" — and the test for that is whether the scheme lent one.
		//    On the Default scheme it did not (grey, see $accent), yet this fired
		//    anyway and washed the ring, the band word and the rungs to #e8e8e8:
		//    a card with its green taken away and nothing put in its place. The
		//    exemption he wrote for "fresh" — "the ONE rug that keeps the green is
		//    the default's" — was keyed to a slug, and WordPress 7.1 moved the
		//    default to `modern`, which walks straight past that early return.
		// ⭐ Keyed to the accent instead, it holds for whichever scheme is the
		//    default, this generation or the next. ⚠️ It subsumes the is_bright()
		//    test it replaces: a bright ink is the Light scheme, whose $accent is
		//    already empty for its own reason, so that rug is untouched as before.
		$rug_white = '' === $accent
			? ''
			: $light . '.ar-rail-card.ar-rail-card--readiness'
				. '{--rail-face:' . $face . ';--rail-good:' . $face . ';--rail-read:100%}'
				. $rug_hover . '{color:#ffffff}';

		// ⛔ THERE IS NO SECOND BRANCH ANY MORE. Until 2026-08-21 this returned
		// early and ~60 lines below dressed a "bright menu" scheme in its own menu
		// colour instead of a dark ink. That path died on 2026-08-20 when he
		// collapsed blue, ocean and sunrise onto their 7.1 bars and SCHEME_SURFACES
		// emptied — `card_surface_for()` could only ever answer '', so the test
		// guarding the branch was always true and the code under it unreachable.
		// It stood for a day with a comment saying so; this is that deletion.
		// ⚠️ WHAT IT COST, so nobody has to reconstruct it from git: on a bright
		// menu the ink surfaces AND the accent both took the menu's own colour
		// (his call: "same as classic blue theme in light mode"), the rug's
		// "N to fix" took a #f8c350 gold that survives a mid-tone ground, the
		// GHOST button kept the dark ink because it paints on the PAGE, and the
		// button hover DEEPENED instead of lifting. ⛔ Restoring any of that means
		// restoring the branch too — adding a row to SCHEME_SURFACES alone would
		// now do nothing, which is exactly why that table went with it.
		$css = $scope . '{--ar-ink:' . $ink . '}' . $accent;

		// ⛔ …but NOT app.css's hover. It dives to --ar-ink-hard, which is
		//    #000 by day, so a navy or brown button turns BLACK under the
		//    pointer and throws its hue away — his catch on the dark blue:
		//    "changes button to black, which is also not in sync".
		// ⭐ A dark surface LIGHTENS instead. 88% toward white is a 1.35–1.46:1
		//    shift across every ink we emit — the same gesture, read the same way.
		// ⚠️ Skipped when the ink is itself bright: that is the Light scheme,
		//    which schemes.css already dresses by hand, and this would
		//    override it.
		// ⚠️ AND NOT FOR "light", whose own day hover DEEPENS (schemes.css) —
		// emitting a lighten here would be a rule that only ever loses.
		if ( 'light' !== $scheme && ! self::is_bright( $ink ) ) {
			$css .= $light . '.ar-btn:not(.ar-btn--ghost):not(.ar-btn--danger):hover:not(:disabled)'
				. '{background:color-mix(in srgb,' . $ink . ' 88%,#fff);box-shadow:none}';
		}

		return $css . $rug_white;
	}

	/**
	 * Is a colour light enough that a hover should DEEPEN it rather than lift it?
	 *
	 * @param string $hex Colour.
	 * @return bool
	 */
	private static function is_bright( $hex ) {
		$hex = ltrim( strtolower( trim( (string) $hex ) ), '#' );
		if ( ! preg_match( '/\A[0-9a-f]{6}\z/', $hex ) ) {
			return false;
		}
		$rgb = array_map( 'hexdec', str_split( $hex, 2 ) );

		return ( ( max( $rgb ) + min( $rgb ) ) / 2 / 255 ) > SchemeInk::BRIGHT_MENU_L;
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
			// ⭐⭐ WHAT A CARD IS FOR, and it is not the changelog. Each of these
			// used to run sixty to ninety words — the release note's whole
			// argument, in the plugin's own vocabulary ("verdict", "graded",
			// "the sweep"), five times over. His question, and it was the right
			// one: does that make sense to somebody who is not us? A card gets
			// one line about what is different for the reader, and a second only
			// when there is something to expect or do. The full story already
			// lives in the changelog, one click away, which is where an owner
			// who wants it will go.
			// ⛔ No internal words. If a sentence needs "verdict" or "sweep" to
			// stand up, it is the changelog's sentence, not this one.
			// ⭐ Titles are Title Case, the standing rule for every title this
			// plugin writes.
			'items'   => array(
				// ⛔ EVERY RELEASE REWRITES THIS LIST, not just the ones that add
				// features. The card re-shows whenever AGENTIMUS_VERSION changes, so
				// leaving the last release's items here would greet an owner with news
				// they have already read, under a heading claiming it is new — the
				// fault he caught on the 1.40.0-dev4 build, where 1.39.0's items sat
				// under a 1.40.0 title.
				// ⭐ THE LAST SLOT IS THE FIXES, and it is a SINGLE item — his call,
				// 2026-08-23. A release ships more fixes than a card has room for, and
				// listing them one per row would push the two things that are genuinely
				// different off the top. One row that says "these were wrong and now
				// are" is the honest shape, and it goes LAST: nobody opens a plugin to
				// read what used to be broken.
				// ⛔ A LINK WHERE THERE WAS DEAD TEXT IS NOT A FEATURE. The plugin cards
				// on Integrations grew a link to each plugin's own site, and that had a
				// row here until he cut it: the owner did not lose anything before, and
				// a card that announces every improvement teaches people to stop reading
				// it. The Discovery one survives because it changes what the owner can
				// SEE — one click and the page an assistant fetches is on screen.
				// ⛔ The internal-names fix is NOT in the fixes row either. "We stopped
				// showing you our own key names" is an apology for a thing the owner
				// never had a word for — the changelog carries it.
				// ⭐ A SMALL RELEASE GETS A SMALL CARD. 1.49.0 ships one arc and no
				// user-facing fixes (the one fix of the cycle never left a dev
				// build), so there is no fixes row — padding the card to four
				// items would teach people to stop reading it.
				array(
					'icon'  => 'shield',
					'title' => 'A Warning Now Checks Who Was Really Blocked',
					'text'  => 'Your CDN counts crawlers by the name they wear, and anyone can wear one. Before Agentimus tells you Cloudflare is blocking an AI company, it now checks whether the blocked traffic actually came from that company\'s own addresses. When it turns out to be impostors being stopped, the warning says that instead — your edge is doing its job, and nothing needs allowing.',
				),
				array(
					'icon'  => 'clock',
					'title' => 'And It Corrects Itself Both Ways',
					'text'  => 'The note keeps the date the blocking started and any fold you made, and it turns back into a warning by itself the moment the real company starts being refused. An inconclusive check never downgrades a warning — "could not say" is not "not them".',
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
			// Which day a week starts on here (Settings → General). A calendar that
			// always starts on Sunday is wrong for most of the world, and the answer
			// is already an option this site set — the Report screen's date picker
			// lays its columns out from this.
			'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
			// ⛔ The day THIS SERVER calls today, in the clock its activity is
			// stamped in (GMT — see Report\Data::today_gmt()). The Report
			// screen's presets count back from it, so "7 days" is seven of the
			// days the log actually has, never seven of the browser's. Seeded
			// here so the first click has an answer; every report read replaces
			// it with the one the answer carries.
			'todayGmt'    => gmdate( 'Y-m-d' ),
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
