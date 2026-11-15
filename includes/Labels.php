<?php
/**
 * Translated labels.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Human readable, translated names for checks, thresholds and statuses.
 * The REST API and metrics use stable English ids; the admin screens and
 * Site Health use these labels.
 */
final class Labels {

	/**
	 * Check names.
	 *
	 * @return array<string,string>
	 */
	public static function checks(): array {
		return array(
			'database'         => __( 'Database', 'wp-health-api' ),
			'object_cache'     => __( 'Object cache', 'wp-health-api' ),
			'cron'             => __( 'WP-Cron', 'wp-health-api' ),
			'action_scheduler' => __( 'Action Scheduler', 'wp-health-api' ),
			'disk'             => __( 'Disk space', 'wp-health-api' ),
			'php'              => __( 'PHP runtime', 'wp-health-api' ),
			'updates'          => __( 'Pending updates', 'wp-health-api' ),
			'ssl'              => __( 'TLS certificate', 'wp-health-api' ),
			'maintenance'      => __( 'Maintenance mode', 'wp-health-api' ),
			'woocommerce'      => __( 'WooCommerce orders', 'wp-health-api' ),
		);
	}

	/**
	 * Name of one check.
	 *
	 * @param string $id Check id.
	 */
	public static function check( string $id ): string {
		$checks = self::checks();

		return $checks[ $id ] ?? ucwords( str_replace( '_', ' ', $id ) );
	}

	/**
	 * Threshold names.
	 *
	 * @return array<string,string>
	 */
	public static function thresholds(): array {
		return array(
			'database.latency_ms'                 => __( 'Query latency', 'wp-health-api' ),
			'object_cache.latency_ms'             => __( 'Round trip latency', 'wp-health-api' ),
			'cron.overdue_events'                 => __( 'Overdue events', 'wp-health-api' ),
			'cron.last_run_age'                   => __( 'Time since last run', 'wp-health-api' ),
			'action_scheduler.past_due'           => __( 'Past-due actions', 'wp-health-api' ),
			'action_scheduler.failed_24h'         => __( 'Failed actions (24 hours)', 'wp-health-api' ),
			'disk.free_percent'                   => __( 'Free space (percent)', 'wp-health-api' ),
			'disk.free_mb'                        => __( 'Free space (MiB)', 'wp-health-api' ),
			'php.memory_limit_mb'                 => __( 'Memory limit', 'wp-health-api' ),
			'php.opcache_hit_percent'             => __( 'OPcache hit rate', 'wp-health-api' ),
			'php.opcache_free_memory_percent'     => __( 'OPcache free memory', 'wp-health-api' ),
			'updates.core'                        => __( 'Core update available', 'wp-health-api' ),
			'updates.plugins'                     => __( 'Plugin updates', 'wp-health-api' ),
			'updates.themes'                      => __( 'Theme updates', 'wp-health-api' ),
			'ssl.days_left'                       => __( 'Days until expiry', 'wp-health-api' ),
			'woocommerce.orders_last_hour'        => __( 'Orders in the last hour', 'wp-health-api' ),
			'woocommerce.failed_orders_last_hour' => __( 'Failed orders in the last hour', 'wp-health-api' ),
		);
	}

	/**
	 * Status names.
	 *
	 * @param string $status Status.
	 */
	public static function status( string $status ): string {
		switch ( $status ) {
			case 'ok':
				return __( 'OK', 'wp-health-api' );
			case 'warn':
				return __( 'Warning', 'wp-health-api' );
			case 'critical':
				return __( 'Critical', 'wp-health-api' );
		}

		return $status;
	}
}
