<?php
/**
 * JSON-LD preview — one section of the unified "Agentimus" editor panel
 * ({@see EditorPanel}). Renders the exact structured data Agentimus would emit for
 * the post being edited (the same Schema::build_document() the front end and the
 * admin "JSON-LD" tab use). Server-rendered, so it reflects the SAVED post.
 *
 * Registration and asset loading are owned by EditorPanel; this class provides the
 * section's enabled-state, body render, and its styles/scripts.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class SchemaMetaBox {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the JSON-LD feature is on. The section tracks the front-end feature
	 * so it never advertises output that isn't happening.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->settings->enabled( 'enable_schema' );
	}

	/**
	 * Render the read-only preview (section body): a status line (live / an SEO
	 * plugin owns it), the pretty-printed graph, and validate/open links. Reflects
	 * the saved post.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		$schema = new Schema( $this->settings );
		$seo    = $schema->seo_plugin_active();
		// preview = true: show a draft/scheduled post's would-be node, matching the
		// central JSON-LD tab. The front end still gates it; this box is owner-only.
		$doc = $schema->build_document( $post, false, true );

		$obj  = get_post_type_object( $post->post_type );
		$noun = ( $obj && ! empty( $obj->labels->singular_name ) ) ? strtolower( $obj->labels->singular_name ) : $post->post_type;

		echo '<div class="agentimus-schema-box">';

		// Status: what the live page is actually doing right now.
		if ( $seo ) {
			echo '<p class="agentimus-schema-box__status is-info">'
				. esc_html__( 'An SEO plugin currently owns your site’s schema, so Agentimus stands down on the live page. This is what Agentimus would emit instead.', 'agentimus' )
				. '</p>';
		} else {
			echo '<p class="agentimus-schema-box__status is-good">'
				. esc_html__( 'This is emitted in the page’s <head>.', 'agentimus' )
				. '</p>';
		}

		// A password-protected post never ships per-post schema — even the preview
		// shows only the site identity, so check it first (it holds whatever the
		// publish status). A draft/scheduled post DOES get a would-be node below.
		if ( '' !== (string) $post->post_password ) {
			echo '<p class="agentimus-schema-box__note">'
				. esc_html__( 'This is password-protected, so its content is never exposed as schema — only the site-wide identity below.', 'agentimus' )
				. '</p>';
		} elseif ( 'publish' !== $post->post_status ) {
			echo '<p class="agentimus-schema-box__note">'
				. esc_html(
					sprintf(
						/* translators: %s: the content type in lowercase, e.g. "post", "page". */
						__( 'This %s isn’t published yet — below is a preview of the per-post schema it will emit once you publish. It isn’t in your live page’s <head> yet.', 'agentimus' ),
						$noun
					)
				)
				. '</p>';
		}

		if ( null === $doc ) {
			echo '<p class="agentimus-schema-box__note">' . esc_html__( 'Nothing would be emitted for this content.', 'agentimus' ) . '</p></div>';
			return;
		}

		$json = (string) wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		echo '<div class="agentimus-schema-box__bar">';
		echo '<span class="agentimus-schema-box__reflect">' . esc_html__( 'Reflects the saved version — save to refresh.', 'agentimus' ) . '</span>';
		echo '<button type="button" class="button button-small agentimus-schema-box__copy" data-done="' . esc_attr__( 'Copied ✓', 'agentimus' ) . '">'
			. esc_html__( 'Copy JSON', 'agentimus' ) . '</button>';
		echo '</div>';

		echo '<pre class="agentimus-schema-box__code"><code>' . esc_html( $json ) . '</code></pre>';

		// URL-based validators reflect the LIVE page, so only offer them when the
		// live page actually carries this (published, not gated, and not deferred).
		$public = ( 'publish' === $post->post_status && '' === (string) $post->post_password );
		if ( $public && ! $seo ) {
			$url = (string) get_permalink( $post );
			echo '<p class="agentimus-schema-box__tools">';
			printf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( $url ) ),
				esc_html__( 'Google Rich Results test ↗', 'agentimus' )
			);
			echo ' &nbsp;·&nbsp; ';
			printf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://validator.schema.org/#url=' . rawurlencode( $url ) ),
				esc_html__( 'Schema.org validator ↗', 'agentimus' )
			);
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Copy-to-clipboard for the code block, with a select-and-copy fallback.
	 *
	 * @return string
	 */
	public static function js() {
		return <<<'JS'
(function(){var btns=document.querySelectorAll('.agentimus-schema-box__copy');Array.prototype.forEach.call(btns,function(btn){btn.addEventListener('click',function(){var box=btn.closest('.agentimus-schema-box');var pre=box?box.querySelector('.agentimus-schema-box__code'):null;if(!pre){return;}var text=pre.innerText;var done=function(){var label=btn.textContent;btn.textContent=btn.getAttribute('data-done')||'Copied';setTimeout(function(){btn.textContent=label;},2000);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(done).catch(function(){});}else{try{var r=document.createRange();r.selectNodeContents(pre);var s=window.getSelection();s.removeAllRanges();s.addRange(r);document.execCommand('copy');s.removeAllRanges();done();}catch(e){}}});});})();
JS;
	}

	/**
	 * Styles for the JSON-LD section. WordPress-admin palette; scoped to our container.
	 *
	 * @return string
	 */
	public static function css() {
		return '.agentimus-schema-box__status{margin:0 0 10px;padding:8px 11px;border-radius:4px;background:#f6f7f7;border-left:3px solid #c3c4c7;font-size:13px}'
			. '.agentimus-schema-box__status.is-good{border-left-color:#00a32a}'
			. '.agentimus-schema-box__status.is-info{border-left-color:#2271b1}'
			. '.agentimus-schema-box__note{margin:0 0 10px;color:#646970;font-style:italic;font-size:13px}'
			. '.agentimus-schema-box__bar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px}'
			. '.agentimus-schema-box__reflect{color:#646970;font-size:12px}'
			. '.agentimus-schema-box__code{margin:0;padding:12px 14px;max-height:420px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;font-family:Menlo,Consolas,monospace;font-size:12.5px;line-height:1.55;white-space:pre;tab-size:2}'
			. '.agentimus-schema-box__tools{margin:10px 0 0;font-size:13px}';
	}
}
