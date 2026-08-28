<?php
/**
 * Weekly digest data collector. One entry point, {@see collect()}, returns the
 * plain array the Renderer consumes. All week boundaries are UTC CALENDAR
 * days — the same clock as every dashboard tile (see
 * Activity\Repository::stats() for why rolling windows were banned there).
 *
 * The digest week is the full days ending yesterday: [today-7d, today) UTC.
 * The comparison week is the 7 before that. Honesty rules:
 *   - If retention < 7 days, the window shrinks to what actually exists and
 *     the email labels the real range.
 *   - The prior-week comparison only exists when retention covers BOTH weeks
 *     (>= 2 windows); otherwise prev = null and the renderer says nothing
 *     instead of comparing against a half-deleted week.
 *   - The previous SCORE comes from the last digest's snapshot, never from
 *     re-computation — "up from 78" must mean "up from what we told you".
 *
 * @package Agentimus
 */

namespace Agentimus\Digest;

use Agentimus\Activity\Referrals;
use Agentimus\Activity\Repository;
use Agentimus\AgentAccess\Store;
use Agentimus\Readiness;
use Agentimus\Score;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Data {

	/** Top clients listed in the email. */
	const TOP_CLIENTS = 5;

	/**
	 * Collect everything the renderer needs.
	 *
	 * @param Settings   $settings Plugin settings.
	 * @param array|null $snapshot The previous digest's snapshot ({score, sent_at}), or null on the first run.
	 * @return array
	 */
	/**
	 * Live edge-vs-policy conflicts, as { level, title, since } — or an empty
	 * list when no Cloudflare zone is connected, which is most sites.
	 *
	 * Wrapped: the digest is sent on a schedule with nobody watching, so a
	 * subsystem that throws must cost its own section and never the email.
	 *
	 * @param Settings $settings Core settings — the declared policy conflicts compare against.
	 * @return array<int,array{level:string,title:string,since:int}>
	 */
	private static function edge_conflicts( Settings $settings ) {
		try {
			if ( ! class_exists( '\\Agentimus\\Cloudflare\\Settings' ) ) {
				return array();
			}
			$store = new \Agentimus\Cloudflare\Settings();
			if ( ! $store->connected() ) {
				return array();
			}
			$summary = \Agentimus\Cloudflare\Summary::build( $store, $settings, 7 );
			$out     = array();
			// Visible only: one the owner hid is a decision, and the email must
			// not reopen an argument they have already settled.
			foreach ( (array) ( $summary['conflicts'] ?? array() ) as $c ) {
				if ( is_array( $c ) && ! empty( $c['title'] ) ) {
					$out[] = array(
						'level' => (string) ( $c['level'] ?? 'info' ),
						'title' => (string) $c['title'],
						'since' => (int) ( $c['since'] ?? 0 ),
						// The phrase Summary already built — date and claim together.
						'sinceText' => (string) ( $c['sinceText'] ?? '' ),
					);
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public static function collect( Settings $settings, $snapshot ) {
		$retention = Repository::retention_days();
		$days      = min( 7, max( 1, $retention ) );

		$to        = gmdate( 'Y-m-d 00:00:00' ); // Exclusive: today's partial day stays out.
		$from      = gmdate( 'Y-m-d 00:00:00', time() - $days * DAY_IN_SECONDS );
		$has_prior = $retention >= 2 * $days;
		$prev_from = gmdate( 'Y-m-d 00:00:00', time() - 2 * $days * DAY_IN_SECONDS );

		$referrals_now  = self::referrals_between( self::day_of( $from ), self::day_of( $to, -1 ) );
		$referrals_prev = $has_prior ? self::referrals_between( self::day_of( $prev_from ), self::day_of( $from, -1 ) ) : null;

		$report = self::score_report( $settings );

		return array(
			'site_name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'first'     => null === $snapshot,
			'period'    => array(
				'label' => self::period_label( $from, $to ),
				'days'  => $days,
			),
			'agents'    => array(
				'total'     => self::count_between( $from, $to ),
				'prev'      => $has_prior ? self::count_between( $prev_from, $from ) : null,
				'by_client' => self::by_client( $from, $to, self::TOP_CLIENTS ),
			),
			'referrals' => array(
				'total'     => (int) ( $referrals_now['total'] ?? 0 ),
				'prev'      => null === $referrals_prev ? null : (int) ( $referrals_prev['total'] ?? 0 ),
				'by_source' => (array) ( $referrals_now['bySource'] ?? array() ),
			),
			'impostors' => array( 'total' => self::count_between( $from, $to, 2 ) ),
			'robots'    => array( 'change' => \Agentimus\RobotsWatch::change() ),
			// ⭐ THE ONE CHANNEL THAT REACHES AN OWNER WHO IS NOT IN WP-ADMIN. An
			// edge-vs-policy conflict lived only on the Request Log, so it was
			// found by walking onto that screen — and one of his went unseen for
			// weeks. It is a finding now too, but the digest is what arrives
			// whether or not anybody opens the plugin.
			'edge'      => array( 'conflicts' => self::edge_conflicts( $settings ) ),
			'access'    => array( 'events' => self::access_events( $from, $to ) ),
			'score'     => array(
				'now'  => null !== $report && isset( $report['score'] ) ? (int) $report['score'] : null,
				'prev' => is_array( $snapshot ) && isset( $snapshot['score'] ) && null !== $snapshot['score'] ? (int) $snapshot['score'] : null,
			),
			'nudge'     => self::top_nudge( $report ),
			'links'     => array(
				'dashboard' => admin_url( 'admin.php?page=agentimus' ),
				'stop'      => '', // Filled by the Module — it owns the stop key.
			),
		);
	}

	/* ------------------------------------------------------------------ */

	/** A GMT datetime's calendar day, optionally shifted by whole days. */
	private static function day_of( $datetime, $shift_days = 0 ) {
		return gmdate( 'Y-m-d', strtotime( $datetime . ' +0000' ) + $shift_days * DAY_IN_SECONDS );
	}

	/** "Jul 13, 2026 – Jul 19, 2026" — through the site's own date format, per house law. */
	private static function period_label( $from, $to ) {
		$format = (string) get_option( 'date_format', 'M j, Y' );
		if ( '' === trim( $format ) ) {
			$format = 'M j, Y';
		}
		$start = strtotime( $from . ' +0000' );
		$last  = strtotime( $to . ' +0000' ) - DAY_IN_SECONDS; // The window's last included day.
		return date_i18n( $format, $start ) . ' – ' . date_i18n( $format, $last );
	}

	/**
	 * Activity rows in [$from, $to), optionally narrowed to one verdict.
	 *
	 * @param string   $from    GMT datetime, inclusive.
	 * @param string   $to      GMT datetime, exclusive.
	 * @param int|null $verdict Verdict filter (2 = proven impersonator), or null for all.
	 * @return int
	 */
	private static function count_between( $from, $to, $verdict = null ) {
		return Repository::count_between( $from, $to, $verdict );
	}

	/**
	 * Top clients by request count in [$from, $to).
	 *
	 * ⛔ NOT ITS OWN QUERY ANY MORE — his rule, 2026-08-21. This file used to
	 * hand-write the same GROUP BY the dashboard's breakdown runs; they agreed
	 * only by coincidence of both being written correctly on the same day.
	 */
	private static function by_client( $from, $to, $limit ) {
		return Repository::counts_by( 'agent', $from, $limit, $to );
	}

	/** Referral totals for an inclusive Y-m-d day range, via the public report API. */
	private static function referrals_between( $from_day, $to_day ) {
		$report = Referrals::report(
			array(
				'from' => $from_day,
				'to'   => $to_day,
			)
		);
		return array(
			'total'    => (int) ( $report['total'] ?? 0 ),
			'bySource' => (array) ( $report['bySource'] ?? array() ),
		);
	}

	/** Agent-access events ACTIVE in the window. The store is event-keyed, not per-hit. */
	private static function access_events( $from, $to ) {
		return Store::count_active_between( $from, $to );
	}

	/**
	 * The full score report, fail-open — same guard as Admin::aeo_score(): a
	 * scoring throw degrades to "omit the score section", never a dead cron.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array|null
	 */
	private static function score_report( Settings $settings ) {
		try {
			$readiness = ( new Readiness( $settings ) )->report();
			return ( new Score( $settings ) )->report( $readiness );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The top-ranked improvement worth an email line. Actions arrive already
	 * impact-ranked (fails, then warns, then content); info rows are skipped —
	 * "one thing worth doing" must be a thing worth doing.
	 *
	 * @param array|null $report Score report, or null when scoring failed.
	 * @return array{label:string,detail:string}|null
	 */
	private static function top_nudge( $report ) {
		if ( null === $report || empty( $report['actions'] ) || ! is_array( $report['actions'] ) ) {
			return null;
		}
		foreach ( $report['actions'] as $action ) {
			$severity = (string) ( $action['severity'] ?? '' );
			if ( 'info' === $severity ) {
				continue;
			}
			$label = (string) ( $action['title'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			return array(
				'label'  => $label,
				// Action rows explain themselves under `why` (see Score::actions()).
				'detail' => (string) ( $action['why'] ?? '' ),
			);
		}
		return null;
	}
}
