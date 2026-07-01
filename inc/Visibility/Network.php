<?php
/**
 * Multisite network dashboard — a Network-Admin screen that rolls every site's
 * latest AI-visibility numbers into one table, so an agency running many client
 * sites sees them all at a glance. Each site stores its own results (see
 * Monitor\Table), and this screen aggregates across them.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

use Agentimus\Visibility\Store;
use Agentimus\Visibility\Runner;

defined( 'ABSPATH' ) || exit;

final class Network {

	/** @var int Cap on sites scanned per page load, to keep the screen responsive. */
	const MAX_SITES = 100;

	/**
	 * Register the Network-Admin menu (only meaningful on multisite).
	 */
	public function register() {
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
	}

	/**
	 * Add the "AI Visibility" page to the Network Admin sidebar.
	 */
	public function menu() {
		add_menu_page(
			__( 'AI Visibility', 'agentimus' ),
			__( 'AI Visibility', 'agentimus' ),
			'manage_network',
			'agentimus-visibility-network',
			array( $this, 'render' ),
			'dashicons-visibility',
			81
		);
	}

	/**
	 * Render the aggregated table.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_network' ) ) {
			return;
		}

		$rows  = $this->collect();
		$avg   = $this->average_score( $rows );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Visibility — Network', 'agentimus' ) . '</h1>';
		echo '<p style="color:#646970;max-width:640px">' . esc_html__( 'Latest AI-visibility results across every site on the network. Each site keeps its own data; open a site to configure its prompts and API keys.', 'agentimus' ) . '</p>';

		printf(
			'<p style="font-size:14px"><strong>%s</strong> %s</p>',
			esc_html( $avg . '%' ),
			esc_html__( 'average visibility score across sites with data', 'agentimus' )
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Site', 'agentimus' ),
			__( 'Brand', 'agentimus' ),
			__( 'Visibility', 'agentimus' ),
			__( 'Citations', 'agentimus' ),
			__( 'Engines', 'agentimus' ),
			__( 'Last run', 'agentimus' ),
			'',
		) as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No sites found.', 'agentimus' ) . '</td></tr>';
		}

		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( $r['name'] ) . '</td>';
			echo '<td>' . esc_html( $r['brand'] ?: '—' ) . '</td>';
			echo '<td>' . ( $r['hasData'] ? esc_html( $r['visibility'] . '%' ) : '<span style="color:#a7aaad">—</span>' ) . '</td>';
			echo '<td>' . ( $r['hasData'] ? esc_html( $r['citations'] . '%' ) : '<span style="color:#a7aaad">—</span>' ) . '</td>';
			echo '<td>' . esc_html( (string) $r['providers'] ) . '</td>';
			echo '<td>' . esc_html( $r['lastRun'] ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $r['adminUrl'] ) . '">' . esc_html__( 'Open', 'agentimus' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Gather each site's latest numbers.
	 *
	 * @return array[]
	 */
	private function collect() {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => self::MAX_SITES,
			)
		);

		$out = array();
		foreach ( (array) $site_ids as $site_id ) {
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );

			$settings  = new Settings();
			$latest_id = Store::latest_run_id();
			$summary   = $latest_id ? Store::summarize( Store::rows_for_run( $latest_id ) ) : null;
			$last_ts   = (int) get_option( Runner::LAST_RUN_OPTION, 0 );

			$out[] = array(
				'name'       => get_bloginfo( 'name' ),
				'brand'      => (string) $settings->get( 'brand', '' ),
				'hasData'    => (bool) $summary,
				'visibility' => $summary ? (int) $summary['visibilityScore'] : 0,
				'citations'  => $summary ? (int) $summary['citationRate'] : 0,
				'providers'  => count( $settings->active_providers() ),
				'lastRun'    => $last_ts ? wp_date( 'M j, Y H:i', $last_ts ) : __( 'never', 'agentimus' ),
				'adminUrl'   => admin_url( 'admin.php?page=agentimus' ) . '#visibility',
			);

			restore_current_blog();
		}

		return $out;
	}

	/**
	 * Average visibility score across the sites that have run at least once.
	 *
	 * @param array[] $rows Collected rows.
	 * @return int
	 */
	private function average_score( array $rows ) {
		$scores = array();
		foreach ( $rows as $r ) {
			if ( $r['hasData'] ) {
				$scores[] = (int) $r['visibility'];
			}
		}
		if ( empty( $scores ) ) {
			return 0;
		}
		return (int) round( array_sum( $scores ) / count( $scores ) );
	}
}
