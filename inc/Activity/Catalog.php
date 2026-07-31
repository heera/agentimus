<?php
/**
 * Catalog — a recognition layer that turns a raw User-Agent into a plain-English
 * identity card for a KNOWN crawler: who runs it, what KIND it is (AI / SEO /
 * search / social), and a link to its docs.
 *
 * The point is informed consent, not policy. The review queue shows owners
 * unfamiliar tokens (ShapBot, CCBot, DataForSeoBot…) and asks them to Block or
 * Allow. A name alone — "Other bot · shapbot" — gives a non-technical owner no way
 * to decide, so they either block something useful or ignore real noise. Labelling
 * it "AI crawler · Parallel — what is this?" lets them choose on the facts. We label;
 * we never decide for them (that's why these are NOT auto-protected — an AI crawler
 * is a thing many owners legitimately want to block, unlike a search engine).
 *
 * Pure and filterable: no DB, no time(), so it unit-tests in isolation and a host
 * can extend the catalog via the `agentimus_known_agents` filter.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	/**
	 * Lowercase UA token => [ name, operator, kind, url ].
	 *
	 * Ordered specific-first so a longer token wins over a prefix it contains
	 * (e.g. applebot-extended before a bare applebot). `kind` is one of
	 * ai | seo | search | social and drives the friendly category label in the UI.
	 * URLs point at each operator's own crawler/bot documentation where one exists,
	 * so "what is this?" lands on the authoritative page; where an operator publishes
	 * none (or it has rotted), the URL points at the Known Agents crawler directory.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	private static function catalog() {
		$catalog = array(
			// — AI / LLM crawlers: ingest content to train or answer with models. —
			'oai-searchbot'      => array( 'OAI-SearchBot', 'OpenAI', 'ai', 'https://platform.openai.com/docs/bots' ),
			'chatgpt-user'       => array( 'ChatGPT-User', 'OpenAI', 'ai', 'https://platform.openai.com/docs/bots' ),
			'gptbot'             => array( 'GPTBot', 'OpenAI', 'ai', 'https://platform.openai.com/docs/bots' ),
			'claude-searchbot'   => array( 'Claude-SearchBot', 'Anthropic', 'ai', 'https://support.anthropic.com/en/articles/8896518' ),
			'claude-user'        => array( 'Claude-User', 'Anthropic', 'ai', 'https://support.anthropic.com/en/articles/8896518' ),
			'claudebot'          => array( 'ClaudeBot', 'Anthropic', 'ai', 'https://support.anthropic.com/en/articles/8896518' ),
			'anthropic-ai'       => array( 'anthropic-ai', 'Anthropic', 'ai', 'https://support.anthropic.com/en/articles/8896518' ),
			'perplexity-user'    => array( 'Perplexity-User', 'Perplexity', 'ai', 'https://docs.perplexity.ai/guides/bots' ),
			'perplexitybot'      => array( 'PerplexityBot', 'Perplexity', 'ai', 'https://docs.perplexity.ai/guides/bots' ),
			'google-extended'    => array( 'Google-Extended', 'Google', 'ai', 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers' ),
			'applebot-extended'  => array( 'Applebot-Extended', 'Apple', 'ai', 'https://support.apple.com/en-us/119829' ),
			'meta-externalagent' => array( 'Meta-ExternalAgent', 'Meta', 'ai', 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers/' ),
			'bytespider'         => array( 'Bytespider', 'ByteDance', 'ai', 'https://www.cloudflare.com/learning/bots/what-is-bytespider/' ),
			'ccbot'              => array( 'CCBot', 'Common Crawl', 'ai', 'https://commoncrawl.org/faq' ),
			'amazonbot'          => array( 'Amazonbot', 'Amazon', 'ai', 'https://developer.amazon.com/amazonbot' ),
			'cohere-ai'          => array( 'cohere-ai', 'Cohere', 'ai', 'https://cohere.com' ),
			'diffbot'            => array( 'Diffbot', 'Diffbot', 'ai', 'https://knownagents.com/agents/diffbot' ),
			'shapbot'            => array( 'ShapBot', 'Parallel', 'ai', 'https://parallel.ai' ),
			'youbot'             => array( 'YouBot', 'You.com', 'ai', 'https://knownagents.com/agents/youbot' ),
			'imagesiftbot'       => array( 'ImageSiftBot', 'ImageSift', 'ai', 'https://imagesift.com/about' ),
			'omgilibot'          => array( 'Omgilibot', 'Webz.io', 'ai', 'https://webz.io/bot.html' ),
			'timpibot'           => array( 'Timpibot', 'Timpi', 'ai', 'https://timpi.io' ),

			// — SEO / marketing crawlers: backlink & rank tooling, often high-volume. —
			'semrushbot'         => array( 'SemrushBot', 'Semrush', 'seo', 'https://www.semrush.com/bot/' ),
			'ahrefsbot'          => array( 'AhrefsBot', 'Ahrefs', 'seo', 'https://ahrefs.com/robot' ),
			'dataforseobot'      => array( 'DataForSeoBot', 'DataForSEO', 'seo', 'https://dataforseo.com/dataforseo-bot' ),
			'mj12bot'            => array( 'MJ12bot', 'Majestic', 'seo', 'https://mj12bot.com/' ),
			'dotbot'             => array( 'DotBot', 'Moz', 'seo', 'https://moz.com/help/moz-procedures/crawlers/dotbot' ),
			'rogerbot'           => array( 'rogerbot', 'Moz', 'seo', 'https://moz.com/help/moz-procedures/crawlers/rogerbot' ),
			'blexbot'            => array( 'BLEXBot', 'WebMeUp', 'seo', 'https://knownagents.com/agents/blexbot' ),
			'barkrowler'         => array( 'Barkrowler', 'Babbar', 'seo', 'https://www.babbar.tech/crawler' ),

			// — Search engines outside the protected set (regional / vertical). —
			'petalbot'           => array( 'PetalBot', 'Huawei (Petal)', 'search', 'https://aspiegel.com/petalbot' ),
			'seznambot'          => array( 'SeznamBot', 'Seznam', 'search', 'https://napoveda.seznam.cz/en/seznambot-crawler/' ),
			'mojeekbot'          => array( 'MojeekBot', 'Mojeek', 'search', 'https://www.mojeek.com/bot.html' ),

			// — Social / link-preview fetchers: render a card when a link is shared. —
			'facebookexternalhit' => array( 'Facebook', 'Meta', 'social', 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers/' ),
			'twitterbot'         => array( 'Twitterbot', 'X', 'social', 'https://developer.x.com/en/docs/x-for-websites/cards/guides/getting-started' ),
			'linkedinbot'        => array( 'LinkedInBot', 'LinkedIn', 'social', 'https://www.linkedin.com/help/linkedin' ),
			'slackbot-linkexpanding' => array( 'Slackbot', 'Slack', 'social', 'https://api.slack.com/robots' ),
			'discordbot'         => array( 'Discordbot', 'Discord', 'social', 'https://discord.com' ),
			'telegrambot'        => array( 'TelegramBot', 'Telegram', 'social', 'https://core.telegram.org/bots/webapps' ),
		);

		/**
		 * Filter the known-crawler recognition catalog.
		 *
		 * @param array<string,array{0:string,1:string,2:string,3:string}> $catalog
		 *        Lowercase UA token => [ name, operator, kind, url ].
		 */
		return (array) apply_filters( 'agentimus_known_agents', $catalog );
	}

	/**
	 * The full recognition map — lowercase UA token => [ name, operator, kind,
	 * url ], filters applied, ordered specific-first. For consumers that need
	 * the stable TOKEN as a storage key; {@see identify()} returns only the
	 * identity card, not the token it matched on.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public static function known() {
		return self::catalog();
	}

	/**
	 * Identify a User-Agent against the catalog.
	 *
	 * @param string $ua Raw User-Agent.
	 * @return array{name:string,operator:string,kind:string,url:string}|null
	 *         The identity card, or null if the UA matches no known crawler.
	 */
	public static function identify( $ua ) {
		$ua_lc = strtolower( (string) $ua );
		if ( '' === $ua_lc ) {
			return null;
		}
		foreach ( self::catalog() as $token => $row ) {
			if ( false !== strpos( $ua_lc, $token ) ) {
				return array(
					'name'     => (string) $row[0],
					'operator' => (string) $row[1],
					'kind'     => (string) $row[2],
					'url'      => (string) $row[3],
				);
			}
		}
		return null;
	}

	/**
	 * Best-effort guidance for an UNKNOWN crawler (one {@see identify()} doesn't
	 * recognise), so an owner still has somewhere to look. Pure — no DB, no time().
	 *
	 *  - `name`   : the bot's product token parsed from the UA (e.g. "SomeNewBot"),
	 *               a better row title than the generic classifier label.
	 *  - `url`    : the home page the crawler declares in its OWN User-Agent, if
	 *               any — "+https://…" by convention, but a bare "https://…" counts
	 *               too. This is the client's CLAIM, not verified (a spoofer can put
	 *               anything here) — the UI labels it so and shows the host. Only
	 *               http(s) is accepted and the value is sanitised before output.
	 *  - `host`   : that URL's host (sans www), so the UI can show where it leads.
	 *  - `lookup` : a web search to investigate, when there's a name/host to search.
	 *
	 * @param string $ua Raw User-Agent.
	 * @return array{name:string,url:string,host:string,lookup:string}
	 */
	public static function self_declared( $ua ) {
		$ua  = (string) $ua;
		$out = array( 'name' => '', 'url' => '', 'host' => '', 'lookup' => '' );
		if ( '' === trim( $ua ) ) {
			return $out;
		}

		// The home page many well-behaved crawlers include — usually "+https://…",
		// but some omit the "+" ("ethicrawl/0.1 (…; http://ethicrawl.ai/crawler; …)").
		// Attacker-controlled either way, so require an http(s) scheme and sanitise
		// before it can become a link.
		if ( preg_match( '~(https?://[^\s)\]};,"\']+)~i', $ua, $m ) ) {
			$url = esc_url_raw( rtrim( $m[1], '.,;:)' ) );
			if ( '' !== $url && preg_match( '~^https?://~i', $url ) ) {
				$out['url']  = $url;
				$host        = (string) wp_parse_url( $url, PHP_URL_HOST );
				$out['host'] = '' !== $host ? preg_replace( '~^www\.~i', '', $host ) : '';
			}
		}

		// A "Name/version" product token — preferring one that looks like a bot,
		// skipping browser noise — for the row title and the search query.
		if ( preg_match_all( '~([A-Za-z][A-Za-z0-9._-]{1,40})/[0-9]~', $ua, $mm ) ) {
			$skip  = array( 'mozilla', 'applewebkit', 'khtml', 'gecko', 'safari', 'chrome', 'version', 'mobile', 'edg', 'opr', 'firefox', 'crios', 'fxios' );
			$names = array();
			foreach ( $mm[1] as $n ) {
				if ( ! in_array( strtolower( $n ), $skip, true ) ) {
					$names[] = $n;
				}
			}
			foreach ( $names as $n ) {
				if ( preg_match( '~bot|crawl|spider|agent~i', $n ) ) {
					$out['name'] = $n;
					break;
				}
			}
			if ( '' === $out['name'] && ! empty( $names ) ) {
				$out['name'] = $names[0];
			}
		}

		$q = '' !== $out['name'] ? $out['name'] : $out['host'];
		if ( '' !== $q ) {
			$out['lookup'] = 'https://duckduckgo.com/?q=' . rawurlencode( $q . ' crawler bot' );
		}

		return $out;
	}
}
