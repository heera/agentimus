<?php
/**
 * AEO/GEO Score — the unifying number and the ranked "do this next" plan. It fuses
 * the four scored stages of the model into one 0–100 read on how ready a site is to
 * be found, understood, and cited by AI answer engines, then collects every gap into
 * one impact-ranked action list.
 *
 *   Serve      (30) — delivery legibility: the Findable + Readable readiness checks.
 *   Structure  (25) — identity & trust:    the Trusted readiness checks.
 *   Optimize   (30) — content citability:  the per-page {@see PageCheck} signals,
 *                                          averaged over a cached sample of posts.
 *   Measure    (15) — measured citation:   the AI-Visibility "seen in answers" rate.
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
		'serve'     => 30,
		'structure' => 25,
		'optimize'  => 30,
		'measure'   => 15,
	);

	/** Readiness check ids that make up each config pillar (mirrors the UI rungs:
	 *  Serve = Findable + Readable, Structure = Trusted). */
	const SERVE_IDS     = array( 'public', 'permalinks', 'robots', 'sitemap', 'robots_sitemap', 'llms', 'llms_words', 'llms_full', 'llms_full_size', 'schema', 'post_types', 'topics' );
	const STRUCTURE_IDS = array( 'about', 'expertise', 'same_as', 'entity_image', 'entity_role', 'security_txt', 'ai_usage' );

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
	 * @return array{score:int,band:string,blocked:bool,pillars:array,actions:array,measured:bool}
	 */
	public function report() {
		$readiness = ( new Readiness( $this->settings ) )->report();
		$optimize  = $this->optimize();
		$measure   = $this->measure();

		$pillars = array(
			'serve'     => self::rows_score( $readiness, self::SERVE_IDS ),
			'structure' => self::rows_score( $readiness, self::STRUCTURE_IDS ),
			'optimize'  => $optimize['score'],
			'measure'   => $measure['score'],
		);

		$blocked = self::has_fail( $readiness, 'public' );
		$score   = self::blend( $pillars );
		if ( $blocked ) {
			$score = min( $score, 10 ); // Hidden from crawlers → nothing else can help yet.
		}

		return array(
			'score'    => $score,
			'band'     => self::band( $blocked ? 0 : $score ),
			'blocked'  => $blocked,
			'measured' => null !== $measure['score'],
			'pillars'  => array(
				'serve'     => $this->pillar_view( 'serve', $pillars['serve'], __( 'Serve', 'agentimus' ), __( 'Discovery files an agent can find and read.', 'agentimus' ) ),
				'structure' => $this->pillar_view( 'structure', $pillars['structure'], __( 'Structure', 'agentimus' ), __( 'Who you are, in a form an engine can trust.', 'agentimus' ) ),
				'optimize'  => $this->pillar_view( 'optimize', $pillars['optimize'], __( 'Optimize', 'agentimus' ), $this->optimize_note( $optimize ) ),
				'measure'   => $this->pillar_view( 'measure', $pillars['measure'], __( 'Measure', 'agentimus' ), $measure['note'] ),
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

	private function pillar_view( $key, $score, $label, $note ) {
		return array(
			'key'    => $key,
			'label'  => $label,
			'score'  => $score, // int|null (null = not measured yet)
			'weight' => self::WEIGHTS[ $key ],
			'note'   => $note,
		);
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
						$issues[ $key ] = array( 'count' => 0, 'label' => (string) $r['label'] );
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
			$pillar = in_array( $r['id'], self::STRUCTURE_IDS, true ) ? 'structure' : 'serve';
			$out[]  = array(
				'id'       => $r['id'],
				'pillar'   => $pillar,
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
			$out[] = array(
				'id'       => 'content_' . $id,
				'pillar'   => 'optimize',
				'title'    => $issue['label'],
				'why'      => sprintf(
					/* translators: %d: number of posts. */
					_n( '%d post could be more citable here — open its AI Readability panel in the editor.', '%d posts could be more citable here — open their AI Readability panels in the editor.', (int) $issue['count'], 'agentimus' ),
					(int) $issue['count']
				),
				'severity' => 'content',
				'action'   => null,
			);
		}

		// Measure gap — invite setting up AI Visibility, once (low priority).
		if ( null === $measure['score'] ) {
			$out[] = array(
				'id'       => 'measure_setup',
				'pillar'   => 'measure',
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
