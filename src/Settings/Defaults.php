<?php
/**
 * Default settings.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Settings;

use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;

/**
 * Default checks, thresholds and behaviour.
 */
final class Defaults {

	/**
	 * Check ids in report order, with whether they are on by default.
	 *
	 * @return array<string,bool>
	 */
	public static function checks(): array {
		return array(
			'database'         => true,
			'object_cache'     => true,
			'cron'             => true,
			'action_scheduler' => true,
			'disk'             => true,
			'php'              => true,
			'updates'          => true,
			'ssl'              => false,
			'maintenance'      => true,
			'woocommerce'      => false,
		);
	}

	/**
	 * Thresholds: key => [warn, critical, direction, unit].
	 *
	 * Null disables a level. The unit is shown in the settings screen.
	 *
	 * @return array<string,array{0:float|null,1:float|null,2:string,3:string}>
	 */
	public static function thresholds(): array {
		return array(
			'database.latency_ms'                 => array( 250.0, 1000.0, Threshold::ABOVE, 'ms' ),
			'object_cache.latency_ms'             => array( 50.0, 250.0, Threshold::ABOVE, 'ms' ),
			'cron.overdue_events'                 => array( 10.0, 50.0, Threshold::ABOVE, 'events' ),
			'cron.last_run_age'                   => array( 3600.0, 21600.0, Threshold::ABOVE, 's' ),
			'action_scheduler.past_due'           => array( 25.0, 250.0, Threshold::ABOVE, 'actions' ),
			'action_scheduler.failed_24h'         => array( 10.0, null, Threshold::ABOVE, 'actions' ),
			'disk.free_percent'                   => array( 10.0, 5.0, Threshold::BELOW, '%' ),
			'disk.free_mb'                        => array( 1024.0, 256.0, Threshold::BELOW, 'MiB' ),
			'php.memory_limit_mb'                 => array( 64.0, 32.0, Threshold::BELOW, 'MiB' ),
			'php.opcache_hit_percent'             => array( 90.0, null, Threshold::BELOW, '%' ),
			'php.opcache_free_memory_percent'     => array( 5.0, null, Threshold::BELOW, '%' ),
			'updates.core'                        => array( 1.0, null, Threshold::ABOVE, 'updates' ),
			'updates.plugins'                     => array( 1.0, 10.0, Threshold::ABOVE, 'updates' ),
			'updates.themes'                      => array( 1.0, null, Threshold::ABOVE, 'updates' ),
			'ssl.days_left'                       => array( 14.0, 7.0, Threshold::BELOW, 'days' ),
			'woocommerce.orders_last_hour'        => array( null, null, Threshold::BELOW, 'orders' ),
			'woocommerce.failed_orders_last_hour' => array( null, null, Threshold::ABOVE, 'orders' ),
		);
	}

	/**
	 * All default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$thresholds = array();
		foreach ( self::thresholds() as $key => $definition ) {
			$thresholds[ $key ] = array(
				'warn'     => $definition[0],
				'critical' => $definition[1],
			);
		}

		return array(
			'checks'                   => self::checks(),
			'thresholds'               => $thresholds,
			'status_codes'             => array(
				Status::OK       => 200,
				Status::WARN     => 200,
				Status::CRITICAL => 503,
			),
			'cache_ttl'                => 10,
			'rate_limit_public'        => 60,
			'rate_limit_private'       => 30,
			'rate_limit_window'        => 60,
			'auth_failures_limit'      => 10,
			'cron_grace'               => 600,
			'php_warn_below'           => '8.1',
			'php_critical_below'       => '7.4',
			'require_opcache'          => true,
			'require_persistent_cache' => false,
			'maintenance_status'       => Status::WARN,
			'ssl_cache_hours'          => 12,
			'trusted_proxy_header'     => '',
			'public_status'            => 'alive',
		);
	}
}
