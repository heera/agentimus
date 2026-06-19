<?php
/**
 * Uninstall — remove the plugin's option and caches.
 *
 * @package Agentimus
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'agentimus_settings' );
delete_option( 'agentimus_onboarded' );
delete_option( 'agentimus_signing_keys' );
delete_transient( 'agentimus_llms_txt' );
delete_transient( 'agentimus_llms_full' );
delete_transient( 'agentimus' );
delete_transient( 'agentimus_activation_redirect' );

// Activity log: drop the table, version flag and prune schedule.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_agent_hits" ); // phpcs:ignore WordPress.DB
delete_option( 'agentimus_activity_db_version' );
wp_clear_scheduled_hook( 'agentimus_prune_activity' );
wp_clear_scheduled_hook( 'agentimus_warm_llms_full' );
