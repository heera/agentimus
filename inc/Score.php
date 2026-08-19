<?php
/**
 * AEO/GEO Score — the unifying number and the ranked "do this next" plan. It fuses
 * ONE extended ladder into a single 0–100 read on how ready a site is to be found,
 * understood, and cited by AI answer engines, then collects every gap into one
 * impact-ranked action list. The first three rungs ARE the original readiness ladder
 * (the "agent-ready" milestone); the last two extend it into AEO/GEO:
 *
 *   Findable  (15) — an agent can reach and crawl you   ┐
 *   Readable  (15) — content comes back clean            ├ the readiness ladder
 *   Trusted   (25) — it can identify & trust the source ┘  (= "agent-ready")
 *   Optimized (30) — content written to be cited: the per-page {@see PageCheck}
 *                    signals, averaged over EVERY published article-like page,
 *                    read back from the {@see Grades} store the sweep fills.
 *                    ⚠️ It was a 25-post recency sample until 2026-08-18; the
 *                    number moved when it stopped being one, and it should have.
 *   Cited     (15) — measured citation: the AI-Visibility "seen in answers" rate.
 *
 * A pillar with no data yet (no published posts to grade; citation checks not set up)
 * is EXCLUDED and its weight redistributed across the rest — a well-configured new
 * site is never penalised for an outcome it hasn't had the chance to produce. If the
 * site is hidden from crawlers ("Search engine visibility" fails) the score is floored
 * and flagged blocked, mirroring the readiness ladder's "not reachable" state.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Score {

	/** Pillar weights (AEO/GEO plan, Option A). They need not sum to 100 — the blend
	 *  normalises over whichever pillars have data. */
	const WEIGHTS = array(
		'findable'  => 15,
		'readable'  => 15,
		'trusted'   => 25,
		'optimized' => 30,
		'cited'     => 15,
	);

	/** The three readiness rungs — mirrored from the admin's tiers.js so the score and
	 *  the ladder never disagree. The last two rungs (optimized/cited) are computed from
	 *  content and citations, not from these checks. */
	const FINDABLE_IDS = array( 'public', 'permalinks', 'robots', 'sitemap', 'robots_sitemap' );
	const READABLE_IDS = array( 'llms', 'llms_words', 'llms_full', 'llms_full_size', 'schema', 'post_types', 'topics' );
	const TRUSTED_IDS  = array( 'about', 'expertise', 'same_as', 'entity_image', 'entity_role', 'security_txt', 'ai_usage' );

	/** Cap on affected pages listed per issue in the content worklist. */
	const WORKLIST_POSTS_PER_ISSUE = 6;

	/** A Cited reading older than this (days) is a dated reference only — not counted. */
	const CITED_STALE_DAYS = 90;

	/** Cap on the number of ranked actions returned. */
	const MAX_ACTIONS = 8;

	/** @var Settings */
	private $settings;

	/** @var array<int,string[]> Per-request memo for {@see page_flags()} — each answer parses a post. */
	private $flags_memo = array();

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The citability labels ONE page trips — the same checks, gates and wording
	 * the Optimize worklist uses, answered for a single page instead of the
	 * sampled average. Empty when the page passes everything, and equally empty
	 * when the page isn't gradeable at all (a container, a commerce page, a
	 * set-aside) — a caller must never flag what the worklist itself would
	 * excuse. This is what frees the search worklist's cross-flag from the
	 * recency sample: whether a page's problems show must not depend on when
	 * it was last edited.
	 *
	 * @param int $post_id The page.
	 * @return string[] Failed-check labels, in check order.
	 */
	public function page_flags( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( $this->flags_memo[ $post_id ] ) ) {
			return $this->flags_memo[ $post_id ];
		}
		$flags = array();
		$post  = $post_id > 0 ? get_post( $post_id ) : null;
		if ( $post && 'publish' === $post->post_status
			&& in_array( (string) $post->post_type, Gradeability::post_types(), true )
			&& Gradeability::is_gradeable( $post, $this->ignored_ids() ) ) {
			$flags = PageCheck::flags( $post );
		}
		$this->flags_memo[ $post_id ] = $flags;
		return $flags;
	}

	/**
	 * The full report: the blended score, each pillar, and the ranked action plan.
	 *
	 * @param array<int,array<string,mixed>>|null $readiness Precomputed readiness rows
	 *        (the admin boot already runs the report — pass it so it isn't run twice).
	 * @return array{score:int,band:string,blocked:bool,ready:bool,measured:bool,rungs:array,actions:array}
	 */
	public function report( $readiness = null ) {
		$readiness = is_array( $readiness ) ? $readiness : ( new Readiness( $this->settings ) )->report();
		$optimize  = $this->optimize();
		// Citation checks are opt-in. When off, the Cited rung is dropped
		// entirely — no measurement, no rung — and its weight is redistributed across the
		// rest, so the score is a clean four-rung ladder.
		$track   = (bool) $this->settings->get( 'enable_visibility', false );
		$measure = $track ? $this->measure() : array( 'score' => null, 'note' => '', 'state' => 'off', 'off' => true );

		$scores = array(
			'findable'  => self::rows_score( $readiness, self::FINDABLE_IDS ),
			'readable'  => self::rows_score( $readiness, self::READABLE_IDS ),
			'trusted'   => self::rows_score( $readiness, self::TRUSTED_IDS ),
			'optimized' => $optimize['score'],
			'cited'     => $measure['score'],
		);

		$blocked = self::has_fail( $readiness, 'public' );
		$score   = self::blend( $scores );
		if ( $blocked ) {
			$score = min( $score, 10 ); // Hidden from crawlers → nothing else can help yet.
		}
		// "Agent-ready" is the original readiness milestone: the three readiness rungs
		// all pass (a warn or fail on any keeps the score below 100 there).
		$ready = ! $blocked && 100 === (int) $scores['findable'] && 100 === (int) $scores['readable'] && 100 === (int) $scores['trusted'];

		$rungs = array(
			$this->check_rung( 'findable', __( 'Findable', 'agentimus' ), $scores['findable'], $readiness, self::FINDABLE_IDS, __( 'An agent can reach and crawl your site.', 'agentimus' ) ),
			$this->check_rung( 'readable', __( 'Readable', 'agentimus' ), $scores['readable'], $readiness, self::READABLE_IDS, __( 'What it crawls comes back clean and structured.', 'agentimus' ) ),
			$this->check_rung( 'trusted', __( 'Trusted', 'agentimus' ), $scores['trusted'], $readiness, self::TRUSTED_IDS, __( 'It can identify you and trust the source.', 'agentimus' ) ),
			$this->signal_rung( 'optimized', __( 'Optimized', 'agentimus' ), $scores['optimized'], $this->optimize_note( $optimize ), '' ),
		);
		if ( $track ) {
			// Route the Cited rung to the right Visibility sub-view: Settings when the
			// setup isn't complete enough to run a check (no engine key / no questions),
			// otherwise Results.
			$ready_to_run = '' === ( new Visibility\Runner( new Visibility\Settings() ) )->blocking_reason();
			$rungs[]      = $this->signal_rung( 'cited', __( 'Cited', 'agentimus' ), $scores['cited'], $measure['note'], 'visibility', $ready_to_run ? 'results' : 'settings' );
		}

		return array(
			'score'      => $score,
			'band'       => self::band( $blocked ? 0 : $score ),
			'blocked'    => $blocked,
			'ready'      => $ready,
			'measured'   => $track && null !== $measure['score'],
			'rungs'      => $rungs,
			'actions'    => $this->actions( $readiness, $optimize, $measure ),
			'content'    => $this->content_worklist( $optimize ),
			'graded'     => (int) $optimize['posts'],
			// ⭐ How many of the `graded` actually have something wrong. Without
			// it a screen can only reach for the largest issue group — which is
			// the FLOOR of that number, and was printed as its ceiling ("up to
			// 65 of your 103" on a site where 84 pages carried a flag).
			// ⛔ Not derivable from `content`: the groups overlap, so their sum
			// counts a page once per issue and their maximum counts it once.
			'flagged'    => (int) ( isset( $optimize['flagged'] ) ? $optimize['flagged'] : 0 ),
			// ⚠️ How many published pages the sweep has NOT read yet. Without it a
			// screen cannot tell "every page is clean" from "we have only looked
			// at some of them" — and now that `graded` counts the whole site
			// rather than a deliberate sample, that difference is the only thing
			// standing between an honest all-clear and an early one.
			// ⛔ Taken from the SAME cached snapshot as `graded`, never re-read
			// here: two numbers describing one moment must be measured at it.
			'grading'    => (int) ( isset( $optimize['grading'] ) ? $optimize['grading'] : 0 ),
			// ⚠️ How many of the `graded` pages were edited after they were read.
			// They keep their place in `content` — a verdict that is out of date is
			// still the last thing anybody actually measured, and dropping those
			// rows made a page look mended the moment one of its issues was fixed.
			// This is the number that stops the rest of the card reading as today's.
			'rechecking' => (int) ( isset( $optimize['rechecking'] ) ? $optimize['rechecking'] : 0 ),
			'ignored'    => $this->ignored_list(),
			// ⭐ WHAT was graded, in the site's own words — and what was checked
			// but deliberately not graded.
			//
			// The checking scope can now be wider than the gradeable one: a store
			// checks its products for the search they are found for, and grades
			// none of them for quoting, because a product page is short by design
			// and "this is thin" is bad advice on one. Both facts are true and
			// they sit on adjacent screens, so the card has to SAY so — a count
			// of 75 next to a content list holding 400 products, with nothing
			// explaining the difference, is the same contradiction this whole
			// change exists to remove. An all-clear also can't name "posts and
			// pages" on a site that graded four kinds of thing.
			'scope'      => $this->optimize_scope(),
		);
	}

	/**
	 * The names behind the Optimize card's numbers: the types it grades, and the
	 * types it checks without grading.
	 *
	 * @return array{graded:array<int,string>,notGraded:array<int,string>}
	 */
	private function optimize_scope() {
		$checked = Content::check_post_types();
		$off     = empty( $checked );
		// While checking is off the numbers describe the LAST reading, so the
		// names have to describe that same reading — not an empty list, which
		// would read as "nothing was ever graded" over a card full of grades.
		$graded  = $off ? Gradeability::last_known_post_types() : Gradeability::post_types();

		return array(
			'graded'    => array_values( array_map( array( Content::class, 'label' ), $graded ) ),
			'notGraded' => array_values( array_map( array( Content::class, 'label' ), array_diff( $checked, $graded ) ) ),
			// ⚠️ An explicit flag, not "both lists are empty". Since the lists now
			// stay populated while checking is off, emptiness no longer implies
			// anything — and a screen that has to infer this fact would infer it
			// wrongly the moment either list changes shape again.
			'off'       => $off,
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Pillar scoring — pure where it can be.
	 * ---------------------------------------------------------------------- */

	/**
	 * Score a subset of readiness rows: pass = 1, warn = 0.5, fail = 0, as a 0–100
	 * average. Null when none of the ids are present (nothing to score). Pure.
	 *
	 * 'off' rows are left out of BOTH sides of the fraction. Counting one as a
	 * pass paid a full point for a switched-off feature (100 is earned, never
	 * rounded into); counting it as anything else would fine the owner twice,
	 * because the feature's own warn row already carries the cost of the switch.
	 * A rung somehow all-off ends up with $n = 0 and returns null — excluded
	 * from the blend, never a division by zero.
	 *
	 * @param array<int,array<string,mixed>> $rows Readiness rows.
	 * @param array<int,string>              $ids  Ids that belong to this pillar.
	 * @return int|null
	 */
	public static function rows_score( array $rows, array $ids ) {
		$pts = 0.0;
		$n   = 0;
		foreach ( $rows as $r ) {
			if ( ! isset( $r['id'] ) || ! in_array( $r['id'], $ids, true ) ) {
				continue;
			}
			if ( isset( $r['status'] ) && 'off' === $r['status'] ) {
				continue;
			}
			++$n;
			$pts += self::points( isset( $r['status'] ) ? (string) $r['status'] : 'warn' );
		}
		return $n > 0 ? (int) round( $pts / $n * 100 ) : null;
	}

	/**
	 * Blend the pillars into one 0–100, normalising the weights over only the pillars
	 * that have data (null = excluded, its weight redistributed). Pure.
	 *
	 * @param array<string,int|null> $pillars pillar key => 0–100 or null.
	 * @return int
	 */
	public static function blend( array $pillars ) {
		$acc     = 0.0;
		$total_w = 0;
		foreach ( $pillars as $key => $val ) {
			if ( null === $val || ! isset( self::WEIGHTS[ $key ] ) ) {
				continue;
			}
			$w        = self::WEIGHTS[ $key ];
			$acc     += (float) $val * $w;
			$total_w += $w;
		}
		if ( $total_w <= 0 ) {
			return 0;
		}
		$avg = $acc / $total_w;
		// 100 is EARNED, never rounded into. Three perfect pillars and a 99
		// blend to 99.65, which round() proudly called 100 — on a card whose
		// own rung column said "4 to fix" two lines below. A composite may
		// only read 100 when every pillar it counted actually is.
		return ( $avg < 100 ) ? min( 99, (int) round( $avg ) ) : 100;
	}

	/**
	 * A plain-English band for a score. Pure.
	 *
	 * @param int $score 0–100.
	 * @return string
	 */
	public static function band( $score ) {
		if ( $score >= 85 ) {
			return __( 'Excellent', 'agentimus' );
		}
		if ( $score >= 70 ) {
			return __( 'Strong', 'agentimus' );
		}
		if ( $score >= 50 ) {
			return __( 'Fair', 'agentimus' );
		}
		return __( 'Needs work', 'agentimus' );
	}

	/** pass/warn/fail → points. Pure. */
	private static function points( $status ) {
		if ( 'pass' === $status ) {
			return 1.0;
		}
		return 'warn' === $status ? 0.5 : 0.0;
	}

	/** Whether a specific check id is present and failing. Pure. */
	private static function has_fail( array $rows, $id ) {
		foreach ( $rows as $r ) {
			if ( isset( $r['id'], $r['status'] ) && $id === $r['id'] && 'fail' === $r['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A readiness rung (findable/readable/trusted) — carries its pass/total check tally
	 * and links to the Readiness report where those checks live.
	 */
	private function check_rung( $key, $label, $score, array $readiness, array $ids, $note ) {
		$tally = self::rows_tally( $readiness, $ids );
		return array(
			'key'    => $key,
			'label'  => $label,
			'score'  => $score,
			'weight' => self::WEIGHTS[ $key ],
			'kind'   => 'check',
			'pass'   => $tally['pass'],
			'total'  => $tally['total'],
			'note'   => $note,
			'to'     => 'readiness',
		);
	}

	/**
	 * An AEO/GEO rung (optimized/cited) — a measured signal, not a pass/total of checks.
	 * `to` is the tab that improves it ('' for optimized, which is per-post).
	 */
	private function signal_rung( $key, $label, $score, $note, $to, $view = '' ) {
		return array(
			'key'    => $key,
			'label'  => $label,
			'score'  => $score, // int|null (null = not measured/no content yet)
			'weight' => self::WEIGHTS[ $key ],
			'kind'   => 'signal',
			'pass'   => null,
			'total'  => null,
			'note'   => $note,
			'to'     => $to,
			'view'   => $view, // For a 'visibility' rung: which sub-view to open ('settings' | 'results').
		);
	}

	/**
	 * Count passing vs total checks for a rung's ids. Pure.
	 *
	 * 'off' rows stay out of the total, mirroring rows_score(): a rung scoring
	 * 100 must also read "6/6", and the rail's "N to fix" chip is total minus
	 * pass — an off row in the total would wear that chip as work to do, when
	 * there is nothing to do here (the feature's warn row holds the work).
	 *
	 * @param array<int,array<string,mixed>> $rows Readiness rows.
	 * @param array<int,string>              $ids  Ids in this rung.
	 * @return array{pass:int,total:int}
	 */
	private static function rows_tally( array $rows, array $ids ) {
		$pass  = 0;
		$total = 0;
		foreach ( $rows as $r ) {
			if ( ! isset( $r['id'] ) || ! in_array( $r['id'], $ids, true ) ) {
				continue;
			}
			if ( isset( $r['status'] ) && 'off' === $r['status'] ) {
				continue;
			}
			++$total;
			if ( isset( $r['status'] ) && 'pass' === $r['status'] ) {
				++$pass;
			}
		}
		return array( 'pass' => $pass, 'total' => $total );
	}

	/** The rung a readiness check id belongs to, for action ranking. Pure. */
	private static function rung_of( $id ) {
		if ( in_array( $id, self::TRUSTED_IDS, true ) ) {
			return 'trusted';
		}
		if ( in_array( $id, self::READABLE_IDS, true ) ) {
			return 'readable';
		}
		return 'findable';
	}

	/* ---------------------------------------------------------------------- *
	 *  Optimize — per-page citability across a cached sample of posts.
	 * ---------------------------------------------------------------------- */

	/**
	 * Average per-page pass-rate over a sample of recent posts, plus a tally of which
	 * content checks most often need attention (for the action plan). Cached — each
	 * post parse is not cheap; busted with the rest on a content change.
	 *
	 * @return array{score:int|null,posts:int,issues:array<string,array{count:int,label:string}>}
	 */
	private function optimize() {
		$cached = Cache::get( Cache::OPTIMIZE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = $this->compute_optimize();
		Cache::set( Cache::OPTIMIZE, $result );
		return $result;
	}

	/**
	 * ⭐ READ FROM THE GRADE STORE, over the WHOLE site — not computed here over
	 * a sample.
	 *
	 * This used to parse the 25 most recently modified posts inside whatever
	 * request asked for the score, because that was the only affordable shape
	 * before the grade table existed. The cost of that shape was not speed, it
	 * was TRUTH: the Readiness card said "every graded post and page is ready
	 * for AI to quote" about eighteen items, on a site where twelve pages
	 * carried content flags, directly above a list that said so.
	 *
	 * The sweep already runs exactly these checks on every published page and
	 * stores the result. Reading it back makes the pillar mean "this site"
	 * rather than "this site's last 25 edits", and costs one indexed query.
	 *
	 * ⚠️ `score => null` when the sweep has read nothing yet — N/A, so blend()
	 * redistributes the weight rather than scoring the site zero for pages
	 * nobody has looked at. Same answer a commerce-only site gets, and for the
	 * same reason: no article-like content to grade is not a bad grade.
	 *
	 * @return array{score:int|null,posts:int,issues:array<string,array>}
	 */
	private function compute_optimize() {
		// ⭐⭐ The owner has switched every content type off. The pillar must NOT
		// simply go N/A here: blend() redistributes an N/A pillar's weight, so a
		// site could RAISE its score by declining to be looked at — the one
		// incentive this plugin must never create. Instead the store is read back
		// over everything this site could check, which returns the grades of the
		// last real sweep: the score neither rises nor is punished for a decision
		// about what to read NEXT, and the card says it is an older reading.
		$off   = empty( Content::check_post_types() );
		$types = $off ? Gradeability::last_known_post_types() : Gradeability::post_types();
		if ( empty( $types ) ) {
			return array( 'score' => null, 'posts' => 0, 'flagged' => 0, 'issues' => array(), 'grading' => 0, 'rechecking' => 0, 'pending' => array() );
		}

		// ⚠️ Read in the SAME breath as the grades themselves, and cached with
		// them. `grading` used to be taken fresh in report() while `posts` came
		// from this cached payload, so mid-sweep the card could say "11 graded ·
		// 0 still to read" when 64 were graded — two true numbers from two
		// different instants, which is a contradiction on screen and the exact
		// species of bug this whole change exists to remove.
		//
		// ⛔ Zero while checking is off, never the real backlog: "still to read"
		// is a promise that the sweep is coming, and with every type switched off
		// it is not. A number that counts work nobody will do is the same lie as
		// a count that can never reach zero.
		$outstanding = $off ? 0 : Grades::remaining( $types );

		// Pages whose verdict is older than the owner's last edit — saved, and
		// waiting for the sweep to read them again. Same breath, same cached
		// payload, same reason: it is printed beside `posts` and a number taken
		// at another instant can contradict the one next to it.
		//
		// ⛔ Zero while checking is off, for the reason above it: no sweep is
		// coming, so there is no re-reading to promise. The card says the whole
		// card is an older reading in that state.
		// ⚠️ GRADEABLE ONLY — the same population `posts` counts two lines down.
		// Without that, heera.it's card read "75 graded · 88 being read again":
		// both numbers true of what they measured, and the pair impossible.
		$rechecking = $off ? 0 : Grades::rechecking( $types, true, $this->ignored_ids() );

		$read = Grades::optimize( $types, $this->ignored_ids(), self::WORKLIST_POSTS_PER_ISSUE );
		if ( $read['posts'] < 1 ) {
			return array( 'score' => null, 'posts' => 0, 'flagged' => 0, 'issues' => array(), 'grading' => $outstanding, 'rechecking' => $rechecking, 'pending' => array() );
		}

		$issues = array();
		foreach ( $read['issues'] as $id => $issue ) {
			// The label is resolved HERE, from the id, so a stored grade never
			// carries translated words it would have to be re-swept to update.
			$issues[ (string) $id ] = array(
				'count' => (int) $issue['count'],
				'label' => PageCheck::issue_label( $id ),
				'posts' => array_map( 'intval', (array) $issue['posts'] ),
					// Per-type tally, so the worklist can say "3 Posts, 1 Page"
					// instead of calling every flagged item a "page".
					// ⚠️ From the STORE, over every flagged page — not from the
					// handful sampled below. Naming it from the sample printed
					// "6 Posts" above "Showing 6 of 22".
					'types' => isset( $issue['types'] ) && $issue['types']
						? (array) $issue['types']
						: self::type_tally( (array) $issue['posts'] ),
			);
		}

		return array(
			'score'      => $read['score'],
			'posts'      => (int) $read['posts'],
			// ⚠️ Pages, not issues. The card names the biggest issue GROUP beside
			// the graded total, and a group is not a page count — one page can
			// carry three. Read in the same breath as `posts`, from the same
			// rows, so the pair can never describe two different moments.
			'flagged'    => (int) $read['flagged'],
			'issues'     => $issues,
			'grading'    => $outstanding,
			'rechecking' => $rechecking,
			// Which of the pages named above carry a verdict older than the edit,
			// so each row can say so where it stands.
			'pending'    => array_map( 'intval', (array) $read['pending'] ),
		);
	}

	/**
	 * How many of these posts are of each type. Reads the post objects already
	 * in WordPress's cache from the query above — no rendering, no parsing.
	 *
	 * @param array<int,int> $ids Post IDs.
	 * @return array<string,int>
	 */
	private static function type_tally( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$type = (string) get_post_type( (int) $id );
			if ( '' === $type ) {
				continue;
			}
			$out[ $type ] = 1 + ( isset( $out[ $type ] ) ? (int) $out[ $type ] : 0 );
		}
		return $out;
	}

	private function optimize_note( array $optimize ) {
		if ( null === $optimize['score'] ) {
			return __( 'No published posts read yet — this rung starts measuring as soon as your content has been looked at.', 'agentimus' );
		}
		$n = (int) $optimize['posts'];
		return sprintf(
			// ⚠️ "your %1$d" — the whole graded site now, NOT "your %1$d most
			// recently edited". The words moved with the measurement; a note that
			// still said "recently edited" over a site-wide average would be the
			// same lie the old sample told, only quieter.
			//
			// ⚠️ And the NOUN is the graded scope, not the words "posts and
			// pages". That pair was hardcoded, so a site that graded Pages and
			// Docs — or had switched Posts off — read a sentence naming content
			// it had not graded.
			// ⚠️ AND THE AUDIENCE IS NOT ONLY AI. Twelve of the fourteen checks
			// behind this number — headings, alt text, thin content, reading ease,
			// freshness — are what a search engine and a screen reader need too.
			// Naming only assistants made the whole rung look like a bet on one
			// kind of visitor, and let an owner who is unsure about AI dismiss
			// work that pays either way.
			/* translators: 1: number of items graded, 2: the kinds of content graded, e.g. "Pages" or "Posts and Docs". */
			_n( 'How easily an assistant, a search engine or a screen reader can read, quote and credit your %1$d published %2$s.', 'How easily an assistant, a search engine or a screen reader can read, quote and credit all %1$d of your published %2$s.', $n, 'agentimus' ),
			$n,
			self::graded_noun( $n )
		);
	}

	/**
	 * The kinds of content behind the Optimized pillar's numbers, named for a
	 * sentence. Mirrors {@see \Agentimus\Findings::scope_noun()} — the two
	 * sentences sit on adjacent screens over the same measurement, so they must
	 * name it the same way.
	 *
	 * @param int $n How many items — decides singular or plural.
	 * @return string
	 */
	private static function graded_noun( $n ) {
		$types = Gradeability::post_types();
		if ( ! $types || count( $types ) > 3 ) {
			return _n( 'piece of content', 'pieces of content', (int) $n, 'agentimus' );
		}
		$names = array();
		foreach ( $types as $type ) {
			$names[] = 1 === (int) $n ? Content::singular( $type ) : Content::label( $type );
		}
		if ( 1 === count( $names ) ) {
			return $names[0];
		}
		$last = array_pop( $names );
		/* translators: 1: comma-separated list of content types, 2: the last one. */
		return sprintf( __( '%1$s and %2$s', 'agentimus' ), implode( ', ', $names ), $last );
	}

	/**
	 * The per-page content worklist behind the Optimized rung: each citability issue
	 * across the sampled posts, with a short "what to do" and the affected pages linking
	 * to their editors. Built at request time (not inside the cached optimize sample) so
	 * titles and edit links stay fresh and reflect the current user's capabilities.
	 *
	 * @param array $optimize Optimize result (score, posts, issues).
	 * @return array<int,array<string,mixed>>
	 */
	private function content_worklist( array $optimize ) {
		$issues  = isset( $optimize['issues'] ) ? $optimize['issues'] : array();
		$pending = isset( $optimize['pending'] ) ? array_map( 'intval', (array) $optimize['pending'] ) : array();
		uasort(
			$issues,
			static function ( $a, $b ) {
				return (int) $b['count'] - (int) $a['count'];
			}
		);

		$out = array();
		foreach ( $issues as $id => $issue ) {
			$pages = array();
			foreach ( (array) $issue['posts'] as $pid ) {
				$edit = (string) get_edit_post_link( (int) $pid, 'raw' );
				if ( '' === $edit ) {
					continue; // No edit access → nowhere useful to send them.
				}
				$title   = html_entity_decode( wp_strip_all_tags( get_the_title( (int) $pid ) ), ENT_QUOTES, 'UTF-8' );
				$pages[] = array(
					'id'    => (int) $pid,
					'title' => '' !== $title ? $title : __( '(untitled)', 'agentimus' ),
					'url'   => $edit,
					// TRUE when this verdict was measured before the owner's last
					// save. The row stays — this is what stops it posing as current.
					'stale' => in_array( (int) $pid, $pending, true ),
				);
			}
			if ( empty( $pages ) ) {
				continue;
			}
			$out[] = array(
				'id'         => (string) $id,
				'label'      => (string) $issue['label'],
				'why'        => self::content_guidance( (string) $id ),
				'count'      => (int) $issue['count'],
				'countLabel' => self::kind_count_label( isset( $issue['types'] ) ? (array) $issue['types'] : array(), (int) $issue['count'] ),
				'pages'      => $pages,
			);
		}
		return $out;
	}

	/**
	 * A short, plain imperative for a content check — what to do about it, at the group
	 * level (the per-post specifics live in each post's Readability panel). Pure.
	 *
	 * @param string $id PageCheck check id.
	 * @return string
	 */
	private static function content_guidance( $id ) {
		$map = array(
			'words'          => __( 'Expand it — an agent has little to read, and search sees a thin page.', 'agentimus' ),
			'summary'        => __( 'Open with a line that states what the page is about.', 'agentimus' ),
			'evidence'       => __( 'Add a figure, a statistic, or a cited source.', 'agentimus' ),
			'sources'        => __( 'Cite a source — link where its facts come from.', 'agentimus' ),
			'headings'       => __( 'Add H2/H3 headings so agents, readers and search can see its parts.', 'agentimus' ),
			'heading_order'  => __( 'Fix the heading levels so they don’t skip.', 'agentimus' ),
			'passages'       => __( 'Break the long block into shorter, quotable paragraphs.', 'agentimus' ),
			'reading_ease'   => __( 'Simplify the prose — shorter sentences, plainer words.', 'agentimus' ),
			'link_density'   => __( 'Add prose or trim link lists — it reads as navigation.', 'agentimus' ),
			'alt_text'       => __( 'Describe images — for assistants, screen readers and image search.', 'agentimus' ),
			'featured_image' => __( 'Set a featured image so link previews have a picture.', 'agentimus' ),
			'featured_alt'   => __( 'Describe the featured image — image search and screen readers use it.', 'agentimus' ),
			'freshness'      => __( 'Refresh it — engines favour current pages.', 'agentimus' ),
		);
		return isset( $map[ $id ] ) ? $map[ $id ] : __( 'Open it in the editor — the Agentimus box shows what to improve.', 'agentimus' );
	}

	/**
	 * "6 Posts" / "3 Posts, 1 Page" / "2 Products" — the human count for one
	 * worklist issue, named from the real content types behind it (the graded
	 * set is posts AND pages, plus any article-like public type). Falls back to
	 * "N items" when the type map is missing (a cached pre-upgrade sample) or a
	 * type has no label to speak with.
	 *
	 * @param array $types Post-type slug → flagged count.
	 * @param int   $count Total flagged, for the fallback.
	 * @return string
	 */
	private static function kind_count_label( array $types, $count ) {
		$parts = array();
		foreach ( $types as $slug => $n ) {
			$obj  = get_post_type_object( (string) $slug );
			$name = '';
			if ( $obj && isset( $obj->labels ) ) {
				$name = 1 === (int) $n ? (string) $obj->labels->singular_name : (string) $obj->labels->name;
			}
			if ( '' === $name ) {
				$parts = array();
				break;
			}
			$parts[] = sprintf( '%1$s %2$s', number_format_i18n( (int) $n ), $name );
		}
		if ( empty( $parts ) ) {
			/* translators: %d: how many posts/pages flag this check. */
			return sprintf( _n( '%d item', '%d items', max( 1, (int) $count ), 'agentimus' ), (int) $count );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Every published, gradeable page a given content check flags — the full set,
	 * not the worklist's capped preview. Feeds the "set all aside" action.
	 *
	 * @param string $issue_id PageCheck check id (e.g. 'featured_image').
	 * @return int[]
	 */
	public function issue_post_ids( $issue_id ) {
		$issue_id = (string) $issue_id;
		if ( '' === $issue_id ) {
			return array();
		}
		// ⚠️ The WHOLE set, from the store. It used to walk the 25-post sample —
		// so "set all aside" under a count of twelve could park only the four of
		// them that happened to be recent, and report success. A bulk action has
		// to act on the number the owner read before they pressed it.
		return Grades::posts_with_flag( Gradeability::post_types(), $this->ignored_ids(), $issue_id );
	}

	/** Post IDs the owner set aside as "not cited content". */
	private function ignored_ids() {
		return array_values( array_filter( array_map( 'intval', (array) $this->settings->get( 'optimize_ignored', array() ) ) ) );
	}

	/**
	 * The "set aside" list for the worklist: the owner's ignored pages, resolved to a
	 * title + editor link so each can be shown and restored. Only live, editable posts —
	 * a stale id (deleted/unpublished) is dropped rather than shown.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function ignored_list() {
		$ids = $this->ignored_ids();
		// ⭐ Read back, not re-analyzed. This used to render up to 25 of these
		// pages just to name what they were flagged for, and list every one after
		// that with NO flags — so a long set-aside list quietly changed its own
		// meaning halfway down. The sweep already knows.
		$stored = Grades::flag_ids_for( $ids );

		$out = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$edit = (string) get_edit_post_link( $id, 'raw' );
			if ( '' === $edit ) {
				continue;
			}
			// What this page was flagged for — so "set aside" rows still say why
			// they were on the worklist. Empty means the sweep has not read this
			// one yet, which the screens already state elsewhere.
			$flags = array();
			foreach ( ( isset( $stored[ (int) $id ] ) ? $stored[ (int) $id ] : array() ) as $flag_id ) {
				$flags[] = PageCheck::issue_label( $flag_id );
			}
			$out[] = array(
				'id'    => $id,
				'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ),
				'url'   => $edit,
				'flags' => $flags,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 *  Measure — the one pillar that reflects a real outcome (citation checks).
	 * ---------------------------------------------------------------------- */

	/**
	 * The state of the Cited (AI-Visibility) pillar in one word, decided purely from the
	 * facts that drive it. This is the single branch point behind the score, the rung note
	 * AND the action — extracted and made pure (like {@see self::blend()}) so all three
	 * agree and every case is unit-tested:
	 *
	 *   'unset'  — no AI provider is active (never set up, or the key was removed). An old
	 *              run's number is stale and won't update → excluded, never frozen-current.
	 *   'never'  — a provider is set up, but no run has produced a check yet (or an empty run).
	 *   'failed' — a run happened and every check errored (usually an expired/rate-limited
	 *              key). A real failure, not an absence — and pointedly NOT "never run".
	 *   'stale'  — a successful run, but older than the freshness cutoff.
	 *   'ok'     — a fresh run with at least one successful check (some may have errored; a
	 *              partial run still measured a smaller, real sample worth reporting).
	 *
	 * @param bool $has_provider Whether any AI provider is active right now.
	 * @param int  $run          Last run id (a unix timestamp), or 0 if never run.
	 * @param int  $checks       Successful checks in that run.
	 * @param int  $errors       Errored checks in that run.
	 * @param bool $stale        Whether that run is past the freshness cutoff.
	 * @return string One of unset|never|failed|stale|ok.
	 */
	public static function cited_state( $has_provider, $run, $checks, $errors, $stale ) {
		if ( ! $has_provider ) {
			return 'unset';
		}
		if ( (int) $run <= 0 ) {
			return 'never';
		}
		if ( (int) $checks < 1 ) {
			// A run with no successful checks: 'failed' if something errored (a broken key),
			// otherwise an empty run that never really measured → 'never' (run a check).
			return (int) $errors > 0 ? 'failed' : 'never';
		}
		return $stale ? 'stale' : 'ok';
	}

	/**
	 * The measured citation signal: the AI-Visibility "seen in answers" rate from the
	 * latest completed run, plus the state that classifies it. Null score (excluded from
	 * the blend) unless a fresh run actually measured something — measuring citation costs
	 * the owner's own AI credit, so most sites won't have it, and an absent OR failed
	 * outcome must never drag the score down or, worse, read as "never run".
	 *
	 * @return array{score:int|null,note:string,state:string}
	 */
	private function measure() {
		$has_provider = ! empty( ( new Visibility\Settings() )->active_providers() );
		$run          = (int) get_option( Visibility\Runner::LAST_RUN_OPTION, 0 );

		$checks   = 0;
		$errors   = 0;
		$mentions = 0;
		$vscore   = 0;
		$ago      = '';
		$stale    = false;
		if ( $has_provider && $run > 0 ) {
			$summary  = Visibility\Store::summarize( Visibility\Store::rows_for_run( $run ) );
			$checks   = (int) $summary['checks'];
			$errors   = (int) $summary['errors'];
			$mentions = (int) $summary['mentions'];
			$vscore   = (int) $summary['visibilityScore'];
			$ago      = human_time_diff( $run, time() );
			// A citation reading goes stale with time. Past the cutoff it no longer
			// represents today, so it's kept only as a dated reference (the Citations
			// tab still shows the figure + "Last run" date) and dropped from the composite.
			$stale_after = (int) apply_filters( 'agentimus_cited_stale_days', self::CITED_STALE_DAYS );
			$stale       = $stale_after > 0 && ( time() - $run ) > $stale_after * DAY_IN_SECONDS;
		}

		$state = self::cited_state( $has_provider, $run, $checks, $errors, $stale );

		switch ( $state ) {
			case 'unset':
				$note = __( 'Not measured — add an AI provider key under Visibility → Citations to track whether engines cite you.', 'agentimus' );
				break;
			case 'failed':
				$note = __( 'Every check failed on the last run — open Visibility → Citations to check the provider key and re-run.', 'agentimus' );
				break;
			case 'stale':
				$note = sprintf(
					/* translators: 1: last score, 2: human-readable time difference. */
					__( 'Last measured %1$d%% %2$s ago — run a check to refresh.', 'agentimus' ),
					$vscore,
					$ago
				);
				break;
			case 'ok':
				$note = sprintf(
					/* translators: 1: mentions, 2: checks, 3: human-readable time difference. */
					__( 'AI named you in %1$d of %2$d answers · checked %3$s ago.', 'agentimus' ),
					$mentions,
					$checks,
					$ago
				);
				// A partial run still measured a real, smaller sample — KEEP that score, but
				// say what's missing so the drop isn't silent (the bug this feature fixes).
				if ( $errors > 0 ) {
					$note .= ' ' . sprintf(
						/* translators: %d: number of checks that errored on the last run. */
						_n( '%d check didn’t finish.', '%d checks didn’t finish.', $errors, 'agentimus' ),
						$errors
					);
				}
				break;
			default: // 'never'.
				$note = __( 'Citation checks are set up — run one to measure whether engines cite you.', 'agentimus' );
				break;
		}

		return array(
			'score' => 'ok' === $state ? $vscore : null,
			'note'  => $note,
			'state' => $state,
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Action plan — every gap, impact-ranked, each with its fix.
	 * ---------------------------------------------------------------------- */

	/**
	 * Collect and rank the "do this next" list from all pillars.
	 *
	 * @param array $readiness Readiness rows.
	 * @param array $optimize  Optimize result.
	 * @param array $measure   Measure result.
	 * @return array<int,array<string,mixed>>
	 */
	private function actions( array $readiness, array $optimize, array $measure ) {
		$out = array();

		// Config gaps — reuse each readiness row's own fix + jump-to-fix action.
		// 'off' rows are skipped with the passes: they carry no fix of their own,
		// and the switched-off feature's warn row is already in this list — one
		// switch must never queue two actions.
		foreach ( $readiness as $r ) {
			if ( 'pass' === $r['status'] || 'off' === $r['status'] ) {
				continue;
			}
			$out[] = array(
				'id'       => $r['id'],
				'pillar'   => self::rung_of( $r['id'] ),
				'title'    => $r['label'],
				'why'      => '' !== (string) $r['fix'] ? (string) $r['fix'] : (string) $r['detail'],
				'severity' => 'fail' === $r['status'] ? 'fail' : 'warn',
				'action'   => isset( $r['action'] ) ? $r['action'] : null,
			);
		}

		// Content gaps — the two most common per-page issues across the sample.
		$issues = $optimize['issues'];
		uasort( $issues, static function ( $a, $b ) {
			return (int) $b['count'] - (int) $a['count'];
		} );
		$shown = 0;
		foreach ( $issues as $id => $issue ) {
			if ( $shown >= 2 ) {
				break;
			}
			++$shown;
			$example = empty( $issue['posts'] ) ? 0 : (int) $issue['posts'][0];
			$edit    = $example ? (string) get_edit_post_link( $example, 'raw' ) : '';
			$out[] = array(
				'id'       => 'content_' . $id,
				'pillar'   => 'optimized',
				'title'    => $issue['label'],
				// Named, not generic: the old "could be more citable" framing fit the
				// text-quality checks but misdescribed others (a missing featured
				// image is about link previews, not citability). Quoting the check's
				// own label stays accurate for every check, present and future.
				'why'      => sprintf(
					/* translators: 1: number of flagged posts/pages, 2: the check's warning label. */
					_n( '%1$d item flags “%2$s” — open it and the Agentimus box in the editor shows the fix.', '%1$d items flag “%2$s” — open one and the Agentimus box in the editor shows the fix.', (int) $issue['count'], 'agentimus' ),
					(int) $issue['count'],
					(string) $issue['label']
				),
				'severity' => 'content',
				'action'   => '' !== $edit ? array( 'label' => __( 'Open in the editor', 'agentimus' ), 'href' => $edit ) : null,
			);
		}

		// Measure gap. Two different situations, ranked apart: a set-up-and-failing
		// integration is a real problem (warn — an expired key needs fixing, and it
		// outranks a missing alt attribute), while a not-set-up one is only an invite
		// (info). Skipped entirely when citation tracking is off (the tab is hidden).
		if ( empty( $measure['off'] ) ) {
			$state = isset( $measure['state'] ) ? (string) $measure['state'] : '';
			if ( 'failed' === $state ) {
				$out[] = array(
					'id'       => 'visibility_failing',
					'pillar'   => 'cited',
					'title'    => __( 'Citation checks are failing', 'agentimus' ),
					'why'      => __( 'Every check failed on the last run — usually an expired or rate-limited provider key. Open Visibility → Citations to check the key and re-run.', 'agentimus' ),
					'severity' => 'warn',
					'action'   => array( 'label' => __( 'Open Visibility', 'agentimus' ), 'tab' => 'visibility' ),
				);
			} elseif ( in_array( $state, array( 'unset', 'never' ), true ) ) {
				$out[] = array(
					'id'       => 'measure_setup',
					'pillar'   => 'cited',
					'title'    => __( 'Measure AI citations', 'agentimus' ),
					'why'      => __( 'Set up citation checks (your own AI keys, under Visibility → Citations) to track whether engines mention and link to you over time.', 'agentimus' ),
					'severity' => 'info',
					'action'   => array( 'label' => __( 'Open Visibility', 'agentimus' ), 'tab' => 'visibility' ),
				);
			}
		}

		return array_slice( self::rank( $out ), 0, self::MAX_ACTIONS );
	}

	/**
	 * Rank actions by impact then ease: fails first, then warns, then content, then
	 * info; within a tier, heavier pillars first; config fixes (with a jump action)
	 * ahead of content notes. Pure and stable. Returns a re-ordered copy.
	 *
	 * @param array<int,array<string,mixed>> $actions Actions.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rank( array $actions ) {
		$sev_rank = array( 'fail' => 0, 'warn' => 1, 'content' => 2, 'info' => 3 );
		// Decorate-sort-undecorate keeps it stable (usort isn't stable pre-PHP 8.0).
		$decorated = array();
		foreach ( $actions as $i => $a ) {
			$sev    = isset( $sev_rank[ $a['severity'] ] ) ? $sev_rank[ $a['severity'] ] : 2;
			$weight = isset( self::WEIGHTS[ $a['pillar'] ] ) ? self::WEIGHTS[ $a['pillar'] ] : 0;
			$decorated[] = array( $sev, -$weight, $i, $a );
		}
		usort( $decorated, static function ( $x, $y ) {
			return ( $x[0] <=> $y[0] ) ?: ( ( $x[1] <=> $y[1] ) ?: ( $x[2] <=> $y[2] ) );
		} );
		return array_map( static function ( $d ) {
			return $d[3];
		}, $decorated );
	}
}
