<?php
/**
 * Settings store — a single option array, with defaults, typed getters and
 * sanitisation. The one source of truth shared by every module and the REST API.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'agentimus_settings';

	/** Decision dates for the block/trust lists: { allow: { token → unix }, block: { … } }.
	 *  A side option (autoload off), written by {@see update()} whenever a token enters or
	 *  leaves blocked_agents / allowed_agents — so BOTH the review queue's one-click
	 *  Allow/Block and a manual chip edit get dated through the one seam they share.
	 *  Purely informational (the client manager shows "blocked on …"); the lists
	 *  themselves stay the plain string arrays they have always been, so nothing
	 *  existing changes shape. Entries predating this option simply have no date. */
	const DECISIONS_OPTION = 'agentimus_client_decisions';

	/**
	 * The retention periods and row caps the UI offers, in the order it offers them. The
	 * sanitiser snaps to these, and the settings form renders from them, so the two can
	 * never drift apart.
	 */
	const RETENTION_CHOICES = array( 7, 14, 30, 60, 90, 180, 365 );
	const MAX_ROWS_CHOICES  = array( 10000, 25000, 50000, 100000, 250000 );

	/**
	 * Default settings. Identity defaults stay deliberately empty so the admin
	 * is nudged to fill in a real author/organisation profile rather than ship
	 * a generic one.
	 *
	 * @return array
	 */
	public function defaults() {
		$defaults = array(
			'enable_llms_txt'  => true,
			'enable_llms_full' => true,
			'enable_markdown'  => true,
			'enable_robots'    => true,
			'enable_schema'    => true,
			'enable_topics'    => true,  // Per-page AI topics → JSON-LD keywords + per-page markdown front matter. On by default; each page still controls its own topics via the editor meta box.
			'enable_ai_description' => true, // Per-page AI description → JSON-LD `description` + the per-page markdown lead, plus (when ai_description_meta_tag is on) the HTML <meta name="description">. On by default; blank pages fall back to the excerpt. Each page sets its own value via the editor meta box.
			'ai_description_meta_tag' => true, // Sub-option of enable_ai_description: also make the AI description the page's <meta name="description"> — replacing the theme's, still deferring wholesale to a real SEO plugin. Off = enrich only JSON-LD + .md and never touch the <head>. See Description::should_emit()/meta_tag_enabled().
			'topics_derive_default' => true, // Default for new posts: also derive topics from the page's tags & categories (a manual list still wins).
			'topics_max'       => 12,    // Hard cap on emitted topics per page, to keep the machine surfaces lean.
			'enable_activity'  => true,
			// How long agent hits + AI-referral counts are KEPT. The dashboard reports on at
			// most 30 days of that (Repository::report_days), so keeping more gives the
			// Request log a deeper history without stretching the summary cards. Flagged IPs
			// are NOT governed by this — they are the only PII stored and keep their own
			// shorter retention (FlaggedIps::RETENTION_DAYS).
			'activity_retention_days' => 30,
			// Age-based pruning. OFF does not mean "keep forever": the row cap below still
			// trims oldest-first, so records are kept until the log reaches that size. There
			// is deliberately no "no limit" option — an unbounded table is how a shared host
			// runs out of disk. See Repository::prune() / trim_to_cap().
			'activity_auto_prune' => true,
			// Hard ceiling on stored rows, whatever the retention. ~300–700 bytes/row all-in,
			// so 50,000 rows is roughly 13–33 MB.
			'activity_max_rows' => 50000,
			'enable_referral_beacon' => false, // Opt-in "CDN mode": count AI referrals via a tiny front-end beacon instead of server-side, so the count survives a full-page CDN/edge cache (which hides server-side hits). OFF by default — a normal site adds NO front-end script and counts server-side. When ON, the server-side recorder stands down (the two can't be deduped: no per-visit id). See Activity\Referrals::beacon_enabled().
			'log_unknown_referrers' => false, // Opt-in diagnostic: when a human browser arrives from a referrer/utm_source the AI-source map does NOT recognise, record just that host/token so the owner can see what "Traffic from AI" is missing (a miss is otherwise invisible). OFF by default because, unlike the referral counter, it writes on EVERY externally referred page view — it's meant to run for a week, not forever. See Activity\UnknownSources.
			'enable_page_checks' => true, // Editor-only "AI Readability" panel: per-page pass/warn checks (headings, summary, thin content, link density, image alt). No front-end output — an authoring aid — so it's safe on by default.
			'enable_visibility' => false, // Opt-in "AI Visibility" (citation tracking): enable the AI Visibility screen (More menu) AND the Cited rung in the score. OFF by default — it's BYOK (spends the owner's AI credit) and most sites won't use it, so citation only enters the UI + the composite when explicitly turned on. When off the score is a clean 4-rung ladder (Cited excluded, weight redistributed).
			'enable_sitemap'   => true, // Gap-only fallback: stands down when core/SEO provides one, so it's safe on by default.
			'enable_changes'   => true, // JSON change feed at /agentimus-changes.json — recently added/updated pages, with a `?since=` delta filter so agents re-check only what changed. Read-only and cheap (one bounded, cached query), so on by default like the sitemap.
			'bypass_shared_cache' => false, // Opt-in: send `Cache-Control: no-store` on the AI endpoints (llms.txt, the .well-known docs, robots.txt, the change feed) so a shared cache/CDN in front of WordPress doesn't serve stored copies — keeping agent fetches counted in the activity log and freshness-sensitive endpoints current. OFF by default (the docs are edge-cacheable for performance). Only affects caches that RESPECT Cache-Control. See CacheHeaders.
			'purge_cache_on_change' => true, // ON by default: when content changes, ask every detected page cache (WP Rocket, Nginx Helper, W3TC, LiteSpeed, WP Super Cache, Cache Enabler, or an `agentimus_purge_url` listener) to drop the agent files — llms.txt/llms-full.txt/robots.txt/the change feed/the .well-known docs, plus the edited post's own .md twin — so a cache can't serve a stale copy after an edit. Feature-detected (no-op with no cache present). Freshness only, NOT the activity-log under-count (that needs the fetch to reach WP; see bypass_shared_cache). See CachePurge.
			'enable_security_txt' => false, // Opt-in: generate /.well-known/security.txt only when asked AND no file/other plugin already provides one.
			'enable_signing'   => true,     // On by default: RFC 9421 signatures on the discovery docs + a published Web Bot Auth key directory. Feature-detected — silently stands down when libsodium is unavailable, so it's safe as a default.
			'enable_webmcp'    => false,    // Experimental, opt-in: register the site's READ-ONLY tools with in-browser AI agents via the WebMCP browser API (navigator.modelContext). Off by default — it's an emerging, Chrome-experimental standard and it adds a small front-end script, so it ships only when the owner asks. The script is inert in browsers without the API.
			'enable_mcp_server' => false,   // Opt-in: boot the BUNDLED WordPress MCP Adapter and publish the scoped, read-only Agentimus MCP server at /wp-json/agentimus/v1/mcp (application-password/OAuth authenticated; every tool keeps its own permission check). OFF by default — it opens an authenticated machine endpoint, so it ships only when the owner asks. No-ops below WP 6.9 (needs the Abilities API). See Abilities\AdapterBootstrap + Abilities\Registrar::register_mcp_server().
			'enable_agent_writes' => false, // Second, separate opt-in on top of the MCP server: register the WRITE abilities (create/edit drafts, set a page's AI topics/description, apply a readiness fix). OFF by default so "the MCP server is read-only" stays true until the owner deliberately says otherwise. Gates REGISTRATION, not just MCP exposure — off means the write abilities don't exist on any surface (MCP, abilities REST, the in-admin assistant). Requires enable_mcp_server: it renders as a sub-toggle of the MCP card, so sanitize() cascades it off when the server is off (no invisible armed switch). Every write still runs as the authenticated user with that user's capabilities. See Abilities\Registrar::register_write_abilities().
			'agent_writes_publish' => false, // Sub-option of enable_agent_writes (and cascaded off with it, same rule): allow an agent to PUBLISH (status=publish) rather than only draft/pending. OFF by default — "write drafts I review" and "publish to my live site" are different levels of trust, so going live stays a human decision until this is flipped. The user's publish_posts capability is still required on top. See Abilities\ContentWriter::validate_status().
			'webmcp_hidden_tools' => array(), // Owner opt-OUT: names of WebMCP tools to hide from browser agents (deny-list; empty = expose every registered tool). Lets the owner curate per-tool which to expose.
			'llms_full_posts'  => 50,
			'llms_full_max_kb' => 900, // Hard byte budget for /llms-full.txt (KB): generation stops cleanly here and links the index. Deliberately UNDER 1024, not at it: a ~1 MB body sits exactly at the common memcached item ceiling, so with the key + serialization overhead the object cache silently REJECTS it — then every request re-runs the full build. 900 KB leaves headroom so the document actually caches. Filterable higher for sites whose cache (or lack of one) can take it.
			'post_types'       => self::default_post_types(),
			'evergreen_categories' => array(), // Category term IDs whose posts are exempt from the content "freshness" check — timeless content (references, tutorials, legal) that doesn't go stale with age. Empty = every post is age-checked.
			'optimize_ignored'     => array(), // Post IDs the owner marked "not cited content" from the Optimize worklist — pages that aren't meant to be quoted (landing/utility/index). Left out of citability grading entirely; always shown as a visible "set aside" count so the score stays honest.
			'rest_namespaces'  => array(), // Owner-curated REST namespaces to publish in discovery (opt-in; empty = none).
			'oauth_auth_server' => '',     // Optional OAuth authorization-server URL; when set, serve RFC 9728 protected-resource metadata. Never fabricate RFC 8414.
			'suppressed_resources' => array(), // Owner opt-OUT: ids of provider-registered Resources to hide from all output. Declared Resources default to published (spec §04), so empty = publish everything a provider declared.
			'identity'         => array(
				'entity_type'   => 'Person', // Person, or an Organization subtype (Organization, LocalBusiness, Store, …) — see entity_types().
				'name'          => get_bloginfo( 'name' ),
				'role'          => '',
				'about'         => '',
				'not_description' => '', // What the site is NOT — a disambiguation so agents don't miscategorize it (Schema.org disambiguatingDescription).
				'audience'      => '', // Who the site is for (Schema.org audience.audienceType).
				'contact_email' => '', // Opt-in; published in discovery.json only if set.
				'expertise'     => array(),
				'same_as'       => array(),
				'services'      => array(), // Opt-in: owner-declared services [{name, description, url}] → Schema.org Service. Empty = none (never guessed from content).
			),
			'security'         => array(
				'contacts'            => array(), // Extra Contact URIs/emails beyond identity.contact_email (which seeds the first one).
				'policy'              => '',       // URL to the vuln-disclosure policy.
				'acknowledgments'     => '',       // URL to a hall-of-fame / thanks page.
				'encryption'          => '',       // URL to an OpenPGP key.
				'hiring'              => '',       // URL to security job listings.
				'preferred_languages' => '',       // e.g. "en, fr"; empty falls back to the site locale.
				'expires_days'        => 182,      // RFC 9116 SHOULD keep Expires < 1 year.
			),
			'content_signal'   => array(
				'search'   => true,
				'ai_input' => true,
				'ai_train' => false,
			),
			'blocked_trainers' => self::known_trainers(),
			// AI-usage signals beyond robots.txt: publish the SAME ai-train decision
			// where robots.txt can't reach — a W3C TDM Reservation Protocol response
			// header and /.well-known/tdmrep.json. On by default so the existing
			// "no AI training" default stance is asserted in every channel, not just
			// the advisory one ("one decision, every channel").
			'enable_ai_header'    => true,  // tdm-reservation header on content pages (only when training is blocked).
			'enable_tdmrep'       => true,  // serve /.well-known/tdmrep.json (only when training is blocked).
			'ai_noai_header'      => false, // also send the non-standard X-Robots-Tag: noai (best-effort).
			'tdm_policy_url'      => '',     // optional URL to the owner's TDM / AI-usage policy.
			// Optional HARD enforcement (opt-in). robots.txt above is advisory; this
			// actually refuses the request with a 403. Off by default so a fresh
			// install never silently blocks anyone.
			'block_agents'     => false, // Master switch for denying agents at the generated endpoints.
			'block_spoofed'    => true,  // When blocking is on, also deny the DECEPTIVE classes: spoofed/legacy-device UAs (the "Likely spoof/scanner" class) and — with verify_bots on — PROVEN impostors (a claimed verified bot whose address conclusively failed the operator's published check; see Guard::conclusively_forged). No effect while block_agents is false.
			'verify_bots'      => false, // Opt-in: forward-confirm a claimed search engine by reverse DNS. Two independent effects — it flags a proven impersonator in the review queue (and records the verdict for "Check this bot"), and, WHEN blocking is on, stops a spoofed "Googlebot" UA inheriting a real crawler's always-allow. OFF by default because it makes outbound DNS lookups (all this plugin ever makes).
				'store_flagged_ips' => false, // Opt-in, minimised IP capture: store the source IP ONLY for clients flagged as an impersonator (failed reverse-DNS) or a legacy-device spoof, so the review card can show real addresses to block. OFF by default — the ONLY setting that makes the plugin keep personal data. Short retention, cleared with the log, PURGED when switched back off. See Activity\FlaggedIps.
				'identify_bots'    => false, // Opt-in: reverse-DNS EVERY recorded bot hit (not just claimed search engines) and store the owning NETWORK (e.g. amazonaws.com, googlebot.com) — the "who is accessing me" attribution — WITHOUT the IP (the resolved network is org-level, not personal). Verifiable engines additionally get their verified/impostor verdict from the same lookup. OFF by default because it makes outbound DNS (bounded by the same budget/breaker as verify_bots). See BotVerifier::attribute_ip.
			'blocked_agents'   => array(), // Owner's custom user-agent substrings to deny (case-insensitive). Empty = none.
			'allowed_agents'   => array(), // Owner's trust-list (via the activity panel's "Allow"): never blocked, never flagged for review. Empty = none.
				'verifier_disabled' => array(), // Tokens of BUILT-IN verified-bot registry entries the owner switched off. A disabled entry's bot simply becomes unverifiable (verdicts stay 0) — nothing is ever flagged by an absence. See VerifierRegistry.
				'verifier_custom'   => array(), // Owner-ADDED verified-bot entries: { token, label, ua, domains[], url }. For operators that publish verification data (rDNS suffixes and/or an IP-range file) after this release — the owner shouldn't wait for a plugin update to trust them. Sanitised hard in sanitize(); bounded count.
			// Exposure controls — reduce what an ANONYMOUS visitor (crawler / bot /
			// scanner) can read about the site. All opt-in / OFF by default: each one
			// changes a stock WordPress behaviour, so it ships only when the owner asks,
			// and is scoped to logged-out requests (admins/the editor are unaffected).
			'hide_user_enumeration'   => false, // Block anonymous author/user enumeration: wp/v2/users, ?author=N, the core users-sitemap, and the oEmbed author fields.
			'disable_author_archives' => false, // Turn /author/* archive pages into 404s for anonymous visitors (also catches ?author=N) — removes another surface that carries the username slug.
			'hide_wp_version'         => false, // Remove the WordPress version fingerprint: the <meta generator> tag, the feed generator, and the core ?ver= on core assets.
			'tidy_head_links'         => false, // Strip rarely-used auto-generated discovery links (shortlink, oEmbed discovery, RSD/WLW) from the page head + Link header — trims the scrapeable footprint. Keeps the REST api.w.org link (intentional discovery).
			'disable_xmlrpc'          => false, // Disable legacy XML-RPC (xmlrpc.php) — the pingback / system.multicall brute-force-amplification + DDoS surface. Modern clients use the REST API.
			'exposed_extra_paths'     => array(), // Owner-added paths for the "exposed files" self-check (Settings → Exposure), on top of the built-in list. Scan runs browser-side from Readiness; see Exposure::sensitive_paths().
			// Agent access — record who authenticates to, and acts on, the machine surface this
			// plugin creates: application passwords being minted/used/revoked, and abilities being
			// invoked. ON by default, unlike the opt-in controls above, because it is the rare
			// one with nothing to trade off: it stores no personal data (no IP, no location — see
			// AgentAccess\Table), makes no outbound request, and its hot path is an object-cache
			// read. It observes and reports; it never blocks anything.
			'agent_access_events'     => true,
		);

		/**
		 * Filter the default settings.
		 *
		 * @param array $defaults Default settings.
		 */
		$filtered = apply_filters( 'agentimus_default_settings', $defaults );
		// A misbehaving plugin must not be able to hand back a non-array here: the
		// whole admin app and every endpoint resolve their config through this.
		return is_array( $filtered ) ? $filtered : $defaults;
	}

	/**
	 * The canonical catalogue of known model-training crawlers. Seeds the default
	 * blocklist and powers the "add a known crawler" suggestions in the admin, so
	 * a user who removes one can always find its exact user-agent again.
	 *
	 * @return string[]
	 */
	public static function known_trainers() {
		$known = array(
			'Amazonbot',
			'Applebot-Extended',
			'Bytespider',
			'CCBot',
			'ClaudeBot',
			'GPTBot',
			'Google-Extended',
			'meta-externalagent',
		);

		/**
		 * Filter the known training-crawler catalogue.
		 *
		 * @param string[] $known User-agent tokens.
		 */
		$known = (array) apply_filters( 'agentimus_known_trainers', $known );
		return array_values( array_unique( array_filter( array_map( 'trim', $known ) ) ) );
	}

	/**
	 * Suggested user-agents for the hard-block denylist: aggressive SEO/scraper
	 * crawlers that frequently ignore robots.txt. Powers the "add a known scanner"
	 * chips in the admin — purely suggestions; nothing is denied until the owner
	 * adds it AND turns blocking on. (The legacy-device spoof class is handled
	 * separately by the block_spoofed heuristic, so it isn't duplicated here.)
	 *
	 * @return string[]
	 */
	public static function known_scanners() {
		$known = array(
			'AhrefsBot',
			'SemrushBot',
			'DotBot',
			'MJ12bot',
			'BLEXBot',
			'PetalBot',
			'DataForSeoBot',
			'SeekportBot',
			'serpstatbot',
			'ZoominfoBot',
		);

		/**
		 * Filter the suggested scanner catalogue shown in the admin denylist UI.
		 *
		 * @param string[] $known User-agent tokens.
		 */
		$known = (array) apply_filters( 'agentimus_known_scanners', $known );
		return array_values( array_unique( array_filter( array_map( 'trim', $known ) ) ) );
	}

	/**
	 * Suggested user-agents for the always-allow trust-list: well-known AI agents
	 * that read or answer on a user's behalf (assistants and answer engines).
	 * Powers the "add a trusted AI agent" chips under the allow-list — purely
	 * suggestions; nothing is trusted until the owner adds it. The built-in search
	 * engines (Googlebot, Bingbot, …) are NOT here: they are always allowed
	 * structurally by Guard::engine_signatures(), so listing them would be noise.
	 *
	 * Deliberately EXCLUDES training crawlers (GPTBot, ClaudeBot, CCBot,
	 * Google-Extended, Bytespider, Amazonbot, meta-externalagent) — those belong to
	 * the training opt-out (blocked_trainers / the ai-train signal), not the trust
	 * list, and allow-listing a crawler you may be reserving against would
	 * contradict that choice.
	 *
	 * @return string[]
	 */
	public static function known_allowed() {
		$known = array(
			'ChatGPT-User',         // OpenAI — fetches a page when a ChatGPT user asks about it.
			'OAI-SearchBot',        // OpenAI — powers ChatGPT search citations.
			'Claude-User',          // Anthropic — Claude fetching on a user's behalf.
			'Claude-SearchBot',     // Anthropic — Claude search.
			'PerplexityBot',        // Perplexity — answer-engine index.
			'Perplexity-User',      // Perplexity — on-demand user fetch.
			'DuckAssistBot',        // DuckDuckGo — AI-assist answers.
			'MistralAI-User',       // Mistral — Le Chat on-demand fetch.
			'Meta-ExternalFetcher', // Meta — on-demand fetch (not the meta-externalagent trainer).
		);

		/**
		 * Filter the suggested always-allow catalogue shown in the admin trust-list UI.
		 *
		 * @param string[] $known User-agent tokens.
		 */
		$known = (array) apply_filters( 'agentimus_known_allowed', $known );
		return array_values( array_unique( array_filter( array_map( 'trim', $known ) ) ) );
	}

	/**
	 * The privacy-safe default content selection: posts and pages only. Every
	 * other public post type (WooCommerce products, CRM/support/forms/boards
	 * CPTs, …) is opt-IN, so a fresh install never advertises or dumps content
	 * the owner didn't choose. Falls back to all public types only on the rare
	 * site that registers neither `post` nor `page`.
	 *
	 * @return string[]
	 */
	public static function default_post_types() {
		$available = Content::available();
		$safe      = array_values( array_intersect( array( 'post', 'page' ), $available ) );
		return ! empty( $safe ) ? $safe : $available;
	}

	/**
	 * The full, merged settings array.
	 *
	 * @return array
	 */
	public function all() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = $this->defaults();
		$all      = wp_parse_args( $saved, $defaults );

		// wp_parse_args is shallow, so a stored ASSOCIATIVE nested setting (identity,
		// security, content_signal) would replace its default wholesale and lose any
		// sub-key added in a later version — silently dropping, e.g., a content-signal /
		// AI-training default. Deep-merge each so a stored value keeps its own keys but
		// still inherits new ones. List defaults (blocked_trainers, post_types…) are left
		// alone: an empty stored list is a deliberate "none", not "inherit the defaults".
		foreach ( $defaults as $key => $default_value ) {
			if ( is_array( $default_value ) && self::is_assoc( $default_value ) ) {
				$all[ $key ] = wp_parse_args(
					isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array(),
					$default_value
				);
			}
		}

		/**
		 * Filter the resolved settings on read.
		 *
		 * @param array $all Resolved settings.
		 */
		$filtered = apply_filters( 'agentimus_settings', $all );
		// Keep the valid resolved array if a filter mangles the shape — this feeds
		// wp_localize_script (the admin boot) and every reader of the settings.
		return is_array( $filtered ) ? $filtered : $all;
	}

	/**
	 * A single top-level value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * A boolean toggle.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function enabled( $key ) {
		return (bool) $this->get( $key, false );
	}

	/**
	 * An identity sub-value (name, about, expertise, …).
	 *
	 * @param string $key     Identity key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function identity( $key, $default = null ) {
		$identity = (array) $this->get( 'identity', array() );
		return array_key_exists( $key, $identity ) ? $identity[ $key ] : $default;
	}

	/**
	 * Persist a (raw) settings array after sanitising it.
	 *
	 * @param array $input Raw input (e.g. from REST).
	 * @return array The sanitised, stored settings.
	 */
	public function update( array $input ) {
		$prev  = get_option( self::OPTION, array() );
		$clean = $this->sanitize( $input );
		update_option( self::OPTION, $clean );
		$this->record_decisions( is_array( $prev ) ? $prev : array(), $clean );
		Cache::flush();
		// Opted back OUT of flagged-IP storage → purge what was kept, so the plugin never
		// holds personal data the owner just declined. A listener (Activity\Module) clears
		// the store; firing an action keeps Settings decoupled from the table.
		if ( ! empty( $prev['store_flagged_ips'] ) && empty( $clean['store_flagged_ips'] ) ) {
			/** Fires when the owner disables flagged-IP capture; purge the stored IPs. */
			do_action( 'agentimus_flagged_ips_purge' );
		}
		return $clean;
	}

	/**
	 * Add one user-agent token to the hard-block denylist AND arm enforcement —
	 * the persistence side of the activity panel's one-click "Block this client".
	 * The denylist is inert while block_agents is off, so a "block" that didn't turn
	 * blocking on would silently do nothing; flipping it on here makes the see-it →
	 * block-it loop actually bite. Reads the full resolved settings and writes them
	 * back through update() (a partial update would reset omitted keys to defaults).
	 * Idempotent: a token already present (case-insensitively) is not duplicated.
	 *
	 * @param string $token Raw user-agent substring (or glob/regex) to deny.
	 * @return array The stored settings.
	 */
	public function block_agent( $token ) {
		$token = substr( trim( (string) $token ), 0, 200 );
		$all   = $this->all();
		if ( '' !== $token ) {
			$list  = array_values( (array) $all['blocked_agents'] );
			$lower = array_map( 'strtolower', $list );
			if ( ! in_array( strtolower( $token ), $lower, true ) ) {
				$list[] = $token;
			}
			$all['blocked_agents'] = $list;
		}
		$all['block_agents'] = true;
		return $this->update( $all );
	}

	/**
	 * Arm hard enforcement for the spoofed/legacy-device class — the action behind
	 * "Block scanners" on a flagged spoof row. Turns on the master switch and the
	 * spoof heuristic together (no per-UA token: the class is matched by
	 * {@see \Agentimus\Activity\Classifier::is_spoof()}). Full read-modify-write, as
	 * in {@see block_agent()}.
	 *
	 * @return array The stored settings.
	 */
	public function block_spoofed_class() {
		$all                  = $this->all();
		$all['block_agents']  = true;
		$all['block_spoofed'] = true;
		return $this->update( $all );
	}

	/**
	 * Record when a token entered the block/trust lists, and forget the date when it
	 * leaves. Compares the two settings snapshots case-insensitively (the lists dedupe
	 * that way) and only writes when something actually changed. Times are unix GMT.
	 *
	 * @param array $prev  Stored settings before this update (may be empty).
	 * @param array $clean Sanitized settings just written.
	 */
	private function record_decisions( array $prev, array $clean ) {
		$map     = get_option( self::DECISIONS_OPTION, array() );
		$map     = is_array( $map ) ? $map : array();
		$changed = false;

		foreach ( array(
			'allow' => 'allowed_agents',
			'block' => 'blocked_agents',
		) as $kind => $list_key ) {
			$old = array_map( 'strtolower', array_map( 'strval', (array) ( $prev[ $list_key ] ?? array() ) ) );
			$new = array_map( 'strtolower', array_map( 'strval', (array) ( $clean[ $list_key ] ?? array() ) ) );
			if ( ! isset( $map[ $kind ] ) || ! is_array( $map[ $kind ] ) ) {
				$map[ $kind ] = array();
			}
			foreach ( array_diff( $new, $old ) as $token ) {
				$map[ $kind ][ $token ] = time();
				$changed                = true;
			}
			foreach ( array_diff( $old, $new ) as $token ) {
				if ( isset( $map[ $kind ][ $token ] ) ) {
					unset( $map[ $kind ][ $token ] );
					$changed = true;
				}
			}
		}

		if ( $changed ) {
			update_option( self::DECISIONS_OPTION, $map, false );
		}
	}

	/**
	 * The decision-date map: { allow: { lowercased token → unix }, block: { … } }.
	 *
	 * @return array{allow:array<string,int>,block:array<string,int>}
	 */
	public static function decisions() {
		$map = get_option( self::DECISIONS_OPTION, array() );
		$map = is_array( $map ) ? $map : array();
		return array(
			'allow' => isset( $map['allow'] ) && is_array( $map['allow'] ) ? $map['allow'] : array(),
			'block' => isset( $map['block'] ) && is_array( $map['block'] ) ? $map['block'] : array(),
		);
	}

	/**
	 * Add one user-agent token to the owner's trust-list — the "Allow" action on a
	 * flagged row. An allowed agent is treated like a protected search engine: never
	 * blocked (even by a broad rule) and never flagged for review again. Full
	 * read-modify-write, deduped case-insensitively (see {@see block_agent()}).
	 *
	 * @param string $token Raw user-agent substring to trust.
	 * @return array The stored settings.
	 */
	public function allow_agent( $token ) {
		$token = substr( trim( (string) $token ), 0, 200 );
		$all   = $this->all();
		if ( '' !== $token ) {
			$list  = array_values( (array) $all['allowed_agents'] );
			$lower = array_map( 'strtolower', $list );
			if ( ! in_array( strtolower( $token ), $lower, true ) ) {
				$list[] = $token;
			}
			$all['allowed_agents'] = $list;
		}
		return $this->update( $all );
	}

	/**
	 * Remove one token from a client list — the client manager's "Unblock" /
	 * "Un-trust". The inverse of {@see block_agent()} / {@see allow_agent()}:
	 * same full read-modify-write, same case-insensitive match. Removing a
	 * blocked token deliberately does NOT touch the block_agents master switch —
	 * un-listing one client is not a decision about enforcement as a whole.
	 *
	 * @param string $token Token to remove (case-insensitive).
	 * @param string $list_key 'allowed_agents' or 'blocked_agents'.
	 * @return array The stored settings.
	 */
	public function remove_agent_token( $token, $list_key ) {
		$list_key = 'blocked_agents' === $list_key ? 'blocked_agents' : 'allowed_agents';
		$needle   = strtolower( trim( (string) $token ) );
		$all      = $this->all();
		if ( '' !== $needle ) {
			$all[ $list_key ] = array_values(
				array_filter(
					(array) $all[ $list_key ],
					static function ( $t ) use ( $needle ) {
						return strtolower( (string) $t ) !== $needle;
					}
				)
			);
		}
		return $this->update( $all );
	}

	/**
	 * Restore every setting to its factory default, wiping the stored option and
	 * any generated caches. Identity text, crawler policy and feature toggles all
	 * revert. Returns the resolved (default) settings.
	 *
	 * @return array
	 */
	public function reset() {
		delete_option( self::OPTION );
		delete_option( self::DECISIONS_OPTION ); // The lists are gone; their dates go too.
		add_option( self::OPTION, $this->defaults() );
		Cache::flush();

		/**
		 * Fires after settings are reset to defaults (a Pro add-on can clear its
		 * own state too).
		 */
		do_action( 'agentimus_settings_reset' );

		return $this->all();
	}

	/**
	 * Seed defaults on activation if the option is absent.
	 */
	public function ensure_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $this->defaults() );
		}
	}

	/** Bump when a new step is added to {@see maybe_migrate_defaults()}. */
	const DEFAULTS_MIGRATION        = 2;
	const DEFAULTS_MIGRATED_OPTION  = 'agentimus_defaults_migrated';

	/**
	 * One-shot, step-versioned migrations for installs upgrading from an older release. Each step
	 * runs at most once (gated on the stored step number), so a later bump never re-runs an earlier
	 * step — which is what lets a step that nudges a default coexist with the owner later re-choosing
	 * the old value without being fought.
	 *
	 *   Step 1 — lower the un-customised llms-full budget from the old 1024 KB (which sat exactly at
	 *            the common 1 MB object-cache item ceiling, so /llms-full.txt silently failed to
	 *            cache) to 900. Only touches a value still EQUAL to the old default; a value the
	 *            owner chose is left alone.
	 *   Step 2 — force-autoload the tiny flags that are read on EVERY boot (the six *_db_version
	 *            stamps + this migration flag). update_option() does not flip an existing row's
	 *            autoload, so an install upgraded from a release that wrote them autoload=off would
	 *            keep paying one indexed query per read on a site with no persistent object cache.
	 *
	 * @return void
	 */
	public function maybe_migrate_defaults() {
		$done = (int) get_option( self::DEFAULTS_MIGRATED_OPTION, 0 );
		if ( $done >= self::DEFAULTS_MIGRATION ) {
			return;
		}

		// Step 1 — the llms-full default nudge. Runs only if this install has never done it, so a
		// later step bump can't re-nudge a value the owner deliberately re-chose after step 1.
		if ( $done < 1 ) {
			$saved = get_option( self::OPTION );
			if ( is_array( $saved ) && isset( $saved['llms_full_max_kb'] ) && 1024 === (int) $saved['llms_full_max_kb'] ) {
				$saved['llms_full_max_kb'] = 900;
				update_option( self::OPTION, $saved );
			}
		}

		// Step 2 — flip the per-boot flags to autoloaded in place. wp_set_option_autoload() is WP
		// 6.4+; below that (our 6.0 floor) the install keeps the old per-request read — a tiny,
		// shrinking population, and a perf nuance, not a defect.
		if ( $done < 2 && function_exists( 'wp_set_option_autoload' ) ) {
			foreach ( self::per_boot_flag_options() as $flag ) {
				if ( false !== get_option( $flag, false ) ) {
					wp_set_option_autoload( $flag, true );
				}
			}
		}

		update_option( self::DEFAULTS_MIGRATED_OPTION, self::DEFAULTS_MIGRATION );
	}

	/**
	 * The small flags read on every boot (schema-version stamps + the migration flag). They belong
	 * in the single autoloaded alloptions load rather than a per-request query each. Fresh writes
	 * already default to autoloaded; {@see maybe_migrate_defaults()} step 2 flips existing rows.
	 *
	 * @return string[]
	 */
	private static function per_boot_flag_options() {
		return array(
			self::DEFAULTS_MIGRATED_OPTION,
			'agentimus_activity_db_version',
			'agentimus_referrals_db_version',
			'agentimus_unknown_sources_db_version',
			'agentimus_flagged_ips_db_version',
			'agentimus_agent_access_db_version',
			'agentimus_visibility_db_version',
		);
	}

	/**
	 * The selectable schema.org entity types (filterable). 'Person' is the human
	 * case; the rest are Organization subtypes (a shop is a Store → LocalBusiness →
	 * Organization). Add-ons/devs can extend via the `agentimus_entity_types` filter
	 * (e.g. add 'Restaurant'). 'Person' is always guaranteed present so the
	 * sanitiser's fallback stays valid.
	 *
	 * @return string[]
	 */
	public function entity_types() {
		$types = array( 'Person', 'Organization', 'LocalBusiness', 'Store' );
		$types = (array) apply_filters( 'agentimus_entity_types', $types );
		$types = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );
		return in_array( 'Person', $types, true ) ? $types : array_merge( array( 'Person' ), $types );
	}

	/**
	 * Sanitise a raw settings array against the schema.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( array $input ) {
		$defaults = $this->defaults();
		$clean    = array();

		// Every top-level BOOLEAN setting is sanitised uniformly and AUTOMATICALLY from
		// the schema: a checkbox that is present becomes its bool; one omitted from a
		// partial update keeps its DEFAULT (so a partial save never silently flips a flag
		// off). Deriving the set from defaults() means a new switch is saveable the moment
		// it is added there — there is NO second list to keep in sync, so a toggle can
		// never silently fail to save. SettingsDefaultsTest locks this guarantee in.
		foreach ( $defaults as $flag => $default ) {
			if ( is_bool( $default ) ) {
				$clean[ $flag ] = ! isset( $input[ $flag ] ) ? $default : ! empty( $input[ $flag ] );
			}
		}

		// The agent-writes ladder collapses downward: each rung lives in the UI as a
		// sub-toggle of the one above (writes inside the MCP card, publish inside
		// writes), so a rung whose parent is off must not stay armed invisibly — an
		// owner who turns the MCP server off, then back on months later, must not
		// silently resurrect write access they can no longer see. Registrar re-checks
		// the same implication at registration time; this makes the STORED state match.
		if ( empty( $clean['enable_mcp_server'] ) ) {
			$clean['enable_agent_writes'] = false;
		}
		if ( empty( $clean['enable_agent_writes'] ) ) {
			$clean['agent_writes_publish'] = false;
		}

		// Per-page AI topics: the two toggles are handled by the boolean loop above; only
		// the numeric cap needs its own clamp.
		$topics_max          = isset( $input['topics_max'] ) ? (int) $input['topics_max'] : $defaults['topics_max'];
		$clean['topics_max'] = max( 1, min( 50, $topics_max ) );

		$posts                    = isset( $input['llms_full_posts'] ) ? (int) $input['llms_full_posts'] : $defaults['llms_full_posts'];
		$clean['llms_full_posts'] = max( 1, min( 500, $posts ) );

		// Activity retention + row cap: snap to the offered choices rather than clamping to a
		// range. A value the UI never offers (say 37 days) is a sign of a tampered payload, and
		// silently accepting it would make the dropdown lie about what's stored. `auto_prune` is
		// a bool, so the loop above already handled it.
		$retention                        = isset( $input['activity_retention_days'] ) ? (int) $input['activity_retention_days'] : $defaults['activity_retention_days'];
		$clean['activity_retention_days'] = in_array( $retention, self::RETENTION_CHOICES, true ) ? $retention : $defaults['activity_retention_days'];

		$max_rows                    = isset( $input['activity_max_rows'] ) ? (int) $input['activity_max_rows'] : $defaults['activity_max_rows'];
		$clean['activity_max_rows'] = in_array( $max_rows, self::MAX_ROWS_CHOICES, true ) ? $max_rows : $defaults['activity_max_rows'];

		$kb                        = isset( $input['llms_full_max_kb'] ) ? (int) $input['llms_full_max_kb'] : $defaults['llms_full_max_kb'];
		$clean['llms_full_max_kb'] = max( 64, min( 20480, $kb ) );

		$clean['oauth_auth_server'] = isset( $input['oauth_auth_server'] ) ? esc_url_raw( (string) $input['oauth_auth_server'] ) : '';

		$available           = Content::available();
		$requested           = $this->sanitize_list( isset( $input['post_types'] ) ? $input['post_types'] : array(), 'sanitize_key' );
		$clean['post_types'] = array_values( array_intersect( $requested, $available ) );
		if ( empty( $clean['post_types'] ) ) {
			// Never store an empty set (that would hide all content) — but never
			// silently widen to ALL public types either, which would leak every
			// CPT. Keep the current selection if there is one; otherwise fall
			// back to the privacy-safe default (posts + pages).
			$current             = array_values( array_intersect( (array) ( new self() )->get( 'post_types', array() ), $available ) );
			$clean['post_types'] = ! empty( $current ) ? $current : self::default_post_types();
		}

		// Owner-curated REST namespaces to publish (e.g. "wc/store/v1"). Keep only
		// namespace-safe characters; nothing is published unless explicitly listed.
		$ns_in                    = isset( $input['rest_namespaces'] ) && is_array( $input['rest_namespaces'] ) ? $input['rest_namespaces'] : array();
		$clean['rest_namespaces'] = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $ns ) {
							return preg_replace( '#[^a-z0-9/_-]#', '', strtolower( (string) $ns ) );
						},
						$ns_in
					)
				)
			)
		);

		// Owner opt-OUT list: ids of provider-declared Resources to suppress from
		// all served output. Stored by id so the choice survives the provider later
		// changing that Resource (spec §04, M14). Ids match the Resource slug shape.
		$sup_in                        = isset( $input['suppressed_resources'] ) && is_array( $input['suppressed_resources'] ) ? $input['suppressed_resources'] : array();
		$clean['suppressed_resources'] = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $id ) {
							return trim( (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $id ) ), '-' );
						},
						$sup_in
					)
				)
			)
		);

		// Content-Signal is a fixed vocabulary (search / ai-input / ai-train),
		// stored as booleans so no free-text can reach the public robots.txt.
		$signal_in               = isset( $input['content_signal'] ) && is_array( $input['content_signal'] ) ? $input['content_signal'] : array();
		$clean['content_signal'] = array(
			'search'   => ! empty( $signal_in['search'] ),
			'ai_input' => ! empty( $signal_in['ai_input'] ),
			'ai_train' => ! empty( $signal_in['ai_train'] ),
		);

		$identity_in          = isset( $input['identity'] ) && is_array( $input['identity'] ) ? $input['identity'] : array();
		$type                 = isset( $identity_in['entity_type'] ) ? (string) $identity_in['entity_type'] : 'Person';
		$clean['identity']    = array(
			'entity_type' => in_array( $type, $this->entity_types(), true ) ? $type : 'Person',
			'name'        => isset( $identity_in['name'] ) ? $this->clip( sanitize_text_field( (string) $identity_in['name'] ), 200 ) : '',
			'role'        => isset( $identity_in['role'] ) ? $this->clip( sanitize_text_field( (string) $identity_in['role'] ), 200 ) : '',
			'about'         => isset( $identity_in['about'] ) ? $this->clip( sanitize_textarea_field( (string) $identity_in['about'] ), 2000 ) : '',
			'not_description' => isset( $identity_in['not_description'] ) ? $this->clip( sanitize_textarea_field( (string) $identity_in['not_description'] ), 2000 ) : '',
			'audience'      => isset( $identity_in['audience'] ) ? $this->clip( sanitize_text_field( (string) $identity_in['audience'] ), 300 ) : '',
			'contact_email' => isset( $identity_in['contact_email'] ) ? sanitize_email( (string) $identity_in['contact_email'] ) : '',
			'expertise'     => $this->sanitize_list( isset( $identity_in['expertise'] ) ? $identity_in['expertise'] : array(), 'sanitize_text_field' ),
			'same_as'       => $this->sanitize_list( isset( $identity_in['same_as'] ) ? $identity_in['same_as'] : array(), 'esc_url_raw' ),
			'services'      => $this->sanitize_services( isset( $identity_in['services'] ) ? $identity_in['services'] : array() ),
		);

		// security.txt (RFC 9116) fields. Contacts accept an email or an http(s)/
		// mailto/tel URI; the rest are URLs; the freshness window is clamped < 1 year.
		$security_in       = isset( $input['security'] ) && is_array( $input['security'] ) ? $input['security'] : array();
		$clean['security'] = array(
			'contacts'            => $this->sanitize_list( isset( $security_in['contacts'] ) ? $security_in['contacts'] : array(), array( $this, 'sanitize_contact_value' ) ),
			'policy'              => isset( $security_in['policy'] ) ? esc_url_raw( (string) $security_in['policy'] ) : '',
			'acknowledgments'     => isset( $security_in['acknowledgments'] ) ? esc_url_raw( (string) $security_in['acknowledgments'] ) : '',
			'encryption'          => isset( $security_in['encryption'] ) ? esc_url_raw( (string) $security_in['encryption'] ) : '',
			'hiring'              => isset( $security_in['hiring'] ) ? esc_url_raw( (string) $security_in['hiring'] ) : '',
			'preferred_languages' => isset( $security_in['preferred_languages'] ) ? sanitize_text_field( (string) $security_in['preferred_languages'] ) : '',
			'expires_days'        => isset( $security_in['expires_days'] ) ? max( 1, min( 365, (int) $security_in['expires_days'] ) ) : 182,
		);

		$trainers                 = isset( $input['blocked_trainers'] ) ? $input['blocked_trainers'] : $defaults['blocked_trainers'];
		$clean['blocked_trainers'] = $this->sanitize_list( $trainers, 'sanitize_text_field' );

		// AI-usage signal channels: the header/tdmrep toggles are handled by the boolean
		// loop above; only the optional policy URL needs sanitising here.
		$clean['tdm_policy_url'] = isset( $input['tdm_policy_url'] ) ? esc_url_raw( (string) $input['tdm_policy_url'] ) : '';

		// (enable_webmcp is handled by the boolean loop above.)
		// Owner's per-tool hide list (deny-list of WebMCP tool names). Keep only
		// tool-name-safe characters; empty = expose every registered tool.
		$hidden_in = isset( $input['webmcp_hidden_tools'] ) && is_array( $input['webmcp_hidden_tools'] ) ? $input['webmcp_hidden_tools'] : array();
		$clean['webmcp_hidden_tools'] = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $name ) {
							return preg_replace( '#[^a-zA-Z0-9._/-]#', '', (string) $name );
						},
						$hidden_in
					)
				)
			)
		);

		// Optional hard-block enforcement. The switches (block_agents, block_spoofed,
		// verify_bots) are handled by the boolean loop above — the isset-guard keeps
		// block_spoofed's safe default on a partial update. Here we sanitise its lists.
		$agents                  = isset( $input['blocked_agents'] ) ? $input['blocked_agents'] : array();
		$clean['blocked_agents'] = $this->sanitize_list(
			$agents,
			static function ( $t ) {
				$t = trim( sanitize_text_field( (string) $t ) );
				// A 1–2 char entry is never a real bot name — matched as a substring it
				// would deny half the internet at the AI endpoints. Same 3+ floor as the
				// Verified-bots UA needle, so the two rules can't drift.
				return strlen( $t ) < 3 ? '' : $t;
			}
		);
		$allowed                 = isset( $input['allowed_agents'] ) ? $input['allowed_agents'] : array();
		$clean['allowed_agents'] = $this->sanitize_list( $allowed, 'sanitize_text_field' );

		// Verified-bots registry edits. Disabled built-ins: known tokens only, so a stray
		// value can't accumulate. Custom entries: each needs a UA needle of 3+ chars (a
		// 1-2 char needle would claim-match half the internet) and at least one actual
		// verification source — rDNS suffixes and/or an https range-file URL. Entries
		// keep their token across saves (it keys the range cache); new ones get one
		// derived from the label. Bounded count.
		$disabled_in                = isset( $input['verifier_disabled'] ) ? (array) $input['verifier_disabled'] : array();
		$clean['verifier_disabled'] = array_values(
			array_intersect(
				array_unique( array_map( 'sanitize_key', $disabled_in ) ),
				array_keys( VerifierRegistry::builtins() )
			)
		);
		$clean['verifier_custom']   = $this->sanitize_verifier_custom(
			isset( $input['verifier_custom'] ) ? (array) $input['verifier_custom'] : array()
		);

		// Owner-added paths/filenames for the exposed-files self-check. Keep only path-safe
		// characters, AS ENTERED — a leading slash is NOT forced, because a bare filename (no
		// slash) is meaningful: Exposure::sensitive_paths() expands it to the common locations,
		// while a slashed value is treated as an explicit path. Bounded in count/length.
		$paths_in                     = isset( $input['exposed_extra_paths'] ) ? $input['exposed_extra_paths'] : array();
		$clean['exposed_extra_paths'] = $this->sanitize_list(
			$paths_in,
			static function ( $p ) {
				return preg_replace( '#[^A-Za-z0-9._~/\-]#', '', trim( (string) $p ) );
			}
		);

		// Evergreen categories: a bounded list of positive term IDs (drop blanks/zero/
		// negatives outright rather than absint-ing a bad value into a real category ID).
		$ever_in                       = isset( $input['evergreen_categories'] ) ? (array) $input['evergreen_categories'] : array();
		$ever_ids                      = array_filter(
			array_map( 'intval', $ever_in ),
			static function ( $n ) {
				return $n > 0;
			}
		);
		$clean['evergreen_categories'] = array_values( array_slice( array_unique( $ever_ids ), 0, 200 ) );

		// Optimize "set aside" list: bounded positive post IDs, same rules.
		$ignored_in                = isset( $input['optimize_ignored'] ) ? (array) $input['optimize_ignored'] : array();
		$ignored_ids               = array_filter(
			array_map( 'intval', $ignored_in ),
			static function ( $n ) {
				return $n > 0;
			}
		);
		$clean['optimize_ignored'] = array_values( array_slice( array_unique( $ignored_ids ), 0, 1000 ) );

		/**
		 * Filter the sanitised settings before they are stored.
		 *
		 * @param array $clean Sanitised settings.
		 * @param array $input Raw input.
		 */
		$filtered = apply_filters( 'agentimus_sanitize_settings', $clean, $input );
		// Never persist a non-array: this result is written straight to the option,
		// and a corrupt option would break every subsequent read + the admin.
		return is_array( $filtered ) ? $filtered : $clean;
	}

	/**
	 * Sanitise a list of strings (array, or newline/comma string) and drop blanks.
	 *
	 * @param mixed    $value    Raw list.
	 * @param callable $callback Per-item sanitiser.
	 * @return string[]
	 */
	private function sanitize_list( $value, $callback, $max_items = 200, $max_length = 300 ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		$value = array_map( $callback, array_map( 'trim', (array) $value ) );

		// Bound BOTH the item count and each item's length, so a paste of tens of
		// thousands of tokens (or one enormous entry) can't bloat the autoloaded option
		// that is read on every front-end request.
		$out = array();
		foreach ( $value as $item ) {
			$item = (string) $item;
			if ( '' === $item ) {
				continue;
			}
			if ( strlen( $item ) > $max_length ) {
				$item = substr( $item, 0, $max_length );
			}
			$out[] = $item;
			if ( count( $out ) >= $max_items ) {
				break;
			}
		}
		return array_values( $out );
	}

	/**
	 * Sanitise owner-added verified-bot registry entries. Each survives only with a UA
	 * needle of 3+ chars AND at least one verification source: rDNS domain suffixes
	 * (normalised to leading-dot lowercase hostnames) and/or an https range-file URL.
	 * A pre-existing custom token (c_*) is kept so the entry's identity — and its
	 * cached range file — survives a re-save; new entries get one from the label.
	 *
	 * @param array $value Raw entries.
	 * @return array
	 */
	private function sanitize_verifier_custom( array $value ) {
		$out     = array();
		$seen    = array_keys( VerifierRegistry::builtins() );
		$needles = array();
		foreach ( VerifierRegistry::builtins() as $b ) {
			$needles[] = (string) $b['ua'];
		}
		foreach ( $value as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$label = sanitize_text_field( isset( $e['label'] ) ? (string) $e['label'] : '' );
			$label = substr( $label, 0, 40 );
			$ua    = strtolower( sanitize_text_field( isset( $e['ua'] ) ? (string) $e['ua'] : '' ) );
			$ua    = substr( trim( $ua ), 0, 64 );

			$domains = array();
			foreach ( array_slice( (array) ( isset( $e['domains'] ) ? $e['domains'] : array() ), 0, 10 ) as $d ) {
				// Accept "googlebot.com", ".googlebot.com" or a pasted URL; store ".host".
				$d = strtolower( trim( (string) $d ) );
				$d = preg_replace( '#^https?://#', '', $d );
				$d = trim( preg_replace( '#[^a-z0-9.\-]#', '', $d ), '.' );
				if ( '' !== $d && false !== strpos( $d, '.' ) ) {
					$domains[] = '.' . $d;
				}
			}

			$url = isset( $e['url'] ) ? esc_url_raw( trim( (string) $e['url'] ), array( 'https' ) ) : '';
			if ( 0 !== strpos( $url, 'https://' ) ) {
				$url = ''; // https only, enforced here too — the fetcher refuses anything else anyway.
			}

			if ( '' === $label || strlen( $ua ) < 3 || ( empty( $domains ) && '' === $url ) ) {
				continue;
			}
			if ( in_array( $ua, $needles, true ) ) {
				continue; // One claim, one entry: a needle another entry already owns is rejected.
			}

			$token = isset( $e['token'] ) && preg_match( '/^c_[a-z0-9_\-]{1,40}$/', (string) $e['token'] )
				? (string) $e['token']
				: 'c_' . substr( sanitize_key( str_replace( ' ', '_', $label ) ), 0, 40 );
			if ( in_array( $token, $seen, true ) ) {
				continue; // No shadowing a built-in and no duplicate customs.
			}
			$seen[]    = $token;
			$needles[] = $ua;

			$out[] = array(
				'token'   => $token,
				'label'   => $label,
				'ua'      => $ua,
				'domains' => array_values( array_unique( $domains ) ),
				'url'     => $url,
			);
			if ( count( $out ) >= VerifierRegistry::MAX_CUSTOM ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Clip a scalar string to a maximum length — a length bound for the free-text
	 * identity/service fields, which flow into the autoloaded option and the JSON-LD.
	 *
	 * @param string $value Sanitised text.
	 * @param int    $max   Maximum length in bytes.
	 * @return string
	 */
	private function clip( $value, $max ) {
		$value = (string) $value;
		return strlen( $value ) > $max ? substr( $value, 0, $max ) : $value;
	}

	/**
	 * Whether an array is associative (a keyed map, e.g. content_signal) rather than a
	 * plain list (e.g. blocked_trainers) — so all() deep-merges only the maps.
	 *
	 * @param array $arr Array to test.
	 * @return bool
	 */
	private static function is_assoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Sanitise the owner-declared services list into clean `{name, description, url}`
	 * rows. A row with no name is dropped (a nameless service is meaningless);
	 * description and url are optional. Accepts the array the UI sends.
	 *
	 * @param mixed $value Raw services list.
	 * @return array<int,array{name:string,description:string,url:string}>
	 */
	private function sanitize_services( $value ) {
		$out = array();
		foreach ( (array) $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? $this->clip( sanitize_text_field( (string) $row['name'] ), 200 ) : '';
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'name'        => $name,
				'description' => isset( $row['description'] ) ? $this->clip( sanitize_textarea_field( (string) $row['description'] ), 1000 ) : '',
				'url'         => isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '',
			);
			if ( count( $out ) >= 50 ) {
				break; // Bound the services list.
			}
		}
		return $out;
	}

	/**
	 * Sanitise one security.txt Contact value: an email is kept as a bare address
	 * (SecurityTxt prefixes mailto: at render time); otherwise it must be an
	 * http(s)/mailto/tel URI. Anything else collapses to '' and is dropped.
	 *
	 * @param string $value Raw contact.
	 * @return string
	 */
	public function sanitize_contact_value( $value ) {
		$value = trim( (string) $value );
		if ( is_email( $value ) ) {
			return sanitize_email( $value );
		}
		// Require an explicit, allowed scheme so esc_url_raw() can't turn a bare
		// word into a bogus "https://…" contact.
		if ( ! preg_match( '#^(https?|mailto|tel):#i', $value ) ) {
			return '';
		}
		return (string) esc_url_raw( $value, array( 'https', 'http', 'mailto', 'tel' ) );
	}
}
