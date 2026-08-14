<?php
/**
 * Bing module — the daily poll that copies Bing's crawl statistics into the
 * site's own table, the schedule that runs it, and the verification tag the
 * site prints so Bing can confirm ownership.
 *
 * One HTTPS request per poll to Bing's Webmaster API, nothing else. The whole
 * feature stands down (no cron, no queries) until a key is connected.
 * Fail-open throughout — an API error records itself and keeps the last good
 * data.
 *
 * @package Agentimus
 */

namespace Agentimus\Bing;

use Agentimus\PollLock;

defined( 'ABSPATH' ) || exit;

final class Module {

	use PollLock;

	/** @var string The recurring poll event. */
	const CRON = 'agentimus_bing_poll';

	/** @var string Option-backed run lock — an option, not a transient, so it works without a persistent object cache. */
	const LOCK_OPTION = 'agentimus_bing_lock';

	/** @var int A poll is one HTTP call; a lock older than this is a died run to steal. */
	const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/** @var Settings */
	private $settings;

	/** @var Client */
	private $client;

	/**
	 * @param Settings    $settings The Bing connection store.
	 * @param Client|null $client   Injectable for tests.
	 */
	public function __construct( Settings $settings, ?Client $client = null ) {
		$this->settings = $settings;
		$this->client   = $client ? $client : new Client();
	}

	/**
	 * Hooks only — no work in the constructor.
	 *
	 * @return void
	 */
	public function register() {
		Table::maybe_install();
		\Agentimus\Search\Table::maybe_install();
		// ⚠️ Here, not only in the activation block: a plugin UPDATE does not
		// re-run activation, so a table introduced in a new version would never
		// exist on any site that already had the plugin. Every table in this
		// plugin migrates from a register() for exactly that reason.
		\Agentimus\Search\Asks::maybe_install();
		add_action( self::CRON, array( $this, 'poll' ) );
		add_action( 'admin_init', array( $this, 'sync_schedule' ) );
		// The verification tag: printed whenever a code is stored, so "click
		// Verify" works in Bing's dashboard and through our VerifySite call
		// alike. Early in the head, like the other identity tags.
		add_action( 'wp_head', array( $this, 'print_verification_tag' ), 1 );
	}

	/**
	 * The msvalidate.01 meta tag, when the owner has pasted a code.
	 *
	 * @return void
	 */
	public function print_verification_tag() {
		$code = (string) $this->settings->get( 'msvalidate', '' );
		if ( '' === $code ) {
			return;
		}
		echo '<meta name="msvalidate.01" content="' . esc_attr( $code ) . '" />' . "\n";
	}

