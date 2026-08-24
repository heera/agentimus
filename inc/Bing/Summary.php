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
		// IndexNow travels on both branches: it needs no Bing connection to
		// work, and the card is where an owner looks for "does Bing know?".
		$view['indexnow'] = \Agentimus\IndexNow::state();
		if ( empty( $view['connected'] ) ) {
			return array_merge( $view, array( 'days' => $days ) );
		}

		$rows = Table::window( $days );

		// ⛔⛔ THE HEADLINE TILES MUST COME FROM A DAY BING ACTUALLY REPORTED.
		// They read the most recent row, and Bing answers for dates it has no
		// numbers for {@see Table::reported()} — so a window ending on one of
		// those printed "0 pages in Bing's index" under a heading that means it,
		// beside a chart whose other twenty-nine days said 229. The newest day
		// that carries numbers is the honest "where the index stands"; a day
		// that says nothing cannot speak for the site.
		$latest = null;
		for ( $i = count( $rows ) - 1; $i >= 0; $i-- ) {
			if ( Table::reported( $rows[ $i ] ) ) {
				$latest = $rows[ $i ];
				break;
			}
		}

		$errors = 0;
		$trend  = array();
		foreach ( $rows as $row ) {
			$errors  += $row['code_4xx'] + $row['code_5xx'];
			// The whole day travels with its bar, so a click can open the day's
			// crawl picture without another request.
			$trend[] = array(
				'date'            => $row['date_at'],
				// ⭐ Whether this day is a reading at all. Every number below it
				// is 0 when this is false, and those zeros are Bing's silence,
				// not the site's. The screen draws the day as a gap it can name
				// rather than as a collapse to nothing.
				'reported'        => Table::reported( $row ),
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
			// Bing's own sitemap record — url, lastReadAt (Bing's date, '' when
			// never read), urls — so the card can answer "does Bing know my
			// sitemap?" the way the Google card answers it from sitemaps.list.
			// feedsAt distinguishes "no sitemap registered" (fetched, empty)
			// from "not fetched yet" (0) — the card must never claim the first
			// while the truth is the second.
			'feeds'     => array_values( (array) $bing->get( 'feeds', array() ) ),
			'feedsAt'   => (int) $bing->get( 'feeds_at', 0 ),
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
		// ⚠️ The most recent day BING REPORTED, not the most recent row. A date
		// Bing answered for without numbers {@see Table::reported()} carries
		// zeros, and reading those as "no errors today" would switch this
		// warning off on a site that is still erroring — the currency rule
		// defeated by a non-answer rather than by a fix.
		$last_day_errors = 0;
		for ( $i = count( $rows ) - 1; $i >= 0; $i-- ) {
			if ( Table::reported( $rows[ $i ] ) ) {
				$last_day_errors = $rows[ $i ]['code_4xx'] + $rows[ $i ]['code_5xx'];
				break;
			}
		}
		if ( $totals['crawlErrors'] >= self::MIN_ERRORS && $last_day_errors > 0 ) {
			$errors_4xx = 0;
			$errors_5xx = 0;
			foreach ( $rows as $row ) {
				$errors_4xx += $row['code_4xx'];
				$errors_5xx += $row['code_5xx'];
			}
			// The advice must match what the numbers say: 4xx = pages that no
			// longer exist (a links-and-redirects chore), 5xx = the site failing
			// to answer (a server chore). "Check your server" over a pile of
			// 404s sends the owner to the wrong room.
			if ( 0 === $errors_5xx ) {
				$body = sprintf(
					/* translators: 1: error count, 2: number of days. */
					__( 'Bing’s crawler got %1$s error answers in the last %2$d days — including some on the most recent day. Every one was a “page not found” kind of answer (4xx), which usually means pages that no longer exist: links or a sitemap entry still point at deleted or moved URLs. Open Bing Webmaster Tools to see which URLs, then fix the links or add redirects. Pages that answer with errors drop out of Bing’s index, and ChatGPT search finds pages through that index today. Once the errors stop, this warning clears with Bing’s next numbers.', 'agentimus' ),
					number_format_i18n( $totals['crawlErrors'] ),
					count( $rows )
				);
			} elseif ( 0 === $errors_4xx ) {
				$body = sprintf(
					/* translators: 1: error count, 2: number of days. */
					__( 'Bing’s crawler got %1$s error answers in the last %2$d days — including some on the most recent day. Every one was a server error (5xx) — the site failed to answer. Check your server and any firewall in front of it. Pages that answer with errors drop out of Bing’s index, and ChatGPT search finds pages through that index today. Once the errors stop, this warning clears with Bing’s next numbers.', 'agentimus' ),
					number_format_i18n( $totals['crawlErrors'] ),
					count( $rows )
				);
			} else {
				$body = sprintf(
					/* translators: 1: error count, 2: number of days, 3: 4xx count, 4: 5xx count. */
					__( 'Bing’s crawler got %1$s error answers in the last %2$d days — including some on the most recent day. %3$s were “page not found” kind of answers (4xx) — usually pages that no longer exist, so fix the links or add redirects — and %4$s were server errors (5xx), so also check your server and any firewall in front of it. Pages that answer with errors drop out of Bing’s index, and ChatGPT search finds pages through that index today. Once the errors stop, this warning clears with Bing’s next numbers.', 'agentimus' ),
					number_format_i18n( $totals['crawlErrors'] ),
					count( $rows ),
					number_format_i18n( $errors_4xx ),
					number_format_i18n( $errors_5xx )
				);
			}
			$out[] = array(
				'id'    => 'bing-crawl-errors',
				'level' => 'warn',
				'title' => __( 'Bing keeps hitting errors on your site', 'agentimus' ),
				'body'  => $body,
				'url'   => 'https://www.bing.com/webmasters/',
			);
		}

		return $out;
	}
}
