<?php
/**
 * Uninstall — remove the plugin's option and caches.
 *
 * @package Agentify
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'agentify_settings' );
delete_option( 'agentify_onboarded' );
delete_transient( 'agentify_llms_txt' );
delete_transient( 'agentify_llms_full' );
delete_transient( 'agentify_discovery' );
delete_transient( 'agentify_activation_redirect' );

// Activity log: drop the table, version flag and prune schedule.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentify_agent_hits" ); // phpcs:ignore WordPress.DB
delete_option( 'agentify_activity_db_version' );
wp_clear_scheduled_hook( 'agentify_prune_activity' );
