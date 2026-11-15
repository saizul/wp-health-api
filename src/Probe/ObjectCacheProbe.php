<?php
/**
 * Object cache probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

/**
 * Reads object cache details and performs a write/read round trip.
 */
interface ObjectCacheProbe {

	/**
	 * Backend name, e.g. "redis", "memcached", "apcu" or "none".
	 */
	public function backend(): string;

	/**
	 * Whether an external (persistent) object cache is installed.
	 */
	public function is_persistent(): bool;

	/**
	 * Write a random value, read it back and delete it.
	 *
	 * @return array{ok:bool,latency:float|null,error:string} Latency in seconds.
	 */
	public function round_trip(): array;
}
