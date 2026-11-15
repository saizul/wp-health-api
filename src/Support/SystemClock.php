<?php
/**
 * System clock.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Support;

/**
 * Clock backed by the system time.
 */
final class SystemClock implements Clock {

	/**
	 * Current Unix time in seconds.
	 */
	public function now(): int {
		return time();
	}

	/**
	 * High resolution time in seconds.
	 */
	public function microtime(): float {
		return function_exists( 'hrtime' ) ? hrtime( true ) / 1e9 : microtime( true );
	}
}
