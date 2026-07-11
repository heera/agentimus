<?php
/**
 * Pro settings store — the AI-visibility monitoring configuration: which brand
 * and competitors to watch, the prompts to track, per-provider API keys and
 * models, the run cadence and how long to keep history.
 *
 * Kept in its own option (never mixed into the free core's settings) so the two
 * plugins can be installed, reset or uninstalled independently. API keys are
 * masked by default across the REST boundary — public_view() replaces each stored
 * key with a placeholder, so a key is never echoed back in the config payload. The
 * full key is returned only by the explicit, manage_options-gated reveal endpoint
 * (see Rest::reveal_key), when an admin asks to view their own key.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** @var string Option key. */
	const OPTION = 'agentimus_visibility';

	/** @var string Placeholder the UI shows for a stored key; means "unchanged" on save. */
	const KEY_MASK = '__stored__';

	/** @var int Hard cap on tracked prompts per product, to bound a run's cost. */
	const MAX_PROMPTS = 25;

	/** @var int Hard cap on tracked competitors per product. */
	const MAX_COMPETITORS = 20;

	/** @var int Hard cap on tracked products — each has its own name, site, rivals and questions. */
	const MAX_TARGETS = 10;

	/** @var int Longest product category ("WordPress SEO plugin") — a short noun phrase, not a pitch. */
	const MAX_CATEGORY = 80;

	/**
	 * The free-core plugin instance, when available, used only to seed sensible
	 * defaults (brand, domain) from the site's own identity.
	 *
	 * @var \Agentimus\Plugin|null
	 */
	private $core;

	/** @var array|null Lazily-loaded, defaults-merged settings. */
	private $cache = null;

	/**
	 * @param \Agentimus\Plugin|null $core The booted core plugin instance.
	 */
	public function __construct( $core = null ) {
		$this->core = $core;
	}

	/**
	 * The provider catalog — every engine Pro can query, with a sensible default
	 * model and the console URL where a user mints a key. The default models lean
	 * cheap/fast so a recurring monitoring run stays affordable; every one is
	 * user-editable. Anthropic defaults to the current capable Opus tier per the
	 * provider's own guidance, with Claude Haiku documented as the low-cost swap.
	 *
	 * @return array<string,array>
	 */
	public static function catalog() {
		return array(
			'openai'     => array(
				'label'    => 'ChatGPT (OpenAI)',
				'model'    => 'gpt-4o-mini',
				// A few common models to pick from; the field also accepts a custom ID.
				'models'   => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1' ),
				'key_hint' => 'sk-…',
				'help_url' => 'https://platform.openai.com/api-keys',
				'grounded' => false,
				// Can optionally answer from a live web search (needs a search-capable
				// model, e.g. gpt-4.1). See Providers\OpenAI.
				'web_search_capable' => true,
				'web_search_model'   => 'gpt-4.1',
			),
			'gemini'     => array(
				'label'    => 'Gemini (Google)',
				// Default to Google's rolling "-latest" alias, which auto-tracks the current
				// Flash model — pinned versions go stale fast (2.0 was deprecated Mar 2026, and
				// 2.5 became unavailable to new keys), so a hard-coded ID like "gemini-2.0-flash"
				// returns "limit: 0" / "no longer available". The alias sidesteps that; a pinned
				// version is offered for anyone who wants to lock it. Field also accepts a custom ID.
				'model'    => 'gemini-flash-latest',
				'models'   => array( 'gemini-flash-latest', 'gemini-flash-lite-latest', 'gemini-3.5-flash' ),
				'key_hint' => 'AIza…',
				'help_url' => 'https://aistudio.google.com/app/apikey',
				'grounded' => false,
				// Can optionally ground answers on Google Search. See Providers\Gemini.
				'web_search_capable' => true,
			),
			'anthropic'  => array(
				'label'    => 'Claude (Anthropic)',
				'model'    => 'claude-opus-4-8',
				'models'   => array( 'claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5-20251001' ),
				'key_hint' => 'sk-ant-…',
				'help_url' => 'https://console.anthropic.com/settings/keys',
				'grounded' => false,
				// Can optionally ground answers on Claude's built-in web search tool,
				// which cites its sources. See Providers\Anthropic.
				'web_search_capable' => true,
			),
			// Last on purpose: it's the odd one out — always grounded, so it has no live-web
			// switch to set. Keeping the three switchable engines together reads better.
			'perplexity' => array(
				'label'    => 'Perplexity',
				'model'    => 'sonar',
				'models'   => array( 'sonar', 'sonar-pro', 'sonar-reasoning' ),
				'key_hint' => 'pplx-…',
				'help_url' => 'https://www.perplexity.ai/settings/api',
				'grounded' => true, // Answers from live web results with citations.
			),
		);
	}

	/** @return string[] The provider IDs, in display order. */
	public static function provider_ids() {
		return array_keys( self::catalog() );
	}

	/**
	 * The factory defaults. Brand and domain are seeded from the site so a first
	 * run is meaningful before the user has configured anything.
	 *
	 * @return array
	 */
	public function defaults() {
		$providers = array();
		foreach ( self::catalog() as $id => $meta ) {
			$providers[ $id ] = array(
				'enabled'    => false,
				'key'        => '',
				'model'      => $meta['model'],
				'web_search' => false,
			);
		}

		return array(
			'targets'        => $this->default_targets(),
			'providers'      => $providers,
			// Automatic checks are OFF until the owner opts in — each run spends their
			// own AI API budget, so we never start recurring paid work on their behalf.
			// "Run check now" still works on demand. Turning this on schedules the run.
			'active'         => false,
			'frequency'      => 'weekly', // daily | weekly
			'retention_days' => 180,
		);
	}

	/**
	 * One starting product, seeded from the site's own identity (name + domain), so a
	 * first run is meaningful before anything is configured. Users can add more, each
	 * with its own name, website, competitors and questions.
	 *
	 * @return array[]
	 */
	private function default_targets() {
		$name   = $this->default_brand();
		$domain = $this->default_domain();
		if ( '' === $name && '' === $domain ) {
			return array();
		}
		return array(
			array(
				'name'        => $name,
				'category'    => '',
				'domain'      => $domain,
				'active'      => true,
				'competitors' => array(),
				'prompts'     => array(),
			),
		);
	}

	/**
	 * The natural default brand name to track: the name from the core Identity
	 * settings if the owner set one, otherwise the site title. Reading the core
	 * option keeps this module self-contained (no hard dependency on core Settings).
	 *
	 * @return string
	 */
	private function default_brand() {
		$core = get_option( 'agentimus_settings' );
		if ( is_array( $core ) && ! empty( $core['identity']['name'] ) ) {
			return trim( wp_strip_all_tags( (string) $core['identity']['name'] ) );
		}
		$name = get_bloginfo( 'name' );
		return is_string( $name ) ? trim( wp_strip_all_tags( $name ) ) : '';
	}

	/** @return string The site's bare host (no scheme/path) — used for citation detection. */
	private function default_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? preg_replace( '/^www\./i', '', strtolower( $host ) ) : '';
	}

	/**
	 * The full, defaults-merged settings (server-side view — includes real keys).
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$this->cache = $this->merge_defaults( $stored );
		return $this->cache;
	}

	/**
	 * Deep-merge stored values over defaults, so a newly-added provider or setting
	 * always has a value even on an install that predates it.
	 *
	 * @param array $stored Raw stored settings.
	 * @return array
	 */
	private function merge_defaults( array $stored ) {
		$defaults = $this->defaults();
		$out      = array_merge( $defaults, $stored );

		// Providers merge one level deeper: keep any provider the catalog defines,
		// filling gaps from defaults, and drop any stored provider no longer known.
		$out['providers'] = array();
		foreach ( $defaults['providers'] as $id => $default_cfg ) {
			$stored_cfg              = isset( $stored['providers'][ $id ] ) && is_array( $stored['providers'][ $id ] )
				? $stored['providers'][ $id ]
				: array();
			$out['providers'][ $id ] = array_merge( $default_cfg, $stored_cfg );
		}

		// Products: prefer the new per-product list. Migrate the older flat shape
		// (a single/multi brand + one shared domain/competitors/prompts) into one
		// product per name so nobody loses their setup on upgrade.
		if ( isset( $stored['targets'] ) && is_array( $stored['targets'] ) ) {
			$out['targets'] = $this->normalize_targets( $stored['targets'] );
		} elseif ( isset( $stored['brands'] ) || isset( $stored['brand'] ) || isset( $stored['prompts'] ) || isset( $stored['competitors'] ) ) {
			$out['targets'] = $this->migrate_flat( $stored );
		} else {
			$out['targets'] = $defaults['targets'];
		}

		// Schedule master switch. Migrate a legacy 'manual' frequency to "off" and
		// normalize the frequency itself to the two recurring options.
		if ( isset( $stored['active'] ) ) {
			$out['active'] = (bool) $stored['active'];
		} elseif ( isset( $stored['frequency'] ) && 'manual' === $stored['frequency'] ) {
			$out['active'] = false;
		} else {
			$out['active'] = $defaults['active'];
		}
		if ( 'manual' === ( $out['frequency'] ?? '' ) || ! in_array( ( $out['frequency'] ?? '' ), array( 'daily', 'weekly' ), true ) ) {
			$out['frequency'] = 'weekly';
		}

		// Drop the retired flat keys so they don't linger in the saved option.
		unset( $out['brands'], $out['brand'], $out['domain'], $out['competitors'], $out['prompts'] );

		return $out;
	}

	/**
	 * Ensure every stored product has the full shape (name, category, domain, competitors,
	 * prompts) with clean lists — tolerant of partial/older rows, which predate `category`
	 * and simply read as ''.
	 *
	 * @param mixed $targets Raw stored products.
	 * @return array[]
	 */
	private function normalize_targets( $targets ) {
		$out = array();
		foreach ( (array) $targets as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$out[] = array(
				'name'        => isset( $t['name'] ) ? trim( (string) $t['name'] ) : '',
				'category'    => isset( $t['category'] ) ? trim( (string) $t['category'] ) : '',
				'domain'      => isset( $t['domain'] ) ? trim( (string) $t['domain'] ) : '',
				'active'      => isset( $t['active'] ) ? (bool) $t['active'] : true,
				'competitors' => $this->clean_names( isset( $t['competitors'] ) ? (array) $t['competitors'] : array() ),
				'prompts'     => $this->clean_names( isset( $t['prompts'] ) ? (array) $t['prompts'] : array() ),
			);
		}
		return $out;
	}

	/**
	 * Fold a pre-product (flat) config into one product per tracked name, all sharing
	 * the old single domain/competitors/prompts.
	 *
	 * @param array $stored Raw stored settings in the old shape.
	 * @return array[]
	 */
	private function migrate_flat( array $stored ) {
		$names = array();
		if ( isset( $stored['brands'] ) ) {
			$names = $this->clean_names( (array) $stored['brands'] );
		} elseif ( isset( $stored['brand'] ) && '' !== trim( (string) $stored['brand'] ) ) {
			$names = array( trim( (string) $stored['brand'] ) );
		}
		if ( empty( $names ) ) {
			return $this->default_targets();
		}

		$domain      = isset( $stored['domain'] ) ? trim( (string) $stored['domain'] ) : $this->default_domain();
		$competitors = $this->clean_names( isset( $stored['competitors'] ) ? (array) $stored['competitors'] : array() );
		$prompts     = $this->clean_names( isset( $stored['prompts'] ) ? (array) $stored['prompts'] : array() );

		$out = array();
		foreach ( $names as $n ) {
			$out[] = array(
				'name'        => $n,
				'category'    => '', // Predates the field; the owner fills it in.
				'domain'      => $domain,
				'active'      => true,
				'competitors' => $competitors,
				'prompts'     => $prompts,
			);
		}
		return $out;
	}

	/**
	 * Trim, drop empties, and de-duplicate a list of names, preserving order.
	 *
	 * @param array $names Raw names.
	 * @return string[]
	 */
	private function clean_names( array $names ) {
		$out = array();
		foreach ( $names as $n ) {
			$n = trim( (string) $n );
			if ( '' !== $n && ! in_array( $n, $out, true ) ) {
				$out[] = $n;
			}
		}
		return $out;
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
	 * The providers that are both enabled and have a key — the ones a run queries.
	 *
	 * @return array<string,array> Keyed by provider id, each { key, model }.
	 */
	public function active_providers() {
		$out = array();
		foreach ( (array) $this->get( 'providers', array() ) as $id => $cfg ) {
			// Decrypt first, then gate on the usable key: a stored value that can't be
			// decrypted (rotated salts) resolves to '' and the provider is skipped,
			// rather than a garbage key being sent to the API.
			$key = Crypto::decrypt( (string) ( $cfg['key'] ?? '' ) );
			if ( ! empty( $cfg['enabled'] ) && '' !== trim( $key ) ) {
				$out[ $id ] = array(
					'key'        => $key,
					'model'      => (string) $cfg['model'],
					'web_search' => ! empty( $cfg['web_search'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * The browser-safe view of settings: real keys are replaced with a boolean
	 * "hasKey" and the mask placeholder, so a stored secret never leaves the server.
	 *
	 * @return array
	 */
	public function public_view() {
		$all = $this->all();

		$providers = array();
		foreach ( self::catalog() as $id => $meta ) {
			$cfg               = isset( $all['providers'][ $id ] ) ? $all['providers'][ $id ] : array();
			$has_key           = '' !== trim( (string) ( $cfg['key'] ?? '' ) );
			$providers[ $id ] = array(
				'enabled'          => ! empty( $cfg['enabled'] ),
				'model'            => (string) ( $cfg['model'] ?? $meta['model'] ),
				'models'           => array_values( (array) ( $meta['models'] ?? array( $meta['model'] ) ) ),
				'hasKey'           => $has_key,
				'key'              => $has_key ? self::KEY_MASK : '',
				'label'            => $meta['label'],
				'keyHint'          => $meta['key_hint'],
				'helpUrl'          => $meta['help_url'],
				'grounded'         => (bool) $meta['grounded'],
				'webSearch'        => ! empty( $cfg['web_search'] ),
				'webSearchCapable' => ! empty( $meta['web_search_capable'] ),
			);
		}

		$targets = array();
		foreach ( (array) $all['targets'] as $t ) {
			$targets[] = array(
				'name'        => (string) ( $t['name'] ?? '' ),
				'category'    => (string) ( $t['category'] ?? '' ),
				'domain'      => (string) ( $t['domain'] ?? '' ),
				'active'      => isset( $t['active'] ) ? (bool) $t['active'] : true,
				'competitors' => array_values( (array) ( $t['competitors'] ?? array() ) ),
				'prompts'     => array_values( (array) ( $t['prompts'] ?? array() ) ),
			);
		}

		return array(
			'targets'        => $targets,
			'providers'      => $providers,
			'scheduleActive' => (bool) $all['active'],
			'frequency'      => (string) $all['frequency'],
			'retentionDays'  => (int) $all['retention_days'],
		);
	}

	/**
	 * Sanitize and persist an incoming (browser) settings payload, preserving any
	 * stored API key the user left masked.
	 *
	 * @param array $input Raw input.
	 * @return array The saved, defaults-merged settings.
	 */
	public function update( array $input ) {
		$current = $this->all();
		$clean   = $current;

		// Accept the per-product list; still honour the older flat fields (brands +
		// shared domain/competitors/prompts) by folding them into products.
		if ( array_key_exists( 'targets', $input ) ) {
			$clean['targets'] = $this->sanitize_targets( $input['targets'] );
		} else {
			$legacy = array();
			foreach ( array( 'brands', 'brand', 'domain', 'competitors', 'prompts' ) as $k ) {
				if ( array_key_exists( $k, $input ) ) {
					$legacy[ $k ] = $input[ $k ];
				}
			}
			if ( ! empty( $legacy ) ) {
				$clean['targets'] = $this->sanitize_targets( $this->migrate_flat( $legacy ) );
			}
		}
		if ( array_key_exists( 'active', $input ) || array_key_exists( 'scheduleActive', $input ) ) {
			$clean['active'] = (bool) ( $input['active'] ?? $input['scheduleActive'] );
		}
		if ( array_key_exists( 'frequency', $input ) ) {
			$freq = sanitize_key( (string) $input['frequency'] );
			if ( 'manual' === $freq ) {
				// Back-compat: an explicit "manual" now means the schedule is off.
				$clean['active']    = false;
				$clean['frequency'] = 'weekly';
			} else {
				$clean['frequency'] = in_array( $freq, array( 'daily', 'weekly' ), true ) ? $freq : 'weekly';
			}
		}
		if ( array_key_exists( 'retentionDays', $input ) || array_key_exists( 'retention_days', $input ) ) {
			$days                   = (int) ( $input['retentionDays'] ?? $input['retention_days'] );
			$clean['retention_days'] = max( 7, min( 730, $days ) );
		}

		if ( isset( $input['providers'] ) && is_array( $input['providers'] ) ) {
			foreach ( self::catalog() as $id => $meta ) {
				if ( ! isset( $input['providers'][ $id ] ) || ! is_array( $input['providers'][ $id ] ) ) {
					continue;
				}
				$in  = $input['providers'][ $id ];
				$cfg = $clean['providers'][ $id ];

				if ( array_key_exists( 'enabled', $in ) ) {
					$cfg['enabled'] = (bool) $in['enabled'];
				}
				if ( array_key_exists( 'model', $in ) ) {
					$model         = sanitize_text_field( (string) $in['model'] );
					$cfg['model'] = '' !== $model ? $model : $meta['model'];
				}
				// Live web search — only meaningful for engines that support it.
				if ( array_key_exists( 'web_search', $in ) ) {
					$cfg['web_search'] = ! empty( $meta['web_search_capable'] ) && (bool) $in['web_search'];
				}
				// The masked placeholder means "leave the stored key untouched" (this is
				// what an untouched field sends). Any other value is taken literally —
				// including an empty string, which clears (removes) the key. Because a
				// saved key shows as dots in the field, an empty field is a deliberate
				// clear, so this can't wipe a key by accident.
				if ( array_key_exists( 'key', $in ) ) {
					$key = trim( (string) $in['key'] );
					if ( self::KEY_MASK !== $key ) {
						$cfg['key'] = sanitize_text_field( $key );
					}
				}

				$clean['providers'][ $id ] = $cfg;
			}
		}

		// Encrypt every provider key at rest — and migrate any legacy plaintext key —
		// at this single persistence point, so a key is only ever STORED as ciphertext.
		foreach ( (array) $clean['providers'] as $pid => $cfg ) {
			if ( isset( $cfg['key'] ) ) {
				$clean['providers'][ $pid ]['key'] = Crypto::encrypt_if_needed( (string) $cfg['key'] );
			}
		}

		update_option( self::OPTION, $clean, false );
		$this->cache = $clean;
		return $clean;
	}

	/**
	 * The decrypted API key for one provider, or '' when none is stored (or it can't be
	 * decrypted — e.g. the site's salts were rotated). The only supported way to read a
	 * usable key back out; the stored form is ciphertext (see {@see Crypto}).
	 *
	 * @param string $id Provider id.
	 * @return string
	 */
	public function provider_key( $id ) {
		$providers = (array) $this->get( 'providers', array() );
		$stored    = isset( $providers[ $id ]['key'] ) ? (string) $providers[ $id ]['key'] : '';
		return Crypto::decrypt( $stored );
	}

	/**
	 * Normalize a domain to a bare, lower-cased host.
	 *
	 * @param string $value Raw domain or URL.
	 * @return string
	 */
	private function sanitize_domain( $value ) {
		$value = trim( strtolower( $value ) );
		if ( '' === $value ) {
			return '';
		}
		// Accept a full URL and reduce it to the host.
		if ( false !== strpos( $value, '/' ) || false !== strpos( $value, ':' ) ) {
			$host = wp_parse_url( ( 0 === strpos( $value, 'http' ) ? $value : 'https://' . $value ), PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$value = $host;
			}
		}
		$value = preg_replace( '/^www\./', '', $value );
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize the incoming products list: keep only named products, clean each one's
	 * website/competitors/questions, and cap the number of products.
	 *
	 * @param mixed $targets Incoming products (or not).
	 * @return array[]
	 */
	private function sanitize_targets( $targets ) {
		if ( ! is_array( $targets ) ) {
			return array();
		}
		$out = array();
		foreach ( $targets as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$name = trim( sanitize_text_field( (string) ( $t['name'] ?? '' ) ) );
			if ( '' === $name ) {
				continue; // A product with no name can't be scored.
			}
			if ( strlen( $name ) > 120 ) {
				$name = substr( $name, 0, 120 );
			}
			// Cap in CHARACTERS, not bytes: the field's maxlength counts characters, so a
			// byte cut would land mid-character on a non-Latin category and store a broken
			// string. (mb_substr is always available — WordPress polyfills it.)
			$category = trim( sanitize_text_field( (string) ( $t['category'] ?? '' ) ) );
			if ( mb_strlen( $category ) > self::MAX_CATEGORY ) {
				$category = trim( mb_substr( $category, 0, self::MAX_CATEGORY ) );
			}
			$out[] = array(
				'name'        => $name,
				'category'    => $category,
				'domain'      => $this->sanitize_domain( (string) ( $t['domain'] ?? '' ) ),
				'active'      => array_key_exists( 'active', $t ) ? (bool) $t['active'] : true,
				'competitors' => $this->sanitize_list( $t['competitors'] ?? array(), self::MAX_COMPETITORS ),
				'prompts'     => $this->sanitize_list( $t['prompts'] ?? array(), self::MAX_PROMPTS, 300 ),
			);
			if ( count( $out ) >= self::MAX_TARGETS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Sanitize a free-text list: trim, drop empties, de-dupe, cap length and count.
	 *
	 * @param mixed $list       Incoming array (or not).
	 * @param int   $max_items  Max entries kept.
	 * @param int   $max_length Max characters per entry.
	 * @return array
	 */
	private function sanitize_list( $list, $max_items, $max_length = 120 ) {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $item ) {
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' === $item ) {
				continue;
			}
			if ( strlen( $item ) > $max_length ) {
				$item = substr( $item, 0, $max_length );
			}
			if ( ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
			if ( count( $out ) >= $max_items ) {
				break;
			}
		}
		return $out;
	}
}
