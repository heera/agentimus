<?php
/**
 * Google index watch — is Google's index holding this site's pages?
 *
 * Google exposes no bulk index report (its Crawl Stats screen has no API), so
 * the URL Inspection API — 2,000 lookups a day, one URL at a time — is the
 * only honest way to ask. Two tiers share that budget:
 *
 * - the WATCHLIST: homepage, busiest pages, newest posts — checked every day,
 *   every answer shown as a row. Small, stable, the pages that matter most.
 * - the SITE ROTATION: every other published URL, walked in daily slices of
 *   {@see ROTATION_DAILY}. A small site gets full coverage every day; a big
 *   one gets every page re-checked on a stated cadence — never a pretended
 *   whole-site snapshot. Only PROBLEMS surface as rows; healthy pages become
 *   one summary count, because 500 green rows is noise and one number isn't.
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

	/** @var int Busiest pages watched, by Google's own impression counts. */
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

	/**
	 * Everything one sweep run should inspect: the watchlist, then the day's
	 * rotation slice.
	 *
	 * @param \Agentimus\Settings|null $core Core settings (injectable for tests).
	 * @return array<int,array{url:string,post_id:int,reason:string}>
	 */
	public static function run_targets( \Agentimus\Settings $core = null ) {
		$watch = self::targets( $core );
		return array_merge( $watch, self::rotation_targets( $watch, $core ) );
	}

	/**
	 * The watchlist: the homepage, the newest posts, then the busiest pages.
	 * Order matters twice — it is the display order, and when quota runs out
	 * mid-sweep it decides who got checked first.
	 *
	 * @param \Agentimus\Settings|null $core Core settings (injectable for tests).
	 * @return array<int,array{url:string,post_id:int,reason:string}>
	 */
	public static function targets( \Agentimus\Settings $core = null ) {
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
	public static function rotation_targets( array $watch, \Agentimus\Settings $core = null ) {
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
	 * - token/transport: every further call in THIS chunk would fail the same
	 *   way — record the failure once and PAUSE, never abort: the un-inspected
	 *   URL rejoins the queue and the run resumes on the next call (the panel's
	 *   next visit, or the daily sweep). A transient blip — one slow Google
	 *   answer, a network hiccup — costs nothing; aborting used to restart the
	 *   run from scratch and re-spend the whole watchlist's quota over it.
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
					// Token/transport — stop this chunk and record the failure once,
					// but keep the queue so the run PAUSES instead of aborting. The
					// failed URL rejoins at the TAIL: a URL that reliably hangs Google
					// can then never block the head of the line for the rest. No
					// retry loop hides here — the panel's chunk loop stops on a
					// reported error, and the cron safety net only follows a clean
					// chunk ({@see Module::run_index_sweep()}).
					$error   = (string) $out['error'];
					$queue[] = $t;
					break;
				}
			} else {
				$row = array_merge( $t, $out['result'], array(
					'error'        => '',
					'inspected_at' => time(),
				) );
			}

			if ( 'site' === $t['reason'] ) {
				$key    = self::norm( $t['url'] );
				$cursor = (int) $t['post_id'];
				if ( isset( $cov[ $key ] ) || count( $cov ) < self::COVERAGE_CAP ) {
					// Healthy pages shrink to a count; problems keep the whole
					// story so they can stand as rows.
					$cov[ $key ] = self::is_problem( $row ) ? $row : array(
						'url'          => $row['url'],
						'post_id'      => $row['post_id'],
						'verdict'      => $row['verdict'],
						'inspected_at' => $row['inspected_at'],
					);
				}
			} else {
				$fresh[ self::norm( $t['url'] ) ] = $row;
			}

			if ( microtime( true ) >= $deadline ) {
				break; // Chunk over; $queue keeps the rest for the next call.
			}
		}

		// Rebuild the card rows in watchlist order: this run's answer, else the
		// last good one. A URL that fell off the watchlist falls off with it.
		$rows = array();
		foreach ( $watch as $t ) {
			if ( 'site' === $t['reason'] ) {
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
			'cov'         => $cov,
			'rot_cursor'  => $cursor,
			'site_total'  => $state['site_total'],
			'sitemaps'    => $state['sitemaps'],
			'sitemaps_at' => $state['sitemaps_at'],
		);
		update_option( self::OPTION, $payload, false );
		return $payload;
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
	 * `cov` is the cumulative site-rotation coverage keyed by normalized URL.
	 *
	 * @return array{rows:array,checked_at:int,error:string,quota:bool,watch:array,queue:array,cov:array,rot_cursor:int,site_total:int}
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
			'cov'        => isset( $raw['cov'] ) && is_array( $raw['cov'] ) ? $raw['cov'] : array(),
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

		$watch_norms = array();
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
		}

		// The whole-site picture: the coverage map plus the watchlist rows —
		// watched pages are site pages too, they just live in their own list.
		$site             = &$base['site'];
		$site['totalUrls'] = $stored['site_total'];
		$site['checked']   = $base['counts']['checked'];
		$site['onGoogle']  = $base['counts']['onGoogle'];
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
			}
		}
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
			$site['problems'] = array_slice( $site['problems'], 0, self::PROBLEMS_CAP );
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
			'inspectedAt'      => (int) ( isset( $row['inspected_at'] ) ? $row['inspected_at'] : 0 ),
			'error'            => $row_error,
		);
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
		$url = trim( (string) $url );
		if ( '' !== $url && '/' === $url[0] ) {
			$url = home_url( $url );
		}
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || $host !== $home_host ) {
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
	private static function norm( $url ) {
		$url  = rtrim( (string) $url, '/' );
		$head = (string) wp_parse_url( $url, PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( $url, PHP_URL_HOST );
		$rest = substr( $url, strlen( $head ) );
		return strtolower( $head ) . $rest;
	}
}
