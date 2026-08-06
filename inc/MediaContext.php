<?php
/**
 * Media context — a line about each video or audio item on a page, so an agent
 * knows what the media is, and the page's own switch for "this is meant to be
 * watched, not read".
 *
 * A player element carries no words at all. An `<iframe>` renders to nothing in
 * the plain-text edition and contributes nothing to {@see PageCheck}, so a post
 * built around a video or a podcast is, to every machine Agentimus serves, an
 * empty post.
 *
 * WHAT THIS IS NOT: a transcript store. Transcripts belong to whoever already
 * owns them — Fluent Player, the video platform's own captions, a dedicated
 * transcript plugin, or the author typing them into the post. Agentimus neither
 * holds them nor renders them; it *detects* one wherever it already is and
 * credits it. Owning that content would put this plugin in the business of
 * writing to the front end, competing with tools built for the job, and forcing
 * a show-it-or-hide-it choice nobody needed to make.
 *
 * What Agentimus does instead is the job nothing else does: carry the media's
 * meaning into the machine surfaces. One sentence per item — what it is, what
 * it covers — keyed to the item itself, so it reaches `VideoObject.description`
 * for the right video and the `.md` twin beside the right player. A page with
 * six videos gets six notes, not one ambiguous block at the bottom.
 *
 * The notes are written in the block editor, on the media itself: select a video
 * and its own inspector carries the field, so you always see what you are
 * describing. Nothing is rendered into the admin sidebar and nothing is written
 * to the front end.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class MediaContext {

	/** @var string Post meta: map of media key => the owner's line about it. */
	const META = '_agentimus_media_context';

	/** @var int Hard cap on one context line (chars). A sentence or two, not an essay —
	 *  the words that describe media are metadata, and the media's own words belong
	 *  in a transcript, which this class deliberately does not own. */
	const MAX_LEN = 600;

	/** @var int Most items one post may carry notes for. */
	const MAX_ITEMS = 50;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the post meta, the editor save hook and the cache-busting hooks.
	 * There is no front-end hook: this class never writes to a page.
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'editor_assets' ) );
		}

		// A pure REST meta write does not always fire save_post, and save_post is
		// what busts the generated caches — flush on our meta changing too, so the
		// .md twin and the JSON-LD can never serve a stale note.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_on_meta' ), 10, 3 );
		}
	}

	/**
	 * Register the post meta on every agent-visible post type.
	 */
	public static function register_meta() {
		$auth = array( __CLASS__, 'can_edit' );

		foreach ( Content::post_types() as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'type'              => 'object',
					'single'            => true,
					'default'           => array(),
					'show_in_rest'      => array(
						'schema' => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'string' ),
						),
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_map' ),
					'auth_callback'     => $auth,
				)
			);
		}
	}

	/* ---------------------------------------------------------------------- *
	 *  Reading.
	 * ---------------------------------------------------------------------- */

	/**
	 * The stored notes for a post, exactly as saved: media URL => context line.
	 *
	 * Keyed by the URL itself rather than by {@see PageCheck::media_key()}. That
	 * matters: the block-editor panel writes these notes straight into post meta
	 * from JavaScript, and a computed key would mean a second copy of the
	 * normalisation rules living in JS, drifting from the PHP the moment either
	 * changed. Storing the plain URL keeps the algorithm in one language;
	 * {@see resolve()} does the matching on the way out.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	public static function all( $post_id ) {
		$map = get_post_meta( (int) $post_id, self::META, true );
		return is_array( $map ) ? array_filter( array_map( 'strval', $map ), 'strlen' ) : array();
	}

	/**
	 * The stored notes re-keyed by media identity, so a note written against the
	 * block's URL is found once WordPress resolves the embed to a different one.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string> media key => note.
	 */
	public static function resolve( $post_id ) {
		$out = array();
		foreach ( self::all( $post_id ) as $url => $note ) {
			$out[ PageCheck::media_key( $url ) ] = $note;
		}
		return $out;
	}

	/**
	 * The note for one media URL ('' when there is none).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Media URL.
	 * @return string
	 */
	public static function for_url( $post_id, $url ) {
		$map = self::resolve( $post_id );
		$key = PageCheck::media_key( $url );
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * The media on a post, each with whatever note the owner wrote for it.
	 *
	 * Reads the post's OWN block render rather than the full `the_content` chain:
	 * that is the form the editor sees and the form notes are keyed against, and
	 * it runs no third-party filters.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return array<int,array{kind:string,url:string,name:string,embed:bool,key:string,context:string}>
	 */
	public static function items_for( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}

		$content = (string) $post->post_content;
		$max     = (int) apply_filters( 'agentimus_media_max_bytes', 256 * 1024 );
		if ( $max > 0 && strlen( $content ) > $max ) {
			return array();
		}

		$items = PageCheck::media_items( self::rendered_html( $post ) );
		$notes = self::resolve( $post->ID );

		$titles = self::oembed_titles( $post );

		foreach ( $items as $i => $item ) {
			$note = isset( $notes[ $item['key'] ] ) ? $notes[ $item['key'] ] : '';

			// The provider's own title for this video, from the oEmbed HTML WordPress
			// already cached on the post. Without it every unresolved embed is
			// nameless, and the surfaces fall back to the POST's title — which on a
			// page with five videos states, five times, that each one is the post.
			if ( '' === $item['name'] && isset( $titles[ $item['key'] ] ) ) {
				$items[ $i ]['name'] = $titles[ $item['key'] ];
				$item['name']        = $titles[ $item['key'] ];
			}

			// A caption the author already wrote under the media is a description of
			// it, in their words, visible to the reader — a far better default than
			// anything this plugin could infer, and one that stays in step with the
			// page because it is never copied anywhere. The written note wins; the
			// caption fills in behind it.
			$items[ $i ]['context'] = '' !== $note ? $note : $item['caption'];
			$items[ $i ]['source']  = '' !== $note ? 'note' : ( '' !== $item['caption'] ? 'caption' : '' );
		}

		return $items;
	}

	/**
	 * The post's own block render, memoised for the request.
	 *
	 * Deliberately NOT the full `the_content` chain: this is the form the editor
	 * sees and notes are keyed against, and it runs no third-party filters.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function rendered_html( $post ) {
		static $cache = array();

		// Keyed by the CONTENT, not the post id: the same post can be asked about
		// twice in one request either side of an edit, and a stale render would
		// then describe media the post no longer has. md5 costs nothing next to
		// do_blocks, which is what this exists to avoid repeating.
		$content = (string) $post->post_content;
		$key     = md5( $content );

		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = function_exists( 'do_blocks' ) ? do_blocks( $content ) : $content;
		}
		return $cache[ $key ];
	}

	/**
	 * Whether something else on this page already describes its video in JSON-LD.
	 *
	 * Detects the SYMPTOM, never the plugin: any `application/ld+json` carrying a
	 * VideoObject, wherever it came from. A table of plugin names would be wrong
	 * the day one of them renamed a class or a new one shipped, and it would say
	 * nothing about the plugin written next year.
	 *
	 * It only sees emitters that write inline with their player — which is where
	 * a player plugin naturally puts it, since the markup and the schema describe
	 * the same block. Anything emitting in `wp_head` or `wp_footer` runs after we
	 * do and cannot be seen from here; {@see Schema::video_nodes()} offers a
	 * filter for those.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function foreign_video_schema( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}

		$html = self::rendered_html( $post );
		if ( false === stripos( $html, 'ld+json' ) ) {
			return false; // The overwhelmingly common case, answered by one strpos.
		}

		if ( ! preg_match_all( '~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m ) ) {
			return false;
		}
		foreach ( $m[1] as $json ) {
			if ( false !== stripos( $json, 'VideoObject' ) || false !== stripos( $json, 'AudioObject' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Media titles from the oEmbed responses WordPress cached on this post.
	 *
	 * Purely local: `_oembed_*` post meta is the provider HTML from a fetch that
	 * already happened, so reading it costs nothing and never touches the network
	 * — the rule every path in this feature keeps.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,string> media key => title.
	 */
	private static function oembed_titles( $post ) {
		$out = array();

		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 !== strpos( (string) $key, '_oembed_' ) || 0 === strpos( (string) $key, '_oembed_time_' ) ) {
				continue;
			}
			$html = is_array( $values ) ? (string) reset( $values ) : (string) $values;
			if ( '' === $html || false === strpos( $html, '<' ) ) {
				continue; // "{{unknown}}" — a fetch that failed.
			}
			if ( ! preg_match( '~\ssrc=["\']([^"\']+)["\']~i', $html, $src ) ) {
				continue;
			}
			if ( ! preg_match( '~\stitle=["\']([^"\']+)["\']~i', $html, $title ) ) {
				continue;
			}
			$name = trim( html_entity_decode( $title[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $name ) {
				$out[ PageCheck::media_key( $src[1] ) ] = $name;
			}
		}

		return $out;
	}

	/**
	 * Every context line on a post, joined — what the page tells an agent about
	 * its media, as one body of text. Used to price the notes as substance.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return string
	 */
	public static function text_for( $post ) {
		$lines = array();
		foreach ( self::items_for( $post ) as $item ) {
			if ( '' !== $item['context'] ) {
				$lines[] = $item['context'];
			}
		}
		return implode( "\n\n", $lines );
	}

	/**
	 * A transcript already published on the page, wherever it came from — a
	 * `<details>` block, a "Transcript" section, Fluent Player's own output. We
	 * read it; we never write it.
	 *
	 * @param string $html Rendered content HTML.
	 * @return string Plain text.
	 */
	public static function on_page_transcript( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}
		$dom = PageCheck::dom_of( $html );
		if ( ! $dom ) {
			return '';
		}

		foreach ( $dom->getElementsByTagName( 'details' ) as $details ) {
			$summary = '';
			$body    = '';
			foreach ( $details->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType && 'summary' === strtolower( $child->nodeName ) ) {
					$summary = (string) $child->textContent;
					continue;
				}
				$body .= ' ' . self::node_text( $child );
			}
			if ( PageCheck::is_transcript_label( $summary ) ) {
				$text = self::tidy( $body );
				if ( '' !== $text ) {
					return $text;
				}
			}
		}

		foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
			if ( ! preg_match( '/^h([1-6])$/', strtolower( $node->nodeName ), $m ) ) {
				continue;
			}
			if ( ! PageCheck::is_transcript_label( $node->textContent ) ) {
				continue;
			}
			$level = (int) $m[1];
			$body  = '';
			for ( $sib = $node->nextSibling; $sib; $sib = $sib->nextSibling ) {
				if ( XML_ELEMENT_NODE === $sib->nodeType
					&& preg_match( '/^h([1-6])$/', strtolower( $sib->nodeName ), $mm )
					&& (int) $mm[1] <= $level ) {
					break;
				}
				$body .= ' ' . self::node_text( $sib );
			}
			$text = self::tidy( $body );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	/* ---------------------------------------------------------------------- *
	 *  The block panel — where a note is actually written.
	 * ---------------------------------------------------------------------- */

	/**
	 * The block types that get a context field in their own inspector.
	 *
	 * @return string[]
	 */
	public static function blocks() {
		/**
		 * Filter the block types offered a media-context field.
		 *
		 * @param string[] $blocks Block names.
		 */
		return (array) apply_filters( 'agentimus_media_context_blocks', array( 'core/embed', 'core/video', 'core/audio' ) );
	}

	/**
	 * Enqueue the block-editor panel on post screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function editor_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( (string) $screen->post_type, Content::post_types(), true ) ) {
			return;
		}

		wp_register_script(
			'agentimus-media-context',
			false,
			array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-i18n' ),
			AGENTIMUS_VERSION,
			true
		);
		wp_enqueue_script( 'agentimus-media-context' );
		wp_add_inline_script(
			'agentimus-media-context',
			'var _agentimusMedia = ' . wp_json_encode(
				array(
					'meta'    => self::META,
					'blocks'  => array_values( self::blocks() ),
					// The one copy of the mark, from Admin — the panel injects it so
					// the inspector carries the same badge as every Agentimus box.
					'icon'    => Admin::brand_icon( 20 ),
					'maxLen'  => self::MAX_LEN,
					'title'   => __( 'Context for AI', 'agentimus' ),
					'label'   => __( 'What is this about?', 'agentimus' ),
					'help'    => __( 'A player carries no words. One line here is what an assistant reads instead of a blank rectangle — it reaches the page\'s structured data and its plain-text edition. Transcripts stay wherever you already keep them.', 'agentimus' ),
					/* translators: %s: the caption the author wrote under this media. */
					'fallback' => __( 'If left blank, agents will use the caption: %s', 'agentimus' ),
					'noUrl'   => __( 'Add the video or audio first, then describe it here.', 'agentimus' ),
				)
			) . ';',
			'before'
		);
		wp_add_inline_script( 'agentimus-media-context', self::editor_js() );
	}

	/**
	 * The block-inspector panel.
	 *
	 * Writes straight into the post meta this class already owns, keyed by the
	 * block's own URL — no second copy of {@see PageCheck::media_key()} in
	 * JavaScript, which is the whole reason the notes are stored by URL.
	 *
	 * @return string
	 */
	private static function editor_js() {
		return <<<'JS'
( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.blockEditor || ! window._agentimusMedia ) { return; }

	var el  = wp.element.createElement;
	var cfg = window._agentimusMedia;

	/* The URL a media block points at. core/embed keeps it in `url`; the
	   self-hosted players keep it in `src`. Nothing is read out of raw markup:
	   a hand-written <iframe> is the author's own business. */
	function urlOf( props ) {
		var a = props.attributes || {};
		return ( a.url || a.src || '' ).trim();
	}

	wp.hooks.addFilter(
		'editor.BlockEdit',
		'agentimus/media-context',
		wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
			return function ( props ) {
				/* Hooks run on EVERY render, before any early return. `isSelected`
				   flips over a block's life, so returning above these would change
				   the hook order between renders — React's one hard rule, and the
				   reason the field looked like it worked while saving nothing. The
				   block-name check is safe here: a component instance never changes
				   block type. InspectorControls already renders only for the
				   selected block, so no isSelected test is needed at all. */
				var meta = wp.data.useSelect( function ( s ) {
					var ed = s( 'core/editor' );
					return ( ed && ed.getEditedPostAttribute( 'meta' ) ) || {};
				}, [] );
				var edit = wp.data.useDispatch( 'core/editor' ).editPost;

				if ( cfg.blocks.indexOf( props.name ) === -1 ) {
					return el( BlockEdit, props );
				}

				var url     = urlOf( props );
				var notes   = meta[ cfg.meta ] || {};
				var caption = ( ( props.attributes || {} ).caption || '' ).toString().replace( /<[^>]*>/g, '' ).trim();

				return el(
					wp.element.Fragment,
					null,
					el( BlockEdit, props ),
					el(
						wp.blockEditor.InspectorControls,
						null,
						el(
							wp.components.PanelBody,
							{
								/* The mark goes in the TITLE, not PanelBody's `icon`
								   prop — core renders that one after the words, and the
								   badge belongs in front of them, as it does on every
								   other Agentimus box. */
								title: el(
									'span',
									{ style: { display: 'inline-flex', alignItems: 'center', gap: '6px' } },
									el( 'span', {
										style: { display: 'inline-flex', lineHeight: 0, flex: 'none' },
										dangerouslySetInnerHTML: { __html: cfg.icon },
									} ),
									el( 'span', null, cfg.title )
								),
								initialOpen: true,
							},
							! url
								? el( 'p', { style: { color: '#757575', fontSize: '12px', margin: 0 } }, cfg.noUrl )
								: el( wp.components.TextareaControl, {
									label: cfg.label,
									/* Say what agents get if this stays empty, the way the AI
									   description field does — never pre-fill the box with the
									   caption, which would copy it into storage and then drift
									   the moment the caption was edited. */
									help: caption
										? cfg.fallback.replace( '%s', '“' + caption + '”' ) + ' ' + cfg.help
										: cfg.help,
									rows: 3,
									maxLength: cfg.maxLen,
									value: notes[ url ] || '',
									onChange: function ( next ) {
										var updated = Object.assign( {}, notes );
										if ( next && next.trim() ) {
											updated[ url ] = next;
										} else {
											delete updated[ url ];
										}
										var patch = {};
										patch[ cfg.meta ] = updated;
										edit( { meta: patch } );
									},
								} )
						)
					)
				);
			};
		}, 'agentimusMediaContext' )
	);
}( window.wp ) );
JS;
	}

	/* ---------------------------------------------------------------------- *
	 *  Plumbing.
	 * ---------------------------------------------------------------------- */

	/**
	 * Sanitise the whole key => note map.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,string>
	 */
	public static function sanitize_map( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $key => $note ) {
			// Keys are media URLs, written by the block panel or the meta box.
			$key = esc_url_raw( trim( (string) $key ) );
			if ( '' === $key || ! preg_match( '~^(https?:)?//~i', $key ) || strlen( $key ) > 2000 ) {
				continue;
			}
			$note = self::sanitize_note( $note );
			if ( '' === $note ) {
				continue;
			}
			$out[ $key ] = $note;
			if ( count( $out ) >= self::MAX_ITEMS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Sanitise one note: plain text, collapsed, capped.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_note( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = sanitize_textarea_field( $value );
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
		if ( strlen( $value ) > self::MAX_LEN ) {
			$value = trim( substr( $value, 0, self::MAX_LEN ) );
		}
		return $value;
	}

	/**
	 * REST auth: only users who may edit the post may write its notes.
	 *
	 * @param bool   $allowed  Unused.
	 * @param string $meta_key Unused.
	 * @param int    $post_id  Post ID.
	 * @return bool
	 */
	public static function can_edit( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Bust the generated caches when our meta changes.
	 *
	 * @param int    $meta_id  Unused.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	public static function flush_on_meta( $meta_id, $post_id, $meta_key ) {
		if ( self::META === $meta_key ) {
			Cache::flush();
		}
	}

	/**
	 * Plain text of a node, with block-level elements kept apart.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private static function node_text( $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return (string) $node->nodeValue;
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}
		$tag = strtolower( $node->nodeName );
		if ( in_array( $tag, array( 'script', 'style' ), true ) ) {
			return '';
		}
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::node_text( $child );
		}
		return in_array( $tag, array( 'p', 'div', 'li', 'br', 'section' ), true ) ? $out . "\n\n" : $out;
	}

	/**
	 * Collapse extracted whitespace, keeping paragraph breaks.
	 *
	 * @param string $text Raw extracted text.
	 * @return string
	 */
	private static function tidy( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/ *\n *\n[\s\n]*/', "\n\n", $text );
		$text = (string) preg_replace( '/(?<!\n)\n(?!\n)/', ' ', $text );
		return trim( $text );
	}
}
