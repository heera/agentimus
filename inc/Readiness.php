<?php
/**
 * Readiness report — a list of pass/warn/fail checks that the admin "Readiness"
 * panel renders. Each check is cheap and never fetches; the rows that need the
 * site's real served output read RouteProbe's stored summary, and report()
 * queues that probe's async refresh when the summary is stale.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Readiness {

	/**
	 * The de-facto floor an llms.txt is expected to clear: a near-empty index
	 * gives an agent almost nothing to read or cite. Checked by check_llms_words().
	 */
	const MIN_LLMS_WORDS = 200;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run the checks.
	 *
	 * @return array<int,array<string,string>> List of { id, label, status, detail }.
	 */
	public function report() {
		RouteProbe::maybe_schedule(); // Queue the async self-check when stale; rows read stored data only.
		$checks = array(
			$this->check_site_public(),
			$this->check_permalinks(),
			$this->check_llms_txt(),
			$this->check_llms_words(),
			$this->check_llms_full(),
			$this->check_llms_full_size(),
			$this->check_post_types(),
			$this->check_topics(),
			$this->check_about(),
			$this->check_expertise(),
			$this->check_same_as(),
			$this->check_entity_image(),
			$this->check_entity_role(),
			$this->check_security_txt(),
			$this->check_schema_conflict(),
			$this->check_head_conflict(),
			$this->check_seo_coverage(),
			$this->check_static_robots(),
			$this->check_ai_usage_policy(),
			$this->check_sitemap(),
			$this->check_robots_sitemap(),
		);

		/**
		 * Filter the readiness checks (a Pro add-on can append its own).
		 *
		 * @param array    $checks   Check rows.
		 * @param Settings $settings Settings store.
		 */
		return $this->normalize( apply_filters( 'agentimus_readiness_checks', $checks, $this->settings ) );
	}

	/**
	 * Guarantee every row is well-formed before it reaches the UI. Third-party
	 * checks appended via `agentimus_readiness_checks` may omit fields or use a
	 * different convention (e.g. a boolean `pass`/`ok` instead of a `status`
	 * string) — and a single malformed row must never blank the Readiness screen
	 * or skew the score. An unrecognised status becomes `warn`: surfaced for
	 * attention rather than silently counted as a pass.
	 *
	 * @param mixed $checks Raw checks from the filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize( $checks ) {
		$valid = array( 'pass', 'warn', 'fail' );
		$out   = array();
		foreach ( (array) $checks as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$id = isset( $c['id'] ) ? (string) $c['id'] : '';
			if ( '' === $id ) {
				continue; // A check with no id can't be anchored or deduped — drop it.
			}
			$status = isset( $c['status'] ) ? (string) $c['status'] : '';
			if ( ! in_array( $status, $valid, true ) ) {
				// Accept a boolean pass/ok convention; otherwise flag for attention.
				if ( isset( $c['pass'] ) || isset( $c['ok'] ) ) {
					$status = ( ! empty( $c['pass'] ) || ! empty( $c['ok'] ) ) ? 'pass' : 'fail';
				} else {
					$status = 'warn';
				}
			}
			$out[] = array(
				'id'     => $id,
				'label'  => ( isset( $c['label'] ) && '' !== (string) $c['label'] ) ? (string) $c['label'] : $id,
				'status' => $status,
				'detail' => isset( $c['detail'] ) ? (string) $c['detail'] : '',
				'fix'    => isset( $c['fix'] ) ? (string) $c['fix'] : '',
				'action' => ( isset( $c['action'] ) && is_array( $c['action'] ) ) ? $c['action'] : null,
			);
		}
		return $out;
	}

	/**
	 * Build one check row.
	 *
	 * @param string     $id     Identifier.
	 * @param string     $label  Human label.
	 * @param string     $status pass|warn|fail.
	 * @param string     $detail What the check found (the current state).
	 * @param string     $fix    What to do about it — shown for warn/fail. Empty for a clean pass.
	 * @param array|null $action Optional call-to-action: an in-app jump
	 *                           { label, tab, anchor } or an external { label, href }.
	 * @return array
	 */
	/**
	 * AgentReady v1.0.0 (agentready.org) stable requirement IDs, attached to the
	 * checks that evidence them. Only protocol-backed rows are tagged — a
	 * content-quality grade is this plugin's own bar, not a spec citation, and
	 * an over-tagged report would claim more than the spec says.
	 */
	const AR_MAP = array(
		'ai_usage'       => 'AR-DISC-01',
		'robots'         => 'AR-DISC-01',
		'sitemap'        => 'AR-DISC-02',
		'robots_sitemap' => 'AR-DISC-02',
		'llms'           => 'AR-DISC-03',
		'llms_words'     => 'AR-DISC-03',
		'llms_full'      => 'AR-DISC-04',
		'llms_full_size' => 'AR-DISC-04',
		'schema'         => 'AR-CONT-01',
		'about'          => 'AR-CONT-01',
		'entity_role'    => 'AR-CONT-01',
		'entity_image'   => 'AR-CONT-01',
		'same_as'        => 'AR-CONT-01',
	);

	private function row( $id, $label, $status, $detail, $fix = '', $action = null, array $links = array() ) {
		$row       = compact( 'id', 'label', 'status', 'detail', 'fix', 'action', 'links' );
		$row['ar'] = isset( self::AR_MAP[ $id ] ) ? self::AR_MAP[ $id ] : '';
		return $row;
	}

	/**
	 * An in-app call-to-action that jumps to a section of the Settings tab.
	 *
	 * @param string $label  Button label.
	 * @param string $anchor DOM id of the target section.
	 * @return array
	 */
	private function nav( $label, $anchor ) {
		return array(
			'label'  => $label,
			'tab'    => 'settings',
			'anchor' => $anchor,
		);
	}

	/**
	 * An external call-to-action (e.g. a core WordPress admin screen).
	 *
	 * @param string $label Link label.
	 * @param string $href  Destination URL.
	 * @return array
	 */
	private function link( $label, $href ) {
		return array(
			'label' => $label,
			'href'  => $href,
		);
	}

	private function check_site_public() {
		$public = (bool) get_option( 'blog_public', 1 );
		return $public
			? $this->row( 'public', __( 'Search engine visibility', 'agentimus' ), 'pass', __( 'The site is public, so agents and crawlers can read it.', 'agentimus' ) )
			: $this->row(
				'public',
				__( 'Search engine visibility', 'agentimus' ),
				'fail',
				__( 'Settings → Reading is set to discourage search engines. Agents will be blocked.', 'agentimus' ),
				__( 'Open Settings → Reading and uncheck “Discourage search engines from indexing this site.” Nothing else here matters until this is on.', 'agentimus' ),
				$this->link( __( 'Open Reading settings', 'agentimus' ), admin_url( 'options-reading.php' ) )
			);
	}

	private function check_permalinks() {
		$pretty = (bool) get_option( 'permalink_structure' );
		return $pretty
			? $this->row( 'permalinks', __( 'Pretty permalinks', 'agentimus' ), 'pass', __( 'Markdown URLs like /post-slug.md resolve cleanly.', 'agentimus' ) )
			: $this->row(
				'permalinks',
				__( 'Pretty permalinks', 'agentimus' ),
				'warn',
				__( 'Plain permalinks are active. Switch to a pretty structure for tidy .md URLs.', 'agentimus' ),
				__( 'In Settings → Permalinks choose “Post name” (or any non-plain structure). With plain links, the /slug.md markdown URLs agents prefer can’t resolve.', 'agentimus' ),
				$this->link( __( 'Open Permalinks', 'agentimus' ), admin_url( 'options-permalink.php' ) )
			);
	}

	/**
	 * The setting alone is not the truth: a static file at the web root, or
	 * another plugin on the same route, can serve its own /llms.txt while this
	 * one says "on". The static file is checked directly; the route itself is
	 * judged from RouteProbe's stored self-check, and when that data is absent
	 * the row stays a plain pass — never a failure built on a missing probe.
	 */
	private function check_llms_txt() {
		$label = __( '/llms.txt index', 'agentimus' );
		if ( ! $this->settings->enabled( 'enable_llms_txt' ) ) {
			return $this->row(
				'llms',
				$label,
				'warn',
				__( 'Disabled. Enable it so agents can discover your content map.', 'agentimus' ),
				__( 'Turn on “/llms.txt index” under Settings → Features. It publishes a single map of your site that crawlers and agents check first.', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-feat-enable_llms_txt' )
			);
		}

		if ( file_exists( Paths::site_root() . 'llms.txt' ) ) {
			return $this->row(
				'llms',
				$label,
				'warn',
				__( 'A file named llms.txt exists in the site root folder. Visitors and agents get that file, not the index this plugin builds.', 'agentimus' ),
				__( 'Delete that file to let Agentimus serve its generated index — or keep the file and turn this feature off here.', 'agentimus' ),
				$this->link( __( 'View llms.txt', 'agentimus' ), home_url( '/llms.txt' ) )
			);
		}

		$probe = RouteProbe::data();
		$llms  = ( is_array( $probe ) && isset( $probe['llms'] ) && is_array( $probe['llms'] ) ) ? $probe['llms'] : null;

		if ( null !== $llms && 200 !== (int) $llms['http'] ) {
			return $this->row(
				'llms',
				$label,
				'fail',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The setting is on, but the last self-check could not load /llms.txt (HTTP %d).', 'agentimus' ),
					(int) $llms['http']
				),
				__( 'Open /llms.txt in your browser. If it does not load, save Settings → Permalinks once and check again.', 'agentimus' ),
				$this->link( __( 'View llms.txt', 'agentimus' ), home_url( '/llms.txt' ) )
			);
		}

		if ( null !== $llms && empty( $llms['ours'] ) ) {
			return $this->row(
				'llms',
				$label,
				'fail',
				__( 'The setting is on, but something else answers /llms.txt on this site. Agents get that file, not the index this plugin builds.', 'agentimus' ),
				__( 'Another plugin probably has its own llms.txt feature. Turn it off there, or turn it off here, so the site serves one index.', 'agentimus' ),
				$this->link( __( 'View llms.txt', 'agentimus' ), home_url( '/llms.txt' ) )
			);
		}

		$detail = null !== $llms
			? sprintf( /* translators: %s: URL. */ __( 'Served at %s. A self-check confirmed this plugin’s index is what visitors get.', 'agentimus' ), home_url( '/llms.txt' ) )
			: sprintf( /* translators: %s: URL. */ __( 'Served at %s.', 'agentimus' ), home_url( '/llms.txt' ) );
		return $this->row( 'llms', $label, 'pass', $detail );
	}

	/**
	 * Duplicate head output — two meta descriptions or two social card blocks
	 * on the home page mean two plugins (or a theme and a plugin) print the
	 * same tags, and engines pick one on their own. Reads RouteProbe's stored
	 * summary; before the first probe lands the row is simply absent (report()
	 * drops the null), because a verdict without data would be a guess.
	 */
	private function check_head_conflict() {
		$probe = RouteProbe::data();
		$head  = ( is_array( $probe ) && isset( $probe['head'] ) && is_array( $probe['head'] ) ) ? $probe['head'] : null;
		if ( null === $head ) {
			return null;
		}

		$label        = __( 'Duplicate meta tags', 'agentimus' );
		$descriptions = (int) $head['descriptions'];
		$og_titles    = (int) $head['og_titles'];

		if ( $descriptions <= 1 && $og_titles <= 1 ) {
			return $this->row(
				'head_dupes',
				$label,
				'pass',
				__( 'The home page has one description tag and one set of social card tags.', 'agentimus' )
			);
		}

		$repeated = array();
		if ( $descriptions > 1 ) {
			/* translators: %d: number of meta description tags (always 2 or more). */
			$repeated[] = sprintf( __( '%d description tags', 'agentimus' ), $descriptions );
		}
		if ( $og_titles > 1 ) {
			/* translators: %d: number of og:title tags (always 2 or more). */
			$repeated[] = sprintf( __( '%d social card titles', 'agentimus' ), $og_titles );
		}
		$detail = sprintf(
			/* translators: %s: the repeated tag kinds with counts, e.g. "2 description tags and 2 social card titles". */
			__( 'The home page has %s. One of each is correct: when two plugins print the same tag, engines pick one on their own.', 'agentimus' ),
			implode( __( ' and ', 'agentimus' ), $repeated )
		);
		$others = isset( $head['others'] ) ? array_filter( array_map( 'strval', (array) $head['others'] ) ) : array();
		if ( ! empty( $others ) ) {
			$detail .= ' ' . sprintf(
				/* translators: %s: comma-separated list of other tools that mark the page, e.g. "ThinkRank 1.22.0". */
				__( 'Also printing tags here: %s.', 'agentimus' ),
				implode( ', ', $others )
			);
		}

		return $this->row(
			'head_dupes',
			$label,
			'warn',
			$detail,
			__( 'Keep one source for these tags. Turn off the duplicate output in the other plugin or in the theme. When Agentimus sees a known SEO plugin, it steps back by itself.', 'agentimus' ),
			$this->link( __( 'View home page', 'agentimus' ), home_url( '/' ) )
		);
	}

	/**
	 * The /llms.txt index should carry enough substance to be worth reading: a
	 * sparse file (no profile, a handful of posts) gives an agent little to ingest
	 * or cite. We measure the generated index against a 200-word floor — the
	 * de-facto minimum an llms.txt is expected to clear — and, when it's thin,
	 * nudge toward real content rather than padding the file with filler.
	 */
	private function check_llms_words() {
		// When the index is off, check_llms_txt() already warns — don't double-flag.
		if ( ! $this->settings->enabled( 'enable_llms_txt' ) ) {
			return $this->row( 'llms_words', __( '/llms.txt substance', 'agentimus' ), 'pass', __( 'The /llms.txt index is off, so there is nothing to measure.', 'agentimus' ) );
		}

		// Cache the word count so a repeat admin load (report() runs on every settings
		// load/save) doesn't re-derive it — and, when the /llms.txt cache has gone cold,
		// doesn't trigger a synchronous rebuild just to count. Busted with the rest on a
		// content/settings change.
		$words = Cache::get( Cache::LLMS_WORDS );
		if ( false === $words ) {
			$words = self::word_count( ( new LlmsText( $this->settings ) )->llms_txt() );
			Cache::set( Cache::LLMS_WORDS, $words );
		}

		return $this->llms_words_row( (int) $words );
	}

	/**
	 * Grade a word count against the floor and build the row. Split from
	 * check_llms_words() so the pass/warn threshold and its messaging are testable
	 * without standing up the (WP-heavy) llms.txt generation pipeline.
	 *
	 * @param int $words Word count of the generated /llms.txt.
	 * @return array
	 */
	private function llms_words_row( $words ) {
		if ( $words >= self::MIN_LLMS_WORDS ) {
			return $this->row(
				'llms_words',
				__( '/llms.txt substance', 'agentimus' ),
				'pass',
				sprintf(
					/* translators: 1: word count; 2: the minimum, e.g. 200. */
					__( 'Your /llms.txt carries about %1$s words — clear of the %2$s-word minimum agents expect.', 'agentimus' ),
					number_format_i18n( $words ),
					number_format_i18n( self::MIN_LLMS_WORDS )
				)
			);
		}

		$detail = sprintf(
			/* translators: 1: word count; 2: the minimum, e.g. 200. */
			__( 'Your /llms.txt is thin — about %1$s words, under the %2$s-word minimum an agent expects. A sparse index gives it little to read or cite.', 'agentimus' ),
			number_format_i18n( $words ),
			number_format_i18n( self::MIN_LLMS_WORDS )
		);

		// State-aware advice: never send an owner back to redo what they've done.
		// With the profile already in the file, the only real lever left is
		// published content — say so, and point at the editor, not at Settings.
		$has_profile = '' !== trim( (string) $this->settings->identity( 'about', '' ) );

		if ( $has_profile ) {
			return $this->row(
				'llms_words',
				__( '/llms.txt substance', 'agentimus' ),
				'warn',
				$detail,
				__( 'Your profile is already in the file — it’s thin because the site has little published content yet. Publish a few pages or posts; each flows into llms.txt and lifts it over the minimum.', 'agentimus' ),
				$this->link( __( 'Write a post', 'agentimus' ), admin_url( 'post-new.php' ) )
			);
		}

		return $this->row(
			'llms_words',
			__( '/llms.txt substance', 'agentimus' ),
			'warn',
			$detail,
			__( 'Flesh it out with real content, not filler: add a profile sentence and 3–5 expertise topics under Settings → Identity, and publish a few pages or posts. Each flows into llms.txt and lifts it over the minimum.', 'agentimus' ),
			$this->nav( __( 'Edit Identity', 'agentimus' ), 'ar-about' )
		);
	}

	/**
	 * Count the human-readable words in a markdown document. The `(url)` half of
	 * every link is dropped first so URLs don't inflate the total; what remains is
	 * the prose and link labels an agent actually reads.
	 *
	 * @param string $markdown Markdown text.
	 * @return int
	 */
	private static function word_count( $markdown ) {
		$prose = preg_replace( '/\]\([^)]*\)/', ']', (string) $markdown ); // [label](url) → [label].
		return str_word_count( wp_strip_all_tags( (string) $prose ) );
	}

	private function check_llms_full() {
		return $this->settings->enabled( 'enable_llms_full' )
			? $this->row( 'llms_full', __( '/llms-full.txt full text', 'agentimus' ), 'pass', sprintf( /* translators: %s: URL. */ __( 'Served at %s.', 'agentimus' ), home_url( '/llms-full.txt' ) ) )
			: $this->row(
				'llms_full',
				__( '/llms-full.txt full text', 'agentimus' ),
				'warn',
				__( 'Disabled. The full-text edition lets agents ingest everything in one request.', 'agentimus' ),
				__( 'Enable “/llms-full.txt full text” under Settings → Features so an agent can pull your whole corpus in one fetch instead of crawling page by page.', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-feat-enable_llms_full' )
			);
	}

	private function check_llms_full_size() {
		// Only meaningful when the full-text edition is on; when off, check_llms_full()
		// already covers it — don't double-warn.
		if ( ! $this->settings->enabled( 'enable_llms_full' ) ) {
			return $this->row( 'llms_full_size', __( 'Full-text file size', 'agentimus' ), 'pass', __( 'The full-text edition is off, so there is nothing to size.', 'agentimus' ) );
		}

		$est       = Content::estimate_full_size( $this->settings );
		$stat      = Cache::get( Cache::LLMS_FULL_STAT );
		$truncated = is_array( $stat ) && ! empty( $stat['truncated'] );

		if ( $est['will_truncate'] || $truncated ) {
			$detail = ( $truncated && is_array( $stat ) )
				? sprintf(
					/* translators: 1: served size e.g. "1 MB"; 2: budget size. */
					__( 'The full-text file was last served at %1$s and truncated at the %2$s limit, so some content is left out.', 'agentimus' ),
					size_format( (int) $stat['bytes'] ),
					size_format( $est['budget_bytes'] )
				)
				: sprintf(
					/* translators: 1: item count; 2: estimated size; 3: budget size. */
					__( 'About %1$s items (~%2$s) would exceed the %3$s size limit, so the file will be truncated.', 'agentimus' ),
					number_format_i18n( $est['items'] ),
					size_format( $est['est_bytes'] ),
					size_format( $est['budget_bytes'] )
				);

			return $this->row(
				'llms_full_size',
				__( 'Full-text file size', 'agentimus' ),
				'warn',
				$detail,
				__( 'Lower “Posts in /llms-full.txt” under Settings → Features so the file fits, or rely on the /llms.txt index — agents can still fetch any page by appending .md to its URL.', 'agentimus' ),
				$this->nav( __( 'Adjust in Features', 'agentimus' ), 'ar-full-count' )
			);
		}

		return $this->row(
			'llms_full_size',
			__( 'Full-text file size', 'agentimus' ),
			'pass',
			sprintf(
				/* translators: 1: item count; 2: estimated size; 3: budget size. */
				__( 'About %1$s items (~%2$s), within the %3$s limit.', 'agentimus' ),
				number_format_i18n( $est['items'] ),
				size_format( $est['est_bytes'] ),
				size_format( $est['budget_bytes'] )
			)
		);
	}

	private function check_post_types() {
		$types  = Content::post_types();
		$labels = array_map( array( Content::class, 'label' ), $types );
		return $this->row(
			'post_types',
			__( 'Content coverage', 'agentimus' ),
			'pass',
			sprintf(
				/* translators: 1: number of content types, 2: comma-separated list. */
				__( 'Indexing %1$d content type(s): %2$s.', 'agentimus' ),
				count( $types ),
				implode( ', ', $labels )
			)
		);
	}

	private function check_topics() {
		if ( ! $this->settings->enabled( 'enable_topics' ) ) {
			return $this->row(
				'topics',
				__( 'Topics for AI', 'agentimus' ),
				'warn',
				__( 'Topics for AI is off, so no page tells assistants what it’s specifically about.', 'agentimus' ),
				__( 'Turn on “Topics for AI” under Settings → Topics for AI. Each post can then carry a short list of topics that become JSON-LD keywords and a line in its .md, so assistants grasp and cite it correctly.', 'agentimus' ),
				$this->nav( __( 'Enable Topics for AI', 'agentimus' ), 'ar-feat-enable_topics' )
			);
		}
		$cov = Topics::coverage();
		return $this->topics_row( (int) $cov['total'], (int) $cov['with_topics'], ! empty( $cov['derive'] ) );
	}

	/**
	 * Grade topic coverage. Split from check_topics() so the thresholds and
	 * messaging are testable without a database (Topics::coverage() runs SQL).
	 *
	 * @param int  $total  Published, agent-visible posts.
	 * @param int  $with   Of those, how many carry their own (manual) topics.
	 * @param bool $derive Whether auto-fill from tags & categories is on by default.
	 * @return array
	 */
	private function topics_row( $total, $with, $derive ) {
		if ( $total <= 0 ) {
			return $this->row( 'topics', __( 'Topics for AI', 'agentimus' ), 'pass', __( 'On. No published content yet — topics apply once you publish.', 'agentimus' ) );
		}

		// Auto-fill on → every post with a tag or category already carries topics; the
		// only lever left is adding sharper, post-specific ones, so this is a pass.
		if ( $derive ) {
			return $this->row(
				'topics',
				__( 'Topics for AI', 'agentimus' ),
				'pass',
				sprintf(
					/* translators: 1: posts with their own topics; 2: total published posts. */
					__( 'On, and auto-filling topics from tags & categories. %1$s of %2$s posts also have their own specific topics — add more to sharpen how assistants describe them.', 'agentimus' ),
					number_format_i18n( $with ),
					number_format_i18n( $total )
				)
			);
		}

		// Auto-fill off → coverage rests entirely on manually-set topics.
		if ( ( $with / $total ) >= 0.5 ) {
			return $this->row(
				'topics',
				__( 'Topics for AI', 'agentimus' ),
				'pass',
				sprintf(
					/* translators: 1: posts with topics; 2: total published posts. */
					__( '%1$s of %2$s published posts have topics set.', 'agentimus' ),
					number_format_i18n( $with ),
					number_format_i18n( $total )
				)
			);
		}

		return $this->row(
			'topics',
			__( 'Topics for AI', 'agentimus' ),
			'warn',
			sprintf(
				/* translators: 1: posts with topics; 2: total published posts. */
				__( 'Only %1$s of %2$s posts have topics, and auto-fill from tags & categories is off — so most tell assistants nothing about their subject.', 'agentimus' ),
				number_format_i18n( $with ),
				number_format_i18n( $total )
			),
			__( 'Add a few topics to your key posts in the editor’s “Topics for AI” box, or turn on “Use tags & categories by default” under Settings → Topics for AI to fill them in automatically.', 'agentimus' ),
			$this->nav( __( 'Open Topics for AI', 'agentimus' ), 'ar-sec-topics' )
		);
	}

	private function check_about() {
		return '' !== trim( (string) $this->settings->identity( 'about' ) )
			? $this->row( 'about', __( 'Author / entity profile', 'agentimus' ), 'pass', __( 'A profile sentence is set — the highest-signal line for retrieval.', 'agentimus' ) )
			: $this->row(
				'about',
				__( 'Author / entity profile', 'agentimus' ),
				'fail',
				__( 'No profile set. Add one under Identity so agents know who owns this site.', 'agentimus' ),
				__( 'Write one plain sentence under Settings → Identity: who you are, your role, and what this site is about. It’s the single line agents quote most when they cite you.', 'agentimus' ),
				$this->nav( __( 'Edit Identity', 'agentimus' ), 'ar-about' )
			);
	}

	private function check_expertise() {
		return ! empty( array_filter( (array) $this->settings->identity( 'expertise', array() ) ) )
			? $this->row( 'expertise', __( 'Expertise topics', 'agentimus' ), 'pass', __( 'Expertise is declared (feeds llms.txt and knowsAbout).', 'agentimus' ) )
			: $this->row(
				'expertise',
				__( 'Expertise topics', 'agentimus' ),
				'warn',
				__( 'No expertise topics set. They establish topical authority for agents.', 'agentimus' ),
				__( 'Add three to five topics under Settings → Identity (e.g. “WordPress development”, “API design”). They flow into llms.txt and the schema knowsAbout list so agents know what you’re an authority on.', 'agentimus' ),
				$this->nav( __( 'Add topics', 'agentimus' ), 'ar-id-expertise' )
			);
	}

	private function check_same_as() {
		return ! empty( array_filter( (array) $this->settings->identity( 'same_as', array() ) ) )
			? $this->row( 'same_as', __( 'sameAs profiles', 'agentimus' ), 'pass', __( 'Linked profiles help agents resolve your entity confidently.', 'agentimus' ) )
			: $this->row(
				'same_as',
				__( 'sameAs profiles', 'agentimus' ),
				'warn',
				__( 'No sameAs links (GitHub, LinkedIn, X…). Add them to strengthen entity matching.', 'agentimus' ),
				__( 'Add your GitHub, LinkedIn, or X profile URLs under Settings → Identity. These “sameAs” links let an agent tie this site to a known entity instead of guessing.', 'agentimus' ),
				$this->nav( __( 'Add profiles', 'agentimus' ), 'ar-id-sameas' )
			);
	}

	/**
	 * The site entity should carry an image/logo — engines show one in the entity and
	 * knowledge cards they build. Grades exactly what {@see Schema::entity_image()}
	 * emits, and stands down when Agentimus isn't the one publishing the entity schema.
	 */
	private function check_entity_image() {
		if ( ! $this->settings->enabled( 'enable_schema' ) ) {
			return $this->row( 'entity_image', __( 'Entity image', 'agentimus' ), 'pass', __( 'Schema output is off, so the entity image is handled elsewhere.', 'agentimus' ) );
		}
		$schema = new Schema( $this->settings );
		if ( $schema->seo_plugin_active() ) {
			return $this->row( 'entity_image', __( 'Entity image', 'agentimus' ), 'pass', __( 'An SEO plugin owns your schema, including the entity image.', 'agentimus' ) );
		}
		$is_org = 'Person' !== (string) $this->settings->identity( 'entity_type', 'Person' );
		if ( '' !== $schema->entity_image() ) {
			return $this->row(
				'entity_image',
				__( 'Entity image', 'agentimus' ),
				'pass',
				$is_org
					? __( 'Your organization carries a logo that engines can show in knowledge and answer cards.', 'agentimus' )
					: __( 'Your entity carries an image that engines can show in knowledge and answer cards.', 'agentimus' )
			);
		}
		return $this->row(
			'entity_image',
			__( 'Entity image', 'agentimus' ),
			'warn',
			$is_org
				? __( 'Your Organization schema has no logo. Engines display one in the entity cards they build from your site.', 'agentimus' )
				: __( 'Your Person schema has no image. Engines display one in the entity cards they build from your site.', 'agentimus' ),
			__( 'Set a Site Icon (Appearance → Customize → Site Identity). Agentimus publishes it as your entity’s image/logo — or point at a dedicated file with the agentimus_entity_image filter.', 'agentimus' ),
			$this->link( __( 'Set a Site Icon', 'agentimus' ), admin_url( 'customize.php?autofocus[control]=site_icon' ) )
		);
	}

	/**
	 * A Person entity should state a role — it becomes the schema jobTitle, the
	 * difference between an engine naming you and knowing what you do (an E-E-A-T
	 * author signal). An Organization has no jobTitle, so the check doesn't apply.
	 */
	private function check_entity_role() {
		if ( 'Person' !== (string) $this->settings->identity( 'entity_type', 'Person' ) ) {
			return $this->row( 'entity_role', __( 'Role / title', 'agentimus' ), 'pass', __( 'Not applicable — this site’s entity is an organization, not a person.', 'agentimus' ) );
		}
		if ( '' !== trim( (string) $this->settings->identity( 'role', '' ) ) ) {
			return $this->row( 'entity_role', __( 'Role / title', 'agentimus' ), 'pass', __( 'A role is set — it becomes your schema jobTitle, establishing who you are.', 'agentimus' ) );
		}
		return $this->row(
			'entity_role',
			__( 'Role / title', 'agentimus' ),
			'warn',
			__( 'No role or title set. An engine can name you but not say what you do — a weaker author signal.', 'agentimus' ),
			__( 'Add a short role under Settings → Identity (e.g. “WordPress developer”, “Nutrition researcher”). It becomes your schema jobTitle and sharpens your standing as an author.', 'agentimus' ),
			// The ROLE input, not the about textarea — pointing "Add a role" at
			// ar-about had owners typing their role into the wrong field.
			$this->nav( __( 'Add a role', 'agentimus' ), 'ar-role' )
		);
	}

	private function check_security_txt() {
		$url = home_url( '/.well-known/security.txt' );

		// A real on-disk file always wins (the generator stands down) — and it's
		// still the goal: a published security contact. Report it as a pass.
		if ( file_exists( \Agentimus\Paths::site_root() . '.well-known/security.txt' ) ) {
			return $this->row(
				'security_txt',
				__( 'security.txt contact', 'agentimus' ),
				'pass',
				/* translators: %s: URL. */
				sprintf( __( 'A security.txt file is published at %s; Agentimus links it and stands aside.', 'agentimus' ), $url )
			);
		}

		// Mirror the generator's real decision so the check never drifts from it.
		$sec = new Discovery\SecurityTxt( $this->settings );

		// Off (and no file): a disclosure contact is a recommended trust signal.
		if ( ! $sec->should_serve() ) {
			return $this->row(
				'security_txt',
				__( 'security.txt contact', 'agentimus' ),
				'warn',
				__( 'No security.txt. Researchers and agents have no machine-readable way to report a vulnerability.', 'agentimus' ),
				__( 'Turn on “Generate security.txt” under Settings → Security.txt and add a contact (your Identity email is reused automatically). It publishes an RFC 9116 disclosure contact at /.well-known/security.txt.', 'agentimus' ),
				$this->nav( __( 'Enable security.txt', 'agentimus' ), 'ar-feat-enable_security_txt' )
			);
		}

		// On, but no contact ⇒ RFC 9116 makes the document invalid ⇒ nothing served.
		$contacts = $sec->contacts();
		if ( empty( $contacts ) ) {
			return $this->row(
				'security_txt',
				__( 'security.txt contact', 'agentimus' ),
				'warn',
				__( 'security.txt is enabled but has no contact, so RFC 9116 makes it invalid and nothing is served.', 'agentimus' ),
				__( 'Add at least one Security contact (or a public contact email under Identity) in Settings → Security.txt. Without a Contact line the document can’t be published.', 'agentimus' ),
				$this->nav( __( 'Add a contact', 'agentimus' ), 'ar-sec-security' )
			);
		}

		// On, with a contact, and no file shadowing it → live.
		return $this->row(
			'security_txt',
			__( 'security.txt contact', 'agentimus' ),
			'pass',
			sprintf(
				/* translators: 1: number of contacts, 2: URL. */
				_n( 'Serving %1$d security contact at %2$s.', 'Serving %1$d security contacts at %2$s.', count( $contacts ), 'agentimus' ),
				count( $contacts ),
				$url
			)
		);
	}

	private function check_schema_conflict() {
		$schema = new Schema( $this->settings );
		if ( ! $this->settings->enabled( 'enable_schema' ) ) {
			return $this->row(
				'schema',
				__( 'JSON-LD structured data', 'agentimus' ),
				'warn',
				__( 'Schema output is disabled in settings.', 'agentimus' ),
				__( 'Enable “JSON-LD structured data” under Settings → Features — unless an SEO plugin already emits schema, in which case leaving it off avoids duplicate markup.', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-feat-enable_schema' )
			);
		}
		return $schema->seo_plugin_active()
			? $this->row( 'schema', __( 'JSON-LD structured data', 'agentimus' ), 'pass', __( 'An SEO plugin owns schema; Agentimus is standing down to avoid duplicates.', 'agentimus' ) )
			: $this->row( 'schema', __( 'JSON-LD structured data', 'agentimus' ), 'pass', __( 'Agentimus is emitting WebSite + entity + article schema.', 'agentimus' ) );
	}

	/**
	 * The mode surface ({@see SeoContext}): who covers search SEO on this site.
	 * Coexist credits the named suite; solo states plainly that Agentimus has it
	 * covered — or warns naming exactly which of the search basics are off.
	 */
	private function check_seo_coverage() {
		$resolved = SeoContext::resolve();
		$label    = __( 'Search SEO coverage', 'agentimus' );

		if ( 'coexist' === $resolved['mode'] ) {
			$plugin = $resolved['plugin'] ? $resolved['plugin']['label'] : __( 'Your SEO plugin', 'agentimus' );
			return $this->row(
				'seo_coverage',
				$label,
				'pass',
				sprintf(
					/* translators: %s: the active SEO plugin's name, e.g. "Yoast SEO". */
					__( '%s handles search SEO — titles, social cards, canonicals. Agentimus stands aside there and adds only the AI-facing layer it doesn’t cover.', 'agentimus' ),
					$plugin
				)
			);
		}

		$basics = array(
			'enable_seo_titles'   => __( 'per-page SEO titles', 'agentimus' ),
			'enable_social_cards' => __( 'social share cards', 'agentimus' ),
			'enable_canonicals'   => __( 'canonical links', 'agentimus' ),
		);
		$off    = array();
		foreach ( $basics as $flag => $name ) {
			if ( ! $this->settings->enabled( $flag ) ) {
				$off[] = $name;
			}
		}

		if ( empty( $off ) ) {
			return $this->row(
				'seo_coverage',
				$label,
				'pass',
				__( 'No SEO plugin installed — and none needed: Agentimus covers per-page SEO titles, social share cards and canonical links (your sitemap too, see below).', 'agentimus' )
			);
		}

		return $this->row(
			'seo_coverage',
			$label,
			'warn',
			sprintf(
				/* translators: %s: comma-separated list of the disabled features. */
				__( 'No SEO plugin is installed, but some of the search basics are turned off: %s. Nothing else on this site provides them.', 'agentimus' ),
				implode( ', ', $off )
			),
			__( 'Turn them on under Settings → Discovery → Search basics — or install an SEO plugin, and Agentimus will step aside automatically.', 'agentimus' ),
			$this->nav( __( 'Open Search basics', 'agentimus' ), 'ar-sec-search-basics' )
		);
	}

	private function check_static_robots() {
		$file = \Agentimus\Paths::site_root() . 'robots.txt';
		return file_exists( $file )
			? $this->row(
				'robots',
				__( 'robots.txt control', 'agentimus' ),
				'warn',
				__( 'A static robots.txt exists at the web root and overrides this plugin. Remove it to let Agentimus manage robots rules, or edit it by hand.', 'agentimus' ),
				__( 'Delete robots.txt from your site root to let Agentimus serve a managed virtual one — or, if you maintain it by hand, add your crawler and Sitemap directives there yourself.', 'agentimus' ),
				// The fix lives on the server, not in wp-admin — the honest link is
				// the file itself, so the owner sees exactly what's overriding us.
				$this->link( __( 'View robots.txt', 'agentimus' ), home_url( '/robots.txt' ) ),
				$this->robots_engine_links()
			)
			: $this->row(
				'robots',
				__( 'robots.txt control', 'agentimus' ),
				'pass',
				__( 'WordPress serves a virtual robots.txt that this plugin manages.', 'agentimus' ),
				'',
				// The permanent home of the on-demand doors: your file, and the
				// copy each connected engine last read — reachable any day, not
				// only during the two weeks after a change.
				$this->link( __( 'View robots.txt', 'agentimus' ), home_url( '/robots.txt' ) ),
				$this->robots_engine_links()
			);
	}

	/**
	 * The per-engine robots doors: what does Google/Bing actually read? One
	 * door per CONNECTED source, never invented — Google's robots report shows
	 * the file Google last fetched and when; Bing's tester does the same for
	 * Bingbot. Each engine keeps fetching robots.txt on its own schedule, so
	 * this is a different question from "what does my server serve?".
	 *
	 * @return array[] Zero or more links ({@see link()}).
	 */
	private function robots_engine_links() {
		$links  = array();
		$google = new Google\Settings();
		if ( $google->connected() ) {
			$links[] = $this->link(
				__( 'See what Google reads', 'agentimus' ),
				'https://search.google.com/search-console/settings/robots-txt?resource_id=' . rawurlencode( (string) $google->get( 'property' ) )
			);
		}
		$bing = new Bing\Settings();
		if ( $bing->connected() ) {
			$links[] = $this->link(
				__( 'See what Bing reads', 'agentimus' ),
				'https://www.bing.com/webmasters/robotstxttester?siteUrl=' . rawurlencode( (string) $bing->get( 'site_url' ) )
			);
		}
		return $links;
	}

	// NOTE deliberately absent: the transient "robots.txt changed" observation
	// used to render here as a scoreless row, but a notice among checks read as
	// a check — it breathed the report's denominator and painted a WARN over a
	// healthy group (removed 1.34.0, the owner's call). The change still
	// surfaces where a notice belongs: the weekly digest. The robots DOORS —
	// the file, and what each engine last read — live on the permanent
	// robots.txt-control row above, on demand.

	private function check_ai_usage_policy() {
		$signal   = (array) $this->settings->get( 'content_signal', array() );
		$reserved = empty( $signal['ai_train'] );
		$header   = $this->settings->enabled( 'enable_ai_header' );
		$tdmrep   = $this->settings->enabled( 'enable_tdmrep' );

		// Training allowed → nothing to reserve; informational pass.
		if ( ! $reserved ) {
			return $this->row(
				'ai_usage',
				__( 'AI usage policy', 'agentimus' ),
				'pass',
				__( 'AI training is allowed, so no reservation is published. Specific crawlers can still be blocked by name.', 'agentimus' )
			);
		}

		// Reserved AND backed by at least one enforceable signal beyond robots.txt.
		if ( $header || $tdmrep ) {
			$where = array();
			if ( $header ) {
				$where[] = __( 'a tdm-reservation response header', 'agentimus' );
			}
			if ( $tdmrep ) {
				$where[] = __( '/.well-known/tdmrep.json', 'agentimus' );
			}
			return $this->row(
				'ai_usage',
				__( 'AI usage policy', 'agentimus' ),
				'pass',
				sprintf(
					/* translators: %s: human list of the published signals, e.g. "a tdm-reservation response header and /.well-known/tdmrep.json". */
					__( 'Your no-AI-training preference is published as %s — standardized signals, not just an advisory robots.txt line.', 'agentimus' ),
					implode( __( ' and ', 'agentimus' ), $where )
				)
			);
		}

		// Reserved, but only advisory robots.txt is carrying it.
		return $this->row(
			'ai_usage',
			__( 'AI usage policy', 'agentimus' ),
			'warn',
			__( 'You ask AI not to train on your content, but only in robots.txt — which a crawler can ignore.', 'agentimus' ),
			__( 'Turn on the tdm-reservation header and /.well-known/tdmrep.json under Settings → Crawler policy so your preference is published as standardized, harder-to-ignore signals.', 'agentimus' ),
			$this->nav( __( 'Enable AI signals', 'agentimus' ), 'ar-feat-enable_ai_header' )
		);
	}

	private function check_sitemap() {
		$sitemap = Sitemap::detect();

		// Nothing serves a sitemap — core is off, no SEO plugin, generator opted out.
		if ( '' === $sitemap['url'] ) {
			return $this->row(
				'sitemap',
				__( 'XML sitemap', 'agentimus' ),
				'warn',
				__( 'No sitemap detected. Crawlers and agents have no single index of your URLs to start from.', 'agentimus' ),
				__( 'Turn on “XML sitemap” under Settings → Features and Agentimus will generate one for you — no SEO plugin required. (Re-enabling WordPress core sitemaps, or any major SEO plugin, also satisfies this; Agentimus auto-detects and links whichever exists.)', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-feat-enable_sitemap' )
			);
		}

		// Every serving branch names — and OPENS — the address actually served:
		// detect() is the single truth (promoted = the standard /wp-sitemap.xml,
		// coexist = the legacy path, core/SEO = theirs), so the row can never
		// again tell an old story while robots.txt advertises the new one.
		$view = $this->link( __( 'View sitemap', 'agentimus' ), $sitemap['url'] );

		// WordPress core serves it.
		if ( 'core' === $sitemap['source'] ) {
			return $this->row(
				'sitemap',
				__( 'XML sitemap', 'agentimus' ),
				'pass',
				__( 'WordPress core sitemap is live and advertised in robots.txt and llms.txt.', 'agentimus' ),
				'',
				$view
			);
		}

		// Agentimus serves it — promoted to the standard address, or filling
		// the gap at its own.
		if ( 'agentimus' === $sitemap['source'] ) {
			return $this->row(
				'sitemap',
				__( 'XML sitemap', 'agentimus' ),
				'pass',
				sprintf(
					/* translators: %s: sitemap URL. */
					__( 'Agentimus is generating your sitemap at %s and advertising it in robots.txt and llms.txt.', 'agentimus' ),
					$sitemap['url']
				),
				'',
				$view
			);
		}

		// A known SEO plugin owns it — detected and linked, never duplicated.
		return $this->row(
			'sitemap',
			__( 'XML sitemap', 'agentimus' ),
			'pass',
			sprintf(
				/* translators: %s: SEO plugin name, e.g. “Yoast SEO”. */
				__( 'Provided by %s and advertised in robots.txt and llms.txt — Agentimus links it rather than emitting a duplicate.', 'agentimus' ),
				$sitemap['label']
			),
			'',
			$view
		);
	}

	private function check_robots_sitemap() {
		$robots  = $this->robots_txt_contents();
		$has     = (bool) preg_match( '/^\s*sitemap:\s*https?:\/\//mi', $robots['contents'] );
		$sitemap = Sitemap::detect();

		// Advertised — crawlers autodiscover the sitemap. This is the goal.
		if ( $has ) {
			return $this->row(
				'robots_sitemap',
				__( 'Sitemap in robots.txt', 'agentimus' ),
				'pass',
				__( 'robots.txt declares a Sitemap: line, so crawlers discover your sitemap automatically.', 'agentimus' )
			);
		}

		// A non-public site emits "Disallow: /" with no Sitemap line by design —
		// the "Search engine visibility" check above is the real fix, not robots.
		if ( ! (bool) get_option( 'blog_public', 1 ) && ! $robots['static'] ) {
			return $this->row(
				'robots_sitemap',
				__( 'Sitemap in robots.txt', 'agentimus' ),
				'warn',
				__( 'robots.txt currently blocks all crawlers, so it advertises no sitemap.', 'agentimus' ),
				__( 'This follows from “Search engine visibility” being off — fix that check first and the Sitemap: line is emitted automatically.', 'agentimus' ),
				// The same destination the "Search engine visibility" check points to:
				// this row is downstream of that switch.
				$this->link( __( 'Open Reading settings', 'agentimus' ), admin_url( 'options-reading.php' ) )
			);
		}

		// Not advertised, and there's nothing to advertise yet.
		if ( '' === $sitemap['url'] ) {
			return $this->row(
				'robots_sitemap',
				__( 'Sitemap in robots.txt', 'agentimus' ),
				'warn',
				__( 'robots.txt has no Sitemap: line — there is no sitemap to point to yet.', 'agentimus' ),
				__( 'Enable “XML sitemap” under Settings → Features: Agentimus then generates a sitemap and adds the Sitemap: line to robots.txt in one step. (Core or an SEO-plugin sitemap would be linked automatically too.)', 'agentimus' ),
				$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-feat-enable_sitemap' )
			);
		}

		// A sitemap exists but a hand-maintained static robots.txt doesn't link it.
		if ( $robots['static'] ) {
			return $this->row(
				'robots_sitemap',
				__( 'Sitemap in robots.txt', 'agentimus' ),
				'warn',
				/* translators: %s: sitemap URL. */
				sprintf( __( 'A sitemap exists (%s) but your static robots.txt doesn’t link it, so crawlers may miss it.', 'agentimus' ), $sitemap['url'] ),
				/* translators: %s: sitemap URL. */
				sprintf( __( 'Add this line to the robots.txt file at your site root: “Sitemap: %s”. A static file overrides the one Agentimus manages, so the link has to be added by hand.', 'agentimus' ), $sitemap['url'] ),
				// Hand-edit fix → show the hand-maintained file it goes into.
				$this->link( __( 'View robots.txt', 'agentimus' ), home_url( '/robots.txt' ) )
			);
		}

		// A sitemap exists, robots is virtual, but the Sitemap: line is missing —
		// almost always because the robots.txt feature is switched off.
		return $this->row(
			'robots_sitemap',
			__( 'Sitemap in robots.txt', 'agentimus' ),
			'warn',
			/* translators: %s: sitemap URL. */
			sprintf( __( 'A sitemap exists (%s) but isn’t advertised in robots.txt, so crawlers may not find it.', 'agentimus' ), $sitemap['url'] ),
			__( 'Turn on “robots.txt rules” under Settings → Features so Agentimus advertises your sitemap to crawlers.', 'agentimus' ),
			$this->nav( __( 'Enable in Features', 'agentimus' ), 'ar-feat-enable_robots' )
		);
	}

	/**
	 * The robots.txt a crawler would see: the static file if one exists at the
	 * web root, otherwise the virtual output assembled exactly as WordPress's
	 * do_robots() does (so every robots_txt filter — core, SEO plugins, Agentimus
	 * — is reflected).
	 *
	 * @return array{contents:string,static:bool}
	 */
	private function robots_txt_contents() {
		return RobotsWatch::served(); // One reconstruction, shared with the change watch.
	}
}
