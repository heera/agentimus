<?php
/**
 * Pro settings store — the AI-visibility monitoring configuration: which brand
 * and competitors to watch, the prompts to track, per-provider API keys and
 * models, the run cadence and how long to keep history.
 *
 * Kept in its own option (never mixed into the free core's settings) so the two
 * plugins can be installed, reset or uninstalled independently. API keys are
 * write-only across the REST boundary — they are stored here but masked on the
 * way out (see public_view()), so a key is never echoed back to the browser.
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

	/** @var int Hard cap on tracked prompts, to bound a monitoring run's cost. */
	const MAX_PROMPTS = 25;

	/** @var int Hard cap on tracked competitors. */
	const MAX_COMPETITORS = 20;

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
				'key_hint' => 'sk-…',
				'help_url' => 'https://platform.openai.com/api-keys',
				'grounded' => false,
				// Can optionally answer from a live web search (needs a search-capable
				// model, e.g. gpt-4.1). See Providers\OpenAI.
				'web_search_capable' => true,
				'web_search_model'   => 'gpt-4.1',
			),
			'perplexity' => array(
				'label'    => 'Perplexity',
				'model'    => 'sonar',
				'key_hint' => 'pplx-…',
				'help_url' => 'https://www.perplexity.ai/settings/api',
				'grounded' => true, // Answers from live web results with citations.
			),
			'gemini'     => array(
				'label'    => 'Gemini (Google)',
				'model'    => 'gemini-2.0-flash',
				'key_hint' => 'AIza…',
				'help_url' => 'https://aistudio.google.com/app/apikey',
				'grounded' => false,
				// Can optionally ground answers on Google Search. See Providers\Gemini.
				'web_search_capable' => true,
			),
			'anthropic'  => array(
				'label'    => 'Claude (Anthropic)',
				'model'    => 'claude-opus-4-8',
				'key_hint' => 'sk-ant-…',
				'help_url' => 'https://console.anthropic.com/settings/keys',
				'grounded' => false,
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
			'brand'          => $this->default_brand(),
			'domain'         => $this->default_domain(),
			'competitors'    => array(),
			'prompts'        => array(),
			'providers'      => $providers,
			'frequency'      => 'weekly', // manual | daily | weekly
			'retention_days' => 180,
		);
	}

	/**
	 * The natural default brand to track: the name from the core Identity settings
	 * if the owner set one, otherwise the site title. Reading the core option keeps
	 * this module self-contained (no hard dependency on the core Settings class).
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

		$out['competitors'] = isset( $stored['competitors'] ) ? (array) $stored['competitors'] : $defaults['competitors'];
		$out['prompts']     = isset( $stored['prompts'] ) ? (array) $stored['prompts'] : $defaults['prompts'];

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
			if ( ! empty( $cfg['enabled'] ) && '' !== trim( (string) $cfg['key'] ) ) {
				$out[ $id ] = array(
					'key'        => (string) $cfg['key'],
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

		return array(
			'brand'         => (string) $all['brand'],
			'domain'        => (string) $all['domain'],
			'competitors'   => array_values( (array) $all['competitors'] ),
			'prompts'       => array_values( (array) $all['prompts'] ),
			'providers'     => $providers,
			'frequency'     => (string) $all['frequency'],
			'retentionDays' => (int) $all['retention_days'],
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

		if ( array_key_exists( 'brand', $input ) ) {
			$clean['brand'] = sanitize_text_field( (string) $input['brand'] );
		}
		if ( array_key_exists( 'domain', $input ) ) {
			$clean['domain'] = $this->sanitize_domain( (string) $input['domain'] );
		}
		if ( array_key_exists( 'competitors', $input ) ) {
			$clean['competitors'] = $this->sanitize_list( $input['competitors'], self::MAX_COMPETITORS );
		}
		if ( array_key_exists( 'prompts', $input ) ) {
			$clean['prompts'] = $this->sanitize_list( $input['prompts'], self::MAX_PROMPTS, 300 );
		}
		if ( array_key_exists( 'frequency', $input ) ) {
			$freq              = sanitize_key( (string) $input['frequency'] );
			$clean['frequency'] = in_array( $freq, array( 'manual', 'daily', 'weekly' ), true ) ? $freq : 'weekly';
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
				// A blank or masked key means "leave the stored key untouched"; any
				// other value replaces it. Trim to kill accidental whitespace.
				if ( array_key_exists( 'key', $in ) ) {
					$key = trim( (string) $in['key'] );
					if ( '' !== $key && self::KEY_MASK !== $key ) {
						$cfg['key'] = sanitize_text_field( $key );
					}
				}

				$clean['providers'][ $id ] = $cfg;
			}
		}

		update_option( self::OPTION, $clean, false );
		$this->cache = $clean;
		return $clean;
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
