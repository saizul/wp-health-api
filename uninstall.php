<?php
/**
 * Uninstall routine.
 *
 * Removes the settings, the token hash, the recorded cron time, the
 * heartbeat event and all cached reports and rate-limit counters.
 *
 * @package Saizul\HealthApi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_health_api_settings' );
delete_option( 'wp_health_api_token_hash' );
delete_option( 'wp_health_api_last_cron' );

wp_clear_scheduled_hook( 'wp_health_api_heartbeat' );

global $wpdb;

// Reports, certificate cache and rate-limit counters are transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wp_health_api_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wp_health_api_' ) . '%'
	)
);
