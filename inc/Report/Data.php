<?php
/**
 * The Report screen's collector: what AI did on this site between two dates,
 * gathered from the same producers the weekly digest reads.
 *
 * ⭐ WHY THIS EXISTS. Every number in this plugin lives on the screen that owns
 * it, which answers "how is this part doing?" — and nothing answered "what
 * happened between these two dates?". The weekly email was the only surface
 * that ever gathered the whole story into one read, and it arrives once a week,
 * for one fixed window. This is that read, on demand, for any window.
 *
 * ⛔ IT OWNS NO NUMBER. Every figure comes from the producer that already owns
 * it — {@see \Agentimus\Activity\Repository} for reads, {@see Referrals} for
 * visits, {@see \Agentimus\AgentAccess\Store} for tool runs, {@see Score} for
 * the score — exactly as {@see \Agentimus\Digest\Data} does. A test pins the two
 * together: for one window they must report the same counts, or the email and
 * the screen have started telling the owner different things.
 *
 * ⭐⭐ THE HONESTY RULE THIS SCREEN IS BUILT ON. Three kinds of number live here
 * and a date range means something different to each, so every block carries
 * its own `freshness`:
 *   - `live`  — the request log, referrals, access events. Exact for any window
 *               inside retention, including a window that ends this minute.
 *   - `lagging` — Google and Bing publish a day or three behind. Asked for a
 *               window they have not reported on yet, they say so instead of
 *               answering zero, because a zero there means "we don't know".
 *   - `state` — the score, the findings, robots.txt. There is no history table:
 *               they describe the site as it stands and say "as of now"
 *               whatever window is asked for.
 *
 * @package Agentimus
 */

namespace Agentimus\Report;

use Agentimus\Activity\Referrals;
use Agentimus\Activity\Repository;
use Agentimus\AgentAccess\Store as AccessStore;
use Agentimus\Readiness;
use Agentimus\RobotsWatch;
use Agentimus\Score;
use Agentimus\Search\Report as SearchReport;
use Agentimus\Settings;
use Agentimus\Visibility\Settings as VisibilitySettings;
use Agentimus\Visibility\Store as VisibilityStore;

defined( 'ABSPATH' ) || exit;

final class Data {

	/** Top clients / sources listed per block. */
	const TOP_ROWS = 5;

	/** Freshness kinds — see the class docblock. */
	const LIVE    = 'live';
	const LAGGING = 'lagging';
	const STATE   = 'state';

	/**
	 * Everything the Report screen shows for one window.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param string   $from     First day of the window, 'Y-m-d' (site clock).
	 * @param string   $to       Last day of the window, 'Y-m-d' (inclusive).
	 * @return array
	 */
	public static function collect( Settings $settings, $from, $to ) {
		$window = self::window( $from, $to );
		$report = self::score_report( $settings );

		return array_merge(
			self::live_blocks( $window ),
			array(
				'robots'    => array(
					'freshness' => self::STATE,
					'change'    => RobotsWatch::change(),
				),
				'search'    => self::search( $window['from'], $window['to'] ),
				'citations' => self::citations( $window['from'], $window['to'] ),
				'score'     => array(
					'freshness' => self::STATE,
					'now'       => null !== $report && isset( $report['score'] ) ? (int) $report['score'] : null,
					'band'      => null !== $report && isset( $report['band'] ) ? (string) $report['band'] : '',
				),
				'nudge'     => array(
					'freshness' => self::STATE,
					'top'       => self::top_nudge( $report ),
				),
			)
		);
	}

	/**
	 * The LIVE blocks only — the same window, the same producers, minus every
	 * figure that cannot change between two ticks of a 15-second clock.
	 *
	 * ⭐ WHY A SLICE EXISTS. The dashboard's Today line rides the app's live
	 * clock, and the blocks it never shows are the expensive ones: `score`
	 * re-runs the whole readiness report, `search` and `citations` read two more
	 * stores — work that answers questions about the site's standing, not about
	 * the minute you are in.
	 *
	 * ⛔ A SLICE, NEVER A SECOND COLLECTOR. The window maths, the clamp and the
	 * counts below are the very code `collect()` returns, so the line, the
	 * Report screen and the weekly email still cannot disagree. Anything that
	 * needs its own query belongs in `collect()`, not here.
	 *
	 * @param string $from First day of the window, 'Y-m-d'.
	 * @param string $to   Last day of the window, 'Y-m-d' (inclusive).
	 * @return array
	 */
	public static function live( $from, $to ) {
		return self::live_blocks( self::window( $from, $to ) );
	}

