<?php
/**
 * AI Readability — one section of the unified "Agentimus" editor panel
 * ({@see EditorPanel}). Runs {@see PageCheck} over the post being edited and lists
 * what would make it easier for an AI to read, section and cite. Server-rendered,
 * so it reflects the SAVED post. Admin-only and advisory — no front-end output.
 *
 * Registration and asset loading are owned by EditorPanel; this class provides the
 * section's enabled-state, body render, and its styles.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class PageCheckMetaBox {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->settings->enabled( 'enable_page_checks' );
	}

	/**
	 * Render the pass/warn rows and a one-line summary (section body). Reflects the
	 * saved post.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		echo self::rows_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every field is escaped inside rows_html().
	}

	/**
	 * The section body as an HTML string — the pass/warn rows and the one-line
	 * summary. Pure given a post, so the meta box and the REST auto-refresh route
	 * ({@see EditorPanel::rest_page_check()}) render byte-identical markup.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return string
	 */
	public static function rows_html( $post ) {
		$rows = PageCheck::analyze( $post );
		$sum  = PageCheck::summary( $rows );

		// Offer the "Fix with AI" affordance only when a provider is actually configured.
		// Resolved once here, not per row.
		$ai = Assist::ai_available();

		// The featured-image row's one-click Generate needs a longer chain — block
		// editor, writes switch, image-capable provider, upload rights. Also once.
		$genfeat = AssistantEditor::featured_one_click();

		ob_start();
		printf( '<div class="agentimus-pc" data-post="%d">', (int) $post->ID );

		$head = ( $sum['warn'] + $sum['fail'] ) > 0
			? sprintf(
				/* translators: 1: passing count, 2: to-improve count. */
				esc_html__( '%1$d good · %2$d to improve', 'agentimus' ),
				(int) $sum['pass'],
				(int) ( $sum['warn'] + $sum['fail'] )
			)
			: esc_html__( 'Looks good — nothing to improve.', 'agentimus' );

		echo '<p class="agentimus-pc__head">' . esc_html( $head ) . '</p>';
		// The audience, stated ONCE. Every row below then says what to do without
		// having to re-justify who it is for.
		echo '<p class="agentimus-pc__lead">' . esc_html__( 'How easily this page can be read, sectioned and quoted — by AI assistants, by search engines and by people.', 'agentimus' ) . '</p>';
		echo '<p class="agentimus-pc__reflect">' . esc_html__( 'Updates when you save.', 'agentimus' ) . '</p>';

		echo '<ul class="agentimus-pc__list">';
		foreach ( $rows as $r ) {
			$status = in_array( $r['status'], array( 'pass', 'warn', 'fail' ), true ) ? $r['status'] : 'warn';
			$mark   = 'pass' === $status ? '✓' : '!';

			// A "Fix with AI" button on the checks AI can draft (warn/fail only). The result
			// is rendered into the sibling .agentimus-pc__fixout by the delegated handler in
			// Assist's editor script.
			$fix = '';
			if ( $ai && in_array( $status, array( 'warn', 'fail' ), true ) && Assist::fixable( $r['id'] ) ) {
				$fix = '<button type="button" class="agentimus-pc__fix" data-check="' . esc_attr( $r['id'] ) . '">'
					. '<svg class="agentimus-assist__icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3l1.9 4.7L18.6 9l-4.7 1.9L12 15.6 10.1 10.9 5.4 9l4.7-1.3z"/></svg>'
					. esc_html__( 'Fix with AI', 'agentimus' )
					. '</button><div class="agentimus-pc__fixout" hidden></div>';
			}

			// The featured-image row gets a one-click of its own — not a text
			// suggestion but the assistant's generate-and-set-featured (bound by
			// delegation in AssistantEditor's editor script). Its class is NOT
			// agentimus-pc__fix, so the Fix-with-AI handler never catches it.
			if ( $genfeat && 'featured_image' === $r['id'] && 'pass' !== $status ) {
				$fix = '<button type="button" class="agentimus-pc__genfeat">'
					. '<svg class="agentimus-assist__icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3l1.9 4.7L18.6 9l-4.7 1.9L12 15.6 10.1 10.9 5.4 9l4.7-1.3z"/></svg>'
					. esc_html__( 'Generate with AI', 'agentimus' )
					. '</button>';
			}

			printf(
				'<li class="agentimus-pc__row is-%1$s"><span class="agentimus-pc__mark" aria-hidden="true">%2$s</span><span class="agentimus-pc__text"><strong>%3$s</strong>%4$s%5$s</span></li>',
				esc_attr( $status ),
				esc_html( $mark ),
				esc_html( $r['label'] ),
				'' !== $r['detail'] ? '<span class="agentimus-pc__detail">' . esc_html( $r['detail'] ) . '</span>' : '',
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from static markup, esc_attr'd data-check and esc_html'd label.
				$fix
			);
		}
		echo '</ul>';

		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Styles for the readability section. WordPress-admin palette; scoped to our container.
	 *
	 * @return string
	 */
	public static function css() {
		return '.agentimus-pc__head{margin:0 0 2px;font-weight:600;font-size:13px}'
			// The audience line: quieter than the head, above the "updates when you
			// save" note, and it wraps rather than squeezing the list.
			. '.agentimus-pc__lead{margin:0 0 4px;font-size:12px;line-height:1.5;color:#50575e}'
			. '.agentimus-pc__list{margin:0;padding:0;list-style:none}'
			. '.agentimus-pc__row{display:flex;gap:9px;align-items:flex-start;padding:7px 0;border-top:1px solid #f0f0f1;font-size:13px}'
			. '.agentimus-pc__row:first-child{border-top:0}'
			. '.agentimus-pc__mark{flex:0 0 18px;width:18px;height:18px;line-height:18px;text-align:center;border-radius:50%;font-size:11px;font-weight:700;color:#fff}'
			. '.agentimus-pc__row.is-pass .agentimus-pc__mark{background:#00a32a}'
			. '.agentimus-pc__row.is-warn .agentimus-pc__mark{background:#dba617}'
			. '.agentimus-pc__row.is-fail .agentimus-pc__mark{background:#d63638}'
			// ⭐ A MEASURE, not the window. This box sits in the editor's meta-box
			// area, which is as wide as the screen — so on a 2000px display a
			// verdict ran the whole way across in one line and stopped being
			// readable at all (his catch, 2026-08-19). 68ch is inside the 45-75
			// characters typography has settled on for body text, and it CAPS
			// rather than sets: a narrow editor, a phone or a sidebar never sees
			// it, because max-width can only ever take width away.
			. '.agentimus-pc__text{display:block;max-width:68ch}'
			. '.agentimus-pc__detail{display:block;color:#646970;margin-top:2px}'
			// The two paragraphs above the list are the same sentence-shaped
			// content and take the same measure — a capped list under a
			// full-width lead reads as two different panels.
			. '.agentimus-pc__lead,.agentimus-pc__reflect{max-width:68ch}'
			. '.agentimus-pc__reflect{color:#646970;font-size:12px;margin:0 0 10px}'
			// The featured row's one-click, dressed like the Fix-with-AI button but
			// styled here (its own class keeps it out of that handler's reach).
			. '.agentimus-pc__genfeat{display:inline-flex;align-items:center;gap:4px;margin-top:6px;background:none;border:1px solid #c3c4c7;border-radius:3px;padding:2px 8px;font-size:12px;color:#2271b1;cursor:pointer}'
			. '.agentimus-pc__genfeat:hover{border-color:#2271b1;background:#f6f7f7}'
			. '.agentimus-pc__genfeat[disabled]{opacity:.7;cursor:progress}'
			. '.agentimus-pc__genfeat .agentimus-assist__icon{color:#8073a6}';
	}
}
