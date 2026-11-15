<?php
/**
 * Frozen clock for tests and deterministic runs.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Support;

/**
 * Clock that only moves when told to.
 */
final class FrozenClock implements Clock {

	/**
	 * Current time.
	 *
	 * @var int
	 */
	private $now;

	/**
	 * High resolution time.
	 *
	 * @var float
	 */
	private $micro;

	/**
	 * Constructor.
	 *
	 * @param int $now Unix time.
	 */
	public function __construct( int $now ) {
		$this->now   = $now;
		$this->micro = (float) $now;
	}

	/**
	 * Current Unix time in seconds.
	 */
	public function now(): int {
		return $this->now;
	}

	/**
	 * High resolution time in seconds.
	 */
	public function microtime(): float {
		return $this->micro;
	}

	/**
	 * Move the clock forward.
	 *
	 * @param float $seconds Seconds, fractions allowed.
	 */
	public function advance( float $seconds ): void {
		$this->micro += $seconds;
		$this->now    = (int) floor( $this->micro );
	}
}