	/**
	 * One window, resolved: ordered, clamped, and turned into the GMT bounds
	 * every block below reads. Shared so a report and its live slice can never
	 * be counting different days.
	 *
	 * @param string $from First day, 'Y-m-d'.
	 * @param string $to   Last day, 'Y-m-d' (inclusive).
	 * @return array
	 */
	private static function window( $from, $to ) {
		$from = self::day( $from );
		$to   = self::day( $to );
		if ( $to < $from ) {
			list( $from, $to ) = array( $to, $from );
		}

		// ⛔⛔ A WINDOW CANNOT RUN PAST TODAY, and this is not tidiness.
		// The producers disagree about what a day in the future means: the
		// activity store answers it honestly with zero, while the referral
		// store CLAMPS the range it was given and answers with today's rows.
		// Ask for tomorrow and the same card reports "no crawler fetched
		// anything" beside "1 visit from AI" — two blocks, one row, different
		// days. Clamped here, once, so every block below reads the same window.
		// Found on his site, 2026-08-25, where a browser six hours ahead of UTC
		// asked for a GMT day that had not started.
		$today = self::today_gmt();
		if ( $to > $today ) {
			$to = $today;
		}
		if ( $from > $today ) {
			$from = $today;
		}

		// The activity store works in GMT datetimes over a half-open window:
		// [start of the first day, start of the day after the last).
		$start = $from . ' 00:00:00';
		$end   = gmdate( 'Y-m-d 00:00:00', strtotime( $to . ' +0000' ) + DAY_IN_SECONDS );

		// The window immediately before this one, same length — what "up from"
		// compares against. Null when retention cannot cover both.
		$length    = (int) round( ( strtotime( $end . ' +0000' ) - strtotime( $start . ' +0000' ) ) / DAY_IN_SECONDS );
		$retention = Repository::retention_days();

		return array(
			'from'      => $from,
			'to'        => $to,
			'today'     => $today,
			'start'     => $start,
			'end'       => $end,
			'length'    => $length,
			'retention' => $retention,
			'hasPrior'  => $retention >= 2 * $length,
			'prevStart' => gmdate( 'Y-m-d 00:00:00', strtotime( $start . ' +0000' ) - $length * DAY_IN_SECONDS ),
		);
	}

	/**
	 * The range block and the four live counts, for a resolved window.
	 *
	 * @param array $w Window, from {@see window()}.
	 * @return array
	 */
	private static function live_blocks( array $w ) {
		$start      = $w['start'];
		$end        = $w['end'];
		$prev_start = $w['hasPrior'] ? $w['prevStart'] : null;

		return array(
			'range'     => array(
				'from'      => $w['from'],
				'to'        => $w['to'],
				'days'      => $w['length'],
				'label'     => self::label( $w['from'], $w['to'] ),
				// A window that runs past yesterday includes a day still being
				// written — the screen says "so far" rather than pretending the
				// day is closed.
				'open'      => $w['to'] >= $w['today'],
				// ⭐ The day the SERVER calls today, sent whatever window was
				// asked for. The screen counts its presets back from this, so
				// "7 days" is seven of these days and never seven of the
				// browser's. ⛔ Not the same as `to`: a custom window ending in
				// June must not move where "today" is.
				'today'     => $w['today'],
				// ⛔ Whether that day is the READER's day is not decided here:
				// the site's timezone option is not where the person looking at
				// the screen is. The browser compares its own calendar against
				// this and marks the label itself — see utcDayNote() in
				// wpDate.js. wpftest, set to UTC and read on a +06 laptop, is
				// exactly the case a server-side test cannot see.
				//
				// ⛔⛔ AND THE COUNTS THEMSELVES STAY UTC, whatever either clock
				// says. The referral store keeps one row per (day, source, path)
				// with the day baked in at write time — there is no per-visit
				// stamp to re-bucket, here or in the history already stored.
				// Counting reads on the site's clock and visits on UTC would put
				// two different days in one row, which is the one thing the
				// one-count law forbids.
			),
			'reads'     => array(
				'freshness' => self::LIVE,
				'total'     => Repository::count_between( $start, $end ),
				'prev'      => null !== $prev_start ? Repository::count_between( $prev_start, $start ) : null,
				'byClient'  => Repository::counts_by( 'agent', $start, self::TOP_ROWS, $end ),
				'retention' => $w['retention'],
			),
			'visits'    => self::visits( $w['from'], $w['to'], $prev_start, $start ),
			'impostors' => array(
				'freshness' => self::LIVE,
				'total'     => Repository::count_between( $start, $end, 2 ),
			),
			'access'    => array(
				'freshness' => self::LIVE,
				'events'    => AccessStore::count_active_between( $start, $end ),
			),
		);
	}

