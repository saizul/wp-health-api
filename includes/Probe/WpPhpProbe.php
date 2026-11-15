<?php
/**
 * PHP runtime probe.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use Saizul\HealthApi\Probe\PhpProbe;

defined( 'ABSPATH' ) || exit;

/**
 * Reads PHP settings and OPcache status of the running SAPI. Under WP-CLI
 * this is the CLI SAPI, where OPcache is usually off.
 */
final class WpPhpProbe implements PhpProbe {

	/**
	 * PHP version.
	 */
	public function version(): string {
		return PHP_VERSION;
	}

	/**
	 * Raw memory_limit value.
	 */
	public function memory_limit(): string {
		return (string) ini_get( 'memory_limit' );
	}

	/**
	 * Peak memory.
	 */
	public function memory_peak(): int {
		return memory_get_peak_usage( true );
	}

	/**
	 * OPcache status.
	 *
	 * When opcache.restrict_api blocks opcache_get_status(), only the
	 * enabled flag is known and the statistics are reported as zero.
	 *
	 * @return array{enabled:bool,hits:int,misses:int,used_memory:int,free_memory:int,wasted_memory:int,cached_scripts:int,cache_full:bool,restricted:bool}|null
	 */
	public function opcache(): ?array {
		if ( ! extension_loaded( 'Zend OPcache' ) ) {
			return null;
		}
		$status = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warns when restricted by opcache.restrict_api.
		if ( ! is_array( $status ) ) {
			$enabled = filter_var( ini_get( PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );

			return array(
				'enabled'        => $enabled,
				'hits'           => 0,
				'misses'         => 0,
				'used_memory'    => 0,
				'free_memory'    => 0,
				'wasted_memory'  => 0,
				'cached_scripts' => 0,
				'cache_full'     => false,
				'restricted'     => $enabled,
			);
		}
		$memory = isset( $status['memory_usage'] ) && is_array( $status['memory_usage'] ) ? $status['memory_usage'] : array();
		$stats  = isset( $status['opcache_statistics'] ) && is_array( $status['opcache_statistics'] ) ? $status['opcache_statistics'] : array();

		return array(
			'enabled'        => ! empty( $status['opcache_enabled'] ),
			'hits'           => (int) ( $stats['hits'] ?? 0 ),
			'misses'         => (int) ( $stats['misses'] ?? 0 ),
			'used_memory'    => (int) ( $memory['used_memory'] ?? 0 ),
			'free_memory'    => (int) ( $memory['free_memory'] ?? 0 ),
			'wasted_memory'  => (int) ( $memory['wasted_memory'] ?? 0 ),
			'cached_scripts' => (int) ( $stats['num_cached_scripts'] ?? 0 ),
			'cache_full'     => ! empty( $status['cache_full'] ),
			'restricted'     => false,
		);
	}
}
