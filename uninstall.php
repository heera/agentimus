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
	delete_option( 'agentimus_defaults_migrated' );
	delete_option( 'agentimus_signing_keys' );
	delete_option( 'agentimus_rewrite_version' );
	delete_option( 'agentimus_rewrite_flushed_at' );
	delete_option( 'agentimus_tombstones' );
	delete_option( 'agentimus_bot_ranges' );
	delete_option( 'agentimus_whatsnew_seen' );
	delete_option( 'agentimus_review_ask' );
	delete_option( 'agentimus_route_probe' );
	delete_option( 'agentimus_robots_watch' );
	delete_option( 'agentimus_mcp_token' );
	delete_option( 'agentimus_oauth' );
	delete_option( 'agentimus_seo_mode' );      // SeoContext verdict cache — survived a real uninstall, caught 2026-08-01.
	delete_option( 'agentimus_next_steps' );    // The post-setup "Worth a look next" card state.
	delete_option( 'agentimus_digest_last' );   // Weekly digest snapshot + unsubscribe key.
	delete_option( 'agentimus_digest_stop_key' );
	delete_transient( 'agentimus_ranges_pending' );
	delete_transient( 'agentimus_llms_txt' );
	delete_transient( 'agentimus_llms_full' );
	delete_transient( 'agentimus_changes' );
	delete_transient( 'agentimus' );
	delete_transient( 'agentimus_activation_redirect' );
	delete_transient( 'agentimus_topic_suggest' );
	delete_transient( 'agentimus_review_count' );
	delete_transient( 'agentimus_selfcheck' );
	delete_transient( 'agentimus_selfcheck_probe' );

	// Markdown body cache: the settings epoch + the filesystem cache directory.
	delete_option( 'agentimus_md_cache_epoch' );
	$agentimus_up = wp_upload_dir();
	if ( empty( $agentimus_up['error'] ) && ! empty( $agentimus_up['basedir'] ) ) {
		$agentimus_md_dir = $agentimus_up['basedir'] . '/agentimus-md-cache';
		if ( is_dir( $agentimus_md_dir ) ) {
			foreach ( (array) glob( $agentimus_md_dir . '/*.md' ) as $agentimus_md_file ) {
				@unlink( $agentimus_md_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors -- our own cache files.
			}
			// Direct rmdir, not WP_Filesystem: uninstall runs with no admin context, so requesting
		// filesystem credentials could prompt (or fail) and leave the directory behind. This is
		// our own now-empty cache dir under uploads.
		@rmdir( $agentimus_md_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.directory_rmdir, WordPress.PHP.NoSilencedErrors -- our own now-empty cache dir.
		}
	}

	// Activity log: drop the tables, version flags and prune schedule.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_agent_hits" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_ai_referrals" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_flagged_ips" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_unknown_sources" ); // phpcs:ignore WordPress.DB
	delete_option( 'agentimus_activity_db_version' );
	delete_option( 'agentimus_referrals_db_version' );
	delete_option( 'agentimus_unknown_sources_db_version' );
	delete_option( 'agentimus_flagged_ips_db_version' );
	delete_option( 'agentimus_review_dismissed' );
	delete_option( 'agentimus_review_reverified' );
	delete_option( 'agentimus_client_decisions' );
	wp_clear_scheduled_hook( 'agentimus_prune_activity' );
	wp_clear_scheduled_hook( 'agentimus_warm_llms_full' );
	wp_clear_scheduled_hook( 'agentimus_weekly_digest' );
	wp_clear_scheduled_hook( 'agentimus_refresh_bot_ranges' );
	wp_clear_scheduled_hook( 'agentimus_route_probe_refresh' );

	// Agent Access: the credential/ability event log, its schema flag and the proven
	// execute-hook verdict.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_agent_events" ); // phpcs:ignore WordPress.DB
	delete_option( 'agentimus_agent_access_db_version' );
	delete_option( 'agentimus_agent_access_hooks' );

	// Cloudflare edge source: aggregates table, connection option, lock and poll.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_edge_hourly" ); // phpcs:ignore WordPress.DB
	delete_option( 'agentimus_cloudflare' );
	delete_option( 'agentimus_edge_db_version' );
	delete_option( 'agentimus_edge_lock' );
	wp_clear_scheduled_hook( 'agentimus_edge_poll' );

	// Bing search source: daily-stats table, connection option, lock and poll.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_bing_daily" ); // phpcs:ignore WordPress.DB
	delete_option( 'agentimus_bing' );
	delete_option( 'agentimus_bing_db_version' );
	delete_option( 'agentimus_bing_lock' );
	delete_option( 'agentimus_bing_query_backfill' );
	wp_clear_scheduled_hook( 'agentimus_bing_poll' );

	// Google search source + the shared search-queries snapshot table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_search_queries" ); // phpcs:ignore WordPress.DB
	delete_option( 'agentimus_google' );
	delete_option( 'agentimus_google_lock' );
	delete_option( 'agentimus_search_db_version' );
	delete_transient( 'agentimus_google_token' );
	wp_clear_scheduled_hook( 'agentimus_google_poll' );

	// AI Visibility monitoring: results table, options and scheduled run.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentimus_visibility" ); // phpcs:ignore WordPress.DB
	delete_option( 'agentimus_visibility' );
	delete_option( 'agentimus_visibility_last_run' );
	delete_option( 'agentimus_visibility_lock' );
	delete_option( 'agentimus_visibility_cryptokey' );
	delete_option( 'agentimus_visibility_db_version' );
	delete_option( 'agentimus_visibility_demo' );
	wp_clear_scheduled_hook( 'agentimus_visibility_run' );
	wp_clear_scheduled_hook( 'agentimus_visibility_run_now' ); // the one-off "run now" event.
}

if ( is_multisite() ) {
	// Page through EVERY site in bounded batches rather than a single capped query — uninstall's
	// contract is that nothing is left orphaned, and a fixed cap silently stranded tables/options
	// on sites beyond it. Teardown is light (drop tables, delete options, clear cron), so paging
	// is safe where a full activation loop would risk a timeout. Prefixed vars: uninstall.php runs
	// at global scope, so an unprefixed name could collide with another plugin's uninstaller.
	$agentimus_batch  = 500;
	$agentimus_offset = 0;
	do {
		$agentimus_site_ids = get_sites( array( 'fields' => 'ids', 'number' => $agentimus_batch, 'offset' => $agentimus_offset ) );
		foreach ( (array) $agentimus_site_ids as $agentimus_site_id ) {
			switch_to_blog( (int) $agentimus_site_id );
			agentimus_uninstall_site();
			restore_current_blog();
		}
		$agentimus_offset += $agentimus_batch;
	} while ( count( (array) $agentimus_site_ids ) === $agentimus_batch );
} else {
	agentimus_uninstall_site();
}
