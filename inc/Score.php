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
 *                    signals, averaged over a cached sample of posts.
 *   Cited     (15) — measured citation: the AI-Visibility "seen in answers" rate.
 *
 * A pillar with no data yet (no published posts to grade; AI Visibility not set up)
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

	/** How many recent posts the Optimize pillar samples (each is parsed). */
	const OPTIMIZE_SAMPLE = 25;

	/** Cap on the number of ranked actions returned. */
	const MAX_ACTIONS = 8;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
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
		$measure   = $this->measure();

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

		return array(
			'score'    => $score,
			'band'     => self::band( $blocked ? 0 : $score ),
			'blocked'  => $blocked,
			'ready'    => $ready,
			'measured' => null !== $measure['score'],
			'rungs'    => array(
				$this->check_rung( 'findable', __( 'Findable', 'agentimus' ), $scores['findable'], $readiness, self::FINDABLE_IDS, __( 'An agent can reach and crawl your site.', 'agentimus' ) ),
				$this->check_rung( 'readable', __( 'Readable', 'agentimus' ), $scores['readable'], $readiness, self::READABLE_IDS, __( 'What it crawls comes back clean and structured.', 'agentimus' ) ),
				$this->check_rung( 'trusted', __( 'Trusted', 'agentimus' ), $scores['trusted'], $readiness, self::TRUSTED_IDS, __( 'It can identify you and trust the source.', 'agentimus' ) ),
				$this->signal_rung( 'optimized', __( 'Optimized', 'agentimus' ), $scores['optimized'], $this->optimize_note( $optimize ), '' ),
				$this->signal_rung( 'cited', __( 'Cited', 'agentimus' ), $scores['cited'], $measure['note'], 'visibility' ),
			),
			'actions'  => $this->actions( $readiness, $optimize, $measure ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Pillar scoring — pure where it can be.
	 * ---------------------------------------------------------------------- */

	/**
	 * Score a subset of readiness rows: pass = 1, warn = 0.5, fail = 0, as a 0–100
	 * average. Null when none of the ids are present (nothing to score). Pure.
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
		return $total_w > 0 ? (int) round( $acc / $total_w ) : 0;
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
	private function signal_rung( $key, $label, $score, $note, $to ) {
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
		);
	}

	/**
	 * Count passing vs total checks for a rung's ids. Pure.
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

	private function compute_optimize() {
		$ids = get_posts(
			array(
				'post_type'        => Content::post_types(),
				'post_status'      => 'publish',
				'numberposts'      => self::OPTIMIZE_SAMPLE,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		if ( empty( $ids ) ) {
			return array( 'score' => null, 'posts' => 0, 'issues' => array() );
		}

		$sum    = 0.0;
		$graded = 0;
		$issues = array();
		foreach ( (array) $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post ) {
				continue;
			}
			$rows  = PageCheck::analyze( $post );
			$pts   = 0.0;
			$count = 0;
			foreach ( $rows as $r ) {
				++$count;
				$pts += self::points( (string) $r['status'] );
				if ( 'pass' !== $r['status'] ) {
					$key = (string) $r['id'];
					if ( ! isset( $issues[ $key ] ) ) {
						// Remember the first post that trips this check, so the action can
						// link straight to its editor (where the fix guidance lives).
						$issues[ $key ] = array( 'count' => 0, 'label' => (string) $r['label'], 'example' => (int) $id );
					}
					++$issues[ $key ]['count'];
				}
			}
			if ( $count > 0 ) {
				$sum += $pts / $count;
				++$graded;
			}
		}

		return array(
			'score'  => $graded > 0 ? (int) round( $sum / $graded * 100 ) : null,
			'posts'  => $graded,
			'issues' => $issues,
		);
	}

	private function optimize_note( array $optimize ) {
		if ( null === $optimize['score'] ) {
			return __( 'No published posts to grade yet.', 'agentimus' );
		}
		return sprintf(
			/* translators: %d: number of posts sampled. */
			_n( 'Citability across your %d most recent post.', 'Citability across your %d most recent posts.', (int) $optimize['posts'], 'agentimus' ),
			(int) $optimize['posts']
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Measure — the one pillar that reflects a real outcome (AI Visibility).
	 * ---------------------------------------------------------------------- */

	/**
	 * The measured citation signal: the AI-Visibility "seen in answers" rate from the
	 * latest completed run. Null (excluded) when AI Visibility hasn't been set up or
	 * run — measuring citation costs the owner's own AI credit, so most sites won't
	 * have it, and an absent outcome must never drag the score down.
	 *
	 * @return array{score:int|null,note:string}
	 */
	private function measure() {
		$run = (int) get_option( Visibility\Runner::LAST_RUN_OPTION, 0 );
		if ( $run > 0 ) {
			$summary = Visibility\Store::summarize( Visibility\Store::rows_for_run( $run ) );
			if ( (int) $summary['checks'] > 0 ) {
				return array(
					'score' => (int) $summary['visibilityScore'],
					'note'  => sprintf(
						/* translators: 1: mentions, 2: checks. */
						__( 'AI named you in %1$d of %2$d answers in your last check.', 'agentimus' ),
						(int) $summary['mentions'],
						(int) $summary['checks']
					),
				);
			}
		}
		return array(
			'score' => null,
			'note'  => __( 'Not measured yet — set up AI Visibility to track whether engines actually cite you.', 'agentimus' ),
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
		foreach ( $readiness as $r ) {
			if ( 'pass' === $r['status'] ) {
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
			$edit = empty( $issue['example'] ) ? '' : (string) get_edit_post_link( (int) $issue['example'], 'raw' );
			$out[] = array(
				'id'       => 'content_' . $id,
				'pillar'   => 'optimized',
				'title'    => $issue['label'],
				'why'      => sprintf(
					/* translators: %d: number of posts. */
					_n( '%d post could be more citable — open it to fix in the editor (its AI Readability panel).', '%d posts could be more citable — open one to fix in the editor (its AI Readability panel).', (int) $issue['count'], 'agentimus' ),
					(int) $issue['count']
				),
				'severity' => 'content',
				'action'   => '' !== $edit ? array( 'label' => __( 'Open the post', 'agentimus' ), 'href' => $edit ) : null,
			);
		}

		// Measure gap — invite setting up AI Visibility, once (low priority).
		if ( null === $measure['score'] ) {
			$out[] = array(
				'id'       => 'measure_setup',
				'pillar'   => 'cited',
				'title'    => __( 'Measure whether AI cites you', 'agentimus' ),
				'why'      => __( 'Set up AI Visibility (your own AI keys) to track whether engines mention and link to you over time.', 'agentimus' ),
				'severity' => 'info',
				'action'   => array( 'label' => __( 'Open AI Visibility', 'agentimus' ), 'tab' => 'visibility' ),
			);
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
