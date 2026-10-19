<?php
/**
 * The "Readers From AI" column on WordPress's own posts lists — sortable, so the posts
 * an assistant sends readers to rise to the top without opening each one.
 *
 * Reads through {@see PostTraffic}, like Your Content and the editor's Traffic
 * tab, so the number here is the number there. One grouped query fills the
 * whole screen of rows; never one per row.
 *
 * Shown only while traffic recording is on: with it off every row would read 0,
 * and a column of measured-looking zeros that nothing measured is worse than no
 * column.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class PostTrafficColumn {

	const COLUMN = 'agentimus_ai_visits';

	/** @var Settings */
	private $settings;

	/** @var array<int,array>|null This screen's rows, read once. */
	private $totals = null;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', array( $this, 'hook_types' ) );
	}

	/**
	 * Hook each agent-visible post type's list. Late enough that every type is registered.
	 */
	public function hook_types() {
		if ( ! $this->settings->enabled( 'enable_activity' ) ) {
			return;
		}
		foreach ( Content::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( $this, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'cell' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( $this, 'sortable' ) );
		}
		add_filter( 'posts_clauses', array( $this, 'order' ), 10, 2 );
	}

	/**
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function columns( $columns ) {
		// ⭐ Not "AI Visits": on the Request Log a visit is a BOT's, so the word meant
		// machines there and people here. His call, 2026-10-19 — one name everywhere.
		$label = esc_html__( 'Readers From AI', 'agentimus' );
		// ⚠️ While older traffic is still being matched to their posts these numbers
		// are short, and climb as matching goes on — said under the heading, in
		// words, because a column of low numbers otherwise reads as the answer.
		// (Your Content says the same in its opened row.) Gone once matching ends.
		if ( ! PostTraffic::backfill_done() ) {
			$label .= '<span class="agentimus-col-note" style="display:block;font-size:11px;font-weight:400;line-height:1.4;color:#646970">'
				. esc_html__( 'Still matching older traffic; may rise', 'agentimus' )
				. '</span>';
		}
		$columns[ self::COLUMN ] = $label;
		return $columns;
	}

	/**
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string|array>
	 */
	public function sortable( $columns ) {
		// Busiest first on the first click — the question the column is for.
		$columns[ self::COLUMN ] = array( self::COLUMN, true );
		return $columns;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function cell( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		if ( null === $this->totals ) {
			global $wp_query;
			$ids          = isset( $wp_query->posts ) ? wp_list_pluck( (array) $wp_query->posts, 'ID' ) : array();
			$this->totals = PostTraffic::totals_for( $ids ? $ids : array( (int) $post_id ) );
		}
		if ( ! isset( $this->totals[ (int) $post_id ] ) ) {
			$this->totals += PostTraffic::totals_for( array( (int) $post_id ) );
		}
		echo esc_html( number_format_i18n( (int) $this->totals[ (int) $post_id ]['ai'] ) );
	}

	/**
	 * Order the main list by AI visits when the column header asks for it.
	 *
	 * @param array     $clauses Query clauses.
	 * @param \WP_Query $query   The query.
	 * @return array
	 */
	public function order( $clauses, $query ) {
		if ( ! $query->is_main_query() || self::COLUMN !== $query->get( 'orderby' ) ) {
			return $clauses;
		}
		global $wpdb;
		$dir                 = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
		$clauses['join']    .= PostTraffic::join_sql( 'ai', "{$wpdb->posts}.ID" );
		$clauses['orderby']  = "COALESCE(pt.n, 0) $dir, {$wpdb->posts}.post_date DESC";
		return $clauses;
	}
}
