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
		echo self::rows_html( $post ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- every field is escaped inside rows_html().
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

		ob_start();
		echo '<div class="agentimus-pc">';

		$head = ( $sum['warn'] + $sum['fail'] ) > 0
			? sprintf(
				/* translators: 1: passing count, 2: to-improve count. */
				esc_html__( '%1$d good · %2$d to improve', 'agentimus' ),
				(int) $sum['pass'],
				(int) ( $sum['warn'] + $sum['fail'] )
			)
			: esc_html__( 'Looks good — nothing to improve.', 'agentimus' );

		echo '<p class="agentimus-pc__head">' . esc_html( $head ) . '</p>';

		echo '<ul class="agentimus-pc__list">';
		foreach ( $rows as $r ) {
			$status = in_array( $r['status'], array( 'pass', 'warn', 'fail' ), true ) ? $r['status'] : 'warn';
			$mark   = 'pass' === $status ? '✓' : '!';
			printf(
				'<li class="agentimus-pc__row is-%1$s"><span class="agentimus-pc__mark" aria-hidden="true">%2$s</span><span class="agentimus-pc__text"><strong>%3$s</strong>%4$s</span></li>',
				esc_attr( $status ),
				esc_html( $mark ),
				esc_html( $r['label'] ),
				'' !== $r['detail'] ? '<span class="agentimus-pc__detail">' . esc_html( $r['detail'] ) . '</span>' : ''
			);
		}
		echo '</ul>';

		echo '<p class="agentimus-pc__reflect">' . esc_html__( 'Updates when you save.', 'agentimus' ) . '</p>';
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Styles for the readability section. WordPress-admin palette; scoped to our container.
	 *
	 * @return string
	 */
	public static function css() {
		return '.agentimus-pc__head{margin:0 0 10px;font-weight:600;font-size:13px}'
			. '.agentimus-pc__list{margin:0;padding:0;list-style:none}'
			. '.agentimus-pc__row{display:flex;gap:9px;align-items:flex-start;padding:7px 0;border-top:1px solid #f0f0f1;font-size:13px}'
			. '.agentimus-pc__row:first-child{border-top:0}'
			. '.agentimus-pc__mark{flex:0 0 18px;width:18px;height:18px;line-height:18px;text-align:center;border-radius:50%;font-size:11px;font-weight:700;color:#fff}'
			. '.agentimus-pc__row.is-pass .agentimus-pc__mark{background:#00a32a}'
			. '.agentimus-pc__row.is-warn .agentimus-pc__mark{background:#dba617}'
			. '.agentimus-pc__row.is-fail .agentimus-pc__mark{background:#d63638}'
			. '.agentimus-pc__text{display:block}'
			. '.agentimus-pc__detail{display:block;color:#646970;margin-top:2px}'
			. '.agentimus-pc__reflect{color:#646970;font-size:12px;margin:10px 0 0}';
	}
}
