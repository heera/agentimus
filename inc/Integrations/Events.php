<?php
/**
 * Integration events — the catalog, the envelope, and the listeners that turn
 * moments inside the plugin into rows on the outgoing queue.
 *
 * The plugin already NOTICES the moments an owner would want relayed — a new
 * finding, a caught impostor, a robots policy that moved — but each of those
 * observations lives inside its own subsystem, shaped for its own screen. This
 * class is the one place those moments are translated into a stable, versioned
 * wire contract, so an external service (Zapier, Make, n8n, anything with a
 * URL) can consume them without knowing anything about our internals.
 *
 * Three rules keep it safe:
 *
 *  - The contract is EXPLICIT. Every event's payload is built here, key by
 *    key, and carries a version number. Nothing internal (a DB row, a settings
 *    array) is ever forwarded as-is, so an internal rename can never silently
 *    break a subscriber — and nothing can leak that we did not choose to send.
 *  - Own-site report data only, never visitor PII. An impostor event names the
 *    CLIENT (the user-agent token), never an IP address; the digest event
 *    carries the digest's summary numbers, not the rows behind them.
 *  - Inert unless connected. register() stands down without adding a single
 *    hook while no service is connected — the WebMCP doctrine. The do_action
 *    seams in the emitting subsystems always fire (a no-listener action is
 *    free); only THIS class's listeners come and go. One emit fans out to
 *    every connected service that subscribed (Services::wanting), each as its
 *    own queue row — so one slow receiver never owes another one anything.
 *
 * finding_opened is the one event with no live seam to listen on: findings are
 * COMPUTED on read (Findings::all()), not stored, so "a new finding appeared"
 * is a fact nobody witnesses. A daily cron computes the list, compares the
 * finding identities against the set it saw last time, and announces only the
 * new ones. The first run after connecting seeds the baseline silently — the
 * same first-look-remembers-quietly law RobotsWatch follows — so connecting
 * never floods the webhook with everything that was already true.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations;

use Agentimus\Findings;
use Agentimus\Settings;
use Agentimus\Worklist;

defined( 'ABSPATH' ) || exit;

final class Events {

	/** The payload contract's version. Bump when any event's data shape changes. */
	const VERSION = 1;

	/* -- Event names -------------------------------------------------------- */

	/** A finding the owner has not been shown before appeared on the front door. */
	const FINDING_OPENED = 'finding_opened';

	/** The weekly digest went out; the payload carries its summary numbers. */
	const DIGEST_SENT = 'digest_sent';

	/** A client claiming a verified bot's name conclusively failed its operator's check. */
	const IMPOSTOR_FLAGGED = 'impostor_flagged';

	/** robots.txt's policy lines moved — the owner's own edit, or another plugin's. */
	const ROBOTS_CHANGED = 'robots_policy_changed';

	/** A citation monitoring run completed; the payload carries its counts. */
	const CITATION_RUN_FINISHED = 'citation_run_finished';

	/** An agent (or the in-admin assistant) created or updated content. */
	const AGENT_WROTE_CONTENT = 'agent_wrote_content';

	/* -- The findings cron -------------------------------------------------- */

	/** Daily cron that diffs the computed findings against the last-seen set. */
	const CRON_FINDINGS = 'agentimus_integrations_findings';

	/** Option: identities of every finding already announced (or seeded). Not autoloaded. */
	const FINDINGS_SEEN_OPTION = 'agentimus_integrations_findings_seen';

	/** Ceiling on remembered finding identities — the list can only ever hold
	 *  what Findings::all() ships (a couple of dozen), so this is a corruption
	 *  guard, not a working limit. */
	const FINDINGS_SEEN_MAX = 100;

	/** Option: recently announced impostor clients ({ client → unix }). Not autoloaded. */
	const IMPOSTOR_SEEN_OPTION = 'agentimus_integrations_impostor_seen';

	/** One announcement per impostor client per this many seconds. The review
	 *  queue reports every hit; a webhook that rang per HIT would ring hundreds
	 *  of times for one forged crawler's crawl. */
	const IMPOSTOR_QUIET = DAY_IN_SECONDS;

	/** @var Settings */
	private $settings;

	/** @var string The ability currently executing, captured from the Abilities
	 *  API's own announce hook — so agent_wrote_content can name WHICH tool
	 *  wrote. '' when no ability is in flight (the in-admin assistant's writes
	 *  reach ContentWriter without one). */
	private static $ability = '';

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The event catalog: name → { label, description }, in the order the
	 * settings panel offers them. Labels are the checkbox copy; descriptions
	 * are the one line under it.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function catalog() {
		$catalog = array(
			self::FINDING_OPENED        => array(
				'label'       => __( 'New finding', 'agentimus' ),
				'description' => __( 'Something new needs your attention — checked once a day.', 'agentimus' ),
			),
			self::DIGEST_SENT           => array(
				'label'       => __( 'Weekly digest sent', 'agentimus' ),
				'description' => __( 'The weekly email went out; this carries its numbers.', 'agentimus' ),
			),
			self::IMPOSTOR_FLAGGED      => array(
				'label'       => __( 'Impostor caught', 'agentimus' ),
				'description' => __( 'A client pretending to be a known crawler failed its check.', 'agentimus' ),
			),
			self::ROBOTS_CHANGED        => array(
				'label'       => __( 'Robots policy changed', 'agentimus' ),
				'description' => __( 'The rules in robots.txt moved — by you, or by another plugin.', 'agentimus' ),
			),
			self::CITATION_RUN_FINISHED => array(
				'label'       => __( 'Citation check finished', 'agentimus' ),
				'description' => __( 'A citation monitoring run completed, with its counts.', 'agentimus' ),
			),
			self::AGENT_WROTE_CONTENT   => array(
				'label'       => __( 'Agent wrote content', 'agentimus' ),
				'description' => __( 'An AI assistant created or edited a post or page.', 'agentimus' ),
			),
		);

		/**
		 * Filter the integration event catalog.
		 *
		 * An add-on that emits its own moments can register them here so the
		 * settings checkboxes and the sanitiser both know the name. Entries
		 * must be name → { label, description }; the six built-ins cannot be
		 * removed (a subscriber's checkbox must never vanish under it).
		 *
		 * @param array $catalog Event name → { label, description }.
		 */
		$filtered = apply_filters( 'agentimus_integration_events', $catalog );
		if ( ! is_array( $filtered ) ) {
			return $catalog;
		}
		// The built-ins always survive — a filter may add, never subtract.
		foreach ( $catalog as $name => $row ) {
			if ( ! isset( $filtered[ $name ] ) || ! is_array( $filtered[ $name ] ) ) {
				$filtered[ $name ] = $row;
			}
		}
		return $filtered;
	}

	/**
	 * Every valid event name — what the settings sanitiser intersects against.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array_keys( self::catalog() );
	}

	/**
	 * The versioned wire envelope every delivery wears.
	 *
	 * @param string $event Event name.
	 * @param array  $data  The event's payload (already contract-shaped).
	 * @return array{event:string,version:int,site:string,at:int,data:array}
	 */
	public static function envelope( $event, array $data ) {
		return array(
			'event'   => (string) $event,
			'version' => self::VERSION,
			'site'    => home_url( '/' ),
			'at'      => time(),
			'data'    => $data,
		);
	}

	/* -- Registration ------------------------------------------------------- */

	/**
	 * Arm the listeners — or stand down entirely.
	 *
	 * Disconnected means NOT ONE hook is added (beyond clearing a stale
	 * findings cron a disconnect left behind, which is a removal, not a hook).
	 * The return value says which way it went, so a test can hold the promise.
	 *
	 * @return bool True when the listeners were armed.
	 */
	public function register() {
		if ( ! Services::any_connected( $this->settings ) ) {
			// Bidirectional self-heal, like Digest\Module: a disconnect (or a
			// settings restore) must take its scheduled work with it.
			if ( wp_next_scheduled( self::CRON_FINDINGS ) ) {
				wp_clear_scheduled_hook( self::CRON_FINDINGS );
			}
			return false;
		}

		add_action( 'agentimus_digest_sent', array( $this, 'on_digest_sent' ) );
		add_action( 'agentimus_robots_policy_changed', array( $this, 'on_robots_changed' ) );
		add_action( 'agentimus_impostor_flagged', array( $this, 'on_impostor_flagged' ) );
		add_action( 'agentimus_citation_run_finished', array( $this, 'on_citation_run_finished' ) );
		add_action( 'agentimus_agent_wrote_content', array( $this, 'on_agent_wrote_content' ), 10, 2 );
		// The Abilities API announces an execution before it runs; remember the
		// name so a write that lands moments later can say which tool made it.
		add_action( 'wp_before_execute_ability', array( __CLASS__, 'note_ability' ) );

		add_action( self::CRON_FINDINGS, array( $this, 'findings_tick' ) );
		$this->sync_findings_schedule();

		return true;
	}

	/**
	 * Make the findings cron agree with the configuration: scheduled daily when
	 * the finding_opened box is ticked, absent otherwise. Cheap (one
	 * wp_next_scheduled lookup), safe on every boot.
	 */
	public function sync_findings_schedule() {
		$wanted = self::wants_findings_cron( $this->settings );
		$next   = wp_next_scheduled( self::CRON_FINDINGS );
		if ( ! $wanted ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_FINDINGS );
			}
			return;
		}
		if ( ! $next ) {
			// First tick an hour out, then daily. The first tick only seeds the
			// baseline, so there is no hurry — and an hour keeps "connect, then
			// immediately reconfigure" from paying for two Findings builds.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_FINDINGS );
		}
	}

	/**
	 * Whether the daily findings diff should run at all. Pure decision, so a
	 * test can hold it without a scheduler: the cron computes readiness + the
	 * score + the search report, and that price is only worth paying when
	 * someone subscribed to the answer.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function wants_findings_cron( Settings $settings ) {
		foreach ( Services::all() as $class ) {
			if ( $class::wants( $settings, self::FINDING_OPENED ) ) {
				return true;
			}
		}
		return false;
	}

	/* -- Payload builders (the contract, one per event) ---------------------- */

	/**
	 * finding_opened: the finding as the front door states it, plus where to act.
	 *
	 * @param array $row A Findings::all() row.
	 * @return array{id:string,tier:string,title:string,why:string,url:string}
	 */
	public static function finding_payload( array $row ) {
		return array(
			'id'    => self::finding_identity( $row ),
			'tier'  => isset( $row['tier'] ) ? (string) $row['tier'] : '',
			'title' => isset( $row['title'] ) ? (string) $row['title'] : '',
			'why'   => isset( $row['why'] ) ? (string) $row['why'] : '',
			'url'   => admin_url( 'admin.php?page=agentimus#findings' ),
		);
	}

	/**
	 * digest_sent: the digest's own summary numbers, nothing behind them.
	 *
	 * @param array $data The collected digest data (Digest\Data::collect()).
	 * @return array
	 */
	public static function digest_payload( array $data ) {
		return array(
			'period'    => array(
				'label' => isset( $data['period']['label'] ) ? (string) $data['period']['label'] : '',
				'days'  => isset( $data['period']['days'] ) ? (int) $data['period']['days'] : 0,
			),
			'agents'    => isset( $data['agents']['total'] ) ? (int) $data['agents']['total'] : 0,
			'referrals' => isset( $data['referrals']['total'] ) ? (int) $data['referrals']['total'] : 0,
			'impostors' => isset( $data['impostors']['total'] ) ? (int) $data['impostors']['total'] : 0,
			'score'     => array(
				'now'  => isset( $data['score']['now'] ) && null !== $data['score']['now'] ? (int) $data['score']['now'] : null,
				'prev' => isset( $data['score']['prev'] ) && null !== $data['score']['prev'] ? (int) $data['score']['prev'] : null,
			),
		);
	}

	/**
	 * impostor_flagged: the client's NAME — the user-agent token that lied —
	 * and deliberately nothing else. No IP ever leaves the site.
	 *
	 * @param string $client Client name (recognised agent label, or the UA).
	 * @return array{client:string}
	 */
	public static function impostor_payload( $client ) {
		return array(
			'client' => substr( trim( (string) $client ), 0, 200 ),
		);
	}

	/**
	 * robots_policy_changed: the moved policy lines, clipped the same way the
	 * findings clip evidence — shown up to the cap, the rest counted out loud.
	 *
	 * @param array $change RobotsWatch change event ({ at, added, removed }).
	 * @return array{added:array<int,string>,removed:array<int,string>,at:int}
	 */
	public static function robots_payload( array $change ) {
		return array(
			'added'   => self::clip_lines( isset( $change['added'] ) ? (array) $change['added'] : array() ),
			'removed' => self::clip_lines( isset( $change['removed'] ) ? (array) $change['removed'] : array() ),
			'at'      => isset( $change['at'] ) ? (int) $change['at'] : time(),
		);
	}

	/**
	 * citation_run_finished: the run's summary counts, as the runner reported them.
	 *
	 * @param array $result Visibility\Runner run result.
	 * @return array{runId:int,checks:int,capped:bool,trigger:string}
	 */
	public static function citation_payload( array $result ) {
		return array(
			'runId'   => isset( $result['runId'] ) ? (int) $result['runId'] : 0,
			'checks'  => isset( $result['checks'] ) ? (int) $result['checks'] : 0,
			'capped'  => ! empty( $result['capped'] ),
			// Always 'scheduled' on a delivered event today, but carried anyway:
			// a webhook's own automation may want to know, and a payload that
			// drops the field would make a later change to this rule invisible.
			'trigger' => isset( $result['trigger'] ) && 'manual' === $result['trigger'] ? 'manual' : 'scheduled',
		);
	}

	/**
	 * agent_wrote_content: which post, what happened, and which tool did it.
	 *
	 * @param int    $post_id Written post.
	 * @param string $action  'create' or 'update'.
	 * @param string $ability The executing ability's name, '' when none was in
	 *                        flight (the in-admin assistant's route).
	 * @return array{postId:int,title:string,action:string,ability:string}
	 */
	public static function wrote_payload( $post_id, $action, $ability ) {
		return array(
			'postId'  => (int) $post_id,
			'title'   => Worklist::title_of( (int) $post_id ),
			'action'  => 'update' === (string) $action ? 'update' : 'create',
			'ability' => substr( (string) $ability, 0, 100 ),
		);
	}

	/* -- Listeners ----------------------------------------------------------- */

	/**
	 * Digest went out (real sends only; Digest\Module skips the seam on tests).
	 *
	 * @param array $data The collected digest data.
	 */
	public function on_digest_sent( $data ) {
		if ( is_array( $data ) ) {
			$this->emit( self::DIGEST_SENT, self::digest_payload( $data ) );
		}
	}

	/**
	 * robots.txt's policy lines moved.
	 *
	 * @param array $change RobotsWatch change event.
	 */
	public function on_robots_changed( $change ) {
		if ( is_array( $change ) ) {
			$this->emit( self::ROBOTS_CHANGED, self::robots_payload( $change ) );
		}
	}

	/**
	 * An impostor was caught on the serve path. Announce a CLIENT once per
	 * quiet period, not a hit — the recorder fires per request, and a forged
	 * crawler's crawl is one fact, not four hundred.
	 *
	 * @param string $client Client name (recognised label or UA).
	 */
	public function on_impostor_flagged( $client ) {
		$client = substr( trim( (string) $client ), 0, 200 );
		if ( '' === $client ) {
			return;
		}

		$seen = get_option( self::IMPOSTOR_SEEN_OPTION, array() );
		$seen = is_array( $seen ) ? $seen : array();
		$key  = strtolower( $client );
		$now  = time();
		if ( isset( $seen[ $key ] ) && ( $now - (int) $seen[ $key ] ) < self::IMPOSTOR_QUIET ) {
			return; // Already announced within the quiet period.
		}

		if ( ! $this->emit( self::IMPOSTOR_FLAGGED, self::impostor_payload( $client ) ) ) {
			return; // Not queued (box unticked, queue refused) — don't mark it told.
		}

		// Remember, and shed entries old enough to be re-announceable anyway,
		// so a parade of one-off UAs can't grow this option forever.
		$seen[ $key ] = $now;
		foreach ( $seen as $k => $at ) {
			if ( ( $now - (int) $at ) >= self::IMPOSTOR_QUIET ) {
				unset( $seen[ $k ] );
			}
		}
		update_option( self::IMPOSTOR_SEEN_OPTION, $seen, false );
	}

	/**
	 * A citation run completed.
	 *
	 * ⛔ A run the owner STARTED is not announced. His catch, 2026-08-25: he
	 * pressed "Run check now", watched the screen fill in, and Telegram told him
	 * it had finished — news to nobody. These channels exist to carry what
	 * happened while you were not looking, which is the same reasoning that took
	 * the review-queue row off Findings once the bell already carried it.
	 * ⭐ The HOOK still fires for every run, with `trigger` on it, so a listener
	 * of one's own can have them all.
	 *
	 * @param array $result Runner result.
	 */
	public function on_citation_run_finished( $result ) {
		if ( ! is_array( $result ) || empty( $result['ran'] ) ) {
			return;
		}
		if ( 'manual' === ( isset( $result['trigger'] ) ? $result['trigger'] : '' ) ) {
			return;
		}
		$this->emit( self::CITATION_RUN_FINISHED, self::citation_payload( $result ) );
	}

	/**
	 * ContentWriter finished a create/update.
	 *
	 * @param int    $post_id Written post.
	 * @param string $action  'create' or 'update'.
	 */
	public function on_agent_wrote_content( $post_id, $action ) {
		$this->emit( self::AGENT_WROTE_CONTENT, self::wrote_payload( (int) $post_id, (string) $action, self::$ability ) );
	}

	/**
	 * Remember which ability is executing (the Abilities API announces it just
	 * before the callback runs, and requests execute one ability at a time).
	 *
	 * @param string $ability_name Executing ability.
	 */
	public static function note_ability( $ability_name ) {
		self::$ability = (string) $ability_name;
	}

	/* -- The findings diff --------------------------------------------------- */

	/**
	 * Daily: compute the front door's findings, announce the ones that were not
	 * there last time, move the baseline forward. First run seeds silently.
	 */
	public function findings_tick() {
		if ( ! self::wants_findings_cron( $this->settings ) ) {
			return; // The schedule self-heals on the next boot; refuse now.
		}

		$payload = ( new Findings( $this->settings ) )->all();
		$rows    = isset( $payload['findings'] ) ? (array) $payload['findings'] : array();
		$seen    = get_option( self::FINDINGS_SEEN_OPTION, null );

		if ( ! is_array( $seen ) ) {
			// First look remembers silently (the RobotsWatch law): connecting a
			// webhook must not replay everything that was already true.
			update_option( self::FINDINGS_SEEN_OPTION, self::identities( $rows ), false );
			return;
		}

		foreach ( self::fresh( $rows, $seen ) as $row ) {
			$this->emit( self::FINDING_OPENED, self::finding_payload( $row ) );
		}

		// The stored set mirrors what is open NOW — a finding that closed and
		// later reopens is news again, exactly as the front door treats it.
		update_option( self::FINDINGS_SEEN_OPTION, self::identities( $rows ), false );
	}

	/**
	 * The identity of one finding row — stable across recomputes.
	 *
	 * Most rows carry a stable id already. Config gaps all share the id
	 * 'config_gap' and differ by the check that failed, so the check joins the
	 * identity — otherwise the second failing check would never be news.
	 *
	 * @param array $row Findings row.
	 * @return string
	 */
	public static function finding_identity( array $row ) {
		$id = isset( $row['id'] ) ? (string) $row['id'] : '';
		if ( isset( $row['check'] ) && '' !== (string) $row['check'] ) {
			$id .= ':' . (string) $row['check'];
		}
		return $id;
	}

	/**
	 * Every identity in a findings list. Pure; bounded.
	 *
	 * @param array $rows Findings rows.
	 * @return string[]
	 */
	public static function identities( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$id = self::finding_identity( $row );
				if ( '' !== $id && ! in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
			}
		}
		return array_slice( $out, 0, self::FINDINGS_SEEN_MAX );
	}

	/**
	 * The rows whose identity is not in the last-seen set. Pure.
	 *
	 * Waiting rows are excluded on top of the diff: they are the tier built for
	 * work that does NOT exist, and a webhook ringing "new finding" for a page
	 * whose owner-side work is finished would be the exact contradiction that
	 * tier was invented to remove.
	 *
	 * @param array $rows Findings rows.
	 * @param array $seen Previously announced identities.
	 * @return array<int,array>
	 */
	public static function fresh( array $rows, array $seen ) {
		$seen = array_map( 'strval', $seen );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['tier'] ) && Findings::WAITING === $row['tier'] ) {
				continue;
			}
			$id = self::finding_identity( $row );
			if ( '' !== $id && ! in_array( $id, $seen, true ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/* -- Internals ----------------------------------------------------------- */

	/**
	 * Hand one event to the queue — one row per connected service that
	 * subscribed to it (and whose own payload gate accepts it).
	 *
	 * @param string $event Event name.
	 * @param array  $data  Contract-shaped payload.
	 * @return bool Whether the event was queued for at least one service.
	 */
	private function emit( $event, array $data ) {
		$ids = Services::wanting( $this->settings, $event, $data );
		if ( array() === $ids ) {
			return false;
		}

		$envelope   = self::envelope( $event, $data );
		$dispatcher = new Dispatcher( $this->settings );
		$queued     = false;
		foreach ( $ids as $id ) {
			if ( $dispatcher->enqueue( $event, $envelope, $id ) ) {
				$queued = true;
			}
		}
		return $queued;
	}

	/**
	 * Clip a changed-lines list the way findings clip evidence: the first few
	 * shown whole, the rest counted — a cut that announces itself.
	 *
	 * @param array $lines Normalized robots lines.
	 * @param int   $max   How many to keep before counting.
	 * @return array<int,string>
	 */
	private static function clip_lines( array $lines, $max = 20 ) {
		$all  = array_values( array_map( 'strval', $lines ) );
		$kept = array_slice( $all, 0, $max );
		$rest = count( $all ) - count( $kept );
		if ( $rest > 0 ) {
			/* translators: %d: how many further changed lines exist beyond the ones listed. */
			$kept[] = sprintf( __( '+%d more', 'agentimus' ), $rest );
		}
		return $kept;
	}
}
