<?php
/**
 * Front-end agent endpoints: the front controller for /llms.txt, /llms-full.txt,
 * markdown delivery (.md URLs + `Accept: text/markdown`), the robots.txt
 * content-signal rules, the AI-usage (TDM) headers, and the discovery Link
 * headers. Routing, response headers and caching policy live here; the llms.txt /
 * llms-full.txt CONTENT is assembled by {@see LlmsText}.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Endpoints {

	/** @var Settings */
	private $settings;

	/** @var LlmsText The llms.txt / llms-full.txt content builder. */
	private $llms;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->llms     = new LlmsText( $settings );
	}

	/**
	 * Hook the front-end routes and filters.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
		// Late, so link_headers() can see — and avoid duplicating — Link headers a
		// theme already emitted this request (zero-config de-dupe).
		add_action( 'send_headers', array( $this, 'link_headers' ), 99 );
		add_action( 'send_headers', array( $this, 'ai_signal_headers' ), 99 );
		// Mirror the two most useful discovery links into the HTML <head> too — some
		// crawlers/scanners read the markup but not the HTTP Link header.
		add_action( 'wp_head', array( $this, 'head_links' ), 2 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 20, 2 );
		// Re-warm the heavy full-text edition out-of-band after content changes.
		add_action( 'agentimus_cache_flushed', array( $this, 'schedule_warm' ) );
		add_action( 'agentimus_warm_llms_full', array( $this, 'warm_llms_full' ) );
	}

	/**
	 * On a content change (cache flush), schedule ONE debounced WP-Cron event to
	 * regenerate /llms-full.txt — so a crawler rarely pays cold-cache generation. A
	 * burst of edits coalesces into a single pending warm. WP-Cron is request-
	 * triggered, so this is best-effort (the bounded generation in llms_full_txt()
	 * is the real safety net), not a guarantee.
	 */
	public function schedule_warm() {
		if ( ! $this->settings->enabled( 'enable_llms_full' ) ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' )
			&& ! wp_next_scheduled( 'agentimus_warm_llms_full' ) ) {
			wp_schedule_single_event( time() + 30, 'agentimus_warm_llms_full' );
		}
	}

	/**
	 * Cron callback: regenerate the full-text edition into cache (bounded by the
	 * size/time budget). No-op when the feature is off or ceded to another producer.
	 */
	public function warm_llms_full() {
		if ( ! $this->settings->enabled( 'enable_llms_full' ) || $this->yields( 'llms_full' ) ) {
			return;
		}
		$this->llms->llms_full_txt();
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

		// Guarantee a 200 robots.txt. WordPress's virtual robots.txt can come back 404
		// on some hosts — the body is served but the status is wrong, which a strict
		// crawler may disregard, taking our crawler policy and sitemap line with it. When
		// there's no static file and robots rules are on, serve it here on a clean 200,
		// running the same robots_txt filter chain so core, SEO-plugin and our own
		// directives all survive. A producer can cede it via agentimus_yield_surface.
		if ( '/robots.txt' === $path
			&& $this->settings->enabled( 'enable_robots' )
			&& ! $this->yields( 'robots' )
			&& ! file_exists( Paths::site_root() . 'robots.txt' ) ) {
			$this->send_robots( $this->robots_body() );
		}

		if ( '/llms.txt' === $path && $this->settings->enabled( 'enable_llms_txt' ) && ! $this->yields( 'llms_txt' ) ) {
			$this->send( $this->llms->llms_txt(), 'text/plain', 'llms.txt' );
		}

		if ( '/llms-full.txt' === $path && $this->settings->enabled( 'enable_llms_full' ) && ! $this->yields( 'llms_full' ) ) {
			$this->send( $this->llms->llms_full_txt(), 'text/plain', 'llms-full.txt' );
		}

		// The change feed: recently added/updated content as JSON, with a `?since=`
		// delta filter. Freshness is the point, so it gets a short edge max-age.
		if ( '/agentimus-changes.json' === $path && $this->settings->enabled( 'enable_changes' ) && ! $this->yields( 'changes' ) ) {
			// Public, read-only endpoint: `since` is a filter value, not a state
			// change, so no nonce applies.
			$since = isset( $_GET['since'] ) ? sanitize_text_field( wp_unslash( $_GET['since'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->send( Changes::document( $this->settings, $since ), 'application/json', 'changes', 300 );
		}

		// The opt-in fallback sitemap (index + paginated sub-sitemaps) — served
		// only while Agentimus actually owns the sitemap (core/SEO absent), so we
		// never shadow another plugin's file.
		if ( 0 === strpos( $path, '/agentimus-sitemap' ) && '.xml' === substr( $path, -4 ) ) {
			if ( 'agentimus' === Sitemap::detect()['source'] ) {
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
				$this->send( $this->llms->index_markdown(), 'text/markdown', 'markdown' );
			}

			$post_id = url_to_postid( home_url( trailingslashit( $clean ) ) );
			if ( ! $post_id ) {
				$post_id = url_to_postid( home_url( $clean ) );
			}
			if ( $post_id && $this->post_in_scope( $post_id ) ) {
				$this->send( MarkdownCache::post( $post_id ), 'text/markdown', 'markdown' );
			}
			return; // Unknown / out-of-scope .md path: let WordPress 404 normally.
		}

		// Content negotiation on the resolved view — OFF by default; see
		// {@see negotiates_markdown()} for why.
		if ( ! self::negotiates_markdown() ) {
			return;
		}
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( ! self::prefers_markdown( $accept ) ) {
			return;
		}
		if ( is_singular() ) {
			$id = get_queried_object_id();
			if ( $id && $this->post_in_scope( $id ) ) {
				$this->send( MarkdownCache::post( $id ), 'text/markdown', 'markdown' );
			}
		}
		if ( is_front_page() || is_home() || is_archive() || is_search() ) {
			$this->send( $this->llms->index_markdown(), 'text/markdown', 'markdown' );
		}
	}

	/**
	 * Assemble robots.txt exactly as WordPress core's do_robots() does — the allow-all
	 * group and the public / non-public baseline — then run it through the robots_txt
	 * filter so every contributor (core defaults, SEO plugins, and our own
	 * {@see robots_txt()}) is preserved. Mirroring core means owning the route never
	 * drops a directive another producer expected to add.
	 *
	 * @return string
	 */
	private function robots_body() {
		$public = get_option( 'blog_public' );
		$out    = "User-agent: *\n";
		$out   .= ( '0' === (string) $public )
			? "Disallow: /\n"
			: "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		// Failure isolation: robots.txt is hit by every crawler, so a throwing third-party
		// robots_txt filter must never 500 this route (a crawler's retries would make it a
		// self-renewing loop). On any error, fall back to the safe baseline.
		try {
			$filtered = (string) apply_filters( 'robots_txt', $out, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WordPress core's own robots_txt filter to reproduce do_robots(), not declaring a new hook.
			return '' !== trim( $filtered ) ? $filtered : $out;
		} catch ( \Throwable $e ) {
			return $out;
		}
	}

	/**
	 * Emit robots.txt on a clean 200 and stop. Unlike {@see send()} it neither logs a
	 * hit (every crawler fetches robots.txt — it would swamp the activity log) nor runs
	 * the access block: robots.txt is the policy file itself, so even a blocked bot
	 * should be able to read the rules telling it to stay out.
	 *
	 * @param string $body robots.txt content.
	 */
	private function send_robots( $body ) {
		if ( ! headers_sent() ) {
			// WordPress flagged this request a 404 during handle_404() before we ran;
			// clear that so status_header() emits a clean 200 rather than being
			// re-affirmed as 404, then set the status both ways to be certain.
			if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) {
				$GLOBALS['wp_query']->is_404 = false;
			}
			status_header( 200 );
			if ( function_exists( 'http_response_code' ) ) {
				http_response_code( 200 );
			}
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
			CacheHeaders::send( HOUR_IN_SECONDS );
		}
		$is_head = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		if ( ! $is_head ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text robots.txt payload.
		}
		exit;
	}

	/**
	 * Whether a post's type is in the owner's agent-visible selection. Guards the
	 * direct .md / Accept routes so they expose exactly what /llms.txt lists — not
	 * every public post type.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function post_in_scope( $post_id ) {
		return in_array( get_post_type( $post_id ), Content::post_types(), true );
	}

	/**
	 * Emit a plain-text/markdown body with sane headers, then stop.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type MIME type.
	 * @param string $label        Activity-log endpoint label (empty = no log).
	 * @param int    $max_age      Cache-Control max-age (seconds) for cacheable
	 *                             (non-markdown) bodies. Defaults to one hour.
	 */
	/**
	 * Whether a PAGE URL may answer with its markdown twin for a client that asks
	 * for it (`Accept: text/markdown`). **Off by default since 1.21.2.**
	 *
	 * One URL answering with two different bodies is safe only if every cache in
	 * front of the site honours the "never store this" instruction the markdown
	 * answer carries — `Cache-Control`, `CDN-Cache-Control` and Cloudflare's own
	 * vendor header, all set to `no-store` (see {@see send()}).
	 *
	 * Real CDNs don't. A Cloudflare "Cache Everything" rule with an Edge TTL
	 * overrides *every* cache directive an origin can send, and no mainstream CDN
	 * varies its cache key on `Vary: Accept`. The failure this caused in the wild:
	 * an AI crawler — which finds a new post through the change feed within seconds
	 * of publication, so it is often the FIRST client to fetch it — asked a fresh
	 * post for markdown, the edge stored that answer under the post's own URL, and
	 * every human visitor was served raw markdown until the cache expired.
	 *
	 * There is no header that fixes that from the origin, and the site owner is not
	 * the one who finds out — their readers are. So the convenience is off unless
	 * asked for, and the safe route is the default: the `.md` twin is a DISTINCT URL,
	 * cache-safe by construction, advertised in the page's `Link` header, in llms.txt
	 * and in the discovery documents. Agents lose nothing.
	 *
	 * Turn it back on where you know the caching is sound (no CDN, or one that
	 * honours `no-store`):
	 *
	 *     add_filter( 'agentimus_negotiate_markdown', '__return_true' );
	 *
	 * @return bool
	 */
	public static function negotiates_markdown() {
		/**
		 * Whether a page URL may answer with markdown for a client that asks for it.
		 *
		 * @param bool $enabled Default false — see the method docblock for the CDN
		 *                      cache-poisoning failure this default prevents.
		 */
		return (bool) apply_filters( 'agentimus_negotiate_markdown', false );
	}

	/**
	 * Whether an Accept header asks for markdown IN PREFERENCE TO HTML.
	 *
	 * The old test was `stripos( $accept, 'text/markdown' )`, which ignored quality
	 * values entirely: `Accept: text/html;q=0.9, text/markdown;q=0.8` — a client that
	 * plainly prefers HTML — was answered with markdown. RFC 9110 §12.5.1 says the
	 * client's preference IS the q value, so honour it: markdown must be listed, and
	 * must outrank HTML strictly. A tie goes to HTML, because the page URL's own
	 * media type is HTML and a caller that wants markdown badly enough can say so
	 * (or fetch the `.md` twin).
	 *
	 * A wildcard (`*\/*`) never grants markdown — it says "anything", not "markdown" —
	 * so curl's default Accept, and every browser's, leave the page as HTML. That
	 * matters beyond correctness: the fewer clients that can be answered with markdown
	 * at a page URL, the fewer that can poison a shared cache with it.
	 *
	 * @param string $accept Raw Accept header.
	 * @return bool
	 */
	public static function prefers_markdown( $accept ) {
		$accept = strtolower( trim( (string) $accept ) );
		if ( '' === $accept || false === strpos( $accept, 'text/markdown' ) ) {
			return false;
		}

		$q = array();
		foreach ( explode( ',', $accept ) as $part ) {
			$bits = explode( ';', trim( $part ) );
			$type = trim( array_shift( $bits ) );
			if ( '' === $type ) {
				continue;
			}
			$weight = 1.0;
			foreach ( $bits as $param ) {
				$param = trim( $param );
				if ( 0 === strpos( $param, 'q=' ) ) {
					$weight = (float) substr( $param, 2 );
				}
			}
			// A repeated type keeps its highest weight.
			if ( ! isset( $q[ $type ] ) || $weight > $q[ $type ] ) {
				$q[ $type ] = $weight;
			}
		}

		$markdown = isset( $q['text/markdown'] ) ? $q['text/markdown'] : 0.0;
		$html     = max(
			isset( $q['text/html'] ) ? $q['text/html'] : 0.0,
			isset( $q['application/xhtml+xml'] ) ? $q['application/xhtml+xml'] : 0.0
		);

		return $markdown > 0.0 && $markdown > $html;
	}

	private function send( $body, $content_type, $label = '', $max_age = 3600 ) {
		// Optional hard enforcement (opt-in): deny denylisted/spoofed agents before
		// we serve — and before we record a hit, so a blocked request never appears
		// in the log as though it were served.
		Guard::maybe_block();
		if ( '' !== $label ) {
			\Agentimus\Activity\Recorder::record( $label );
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
			// Public, read-only agent docs — allow cross-origin reads so browser-based
			// agents can fetch them too (matches the discovery docs in WellKnown).
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Vary: Accept', false );

			if ( 'text/markdown' === $content_type ) {
				// Markdown can share a URL with the HTML page (content negotiation), so a
				// shared cache that stored it would hand the raw markdown to human
				// visitors. Say "don't store this" in every dialect a CDN reads, because
				// `Cache-Control` alone is not enough: a CDN configured to override origin
				// headers (Cloudflare "Cache Everything" with an Edge TTL, and the
				// equivalent on other edges) rewrites it and caches the body anyway. The
				// CDN-targeted headers below take precedence over `Cache-Control` at the
				// edge, and Cloudflare's own vendor header outranks both.
				header( 'Cache-Control: no-store, max-age=0' );
				header( 'CDN-Cache-Control: no-store' );
				header( 'Cloudflare-CDN-Cache-Control: no-store' );
			} else {
				// Stable URLs (llms.txt, the sitemap) are safe to cache; the change
				// feed passes a shorter max-age since freshness is its whole point.
				// CacheHeaders sends `no-store` instead when the owner opts to keep the
				// AI endpoints out of a shared cache (Settings → Visit log).
				CacheHeaders::send( $max_age );
			}
		}

		$is_head = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		if ( ! $is_head ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text/markdown payload.
		}
		exit;
	}

	/**
	 * Whether Agentimus should STAND DOWN for an agent-readiness surface, so another
	 * producer (a theme or plugin that emits its own llms.txt, markdown, robots
	 * rules or Link headers) owns it instead. This is the documented way to coexist
	 * — a producer cedes a surface in one line, using this public API rather than
	 * sniffing for the plugin:
	 *
	 *     add_filter( 'agentimus_yield_surface', function ( $yield, $surface ) {
	 *         // My theme already serves these — let it.
	 *         return in_array( $surface, array( 'llms_txt', 'markdown' ), true ) ? true : $yield;
	 *     }, 10, 2 );
	 *
	 * @param string $surface One of: llms_txt, llms_full, changes, markdown, link_headers, robots.
	 * @return bool True if Agentimus must not handle this surface.
	 */
	private function yields( $surface ) {
		/**
		 * Cede an agent-readiness surface to another producer.
		 *
		 * @param bool   $yield   Whether Agentimus should stand down. Default false.
		 * @param string $surface Surface key (llms_txt|llms_full|changes|markdown|link_headers|robots).
		 */
		return (bool) apply_filters( 'agentimus_yield_surface', false, $surface );
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
		// Advertise the current page's markdown twin (its `.md` URL) so an agent can
		// discover it from the HTML response instead of guessing the path exists.
		if ( $this->settings->enabled( 'enable_markdown' ) && ! $this->yields( 'markdown' ) ) {
			$md = $this->markdown_alternate_url();
			if ( '' !== $md && ! $this->markdown_alternate_present() ) {
				header( 'Link: <' . esc_url_raw( $md ) . '>; rel="alternate"; type="text/markdown"', false );
			}
		}
	}

	/**
	 * Echo the two highest-value discovery links into the HTML <head>. These are
	 * already advertised in the HTTP Link header (link_headers above), but some
	 * crawlers and readiness scanners parse the markup and not the headers — so a
	 * belt-and-suspenders <link> makes llms.txt and the OpenAPI contract findable
	 * either way. Cheap, idempotent, and skipped when the surface is ceded.
	 */
	public function head_links() {
		if ( is_admin() || is_feed() || $this->yields( 'link_headers' ) ) {
			return;
		}
		if ( $this->settings->enabled( 'enable_llms_txt' ) ) {
			printf(
				'<link rel="describedby" type="text/plain" href="%s">' . "\n",
				esc_url( home_url( '/llms.txt' ) )
			);
		}
		// The OpenAPI 3.1 description of the existing public REST read API is always
		// served at /.well-known/openapi.json while the plugin is active.
		printf(
			'<link rel="service-desc" type="application/json" href="%s">' . "\n",
			esc_url( home_url( '/.well-known/openapi.json' ) )
		);
	}

	/**
	 * The `.md` URL for the page being rendered, or '' when there isn't a faithful
	 * one to advertise. Limited to singular, in-scope content (a post/page that
	 * markdown delivery actually serves) — the front page and archives map only to
	 * the generic site index, so advertising them as a page "alternate" would
	 * mislead, and they're skipped. The URL mirrors route()'s `.md` resolution:
	 * the permalink with `.md` appended.
	 *
	 * @return string
	 */
	private function markdown_alternate_url() {
		if ( ! is_singular() || is_front_page() ) {
			return '';
		}
		$id = get_queried_object_id();
		if ( ! $id || ! $this->post_in_scope( $id ) ) {
			return '';
		}
		$permalink = get_permalink( $id );
		return $permalink ? untrailingslashit( $permalink ) . '.md' : '';
	}

	/**
	 * Whether a markdown `rel="alternate"` Link header was already emitted this
	 * request (e.g. by a theme). Matched on rel AND type, since `alternate` legally
	 * repeats with different media types — so we only de-dupe the markdown one.
	 *
	 * @return bool
	 */
	private function markdown_alternate_present() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'link:' )
				&& false !== stripos( $header, 'rel="alternate"' )
				&& false !== stripos( $header, 'text/markdown' ) ) {
				return true;
			}
		}
		return false;
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
	 *  AI-usage signals (TDM Reservation Protocol headers)
	 * ---------------------------------------------------------------------- */

	/**
	 * The AI-usage reservation, as a PURE decision so it can be unit-tested
	 * without touching headers. The site reserves its content from AI training
	 * when content_signal.ai_train is off ("ai-train=no"); when training is
	 * allowed there is nothing to reserve, so no header/file is published (the
	 * web default — absence of a signal — already means "allowed").
	 *
	 * @return array{reserved:bool,policy:string}
	 */
	public function tdmrep_state() {
		$signal = (array) $this->settings->get( 'content_signal', array() );
		return array(
			'reserved' => empty( $signal['ai_train'] ), // ai-train=no → reserved.
			'policy'   => trim( (string) $this->settings->get( 'tdm_policy_url', '' ) ),
		);
	}

	/**
	 * Emit the AI-usage reservation as response headers on normal content pages:
	 * the W3C TDM Reservation Protocol `tdm-reservation` header (plus an optional
	 * `tdm-policy`), and — when opted in — the non-standard-but-widely-honoured
	 * `X-Robots-Tag: noai, noimageai`. This reaches bots that never read robots.txt.
	 *
	 * Scope: skips admin/REST and our own "please read me" surfaces (llms.txt,
	 * markdown, /.well-known, robots.txt, feeds) — marking those reserved would
	 * contradict the invitation to ingest them. Because send_headers fires before
	 * the query is resolved, the surfaces are matched on the request path, not
	 * conditional tags.
	 *
	 * Emitted only when reserved (training blocked): the web default is "not
	 * reserved", so an "allow" site never stamps a header on every page. The same
	 * value is sent to every client, so it stays edge-cacheable (no Vary).
	 */
	public function ai_signal_headers() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || headers_sent() ) {
			return;
		}
		if ( ! $this->settings->enabled( 'enable_ai_header' ) ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = strtolower( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		if ( '/llms.txt' === $path || '/llms-full.txt' === $path || '/robots.txt' === $path
			|| '.md' === substr( $path, -3 ) || 0 === strpos( $path, '/.well-known/' )
			|| false !== strpos( $path, '/feed' ) ) {
			return;
		}

		$state = $this->tdmrep_state();
		if ( empty( $state['reserved'] ) ) {
			return; // Training allowed → emit nothing (silence == not reserved).
		}

		header( 'tdm-reservation: 1' );
		if ( '' !== $state['policy'] ) {
			header( 'tdm-policy: ' . $state['policy'] );
		}
		if ( $this->settings->enabled( 'ai_noai_header' ) ) {
			header( 'X-Robots-Tag: noai, noimageai', false ); // Append — never clobber an existing X-Robots-Tag.
		}
	}

	/* ---------------------------------------------------------------------- *
	 *  Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * The detected sitemap URL (core or a known SEO plugin), or '' if none.
	 *
	 * @return string
	 */
	private function sitemap_url() {
		return Sitemap::url();
	}
}