	/**
	 * Today, for the dashboard's one-line summary. The same collector — the
	 * strip and the screen can never disagree, because there is only one of them.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array
	 */
	public static function today( Settings $settings ) {
		$today = self::today_gmt();
		return self::collect( $settings, $today, $today );
	}

	/**
	 * The day this report counts in.
	 *
	 * ⛔⛔ GMT, NOT the site's local date, and the difference is not academic.
	 * Every row read here is stamped in GMT ({@see \Agentimus\Activity\Recorder}),
	 * the referral days are GMT days, and the dashboard's other tiles and the
	 * weekly digest are built on UTC calendar days on purpose. Naming "today"
	 * with `current_time()` mixed two clocks: on a site six hours ahead of UTC,
	 * the small hours of the local day asked for a GMT day that had not started
	 * yet, so a morning with dozens of crawler reads behind it reported
	 * "nothing yet today". Found on his own site, 2026-08-25, by reading the
	 * same window out of the log and getting 48 where the card had 0.
	 *
	 * @return string
	 */
	private static function today_gmt() {
		return gmdate( 'Y-m-d' );
	}

	/* ------------------------------------------------------------------ */

	/** Visits people arrived on from an AI answer, by day. */
	private static function visits( $from, $to, $prev_start, $start ) {
		$now = Referrals::report(
			array(
				'from' => $from,
				'to'   => $to,
			)
		);

		$prev = null;
		if ( null !== $prev_start ) {
			$prev = Referrals::report(
				array(
					'from' => gmdate( 'Y-m-d', strtotime( $prev_start . ' +0000' ) ),
					'to'   => gmdate( 'Y-m-d', strtotime( $start . ' +0000' ) - DAY_IN_SECONDS ),
				)
			);
		}

		return array(
			'freshness' => self::LIVE,
			'total'     => (int) ( $now['total'] ?? 0 ),
			'prev'      => null === $prev ? null : (int) ( $prev['total'] ?? 0 ),
			'bySource'  => array_slice( (array) ( $now['bySource'] ?? array() ), 0, self::TOP_ROWS ),
		);
	}

	/**
	 * Search, per connected engine, summed over the days the engine has
	 * actually published.
	 *
	 * ⛔ An engine that has published nothing for the asked days reports
	 * `covered = 0` and its own newest day — it never answers zero, because a
	 * zero here would read as "nobody searched" when it means "not reported
	 * yet". This is the block the whole freshness idea was written for.
	 */
	private static function search( $from, $to ) {
		$engines = array();

		foreach ( array( 'google', 'bing' ) as $source ) {
			$state = SearchReport::source_state( $source );
			if ( empty( $state['sources'][ $source ]['connected'] ) ) {
				continue;
			}

			$daily   = (array) ( SearchReport::extras( $source )['daily'] ?? array() );
			$latest  = '';
			$clicks  = 0;
			$shown   = 0;
			$covered = 0;
			foreach ( $daily as $row ) {
				$date = (string) ( $row['date'] ?? '' );
				if ( '' === $date ) {
					continue;
				}
				if ( $date > $latest ) {
					$latest = $date;
				}
				if ( $date < $from || $date > $to ) {
					continue;
				}
				$covered++;
				$clicks += (int) ( $row['clicks'] ?? 0 );
				$shown  += (int) ( $row['impressions'] ?? 0 );
			}

			$engines[] = array(
				'source'     => $source,
				'covered'    => $covered,
				'clicks'     => $clicks,
				'shown'      => $shown,
				'latestDay'  => $latest,
			);
		}

		return array(
			'freshness' => self::LAGGING,
			'engines'   => $engines,
		);
	}

