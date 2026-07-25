<?php
/**
 * The solo/coexist mode resolver — the single source of truth for "is a known
 * SEO suite active, and which one?".
 *
 * Agentimus runs in one of two modes:
 *   - solo:    no known SEO suite is active. Agentimus covers the basics a site
 *              needs (titles, descriptions, social cards, canonical, sitemap)
 *              on top of its agent-facing surfaces.
 *   - coexist: a known SEO suite owns search SEO. Agentimus defers on every
 *              surface the suite provides and adds only what it doesn't.
 *
 * Detection is evaluated live on every call, so the mode flips automatically
 * the moment a suite is activated or deactivated — there is no stored state to
 * go stale. The suites table below is the ONLY copy of the detection list;
 * {@see Schema::seo_plugin_present()} and {@see Sitemap::detect()} both read it
 * from here.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class SeoContext {

	/**
	 * The known SEO suites, in priority order — when several are active (a
	 * misconfiguration, but it happens mid-migration), the FIRST active entry
	 * is the one Agentimus credits and links. This order predates this class:
	 * it is the sitemap detector's historical order, kept so `source` values
	 * in existing installs never change.
	 *
	 * Yoast and Rank Math serve `/sitemap_index.xml`; AIOSEO, SEOPress and
	 * The SEO Framework serve `/sitemap.xml`.
	 *
	 * @return array<int,array{active:bool,id:string,label:string,sitemap_path:string}>
	 */
	public static function suites() {
		return array(
			array(
				'active'       => defined( 'WPSEO_VERSION' ),
				'id'           => 'yoast',
				'label'        => __( 'Yoast SEO', 'agentimus' ),
				'sitemap_path' => '/sitemap_index.xml',
			),
			array(
				'active'       => class_exists( 'RankMath' ),
				'id'           => 'rankmath',
				'label'        => __( 'Rank Math', 'agentimus' ),
				'sitemap_path' => '/sitemap_index.xml',
			),
			array(
				'active'       => defined( 'AIOSEO_VERSION' ),
				'id'           => 'aioseo',
				'label'        => __( 'All in One SEO', 'agentimus' ),
				'sitemap_path' => '/sitemap.xml',
			),
			array(
				'active'       => defined( 'SEOPRESS_VERSION' ),
				'id'           => 'seopress',
				'label'        => __( 'SEOPress', 'agentimus' ),
				'sitemap_path' => '/sitemap.xml',
			),
			array(
				'active'       => class_exists( '\\The_SEO_Framework\\Load' ),
				'id'           => 'seoframework',
				'label'        => __( 'The SEO Framework', 'agentimus' ),
				'sitemap_path' => '/sitemap.xml',
			),
		);
	}

	/**
	 * The detected suite — the raw signal, with NO Agentimus filter applied, so
	 * the deferral paths that predate modes keep their exact semantics:
	 * {@see Description::should_emit()} reads this signal directly (deliberately
	 * bypassing `agentimus_defer_schema`), while {@see Schema::seo_plugin_active()}
	 * layers that filter on top.
	 *
	 * @return array{id:string,label:string,sitemap_path:string}|null
	 */
	public static function detected() {
		return self::detected_from( self::suites() );
	}

	/**
	 * Whether ANY known SEO suite is active — the boolean form of the raw signal.
	 *
	 * @return bool
	 */
	public static function plugin_present() {
		return null !== self::detected();
	}

	/**
	 * Resolve the current mode. Unlike the raw signal above, the verdict runs
	 * through the `agentimus_solo_mode` filter, so a site owner can force solo
	 * mode alongside a suite Agentimus knows (or hold coexist behavior for a
	 * suite it doesn't). Mode-gated features (head output, sitemap promotion,
	 * the mode surfaces) key off THIS; the pre-mode deferral paths key off the
	 * raw signal and are not affected by the filter.
	 *
	 * @return array{mode:string,plugin:array{id:string,label:string,sitemap_path:string}|null}
	 */
	public static function resolve() {
		return self::resolve_from( self::suites() );
	}

	/**
	 * Whether Agentimus is in solo mode (filter included).
	 *
	 * @return bool
	 */
	public static function solo() {
		return 'solo' === self::resolve()['mode'];
	}

	/**
	 * Pure resolution over a suites table — the testable seam: tests fabricate
	 * tables with `active` flags set instead of defining plugin constants that
	 * would leak between test cases.
	 *
	 * @param array $suites Suites table in {@see suites()} shape.
	 * @return array{mode:string,plugin:array{id:string,label:string,sitemap_path:string}|null}
	 */
	public static function resolve_from( array $suites ) {
		$plugin = self::detected_from( $suites );

		/**
		 * Filter whether Agentimus runs in solo mode.
		 *
		 * @param bool       $solo   True when no known SEO suite was detected.
		 * @param array|null $plugin The detected suite {id, label, sitemap_path}, or null.
		 */
		$solo = (bool) apply_filters( 'agentimus_solo_mode', null === $plugin, $plugin );

		return array(
			'mode'   => $solo ? 'solo' : 'coexist',
			// The detected suite is reported even when the filter forces solo —
			// coexist surfaces name the plugin, and hiding it because a filter
			// flipped the mode would just make support threads harder.
			'plugin' => $plugin,
		);
	}

	/**
	 * First active suite in a table, or null. Pure; see {@see resolve_from()}.
	 *
	 * @param array $suites Suites table in {@see suites()} shape.
	 * @return array{id:string,label:string,sitemap_path:string}|null
	 */
	public static function detected_from( array $suites ) {
		foreach ( $suites as $suite ) {
			if ( ! empty( $suite['active'] ) ) {
				return array(
					'id'           => (string) $suite['id'],
					'label'        => (string) $suite['label'],
					'sitemap_path' => (string) $suite['sitemap_path'],
				);
			}
		}
		return null;
	}
}