	/**
	 * Keep the cron slot consistent with the connection state.
	 *
	 * @return void
	 */
	public function sync_schedule() {
		$scheduled = (bool) wp_next_scheduled( self::CRON );
		if ( ! $this->settings->connected() ) {
			if ( $scheduled ) {
				wp_clear_scheduled_hook( self::CRON );
			}
			return;
		}
		if ( ! $scheduled ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON );
		}
		$this->maybe_backfill_queries();
	}

	/** @var string Marks the one-time query-stats backfill as done. */
	const BACKFILL_OPTION = 'agentimus_bing_query_backfill';

	/**
	 * Query stats arrived after Bing did: a site that connected Bing BEFORE this
	 * feature existed has crawl numbers but no query rows, and would show
	 * "connected, no numbers yet" until the next daily poll — a whole day of
	 * looking broken to someone who just updated. Schedule one catch-up poll,
	 * once ever, a minute after the owner next opens wp-admin.
	 *
	 * @return void
	 */
	private function maybe_backfill_queries() {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}
		// Nothing to catch up on when rows already exist (a fresh connect polls
		// inline) — mark it done and never look again.
		if ( \Agentimus\Search\Table::has_rows( 'bing' ) ) {
			update_option( self::BACKFILL_OPTION, 1, false );
			return;
		}
		update_option( self::BACKFILL_OPTION, 1, false );
		if ( ! wp_next_scheduled( self::CRON ) || wp_next_scheduled( self::CRON ) > time() + MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON );
		}
	}

	/**
	 * Activation-time scheduling: only when a connection already exists.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! ( new Settings() )->connected() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Deactivation cleanup.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * The cron handler. Never overlaps itself.
	 *
	 * @return void
	 */
	public function poll() {
		if ( ! $this->settings->connected() ) {
			return;
		}
		if ( ! self::acquire_lock() ) {
			return;
		}
		try {
			$this->run_poll();
		} finally {
			self::release_lock();
		}
	}

	/**
	 * One poll, also callable inline right after connect so the panel has
	 * numbers immediately instead of "come back tomorrow". Bing returns its
	 * whole recent window each time; a re-polled day replaces its row.
	 *
	 * @return void
	 */
	public function run_poll() {
		$key  = $this->settings->api_key();
		$site = (string) $this->settings->get( 'site_url', '' );
		if ( '' === $key || '' === $site ) {
			return;
		}

		$out = $this->client->crawl_stats( $key, $site );
		if ( isset( $out['error'] ) ) {
			// Keep the last good data; the card shows the numbers' age and this note.
			$this->settings->record_poll( (string) $out['error'] );
		} else {
			Table::upsert( (array) $out['rows'] );
			$this->settings->record_poll( '' );
			Table::prune();
		}

		// The human-traffic series — clicks and impressions per day, the same
		// site-wide daily record Google's trend is built from. One extra call;
		// fail-open like everything here: on error the stored series stands and
		// its own timestamp ages, which is how the screen learns to say so
		// ({@see \Agentimus\Search\Report::extras()}).
		$traffic = $this->client->traffic_stats( $key, $site );
		if ( ! isset( $traffic['error'] ) ) {
			Table::record_traffic( (array) $traffic['rows'] );
			$this->settings->record_traffic_poll();
		}

		// The query-stats half runs regardless of how the crawl half fared, and
		// records its outcome on its own line — two datasets, two honest statuses.
		$this->run_query_poll( $key, $site );

		// The sitemap snapshot — one cheap call. Fail-open: on error the stored
		// snapshot stands (it carries Bing's own dates, so it stays honest).
		$feeds = $this->client->feeds( $key, $site );
		if ( ! isset( $feeds['error'] ) ) {
			$this->settings->record_feeds( (array) $feeds['rows'] );
		}
	}

	/**
	 * @var int Pages asked about in a poll to begin with.
	 *
	 * ⚠️ OURS, not Bing's. Bing publishes no rate limit for its Webmaster API —
	 * checked against Microsoft's access guide, its full published method list,
	 * and their own answer on collecting page metrics in bulk, none of which
	 * states a per-second, per-day or per-key limit. So there is no honest number
	 * to hard-code, and this one starts deliberately low and lets Bing's own
	 * behaviour set the pace {@see ASK_BATCH_MAX, walk_pages()}.
	 */
	const ASK_BATCH_START = 10;

	/** @var int The most pages one poll will ever ask about, however well it has been going. */
	const ASK_BATCH_MAX = 50;

	/** @var int What a clean run adds to the batch. Halving on refusal is much faster than this — deliberately. */
	const ASK_BATCH_STEP = 5;

	/**
	 * Snapshot Bing's query performance: the site-wide query list (feeds the
	 * site's own CTR median), plus per-page query breakdowns for the top pages
	 * by impressions (the rows that can name the page to fix). At most
	 * 2 + one call per page in the current batch, once a day. Fail-open: any error keeps
	 * the previous snapshot and records itself.
	 *
	 * ⚠️ Bing's query endpoints return WEEKLY buckets spanning ~16 months —
	 * one row per query per week ({@see Client::qstats_rows()}). Summing them
	 * whole put a 16-month figure under a card whose Google neighbour covers
	 * 56 days, labelled as if it were the same span. So the snapshot keeps the
	 * exact trailing window Google uses, anchored where Bing's own reporting
	 * ends, aggregates the buckets inside it, and states that range on every
	 * row — the range the card prints is now the range the data covers.
	 *
	 * @param string $key  API key, plaintext.
	 * @param string $site WMT site URL.
	 * @return void
	 */
	public function run_query_poll( $key, $site ) {
		// 1. Site-wide queries — median material, no page attribution here.
		$site_wide = $this->client->query_stats( $key, $site );
		if ( isset( $site_wide['error'] ) ) {
			$this->settings->record_query_poll( (string) $site_wide['error'] );
			return;
		}

		// 2. Bing's own busiest pages: their buckets anchor the window everything
		//    in this snapshot is measured over, and they seed the rotation with
		//    pages already known to have traffic.
		$pages = $this->client->page_stats( $key, $site );
		if ( isset( $pages['error'] ) ) {
			$this->settings->record_query_poll( (string) $pages['error'] );
			return;
		}

		$window = self::bucket_window( array_merge( (array) $site_wide['rows'], (array) $pages['rows'] ) );

		// 3. The site-wide set is written on its own, scoped to the rows that
		//    carry no page. Bing rows are stored as 'all': its API has no
		//    image/video/news split anywhere, so calling them 'web' would put a
		//    distinction in Bing's mouth that Bing never made.
		$site_rows = array();
		foreach ( self::window_aggregate( (array) $site_wide['rows'], $window ) as $row ) {
			$site_rows[] = self::snapshot_row( $row, '', 0, $window );
		}
		$saved = \Agentimus\Search\Table::replace_page( 'bing', '', $site_rows, \Agentimus\Search\Table::TYPE_ALL );
		$this->settings->record_dropped( $saved['dropped'] );

		if ( ! $saved['ok'] ) {
			// See the Google side: `ok`, never a count — and the previous snapshot
			// is still standing, so the sentence must not claim it is gone.
			$this->settings->record_query_poll( __( 'The new search numbers could not be saved. The ones already here are still correct and still shown. This usually happens when the report is bigger than one database write allows.', 'agentimus' ) );
			return;
		}

		$this->settings->record_query_poll( '' );
		// The ledger only ever learns something when a poll replaces the snapshot
		// it would compare to.
		if ( $saved['written'] > 0 ) {
			// ⚠️ The CORE settings, not this module's connection store. Progress
			// wants the set-aside lists, which live in the plugin's own option;
			// $this->settings here is Bing\Settings, and handing it over threw a
			// TypeError that WordPress turned into "there has been a critical
			// error" on the Bing card. Pre-existing, and invisible because the
			// only path that reaches it — a poll that actually stored rows — had
			// no test.
			\Agentimus\Search\Progress::observe( 'bing', new \Agentimus\Settings() );
		}

		$this->walk_pages( $key, $site, (array) $pages['rows'], $window );
	}

	/**
	 * Ask Bing about a few of the site's pages, and write down every answer.
	 *
	 * ⚠️ THIS REPLACED A LOOP THAT COULD NEVER GROW. It asked about the ten
	 * busiest pages, and the write erased the whole source first — so the same
	 * ten pages were refreshed every day and page eleven onward never had any
	 * Bing data at all, for the life of the site. Bing was never the limit; the
	 * loop was.
	 *
	 * Now each answer is written for that page alone, leaving every other page's
	 * rows standing, and the ask ledger remembers who has been asked so the next
	 * poll moves on to somebody new.
	 *
	 * The batch paces itself because Bing publishes no rate limit anywhere in its
	 * documentation — so there is no honest number to hard-code. It starts small,
	 * halves the moment Bing refuses, and grows again on clean runs.
	 *
	 * @param string $key       API key.
	 * @param string $site      WMT site URL.
	 * @param array  $page_rows Bing's own top-pages rows, unaggregated.
	 * @param array  $window    The snapshot's window.
	 * @return void
	 */
	private function walk_pages( $key, $site, array $page_rows, $window ) {
		$batch = (int) $this->settings->get( 'ask_batch', self::ASK_BATCH_START );
		$batch = min( self::ASK_BATCH_MAX, max( 1, $batch ) );

		$queue = $this->ask_queue( $page_rows, $window, $batch );

		// Budgeted, because this can run INSIDE a web request (connect, or the
		// Refresh button): a run of sequential calls against a slow morning is a
		// held worker racing the gateway timeout. A page passed over for time
		// keeps its place at the front of the queue — it is simply not recorded
		// as asked, which is the whole point of recording asks at all.
		$deadline = microtime( true ) + 20.0;
		$refused  = false;

		foreach ( $queue as $page ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$answer = $this->client->page_query_stats( $key, $site, $page['url'] );

			if ( isset( $answer['error'] ) ) {
				// Bing's own words, kept for the screen. A refusal ends the run:
				// asking harder is how a quiet limit becomes a blocked account.
				\Agentimus\Search\Asks::record(
					'bing',
					$page['url'],
					$page['id'],
					\Agentimus\Search\Asks::STATUS_ERROR,
					0,
					(string) $answer['error']
				);
				$refused = true;
				break;
			}

			$rows = array();
			foreach ( self::window_aggregate( (array) $answer['rows'], $window ) as $row ) {
				$rows[] = self::snapshot_row( $row, $page['url'], $page['id'], $window );
			}

			if ( $rows ) {
				\Agentimus\Search\Table::replace_page( 'bing', $page['url'], $rows, \Agentimus\Search\Table::TYPE_ALL );
			} else {
				// Bing answered, and the answer was "nothing". Yesterday's rows for
				// this page would now contradict the ledger, so they go.
				\Agentimus\Search\Table::clear_page( 'bing', $page['url'], \Agentimus\Search\Table::TYPE_ALL );
			}

			\Agentimus\Search\Asks::record(
				'bing',
				$page['url'],
				$page['id'],
				$rows ? \Agentimus\Search\Asks::STATUS_OK : \Agentimus\Search\Asks::STATUS_NONE,
				count( $rows )
			);
		}

		$this->settings->set_ask_batch(
			$refused
				? (int) max( 1, floor( $batch / 2 ) )
				: (int) min( self::ASK_BATCH_MAX, $batch + self::ASK_BATCH_STEP )
		);
	}

	/**
	 * Which pages this poll will ask about, in order.
	 *
	 * Bing's own busiest pages first when they have never been asked about —
	 * they already have traffic, so they are the fastest way to put real answers
	 * on an owner's screen. Then whatever the ledger says is next: newest
	 * unasked pages, then the ones waiting longest for a second look.
	 *
	 * @param array $page_rows Bing's top-pages rows, unaggregated.
	 * @param array $window    The snapshot's window.
	 * @param int   $batch     How many pages this poll may ask about.
	 * @return array<int,array{url:string,id:int}>
	 */
	private function ask_queue( array $page_rows, $window, $batch ) {
		$top = self::window_aggregate( $page_rows, $window );
		usort( $top, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );

		$queue = array();
		$seen  = array();

		foreach ( $top as $row ) {
			if ( count( $queue ) >= $batch ) {
				break;
			}
			$url = (string) $row['key'];
			if ( '' === $url ) {
				continue;
			}
			$id  = \Agentimus\Search\Pages::resolve( $url );
			$id_key = \Agentimus\Search\Asks::key( $id, $url );
			if ( isset( $seen[ $id_key ] ) || null !== \Agentimus\Search\Asks::state( 'bing', $url, $id ) ) {
				continue;
			}
			$seen[ $id_key ] = true;
			$queue[]         = array( 'url' => $url, 'id' => $id );
		}

		foreach ( \Agentimus\Search\Asks::next( 'bing', $batch - count( $queue ) ) as $page ) {
			$id_key = \Agentimus\Search\Asks::key( $page['id'], $page['url'] );
			if ( isset( $seen[ $id_key ] ) || '' === (string) $page['url'] ) {
				continue;
			}
			$seen[ $id_key ] = true;
			$queue[]         = $page;
		}

		return $queue;
	}

	/**
	 * The snapshot's window over Bing's weekly buckets: Google's own span
	 * ({@see \Agentimus\Google\Module::WINDOW_DAYS} — referenced, not copied,
	 * so the two sources can never quietly drift apart), anchored at the
	 * newest bucket Bing reported rather than at today — a stale poll must
	 * not silently shrink the set.
	 *
	 * @param array $rows Normalized qstats rows (with date_at).
	 * @return array{start:string,end:string}|null Null when no row carries a date.
	 */
	public static function bucket_window( array $rows ) {
		$end = '';
		foreach ( $rows as $row ) {
			$date = isset( $row['date_at'] ) ? (string) $row['date_at'] : '';
			if ( $date > $end ) {
				$end = $date;
			}
		}
		if ( '' === $end ) {
			return null;
		}
		$anchor = strtotime( $end . ' 00:00:00 +0000' );
		return array(
			'start' => gmdate( 'Y-m-d', $anchor - ( ( \Agentimus\Google\Module::WINDOW_DAYS - 1 ) * DAY_IN_SECONDS ) ),
			'end'   => $end,
		);
	}

	/**
	 * Collapse weekly buckets into one row per key over the window: clicks and
	 * impressions summed, position impression-weighted (falling back to a
	 * plain mean when every kept bucket has zero impressions — rare, but a
	 * division by zero must not be how we learn it happens).
	 *
	 * A dateless row (DTO drift) is kept rather than dropped: over-counting a
	 * malformed week is a smaller lie than silently losing real traffic.
	 *
	 * @param array      $rows   Normalized qstats rows.
	 * @param array|null $window From bucket_window(); null = no filtering.
	 * @return array<int,array{key:string,clicks:int,impressions:int,position:float}>
	 */
	public static function window_aggregate( array $rows, $window ) {
		$by = array();
		foreach ( $rows as $row ) {
			$date = isset( $row['date_at'] ) ? (string) $row['date_at'] : '';
			if ( null !== $window && '' !== $date && ( $date < $window['start'] || $date > $window['end'] ) ) {
				continue;
			}
			$key = (string) $row['key'];
			if ( ! isset( $by[ $key ] ) ) {
				$by[ $key ] = array( 'key' => $key, 'clicks' => 0, 'impressions' => 0, 'weight' => 0.0, 'pos_sum' => 0.0, 'buckets' => 0 );
			}
			$by[ $key ]['clicks']      += (int) $row['clicks'];
			$by[ $key ]['impressions'] += (int) $row['impressions'];
			$by[ $key ]['weight']      += (float) $row['position'] * (int) $row['impressions'];
			$by[ $key ]['pos_sum']     += (float) $row['position'];
			$by[ $key ]['buckets']++;
		}

		$out = array();
		foreach ( $by as $row ) {
			$row['position'] = $row['impressions'] > 0
				? round( $row['weight'] / $row['impressions'], 2 )
				: round( $row['pos_sum'] / max( 1, $row['buckets'] ), 2 );
			unset( $row['weight'], $row['pos_sum'], $row['buckets'] );
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * One aggregated row shaped for {@see \Agentimus\Search\Table::replace()},
	 * carrying the window it was measured over.
	 *
	 * @param array      $row      An aggregated {key, clicks, impressions, position} row.
	 * @param string     $page_url The page this row belongs to, '' for site-wide.
	 * @param int        $page_id  The resolved post ID, 0 when none.
	 * @param array|null $window   The snapshot's window, when one was found.
	 * @return array
	 */
	private static function snapshot_row( array $row, $page_url, $page_id, $window ) {
		$out = array(
			'query'       => $row['key'],
			'page_url'    => (string) $page_url,
			'page_id'     => (int) $page_id,
			'clicks'      => $row['clicks'],
			'impressions' => $row['impressions'],
			'position'    => $row['position'],
		);
		if ( null !== $window ) {
			$out['range_start'] = $window['start'];
			$out['range_end']   = $window['end'];
		}
		return $out;
	}

}
