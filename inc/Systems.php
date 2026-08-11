<?php
/**
 * The Dashboard's systems summary — "what your site runs, and where it stands."
 *
 * One flat read of every Agentimus subsystem's standing, assembled for the
 * card between the surface tiles and the audience panel. The whole build is
 * option/DB-local by design: settings flags, stored option state (IndexNow,
 * the Google index watch, Cloudflare's connection record), two indexed COUNTs
 * (agent access, focus coverage) and two file_exists calls. Never a loopback
 * fetch, never a search-snapshot walk — the expensive truths this card points
 * at (opportunities, split searches, findings) are NOT recomputed here: the
 * client reads them from the same boot findings payload the nav tab counts,
 * so the card and the tab can never show two different numbers.
 *
 * Rides GET /activity beside the audience block: refreshed by the dashboard's
 * one Refresh and its return-to-screen re-read, costing no request of its own.
 *
 * Timestamps ship as unix seconds — the client formats (wpDate.js), never us.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Systems {

	/**
	 * Build the summary.
	 *
	 * @param Settings $settings Settings store.
	 * @param array    $stats    The Repository::stats() payload this ride-along
	 *                           joins — read for unknownSources, never mutated.
	 * @return array
	 */
	public static function summary( Settings $settings, array $stats = array() ) {
		return array(
			'doors'   => self::doors( $settings ),
			'signals' => self::signals( $settings ),
			'search'  => self::search( $stats ),
			'content' => array(
				'focusWith' => Focus::published_with_focus(),
			),
		);
	}

	/**
	 * Doors — what machines can reach and do.
	 *
	 * @param Settings $settings Settings store.
	 * @return array
	 */
	private static function doors( $settings ) {
		$mcp_on = $settings->enabled( 'enable_mcp_server' );

		return array(
			'mcpOn'       => $mcp_on,
			'writesOn'    => $settings->enabled( 'enable_agent_writes' ),
			'tools'       => $mcp_on ? count( ( new Abilities\Registrar( $settings ) )->mcp_abilities() ) : 0,
			'agents30'    => AgentAccess\Store::agents_active_since( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
			'runsTotal'   => AgentAccess\Store::ability_runs_total(),
			'webmcpOn'    => $settings->enabled( 'enable_webmcp' ),
			'webmcpTools' => $settings->enabled( 'enable_webmcp' ) ? count( ( new WebMcp( $settings ) )->registered_tools() ) : 0,
			'securityTxt' => self::security_txt_state( $settings ),
		);
	}

	/**
	 * security.txt's honest state. The raw toggle is not enough: switched on
	 * with no contact, RFC 9116 makes the file invalid and nothing is served —
	 * reporting "On" there would be the quiet lie this card exists to end.
	 *
	 * @param Settings $settings Settings store.
	 * @return string 'file' (a real file wins) | 'served' | 'needs-contact' | 'off'
	 */
	public static function security_txt_state( $settings ) {
		if ( file_exists( Paths::site_root() . '.well-known/security.txt' ) ) {
			return 'file';
		}
		$txt = new Discovery\SecurityTxt( $settings );
		if ( ! $txt->should_serve() ) {
			return 'off';
		}
		return count( $txt->contacts() ) > 0 ? 'served' : 'needs-contact';
	}

	/**
	 * Signals — what the site announces on its own.
	 *
	 * @param Settings $settings Settings store.
	 * @return array
	 */
	private static function signals( $settings ) {
		$indexnow = IndexNow::state();
		$sitemap  = Sitemap::detect();
		$signal   = (array) $settings->get( 'content_signal', array() );

		$digest_on   = $settings->enabled( 'digest_enabled' );
		$digest_next = 0;
		if ( $digest_on ) {
			// wp_next_scheduled lags a toggle until the next boot syncs the
			// schedule; the computed first slot never lies mid-flight.
			$digest_next = (int) wp_next_scheduled( Digest\Module::CRON );
			if ( ! $digest_next ) {
				$digest_next = Digest\Module::first_send_at();
			}
		}

		$cf      = ( new Cloudflare\Settings() )->public_view();
		$cf_mode = null; // Never configured: not a signal this site has.
		if ( ! empty( $cf['connected'] ) ) {
			$cf_mode = '' !== (string) ( $cf['lastError'] ?? '' ) ? 'error' : 'connected';
		} elseif ( ! empty( $cf['connectedAt'] ) ) {
			$cf_mode = 'off'; // Was connected once — absence is information now.
		}

		return array(
			'indexnowOn'     => ! empty( $indexnow['enabled'] ),
			'indexnowLast'   => (int) ( $indexnow['lastAt'] ?? 0 ),
			'sitemapCovered' => '' !== (string) ( $sitemap['url'] ?? '' ),
			'sitemapLabel'   => (string) ( $sitemap['label'] ?? '' ),
			'robotsManaged'  => ! file_exists( Paths::site_root() . 'robots.txt' ),
			// "AI rules present" = training reserved AND at least one signal
			// carries it — the same reading check_ai_usage_policy() makes.
			'robotsAi'       => empty( $signal['ai_train'] ) && ( $settings->enabled( 'enable_ai_header' ) || $settings->enabled( 'enable_tdmrep' ) ),
			'digestOn'       => $digest_on,
			'digestNext'     => $digest_next,
			'cloudflare'     => $cf_mode,
		);
	}

	/**
	 * Search — the standing half only. Connection flags and the index watch
	 * are option reads; opportunity/split counts deliberately do NOT live
	 * here (the full search report is far past this payload's one-COUNT
	 * budget, and the client already holds those rows in the findings boot).
	 *
	 * @param array $stats The stats payload (for unknownSources).
	 * @return array
	 */
	private static function search( array $stats ) {
		$state  = Search\Report::source_state();
		$google = ! empty( $state['sources']['google']['connected'] );
		$bing   = ! empty( $state['sources']['bing']['connected'] );

		$watch_total = null;
		$watch_on    = null;
		$checked_at  = 0;
		if ( $google ) {
			$view = Google\Index::view( new Google\Settings() );
			if ( ! empty( $view['counts']['checked'] ) ) {
				$watch_total = (int) $view['counts']['checked'];
				$watch_on    = (int) $view['counts']['onGoogle'];
				$checked_at  = (int) ( $view['checkedAt'] ?? 0 );
			}
		}

		$unknown = null;
		if ( ! empty( $stats['unknownSources']['enabled'] ) ) {
			$unknown = count( (array) ( $stats['unknownSources']['hosts'] ?? array() ) );
		}

		return array(
			'googleOn'     => $google,
			'bingOn'       => $bing,
			'watchTotal'   => $watch_total,
			'watchIndexed' => $watch_on,
			'checkedAt'    => $checked_at,
			'unknownHosts' => $unknown,
		);
	}
}
