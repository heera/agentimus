<?php
/**
 * Uninstall — remove the plugin's option and caches.
 *
 * @package HeeraAgentDiscovery
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'heera_agent_discovery_settings' );
delete_option( 'heera_agent_discovery_onboarded' );
delete_option( 'heera_agent_discovery_signing_keys' );
delete_transient( 'heera_agent_discovery_llms_txt' );
delete_transient( 'heera_agent_discovery_llms_full' );
delete_transient( 'heera_agent_discovery' );
delete_transient( 'heera_agent_discovery_activation_redirect' );

// Activity log: drop the table, version flag and prune schedule.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}heera_agent_discovery_agent_hits" ); // phpcs:ignore WordPress.DB
delete_option( 'heera_agent_discovery_activity_db_version' );
wp_clear_scheduled_hook( 'heera_agent_discovery_prune_activity' );
wp_clear_scheduled_hook( 'heera_agent_discovery_warm_llms_full' );
