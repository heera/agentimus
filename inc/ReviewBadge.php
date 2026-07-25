<?php
/**
 * Review-queue badge on the admin menu — the bell's count, visible from EVERY
 * wp-admin screen. Two parts: a count bubble appended to the "Agentimus" menu
 * title (core's own update-count pattern, so it inherits core styling), and a
 * Heartbeat listener that keeps the bubble live while the owner sits on any
 * admin page — a fake Googlebot arriving mid-session lights the sidebar within
 * a Heartbeat tick, no reload.
 *
 * The count is EXACTLY the bell's: pending review-queue clients — flagged
 * sources minus the already-blocked (a blocked client is handled; the SPA's
 * ReviewMenu applies the same filter). One number, one meaning, two places.
 *
 * Cost control: the menu renders on every admin page load and threats() runs
 * real queries, so the count is cached for {@see TTL} seconds — at most one
 * recompute a minute, shared by menu renders and Heartbeat ticks alike. The
 * badge is quiet-by-design: it mirrors a queue that empties itself (new
 * clients age out after 48h, flagged ones leave when acted on), so a lit
 * badge always means "something actually awaits you".
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class ReviewBadge {

	/** Cached count — shared by every admin page load and Heartbeat tick. */
	const TRANSIENT = 'agentimus_review_count';

	/** Cache lifetime, seconds. Staleness cap for the badge between recomputes. */
	const TTL = 60;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Admin-only wiring. Heartbeat's XHR lands on admin-ajax.php, so is_admin()
	 * is true there too and one guard covers both halves.
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'heartbeat_received', array( $this, 'heartbeat' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * The menu title, badge included when something awaits. Called by
	 * Admin::menu() while registering the top-level menu — the menu itself is
	 * capability-gated, so no extra cap check here.
	 *
	 * @param string   $title    The plain menu title.
	 * @param Settings $settings Plugin settings.
	 * @return string
	 */
	public static function decorate( $title, Settings $settings ) {
		$count = self::count( $settings );
		if ( $count < 1 ) {
			return $title;
		}
		return $title . ' ' . self::bubble( $count );
	}

	/**
	 * The bubble markup — core's update-count classes, so core's CSS styles it
	 * (same red circle the Plugins menu wears).
	 *
	 * @param int $count Pending review count, >= 1.
	 * @return string
	 */
	public static function bubble( $count ) {
		$count = (int) $count;
		return sprintf(
			'<span class="update-plugins count-%1$d"><span class="plugin-count" aria-hidden="false">%2$s</span></span>',
			$count,
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Pending review-queue clients: flagged sources that are not already
	 * blocked — the bell's own arithmetic. Cached for TTL seconds; fails open
	 * to 0 (an admin page must never break, or nag, because a badge couldn't
	 * count).
	 *
	 * @param Settings $settings Plugin settings.
	 * @return int
	 */
	public static function count( Settings $settings ) {
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = 0;
		if ( $settings->enabled( 'enable_activity' ) ) {
			try {
				$threats = Activity\Repository::threats( $settings );
				foreach ( (array) ( isset( $threats['sources'] ) ? $threats['sources'] : array() ) as $row ) {
					if ( empty( $row['blocked'] ) ) {
						$count++;
					}
				}
			} catch ( \Throwable $e ) {
				$count = 0;
			}
		}

		set_transient( self::TRANSIENT, $count, self::TTL );
		return $count;
	}

	/**
	 * Drop the cached count, so the next read recomputes. Called by every
	 * mutation that changes what the queue holds — a Block/Allow (any settings
	 * write), an Ignore/Un-ignore, a Re-check, a log clear. The TTL only bounds
	 * staleness from NEW TRAFFIC arriving; the owner's own action must never
	 * read back stale — they just watched themselves act on it.
	 */
	public static function forget() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Answer the Heartbeat pulse — only when OUR script asked (the key rides in
	 * from the client), and only for someone who could see the menu anyway.
	 *
	 * @param array $response Heartbeat response being assembled.
	 * @param array $data     Data the browser sent with this pulse.
	 * @return array
	 */
	public function heartbeat( $response, $data ) {
		if ( empty( $data['agentimus_review'] ) || ! current_user_can( 'manage_options' ) ) {
			return $response;
		}
		$response['agentimus_review'] = array( 'count' => self::count( $this->settings ) );
		return $response;
	}

	/**
	 * Ship the listener on every admin page (admins only): join the Heartbeat
	 * pulse and redraw the sidebar bubble when the count changes. Inline on the
	 * heartbeat handle — a dozen lines don't warrant an asset file or a build.
	 */
	public function assets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_script( 'heartbeat' );
		wp_add_inline_script( 'heartbeat', self::inline_js() );
	}

	/**
	 * The Heartbeat listener — plus the bubble redraw itself, published as
	 * window.agentimusReviewBadge(count) so the SPA can repaint the sidebar the
	 * moment its own queue changes (an owner who just clicked Block must not
	 * stare at the old number until the next Heartbeat tick). The redraw is
	 * vanilla DOM; only the Heartbeat subscription needs jQuery, because
	 * Heartbeat's events ARE jQuery custom events — there is no vanilla
	 * subscription surface.
	 *
	 * @return string
	 */
	public static function inline_js() {
		return '(function($){'
			. 'window.agentimusReviewBadge=function(count){'
			. 'var name=document.querySelector("#toplevel_page_agentimus .wp-menu-name");'
			. 'if(!name){return;}'
			. 'var bubble=name.querySelector(".update-plugins");'
			. 'count=parseInt(count,10)||0;'
			. 'if(count<1){if(bubble){bubble.remove();}return;}'
			. 'if(!bubble){'
			. 'bubble=document.createElement("span");'
			. 'var inner=document.createElement("span");'
			. 'inner.className="plugin-count";'
			. 'inner.setAttribute("aria-hidden","false");'
			. 'bubble.appendChild(inner);'
			. 'name.appendChild(bubble);'
			. '}'
			. 'bubble.className="update-plugins count-"+count;'
			. 'bubble.querySelector(".plugin-count").textContent=String(count);'
			. '};'
			. 'if(!$){return;}'
			. '$(document).on("heartbeat-send",function(e,data){data.agentimus_review=1;});'
			. '$(document).on("heartbeat-tick",function(e,data){'
			. 'if(data.agentimus_review){window.agentimusReviewBadge(data.agentimus_review.count);}'
			. '});})(window.jQuery);';
	}
}
