<?php
/**
 * Database probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

/**
 * Reads database connectivity.
 */
interface DatabaseProbe {

	/**
	 * Run a trivial query and time it.
	 *
	 * @return array{connected:bool,latency:float|null,error:string,server:string}
	 *         Latency in seconds; server is the version string when known.
	 */
	public function ping(): array;
}
