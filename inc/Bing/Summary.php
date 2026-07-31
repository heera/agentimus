<?php
/**
 * The Bing summary — one composition shared by the admin REST route and the
 * MCP read tool, so an assistant asking "can AI search find this site?" gets
 * exactly what the owner's own screen shows: index size, crawl health, and
 * the conflicts between Bing's view and the site's declared policy.
 *
 * @package Agentimus
 */

namespace Agentimus\Bing;

defined( 'ABSPATH' ) || exit;

final class Summary {

	/** @var int Errors over the window below this stay a fact, not a warning. */
	const MIN_ERRORS = 25;

	/**
	 * Build the summary payload.
	 *
	 * @param Settings            $bing The Bing connection store.
	 * @param \Agentimus\Settings $core The core settings — the declared policy.
	 * @param int                 $days Window length in days (already clamped by the caller).
	 * @return array
	 */
	public static function build( Settings $bing, \Agentimus\Settings $core, $days ) {
		$days = max( 1, (int) $days );
		$view = $bing->public_view();
		if ( empty( $view['connected'] ) ) {
			return array_merge( $view, array( 'days' => $days ) );
		}

		$rows   = Table::window( $days );
		$latest = ! empty( $rows ) ? $rows[ count( $rows ) - 1 ] : null;

		$errors = 0;
		$trend  = array();
		foreach ( $rows as $row ) {
			$errors  += $row['code_4xx'] + $row['code_5xx'];
			// The whole day travels with its bar, so a click can open the day's
			// crawl picture without another request.
			$trend[] = array(
				'date'            => $row['date_at'],
				'inIndex'         => $row['in_index'],
				'crawled'         => $row['crawled'],
				'ok'              => $row['code_2xx'],
				'redirects'       => $row['code_301'] + $row['code_302'],
				'clientErrors'    => $row['code_4xx'],
				'serverErrors'    => $row['code_5xx'],
				'blockedByRobots' => $row['blocked_robots'],
			);
		}

		$totals = array(
			'inIndex'         => $latest ? $latest['in_index'] : 0,
			'crawledLatest'   => $latest ? $latest['crawled'] : 0,
			'crawlErrors'     => $errors,
			'blockedByRobots' => $latest ? $latest['blocked_robots'] : 0,
		);

		return array_merge( $view, array(
			'days'      => $days,
			'totals'    => $totals,
			'trend'     => $trend,
			'conflicts' => self::conflicts( $totals, $rows, $core ),
		) );
	}

	/**
	 * Bing's view vs the declared policy — evidence, not settings reads.
	 *
	 * @param array               $totals The summary totals.
	 * @param array               $rows   The window rows, oldest first.
	 * @param \Agentimus\Settings $core   Core settings.
	 * @return array<int,array{id:string,level:string,title:string,body:string,url:string}>
	 */
	private static function conflicts( array $totals, array $rows, \Agentimus\Settings $core ) {
		$out = array();

		// ── robots.txt closes doors the policy holds open ──────────────────
		$signal         = (array) $core->get( 'content_signal', array() );
		$search_welcome = ! isset( $signal['search'] ) || false !== $signal['search'];
		if ( $search_welcome && $totals['blockedByRobots'] > 0 ) {
			$out[] = array(
				'id'    => 'bing-robots-blocked',
				'level' => 'warn',
				'title' => __( 'Bing reports pages blocked by robots.txt, but your policy welcomes search', 'agentimus' ),
				'body'  => sprintf(
					/* translators: %s: number of pages. */
					__( 'Bing says %s of your pages are closed to it by robots.txt. Your site policy says search crawlers are welcome — so something in robots.txt is saying more than you chose. Agentimus writes your robots.txt: check for another plugin or a manual rule adding lines. Once robots.txt opens up, this warning clears with Bing’s next numbers.', 'agentimus' ),
					number_format_i18n( $totals['blockedByRobots'] )
				),
				'url'   => home_url( '/robots.txt' ),
			);
		}

		// ── the crawler keeps hitting errors ───────────────────────────────
		// Currency rule: the week made it significant, but only a still-erroring
		// site fires — the most recent day must carry errors too.
		$last_day_errors = 0;
		if ( ! empty( $rows ) ) {
			$last            = $rows[ count( $rows ) - 1 ];
			$last_day_errors = $last['code_4xx'] + $last['code_5xx'];
		}
		if ( $totals['crawlErrors'] >= self::MIN_ERRORS && $last_day_errors > 0 ) {
			$out[] = array(
				'id'    => 'bing-crawl-errors',
				'level' => 'warn',
				'title' => __( 'Bing keeps hitting errors on your site', 'agentimus' ),
				'body'  => sprintf(
					/* translators: 1: error count, 2: number of days. */
					__( 'Bing’s crawler got %1$s error answers in the last %2$d days — including some on the most recent day. Pages that answer with errors drop out of Bing’s index, and ChatGPT search finds pages through that index today. Check your server and any firewall in front of it. Once the errors stop, this warning clears with Bing’s next numbers.', 'agentimus' ),
					number_format_i18n( $totals['crawlErrors'] ),
					count( $rows )
				),
				'url'   => 'https://www.bing.com/webmasters/',
			);
		}

		return $out;
	}
}
