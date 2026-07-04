<?php
/**
 * The editor-side page-quality panel: a read-only meta box that runs
 * {@see PageCheck} over the post being edited and lists what would make it easier
 * for an AI to read, section and cite. Server-rendered, so it reflects the SAVED
 * post (save to refresh) — mirroring the JSON-LD and Topics meta boxes.
 *
 * Admin-only and advisory: it emits nothing on the front end. Shown for the
 * agent-visible post types while the feature is on.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class PageCheckMetaBox {

	const HANDLE = 'agentimus-pagecheck-box';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the meta box and its styles — admin only.
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * @return bool
	 */
	private function enabled() {
		return (bool) $this->settings->enabled( 'enable_page_checks' );
	}

	/**
	 * Add the box to every agent-visible post type, low in the main column.
	 */
	public function add_meta_box() {
		if ( ! $this->enabled() ) {
			return;
		}
		foreach ( Content::post_types() as $type ) {
			add_meta_box(
				'agentimus-pagecheck',
				__( 'AI Readability (Agentimus)', 'agentimus' ),
				array( $this, 'render_meta_box' ),
				$type,
				'normal',
				'low'
			);
		}
	}

	/**
	 * Render the pass/warn rows and a one-line summary. Reflects the saved post.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		$rows = PageCheck::analyze( $post );
		$sum  = PageCheck::summary( $rows );

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

		echo '<p class="agentimus-pc__reflect">' . esc_html__( 'Reflects the saved version — save to refresh.', 'agentimus' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Inline the panel styles — only on the editor for a covered type. Same
	 * no-build pattern as the JSON-LD / Topics meta boxes.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( ! $this->enabled() ) {
			return;
		}
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, Content::post_types(), true ) ) {
			return;
		}
		wp_register_style( self::HANDLE, false, array(), AGENTIMUS_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, self::inline_css() );
	}

	/**
	 * Styles for the panel. WordPress-admin palette; scoped to our container.
	 *
	 * @return string
	 */
	private static function inline_css() {
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
