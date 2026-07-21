<?php
/**
 * The shareable scorecard — a PUBLIC face for the AI-readiness score.
 *
 * Opt-in (off by default): one switch publishes the score the dashboard
 * already computes as (1) a human-readable page at /ai-readiness, (2) an
 * embeddable SVG badge at /ai-readiness/badge.svg, and (3) a social-preview
 * PNG at /ai-readiness/card.png so a shared link unfurls as a score card.
 * Everything is generated and served by this site — no outbound request,
 * no external image service, nothing sent anywhere.
 *
 * Two display modes, because a score is a brag and people brag differently:
 * 'score' prints the number ("93/100"); 'tier' prints only the earned
 * "AI-Ready" mark (TIER_MIN) — for owners who'd rather not publish a number
 * that isn't 100. The tier's threshold is a plugin constant, deliberately NOT
 * filterable: the mark is only worth showing if it means the same thing on
 * every site that wears it.
 *
 * Honesty rule: every public surface renders the CURRENT report — never a
 * pinned best day. In tier mode a site below the line reads "in progress"
 * (no number leaks); the courtesy warning about a dropped score belongs in
 * the dashboard, not in a lying badge.
 *
 * @package Agentimus
 */

namespace Agentimus;

/**
 * Serves the public scorecard page, badge and social card.
 */
final class Scorecard {

	/** Default public path; filterable via `agentimus_scorecard_path`. */
	const PATH = '/ai-readiness';

	/**
	 * The score at which the 'tier' display earns its "AI-Ready" mark. A
	 * constant, not a setting: a mark whose bar each owner could move would
	 * mean nothing to the stranger reading it.
	 */
	const TIER_MIN = 90;

	/** Transient holding the public snapshot of the score report. */
	const CACHE_KEY = 'agentimus_scorecard';

	/**
	 * The scorecard's default accent — Agentimus green (Heera's pick,
	 * 2026-07-21). A fixed, predictable default: the earlier theme-palette
	 * auto-pick made the surfaces wear whatever a theme declared, which read
	 * as surprising more often than as thoughtful. Owners match their brand
	 * with the colour settings instead.
	 */
	const ACCENT = '#2f7a4c';

	/** @var Settings */
	private $settings;

	/** Resolved font path, memoised per request ('' = none found). */
	private static $font = null;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/** Hook up the routes and the cache invalidation. */
	public function register() {
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
		// The snapshot must never outlive the content it grades: drop it with
		// every generated-file flush (saves, settings changes, term edits …).
		add_action( 'agentimus_cache_flushed', array( self::class, 'flush_snapshot' ) );
	}

	/**
	 * The public base path — the owner's setting (Settings → Share → Address),
	 * the default '/ai-readiness' when unset, and the filter gets the last
	 * word. Normalised: leading slash, no trailing slash.
	 *
	 * @return string
	 */
	public function path() {
		$slug = trim( (string) $this->settings->get( 'scorecard_path', 'ai-readiness' ), '/' );
		$path = '' === $slug ? self::PATH : '/' . $slug;

		/**
		 * Filter the scorecard's public base path.
		 *
		 * @param string $path Leading-slash path, e.g. '/ai-readiness'.
		 */
		$path = apply_filters( 'agentimus_scorecard_path', $path );
		$path = '/' . trim( (string) $path, '/' );
		return '/' === $path ? self::PATH : $path;
	}

