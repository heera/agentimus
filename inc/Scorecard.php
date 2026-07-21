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
		$opts    = array(
			'shape' => (string) $this->settings->get( 'scorecard_badge_shape', 'rectangle' ),
			'bg'    => (string) $this->settings->get( 'scorecard_badge_bg', '' ),
			'fg'    => (string) $this->settings->get( 'scorecard_badge_fg', '' ),
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
		}

		$accent = $this->accent();

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
	 * saturation in the readable mid-range (white text must sit on it) whose
	 * hue isn't an alarm. Red and warning-amber hues are skipped no matter how
	 * on-brand they are — in badge grammar red means FAILING, and a score
	 * wearing it reads as "something is wrong" (shields.io red, error toasts).
	 * A theme whose only saturated tones are alarms gets the house teal. Pure.
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
			if ( $sat < 0.25 ) {
				continue;
			}
			// Readability gate: WCAG relative luminance, not HSL lightness —
			// HSL calls a vivid green "medium" while the eye reads it as
			// bright, and white badge text drowns on it. ≤ 0.1833 guarantees
			// white text ≥ 4.5:1; ≥ 0.02 keeps near-blacks out.
			$lin = array();
			foreach ( $rgb as $ch ) {
				$c     = $ch / 255;
				$lin[] = $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			}
			$rel = 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
			if ( $rel < 0.02 || $rel > 0.1833 ) {
				continue;
			}
			// Hue (0–360): reject the alarm zone — reds through warning ambers
			// (< 70°) and the wrap back into red (> 335°).
			$r = $rgb[0] / 255;
			$g = $rgb[1] / 255;
			$b = $rgb[2] / 255;
			if ( $max === $r ) {
				$hue = fmod( ( $g - $b ) / $sat, 6.0 );
			} elseif ( $max === $g ) {
				$hue = ( $b - $r ) / $sat + 2.0;
			} else {
				$hue = ( $r - $g ) / $sat + 4.0;
			}
			$hue = $hue * 60.0;
			if ( $hue < 0 ) {
				$hue += 360.0;
			}
			if ( $hue < 70.0 || $hue > 335.0 ) {
				continue;
			}
			return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
		}
		return '';
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
		$left_w   = 24 + $label_tw + 8 + $end_pad;  // gem + label + padding.
		$value_w  = $value_tw + 18 + $end_pad;
		$total    = $left_w + $value_w;

		$stroke = str_replace( '%TOTAL_LESS%', (string) ( $total - 1 ), $stroke );

		$gem_x  = 9 + $end_pad;
		$text_x = 24 + $end_pad;

		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $total . '" height="28" role="img" aria-label="' . esc_attr( $title ) . '">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<clipPath id="r"><rect width="' . $total . '" height="28" rx="' . $rx . '"/></clipPath>'
			. '<g clip-path="url(#r)">'
			. '<rect width="' . $left_w . '" height="28" fill="' . esc_attr( $left_bg ) . '"/>'
			. '<rect x="' . $left_w . '" width="' . $value_w . '" height="28" fill="' . esc_attr( $value_bg ) . '"/>'
			. '</g>'
			. $stroke
			. '<rect x="' . $gem_x . '" y="10.5" width="7" height="7" rx="1" transform="rotate(45 ' . ( $gem_x + 3.5 ) . ' 14)" fill="#ad7b18"/>'
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
				// One inner span = one grid item, so the number and its /100
				// share a baseline instead of stacking as two centred rows.
				. '<span class="n"><span>' . esc_html( (string) $score ) . '<small>/100</small></span></span></div>'
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
			. '.gauge .n{position:absolute;inset:0;display:grid;place-items:center;font-size:40px;font-weight:700}.gauge .n small{font-size:15px;font-weight:400;color:var(--muted);margin-left:2px}'
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
		$on_accent = isset( $opts['fg'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $opts['fg'] )
			? strtolower( $opts['fg'] ) : '#ffffff';

		$img = imagecreatetruecolor( 1200, 630 );
		$rgb = static function ( $hex ) use ( $img ) {
			$c = self::hex_rgb( $hex );
			$c = null === $c ? array( 20, 107, 100 ) : $c;
			return imagecolorallocate( $img, $c[0], $c[1], $c[2] );
		};

		$c_bg    = $rgb( $light ? '#f3f0e7' : '#191712' );
		$c_panel = $rgb( $light ? '#e7e1d2' : '#242019' );
		$c_track = $rgb( $light ? '#ddd7c6' : '#2b2720' );
		$c_ink   = $rgb( $light ? '#1b1913' : '#f3f0e7' );
		$c_mut   = $rgb( '#8a8374' );
		$c_acc   = $rgb( $accent );
		$c_onacc = $rgb( $on_accent );
		$c_gem   = $rgb( '#ad7b18' );
		$c_good  = $rgb( $light ? '#2f7a4c' : '#57b47f' );
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

		// ── Header: gem tile, product, report label.
		self::og_rrect( $img, 80, 56, 140, 116, 14, $c_panel );
		imagefilledpolygon( $img, array( 110, 70, 126, 86, 110, 102, 94, 86 ), 4, $c_gem );
		self::og_bold( $img, 27, 162, 92, $c_ink, $font, 'Agentimus' );
		imagettftext( $img, 14, 0, 162, 122, $c_mut, $font, strtoupper( __( 'AI-readiness report', 'agentimus' ) ) );

		// ── The site: label, name, chips.
		$name = (string) get_bloginfo( 'name' );
		$name = mb_strlen( $name ) > 26 ? mb_substr( $name, 0, 25 ) . '…' : $name;
		imagettftext( $img, 16, 0, 80, 208, $c_mut, $font, strtoupper( __( 'Your site', 'agentimus' ) ) );
		self::og_bold( $img, 38, 80, 262, $c_ink, $font, $name );

		$score  = (int) $snap['score'];
		$earned = self::tier_earned( $score );
		$tier   = 'tier' === $display;
		$status = $tier
			? strtoupper( $earned ? __( 'AI-Ready', 'agentimus' ) : __( 'In progress', 'agentimus' ) )
			: strtoupper( (string) $snap['band'] );
		$next_x = self::og_chip( $img, $font, 80, 292, 'WORDPRESS', $c_panel, $c_mut );
		self::og_chip( $img, $font, $next_x + 14, 292, $status, $c_panel, ( $tier && ! $earned ) ? $c_mut : $c_acc );

		// ── Left column: the rungs as labelled bars (tier mode: full tone
		// bars, no numbers — bar lengths are numbers too).
		$y = 380;
		foreach ( $snap['rungs'] as $r ) {
			$val  = isset( $r['score'] ) && null !== $r['score'] ? (int) $r['score'] : null;
			$tone = null === $val ? $c_mut : ( $val >= 80 ? $c_good : ( $val >= 50 ? $c_warn : $c_bad ) );
			imagettftext( $img, 17, 0, 80, $y + 6, $c_ink, $font, (string) $r['label'] );
			self::og_rrect( $img, 270, $y - 6, 640, $y + 6, 6, $c_track );
			$pct = null === $val ? 0 : ( $tier ? 100 : $val );
			if ( $pct > 0 ) {
				self::og_rrect( $img, 270, $y - 6, 270 + (int) round( 3.70 * $pct ), $y + 6, 6, $tone );
			}
			if ( ! $tier && null !== $val ) {
				imagettftext( $img, 15, 0, 662, $y + 5, $c_mut, $font, (string) $val );
			}
			$y += 38;
		}

		// ── Right: the ring gauge.
		$cx = 940;
		$cy = 350;
		if ( $tier ) {
			self::og_ring( $img, $cx, $cy, 148, 118, $c_track, $earned ? $c_acc : null, $earned ? 100 : 0, $c_bg );
			if ( $earned ) {
				self::og_center( $img, 40, $cx, $cy + 14, $c_acc, $font, __( 'AI-Ready', 'agentimus' ), true );
			} else {
				self::og_center( $img, 24, $cx, $cy - 6, $c_mut, $font, __( 'Working toward', 'agentimus' ) );
				self::og_center( $img, 24, $cx, $cy + 32, $c_mut, $font, __( 'AI-Ready', 'agentimus' ) );
			}
		} else {
			self::og_ring( $img, $cx, $cy, 148, 118, $c_track, $c_acc, max( 0, min( 100, $score ) ), $c_bg );
			self::og_center( $img, 88, $cx, $cy + 16, $c_acc, $font, (string) $score, true );
			self::og_center( $img, 24, $cx, $cy + 66, $c_mut, $font, '/ 100' );
			self::og_center( $img, 22, $cx, $cy + 182, $c_ink, $font, (string) $snap['band'] );
		}

		// ── Footer: the question + credit left, the site's domain in a pill.
		imageline( $img, 80, 548, 1120, 548, $c_track );
		self::og_bold( $img, 20, 80, 590, $c_ink, $font, __( 'Is your site AI-ready?', 'agentimus' ) );
		imagettftext( $img, 14, 0, 80, 616, $c_mut, $font, __( 'Measured by Agentimus — free on WordPress.org', 'agentimus' ) );
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$hb   = imagettfbbox( 16, 0, $font, $host );
		$hw   = $hb[2] - $hb[0];
		self::og_rrect( $img, 1120 - $hw - 44, 568, 1120, 612, 22, $c_acc );
		imagettftext( $img, 16, 0, 1120 - $hw - 22, 597, $c_onacc, $font, $host );

		ob_start();
		imagepng( $img );
		imagedestroy( $img );
		return (string) ob_get_clean();
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

	/** A filled, fully-rounded chip; returns the x where the next chip starts. */
	private static function og_chip( $img, $font, $x, $y, $text, $fill, $ink ) {
		$b = imagettfbbox( 13, 0, $font, $text );
		$w = ( $b[2] - $b[0] ) + 36;
		self::og_rrect( $img, $x, $y, $x + $w, $y + 38, 19, $fill );
		imagettftext( $img, 13, 0, $x + 18, $y + 25, $ink, $font, $text );
		return $x + $w;
	}

	/**
	 * A progress ring: full track, an accent sweep clockwise from 12 o'clock,
	 * the middle punched back to the background.
	 */
	private static function og_ring( $img, $cx, $cy, $ro, $ri, $track, $fill, $pct, $bg ) {
		imagefilledellipse( $img, $cx, $cy, 2 * $ro, 2 * $ro, $track );
		if ( null !== $fill && $pct > 0 ) {
			if ( $pct >= 100 ) {
				imagefilledellipse( $img, $cx, $cy, 2 * $ro, 2 * $ro, $fill );
			} else {
				imagefilledarc( $img, $cx, $cy, 2 * $ro, 2 * $ro, -90, (int) round( -90 + 3.6 * $pct ), $fill, IMG_ARC_PIE );
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
