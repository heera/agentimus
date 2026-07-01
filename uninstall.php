<?php
/**
 * Uninstall — remove the plugin's options, transients, tables and scheduled
 * events. On multisite this runs for every site on the network, so nothing is
 * left orphaned on sub-sites.
 *
 * @package Agentimus
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Tear down one site's data (must run in that site's context).
 */
function agentimus_uninstall_site() {
	global $wpdb;

	delete_option( 'agentimus_settings' );
	delete_option( 'agentimus_onboarded' );
	delete_option( 'agentimus_signing_keys' );
	delete_option( 'agentimus_rewrite_version' );
	delete_option( 'agentimus_rewrite_flushed_at' );
	delete_transient( 'agentimus_llms_txt' );
	delete_transient( 'agentimus_llms_full' );
	delete_transient( 'agentimus' );
	delete_transient( 'agentimus_activation_redirect' );

	// Activity log: drop the tables, version flags and prune schedule.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_agent_hits" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_ai_referrals" ); // phpcs:ignore WordPress.DB
	delete_option( 'agentimus_activity_db_version' );
	delete_option( 'agentimus_referrals_db_version' );
	wp_clear_scheduled_hook( 'agentimus_prune_activity' );
	wp_clear_scheduled_hook( 'agentimus_warm_llms_full' );

	// AI Visibility monitoring: results table, options and scheduled run.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_visibility" ); // phpcs:ignore WordPress.DB
	delete_option( 'agentimus_visibility' );
	delete_option( 'agentimus_visibility_last_run' );
	delete_option( 'agentimus_visibility_db_version' );
	delete_option( 'agentimus_visibility_demo' );
	wp_clear_scheduled_hook( 'agentimus_visibility_run' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 1000 ) );
	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		agentimus_uninstall_site();
		restore_current_blog();
	}
} else {
	agentimus_uninstall_site();
}
