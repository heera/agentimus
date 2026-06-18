<?php
/**
 * Uninstall — remove the plugin's option and caches.
 *
 * @package Agentomatic
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'agentomatic_settings' );
delete_option( 'agentomatic_onboarded' );
delete_option( 'agentomatic_signing_keys' );
delete_transient( 'agentomatic_llms_txt' );
delete_transient( 'agentomatic_llms_full' );
delete_transient( 'agentomatic' );
delete_transient( 'agentomatic_activation_redirect' );

// Activity log: drop the table, version flag and prune schedule.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentomatic_agent_hits" ); // phpcs:ignore WordPress.DB
delete_option( 'agentomatic_activity_db_version' );
wp_clear_scheduled_hook( 'agentomatic_prune_activity' );
wp_clear_scheduled_hook( 'agentomatic_warm_llms_full' );
