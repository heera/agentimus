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

	/** House accent (the admin's teal) — the fallback when no theme accent is usable. */
	const ACCENT = '#146b64';

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
	 * The public base path ('/ai-readiness'), normalised, filterable.
	 *
	 * @return string Leading slash, no trailing slash.
	 */
	public static function path() {
		$path = apply_filters( 'agentimus_scorecard_path', self::PATH );
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
		$base = self::path();

		$display = (string) $this->settings->get( 'scorecard_display', 'score' );
		$style   = (string) $this->settings->get( 'scorecard_style', 'auto' );
		$accent  = $this->accent();

		// Short cache windows on purpose (the shields.io convention): these
		// surfaces change when the owner flips a display mode or the score
		// moves, and an embedded badge that shows yesterday's choice for an
		// hour reads as broken. Five minutes still absorbs any hotlink storm —
		// the bodies are transient-backed and cheap.
		if ( $base . '/badge.svg' === $path ) {
			$this->send( self::badge_svg( $this->snapshot(), $display, $style, $accent ), 'image/svg+xml', 300 );
		}

		if ( $base . '/card.png' === $path ) {
			$png = $this->og_png( $this->snapshot(), $display, $style, $accent );
			if ( '' !== $png ) {
				$this->send( $png, 'image/png', 300 );
			}
			return; // No GD / no font: let it 404 rather than serve a broken image.
		}

		if ( $base === $path || $base . '/' === $path ) {
			// The owner's real content always wins: if an actual page lives at
			// this path, stand down entirely (the path is filterable instead).
			if ( url_to_postid( home_url( trailingslashit( $base ) ) ) || url_to_postid( home_url( $base ) ) ) {
				return;
			}
			$this->send( self::page_html( $this->snapshot(), $this->page_context( $base ), $display, $style, $accent ), 'text/html', 60 );
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
	 * Pick a usable accent from a theme palette: the first colour with real
	 * saturation in the readable mid-range (white text must sit on it). Pure.
	 *
	 * @param array<int,string> $colors Hex strings ('#abc' or '#aabbcc').
	 * @return string Normalised '#rrggbb', or '' when nothing qualifies.
	 */
	public static function pick_accent( array $colors ) {
		foreach ( $colors as $hex ) {
			$rgb = self::hex_rgb( (string) $hex );
			if ( null === $rgb ) {
				continue;
			}
			$max = max( $rgb ) / 255;
			$min = min( $rgb ) / 255;
			$sat = $max - $min;
			$lum = ( $max + $min ) / 2;
			if ( $sat >= 0.25 && $lum >= 0.15 && $lum <= 0.62 ) {
				return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
			}
		}
		return '';
	}

	/**
	 * The accent the public surfaces wear: filter → theme palette (block
	 * themes publish theirs in theme.json) → the house teal.
	 */
	private function accent() {
		$accent = apply_filters( 'agentimus_scorecard_accent', '' );
		if ( is_string( $accent ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ) {
			return strtolower( $accent );
		}
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = wp_get_global_settings( array( 'color', 'palette' ) );
			$flat    = array();
			foreach ( array( 'theme', 'custom', 'default' ) as $set ) {
				if ( ! empty( $palette[ $set ] ) && is_array( $palette[ $set ] ) ) {
					foreach ( $palette[ $set ] as $entry ) {
						if ( isset( $entry['color'] ) ) {
							$flat[] = (string) $entry['color'];
						}
					}
				}
			}
			$picked = self::pick_accent( $flat );
			if ( '' !== $picked ) {
				return $picked;
			}
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
	 * @return string SVG document.
	 */
	public static function badge_svg( array $snap, $display, $style, $accent ) {
		$score  = isset( $snap['score'] ) ? (int) $snap['score'] : 0;
		$accent = preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $accent ) ? $accent : self::ACCENT;

		$label = __( 'AI READINESS', 'agentimus' );
		if ( 'tier' === $display ) {
			$earned   = self::tier_earned( $score );
			$value    = $earned ? __( 'READY ✓', 'agentimus' ) : __( 'IN PROGRESS', 'agentimus' );
			$value_bg = $earned ? $accent : '#6b6558';
			$title    = $earned
				? __( 'AI readiness: AI-Ready', 'agentimus' )
				: __( 'AI readiness: in progress', 'agentimus' );
		} else {
			$value    = $score . '/100';
			$value_bg = $accent;
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
			$stroke    = '<rect x="0.5" y="0.5" width="%TOTAL_LESS%" height="27" rx="4" fill="none" stroke="#d8d2c2"/>';
		}

		// Verdana metrics at 11px are ~6.9px/char for caps+digits; textLength
		// pins the rendered width so the estimate can't drift per platform.
		$label_tw = (int) round( mb_strlen( $label ) * 6.9 );
		$value_tw = (int) round( mb_strlen( $value ) * 6.9 );
		$left_w   = 24 + $label_tw + 8;  // gem + label + padding.
		$value_w  = $value_tw + 18;
		$total    = $left_w + $value_w;

		$stroke = str_replace( '%TOTAL_LESS%', (string) ( $total - 1 ), $stroke );

		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $total . '" height="28" role="img" aria-label="' . esc_attr( $title ) . '">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<clipPath id="r"><rect width="' . $total . '" height="28" rx="4"/></clipPath>'
			. '<g clip-path="url(#r)">'
			. '<rect width="' . $left_w . '" height="28" fill="' . esc_attr( $left_bg ) . '"/>'
			. '<rect x="' . $left_w . '" width="' . $value_w . '" height="28" fill="' . esc_attr( $value_bg ) . '"/>'
			. '</g>'
			. $stroke
			. '<rect x="9" y="10.5" width="7" height="7" rx="1" transform="rotate(45 12.5 14)" fill="#ad7b18"/>'
			. '<g font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">'
			. '<text x="24" y="18.5" fill="' . esc_attr( $left_text ) . '" textLength="' . $label_tw . '">' . esc_html( $label ) . '</text>'
			. '<text x="' . ( $left_w + 9 ) . '" y="18.5" fill="#ffffff" font-weight="bold" textLength="' . $value_tw . '">' . esc_html( $value ) . '</text>'
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

		if ( $tier ) {
			$headline = $earned ? __( 'AI-Ready', 'agentimus' ) : __( 'Working toward AI-Ready', 'agentimus' );
			$og_title = $ctx['site'] . ' — ' . $headline;
		} else {
			/* translators: %d: the score out of 100. */
			$headline = sprintf( __( '%d/100', 'agentimus' ), $score );
			/* translators: 1: site name, 2: score. */
			$og_title = sprintf( __( '%1$s — AI readiness %2$d/100', 'agentimus' ), $ctx['site'], $score );
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
				. '<span class="n">' . esc_html( (string) $score ) . '<small>/100</small></span></div>'
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
			. '<meta property="og:site_name" content="' . esc_attr( $ctx['site'] ) . '">'
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
			. '.gauge .n{position:absolute;inset:0;display:grid;place-items:center;font-size:40px;font-weight:700}.gauge .n small{font-size:15px;font-weight:400;color:var(--muted)}'
			. '.band{margin-top:10px;font-weight:600}'
			. '.tier{font-size:30px;font-weight:700;margin:26px 0}.tier[data-earned="1"]{color:var(--accent)}.tier[data-earned="0"]{color:var(--muted);font-size:22px}'
			. 'ol{list-style:none;padding:0;margin:30px 0 0;text-align:left}li{display:grid;grid-template-columns:88px 1fr auto;gap:10px;align-items:center;padding:7px 0;font-size:14px}'
			. '.bar{height:6px;border-radius:3px;background:var(--line);overflow:hidden}.bar span{display:block;height:100%;border-radius:3px;background:var(--accent)}'
			. 'li[data-tone="warn"] .bar span{background:var(--warn)}li[data-tone="bad"] .bar span{background:var(--bad)}li[data-tone="good"] .bar span{background:var(--good)}'
			. '.num{color:var(--muted);font-size:12px;font-variant-numeric:tabular-nums}'
			. 'footer{margin-top:30px;padding-top:16px;border-top:1px solid var(--line);font-size:12px;color:var(--muted)}footer a{color:inherit}'
			. '</style></head><body>'
			. '<main class="card">'
			. '<p class="site">' . $icon . '<a href="' . esc_url( $ctx['home'] ) . '">' . esc_html( $ctx['site'] ) . '</a></p>'
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
	 * Render the 1200×630 social-preview PNG. '' when the server can't.
	 */
	private function og_png( array $snap, $display, $style, $accent ) {
		if ( ! self::og_ready() ) {
			return '';
		}
		$font = self::find_font();
		$dark = 'dark' === $style;

		$img = imagecreatetruecolor( 1200, 630 );
		$bg  = $dark ? array( 25, 23, 18 ) : array( 243, 240, 231 );
		$ink = $dark ? array( 243, 240, 231 ) : array( 27, 25, 19 );
		$mut = array( 122, 116, 102 );
		$acc = self::hex_rgb( $accent );
		$acc = null === $acc ? self::hex_rgb( self::ACCENT ) : $acc;

		$c_bg  = imagecolorallocate( $img, $bg[0], $bg[1], $bg[2] );
		$c_ink = imagecolorallocate( $img, $ink[0], $ink[1], $ink[2] );
		$c_mut = imagecolorallocate( $img, $mut[0], $mut[1], $mut[2] );
		$c_acc = imagecolorallocate( $img, $acc[0], $acc[1], $acc[2] );
		$c_gem = imagecolorallocate( $img, 173, 123, 24 );
		imagefilledrectangle( $img, 0, 0, 1200, 630, $c_bg );

		// Header: gem + site name (length-capped); label line under it.
		$name = (string) get_bloginfo( 'name' );
		$name = mb_strlen( $name ) > 40 ? mb_substr( $name, 0, 39 ) . '…' : $name;
		imagefilledpolygon( $img, array( 92, 96, 106, 110, 92, 124, 78, 110 ), 4, $c_gem );
		imagettftext( $img, 30, 0, 130, 122, $c_ink, $font, $name );
		imagettftext( $img, 19, 0, 130, 168, $c_mut, $font, __( 'AI READINESS', 'agentimus' ) );

		$score  = (int) $snap['score'];
		$earned = self::tier_earned( $score );
		if ( 'tier' === $display ) {
			$line = $earned ? __( 'AI-Ready', 'agentimus' ) : __( 'Working toward AI-Ready', 'agentimus' );
			$size = $earned ? 84 : 52;
			$box  = imagettfbbox( $size, 0, $font, $line );
			imagettftext( $img, $size, 0, (int) ( ( 1200 - ( $box[2] - $box[0] ) ) / 2 ), 380, $earned ? $c_acc : $c_mut, $font, $line );
		} else {
			$num = (string) $score;
			$box = imagettfbbox( 150, 0, $font, $num );
			$nw  = $box[2] - $box[0];
			$sfx = imagettfbbox( 42, 0, $font, '/100' );
			$x   = (int) ( ( 1200 - ( $nw + 24 + ( $sfx[2] - $sfx[0] ) ) ) / 2 );
			imagettftext( $img, 150, 0, $x, 420, $c_acc, $font, $num );
			imagettftext( $img, 42, 0, $x + $nw + 24, 420, $c_mut, $font, '/100' );
			$band = (string) $snap['band'];
			$bb   = imagettfbbox( 30, 0, $font, $band );
			imagettftext( $img, 30, 0, (int) ( ( 1200 - ( $bb[2] - $bb[0] ) ) / 2 ), 478, $c_ink, $font, $band );
		}

		// Rung row: a tone dot + label for each, centred as one line.
		$c_good = imagecolorallocate( $img, 47, 122, 76 );
		$c_warn = imagecolorallocate( $img, 173, 123, 24 );
		$c_bad  = imagecolorallocate( $img, 185, 60, 43 );
		$c_na   = imagecolorallocate( $img, 154, 147, 138 );
		$parts  = array();
		$width  = 0;
		foreach ( $snap['rungs'] as $r ) {
			$label = (string) $r['label'];
			$box   = imagettfbbox( 20, 0, $font, $label );
			$w     = ( $box[2] - $box[0] ) + 26 + 44; // dot + gap + trailing space.
			$val   = isset( $r['score'] ) && null !== $r['score'] ? (int) $r['score'] : null;
			$tone  = null === $val ? $c_na : ( $val >= 80 ? $c_good : ( $val >= 50 ? $c_warn : $c_bad ) );
			$parts[] = array( $label, $tone, $w );
			$width  += $w;
		}
		$x = (int) ( ( 1200 - $width + 44 ) / 2 );
		foreach ( $parts as $p ) {
			imagefilledellipse( $img, $x, 556, 16, 16, $p[1] );
			imagettftext( $img, 20, 0, $x + 16, 564, $c_ink, $font, $p[0] );
			$x += $p[2];
		}

		imagettftext( $img, 17, 0, 900, 612, $c_mut, $font, __( 'Measured by Agentimus', 'agentimus' ) );

		ob_start();
		imagepng( $img );
		imagedestroy( $img );
		return (string) ob_get_clean();
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
