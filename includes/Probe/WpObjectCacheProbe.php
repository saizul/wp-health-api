<?php
/**
 * Object cache probe.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use Saizul\HealthApi\Probe\ObjectCacheProbe;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the object cache drop-in and tests it with a write/read/delete.
 */
final class WpObjectCacheProbe implements ObjectCacheProbe {

	const GROUP = 'wp_health_api';

	/**
	 * Backend name, guessed from the cache class.
	 */
	public function backend(): string {
		global $wp_object_cache;

		if ( ! $this->is_persistent() ) {
			return 'none';
		}
		$class   = is_object( $wp_object_cache ) ? strtolower( get_class( $wp_object_cache ) ) : '';
		$backend = 'unknown';
		foreach ( array(
			'relay'     => 'relay',
			'redis'     => 'redis',
			'memcached' => 'memcached',
			'memcache'  => 'memcache',
			'apcu'      => 'apcu',
			'apc'       => 'apc',
			'sqlite'    => 'sqlite',
		) as $needle => $name ) {
			if ( false !== strpos( $class, $needle ) ) {
				$backend = $name;
				break;
			}
		}

		/**
		 * Filters the object cache backend name shown in reports and metrics.
		 *
		 * @param string $backend Detected backend.
		 * @param string $class   Class of the global object cache.
		 */
		return (string) apply_filters( 'wp_health_api_object_cache_backend', $backend, $class );
	}

	/**
	 * Whether a persistent object cache is in use.
	 */
	public function is_persistent(): bool {
		return (bool) wp_using_ext_object_cache();
	}

	/**
	 * Write, force-read and delete a random value.
	 *
	 * @return array{ok:bool,latency:float|null,error:string}
	 */
	public function round_trip(): array {
		$key     = 'probe_' . wp_generate_password( 12, false, false );
		$value   = wp_generate_password( 24, false, false );
		$started = microtime( true );
		$written = wp_cache_set( $key, $value, self::GROUP, 30 );
		$read    = wp_cache_get( $key, self::GROUP, true );
		wp_cache_delete( $key, self::GROUP );
		$latency = microtime( true ) - $started;

		if ( ! $written ) {
			return array(
				'ok'      => false,
				'latency' => $latency,
				'error'   => 'wp_cache_set() failed',
			);
		}

		return array(
			'ok'      => $read === $value,
			'latency' => $latency,
			'error'   => $read === $value ? '' : 'the value read back differs from the value written',
		);
	}
}
