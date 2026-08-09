<?php
/**
 * Turns clean post HTML into Gutenberg block markup.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * The compose contract keeps the vocabulary tiny — each element maps to exactly
 * one core block, serialised the way the editor itself serialises it. Loose
 * inline strays are first healed back into paragraphs; anything block-level
 * outside the vocabulary rides in a custom-HTML block (renders identically); on
 * any parse trouble the original HTML is returned unchanged so the Classic block
 * stays the safety net. Extracted from {@see Assistant} — which still exposes
 * `Assistant::blockify()` as the historical entry point — because this is a
 * self-contained pure-DOM unit with its own tests.
 */
final class BlockCompiler {

	/** @var string[] Inline tags that, sitting loose between blocks, belong to a sentence. */
	const INLINE_TAGS = array( 'a', 'strong', 'em', 'b', 'i', 'code', 'u', 's', 'sub', 'sup', 'mark', 'small', 'abbr', 'kbd', 'br' );

	/**
	 * Compile clean post HTML into block markup.
	 *
	 * @param string $content Clean post HTML (kses'd, figures already injected).
	 * @return string Block markup, or the original content when conversion can't run.
	 */
	public static function compile( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content || false !== strpos( $content, '<!-- wp:' ) || ! class_exists( \DOMDocument::class ) ) {
			return $content; // Empty, already blocks, or no DOM extension.
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$ok = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><div>' . $content . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		if ( ! $ok || ! $dom->documentElement ) {
			return $content;
		}

		self::heal_loose_inlines( $dom->documentElement );