	/**
	 * Citation runs that fall inside the window. Runs are keyed by the moment
	 * they started, so "which runs happened between these dates" is the run ids
	 * themselves — and a window with none says when the last one was rather
	 * than reading as a failure.
	 */
	private static function citations( $from, $to ) {
		if ( ! ( new Settings() )->get( 'enable_visibility', false ) ) {
			return array(
				'freshness' => self::LAGGING,
				'enabled'   => false,
				'runs'      => 0,
				'summary'   => null,
				'lastRunAt' => '',
				'scheduled' => false,
			);
		}

		$start = (int) strtotime( $from . ' 00:00:00 +0000' );
		$end   = (int) strtotime( $to . ' 00:00:00 +0000' ) + DAY_IN_SECONDS;

		$runs = 0;
		$last = 0;
		foreach ( VisibilityStore::recent_run_ids( 60 ) as $rid ) {
			if ( $rid > $last ) {
				$last = (int) $rid;
			}
			if ( $rid >= $start && $rid < $end ) {
				$runs++;
			}
		}

		$vsettings = new VisibilitySettings();
		$dashboard = VisibilityStore::dashboard( $vsettings );

		return array(
			'freshness' => self::LAGGING,
			'enabled'   => true,
			'runs'      => $runs,
			// The latest run's headline, whether or not it landed in this window
			// — a screen that says "no run in these days" still has to say what
			// the last one found.
			'summary'   => isset( $dashboard['summary'] ) ? $dashboard['summary'] : null,
			'lastRunAt' => $last ? gmdate( 'Y-m-d H:i:s', $last ) : '',
			// Whether the schedule is actually ON — the card must not say
			// "checks run on a schedule" to an owner whose schedule is off.
			'scheduled' => (bool) $vsettings->get( 'active', false ),
		);
	}

	/** A day string, defaulting to today on anything unparseable. */
	private static function day( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '~^\d{4}-\d{2}-\d{2}$~', $value ) ) {
			return self::today_gmt();
		}
		return $value;
	}

	/** "Aug 25, 2026" or "Aug 19 – Aug 25, 2026", in the site's own date format. */
	private static function label( $from, $to ) {
		if ( $from === $to ) {
			return self::day_label( $from );
		}
		return self::day_label( $from ) . ' – ' . self::day_label( $to );
	}

	/**
	 * One UTC calendar day, named in the site's own date format.
	 *
	 * ⭐ Formatted ON THE UTC FACE, said out loud. These are UTC calendar days;
	 * the day must survive being printed on a site in any timezone. The old
	 * line reached the same answer through `date_i18n( $format, $ts )`, which
	 * gets there only because its timestamp path re-reads the stamp as local
	 * wall-clock — behaviour core itself labels "a legacy implementation
	 * quirk". Correct today, and nothing anyone would notice the day it stops
	 * being. Checked, 2026-08-25: this was not a live bug, it was a load-bearing
	 * accident, and {@see ReportDbTest} now pins the outcome either way.
	 *
	 * @param string $day 'Y-m-d', a UTC calendar day.
	 * @return string
	 */
	private static function day_label( $day ) {
		$format = (string) get_option( 'date_format', 'M j, Y' );
		if ( '' === trim( $format ) ) {
			$format = 'M j, Y';
		}
		return wp_date( $format, strtotime( $day . ' +0000' ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * The full score report, fail-open — a scoring throw degrades to "no score
	 * block", never a dead screen. The same guard the digest uses.
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
	 * The top-ranked thing worth doing — fails and warnings only, never an info
	 * row, because "one thing worth doing" has to be a thing worth doing.
	 *
	 * @param array|null $report Score report.
	 * @return array{label:string,detail:string}|null
	 */
	private static function top_nudge( $report ) {
		if ( null === $report || empty( $report['actions'] ) || ! is_array( $report['actions'] ) ) {
			return null;
		}
		foreach ( $report['actions'] as $action ) {
			if ( 'info' === (string) ( $action['severity'] ?? '' ) ) {
				continue;
			}
			$label = (string) ( $action['title'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			return array(
				'label'  => $label,
				'detail' => (string) ( $action['why'] ?? '' ),
			);
		}
		return null;
	}
}
