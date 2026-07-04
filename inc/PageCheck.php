<?php
/**
 * Per-page content-quality checks — the per-post complement to the site-level
 * {@see Readiness} panel. Where Readiness asks "is the SITE set up for agents?",
 * this asks "is THIS page easy for an agent to read, section and cite?" and is
 * surfaced in the editor by {@see PageCheckMetaBox}.
 *
 * The analysis is pure: it inspects a post's rendered HTML (via
 * {@see Content::markdown_source()}) — headings, a lead summary, word count, link
 * density, image alt text — and returns pass/warn rows. No front-end output; it's
 * an authoring aid only. Each check is cheap and side-effect free.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class PageCheck {

	/** Below this word count a page is "thin" — little for an agent to extract. */
	const MIN_WORDS = 100;

	/** Only expect headings once content is long enough to need sectioning. */
	const HEADINGS_MIN_WORDS = 300;

	/** A lead paragraph of at least this many words counts as a summary. */
	const SUMMARY_MIN_WORDS = 15;

	/** Warn when linked words exceed this share of the prose (nav-heavy content). */
	const LINK_DENSITY_MAX = 0.35;

	/**
	 * Run the checks for a post.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return array<int,array<string,string>> Rows: { id, label, status, detail }.
	 */
	public static function analyze( \WP_Post $post ) {
		$has_excerpt = '' !== trim( (string) $post->post_excerpt );
		$stats       = self::stats( Content::markdown_source( $post ), $has_excerpt );

		$checks = array(
			self::check_words( $stats ),
			self::check_summary( $stats ),
			self::check_headings( $stats ),
			self::check_heading_order( $stats ),
			self::check_link_density( $stats ),
			self::check_alt_text( $stats ),
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
	 * Parse rendered HTML into the cheap counts the checks read. Pure — pass any
	 * HTML string (and whether the post has a manual excerpt) and it holds.
	 *
	 * @param string $html        Rendered content HTML.
	 * @param bool   $has_excerpt Whether the post has a manual excerpt.
	 * @return array<string,mixed>
	 */
	public static function stats( $html, $has_excerpt = false ) {
		$html  = (string) $html;
		$words = self::word_count( self::text_of( $html ) );

		$headings   = array();
		$paragraphs = array();
		$links      = 0;
		$link_words = 0;
		$images     = 0;
		$images_no_alt = 0;

		$dom = self::dom( $html );
		if ( $dom ) {
			foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
				$tag = strtolower( $node->nodeName );
				if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
					$headings[] = (int) $m[1];
				} elseif ( 'p' === $tag ) {
					$paragraphs[] = self::word_count( $node->textContent );
				} elseif ( 'a' === $tag ) {
					++$links;
					$link_words += self::word_count( $node->textContent );
				} elseif ( 'img' === $tag ) {
					++$images;
					if ( '' === trim( (string) $node->getAttribute( 'alt' ) ) ) {
						++$images_no_alt;
					}
				}
			}
		}

		return array(
			'words'         => $words,
			'headings'      => $headings,   // heading levels in document order
			'paragraphs'    => $paragraphs, // per-paragraph word counts
			'links'         => $links,
			'link_words'    => $link_words,
			'images'        => $images,
			'images_no_alt' => $images_no_alt,
			'has_excerpt'   => (bool) $has_excerpt,
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Individual checks — each returns a { id, label, status, detail } row.
	 * ---------------------------------------------------------------------- */

	private static function check_words( array $s ) {
		if ( (int) $s['words'] >= self::MIN_WORDS ) {
			return self::row( 'words', __( 'Enough substance', 'agentimus' ), 'pass', sprintf( /* translators: %d: word count. */ __( '%d words — enough for an agent to extract and cite.', 'agentimus' ), (int) $s['words'] ) );
		}
		return self::row(
			'words',
			__( 'Thin content', 'agentimus' ),
			'warn',
			sprintf( /* translators: 1: word count, 2: the minimum. */ __( 'Only %1$d words. Below ~%2$d an agent has little to work with — expand the page or merge it.', 'agentimus' ), (int) $s['words'], self::MIN_WORDS )
		);
	}

	private static function check_summary( array $s ) {
		// Thin pages are already flagged; don't pile a summary warning on top.
		if ( (int) $s['words'] < self::MIN_WORDS ) {
			return self::row( 'summary', __( 'Opening summary', 'agentimus' ), 'pass', __( 'Not required on a short page.', 'agentimus' ) );
		}
		$lead = ! empty( $s['paragraphs'] ) ? (int) $s['paragraphs'][0] : 0;
		if ( ! empty( $s['has_excerpt'] ) || $lead >= self::SUMMARY_MIN_WORDS ) {
			return self::row( 'summary', __( 'Opening summary', 'agentimus' ), 'pass', __( 'Has an excerpt or a substantive opening paragraph an agent can lift as the gist.', 'agentimus' ) );
		}
		return self::row(
			'summary',
			__( 'No opening summary', 'agentimus' ),
			'warn',
			__( 'The page opens without a clear lead. Add an excerpt or a first paragraph that states what it’s about, so an agent can grab the gist.', 'agentimus' )
		);
	}

	private static function check_headings( array $s ) {
		if ( (int) $s['words'] < self::HEADINGS_MIN_WORDS || ! empty( $s['headings'] ) ) {
			return self::row( 'headings', __( 'Section headings', 'agentimus' ), 'pass', empty( $s['headings'] ) ? __( 'Short enough to read without headings.', 'agentimus' ) : sprintf( /* translators: %d: heading count. */ __( '%d heading(s) give the page navigable structure.', 'agentimus' ), count( $s['headings'] ) ) );
		}
		return self::row(
			'headings',
			__( 'No headings', 'agentimus' ),
			'warn',
			__( 'A long page with no headings is one undifferentiated block. Add H2/H3 headings so an agent can section and quote it.', 'agentimus' )
		);
	}

	private static function check_heading_order( array $s ) {
		$prev = 0;
		foreach ( (array) $s['headings'] as $level ) {
			if ( $prev && $level > $prev + 1 ) {
				return self::row(
					'heading_order',
					__( 'Heading order', 'agentimus' ),
					'warn',
					sprintf( /* translators: 1: from level, 2: to level. */ __( 'Heading levels jump (H%1$d → H%2$d). Don’t skip levels — it garbles the outline an agent builds.', 'agentimus' ), (int) $prev, (int) $level )
				);
			}
			$prev = (int) $level;
		}
		return self::row( 'heading_order', __( 'Heading order', 'agentimus' ), 'pass', __( 'Heading levels nest without skips.', 'agentimus' ) );
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
					sprintf( /* translators: %d: percentage. */ __( 'About %d%% of the words are inside links — this reads more like navigation than content. Add prose or trim the link lists.', 'agentimus' ), (int) round( $ratio * 100 ) )
				);
			}
		}
		return self::row( 'link_density', __( 'Prose vs links', 'agentimus' ), 'pass', __( 'The page is mostly readable prose, not link lists.', 'agentimus' ) );
	}

	private static function check_alt_text( array $s ) {
		if ( (int) $s['images_no_alt'] > 0 ) {
			return self::row(
				'alt_text',
				__( 'Image alt text', 'agentimus' ),
				'warn',
				sprintf( /* translators: 1: missing count, 2: total. */ __( '%1$d of %2$d image(s) have no alt text. Agents can’t read pixels — describe each image so its meaning survives.', 'agentimus' ), (int) $s['images_no_alt'], (int) $s['images'] )
			);
		}
		$detail = (int) $s['images'] > 0 ? __( 'Every image has alt text.', 'agentimus' ) : __( 'No images to describe.', 'agentimus' );
		return self::row( 'alt_text', __( 'Image alt text', 'agentimus' ), 'pass', $detail );
	}

	/* ---------------------------------------------------------------------- *
	 *  Helpers
	 * ---------------------------------------------------------------------- */

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
