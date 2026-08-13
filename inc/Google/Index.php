<?php
/**
 * Google index watch — is Google's index holding this site's pages?
 *
 * Google exposes no bulk index report (its Crawl Stats screen has no API), so
 * the URL Inspection API — 2,000 lookups a day, one URL at a time — is the
 * only honest way to ask. Three tiers share that budget:
 *
 * - the WATCHLIST: homepage, busiest pages, newest posts — checked every day.
 *   Small, stable, the pages where silent de-indexing costs the most.
 * - PROMOTED PROBLEMS: any page a check found unhealthy joins the daily check
 *   (stalest answer first, capped at {@see PROMOTED_DAILY}) until it heals —
 *   "watching" a problem means asking about it every day, not once a rotation.
 * - the SITE ROTATION: every other published URL, walked in daily slices of
 *   {@see ROTATION_DAILY}. A small site gets full coverage every day; a big
 *   one gets every page re-checked on a stated cadence — never a pretended
 *   whole-site snapshot.
 *
 * Rows are for what needs eyes. Only PROBLEMS stand as rows; healthy pages
 * become counts, because 500 green rows is noise and one number isn't — and a
 * page that heals announces "now on Google" for {@see HEALED_KEEP} before
 * going quiet like the rest: the disappearance of a problem row is news, and
 * news must never be delivered as silence.
 *
 * Why it earns a card at all: Google's index is what AI Overviews, AI Mode and
 * Gemini grounding read — the Google twin of "Bing's index is what ChatGPT
 * search reads".
 *
 * Fail-open throughout: a spent quota or a dead token keeps the last good
 * answers and names the failure; it never erases and never invents.
 *
 * @package Agentimus
 */

namespace Agentimus\Google;

use Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Index {

	/** @var string Option key — one autoload-off option. */
	const OPTION = 'agentimus_google_index';

	/** @var int Busiest Pages watched, by Google's own impression counts. */
	const BUSIEST = 10;

	/** @var int Newest published posts watched — "is my new post indexed yet?". */
	const NEWEST = 10;

	/** @var int Site-rotation inspections per day — with the watchlist, ~6% of
	 * Google's budget, leaving plenty for other tools sharing the property. */
	const ROTATION_DAILY = 100;

	/** @var int Coverage entries kept at most; growth stops (never evicts) past
	 * this. Stated on-screen when it binds — a silent cap would read as "covered". */
	const COVERAGE_CAP = 5000;

	/** @var int Published-ID fetch ceiling for the rotation walk. */
	const IDS_CAP = 10000;

	/** @var int Google's documented URL Inspection budget, per property per day. */
	const DAILY_CAP = 2000;

	/** @var int Problem rows the VIEW ships at most — REST, MCP and the DOM all
	 * ride this payload; problemsTotal always carries the uncapped truth. */
	const PROBLEMS_CAP = 50;

	/** @var int Rows per page of the on-demand problems listing — one bounded
	 * slice per page turn, however sick the site. */
	const PROBLEMS_PER_PAGE = 50;

	/** @var int Consecutive no-answer failures (a timeout, a reset, a Google
	 * 5xx) a run absorbs in silence before it pauses loudly. One slow Google
	 * answer is routine; three in a row is a line worth telling the owner about. */
	const BLIP_LIMIT = 3;

	/** @var int Problem pages promoted into the daily check at most — capped so
	 * a sick site cannot spend the whole inspection budget on its wounds.
	 * Stalest answer first, so with more problems than this every page still
	 * takes its turn at the front. */
	const PROMOTED_DAILY = 20;

	/** @var int How long a healed page keeps announcing "now on Google" before
	 * it goes quiet like every other healthy page. */
	const HEALED_KEEP = 2 * DAY_IN_SECONDS;

	/** @var int Healed rows the view ships at most — the announcement list is
	 * bounded like every other list here. */
	const HEALED_CAP = 20;

	/**
	 * Everything one sweep run should inspect: the watchlist, then the
	 * promoted problems (both daily), then the day's rotation slice.
	 *
	 * @param \Agentimus\Settings|null $core Core settings (injectable for tests).
	 * @return array<int,array{url:string,post_id:int,reason:string}>
	 */
	public static function run_targets( ?\Agentimus\Settings $core = null ) {
		$watch = self::targets( $core );
		$daily = array_merge( $watch, self::promoted_targets( $watch ) );
		return array_merge( $daily, self::rotation_targets( $daily, $core ) );
	}

	/**
	 * The promoted tier: every page the coverage map holds as a PROBLEM joins
	 * the daily check until it heals — a problem page is exactly the page the
	 * owner is waiting on, and waiting deserves a daily answer, not a rotation
	 * slot. Stalest answer first, capped at {@see PROMOTED_DAILY} and stated
	 * on the card: with 100 problems on a 200-page site, the twenty asked
	 * longest ago go first and the rest keep their rotation cadence.
	 *
	 * @param array $watch The watchlist (for dedup).
	 * @return array<int,array{url:string,post_id:int,reason:string}>
	 */
	public static function promoted_targets( array $watch ) {
		$seen = array();
		foreach ( $watch as $t ) {
			$seen[ self::norm( $t['url'] ) ] = true;
		}
		$sick = array();
		foreach ( self::stored()['cov'] as $key => $entry ) {
			if ( isset( $seen[ $key ] ) || ! is_array( $entry ) || ! self::is_problem( $entry ) ) {
				continue;
			}
			$sick[] = $entry;
		}
		usort( $sick, static function ( $a, $b ) {
			return (int) ( isset( $a['inspected_at'] ) ? $a['inspected_at'] : 0 ) <=> (int) ( isset( $b['inspected_at'] ) ? $b['inspected_at'] : 0 );
		} );

		$out = array();
		foreach ( array_slice( $sick, 0, self::PROMOTED_DAILY ) as $entry ) {
			$out[] = array(
				'url'     => (string) $entry['url'],
				'post_id' => (int) ( isset( $entry['post_id'] ) ? $entry['post_id'] : 0 ),
				'reason'  => 'problem',
			);
		}
		return $out;
	}

	/**
	 * The watchlist: the homepage, the newest posts, then the busiest pages.
	 * Order matters twice — it is the display order, and when quota runs out
	 * mid-sweep it decides who got checked first.
	 *
	 * @param \Agentimus\Settings|null $core Core settings (injectable for tests).
	 * @return array<int,array{url:string,post_id:int,reason:string}>
	 */
	public static function targets( ?\Agentimus\Settings $core = null ) {
		$core = $core ? $core : new \Agentimus\Settings();

		$home = array(
			array(
				'url'     => home_url( '/' ),
				'post_id' => 0,
				'reason'  => 'home',
			),
		);

		$newest = array();
		$posts  = get_posts( array(
			'post_type'        => (array) $core->get( 'post_types', array( 'post', 'page' ) ),
			'post_status'      => 'publish',
			'numberposts'      => self::NEWEST,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		) );
		foreach ( (array) $posts as $post ) {
			$link = get_permalink( $post );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$newest[] = array(
				'url'     => $link,
				'post_id' => (int) $post->ID,
				'reason'  => 'new',
			);
		}

		$busiest = array();
		$by_page = array();
		foreach ( Search\Table::snapshot( 'google' ) as $row ) {
			$url = (string) $row['page_url'];
			if ( '' === $url ) {
				continue;
			}
			if ( ! isset( $by_page[ $url ] ) ) {
				$by_page[ $url ] = array( 'impressions' => 0, 'post_id' => (int) $row['page_id'] );
			}
			$by_page[ $url ]['impressions'] += (int) $row['impressions'];
		}
		uasort( $by_page, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );
		foreach ( array_slice( $by_page, 0, self::BUSIEST, true ) as $url => $agg ) {
			$busiest[] = array(
				'url'     => $url,
				'post_id' => $agg['post_id'],
				'reason'  => 'busy',
			);
		}

		return self::select( $home, $newest, $busiest );
	}

	/**
	 * The day's slice of the whole-site walk: published URLs after the stored
	 * cursor, wrapping at the end, skipping anything the watchlist already
	 * covers today. Also records the site's URL total for the view.
	 *
	 * @param array                    $watch The watchlist (for dedup).
	 * @param \Agentimus\Settings|null $core  Core settings (injectable for tests).
	 * @return array<int,array{url:string,post_id:int,reason:string}>
	 */
	public static function rotation_targets( array $watch, ?\Agentimus\Settings $core = null ) {
		$core = $core ? $core : new \Agentimus\Settings();

		$ids = get_posts( array(
			'post_type'        => (array) $core->get( 'post_types', array( 'post', 'page' ) ),
			'post_status'      => 'publish',
			'numberposts'      => self::IDS_CAP,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => false,
		) );
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );

		$state = self::stored();
		// Total = every published URL plus the homepage — what "the whole site"
		// means on this card.
		$total = count( $ids ) + 1;
		if ( $state['site_total'] !== $total ) {
			$state['site_total'] = $total;
			update_option( self::OPTION, $state, false );
		}
		if ( empty( $ids ) ) {
			return array();
		}

		// Cursor order: everything after the last inspected ID, then wrap.
		$after  = array();
		$before = array();
		foreach ( $ids as $id ) {
			if ( $id > $state['rot_cursor'] ) {
				$after[] = $id;
			} else {
				$before[] = $id;
			}
		}
		$ordered = array_merge( $after, $before );

		$seen = array();
		foreach ( $watch as $t ) {
			$seen[ self::norm( $t['url'] ) ] = true;
		}

		$out = array();
		foreach ( $ordered as $id ) {
			if ( count( $out ) >= self::ROTATION_DAILY ) {
				break;
			}
			$link = get_permalink( $id );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$key = self::norm( $link );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'url'     => $link,
				'post_id' => $id,
				'reason'  => 'site',
			);
		}
		return $out;
	}

	/**
	 * Merge the watchlist's three sources into one deduplicated list. First
	 * mention wins, so a page that is both new and busy keeps "new" — the
	 * label that explains why a page with little traffic is being watched.
	 *
	 * @param array $home    Homepage row(s).
	 * @param array $newest  Newest-post rows.
	 * @param array $busiest Busiest-page rows.
	 * @return array<int,array{url:string,post_id:int,reason:string}>
	 */
	public static function select( array $home, array $newest, array $busiest ) {
		// The stated budget is enforced HERE, not trusted to the gatherers: the
		// card says "up to N", so no caller can make that a lie.
		$home    = array_slice( $home, 0, 1 );
		$newest  = array_slice( $newest, 0, self::NEWEST );
		$busiest = array_slice( $busiest, 0, self::BUSIEST );

		$out  = array();
		$seen = array();
		foreach ( array_merge( $home, $newest, $busiest ) as $row ) {
			$url = (string) ( isset( $row['url'] ) ? $row['url'] : '' );
			if ( '' === $url ) {
				continue;
			}
			$key = self::norm( $url );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'url'     => $url,
				'post_id' => (int) ( isset( $row['post_id'] ) ? $row['post_id'] : 0 ),
				'reason'  => (string) ( isset( $row['reason'] ) ? $row['reason'] : '' ),
			);
		}
		return array_slice( $out, 0, 1 + self::NEWEST + self::BUSIEST );
	}

	/**
	 * Inspect the run's targets and store the merged result.
	 *
	 * CHUNKED, because ~20+ sequential API calls inside one web request is a
	 * held FPM worker and a gateway timeout waiting to happen (it happened).
	 * A budgeted call inspects what fits, persists the remaining queue, and
	 * answers; the next call — the panel's polling loop, or a follow-up cron
	 * event — continues exactly where this one stopped. At least one URL is
	 * always inspected per call, so a sweep can never stall at zero progress.
	 *
	 * Answers route by tier: watchlist answers become the card's rows;
	 * rotation answers fold into the coverage map (and advance the cursor),
	 * where only problems keep their full detail.
	 *
	 * Three failure lanes, each with its own honest ending:
	 * - quota spent (429): a STATE, not a fault — the run ends, everything
	 *   uninspected keeps its last good answer;
	 * - one URL refused (400): that URL's own problem — recorded on the row,
	 *   the sweep keeps going;
	 * - token/transport: the chunk ends and the failed URL rejoins the queue at
	 *   the tail — the run PAUSES, never aborts, and resumes on the next call
	 *   (the panel's next visit, or the daily sweep). HOW LOUDLY it pauses
	 *   depends on what the next call would meet: a refusal with an HTTP status
	 *   (401, 403) repeats for every URL, so it is recorded at once; no answer
	 *   at all (a timeout, a reset) or Google's own 5xx is usually one slow
	 *   moment — those pause in SILENCE, so the panel's loop simply carries on,
	 *   and only {@see BLIP_LIMIT} of them in a row (an answer in between
	 *   starts the count over) get recorded as a failure. A recorded error
	 *   used to be the price of any blip — and every 121-URL run met one.
	 *
	 * @param Client     $client   The API client.
	 * @param string     $token    Bearer token.
	 * @param string     $property The Search Console property.
	 * @param array      $targets  Run targets ({@see run_targets()}) — used to
	 *                             START a run; a run already underway finishes
	 *                             its own persisted queue first.
	 * @param float|null $budget   Seconds this call may spend; null = no limit.
	 * @return array The stored payload.
	 */
	public static function sweep( Client $client, $token, $property, array $targets, $budget = null ) {
		$state = self::stored();
		$prev  = array();
		foreach ( $state['rows'] as $row ) {
			$prev[ self::norm( (string) $row['url'] ) ] = $row;
		}

		// A run in flight finishes its own list — mid-run watchlist drift would
		// otherwise re-order the queue under the sweep. A finished (or first)
		// run starts fresh from today's targets.
		$watch = $state['queue'] ? $state['watch'] : $targets;
		$queue = $state['queue'] ? $state['queue'] : $targets;

		$cov      = $state['cov'];
		$cursor   = $state['rot_cursor'];
		$blips    = $state['queue'] ? $state['blips'] : 0;
		$fresh    = array();
		$error    = '';
		$quota    = false;
		$deadline = microtime( true ) + ( null === $budget ? PHP_INT_MAX : (float) $budget );

		while ( ! empty( $queue ) ) {
			$t   = array_shift( $queue );
			$out = $client->inspect_url( $token, $property, $t['url'] );

			if ( isset( $out['error'] ) ) {
				if ( ! empty( $out['quota'] ) ) {
					// Today's budget is spent — the run is over, not paused.
					$quota = true;
					$queue = array();
					break;
				}
				if ( 400 === (int) ( isset( $out['status'] ) ? $out['status'] : 0 ) ) {
					$row = array_merge( $t, self::empty_result(), array(
						'error'        => (string) $out['error'],
						'inspected_at' => time(),
					) );
				} else {
					// Token/transport — stop this chunk, but keep the queue so the
					// run PAUSES instead of aborting. The failed URL rejoins at the
					// TAIL: a URL that reliably hangs Google can then never block
					// the head of the line for the rest. A failure that carried no
					// answer (a timeout, a reset) or Google's own 5xx stays silent
					// until BLIP_LIMIT in a row; a refusal with any other status
					// would repeat for every URL, so it surfaces at once. No retry
					// loop hides here — a chunk still ends on its first failure;
					// only the RUN outlives it ({@see Module::run_index_sweep()}).
					$status = (int) ( isset( $out['status'] ) ? $out['status'] : 0 );
					$blips  = ( 0 === $status || $status >= 500 ) ? $blips + 1 : self::BLIP_LIMIT;
					if ( $blips >= self::BLIP_LIMIT ) {
						$error = (string) $out['error'];
					}
					$queue[] = $t;
					break;
				}
			} else {
				$row = array_merge( $t, $out['result'], array(
					'error'        => '',
					'inspected_at' => time(),
				) );
			}
			// An answer of any kind — even a per-URL refusal — proves the line
			// is alive: the blip count starts over.
			$blips = 0;

			$key = self::norm( $t['url'] );
			if ( 'site' === $t['reason'] || 'problem' === $t['reason'] ) {
				if ( 'site' === $t['reason'] ) {
					// Only the rotation walks the cursor — a promoted re-check
					// is out-of-band and must not skip anyone's turn.
					$cursor = (int) $t['post_id'];
				}
				if ( isset( $cov[ $key ] ) || count( $cov ) < self::COVERAGE_CAP ) {
					// Healthy pages shrink to a count; problems keep the whole
					// story so they can stand as rows. A page that just turned
					// healthy takes a healed stamp with it — the disappearance
					// of its problem row is news, not silence.
					$prev_entry  = isset( $cov[ $key ] ) ? (array) $cov[ $key ] : array();
					$cov[ $key ] = self::is_problem( $row ) ? $row : array_merge(
						array(
							'url'          => $row['url'],
							'post_id'      => $row['post_id'],
							'verdict'      => $row['verdict'],
							'inspected_at' => $row['inspected_at'],
						),
						self::healed_mark( $prev_entry )
					);
				}
			} else {
				// Watch rows heal too — against their own previous answer.
				if ( ! self::is_problem( $row ) && isset( $prev[ $key ] ) ) {
					$row = array_merge( $row, self::healed_mark( (array) $prev[ $key ] ) );
				}
				$fresh[ $key ] = $row;
			}

			if ( microtime( true ) >= $deadline ) {
				break; // Chunk over; $queue keeps the rest for the next call.
			}
		}

		// Rebuild the card rows in watchlist order: this run's answer, else the
		// last good one. A URL that fell off the watchlist falls off with it.
		// Promoted problems are coverage entries, not rows — they skip too.
		$rows = array();
		foreach ( $watch as $t ) {
			if ( 'site' === $t['reason'] || 'problem' === $t['reason'] ) {
				continue;
			}
			$key = self::norm( $t['url'] );
			if ( isset( $fresh[ $key ] ) ) {
				$rows[] = $fresh[ $key ];
			} elseif ( isset( $prev[ $key ] ) ) {
				$rows[] = array_merge( $prev[ $key ], array( 'reason' => $t['reason'] ) );
			}
		}

		$payload = array(
			'rows'        => $rows,
			'checked_at'  => time(),
			'error'       => $error,
			'quota'       => $quota,
			'watch'       => empty( $queue ) ? array() : $watch,
			'queue'       => $queue,
			'blips'       => empty( $queue ) ? 0 : $blips,
			'cov'         => $cov,
			'rot_cursor'  => $cursor,
			'site_total'  => $state['site_total'],
			'sitemaps'    => $state['sitemaps'],
			'sitemaps_at' => $state['sitemaps_at'],
			// Carried through untouched: this is a record of what the OWNER did,
			// not of anything Google said, so a sweep has no business clearing it.
			'opened'      => $state['opened'],
		);
		update_option( self::OPTION, $payload, false );
		return $payload;
	}

	/**
	 * One page of one state's problem rows, from stored data alone — the
	 * on-demand half of the problems display. The card ships every group's
	 * true count up front; opening a group (or turning its page) asks here
	 * for a bounded slice: no live Google call, no quota, and only the
	 * slice's rows pay the row_view() cost (titles are DB lookups).
	 *
	 * Order matches the view's composition: watched pages first, then the
	 * coverage map in its stored order — stable across pages of one snapshot.
	 *
	 * @param string $state One of the {@see state_key()} buckets.
	 * @param int    $page  1-based page number.
	 * @return array { state, page, pages, perPage, total, rows }
	 */
	public static function problems_page( $state, $page ) {
		$state  = (string) $state;
		$page   = max( 1, (int) $page );
		$stored = self::stored();

		$watch_norms = array();
		$matching    = array();
		foreach ( $stored['rows'] as $row ) {
			$watch_norms[ self::norm( (string) $row['url'] ) ] = true;
			// Same "unasked is not a problem" rule the view counts by — the
			// listing and the count pills must never disagree.
			if ( self::is_problem( $row ) && self::answered( $row ) && self::state_key( $row ) === $state ) {
				$matching[] = $row;
			}
		}
		foreach ( $stored['cov'] as $key => $entry ) {
			if ( isset( $watch_norms[ $key ] ) || ! is_array( $entry ) ) {
				continue;
			}
			if ( self::is_problem( $entry ) && self::state_key( $entry ) === $state ) {
				$matching[] = $entry;
			}
		}

		$total = count( $matching );
		$pages = (int) ceil( $total / self::PROBLEMS_PER_PAGE );
		$slice = array_slice( $matching, ( $page - 1 ) * self::PROBLEMS_PER_PAGE, self::PROBLEMS_PER_PAGE );

		$rows = array();
		foreach ( $slice as $row ) {
			$rows[] = self::row_view( $row );
		}
		return array(
			'state'   => $state,
			'page'    => $page,
			'pages'   => $pages,
			'perPage' => self::PROBLEMS_PER_PAGE,
			'total'   => $total,
			'rows'    => $rows,
		);
	}

	/**
	 * Cap a problem list by seating one row per state bucket per round until
	 * the seats run out — every bucket that exists ships rows, bigger buckets
	 * take the leftover seats, and within a bucket the original order holds.
	 * (The client groups by stateKey, so the interleaved order is invisible.)
	 *
	 * @param array $rows View problem rows (each carrying stateKey).
	 * @param int   $cap  Seats available.
	 * @return array
	 */
	private static function fair_cap( array $rows, $cap ) {
		$buckets = array();
		foreach ( $rows as $row ) {
			$buckets[ (string) $row['stateKey'] ][] = $row;
		}
		$out  = array();
		$more = true;
		while ( count( $out ) < $cap && $more ) {
			$more = false;
			foreach ( array_keys( $buckets ) as $key ) {
				if ( empty( $buckets[ $key ] ) ) {
					continue;
				}
				$out[] = array_shift( $buckets[ $key ] );
				$more  = true;
				if ( count( $out ) >= $cap ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * The healed stamp a newly-healthy answer carries forward. Three cases:
	 * the previous answer was a problem (the healing moment — stamp it now,
	 * and remember what it healed FROM: "now on Google" means little without
	 * "was: discovered, not yet crawled"); the previous answer already carried
	 * a stamp still inside its {@see HEALED_KEEP} window (carry it, don't
	 * renew it — an announcement that resets daily never ends); anything else
	 * (no stamp).
	 *
	 * @param array $prev The previous stored answer for the same URL.
	 * @return array Empty, or { healed_at: int, healed_from: string }.
	 */
	private static function healed_mark( array $prev ) {
		if ( empty( $prev ) ) {
			return array();
		}
		if ( self::is_problem( $prev ) ) {
			return array(
				'healed_at'   => time(),
				'healed_from' => self::state_key( $prev ),
			);
		}
		if ( ! empty( $prev['healed_at'] ) && ( time() - (int) $prev['healed_at'] ) < self::HEALED_KEEP ) {
			return array(
				'healed_at'   => (int) $prev['healed_at'],
				'healed_from' => (string) ( isset( $prev['healed_from'] ) ? $prev['healed_from'] : '' ),
			);
		}
		return array();
	}

	/**
	 * Whether a sweep run is mid-flight — a persisted queue awaiting its next
	 * chunk. Callers use it to do once-per-RUN work (not once per chunk).
	 *
	 * @return bool
	 */
	public static function run_in_flight() {
		$stored = self::stored();
		return ! empty( $stored['queue'] );
	}

	/**
	 * Store the registered-sitemaps snapshot ({@see Client::sitemaps()}).
	 * Kept only on success — a transport blip never erases the last good
	 * answer — and stamped, so the view can tell "no sitemap registered"
	 * (a finding) apart from "never looked" (silence).
	 *
	 * @param array $out Client::sitemaps() result.
	 * @return void
	 */
	public static function store_sitemaps( array $out ) {
		if ( isset( $out['error'] ) || ! isset( $out['sitemaps'] ) ) {
			return;
		}
		$stored                = self::stored();
		$stored['sitemaps']    = array_slice( (array) $out['sitemaps'], 0, 20 );
		$stored['sitemaps_at'] = time();
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * The stored sweep result, shape-guaranteed. `queue` is the tail of a run
	 * still in flight (empty = the last sweep finished); `watch` is that run's
	 * full target list, kept so every chunk rebuilds rows in one stable order;
	 * `blips` is the run's consecutive no-answer failure count ({@see sweep()});
	 * `cov` is the cumulative site-rotation coverage keyed by normalized URL.
	 *
	 * @return array{rows:array,checked_at:int,error:string,quota:bool,watch:array,queue:array,blips:int,cov:array,rot_cursor:int,site_total:int}
	 */
	public static function stored() {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'rows'       => isset( $raw['rows'] ) && is_array( $raw['rows'] ) ? $raw['rows'] : array(),
			'checked_at' => (int) ( isset( $raw['checked_at'] ) ? $raw['checked_at'] : 0 ),
			'error'      => (string) ( isset( $raw['error'] ) ? $raw['error'] : '' ),
			'quota'      => ! empty( $raw['quota'] ),
			'watch'      => isset( $raw['watch'] ) && is_array( $raw['watch'] ) ? $raw['watch'] : array(),
			'queue'      => isset( $raw['queue'] ) && is_array( $raw['queue'] ) ? $raw['queue'] : array(),
			'blips'      => (int) ( isset( $raw['blips'] ) ? $raw['blips'] : 0 ),
			'cov'        => isset( $raw['cov'] ) && is_array( $raw['cov'] ) ? $raw['cov'] : array(),
			// url => unix time the owner opened that row in Search Console. OUR
			// record of THEIR click; never a claim about Google's queue.
			'opened'     => isset( $raw['opened'] ) && is_array( $raw['opened'] ) ? $raw['opened'] : array(),
			'rot_cursor' => (int) ( isset( $raw['rot_cursor'] ) ? $raw['rot_cursor'] : 0 ),
			'site_total' => (int) ( isset( $raw['site_total'] ) ? $raw['site_total'] : 0 ),
			'sitemaps'    => isset( $raw['sitemaps'] ) && is_array( $raw['sitemaps'] ) ? $raw['sitemaps'] : array(),
			'sitemaps_at' => (int) ( isset( $raw['sitemaps_at'] ) ? $raw['sitemaps_at'] : 0 ),
		);
	}

	/**
	 * The payload the admin card and the MCP tool both read — one composition,
	 * so an assistant asking "is this site in Google's index?" sees exactly
	 * what the owner's screen shows. Every key exists in every state; a
	 * disconnected source is empty, never absent.
	 *
	 * @param Settings $google The Google connection store.
	 * @return array
	 */
	public static function view( Settings $google ) {
		$base = array(
			'connected' => $google->connected(),
			'property'  => (string) $google->get( 'property', '' ),
			'checkedAt' => 0,
			'lastError' => '',
			'quotaHit'  => false,
			'pending'   => 0,
			'watched'   => array(
				'busiest'       => self::BUSIEST,
				'newest'        => self::NEWEST,
				'promotedDaily' => self::PROMOTED_DAILY,
				'rotationDaily' => self::ROTATION_DAILY,
				'dailyCap'      => self::DAILY_CAP,
			),
			'counts'    => array(
				'checked'          => 0,
				'onGoogle'         => 0,
				'notOnGoogle'      => 0,
				'errors'           => 0,
				'canonicalDiffers' => 0,
			),
			'site'      => array(
				'totalUrls'     => 0,
				'checked'       => 0,
				'onGoogle'      => 0,
				'notOnGoogle'   => 0,
				'cycleDays'     => 0,
				'healed'        => array(),
				'healedTotal'   => 0,
				'problems'      => array(),
				'problemsTotal' => 0,
				'problemStates' => array(
					'error'      => 0,
					'canonical'  => 0,
					'unknown'    => 0,
					'discovered' => 0,
					'crawled'    => 0,
					'blocked'    => 0,
					'other'      => 0,
				),
			),
			'sitemaps'  => array(
				'checkedAt'  => 0,
				'liveUrl'    => '',
				'registered' => array(),
			),
			'rows'      => array(),
		);
		if ( ! $base['connected'] ) {
			return $base;
		}

		$stored            = self::stored();
		$base['checkedAt'] = $stored['checked_at'];
		$base['lastError'] = $stored['error'];
		$base['quotaHit']  = $stored['quota'];
		$base['pending']   = count( $stored['queue'] );

		// Registered-sitemap health — what the OWNER once told Google vs what
		// the site serves today. checkedAt 0 = never looked (say nothing);
		// looked + empty registered = a finding, not silence.
		$base['sitemaps']['checkedAt'] = $stored['sitemaps_at'];
		$base['sitemaps']['liveUrl']   = (string) \Agentimus\Sitemap::url();
		foreach ( $stored['sitemaps'] as $reg ) {
			$base['sitemaps']['registered'][] = array(
				'path'      => (string) ( isset( $reg['path'] ) ? $reg['path'] : '' ),
				'pending'   => ! empty( $reg['pending'] ),
				'lastRead'  => (int) ( isset( $reg['last_downloaded'] ) ? $reg['last_downloaded'] : 0 ),
				'errors'    => (int) ( isset( $reg['errors'] ) ? $reg['errors'] : 0 ),
				'warnings'  => (int) ( isset( $reg['warnings'] ) ? $reg['warnings'] : 0 ),
				'submitted' => (int) ( isset( $reg['submitted'] ) ? $reg['submitted'] : 0 ),
			);
		}

		$now          = time();
		$healed       = array();
		$watch_norms  = array();
		$watch_sick   = array();
		foreach ( $stored['rows'] as $row ) {
			$watch_norms[ self::norm( (string) $row['url'] ) ] = true;

			$view_row = self::row_view( $row );
			if ( '' !== $view_row['error'] ) {
				$base['counts']['errors']++;
			} elseif ( 'pass' === $view_row['verdict'] ) {
				$base['counts']['onGoogle']++;
			} else {
				$base['counts']['notOnGoogle']++;
			}
			if ( $view_row['canonicalDiffers'] ) {
				$base['counts']['canonicalDiffers']++;
			}
			$base['counts']['checked']++;
			$base['rows'][] = $view_row;

			// Watched pages with problems stand with every other problem — one
			// list for "needs a look", wherever the page came from. An answer
			// the sweep never reached (verdict and error both empty) is not a
			// problem yet, just unasked.
			if ( self::is_problem( $row ) && self::answered( $row ) ) {
				$watch_sick[] = $view_row;
			} elseif ( $view_row['healedAt'] > 0 && ( $now - $view_row['healedAt'] ) < self::HEALED_KEEP ) {
				$healed[] = $view_row;
			}
		}

		// The whole-site picture: the coverage map plus the watchlist rows —
		// watched pages are site pages too, they just live in their own list.
		$site              = &$base['site'];
		$site['totalUrls'] = $stored['site_total'];
		$site['checked']   = $base['counts']['checked'];
		$site['onGoogle']  = $base['counts']['onGoogle'];
		$site['problems']  = $watch_sick;
		foreach ( $stored['cov'] as $key => $entry ) {
			if ( isset( $watch_norms[ $key ] ) ) {
				continue; // Its watchlist row already counted it.
			}
			$site['checked']++;
			if ( 'PASS' === strtoupper( (string) ( isset( $entry['verdict'] ) ? $entry['verdict'] : '' ) ) && empty( $entry['error'] ) ) {
				$site['onGoogle']++;
			}
			if ( self::is_problem( $entry ) ) {
				$site['problems'][] = self::row_view( $entry );
			} elseif ( ! empty( $entry['healed_at'] ) && ( $now - (int) $entry['healed_at'] ) < self::HEALED_KEEP ) {
				$healed[] = self::row_view( $entry );
			}
		}

		// The healed announcements: newest first, bounded like every list, the
		// uncapped truth in healedTotal.
		usort( $healed, static function ( $a, $b ) {
			return $b['healedAt'] <=> $a['healedAt'];
		} );
		$site['healedTotal'] = count( $healed );
		$site['healed']      = array_slice( $healed, 0, self::HEALED_CAP );
		$site['notOnGoogle'] = max( 0, $site['checked'] - $site['onGoogle'] );
		$rotating            = max( 0, $site['totalUrls'] - count( $stored['rows'] ) );
		$site['cycleDays']   = $rotating > 0 ? (int) ceil( $rotating / self::ROTATION_DAILY ) : 0;

		// Bounded payload: a sick 5,000-page site must not ship thousands of
		// problem rows through REST and MCP. problemsTotal owns the truth —
		// a cap presented as the whole picture would be the silent-cap lie —
		// and the per-state totals are counted BEFORE the cap, so a group's
		// count pill stays true even when its rows are the bounded slice.
		$site['problemsTotal'] = count( $site['problems'] );
		foreach ( $site['problems'] as $problem ) {
			$bucket = (string) $problem['stateKey'];
			if ( isset( $site['problemStates'][ $bucket ] ) ) {
				$site['problemStates'][ $bucket ]++;
			}
		}
		if ( $site['problemsTotal'] > self::PROBLEMS_CAP ) {
			// Fair-share, not a head-slice: a straight cut in coverage order
			// could swallow a whole bucket (96 discovered rows ahead of 4
			// blocked ones = the blocked group never renders), and a category
			// of trouble with no rows is invisible no matter what the counts
			// say. Every bucket keeps its first rows on screen.
			$site['problems'] = self::fair_cap( $site['problems'], self::PROBLEMS_CAP );
		}

		return $base;
	}

	/**
	 * One stored row as every reader sees it.
	 *
	 * @param array $row A stored sweep row.
	 * @return array
	 */
	private static function row_view( array $row ) {
		// Read per row, not cached in a static: mark_opened() can write during the
		// same request that renders the answer, and a static would hand back the
		// state from before the click. get_option is already memory-cached, so
		// the honest version costs nothing. {@see mark_opened()}.
		$opened = self::stored()['opened'];

		$verdict   = strtoupper( (string) ( isset( $row['verdict'] ) ? $row['verdict'] : '' ) );
		$row_error = (string) ( isset( $row['error'] ) ? $row['error'] : '' );
		$canonical = (string) ( isset( $row['google_canonical'] ) ? $row['google_canonical'] : '' );
		$url       = (string) $row['url'];
		$post_id   = (int) ( isset( $row['post_id'] ) ? $row['post_id'] : 0 );

		$title = '';
		if ( 'home' === ( isset( $row['reason'] ) ? $row['reason'] : '' ) ) {
			$title = __( 'Homepage', 'agentimus' );
		} elseif ( $post_id > 0 ) {
			// Decoded, not escaped: get_the_title() returns HTML entities,
			// and the card renders text — same rule as the search screens.
			$title = html_entity_decode( (string) get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		}
		if ( '' === $title ) {
			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$title = '' !== $path ? $path : $url;
		}

		return array(
			'url'              => $url,
			'postId'           => $post_id,
			'title'            => $title,
			'reason'           => (string) ( isset( $row['reason'] ) ? $row['reason'] : '' ),
			'verdict'          => strtolower( $verdict ),
			'state'            => (string) ( isset( $row['coverage_state'] ) ? $row['coverage_state'] : '' ),
			'lastCrawl'        => (int) ( isset( $row['last_crawl'] ) ? $row['last_crawl'] : 0 ),
			'robotsBlocked'    => 'DISALLOWED' === (string) ( isset( $row['robots_state'] ) ? $row['robots_state'] : '' ),
			'noindex'          => 0 === strpos( (string) ( isset( $row['indexing_state'] ) ? $row['indexing_state'] : '' ), 'BLOCKED_BY' ),
			'fetchState'       => (string) ( isset( $row['fetch_state'] ) ? $row['fetch_state'] : '' ),
			'canonicalDiffers' => '' !== $canonical && self::norm( $canonical ) !== self::norm( $url ),
			'googleCanonical'  => $canonical,
			'inSitemap'        => ! empty( $row['in_sitemap'] ),
			'referrers'        => (int) ( isset( $row['referrers'] ) ? $row['referrers'] : 0 ),
			'stateKey'         => self::state_key( $row ),
			'richIssues'       => (int) ( isset( $row['rich_issues'] ) ? $row['rich_issues'] : 0 ),
			'richTypes'        => (string) ( isset( $row['rich_types'] ) ? $row['rich_types'] : '' ),
			'gscLink'          => (string) ( isset( $row['gsc_link'] ) ? $row['gsc_link'] : '' ),
			'openedAt'         => (int) ( isset( $opened[ self::norm( $url ) ] ) ? $opened[ self::norm( $url ) ] : 0 ),
			'inspectedAt'      => (int) ( isset( $row['inspected_at'] ) ? $row['inspected_at'] : 0 ),
			'healedAt'         => (int) ( isset( $row['healed_at'] ) ? $row['healed_at'] : 0 ),
			'healedFrom'       => (string) ( isset( $row['healed_from'] ) ? $row['healed_from'] : '' ),
			'error'            => $row_error,
		);
	}

	/**
	 * Every bucket {@see state_key()} can answer with — the vocabulary REST
	 * and MCP validate a requested state against.
	 *
	 * @return array<int,string>
	 */
	public static function state_keys() {
		return array( 'error', 'canonical', 'unknown', 'discovered', 'crawled', 'blocked', 'other' );
	}

	/**
	 * The state bucket a problem row belongs to — computed HERE so the group
	 * count pills can tell the uncapped truth: rows are capped, counts never
	 * are, and the client must group by the same key the totals were counted
	 * under. Buckets, most-lost first: error, canonical, unknown, discovered,
	 * crawled, blocked, other.
	 *
	 * @param array $row A stored sweep row (or coverage entry).
	 * @return string
	 */
	public static function state_key( array $row ) {
		if ( ! empty( $row['error'] ) ) {
			return 'error';
		}
		$canonical = (string) ( isset( $row['google_canonical'] ) ? $row['google_canonical'] : '' );
		if ( '' !== $canonical && self::norm( $canonical ) !== self::norm( (string) ( isset( $row['url'] ) ? $row['url'] : '' ) ) ) {
			return 'canonical';
		}
		$state = (string) ( isset( $row['coverage_state'] ) ? $row['coverage_state'] : '' );
		if ( false !== stripos( $state, 'unknown to Google' ) ) {
			return 'unknown';
		}
		if ( 0 === stripos( $state, 'Discovered' ) ) {
			return 'discovered';
		}
		if ( 0 === stripos( $state, 'Crawled' ) ) {
			return 'crawled';
		}
		if ( 'DISALLOWED' === (string) ( isset( $row['robots_state'] ) ? $row['robots_state'] : '' )
			|| 0 === strpos( (string) ( isset( $row['indexing_state'] ) ? $row['indexing_state'] : '' ), 'BLOCKED_BY' ) ) {
			return 'blocked';
		}
		return 'other';
	}

	/**
	 * One page's stored answer, by URL — the coverage map remembers every
	 * checked page, including the healthy ones that never earn a row, so a
	 * lookup answers from local data alone: no live call, no quota.
	 *
	 * @param string $url Absolute URL (or a path on this site).
	 * @return array{status:string,row:array|null} status: found | unchecked | foreign.
	 */
	public static function lookup( $url ) {
		$url = self::resolve_local( $url );
		if ( '' === $url ) {
			return array( 'status' => 'foreign', 'row' => null );
		}

		$key    = self::norm( $url );
		$stored = self::stored();
		foreach ( $stored['rows'] as $row ) {
			if ( self::norm( (string) $row['url'] ) === $key ) {
				return array( 'status' => 'found', 'row' => self::row_view( $row ) );
			}
		}
		if ( isset( $stored['cov'][ $key ] ) ) {
			return array( 'status' => 'found', 'row' => self::row_view( $stored['cov'][ $key ] ) );
		}
		return array( 'status' => 'unchecked', 'row' => null );
	}

	/**
	 * Whether a stored row ever got an answer — verdict or error, either
	 * counts. A row the sweep never reached is unasked, not a problem; the
	 * ONE definition the view's counts and the problems listing both use.
	 *
	 * @param array $row A stored sweep row.
	 * @return bool
	 */
	private static function answered( array $row ) {
		return '' !== (string) ( isset( $row['verdict'] ) ? $row['verdict'] : '' )
			|| '' !== (string) ( isset( $row['error'] ) ? $row['error'] : '' );
	}

	/**
	 * Does this stored row carry anything an owner should look at?
	 *
	 * @param array $row A stored sweep row (or coverage entry).
	 * @return bool
	 */
	private static function is_problem( array $row ) {
		if ( ! empty( $row['error'] ) ) {
			return true;
		}
		if ( 'PASS' !== strtoupper( (string) ( isset( $row['verdict'] ) ? $row['verdict'] : '' ) ) ) {
			return true;
		}
		$canonical = (string) ( isset( $row['google_canonical'] ) ? $row['google_canonical'] : '' );
		if ( '' !== $canonical && self::norm( $canonical ) !== self::norm( (string) $row['url'] ) ) {
			return true;
		}
		if ( 'DISALLOWED' === (string) ( isset( $row['robots_state'] ) ? $row['robots_state'] : '' ) ) {
			return true;
		}
		if ( 0 === strpos( (string) ( isset( $row['indexing_state'] ) ? $row['indexing_state'] : '' ), 'BLOCKED_BY' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * The result fields a failed inspection still has to carry, empty.
	 *
	 * @return array
	 */
	private static function empty_result() {
		return array(
			'verdict'          => '',
			'coverage_state'   => '',
			'robots_state'     => '',
			'indexing_state'   => '',
			'fetch_state'      => '',
			'last_crawl'       => 0,
			'google_canonical' => '',
			'crawled_as'       => '',
			'in_sitemap'       => false,
			'referrers'        => 0,
			'gsc_link'         => '',
			'rich_types'       => '',
			'rich_issues'      => 0,
		);
	}

	/**
	 * Dedup/compare form of a URL — scheme and host case-folded, trailing
	 * slash dropped. Both forms of the same page must collide here.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	/**
	 * Inspect ONE url live, right now, because the owner asked.
	 *
	 * The rotation answers "is the site in?" on its own schedule; this answers
	 * "what about THIS page, this second?" — after a fix, before a share, when
	 * the stored answer is a day old and the question is urgent. It spends one
	 * of the day's 2,000 inspections, so it only ever runs on a click.
	 *
	 * The same three failure lanes the sweep uses, for the same reasons: a 429
	 * is the day's budget, not this page's fault; a 400 is this URL's own
	 * problem and becomes its answer; anything else is the connection or the
	 * token and must not be written down as a verdict about the page.
	 *
	 * A successful answer is STORED, not just returned — otherwise the card
	 * would keep showing yesterday's verdict beside a fresh one, and the owner
	 * would have to wonder which is true.
	 *
	 * @param Client $client   The Search Console client.
	 * @param string $token    OAuth token.
	 * @param string $property The property owning the URL.
	 * @param string $url      The URL to inspect.
	 * @return array{status:string,row:?array,error:string}
	 */
	public static function inspect_now( Client $client, $token, $property, $url ) {
		$url = self::resolve_local( $url );
		if ( '' === $url ) {
			// Google answers only for URLs inside the connected property, so a
			// foreign address is refused here rather than spending an inspection
			// to be told the same thing.
			return array( 'status' => 'foreign', 'row' => null, 'error' => '' );
		}

		$target = array(
			'url'     => $url,
			'post_id' => function_exists( 'url_to_postid' ) ? (int) url_to_postid( $url ) : 0,
			'reason'  => 'lookup',
		);

		$out = $client->inspect_url( $token, $property, $url );

		if ( isset( $out['error'] ) ) {
			if ( ! empty( $out['quota'] ) ) {
				return array( 'status' => 'quota', 'row' => null, 'error' => (string) $out['error'] );
			}
			if ( 400 !== (int) ( isset( $out['status'] ) ? $out['status'] : 0 ) ) {
				// Transport or token: nothing was learned about this page, so
				// nothing about this page gets written down.
				return array( 'status' => 'error', 'row' => null, 'error' => (string) $out['error'] );
			}
			$row = array_merge( $target, self::empty_result(), array(
				'error'        => (string) $out['error'],
				'inspected_at' => time(),
			) );
		} else {
			$row = array_merge( $target, $out['result'], array(
				'error'        => '',
				'inspected_at' => time(),
			) );
		}

		return array( 'status' => 'checked', 'row' => self::store_single( $row ), 'error' => '' );
	}

	/**
	 * File one fresh answer where that URL already lives, and hand back its view.
	 *
	 * A URL on the watchlist keeps its row; anything else is a coverage entry,
	 * shrunk to a count when healthy exactly as the rotation shrinks it — a
	 * single manual check must not leave a page stored differently from how the
	 * daily sweep would have stored it, or the two would disagree tomorrow.
	 *
	 * @param array $row The fresh row.
	 * @return array The row as readers see it.
	 */
	private static function store_single( array $row ) {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$key = self::norm( (string) $row['url'] );

		$state = self::stored();
		$rows  = $state['rows'];
		$found = false;
		foreach ( $rows as $i => $existing ) {
			if ( self::norm( (string) $existing['url'] ) !== $key ) {
				continue;
			}
			// Healing is news: a watch row that just turned healthy says so,
			// the same way it would have after a sweep.
			if ( ! self::is_problem( $row ) ) {
				$row = array_merge( $row, self::healed_mark( (array) $existing ) );
			}
			// Keep the reason it was watched for — this check was out-of-band and
			// says nothing about why the page is on the list.
			$row['reason'] = isset( $existing['reason'] ) ? $existing['reason'] : $row['reason'];
			$rows[ $i ]    = $row;
			$found         = true;
			break;
		}

		if ( $found ) {
			$raw['rows'] = $rows;
		} else {
			$cov  = $state['cov'];
			$prev = isset( $cov[ $key ] ) ? (array) $cov[ $key ] : array();
			if ( isset( $cov[ $key ] ) || count( $cov ) < self::COVERAGE_CAP ) {
				$cov[ $key ] = self::is_problem( $row ) ? $row : array_merge(
					array(
						'url'          => $row['url'],
						'post_id'      => $row['post_id'],
						'verdict'      => $row['verdict'],
						'inspected_at' => $row['inspected_at'],
					),
					self::healed_mark( $prev )
				);
			}
			$raw['cov'] = $cov;
		}

		update_option( self::OPTION, $raw, false );
		return self::row_view( $row );
	}

	/**
	 * Record that the owner opened this URL in Search Console.
	 *
	 * Google keeps no memory of "indexing requested": a fresh inspection shows a
	 * plain REQUEST INDEXING again, and no API exposes the pending state. So the
	 * only honest tracker is our own — a note that says what the OWNER did and
	 * when, never a claim about Google's queue. The row says "console opened
	 * {date}", which is true, rather than "indexing requested", which we cannot
	 * know. Survives sweeps: {@see sweep()} carries `opened` through untouched.
	 *
	 * @param string $url The URL whose Search Console link was opened.
	 * @return int Unix time recorded, or 0 when the URL isn't one we hold.
	 */
	public static function mark_opened( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return 0;
		}

		$state = self::stored();
		$key   = self::norm( $url );

		// Only URLs this card actually knows about. Anything else would grow the
		// option without bound on a hand-crafted request, and would put a note on
		// a row that will never render.
		$known = isset( $state['cov'][ $key ] );
		if ( ! $known ) {
			foreach ( $state['rows'] as $row ) {
				if ( self::norm( (string) $row['url'] ) === $key ) {
					$known = true;
					break;
				}
			}
		}
		if ( ! $known ) {
			return 0;
		}

		$now                    = time();
		$state['opened'][ $key ] = $now;

		$raw           = get_option( self::OPTION, array() );
		$raw           = is_array( $raw ) ? $raw : array();
		$raw['opened'] = $state['opened'];
		update_option( self::OPTION, $raw, false );

		return $now;
	}

	/**
	 * Resolve what someone typed (or a stored row carries) into an absolute URL
	 * ON THIS SITE, or '' when it plainly isn't one.
	 *
	 * The old rule was one line — prepend home_url() only when the string starts
	 * with '/' — and it refused everything else as foreign. That is fine for
	 * stored rows, which are always absolute, and wrong for the lookup box, where
	 * a person types what a person types. "privacy-policy", "heera.it/terms" and
	 * "/terms/" all mean the same page on their own site, and answering the first
	 * two with "that address isn't on this site" is the tool being pedantic about
	 * its own input format.
	 *
	 * Anything genuinely elsewhere still comes back empty — this widens what we
	 * understand, never what we accept.
	 *
	 * @param string $url A URL, a host-relative path, or a bare path.
	 * @return string Absolute URL on this site, or '' when it is not ours.
	 */
	private static function resolve_local( $url ) {
		// The rule lives in \Agentimus\LocalUrl now — Bing's URL check reads the
		// same one, so the two boxes can never disagree about what "this site's
		// page" means.
		return \Agentimus\LocalUrl::resolve( $url );
	}

	private static function norm( $url ) {
		$url  = rtrim( (string) $url, '/' );
		$head = (string) wp_parse_url( $url, PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( $url, PHP_URL_HOST );
		$rest = substr( $url, strlen( $head ) );
		return strtolower( $head ) . $rest;
	}
}
