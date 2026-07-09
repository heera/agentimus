<?php
/**
 * HTML → Markdown rendering for the agent endpoints.
 *
 * Walks the DOM rather than running regexes so nested lists, links inside
 * emphasis, code blocks, etc. survive. Good enough for prose; not a full
 * CommonMark serializer.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Markdown {

	/**
	 * A single post/page rendered as standalone markdown with small front matter.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function post( $post_id ) {
		$post = get_post( $post_id );
		// Never expose what the HTML site itself gates: non-published statuses and
		// password-protected posts — whose body would otherwise be dumped in full
		// to any anonymous client via .md, Accept negotiation, or /llms-full.txt.
		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return "# Not found\n";
		}

		$title = self::text( get_the_title( $post ) );
		$url   = get_permalink( $post );

		$out  = '# ' . $title . "\n\n";
		$meta = array();
		if ( 'post' === $post->post_type ) {
			$meta[] = get_the_date( 'Y-m-d', $post );
			$author = get_the_author_meta( 'display_name', $post->post_author );
			if ( $author ) {
				$meta[] = 'by ' . $author;
			}
			$cats = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
				$meta[] = 'in ' . implode( ', ', $cats );
			}
		}
		$meta[] = '<' . $url . '>';
		$out   .= '*' . implode( ' · ', $meta ) . "*\n\n";

		// Per-page AI description as a lead blockquote — a one-line summary so an agent
		// fetching this .md knows what the page is before reading the body, even when the
		// JSON-LD stands down for an SEO plugin. Same defensive contract as the topics/body
		// below: this serves UNAUTHENTICATED endpoints, so a filter that throws must degrade
		// to the rest of the document, never fatal the request.
		try {
			$summary = Description::for_post( $post );
		} catch ( \Throwable $e ) {
			$summary = '';
		}
		if ( '' !== $summary ) {
			$out .= '> ' . self::text( $summary ) . "\n\n";
		}

		// Per-page AI topics as a front-matter line — an agent fetching this .md gets
		// what the page is about even when the JSON-LD stands down for an SEO plugin.
		// Topics::for_post() runs the `agentimus_topics` filter (third-party code), and
		// the body below runs `the_content` — every shortcode, block and page-builder
		// callback on the site. This method serves UNAUTHENTICATED endpoints (/slug.md,
		// `Accept: text/markdown`, and each item of /llms-full.txt), so a single post
		// whose filters throw must degrade to title + metadata, never fatal the request
		// (or, in the full-text loop, take the whole document down with it).
		try {
			$topics = Topics::for_post( $post );
		} catch ( \Throwable $e ) {
			$topics = array();
		}
		if ( ! empty( $topics ) ) {
			$out .= 'Topics: ' . implode( ', ', $topics ) . "\n\n";
		}

		try {
			$out .= self::from_html( Content::markdown_source( $post ) );
		} catch ( \Throwable $e ) {
			// A shortcode/block/page-builder callback inside the_content threw — serve
			// the title + metadata already assembled rather than 500 the endpoint.
			$out = rtrim( $out );
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Convert a fragment of HTML to clean markdown.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function from_html( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return '';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return trim( wp_strip_all_tags( $html ) ) . "\n";
		}

		$dom  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><div id="agentimus-md-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$root = $dom->getElementById( 'agentimus-md-root' );
		$md   = $root ? self::children( $root ) : '';

		$md = preg_replace( "/[ \t]+\n/", "\n", $md );
		$md = preg_replace( "/\n{3,}/", "\n\n", $md );
		return trim( $md ) . "\n";
	}

	/**
	 * Concatenate the markdown of a node's children.
	 *
	 * @param \DOMNode $node Parent node.
	 * @return string
	 */
	private static function children( $node ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::node( $child );
		}
		return $out;
	}

	/**
	 * Render one DOM node to markdown.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private static function node( $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return preg_replace( '/\s+/', ' ', $node->nodeValue );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag   = strtolower( $node->nodeName );
		$inner = self::children( $node );

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				return "\n\n" . str_repeat( '#', $level ) . ' ' . trim( $inner ) . "\n\n";

			case 'p':
				return "\n\n" . trim( $inner ) . "\n\n";

			case 'br':
				return "  \n";

			case 'hr':
				return "\n\n---\n\n";

			case 'strong':
			case 'b':
				$t = trim( $inner );
				return '' === $t ? '' : '**' . $t . '**';

			case 'em':
			case 'i':
				$t = trim( $inner );
				return '' === $t ? '' : '*' . $t . '*';

			case 'del':
			case 's':
				$t = trim( $inner );
				return '' === $t ? '' : '~~' . $t . '~~';

			case 'code':
				return '`' . trim( $node->textContent ) . '`';

			case 'pre':
				return "\n\n```\n" . rtrim( $node->textContent ) . "\n```\n\n";

			case 'a':
				$href = trim( (string) $node->getAttribute( 'href' ) );
				$text = trim( $inner );
				if ( '' === $href ) {
					return $text;
				}
				return '[' . ( '' !== $text ? $text : $href ) . '](' . $href . ')';

			case 'img':
				$src = trim( (string) $node->getAttribute( 'src' ) );
				$alt = trim( (string) $node->getAttribute( 'alt' ) );
				return '' === $src ? '' : '![' . $alt . '](' . $src . ')';

			case 'blockquote':
				$t = trim( $inner );
				return "\n\n" . preg_replace( '/^/m', '> ', $t ) . "\n\n";

			case 'ul':
			case 'ol':
				return "\n\n" . self::list_node( $node, $tag ) . "\n\n";

			case 'li':
				return $inner; // Composed by list_node().

			case 'figure':
			case 'figcaption':
			case 'div':
			case 'section':
			case 'article':
			case 'header':
			case 'footer':
			case 'main':
				$t = trim( $inner );
				return '' === $t ? '' : "\n\n" . $t . "\n\n";

			default:
				return $inner;
		}
	}

	/**
	 * Render a <ul>/<ol> to markdown, handling nesting via indentation.
	 *
	 * @param \DOMNode $node Node.
	 * @param string   $type ul|ol.
	 * @return string
	 */
	private static function list_node( $node, $type ) {
		$lines = array();
		$i     = 1;
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}
			$marker  = 'ol' === $type ? ( $i++ . '.' ) : '-';
			$content = trim( self::children( $child ) );
			$content = preg_replace( "/\n/", "\n  ", $content );
			$lines[] = $marker . ' ' . $content;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Plain text from HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function text( $html ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
