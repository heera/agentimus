<?php
/**
 * LlmsText — builds the agent-facing text documents: the /llms.txt index and the
 * /llms-full.txt full-text edition (plus the home/archive markdown view, which
 * reuses the index). Pure content assembly over the site's identity + Content —
 * the HTTP routing, headers and caching policy live in {@see Endpoints}, which
 * owns an instance of this and serves what it produces.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class LlmsText {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

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
		// Only promise the Accept route when it is actually on (it is off by default —
		// see Endpoints::negotiates_markdown). Advertising it otherwise would send agents
		// to a page URL asking for markdown, which is both a dead end and, on a CDN that
		// force-caches page URLs, the request that poisons the cache for human readers.
		$out .= sprintf(
			"%s. Plain-text and markdown versions of any page are available by appending `.md` to its URL%s.\n",
			'' !== $tagline ? $tagline : 'A WordPress site',
			Endpoints::negotiates_markdown() ? ', or by requesting it with `Accept: text/markdown`' : ''
		);

		// Bridge agents that enter via llms.txt to the structured manifest. The
		// manifest is the single source of truth for this site's machine surfaces
		// — endpoints, capabilities, agent cards, REST — and self-describes the
		// protocol it implements ($schema + spec_version), so we link it rather
		// than duplicating a capabilities list here that could drift out of sync.
		// Always served (no enable toggle), so the pointer can never 404.
		$out .= sprintf(
			"\nA machine-readable discovery manifest of this site's agent endpoints, capabilities, and identity (and the protocol spec it implements) is at [%s](%s).\n",
			'/.well-known/discovery.json',
			esc_url_raw( home_url( '/.well-known/discovery.json' ) )
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
		// The site as an installable Agent Skill (agentskills.io): what this site
		// offers an agent and when to use it — the agent-instruction companion to
		// the discovery manifest linked above.
		$out .= '- [Agent skill](' . esc_url_raw( \Agentimus\Discovery\SkillMd::url() ) . "): this site packaged as a SKILL.md — capabilities and when to use them\n";
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

		// Cold cache. This build renders every page/post through the_content and
		// DOM→markdown, so its cost scales with the site — and it runs on an
		// UNAUTHENTICATED request, where a burst of crawlers hitting a cold cache would
		// otherwise each launch a full build at once (a cache stampede). Serialise it:
		// one caller builds and populates the cache; the rest fall back to the lighter
		// /llms.txt index this once rather than pile a second heavy build onto the same
		// moment. A true cross-request mutex only on object-cache sites; elsewhere every
		// caller builds as before — the lock only ever helps, never blocks.
		if ( ! Cache::acquire_lock( Cache::LLMS_FULL ) ) {
			return $this->llms_txt();
		}
		try {
			return $this->build_llms_full();
		} finally {
			Cache::release_lock( Cache::LLMS_FULL );
		}
	}

	/**
	 * Assemble the full-text edition. Bounded by a byte budget, a per-item cap and a
	 * wall-clock deadline (the guards inline). Only ever called by llms_full_txt(),
	 * which holds the build lock around it and caches the result.
	 *
	 * @return string
	 */
	private function build_llms_full() {
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
		$budget   = max( 64, (int) $this->settings->get( 'llms_full_max_kb', 900 ) ) * 1024;
		$item_cap = (int) apply_filters( 'agentimus_llms_full_item_max_bytes', min( 256 * 1024, max( 32 * 1024, intdiv( $budget, 4 ) ) ) );
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
			. ', and append `.md` to any page URL' . ( Endpoints::negotiates_markdown() ? ' (or send `Accept: text/markdown`)' : '' ) . ' to fetch that page in full.' . "\n";
	}

	/**
	 * The home/archive view as markdown — reuses the llms.txt index.
	 *
	 * @return string
	 */
	public function index_markdown() {
		return $this->llms_txt();
	}

	/**
	 * The shared `## About` + Expertise block, from the identity settings.
	 *
	 * @return string
	 */
	private function about_block() {
		$about     = $this->text( $this->settings->identity( 'about', '' ) );
		$not       = $this->text( $this->settings->identity( 'not_description', '' ) );
		$audience  = $this->text( $this->settings->identity( 'audience', '' ) );
		$expertise = array_values( array_filter( array_map( 'trim', (array) $this->settings->identity( 'expertise', array() ) ) ) );

		if ( '' === $about && '' === $not && '' === $audience && empty( $expertise ) ) {
			return '';
		}

		$out = "\n## About\n\n";
		if ( '' !== $about ) {
			$out .= $about . "\n";
		}
		if ( '' !== $not ) {
			$out .= "\nWhat this is not: " . $not . "\n";
		}
		if ( '' !== $audience ) {
			$out .= "\nAudience: " . $audience . "\n";
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
		$exclude = apply_filters( 'agentimus_topic_exclude', array( 'uncategorized' ) );

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
			// Skip junk/placeholder categories that are just a number ("67", a stray
			// ID) — same rule the per-post topics use, so the site directory stays clean.
			if ( ! Topics::is_meaningful_topic_name( $cat->name, $cat ) ) {
				continue;
			}
			$desc = $this->text( $cat->description );
			if ( '' === $desc ) {
				/* translators: %s: number of posts. */
				$desc = sprintf( _n( '%s article', '%s articles', $cat->count, 'agentimus' ), number_format_i18n( $cat->count ) );
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
		// Collapse ALL whitespace (newlines, tabs, runs) to single spaces. Every caller emits a
		// SINGLE-LINE value — a "- [Title](url)" list entry, an identity line — so a newline in a
		// post title, category name, or an identity field would forge a fake list entry or break
		// the document's line structure. Reachable via a settings textarea (newline-preserving), a
		// content import, or a direct DB write, so strip it here at the single choke point. Decode
		// runs first, so an entity-encoded newline (&#10;) is caught too. `\s` matches only ASCII
		// whitespace bytes, never a UTF-8 continuation byte, so this is multibyte-safe without /u.
		$text = (string) preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}
}
