<?php
/**
 * Clock abstraction.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Support;

/**
 * Source of the current time, replaceable in tests.
 */
interface Clock {

	/**
	 * Current Unix time in seconds.
	 */
	public function now(): int;

	/**
	 * Monotonic-ish high resolution time in seconds, used to time checks.
	 */
	public function microtime(): float;
}