	/** Drop the cached snapshot (hooked to agentimus_cache_flushed). */
	public static function flush_snapshot() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Front controller: serve the page / badge / card when the feature is on.
	 * Same guard set as Endpoints::route(); with the switch off, none of these
	 * paths exist and WordPress 404s them exactly as before.
	 */
	public function route() {
		if ( is_admin() || is_feed() || is_embed()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		if ( ! $this->settings->enabled( 'share_scorecard' ) ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = '/' . ltrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$base = $this->path();

		$display = (string) $this->settings->get( 'scorecard_display', 'score' );
		$style   = (string) $this->settings->get( 'scorecard_style', 'auto' );
		$page_on = (bool) $this->settings->get( 'scorecard_page_enabled', true );
		$opts    = array(
			'shape' => (string) $this->settings->get( 'scorecard_badge_shape', 'rectangle' ),
			'bg'    => (string) $this->settings->get( 'scorecard_badge_bg', '' ),
			'fg'    => (string) $this->settings->get( 'scorecard_badge_fg', '' ),
			'name'  => (bool) $this->settings->get( 'scorecard_show_name', true ),
		);

		// Admin-only preview overrides (?d=&s=&sh=&bg=&fg=): the settings screen
		// re-fetches the badge the instant a choice changes — before the autosave
		// lands — so rendering from SAVED values would always show one change
		// behind. Strictly signed-in-owner: honoring these publicly would let
		// anyone un-hide a tier-mode site's number with ?d=score.
		if ( current_user_can( 'manage_options' ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only preview parameters, no state change.
			$d  = isset( $_GET['d'] ) ? sanitize_key( wp_unslash( $_GET['d'] ) ) : '';
			$s  = isset( $_GET['s'] ) ? sanitize_key( wp_unslash( $_GET['s'] ) ) : '';
			$sh = isset( $_GET['sh'] ) ? sanitize_key( wp_unslash( $_GET['sh'] ) ) : '';
			// Colours travel as bare 6-hex (no #) to dodge URL-encoding noise.
			$bg = isset( $_GET['bg'] ) ? sanitize_key( wp_unslash( $_GET['bg'] ) ) : '';
			$fg = isset( $_GET['fg'] ) ? sanitize_key( wp_unslash( $_GET['fg'] ) ) : '';
			$nm = isset( $_GET['nm'] ) ? sanitize_key( wp_unslash( $_GET['nm'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( in_array( $d, Settings::SCORECARD_DISPLAYS, true ) ) {
				$display = $d;
			}
			if ( in_array( $s, Settings::SCORECARD_STYLES, true ) ) {
				$style = $s;
			}
			if ( in_array( $sh, Settings::SCORECARD_SHAPES, true ) ) {
				$opts['shape'] = $sh;
			}
			if ( preg_match( '/^[0-9a-f]{6}$/', $bg ) ) {
				$opts['bg'] = '#' . $bg;
			}
			if ( preg_match( '/^[0-9a-f]{6}$/', $fg ) ) {
				$opts['fg'] = '#' . $fg;
			}
			if ( '0' === $nm || '1' === $nm ) {
				$opts['name'] = '1' === $nm;
			}
			$pe = isset( $_GET['pe'] ) ? sanitize_key( wp_unslash( $_GET['pe'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview parameter.
			if ( '0' === $pe || '1' === $pe ) {
				$page_on = '1' === $pe;
			}
		}

		$accent = $this->accent();
		// The owner's custom background is the ONE colour scheme — every public
		// surface follows it: badge value, card accent, and the page's ring and
		// healthy bars alike. (Includes a live admin preview override, so the
		// three previews always agree.)
		if ( isset( $opts['bg'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $opts['bg'] ) ) {
			$accent = strtolower( (string) $opts['bg'] );
		}

		// Short cache windows on purpose (the shields.io convention): these
		// surfaces change when the owner flips a display mode or the score
		// moves, and an embedded badge that shows yesterday's choice for an
		// hour reads as broken. Five minutes still absorbs any hotlink storm —
		// the bodies are transient-backed and cheap.
		if ( $base . '/badge.svg' === $path ) {
			$this->send( self::badge_svg( $this->snapshot(), $display, $style, $accent, $opts ), 'image/svg+xml', 300 );
		}

		if ( $base . '/card.png' === $path ) {
			$png = $this->og_png( $this->snapshot(), $display, $style, $accent, $opts );
			if ( '' !== $png ) {
				$this->send( $png, 'image/png', 300 );
			}
			return; // No GD / no font: let it 404 rather than serve a broken image.
		}

		if ( $base === $path || $base . '/' === $path ) {
			// The page has its own switch beneath the master: off, only the
			// badge and card exist and this address 404s like any other.
			if ( ! $page_on ) {
				return;
			}
			// The owner's real content always wins: if an actual page lives at
			// this path, stand down entirely (the path is filterable instead).
			if ( url_to_postid( home_url( trailingslashit( $base ) ) ) || url_to_postid( home_url( $base ) ) ) {
				return;
			}
			$ctx              = $this->page_context( $base );
			$ctx['show_name'] = ! isset( $opts['name'] ) || false !== $opts['name'];
			$this->send( self::page_html( $this->snapshot(), $ctx, $display, $style, $accent ), 'text/html', 60 );
		}
	}

	/* ---------------------------------------------------------------------- *
	 *  Data
	 * ---------------------------------------------------------------------- */

	/**
	 * The public subset of the score report, cached for an hour. Only what the
	 * public surfaces print — never the worklist, actions or per-page details;
	 * show the trophy, not the laundry.
	 *
	 * @return array{score:int,band:string,ready:bool,blocked:bool,rungs:array,generated:int}
	 */
	public function snapshot() {
		$snap = get_transient( self::CACHE_KEY );
		if ( is_array( $snap ) && isset( $snap['score'], $snap['rungs'] ) ) {
			return $snap;
		}

		$report = ( new Score( $this->settings ) )->report();
		$rungs  = array();
		foreach ( $report['rungs'] as $r ) {
			$rungs[] = array(
				'key'   => $r['key'],
				'label' => $r['label'],
				'score' => $r['score'], // int|null (null = not measured).
			);
		}

		$snap = array(
			'score'     => (int) $report['score'],
			'band'      => (string) $report['band'],
			'ready'     => ! empty( $report['ready'] ),
			'blocked'   => ! empty( $report['blocked'] ),
			'rungs'     => $rungs,
			'generated' => time(),
		);
		set_transient( self::CACHE_KEY, $snap, HOUR_IN_SECONDS );
		return $snap;
	}

	/** Whether a score earns the 'tier' display's "AI-Ready" mark. Pure. */
	public static function tier_earned( $score ) {
		return (int) $score >= self::TIER_MIN;
	}

	/**
	 * The accent currently in effect, for the admin preview swatches — so the
	 * colour pickers' "automatic" state shows the real resolved colour, not a
	 * hardcoded guess.
	 */
	public function current_accent() {
		return $this->accent();
	}

	/**
	 * The accent the public surfaces wear: the filter gets the first word,
	 * else the Agentimus green. (A custom badge background overrides this in
	 * the route for every surface.)
	 */
	private function accent() {
		$accent = apply_filters( 'agentimus_scorecard_accent', '' );
		if ( is_string( $accent ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ) {
			return strtolower( $accent );
		}
		return self::ACCENT;
	}

	/** '#abc'/'#aabbcc' → [r,g,b], or null when it isn't one. Pure. */
	private static function hex_rgb( $hex ) {
		if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex, $m ) ) {
			return null;
		}
		$h = $m[1];
		if ( 3 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
	}

	/* ---------------------------------------------------------------------- *
	 *  Badge (SVG) — fixed shape, adaptive colours. The shape is the brand:
	 *  a badge that looks the same everywhere is what makes it recognisable,
	 *  so styles change colours only, never the layout.
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the badge. Pure.
	 *
	 * @param array  $snap    Snapshot (only 'score' is used).
	 * @param string $display 'score' | 'tier'.
	 * @param string $style   'auto' | 'light' | 'dark' — 'auto' renders light:
	 *                        an <img> can't reliably follow the page's theme.
	 * @param string $accent  '#rrggbb'.
	 * @param array  $opts    Optional owner tuning: 'shape' rectangle|rounded|pill,
	 *                        'bg'/'fg' custom value-segment colours ('' = automatic).
	 *                        Custom colours are the owner's explicit brand call, so
	 *                        they bypass the accent picker's gates — but never the
	 *                        neutral "in progress" state, which must stay visually
	 *                        distinct from the earned mark.
	 * @return string SVG document.
	 */
	public static function badge_svg( array $snap, $display, $style, $accent, array $opts = array() ) {
		$score  = isset( $snap['score'] ) ? (int) $snap['score'] : 0;
		$accent = preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $accent ) ? $accent : self::ACCENT;

		$shape = isset( $opts['shape'] ) ? (string) $opts['shape'] : 'rectangle';
		$rx    = 'pill' === $shape ? 14 : ( 'rounded' === $shape ? 9 : 4 );

		$custom_bg = isset( $opts['bg'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $opts['bg'] ) ? strtolower( $opts['bg'] ) : '';
		$value_fg  = isset( $opts['fg'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $opts['fg'] ) ? strtolower( $opts['fg'] ) : '#ffffff';

		$label = __( 'AI READINESS', 'agentimus' );
		if ( 'tier' === $display ) {
			$earned   = self::tier_earned( $score );
			$value    = $earned ? __( 'READY ✓', 'agentimus' ) : __( 'IN PROGRESS', 'agentimus' );
			$value_bg = $earned ? ( '' !== $custom_bg ? $custom_bg : $accent ) : '#6b6558';
			$title    = $earned
				? __( 'AI readiness: AI-Ready', 'agentimus' )
				: __( 'AI readiness: in progress', 'agentimus' );
		} else {
			$value    = $score . '/100';
			$value_bg = '' !== $custom_bg ? $custom_bg : $accent;
			/* translators: %d: the score out of 100. */
			$title = sprintf( __( 'AI readiness: %d out of 100', 'agentimus' ), $score );
		}

		if ( 'dark' === $style ) {
			$left_bg   = '#1b1913';
			$left_text = '#f3f0e7';
			$stroke    = '';
		} else {
			$left_bg   = '#f3f0e7';
			$left_text = '#1b1913';
			$stroke    = '<rect x="0.5" y="0.5" width="%TOTAL_LESS%" height="27" rx="' . $rx . '" fill="none" stroke="#d8d2c2"/>';
		}

		// Verdana metrics at 11px are ~6.9px/char for caps+digits; textLength
		// pins the rendered width so the estimate can't drift per platform.
		// The pill's fully-round ends eat into the text lanes, so it carries
		// wider end padding to keep the first and last glyphs off the curve.
		$end_pad  = 'pill' === $shape ? 10 : 0;
		$label_tw = (int) round( mb_strlen( $label ) * 6.9 );
		$value_tw = (int) round( mb_strlen( $value ) * 6.9 );
		$icon_x   = 7 + $end_pad;
		$text_x   = $icon_x + 24; // 18px brand tile + 6px gap.
		$left_w   = $text_x + $label_tw + 8;
		$value_w  = $value_tw + 18 + $end_pad;
		$total    = $left_w + $value_w;

		$stroke = str_replace( '%TOTAL_LESS%', (string) ( $total - 1 ), $stroke );

		// The brand mark, 18px — the same tile as the admin menu and the card
		// (dark rounded square, teal ring, paper A, amber crossbar), scaled
		// from its own 24-unit grid.
		$mark = '<g transform="translate(' . $icon_x . ',5) scale(0.75)">'
			. '<rect x="1.2" y="1.2" width="21.6" height="21.6" rx="6" fill="#1b1913" stroke="#146b64" stroke-width="1.5"/>'
			. '<path d="M7.35 17.3 12 6.7 16.65 17.3" stroke="#f3f0e7" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
			. '<path d="M9.5 13H14.5" stroke="#ad7b18" stroke-width="1.9" stroke-linecap="round"/>'
			. '</g>';

		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $total . '" height="28" role="img" aria-label="' . esc_attr( $title ) . '">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<clipPath id="r"><rect width="' . $total . '" height="28" rx="' . $rx . '"/></clipPath>'
			. '<g clip-path="url(#r)">'
			. '<rect width="' . $left_w . '" height="28" fill="' . esc_attr( $left_bg ) . '"/>'
			. '<rect x="' . $left_w . '" width="' . $value_w . '" height="28" fill="' . esc_attr( $value_bg ) . '"/>'
			. '</g>'
			. $stroke
			. $mark
			. '<g font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">'
			. '<text x="' . $text_x . '" y="18.5" fill="' . esc_attr( $left_text ) . '" textLength="' . $label_tw . '">' . esc_html( $label ) . '</text>'
			. '<text x="' . ( $left_w + 9 ) . '" y="18.5" fill="' . esc_attr( $value_fg ) . '" font-weight="bold" textLength="' . $value_tw . '">' . esc_html( $value ) . '</text>'
			. '</g>'
			. '</svg>';
	}

	/* ---------------------------------------------------------------------- *
	 *  Page (HTML)
	 * ---------------------------------------------------------------------- */

	/** Everything the page needs from WordPress, gathered by the route glue. */
	private function page_context( $base ) {
		$snap      = $this->snapshot();
		$generated = isset( $snap['generated'] ) ? (int) $snap['generated'] : time();
		return array(
			'site'    => (string) get_bloginfo( 'name' ),
			'host'    => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'home'    => home_url( '/' ),
			'url'     => home_url( $base . '/' ),
			'icon'    => function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 64 ) : '',
			'badge'   => home_url( $base . '/badge.svg' ),
			'og'      => self::og_ready() ? home_url( $base . '/card.png' ) : '',
			'updated' => function_exists( 'date_i18n' )
				? date_i18n( (string) get_option( 'date_format', 'F j, Y' ), $generated )
				: gmdate( 'F j, Y', $generated ),
			'plugin'  => 'https://wordpress.org/plugins/agentimus/',
		);
	}

	/**
	 * The public page. Pure: everything WordPress-shaped arrives via $ctx.
	 * Standalone document on purpose — plugin markup dropped into arbitrary
	 * themes is how public pages break on exactly the sites nobody tested; it
	 * borrows the site's identity (name, icon, accent) instead of its theme.
	 *
	 * @param array  $snap    Snapshot.
	 * @param array  $ctx     See page_context().
	 * @param string $display 'score' | 'tier'.
	 * @param string $style   'auto' | 'light' | 'dark'.
	 * @param string $accent  '#rrggbb'.
	 * @return string HTML document.
	 */
	public static function page_html( array $snap, array $ctx, $display, $style, $accent ) {
		$score  = (int) $snap['score'];
		$band   = (string) $snap['band'];
		$tier   = 'tier' === $display;
		$earned = self::tier_earned( $score );
		$accent = preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $accent ) ? $accent : self::ACCENT;

		// The owner may keep the site's NAME off the public surfaces (a site
		// name is often a person); the domain stands in — public by definition.
		$show_name = ! isset( $ctx['show_name'] ) || $ctx['show_name'];
		$who       = $show_name ? $ctx['site'] : ( isset( $ctx['host'] ) && '' !== $ctx['host'] ? $ctx['host'] : $ctx['site'] );

		if ( $tier ) {
			$headline = $earned ? __( 'AI-Ready', 'agentimus' ) : __( 'Working toward AI-Ready', 'agentimus' );
			$og_title = $who . ' — ' . $headline;
		} else {
			/* translators: %d: the score out of 100. */
			$headline = sprintf( __( '%d/100', 'agentimus' ), $score );
			/* translators: 1: site name (or domain), 2: score. */
			$og_title = sprintf( __( '%1$s — AI readiness %2$d/100', 'agentimus' ), $who, $score );
		}
		$og_desc = __( 'How ready this site is for AI assistants — findable, readable, trusted, optimized and cited.', 'agentimus' );

		// The ring gauge: same geometry as the dashboard's (r=52 on a 116 box).
		$circ   = 2 * M_PI * 52;
		$offset = $circ * ( 1 - max( 0, min( 100, $score ) ) / 100 );

		$rows = '';
		foreach ( $snap['rungs'] as $r ) {
			$val  = isset( $r['score'] ) && null !== $r['score'] ? (int) $r['score'] : null;
			$tone = null === $val ? 'na' : ( $val >= 80 ? 'good' : ( $val >= 50 ? 'warn' : 'bad' ) );
			// Tier mode hides EVERY number — including the bar widths, which would
			// otherwise leak the per-rung scores through the inline styles. A full
			// bar in the rung's tone says how it's doing without saying how much.
			$pct  = null === $val ? 0 : ( $tier ? 100 : $val );
			$num  = ( ! $tier && null !== $val ) ? '<span class="num">' . esc_html( (string) $val ) . '%</span>' : '';
			$na   = null === $val ? '<span class="num">' . esc_html__( 'not measured', 'agentimus' ) . '</span>' : '';
			$rows .= '<li data-tone="' . esc_attr( $tone ) . '"><span class="name">' . esc_html( $r['label'] ) . '</span>'
				. '<span class="bar" aria-hidden="true"><span style="width:' . $pct . '%"></span></span>' . $num . $na . '</li>';
		}

		$meta_og = '';
		if ( '' !== $ctx['og'] ) {
			$meta_og = '<meta property="og:image" content="' . esc_url( $ctx['og'] ) . '">'
				. '<meta name="twitter:card" content="summary_large_image">';
		}
		$icon = '' !== $ctx['icon'] ? '<img class="icon" src="' . esc_url( $ctx['icon'] ) . '" alt="" width="28" height="28">' : '';

		$scheme = 'auto' === $style ? 'light dark' : $style;

		// In tier mode the big display is the mark, not the ring — no number leaks.
		if ( $tier ) {
			$hero = '<p class="tier" data-earned="' . ( $earned ? '1' : '0' ) . '">' . esc_html( $headline ) . '</p>';
		} else {
			$hero = '<div class="gauge" role="img" aria-label="' . esc_attr( $og_title ) . '">'
				. '<svg viewBox="0 0 116 116"><circle class="track" cx="58" cy="58" r="52"/>'
				. '<circle class="fill" cx="58" cy="58" r="52" stroke-dasharray="' . esc_attr( (string) round( $circ, 2 ) ) . '" stroke-dashoffset="' . esc_attr( (string) round( $offset, 2 ) ) . '"/></svg>'
				// One inner span = one grid item, so the number and its /100
				// share a baseline instead of stacking as two centred rows.
				. '<span class="n"><span>' . esc_html( (string) $score ) . '<small>/ 100</small></span></span></div>'
				. '<p class="band">' . esc_html( $band ) . '</p>';
		}

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="color-scheme" content="' . esc_attr( $scheme ) . '">'
			. '<title>' . esc_html( $og_title ) . '</title>'
			. '<meta name="description" content="' . esc_attr( $og_desc ) . '">'
			. '<meta property="og:title" content="' . esc_attr( $og_title ) . '">'
			. '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">'
			. '<meta property="og:url" content="' . esc_url( $ctx['url'] ) . '">'
			. '<meta property="og:site_name" content="' . esc_attr( $who ) . '">'
			. '<meta property="og:type" content="website">'
			. $meta_og
			. '<style>'
			. ':root{--accent:' . $accent . ';--paper:#f3f0e7;--ink:#1b1913;--muted:#6b6558;--line:#d8d2c2;--good:#2f7a4c;--warn:#ad7b18;--bad:#b93c2b;--na:#9a938a}'
			. ( 'dark' === $style ? ':root{--paper:#191712;--ink:#f3f0e7;--muted:#9a938a;--line:#39352b}' : '' )
			. ( 'auto' === $style ? '@media(prefers-color-scheme:dark){:root{--paper:#191712;--ink:#f3f0e7;--muted:#9a938a;--line:#39352b}}' : '' )
			. '*{box-sizing:border-box;margin:0}body{background:var(--paper);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;display:grid;min-height:100vh;place-items:center;padding:24px}'
			. '.card{width:min(460px,100%);border:1px solid var(--line);border-radius:10px;padding:36px 32px;text-align:center}'
			. '.site{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:26px}.site a{color:inherit;text-decoration:none;font-weight:600}.icon{border-radius:6px}'
			. '.what{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:18px}'
			. '.gauge{position:relative;width:150px;margin:0 auto}.gauge svg{width:100%;transform:rotate(-90deg)}.gauge circle{fill:none;stroke-width:9}.gauge .track{stroke:var(--line)}.gauge .fill{stroke:var(--accent);stroke-linecap:round}'
			. '.gauge .n{position:absolute;inset:0;display:grid;place-items:center;font-size:40px;font-weight:700}.gauge .n small{font-size:15px;font-weight:400;color:var(--muted);margin-left:6px}'
			. '.band{margin-top:10px;font-weight:600}'
			. '.tier{font-size:30px;font-weight:700;margin:26px 0}.tier[data-earned="1"]{color:var(--accent)}.tier[data-earned="0"]{color:var(--muted);font-size:22px}'
			. 'ol{list-style:none;padding:0;margin:30px 0 0;text-align:left}li{display:grid;grid-template-columns:88px 1fr auto;gap:10px;align-items:center;padding:7px 0;font-size:14px}'
			. '.bar{height:6px;border-radius:3px;background:var(--line);overflow:hidden}.bar span{display:block;height:100%;border-radius:3px;background:var(--accent)}'
			// Healthy bars wear the accent (the default span colour), same rule
			// as the share card — warn/bad keep their semantic tones.
			. 'li[data-tone="warn"] .bar span{background:var(--warn)}li[data-tone="bad"] .bar span{background:var(--bad)}'
			. '.num{color:var(--muted);font-size:12px;font-variant-numeric:tabular-nums}'
			. 'footer{margin-top:30px;padding-top:16px;border-top:1px solid var(--line);font-size:12px;color:var(--muted)}footer a{color:inherit}'
			. '</style></head><body>'
			. '<main class="card">'
			. '<p class="site">' . $icon . '<a href="' . esc_url( $ctx['home'] ) . '">' . esc_html( $who ) . '</a></p>'
			. '<p class="what">' . esc_html__( 'AI readiness', 'agentimus' ) . '</p>'
			. $hero
			. '<ol>' . $rows . '</ol>'
			. '<footer>' . sprintf(
				/* translators: 1: link to the Agentimus plugin page, 2: date. */
				esc_html__( 'Measured by %1$s · Updated %2$s', 'agentimus' ),
				'<a href="' . esc_url( $ctx['plugin'] ) . '" rel="noopener">Agentimus</a>',
				esc_html( $ctx['updated'] )
			) . '</footer>'
			. '</main></body></html>';
	}

	/* ---------------------------------------------------------------------- *
	 *  Social card (PNG) — needs GD with FreeType and a TTF to draw with. The
	 *  plugin bundles no font (yet); it borrows one a default theme ships.
	 *  Honest degradation: without both, the page simply omits og:image and
	 *  the link unfurls as text — nothing breaks.
	 * ---------------------------------------------------------------------- */

	/** Whether the social card can render on this server. */
	public static function og_ready() {
		return function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagettftext' )
			&& '' !== self::find_font();
	}

	/**
	 * A TTF to draw the card with: filter → a bundled font (future) → a font
	 * a bundled default theme ships (Inter / DM Sans). '' when none exists.
	 */
	public static function find_font() {
		if ( null !== self::$font ) {
			return self::$font;
		}
		$font = apply_filters( 'agentimus_scorecard_font', '' );
		if ( is_string( $font ) && '' !== $font && file_exists( $font ) ) {
			self::$font = $font;
			return $font;
		}
		if ( defined( 'AGENTIMUS_DIR' ) && file_exists( AGENTIMUS_DIR . 'assets/fonts/scorecard.ttf' ) ) {
			self::$font = AGENTIMUS_DIR . 'assets/fonts/scorecard.ttf';
			return self::$font;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			foreach ( array( '/themes/*/assets/fonts/inter/*.ttf', '/themes/*/assets/fonts/dm-sans/DMSans-Regular.ttf' ) as $pattern ) {
				$hits = glob( WP_CONTENT_DIR . $pattern );
				if ( ! empty( $hits ) ) {
					self::$font = $hits[0];
					return self::$font;
				}
			}
		}
		self::$font = '';
		return '';
	}

	/**
	 * Render the 1200×630 social-preview PNG — a report-style card: brand
	 * header, site name with chips, the rungs as labelled bars on the left,
	 * a ring gauge on the right, and a footer with the site's domain in a
	 * pill. '' when the server can't render (no GD/FreeType, no font).
	 *
	 * 'auto' renders DARK here on purpose: a social feed is a mixed,
	 * unknowable background and the dark card holds its own on both.
	 * The badge's colour configuration flows in via $opts — a custom
	 * background becomes the card's accent (ring, number, chip, pill),
	 * a custom text colour the pill's text. Tier mode leaks no numbers:
	 * full tone bars, no per-rung values, no sweep angle to reverse.
	 */
	private function og_png( array $snap, $display, $style, $accent, array $opts = array() ) {
		if ( ! self::og_ready() ) {
			return '';
		}
		$font  = self::find_font();
		$light = 'light' === $style;

		if ( isset( $opts['bg'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $opts['bg'] ) ) {
			$accent = strtolower( $opts['bg'] );
		}

		$img = imagecreatetruecolor( 1200, 630 );
		$rgb = static function ( $hex ) use ( $img ) {
			$c = self::hex_rgb( $hex );
			$c = null === $c ? array( 47, 122, 76 ) : $c;
			return imagecolorallocate( $img, $c[0], $c[1], $c[2] );
		};

		$c_bg    = $rgb( $light ? '#f3f0e7' : '#191712' );
		$c_track = $rgb( $light ? '#ddd7c6' : '#2b2720' );
		$c_ink   = $rgb( $light ? '#1b1913' : '#f3f0e7' );
		$c_mut   = $rgb( '#8a8374' );
		$c_acc   = $rgb( $accent );
		$c_warn  = $rgb( $light ? '#ad7b18' : '#d09a2f' );
		$c_bad   = $rgb( $light ? '#b93c2b' : '#d9604e' );

		imagefilledrectangle( $img, 0, 0, 1200, 630, $c_bg );

		// Faint grid on the dark card — texture, never content.
		if ( ! $light ) {
			$grid = imagecolorallocatealpha( $img, 243, 240, 231, 122 );
			for ( $g = 60; $g < 1200; $g += 60 ) {
				imageline( $img, $g, 0, $g, 630, $grid );
			}
			for ( $g = 60; $g < 630; $g += 60 ) {
				imageline( $img, 0, $g, 1200, $g, $grid );
			}
		}

		// ── Header: the real brand tile, product, report label. The text block
		// is pinned to the tile's height: the title's cap tops align with the
		// tile's top edge, the label's baseline with its bottom edge. Sized to
		// support the report, not compete with the score — the score is the hero.
		self::og_logo( $img, 80, 56, 52 );
		self::og_bold( $img, 23, 150, 76, $c_ink, $font, 'Agentimus' );
		imagettftext( $img, 13, 0, 150, 108, $c_mut, $font, strtoupper( __( 'AI-readiness scorecard', 'agentimus' ) ) );

		// ── The site: name + domain — or just the domain when the owner keeps
		// the name private (a site name is often a person; the domain is
		// public by definition).
		$host      = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$show_name = ! isset( $opts['name'] ) || false !== $opts['name'];
		// The name in ink with the domain as a quiet outlined chip beneath it —
		// "site: example.com" (Gemini's suggestion, Heera's pick). With the
		// name hidden the domain IS the identity, so it takes the name's own
		// slot at full size and the chip (which would just repeat it) goes.
		if ( $show_name ) {
			$name = (string) get_bloginfo( 'name' );
			$name = mb_strlen( $name ) > 26 ? mb_substr( $name, 0, 25 ) . '…' : $name;
			self::og_bold( $img, 36, 80, 230, $c_ink, $font, $name );

			// The domain chip, with the favicon riding INSIDE its border —
			// a circular crop, so any square icon sits naturally in the pill.
			$chip_y = 254;
			$fav    = self::og_favicon_img( 26 );
			$pad_l  = null !== $fav ? 42 : 20;
			$cb     = imagettfbbox( 15, 0, $font, $host );
			$cw     = ( $cb[2] - $cb[0] ) + $pad_l + 18;
			self::og_rrect( $img, 80, $chip_y, 80 + $cw, $chip_y + 38, 19, $c_track );
			self::og_rrect( $img, 82, $chip_y + 2, 78 + $cw, $chip_y + 36, 17, $c_bg );
			if ( null !== $fav ) {
				imagecopy( $img, $fav, 88, $chip_y + 6, 0, 0, 26, 26 );
				imagedestroy( $fav );
			}
			imagettftext( $img, 15, 0, 80 + $pad_l, $chip_y + 25, $c_mut, $font, $host );
		} else {
			// The domain is the primary identity — the favicon matches its size.
			$fav = self::og_favicon_img( 44 );
			$hx  = 80;
			if ( null !== $fav ) {
				imagecopy( $img, $fav, 80, 224, 0, 0, 44, 44 );
				imagedestroy( $fav );
				$hx = 138;
			}
			self::og_bold( $img, 36, $hx, 262, $c_ink, $font, $host );
		}

		$score  = (int) $snap['score'];
		$earned = self::tier_earned( $score );
		$tier   = 'tier' === $display;

		// ── Left column: the rungs as labelled bars (tier mode: full tone
		// bars, no numbers — bar lengths are numbers too).
		$y = 360;
		foreach ( $snap['rungs'] as $r ) {
			$val  = isset( $r['score'] ) && null !== $r['score'] ? (int) $r['score'] : null;
			// Healthy bars wear the configured accent — same rule as the public
			// page — so the card is one palette; warn/bad keep their semantic
			// tones (a warning must never dress in the celebration colour).
			$tone = null === $val ? $c_mut : ( $val >= 80 ? $c_acc : ( $val >= 50 ? $c_warn : $c_bad ) );
			// The bar is centred on the label's optical middle (≈2px above the
			// row's midline for mixed-case text), not on the baseline row.
			imagettftext( $img, 17, 0, 80, $y + 6, $c_ink, $font, (string) $r['label'] );
			self::og_rrect( $img, 270, $y - 8, 640, $y + 4, 6, $c_track );
			$pct = null === $val ? 0 : ( $tier ? 100 : $val );
			if ( $pct > 0 ) {
				self::og_rrect( $img, 270, $y - 8, 270 + (int) round( 3.70 * $pct ), $y + 4, 6, $tone );
			}
			if ( ! $tier && null !== $val ) {
				imagettftext( $img, 15, 0, 662, $y + 5, $c_mut, $font, (string) $val );
			}
			$y += 38;
		}

		// ── Right: the ring gauge — the card's hero. Everything lives inside
		// the ring: the label, the big number, the scale, and the band demoted
		// to small muted text. The sweep wears a subtle gradient from the
		// accent to a lighter cousin of itself — always derived from the
		// CONFIGURED colour, never a hardcoded second palette.
		$cx        = 940;
		$cy        = 330;
		$acc_rgb   = self::hex_rgb( $accent );
		$acc_rgb   = null === $acc_rgb ? self::hex_rgb( self::ACCENT ) : $acc_rgb;
		$acc_light = array(
			(int) round( $acc_rgb[0] + ( 255 - $acc_rgb[0] ) * 0.30 ),
			(int) round( $acc_rgb[1] + ( 255 - $acc_rgb[1] ) * 0.30 ),
			(int) round( $acc_rgb[2] + ( 255 - $acc_rgb[2] ) * 0.30 ),
		);
		if ( $tier ) {
			self::og_ring( $img, $cx, $cy, 160, 126, $c_track, $earned ? $acc_rgb : null, $acc_light, $earned ? 100 : 0, $c_bg );
			if ( $earned ) {
				self::og_center( $img, 40, $cx, $cy + 14, $c_acc, $font, __( 'AI-Ready', 'agentimus' ), true );
			} else {
				self::og_center( $img, 22, $cx, $cy - 4, $c_mut, $font, __( 'Working toward', 'agentimus' ) );
				self::og_center( $img, 22, $cx, $cy + 32, $c_mut, $font, __( 'AI-Ready', 'agentimus' ) );
			}
		} else {
			self::og_ring( $img, $cx, $cy, 160, 126, $c_track, $acc_rgb, $acc_light, max( 0, min( 100, $score ) ), $c_bg );
			// One composite on one baseline — "91/100", the badge's own reading:
			// the number big in the accent, the scale small and muted beside it.
			// Three pieces with EQUAL gaps — "91 / 100". A space baked into the
			// suffix string lies: font side-bearings make the two sides uneven.
			$num   = (string) $score;
			$gap_l = 12;
			$gap_r = 9; // The slash leans right; a slightly tighter right gap reads even.
			$nb  = imagettfbbox( 84, 0, $font, $num );
			$slb = imagettfbbox( 26, 0, $font, '/' );
			$hb  = imagettfbbox( 26, 0, $font, '100' );
			// The faux-bold second pass widens the number by its offset — count
			// it, or the slash sits closer to the number than to the 100.
			$nw  = ( $nb[2] - $nb[0] ) + max( 1, (int) round( 84 / 30 ) );
			$slw = $slb[2] - $slb[0];
			$tw  = $nw + $gap_l + $slw + $gap_r + ( $hb[2] - $hb[0] );
			$x0  = (int) ( $cx - $tw / 2 );
			self::og_bold( $img, 84, $x0, $cy + 30, $c_acc, $font, $num );
			imagettftext( $img, 26, 0, $x0 + $nw + $gap_l, $cy + 30, $c_mut, $font, '/' );
			imagettftext( $img, 26, 0, $x0 + $nw + $gap_l + $slw + $gap_r, $cy + 30, $c_mut, $font, '100' );
			// The band sits below the ring, in the accent — the verdict line.
			self::og_center( $img, 23, $cx, $cy + 160 + 38, $c_acc, $font, (string) $snap['band'], true );
		}

		// ── Footer: one line — the question in ink, then the credit smaller
		// and dimmed on the same baseline. No domain pill — the domain already
		// sits under the site name, and saying it twice is noise.
		imageline( $img, 80, 548, 1120, 548, $c_track );
		$question = __( 'What does your site score?', 'agentimus' );
		$qb       = imagettfbbox( 19, 0, $font, $question );
		self::og_bold( $img, 19, 80, 597, $c_ink, $font, $question );
		imagettftext( $img, 14, 0, 80 + ( $qb[2] - $qb[0] ) + 18, 597, $c_mut, $font, __( 'Measured by Agentimus — free on WordPress.org', 'agentimus' ) );

		ob_start();
		imagepng( $img );
		imagedestroy( $img );
		return (string) ob_get_clean();
	}

	/**
	 * The brand tile — the same mark as the menu icon and the meta-box title
	 * (dark rounded square, teal ring, paper "A", amber crossbar) — drawn on a
	 * 4× off-screen canvas and resampled down, because GD's thick lines are
	 * jagged at target size and the supersample smooths them.
	 *
	 * @param resource|\GdImage $img  Destination image.
	 * @param int               $x    Left.
	 * @param int               $y    Top.
	 * @param int               $size Tile edge in destination pixels.
	 */
	private static function og_logo( $img, $x, $y, $size ) {
		$dim = $size * 4;
		$u   = $dim / 24; // The mark is drawn on the icon's own 24-unit grid.
		$t   = imagecreatetruecolor( $dim, $dim );
		imagealphablending( $t, false );
		imagesavealpha( $t, true );
		imagefill( $t, 0, 0, imagecolorallocatealpha( $t, 0, 0, 0, 127 ) );
		imagealphablending( $t, true );

		$ink   = imagecolorallocate( $t, 27, 25, 19 );
		$teal  = imagecolorallocate( $t, 20, 107, 100 );
		$paper = imagecolorallocate( $t, 243, 240, 231 );
		$amber = imagecolorallocate( $t, 173, 123, 24 );

		self::og_rrect( $t, 0, 0, $dim - 1, $dim - 1, (int) round( 6 * $u ), $teal );
		$in = (int) round( 1.6 * $u );
		self::og_rrect( $t, $in, $in, $dim - 1 - $in, $dim - 1 - $in, (int) round( 5 * $u ), $ink );

		// The "A": two legs in paper, the crossbar in amber, round caps.
		$legs = array(
			array( 7.35, 17.3, 12.0, 6.7, $paper, 2.0 ),
			array( 12.0, 6.7, 16.65, 17.3, $paper, 2.0 ),
			array( 9.5, 13.0, 14.5, 13.0, $amber, 1.9 ),
		);
		foreach ( $legs as $l ) {
			$w = max( 1, (int) round( $l[5] * $u ) );
			imagesetthickness( $t, $w );
			imageline( $t, (int) round( $l[0] * $u ), (int) round( $l[1] * $u ), (int) round( $l[2] * $u ), (int) round( $l[3] * $u ), $l[4] );
			imagefilledellipse( $t, (int) round( $l[0] * $u ), (int) round( $l[1] * $u ), $w, $w, $l[4] );
			imagefilledellipse( $t, (int) round( $l[2] * $u ), (int) round( $l[3] * $u ), $w, $w, $l[4] );
		}
		imagesetthickness( $t, 1 );

		imagecopyresampled( $img, $t, $x, $y, 0, 0, $size, $size, $dim, $dim );
		imagedestroy( $t );
	}

	/**
	 * The site icon (favicon) as a CIRCLE with a hairline ring, on a
	 * transparent canvas ready to imagecopy anywhere. Built at 4× and
	 * downsampled so the circular edge is smooth. Reads the attachment file
	 * from DISK — never an HTTP fetch — and returns null for missing icons or
	 * formats GD can't decode (SVG, ICO): the caller keeps its no-icon layout.
	 *
	 * @param int $size Edge in destination pixels.
	 * @return resource|\GdImage|null
	 */
	private static function og_favicon_img( $size ) {
		$id = (int) get_option( 'site_icon' );
		if ( $id <= 0 || ! function_exists( 'get_attached_file' ) ) {
			return null;
		}
		$path = (string) get_attached_file( $id );
		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local attachment path, never a URL.
		if ( false === $data ) {
			return null;
		}
		$src = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- undecodable icon formats must degrade silently.
		if ( ! $src ) {
			return null;
		}

		$d = $size * 4;
		$r = $d / 2;
		$t = imagecreatetruecolor( $d, $d );
		imagesavealpha( $t, true );
		imagealphablending( $t, false );
		$trans = imagecolorallocatealpha( $t, 0, 0, 0, 127 );
		imagefill( $t, 0, 0, $trans );
		imagealphablending( $t, true );
		imagecopyresampled( $t, $src, 0, 0, 0, 0, $d, $d, imagesx( $src ), imagesy( $src ) );
		imagedestroy( $src );

		// Circular mask: everything outside the circle goes transparent.
		imagealphablending( $t, false );
		$rr = $r * $r;
		for ( $yy = 0; $yy < $d; $yy++ ) {
			for ( $xx = 0; $xx < $d; $xx++ ) {
				$dx = $xx - $r + 0.5;
				$dy = $yy - $r + 0.5;
				if ( $dx * $dx + $dy * $dy > $rr ) {
					imagesetpixel( $t, $xx, $yy, $trans );
				}
			}
		}
		imagealphablending( $t, true );

		// The hairline ring that frames it.
		imagesetthickness( $t, 6 );
		imageellipse( $t, (int) $r, (int) $r, $d - 6, $d - 6, imagecolorallocate( $t, 138, 131, 116 ) );
		imagesetthickness( $t, 1 );

		$f = imagecreatetruecolor( $size, $size );
		imagesavealpha( $f, true );
		imagealphablending( $f, false );
		imagefill( $f, 0, 0, imagecolorallocatealpha( $f, 0, 0, 0, 127 ) );
		imagecopyresampled( $f, $t, 0, 0, 0, 0, $size, $size, $d, $d );
		imagedestroy( $t );
		return $f;
	}

	/** Filled rounded rectangle — GD ships none: a cross of rects + corner discs. */
	private static function og_rrect( $img, $x1, $y1, $x2, $y2, $r, $color ) {
		$r = (int) min( $r, floor( ( $x2 - $x1 ) / 2 ), floor( ( $y2 - $y1 ) / 2 ) );
		imagefilledrectangle( $img, $x1 + $r, $y1, $x2 - $r, $y2, $color );
		imagefilledrectangle( $img, $x1, $y1 + $r, $x2, $y2 - $r, $color );
		imagefilledellipse( $img, $x1 + $r, $y1 + $r, 2 * $r, 2 * $r, $color );
		imagefilledellipse( $img, $x2 - $r, $y1 + $r, 2 * $r, 2 * $r, $color );
		imagefilledellipse( $img, $x1 + $r, $y2 - $r, 2 * $r, 2 * $r, $color );
		imagefilledellipse( $img, $x2 - $r, $y2 - $r, 2 * $r, 2 * $r, $color );
	}

	/**
	 * A progress ring: full track, a sweep clockwise from 12 o'clock drawn in
	 * short arc segments that interpolate from $from_rgb to $to_rgb (a subtle
	 * gradient), the middle punched back to the background.
	 *
	 * @param resource|\GdImage $img      Image.
	 * @param int               $cx       Centre x.
	 * @param int               $cy       Centre y.
	 * @param int               $ro       Outer radius.
	 * @param int               $ri       Inner radius.
	 * @param int               $track    Allocated track colour.
	 * @param array|null        $from_rgb Sweep start [r,g,b]; null = track only.
	 * @param array             $to_rgb   Sweep end [r,g,b].
	 * @param int               $pct      0–100.
	 * @param int               $bg       Allocated background colour (the punch).
	 */
	private static function og_ring( $img, $cx, $cy, $ro, $ri, $track, $from_rgb, $to_rgb, $pct, $bg ) {
		imagefilledellipse( $img, $cx, $cy, 2 * $ro, 2 * $ro, $track );
		if ( null !== $from_rgb && $pct > 0 ) {
			$sweep = 3.6 * min( 100, $pct );
			$steps = max( 1, (int) ceil( $sweep / 6 ) );
			for ( $i = 0; $i < $steps; $i++ ) {
				$t = $steps > 1 ? $i / ( $steps - 1 ) : 1.0;
				$c = imagecolorallocate(
					$img,
					(int) round( $from_rgb[0] + ( $to_rgb[0] - $from_rgb[0] ) * $t ),
					(int) round( $from_rgb[1] + ( $to_rgb[1] - $from_rgb[1] ) * $t ),
					(int) round( $from_rgb[2] + ( $to_rgb[2] - $from_rgb[2] ) * $t )
				);
				// Each segment overlaps the next by a degree to hide the seams.
				$a1 = -90 + $sweep * $i / $steps;
				$a2 = min( -90 + $sweep, $a1 + ( $sweep / $steps ) + 1.2 );
				imagefilledarc( $img, $cx, $cy, 2 * $ro, 2 * $ro, (int) round( $a1 ), (int) round( $a2 ), $c, IMG_ARC_PIE );
			}
		}
		imagefilledellipse( $img, $cx, $cy, 2 * $ri, 2 * $ri, $bg );
	}

	/** TTF text centred on x. */
	private static function og_center( $img, $size, $cx, $y, $color, $font, $text, $bold = false ) {
		$b = imagettfbbox( $size, 0, $font, $text );
		$x = (int) ( $cx - ( $b[2] - $b[0] ) / 2 );
		if ( $bold ) {
			self::og_bold( $img, $size, $x, $y, $color, $font, $text );
		} else {
			imagettftext( $img, $size, 0, $x, $y, $color, $font, $text );
		}
	}

	/** Faux bold for a single-weight font: the glyphs drawn twice, a hair apart. */
	private static function og_bold( $img, $size, $x, $y, $color, $font, $text ) {
		imagettftext( $img, $size, 0, $x, $y, $color, $font, $text );
		imagettftext( $img, $size, 0, $x + max( 1, (int) round( $size / 30 ) ), $y, $color, $font, $text );
	}

	/* ---------------------------------------------------------------------- *
	 *  Output
	 * ---------------------------------------------------------------------- */

	/**
	 * Serve a public scorecard body. Unlike the agent docs in Endpoints, this
	 * is a HUMAN surface: no Guard (a blocked UA would break embedded badges),
	 * no activity-log row, plain public cache headers.
	 */
	private function send( $body, $type, $max_age = 3600 ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			$charset = 0 === strpos( $type, 'image/' ) ? '' : '; charset=UTF-8';
			header( 'Content-Type: ' . $type . $charset );
			header( 'X-Content-Type-Options: nosniff' );
			// The signed-in owner is the person iterating on the settings — a
			// display-mode flip they can't see without a hard reload reads as
			// "broken". They get every response fresh; the anonymous public
			// (and any edge in front of it) keeps the cache window. The body
			// is identical either way, so a confused shared cache can mix the
			// two up without ever serving anyone the wrong thing.
			if ( is_user_logged_in() ) {
				header( 'Cache-Control: no-store' );
			} else {
				header( 'Cache-Control: public, max-age=' . (int) $max_age );
			}
		}
		$is_head = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		if ( ! $is_head ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG/PNG payloads are generated whole; the HTML page escapes every dynamic value at build time (see page_html).
		}
		exit;
	}
}
