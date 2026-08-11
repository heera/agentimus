<?php
/**
 * The progress ledger — what a page's search standing was when we first put it
 * on a worklist, and what changed since.
 *
 * WHY THIS HAS TO EXIST: {@see Table} is a SNAPSHOT. Every poll replaces its
 * source's rows wholesale, because the engines re-report their whole trailing
 * window each time. That is the right storage for "where do I stand today" and
 * it makes one question unanswerable — "did the thing I fixed actually work?" —
 * because the version of the world it would be compared against was overwritten.
 *
 * So this class keeps the one small thing the snapshot cannot: a baseline per
 * worklist page, written when that page first appears, and the resolutions
 * detected when a later poll shows it has climbed out of the group it was in.
 *
 * Two rules keep it honest:
 *
 *  - A page LEAVING a worklist is not a resolution. It can leave by getting
 *    worse, by losing its traffic, or by being set aside. A resolution is only
 *    recorded when the new numbers are BETTER than the baseline and cross the
 *    line the group was drawn at. Everything else drops silently, because
 *    "well done" for a page that sank is worse than saying nothing.
 *  - Nothing here is inferred from the owner's edits. Whether their side is
 *    done is a different question, answered live by {@see Standing} — this
 *    class only reports what the ENGINE now says.
 *
 * Written from the poll and nowhere else: the only moment new evidence exists
 * is the moment a fresh snapshot lands, so read paths never touch it.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Progress {

	/** Where the ledger lives. Small, bounded, and autoloaded with settings. */
	const OPTION = 'agentimus_search_progress';

	/**
	 * How long a resolution stays news, in days.
	 *
	 * It expires rather than waiting to be dismissed. A win needs somewhere to
	 * be seen, not a permanent home: a "recently resolved" archive grows forever
	 * at the bottom of a screen whose whole promise is that it stays short, and
	 * nobody opens a museum. Roughly a week is long enough that an owner who
	 * checks in on weekends still sees it.
	 */
	const KEEP_DAYS = 7;

	/** Most resolutions held at once. Well above what a week can produce. */
	const MAX_RESOLVED = 12;

	/**
	 * Record what a fresh snapshot changed, and re-baseline.
	 *
	 * Called after a successful poll wrote its rows — never from a read path.
	 * A source with no rows is left completely alone: an empty or failed poll
	 * must not read as "every page resolved at once".
	 *
	 * @param string               $source 'google' or 'bing'.
	 * @param \Agentimus\Settings  $core   Core settings (holds the set-aside lists).
	 * @return void
	 */
	public static function observe( $source, \Agentimus\Settings $core ) {
		$source = sanitize_key( (string) $source );
		if ( '' === $source ) {
			return;
		}

		$rows = Table::snapshot( $source );
		if ( ! $rows ) {
			return;
		}

		$all        = $core->all();
		$aside      = ( isset( $all['search_ignored'] ) && is_array( $all['search_ignored'] ) ) ? array_map( 'intval', $all['search_ignored'] ) : array();
		$aside_urls = ( isset( $all['search_ignored_urls'] ) && is_array( $all['search_ignored_urls'] ) ) ? array_map( 'strval', $all['search_ignored_urls'] ) : array();

		$report = Opportunities::build( $rows, $aside, $aside_urls );

		// Where every page stands NOW, by the same measure the cards use — the
		// lookup that tells a page that climbed out from a page that vanished.
		$index = Opportunities::page_index(
			array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return ! Opportunities::is_operator_query( (string) $row['query'] );
					}
				)
			)
		);

		$state     = self::read();
		$baselines = $state['baselines'];
		$resolved  = $state['resolved'];
		$now       = self::now();

		// Which pages are on a worklist in THIS snapshot, and under which key.
		$current = array();
		foreach ( array( Opportunities::KIND_NEAR => 'almost_there', Opportunities::KIND_SEEN => 'seen_not_chosen' ) as $kind => $group ) {
			foreach ( (array) $report[ $group ] as $card ) {
				$page_key = Opportunities::page_key( (int) $card['page_id'], (string) $card['page_url'] );
				$current[ self::key( $source, $kind, $page_key ) ] = array(
					'kind'     => $kind,
					'page_key' => $page_key,
					'card'     => $card,
				);
			}
		}

		// A page the owner has set aside is not news either way — they told us
		// to stop having opinions about it, and a congratulation is an opinion.
		$hidden = self::hidden_keys( $index, $aside, $aside_urls );

		foreach ( $baselines as $key => $base ) {
			if ( 0 !== strpos( $key, $source . '|' ) ) {
				continue; // Another source's ledger; this poll says nothing about it.
			}
			if ( isset( $current[ $key ] ) ) {
				continue; // Still on the worklist. The baseline stands.
			}

			unset( $baselines[ $key ] );

			$page_key = (string) $base['page_key'];
			if ( isset( $hidden[ $page_key ] ) || ! isset( $index[ $page_key ] ) ) {
				continue; // Set aside, or gone from the report entirely. No claim.
			}

			$win = self::improvement( (string) $base['kind'], $base, $index[ $page_key ] );
			if ( null === $win ) {
				continue; // It left the group without getting better. Say nothing.
			}

			$win['source']   = $source;
			$win['kind']     = (string) $base['kind'];
			$win['page_id']  = (int) $index[ $page_key ]['page_id'];
			$win['page_url'] = (string) $index[ $page_key ]['page_url'];
			$win['query']    = (string) $base['query'];
			$win['at']       = $now;
			$resolved[]      = $win;
		}

		// Anything new on a worklist starts its clock now.
		foreach ( $current as $key => $entry ) {
			if ( isset( $baselines[ $key ] ) ) {
				continue;
			}
			$card              = $entry['card'];
			$queries           = (array) $card['queries'];
			$baselines[ $key ] = array(
				'kind'        => $entry['kind'],
				'page_key'    => $entry['page_key'],
				'page_id'     => (int) $card['page_id'],
				'page_url'    => (string) $card['page_url'],
				// The busiest search on the card — the one the editor promotes as
				// the page's focus, so the resolution names what the owner worked on.
				'query'       => $queries ? (string) $queries[0]['query'] : '',
				'position'    => (float) $card['position'],
				'impressions' => (int) $card['impressions'],
				'clicks'      => (int) $card['clicks'],
				'since'       => $now,
			);
		}

		self::write(
			array(
				'baselines' => $baselines,
				'resolved'  => self::prune( $resolved, $now ),
				// Carried through: a poll must never resurrect news the owner
				// has already dismissed.
				'seen'      => (int) $state['seen'],
			)
		);
	}

	/**
	 * Did this page actually get better, and in the way its group was about?
	 *
	 * The honesty rule of the whole feature, public so it can be tested as one:
	 * LEAVING A WORKLIST IS NOT RESOLVING. A page drops out by sinking, by losing
	 * its traffic, or by being set aside, and congratulating an owner for a page
	 * that collapsed is worse than saying nothing at all.
	 *
	 * @param string $kind    Opportunities::KIND_NEAR or ::KIND_SEEN.
	 * @param array  $base    The stored baseline.
	 * @param array  $page    Its current {@see Opportunities::page_index()} entry.
	 * @return array|null The move worth reporting, or null when there isn't one.
	 */
	public static function improvement( $kind, array $base, array $page ) {
		$impr = (int) $page['impressions'];
		if ( $impr < 1 ) {
			return null;
		}
		$position = Opportunities::page_position( $page );
		$was      = (float) $base['position'];

		if ( Opportunities::KIND_NEAR === $kind ) {
			// A card can join this group on ONE bad search while the page's own
			// average already sits on page one — the group judges searches first,
			// pages second. For such a card "it reached page one" was true the day
			// it appeared, so a later wobble in the average would announce a climb
			// that never happened. Only a page that was itself in the band can be
			// said to have left it.
			if ( $was < Opportunities::NEAR_MIN ) {
				return null;
			}
			// Out of the 8–20 band by climbing, not by sinking — and onto page
			// one, which is the whole point of the group.
			if ( $position <= 0 || $position >= $was || $position >= Opportunities::NEAR_MIN ) {
				return null;
			}
			return array(
				'from'  => round( $was, 1 ),
				'to'    => round( $position, 1 ),
				'shown' => $impr,
			);
		}

		// Seen and not chosen: the group is about the click rate, so that is
		// what has to have moved. Clicks where there were none is the plainest
		// version of it, and the only one worth a headline.
		$clicks     = (int) $page['clicks'];
		$was_clicks = (int) $base['clicks'];
		$was_impr   = (int) $base['impressions'];
		$ctr        = $clicks / $impr;
		$was_ctr    = $was_impr > 0 ? $was_clicks / $was_impr : 0.0;
		if ( $clicks < 1 || $ctr <= $was_ctr ) {
			return null;
		}
		return array(
			'from'  => round( $was_ctr * 100, 1 ),
			'to'    => round( $ctr * 100, 1 ),
			'shown' => $impr,
		);
	}

	/**
	 * The resolutions still worth showing, newest first.
	 *
	 * Read-only and self-expiring: a resolution older than {@see self::KEEP_DAYS}
	 * simply stops being returned, so no screen needs a dismiss control for a
	 * line that removes itself.
	 *
	 * @return array<int,array>
	 */
	public static function resolved() {
		$state = self::read();
		$seen  = (int) $state['seen'];
		$out   = array_values(
			array_filter(
				self::prune( $state['resolved'], self::now() ),
				static function ( $row ) use ( $seen ) {
					// Read once, gone. Anything that landed at or before the
					// moment they said "seen it" stops being news to them —
					// while a win that arrives afterwards still gets its turn.
					return (int) $row['at'] > $seen;
				}
			)
		);
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['at'] <=> (int) $a['at'];
			}
		);
		return $out;
	}

	/**
	 * Mark every resolution up to now as read.
	 *
	 * A timestamp rather than a per-row flag: "I have seen everything up to
	 * here" is the whole of what the owner is saying, it cannot go stale, and it
	 * leaves a win that lands tomorrow free to announce itself.
	 *
	 * The expiry still stands underneath. Dismissing is the owner's shortcut,
	 * not the only exit — a week-old win goes whether anyone looked or not.
	 *
	 * @return void
	 */
	public static function mark_seen() {
		$state         = self::read();
		$state['seen'] = self::now();
		self::write( $state );
	}

	/**
	 * When a page joined a worklist, as a timestamp.
	 *
	 * @param string $source   Source key.
	 * @param string $kind     Opportunities::KIND_NEAR or ::KIND_SEEN.
	 * @param int    $page_id  Resolved post ID, or 0.
	 * @param string $page_url The page URL.
	 * @return int Zero when this page has no baseline yet — a poll has not run
	 *             since it appeared, and a date we cannot support is worse than none.
	 */
	public static function since( $source, $kind, $page_id, $page_url = '' ) {
		$key   = self::key( sanitize_key( (string) $source ), (string) $kind, Opportunities::page_key( (int) $page_id, (string) $page_url ) );
		$state = self::read();
		return isset( $state['baselines'][ $key ]['since'] ) ? (int) $state['baselines'][ $key ]['since'] : 0;
	}

	/**
	 * Forget everything. Used when a source is disconnected: baselines describe
	 * an engine's report, and keeping them across a disconnect would let a
	 * reconnection weeks later announce "resolutions" nobody was waiting for.
	 *
	 * @param string $source Source key, or '' for every source.
	 * @return void
	 */
	public static function forget( $source = '' ) {
		$source = sanitize_key( (string) $source );
		if ( '' === $source ) {
			delete_option( self::OPTION );
			return;
		}
		$state = self::read();
		foreach ( array_keys( $state['baselines'] ) as $key ) {
			if ( 0 === strpos( $key, $source . '|' ) ) {
				unset( $state['baselines'][ $key ] );
			}
		}
		$state['resolved'] = array_values(
			array_filter(
				$state['resolved'],
				static function ( $row ) use ( $source ) {
					return (string) $row['source'] !== $source;
				}
			)
		);
		self::write( $state );
	}

	/* ---------------------------------------------------------------------- *
	 *  Storage
	 * ---------------------------------------------------------------------- */

	/**
	 * The ledger, always in its full shape.
	 *
	 * @return array{baselines:array<string,array>,resolved:array<int,array>}
	 */
	private static function read() {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'baselines' => ( isset( $state['baselines'] ) && is_array( $state['baselines'] ) ) ? $state['baselines'] : array(),
			'resolved'  => ( isset( $state['resolved'] ) && is_array( $state['resolved'] ) ) ? array_values( $state['resolved'] ) : array(),
			// The moment the owner last said "seen it". Zero until they do.
			'seen'      => isset( $state['seen'] ) ? (int) $state['seen'] : 0,
		);
	}

	/**
	 * @param array $state Full ledger.
	 * @return void
	 */
	private static function write( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Drop what has stopped being news, newest kept first.
	 *
	 * @param array $resolved Resolutions.
	 * @param int   $now      Current timestamp.
	 * @return array<int,array>
	 */
	private static function prune( array $resolved, $now ) {
		$floor = (int) $now - ( self::KEEP_DAYS * DAY_IN_SECONDS );
		$out   = array();
		foreach ( $resolved as $row ) {
			if ( is_array( $row ) && isset( $row['at'] ) && (int) $row['at'] >= $floor ) {
				$out[] = $row;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['at'] <=> (int) $a['at'];
			}
		);
		return array_slice( $out, 0, self::MAX_RESOLVED );
	}

	/**
	 * Page keys the owner has set aside, as a lookup.
	 *
	 * @param array $index      Current page index.
	 * @param array $aside      Set-aside post IDs.
	 * @param array $aside_urls Set-aside URLs.
	 * @return array<string,true>
	 */
	private static function hidden_keys( array $index, array $aside, array $aside_urls ) {
		$aside_urls = array_map( array( Pages::class, 'key' ), $aside_urls );
		$out        = array();
		foreach ( $index as $page_key => $page ) {
			$page_id = (int) $page['page_id'];
			$url_key = Pages::key( (string) $page['page_url'] );
			if ( ( $page_id > 0 && in_array( $page_id, $aside, true ) )
				|| ( '' !== $url_key && in_array( $url_key, $aside_urls, true ) ) ) {
				$out[ $page_key ] = true;
			}
		}
		return $out;
	}

	/**
	 * @param string $source   Source key.
	 * @param string $kind     Opportunities::KIND_NEAR or ::KIND_SEEN.
	 * @param string $page_key Page key.
	 * @return string
	 */
	private static function key( $source, $kind, $page_key ) {
		// Kind is part of the identity: one page can rank 8–20 on one search and
		// under-earn on another, and those two resolve on different evidence.
		return $source . '|' . $kind . '|' . $page_key;
	}

	/**
	 * @return int
	 */
	private static function now() {
		return (int) current_time( 'timestamp', true );
	}
}
