<?php
/**
 * Opportunities — the engine that turns a query-stats snapshot into the two
 * honest worklists:
 *
 *   almost_there   — queries ranking 8–20: already chosen by the engine's
 *                    index, one push from page one.
 *   seen_not_chosen — queries already ON page one whose CTR sits well under
 *                    THIS SITE'S OWN page-one median. The bar is self-derived
 *                    from the same snapshot — never an industry benchmark we'd
 *                    have to invent — and when the site has too few page-one
 *                    rows to compute an honest median, the group simply
 *                    doesn't exist rather than judging against noise.
 *
 * Grouped by PAGE, because the page is the unit of fixing: every action the UI
 * offers is an existing Agentimus surface on that page. Pages the owner SET
 * ASIDE in Optimize are excluded here too and counted visibly — one decision
 * per page, honored by every worklist ({@see \Agentimus\Rest} optimize_ignored).
 *
 * Pure math over arrays; WP touches only for the set-aside list and post
 * titles, both injectable for tests.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Opportunities {

	/** @var float Page-two band the "almost there" group covers. */
	/**
	 * The two worklists, named once.
	 *
	 * This class draws the line between them, so it owns what they are called.
	 * Every other subsystem that has an opinion per group — the ledger's
	 * baselines, the done-verdict, the findings split — keys off these rather
	 * than off its own string literals.
	 */
	const KIND_NEAR = 'almost';

	/** @see self::KIND_NEAR */
	const KIND_SEEN = 'seen';

	const NEAR_MIN = 8.0;
	const NEAR_MAX = 20.0;

	/** @var int An "almost there" query must have at least this many impressions — below it, a push wins noise. */
	const NEAR_MIN_IMPRESSIONS = 50;

	/** @var int A "seen, not chosen" query must have at least this many impressions to judge its CTR at all. */
	const CTR_MIN_IMPRESSIONS = 100;

	/** @var float "Low" means under this fraction of the site's own page-one median CTR. */
	const CTR_FRACTION = 0.6;

	/** @var int The median needs at least this many page-one rows, or the group doesn't run. */
	const MEDIAN_MIN_ROWS = 5;

	/** @var int Pages per group, most impressions first. */
	const MAX_PAGES = 6;

	/** @var int Queries listed per page card. */
	const MAX_QUERIES = 3;

	/** @var int Discarded searches shown verbatim, so the filter can be audited rather than trusted. */
	const NOISE_EXAMPLES = 6;

	/**
	 * Build the report from a snapshot.
	 *
	 * @param array      $rows           Snapshot rows ({@see Table::snapshot()}).
	 * @param array      $set_aside      Post IDs the owner set aside (the shared Optimize list).
	 * @param array      $set_aside_urls URL keys ({@see Pages::key()}) set aside for pages that
	 *                                   map to no post — the only identity such a page has.
	 * @param array|null $collisions     {@see Collisions::build()} rows, when the caller has
	 *                                   already built them over this same snapshot. NULL means
	 *                                   build them here — ⛔ never "skip the check": a search a
	 *                                   page is losing to its own sibling must leave this
	 *                                   worklist whoever is asking, or one door advises the
	 *                                   climb another door says to abandon.
	 * @return array {
	 *     almost_there:    array<int,array> page cards,
	 *     seen_not_chosen: array<int,array> page cards,
	 *     median_ctr:      float|null  the self-derived page-one median (null = not computable),
	 *     counts:          array{opportunities:int,almost:int,seen:int,set_aside:int},
	 * }
	 */
	public static function build( array $rows, array $set_aside = array(), array $set_aside_urls = array(), $collisions = null ) {
		$set_aside      = array_map( 'intval', $set_aside );
		$set_aside_urls = array_map( array( Pages::class, 'key' ), array_map( 'strval', $set_aside_urls ) );

		// ⛔ THE SEARCHES THIS SITE IS SPLITTING WITH ITSELF. A page holding the
		// weaker half of one is not "one push from page one" for it — the push is
		// exactly what the split row asks them NOT to make. Read from the caller's
		// own collision pass where there is one, so a screen showing both never
		// computes the same split twice or disagrees with itself about who won.
		$lost = Collisions::losing_pairs(
			null === $collisions ? Collisions::build( $rows, $set_aside, $set_aside_urls ) : (array) $collisions
		);

		// 1. Measure the machine traffic BEFORE discarding it. Dropping it silently
		// leaves an empty worklist next to a Search Performance screen full of big
		// numbers, and the owner can only read that as a bug.
		//
		// Measured over PAGE-ATTRIBUTED rows only: an engine reports the same window
		// twice — a site-wide query list AND per-page breakdowns — so a share taken
		// across both divides by a double-counted total and describes nothing. These
		// are the rows this worklist is built from, so they are the honest
		// denominator for explaining why it looks empty.
		$noise_impr = 0;
		$all_impr   = 0;
		// Keyed BY QUERY, not by row. One search that landed on six pages is six
		// rows but one search, and calling it six would overstate how many
		// different things were dropped — besides filling the sample below with
		// the same string repeated.
		$noise_by_query = array();
		$noise_kind     = array();
		// ⛔ COUNTED SEPARATELY, because only this one is evidence about WHO
		// searched. The screen says "these views weren't people" and may only say
		// it of operator probes; a pasted URL is dropped for a different reason
		// and proves nothing about the searcher {@see Noise}.
		$probe_impr = 0;
		foreach ( $rows as $row ) {
			if ( '' === (string) $row['page_url'] && (int) $row['page_id'] < 1 ) {
				continue;
			}
			$impr      = (int) $row['impressions'];
			$all_impr += $impr;
			$query     = (string) $row['query'];
			$kind      = Noise::kind( $query );
			if ( '' !== $kind ) {
				$noise_impr += $impr;
				if ( Noise::OPERATOR === $kind ) {
					$probe_impr += $impr;
				}
				// Kept verbatim: a percentage asks to be believed, where the actual
				// discarded searches can be read and judged — the only way an owner
				// can tell an over-eager filter from a correct one.
				if ( ! isset( $noise_by_query[ $query ] ) ) {
					$noise_by_query[ $query ] = 0;
				}
				$noise_by_query[ $query ] += $impr;
				$noise_kind[ $query ]      = $kind;
			}
		}
		arsort( $noise_by_query );
		$noise_rows     = count( $noise_by_query );
		$noise_examples = array();
		foreach ( array_slice( $noise_by_query, 0, self::NOISE_EXAMPLES, true ) as $query => $impr ) {
			// ⭐ Each row says WHY it was dropped. With one rule the reason could be
			// stated once in a footnote; with four, a list that does not name the
			// reason per row cannot be audited — which is the only thing it is for.
			$noise_examples[] = array(
				'query'       => $query,
				'impressions' => $impr,
				'kind'        => $noise_kind[ $query ],
			);
		}

		// ⛔ THE OWNER'S OWN LEDGER, WHOLE — never the top six. The list above is
		// illustrative and capped; this one is a set of decisions the owner made
		// and must be able to undo, and a dismissal they cannot see is a dismissal
		// they cannot lift. Built from the STORED list, not from the rows: a
		// search the engines have since stopped reporting is still dismissed, and
		// would vanish from a rows-derived list while still quietly filtering.
		$dismissed = array();
		foreach ( Noise::dismissed() as $query ) {
			$dismissed[] = array(
				'query'       => (string) $query,
				'impressions' => isset( $noise_by_query[ $query ] ) ? (int) $noise_by_query[ $query ] : 0,
			);
		}

		// 2. Group the surviving page-attributed rows by page. Rows with no page
		// identity (an engine's site-wide query stats) still inform the median
		// below, but can never form a card — a worklist entry that can't name the
		// page to fix isn't a worklist.
		$rows  = array_values( array_filter( $rows, static function ( $row ) {
			return ! Noise::is_noise( (string) $row['query'] );
		} ) );
		$pages = self::page_index( $rows );

		// 3. The bar. Individual searches decide it when enough of them carry
		// real traffic; otherwise the same measure taken over whole pages —
		// because an engine can report one page's demand split across dozens of
		// tiny long-tail searches, and a site is not "unmeasurable" just because
		// its traffic arrives that way.
		$bar    = self::median_with_reason( $rows );
		if ( null === $bar['median'] ) {
			// Whole pages get the same rule; its verdict replaces the row-level one,
			// because it is the last and broadest thing we tried.
			$bar = self::median_with_reason( self::page_totals( $pages ) );
		}
		$median = $bar['median'];

		$near         = array();
		$seen         = array();
		$hidden_pages = array();
		// Did ANYTHING carry enough traffic to be judged at all? Without this, a
		// source whose searches are all tiny produces an empty worklist that
		// reads like praise — "your pages are earning their clicks" — when the
		// honest answer is "there isn't enough here to judge".
		$judged = false;

		foreach ( $pages as $key => $page ) {
			$page_id = (int) $page['page_id'];

			// Whether this snapshot CAN be judged is a fact about the data, not
			// about the owner's choices — so it is settled before the set-aside
			// skip. Otherwise a site that set every page aside would be told "not
			// enough search traffic to judge yet", which is simply untrue.
			if ( (int) $page['impressions'] >= self::NEAR_MIN_IMPRESSIONS ) {
				$judged = true;
			} else {
				foreach ( $page['rows'] as $row ) {
					if ( (int) $row['impressions'] >= self::NEAR_MIN_IMPRESSIONS ) {
						$judged = true;
						break;
					}
				}
			}

			// The set-aside: excluded from the worklists, counted visibly. A page
			// that maps to no post is held back by URL — the same one-click
			// decision, keyed by the only identity such a page has. The URL match
			// runs for mapped pages too, so an entry stored while a page was
			// unmapped keeps holding it back after it gains a post ID.
			$url_key = Pages::key( (string) $page['page_url'] );
			if ( ( $page_id > 0 && in_array( $page_id, $set_aside, true ) )
				|| ( '' !== $url_key && in_array( $url_key, $set_aside_urls, true ) ) ) {
				$hidden_pages[ $key ] = true;
				continue;
			}

			// The searches this page is losing to a sibling leave the worklist —
			// and if that is all it had, so does the page. ⚠️ Its totals are
			// rebuilt from what survives, because 4b judges a page on its whole
			// demand, and demand it is being told to give up is not demand it
			// can act on.
			$page = self::without_lost( $page, $lost );
			if ( ! $page['rows'] ) {
				continue;
			}

			// 4a. Individual searches first — the sharpest signal, and the one a
			// title rewrite answers directly.
			$near_q = array();
			$seen_q = array();
			foreach ( $page['rows'] as $row ) {
				$pos  = (float) $row['position'];
				$impr = (int) $row['impressions'];
				$ctr  = $impr > 0 ? ( (int) $row['clicks'] ) / $impr : 0.0;
				if ( self::is_near( $pos, $impr ) ) {
					$near_q[] = self::query_row( $row, $pos, $impr, $ctr );
				} elseif ( self::is_seen( $pos, $impr, $ctr, $median ) ) {
					$seen_q[] = self::query_row( $row, $pos, $impr, $ctr );
				}
			}

			if ( $near_q ) {
				$near[ $key ] = self::bucket( $page, $near_q, false );
			}
			if ( $seen_q ) {
				$seen[ $key ] = self::bucket( $page, $seen_q, false );
			}
			if ( $near_q || $seen_q ) {
				continue;
			}

			// 4b. Nothing qualified query by query — so judge the PAGE. Its whole
			// demand, its impression-weighted rank, its real click rate. This is
			// what makes a page visible when its traffic arrives as fifty small
			// searches instead of one big one.
			$impr = (int) $page['impressions'];
			if ( $impr < self::NEAR_MIN_IMPRESSIONS ) {
				continue;
			}
			$pos    = self::page_position( $page );
			$ctr    = $page['clicks'] / $impr;

			if ( self::is_near( $pos, $impr ) ) {
				$near[ $key ] = self::bucket( $page, self::top_queries( $page ), true );
			} elseif ( self::is_seen( $pos, $impr, $ctr, $median ) ) {
				$seen[ $key ] = self::bucket( $page, self::top_queries( $page ), true );
			}
		}

		$near_cards = self::cards( $near );
		$seen_cards = self::cards( $seen );
		// Count PAGES, not searches: a page is the thing you open and fix, and a
		// long-tail page carrying forty tiny searches is one job, not forty.
		$almost_n = count( $near_cards );
		$seen_n   = count( $seen_cards );
		// …and not the same page twice. One page can rank 8–20 on one search AND
		// under-earn on another, so it legitimately appears in both groups — but
		// it is still ONE page to open, and the header counts pages.
		$distinct = count( array_unique( array_merge( array_keys( $near ), array_keys( $seen ) ) ) );

		return array(
			'almost_there'    => array_slice( $near_cards, 0, self::MAX_PAGES ),
			'seen_not_chosen' => array_slice( $seen_cards, 0, self::MAX_PAGES ),
			// How many pages each group really has, so the screen can admit what
			// the cap hides. A worklist that shows six of forty without saying so
			// reads as "that's all of it".
			'page_counts'     => array(
				'almost' => count( $near_cards ),
				'seen'   => count( $seen_cards ),
			),
			'median_ctr'      => null === $median ? null : round( $median * 100, 1 ),
			// The bar actually applied by is_seen(). The median alone is NOT the
			// threshold — a page must fall well under it — so a screen that prints
			// the median next to the words "not clicked enough means below…" states
			// a number the engine never used.
			'ctr_bar'         => null === $median ? null : round( $median * self::CTR_FRACTION * 100, 1 ),
			// WHY there is no bar, when there isn't one. "Not enough page-one results
			// carrying real traffic" and "plenty of them, none clicked" are different
			// facts about a site, and a screen that states the wrong one is telling
			// the owner something untrue about their own numbers.
			'median_reason'   => $bar['reason'],
			// How many page-one results carried enough views to measure, and how
			// many it takes. Turns "not enough" into something checkable.
			'median_rows'     => (int) $bar['qualifying'],
			'median_needs'    => self::MEDIAN_MIN_ROWS,
			// What was thrown away as machine traffic, so the screen can explain
			// itself instead of looking like it disagrees with Search Performance.
			// Share is of the same row set both numbers come from, never a total
			// borrowed from another screen's arithmetic.
			'noise'           => array(
				// Distinct searches, not rows ({@see $noise_by_query}).
				'searches'   => $noise_rows,
				'share'      => $all_impr > 0 ? (int) round( ( $noise_impr / $all_impr ) * 100 ) : 0,
				// ⛔ The share the screen may call "not people" — operator probes
				// ONLY. `share` above covers everything left out, for whatever
				// reason, and saying THAT was machines would be a claim about the
				// searcher with nothing behind it.
				'probeShare' => $all_impr > 0 ? (int) round( ( $probe_impr / $all_impr ) * 100 ) : 0,
				'examples'   => $noise_examples,
				'dismissed'  => $dismissed,
			),
			// False = rows exist, but every one is too thin to judge. The screen
			// must say that plainly instead of implying everything is fine.
			'judged'          => $judged,
			'counts'          => array(
				'almost'        => $almost_n,
				'seen'          => $seen_n,
				'opportunities' => $distinct,
				'set_aside'     => count( $hidden_pages ),
			),
		);
	}

	/**
	 * A search-operator string rather than something a person typed looking for
	 * an answer: `site:`, `intext:`, `inurl:`, `filetype:`, `cache:`. Engines
	 * report these because scrapers and SEO tools run them in bulk, and they
	 * badly distort a worklist — a page can collect hundreds of impressions from
	 * `site:` probes that no human will ever click, and "improve the title" is
	 * no answer to a machine. They stay in the stored snapshot (Search
	 * Performance is the raw record); the worklist simply refuses to act on them.
	 *
	 * ⛔⛔ A NEGATED OPERATOR IS STILL AN OPERATOR, and missing that made this
	 * whole guard leak. The test used to be "starts the string, or follows a
	 * space" — and in `-site:reddit.com` the character before `site:` is a
	 * hyphen, so the single commonest shape of a machine probe walked straight
	 * through it: the long exclusion list an SEO tool or a scraper appends to
	 * every query. Found on heera.it, 2026-08-25, where a post was being judged
	 * against `"ai answer engines ai user agents" -site:reddit.com -site:twitter.com …`
	 * while the site's probe share read 0% across 17,919 impressions.
	 *
	 * ⭐ So the test is a TOKEN BOUNDARY, not a space: the operator may open the
	 * string or follow whitespace, a bracket or a quote, and may carry a leading
	 * `-` (exclude) or `+` (require). ⛔ It is deliberately not "appears
	 * anywhere": `website design` and `how to cache a query in laravel` are
	 * things people type, and a filter that eats them would quietly delete real
	 * demand from the worklist — the opposite failure, and the harder one to
	 * notice.
	 *
	 * @param string $query The reported search.
	 * @return bool
	 */
	public static function is_operator_query( $query ) {
		// ⛔ The NARROW question, and it keeps its narrow name: does this use
		// search operators — the evidence Search Performance puts the words
		// "machine traffic" behind. {@see Noise} owns the wider one, "can a page
		// be judged against this at all?", which a URL fails without being a
		// probe. Merging them would have `isProbe` claim a searcher it cannot see.
		return Noise::is_operator( $query );
	}

	/**
	 * Is this an "almost there" — ranking on page two with real demand behind it?
	 *
	 * @param float $pos  Average position.
	 * @param int   $impr Impressions.
	 * @return bool
	 */
	private static function is_near( $pos, $impr ) {
		return $pos >= self::NEAR_MIN && $pos <= self::NEAR_MAX && $impr >= self::NEAR_MIN_IMPRESSIONS;
	}

	/**
	 * Is this "seen, not chosen" — on page one, with enough views to judge, and
	 * a click rate well under the site's own page-one median?
	 *
	 * @param float      $pos    Average position.
	 * @param int        $impr   Impressions.
	 * @param float      $ctr    Click rate as a fraction.
	 * @param float|null $median The site's own page-one median, or null when unknowable.
	 * @return bool
	 */
	private static function is_seen( $pos, $impr, $ctr, $median ) {
		return null !== $median
			&& $pos > 0 && $pos < self::NEAR_MIN
			&& $impr >= self::CTR_MIN_IMPRESSIONS
			&& $ctr < ( $median * self::CTR_FRACTION );
	}

	/**
	 * One search, shaped for the card.
	 *
	 * @param array $row  The snapshot row.
	 * @param float $pos  Position.
	 * @param int   $impr Impressions.
	 * @param float $ctr  Click rate as a fraction.
	 * @return array
	 */
	private static function query_row( array $row, $pos, $impr, $ctr ) {
		return array(
			'query'       => (string) $row['query'],
			'position'    => round( $pos, 1 ),
			'impressions' => $impr,
			'clicks'      => (int) $row['clicks'],
			'ctr'         => round( $ctr * 100, 1 ),
		);
	}

	/**
	 * A page bucket ready for {@see cards()}.
	 *
	 * @param array $page       The grouped page.
	 * @param array $queries    The searches to show.
	 * @param bool  $whole_page Whether the PAGE's totals qualified rather than any single search.
	 * @return array
	 */
	private static function bucket( array $page, array $queries, $whole_page ) {
		$impr = (int) $page['impressions'];
		return array(
			'page_id'     => (int) $page['page_id'],
			'page_url'    => (string) $page['page_url'],
			// The page's whole demand either way, so cards sort by what the page
			// really carries rather than by the slice that happened to qualify.
			'impressions' => $impr,
			'clicks'      => (int) $page['clicks'],
			'position'    => $impr > 0 ? round( $page['pos_weight'] / $impr, 1 ) : 0.0,
			'ctr'         => $impr > 0 ? round( ( $page['clicks'] / $impr ) * 100, 1 ) : 0.0,
			// Distinct searches. Two URL spellings of one post merge into a single
			// group, so a row count could name the same search twice.
			'searches'    => count( array_unique( array_map( static function ( $r ) {
				return (string) $r['query'];
			}, $page['rows'] ) ) ),
			'whole_page'  => (bool) $whole_page,
			'queries'     => $queries,
		);
	}

	/**
	 * A page's biggest searches, for a card the page itself earned.
	 *
	 * @param array $page The grouped page.
	 * @return array<int,array>
	 */
	private static function top_queries( array $page ) {
		$out = array();
		foreach ( $page['rows'] as $row ) {
			$impr  = (int) $row['impressions'];
			$out[] = self::query_row( $row, (float) $row['position'], $impr, $impr > 0 ? ( (int) $row['clicks'] ) / $impr : 0.0 );
		}
		usort( $out, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );
		return $out;
	}

	/**
	 * One page's rows minus the searches it is LOSING in a split, with its
	 * totals rebuilt from what is left.
	 *
	 * ⭐ It rebuilds rather than subtracts: `pos_weight` is an impression-weighted
	 * sum, so a position carried over from the old total and divided by the new
	 * impressions would be a rank the page never held.
	 *
	 * @param array $page One {@see page_index()} entry.
	 * @param array $lost {@see Collisions::losing_pairs()}.
	 * @return array The same shape, possibly with fewer rows.
	 */
	private static function without_lost( array $page, array $lost ) {
		if ( ! $lost ) {
			return $page;
		}
		$by_id  = (int) $page['page_id'] > 0 ? self::page_key( (int) $page['page_id'], '' ) : '';
		$by_url = '' === (string) $page['page_url'] ? '' : self::page_key( 0, (string) $page['page_url'] );

		$kept = array();
		foreach ( $page['rows'] as $row ) {
			$query = (string) $row['query'];
			if ( '' !== $by_id && isset( $lost[ Collisions::lost_key( $by_id, $query ) ] ) ) {
				continue;
			}
			if ( '' !== $by_url && isset( $lost[ Collisions::lost_key( $by_url, $query ) ] ) ) {
				continue;
			}
			$kept[] = $row;
		}
		if ( count( $kept ) === count( $page['rows'] ) ) {
			return $page;
		}

		$page['rows']        = $kept;
		$page['impressions'] = 0;
		$page['clicks']      = 0;
		$page['pos_weight']  = 0.0;
		foreach ( $kept as $row ) {
			$impr                 = (int) $row['impressions'];
			$page['impressions'] += $impr;
			$page['clicks']      += (int) $row['clicks'];
			$page['pos_weight']  += ( (float) $row['position'] ) * $impr;
		}
		return $page;
	}

	/**
	 * Snapshot rows grouped by the page they landed on.
	 *
	 * Public because the progress ledger needs the SAME page totals this class
	 * judges against: a resolution is "this page's rank improved", and a second
	 * implementation of the grouping would eventually disagree with this one
	 * about what a page's rank even is. Rows with no page identity are dropped —
	 * they inform the median, but they can never be a page.
	 *
	 * @param array $rows Snapshot rows, operator queries already filtered out.
	 * @return array<string,array> Keyed by page key; carries pos_weight and rows.
	 */
	public static function page_index( array $rows ) {
		$pages = array();
		foreach ( $rows as $row ) {
			$page_id = (int) $row['page_id'];
			$url     = (string) $row['page_url'];
			if ( 0 === $page_id && '' === $url ) {
				continue;
			}
			$key = self::page_key( $page_id, $url );
			if ( ! isset( $pages[ $key ] ) ) {
				$pages[ $key ] = array(
					'page_id'     => $page_id,
					'page_url'    => $url,
					'impressions' => 0,
					'clicks'      => 0,
					'pos_weight'  => 0.0,
					'rows'        => array(),
				);
			}
			$impr = (int) $row['impressions'];
			$pages[ $key ]['impressions'] += $impr;
			$pages[ $key ]['clicks']      += (int) $row['clicks'];
			$pages[ $key ]['pos_weight']  += ( (float) $row['position'] ) * $impr;
			$pages[ $key ]['rows'][]       = $row;
		}
		return $pages;
	}

	/**
	 * One page's identity inside a snapshot. A post ID when the URL resolved to
	 * one, the URL's digest otherwise — the same page must key the same way
	 * across polls or the ledger loses track of it.
	 *
	 * @param int    $page_id Resolved post ID, or 0.
	 * @param string $url     Page URL.
	 * @return string
	 */
	public static function page_key( $page_id, $url ) {
		return (int) $page_id > 0 ? 'p' . (int) $page_id : 'u' . md5( (string) $url );
	}

	/**
	 * A page's impression-weighted average rank — the number both the cards and
	 * the ledger mean by "where this page ranks".
	 *
	 * @param array $page One {@see self::page_index()} entry.
	 * @return float Zero when the page has no impressions to weight by.
	 */
	public static function page_position( array $page ) {
		$impr = (int) $page['impressions'];
		return $impr > 0 ? ( (float) $page['pos_weight'] ) / $impr : 0.0;
	}

	/**
	 * Page groups projected into snapshot-shaped rows, so the median helper can
	 * measure whole pages with the identical rule it applies to searches.
	 *
	 * @param array $pages Grouped pages.
	 * @return array<int,array>
	 */
	private static function page_totals( array $pages ) {
		$rows = array();
		foreach ( $pages as $page ) {
			$impr = (int) $page['impressions'];
			if ( $impr < 1 ) {
				continue;
			}
			$rows[] = array(
				'query'       => '',
				'page_url'    => (string) $page['page_url'],
				'page_id'     => (int) $page['page_id'],
				'clicks'      => (int) $page['clicks'],
				'impressions' => $impr,
				'position'    => $page['pos_weight'] / $impr,
			);
		}
		return $rows;
	}

	/**
	 * The site's own page-one median CTR from the snapshot, or null when there
	 * aren't enough page-one rows to say anything honest.
	 *
	 * @param array $rows Snapshot rows.
	 * @return float|null Median CTR as a fraction (0.052 = 5.2%).
	 */
	public static function page_one_median_ctr( array $rows ) {
		$bar = self::median_with_reason( $rows );
		return $bar['median'];
	}

	/**
	 * The same median, plus WHY it couldn't be computed when it couldn't.
	 *
	 * Two very different absences hide behind one null, and they call for
	 * different sentences on screen:
	 *
	 *   'thin'      — fewer than MEDIAN_MIN_ROWS page-one results carry enough
	 *                 views to measure. Says nothing about clicking; the engine
	 *                 simply hasn't reported enough traffic yet.
	 *   'unclicked' — enough of them exist, and the middle one earned no clicks
	 *                 at all. A median of zero is not a bar, it's an absence:
	 *                 every page would pass "below average" and the screen would
	 *                 congratulate a site nobody clicks.
	 *
	 * @param array $rows Snapshot rows (or whole pages projected as rows).
	 * @return array{median:float|null,reason:string} reason is '' when a bar exists.
	 */
	private static function median_with_reason( array $rows ) {
		$ctrs = array();
		foreach ( $rows as $row ) {
			$pos  = (float) $row['position'];
			$impr = (int) $row['impressions'];
			if ( $pos > 0 && $pos < self::NEAR_MIN && $impr >= self::NEAR_MIN_IMPRESSIONS ) {
				$ctrs[] = ( (int) $row['clicks'] ) / $impr;
			}
		}
		if ( count( $ctrs ) < self::MEDIAN_MIN_ROWS ) {
			// Carry the count: "too few" is a shrug, "3 of the 5 it takes" is a fact
			// the owner can check and act on.
			return array( 'median' => null, 'reason' => 'thin', 'qualifying' => count( $ctrs ) );
		}
		sort( $ctrs );
		$n      = count( $ctrs );
		$mid    = (int) floor( $n / 2 );
		$median = 0 === $n % 2 ? ( $ctrs[ $mid - 1 ] + $ctrs[ $mid ] ) / 2 : $ctrs[ $mid ];

		return $median > 0
			? array( 'median' => $median, 'reason' => '', 'qualifying' => $n )
			: array( 'median' => null, 'reason' => 'unclicked', 'qualifying' => $n );
	}

	/**
	 * Page buckets → sorted cards, queries capped for display but all counted.
	 *
	 * @param array $buckets Keyed page buckets.
	 * @return array<int,array>
	 */
	private static function cards( array $buckets ) {
		$cards = array_values( $buckets );
		usort( $cards, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );
		foreach ( $cards as &$card ) {
			usort( $card['queries'], static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			} );
			$card['all_queries'] = $card['queries'];
			$card['more']        = max( 0, count( $card['queries'] ) - self::MAX_QUERIES );
			$card['queries']     = array_slice( $card['queries'], 0, self::MAX_QUERIES );
		}
		unset( $card );
		return $cards;
	}
}
