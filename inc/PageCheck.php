<?php
/**
 * Per-page content-quality checks — the per-post complement to the site-level
 * {@see Readiness} panel. Where Readiness asks "is the SITE set up for agents?",
 * this asks "is THIS page easy for an agent to read, section and cite?" and is
 * surfaced in the editor by {@see PageCheckMetaBox}.
 *
 * The analysis is pure: it inspects a post's rendered HTML (via
 * {@see Content::markdown_source()}) — headings, a lead summary, word count, link
 * density, image alt text — plus the traits that make a page *citable*: concrete
 * figures or cited sources to quote, quotable (not over-long) passages, and how
 * recently it was updated. It returns pass/warn rows. No front-end output; it's an
 * authoring aid only. Each check is cheap and side-effect free.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class PageCheck {

	/** Below this word count a page is "thin" — little for an agent to extract. */
	const MIN_WORDS = 100;

	/**
	 * The substance check applies only to pages this site grades for quoting.
	 *
	 * ⭐ A container — the Posts page, a cart, a form page, a product — is not an
	 * article, and "write more" is the one piece of advice its owner cannot act
	 * on: the words on it belong to whatever renders it. Grading excuses those
	 * pages already {@see \Agentimus\Gradeability::is_graded_for_quoting()}; the
	 * worklist was still billing them for their length on the next screen along.
	 *
	 * ⚠️ A CONSTANT because {@see ruleset()} is derived from these, and this is a
	 * change to what a check SAYS. Declaring the rule here is what moves the
	 * fingerprint and sends every stored verdict back to be read under it — a
	 * behaviour change that left no trace in the fingerprint would fix the check
	 * while every page kept the old check's flag until somebody edited it.
	 */
	const SUBSTANCE_ARTICLES_ONLY = true;

	/**
	 * A caption is evidence against alt="".
	 *
	 * ⭐ The rule: an empty alt on its own is respected as the WAI marker it is
	 * — a spacer or a divider is never flagged. An empty alt on a figure whose
	 * author wrote a CAPTION under it is a contradiction, and the caption is the
	 * author's own evidence that the picture means something
	 * {@see has_caption()}.
	 *
	 * ⚠️⚠️ A CONSTANT FOR THE REASON THE ONE ABOVE IS, and this rule is why the
	 * warning there is worth repeating: it shipped first WITHOUT declaring
	 * itself here, and the fingerprint {@see ruleset()} is built from the check
	 * IDS and these constants — neither of which the rule touched. So every
	 * stored verdict on every site still looked current, `is_stale_row()` sent
	 * nothing back to be read, and a check that works perfectly would never
	 * have run on a single existing page. Proven by comparing both files'
	 * fingerprint inputs, 2026-08-25.
	 *
	 * ⛔ THE LAW: a change to what a check SAYS must move the fingerprint, and
	 * on this class the way to move it is to declare the rule here. Correct
	 * code that no page is ever re-read under is indistinguishable from code
	 * that was never written.
	 */
	const CAPTIONED_BLANK_ALT_IS_A_GAP = true;

	/** Only expect headings once content is long enough to need sectioning. */
	const HEADINGS_MIN_WORDS = 300;

	/** A lead paragraph of at least this many words counts as a summary. */
	const SUMMARY_MIN_WORDS = 15;

	/** Warn when linked words exceed this share of the prose (nav-heavy content). */
	const LINK_DENSITY_MAX = 0.35;

	/** Below this a page is too short to expect figures or cited sources. */
	const EVIDENCE_MIN_WORDS = 150;

	/** A single paragraph longer than this is hard to lift as one clean quotable passage. */
	const LONG_PARAGRAPH_WORDS = 160;

	/** Past this age (days) a substantive page reads as stale — engines favour current sources. */
	const STALE_DAYS = 730;

	/** Below this a page is too short to expect outbound references. */
	const SOURCES_MIN_WORDS = 300;

	/** A page carrying video needs at least this much readable text of its own.
	 *  Below it the page IS the video: an assistant sees an <iframe> — which
	 *  carries no words at all — and a handful of framing sentences, so nothing
	 *  the video actually says can ever be quoted or cited. Set above
	 *  MIN_WORDS because a video page clears the thin-content bar on framing
	 *  alone while still being unreadable in the only way that matters here. */
	const VIDEO_MIN_WORDS = 150;

	/** Flesch Reading Ease bands: at/above OK reads general-audience; below HARD
	 *  is university-level prose. (English-only — the formula fits no other
	 *  language, and the check says so instead of mis-grading.) */
	/**
	 * @var int Average words per sentence at or below which the sentences are
	 *          NOT what is holding a page's reading ease down.
	 *
	 * ⭐ His catch, 2026-08-19, on a 798-word piece about Composer supply-chain
	 * security: the row said "shorter sentences and plainer words" over prose
	 * averaging 11 words a sentence. Half that advice could not be followed —
	 * there was nothing to shorten — and advice you cannot act on teaches an
	 * owner to skim the row. Measured on that page: sentences cost the score 11
	 * points and the vocabulary cost it 180. Fifteen is where plain-English
	 * guidance puts a normal sentence, and the Flesch term itself charges barely
	 * a point per word — so at or under it, the sentences are not the story.
	 */
	const SENTENCE_PLAIN = 15;

	const READING_EASE_OK   = 50;
	const READING_EASE_HARD = 30;

	/** Repetition reads as familiarity: a word of FAMILIAR_MIN_SYLLABLES+
	 *  estimated syllables that the page uses FAMILIAR_MIN_OCCURRENCES+ times is
	 *  its own subject vocabulary ("security" on a security page), and the
	 *  adjusted score prices each use at FAMILIAR_COUNTED_SYLLABLES — an
	 *  ordinary word. Flesch alone has no notion of familiarity and double-
	 *  charges a page for naming its topic; word-list formulas (Dale–Chall,
	 *  Gunning Fog) grade familiar words gently for the same reason. One-off
	 *  long words keep full weight — variety, not repetition, is what actually
	 *  reads hard, so thesaurus-prose still grades honestly. */
	const FAMILIAR_MIN_SYLLABLES     = 4;
	const FAMILIAR_MIN_OCCURRENCES   = 3;
	const FAMILIAR_COUNTED_SYLLABLES = 2;

	/**
	 * Run the checks for a post.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return array<int,array<string,string>> Rows: { id, label, status, detail }.
	 */
	public static function analyze( \WP_Post $post ) {
		$has_excerpt = '' !== trim( (string) $post->post_excerpt );
		$measured    = Content::markdown_source( $post );
		$stats       = self::stats( $measured, $has_excerpt, self::home_host() );
		// Recency is a post fact, not a content fact — fold it in after the pure parse.
		$stats['age_days']  = self::age_days( $post );
		$stats['evergreen'] = self::is_evergreen( $post );
		// Whether this site grades this page for quoting at all — a post fact too,
		// and the one the substance check needs. ⭐ Handed the render above rather
		// than making its own: the gradeability question renders the body to find
		// out whether there is one, and this method must not render twice on an
		// editor load.
		$stats['gradeable'] = Gradeability::is_graded_for_quoting( $post, $measured );
		// The site language is a runtime fact too: the reading-ease formula only
		// fits English, and the check skips honestly elsewhere.
		$stats['english'] = 0 === stripos( (string) get_locale(), 'en' );
		// So is the featured image — and it's only expected where the content
		// type and theme actually offer one.
		$stats['featured']          = has_post_thumbnail( $post );
		$stats['featured_expected'] = post_type_supports( $post->post_type, 'thumbnail' ) && current_theme_supports( 'post-thumbnails' );

		// ⭐ THE FEATURED IMAGE IS DRAWN BY THE THEME, so it never appears in the
		// content this class parses — which is why nothing ever reported it. It is
		// read here from the ATTACHMENT instead.
		//
		// ⛔ Deliberately NOT judged on what the theme renders. Checking that would
		// mean fetching the front-end page, and a panel that runs on every editor
		// load must not make an HTTP request. Nor can it be inferred: the-alpha
		// substitutes the post title inline in single.php, not through a filter, so
		// get_the_post_thumbnail() called from wp-admin returns alt="" and would
		// accuse a page that is in fact described.
		//
		// ⭐ So the claim is narrowed to one this CAN prove: the picture has no
		// description OF ITS OWN. That is true whatever the theme does — and a
		// theme falling back to the post title describes the article, not the
		// picture, so the advice holds there too.
		// ⭐ …and what the THEME does with that description is read from the probe
		// {@see ThemeImageProbe}, which asked the served page once, in cron, on
		// this site's own theme. One option read here, no HTTP: the claim gets to
		// be about the page a reader receives without any check ever fetching one.
		$theme                   = ThemeImageProbe::data();
		$stats['served_alt']     = null === $theme ? '' : (string) $theme['described'];
		$stats['served_alt_bare'] = null === $theme ? '' : (string) $theme['bare'];

		$stats['featured_alt']  = '';
		$stats['featured_file'] = '';
		if ( ! empty( $stats['featured'] ) ) {
			$thumb_id = (int) get_post_thumbnail_id( $post );
			if ( $thumb_id ) {
				$stats['featured_alt']  = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
				$stats['featured_file'] = self::library_name( (string) wp_get_attachment_url( $thumb_id ) );
			}
		}

		// The owner's own notes about this page's media.
		$items      = MediaContext::items_for( $post );
		$described  = 0;
		$note_text  = '';
		foreach ( $items as $item ) {
			if ( '' !== $item['context'] ) {
				++$described;
				$note_text .= ' ' . $item['context'];
			}
		}

		$stats['media_described'] = $described;

		// Deliberately NOT added to the word count. The notes make a page LEGIBLE —
		// an agent can tell what its media holds — but they do not make it
		// SUBSTANTIAL, and a bar calibrated for prose must not be cleared by
		// captions. Blending the two would let five videos and five one-liners turn
		// a nearly-empty page green, which is the opposite of what this panel is
		// for. The media row credits the work; the length row keeps telling the
		// truth about the page's own words. Two rows, two separate facts, neither
		// covering for the other.

		// The media detection above ran on the full the_content render; the notes
		// are keyed to the block render. Where the two disagree about how many
		// items exist, trust the one the owner actually saw in the editor.
		if ( ! empty( $items ) ) {
			$stats['videos'] = count( array_filter( $items, static function ( $m ) { return 'audio' !== $m['kind']; } ) );
			$stats['audios'] = count( array_filter( $items, static function ( $m ) { return 'audio' === $m['kind']; } ) );
		}

		$checks = array(
			self::check_words( $stats ),
			self::check_summary( $stats ),
			self::check_evidence( $stats ),
			self::check_sources( $stats ),
			self::check_headings( $stats ),
			self::check_heading_order( $stats ),
			self::check_passages( $stats ),
			self::check_reading_ease( $stats ),
			self::check_link_density( $stats ),
			self::check_alt_text( $stats ),
			self::check_media( $stats ),
			self::check_featured_image( $stats ),
			self::check_featured_alt( $stats ),
			self::check_freshness( $stats ),
		);

		/**
		 * Filter the per-page checks (a Pro add-on can append its own).
		 *
		 * @param array    $checks Check rows.
		 * @param array    $stats  The parsed page stats.
		 * @param \WP_Post $post   The post.
		 */
		$checks = apply_filters( 'agentimus_page_checks', $checks, $stats, $post );

		return self::normalize( $checks );
	}

	/**
	 * The labels of every check a post does NOT pass — the readable "what's off"
	 * list several screens collect from {@see analyze()}. One place so the reading
	 * of a row's pass/label shape is written once.
	 *
	 * @param \WP_Post $post Post to check.
	 * @return string[] Non-pass check labels.
	 */
	public static function flags( \WP_Post $post ) {
		$out = self::summarize( self::analyze( $post ) );
		return $out['flags'];
	}

	/**
	 * One page's citability, reduced to the three facts the grade store keeps.
	 *
	 * PURE, and it takes ROWS rather than a post on purpose: {@see analyze()}
	 * renders the page, and everything that wants a piece of that — the worklist
	 * row, the stored grade, the Optimized pillar, the by-issue list — has to be
	 * served from ONE render.
	 *
	 * `points` is the per-check average as 0–100 (pass 1, warn ½, fail 0) — the
	 * number the Optimized pillar averages across the site. Rounding to a whole
	 * point per page costs at most half a point on the pillar and buys a smallint
	 * column. `checks` rides along so a caller can tell "scored zero" apart from
	 * "no check ran", which are not the same claim.
	 *
	 * @param array $rows analyze() rows.
	 * @return array{points:int,checks:int,flags:array<int,string>,ids:array<int,string>}
	 */
	public static function summarize( array $rows ) {
		$pts   = 0.0;
		$n     = 0;
		$flags = array();
		$ids   = array();

		foreach ( (array) $rows as $r ) {
			if ( ! isset( $r['status'] ) ) {
				continue;
			}
			++$n;
			$status = (string) $r['status'];
			$pts   += 'pass' === $status ? 1.0 : ( 'warn' === $status ? 0.5 : 0.0 );
			if ( 'pass' !== $status ) {
				$flags[] = isset( $r['label'] ) ? (string) $r['label'] : '';
				$ids[]   = isset( $r['id'] ) ? (string) $r['id'] : '';
			}
		}

		return array(
			'points' => $n > 0 ? (int) round( $pts / $n * 100 ) : 0,
			'checks' => $n,
			'flags'  => array_values( array_filter( $flags ) ),
			'ids'    => array_values( array_filter( $ids ) ),
		);
	}

	/**
	 * The name of the PROBLEM a check id stands for, for a screen rebuilding a
	 * by-issue list out of stored ids rather than out of a fresh render.
	 *
	 * Every check has exactly one non-pass label, so this is one-to-one. It is
	 * translated HERE, at read time, which is why the store keeps ids and not
	 * words: a site that changes language must not need re-grading to be
	 * readable again.
	 *
	 * ⚠️ An id with no entry — a check added through `agentimus_page_checks` by
	 * an add-on — returns its own id rather than an empty heading. Silence would
	 * read as "no issue"; the raw id at least names something.
	 * {@see \Agentimus\Tests\PageCheckIssueLabelTest} holds this map to the
	 * checks it describes.
	 *
	 * @param string $id Check id.
	 * @return string
	 */
	public static function issue_label( $id ) {
		$map = self::issue_labels();
		$id  = (string) $id;
		return isset( $map[ $id ] ) ? $map[ $id ] : $id;
	}

	/**
	 * Every built-in check id, with the name of the problem it stands for.
	 *
	 * One map, two readers: the label lookup above, and {@see ruleset()}, which
	 * needs the ID LIST and must not keep a second copy of it — a fingerprint
	 * that can fall out of step with the checks it fingerprints is worse than
	 * none, because it fails silently and in the safe-looking direction.
	 *
	 * @return array<string,string> id => problem name.
	 */
	public static function issue_labels() {
		return array(
			'words'          => __( 'Not enough substance yet', 'agentimus' ),
			'summary'        => __( 'No opening summary', 'agentimus' ),
			'evidence'       => __( 'Short on specifics', 'agentimus' ),
			'sources'        => __( 'No outbound sources', 'agentimus' ),
			'headings'       => __( 'No headings', 'agentimus' ),
			'heading_order'  => __( 'Heading order', 'agentimus' ),
			'passages'       => __( 'Long blocks', 'agentimus' ),
			'reading_ease'   => __( 'Low reading ease', 'agentimus' ),
			'link_density'   => __( 'Mostly links', 'agentimus' ),
			'alt_text'       => __( 'Image alt text', 'agentimus' ),
			'media'          => __( 'Media without context', 'agentimus' ),
			'featured_image' => __( 'No featured image', 'agentimus' ),
			'featured_alt'   => __( 'Featured image not described', 'agentimus' ),
			'freshness'      => __( 'Getting stale', 'agentimus' ),
		);
	}

	/**
	 * A fingerprint of WHAT THESE CHECKS ARE — the missing half of a stored grade.
	 *
	 * ⭐⭐ A grade is an answer to a question, and the store kept the answer while
	 * forgetting the question. Adding a check, or moving a threshold, changed
	 * what "worth fixing" means on every page — and nothing re-read anything,
	 * because only a schema change or a content edit ever invalidated a verdict.
	 * 1.37.0 escaped it by accident: the grade table was NEW in that release, so
	 * every site was swept from empty. The next release to touch a check would
	 * have shipped a site full of verdicts from the previous one, with no sign
	 * that anything was out of date.
	 *
	 * ⭐ DERIVED, never hand-bumped. It is built from the two things that decide
	 * what a check says — the set of check ids and the thresholds they judge
	 * against — so adding, removing or re-tuning a check moves it by itself.
	 * "Remember to bump the constant" is exactly the step that gets forgotten,
	 * and forgetting it here is invisible: every screen keeps answering, in the
	 * old checks' words.
	 *
	 * ⚠️ The class constants are read whole, on purpose. A new one is far more
	 * likely to be a threshold than not, and the cost of being wrong is one
	 * background re-read of content that keeps its verdicts on screen the entire
	 * time — while the cost of missing a real change is a site quietly graded by
	 * checks it no longer runs.
	 *
	 * ⛔ Never store the labels. Words are translated at read time; a site that
	 * switches language must not need re-grading to be legible.
	 *
	 * @return string 12 hex characters — short enough for a column, wide enough
	 *                that no two check sets meet by accident.
	 */
	public static function ruleset() {
		// ⚠️ Keyed on the filter's answer rather than memoised outright: an
		// add-on registering its checks late would otherwise be told the
		// fingerprint it changed, from before it changed it. The reflection and
		// the hash — the only parts worth caching — still run once per answer.
		static $cache = array();
		static $own   = null;

		if ( null === $own ) {
			$ids = array_keys( self::issue_labels() );
			sort( $ids );

			// Every threshold this class judges by, name and value, in a stable order.
			$constants = ( new \ReflectionClass( __CLASS__ ) )->getConstants();
			ksort( $constants );
			$scalars = array();
			foreach ( $constants as $name => $value ) {
				if ( is_scalar( $value ) ) {
					$scalars[] = $name . '=' . ( is_bool( $value ) ? (int) $value : (string) $value );
				}
			}
			$own = implode( ',', $ids ) . '|' . implode( ',', $scalars );
		}

		/**
		 * Add to the fingerprint of the check set.
		 *
		 * An add-on appending checks through `agentimus_page_checks` changes what
		 * a grade MEANS, and this is how it says so — returning a new string
		 * re-reads the site under the new checks. ⛔ It must be stable between
		 * requests: a value that changes on every call would re-grade for ever.
		 *
		 * @param string $extra Anything else that decides what the checks say.
		 */
		// ⭐ WHAT THE THEME DOES IS PART OF THE RULE. The featured-image check
		// judges the served page through {@see ThemeImageProbe}, so a theme
		// switch — or an accessibility plugin that starts supplying alt text —
		// changes what that check says about every page on the site. Folding the
		// probe's answer in here is what sends those pages back to be read: fix
		// your theme and the old complaints clear themselves, instead of standing
		// until somebody edits each post.
		$extra = ThemeImageProbe::signature() . '|' . (string) apply_filters( 'agentimus_page_ruleset', '' );
		if ( isset( $cache[ $extra ] ) ) {
			return $cache[ $extra ];
		}

		$cache[ $extra ] = substr( md5( $own . '|' . $extra ), 0, 12 );
		return $cache[ $extra ];
	}

	/**
	 * Parse rendered HTML into the cheap counts the checks read. Pure — pass any
	 * HTML string (and whether the post has a manual excerpt) and it holds.
	 *
	 * @param string $html        Rendered content HTML.
	 * @param bool   $has_excerpt Whether the post has a manual excerpt.
	 * @param string $home_host   The site's own host (lowercased), so links elsewhere
	 *                            count as outbound (cited) sources. '' = don't classify.
	 * @return array<string,mixed>
	 */
	public static function stats( $html, $has_excerpt = false, $home_host = '' ) {
		$html    = (string) $html;
		$text    = self::text_of( $html );
		$words   = self::word_count( $text );
		// Concrete, quotable specifics: numbers, percentages, dates, amounts.
		$figures = (int) preg_match_all( '/\d[\d.,]*%?/', $text );

		$headings     = array();
		$heading_text = array(); // same order as $headings, so index N describes level N
		$paragraphs = array();
		// ⭐ WHICH paragraph, not just how long the worst one is. A page can hold
		// several over the line, and the row used to report only max() — so an
		// owner fixed one, the warning came back with a different number, and it
		// read as "nothing happened". Found by walking heera.it, 2026-08-18.
		// Only the offenders are kept, so a long page costs a handful of strings.
		$long_paras = array();
		$links      = 0;
		$link_words = 0;
		$outbound   = 0;
		$images     = 0;
		$images_no_alt = 0;
		$images_slug_alt = 0;
		$slug_alt_names  = array();
		$images_blank_alt = 0;
		$blank_alt_names  = array();
		// Same idea for pictures: "1 of 3 images" cannot be acted on without
		// hunting. The file name is what the owner sees in the media library.
		$no_alt_names  = array();
		$captions   = 0;
		$transcript = false;
		$media      = array();

		$dom = self::dom( $html );
		if ( $dom ) {
			// One detection pass, shared with the editor and the emitters, so the
			// count in the panel can never disagree with the list beside it.
			$media = self::media_items_from_dom( $dom );

			foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
				$tag = strtolower( $node->nodeName );
				if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
					$headings[] = (int) $m[1];
					// ⭐ Kept beside the levels rather than folded into them:
					// $s['headings'] is a list of LEVELS that several checks read
					// and count, and widening it would change every one of them.
					$heading_text[] = self::opening_words( $node->textContent, 6 );
					// "Transcript" as a section heading — the plainest way an author
					// writes one, and it costs nothing to recognise.
					if ( self::is_transcript_label( $node->textContent ) ) {
						$transcript = true;
					}
				} elseif ( 'track' === $tag ) {
					// Captions already attached to a self-hosted video: words that
					// exist and simply aren't being used yet.
					$kind = strtolower( trim( (string) $node->getAttribute( 'kind' ) ) );
					if ( ( '' === $kind || 'captions' === $kind || 'subtitles' === $kind ) && '' !== trim( (string) $node->getAttribute( 'src' ) ) ) {
						++$captions;
					}
				} elseif ( 'details' === $tag ) {
					// A collapsed "Transcript" block — visible to a reader who opens
					// it, and always present in the text an assistant reads.
					if ( self::is_transcript_label( self::summary_text( $node ) ) ) {
						$transcript = true;
					}
				} elseif ( 'p' === $tag ) {
					$para_words   = self::word_count( $node->textContent );
					$paragraphs[] = $para_words;
					if ( $para_words > self::LONG_PARAGRAPH_WORDS ) {
						$long_paras[] = array(
							'words' => $para_words,
							'opens' => self::opening_words( $node->textContent ),
						);
					}
				} elseif ( 'a' === $tag ) {
					++$links;
					$link_words += self::word_count( $node->textContent );
					if ( self::is_outbound( (string) $node->getAttribute( 'href' ), $home_host ) ) {
						++$outbound;
					}
				} elseif ( 'img' === $tag ) {
					++$images;
					// A missing `alt` attribute is a real gap; alt="" is the standards-correct
					// marker for a decorative image (WAI) — intentional, so don't flag it.
					if ( ! $node->hasAttribute( 'alt' ) ) {
						++$images_no_alt;
						$name = self::image_name( (string) $node->getAttribute( 'src' ) );
						if ( '' !== $name ) {
							$no_alt_names[] = $name;
						}
					} elseif ( '' === trim( (string) $node->getAttribute( 'alt' ) ) ) {
						// ⛔⛔ alt="" AND A CAPTION. Empty alt is the WAI marker for a
						// picture that carries no meaning, and on its own it is still
						// respected above — a spacer, a rule, a decorative flourish is
						// never flagged here.
						//
						// A CAPTION is evidence against that reading, and it is the
						// author's own evidence: nobody writes a sentence underneath a
						// picture that says nothing. Found on heera.it 2026-08-25,
						// where one post had real alt on three screenshots of four and
						// a blank on the fourth, and another had blanks on two
						// screenshots that both carried captions. On WordPress the
						// editor leaves this field empty by default, so "decorative"
						// and "nobody filled it in" arrive looking identical — and
						// reading every one of them as a decision let a captioned
						// screenshot reach every screen reader undescribed while this
						// row said "Every image has alt text".
						if ( self::CAPTIONED_BLANK_ALT_IS_A_GAP && self::has_caption( $node ) ) {
							++$images_blank_alt;
							$blank_alt_names[] = self::library_name( (string) $node->getAttribute( 'src' ) );
						}
					} elseif ( self::is_filename_alt( (string) $node->getAttribute( 'alt' ), (string) $node->getAttribute( 'src' ) ) ) {
						++$images_slug_alt;
						// ⚠️ THE FILE, NOT THE ALT. Quoting the alt back looked right —
						// it is the string being replaced — but an owner cannot open a
						// string. He read "screen-shot-2016-09-15-at-5-00-13-am", went
						// to the media library and set the alt on a DIFFERENT picture
						// (the featured image), because nothing in the sentence said
						// which file to open. heera.it, 2026-08-18. For a file-name alt
						// the two are near-identical anyway, so the file wins: it is
						// what the library lists and what its search matches.
						$slug_alt_names[] = self::library_name( (string) $node->getAttribute( 'src' ) );
					}
				}
			}
		}

		// Sentence and syllable counts feed the reading-ease grade. Rough by
		// design — Flesch is a heuristic, not a measurement. It grades PROSE:
		// code samples aren't sentences and an identifier like
		// App\Http\Middleware\InputValidator is not a twelve-syllable word, so
		// code containers are stripped before this pass. (`words` above stays
		// whole-content — code IS substance on a tutorial page.)
		$prose_html  = (string) preg_replace( '@<(pre|code|kbd|samp)\b[^>]*>.*?</\1>@si', ' ', $html );
		$prose_text  = self::text_of( $prose_html );
		$prose_words = self::word_count( $prose_text );
		$sentences   = self::sentence_count( $prose_html );
		$syllables = 0;
		$long      = array(); // long-word frequencies: word => [count, syllables]
		foreach ( explode( ' ', trim( (string) preg_replace( '/\s+/', ' ', $prose_text ) ) ) as $token ) {
			$n          = self::syllables( $token );
			$syllables += $n;
			if ( $n >= self::FAMILIAR_MIN_SYLLABLES ) {
				// Keep internal hyphens so terms stay readable when a check names
				// them ("ai-generated", not "aigenerated"); case and punctuation
				// variants still merge.
				$word = trim( (string) preg_replace( '/[^a-z-]+/', '', strtolower( $token ) ), '-' );
				if ( ! isset( $long[ $word ] ) ) {
					$long[ $word ] = array( 0, $n );
				}
				++$long[ $word ][0];
			}
		}
		// Split the long words into the page's own recurring terms (priced as
		// familiar in the adjusted syllable count) and one-off heavy words (full
		// weight, surfaced so the author knows where the weight sits).
		$familiar_syllables = $syllables;
		$familiar_terms     = array();
		$heavy_words        = array();
		foreach ( $long as $word => $wf ) {
			if ( $wf[0] >= self::FAMILIAR_MIN_OCCURRENCES ) {
				$familiar_terms[ $word ] = $wf[0];
				$familiar_syllables    -= ( $wf[1] - self::FAMILIAR_COUNTED_SYLLABLES ) * $wf[0];
			} else {
				$heavy_words[ $word ] = $wf[0];
			}
		}
		arsort( $familiar_terms );
		arsort( $heavy_words );

		return array(
			'words'          => $words,
			'figures'        => $figures,
			'headings'       => $headings,   // heading levels in document order
			'heading_text'   => $heading_text, // their opening words, same order
			'paragraphs'     => $paragraphs, // per-paragraph word counts
			'long_paragraphs' => $long_paras, // only those over the limit: words + opening words
			'images_no_alt_names' => $no_alt_names,
			'images_slug_alt'     => $images_slug_alt,
			'images_slug_alt_names' => $slug_alt_names,
			'images_blank_alt'      => $images_blank_alt,
			'images_blank_alt_names' => $blank_alt_names,
			'links'          => $links,
			'link_words'     => $link_words,
			'outbound_links' => $outbound,
			'images'         => $images,
			'images_no_alt'  => $images_no_alt,
			'media'          => $media,
			'videos'         => count( array_filter( $media, static function ( $m ) { return 'audio' !== $m['kind']; } ) ),
			'audios'         => count( array_filter( $media, static function ( $m ) { return 'audio' === $m['kind']; } ) ),
			'video_captions' => $captions,
			'has_transcript' => (bool) $transcript,
			'has_excerpt'    => (bool) $has_excerpt,
			'sentences'      => $sentences,
			'prose_words'    => $prose_words,
			'syllables'      => $syllables,
			'familiar_syllables' => $familiar_syllables,
			'familiar_terms'     => array_slice( $familiar_terms, 0, 5, true ),
			'heavy_words'        => array_slice( $heavy_words, 0, 5, true ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Individual checks — each returns a { id, label, status, detail } row.
	 * ---------------------------------------------------------------------- */

	private static function check_words( array $s ) {
		$words = (int) $s['words'];

		// ⛔ Not an article — so its length is not this owner's to answer for.
		// {@see SUBSTANCE_ARTICLES_ONLY}. An ABSENT verdict judges normally: the
		// unknown case must leave the check running, never silently retire it.
		if ( self::SUBSTANCE_ARTICLES_ONLY && isset( $s['gradeable'] ) && ! $s['gradeable'] ) {
			return self::row(
				'words',
				__( 'Substance', 'agentimus' ),
				'pass',
				__( 'Not measured — this page is not written as an article. A blog index, a form, a cart or a product gets its words from whatever fills it, so its length is not yours to answer for.', 'agentimus' )
			);
		}

		if ( $words >= self::MIN_WORDS ) {
			return self::row(
				'words',
				__( 'Enough substance', 'agentimus' ),
				'pass',
				sprintf( /* translators: %d: word count. */ __( '%d words — enough for an AI assistant to extract and cite, and enough for a search engine to treat as a real page.', 'agentimus' ), $words )
			);
		}
		return self::row(
			'words',
			__( 'Not enough substance yet', 'agentimus' ),
			'warn',
			sprintf( /* translators: 1: word count, 2: the minimum. */ __( 'Only %1$d words. Below ~%2$d an AI assistant has little to work with, and a search engine sees a thin page — expand it or merge it into another.', 'agentimus' ), $words, self::MIN_WORDS )
		);
	}

	private static function check_summary( array $s ) {
		// Thin pages are already flagged; don't pile a summary warning on top.
		if ( (int) $s['words'] < self::MIN_WORDS ) {
			return self::row( 'summary', __( 'Opening summary', 'agentimus' ), 'pass', __( 'Not required on a short page.', 'agentimus' ) );
		}
		$lead = ! empty( $s['paragraphs'] ) ? (int) $s['paragraphs'][0] : 0;
		if ( ! empty( $s['has_excerpt'] ) || $lead >= self::SUMMARY_MIN_WORDS ) {
			return self::row( 'summary', __( 'Opening summary', 'agentimus' ), 'pass', __( 'Has an excerpt or a solid first paragraph — the line an AI assistant quotes and a search result shows.', 'agentimus' ) );
		}
		return self::row(
			'summary',
			__( 'No opening summary', 'agentimus' ),
			'warn',
			__( 'The page does not start with a clear summary. Add an excerpt, or a first paragraph that says what the page is about — it is the line assistants quote and search results show.', 'agentimus' )
		);
	}

	private static function check_evidence( array $s ) {
		// Short pages aren't expected to marshal figures or sources.
		if ( (int) $s['words'] < self::EVIDENCE_MIN_WORDS ) {
			return self::row( 'evidence', __( 'Backed with specifics', 'agentimus' ), 'pass', __( 'Short enough not to need figures or sources.', 'agentimus' ) );
		}
		$figures  = (int) ( isset( $s['figures'] ) ? $s['figures'] : 0 );
		$outbound = (int) ( isset( $s['outbound_links'] ) ? $s['outbound_links'] : 0 );
		if ( $figures >= 2 || $outbound >= 1 ) {
			return self::row( 'evidence', __( 'Backed with specifics', 'agentimus' ), 'pass', __( 'Carries figures or cited sources — the kind of specifics an AI assistant can quote and attribute.', 'agentimus' ) );
		}
		return self::row(
			'evidence',
			__( 'Short on specifics', 'agentimus' ),
			'warn',
			__( 'No figures, dates, or outbound sources. Specifics are what an AI assistant lifts and credits, and what a reader trusts — add a statistic, a concrete detail, or a link to a source you build on.', 'agentimus' )
		);
	}

	private static function check_sources( array $s ) {
		// Sharper than the evidence check: figures can make a page QUOTABLE, but
		// only outbound references make it read VERIFIABLE — engines favour pages
		// that show where their facts come from.
		if ( (int) $s['words'] < self::SOURCES_MIN_WORDS ) {
			return self::row( 'sources', __( 'Cited sources', 'agentimus' ), 'pass', __( 'Short enough not to need references.', 'agentimus' ) );
		}
		$outbound = (int) ( isset( $s['outbound_links'] ) ? $s['outbound_links'] : 0 );
		if ( $outbound >= 1 ) {
			return self::row( 'sources', __( 'Cited sources', 'agentimus' ), 'pass', sprintf( /* translators: %d: outbound link count. */ _n( '%d outbound link — the page shows where its facts come from.', '%d outbound links — the page shows where its facts come from.', (int) $outbound, 'agentimus' ), $outbound ) );
		}
		return self::row(
			'sources',
			__( 'No outbound sources', 'agentimus' ),
			'warn',
			__( 'A long page with no links to outside sources gives readers no way to check its facts. AI assistants prefer pages that show their sources — link the material you build on.', 'agentimus' )
		);
	}

	private static function check_headings( array $s ) {
		if ( (int) $s['words'] < self::HEADINGS_MIN_WORDS || ! empty( $s['headings'] ) ) {
			return self::row( 'headings', __( 'Section headings', 'agentimus' ), 'pass', empty( $s['headings'] ) ? __( 'Short enough to read without headings.', 'agentimus' ) : sprintf( /* translators: %d: heading count. */ _n( '%d heading gives the page navigable structure.', '%d headings give the page navigable structure.', count( $s['headings'] ), 'agentimus' ), count( $s['headings'] ) ) );
		}
		return self::row(
			'headings',
			__( 'No headings', 'agentimus' ),
			'warn',
			__( 'A long page with no headings is one big block of text. Add H2/H3 headings so an AI assistant can find and quote each part, and so readers and search engines can see how the page is organised.', 'agentimus' )
		);
	}

	private static function check_heading_order( array $s ) {
		$texts = isset( $s['heading_text'] ) ? (array) $s['heading_text'] : array();
		$prev  = 0;
		foreach ( (array) $s['headings'] as $i => $level ) {
			if ( $prev && $level > $prev + 1 ) {
				// WHICH heading. "H2 → H4" is true and unactionable on a page with
				// nine headings; the words are what the owner scrolls to find.
				$at = isset( $texts[ $i ] ) && '' !== $texts[ $i ]
					/* translators: %s: the heading's opening words, quoted. */
					? ' ' . sprintf( __( 'It happens at %s.', 'agentimus' ), self::quoted( $texts[ $i ] ) )
					: '';
				return self::row(
					'heading_order',
					__( 'Heading order', 'agentimus' ),
					'warn',
					sprintf( /* translators: 1: from level, 2: to level, 3: where it happens (may be empty). */ __( 'Heading levels jump (H%1$d → H%2$d).%3$s Don’t skip levels — it breaks the outline an AI assistant builds from the page, and the one a screen reader announces.', 'agentimus' ), (int) $prev, (int) $level, $at )
				);
			}
			$prev = (int) $level;
		}
		return self::row( 'heading_order', __( 'Heading order', 'agentimus' ), 'pass', __( 'Heading levels nest without skips.', 'agentimus' ) );
	}

	/**
	 * The first few words of a passage, so a row can say WHICH one it means.
	 * An owner searches the editor for these words; eight is enough to land on
	 * the right block and short enough to sit in a narrow meta box.
	 *
	 * @param string $text  Passage text.
	 * @param int    $limit How many words to keep.
	 * @return string
	 */
	/**
	 * Curly quotes around a fragment — the panel already speaks in curly quotes,
	 * and straight ones beside them read as a different voice.
	 *
	 * @param string $text Fragment.
	 * @return string
	 */
	private static function quoted( $text ) {
		return '“' . $text . '”';
	}

	/**
	 * "a, b and c" — written the way a person says it. ⛔ Not a comma-joined
	 * machine list: this sentence is read aloud in an owner's head.
	 *
	 * @param array $items Already-formatted items.
	 * @return string
	 */
	private static function and_list( array $items ) {
		$items = array_values( array_filter( $items ) );
		$n     = count( $items );
		if ( $n < 2 ) {
			return $n ? $items[0] : '';
		}
		$last = array_pop( $items );
		/* translators: 1: all items but the last, comma-joined. 2: the last item. */
		return sprintf( __( '%1$s and %2$s', 'agentimus' ), implode( ', ', $items ), $last );
	}

	private static function opening_words( $text, $limit = 8 ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( '' === $text ) {
			return '';
		}
		$parts = explode( ' ', $text );
		if ( count( $parts ) <= $limit ) {
			return $text;
		}
		return implode( ' ', array_slice( $parts, 0, $limit ) ) . '…';
	}

	/**
	 * The file name as the media library lists it — WordPress's size suffix
	 * (-760x480) and edit suffix (-e1473894420914) stripped, so the name in the
	 * sentence is the name the owner can search for.
	 *
	 * @param string $src Image src attribute.
	 * @return string
	 */
	private static function library_name( $src ) {
		$src = (string) preg_replace( '/[?#].*$/', '', trim( (string) $src ) );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return '';
		}
		$name = rawurldecode( basename( $src ) );
		$ext  = '';
		if ( preg_match( '/(\.[a-z0-9]{2,5})$/i', $name, $m ) ) {
			$ext  = $m[1];
			$name = substr( $name, 0, -strlen( $ext ) );
		}
		$name = (string) preg_replace( '/-e\d{8,}$/', '', $name );
		$name = (string) preg_replace( '/-\d+x\d+$/', '', $name );
		$name .= $ext;
		return strlen( $name ) > 48 ? substr( $name, 0, 47 ) . '…' : $name;
	}

	/**
	 * Is this image captioned — does its author say, in writing, that it means
	 * something?
	 *
	 * ⭐ The evidence that an empty alt was a blank field rather than a
	 * decision. Deliberately narrow: the NEAREST enclosing figure, and only a
	 * caption with actual words in it. An empty `<figcaption>` (the block
	 * editor leaves one behind when a caption is deleted) proves nothing and is
	 * not allowed to accuse a picture.
	 *
	 * @param \DOMNode $node The img node.
	 * @return bool
	 */
	private static function has_caption( $node ) {
		for ( $parent = $node->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode ) {
			// ⛔⛔ TWO SHAPES, because WordPress emits two. `[caption]` becomes a
			// `<figure>` only where the theme declares html5 caption support;
			// everywhere else — a great many older themes — the very same
			// shortcode renders as `<div class="wp-caption">` with a
			// `<p class="wp-caption-text">` inside it. Looking only for figures
			// made this rule silently block-editor-and-modern-theme-only, which
			// is not a rule at all on half the sites that would install this.
			$box = 'figure' === strtolower( $parent->nodeName ) || self::has_class( $parent, 'wp-caption' );
			if ( ! $box ) {
				continue;
			}
			foreach ( $parent->getElementsByTagName( '*' ) as $child ) {
				$is_caption = 'figcaption' === strtolower( $child->nodeName ) || self::has_class( $child, 'wp-caption-text' );
				if ( $is_caption && '' !== trim( (string) $child->textContent ) ) {
					return true;
				}
			}
			// The nearest caption box has no words in it. A caption box inside a
			// caption box is not a thing, so nothing further out is worth asking.
			return false;
		}
		return false;
	}

	/**
	 * One class on an element, matched as a whole word.
	 *
	 * ⚠️ `strpos` would say `wp-caption-text` contains `wp-caption`, which would
	 * make the caption its own container and the container its own caption.
	 *
	 * @param \DOMElement $el    Element.
	 * @param string      $class Class to look for.
	 * @return bool
	 */
	private static function has_class( $el, $class ) {
		$attr = trim( (string) $el->getAttribute( 'class' ) );
		return '' !== $attr && in_array( $class, preg_split( '/\s+/', $attr ), true );
	}

	/**
	 * Does this alt text count as a DESCRIPTION? The one definition, shared.
	 *
	 * ⛔⛔ THE INVARIANT, and the bug that bought it (heera.it, 2026-08-25): a
	 * tool that guards on "already described" has to ask the same question the
	 * CHECKS ask, or it refuses the exact rows they raise. describe-image
	 * refused post 2621's featured image as already described while
	 * {@see check_featured_alt()} was flagging it — the alt was
	 * "WordPress-Hidden-Gems", the file name — and the refusal went on to tell
	 * the agent the checks were satisfied. A guard drawn tighter than its check
	 * turns the worklist into a list of rows nothing can close.
	 *
	 * So this is the only place that answers it. Anything a person actually
	 * wrote counts. An empty field does not, and neither does the file name
	 * wearing the attribute ({@see is_filename_alt()}). A rule added here
	 * reaches the checks and the write tools in the same commit — which is the
	 * whole reason it is one function and not two.
	 *
	 * ⭐ Public because {@see \Agentimus\Abilities\MediaWriter} is the other
	 * caller: the checks decide what "described" means, and the writer obeys
	 * them rather than keeping a second opinion.
	 *
	 * @param string $alt The stored alt text.
	 * @param string $src The image's file name or URL — what the file-name test
	 *                    compares against. Pass '' when it is genuinely unknown;
	 *                    a non-empty alt then simply counts.
	 * @return bool
	 */
	public static function counts_as_described( $alt, $src ) {
		$alt = trim( (string) $alt );
		return '' !== $alt && ! self::is_filename_alt( $alt, $src );
	}

	/**
	 * Alt text that is only the file name — "screen-shot-2016-09-15-at-5-00-13-am"
	 * on Screen-Shot-2016-09-15-at-5.00.13-AM.png. The attribute is present, so
	 * the old check called the image described and passed; an assistant reading
	 * it learns nothing at all. ⭐ Found on a real post (heera.it, 2026-08-18).
	 *
	 * ⛔ A matching name is NOT enough on its own: "red-fox-in-snow.jpg" with alt
	 * "Red fox in snow" is a good description that happens to match. The tell is
	 * that a DESCRIPTION HAS SPACES — so a spaceless alt that normalises to the
	 * file name is the file name, and anything a person wrote is left alone.
	 *
	 * @param string $alt The alt attribute.
	 * @param string $src The src attribute.
	 * @return bool
	 */
	private static function is_filename_alt( $alt, $src ) {
		$alt = trim( (string) $alt );
		if ( '' === $alt || preg_match( '/\s/u', $alt ) ) {
			return false;
		}
		$src = (string) preg_replace( '/[?#].*$/', '', trim( (string) $src ) );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return false;
		}
		$base = rawurldecode( basename( $src ) );
		$base = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $base ); // extension
		$base = (string) preg_replace( '/-e\d{8,}$/', '', $base );          // WordPress edit suffix
		$base = (string) preg_replace( '/-\d+x\d+$/', '', $base );         // WordPress size suffix
		if ( '' === $base ) {
			return false;
		}
		// ⛔ A SINGLE COMMON WORD IS NOT PROOF. alt="table" on table.png matches,
		// but a person may simply have typed the word — and "you copied the file
		// name" is then an accusation we cannot support. Only a multi-part slug
		// (screen-shot-2016-09-15-…, dsc-0091, img-4432) is machine-made beyond
		// doubt. Caught by scanning heera.it before shipping this: 2 pages
		// matched and one of them was this exact false positive.
		$slug = self::slugify( $alt );
		if ( false === strpos( $slug, '-' ) ) {
			return false;
		}
		return $slug === self::slugify( $base );
	}

	/**
	 * Lowercase, non-alphanumerics folded to single hyphens — so a file name and
	 * the slug WordPress made from it compare equal.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function slugify( $text ) {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $text ) ), '-' );
	}

	/**
	 * A picture's file name, as the media library shows it. Query strings and
	 * directories are noise here — the owner is scanning a list of names.
	 *
	 * @param string $src Image src attribute.
	 * @return string
	 */
	private static function image_name( $src ) {
		$src = trim( (string) $src );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return '';
		}
		$path = (string) preg_replace( '/[?#].*$/', '', $src );
		$name = rawurldecode( basename( $path ) );
		return strlen( $name ) > 48 ? substr( $name, 0, 47 ) . '…' : $name;
	}

	private static function check_passages( array $s ) {
		if ( (int) $s['words'] < self::MIN_WORDS || empty( $s['paragraphs'] ) ) {
			return self::row( 'passages', __( 'Quotable passages', 'agentimus' ), 'pass', __( 'Short enough to quote as-is.', 'agentimus' ) );
		}
		$longest = (int) max( (array) $s['paragraphs'] );
		if ( $longest > self::LONG_PARAGRAPH_WORDS ) {
			// ⚠️ stats() supplies the offenders; a hand-built stats array (tests,
			// and any caller that only has counts) still gets the old sentence
			// rather than an empty one. The COUNT comes from the same array the
			// warning came from, so the two can never disagree.
			$long  = isset( $s['long_paragraphs'] ) ? (array) $s['long_paragraphs'] : array();
			$opens = array();
			foreach ( $long as $para ) {
				if ( ! empty( $para['opens'] ) ) {
					$opens[] = $para['opens'];
				}
			}
			$count = count( $long );

			if ( $count > 1 ) {
				// ⭐ Only three openings are named. A page with nine long blocks
				// needs a rewrite, not a nine-item list in a narrow panel.
				$shown = array_slice( $opens, 0, 3 );
				$listed = self::and_list( array_map( array( __CLASS__, 'quoted' ), $shown ) );
				// ⛔ Not "…, and 2 more" bolted onto an and-list — "A, B and C, and
				// 2 more" is two conjunctions in one breath. Naming the three as
				// THE FIRST three says the same thing in one clause, and it is
				// true: they come in document order.
				$where = '';
				if ( $shown ) {
					$where = count( $opens ) > count( $shown )
						/* translators: %s: the opening words of the first three long paragraphs, already quoted and joined. */
						? sprintf( __( 'The first three start %s.', 'agentimus' ), $listed )
						/* translators: %s: the opening words of each long paragraph, already quoted and joined. */
						: sprintf( __( 'They start %s.', 'agentimus' ), $listed );
				}
				return self::row(
					'passages',
					sprintf( /* translators: %d: how many paragraphs are too long. */ __( '%d long blocks', 'agentimus' ), $count ),
					'warn',
					trim( sprintf(
						/* translators: 1: how many paragraphs, 2: the threshold, 3: the longest one's word count. */
						__( '%1$d paragraphs run over ~%2$d words. The longest is ~%3$d.', 'agentimus' ),
						$count,
						self::LONG_PARAGRAPH_WORDS,
						$longest
					) . ' ' . $where . ' ' . __( 'Break each one into shorter blocks, so an AI assistant can lift a clean, self-contained passage — and a reader can skim them.', 'agentimus' ) )
				);
			}

			$where = $opens
				? sprintf( /* translators: %s: the paragraph's opening words, quoted. */ __( 'It starts %s.', 'agentimus' ), self::quoted( $opens[0] ) ) . ' '
				: '';
			return self::row(
				'passages',
				__( 'One long block', 'agentimus' ),
				'warn',
				sprintf( /* translators: 1: word count, 2: where it starts (may be empty), 3: the threshold. */ __( 'A paragraph runs ~%1$d words. %2$sBreak blocks over ~%3$d into shorter ones, so an AI assistant can lift a clean, self-contained passage — and a reader can skim it.', 'agentimus' ), $longest, $where, self::LONG_PARAGRAPH_WORDS )
			);
		}
		return self::row( 'passages', __( 'Quotable passages', 'agentimus' ), 'pass', __( 'Paragraphs are a quotable length — easy to lift a clean passage from.', 'agentimus' ) );
	}

	private static function check_reading_ease( array $s ) {
		// Flesch Reading Ease — an ENGLISH formula; on other languages it grades
		// noise, so the check skips honestly rather than mis-scoring.
		if ( array_key_exists( 'english', $s ) && empty( $s['english'] ) ) {
			return self::row( 'reading_ease', __( 'Reading ease', 'agentimus' ), 'pass', __( 'The reading-ease formula only fits English — skipped for this site’s language.', 'agentimus' ) );
		}
		// A page that is mostly code may carry too little PROSE to grade, however
		// substantial it is — skip honestly rather than score a handful of lines.
		$gradable = (int) ( isset( $s['prose_words'] ) ? $s['prose_words'] : $s['words'] );
		if ( $gradable < self::MIN_WORDS ) {
			return self::row( 'reading_ease', __( 'Reading ease', 'agentimus' ), 'pass', __( 'Too short to grade.', 'agentimus' ) );
		}
		$score = self::reading_ease( $s );
		if ( $score >= self::READING_EASE_OK ) {
			return self::row( 'reading_ease', __( 'Reading ease', 'agentimus' ), 'pass', sprintf( /* translators: %d: Flesch score. */ __( 'Score %d — plain enough for a general audience, the kind of writing AI assistants quote most.', 'agentimus' ), (int) round( $score ) ) );
		}
		// A technical subject is not hard prose: rescore with the page's own
		// recurring terms priced as familiar. Clearing the bar HERE means the
		// writing around the vocabulary is plain — the raw formula was charging
		// the page for naming its own topic.
		$familiar = self::reading_ease_familiar( $s );
		$terms    = isset( $s['familiar_terms'] ) ? array_slice( array_keys( (array) $s['familiar_terms'] ), 0, 3 ) : array();
		if ( $familiar >= self::READING_EASE_OK && $terms ) {
			return self::row(
				'reading_ease',
				__( 'Reading ease', 'agentimus' ),
				'pass',
				sprintf(
					/* translators: 1: raw Flesch score, 2: adjusted score, 3: the page's recurring terms. */
					__( 'Score %1$d — %2$d with the page’s own recurring terms (%3$s) read as familiar. Plain prose about a technical subject.', 'agentimus' ),
					(int) round( $score ),
					(int) round( $familiar ),
					implode( ', ', $terms )
				)
			);
		}
		$band = $familiar < self::READING_EASE_HARD
			? __( 'university-level prose', 'agentimus' )
			: __( 'college-level prose', 'agentimus' );
		// floor, not round: a 49.5 must never display as "score 50" on a warn row
		// — the number shown should stay below the pass bar the row failed.
		// ⭐ WHICH HALF is holding the score down. Flesch has two terms — sentence
		// length and word length — and telling an author to shorten sentences
		// that already average eleven words is advice with nothing behind it.
		$sentences = (int) ( isset( $s['sentences'] ) ? $s['sentences'] : 0 );
		$prose     = (int) ( isset( $s['prose_words'] ) ? $s['prose_words'] : 0 );
		$per       = $sentences > 0 ? (int) round( $prose / $sentences ) : 0;

		$detail = ( $per > 0 && $per <= self::SENTENCE_PLAIN )
			? sprintf(
				/* translators: 1: Flesch score, 2: difficulty band, 3: average words per sentence. */
				__( 'Reading-ease score %1$d — %2$s. Your sentences are already short (%3$d words on average), so it is the vocabulary carrying this: plainer words, where the subject allows them, make passages easier for AI assistants to lift and for readers to trust.', 'agentimus' ),
				(int) floor( $familiar ),
				$band,
				$per
			)
			: sprintf(
				/* translators: 1: Flesch score, 2: difficulty band. */
				__( 'Reading-ease score %1$d — %2$s. Shorter sentences and plainer words make passages easier for AI assistants to lift and for readers to trust.', 'agentimus' ),
				(int) floor( $familiar ),
				$band
			);
		$heavy  = isset( $s['heavy_words'] ) ? array_slice( array_keys( (array) $s['heavy_words'] ), 0, 3 ) : array();
		if ( $heavy ) {
			$detail .= ' ' . sprintf(
				/* translators: %s: the page's heaviest words. */
				__( 'Heaviest words here: %s.', 'agentimus' ),
				implode( ', ', $heavy )
			);
		}
		return self::row( 'reading_ease', __( 'Low reading ease', 'agentimus' ), 'warn', $detail );
	}

	/**
	 * PURE: the Flesch Reading Ease score from the parsed counts.
	 * 206.835 − 1.015 × (words/sentences) − 84.6 × (syllables/words).
	 *
	 * @param array $s Stats with words/sentences/syllables.
	 * @return float
	 */
	public static function reading_ease( array $s ) {
		// prose_words matches the code-stripped text the sentence and syllable
		// counts came from; plain `words` is the whole-content fallback.
		$words     = max( 1, (int) ( isset( $s['prose_words'] ) ? $s['prose_words'] : $s['words'] ) );
		$sentences = max( 1, (int) ( isset( $s['sentences'] ) ? $s['sentences'] : 0 ) );
		$syllables = max( 1, (int) ( isset( $s['syllables'] ) ? $s['syllables'] : 0 ) );
		return 206.835 - 1.015 * ( $words / $sentences ) - 84.6 * ( $syllables / $words );
	}

	/**
	 * PURE: the reading-ease score with the page's recurring long words priced
	 * as familiar (see the FAMILIAR_* constants) — how the prose reads to
	 * someone who accepts the page's subject vocabulary. Falls back to the raw
	 * score when the parse carries no adjusted count.
	 *
	 * @param array $s Stats with words/sentences and familiar_syllables.
	 * @return float
	 */
	public static function reading_ease_familiar( array $s ) {
		if ( isset( $s['familiar_syllables'] ) ) {
			$s['syllables'] = $s['familiar_syllables'];
		}
		return self::reading_ease( $s );
	}

	private static function check_link_density( array $s ) {
		$words = (int) $s['words'];
		if ( $words >= 50 ) {
			$ratio = $s['link_words'] / max( 1, $words );
			if ( $ratio > self::LINK_DENSITY_MAX ) {
				return self::row(
					'link_density',
					__( 'Mostly links', 'agentimus' ),
					'warn',
					sprintf( /* translators: %d: percentage. */ __( 'About %d%% of the words are inside links — this reads more like navigation than content. Assistants and search engines both have little here to index or quote. Add prose or trim the link lists.', 'agentimus' ), (int) round( $ratio * 100 ) )
				);
			}
		}
		return self::row( 'link_density', __( 'Prose vs links', 'agentimus' ), 'pass', __( 'The page is mostly readable prose, not link lists.', 'agentimus' ) );
	}

	private static function check_alt_text( array $s ) {
		// An alt that is only the file name is an absence wearing the attribute:
		// it must name itself rather than pass. Reported after the truly missing
		// ones, because a missing description is the bigger gap.
		$slugged = (int) ( isset( $s['images_slug_alt'] ) ? $s['images_slug_alt'] : 0 );
		$slug_line = '';
		if ( $slugged > 0 ) {
			$slug_shown = array_slice( array_filter( (array) ( isset( $s['images_slug_alt_names'] ) ? $s['images_slug_alt_names'] : array() ) ), 0, 2 );
			// ⭐ Lead with the file when we have it: the first thing the owner does
			// is find the picture, and only then edit its text.
			if ( 1 === $slugged && $slug_shown ) {
				$slug_line = sprintf(
					/* translators: %s: the image file name, quoted. */
					__( '%s is described only by its file name, which tells an assistant nothing.', 'agentimus' ),
					self::quoted( $slug_shown[0] )
				) . ' ';
			} elseif ( $slug_shown ) {
				$slug_line = sprintf(
					/* translators: 1: how many images, 2: their file names, quoted and joined. */
					__( '%1$d images are described only by their file names, which tell an assistant nothing — %2$s.', 'agentimus' ),
					$slugged,
					self::and_list( array_map( array( __CLASS__, 'quoted' ), $slug_shown ) )
				) . ' ';
			} else {
				$slug_line = sprintf(
					/* translators: %d: how many images are described only by their file name. */
					_n(
						'%d image is described only by its file name, which tells an assistant nothing.',
						'%d images are described only by their file names, which tell an assistant nothing.',
						$slugged,
						'agentimus'
					),
					$slugged
				) . ' ';
			}
		}

		// ⛔ Captioned, and marked as meaning nothing. Reported in its own words
		// because the FIX is different: these images are not missing a field,
		// they are carrying an answer ("this is decorative") that the caption
		// underneath contradicts.
		$blank      = (int) ( isset( $s['images_blank_alt'] ) ? $s['images_blank_alt'] : 0 );
		$blank_line = '';
		if ( $blank > 0 ) {
			$blank_shown = array_slice( array_filter( (array) ( isset( $s['images_blank_alt_names'] ) ? $s['images_blank_alt_names'] : array() ) ), 0, 2 );
			if ( 1 === $blank && $blank_shown ) {
				$blank_line = sprintf(
					/* translators: %s: the image file name, quoted. */
					__( '%s is marked as decorative (its alt text is empty) but carries a caption — and a picture worth captioning is not one that means nothing.', 'agentimus' ),
					self::quoted( $blank_shown[0] )
				) . ' ';
			} elseif ( $blank_shown ) {
				$blank_line = sprintf(
					/* translators: 1: how many images, 2: their file names, quoted and joined. */
					__( '%1$d images are marked as decorative (their alt text is empty) but carry captions — and a picture worth captioning is not one that means nothing — %2$s.', 'agentimus' ),
					$blank,
					self::and_list( array_map( array( __CLASS__, 'quoted' ), $blank_shown ) )
				) . ' ';
			} else {
				$blank_line = sprintf(
					/* translators: %d: how many images are marked decorative but captioned. */
					_n(
						'%d image is marked as decorative (its alt text is empty) but carries a caption.',
						'%d images are marked as decorative (their alt text is empty) but carry captions.',
						$blank,
						'agentimus'
					),
					$blank
				) . ' ';
			}
		}

		if ( (int) $s['images_no_alt'] > 0 ) {
			// ⭐ WHICH picture. "1 of 3" sent the owner hunting through the post;
			// the file name is what they already see in the media library. Three
			// at most — past that the answer is "describe them all".
			$shown = array_slice( (array) ( isset( $s['images_no_alt_names'] ) ? $s['images_no_alt_names'] : array() ), 0, 3 );
			$names = $shown
				? sprintf(
					/* translators: %s: image file names, already quoted and joined. */
					__( 'No description on %s.', 'agentimus' ),
					self::and_list( array_map( array( __CLASS__, 'quoted' ), $shown ) )
				) . ' '
				: '';

			// ⚠️ "1 of 1 images has no alt text" — seen on a real post (heera.it,
			// 2026-08-18). Both numbers say one and the noun says many. When every
			// image is missing its description, "N of N" is arithmetic nobody
			// needs: say it plainly instead.
			$missing = (int) $s['images_no_alt'];
			$total   = (int) $s['images'];
			if ( $missing >= $total ) {
				// ⭐ One image, and we know its name: "The image has no alt text.
				// No description on “x.png”." says it twice. The name alone is the
				// shorter true sentence.
				$count_line = 1 === $total
					? ( $shown ? '' : __( 'The image has no alt text.', 'agentimus' ) )
					: sprintf(
						/* translators: %d: how many images are on the page. */
						__( 'None of the %d images has alt text.', 'agentimus' ),
						$total
					);
			} else {
				$count_line = sprintf(
					/* translators: 1: how many images lack alt text, 2: how many images in total. */
					_n( '%1$d of %2$d images has no alt text.', '%1$d of %2$d images have no alt text.', $missing, 'agentimus' ),
					$missing,
					$total
				);
			}
			return self::row(
				'alt_text',
				__( 'Image alt text', 'agentimus' ),
				'warn',
				trim( $count_line . ' ' . $names . $slug_line . $blank_line . __( 'AI assistants can’t read pixels. Neither can screen readers, and image search leans on the same words. Describe each image so its meaning survives.', 'agentimus' ) )
			);
		}
		if ( $slugged > 0 ) {
			return self::row(
				'alt_text',
				__( 'Image alt text', 'agentimus' ),
				'warn',
				trim( $slug_line . _n(
					'Replace its alt text with a short sentence about what the picture shows — for assistants, for screen readers and for image search alike.',
					'Replace their alt text with short sentences about what each picture shows — for assistants, for screen readers and for image search alike.',
					$slugged,
					'agentimus'
				) . ' ' . $blank_line )
			);
		}
		if ( $blank > 0 ) {
			return self::row(
				'alt_text',
				__( 'Image alt text', 'agentimus' ),
				'warn',
				trim( $blank_line . _n(
					'Describe it, or leave the alt text empty only if the picture really adds nothing a reader would miss.',
					'Describe them, or leave the alt text empty only where the picture really adds nothing a reader would miss.',
					$blank,
					'agentimus'
				) )
			);
		}

		$detail = (int) $s['images'] > 0 ? __( 'Every image has alt text.', 'agentimus' ) : __( 'No images to describe.', 'agentimus' );
		return self::row( 'alt_text', __( 'Image alt text', 'agentimus' ), 'pass', $detail );
	}

	/**
	 * Can an agent tell what this page's media is about?
	 *
	 * Deliberately NOT "is there a transcript?". A transcript is one way to
	 * answer, not the requirement — and it belongs to whatever tool the owner
	 * already uses for it. Any of four things settles this row, and the row says
	 * which one did.
	 *
	 * @param array $s Stats.
	 * @return array
	 */
	private static function check_media( array $s ) {
		$videos = (int) ( isset( $s['videos'] ) ? $s['videos'] : 0 );
		$audios = (int) ( isset( $s['audios'] ) ? $s['audios'] : 0 );
		$total  = $videos + $audios;

		$label = __( 'Video & audio', 'agentimus' );
		if ( $total < 1 ) {
			return self::row( 'media', $label, 'pass', __( 'No video or audio on this page.', 'agentimus' ) );
		}

		$noun = self::media_noun( $videos, $audios );

		// Every item is described — the outcome this row exists to produce.
		$described = (int) ( isset( $s['media_described'] ) ? $s['media_described'] : 0 );
		if ( $described >= $total ) {
			return self::row(
				'media',
				$label,
				'pass',
				sprintf( /* translators: %s: e.g. "2 videos". */ __( 'Every item has a line of context, so an assistant knows what it holds (%s).', 'agentimus' ), $noun )
			);
		}

		// A transcript already published on the page — by any tool — answers it too.
		if ( ! empty( $s['has_transcript'] ) ) {
			return self::row( 'media', $label, 'pass', __( 'The page carries a transcript, so what is said here can be quoted.', 'agentimus' ) );
		}

		$words = (int) $s['words'];
		if ( $words >= self::VIDEO_MIN_WORDS ) {
			return self::row(
				'media',
				$label,
				'pass',
				sprintf( /* translators: %d: word count. */ __( '%d words of readable text accompany it — the page stands on its own.', 'agentimus' ), $words )
			);
		}

		// Nothing answers it. Ask for the cheapest thing that would.
		$missing = $total - $described;
		if ( $described > 0 ) {
			return self::row(
				'media',
				__( 'Media without context', 'agentimus' ),
				'warn',
				sprintf(
					/* translators: 1: how many items lack a line, 2: e.g. "3 videos". */
					_n(
						'%1$d of %2$s still has no line of context. An assistant reads no sound or picture — describe it so it knows what is there.',
						'%1$d of %2$s still have no line of context. An assistant reads no sound or picture — describe them so it knows what is there.',
						$missing,
						'agentimus'
					),
					$missing,
					$noun
				)
			);
		}

		$detail = (int) ( isset( $s['video_captions'] ) ? $s['video_captions'] : 0 ) > 0
			// Captions exist but reach nothing: name the shorter next step.
			? __( 'A captions file is attached but nothing on the page says what this is about. Add a line of context, or publish those words as a transcript.', 'agentimus' )
			: __( 'An assistant reads no sound or picture, so this page tells it almost nothing. Select each one in the editor and say what it is about.', 'agentimus' );

		return self::row(
			'media',
			__( 'Media without context', 'agentimus' ),
			'warn',
			sprintf( /* translators: 1: e.g. "3 videos", 2: the advice. */ __( '%1$s and %2$d words. %3$s', 'agentimus' ), ucfirst( $noun ), (int) $s['words'], $detail )
		);
	}

	/**
	 * "2 videos", "a podcast and 1 video" — a plain phrase for what a page holds.
	 *
	 * @param int $videos Video count.
	 * @param int $audios Audio count.
	 * @return string
	 */
	private static function media_noun( $videos, $audios ) {
		$parts = array();
		if ( $videos > 0 ) {
			/* translators: %d: number of videos. */
			$parts[] = sprintf( _n( '%d video', '%d videos', $videos, 'agentimus' ), $videos );
		}
		if ( $audios > 0 ) {
			/* translators: %d: number of audio items. */
			$parts[] = sprintf( _n( '%d audio item', '%d audio items', $audios, 'agentimus' ), $audios );
		}
		return implode( __( ' and ', 'agentimus' ), $parts );
	}

	/**
	 * Whether an iframe src points at a video player. Hosts only — the path and
	 * query differ per provider and prove nothing.
	 *
	 * Public because {@see Markdown} names the same players in the plain-text
	 * edition and must agree with this check about what counts as one.
	 *
	 * @param string $src The iframe's src attribute.
	 * @return bool
	 */
	public static function is_video_embed( $src ) {
		$host = (string) wp_parse_url( trim( (string) $src ), PHP_URL_HOST );
		if ( '' === $host ) {
			return false;
		}
		$host = strtolower( preg_replace( '~^www\.~i', '', $host ) );

		foreach ( self::video_hosts() as $known ) {
			$known = strtolower( ltrim( (string) $known, '.' ) );
			if ( '' !== $known && ( $host === $known || substr( $host, -strlen( '.' . $known ) ) === '.' . $known ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The hosts recognised as video players.
	 *
	 * Public and separate from {@see is_video_embed()} because the block-editor
	 * panel needs the same list to recognise a raw <iframe> inside an HTML block,
	 * and a second copy of it living in JavaScript would drift from this one.
	 *
	 * @return string[]
	 */
	public static function video_hosts() {
		/**
		 * Filter the hosts recognised as video players. A match is by suffix, so
		 * "youtube.com" also covers "www.youtube.com" and "player.youtube.com".
		 *
		 * @param array<int,string> $hosts Video player hosts.
		 */
		return (array) apply_filters(
			'agentimus_video_hosts',
			array(
				// Consumer platforms.
				'youtube.com',
				'youtube-nocookie.com',
				'youtu.be',
				'vimeo.com',
				'dailymotion.com',
				'ted.com',
				'twitch.tv',
				'tiktok.com',
				'rumble.com',
				'odysee.com',
				'bitchute.com',
				'kick.com',
				'streamable.com',
				// Facebook and Instagram are deliberately ABSENT. This list matches on
				// host alone, and a raw facebook.com iframe is far more often a like
				// button or a page plugin than a video — counting those would produce
				// confident, wrong advice. Their actual videos come through the embed
				// block, where WordPress's own `is-type-video` classification catches
				// them properly.
				// Business / hosted video.
				'wistia.net',
				'wistia.com',
				'loom.com',
				'videopress.com',
				'video.wordpress.com',
				'brightcove.net',
				'jwplayer.com',
				'vidyard.com',
				'sproutvideo.com',
				'vidalytics.com',
				'panopto.com',
				'kaltura.com',
				'api.video',
				'muse.ai',
				// Infrastructure players — the URL is the only thing that names them.
				'videodelivery.net',      // Cloudflare Stream.
				'cloudflarestream.com',
				'mediadelivery.net',      // Bunny Stream.
				'stream.mux.com',
			)
		);
	}

	/**
	 * Every playable item in some rendered HTML, in document order.
	 *
	 * The single detection pass the whole feature reads: {@see stats()} counts
	 * from it, the editor lists from it, and Schema and Markdown describe from it
	 * — so the checker, the panel and the machine surfaces can never disagree
	 * about what media a page holds.
	 *
	 * @param string $html Rendered content HTML.
	 * @return array<int,array{kind:string,url:string,name:string,embed:bool,key:string}>
	 */
	public static function media_items( $html ) {
		$dom = self::dom( (string) $html );
		return $dom ? self::media_items_from_dom( $dom ) : array();
	}

	/**
	 * {@see media_items()} against an already-parsed document.
	 *
	 * @param \DOMDocument $dom Parsed document.
	 * @return array<int,array{kind:string,url:string,name:string,embed:bool,key:string}>
	 */
	private static function media_items_from_dom( $dom ) {
		$found = array();
		$seen  = array();

		foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
			$tag  = strtolower( $node->nodeName );
			$item = null;

			// An embed block owns whatever player is inside it, so it is resolved as
			// a whole and its descendants are skipped — otherwise one embed would
			// yield both a figure and an iframe for the same media.
			if ( 'figure' === $tag ) {
				$item = self::embed_figure_video( $node );
				if ( $item ) {
					$item['kind'] = 'video';
				}
			} elseif ( self::inside_embed_block( $node ) ) {
				continue;
			} elseif ( 'iframe' === $tag ) {
				// A standalone <iframe> is hand-written markup, and what the author
				// put inside it is their business, not this plugin's. Only WordPress's
				// own embeds are graded — the block above — so the plugin never
				// second-guesses markup somebody wrote deliberately.
				continue;
			} elseif ( 'video' === $tag || 'audio' === $tag ) {
				// Audio is first class here. A podcast episode has exactly the same
				// problem a silent video does — the words do not exist for anything
				// that cannot listen — and it is the commonest case of all.
				$src = trim( (string) $node->getAttribute( 'src' ) );
				if ( '' === $src ) {
					foreach ( $node->childNodes as $child ) {
						if ( XML_ELEMENT_NODE === $child->nodeType && 'source' === strtolower( $child->nodeName ) ) {
							$src = trim( (string) $child->getAttribute( 'src' ) );
							if ( '' !== $src ) {
								break;
							}
						}
					}
				}
				if ( '' !== $src ) {
					$item = array(
						'kind'  => $tag,
						'url'   => $src,
						'name'  => trim( (string) $node->getAttribute( 'title' ) ),
						'embed' => false,
						// core/video's own Poster image control renders here. It is
						// THIS video's still, chosen by the author — the only honest
						// thumbnail available without asking them for a second one.
						'poster' => trim( (string) $node->getAttribute( 'poster' ) ),
					);
				}
			}

			if ( ! $item ) {
				continue;
			}
			$item['key']     = self::media_key( $item['url'] );
			$item['caption'] = self::figure_caption( $node );
			if ( ! isset( $item['poster'] ) ) {
				$item['poster'] = '';
			}
			if ( isset( $seen[ $item['key'] ] ) ) {
				continue; // The same media twice on a page is one item.
			}
			$seen[ $item['key'] ] = true;
			$found[]              = $item;
		}

		return $found;
	}

	/**
	 * A figure's text with its `<figcaption>` left out.
	 *
	 * @param \DOMNode $figure Figure node.
	 * @return string
	 */
	private static function figure_text_without_caption( $figure ) {
		$out = '';
		foreach ( $figure->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'figcaption' === strtolower( $child->nodeName ) ) {
				continue;
			}
			$out .= ' ' . $child->textContent;
		}
		return trim( $out );
	}

	/**
	 * The caption an author wrote under a piece of media, or ''.
	 *
	 * Worth reading because it is already a description of the media, written by
	 * the person who put it there and visible to the reader — which makes it a
	 * far better default than anything this plugin could infer.
	 *
	 * @param \DOMNode $node The media node, or the figure holding it.
	 * @return string
	 */
	private static function figure_caption( $node ) {
		// Walk up to the figure when we were handed the player itself.
		$figure = $node;
		if ( 'figure' !== strtolower( $figure->nodeName ) ) {
			for ( $p = $node->parentNode, $depth = 0; $p && $depth < 4; $p = $p->parentNode, $depth++ ) {
				if ( XML_ELEMENT_NODE === $p->nodeType && 'figure' === strtolower( $p->nodeName ) ) {
					$figure = $p;
					break;
				}
			}
		}
		if ( ! method_exists( $figure, 'getElementsByTagName' ) ) {
			return '';
		}

		foreach ( $figure->getElementsByTagName( 'figcaption' ) as $caption ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', $caption->textContent ) );
			if ( '' !== $text ) {
				return $text;
			}
		}
		return '';
	}

	/**
	 * A stable identity for one piece of media, so a note written against it
	 * survives the URL changing shape.
	 *
	 * It has to, because the SAME video has two addresses depending on whether
	 * WordPress has resolved the embed yet: the block stores
	 * `youtube.com/watch?v=ID`, the resolved player is `youtube.com/embed/ID`,
	 * and a note keyed on one would be invisible to the other.
	 *
	 * The rule is deliberately provider-agnostic — a table of "where YouTube puts
	 * its id" would need extending for every service that ever exists. Instead:
	 * the host, plus the last path segment that isn't structural scaffolding
	 * ("embed", "iframe", "watch"…), falling back to the shortest id-shaped query
	 * value. On both YouTube forms that yields `ID`; on both Vimeo forms,
	 * the numeric id; on a Cloudflare Stream `/{id}/iframe`, the id.
	 *
	 * @param string $url Media URL.
	 * @return string
	 */
	public static function media_key( $url ) {
		$url  = trim( (string) $url );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		// The last two labels, not the whole host: the same video is served from
		// `vimeo.com` in the block and `player.vimeo.com` once resolved, and from
		// `customer-<account>.cloudflarestream.com` per account. Keying on the full
		// host would make one video look like two. The id below is what actually
		// distinguishes items, so a coarse host costs nothing.
		$labels = array_values( array_filter( explode( '.', $host ), 'strlen' ) );
		if ( count( $labels ) > 2 ) {
			$labels = array_slice( $labels, -2 );
		}
		$host = implode( '.', $labels );

		/**
		 * Filter the path segments treated as scaffolding rather than identity.
		 *
		 * @param string[] $words Lowercased segment names to skip.
		 */
		$skip = (array) apply_filters(
			'agentimus_media_key_skip_segments',
			array( 'embed', 'embeds', 'iframe', 'watch', 'video', 'videos', 'v', 'e', 'player', 'view', 'oembed' )
		);

		$id   = '';
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		for ( $i = count( $segments ) - 1; $i >= 0; $i-- ) {
			$segment = $segments[ $i ];
			if ( in_array( strtolower( $segment ), $skip, true ) ) {
				continue;
			}
			if ( preg_match( '~^[A-Za-z0-9_.-]{6,}$~', $segment ) ) {
				$id = $segment;
				break;
			}
		}

		if ( '' === $id ) {
			// The id lives in the query string (`watch?v=…`). Prefer the SHORTEST
			// id-shaped value: on a YouTube watch URL the playlist id is the longer
			// one and is not the video's identity.
			$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			parse_str( $query, $params );
			foreach ( (array) $params as $value ) {
				if ( ! is_string( $value ) || ! preg_match( '~^[A-Za-z0-9_-]{6,}$~', $value ) ) {
					continue;
				}
				if ( '' === $id || strlen( $value ) < strlen( $id ) ) {
					$id = $value;
				}
			}
		}

		if ( '' === $id ) {
			// Nothing id-shaped anywhere — fall back to the whole address, so two
			// genuinely different items still get two keys.
			$id = md5( $url );
		}

		return ( '' !== $host ? $host : 'local' ) . ':' . $id;
	}

	/**
	 * The player an embed-block figure represents, or null.
	 *
	 * Providers disagree about where they put the video's address, so this looks
	 * in all four places they use: a resolved `<iframe>`, a bare URL left in the
	 * figure's text, the `cite` attribute of the `<blockquote>` that script-based
	 * providers render (TikTok and Instagram both do this — the URL appears in no
	 * text node at all), and finally a link in the body.
	 *
	 * Shared so {@see Markdown} and {@see Schema} can never disagree about what a
	 * figure is or where it points.
	 *
	 * @param \DOMNode $figure A `<figure>` node.
	 * @return array{url:string,name:string,embed:bool}|null
	 */
	public static function embed_figure_video( $figure ) {
		if ( ! method_exists( $figure, 'getAttribute' ) ) {
			return null;
		}
		$class = (string) $figure->getAttribute( 'class' );
		if ( false === strpos( $class, 'wp-block-embed' ) ) {
			return null;
		}

		// WordPress's own classification. When it is absent we fall back to the
		// host list, so an unclassified figure still resolves if we know the host.
		$is_video = false !== strpos( $class, 'is-type-video' );

		// A resolved player carries the real title — always prefer it.
		foreach ( $figure->getElementsByTagName( 'iframe' ) as $frame ) {
			$src = trim( (string) $frame->getAttribute( 'src' ) );
			if ( '' !== $src && ( $is_video || self::is_video_embed( $src ) ) ) {
				return array(
					'url'   => $src,
					'name'  => trim( (string) $frame->getAttribute( 'title' ) ),
					'embed' => true,
				);
			}
		}

		// NOT $figure->textContent: a captioned embed concatenates its bare URL and
		// its caption with no separator, so "…/abc12345" + "A short walkthrough"
		// reads as "…/abc12345A" and every caption silently corrupted the video's
		// identity. The caption is prose about the media, never its address.
		$text = self::figure_text_without_caption( $figure );
		$url  = self::embedded_video_url( $text );

		if ( '' === $url && $is_video ) {
			// WordPress says this is a video, so an address we don't recognise is
			// still the video's address.
			if ( preg_match( '~https?://[^\s<>"\']+~i', $text, $m ) ) {
				$url = rtrim( $m[0], '.,;:)' );
			}
		}

		if ( '' === $url ) {
			foreach ( $figure->getElementsByTagName( 'blockquote' ) as $quote ) {
				$cite = trim( (string) $quote->getAttribute( 'cite' ) );
				if ( '' !== $cite && ( $is_video || self::is_video_embed( $cite ) ) && preg_match( '~^https?://~i', $cite ) ) {
					$url = $cite;
					break;
				}
			}
		}

		if ( '' === $url ) {
			foreach ( $figure->getElementsByTagName( 'a' ) as $link ) {
				$href = trim( (string) $link->getAttribute( 'href' ) );
				if ( '' !== $href && self::is_video_embed( $href ) ) {
					$url = $href;
					break;
				}
			}
		}

		if ( '' === $url ) {
			return null;
		}

		return array(
			'url'   => $url,
			'name'  => '', // Only a resolved player's markup carries a title.
			'embed' => true,
		);
	}

	/**
	 * Whether a node sits inside a `wp-block-embed` figure, which counts its own
	 * player. Walks up a handful of levels — the block's wrapper nesting is fixed
	 * and shallow.
	 *
	 * @param \DOMNode $node Node.
	 * @return bool
	 */
	public static function inside_embed_block( $node ) {
		for ( $p = $node->parentNode, $depth = 0; $p && $depth < 4; $p = $p->parentNode, $depth++ ) {
			if ( XML_ELEMENT_NODE !== $p->nodeType ) {
				continue;
			}
			if ( 'figure' === strtolower( $p->nodeName )
				&& false !== strpos( (string) $p->getAttribute( 'class' ), 'wp-block-embed' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The first video URL in a block of text ('' when there is none) — how an
	 * unresolved embed block names its video.
	 *
	 * @param string $text Text content.
	 * @return string
	 */
	public static function embedded_video_url( $text ) {
		if ( ! preg_match_all( '~https?://[^\s<>"\']+~i', (string) $text, $m ) ) {
			return '';
		}
		foreach ( $m[0] as $url ) {
			$url = rtrim( $url, '.,;:)' );
			if ( self::is_video_embed( $url ) ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * The <summary> text of a <details> element ('' when it has none).
	 *
	 * @param \DOMNode $node The <details> node.
	 * @return string
	 */
	private static function summary_text( $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'summary' === strtolower( $child->nodeName ) ) {
				return (string) $child->textContent;
			}
		}
		return '';
	}

	/**
	 * Whether a label names a transcript. Deliberately loose about what surrounds
	 * the word ("Full transcript", "Transcript of the talk") and deliberately
	 * strict about length, so a paragraph merely mentioning transcripts is never
	 * mistaken for one.
	 *
	 * Public because {@see MediaContext} pulls the words out of the very sections
	 * this recognises, and the two must never disagree about what one is.
	 *
	 * @param string $text Heading or summary text.
	 * @return bool
	 */
	public static function is_transcript_label( $text ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		if ( '' === $text || strlen( $text ) > 60 ) {
			return false;
		}

		/**
		 * Filter the pattern that recognises a transcript label, for sites that
		 * write the heading in another language.
		 *
		 * @param string $pattern A full preg pattern, matched against the label.
		 */
		$pattern = (string) apply_filters( 'agentimus_transcript_label_pattern', '~\btranscript(ion)?\b~i' );

		return (bool) preg_match( $pattern, $text );
	}

	/**
	 * Does the featured image carry a description of its own? Separate from
	 * {@see check_featured_image()} on purpose: "No featured image" and "the
	 * featured image has no description" are different problems with different
	 * fixes, and one row cannot wear both labels honestly.
	 *
	 * @param array $s Page stats.
	 * @return array|null Null when there is nothing to judge — normalize() drops it.
	 */
	private static function check_featured_alt( array $s ) {
		$label = __( 'Featured image description', 'agentimus' );

		// ⛔ NO ROW AT ALL when there is nothing to judge — no featured image, a
		// type that has none, or a caller that did not read the attachment. A
		// green "nothing to check here" sitting directly under "No featured
		// image" is two rows discussing one absence.
		if ( empty( $s['featured_expected'] ) || empty( $s['featured'] ) || ! array_key_exists( 'featured_alt', $s ) ) {
			return null;
		}

		$alt  = trim( (string) $s['featured_alt'] );
		$file = (string) ( isset( $s['featured_file'] ) ? $s['featured_file'] : '' );
		$name = '' !== $file ? self::quoted( $file ) : __( 'the featured image', 'agentimus' );

		// What the SERVED page does with a description — for pages that have one,
		// and for pages that don't. Empty means nobody has looked, and every
		// branch below falls back to the claim that needs no fetch.
		$served_described = (string) ( isset( $s['served_alt'] ) ? $s['served_alt'] : '' );
		$served_bare      = (string) ( isset( $s['served_alt_bare'] ) ? $s['served_alt_bare'] : '' );

		// ⭐⭐ THE FALSE PASS. The owner wrote a description, and their theme
		// serves the article's title instead — so the picture reaches every
		// assistant and screen reader undescribed while the media library looks
		// perfect. Nothing could see this until the page itself was read.
		if ( '' !== $alt && ThemeImageProbe::USES_TITLE === $served_described ) {
			return self::row(
				'featured_alt',
				__( 'Featured image not described', 'agentimus' ),
				'warn',
				sprintf(
					/* translators: %s: the image file name, quoted. */
					__( 'You described %s, but your theme serves the post title as the description instead — it does not use the media library’s alt text at all. The description you wrote never reaches a reader. This is a theme fix, not a content one.', 'agentimus' ),
					$name
				)
			);
		}

		if ( '' === $alt ) {
			// The picture has no description of its own. What that MEANS on the
			// page depends on the theme, and now we know which case this is.
			if ( ThemeImageProbe::USES_NOTHING === $served_bare ) {
				return self::row(
					'featured_alt',
					__( 'Featured image not described', 'agentimus' ),
					'fail',
					sprintf(
						/* translators: %s: the image file name, quoted. */
						__( '%s reaches readers with no description at all: your theme was checked on one of this site’s own pages and it prints an empty alt where a picture has none of its own. Add alt text in the media library; assistants, screen readers and image search all rely on it.', 'agentimus' ),
						$name
					)
				);
			}
			if ( ThemeImageProbe::USES_TITLE === $served_bare ) {
				return self::row(
					'featured_alt',
					__( 'Featured image not described', 'agentimus' ),
					'warn',
					sprintf(
						/* translators: %s: the image file name, quoted. */
						__( '%s has no description of its own, so your theme stands the post title in its place — which describes the article, not the picture. Add alt text in the media library and the theme will use it.', 'agentimus' ),
						$name
					)
				);
			}
			// Nobody has read the served page yet: the narrowed claim, which is
			// true whatever the theme turns out to do.
			return self::row(
				'featured_alt',
				__( 'Featured image not described', 'agentimus' ),
				'warn',
				sprintf(
					/* translators: %s: the image file name, quoted. */
					__( '%s has no description of its own. Add alt text in the media library — assistants, screen readers and image search all rely on it, and a theme that falls back to the post title describes the article, not the picture.', 'agentimus' ),
					$name
				)
			);
		}

		if ( self::is_filename_alt( $alt, $file ) ) {
			return self::row(
				'featured_alt',
				__( 'Featured image not described', 'agentimus' ),
				'warn',
				sprintf(
					/* translators: %s: the image file name, quoted. */
					__( '%s is described only by its file name, which tells an assistant nothing. Replace its alt text with a short sentence about what the picture shows.', 'agentimus' ),
					$name
				)
			);
		}

		// Described, and — where the page has been read — described where it
		// counts. The second sentence is only added when it was actually checked;
		// a pass that claims more than was measured is the fault this whole probe
		// exists to end.
		return self::row(
			'featured_alt',
			$label,
			'pass',
			ThemeImageProbe::USES_LIBRARY === $served_described
				? __( 'The featured image carries its own description, and this site’s pages serve it.', 'agentimus' )
				: __( 'The featured image carries its own description.', 'agentimus' )
		);
	}

	private static function check_featured_image( array $s ) {
		// Only expected where the content type and theme actually offer one —
		// skip honestly instead of nagging about a box that isn't there.
		if ( empty( $s['featured_expected'] ) ) {
			return self::row( 'featured_image', __( 'Featured image', 'agentimus' ), 'pass', __( 'This content type doesn’t use featured images — nothing to check.', 'agentimus' ) );
		}
		if ( ! empty( $s['featured'] ) ) {
			return self::row( 'featured_image', __( 'Featured image', 'agentimus' ), 'pass', __( 'Set — link previews and embeds have a picture to show for this page.', 'agentimus' ) );
		}
		return self::row(
			'featured_image',
			__( 'No featured image', 'agentimus' ),
			'warn',
			__( 'Without a featured image, link previews and embeds have no picture to show for this page. Choose an image that stands for it. This row grades the saved post — it updates when you save or publish.', 'agentimus' )
		);
	}

	private static function check_freshness( array $s ) {
		$age = (int) ( isset( $s['age_days'] ) ? $s['age_days'] : 0 );
		// Evergreen content (owner-marked categories) is timeless — a reference, tutorial,
		// or legal page doesn't go stale with age, so it's exempt from the age check.
		if ( ! empty( $s['evergreen'] ) ) {
			return self::row( 'freshness', __( 'Freshness', 'agentimus' ), 'pass', __( 'Evergreen — exempt from the freshness check.', 'agentimus' ) );
		}
		// Only nag substantive content, and only when we actually know its age.
		if ( (int) $s['words'] < self::MIN_WORDS || $age <= 0 ) {
			return self::row( 'freshness', __( 'Freshness', 'agentimus' ), 'pass', __( 'Nothing to flag.', 'agentimus' ) );
		}
		if ( $age > self::STALE_DAYS ) {
			$months = (int) round( $age / 30 );
			return self::row(
				'freshness',
				__( 'Getting stale', 'agentimus' ),
				'warn',
				sprintf( /* translators: %d: months since last update. */ __( 'Last updated about %d months ago. AI assistants favour current sources, and so does search — a refresh (even a dated review note) helps this page stay citable.', 'agentimus' ), $months )
			);
		}
		return self::row( 'freshness', __( 'Freshness', 'agentimus' ), 'pass', __( 'Recently enough updated to read as current.', 'agentimus' ) );
	}

	/* ---------------------------------------------------------------------- *
	 *  Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Whether a post sits in an owner-marked evergreen category, exempting it from the
	 * freshness check. Timeless content (references, tutorials, legal) shouldn't read as
	 * "stale" just for being old.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	private static function is_evergreen( \WP_Post $post ) {
		$cats = ( new Settings() )->get( 'evergreen_categories', array() );
		/**
		 * Category term IDs whose posts are exempt from the freshness check.
		 *
		 * @param int[]    $cats Term IDs.
		 * @param \WP_Post $post The post being considered.
		 */
		$cats = array_values( array_filter( array_map( 'intval', (array) apply_filters( 'agentimus_evergreen_categories', $cats, $post ) ) ) );
		if ( empty( $cats ) ) {
			return false;
		}
		return (bool) has_category( $cats, $post );
	}

	/** The site's own host (lowercased), for classifying links as outbound sources. */
	private static function home_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ); // home_url() only exists at runtime; this never runs in the pure stats() unit path.
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/** Days since a post was last modified; 0 when unknown. */
	private static function age_days( \WP_Post $post ) {
		$stamp = ( isset( $post->post_modified_gmt ) && '0000-00-00 00:00:00' !== $post->post_modified_gmt )
			? strtotime( $post->post_modified_gmt . ' UTC' )
			: 0;
		if ( ! $stamp ) {
			return 0;
		}
		return max( 0, (int) floor( ( time() - $stamp ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Whether a link points off-site — an absolute http(s) URL to a different host
	 * than the site's own. Relative and same-host links are internal, not sources.
	 *
	 * @param string $href      The link's href.
	 * @param string $home_host The site's host, or '' to skip classification.
	 * @return bool
	 */
	private static function is_outbound( $href, $home_host ) {
		$href = trim( (string) $href );
		if ( '' === $home_host || ! preg_match( '#^https?://#i', $href ) ) {
			return false;
		}
		$host = wp_parse_url( $href, PHP_URL_HOST ); // href is already known-absolute; wp_parse_url is stubbed for the stats() unit path.
		return is_string( $host ) && strtolower( $host ) !== $home_host;
	}

	/**
	 * A summary count of the rows by status, for the meta box header.
	 *
	 * @param array<int,array<string,string>> $rows Normalized rows.
	 * @return array{pass:int,warn:int,fail:int}
	 */
	public static function summary( array $rows ) {
		$out = array( 'pass' => 0, 'warn' => 0, 'fail' => 0 );
		foreach ( $rows as $r ) {
			$status = isset( $r['status'] ) ? $r['status'] : 'warn';
			if ( isset( $out[ $status ] ) ) {
				++$out[ $status ];
			}
		}
		return $out;
	}

	private static function row( $id, $label, $status, $detail ) {
		return compact( 'id', 'label', 'status', 'detail' );
	}

	/**
	 * Coerce raw checks (including any a filter appended) into well-formed rows —
	 * an unknown status becomes `warn` rather than a silent pass.
	 *
	 * @param mixed $checks Raw checks.
	 * @return array<int,array<string,string>>
	 */
	private static function normalize( $checks ) {
		$valid = array( 'pass', 'warn', 'fail' );
		$out   = array();
		foreach ( (array) $checks as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$id = isset( $c['id'] ) ? (string) $c['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$status = isset( $c['status'] ) && in_array( $c['status'], $valid, true ) ? (string) $c['status'] : 'warn';
			$out[]  = array(
				'id'     => $id,
				'label'  => ( isset( $c['label'] ) && '' !== (string) $c['label'] ) ? (string) $c['label'] : $id,
				'status' => $status,
				'detail' => isset( $c['detail'] ) ? (string) $c['detail'] : '',
			);
		}
		return $out;
	}

	/**
	 * Count words in plain text (whitespace-delimited; unicode-safe enough for a
	 * rough substance measure).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function word_count( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		return '' === $text ? 0 : count( explode( ' ', $text ) );
	}

	/**
	 * PURE: sentence count for the reading-ease grade. Punctuation ends a
	 * sentence — and so does a block boundary (a paragraph, list item, heading,
	 * cell), because bullets and headings rarely carry a full stop, and gluing
	 * them into their neighbours grades list-heavy pages as one enormous
	 * "sentence".
	 *
	 * @param string $html Rendered content HTML.
	 * @return int
	 */
	private static function sentence_count( $html ) {
		// Closing block tags become hard boundaries before the tag-strip — a
		// marker survives text_of() where a bare space would glue neighbours.
		$html  = (string) preg_replace( '@</(?:p|li|h[1-6]|blockquote|figcaption|caption|dt|dd|td|th|pre)\s*>@i', "\x1E", (string) $html );
		$count = 0;
		foreach ( explode( "\x1E", self::text_of( $html ) ) as $block ) {
			$block = trim( (string) preg_replace( '/\s+/', ' ', $block ) );
			if ( ! preg_match( '/[\p{L}\p{N}]/u', $block ) ) {
				continue; // No words, no sentence — an empty cell or bare divider.
			}
			$count += (int) preg_match_all( '/[.!?]+(?:\s|$)/u', $block );
			if ( ! preg_match( '/[.!?]$/u', $block ) ) {
				++$count; // The block's tail is a sentence even without a full stop.
			}
		}
		return $count;
	}

	/**
	 * PURE: estimate an English word's syllables — vowel groups, minus the usual
	 * silent-e, floor of one. A heuristic feeding a heuristic (Flesch); tokens
	 * without ASCII letters (numbers, other scripts) count zero so they don't
	 * skew the grade.
	 *
	 * @param string $word One whitespace-delimited token.
	 * @return int
	 */
	private static function syllables( $word ) {
		$word = strtolower( (string) preg_replace( '/[^a-zA-Z]/', '', (string) $word ) );
		if ( '' === $word ) {
			return 0;
		}
		if ( strlen( $word ) <= 3 ) {
			return 1;
		}
		$word  = (string) preg_replace( '/(?<=[^aeiouyl])e$/', '', $word ); // silent e ("make"), keeping "-le" ("table").
		$count = (int) preg_match_all( '/[aeiouy]{1,2}/', $word );
		return max( 1, $count );
	}

	/**
	 * Plain text from HTML for word counting — tags become spaces (so adjacent
	 * block text like </p><p> doesn't glue into one fake word), and script/style
	 * bodies are dropped. Not for display; a rough substance measure only.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function text_of( $html ) {
		$html = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', ' ', (string) $html );
		$text = preg_replace( '/<[^>]+>/', ' ', (string) $html );
		return html_entity_decode( (string) $text, ENT_QUOTES );
	}

	/**
	 * Parse an HTML fragment into a DOMDocument, tolerating the malformed markup
	 * real content produces. Returns null when the fragment is empty or DOM is
	 * unavailable.
	 *
	 * @param string $html HTML.
	 * @return \DOMDocument|null
	 */
	public static function dom_of( $html ) {
		return self::dom( $html );
	}

	private static function dom( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html || ! class_exists( '\DOMDocument' ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		// Force UTF-8 and wrap the fragment so loadHTML has a single root.
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $dom;
	}
}
