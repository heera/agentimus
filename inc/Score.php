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

	/** Cap on affected pages listed per issue in the content worklist. */
	const WORKLIST_POSTS_PER_ISSUE = 6;

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
			'content'  => $this->content_worklist( $optimize ),
			'graded'   => (int) $optimize['posts'],
			'ignored'  => $this->ignored_list(),
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

	/**
	 * Post types graded for content citability (the Optimize pillar): articles, pages
	 * and doc-like content — the stuff written to be read and cited. Commerce products
	 * are excluded, because a product page is short by design and isn't written to be
	 * quoted, so article checks (thin content, quotable passages) misfire on it. Starts
	 * from the agent-visible types, drops known commerce types, and is filterable both
	 * ways so an adapter can exclude its own type or force a custom one back in.
	 *
	 * @return string[]
	 */
	private function citability_post_types() {
		/**
		 * Commerce/product post types to exclude from citability grading. Seeded with the
		 * common carts (WooCommerce, EDD, FluentCart).
		 *
		 * @param string[] $commerce Post-type slugs treated as commerce, not articles.
		 */
		$commerce = (array) apply_filters(
			'agentimus_commerce_post_types',
			array( 'product', 'product_variation', 'download', 'shop_order', 'shop_coupon', 'fluent-products' )
		);

		$types = array_values( array_diff( Content::post_types(), $commerce ) );

		/**
		 * The exact post types graded for content citability, after commerce is removed.
		 *
		 * @param string[] $types Post-type slugs to grade.
		 */
		return array_values( (array) apply_filters( 'agentimus_citability_post_types', $types ) );
	}

	/**
	 * Whether a sampled post is a real article whose content can be graded for citability.
	 * Excludes the structural and empty pages that would otherwise read as false positives:
	 *
	 *  - The blog-index container ("Posts page"): WordPress renders the loop, so it has no
	 *    authored content of its own — grading it "thin" is always wrong.
	 *  - Any page with no extractable text: a front page the theme renders, a page-builder
	 *    or form page, a placeholder. There's nothing to expand, or the content lives where
	 *    neither the plugin nor an agent can read it — either way "thin content" is a false
	 *    positive, not an article to improve.
	 *
	 * @param \WP_Post $post The sampled post.
	 * @return bool
	 */
	private function is_gradeable( \WP_Post $post ) {
		if ( (int) $post->ID === (int) get_option( 'page_for_posts' ) ) {
			return false;
		}
		// Owner set-aside: pages marked "not cited content" from the worklist — content
		// that isn't meant to be quoted (a landing/utility/index page). Always surfaced
		// as a visible "set aside" count, so this never silently inflates the score.
		if ( in_array( (int) $post->ID, $this->ignored_ids(), true ) ) {
			return false;
		}
		if ( str_word_count( wp_strip_all_tags( (string) Content::markdown_source( $post ) ) ) < 1 ) {
			return false;
		}
		/**
		 * Exclude a specific page/post from content-citability grading (the Optimize
		 * pillar) — e.g. a utility page an owner or adapter doesn't want graded as an article.
		 *
		 * @param bool     $gradeable Default true.
		 * @param \WP_Post $post      The post being considered.
		 */
		return (bool) apply_filters( 'agentimus_gradeable_post', true, $post );
	}

	private function compute_optimize() {
		$types = $this->citability_post_types();
		if ( empty( $types ) ) {
			// No article-like content to grade (e.g. a commerce-only site) → the Optimize
			// pillar is N/A (null), so blend() redistributes its weight instead of scoring 0.
			return array( 'score' => null, 'posts' => 0, 'issues' => array() );
		}
		$ids = get_posts(
			array(
				'post_type'        => $types,
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
			if ( ! $post || ! $this->is_gradeable( $post ) ) {
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
						$issues[ $key ] = array( 'count' => 0, 'label' => (string) $r['label'], 'posts' => array() );
					}
					++$issues[ $key ]['count'];
					// Collect the affected posts (capped) so the worklist — and the
					// action's fix link — can point straight at each post's editor.
					if ( count( $issues[ $key ]['posts'] ) < self::WORKLIST_POSTS_PER_ISSUE ) {
						$issues[ $key ]['posts'][] = (int) $id;
					}
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
		$issues = isset( $optimize['issues'] ) ? $optimize['issues'] : array();
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
				);
			}
			if ( empty( $pages ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (string) $id,
				'label' => (string) $issue['label'],
				'why'   => self::content_guidance( (string) $id ),
				'count' => (int) $issue['count'],
				'pages' => $pages,
			);
		}
		return $out;
	}

	/**
	 * A short, plain imperative for a content check — what to do about it, at the group
	 * level (the per-post specifics live in each post's AI Readability panel). Pure.
	 *
	 * @param string $id PageCheck check id.
	 * @return string
	 */
	private static function content_guidance( $id ) {
		$map = array(
			'words'         => __( 'Expand it — an agent has little to read or cite.', 'agentimus' ),
			'summary'       => __( 'Open with a line that states what the page is about.', 'agentimus' ),
			'evidence'      => __( 'Add a figure, a statistic, or a cited source.', 'agentimus' ),
			'headings'      => __( 'Add H2/H3 headings so an agent can section it.', 'agentimus' ),
			'heading_order' => __( 'Fix the heading levels so they don’t skip.', 'agentimus' ),
			'passages'      => __( 'Break the long block into shorter, quotable paragraphs.', 'agentimus' ),
			'link_density'  => __( 'Add prose or trim link lists — it reads as navigation.', 'agentimus' ),
			'alt_text'      => __( 'Describe images with alt text.', 'agentimus' ),
			'freshness'     => __( 'Refresh it — engines favour current pages.', 'agentimus' ),
		);
		return isset( $map[ $id ] ) ? $map[ $id ] : __( 'Improve this page’s citability in the editor.', 'agentimus' );
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
		$out = array();
		foreach ( $this->ignored_ids() as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$edit = (string) get_edit_post_link( $id, 'raw' );
			if ( '' === $edit ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ),
				'url'   => $edit,
			);
		}
		return $out;
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
		// A live citation signal needs AI Visibility to be configured right now. If it was
		// never set up — or was set up and run, then the provider key was removed/disabled
		// — an old run's score is stale: it no longer reflects reality and won't update.
		// Treat that as not-measured (null → excluded from the blend, its weight
		// redistributed), never a frozen number masquerading as current.
		if ( empty( ( new Visibility\Settings() )->active_providers() ) ) {
			return array(
				'score' => null,
				'note'  => __( 'Not measured — add an AI provider key under AI Visibility to track whether engines cite you.', 'agentimus' ),
			);
		}
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
			'note'  => __( 'AI Visibility is set up — run a check to measure whether engines cite you.', 'agentimus' ),
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
			$example = empty( $issue['posts'] ) ? 0 : (int) $issue['posts'][0];
			$edit    = $example ? (string) get_edit_post_link( $example, 'raw' ) : '';
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
