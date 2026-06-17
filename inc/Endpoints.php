<?php
/**
 * Front-end agent endpoints: /llms.txt, /llms-full.txt, markdown delivery
 * (.md URLs + `Accept: text/markdown`), the robots.txt content-signal rules,
 * and the discovery Link headers.
 *
 * @package Agentify
 */

namespace Agentify;

defined( 'ABSPATH' ) || exit;

final class Endpoints {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the front-end routes and filters.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
		// Late, so link_headers() can see — and avoid duplicating — Link headers a
		// theme already emitted this request (zero-config de-dupe).
		add_action( 'send_headers', array( $this, 'link_headers' ), 99 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 20, 2 );
	}

	/**
	 * Route the agent-facing endpoints before the normal template loads.
	 * Explicit paths win first, then `Accept` negotiation on the resolved view.
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

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = '/' . ltrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );

		if ( '/llms.txt' === $path && $this->settings->enabled( 'enable_llms_txt' ) && ! $this->yields( 'llms_txt' ) ) {
			$this->send( $this->llms_txt(), 'text/plain', 'llms.txt' );
		}

		if ( '/llms-full.txt' === $path && $this->settings->enabled( 'enable_llms_full' ) && ! $this->yields( 'llms_full' ) ) {
			$this->send( $this->llms_full_txt(), 'text/plain', 'llms-full.txt' );
		}

		// The opt-in fallback sitemap (index + paginated sub-sitemaps) — served
		// only while Agentify actually owns the sitemap (core/SEO absent), so we
		// never shadow another plugin's file.
		if ( 0 === strpos( $path, '/agentify-sitemap' ) && '.xml' === substr( $path, -4 ) ) {
			if ( 'agentify' === Sitemap::detect()['source'] ) {
				$body = Sitemap::body( $path );
				if ( '' !== $body ) {
					$this->send( $body, 'application/xml', 'sitemap' );
				}
			}
			return; // Unknown/inactive sitemap path: let WordPress 404 it normally.
		}

		if ( ! $this->settings->enabled( 'enable_markdown' ) || $this->yields( 'markdown' ) ) {
			return;
		}

		// Parallel markdown URL: /slug.md, /index.md (home), etc.
		if ( '.md' === substr( $path, -3 ) ) {
			$clean = substr( $path, 0, -3 );

			if ( '' === $clean || '/' === $clean || '/index' === $clean ) {
				$this->send( $this->index_markdown(), 'text/markdown', 'markdown' );
			}

			$post_id = url_to_postid( home_url( trailingslashit( $clean ) ) );
			if ( ! $post_id ) {
				$post_id = url_to_postid( home_url( $clean ) );
			}
			if ( $post_id ) {
				$this->send( Markdown::post( $post_id ), 'text/markdown', 'markdown' );
			}
			return; // Unknown .md path: let WordPress 404 normally.
		}

		// Content negotiation on the resolved view.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( false === stripos( $accept, 'text/markdown' ) ) {
			return;
		}
		if ( is_singular() ) {
			$this->send( Markdown::post( get_queried_object_id() ), 'text/markdown', 'markdown' );
		}
		if ( is_front_page() || is_home() || is_archive() || is_search() ) {
			$this->send( $this->index_markdown(), 'text/markdown', 'markdown' );
		}
	}

	/**
	 * Emit a plain-text/markdown body with sane headers, then stop.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type MIME type.
	 * @param string $label        Activity-log endpoint label (empty = no log).
	 */
	private function send( $body, $content_type, $label = '' ) {
		if ( '' !== $label ) {
			\Agentify\Activity\Recorder::record( $label );
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Vary: Accept', false );

			if ( 'text/markdown' === $content_type ) {
				// Negotiated markdown shares a URL with HTML; never let it be cached.
				header( 'Cache-Control: no-store, max-age=0' );
			} else {
				// Stable URLs (llms.txt, the sitemap) are safe to cache.
				header( 'Cache-Control: public, max-age=3600' );
			}
		}

		$is_head = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		if ( ! $is_head ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text/markdown payload.
		}
		exit;
	}

	/**
	 * Whether Agentify should STAND DOWN for an agent-readiness surface, so another
	 * producer (a theme or plugin that emits its own llms.txt, markdown, robots
	 * rules or Link headers) owns it instead. This is the documented way to coexist
	 * — a producer cedes a surface in one line, using this public API rather than
	 * sniffing for the plugin:
	 *
	 *     add_filter( 'agentify_yield_surface', function ( $yield, $surface ) {
	 *         // My theme already serves these — let it.
	 *         return in_array( $surface, array( 'llms_txt', 'markdown' ), true ) ? true : $yield;
	 *     }, 10, 2 );
	 *
	 * @param string $surface One of: llms_txt, llms_full, markdown, link_headers, robots.
	 * @return bool True if Agentify must not handle this surface.
	 */
	private function yields( $surface ) {
		/**
		 * Cede an agent-readiness surface to another producer.
		 *
		 * @param bool   $yield   Whether Agentify should stand down. Default false.
		 * @param string $surface Surface key (llms_txt|llms_full|markdown|link_headers|robots).
		 */
		return (bool) apply_filters( 'agentify_yield_surface', false, $surface );
	}

	/**
	 * Whether a `Link:` header with the given rel was already emitted this request
	 * (e.g. by a theme). link_headers() runs late (priority 99) precisely so this
	 * check sees earlier headers and never duplicates a rel another producer set.
	 *
	 * @param string $rel Relation type, e.g. "api-catalog".
	 * @return bool
	 */
	private function link_present( $rel ) {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'link:' ) && false !== stripos( $header, 'rel="' . $rel . '"' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Advertise discovery endpoints on every front-end response — skipping any rel a
	 * theme/plugin already set (zero-config de-duplication), and standing down
	 * entirely when the `link_headers` surface is ceded.
	 */
	public function link_headers() {
		if ( is_admin() || $this->yields( 'link_headers' ) ) {
			return;
		}
		if ( ! $this->link_present( 'api-catalog' ) ) {
			header( 'Link: <' . esc_url_raw( rest_url() ) . '>; rel="api-catalog"', false );
		}
		if ( $this->settings->enabled( 'enable_llms_txt' ) && ! $this->link_present( 'describedby' ) ) {
			header( 'Link: <' . esc_url_raw( home_url( '/llms.txt' ) ) . '>; rel="describedby"; type="text/plain"', false );
		}
	}

	/* ---------------------------------------------------------------------- *
	 *  robots.txt
	 * ---------------------------------------------------------------------- */

	/**
	 * Augment WordPress's robots output *without clobbering it* — so we co-exist
	 * with Yoast, WooCommerce and any other plugin filtering robots_txt. We
	 * inject a Content-Signal into the existing `User-agent: *` group, append a
	 * model-training crawler blocklist (skipping agents already present), and
	 * add a Sitemap only if none is declared yet. Read/cite bots stay allowed.
	 *
	 * @param string $output Robots content so far.
	 * @param bool   $public Whether the site is indexable.
	 * @return string
	 */
	public function robots_txt( $output, $public ) {
		if ( ! $public || ! $this->settings->enabled( 'enable_robots' ) || $this->yields( 'robots' ) ) {
			return $output;
		}

		$output = (string) $output;

		// 1. Declare the Content-Signal inside the existing allow-all group.
		$signal = $this->content_signal_string();
		if ( '' !== $signal && false === stripos( $output, 'content-signal:' ) ) {
			$line     = 'Content-Signal: ' . $signal;
			$injected = preg_replace( '/(^User-agent:\s*\*\s*$)/mi', "$1\n" . $line, $output, 1 );
			if ( is_string( $injected ) && $injected !== $output ) {
				$output = $injected;
			} else {
				$output = "User-agent: *\n" . $line . "\n\n" . ltrim( $output );
			}
		}

		// 2. Hard-block the listed crawlers by name. robots.txt can only enforce a
		// block per named user-agent (there is no "all AI trainers" directive), so
		// this list is the *enforcement* arm — independent of the ai-train signal
		// above and applied whether training is declared Allowed or Blocked. An
		// empty list blocks no one.
		$trainers = array_values( array_filter( array_map( 'trim', (array) $this->settings->get( 'blocked_trainers', array() ) ) ) );
		$new      = array();
		foreach ( $trainers as $agent ) {
			if ( false === stripos( $output, 'User-agent: ' . $agent ) ) {
				$new[] = 'User-agent: ' . $agent;
			}
		}
		if ( ! empty( $new ) ) {
			$output = rtrim( $output ) . "\n\n" . implode( "\n", $new ) . "\nDisallow: /\n";
		}

		// 3. Advertise a sitemap only if nobody else has.
		$sitemap = $this->sitemap_url();
		if ( $sitemap && false === stripos( $output, 'sitemap:' ) ) {
			$output = rtrim( $output ) . "\n\nSitemap: " . esc_url_raw( $sitemap ) . "\n";
		}

		return $output;
	}

	/**
	 * Compose the Content-Signal directive from the stored booleans. The
	 * vocabulary is fixed (search / ai-input / ai-train), so the public
	 * robots.txt can only ever contain valid, expected values.
	 *
	 * @return string e.g. "search=yes, ai-input=yes, ai-train=no".
	 */
	private function content_signal_string() {
		$signal = (array) $this->settings->get( 'content_signal', array() );
		$yn     = static function ( $v ) {
			return ! empty( $v ) ? 'yes' : 'no';
		};
		return sprintf(
			'search=%s, ai-input=%s, ai-train=%s',
			$yn( isset( $signal['search'] ) ? $signal['search'] : false ),
			$yn( isset( $signal['ai_input'] ) ? $signal['ai_input'] : false ),
			$yn( isset( $signal['ai_train'] ) ? $signal['ai_train'] : false )
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  /llms.txt
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the llms.txt index (cached an hour, busted on content change).
	 *
	 * @return string
	 */
	public function llms_txt() {
		$cached = Cache::get( Cache::LLMS_TXT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$name    = $this->text( $this->settings->identity( 'name', get_bloginfo( 'name' ) ) );
		$tagline = $this->text( get_bloginfo( 'description' ) );

		$out = '# ' . ( '' !== $name ? $name : home_url( '/' ) ) . "\n\n";
		if ( '' !== $tagline ) {
			$out .= '> ' . $tagline . "\n\n";
		}
		$out .= sprintf(
			"%s. Plain-text and markdown versions of any page are available by appending `.md` to its URL, or by requesting it with `Accept: text/markdown`.\n",
			'' !== $tagline ? $tagline : 'A WordPress site'
		);

		$out .= $this->about_block();

		// A section per agent-visible post type (pages, posts, products, CPTs…).
		foreach ( Content::index_sections() as $post_type ) {
			$items = Content::query( $post_type, 50 );
			if ( empty( $items ) ) {
				continue;
			}
			$out .= "\n## " . Content::label( $post_type ) . "\n\n";
			foreach ( $items as $item ) {
				$out .= $this->link_line( $item );
			}
		}

		// Topics.
		$topics = $this->topics();
		if ( '' !== $topics ) {
			$out .= "\n## Topics\n\n" . $topics;
		}

		$out .= "\n## Optional\n\n";
		if ( $this->settings->enabled( 'enable_llms_full' ) ) {
			$out .= '- [Full text](' . esc_url_raw( home_url( '/llms-full.txt' ) ) . "): every page and recent post concatenated into one document\n";
		}
		$out .= '- [Feed](' . esc_url_raw( get_feed_link() ) . "): RSS of recent posts\n";
		$sitemap = $this->sitemap_url();
		if ( $sitemap ) {
			$out .= '- [Sitemap](' . esc_url_raw( $sitemap ) . "): full XML sitemap\n";
		}

		$out = rtrim( $out ) . "\n";
		Cache::set( Cache::LLMS_TXT, $out );
		return $out;
	}

	/**
	 * Build the llms-full.txt full-text edition.
	 *
	 * @return string
	 */
	public function llms_full_txt() {
		$cached = Cache::get( Cache::LLMS_FULL );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$name    = $this->text( $this->settings->identity( 'name', get_bloginfo( 'name' ) ) );
		$tagline = $this->text( get_bloginfo( 'description' ) );

		$out = '# ' . ( '' !== $name ? $name : home_url( '/' ) ) . "\n\n";
		if ( '' !== $tagline ) {
			$out .= '> ' . $tagline . "\n\n";
		}
		$out .= "Full-text edition: the profile below, then the complete content of each page and recent article, concatenated for ingestion in a single pass. The link-only index is at /llms.txt.\n";

		$out .= $this->about_block();

		// Bounded generation. This runs synchronously on a PUBLIC request (a
		// crawler can trigger a cold-cache build), so on a large site — many post
		// types × many items, each rendered through the_content + DOM→markdown — an
		// unbounded build could exhaust PHP's memory_limit / max_execution_time.
		// We bound the WORK (rather than raise limits, which would be wrong on an
		// unauthenticated request) with three guards: a total byte budget, a
		// per-item cap, and a wall-clock deadline. When any trips we stop at an
		// item boundary (never mid-markdown, so the document stays valid), append a
		// note pointing back to the index, and cache what we produced.
		$budget   = max( 64, (int) $this->settings->get( 'llms_full_max_kb', 1024 ) ) * 1024;
		$item_cap = (int) apply_filters( 'agentify_llms_full_item_max_bytes', min( 256 * 1024, max( 32 * 1024, intdiv( $budget, 4 ) ) ) );
		$deadline = $this->generation_deadline();
		$start    = microtime( true );

		$count     = (int) $this->settings->get( 'llms_full_posts', 50 );
		$emitted   = 0;
		$truncated = false;
		$reason    = '';

		foreach ( Content::index_sections() as $post_type ) {
			// Pages are few and structural — include the lot; cap the rest.
			$limit = 'page' === $post_type ? 50 : ( $count > 0 ? $count : 50 );
			foreach ( Content::query( $post_type, $limit ) as $item ) {
				// Time guard first — before the (expensive) render, so we never
				// start work we can't finish within budget.
				if ( microtime( true ) - $start >= $deadline ) {
					$truncated = true;
					$reason    = 'time';
					break 2;
				}
				if ( ! Content::has_body( $item ) ) {
					continue; // No prose body (template-only or builder-empty).
				}
				$md = Markdown::post( $item->ID );
				// Per-item cap: skip a single runaway item rather than let it
				// dominate; it stays reachable via its own .md URL and the index.
				if ( strlen( $md ) > $item_cap ) {
					continue;
				}
				$piece = "\n---\n\n" . $md . "\n";
				// Byte budget: test the PROJECTED size before appending, so even the
				// first item can't overshoot. Stop at the boundary → always valid.
				if ( strlen( $out ) + strlen( $piece ) > $budget ) {
					$truncated = true;
					$reason    = 'budget';
					break 2;
				}
				$out .= $piece;
				++$emitted;
			}
		}

		if ( $truncated ) {
			$out .= $this->truncation_note();
		}

		$out = rtrim( $out ) . "\n";

		// Cache what we produced — a truncated body for a shorter window (content
		// or settings may change and bring it back under budget) — and record the
		// status so the admin can see a cap was hit without re-fetching the file.
		$ttl = $truncated ? Cache::TTL_PARTIAL : Cache::TTL;
		Cache::set( Cache::LLMS_FULL, $out, $ttl );
		Cache::set(
			Cache::LLMS_FULL_STAT,
			array(
				'bytes'        => strlen( $out ),
				'truncated'    => $truncated,
				'reason'       => $reason,
				'items'        => $emitted,
				'generated_at' => time(),
			),
			$ttl
		);
		return $out;
	}

	/**
	 * A safe wall-clock budget (seconds) for one full-text generation: half of
	 * PHP's max_execution_time, floored at 5s and ceiled at 20s. When the limit is
	 * unlimited (0 — CLI or some hosts) we still cap at 20s so a single public
	 * request can never run away.
	 *
	 * @return float
	 */
	private function generation_deadline() {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return 20.0;
		}
		return (float) max( 5, min( 20, (int) floor( $max * 0.5 ) ) );
	}

	/**
	 * The note appended when the full-text edition is cut short by a guard. Valid
	 * markdown (rule + blockquote); because we only ever stop at an item boundary
	 * it never glues onto a half-open construct.
	 *
	 * @return string
	 */
	private function truncation_note() {
		return "\n---\n\n> Note: this full-text edition was truncated to stay within a size limit, so not every page is included above. "
			. 'For the complete list of pages see the index at ' . esc_url_raw( home_url( '/llms.txt' ) )
			. ', and append `.md` to any page URL (or send `Accept: text/markdown`) to fetch that page in full.' . "\n";
	}

	/**
	 * The home/archive view as markdown — reuses the llms.txt index.
	 *
	 * @return string
	 */
	public function index_markdown() {
		return $this->llms_txt();
	}

	/* ---------------------------------------------------------------------- *
	 *  Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * The shared `## About` + Expertise block, from the identity settings.
	 *
	 * @return string
	 */
	private function about_block() {
		$about     = $this->text( $this->settings->identity( 'about', '' ) );
		$expertise = array_values( array_filter( array_map( 'trim', (array) $this->settings->identity( 'expertise', array() ) ) ) );

		if ( '' === $about && empty( $expertise ) ) {
			return '';
		}

		$out = "\n## About\n\n";
		if ( '' !== $about ) {
			$out .= $about . "\n";
		}
		if ( ! empty( $expertise ) ) {
			$out .= "\nExpertise:\n\n";
			foreach ( $expertise as $topic ) {
				$out .= '- ' . $topic . "\n";
			}
		}
		return $out;
	}

	/**
	 * `- [Topic](archive): description` lines for non-empty categories.
	 *
	 * @return string
	 */
	private function topics() {
		$exclude = apply_filters( 'agentify_topic_exclude', array( 'uncategorized' ) );

		$cats = get_categories(
			array(
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 30,
			)
		);
		if ( empty( $cats ) || is_wp_error( $cats ) ) {
			return '';
		}

		$lines = '';
		foreach ( $cats as $cat ) {
			if ( in_array( $cat->slug, (array) $exclude, true ) ) {
				continue;
			}
			$desc = $this->text( $cat->description );
			if ( '' === $desc ) {
				/* translators: %s: number of posts. */
				$desc = sprintf( _n( '%s article', '%s articles', $cat->count, 'agentify' ), number_format_i18n( $cat->count ) );
			}
			$lines .= '- [' . $this->text( $cat->name ) . '](' . esc_url_raw( get_category_link( $cat ) ) . '): ' . $desc . "\n";
		}
		return $lines;
	}

	/**
	 * One `- [Title](url): excerpt` line for a post or page.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private function link_line( $post ) {
		$title   = $this->text( get_the_title( $post ) );
		$excerpt = trim( preg_replace( '/\s+/', ' ', $this->text( get_the_excerpt( $post ) ) ) );
		if ( '' !== $excerpt ) {
			$excerpt = ': ' . wp_html_excerpt( $excerpt, 160, '…' );
		}
		return '- [' . $title . '](' . esc_url_raw( get_permalink( $post ) ) . ')' . $excerpt . "\n";
	}

	/**
	 * The detected sitemap URL (core or a known SEO plugin), or '' if none.
	 *
	 * @return string
	 */
	private function sitemap_url() {
		return Sitemap::url();
	}

	/**
	 * Plain text from HTML: strip tags, decode entities, trim.
	 *
	 * @param string $html Raw.
	 * @return string
	 */
	private function text( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( $text );
	}
}
