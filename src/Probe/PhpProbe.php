<?php
/**
 * PHP runtime probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

/**
 * Reads the PHP runtime configuration.
 */
interface PhpProbe {

	/**
	 * PHP version, e.g. "8.3.12".
	 */
	public function version(): string;

	/**
	 * Raw memory_limit ini value, e.g. "256M" or "-1".
	 */
	public function memory_limit(): string;

	/**
	 * Peak memory use of this request in bytes.
	 */
	public function memory_peak(): int;

	/**
	 * OPcache status, or null when the extension is not loaded or
	 * opcache_get_status() is disabled.
	 *
	 * @return array{enabled:bool,hits:int,misses:int,used_memory:int,free_memory:int,wasted_memory:int,cached_scripts:int,cache_full:bool,restricted:bool}|null
	 */
	public function opcache(): ?array;
}