		$blocks = array();
		foreach ( $dom->documentElement->childNodes as $node ) {
			$block = self::node_to_block( $node );
			if ( '' !== $block ) {
				$blocks[] = $block;
			}
		}
		return $blocks ? implode( "\n\n", $blocks ) : $content;
	}

	/**
	 * Re-home LOOSE inline nodes — a bare link/emphasis/text sitting between
	 * block elements. The model sometimes writes
	 * `<p>…hook into WordPress at the</p> <a>plugins_loaded</a> <p>action…</p>`,
	 * which renders as one sentence broken across three lines (and, before this,
	 * shipped the bare tag through the custom-HTML fallback). The repair follows
	 * the sentence: a loose run continues the previous paragraph when that
	 * paragraph is plainly unfinished (no terminal punctuation), and pulls the
	 * following paragraph in when IT plainly continues the sentence (starts
	 * lowercase). A run that is NOT mid-sentence just gains its own paragraph —
	 * the same rendering the browser's anonymous block gave it, but as a real,
	 * editable block. Mutates the parsed DOM in place, before block mapping.
	 *
	 * @param \DOMElement $root The parsed content's wrapping element.
	 */
	private static function heal_loose_inlines( \DOMElement $root ) {
		$doc   = $root->ownerDocument;
		$nodes = array();
		foreach ( $root->childNodes as $node ) {
			$nodes[] = $node; // Snapshot: the loop moves nodes around.
		}

		$open = null; // The paragraph currently absorbing a mid-sentence run.
		$last = null; // The previous significant top-level node.
		foreach ( $nodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType && '' === trim( $node->textContent ) ) {
				continue; // Formatting whitespace between blocks.
			}
			$tag   = XML_ELEMENT_NODE === $node->nodeType ? strtolower( $node->nodeName ) : '';
			$loose = XML_TEXT_NODE === $node->nodeType || in_array( $tag, self::INLINE_TAGS, true );

			if ( $loose ) {
				if ( ! $open ) {
					if ( $last instanceof \DOMElement && 'p' === strtolower( $last->nodeName ) && self::sentence_open( $last->textContent ) ) {
						$open = $last; // The run continues the unfinished sentence.
					} else {
						$open = $doc->createElement( 'p' ); // Standalone run: its own paragraph.
						$root->insertBefore( $open, $node );
						$last = $open;
					}
				}
				if ( $open->hasChildNodes() ) {
					$open->appendChild( $doc->createTextNode( ' ' ) ); // The seam the source line break stood for.
				}
				$open->appendChild( $node );
				continue;
			}

			if ( $open && 'p' === $tag
				&& self::sentence_open( $open->textContent )
				&& preg_match( '/^\p{Ll}/u', trim( $node->textContent ) ) ) {
				// The sentence runs on into this paragraph — fold it in.
				$open->appendChild( $doc->createTextNode( ' ' ) );
				while ( $node->firstChild ) {
					$open->appendChild( $node->firstChild );
				}
				$root->removeChild( $node );
				$open = null;
				continue;
			}

			$open = null;
			$last = $node;
		}
	}

	/**
	 * PURE: whether a paragraph's text ends mid-sentence — no terminal
	 * punctuation (closing quotes/brackets may trail it). Colons and
	 * semicolons count as terminal: "the following:" introduces a block,
	 * it doesn't continue into one.
	 *
	 * @param string $text The paragraph's plain text.
	 * @return bool
	 */
	private static function sentence_open( $text ) {
		$text = trim( (string) $text );
		return '' !== $text && ! preg_match( '/[.!?…:;]["\'\x{201D}\x{2019})\]]*$/u', $text );
	}

	/**
	 * One top-level DOM node → one serialised core block.
	 *
	 * @param \DOMNode $node A child of the parsed content root.
	 * @return string Block markup, or '' for ignorable nodes.
	 */
	private static function node_to_block( \DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( $node->textContent );
			return '' === $text ? '' : "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );
		switch ( $tag ) {
			case 'p':
				return "<!-- wp:paragraph -->\n<p>" . self::dom_inner_html( $node ) . "</p>\n<!-- /wp:paragraph -->";

			case 'h2':
				return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . self::dom_inner_html( $node ) . "</h2>\n<!-- /wp:heading -->";

			case 'h3':
				return "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . self::dom_inner_html( $node ) . "</h3>\n<!-- /wp:heading -->";

			case 'ul':
			case 'ol':
				$items = '';
				foreach ( $node->childNodes as $child ) {
					if ( XML_ELEMENT_NODE === $child->nodeType && 'li' === strtolower( $child->nodeName ) ) {
						$items .= "<!-- wp:list-item -->\n<li>" . self::dom_inner_html( $child ) . "</li>\n<!-- /wp:list-item -->\n";
					}
				}
				$attrs = 'ol' === $tag ? ' {"ordered":true}' : '';
				return '<!-- wp:list' . $attrs . " -->\n<" . $tag . " class=\"wp-block-list\">\n" . $items . '</' . $tag . ">\n<!-- /wp:list -->";

			case 'blockquote':
				// The quote block nests paragraph blocks; loose inline content
				// (a bare-text quote) collects into one.
				$inner = '';
				$loose = '';
				foreach ( $node->childNodes as $child ) {
					if ( XML_ELEMENT_NODE === $child->nodeType && 'p' === strtolower( $child->nodeName ) ) {
						if ( '' !== trim( $loose ) ) {
							$inner .= "<!-- wp:paragraph -->\n<p>" . trim( $loose ) . "</p>\n<!-- /wp:paragraph -->";
							$loose  = '';
						}
						$inner .= "<!-- wp:paragraph -->\n<p>" . self::dom_inner_html( $child ) . "</p>\n<!-- /wp:paragraph -->";
					} else {
						$loose .= $node->ownerDocument->saveHTML( $child );
					}
				}
				if ( '' !== trim( $loose ) ) {
					$inner .= "<!-- wp:paragraph -->\n<p>" . trim( $loose ) . "</p>\n<!-- /wp:paragraph -->";
				}
				return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . $inner . "</blockquote>\n<!-- /wp:quote -->";

			case 'figure':
				// Our injected image figure — recover the attachment id from the
				// wp-image-N class the editor itself uses.
				$img = $node->getElementsByTagName( 'img' )->item( 0 );
				$id  = 0;
				if ( $img && preg_match( '/wp-image-(\d+)/', (string) $img->getAttribute( 'class' ), $m ) ) {
					$id = (int) $m[1];
				}
				$attrs = $id > 0 ? sprintf( ' {"id":%d,"sizeSlug":"large","linkDestination":"none"}', $id ) : '';
				return '<!-- wp:image' . $attrs . " -->\n" . $node->ownerDocument->saveHTML( $node ) . "\n<!-- /wp:image -->";

			default:
				// Outside the contract's vocabulary: render verbatim through the
				// custom-HTML block — displays identically, still valid blocks.
				return "<!-- wp:html -->\n" . $node->ownerDocument->saveHTML( $node ) . "\n<!-- /wp:html -->";
		}
	}

	/**
	 * The inner HTML of a DOM node, serialised by its own document.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private static function dom_inner_html( \DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}
}
